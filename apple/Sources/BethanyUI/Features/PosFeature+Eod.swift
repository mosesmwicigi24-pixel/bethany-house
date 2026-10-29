import SwiftUI
import BethanyKit

/// The cashier's End of Day report (UserEodModal.tsx): the day's orders
/// with a note each, the day in her words, submitted before the till can be
/// counted; management's acknowledgement and the comment thread below it.
struct UserEodSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let session: PosSession
    let outletId: Int
    let outletName: String
    let registerId: Int
    /// Pinned by the morning check to the day the stale till opened.
    let fixedDate: String?
    let onSubmitted: () -> Void

    @State private var date = Date()
    @State private var summary: Resource<JSONValue>?
    @State private var notes: [String: String] = [:]
    @State private var sentiments = ""
    @State private var reply = ""
    @State private var busy = false
    @State private var hydratedFor: String?

    private var day: String { fixedDate ?? HubDates.dayString(date) }
    private var existing: JSONValue? { summary?.value?["existing_report"].flatMap { $0.isNull ? nil : $0 } }
    private var currency: String { session.outlet?.currencyCode ?? "KES" }

    var body: some View {
        PosSheetFrame("End of Day report", subtitle: outletName) {
            HStack {
                if fixedDate == nil { DatePicker("Day", selection: $date, in: ...Date(), displayedComponents: .date).labelsHidden() } else { Text(day).font(Theme.Font.secondary) }
                Spacer()
                if let e = existing {
                    StatusBadge(e["acknowledged_at"]?.isNull == false ? "acknowledged" : (e["submitted_at"]?.isNull == false ? "submitted" : "draft"))
                }
                Button { printReport() } label: { Label("Print", systemImage: "printer") }.disabled(summary?.value == nil)
            }
            if let summary {
                LoadableView(summary.state, retry: { summary.load() }) { s in
                    TileGrid(minWidth: 140) {
                        StatTile("Orders", value: s["order_count"]?.string ?? "0")
                        StatTile("Sales", value: Money(json: s["total_sales"] ?? .null, currency: currency)?.formatted() ?? "—")
                        StatTile("Paid", value: Money(json: s["total_paid"] ?? .null, currency: currency)?.formatted() ?? "—")
                        StatTile("Balance", value: Money(json: s["total_balance"] ?? .null, currency: currency)?.formatted() ?? "—", tone: (Money(json: s["total_balance"] ?? .null)?.isZero ?? true) ? nil : .warning)
                    }
                    Text("Figures are the Hub's for your orders on this day at this outlet.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                    ForEach(Array((s["orders"]?.array ?? []).enumerated()), id: \.offset) { _, o in
                        let oid = o["id"]?.string ?? ""
                        VStack(alignment: .leading, spacing: 4) {
                            HStack {
                                Text(o["order_number"]?.string ?? "").font(Theme.Font.body)
                                StatusBadge(o["payment_status"]?.string)
                                Spacer()
                                MoneyText(Money(json: o["total_amount"] ?? .null, currency: currency))
                            }
                            Text("\(o["customer_name"]?.string ?? "Walk-in") · paid \(Money(json: o["amount_paid"] ?? .null, currency: currency)?.formatted() ?? "—") · balance \(Money(json: o["balance"] ?? .null, currency: currency)?.formatted() ?? "—")")
                                .font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                            if let items = o["items"]?.array, !items.isEmpty {
                                Text(items.map { "\($0["quantity"]?.int ?? 0)× \($0["product_name"]?.string ?? "")" }.joined(separator: ", ")).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                            }
                            TextField("Note on this order (optional)", text: Binding(get: { notes[oid] ?? "" }, set: { notes[oid] = $0 }), axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(1...3)
                        }
                        .padding(Theme.Space.m).background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
                    }
                    Text("How did the day go?").font(Theme.Font.sectionTitle)
                    TextEditor(text: $sentiments).frame(minHeight: 120).padding(4).background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.field)).overlay(RoundedRectangle(cornerRadius: Theme.Radius.field).stroke(Theme.line))
                    if let e = existing {
                        thread(e)
                    }
                }
            }
        } actions: {
            Button("Close") { dismiss() }
            Button(busy ? "Saving…" : (existing == nil ? "Submit report" : "Update report")) { Task { await save() } }
                .buttonStyle(.borderedProminent).tint(Theme.Palette.brand).disabled(busy || summary?.value == nil)
        }
        .task { make() }
        .onChange(of: date) { _, _ in make() }
        .onChange(of: summary?.value?["date"]?.string) { _, d in hydrate(d) }
    }

    @ViewBuilder
    private func thread(_ e: JSONValue) -> some View {
        if let ack = e["acknowledged_at"]?.date { Label("Read by management", systemImage: "checkmark.seal").font(Theme.Font.secondary).foregroundStyle(Theme.Palette.success); DateText(ack) }
        let comments = e["comments"]?.array ?? []
        if !comments.isEmpty {
            Text("Conversation").font(Theme.Font.sectionTitle)
            ForEach(Array(comments.enumerated()), id: \.offset) { _, c in
                VStack(alignment: .leading, spacing: 2) {
                    HStack { Text(c["user_name"]?.string ?? "").font(Theme.Font.caption).fontWeight(.semibold); DateText(c["created_at"]?.date, style: .relative) }
                    Text(c["body"]?.string ?? "").font(Theme.Font.secondary)
                }
                .padding(Theme.Space.s).frame(maxWidth: .infinity, alignment: .leading).background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
            }
        }
        HStack {
            TextField("Reply to management", text: $reply).textFieldStyle(.roundedBorder)
            Button("Send") { Task { await sendReply(e) } }.disabled(reply.trimmingCharacters(in: .whitespaces).isEmpty || busy)
        }
    }

    private func make() {
        let api = session.api, oid = outletId, d = day
        summary = Resource { try await api.userEod(outletId: oid, date: d) }
        summary?.load()
    }

    private func hydrate(_ d: String?) {
        guard let d, hydratedFor != d else { return }
        hydratedFor = d
        if let e = existing {
            notes = e["order_notes"]?.object?.compactMapValues(\.string) ?? [:]
            sentiments = e["sentiments"]?.string ?? ""
        } else { notes = [:]; sentiments = "" }
    }

    private func save() async {
        busy = true; defer { busy = false }
        do {
            let r = try await session.api.saveUserEod(jsonObject([
                "outlet_id": .number(Double(outletId)), "date": .string(day), "register_id": .number(Double(registerId)),
                "order_notes": .object(notes.filter { !$0.value.trimmingCharacters(in: .whitespaces).isEmpty }.mapValues { .string($0) }),
                "sentiments": .string(sentiments),
            ]))
            env.toasts.show(r["message"]?.string ?? "End of Day report saved")
            hydratedFor = nil
            await summary?.reload()
            onSubmitted()
        } catch { env.toasts.error(error) }
    }

    private func sendReply(_ e: JSONValue) async {
        guard let id = e["id"]?.int else { return }
        busy = true; defer { busy = false }
        do { _ = try await session.api.addEodComment(reportId: id, body: reply.trimmingCharacters(in: .whitespaces)); reply = ""; hydratedFor = nil; await summary?.reload() } catch { env.toasts.error(error) }
    }

    private func printReport() {
        guard let s = summary?.value else { return }
        func esc(_ t: String) -> String { t.replacingOccurrences(of: "&", with: "&amp;").replacingOccurrences(of: "<", with: "&lt;") }
        func m(_ v: JSONValue?) -> String { v.flatMap { Money(json: $0, currency: currency) }?.formatted() ?? "—" }
        var h = "<html><body style='font-family:-apple-system,Helvetica;font-size:12px'><h2>End of Day report</h2>"
        h += "<div>\(esc(outletName)) · \(esc(day)) · \(esc(s["user_name"]?.string ?? ""))</div>"
        h += "<p>Orders \(s["order_count"]?.int ?? 0) · Sales \(m(s["total_sales"])) · Paid \(m(s["total_paid"])) · Balance \(m(s["total_balance"]))</p><table width='100%' border='0'>"
        for o in s["orders"]?.array ?? [] {
            let note = notes[o["id"]?.string ?? ""] ?? ""
            h += "<tr><td>\(esc(o["order_number"]?.string ?? ""))</td><td>\(esc(o["customer_name"]?.string ?? ""))</td><td align='right'>\(m(o["total_amount"]))</td><td>\(esc(o["payment_status"]?.string ?? ""))</td></tr>"
            if !note.isEmpty { h += "<tr><td colspan='4'><i>\(esc(note))</i></td></tr>" }
        }
        h += "</table><h3>How the day went</h3><p>\(esc(sentiments))</p></body></html>"
        PrintService.printHTML(h, jobName: "EoD \(day)")
    }
}

