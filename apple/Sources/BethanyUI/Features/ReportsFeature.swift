import SwiftUI
import BethanyKit

/// Reports: every Route.ReportPage on the shared ReportShell frame.
/// Endpoints verified against routes/api.php (report.page groups),
/// ExecutiveReportController, ReportController, EnhancedReportController,
/// ReportPdfController, IntelligenceController and react-admin/src/api/reports.ts.
@MainActor
enum ReportsFeature {
    static func register() {
        FeatureRegistry.register({ if case .report = $0 { return true }; return false }) { route in
            guard case .report(let page) = route else { return AnyView(EmptyView()) }
            switch page {
            case .executive: return AnyView(ExecutiveReportView())
            case .sales: return AnyView(SalesReportView())
            case .customers: return AnyView(CustomersReportView())
            case .finance: return AnyView(FinanceReportView())
            case .production: return AnyView(ProductionReportView())
            case .inventory: return AnyView(InventoryReportView())
            case .procurement: return AnyView(ProcurementReportView())
            case .performance: return AnyView(PerformanceReportView())
            case .signals: return AnyView(SignalsReportView())
            case .explorer: return AnyView(ExplorerReportView())
            case .dataQuality: return AnyView(DataQualityReportView())
            }
        }
    }
}

// MARK: - Payload-driven rendering

/// Keys whose numeric value is money (a KES figure unless the row names a currency).
func isMoneyKey(_ key: String) -> Bool {
    let k = key.lowercased()
    if k.contains("count") || k.contains("orders") || k.hasSuffix("_days") || k.contains("pct") || k.contains("percent") || k.contains("rate") || k.contains("units") || k.contains("qty") || k.contains("quantity") || k.contains("lines") || k.hasSuffix("_id") || k == "id" || k.contains("customers") || k.contains("buyers") || k.contains("checks") || k.contains("skus") || k.contains("items") { return false }
    return ["revenue", "amount", "total", "value", "paid", "balance", "collected", "sales", "cost", "profit", "spent", "refund", "price", "kes", "volume", "expense", "inflow", "outflow", "gross", "net", "margin_", "sold", "due", "held", "budget", "aov", "ltv", "lifetime"].contains { k.contains($0) }
}

/// A table whose columns come from the payload's own keys (first row), so a
/// breakdown is never shown with an invented column.
struct AutoTable: View {
    let rows: [JRow]
    var hide: Set<String> = ["id", "links", "ckey", "product_id", "customer_id", "user_id", "variant_id", "inventory_item_id", "material_id", "outlet_id", "category_id"]
    var currency: String? = nil
    var onTap: ((JRow) -> Void)? = nil

    private var columns: [TableColumn] {
        guard let first = rows.first?.json.object else { return [] }
        let keys = first.keys.sorted { a, b in
            func rank(_ k: String) -> Int { ["name", "label", "title", "ref", "product_name", "customer", "date", "month", "week", "period"].firstIndex(of: k) ?? 50 }
            return rank(a) == rank(b) ? a < b : rank(a) < rank(b)
        }
        return keys.compactMap { k in
            guard !hide.contains(k) else { return nil }
            let v = first[k]
            if v?.array != nil || (v?.object != nil) { return nil }
            if isMoneyKey(k) && (v?.double != nil || v?.isNull == true) { return TableColumn(k.humanized, k, .money, fixedCurrency: currency) }
            if k.contains("pct") || k.contains("percent") || k.hasSuffix("_rate") { return TableColumn(k.humanized, k, .pct) }
            if case .number(let n)? = v { return TableColumn(k.humanized, k, n == n.rounded() ? .int : .number(2)) }
            if k == "status" || k == "severity" || k == "tier" { return TableColumn(k.humanized, k, .status) }
            if k.hasSuffix("_at") || k == "date" || k.hasSuffix("_date") { return TableColumn(k.humanized, k, .date) }
            return TableColumn(k.humanized, k, .text)
        }
    }

    var body: some View { DataTable(columns: columns, rows: rows, onTap: onTap) }
}

