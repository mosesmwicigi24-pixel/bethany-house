package ke.co.bethanyhouse.hub.ui.features.reports

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.width
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.arr
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.components.BarsChart
import ke.co.bethanyhouse.hub.ui.components.BasisCaption
import ke.co.bethanyhouse.hub.ui.components.DecimalField
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.LinesChart
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.chartPoints
import ke.co.bethanyhouse.hub.ui.components.fmtMoney
import ke.co.bethanyhouse.hub.ui.components.jrows
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.serialization.json.JsonArray

// Production & Fulfilment, Inventory, Procurement & Suppliers.

@Composable
fun ProductionReportScreen() {
    val env = LocalEnv.current
    var tab by remember { mutableStateOf("overview") }
    var costingId by remember { mutableStateOf("") }
    val tabs = remember(env.session.current) {
        val t = mutableListOf("overview" to "Overview", "efficiency" to "Efficiency", "tailors" to "Tailors", "intelligence" to "Intelligence")
        if (env.session.current.can("reports.financial")) t += "costing" to "Costing"
        t.toList()
    }
    fun csv(w: ReportWindow): CSVExport {
        val path = mapOf("efficiency" to "production/efficiency", "tailors" to "production/tailor-productivity", "intelligence" to "production-intelligence", "costing" to "production/costing-summary")[tab] ?: "production/summary"
        return CSVExport("/v1/admin/reports/$path", if (tab == "intelligence") w.periodParams() else w.dateParams(), "production-$tab.csv")
    }

    ReportShell(page = Route.ReportPage.PRODUCTION, csv = ::csv, pdfType = "production", scheduleType = "production") { w ->
        SegmentedTabs(tabs, tab) { tab = it }
        when (tab) {
            "overview" -> ReportData("/v1/admin/reports/production/summary", query = w.dateParams()) { j ->
                SectionCard("Summary") { AutoTiles(j["summary"]) }
                SectionCard("By product") { AutoTable(rows = jrows(j["by_product"])) }
                SectionCard("By tailor") { AutoTable(rows = jrows(j["by_tailor"])) }
                SectionCard("Daily") { LinesChart(points = chartPoints(j["daily_trend"], label = "date", y = "orders_created", series = "Created") + chartPoints(j["daily_trend"], label = "date", y = "orders_completed", series = "Completed"), currency = null) }
                SectionCard("By status") { AutoTable(rows = jrows(j["by_status"])) }
            }
            "efficiency" -> ReportData("/v1/admin/reports/production/efficiency", query = w.dateParams()) { j ->
                SectionCard("Efficiency") { if (j["efficiency"].arr != null) AutoTable(rows = jrows(j["efficiency"])) else AutoPairs(j["efficiency"]) }
            }
            "tailors" -> ReportData("/v1/admin/reports/production/tailor-productivity", query = w.dateParams()) { j ->
                SectionCard("Tailor productivity") { AutoTable(rows = jrows(j["tailors"])) }
            }
            "intelligence" -> ReportData("/v1/admin/reports/production-intelligence", query = w.periodParams()) { j ->
                SectionCard("Stage cycle times") { AutoTable(rows = jrows(j["cycle_times"])) }
                SectionCard("Bottlenecks (now)") { AutoTable(rows = jrows(j["bottlenecks"])) }
                SectionCard("Tailor throughput") { AutoTable(rows = jrows(j["tailors"])) }
                SectionCard("QC") { AutoTiles(j["qc"]) }
                SectionCard("Capacity outlook") { AutoTiles(j["capacity"]) }
                SectionCard("Material demand of open orders") { AutoTable(rows = jrows(j["materials"])) }
            }
            "costing" -> {
                ReportData("/v1/admin/reports/production/costing-summary", query = w.dateParams()) { j ->
                    SectionCard("Costing summary · KES only (historical material cost vs revenue)") { AutoTiles(j["summary"]) }
                    SectionCard("Batches (tap for the full costing report)") {
                        AutoTable(rows = jrows(j["orders"]), currency = "KES") { r -> r.int("id")?.let { costingId = it.toString() } }
                    }
                    j["by_product"]?.takeUnless { it.isNull }?.let { p -> SectionCard("By product") { AutoTable(rows = jrows(p), currency = "KES") } }
                }
                SectionCard("Product costing & profitability report (production order id)") {
                    Field("Production order id", costingId, { costingId = it.filter { c -> c.isDigit() } }, Modifier.width(200.dp), keyboard = KeyboardType.Number)
                    costingId.toIntOrNull()?.let { ProductCostingReport(it) }
                }
            }
        }
    }
}

