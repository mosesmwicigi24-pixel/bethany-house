import SwiftUI
import BethanyKit

// Production & Fulfilment, Inventory, Procurement & Suppliers.

struct ProductionReportView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var tab = "overview"
    @State private var costingId = ""

    private var tabs: [(String, String)] {
        var t = [("overview", "Overview"), ("efficiency", "Efficiency"), ("tailors", "Tailors"), ("intelligence", "Intelligence")]
        if env.session.can("reports.financial") { t.append(("costing", "Costing")) }
        return t
    }

    var body: some View {
        ReportShell(page: .production, csv: { w in csv(w) }, pdfType: "production", scheduleType: "production") { w in
            SegmentedTabs(tabs: tabs, selection: $tab)
            switch tab {
            case "overview": ReportData("/v1/admin/reports/production/summary", query: w.dateParams()) { j in
                SectionCard("Summary") { AutoTiles(json: j["summary"]) }
                SectionCard("By product") { AutoTable(rows: jrows(j["by_product"])) }
                SectionCard("By tailor") { AutoTable(rows: jrows(j["by_tailor"])) }
                SectionCard("Daily") { LinesChart(points: chartPoints(j["daily_trend"], label: "date", y: "orders_created", series: "Created") + chartPoints(j["daily_trend"], label: "date", y: "orders_completed", series: "Completed"), currency: nil) }
                SectionCard("By status") { AutoTable(rows: jrows(j["by_status"])) }
            }
            case "efficiency": ReportData("/v1/admin/reports/production/efficiency", query: w.dateParams()) { j in
                SectionCard("Efficiency") { if j["efficiency"]?.array != nil { AutoTable(rows: jrows(j["efficiency"])) } else { AutoPairs(json: j["efficiency"]) } }
            }
            case "tailors": ReportData("/v1/admin/reports/production/tailor-productivity", query: w.dateParams()) { j in
                SectionCard("Tailor productivity") { AutoTable(rows: jrows(j["tailors"])) }
            }
            case "intelligence": ReportData("/v1/admin/reports/production-intelligence", query: w.periodParams()) { j in
                SectionCard("Stage cycle times") { AutoTable(rows: jrows(j["cycle_times"])) }
                SectionCard("Bottlenecks (now)") { AutoTable(rows: jrows(j["bottlenecks"])) }
                SectionCard("Tailor throughput") { AutoTable(rows: jrows(j["tailors"])) }
                SectionCard("QC") { AutoTiles(json: j["qc"]) }
                SectionCard("Capacity outlook") { AutoTiles(json: j["capacity"]) }
                SectionCard("Material demand of open orders") { AutoTable(rows: jrows(j["materials"])) }
            }
            case "costing": costing(w)
            default: EmptyView()
            }
        }
    }

    private func csv(_ w: ReportWindow) -> CSVExport {
        let path = ["efficiency": "production/efficiency", "tailors": "production/tailor-productivity", "intelligence": "production-intelligence", "costing": "production/costing-summary"][tab] ?? "production/summary"
        return CSVExport(path: "/v1/admin/reports/\(path)", query: tab == "intelligence" ? w.periodParams() : w.dateParams(), name: "production-\(tab).csv")
    }

    @ViewBuilder
    private func costing(_ w: ReportWindow) -> some View {
        ReportData("/v1/admin/reports/production/costing-summary", query: w.dateParams()) { j in
            SectionCard("Costing summary · KES only (historical material cost vs revenue)") { AutoTiles(json: j["summary"]) }
            SectionCard("Batches (tap for the full costing report)") {
                AutoTable(rows: jrows(j["orders"]), currency: "KES") { r in if let id = r.int("id") { costingId = String(id) } }
            }
            if let p = j["by_product"] { SectionCard("By product") { AutoTable(rows: jrows(p), currency: "KES") } }
        }
        SectionCard("Product costing & profitability report (production order id)") {
            HStack { TextField("Production order id", text: $costingId).textFieldStyle(.roundedBorder).frame(maxWidth: 200); Spacer() }
            if let id = Int(costingId) { ProductCostingReport(id: id) }
        }
    }
}

