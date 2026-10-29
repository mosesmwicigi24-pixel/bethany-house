package ke.co.bethanyhouse.hub.ui.features.reports

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Error
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.MoneyByCurrency
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.arr
import ke.co.bethanyhouse.hub.core.dbl
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.obj
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.ActionLink
import ke.co.bethanyhouse.hub.ui.components.BarsChart
import ke.co.bethanyhouse.hub.ui.components.BasisCaption
import ke.co.bethanyhouse.hub.ui.components.DataTable
import ke.co.bethanyhouse.hub.ui.components.DetailRow
import ke.co.bethanyhouse.hub.ui.components.InfoSheet
import ke.co.bethanyhouse.hub.ui.components.JRow
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.StatTile
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.TableColumn
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.chartPoints
import ke.co.bethanyhouse.hub.ui.components.fmtMoney
import ke.co.bethanyhouse.hub.ui.components.fmtNumber
import ke.co.bethanyhouse.hub.ui.components.fmtPct
import ke.co.bethanyhouse.hub.ui.components.jpairs
import ke.co.bethanyhouse.hub.ui.components.jrows
import ke.co.bethanyhouse.hub.ui.hub
import ke.co.bethanyhouse.hub.ui.shell.FeatureRegistry
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.doubleOrNull

/**
 * Reports: every Route.ReportPage on the shared ReportShell frame.
 * Endpoints verified against routes/api.php (report.page groups),
 * ExecutiveReportController, ReportController, EnhancedReportController,
 * ReportPdfController, IntelligenceController and react-admin/src/api/reports.ts
 * (through the Apple port, apple/Sources/BethanyUI/Features/Reports*.swift).
 */
object ReportsFeature {
    fun register() {
        FeatureRegistry.register({ it is Route.Report }) { r -> ReportScreen((r as Route.Report).page) }
    }
}

@Composable
fun ReportScreen(page: Route.ReportPage) {
    when (page) {
        Route.ReportPage.EXECUTIVE -> ExecutiveReportScreen()
        Route.ReportPage.SALES -> SalesReportScreen()
        Route.ReportPage.CUSTOMERS -> CustomersReportScreen()
        Route.ReportPage.FINANCE -> FinanceReportScreen()
        Route.ReportPage.PRODUCTION -> ProductionReportScreen()
        Route.ReportPage.INVENTORY -> InventoryReportScreen()
        Route.ReportPage.PROCUREMENT -> ProcurementReportScreen()
        Route.ReportPage.PERFORMANCE -> PerformanceReportScreen()
        Route.ReportPage.SIGNALS -> SignalsReportScreen()
        Route.ReportPage.EXPLORER -> ExplorerReportScreen()
        Route.ReportPage.DATA_QUALITY -> DataQualityReportScreen()
    }
}

// ── Payload-driven rendering ─────────────────────────────────────────────────

/** Keys whose numeric value is money (a KES figure unless the row names a currency). */
internal fun isMoneyKey(key: String): Boolean {
    val k = key.lowercase()
    if (k.contains("count") || k.contains("orders") || k.endsWith("_days") || k.contains("pct") || k.contains("percent") || k.contains("rate") || k.contains("units") || k.contains("qty") || k.contains("quantity") || k.contains("lines") || k.endsWith("_id") || k == "id" || k.contains("customers") || k.contains("buyers") || k.contains("checks") || k.contains("skus") || k.contains("items")) return false
    return listOf("revenue", "amount", "total", "value", "paid", "balance", "collected", "sales", "cost", "profit", "spent", "refund", "price", "kes", "volume", "expense", "inflow", "outflow", "gross", "net", "margin_", "sold", "due", "held", "budget", "aov", "ltv", "lifetime").any { k.contains(it) }
}

internal fun isPctKey(k: String) = k.contains("pct") || k.contains("percent") || k.endsWith("_rate")

/** A JSON number (not a numeric string). */
internal fun JsonElement?.isNumber(): Boolean { val p = this as? JsonPrimitive ?: return false; return !p.isString && p.doubleOrNull != null }
internal fun JsonElement?.isScalar(): Boolean = this.arr == null && this.obj == null

