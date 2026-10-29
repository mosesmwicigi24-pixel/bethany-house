import SwiftUI
import BethanyKit

/// Sales & Orders: the ReportController sales/* endpoints as tabs, plus the
/// ledger and the unconfirmed queue (order-pipeline). Dates go as
/// start_date/end_date (report.window normalises); every money figure is
/// in the payload's `currency` (KES at the reporting rate by default).
struct SalesReportView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var tab = "summary"
    @State private var compare = false

    private var tabs: [(String, String)] {
        var t = [("summary", "Summary"), ("by-product", "By product"), ("by-category", "By category"), ("by-customer", "By customer"), ("by-outlet", "By outlet"), ("by-payment-method", "By payment method"), ("returns", "Returns"), ("ledger", "Ledger"), ("unconfirmed", "Unconfirmed")]
        if env.session.can("reports.customers") { t.append(("neema", "Neema")) }
        return t
    }

    var body: some View {
        ReportShell(page: .sales, csv: { w in csv(w) }, pdfType: "sales", scheduleType: "sales", dated: tab != "unconfirmed") { w in
            SegmentedTabs(tabs: tabs, selection: $tab)
            switch tab {
            case "summary": summary(w)
            case "by-product": ReportData("/v1/admin/reports/sales/by-product", query: w.dateParams(["limit": "50"])) { j in breakdown(j, key: "products", chartLabel: "name_en", chartY: "total_revenue") }
            case "by-category": ReportData("/v1/admin/reports/sales/by-category", query: w.dateParams()) { j in breakdown(j, key: "categories", chartLabel: "category_name", chartY: "total_revenue") }
            case "by-customer": ReportData("/v1/admin/reports/sales/by-customer", query: w.dateParams(["limit": "50"])) { j in breakdown(j, key: "customers", chartLabel: "name", chartY: "total_spent") }
            case "by-outlet": ReportData("/v1/admin/reports/sales/by-outlet", query: w.dateParams()) { j in breakdown(j, key: "outlets", chartLabel: "outlet_name", chartY: "total_revenue") }
            case "by-payment-method": ReportData("/v1/admin/reports/sales/by-payment-method", query: w.dateParams()) { j in
                SectionCard("Payment methods · total \(fmtMoney(j["total"], currency: "KES"))") { BasisCaption(j["basis"]?.string); AutoTable(rows: jrows(j["payment_methods"]), currency: "KES") }
            }
            case "returns": ReportData("/v1/admin/reports/sales/returns", query: w.dateParams()) { j in
                SectionCard("Returns") { AutoTiles(json: j["summary"]) }
                SectionCard("By reason") { AutoTable(rows: jrows(j["by_reason"])) }
                SectionCard("By item reason") { AutoTable(rows: jrows(j["by_item_reason"])) }
            }
            case "ledger": ledger(w)
            case "unconfirmed": pipeline(w)
            case "neema": NeemaSection(window: w)
            default: EmptyView()
            }
        }
    }

    private func csv(_ w: ReportWindow) -> CSVExport {
        switch tab {
        case "unconfirmed": return CSVExport(path: "/v1/admin/reports/order-pipeline", query: ["outlet_id": w.outletId.map(String.init)], name: "unconfirmed-orders.csv")
        case "neema": return CSVExport(path: "/v1/admin/reports/sales/neema", query: w.dateParams(), name: "neema.csv")
        default: return CSVExport(path: "/v1/admin/reports/sales/\(tab)", query: w.dateParams(), name: "sales-\(tab).csv")
        }
    }

    @ViewBuilder
    private func summary(_ w: ReportWindow) -> some View {
        Toggle("Compare with the previous period", isOn: $compare).toggleStyle(.switch).font(Theme.Font.secondary)
        ReportData("/v1/admin/reports/sales/summary", query: w.dateParams(["compare": compare ? "1" : nil])) { j in
            let cur = j["currency"]?.string ?? "KES"
            let s = j["summary"]
            SectionCard("Summary · \(cur)") {
                TileGrid(minWidth: 170) {
                    DrillLink(metric: "revenue", title: "Sold") { StatTile("Revenue (sales truth)", value: fmtMoney(s?["total_revenue"], currency: cur), hint: "recognised orders, by order date") }
                    StatTile("Orders", value: s?["total_orders"]?.string ?? "0")
                    StatTile("Average order", value: fmtMoney(s?["average_order_value"], currency: cur))
                    StatTile("Collected (money truth)", value: fmtMoney(s?["total_collected"], currency: cur), hint: "basis: \(j["collected_basis"]?.string ?? "payment date"), net of refunds")
                }
                AutoPairs(json: s, currency: cur)
                CurrencyNotes(json: j)
                BasisCaption(cur == "KES" ? "KES at the reporting rate (128 KES/USD), never the customer pricing rate." : "Shown natively in \(cur); only orders in this currency.")
            }
            if let c = j["comparison"], !c.isNull {
                SectionCard("Previous period \(c["period"]?["start"]?.string?.prefix(10) ?? "") – \(c["period"]?["end"]?.string?.prefix(10) ?? "")") { AutoPairs(json: c, currency: cur) }
            }
            SectionCard("Daily") { LinesChart(points: chartPoints(j["daily_breakdown"], label: "date", y: "revenue", series: "Revenue"), currency: cur); AutoTable(rows: jrows(j["daily_breakdown"]), currency: cur) }
            SectionCard("Weekly") { AutoTable(rows: jrows(j["weekly_breakdown"]), currency: cur) }
            SectionCard("By payment method (collected, by payment date)") { AutoTable(rows: jrows(j["by_payment_method"]), currency: cur) }
            SectionCard("By hour") { BarsChart(points: chartPoints(j["by_hour"], label: "hour", y: "revenue"), currency: cur) }
            SectionCard("By day of week") { BarsChart(points: chartPoints(j["by_day_of_week"], label: "day", y: "revenue"), currency: cur) }
        }
    }

    @ViewBuilder
    private func breakdown(_ j: JSONValue, key: String, chartLabel: String, chartY: String) -> some View {
        let cur = j["currency"]?.string ?? "KES"
        SectionCard(key.humanized + " · \(cur)") {
            BarsChart(points: Array(chartPoints(j[key], label: chartLabel, y: chartY).prefix(15)), currency: cur)
            AutoTable(rows: jrows(j[key]), currency: cur) { r in
                if key == "products", let id = r.int("id") { env.navigator.go(.product(id: id)) }
                if key == "customers", let id = r.int("customer_id") ?? r.int("id") { env.navigator.go(.customer(id: id)) }
            }
            CurrencyNotes(json: j)
        }
    }

    @ViewBuilder
    private func ledger(_ w: ReportWindow) -> some View {
        ReportData("/v1/admin/reports/sales/ledger", query: w.dateParams()) { j in
            let cur = j["period"]?["currency"]?.string ?? "KES"
            SectionCard("Channels · sales = paid + balance, paid attributed to the order's period") {
                DataTable(columns: [TableColumn("Channel", "label"), TableColumn("Orders", "orders", .int), TableColumn("Sales", "sales", .money, fixedCurrency: cur), TableColumn("Paid", "paid", .money, fixedCurrency: cur), TableColumn("Balance", "balance", .money, fixedCurrency: cur)], rows: jrows(j["channels"]))
            }
            if let r = j["reconciliation"] {
                SectionCard("Reconciliation") {
                    DetailRow("Recognised sales", fmtMoney(r["recognised_sales"], currency: cur))
                    DetailRow("Pipeline (unconfirmed carts)", fmtMoney(r["pipeline_sales"], currency: cur))
                    DetailRow("Gross order book", fmtMoney(r["gross_order_book"], currency: cur))
                    BasisCaption(r["note"]?.string)
                }
            }
            SectionCard("By stage per channel") {
                ForEach(jrows(j["by_stage"])) { ch in
                    Text(ch.str("label") ?? "").font(Theme.Font.secondary.weight(.semibold))
                    ForEach(["confirmed", "processed", "completed"], id: \.self) { st in
                        if let f = ch["stages"]?[st] { HStack { Text(st.humanized).frame(width: 100, alignment: .leading); Text("\(f["orders"]?.int ?? 0) orders"); Spacer(); Text("sales \(fmtMoney(f["sales"], currency: cur)) · paid \(fmtMoney(f["paid"], currency: cur)) · balance \(fmtMoney(f["balance"], currency: cur))") }.font(Theme.Font.caption) }
                    }
                }
            }
            SectionCard("Daily") { LinesChart(points: chartPoints(j["daily"], label: "date", y: "sales", series: "Sales") + chartPoints(j["daily"], label: "date", y: "paid", series: "Paid"), currency: cur); AutoTable(rows: jrows(j["daily"]), currency: cur) }
            SectionCard("Weekly") { bucketTable(j["weekly"], cur) }
            SectionCard("Monthly") { bucketTable(j["monthly"], cur) }
        }
    }

    private func bucketTable(_ v: JSONValue?, _ cur: String) -> some View {
        DataTable(columns: [TableColumn("Period", "period"), TableColumn("Orders", "orders", .int), TableColumn("Sales", "sales", .money, fixedCurrency: cur), TableColumn("Paid", "paid", .money, fixedCurrency: cur), TableColumn("Balance", "balance", .money, fixedCurrency: cur)],
                  rows: jrows(v).map { r in var o = r["total"]?.object ?? [:]; o["period"] = r["period"] ?? .null; return JRow(id: r.id, json: .object(o)) })
    }

    @ViewBuilder
    private func pipeline(_ w: ReportWindow) -> some View {
        ReportData("/v1/admin/reports/order-pipeline", query: ["outlet_id": w.outletId.map(String.init), "sort": "value"]) { j in
            let s = j["summary"]
            SectionCard("Unconfirmed carts (pipeline truth — never income)") {
                TileGrid(minWidth: 170) {
                    StatTile("Carts", value: s?["orders"]?.string ?? "0")
                    StatTile("Value", value: fmtMoney(s?["value"], currency: s?["currency"]?.string ?? "KES"), hint: (s?["excluded_non_kes_orders"]?.int ?? 0) > 0 ? "\(s?["excluded_non_kes_orders"]?.int ?? 0) orders without a reporting rate listed, not counted" : nil)
                }
                AutoTable(rows: jrows(j["aging"]))
                AutoTable(rows: jrows(j["by_channel"]))
            }
            SectionCard("Orders, largest recoverable money first") {
                DataTable(columns: [TableColumn("Order", "order_number"), TableColumn("Channel", "channel", .status), TableColumn("Customer", "customer_name"), TableColumn("Phone", "customer_phone"), TableColumn("Items", "item_count", .int), TableColumn("Age (days)", "age_days", .int), TableColumn("Total", "total_amount", .money, currencyKey: "currency_code"), TableColumn("KES", "total_kes", .money, fixedCurrency: "KES")], rows: jrows(j["orders"])) { r in
                    if let id = r.int("id") { env.navigator.go(.order(id: id)) }
                }
            }
        }
    }
}