/// GET reports/production/costing/{id} (reports.financial): the batch's
/// cost lines, sales, gross/net profit and the server's recommendation.
struct ProductCostingReport: View {
    let id: Int
    @State private var sellingPrice = ""
    @State private var quantitySold = ""
    @State private var labour = ""
    @State private var packaging = ""
    @State private var other = ""
    @State private var delivery = ""
    @State private var commission = ""
    @State private var marketing = ""
    @State private var charges = ""
    @State private var comment = ""
    @State private var applied: [String: String?] = [:]

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.s) {
            Text("Scenario inputs (blank = the server's recorded figures)").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            HStack { DecimalField("Selling price", text: $sellingPrice); DecimalField("Qty sold", text: $quantitySold); DecimalField("Labour", text: $labour); DecimalField("Packaging", text: $packaging); DecimalField("Other", text: $other) }
            HStack { DecimalField("Delivery", text: $delivery); DecimalField("Commission", text: $commission); DecimalField("Marketing", text: $marketing); DecimalField("Payment charges", text: $charges) }
            HStack { TextField("Management comment", text: $comment).textFieldStyle(.roundedBorder); Button("Run") { applied = params } }
            ReportData("/v1/admin/reports/production/costing/\(id)", query: applied) { j in
                let r = j["report"]
                if let h = r?["header"] { AutoPairs(json: h) }
                Text("Cost breakdown").font(Theme.Font.secondary.weight(.semibold))
                AutoTable(rows: jrows(r?["cost_breakdown"]?["lines"]), currency: "KES")
                Text("Total production cost: \(fmtMoney(r?["cost_breakdown"]?["total_production_cost"], currency: "KES"))").font(Theme.Font.secondary)
                ForEach(["cost_summary", "sales_summary", "gross_profit", "net_profit", "margins", "final_summary", "recommendation"], id: \.self) { k in
                    if let s = r?[k] {
                        Text(k.humanized).font(Theme.Font.secondary.weight(.semibold))
                        AutoPairs(json: s)
                        if k == "net_profit", let se = s["selling_expenses"]?.array, !se.isEmpty { AutoTable(rows: jrows(.array(se)), currency: "KES") }
                    }
                }
            }
        }
    }

    private var params: [String: String?] {
        ["selling_price": jstr(sellingPrice)?.string, "quantity_sold": jstr(quantitySold)?.string, "labour_cost": jstr(labour)?.string, "packaging_cost": jstr(packaging)?.string, "other_costs": jstr(other)?.string, "delivery_cost": jstr(delivery)?.string, "commission": jstr(commission)?.string, "marketing_cost": jstr(marketing)?.string, "payment_charges": jstr(charges)?.string, "management_comment": jstr(comment)?.string]
    }
}

struct InventoryReportView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var tab = "stock"
    @State private var lowOnly = false

    var body: some View {
        ReportShell(page: .inventory, csv: { w in csv(w) }, pdfType: "inventory", scheduleType: "inventory", dated: ["movements", "intelligence"].contains(tab)) { w in
            SegmentedTabs(tabs: [("stock", "Stock on hand"), ("low", "Low stock"), ("valuation", "Valuation"), ("aging", "Aging"), ("movements", "Movements"), ("intelligence", "Intelligence"), ("stockout", "Stock-out loss")], selection: $tab)
            let outlet: [String: String?] = ["outlet_id": w.outletId.map(String.init)]
            switch tab {
            case "stock":
                Toggle("Low stock only", isOn: $lowOnly).toggleStyle(.switch).font(Theme.Font.secondary)
                ReportData("/v1/admin/reports/inventory/stock-on-hand", query: outlet.merging(["low_stock_only": lowOnly ? "1" : nil]) { $1 }) { j in
                    SectionCard("Totals") { AutoTiles(json: j["totals"]) ; BasisCaption("Stock value needs cost (products.view_cost); the server sends null without it.") }
                    SectionCard("Items") { AutoTable(rows: jrows(j["items"]), currency: "KES") { r in if let id = r.int("product_id") { env.navigator.go(.product(id: id)) } } }
                }
            case "low": ReportData("/v1/admin/reports/inventory/low-stock", query: outlet) { j in
                SectionCard("Low stock · \(j["total_count"]?.int ?? 0)") { AutoTable(rows: jrows(j["low_stock_items"])) }
            }
            case "valuation": ReportData("/v1/admin/reports/inventory/valuation", query: outlet) { j in
                SectionCard("Valuation at retail (basis: \(j["grand_totals"]?["basis"]?.string ?? "available"))") { AutoTiles(json: j["grand_totals"]) ; BasisCaption("Retail value at the KES regular price, not cost — inventory truth is historical cost; see Aging for cost where it exists.") }
                SectionCard("By category and outlet") { AutoTable(rows: jrows(j["breakdown"]), currency: "KES") }
            }
            case "aging": ReportData("/v1/admin/reports/inventory/aging", query: w.periodParams()) { j in
                SectionCard("Stock by time since it last moved") { AutoTable(rows: jrows(j["buckets"]), currency: "KES"); AutoTiles(json: j["totals"]) ; BasisCaption(j["turnover"]?["note"]?.string); AutoPairs(json: j["turnover"]) }
                SectionCard("Slow items") { AutoTable(rows: jrows(j["slow_items"]), currency: "KES") { r in if let id = r.int("id") { env.navigator.go(.product(id: id)) } } }
            }
            case "movements": ReportData("/v1/admin/reports/inventory/movement", query: w.dateParams()) { j in
                SectionCard("By type") { AutoTable(rows: jrows(j["by_type"])) }
                SectionCard("Transactions") { AutoTable(rows: jrows(j["transactions"])) }
            }
            case "intelligence": ReportData("/v1/admin/reports/inventory-intelligence", query: w.periodParams()) { j in
                SectionCard("Health") { AutoTiles(json: j["health"]) }
                SectionCard("ABC classification") { if let c = j["abc"]?["classes"] { if c.array != nil { AutoTable(rows: jrows(c), currency: "KES") } else { AutoPairs(json: c) } }; AutoTable(rows: jrows(j["abc"]?["items"]), currency: "KES") }
                SectionCard("Stock-out risks") { AutoTable(rows: jrows(j["stockout_risks"])) }
                SectionCard("Dead stock") { if j["dead_stock"]?.array != nil { AutoTable(rows: jrows(j["dead_stock"]), currency: "KES") } else { AutoPairs(json: j["dead_stock"]) } }
                SectionCard("Shrinkage") { AutoPairs(json: j["shrinkage"]) }
                SectionCard("Materials") { AutoPairs(json: j["materials"]); AutoTable(rows: jrows(j["materials"]?["materials"]), currency: "KES") }
            }
            case "stockout": ReportData("/v1/admin/reports/stockout-loss", query: outlet) { j in
                SectionCard("Stock-out revenue loss (trailing window; measured-confidence products in totals)") { AutoTiles(json: j["summary"]) }
                SectionCard("Products") { AutoTable(rows: jrows(j["products"]), currency: "KES") { r in if let id = r.int("product_id") { env.navigator.go(.product(id: id)) } } }
            }
            default: EmptyView()
            }
        }
    }

    private func csv(_ w: ReportWindow) -> CSVExport {
        let path = ["low": "inventory/low-stock", "valuation": "inventory/valuation", "aging": "inventory/aging", "movements": "inventory/movement", "intelligence": "inventory-intelligence", "stockout": "stockout-loss"][tab] ?? "inventory/stock-on-hand"
        return CSVExport(path: "/v1/admin/reports/\(path)", query: ["aging", "intelligence"].contains(tab) ? w.periodParams() : w.dateParams(), name: "inventory-\(tab).csv")
    }
}