// MARK: - Day summary (GET pos/reports/daily — EndOfDayModal.tsx)

struct DailySummarySheet: View {
    @Environment(\.dismiss) private var dismiss
    let session: PosSession
    let outlet: PosOutlet
    @State private var date = Date()
    @State private var summary: Resource<JSONValue>?

    var body: some View {
        PosSheetFrame("Day summary", subtitle: outlet.name) {
            HStack {
                DatePicker("Day", selection: $date, in: ...Date(), displayedComponents: .date).labelsHidden()
                Spacer()
                Button { printSummary() } label: { Label("Print", systemImage: "printer") }.disabled(summary?.value == nil)
            }
            if let summary {
                LoadableView(summary.state, retry: { summary.load() }) { s in
                    let basis = s["money_basis"]?.string
                    TileGrid(minWidth: 150) {
                        StatTile("Net sales", value: kes(s["net_sales"]), hint: basis)
                        StatTile("Transactions", value: s["total_transactions"]?.string ?? "0")
                        StatTile("Avg. transaction", value: kes(s["average_transaction"]))
                        StatTile("Returns", value: kes(s["total_returns"]), tone: (s["total_returns"]?.money?.isZero ?? true) ? nil : .warning)
                    }
                    SectionCard("By tender") {
                        row("Cash", s["cash_sales"]); row("M-Pesa", s["mpesa_sales"]); row("Card", s["card_sales"]); row("Other", s["other_sales"])
                        Divider()
                        row("Gross sales", s["total_sales"]); row("Returns", s["total_returns"]); row("Net sales", s["net_sales"])
                    }
                    if let by = s["by_currency"]?.array, !by.isEmpty {
                        SectionCard("By currency (native, with the shilling figure at the reporting rate)") {
                            ForEach(Array(by.enumerated()), id: \.offset) { _, c in
                                let code = c["currency_code"]?.string ?? "?"
                                HStack {
                                    Text("\(code) · \(c["count"]?.int ?? 0) sale\(c["count"]?.int == 1 ? "" : "s")").font(Theme.Font.secondary)
                                    Spacer()
                                    MoneyText(Money(json: c["amount"] ?? .null, currency: code))
                                    if let k = c["amount_kes"], !k.isNull { Text("= \(Money(json: k, currency: "KES")?.formatted() ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                                    else { Text("no reporting rate").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning) }
                                }
                            }
                            if let u = s["unconverted"]?.array, !u.isEmpty { Text("Not converted: \(u.compactMap(\.string).joined(separator: ", "))").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning) }
                        }
                    }
                    if let top = s["top_products"]?.array, !top.isEmpty {
                        SectionCard("Top products") {
                            ForEach(Array(top.prefix(8).enumerated()), id: \.offset) { i, p in
                                HStack { Text("\(i + 1). \(p["name"]?.string ?? "")").font(Theme.Font.secondary); Spacer(); Text("×\(p["qty"]?.int ?? 0)").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary); Text(kes(p["revenue"])).monospacedDigit() }
                            }
                        }
                    }
                    if let hours = s["hourly_breakdown"]?.array, !hours.isEmpty {
                        SectionCard("By hour") {
                            ForEach(Array(hours.enumerated()), id: \.offset) { _, h in
                                HStack { Text(String(format: "%02d:00", h["hour"]?.int ?? 0)).font(Theme.Font.mono); Spacer(); Text("\(h["transactions"]?.int ?? 0) txns").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary); Text(kes(h["sales"])).monospacedDigit() }
                            }
                        }
                    }
                }
            }
        } actions: { Button("Close") { dismiss() } }
        .task { make() }
        .onChange(of: date) { _, _ in make() }
    }

    /// The daily summary's top-level figures are KES at each order's reporting rate (Step 5, `money_basis`).
    private func kes(_ v: JSONValue?) -> String { v.flatMap { Money(json: $0, currency: "KES") }?.formatted() ?? "—" }
    private func row(_ label: String, _ v: JSONValue?) -> some View { HStack { Text(label).font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary); Spacer(); Text(kes(v)).monospacedDigit() } }

    private func make() {
        let api = session.api, oid = outlet.id, d = HubDates.dayString(date)
        summary = Resource { try await api.dailySummary(outletId: oid, date: d) }
        summary?.load()
    }

    private func printSummary() {
        guard let s = summary?.value else { return }
        var h = "<html><body style='font-family:-apple-system,Helvetica;font-size:12px'><h2>Day summary · \(outlet.name) · \(HubDates.dayString(date))</h2>"
        if let b = s["money_basis"]?.string { h += "<div>\(b)</div>" }
        h += "<table>"
        for (l, k) in [("Gross sales", "total_sales"), ("Returns", "total_returns"), ("Net sales", "net_sales"), ("Transactions", "total_transactions"), ("Cash", "cash_sales"), ("M-Pesa", "mpesa_sales"), ("Card", "card_sales"), ("Other", "other_sales")] {
            h += "<tr><td>\(l)</td><td align='right'>\(k == "total_transactions" ? (s[k]?.string ?? "0") : kes(s[k]))</td></tr>"
        }
        h += "</table></body></html>"
        PrintService.printHTML(h, jobName: "Day summary \(HubDates.dayString(date))")
    }
}