/// Scalar fields of an object as detail rows, money by key.
struct AutoPairs: View {
    let json: JSONValue?
    var currency = "KES"
    var body: some View {
        ForEach(jpairs(json).filter { $0.value.array == nil && $0.value.object == nil }, id: \.key) { p in
            DetailRow(p.key.humanized, cell(p.key, p.value))
        }
    }
    private func cell(_ k: String, _ v: JSONValue) -> String {
        if v.isNull { return "—" }
        if isMoneyKey(k), v.double != nil { return fmtMoney(v, currency: currency) }
        if k.contains("pct") || k.contains("percent") || k.hasSuffix("_rate"), let d = v.double { return fmtPct(d) }
        if case .number(let n) = v { return n == n.rounded() ? fmtNumber(n) : fmtNumber(n, decimals: 2) }
        return v.string ?? "—"
    }
}

/// Scalar fields of an object as stat tiles.
struct AutoTiles: View {
    let json: JSONValue?
    var currency = "KES"
    var body: some View {
        TileGrid(minWidth: 170) {
            ForEach(jpairs(json).filter { $0.value.array == nil && $0.value.object == nil && !$0.value.isNull }, id: \.key) { p in
                StatTile(p.key.humanized, value: value(p.key, p.value))
            }
        }
    }
    private func value(_ k: String, _ v: JSONValue) -> String {
        if isMoneyKey(k), v.double != nil { return fmtMoney(v, currency: currency) }
        if k.contains("pct") || k.contains("percent") || k.hasSuffix("_rate"), let d = v.double { return fmtPct(d) }
        if case .number(let n) = v { return n == n.rounded() ? fmtNumber(n) : fmtNumber(n, decimals: 2) }
        return v.string ?? "—"
    }
}

/// Opens a row's server-stated links (`links: {order: "/sales/orders/1"}`).
struct RowLinks: View {
    @Environment(AppEnvironment.self) private var env
    let row: JRow
    var body: some View {
        HStack(spacing: Theme.Space.m) {
            ForEach(jpairs(row["links"]), id: \.key) { l in
                if let to = l.value.string, let route = Route.from(consolePath: to) { Button(l.key.humanized + " →") { env.navigator.go(route) }.font(Theme.Font.caption) }
            }
        }
    }
}

// MARK: - Executive Overview (GET reports/executive, reports/engine-room)

