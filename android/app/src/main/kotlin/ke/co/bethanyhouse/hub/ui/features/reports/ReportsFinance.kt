package ke.co.bethanyhouse.hub.ui.features.reports

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.arr
import ke.co.bethanyhouse.hub.core.dbl
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.BarsChart
import ke.co.bethanyhouse.hub.ui.components.BasisCaption
import ke.co.bethanyhouse.hub.ui.components.DetailRow
import ke.co.bethanyhouse.hub.ui.components.LinesChart
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.StatTile
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.chartPoints
import ke.co.bethanyhouse.hub.ui.components.fmtMoney
import ke.co.bethanyhouse.hub.ui.components.fmtPct
import ke.co.bethanyhouse.hub.ui.components.jpairs
import ke.co.bethanyhouse.hub.ui.components.jrows
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.serialization.json.JsonElement

/**
 * Finance & Cash (reports.financial): P&L, cash flow, expenses, tax, trends
 * (revenue sold vs collected), intelligence (earned P&L, budgets, weekly cash
 * flow, rails). Every figure is KES at the reporting rate; the P&L refuses
 * any other currency (KES_ONLY_REPORT). Visible only to the roles
 * FinancialVisibility allows: ReportShell shows the "not for your role"
 * state when reports.financial is missing.
 */