/** GET reports/production/costing/{id} (reports.financial): the batch's cost lines, sales, gross/net profit and the server's recommendation. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun ProductCostingReport(id: Int) {
    var sellingPrice by remember { mutableStateOf("") }
    var quantitySold by remember { mutableStateOf("") }
    var labour by remember { mutableStateOf("") }
    var packaging by remember { mutableStateOf("") }
    var other by remember { mutableStateOf("") }
    var delivery by remember { mutableStateOf("") }
    var commission by remember { mutableStateOf("") }
    var marketing by remember { mutableStateOf("") }
    var charges by remember { mutableStateOf("") }
    var comment by remember { mutableStateOf("") }
    var applied by remember { mutableStateOf<Map<String, String?>>(emptyMap()) }
    fun opt(s: String): String? = s.trim().ifEmpty { null }
    val params: Map<String, String?> = mapOf(
        "selling_price" to opt(sellingPrice), "quantity_sold" to opt(quantitySold), "labour_cost" to opt(labour), "packaging_cost" to opt(packaging), "other_costs" to opt(other),
        "delivery_cost" to opt(delivery), "commission" to opt(commission), "marketing_cost" to opt(marketing), "payment_charges" to opt(charges), "management_comment" to opt(comment),
    )
    val half = Modifier.width(150.dp)
    Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
        Text("Scenario inputs (blank = the server's recorded figures)", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
            DecimalField("Selling price", sellingPrice, { sellingPrice = it }, half); DecimalField("Qty sold", quantitySold, { quantitySold = it }, half)
            DecimalField("Labour", labour, { labour = it }, half); DecimalField("Packaging", packaging, { packaging = it }, half); DecimalField("Other", other, { other = it }, half)
            DecimalField("Delivery", delivery, { delivery = it }, half); DecimalField("Commission", commission, { commission = it }, half)
            DecimalField("Marketing", marketing, { marketing = it }, half); DecimalField("Payment charges", charges, { charges = it }, half)
        }
        Field("Management comment", comment, { comment = it })
        Button(onClick = { applied = params }) { Text("Run") }
        ReportData("/v1/admin/reports/production/costing/$id", query = applied) { j ->
            val r = j["report"]
            r["header"]?.takeUnless { it.isNull }?.let { AutoPairs(it) }
            Text("Cost breakdown", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
            AutoTable(rows = jrows(r["cost_breakdown"]["lines"]), currency = "KES")
            Text("Total production cost: ${fmtMoney(r["cost_breakdown"]["total_production_cost"], "KES")}", style = MaterialTheme.typography.bodySmall)
            listOf("cost_summary", "sales_summary", "gross_profit", "net_profit", "margins", "final_summary", "recommendation").forEach { k ->
                r[k]?.takeUnless { it.isNull }?.let { s ->
                    Text(k.humanized, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                    AutoPairs(s)
                    if (k == "net_profit") s["selling_expenses"].arr?.takeIf { it.isNotEmpty() }?.let { AutoTable(rows = jrows(JsonArray(it)), currency = "KES") }
                }
            }
        }
    }
}

@Composable
fun InventoryReportScreen() {
    val env = LocalEnv.current
    var tab by remember { mutableStateOf("stock") }
    var lowOnly by remember { mutableStateOf(false) }
    fun csv(w: ReportWindow): CSVExport {
        val path = mapOf("low" to "inventory/low-stock", "valuation" to "inventory/valuation", "aging" to "inventory/aging", "movements" to "inventory/movement", "intelligence" to "inventory-intelligence", "stockout" to "stockout-loss")[tab] ?: "inventory/stock-on-hand"
        return CSVExport("/v1/admin/reports/$path", if (tab in listOf("aging", "intelligence")) w.periodParams() else w.dateParams(), "inventory-$tab.csv")
    }

    ReportShell(page = Route.ReportPage.INVENTORY, csv = ::csv, pdfType = "inventory", scheduleType = "inventory", dated = tab in listOf("movements", "intelligence")) { w ->
        SegmentedTabs(listOf("stock" to "Stock on hand", "low" to "Low stock", "valuation" to "Valuation", "aging" to "Aging", "movements" to "Movements", "intelligence" to "Intelligence", "stockout" to "Stock-out loss"), tab) { tab = it }
        val outlet = w.outletParams()
        when (tab) {
            "stock" -> {
                ToggleRow("Low stock only", lowOnly, { lowOnly = it })
                ReportData("/v1/admin/reports/inventory/stock-on-hand", query = outlet + ("low_stock_only" to if (lowOnly) "1" else null)) { j ->
                    SectionCard("Totals") { AutoTiles(j["totals"]); BasisCaption("Stock value needs cost (products.view_cost); the server sends null without it.") }
                    SectionCard("Items") { AutoTable(rows = jrows(j["items"]), currency = "KES") { r -> r.int("product_id")?.let { env.navigator.push(Route.Product(it)) } } }
                }
            }
            "low" -> ReportData("/v1/admin/reports/inventory/low-stock", query = outlet) { j ->
                SectionCard("Low stock · ${j["total_count"].int ?: 0}") { AutoTable(rows = jrows(j["low_stock_items"])) }
            }
            "valuation" -> ReportData("/v1/admin/reports/inventory/valuation", query = outlet) { j ->
                SectionCard("Valuation at retail (basis: ${j["grand_totals"]["basis"].str ?: "available"})") { AutoTiles(j["grand_totals"]); BasisCaption("Retail value at the KES regular price, not cost — inventory truth is historical cost; see Aging for cost where it exists.") }
                SectionCard("By category and outlet") { AutoTable(rows = jrows(j["breakdown"]), currency = "KES") }
            }
            "aging" -> ReportData("/v1/admin/reports/inventory/aging", query = w.periodParams()) { j ->
                SectionCard("Stock by time since it last moved") { AutoTable(rows = jrows(j["buckets"]), currency = "KES"); AutoTiles(j["totals"]); BasisCaption(j["turnover"]["note"].str); AutoPairs(j["turnover"]) }
                SectionCard("Slow items") { AutoTable(rows = jrows(j["slow_items"]), currency = "KES") { r -> r.int("id")?.let { env.navigator.push(Route.Product(it)) } } }
            }
            "movements" -> ReportData("/v1/admin/reports/inventory/movement", query = w.dateParams()) { j ->
                SectionCard("By type") { AutoTable(rows = jrows(j["by_type"])) }
                SectionCard("Transactions") { AutoTable(rows = jrows(j["transactions"])) }
            }
            "intelligence" -> ReportData("/v1/admin/reports/inventory-intelligence", query = w.periodParams()) { j ->
                SectionCard("Health") { AutoTiles(j["health"]) }
                SectionCard("ABC classification") {
                    j["abc"]["classes"]?.let { c -> if (c.arr != null) AutoTable(rows = jrows(c), currency = "KES") else AutoPairs(c) }
                    AutoTable(rows = jrows(j["abc"]["items"]), currency = "KES")
                }
                SectionCard("Stock-out risks") { AutoTable(rows = jrows(j["stockout_risks"])) }
                SectionCard("Dead stock") { if (j["dead_stock"].arr != null) AutoTable(rows = jrows(j["dead_stock"]), currency = "KES") else AutoPairs(j["dead_stock"]) }
                SectionCard("Shrinkage") { AutoPairs(j["shrinkage"]) }
                SectionCard("Materials") { AutoPairs(j["materials"]); AutoTable(rows = jrows(j["materials"]["materials"]), currency = "KES") }
            }
            "stockout" -> ReportData("/v1/admin/reports/stockout-loss", query = outlet) { j ->
                SectionCard("Stock-out revenue loss (trailing window; measured-confidence products in totals)") { AutoTiles(j["summary"]) }
                SectionCard("Products") { AutoTable(rows = jrows(j["products"]), currency = "KES") { r -> r.int("product_id")?.let { env.navigator.push(Route.Product(it)) } } }
            }
        }
    }
}

@Composable
fun ProcurementReportScreen() {
    val env = LocalEnv.current
    var tab by remember { mutableStateOf("overview") }
    fun csv(w: ReportWindow): CSVExport = when (tab) {
        "intelligence" -> CSVExport("/v1/admin/reports/procurement-intelligence", w.periodParams(), "procurement-intelligence.csv")
        "seasonal" -> CSVExport("/v1/admin/reports/seasonal-demand", w.outletParams(), "seasonal-demand.csv")
        else -> CSVExport("/v1/admin/reports/purchase-orders", w.dateParams(), "purchase-orders.csv")
    }

    ReportShell(page = Route.ReportPage.PROCUREMENT, csv = ::csv, pdfType = "procurement", scheduleType = "procurement", dated = tab != "seasonal") { w ->
        SegmentedTabs(listOf("overview" to "Overview", "suppliers" to "Suppliers", "items" to "Items", "intelligence" to "Intelligence", "seasonal" to "Seasonal demand"), tab) { tab = it }
        when (tab) {
            "overview", "suppliers", "items" -> ReportData("/v1/admin/reports/purchase-orders", query = w.dateParams()) { j ->
                when (tab) {
                    "overview" -> {
                        SectionCard("Purchase orders · KES at the reporting rate") { AutoTiles(j["summary"]) }
                        SectionCard("Monthly") { BarsChart(points = chartPoints(j["monthly_trend"], label = "month", y = "total_value")); AutoTable(rows = jrows(j["monthly_trend"]), currency = "KES") }
                        SectionCard("By status") { AutoTable(rows = jrows(j["by_status"]), currency = "KES") }
                    }
                    "suppliers" -> SectionCard("By supplier") { AutoTable(rows = jrows(j["by_supplier"]), currency = "KES") }
                    else -> SectionCard("Top items") { AutoTable(rows = jrows(j["top_items"]), currency = "KES") }
                }
            }
            "intelligence" -> ReportData("/v1/admin/reports/procurement-intelligence", query = w.periodParams()) { j ->
                SectionCard("Supplier scorecard") { AutoTable(rows = jrows(j["suppliers"]), currency = "KES") }
                SectionCard("Purchase suggestions") { AutoTable(rows = jrows(j["suggestions"]), currency = "KES") }
                SectionCard("Open purchase orders") { AutoTiles(j["open_pos"]) }
            }
            "seasonal" -> ReportData("/v1/admin/reports/seasonal-demand", query = w.outletParams()) { j ->
                SectionCard("Seasonal demand planning (120-day horizon)") {
                    AutoTiles(j["summary"])
                    if ((j["summary"]["history_depth_days"].int ?: 0) == 0) Text("No legacy history imported yet; projections use hub velocity only.", style = MaterialTheme.typography.labelSmall, color = Palette.warning)
                }
                jrows(j["seasons"]).forEach { s ->
                    SectionCard("${s.str("label") ?: ""} · ${s.str("start")?.take(10) ?: ""} – ${s.str("end")?.take(10) ?: ""} · in ${s.int("days_until_start") ?: 0} days${s.str("sub_of")?.let { " (inside $it)" } ?: ""}") {
                        AutoTable(rows = jrows(s["products"]), currency = "KES") { r -> r.int("product_id")?.let { env.navigator.push(Route.Product(it)) } }
                    }
                }
            }
        }
    }
}