struct ProcurementReportView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var tab = "overview"

    var body: some View {
        ReportShell(page: .procurement, csv: { w in csv(w) }, pdfType: "procurement", scheduleType: "procurement", dated: tab != "seasonal") { w in
            SegmentedTabs(tabs: [("overview", "Overview"), ("suppliers", "Suppliers"), ("items", "Items"), ("intelligence", "Intelligence"), ("seasonal", "Seasonal demand")], selection: $tab)
            switch tab {
            case "overview", "suppliers", "items": ReportData("/v1/admin/reports/purchase-orders", query: w.dateParams()) { j in
                if tab == "overview" {
                    SectionCard("Purchase orders · KES at the reporting rate") { AutoTiles(json: j["summary"]) }
                    SectionCard("Monthly") { BarsChart(points: chartPoints(j["monthly_trend"], label: "month", y: "total_value")); AutoTable(rows: jrows(j["monthly_trend"]), currency: "KES") }
                    SectionCard("By status") { AutoTable(rows: jrows(j["by_status"]), currency: "KES") }
                } else if tab == "suppliers" {
                    SectionCard("By supplier") { AutoTable(rows: jrows(j["by_supplier"]), currency: "KES") }
                } else {
                    SectionCard("Top items") { AutoTable(rows: jrows(j["top_items"]), currency: "KES") }
                }
            }
            case "intelligence": ReportData("/v1/admin/reports/procurement-intelligence", query: w.periodParams()) { j in
                SectionCard("Supplier scorecard") { AutoTable(rows: jrows(j["suppliers"]), currency: "KES") }
                SectionCard("Purchase suggestions") { AutoTable(rows: jrows(j["suggestions"]), currency: "KES") }
                SectionCard("Open purchase orders") { AutoTiles(json: j["open_pos"]) }
            }
            case "seasonal": ReportData("/v1/admin/reports/seasonal-demand", query: ["outlet_id": w.outletId.map(String.init)]) { j in
                SectionCard("Seasonal demand planning (120-day horizon)") {
                    AutoTiles(json: j["summary"])
                    if (j["summary"]?["history_depth_days"]?.int ?? 0) == 0 { Text("No legacy history imported yet; projections use hub velocity only.").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning) }
                }
                ForEach(jrows(j["seasons"])) { s in
                    SectionCard("\(s.str("label") ?? "") · \(s.str("start")?.prefix(10) ?? "") – \(s.str("end")?.prefix(10) ?? "") · in \(s.int("days_until_start") ?? 0) days\(s.str("sub_of").map { " (inside \($0))" } ?? "")") {
                        AutoTable(rows: jrows(s["products"]), currency: "KES") { r in if let id = r.int("product_id") { env.navigator.go(.product(id: id)) } }
                    }
                }
            }
            default: EmptyView()
            }
        }
    }

    private func csv(_ w: ReportWindow) -> CSVExport {
        switch tab {
        case "intelligence": return CSVExport(path: "/v1/admin/reports/procurement-intelligence", query: w.periodParams(), name: "procurement-intelligence.csv")
        case "seasonal": return CSVExport(path: "/v1/admin/reports/seasonal-demand", query: ["outlet_id": w.outletId.map(String.init)], name: "seasonal-demand.csv")
        default: return CSVExport(path: "/v1/admin/reports/purchase-orders", query: w.dateParams(), name: "purchase-orders.csv")
        }
    }
}
