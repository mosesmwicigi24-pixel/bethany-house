package ke.co.bethanyhouse.hub.ui.features.reports

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.width
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.dbl
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.obj
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.components.BarsChart
import ke.co.bethanyhouse.hub.ui.components.BasisCaption
import ke.co.bethanyhouse.hub.ui.components.DataTable
import ke.co.bethanyhouse.hub.ui.components.DetailRow
import ke.co.bethanyhouse.hub.ui.components.JRow
import ke.co.bethanyhouse.hub.ui.components.LinesChart
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.StatTile
import ke.co.bethanyhouse.hub.ui.components.TableColumn
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.chartPoints
import ke.co.bethanyhouse.hub.ui.components.fmtMoney
import ke.co.bethanyhouse.hub.ui.components.fmtPct
import ke.co.bethanyhouse.hub.ui.components.jrows
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject

/**
 * Sales & Orders: the ReportController sales endpoints as tabs, plus the
 * ledger and the unconfirmed queue (order-pipeline). Dates go as
 * start_date/end_date (report.window normalises); every money figure is
 * in the payload's `currency` (KES at the reporting rate by default).
 */
@Composable
fun SalesReportScreen() {
    val env = LocalEnv.current
    var tab by remember { mutableStateOf("summary") }
    var compare by remember { mutableStateOf(false) }
    val tabs = remember(env.session.current) {
        val t = mutableListOf("summary" to "Summary", "by-product" to "By product", "by-category" to "By category", "by-customer" to "By customer", "by-outlet" to "By outlet", "by-payment-method" to "By payment method", "returns" to "Returns", "ledger" to "Ledger", "unconfirmed" to "Unconfirmed")
        if (env.session.current.can("reports.customers")) t += "neema" to "Neema"
        t.toList()
    }
    fun csv(w: ReportWindow): CSVExport = when (tab) {
        "unconfirmed" -> CSVExport("/v1/admin/reports/order-pipeline", w.outletParams(), "unconfirmed-orders.csv")
        "neema" -> CSVExport("/v1/admin/reports/sales/neema", w.dateParams(), "neema.csv")
        else -> CSVExport("/v1/admin/reports/sales/$tab", w.dateParams(), "sales-$tab.csv")
    }

    ReportShell(page = Route.ReportPage.SALES, csv = ::csv, pdfType = "sales", scheduleType = "sales", dated = tab != "unconfirmed") { w ->
        SegmentedTabs(tabs, tab) { tab = it }
        when (tab) {
            "summary" -> {
                ToggleRow("Compare with the previous period", compare, { compare = it })
                ReportData("/v1/admin/reports/sales/summary", query = w.dateParams(mapOf("compare" to if (compare) "1" else null))) { j ->
                    val cur = j["currency"].str ?: "KES"
                    val s = j["summary"]
                    SectionCard("Summary · $cur") {
                        TileGrid(minWidth = 170.dp) {
                            DrillLink(metric = "revenue", title = "Sold") { StatTile("Revenue (sales truth)", value = fmtMoney(s["total_revenue"], cur), hint = "recognised orders, by order date", modifier = ReportTile) }
                            StatTile("Orders", value = s["total_orders"].str ?: "0", modifier = ReportTile)
                            StatTile("Average order", value = fmtMoney(s["average_order_value"], cur), modifier = ReportTile)
                            StatTile("Collected (money truth)", value = fmtMoney(s["total_collected"], cur), hint = "basis: ${j["collected_basis"].str ?: "payment date"}, net of refunds", modifier = ReportTile)
                        }
                        AutoPairs(s, cur)
                        CurrencyNotes(j)
                        BasisCaption(if (cur == "KES") "KES at the reporting rate (128 KES/USD), never the customer pricing rate." else "Shown natively in $cur; only orders in this currency.")
                    }
                    j["comparison"]?.takeUnless { it.isNull }?.let { c ->
                        SectionCard("Previous period ${c["period"]["start"].str?.take(10) ?: ""} – ${c["period"]["end"].str?.take(10) ?: ""}") { AutoPairs(c, cur) }
                    }
                    SectionCard("Daily") { LinesChart(points = chartPoints(j["daily_breakdown"], label = "date", y = "revenue", series = "Revenue"), currency = cur); AutoTable(rows = jrows(j["daily_breakdown"]), currency = cur) }
                    SectionCard("Weekly") { AutoTable(rows = jrows(j["weekly_breakdown"]), currency = cur) }
                    SectionCard("By payment method (collected, by payment date)") { AutoTable(rows = jrows(j["by_payment_method"]), currency = cur) }
                    SectionCard("By hour") { BarsChart(points = chartPoints(j["by_hour"], label = "hour", y = "revenue"), currency = cur) }
                    SectionCard("By day of week") { BarsChart(points = chartPoints(j["by_day_of_week"], label = "day", y = "revenue"), currency = cur) }
                }
            }
            "by-product" -> ReportData("/v1/admin/reports/sales/by-product", query = w.dateParams(mapOf("limit" to "50"))) { j -> Breakdown(j, key = "products", chartLabel = "name_en", chartY = "total_revenue") }
            "by-category" -> ReportData("/v1/admin/reports/sales/by-category", query = w.dateParams()) { j -> Breakdown(j, key = "categories", chartLabel = "category_name", chartY = "total_revenue") }
            "by-customer" -> ReportData("/v1/admin/reports/sales/by-customer", query = w.dateParams(mapOf("limit" to "50"))) { j -> Breakdown(j, key = "customers", chartLabel = "name", chartY = "total_spent") }
            "by-outlet" -> ReportData("/v1/admin/reports/sales/by-outlet", query = w.dateParams()) { j -> Breakdown(j, key = "outlets", chartLabel = "outlet_name", chartY = "total_revenue") }
            "by-payment-method" -> ReportData("/v1/admin/reports/sales/by-payment-method", query = w.dateParams()) { j ->
                SectionCard("Payment methods · total ${fmtMoney(j["total"], "KES")}") { BasisCaption(j["basis"].str); AutoTable(rows = jrows(j["payment_methods"]), currency = "KES") }
            }
            "returns" -> ReportData("/v1/admin/reports/sales/returns", query = w.dateParams()) { j ->
                SectionCard("Returns") { AutoTiles(j["summary"]) }
                SectionCard("By reason") { AutoTable(rows = jrows(j["by_reason"])) }
                SectionCard("By item reason") { AutoTable(rows = jrows(j["by_item_reason"])) }
            }
            "ledger" -> Ledger(w)
            "unconfirmed" -> Pipeline(w)
            "neema" -> NeemaSection(w)
        }
    }
}

