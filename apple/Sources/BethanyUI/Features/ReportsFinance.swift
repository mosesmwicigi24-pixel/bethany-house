import SwiftUI
import BethanyKit

/// Finance & Cash (reports.financial): P&L, cash flow, expenses, tax, trends
/// (revenue sold vs collected), intelligence (earned P&L, budgets, weekly cash
/// flow, rails). Every figure is KES at the reporting rate; the P&L refuses
/// any other currency (KES_ONLY_REPORT).
struct FinanceReportView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var tab = "pl"
    @State private var compare = false

    var body: some View {
        ReportShell(page: .finance, csv: { w in csv(w) }, pdfType: "financial", scheduleType: "financial") { w in
            SegmentedTabs(tabs: [("pl", "P&L"), ("cashflow", "Cash flow"), ("expenses", "Expenses"), ("tax", "Tax"), ("trends", "Trends"), ("intelligence", "Intelligence")], selection: $tab)
            switch tab {
            case "pl": pl(w)
            case "cashflow": ReportData("/v1/admin/reports/financial/cash-flow", query: w.dateParams()) { j in
                SectionCard("Inflows (settled payments net of refunds, by payment month and method)") { BarsChart(points: chartPoints(j["inflows"], label: "month", y: "inflow")); AutoTable(rows: jrows(j["inflows"]), currency: "KES") }
                SectionCard("Outflows (expenses by expense month, amount_kes)") { BarsChart(points: chartPoints(j["outflows"], label: "month", y: "outflow")); AutoTable(rows: jrows(j["outflows"]), currency: "KES") }
                BasisCaption("Cash truth. Supplier payments are not in these outflows unless the server lists them.")
            }
            case "expenses": ReportData("/v1/admin/reports/financial/expenses", query: w.dateParams()) { j in
                SectionCard("Expenses · \(fmtMoney(j["total"], currency: "KES"))") {
                    BasisCaption(j["total_basis"]?.string)
                    if let p = j["pending_approval"] { DrillLink(metric: "expenses", title: "Expenses") { StatTile("Pending approval (not in the total)", value: fmtMoney(p["total"], currency: "KES"), hint: "\(p["count"]?.int ?? 0) waiting", tone: (p["count"]?.int ?? 0) > 0 ? .amber : nil) } }
                    AutoTable(rows: jrows(j["by_status"]), currency: "KES")
                    LinesChart(points: chartPoints(j["monthly"], label: "month", y: "total", series: "Expenses"))
                }
                SectionCard("Expenses in the period") { AutoTable(rows: jrows(j["expenses"]), currency: "KES") { r in if let id = r.int("id") { env.navigator.go(.expense(id: id)) } } }
            }
            case "tax": ReportData("/v1/admin/reports/financial/tax", query: w.dateParams()) { j in
                SectionCard("Tax on sales (basis: \(j["basis"]?.string ?? "sold"))") {
                    AutoTiles(json: j["totals"])
                    AutoTable(rows: jrows(j["by_tax_rate"]), currency: "KES")
                    if let c = j["conflicting_rate_products"]?.array, !c.isEmpty { Text("\(c.count) product(s) carry more than one tax rate — their lines are reported at the rate on the order line.").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning) }
                }
            }
            case "trends": ReportData("/v1/admin/reports/financial/revenue", query: w.dateParams()) { j in
                let cur = j["period"]?["currency"]?.string ?? "KES"
                SectionCard("\(j["sold"]?["label"]?.string ?? "Sold") · \(fmtMoney(j["sold"]?["total"], currency: cur))") { BasisCaption(j["sold"]?["basis"]?.string); monthly(j["sold"]?["monthly"], cur, "Sold") }
                SectionCard("\(j["collected"]?["label"]?.string ?? "Collected") · \(fmtMoney(j["collected"]?["total"], currency: cur))") { BasisCaption(j["collected"]?["basis"]?.string); monthly(j["collected"]?["monthly"], cur, "Collected") }
                if let r = j["receivable_movement"] { SectionCard("Receivable movement · \(fmtMoney(r["amount"], currency: cur))") { BasisCaption(r["note"]?.string) } }
                BasisCaption(j["note"]?.string)
            }
            case "intelligence": ReportData("/v1/admin/reports/financial-intelligence", query: w.periodParams()) { j in
                SectionCard("Earned P&L (fully-paid orders at cost snapshot; approved + paid expenses)") {
                    let p = j["pnl"]
                    TileGrid(minWidth: 160) {
                        StatTile("Earned revenue", value: fmtMoney(p?["earned_revenue"], currency: "KES"), hint: "\(p?["earned_orders"]?.int ?? 0) orders")
                        StatTile("COGS (estimate)", value: fmtMoney(p?["cogs_estimate"], currency: "KES"), hint: (p?["unpriced_lines"]?.int ?? 0) > 0 ? "\(p?["unpriced_lines"]?.int ?? 0) lines without cost — counted at zero" : nil, tone: (p?["unpriced_lines"]?.int ?? 0) > 0 ? .warning : nil)
                        StatTile("Gross profit", value: fmtMoney(p?["gross_profit"], currency: "KES"), hint: p?["gross_margin_pct"]?.double.map { "margin \(fmtPct($0))" })
                        DrillLink(metric: "expenses", title: "Expenses") { StatTile("Expenses", value: fmtMoney(p?["expenses"], currency: "KES"), hint: "pending approval, not deducted: \(fmtMoney(p?["expenses_pending_approval"]?["amount"], currency: "KES"))") }
                        StatTile("Net profit", value: fmtMoney(p?["net_profit"], currency: "KES"))
                    }
                }
                SectionCard("Expenses by category") { AutoTable(rows: jrows(j["expenses"]), currency: "KES") }
                SectionCard("Cash flow by week (in: settled payments; out: expenses + supplier payments)") { LinesChart(points: chartPoints(j["cash_flow"], label: "week", y: "in", series: "In") + chartPoints(j["cash_flow"], label: "week", y: "out", series: "Out")); AutoTable(rows: jrows(j["cash_flow"]), currency: "KES") }
                SectionCard("Rails (method reconciliation)") { if j["rails"]?.array != nil { AutoTable(rows: jrows(j["rails"]), currency: "KES") } else { AutoPairs(json: j["rails"]) } }
            }
            default: EmptyView()
            }
        }
    }

    private func csv(_ w: ReportWindow) -> CSVExport {
        let path: String
        switch tab {
        case "cashflow": path = "financial/cash-flow"
        case "expenses": path = "financial/expenses"
        case "tax": path = "financial/tax"
        case "trends": path = "financial/revenue"
        case "intelligence": path = "financial-intelligence"
        default: path = "financial/profit-loss"
        }
        return CSVExport(path: "/v1/admin/reports/\(path)", query: tab == "intelligence" ? w.periodParams() : w.dateParams(), name: "finance-\(tab).csv")
    }

    @ViewBuilder
    private func monthly(_ v: JSONValue?, _ cur: String, _ series: String) -> some View {
        if v?.array != nil { LinesChart(points: chartPoints(v, label: "month", y: "total", series: series) + chartPoints(v, label: "month", y: "amount", series: series), currency: cur); AutoTable(rows: jrows(v), currency: cur) }
        else { ForEach(jpairs(v), id: \.key) { p in DetailRow(p.key, fmtMoney(p.value, currency: cur)) } }
    }

    @ViewBuilder
    private func pl(_ w: ReportWindow) -> some View {
        Toggle("Compare with the previous period", isOn: $compare).toggleStyle(.switch).font(Theme.Font.secondary)
        ReportData("/v1/admin/reports/financial/profit-loss", query: w.dateParams(["compare": compare ? "1" : nil])) { j in
            SectionCard("Profit & loss · KES at the reporting rate") {
                TileGrid(minWidth: 160) {
                    DrillLink(metric: "revenue", title: "Sold") { StatTile("Revenue", value: fmtMoney(j["revenue"], currency: "KES"), hint: "recognised sales, by order date") }
                    StatTile("Cost of goods sold", value: fmtMoney(j["cost_of_goods_sold"], currency: "KES"), hint: (j["unpriced_lines"]?.int ?? 0) > 0 ? "\(j["unpriced_lines"]?.int ?? 0) lines without a cost snapshot" : "historical cost snapshots", tone: (j["unpriced_lines"]?.int ?? 0) > 0 ? .warning : nil)
                    StatTile("Gross profit", value: fmtMoney(j["gross_profit"], currency: "KES"), hint: j["gross_profit_margin_percent"]?.double.map { "margin \(fmtPct($0))" })
                    DrillLink(metric: "expenses", title: "Expenses") { StatTile("Operating expenses", value: fmtMoney(j["operating_expenses"], currency: "KES"), hint: "approved + paid; pending: \(fmtMoney(j["expenses_pending_approval"]?["amount"], currency: "KES")) (\(j["expenses_pending_approval"]?["count"]?.int ?? 0))") }
                    StatTile("Net profit", value: fmtMoney(j["net_profit"], currency: "KES"), hint: j["net_margin"]?.double.map { "margin \(fmtPct($0))" })
                    StatTile("Tax on sales", value: fmtMoney(j["tax_collected"], currency: "KES"))
                    StatTile("Discounts given", value: fmtMoney(j["discounts_given"], currency: "KES"))
                }
                Text("Gross profit = recognised net revenue − historical COGS; net profit = gross profit − approved/paid operating expenses (CLAUDE.md §7).").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            }
            SectionCard("Expenses by category") { if j["expenses_by_category"]?.array != nil { AutoTable(rows: jrows(j["expenses_by_category"]), currency: "KES") } else { AutoPairs(json: j["expenses_by_category"]) } }
            if let c = j["comparison"], !c.isNull { SectionCard("Previous period") { AutoPairs(json: c) } }
        }
    }
}
