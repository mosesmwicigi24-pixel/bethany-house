package ke.co.bethanyhouse.hub.ui.features.reports

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.arr
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.dbl
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.obj
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.JRow
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.fmtMoney
import ke.co.bethanyhouse.hub.ui.components.fmtPct
import ke.co.bethanyhouse.hub.ui.components.jpairs
import ke.co.bethanyhouse.hub.ui.components.jrows
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import java.util.Locale

/**
 * Customers & Neema: overview (customers/summary), LTV, retention, second
 * purchase, win-back (+ outreach log and logging a contact), institutions,
 * geography and channels (intelligence.view), replenishment, Neema.
 */
@Composable
fun CustomersReportScreen() {
    val env = LocalEnv.current
    var tab by remember { mutableStateOf("overview") }
    var logging by remember { mutableStateOf<JRow?>(null) }
    val tabs = remember(env.session.current) {
        val t = mutableListOf("overview" to "Overview", "ltv" to "Lifetime value", "retention" to "Retention", "secondpurchase" to "Second purchase", "winback" to "Win-back", "outreachlog" to "Outreach log", "institutions" to "Institutions", "replenishment" to "Replenishment", "neema" to "Neema")
        if (env.session.current.can("intelligence.view")) { t += "geography" to "Geography"; t += "channels" to "Channels" }
        t.toList()
    }
    val dated = tab in listOf("overview", "ltv", "retention", "neema")
    fun csv(w: ReportWindow): CSVExport = when (tab) {
        "ltv" -> CSVExport("/v1/admin/reports/customers/lifetime-value", w.dateParams(), "customers-ltv.csv")
        "retention" -> CSVExport("/v1/admin/reports/customers/retention", w.dateParams(), "customers-retention.csv")
        "neema" -> CSVExport("/v1/admin/reports/sales/neema", w.dateParams(), "neema.csv")
        else -> CSVExport("/v1/admin/reports/customers/summary", w.dateParams(), "customers-summary.csv")
    }

    ReportShell(page = Route.ReportPage.CUSTOMERS, csv = ::csv, pdfType = "customers", scheduleType = "customers", dated = dated) { w ->
        SegmentedTabs(tabs, tab) { tab = it }
        val outlet = w.outletParams()
        when (tab) {
            "overview" -> ReportData("/v1/admin/reports/customers/summary", query = w.dateParams()) { j ->
                SectionCard("Customers in the period") { AutoTiles(j); AutoTable(rows = jrows(j["acquisition_trend"])) }
            }
            "ltv" -> ReportData("/v1/admin/reports/customers/lifetime-value", query = w.dateParams(mapOf("limit" to "50"))) { j ->
                val cur = j["currency"].str ?: "KES"
                SectionCard("Lifetime value · $cur") { AutoTable(rows = jrows(j["customers"]), currency = cur) { r -> r.int("id")?.let { env.navigator.push(Route.Customer(it)) } } }
            }
            "retention" -> ReportData("/v1/admin/reports/customers/retention", query = w.dateParams()) { j ->
                SectionCard("Cohort retention (customers who bought again, by month)") {
                    jrows(j["retention"]).forEach { c ->
                        Column(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Text("${c.str("cohort") ?: ""} (${c.int("size") ?: 0})", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                            Text(jpairs(c["months"]).joinToString("  ") { "${it.key}: ${it.value.int ?: 0}" }, style = MaterialTheme.typography.labelSmall)
                        }
                        HorizontalDivider(color = MaterialTheme.hub.line)
                    }
                }
            }
            "secondpurchase" -> ReportData("/v1/admin/reports/second-purchase", query = outlet) { j ->
                SectionCard("First → second purchase") { AutoTiles(j["summary"]); AutoTable(rows = jrows(j["cohorts"])) }
                SectionCard("Recent one-time buyers (${j["worklist_total"].int ?: 0}, last ${j["recent_days"].int ?: 0} days)") {
                    AutoTable(rows = jrows(j["worklist"]), hide = setOf("order_id", "email")) { r -> r.int("order_id")?.let { env.navigator.push(Route.Order(it)) } }
                }
            }
            "winback" -> ReportData("/v1/admin/reports/win-back", query = outlet) { j ->
                SectionCard("Dormant customers against their own rhythm") { AutoTiles(j["summary"]) }
                SectionCard("Customers at risk") {
                    jrows(j["customers"]).forEach { c ->
                        Column(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top) {
                                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                    Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                                        Text(c.str("name") ?: "", style = MaterialTheme.typography.bodyMedium)
                                        Text(String.format(Locale.US, "%.1f× overdue", c.dbl("urgency") ?: 0.0), style = MaterialTheme.typography.labelSmall, color = Palette.warning)
                                    }
                                    Text("${c.str("phone") ?: "—"} · quiet ${c.int("days_quiet") ?: 0}d · usual gap ${c.int("median_gap_days") ?: 0}d · ${c.int("orders_365") ?: 0} orders/yr", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                                    c["last_outreach"]?.takeUnless { it.isNull }?.let { o -> Text("Last contact: ${o["channel"].str ?: ""} ${o["at"].str?.take(10) ?: ""} by ${o["by_name"].str ?: "—"}", style = MaterialTheme.typography.labelSmall) }
                                    c["recovered"]?.takeUnless { it.isNull }?.let { r -> Text("Won back: ${r["order_number"].str ?: ""} ${fmtMoney(r["amount"], "KES")}", style = MaterialTheme.typography.labelSmall, color = Palette.success) }
                                }
                                Column(horizontalAlignment = Alignment.End) {
                                    Text(fmtMoney(c["revenue_365"], "KES"), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                                    Text("at risk / yr", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
                                }
                            }
                            Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                                if (env.session.current.can("customers.insights")) TextButton(onClick = { logging = c }) { Text("Log contact") }
                                c.int("customer_id")?.let { id -> TextButton(onClick = { env.navigator.push(Route.Customer(id)) }) { Text("Open") } }
                            }
                        }
                        HorizontalDivider(color = MaterialTheme.hub.line)
                    }
                }
            }
            "outreachlog" -> ReportData("/v1/admin/reports/outreach-log", query = emptyMap()) { j ->
                SectionCard("Outreach (automated radar pings + manual win-back), newest first") { AutoTiles(j["summary"]); AutoTable(rows = jrows(j["rows"])) }
            }
            "institutions" -> ReportData("/v1/admin/reports/institutions", query = outlet) { j ->
                SectionCard("Institutional accounts (trailing 365 days)") { AutoTiles(j["summary"]) }
                SectionCard("Accounts") {
                    jrows(j["accounts"]).forEach { a ->
                        Column(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                                Text(a.str("name") ?: "", style = MaterialTheme.typography.bodyMedium, modifier = Modifier.weight(1f))
                                if (a["risk"].bool == true) StatusBadge("at risk", StatusTone.WARNING)
                                Text(fmtMoney(a["revenue_365"], "KES"), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                            }
                            Text("${a.str("phone") ?: "—"} · ${a.int("orders_365") ?: 0} orders · quiet ${a.int("days_quiet") ?: 0}d · ${a.int("buyer_contacts") ?: 0} buyer contact(s) · top: ${a.str("top_product") ?: "—"}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                            a["cross_sell"].arr?.takeIf { it.isNotEmpty() }?.let { x -> Text("Cross-sell: " + x.joinToString(", ") { "${it["name"].str ?: ""} (${fmtPct(it["adoption_pct"].dbl, 0)})" }, style = MaterialTheme.typography.labelSmall) }
                        }
                        HorizontalDivider(color = MaterialTheme.hub.line)
                    }
                }
            }
            "replenishment" -> ReportData("/v1/admin/reports/replenishment", query = outlet) { j ->
                SectionCard("Replenishment radar (as of now)") { AutoTiles(j["summary"]) }
                SectionCard("Due") { AutoTable(rows = jrows(j["due"])) { r -> r.int("product_id")?.let { env.navigator.push(Route.Product(it)) } } }
            }
            "geography" -> ReportData("/v1/admin/intelligence/customer-geography", query = emptyMap()) { j ->
                SectionCard("Where customers are") { AutoTiles(j["summary"]); AutoTable(rows = jrows(j["countries"])) }
            }
            "channels" -> ReportData("/v1/admin/intelligence/channel-engagement", query = emptyMap()) { j ->
                SectionCard("Messaging channels") { AutoTiles(j["summary"]); AutoTable(rows = jrows(j["channels"])) }
                SectionCard("Most active customers") {
                    AutoTable(rows = jrows(j["top_customers"]).map { r -> JRow(r.id, JsonObject((r.json.obj ?: emptyMap()) + ("channels" to JsonPrimitive(r["channels"].records.mapNotNull { it.str }.joinToString(", "))))) })
                }
            }
            "neema" -> NeemaSection(w)
        }
    }
    logging?.let { c -> WinBackOutreachSheet(customer = c) { logging = null } }
}

/** POST reports/win-back/outreach (customers.insights): logs a manual contact; the server stamps the user, snapshots revenue/days quiet and dedupes within 24h. */
@Composable
private fun WinBackOutreachSheet(customer: JRow, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var channel by remember { mutableStateOf("whatsapp") }
    FormSheet("Log a contact with ${customer.str("name") ?: ""}", onDismiss, saveLabel = "Log", save = {
        val body = J.obj(
            "name" to J.s(customer.str("name") ?: ""), "channel" to J.s(channel),
            "customer_id" to customer.int("customer_id")?.let { J.i(it) }, "phone" to J.sOpt(customer.str("phone")),
            "revenue_365" to customer["revenue_365"], "days_quiet" to customer["days_quiet"],
        )
        val r = env.client.post("/v1/admin/reports/win-back/outreach", body)
        val already = r.json["already_logged"].bool == true
        env.toasts.show(if (already) "Already logged in the last 24 hours" else "Contact logged", if (already) StatusTone.INFO else StatusTone.SUCCESS)
    }) { _ ->
        SegmentedTabs(listOf("whatsapp" to "WhatsApp", "call" to "Call", "other" to "Other"), channel) { channel = it }
        Text("Recorded against ${customer.str("phone") ?: "this customer"}; a recovery is attributed to the first order within 30 days.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
    }
}
