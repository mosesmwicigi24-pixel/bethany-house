import SwiftUI
import BethanyKit

/// Customers & Neema: overview (customers/summary), LTV, retention, second
/// purchase, win-back (+ outreach log and logging a contact), institutions,
/// geography and channels (intelligence.view), replenishment, Neema.
struct CustomersReportView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var tab = "overview"
    @State private var logging: JRow?

    private var tabs: [(String, String)] {
        var t = [("overview", "Overview"), ("ltv", "Lifetime value"), ("retention", "Retention"), ("secondpurchase", "Second purchase"), ("winback", "Win-back"), ("outreachlog", "Outreach log"), ("institutions", "Institutions"), ("replenishment", "Replenishment"), ("neema", "Neema")]
        if env.session.can("intelligence.view") { t.append(("geography", "Geography")); t.append(("channels", "Channels")) }
        return t
    }
    private var dated: Bool { ["overview", "ltv", "retention", "neema"].contains(tab) }

    var body: some View {
        ReportShell(page: .customers, csv: { w in csv(w) }, pdfType: "customers", scheduleType: "customers", dated: dated) { w in
            SegmentedTabs(tabs: tabs, selection: $tab)
            let outlet: [String: String?] = ["outlet_id": w.outletId.map(String.init)]
            switch tab {
            case "overview": ReportData("/v1/admin/reports/customers/summary", query: w.dateParams()) { j in
                SectionCard("Customers in the period") { AutoTiles(json: j) ; AutoTable(rows: jrows(j["acquisition_trend"])) }
            }
            case "ltv": ReportData("/v1/admin/reports/customers/lifetime-value", query: w.dateParams(["limit": "50"])) { j in
                SectionCard("Lifetime value · \(j["currency"]?.string ?? "KES")") { AutoTable(rows: jrows(j["customers"]), currency: j["currency"]?.string ?? "KES") { r in if let id = r.int("id") { env.navigator.go(.customer(id: id)) } } }
            }
            case "retention": ReportData("/v1/admin/reports/customers/retention", query: w.dateParams()) { j in
                SectionCard("Cohort retention (customers who bought again, by month)") {
                    ForEach(jrows(j["retention"])) { c in
                        HStack(alignment: .top) { Text("\(c.str("cohort") ?? "") (\(c.int("size") ?? 0))").font(Theme.Font.secondary.weight(.semibold)).frame(width: 120, alignment: .leading); Text(jpairs(c["months"]).map { "\($0.key): \($0.value.int ?? 0)" }.joined(separator: "  ")).font(Theme.Font.caption) }
                        Divider()
                    }
                }
            }
            case "secondpurchase": ReportData("/v1/admin/reports/second-purchase", query: outlet) { j in
                SectionCard("First → second purchase") { AutoTiles(json: j["summary"]); AutoTable(rows: jrows(j["cohorts"])) }
                SectionCard("Recent one-time buyers (\(j["worklist_total"]?.int ?? 0), last \(j["recent_days"]?.int ?? 0) days)") { AutoTable(rows: jrows(j["worklist"]), hide: ["order_id", "email"]) { r in if let id = r.int("order_id") { env.navigator.go(.order(id: id)) } } }
            }
            case "winback": ReportData("/v1/admin/reports/win-back", query: outlet) { j in
                SectionCard("Dormant customers against their own rhythm") { AutoTiles(json: j["summary"]) }
                SectionCard("Customers at risk") {
                    ForEach(jrows(j["customers"])) { c in
                        HStack(alignment: .top) {
                            VStack(alignment: .leading, spacing: 2) {
                                HStack { Text(c.str("name") ?? "").font(Theme.Font.body); Text(String(format: "%.1f× overdue", c.dbl("urgency") ?? 0)).font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning) }
                                Text("\(c.str("phone") ?? "—") · quiet \(c.int("days_quiet") ?? 0)d · usual gap \(c.int("median_gap_days") ?? 0)d · \(c.int("orders_365") ?? 0) orders/yr").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                if let o = c["last_outreach"], !o.isNull { Text("Last contact: \(o["channel"]?.string ?? "") \(o["at"]?.string?.prefix(10) ?? "") by \(o["by_name"]?.string ?? "—")").font(Theme.Font.caption) }
                                if let r = c["recovered"], !r.isNull { Text("Won back: \(r["order_number"]?.string ?? "") \(fmtMoney(r["amount"], currency: "KES"))").font(Theme.Font.caption).foregroundStyle(Theme.Palette.success) }
                            }
                            Spacer()
                            VStack(alignment: .trailing) { Text(fmtMoney(c["revenue_365"], currency: "KES")).font(Theme.Font.secondary.weight(.semibold)); Text("at risk / yr").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                            if env.session.can("customers.insights") { Button("Log contact") { logging = c } }
                            if let id = c.int("customer_id") { Button("Open") { env.navigator.go(.customer(id: id)) } }
                        }
                        .font(Theme.Font.secondary).padding(.vertical, 4)
                        Divider()
                    }
                }
            }
            case "outreachlog": ReportData("/v1/admin/reports/outreach-log", query: [:]) { j in
                SectionCard("Outreach (automated radar pings + manual win-back), newest first") { AutoTiles(json: j["summary"]); AutoTable(rows: jrows(j["rows"])) }
            }
            case "institutions": ReportData("/v1/admin/reports/institutions", query: outlet) { j in
                SectionCard("Institutional accounts (trailing 365 days)") { AutoTiles(json: j["summary"]) }
                SectionCard("Accounts") {
                    ForEach(jrows(j["accounts"])) { a in
                        VStack(alignment: .leading, spacing: 2) {
                            HStack { Text(a.str("name") ?? "").font(Theme.Font.body); if a["risk"]?.bool == true { StatusBadge("at risk", tone: .warning) }; Spacer(); Text(fmtMoney(a["revenue_365"], currency: "KES")).font(Theme.Font.secondary.weight(.semibold)) }
                            Text("\(a.str("phone") ?? "—") · \(a.int("orders_365") ?? 0) orders · quiet \(a.int("days_quiet") ?? 0)d · \(a.int("buyer_contacts") ?? 0) buyer contact(s) · top: \(a.str("top_product") ?? "—")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                            if let x = a["cross_sell"]?.array, !x.isEmpty { Text("Cross-sell: " + x.map { "\($0["name"]?.string ?? "") (\(fmtPct($0["adoption_pct"]?.double, decimals: 0)))" }.joined(separator: ", ")).font(Theme.Font.caption) }
                        }
                        .padding(.vertical, 4)
                        Divider()
                    }
                }
            }
            case "replenishment": ReportData("/v1/admin/reports/replenishment", query: outlet) { j in
                SectionCard("Replenishment radar (as of now)") { AutoTiles(json: j["summary"]) }
                SectionCard("Due") { AutoTable(rows: jrows(j["due"])) { r in if let id = r.int("product_id") { env.navigator.go(.product(id: id)) } } }
            }
            case "geography": ReportData("/v1/admin/intelligence/customer-geography", query: [:]) { j in
                SectionCard("Where customers are") { AutoTiles(json: j["summary"]); AutoTable(rows: jrows(j["countries"])) }
            }
            case "channels": ReportData("/v1/admin/intelligence/channel-engagement", query: [:]) { j in
                SectionCard("Messaging channels") { AutoTiles(json: j["summary"]); AutoTable(rows: jrows(j["channels"])) }
                SectionCard("Most active customers") { AutoTable(rows: jrows(j["top_customers"]).map { JRow(id: $0.id, json: .object(($0.json.object ?? [:]).merging(["channels": .string(($0["channels"]?.array ?? []).compactMap(\.string).joined(separator: ", "))]) { $1 })) }) }
            }
            case "neema": NeemaSection(window: w)
            default: EmptyView()
            }
        }
        .sheet(item: $logging) { c in WinBackOutreachSheet(customer: c) { logging = nil } }
    }

    private func csv(_ w: ReportWindow) -> CSVExport {
        switch tab {
        case "ltv": return CSVExport(path: "/v1/admin/reports/customers/lifetime-value", query: w.dateParams(), name: "customers-ltv.csv")
        case "retention": return CSVExport(path: "/v1/admin/reports/customers/retention", query: w.dateParams(), name: "customers-retention.csv")
        case "neema": return CSVExport(path: "/v1/admin/reports/sales/neema", query: w.dateParams(), name: "neema.csv")
        default: return CSVExport(path: "/v1/admin/reports/customers/summary", query: w.dateParams(), name: "customers-summary.csv")
        }
    }
}