struct ExecutiveReportView: View {
    @Environment(AppEnvironment.self) private var env
    var body: some View {
        ReportShell(page: .executive) { w in
            ReportData("/v1/admin/reports/executive", query: w.periodParams()) { json in
                let k = json["kpis"]
                attention(json["attention"])
                SectionCard("Sales (sales truth: recognised orders, KES at the reporting rate)") {
                    TileGrid(minWidth: 170) {
                        MetricTile(label: "Sold", metric: k?["sales"]?["revenue"], drill: "revenue")
                        MetricTile(label: "Orders", metric: k?["sales"]?["orders"], money: false, drill: "orders")
                        MetricTile(label: "Average order", metric: k?["sales"]?["aov"])
                        MetricTile(label: "New customers", metric: k?["sales"]?["new_customers"], money: false, drill: "new_customers")
                    }
                }
                SectionCard("Money (money truth: settled payments net of refunds)") {
                    TileGrid(minWidth: 170) {
                        MetricTile(label: "Collected", metric: k?["money"]?["collected"], drill: "collected")
                        outstandingTile(k?["money"]?["outstanding"])
                    }
                    if let aging = k?["money"]?["aging"] {
                        HStack(spacing: Theme.Space.s) {
                            ForEach(jrows(aging["buckets"])) { b in
                                DrillLink(metric: "outstanding", title: "Outstanding \(b.str("label") ?? "")", bucket: b.str("key")) {
                                    VStack(alignment: .leading) { Text(b.str("label") ?? "").font(Theme.Font.caption); Text(fmtMoney(b["amount"], currency: "KES")).font(Theme.Font.secondary.weight(.semibold)); Text("\(b.int("orders") ?? 0) orders").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                                        .padding(8).background(Theme.field, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
                                }
                            }
                            if let d = aging["deposits_held"] {
                                DrillLink(metric: "outstanding", title: "Deposits held", bucket: "deposits") {
                                    VStack(alignment: .leading) { Text("Deposits held").font(Theme.Font.caption); Text(fmtMoney(d["amount"], currency: "KES")).font(Theme.Font.secondary.weight(.semibold)); Text("\(d["orders"]?.int ?? 0) orders").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                                        .padding(8).background(Theme.field, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
                                }
                            }
                        }
                    }
                }
                SectionCard("Production") {
                    TileGrid(minWidth: 160) {
                        MetricTile(label: "Completed (pieces)", metric: k?["production"]?["completed"], money: false, drill: "production_completed")
                        MetricTile(label: "On time", metric: k?["production"]?["on_time_pct"], money: false, hint: "% of completed with a due date")
                        StatTile("Work in progress", value: k?["production"]?["wip"]?.string ?? "0")
                        DrillLink(metric: "production_overdue", title: "Overdue production") { StatTile("Overdue", value: k?["production"]?["overdue"]?.string ?? "0", tone: (k?["production"]?["overdue"]?.int ?? 0) > 0 ? .danger : nil) }
                        StatTile("Low stock", value: k?["inventory"]?["low_stock"]?.string ?? "0", tone: (k?["inventory"]?["low_stock"]?.int ?? 0) > 0 ? .warning : nil)
                    }
                }
                if let f = k?["financial"] {
                    SectionCard("Financial (reports.financial)") {
                        TileGrid(minWidth: 170) {
                            MetricTile(label: "Expenses (approved + paid)", metric: f["expenses"], drill: "expenses")
                            MetricTile(label: "Net collected", metric: f["net_collected"], hint: "Collected − expenses")
                        }
                        if let e = f["earned"] {
                            Text("Earned P&L (fully-paid orders at cost snapshot, approved expenses)").font(Theme.Font.secondary.weight(.semibold))
                            TileGrid(minWidth: 160) {
                                StatTile("Earned revenue", value: fmtMoney(e["earned_revenue"], currency: "KES"), hint: "\(e["earned_orders"]?.int ?? 0) orders")
                                StatTile("COGS (estimate)", value: fmtMoney(e["cogs_estimate"], currency: "KES"))
                                StatTile("Gross profit", value: fmtMoney(e["gross_profit"], currency: "KES"), hint: e["gross_margin_pct"]?.double.map { "margin \(fmtPct($0))" })
                                StatTile("Net profit", value: fmtMoney(e["net_profit"], currency: "KES"), hint: "after \(fmtMoney(e["expenses"], currency: "KES")) expenses")
                            }
                            ForEach(Array((e["limits"]?.array ?? []).enumerated()), id: \.offset) { _, l in Label(l.string ?? "", systemImage: "exclamationmark.triangle").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning) }
                        }
                    }
                }
                gaps(json["structural_gaps"])
            }
            ReportData("/v1/admin/reports/engine-room", query: ["outlet_id": w.outletId.map(String.init)]) { json in
                SectionCard("Engine room (as of now)") {
                    engine("Collections", json["collections"], ["money_on_table": "Money on the table"])
                    engine("Stock-out loss", json["stockout"], ["est_daily_loss_now": "Bleeding now / day", "est_lost_revenue_window": "Lost in window", "products_currently_out": "Products out"])
                    engine("Win-back", json["winback"], ["customers_at_risk": "Customers at risk", "annual_value_at_risk": "Annual value at risk", "recovered_revenue_90d": "Recovered (90d)"])
                    engine("Attach rates", json["attach"], ["missed_revenue_estimate_total": "Missed attach (estimate)", "multi_item_pct": "Multi-item baskets"])
                    engine("Replenishment", json["replenishment"], ["due_customers": "Customers due", "expected_revenue": "Expected revenue", "pings_30d": "Pings (30d)"])
                    engine("Seasonal", json["seasonal"], ["total_gap_value": "Stock gap value", "urgent_orders": "Urgent orders"])
                }
            }
        }
    }

    @ViewBuilder
    private func outstandingTile(_ v: JSONValue?) -> some View {
        if v?["current"] != nil { MetricTile(label: "Outstanding", metric: v, drill: "outstanding") }
        else { DrillLink(metric: "outstanding", title: "Outstanding") { StatTile("Outstanding", value: v?.double != nil ? fmtMoney(v, currency: "KES") : (v?["total"]?.double != nil ? fmtMoney(v?["total"], currency: "KES") : (v?["amount"]?.double != nil ? fmtMoney(v?["amount"], currency: "KES") : "—")), hint: "Open recognised orders, KES") } }
    }

    @ViewBuilder
    private func engine(_ title: String, _ s: JSONValue?, _ keys: [String: String]) -> some View {
        HStack(alignment: .top) {
            Text(title).font(Theme.Font.secondary.weight(.semibold)).frame(width: 130, alignment: .leading)
            if let s, !s.isNull {
                ForEach(keys.map { JPair(key: $0.key, value: .string($0.value)) }.sorted { $0.key < $1.key }) { k in
                    if let v = s[k.key] { VStack(alignment: .leading) { Text(k.value.string ?? "").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary); Text(isMoneyKey(k.key) ? fmtMoney(v, currency: "KES") : (k.key.contains("pct") ? fmtPct(v.double) : (v.string ?? "—"))).font(Theme.Font.secondary) } }
                }
            } else { Text("—").foregroundStyle(Theme.textTertiary) }
            Spacer()
        }
        .font(Theme.Font.secondary)
        Divider()
    }

    @ViewBuilder
    private func attention(_ list: JSONValue?) -> some View {
        let rows = jrows(list)
        if !rows.isEmpty {
            SectionCard("Needs attention") {
                ForEach(rows) { a in
                    HStack(alignment: .top) {
                        Image(systemName: a.str("severity") == "high" ? "exclamationmark.octagon.fill" : "exclamationmark.triangle.fill").foregroundStyle(a.str("severity") == "high" ? Theme.Palette.danger : Theme.Palette.warning)
                        VStack(alignment: .leading, spacing: 2) {
                            Text(a.str("title") ?? "").font(Theme.Font.body)
                            if let d = a.str("detail") { Text(d).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                            HStack { ForEach(jrows(a["actions"])) { act in if act.str("type") == "navigate" { ActionLink(act.str("label") ?? "Open", url: act.str("to")) } } }
                        }
                        Spacer()
                        if let link = a.str("link") { ActionLink("Open", url: link) }
                    }
                    .padding(.vertical, 4)
                }
            }
        }
    }

    @ViewBuilder
    private func gaps(_ list: JSONValue?) -> some View {
        let rows = jrows(list)
        if !rows.isEmpty {
            SectionCard("Zeros that are structural, not quiet") {
                ForEach(rows) { g in
                    VStack(alignment: .leading, spacing: 2) { Text(g.str("title") ?? "").font(Theme.Font.secondary.weight(.semibold)); Text(g.str("detail") ?? "").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }.padding(.vertical, 2)
                }
            }
        }
    }
}

// MARK: - Staff, Outlets & Performance (GET reports/performance)

struct PerformanceReportView: View {
    var body: some View {
        ReportShell(page: .performance, csv: { w in CSVExport(path: "/v1/admin/reports/performance", query: w.periodParams(), name: "staff_outlets_performance.csv") }) { w in
            ReportData("/v1/admin/reports/performance", query: w.periodParams()) { json in
                let t = json["totals"]
                TileGrid(minWidth: 170) {
                    DrillLink(metric: "revenue", title: "Sold") { StatTile("Sold", value: fmtMoney(t?["sold"], currency: "KES"), hint: "sales truth, KES at the reporting rate") }
                    StatTile("Orders", value: t?["orders"]?.string ?? "0")
                    StatTile("Collected", value: fmtMoney(t?["collected"], currency: "KES"), hint: "money truth, by payment date")
                }
                let cols = [TableColumn("Name", "name"), TableColumn("Sold", "sold", .money, fixedCurrency: "KES"), TableColumn("Previous", "sold_previous", .money, fixedCurrency: "KES"), TableColumn("Orders", "orders", .int), TableColumn("Prev orders", "orders_previous", .int), TableColumn("Avg order", "aov", .money, fixedCurrency: "KES"), TableColumn("Buyers", "buyers", .int), TableColumn("Collected", "collected", .money, fixedCurrency: "KES"), TableColumn("Unconfirmed carts", "unconfirmed_carts", .int), TableColumn("Unconfirmed value", "unconfirmed_value", .money, fixedCurrency: "KES")]
                SectionCard("Outlets") { BarsChart(points: chartPoints(json["outlets"], label: "name", y: "sold")); DataTable(columns: cols, rows: jrows(json["outlets"])) }
                SectionCard("Salespeople") { DataTable(columns: cols, rows: jrows(json["salespeople"])) }
                BasisCaption("Previous period: \(json["period"]?["previous_start"]?.string?.prefix(10) ?? "") – \(json["period"]?["previous_end"]?.string?.prefix(10) ?? ""), like for like.")
            }
        }
    }
}

// MARK: - Business Explorer (GET reports/explorer, reports/explorer/orders)

struct ExplorerReportView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var by = "month"
    @State private var filters: [String: String] = [:]
    @State private var drillRow: JRow?
    @State private var dims: [(String, String)] = []

    private func query(_ w: ReportWindow) -> [String: String?] {
        var q = w.periodParams(["by": by])
        for (k, v) in filters where !v.isEmpty { q["f_\(k)"] = v }
        return q
    }

    var body: some View {
        ReportShell(page: .explorer, csv: { w in CSVExport(path: "/v1/admin/reports/explorer", query: query(w), name: "business_explorer_\(by).csv") }) { w in
            HStack(spacing: Theme.Space.s) {
                Picker("By", selection: $by) { ForEach(dims.isEmpty ? [("month", "Month")] : dims, id: \.0) { Text($0.1).tag($0.0) } }.frame(maxWidth: 220)
                ForEach(["channel", "salesperson", "currency", "category", "product"], id: \.self) { f in
                    if let v = filters[f], !v.isEmpty { Button { filters[f] = nil } label: { Label("\(f.humanized): \(v)", systemImage: "xmark.circle") }.font(Theme.Font.caption) }
                }
                Spacer()
            }
            .font(Theme.Font.secondary)
            ReportData("/v1/admin/reports/explorer", query: query(w)) { json in
                let measures = json["measures"]?.array?.compactMap(\.string) ?? []
                let cols = [TableColumn(json["dimensions"]?[json["dimension"]?.string ?? by]?["label"]?.string ?? by.humanized, "label")] + measures.map { m in
                    ["sold", "aov", "collected", "line_value"].contains(m) ? TableColumn(m.humanized + " (KES)", m, .money, fixedCurrency: "KES") : TableColumn(m.humanized, m, .int)
                }
                SectionCard("\(json["row_count"]?.int ?? 0) rows (limit \(json["row_limit"]?.int ?? 0)) · level: \(json["level"]?.string ?? "")") {
                    if measures.contains("sold") { BarsChart(points: chartPoints(json["rows"], label: "label", y: "sold")) }
                    DataTable(columns: cols, rows: jrows(json["rows"])) { r in drillRow = r }
                    if let t = json["totals"] { HStack { Text("Totals").font(Theme.Font.secondary.weight(.semibold)); ForEach(measures, id: \.self) { m in Text("\(m.humanized): \(["sold", "aov", "collected", "line_value"].contains(m) ? fmtMoney(t[m], currency: "KES") : (t[m]?.string ?? "—"))").font(Theme.Font.caption) } } }
                    Text("Tap a row to see its orders (or payments, by method), or to narrow the next dimension by it.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                }
                .onAppear { dims = jpairs(json["dimensions"]).map { ($0.key, $0.value["label"]?.string ?? $0.key) } }
            }
            .sheet(item: $drillRow) { r in ExplorerOrdersSheet(window: w, by: by, key: r.str("key") ?? "", label: r.str("label") ?? "", filters: filters) { if ["channel", "salesperson", "currency", "category", "product"].contains(by) { filters[by] = r.str("key") }; drillRow = nil } }
        }
    }
}

struct ExplorerOrdersSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let window: ReportWindow
    let by: String
    let key: String
    let label: String
    let filters: [String: String]
    let narrow: () -> Void
    @State private var page = 1

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            HStack { Text("\(label) · records").font(Theme.Font.sectionTitle); Spacer(); if ["channel", "salesperson", "currency", "category", "product"].contains(by) { Button("Narrow by this") { narrow() } }; Button("Close") { dismiss() } }
            ReportData("/v1/admin/reports/explorer/orders", query: q) { json in
                Text("\(json["total"]?.int ?? 0) records").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                ScrollView {
                    ForEach(jrows(json["rows"])) { r in
                        HStack { VStack(alignment: .leading) { Text(r.str("ref") ?? r.str("order_number") ?? r.str("payment_number") ?? "#\(r.str("id") ?? "")").font(Theme.Font.mono); Text([r.str("customer"), r.str("who"), r.str("detail")].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary); RowLinks(row: r) }; Spacer(); Text(fmtMoney(r["amount"], currency: "KES")).font(Theme.Font.secondary).monospacedDigit() }.padding(.vertical, 4)
                        Divider()
                    }
                }
                if let total = json["total"]?.int, let per = json["per_page"]?.int, per > 0, total > per {
                    HStack { Button("Previous") { page -= 1 }.disabled(page <= 1); Text("\(page)").font(Theme.Font.caption); Button("Next") { page += 1 }.disabled(page * per >= total) }.font(Theme.Font.secondary)
                }
            }
        }
        .padding(Theme.Space.xl).frame(minWidth: 480, idealWidth: 640, minHeight: 400, idealHeight: 560)
    }

    private var q: [String: String?] {
        var q = window.periodParams(["by": by, "key": key, "page": String(page)])
        for (k, v) in filters where !v.isEmpty { q["f_\(k)"] = v }
        return q
    }
}

// MARK: - Audit & Data Quality (GET reports/data-quality)

struct DataQualityReportView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var open: Set<String> = []

