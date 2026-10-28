package ke.co.bethanyhouse.hub.ui.features.reports

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.AsyncButton
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.DecimalField
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.JRow
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.RemoteImage
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.fmtMoney
import ke.co.bethanyhouse.hub.ui.components.fmtNumber
import ke.co.bethanyhouse.hub.ui.components.fmtPct
import ke.co.bethanyhouse.hub.ui.components.jrows
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive

/**
 * Signals (IntelligenceDashboard.tsx): churn risk, reorder suggestions with
 * the auto-reorder action, smart tasks, budget warnings, material shortages
 * with a preflight check, tailor workload. Each feed keeps its own module
 * gate on top of reports.signals; a refused feed shows as such.
 */
@Composable
fun SignalsReportScreen() {
    val env = LocalEnv.current
    var preflightProduct by remember { mutableStateOf("") }
    var preflightQty by remember { mutableStateOf("1") }
    var preflight by remember { mutableStateOf<JsonElement?>(null) }

    ReportShell(page = Route.ReportPage.SIGNALS, subtitle = "As of now · intelligence feeds", dated = false) { _ ->
        if (env.session.current.can("inventory.view")) {
            SignalFeed(title = "Reorder suggestions", path = "/v1/admin/intelligence/reorder-suggestions", key = "suggestions") { r ->
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    RemoteImage(url = r.str("product_image"), size = 36.dp)
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                            Text(r.str("product_name") ?: "", style = MaterialTheme.typography.bodyMedium)
                            StatusBadge(r.str("severity"), tone = if (r.str("severity") == "out_of_stock") StatusTone.DANGER else StatusTone.WARNING)
                        }
                        Text("${r.str("sku") ?: ""}${r["variant"]["name"].str?.let { " · $it" } ?: ""} · ${r["outlet"]["name"].str ?: "Warehouse"} · available ${r.int("quantity_available") ?: 0} / reorder point ${r.int("reorder_point") ?: 0} · suggest ${r.int("reorder_quantity") ?: 0}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    }
                    val iid = r.int("inventory_item_id")
                    if (env.session.current.can("procurement.create") && iid != null) {
                        ConfirmButton("Draft a purchase order", "A draft PO is created for this item with its active supplier.", action = {
                            try {
                                val res = env.client.post("/v1/admin/intelligence/auto-reorder/$iid")
                                env.toasts.show(res.json["message"].str ?: "Draft PO created")
                                res.json["purchase_order"]["id"].int?.let { env.navigator.push(Route.PurchaseOrder(it)) }
                            } catch (e: ApiError) { env.toasts.error(e) }
                        }) { Text("Auto-reorder") }
                    }
                }
            }
            SignalFeed(title = "Material shortages (open production orders)", path = "/v1/admin/intelligence/material-shortages", key = "shortages") { r ->
                Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                        Text(r.str("material_name") ?: "", style = MaterialTheme.typography.bodyMedium)
                        StatusBadge(r.str("severity"), tone = if (r.str("severity") == "out_of_stock") StatusTone.DANGER else StatusTone.WARNING)
                    }
                    Text("needed ${fmtNumber(r.dbl("total_needed"), 2)} ${r.str("unit") ?: ""} across ${r.int("orders_needing") ?: 0} orders · available ${fmtNumber(r.dbl("available"), 2)} · short ${fmtNumber(r.dbl("shortfall"), 2)}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                }
            }
            SectionCard("Preflight: can we make N of a product?") {
                Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                    Field("Product id", preflightProduct, { preflightProduct = it.filter { c -> c.isDigit() } }, Modifier.width(130.dp), keyboard = KeyboardType.Number)
                    DecimalField("Qty", preflightQty, { preflightQty = it }, Modifier.width(90.dp))
                }
                AsyncButton("Check", enabled = preflightProduct.toIntOrNull() != null) {
                    val pid = preflightProduct.toIntOrNull() ?: return@AsyncButton
                    preflight = env.client.post("/v1/admin/intelligence/material-shortages/preflight", JsonObject(mapOf("product_id" to JsonPrimitive(pid), "quantity" to JsonPrimitive(preflightQty.toIntOrNull() ?: 1)))).json
                }
                preflight?.let { p ->
                    val rows = jrows(p["shortages"])
                    if (rows.isEmpty()) Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Default.CheckCircle, contentDescription = null, tint = Palette.success, modifier = Modifier.size(16.dp))
                        Spacer(Modifier.width(4.dp))
                        Text("No shortages for this quantity.", style = MaterialTheme.typography.bodySmall, color = Palette.success)
                    } else AutoTable(rows = rows)
                }
            }
        }
        if (env.session.current.can("intelligence.view")) {
            SignalFeed(title = "Churn risk", path = "/v1/admin/intelligence/churn-risk", key = "customers", query = mapOf("limit" to "20")) { r ->
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                            Text(r.str("name") ?: "", style = MaterialTheme.typography.bodyMedium)
                            StatusBadge(r.str("risk_level"), tone = if (r.str("risk_level") == "high") StatusTone.DANGER else StatusTone.WARNING)
                        }
                        Text("${r.str("phone") ?: r.str("email") ?: ""} · ${r.int("total_orders") ?: 0} orders · last ${r.int("days_since_last") ?: 0}d ago, usual ${r.int("avg_interval_days") ?: 0}d · overdue ${r.int("overdue_by_days") ?: 0}d", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    }
                    Text(fmtMoney(r["lifetime_value"], "KES"), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
                    r.int("customer_id")?.let { id -> TextButton(onClick = { env.navigator.push(Route.Customer(id)) }) { Text("Open") } }
                }
            }
        }
        if (env.session.current.can("expenses.view")) {
            SignalFeed(title = "Budget warnings", path = "/v1/admin/intelligence/budget-warnings", key = "warnings") { r ->
                val pct = r.dbl("utilization_percent") ?: 0.0
                val exceeded = r.str("severity") == "exceeded"
                Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                        Text("${r.str("category_name") ?: ""} · ${r.str("outlet_name") ?: ""}", style = MaterialTheme.typography.bodyMedium, modifier = Modifier.weight(1f))
                        StatusBadge(r.str("severity"), tone = if (exceeded) StatusTone.DANGER else StatusTone.WARNING)
                        Text(fmtPct(pct, 0), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
                    }
                    Text("${r.str("period") ?: ""} · budget ${fmtMoney(r["budgeted_amount"], "KES")} · spent ${fmtMoney(r["actual_spend"], "KES")} · remaining ${fmtMoney(r["remaining"], "KES")}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    LinearProgressIndicator(progress = { (pct.coerceIn(0.0, 100.0) / 100.0).toFloat() }, color = if (exceeded) Palette.danger else Palette.warning, modifier = Modifier.fillMaxWidth())
                }
            }
        }
        if (env.session.current.can("production.manage_assignees")) {
            SignalFeed(title = "Tailor workload", path = "/v1/admin/intelligence/tailor-workload", key = "tailors") { r ->
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                            Text(r.str("name") ?: "", style = MaterialTheme.typography.bodyMedium)
                            StatusBadge(r.str("recommendation"), tone = when (r.str("recommendation")) { "available" -> StatusTone.SUCCESS; "light" -> StatusTone.INFO; "moderate" -> StatusTone.AMBER; "heavy" -> StatusTone.DANGER; else -> StatusTone.NEUTRAL })
                        }
                        Text("${r.int("active_tasks") ?: 0} active · ${r.int("overdue_tasks") ?: 0} overdue · ${fmtNumber(r.dbl("avg_hours_per_task"), 1)} h/task · completion ${fmtPct(r.dbl("completion_rate"), 0)}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    }
                    Text("score ${fmtNumber(r.dbl("workload_score"), 1)}", style = MaterialTheme.typography.labelSmall)
                }
            }
        }
        SignalFeed(title = "Smart tasks (your open production tasks, prioritised)", path = "/v1/admin/intelligence/smart-tasks", key = null) { r ->
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(r["productionOrder"]["product"]["translations"][0]["name"].str ?: r.str("title") ?: r.str("name") ?: "Task #${r.str("id") ?: ""}", style = MaterialTheme.typography.bodyMedium)
                    Text(listOfNotNull(r.str("status")?.humanized, r.str("stage_name"), r.str("priority"), r.str("due_date")?.let { "due ${it.take(10)}" }).joinToString(" · "), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                }
                (r["productionOrder"]["id"].int ?: r.int("production_order_id"))?.let { id -> TextButton(onClick = { env.navigator.push(Route.ProductionOrder(id)) }) { Text("Open order") } }
            }
        }
    }
}

/** One intelligence feed: `{key: [...]}` (or a bare array), rendered per row. */
@Composable
private fun SignalFeed(title: String, path: String, key: String?, query: Map<String, String?> = emptyMap(), row: @Composable (JRow) -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val version = LocalReportVersion.current
    val resource = remember(path) { Resource(scope) { val j = env.client.get(path, query).json; jrows(if (key != null) j[key] else j) } }
    LaunchedEffect(resource, version) { resource.reload() }
    SectionCard(title) {
        LoadableView(resource.state, retry = { resource.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("Nothing to flag", icon = Icons.Default.CheckCircle) }) { rows ->
            Column { rows.forEach { r -> Column(Modifier.padding(vertical = 4.dp)) { row(r) }; HorizontalDivider(color = MaterialTheme.hub.line) } }
        }
    }
}