/// POST reports/win-back/outreach (customers.insights): logs a manual contact;
/// the server stamps the user, snapshots revenue/days quiet and dedupes within 24h.
struct WinBackOutreachSheet: View {
    @Environment(AppEnvironment.self) private var env
    let customer: JRow
    let done: () -> Void
    @State private var channel = "whatsapp"
    @State private var busy = false
    var body: some View {
        BHFormSheet("Log a contact with \(customer.str("name") ?? "")", primary: "Log", busy: busy, action: run) {
            Picker("Channel", selection: $channel) { Text("WhatsApp").tag("whatsapp"); Text("Call").tag("call"); Text("Other").tag("other") }.pickerStyle(.segmented)
            Text("Recorded against \(customer.str("phone") ?? "this customer"); a recovery is attributed to the first order within 30 days.").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
        }
    }
    private func run() async {
        busy = true; defer { busy = false }
        var body: [String: JSONValue] = ["name": .string(customer.str("name") ?? ""), "channel": .string(channel)]
        body.put("customer_id", customer.int("customer_id").map { .number(Double($0)) }); body.put("phone", customer.str("phone").map { .string($0) })
        body.put("revenue_365", customer["revenue_365"]); body.put("days_quiet", customer["days_quiet"])
        do {
            let r = try await env.session.client.post("/v1/admin/reports/win-back/outreach", json: .object(body))
            env.toasts.show(r.json?["already_logged"]?.bool == true ? "Already logged in the last 24 hours" : "Contact logged", tone: r.json?["already_logged"]?.bool == true ? .info : .success)
            done()
        } catch { env.toasts.error(error) }
    }
}