/// Neema (AI agent) performance — GET reports/sales/neema (Customers & Neema page gate).
struct NeemaSection: View {
    let window: ReportWindow
    var body: some View {
        ReportData("/v1/admin/reports/sales/neema", query: window.dateParams()) { j in
            let cur = j["period"]?["currency"]?.string ?? "KES"
            SectionCard("Leads") {
                TileGrid(minWidth: 150) {
                    StatTile("Leads", value: j["leads"]?["total"]?.string ?? "0")
                    StatTile("Won rate", value: fmtPct(j["leads"]?["won_rate"]?.double))
                    StatTile("Converted (\(j["lead_conversion"]?["window_days"]?.int ?? 14)d)", value: j["lead_conversion"]?["converted"]?.string ?? "0", hint: "rate \(fmtPct(j["lead_conversion"]?["conversion_rate"]?.double))")
                    StatTile("Converted revenue", value: fmtMoney(j["lead_conversion"]?["revenue"], currency: cur), hint: "\(j["lead_conversion"]?["orders"]?.int ?? 0) orders")
                }
                AutoPairs(json: j["leads"]?["by_status"])
                AutoTable(rows: jrows(j["leads"]?["by_intent"]))
            }
            SectionCard("WhatsApp sales") { AutoTiles(json: j["whatsapp_sales"], currency: cur) }
            SectionCard("Contacts per channel") { AutoTable(rows: jrows(j["contacts"])) }
            SectionCard("Message volume") {
                ForEach(jrows(j["message_volume"]?["channels"])) { c in
                    HStack { Text((c.str("channel") ?? "").capitalizedFirst).frame(width: 100, alignment: .leading); Text("period \(c["period"]?["messages"]?.int ?? 0) (\(c["period"]?["inbound"]?.int ?? 0) in)"); Spacer(); Text("all time \(c["all_time"]?["messages"]?.int ?? 0)") }.font(Theme.Font.secondary)
                }
                BasisCaption(j["message_volume"]?["daily_since"]?.string.map { "Daily snapshots collect from \($0); period figures before that are structurally zero." })
            }
        }
    }
}
