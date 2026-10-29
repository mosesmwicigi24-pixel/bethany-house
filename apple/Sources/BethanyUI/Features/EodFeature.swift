import SwiftUI
import BethanyKit

/// EoD review (EodReportsPage.tsx → pos/reports/eod-admin, pos.eod_review)
/// and EoD delivery settings (EodReportSettingsPage.tsx → pos/reports/eod-settings).
@MainActor
enum EodFeature {
    static func register() {
        FeatureRegistry.register(.eodReports) { _ in AnyView(EodReportsView()) }
        FeatureRegistry.register(.eodSettings) { _ in AnyView(EodSettingsView()) }
    }
}

// MARK: - Reports list

struct EodReportsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var from = Calendar.current.date(byAdding: .day, value: -30, to: Date()) ?? Date()
    @State private var to = Date()
    @State private var outletId = 0
    @State private var userId = 0
    @State private var page = 1
    @State private var result: Resource<JSONValue>?
    @State private var selectedId: ReportID?

    var body: some View {
        Page("EoD Reports", subtitle: "Every cashier's end-of-day report, with the figures the Hub holds for that day") {
            HStack(spacing: Theme.Space.m) {
                DatePicker("From", selection: $from, displayedComponents: .date)
                DatePicker("To", selection: $to, in: from..., displayedComponents: .date)
            }
            .font(Theme.Font.secondary)
            if let r = result?.value {
                HStack(spacing: Theme.Space.m) {
                    Picker("Outlet", selection: $outletId) {
                        Text("All outlets").tag(0)
                        ForEach(Array((r["outlets"]?.array ?? []).enumerated()), id: \.offset) { _, o in Text(o["name"]?.string ?? "").tag(o["id"]?.int ?? 0) }
                    }
                    Picker("Cashier", selection: $userId) {
                        Text("All cashiers").tag(0)
                        ForEach(Array((r["users"]?.array ?? []).enumerated()), id: \.offset) { _, u in Text(u["name"]?.string ?? "").tag(u["id"]?.int ?? 0) }
                    }
                }
                .font(Theme.Font.secondary)
            }
            if let result {
                LoadableView(result.state, retry: { result.load() }, isEmpty: { ($0["data"]?.array ?? []).isEmpty }, empty: { EmptyState("No reports in this range", symbol: "moon.stars") }) { r in
                    LazyVStack(spacing: Theme.Space.s) {
                        ForEach(Array((r["data"]?.array ?? []).enumerated()), id: \.offset) { _, row in
                            Button { selectedId = row["id"]?.int.map(ReportID.init) } label: { reportRow(row) }.buttonStyle(.plain)
                        }
                    }
                    if let meta = r["meta"] {
                        HStack {
                            Text("Showing \(meta["from"]?.string ?? "0")–\(meta["to"]?.string ?? "0") of \(meta["total"]?.string ?? "0")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                            Spacer()
                            Button("Previous") { page = max(1, page - 1) }.disabled((meta["current_page"]?.int ?? 1) <= 1)
                            Button("Next") { page += 1 }.disabled((meta["current_page"]?.int ?? 1) >= (meta["last_page"]?.int ?? 1))
                        }
                    }
                }
            }
        }
        .task { load() }
        .onChange(of: from) { _, _ in page = 1; load() }
        .onChange(of: to) { _, _ in page = 1; load() }
        .onChange(of: outletId) { _, _ in page = 1; load() }
        .onChange(of: userId) { _, _ in page = 1; load() }
        .onChange(of: page) { _, _ in load() }
        .refreshable { await result?.reload() }
        .sheet(item: $selectedId) { r in EodReportDetailSheet(id: r.id) { result?.load() } }
    }

    private func reportRow(_ row: JSONValue) -> some View {
        HStack(alignment: .top) {
            VStack(alignment: .leading, spacing: 3) {
                HStack {
                    Text(row["user_name"]?.string ?? "").font(Theme.Font.body)
                    Text(row["outlet_name"]?.string ?? "").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                    if row["acknowledged_at"]?.isNull == false { StatusBadge("acknowledged", tone: .success) } else { StatusBadge("to read", tone: .amber) }
                }
                Text("\(row["report_date"]?.string ?? "") · \(row["order_count"]?.int ?? 0) orders · \(row["note_count"]?.int ?? 0) notes\(row["has_sentiments"]?.bool == true ? " · has a write-up" : "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                if let by = row["sales_by_currency"]?.array, by.count > 1 || by.first?["currency_code"]?.string != "KES" {
                    Text(by.map { "\($0["currency_code"]?.string ?? "") \(Money(json: $0["amount"] ?? .null, currency: $0["currency_code"]?.string ?? "KES")?.formatted() ?? "")" }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                }
            }
            Spacer()
            VStack(alignment: .trailing, spacing: 2) {
                MoneyText(Money(json: row["total_sales"] ?? .null, currency: "KES"))
                Text("paid \(Money(json: row["total_paid"] ?? .null, currency: "KES")?.formatted() ?? "—")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                if let b = Money(json: row["total_balance"] ?? .null, currency: "KES"), !b.isZero { Text("owed \(b.formatted())").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning) }
                Text(row["money_basis"]?.string ?? "").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            }
        }
        .padding(Theme.Space.m).frame(maxWidth: .infinity).background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.field)).contentShape(Rectangle())
    }

    private func load() {
        let c = env.session.client
        let q: [String: String?] = [
            "from": HubDates.dayString(from), "to": HubDates.dayString(to),
            "outlet_id": outletId == 0 ? nil : String(outletId), "user_id": userId == 0 ? nil : String(userId),
            "page": String(page), "per_page": "20",
        ]
        result = Resource { try await c.get("\(PosAPI.base)/reports/eod-admin", query: q).json ?? .null }
        result?.load()
    }
}

struct ReportID: Identifiable, Hashable { let id: Int }

// MARK: - Report detail (eod-admin/{id}, acknowledge, comments)

struct EodReportDetailSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let id: Int
    let onChanged: () -> Void
    @State private var report: Resource<JSONValue>?
    @State private var comment = ""
    @State private var busy = false

    var body: some View {
        PosSheetFrame("End of Day report") {
            if let report {
                LoadableView(report.state, retry: { report.load() }) { r in content(r) }
            }
        } actions: { Button("Close") { dismiss() } }
        .task {
            if report == nil {
                let c = env.session.client, rid = id
                report = Resource { try await c.get("\(PosAPI.base)/reports/eod-admin/\(rid)").json?["report"] ?? .null }
                report?.load()
            }
        }
    }

    @ViewBuilder
    private func content(_ r: JSONValue) -> some View {
        let isAuthor = r["user_id"]?.int == env.session.me?.id
        let acknowledged = r["acknowledged_at"]?.isNull == false
        HStack {
            VStack(alignment: .leading, spacing: 2) {
                Text("\(r["user_name"]?.string ?? "") · \(r["outlet_name"]?.string ?? "")").font(Theme.Font.sectionTitle)
                Text("\(r["report_date"]?.string ?? "") · submitted \(r["submitted_at"]?.date.map { DateFormatterCache.dateTime.string(from: $0) } ?? "—")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            }
            Spacer()
            if acknowledged { StatusBadge("acknowledged", tone: .success) }
            else if !isAuthor { Button(busy ? "…" : "Acknowledge") { Task { await acknowledge() } }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand).disabled(busy) }
        }
        TileGrid(minWidth: 140) {
            StatTile("Orders", value: r["order_count"]?.string ?? "0")
            StatTile("Sales", value: plainFigure(r["total_sales"]))
            StatTile("Paid", value: plainFigure(r["total_paid"]))
            StatTile("Balance", value: plainFigure(r["total_balance"]))
        }
        Text("Figures as the Hub returns them for this report, in the orders' own currency. The list shows the shilling figure at the reporting rate.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        let orders = r["orders"]?.array ?? []
        if !orders.isEmpty {
            SectionCard("Orders") {
                ForEach(Array(orders.enumerated()), id: \.offset) { _, o in
                    VStack(alignment: .leading, spacing: 2) {
                        HStack { Text(o["order_number"]?.string ?? "").font(Theme.Font.body); Text(o["customer_name"]?.string ?? "").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary); StatusBadge(o["payment_status"]?.string); Spacer(); Text(plainFigure(o["total_amount"])).monospacedDigit() }
                        HStack { Text("paid \(plainFigure(o["amount_paid"])) · balance \(plainFigure(o["balance"]))").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                        if let n = o["eod_note"]?.string, !n.isEmpty { Text(n).font(Theme.Font.caption).italic() }
                    }
                    .padding(.vertical, 3)
                }
            }
        }
        if let s = r["sentiments"]?.string, !s.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            SectionCard("How the day went") { Text(plainText(s)).font(Theme.Font.body) }
        }
        SectionCard("Conversation") {
            let comments = r["comments"]?.array ?? []
            if comments.isEmpty { Text("No comments yet.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
            ForEach(Array(comments.enumerated()), id: \.offset) { _, c in
                VStack(alignment: .leading, spacing: 2) {
                    HStack { Text(c["user_name"]?.string ?? "").font(Theme.Font.caption).fontWeight(.semibold); DateText(c["created_at"]?.date, style: .relative) }
                    Text(c["body"]?.string ?? "").font(Theme.Font.secondary)
                }
                .padding(.vertical, 3)
            }
            HStack {
                TextField(isAuthor ? "Reply" : "Ask the cashier a question", text: $comment).textFieldStyle(.roundedBorder)
                Button("Send") { Task { await send() } }.disabled(comment.trimmingCharacters(in: .whitespaces).isEmpty || busy)
            }
        }
    }

    /// A server figure with no currency stated by the endpoint: shown as sent, never converted.
    private func plainFigure(_ v: JSONValue?) -> String { v.flatMap { Money(json: $0, currency: "") }.map { plainAmount($0) } ?? "—" }

    /// The write-up is stored as light HTML (bold, italic, bullets); shown as text here.
    private func plainText(_ html: String) -> String {
        var s = html.replacingOccurrences(of: "<br\\s*/?>", with: "\n", options: .regularExpression)
        s = s.replacingOccurrences(of: "</p>|</div>|</li>", with: "\n", options: .regularExpression)
        s = s.replacingOccurrences(of: "<li>", with: "• ")
        s = s.replacingOccurrences(of: "<[^>]+>", with: "", options: .regularExpression)
        return s.replacingOccurrences(of: "&nbsp;", with: " ").replacingOccurrences(of: "&amp;", with: "&").replacingOccurrences(of: "&lt;", with: "<").replacingOccurrences(of: "&gt;", with: ">").trimmingCharacters(in: .whitespacesAndNewlines)
    }

    private func acknowledge() async {
        busy = true; defer { busy = false }
        do {
            let r = try await env.session.client.post("\(PosAPI.base)/reports/eod-admin/\(id)/acknowledge").json
            env.toasts.show(r?["message"]?.string ?? "Report acknowledged")
            await report?.reload(); onChanged()
        } catch { env.toasts.error(error) }
    }

    private func send() async {
        busy = true; defer { busy = false }
        do {
            _ = try await env.session.client.post("\(PosAPI.base)/reports/eod/\(id)/comments", json: .object(["body": .string(comment.trimmingCharacters(in: .whitespaces))]))
            comment = ""
            await report?.reload(); onChanged()
        } catch { env.toasts.error(error) }
    }
}

// MARK: - Delivery settings (GET/POST pos/reports/eod-settings, POST …/test)

struct EodSettingsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var loaded = false
    @State private var emailEnabled = false
    @State private var emailRecipients = ""
    @State private var emailFrequency = "daily"
    @State private var emailTime = "21:00"
    @State private var slackEnabled = false
    @State private var slackWebhook = ""
    @State private var slackFrequency = "daily"
    @State private var slackTime = "21:00"
    @State private var whatsappEnabled = false
    @State private var whatsappRecipients = ""
    @State private var outletIds: Set<Int> = []
    @State private var outlets: [PosOutlet] = []
    @State private var busy = false
    @State private var error: APIError?

    private static let frequencies = [("daily", "Daily"), ("weekly", "Weekly"), ("off", "Off")]
    private var canEdit: Bool { env.session.can("settings.edit") }

    var body: some View {
        Page("EoD Settings", subtitle: "When and where the consolidated end-of-day report is sent") {
            if !loaded { LoadingSkeleton() } else {
                SectionCard("Email") {
                    Toggle("Send by email", isOn: $emailEnabled).toggleStyle(.switch)
                    TextField("Recipients, comma-separated", text: $emailRecipients, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(1...3)
                    FieldError(error?.errors["email_recipients"]?.first)
                    frequencyRow($emailFrequency, time: $emailTime)
                    Button("Send a test email") { Task { await test("email") } }.disabled(busy || !canEdit)
                }
                SectionCard("Slack") {
                    Toggle("Send to Slack", isOn: $slackEnabled).toggleStyle(.switch)
                    TextField("Incoming webhook URL", text: $slackWebhook).textFieldStyle(.roundedBorder)
                    FieldError(error?.errors["slack_webhook"]?.first)
                    frequencyRow($slackFrequency, time: $slackTime)
                    Button("Send a test to Slack") { Task { await test("slack") } }.disabled(busy || !canEdit)
                }
                SectionCard("WhatsApp") {
                    Toggle("Send by WhatsApp", isOn: $whatsappEnabled).toggleStyle(.switch)
                    TextField("Numbers (E.164), comma-separated", text: $whatsappRecipients).textFieldStyle(.roundedBorder)
                    FieldError(error?.errors["whatsapp_recipients"]?.first)
                    Button("Send a WhatsApp test") { Task { await test("whatsapp") } }.disabled(busy || !canEdit)
                }
                SectionCard("Outlets (none selected = all)") {
                    ForEach(outlets) { o in
                        Toggle(o.name, isOn: Binding(get: { outletIds.contains(o.id) }, set: { on in if on { outletIds.insert(o.id) } else { outletIds.remove(o.id) } })).toggleStyle(.switch).font(Theme.Font.secondary)
                    }
                    if outlets.isEmpty { Text("Outlets load from pos/outlets (pos.access).").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                }
                if let e = error, e.errors.isEmpty { Text(e.message).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
                Button(busy ? "Saving…" : "Save settings") { Task { await save() } }.buttonStyle(.primary).disabled(busy || !canEdit).frame(maxWidth: 320)
                if !canEdit { Text("Viewing only: saving needs settings.edit.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
            }
        }
        .task { await load() }
    }

    private func frequencyRow(_ frequency: Binding<String>, time: Binding<String>) -> some View {
        HStack {
            Picker("Frequency", selection: frequency) { ForEach(Self.frequencies, id: \.0) { Text($0.1).tag($0.0) } }.frame(maxWidth: 220)
            TextField("HH:MM", text: time).textFieldStyle(.roundedBorder).frame(width: 80).monospacedDigit()
            Text("Nairobi time").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
    }

    private func load() async {
        let c = env.session.client
        if let s = try? await c.get("\(PosAPI.base)/reports/eod-settings").json?["settings"] {
            emailEnabled = s["email_enabled"]?.bool ?? false; emailRecipients = s["email_recipients"]?.string ?? ""
            emailFrequency = s["email_frequency"]?.string ?? "daily"; emailTime = s["email_time"]?.string ?? "21:00"
            slackEnabled = s["slack_enabled"]?.bool ?? false; slackWebhook = s["slack_webhook"]?.string ?? ""
            slackFrequency = s["slack_frequency"]?.string ?? "daily"; slackTime = s["slack_time"]?.string ?? "21:00"
            whatsappEnabled = s["whatsapp_enabled"]?.bool ?? false; whatsappRecipients = s["whatsapp_recipients"]?.string ?? ""
            outletIds = Set((s["outlet_ids"]?.array ?? []).compactMap(\.int))
        }
        outlets = (try? await PosAPI(client: c).outlets()) ?? []
        loaded = true
    }

    private var payload: JSONValue {
        .object([
            "email_enabled": .bool(emailEnabled), "email_recipients": .string(emailRecipients), "email_frequency": .string(emailFrequency), "email_time": .string(emailTime),
            "slack_enabled": .bool(slackEnabled), "slack_webhook": .string(slackWebhook), "slack_frequency": .string(slackFrequency), "slack_time": .string(slackTime),
            "whatsapp_enabled": .bool(whatsappEnabled), "whatsapp_recipients": .string(whatsappRecipients),
            "outlet_ids": .array(outletIds.sorted().map { .number(Double($0)) }),
        ])
    }

    private func save() async {
        busy = true; defer { busy = false }
        do {
            let r = try await env.session.client.post("\(PosAPI.base)/reports/eod-settings", json: payload)
            error = nil
            env.toasts.show(r.json?["message"]?.string ?? "EoD delivery settings saved")
        } catch let e as APIError { error = e } catch { self.error = .transport(error.localizedDescription) }
    }

    /// Live form values are sent as overrides so a test works before saving (testEodDelivery).
    private func test(_ channel: String) async {
        busy = true; defer { busy = false }
        do {
            let r = try await env.session.client.post("\(PosAPI.base)/reports/eod-settings/test", json: jsonObject([
                "channel": .string(channel),
                "email_recipients": channel == "email" ? .string(emailRecipients) : nil,
                "slack_webhook": channel == "slack" ? .string(slackWebhook) : nil,
                "whatsapp_recipients": channel == "whatsapp" ? .string(whatsappRecipients) : nil,
            ]))
            env.toasts.show(r.json?["message"]?.string ?? "Test queued")
        } catch { env.toasts.error(error) }
    }
}