/** Prints a scalar the way the key says it should be read: money by key, percent by key, a number as sent. */
internal fun autoCell(k: String, v: JsonElement?, currency: String): String {
    if (v.isNull) return "—"
    if (isMoneyKey(k) && v.dbl != null) return fmtMoney(v, currency)
    if (isPctKey(k)) v.dbl?.let { return fmtPct(it) }
    if (v.isNumber()) { val n = v.dbl!!; return if (n == Math.rint(n)) fmtNumber(n) else fmtNumber(n, 2) }
    return v.str ?: "—"
}

private val defaultHidden = setOf("id", "links", "ckey", "product_id", "customer_id", "user_id", "variant_id", "inventory_item_id", "material_id", "outlet_id", "category_id")

/** A table whose columns come from the payload's own keys (first row), so a breakdown is never shown with an invented column. */
@Composable
fun AutoTable(rows: List<JRow>, hide: Set<String> = defaultHidden, currency: String? = null, onTap: ((JRow) -> Unit)? = null) {
    val first = rows.firstOrNull()?.json.obj
    val columns = if (first == null) emptyList() else {
        fun rank(k: String): Int = listOf("name", "label", "title", "ref", "product_name", "customer", "date", "month", "week", "period").indexOf(k).let { if (it < 0) 50 else it }
        first.keys.sortedWith(compareBy({ rank(it) }, { it })).mapNotNull { k ->
            if (k in hide) return@mapNotNull null
            val v = first[k]
            if (v.arr != null || v.obj != null) return@mapNotNull null
            when {
                isMoneyKey(k) && (v.dbl != null || v.isNull) -> TableColumn(k.humanized, k, TableColumn.Kind.MONEY, fixedCurrency = currency)
                isPctKey(k) -> TableColumn(k.humanized, k, TableColumn.Kind.PCT)
                v.isNumber() -> { val n = v.dbl!!; if (n == Math.rint(n)) TableColumn(k.humanized, k, TableColumn.Kind.INT) else TableColumn(k.humanized, k, TableColumn.Kind.NUMBER, decimals = 2) }
                k == "status" || k == "severity" || k == "tier" -> TableColumn(k.humanized, k, TableColumn.Kind.STATUS)
                k.endsWith("_at") || k == "date" || k.endsWith("_date") -> TableColumn(k.humanized, k, TableColumn.Kind.DATE)
                else -> TableColumn(k.humanized, k, TableColumn.Kind.TEXT)
            }
        }
    }
    DataTable(columns = columns, rows = rows, onTap = onTap)
}

/** Scalar fields of an object as detail rows, money by key; a nested `by_currency` block is shown per currency. */
@Composable
fun AutoPairs(json: JsonElement?, currency: String = "KES") {
    Column {
        jpairs(json).forEach { p ->
            if (p.value.isScalar()) DetailRow(p.key.humanized, autoCell(p.key, p.value, currency))
            else if (p.value["by_currency"] != null) MoneyByCurrencyLines(p.key.humanized, p.value)
        }
    }
}

/** Scalar fields of an object as stat tiles. */
@Composable
fun AutoTiles(json: JsonElement?, currency: String = "KES") {
    TileGrid(minWidth = 170.dp) {
        jpairs(json).filter { it.value.isScalar() && !it.value.isNull }.forEach { p ->
            StatTile(p.key.humanized, value = autoCell(p.key, p.value, currency), modifier = ReportTile)
        }
    }
    jpairs(json).filter { it.value["by_currency"] != null }.forEach { p -> MoneyByCurrencyLines(p.key.humanized, p.value) }
}

/**
 * A server `MoneyByCurrency` block: the KES total at each row's own reporting
 * rate, the native figures beside it, and the unconvertible ones named. Never
 * a sum across currencies on this device.
 */
@Composable
fun MoneyByCurrencyLines(title: String, json: JsonElement?) {
    val m = MoneyByCurrency.from(json) ?: return
    Column(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Text(title, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
            Text(m.kesTotal?.formatted() ?: "—", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
        }
        m.byCurrency.forEach { l ->
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                Text(l.native?.formatted() ?: l.currency, style = MaterialTheme.typography.labelSmall)
                Text(l.kes?.formatted()?.let { "= $it" } ?: "no reporting rate", style = MaterialTheme.typography.labelSmall, color = if (l.kes == null) Palette.warning else MaterialTheme.hub.textTertiary)
            }
        }
        m.unconverted.forEach { u ->
            val code = u["currency"].str ?: u["code"].str ?: u["currency_code"].str ?: "?"
            Text("${fmtMoney(u["native"] ?: u["total"] ?: u["amount"], code)} · no reporting rate, not in the KES total", style = MaterialTheme.typography.labelSmall, color = Palette.warning)
        }
    }
}