@Composable
private fun Breakdown(j: JsonElement, key: String, chartLabel: String, chartY: String) {
    val env = LocalEnv.current
    val cur = j["currency"].str ?: "KES"
    SectionCard(key.humanized + " · $cur") {
        BarsChart(points = chartPoints(j[key], label = chartLabel, y = chartY).take(15), currency = cur)
        AutoTable(rows = jrows(j[key]), currency = cur) { r ->
            if (key == "products") r.int("id")?.let { env.navigator.push(Route.Product(it)) }
            if (key == "customers") (r.int("customer_id") ?: r.int("id"))?.let { env.navigator.push(Route.Customer(it)) }
        }
        CurrencyNotes(j)
    }
}

@Composable
private fun Ledger(w: ReportWindow) {
    ReportData("/v1/admin/reports/sales/ledger", query = w.dateParams()) { j ->
        val cur = j["period"]["currency"].str ?: "KES"
        SectionCard("Channels · sales = paid + balance, paid attributed to the order's period") {
            DataTable(columns = listOf(TableColumn("Channel", "label"), TableColumn("Orders", "orders", TableColumn.Kind.INT), TableColumn("Sales", "sales", TableColumn.Kind.MONEY, fixedCurrency = cur), TableColumn("Paid", "paid", TableColumn.Kind.MONEY, fixedCurrency = cur), TableColumn("Balance", "balance", TableColumn.Kind.MONEY, fixedCurrency = cur)), rows = jrows(j["channels"]))
        }
        j["reconciliation"]?.takeUnless { it.isNull }?.let { r ->
            SectionCard("Reconciliation") {
                DetailRow("Recognised sales", fmtMoney(r["recognised_sales"], cur))
                DetailRow("Pipeline (unconfirmed carts)", fmtMoney(r["pipeline_sales"], cur))
                DetailRow("Gross order book", fmtMoney(r["gross_order_book"], cur))
                BasisCaption(r["note"].str)
            }
        }
        SectionCard("By stage per channel") {
            jrows(j["by_stage"]).forEach { ch ->
                Text(ch.str("label") ?: "", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                listOf("confirmed", "processed", "completed").forEach { st ->
                    ch["stages"][st]?.let { f ->
                        Column(Modifier.fillMaxWidth()) {
                            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                                Text(st.humanized, style = MaterialTheme.typography.labelSmall, modifier = Modifier.width(100.dp))
                                Text("${f["orders"].int ?: 0} orders", style = MaterialTheme.typography.labelSmall)
                            }
                            Text("sales ${fmtMoney(f["sales"], cur)} · paid ${fmtMoney(f["paid"], cur)} · balance ${fmtMoney(f["balance"], cur)}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        }
                    }
                }
            }
        }
        SectionCard("Daily") { LinesChart(points = chartPoints(j["daily"], label = "date", y = "sales", series = "Sales") + chartPoints(j["daily"], label = "date", y = "paid", series = "Paid"), currency = cur); AutoTable(rows = jrows(j["daily"]), currency = cur) }
        SectionCard("Weekly") { BucketTable(j["weekly"], cur) }
        SectionCard("Monthly") { BucketTable(j["monthly"], cur) }
    }
}

@Composable
private fun BucketTable(v: JsonElement?, cur: String) {
    DataTable(
        columns = listOf(TableColumn("Period", "period"), TableColumn("Orders", "orders", TableColumn.Kind.INT), TableColumn("Sales", "sales", TableColumn.Kind.MONEY, fixedCurrency = cur), TableColumn("Paid", "paid", TableColumn.Kind.MONEY, fixedCurrency = cur), TableColumn("Balance", "balance", TableColumn.Kind.MONEY, fixedCurrency = cur)),
        rows = jrows(v).map { r -> JRow(r.id, JsonObject((r["total"].obj ?: emptyMap()) + ("period" to (r["period"] ?: JsonNull)))) },
    )
}

@Composable
private fun Pipeline(w: ReportWindow) {
    val env = LocalEnv.current
    ReportData("/v1/admin/reports/order-pipeline", query = w.outletParams(mapOf("sort" to "value"))) { j ->
        val s = j["summary"]
        SectionCard("Unconfirmed carts (pipeline truth — never income)") {
            TileGrid(minWidth = 170.dp) {
                StatTile("Carts", value = s["orders"].str ?: "0", modifier = ReportTile)
                val excluded = s["excluded_non_kes_orders"].int ?: 0
                StatTile("Value", value = fmtMoney(s["value"], s["currency"].str ?: "KES"), hint = if (excluded > 0) "$excluded orders without a reporting rate listed, not counted" else null, modifier = ReportTile)
            }
            AutoTable(rows = jrows(j["aging"]))
            AutoTable(rows = jrows(j["by_channel"]))
        }
        SectionCard("Orders, largest recoverable money first") {
            DataTable(
                columns = listOf(TableColumn("Order", "order_number"), TableColumn("Channel", "channel", TableColumn.Kind.STATUS), TableColumn("Customer", "customer_name"), TableColumn("Phone", "customer_phone"), TableColumn("Items", "item_count", TableColumn.Kind.INT), TableColumn("Age (days)", "age_days", TableColumn.Kind.INT), TableColumn("Total", "total_amount", TableColumn.Kind.MONEY, currencyKey = "currency_code"), TableColumn("KES", "total_kes", TableColumn.Kind.MONEY, fixedCurrency = "KES")),
                rows = jrows(j["orders"]),
                onTap = { r -> r.int("id")?.let { env.navigator.push(Route.Order(it)) } },
            )
        }
    }
}

/** Neema (AI agent) performance — GET reports/sales/neema (Customers & Neema page gate). */
@Composable
fun NeemaSection(window: ReportWindow) {
    ReportData("/v1/admin/reports/sales/neema", query = window.dateParams()) { j ->
        val cur = j["period"]["currency"].str ?: "KES"
        SectionCard("Leads") {
            TileGrid(minWidth = 150.dp) {
                StatTile("Leads", value = j["leads"]["total"].str ?: "0", modifier = ReportTile)
                StatTile("Won rate", value = fmtPct(j["leads"]["won_rate"].dbl), modifier = ReportTile)
                StatTile("Converted (${j["lead_conversion"]["window_days"].int ?: 14}d)", value = j["lead_conversion"]["converted"].str ?: "0", hint = "rate ${fmtPct(j["lead_conversion"]["conversion_rate"].dbl)}", modifier = ReportTile)
                StatTile("Converted revenue", value = fmtMoney(j["lead_conversion"]["revenue"], cur), hint = "${j["lead_conversion"]["orders"].int ?: 0} orders", modifier = ReportTile)
            }
            AutoPairs(j["leads"]["by_status"])
            AutoTable(rows = jrows(j["leads"]["by_intent"]))
        }
        SectionCard("WhatsApp sales") { AutoTiles(j["whatsapp_sales"], cur) }
        SectionCard("Contacts per channel") { AutoTable(rows = jrows(j["contacts"])) }
        SectionCard("Message volume") {
            jrows(j["message_volume"]["channels"]).forEach { c ->
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                    Text((c.str("channel") ?: "").humanized, style = MaterialTheme.typography.bodySmall, modifier = Modifier.width(100.dp))
                    Text("period ${c["period"]["messages"].int ?: 0} (${c["period"]["inbound"].int ?: 0} in)", style = MaterialTheme.typography.bodySmall)
                    Text("all time ${c["all_time"]["messages"].int ?: 0}", style = MaterialTheme.typography.bodySmall)
                }
            }
            BasisCaption(j["message_volume"]["daily_since"].str?.let { "Daily snapshots collect from $it; period figures before that are structurally zero." })
        }
    }
}