    var body: some View {
        ReportShell(page: .dataQuality) { w in
            ReportData("/v1/admin/reports/data-quality", query: w.periodParams()) { json in
                if let c = json["coverage"] {
                    TileGrid(minWidth: 160) {
                        StatTile("Orders in period", value: c["orders"]?.string ?? "0")
                        StatTile("Buyer identified", value: c["buyer_identified"]?.string ?? "—")
                        StatTile("Order lines", value: c["lines"]?.string ?? "0")
                        StatTile("Lines costed", value: c["lines_costed"]?.string ?? "—")
                    }
                }
                ForEach(["sales", "money", "customers"], id: \.self) { group in
                    let checks = jrows(json["checks"]).filter { $0.str("group") == group }
                    if !checks.isEmpty {
                        SectionCard(group.humanized) {
                            ForEach(checks) { c in check(c, rowLimit: json["row_limit"]?.int ?? 50) }
                        }
                    }
                }
                let gaps = jrows(json["gaps"])
                if !gaps.isEmpty {
                    SectionCard("Structural gaps") { ForEach(gaps) { g in VStack(alignment: .leading, spacing: 2) { Text(g.str("title") ?? "").font(Theme.Font.secondary.weight(.semibold)); Text(g.str("detail") ?? "").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary); Text("Affects: \((g["metrics"]?.array ?? []).compactMap(\.string).joined(separator: ", "))").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) } } }
                }
            }
        }
    }

    @ViewBuilder
    private func check(_ c: JRow, rowLimit: Int) -> some View {
        let key = c.str("key") ?? ""
        VStack(alignment: .leading, spacing: 4) {
            HStack(alignment: .top) {
                StatusBadge(c.str("severity"), tone: c.str("severity") == "high" ? .danger : (c.str("severity") == "medium" ? .warning : .neutral))
                VStack(alignment: .leading, spacing: 2) {
                    Text("\(c.str("title") ?? "") · \(c.int("count") ?? 0)").font(Theme.Font.body)
                    Text("Bends: \(c.str("affects") ?? "")\(c["value"].flatMap { $0.isNull ? nil : " · \(fmtMoney($0, currency: "KES"))" } ?? "") · scope: \(c.str("scope") ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                }
                Spacer()
                if let to = c["fix"]?["to"]?.string, !to.isEmpty { ActionLink(c["fix"]?["label"]?.string ?? "Fix", url: to) }
                if (c.int("count") ?? 0) > 0 { Button(open.contains(key) ? "Hide" : "Rows") { if open.contains(key) { open.remove(key) } else { open.insert(key) } }.font(Theme.Font.caption) }
            }
            if open.contains(key) {
                ForEach(jrows(c["rows"])) { r in
                    HStack { VStack(alignment: .leading) { Text(r.str("ref") ?? r.str("customer") ?? r.str("detail") ?? "#\(r.str("id") ?? "")").font(Theme.Font.mono); Text([r.str("customer"), r.str("detail"), r.str("date").map { String($0.prefix(10)) }].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary); RowLinks(row: r) }; Spacer(); if let a = r["amount"], !a.isNull { Text(fmtMoney(a, currency: "KES")).font(Theme.Font.caption).monospacedDigit() } }.padding(.leading, 24)
                }
                if (c.int("count") ?? 0) > rowLimit { Text("Showing the first \(rowLimit) of \(c.int("count") ?? 0).").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary).padding(.leading, 24) }
            }
            Divider()
        }
    }
}