/** Opens a row's server-stated links (`links: {order: "/sales/orders/1"}`). */
@Composable
fun RowLinks(row: JRow) {
    val env = LocalEnv.current
    val links = jpairs(row["links"]).mapNotNull { l -> l.value.str?.let { Route.fromConsolePath(it) }?.let { l.key.humanized to it } }
    if (links.isEmpty()) return
    Row(horizontalArrangement = Arrangement.spacedBy(Space.m)) {
        links.forEach { (label, route) -> TextButton(onClick = { env.navigator.go(route) }) { Text("$label →", style = MaterialTheme.typography.labelSmall) } }
    }
}

// ── Executive Overview (GET reports/executive, reports/engine-room) ──────────

@Composable
fun ExecutiveReportScreen() {
    ReportShell(page = Route.ReportPage.EXECUTIVE) { w ->
        ReportData("/v1/admin/reports/executive", query = w.periodParams()) { json ->
            val k = json["kpis"]
            Attention(json["attention"])
            SectionCard("Sales (sales truth: recognised orders, KES at the reporting rate)") {
                TileGrid(minWidth = 170.dp) {
                    MetricTile(label = "Sold", metric = k["sales"]["revenue"], drill = "revenue")
                    MetricTile(label = "Orders", metric = k["sales"]["orders"], money = false, drill = "orders")
                    MetricTile(label = "Average order", metric = k["sales"]["aov"])
                    MetricTile(label = "New customers", metric = k["sales"]["new_customers"], money = false, drill = "new_customers")
                }
            }
            SectionCard("Money (money truth: settled payments net of refunds)") {
                TileGrid(minWidth = 170.dp) {
                    MetricTile(label = "Collected", metric = k["money"]["collected"], drill = "collected")
                    OutstandingTile(k["money"]["outstanding"])
                }
                val aging = k["money"]["aging"]
                if (aging != null && !aging.isNull) {
                    TileGrid(minWidth = 120.dp) {
                        jrows(aging["buckets"]).forEach { b ->
                            DrillLink(metric = "outstanding", title = "Outstanding ${b.str("label") ?: ""}", bucket = b.str("key")) { AgingCell(b.str("label") ?: "", b["amount"], b.int("orders")) }
                        }
                        aging["deposits_held"]?.let { d -> DrillLink(metric = "outstanding", title = "Deposits held", bucket = "deposits") { AgingCell("Deposits held", d["amount"], d["orders"].int) } }
                    }
                }
            }
            SectionCard("Production") {
                TileGrid(minWidth = 160.dp) {
                    MetricTile(label = "Completed (pieces)", metric = k["production"]["completed"], money = false, drill = "production_completed")
                    MetricTile(label = "On time", metric = k["production"]["on_time_pct"], money = false, hint = "% of completed with a due date")
                    StatTile("Work in progress", value = k["production"]["wip"].str ?: "0", modifier = ReportTile)
                    DrillLink(metric = "production_overdue", title = "Overdue production") { StatTile("Overdue", value = k["production"]["overdue"].str ?: "0", tone = if ((k["production"]["overdue"].int ?: 0) > 0) StatusTone.DANGER else null, modifier = ReportTile) }
                    StatTile("Low stock", value = k["inventory"]["low_stock"].str ?: "0", tone = if ((k["inventory"]["low_stock"].int ?: 0) > 0) StatusTone.WARNING else null, modifier = ReportTile)
                }
            }
            val f = k["financial"]
            if (f != null && !f.isNull) {
                SectionCard("Financial (reports.financial)") {
                    TileGrid(minWidth = 170.dp) {
                        MetricTile(label = "Expenses (approved + paid)", metric = f["expenses"], drill = "expenses")
                        MetricTile(label = "Net collected", metric = f["net_collected"], hint = "Collected − expenses")
                    }
                    val e = f["earned"]
                    if (e != null && !e.isNull) {
                        Text("Earned P&L (fully-paid orders at cost snapshot, approved expenses)", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                        TileGrid(minWidth = 160.dp) {
                            StatTile("Earned revenue", value = fmtMoney(e["earned_revenue"], "KES"), hint = "${e["earned_orders"].int ?: 0} orders", modifier = ReportTile)
                            StatTile("COGS (estimate)", value = fmtMoney(e["cogs_estimate"], "KES"), modifier = ReportTile)
                            StatTile("Gross profit", value = fmtMoney(e["gross_profit"], "KES"), hint = e["gross_margin_pct"].dbl?.let { "margin ${fmtPct(it)}" }, modifier = ReportTile)
                            StatTile("Net profit", value = fmtMoney(e["net_profit"], "KES"), hint = "after ${fmtMoney(e["expenses"], "KES")} expenses", modifier = ReportTile)
                        }
                        e["limits"].records.forEach { l ->
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Icon(Icons.Default.Warning, contentDescription = null, tint = Palette.warning, modifier = Modifier.size(14.dp))
                                Spacer(Modifier.width(4.dp))
                                Text(l.str ?: "", style = MaterialTheme.typography.labelSmall, color = Palette.warning)
                            }
                        }
                    }
                }
            }
            StructuralGaps(json["structural_gaps"])
        }
        ReportData("/v1/admin/reports/engine-room", query = w.outletParams()) { json ->
            SectionCard("Engine room (as of now)") {
                EngineRow("Collections", json["collections"], mapOf("money_on_table" to "Money on the table"))
                EngineRow("Stock-out loss", json["stockout"], mapOf("est_daily_loss_now" to "Bleeding now / day", "est_lost_revenue_window" to "Lost in window", "products_currently_out" to "Products out"))
                EngineRow("Win-back", json["winback"], mapOf("customers_at_risk" to "Customers at risk", "annual_value_at_risk" to "Annual value at risk", "recovered_revenue_90d" to "Recovered (90d)"))
                EngineRow("Attach rates", json["attach"], mapOf("missed_revenue_estimate_total" to "Missed attach (estimate)", "multi_item_pct" to "Multi-item baskets"))
                EngineRow("Replenishment", json["replenishment"], mapOf("due_customers" to "Customers due", "expected_revenue" to "Expected revenue", "pings_30d" to "Pings (30d)"))
                EngineRow("Seasonal", json["seasonal"], mapOf("total_gap_value" to "Stock gap value", "urgent_orders" to "Urgent orders"))
            }
        }
    }
}