@Composable
fun FinanceReportScreen() {
    val env = LocalEnv.current
    var tab by remember { mutableStateOf("pl") }
    var compare by remember { mutableStateOf(false) }
    fun csv(w: ReportWindow): CSVExport {
        val path = when (tab) {
            "cashflow" -> "financial/cash-flow"; "expenses" -> "financial/expenses"; "tax" -> "financial/tax"; "trends" -> "financial/revenue"; "intelligence" -> "financial-intelligence"
            else -> "financial/profit-loss"
        }
        return CSVExport("/v1/admin/reports/$path", if (tab == "intelligence") w.periodParams() else w.dateParams(), "finance-$tab.csv")
    }

    ReportShell(page = Route.ReportPage.FINANCE, csv = ::csv, pdfType = "financial", scheduleType = "financial") { w ->
        SegmentedTabs(listOf("pl" to "P&L", "cashflow" to "Cash flow", "expenses" to "Expenses", "tax" to "Tax", "trends" to "Trends", "intelligence" to "Intelligence"), tab) { tab = it }
        when (tab) {
            "pl" -> {
                ToggleRow("Compare with the previous period", compare, { compare = it })
                ReportData("/v1/admin/reports/financial/profit-loss", query = w.dateParams(mapOf("compare" to if (compare) "1" else null))) { j ->
                    SectionCard("Profit & loss · KES at the reporting rate") {
                        val unpriced = j["unpriced_lines"].int ?: 0
                        TileGrid(minWidth = 160.dp) {
                            DrillLink(metric = "revenue", title = "Sold") { StatTile("Revenue", value = fmtMoney(j["revenue"], "KES"), hint = "recognised sales, by order date", modifier = ReportTile) }
                            StatTile("Cost of goods sold", value = fmtMoney(j["cost_of_goods_sold"], "KES"), hint = if (unpriced > 0) "$unpriced lines without a cost snapshot" else "historical cost snapshots", tone = if (unpriced > 0) StatusTone.WARNING else null, modifier = ReportTile)
                            StatTile("Gross profit", value = fmtMoney(j["gross_profit"], "KES"), hint = j["gross_profit_margin_percent"].dbl?.let { "margin ${fmtPct(it)}" }, modifier = ReportTile)
                            DrillLink(metric = "expenses", title = "Expenses") { StatTile("Operating expenses", value = fmtMoney(j["operating_expenses"], "KES"), hint = "approved + paid; pending: ${fmtMoney(j["expenses_pending_approval"]["amount"], "KES")} (${j["expenses_pending_approval"]["count"].int ?: 0})", modifier = ReportTile) }
                            StatTile("Net profit", value = fmtMoney(j["net_profit"], "KES"), hint = j["net_margin"].dbl?.let { "margin ${fmtPct(it)}" }, modifier = ReportTile)
                            StatTile("Tax on sales", value = fmtMoney(j["tax_collected"], "KES"), modifier = ReportTile)
                            StatTile("Discounts given", value = fmtMoney(j["discounts_given"], "KES"), modifier = ReportTile)
                        }
                        Text("Gross profit = recognised net revenue − historical COGS; net profit = gross profit − approved/paid operating expenses (CLAUDE.md §7).", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
                    }
                    SectionCard("Expenses by category") { if (j["expenses_by_category"].arr != null) AutoTable(rows = jrows(j["expenses_by_category"]), currency = "KES") else AutoPairs(j["expenses_by_category"]) }
                    j["comparison"]?.takeUnless { it.isNull }?.let { c -> SectionCard("Previous period") { AutoPairs(c) } }
                }
            }
            "cashflow" -> ReportData("/v1/admin/reports/financial/cash-flow", query = w.dateParams()) { j ->
                SectionCard("Inflows (settled payments net of refunds, by payment month and method)") { BarsChart(points = chartPoints(j["inflows"], label = "month", y = "inflow")); AutoTable(rows = jrows(j["inflows"]), currency = "KES") }
                SectionCard("Outflows (expenses by expense month, amount_kes)") { BarsChart(points = chartPoints(j["outflows"], label = "month", y = "outflow")); AutoTable(rows = jrows(j["outflows"]), currency = "KES") }
                BasisCaption("Cash truth. Supplier payments are not in these outflows unless the server lists them.")
            }
            "expenses" -> ReportData("/v1/admin/reports/financial/expenses", query = w.dateParams()) { j ->
                SectionCard("Expenses · ${fmtMoney(j["total"], "KES")}") {
                    BasisCaption(j["total_basis"].str)
                    j["pending_approval"]?.takeUnless { it.isNull }?.let { p ->
                        val n = p["count"].int ?: 0
                        DrillLink(metric = "expenses", title = "Expenses") { StatTile("Pending approval (not in the total)", value = fmtMoney(p["total"], "KES"), hint = "$n waiting", tone = if (n > 0) StatusTone.AMBER else null, modifier = ReportTile) }
                    }
                    AutoTable(rows = jrows(j["by_status"]), currency = "KES")
                    LinesChart(points = chartPoints(j["monthly"], label = "month", y = "total", series = "Expenses"))
                }
                SectionCard("Expenses in the period") { AutoTable(rows = jrows(j["expenses"]), currency = "KES") { r -> r.int("id")?.let { env.navigator.push(Route.Expense(it)) } } }
            }
            "tax" -> ReportData("/v1/admin/reports/financial/tax", query = w.dateParams()) { j ->
                SectionCard("Tax on sales (basis: ${j["basis"].str ?: "sold"})") {
                    AutoTiles(j["totals"])
                    AutoTable(rows = jrows(j["by_tax_rate"]), currency = "KES")
                    val conflicting = j["conflicting_rate_products"].arr
                    if (!conflicting.isNullOrEmpty()) Text("${conflicting.size} product(s) carry more than one tax rate — their lines are reported at the rate on the order line.", style = MaterialTheme.typography.labelSmall, color = Palette.warning)
                }
            }
            "trends" -> ReportData("/v1/admin/reports/financial/revenue", query = w.dateParams()) { j ->
                val cur = j["period"]["currency"].str ?: "KES"
                SectionCard("${j["sold"]["label"].str ?: "Sold"} · ${fmtMoney(j["sold"]["total"], cur)}") { BasisCaption(j["sold"]["basis"].str); Monthly(j["sold"]["monthly"], cur, "Sold") }
                SectionCard("${j["collected"]["label"].str ?: "Collected"} · ${fmtMoney(j["collected"]["total"], cur)}") { BasisCaption(j["collected"]["basis"].str); Monthly(j["collected"]["monthly"], cur, "Collected") }
                j["receivable_movement"]?.takeUnless { it.isNull }?.let { r -> SectionCard("Receivable movement · ${fmtMoney(r["amount"], cur)}") { BasisCaption(r["note"].str) } }
                BasisCaption(j["note"].str)
            }
            "intelligence" -> ReportData("/v1/admin/reports/financial-intelligence", query = w.periodParams()) { j ->
                SectionCard("Earned P&L (fully-paid orders at cost snapshot; approved + paid expenses)") {
                    val p = j["pnl"]
                    val unpriced = p["unpriced_lines"].int ?: 0
                    TileGrid(minWidth = 160.dp) {
                        StatTile("Earned revenue", value = fmtMoney(p["earned_revenue"], "KES"), hint = "${p["earned_orders"].int ?: 0} orders", modifier = ReportTile)
                        StatTile("COGS (estimate)", value = fmtMoney(p["cogs_estimate"], "KES"), hint = if (unpriced > 0) "$unpriced lines without cost — counted at zero" else null, tone = if (unpriced > 0) StatusTone.WARNING else null, modifier = ReportTile)
                        StatTile("Gross profit", value = fmtMoney(p["gross_profit"], "KES"), hint = p["gross_margin_pct"].dbl?.let { "margin ${fmtPct(it)}" }, modifier = ReportTile)
                        DrillLink(metric = "expenses", title = "Expenses") { StatTile("Expenses", value = fmtMoney(p["expenses"], "KES"), hint = "pending approval, not deducted: ${fmtMoney(p["expenses_pending_approval"]["amount"], "KES")}", modifier = ReportTile) }
                        StatTile("Net profit", value = fmtMoney(p["net_profit"], "KES"), modifier = ReportTile)
                    }
                }
                SectionCard("Expenses by category") { AutoTable(rows = jrows(j["expenses"]), currency = "KES") }
                SectionCard("Cash flow by week (in: settled payments; out: expenses + supplier payments)") { LinesChart(points = chartPoints(j["cash_flow"], label = "week", y = "in", series = "In") + chartPoints(j["cash_flow"], label = "week", y = "out", series = "Out")); AutoTable(rows = jrows(j["cash_flow"]), currency = "KES") }
                SectionCard("Rails (method reconciliation)") { if (j["rails"].arr != null) AutoTable(rows = jrows(j["rails"]), currency = "KES") else AutoPairs(j["rails"]) }
            }
        }
    }
}

@Composable
private fun Monthly(v: JsonElement?, cur: String, series: String) {
    if (v.arr != null) {
        LinesChart(points = chartPoints(v, label = "month", y = "total", series = series) + chartPoints(v, label = "month", y = "amount", series = series), currency = cur)
        AutoTable(rows = jrows(v), currency = cur)
    } else jpairs(v).forEach { p -> DetailRow(p.key, fmtMoney(p.value, cur)) }
}