@Composable
private fun AgingCell(label: String, amount: JsonElement?, orders: Int?) {
    Column(Modifier.background(MaterialTheme.hub.field, MaterialTheme.shapes.small).padding(8.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Text(label, style = MaterialTheme.typography.labelSmall)
        Text(fmtMoney(amount, "KES"), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
        Text("${orders ?: 0} orders", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
    }
}

@Composable
private fun OutstandingTile(v: JsonElement?) {
    if (v["current"] != null) MetricTile(label = "Outstanding", metric = v, drill = "outstanding")
    else DrillLink(metric = "outstanding", title = "Outstanding") {
        val value = when {
            v.dbl != null -> fmtMoney(v, "KES")
            v["total"].dbl != null -> fmtMoney(v["total"], "KES")
            v["amount"].dbl != null -> fmtMoney(v["amount"], "KES")
            else -> "—"
        }
        StatTile("Outstanding", value = value, hint = "Open recognised orders, KES", modifier = ReportTile)
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun EngineRow(title: String, s: JsonElement?, keys: Map<String, String>) {
    Column(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(title, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
        if (s == null || s.isNull) Text("—", color = MaterialTheme.hub.textTertiary)
        else FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.l), verticalArrangement = Arrangement.spacedBy(Space.xs)) {
            keys.entries.sortedBy { it.key }.forEach { (k, label) ->
                val v = s[k] ?: return@forEach
                Column {
                    Text(label, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    Text(if (isMoneyKey(k)) fmtMoney(v, "KES") else if (k.contains("pct")) fmtPct(v.dbl) else (v.str ?: "—"), style = MaterialTheme.typography.bodySmall)
                }
            }
        }
    }
    HorizontalDivider(color = MaterialTheme.hub.line)
}

@Composable
private fun Attention(list: JsonElement?) {
    val rows = jrows(list)
    if (rows.isEmpty()) return
    SectionCard("Needs attention") {
        rows.forEach { a ->
            val high = a.str("severity") == "high"
            Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                Icon(if (high) Icons.Default.Error else Icons.Default.Warning, contentDescription = null, tint = if (high) Palette.danger else Palette.warning, modifier = Modifier.size(18.dp))
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(a.str("title") ?: "", style = MaterialTheme.typography.bodyMedium)
                    a.str("detail")?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                    Row { jrows(a["actions"]).forEach { act -> if (act.str("type") == "navigate") ActionLink(act.str("label") ?: "Open", act.str("to")) } }
                }
                a.str("link")?.let { ActionLink("Open", it) }
            }
        }
    }
}

@Composable
private fun StructuralGaps(list: JsonElement?) {
    val rows = jrows(list)
    if (rows.isEmpty()) return
    SectionCard("Zeros that are structural, not quiet") {
        rows.forEach { g ->
            Column(Modifier.padding(vertical = 2.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(g.str("title") ?: "", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                Text(g.str("detail") ?: "", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            }
        }
    }
}

// ── Staff, Outlets & Performance (GET reports/performance) ───────────────────

@Composable
fun PerformanceReportScreen() {
    ReportShell(page = Route.ReportPage.PERFORMANCE, csv = { w -> CSVExport("/v1/admin/reports/performance", w.periodParams(), "staff_outlets_performance.csv") }) { w ->
        ReportData("/v1/admin/reports/performance", query = w.periodParams()) { json ->
            val t = json["totals"]
            TileGrid(minWidth = 170.dp) {
                DrillLink(metric = "revenue", title = "Sold") { StatTile("Sold", value = fmtMoney(t["sold"], "KES"), hint = "sales truth, KES at the reporting rate", modifier = ReportTile) }
                StatTile("Orders", value = t["orders"].str ?: "0", modifier = ReportTile)
                StatTile("Collected", value = fmtMoney(t["collected"], "KES"), hint = "money truth, by payment date", modifier = ReportTile)
            }
            val cols = listOf(
                TableColumn("Name", "name"), TableColumn("Sold", "sold", TableColumn.Kind.MONEY, fixedCurrency = "KES"), TableColumn("Previous", "sold_previous", TableColumn.Kind.MONEY, fixedCurrency = "KES"),
                TableColumn("Orders", "orders", TableColumn.Kind.INT), TableColumn("Prev orders", "orders_previous", TableColumn.Kind.INT), TableColumn("Avg order", "aov", TableColumn.Kind.MONEY, fixedCurrency = "KES"),
                TableColumn("Buyers", "buyers", TableColumn.Kind.INT), TableColumn("Collected", "collected", TableColumn.Kind.MONEY, fixedCurrency = "KES"),
                TableColumn("Unconfirmed carts", "unconfirmed_carts", TableColumn.Kind.INT), TableColumn("Unconfirmed value", "unconfirmed_value", TableColumn.Kind.MONEY, fixedCurrency = "KES"),
            )
            SectionCard("Outlets") { BarsChart(points = chartPoints(json["outlets"], label = "name", y = "sold")); DataTable(columns = cols, rows = jrows(json["outlets"])) }
            SectionCard("Salespeople") { DataTable(columns = cols, rows = jrows(json["salespeople"])) }
            BasisCaption("Previous period: ${json["period"]["previous_start"].str?.take(10) ?: ""} – ${json["period"]["previous_end"].str?.take(10) ?: ""}, like for like.")
        }
    }
}

// ── Business Explorer (GET reports/explorer, reports/explorer/orders) ────────

private val explorerFilterDims = listOf("channel", "salesperson", "currency", "category", "product")
private val explorerMoneyMeasures = listOf("sold", "aov", "collected", "line_value")

@OptIn(ExperimentalLayoutApi::class)
@Composable
fun ExplorerReportScreen() {
    var by by remember { mutableStateOf("month") }
    var filters by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var drillRow by remember { mutableStateOf<JRow?>(null) }
    var dims by remember { mutableStateOf<List<Pair<String, String>>>(emptyList()) }

    fun query(w: ReportWindow): Map<String, String?> {
        val q = w.periodParams(mapOf("by" to by)).toMutableMap()
        filters.forEach { (k, v) -> if (v.isNotEmpty()) q["f_$k"] = v }
        return q
    }

    ReportShell(page = Route.ReportPage.EXPLORER, csv = { w -> CSVExport("/v1/admin/reports/explorer", query(w), "business_explorer_$by.csv") }) { w ->
        FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s), modifier = Modifier.fillMaxWidth()) {
            SelectField("By", by, dims.ifEmpty { listOf("month" to "Month") }, { it?.let { v -> by = v } }, Modifier.width(220.dp), placeholder = "Month")
            explorerFilterDims.forEach { f ->
                val v = filters[f]
                if (!v.isNullOrEmpty()) OutlinedButton(onClick = { filters = filters - f }) { Icon(Icons.Default.Close, contentDescription = null, modifier = Modifier.size(14.dp)); Text(" ${f.humanized}: $v", style = MaterialTheme.typography.labelSmall) }
            }
        }
        ReportData("/v1/admin/reports/explorer", query = query(w)) { json ->
            val measures = json["measures"].records.mapNotNull { it.str }
            LaunchedEffect(json) { dims = jpairs(json["dimensions"]).map { it.key to (it.value["label"].str ?: it.key) } }
            val dim = json["dimension"].str ?: by
            val cols = listOf(TableColumn(json["dimensions"][dim]["label"].str ?: by.humanized, "label")) + measures.map { m ->
                if (m in explorerMoneyMeasures) TableColumn(m.humanized + " (KES)", m, TableColumn.Kind.MONEY, fixedCurrency = "KES") else TableColumn(m.humanized, m, TableColumn.Kind.INT)
            }
            SectionCard("${json["row_count"].int ?: 0} rows (limit ${json["row_limit"].int ?: 0}) · level: ${json["level"].str ?: ""}") {
                if ("sold" in measures) BarsChart(points = chartPoints(json["rows"], label = "label", y = "sold"))
                DataTable(columns = cols, rows = jrows(json["rows"]), onTap = { r -> drillRow = r })
                json["totals"]?.takeUnless { it.isNull }?.let { t ->
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.m), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Text("Totals", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                        measures.forEach { m -> Text("${m.humanized}: ${if (m in explorerMoneyMeasures) fmtMoney(t[m], "KES") else (t[m].str ?: "—")}", style = MaterialTheme.typography.labelSmall) }
                    }
                }
                Text("Tap a row to see its orders (or payments, by method), or to narrow the next dimension by it.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
            }
        }
        drillRow?.let { r ->
            ExplorerOrdersSheet(window = w, by = by, key = r.str("key") ?: "", label = r.str("label") ?: "", filters = filters, onDismiss = { drillRow = null }) {
                if (by in explorerFilterDims) filters = filters + (by to (r.str("key") ?: ""))
                drillRow = null
            }
        }
    }
}

@Composable
private fun ExplorerOrdersSheet(window: ReportWindow, by: String, key: String, label: String, filters: Map<String, String>, onDismiss: () -> Unit, narrow: () -> Unit) {
    var page by remember { mutableIntStateOf(1) }
    val q = window.periodParams(mapOf("by" to by, "key" to key, "page" to page.toString())).toMutableMap()
    filters.forEach { (k, v) -> if (v.isNotEmpty()) q["f_$k"] = v }
    InfoSheet("$label · records", onDismiss) {
        if (by in explorerFilterDims) OutlinedButton(onClick = narrow) { Text("Narrow by this") }
        ReportData("/v1/admin/reports/explorer/orders", query = q) { json ->
            Text("${json["total"].int ?: 0} records", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            Column {
                jrows(json["rows"]).forEach { r ->
                    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.Top) {
                        Column(Modifier.weight(1f)) {
                            Text(r.str("ref") ?: r.str("order_number") ?: r.str("payment_number") ?: "#${r.str("id") ?: ""}", style = MaterialTheme.typography.bodyMedium.copy(fontFamily = FontFamily.Monospace))
                            Text(listOfNotNull(r.str("customer"), r.str("who"), r.str("detail")).joinToString(" · "), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                            RowLinks(r)
                        }
                        Text(fmtMoney(r["amount"], "KES"), style = MaterialTheme.typography.bodySmall)
                    }
                    HorizontalDivider(color = MaterialTheme.hub.line)
                }
            }
            val total = json["total"].int; val per = json["per_page"].int
            if (total != null && per != null && per > 0 && total > per) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    TextButton(enabled = page > 1, onClick = { page -= 1 }) { Text("Previous") }
                    Text("$page", style = MaterialTheme.typography.labelSmall)
                    TextButton(enabled = page * per < total, onClick = { page += 1 }) { Text("Next") }
                }
            }
        }
    }
}

// ── Audit & Data Quality (GET reports/data-quality) ──────────────────────────

@Composable
fun DataQualityReportScreen() {
    var open by remember { mutableStateOf<Set<String>>(emptySet()) }
    ReportShell(page = Route.ReportPage.DATA_QUALITY) { w ->
        ReportData("/v1/admin/reports/data-quality", query = w.periodParams()) { json ->
            json["coverage"]?.takeUnless { it.isNull }?.let { c ->
                TileGrid(minWidth = 160.dp) {
                    StatTile("Orders in period", value = c["orders"].str ?: "0", modifier = ReportTile)
                    StatTile("Buyer identified", value = c["buyer_identified"].str ?: "—", modifier = ReportTile)
                    StatTile("Order lines", value = c["lines"].str ?: "0", modifier = ReportTile)
                    StatTile("Lines costed", value = c["lines_costed"].str ?: "—", modifier = ReportTile)
                }
            }
            val rowLimit = json["row_limit"].int ?: 50
            listOf("sales", "money", "customers").forEach { group ->
                val checks = jrows(json["checks"]).filter { it.str("group") == group }
                if (checks.isNotEmpty()) SectionCard(group.humanized) {
                    checks.forEach { c -> DataQualityCheck(c, rowLimit, open.contains(c.str("key") ?: "")) { k -> open = if (open.contains(k)) open - k else open + k } }
                }
            }
            val gaps = jrows(json["gaps"])
            if (gaps.isNotEmpty()) SectionCard("Structural gaps") {
                gaps.forEach { g ->
                    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Text(g.str("title") ?: "", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                        Text(g.str("detail") ?: "", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        Text("Affects: ${g["metrics"].records.mapNotNull { it.str }.joinToString(", ")}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
                    }
                }
            }
        }
    }
}

@Composable
private fun DataQualityCheck(c: JRow, rowLimit: Int, open: Boolean, toggle: (String) -> Unit) {
    val key = c.str("key") ?: ""
    val count = c.int("count") ?: 0
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            StatusBadge(c.str("severity"), tone = when (c.str("severity")) { "high" -> StatusTone.DANGER; "medium" -> StatusTone.WARNING; else -> StatusTone.NEUTRAL })
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text("${c.str("title") ?: ""} · $count", style = MaterialTheme.typography.bodyMedium)
                val value = c["value"]?.takeUnless { it.isNull }?.let { " · ${fmtMoney(it, "KES")}" } ?: ""
                Text("Bends: ${c.str("affects") ?: ""}$value · scope: ${c.str("scope") ?: ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            c["fix"]["to"].str?.takeIf { it.isNotEmpty() }?.let { ActionLink(c["fix"]["label"].str ?: "Fix", it) }
            if (count > 0) TextButton(onClick = { toggle(key) }) { Text(if (open) "Hide" else "Rows") }
        }
        if (open) {
            jrows(c["rows"]).forEach { r ->
                Row(Modifier.fillMaxWidth().padding(start = 24.dp, top = 2.dp, bottom = 2.dp), verticalAlignment = Alignment.Top) {
                    Column(Modifier.weight(1f)) {
                        Text(r.str("ref") ?: r.str("customer") ?: r.str("detail") ?: "#${r.str("id") ?: ""}", style = MaterialTheme.typography.bodySmall.copy(fontFamily = FontFamily.Monospace))
                        Text(listOfNotNull(r.str("customer"), r.str("detail"), r.str("date")?.take(10)).joinToString(" · "), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        RowLinks(r)
                    }
                    r["amount"]?.takeUnless { it.isNull }?.let { Text(fmtMoney(it, "KES"), style = MaterialTheme.typography.labelSmall) }
                }
            }
            if (count > rowLimit) Text("Showing the first $rowLimit of $count.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary, modifier = Modifier.padding(start = 24.dp))
        }
        HorizontalDivider(color = MaterialTheme.hub.line)
    }
}
