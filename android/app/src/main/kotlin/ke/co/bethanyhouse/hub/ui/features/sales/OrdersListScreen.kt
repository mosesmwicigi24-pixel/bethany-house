package ke.co.bethanyhouse.hub.ui.features.sales

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowDownward
import androidx.compose.material.icons.filled.ArrowUpward
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Link
import androidx.compose.material.icons.filled.ShoppingBag
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
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
import androidx.compose.ui.platform.LocalClipboardManager
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.HubDates
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.DateField
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.DocumentButton
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.ModuleRowCard
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.PagedList
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.StatTile
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.TileModifier
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import java.time.LocalDate

// ── Orders list (GET /admin/orders — OrderController::index / screenQuery) ──

private val ORDER_STATUSES = listOf("pending", "processing", "confirmed", "shipped", "delivered", "completed", "cancelled", "refunded")

/** OrderController::SORTABLE_COLUMNS. */
private val ORDER_SORTS: List<Pair<String, String>> = listOf("created_at" to "Date", "updated_at" to "Updated", "order_number" to "Order number", "total_amount" to "Total", "status" to "Status", "payment_status" to "Payment", "order_type" to "Type")

/**
 * Till, Web, Chat and Quoted Sales and the all-orders view. Filters are the
 * ones `screenQuery` reads: search, status, sales_channel, channel,
 * start_date, end_date, sort_by (SORTABLE_COLUMNS), sort_order.
 */
@Composable
fun OrdersListScreen(channel: Route.OrderChannel?) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var search by remember { mutableStateOf("") }
    var status by remember { mutableStateOf("") }
    var orderType by remember { mutableStateOf("") } // all-orders view only: channel online|pos
    var from by remember { mutableStateOf<LocalDate?>(null) }
    var to by remember { mutableStateOf<LocalDate?>(null) }
    var sortBy by remember { mutableStateOf("created_at") }
    var sortDesc by remember { mutableStateOf(true) }
    var cancelling by remember { mutableStateOf<Record?>(null) }
    val list = remember(channel) { PagedList(scope, env.client, "/v1/admin/orders", map = ::Record) }

    fun query(): Map<String, String?> = mapOf(
        "search" to search.ifEmpty { null },
        "status" to status.ifEmpty { null },
        "sales_channel" to channel?.wire,
        "channel" to if (channel == null && orderType.isNotEmpty()) orderType else null,
        "start_date" to from?.let(HubDates::dayString),
        "end_date" to to?.let(HubDates::dayString),
        "sort_by" to sortBy,
        "sort_order" to if (sortDesc) "desc" else "asc",
    )

    fun reload() { list.query = query().toMutableMap(); list.load() }

    LaunchedEffect(list, status, orderType, sortBy, sortDesc) { reload() }

    val title = when (channel) {
        Route.OrderChannel.TILL -> "Till Sales"
        Route.OrderChannel.WEB -> "Web Orders"
        Route.OrderChannel.CHAT -> "Chat Orders"
        Route.OrderChannel.QUOTED -> "Quoted Sales"
        null -> "Orders"
    }

    Page(title, subtitle = if (list.total > 0) "${list.total} orders" else null, refresh = { list.reload() }) {
        Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
            ModuleSearchField(search, { search = it }, "Order no., customer, phone, email", onSubmit = ::reload)
            Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                OptionPicker("Status", status, ORDER_STATUSES.map { it to it.humanized }, { status = it }, Modifier.weight(1f), allowNone = true)
                if (channel == null) OptionPicker("Type", orderType, listOf("online" to "Online", "pos" to "POS"), { orderType = it }, Modifier.weight(1f), allowNone = true)
            }
            Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                DateField("From", from, { from = it }, Modifier.weight(1f))
                DateField("To", to, { to = it }, Modifier.weight(1f))
            }
            Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                OptionPicker("Sort", sortBy, ORDER_SORTS, { if (it.isNotEmpty()) sortBy = it }, Modifier.weight(1f))
                IconButton(onClick = { sortDesc = !sortDesc }) { Icon(if (sortDesc) Icons.Default.ArrowDownward else Icons.Default.ArrowUpward, contentDescription = "Sort direction") }
                Button(onClick = ::reload) { Text("Apply") }
            }
            if (env.session.current.can("orders.view")) {
                DocumentButton("Export CSV", "/v1/admin/orders/export", query(), "orders.csv")
            }
        }
        LoadableView(list.state, retry = { list.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("No orders match", "Try another status, date range or search.", Icons.Default.ShoppingBag) }) { rows ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                rows.forEachIndexed { i, r ->
                    OrderCard(r, onChanged = { list.load() }, onCancel = { cancelling = r })
                    if (i == rows.lastIndex && list.hasMore) LaunchedEffect(rows.size) { list.loadMore() }
                }
                SalesLoadMore(list)
            }
        }
    }
    cancelling?.let { order -> CancelOrderSheet(order, onDone = { list.load() }, onDismiss = { cancelling = null }) }
}

@Composable
private fun OrderCard(r: Record, onChanged: () -> Unit, onCancel: () -> Unit) {
    val env = LocalEnv.current
    val id = r.intId
    ModuleRowCard(onClick = { if (id != null) env.navigator.push(Route.Order(id)) }) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            SalesMono(r.string("order_number") ?: "#${r.id}", Modifier.weight(1f))
            MoneyText(r.money("total_amount"))
        }
        r.string("quotation_number")?.let { SalesCaption("from $it", MaterialTheme.hub.textTertiary) }
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(r.salesCustomerName ?: "Walk-in", style = MaterialTheme.typography.bodyMedium, maxLines = 1, modifier = Modifier.weight(1f))
            StatusBadge(r.status)
            PaymentSummaryCell(r)
        }
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            val channel = (r.string("sales_bucket") ?: r.string("order_type") ?: "").humanized
            val outlet = r["outlet"]["name"].str ?: r.string("outlet_name") ?: ""
            SalesCaption("$channel · $outlet", MaterialTheme.hub.textTertiary, Modifier.weight(1f))
            DateText(r.date("created_at"), DateStyle.RELATIVE)
        }
        OrderRowActions(r, onChanged = onChanged, onCancel = onCancel)
    }
}

/** `payment_summary` from the list row: one state, the methods that paid. */
@Composable
internal fun PaymentSummaryCell(record: Record) {
    val summary = record["payment_summary"]
    val state = summary["state"].str ?: record.string("payment_status")
    Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(1.dp)) {
        StatusBadge(state)
        val methods = summary["methods"].records
        if (methods.isNotEmpty()) SalesCaption(methods.mapNotNull { it["name"].str ?: it["code"].str }.joinToString(", "), MaterialTheme.hub.textTertiary)
    }
}

private val ROW_STAGES: List<Pair<String, String>> = listOf("confirmed" to "Confirm", "processing" to "Mark processed", "completed" to "Mark completed")

/**
 * The list row's actions (OrdersListPage StageMenu + payment link): Confirm,
 * Mark processed, Mark completed, Cancel — only the moves
 * OrderStatusMachine allows from the row's status — and the payment link.
 */
@Composable
internal fun OrderRowActions(order: Record, onChanged: () -> Unit, onCancel: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val clipboard = LocalClipboardManager.current
    var busy by remember { mutableStateOf(false) }
    var menu by remember { mutableStateOf(false) }
    val allowed = Sales.nextStatuses(order.status)
    val stages = ROW_STAGES.filter { it.first in allowed }
    val canCancel = "cancelled" in allowed && env.session.current.can("orders.cancel")
    val canLink = !Sales.isDead(order.status) && order.string("payment_status") != "paid"
    val id = order.intId ?: return

    suspend fun move(status: String, label: String) {
        busy = true
        try {
            env.client.put("/v1/admin/orders/$id/status", jsonOf("status" to status))
            env.toasts.show("${order.string("order_number") ?: "Order"} — ${label.lowercase()}")
            onChanged()
        } catch (e: Exception) { env.toasts.error(e) } finally { busy = false }
    }

    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
        if (env.session.current.can("orders.edit") && (stages.isNotEmpty() || canCancel)) {
            androidx.compose.foundation.layout.Box {
                TextButton(enabled = !busy, onClick = { menu = true }) {
                    if (busy) CircularProgressIndicator(Modifier.size(14.dp), strokeWidth = 2.dp) else Text("Stage")
                }
                DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                    stages.forEach { (st, label) -> DropdownMenuItem(text = { Text(label) }, onClick = { menu = false; scope.launch { move(st, label) } }) }
                    if (canCancel) {
                        HorizontalDivider()
                        DropdownMenuItem(text = { Text("Cancel order…", color = Palette.danger) }, onClick = { menu = false; onCancel() })
                    }
                }
            }
        }
        if (canLink) {
            IconButton(onClick = {
                scope.launch {
                    try {
                        val r = env.client.get("/v1/admin/orders/$id/payment-link")
                        val url = r.json["payment_url"].str ?: r.json["url"].str
                        if (url != null) { clipboard.setText(AnnotatedString(url)); env.toasts.show("Payment link copied") }
                    } catch (e: Exception) { env.toasts.error(e) }
                }
            }) { Icon(Icons.Default.Link, contentDescription = "Generate & copy payment link", modifier = Modifier.size(18.dp)) }
        }
    }
}

/** PUT /orders/{id}/status {status: cancelled, notes} — orders.edit for the route, orders.cancel to offer it. */
@Composable
internal fun CancelOrderSheet(order: Record, onDone: () -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var notes by remember { mutableStateOf("") }
    FormSheet("Cancel ${order.string("order_number") ?: "order"}?", onDismiss, saveLabel = "Cancel order", destructive = true, save = {
        val id = order.intId ?: return@FormSheet
        val body = mutableMapOf<String, Any?>("status" to "cancelled")
        if (notes.isNotBlank()) body["notes"] = notes
        env.client.put("/v1/admin/orders/$id/status", ke.co.bethanyhouse.hub.core.toJson(body))
        env.toasts.show("Order cancelled")
        onDone()
    }) { error ->
        SalesSecondary("Cancelling takes the order out of recognised sales. Settled money stays on the order until it is reversed or refunded.")
        Field("Note (optional)", notes, { notes = it }, multiline = true)
        SalesFieldErrors(error)
    }
}

// ── Pending queue (GET /admin/orders/pending-queue) ─────────────────────────

/**
 * The unconfirmed-order worklist: `{summary, rows}` sorted by value × age
 * on the server. `kes_value` is null when the currency has no reporting
 * rate — listed, never summed; `excluded_currencies` names them.
 */
@Composable
fun PendingQueueScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val resource = remember { Resource(scope) { env.client.get("/v1/admin/orders/pending-queue").json ?: JsonNull } }
    LaunchedEffect(resource) { resource.load() }
    var action by remember { mutableStateOf<Pair<Record, Boolean>?>(null) }

    Page("Pending Queue", subtitle = "Unconfirmed orders — confirm, record a payment on the order, or cancel", refresh = { resource.reload() }) {
        LoadableView(resource.state, retry = { resource.load() }) { json ->
            QueueSummary(json["summary"])
            val rows = json["rows"].toRecords()
            if (rows.isEmpty()) EmptyState("Nothing is waiting", "Every order has been confirmed or cancelled.", Icons.Default.CheckCircle)
            else Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                rows.forEach { r -> QueueRow(r, onConfirm = { action = r to true }, onCancel = { action = r to false }) }
            }
        }
    }
    action?.let { (row, confirm) -> QueueDecisionSheet(row, confirm, onDone = { resource.load() }, onDismiss = { action = null }) }
}

@Composable
private fun QueueSummary(s: JsonElement?) {
    SectionCard {
        TileGrid(minWidth = 150.dp) {
            StatTile("Waiting", s["total_count"].str ?: "0", modifier = TileModifier)
            StatTile("Value (KES at reporting rate)", Money.from(s["total_kes"], "KES")?.formatted() ?: "—", modifier = TileModifier)
            StatTile("Fresh (≤ 2 days)", s["fresh_count"].str ?: "0", tone = StatusTone.SUCCESS, modifier = TileModifier)
            StatTile("Aging", s["aging_count"].str ?: "0", tone = StatusTone.AMBER, modifier = TileModifier)
            StatTile("Stale", s["stale_count"].str ?: "0", tone = StatusTone.DANGER, modifier = TileModifier)
        }
        val excluded = s["excluded_currencies"].records
        if (excluded.isNotEmpty()) {
            SalesCaption("Not in the KES figure (no reporting rate): " + excluded.joinToString("; ") { e ->
                val code = e["code"].str ?: "?"
                val amt = Money.from(e["amount"], code)?.formatted() ?: ""
                "${e["n"].str ?: "0"} × $code $amt"
            }, Palette.warning)
        }
    }
}

@Composable
private fun QueueRow(r: Record, onConfirm: () -> Unit, onCancel: () -> Unit) {
    val env = LocalEnv.current
    val days = r.int("days_pending") ?: 0
    val tone = if (days <= 2) StatusTone.SUCCESS else if (days <= 7) StatusTone.AMBER else StatusTone.DANGER
    val id = r.intId
    SectionCard {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    TextButton(onClick = { if (id != null) env.navigator.push(Route.Order(id)) }, contentPadding = androidx.compose.foundation.layout.PaddingValues(0.dp)) { SalesMono(r.string("order_number") ?: "#${r.id}") }
                    StatusBadge("$days day${if (days == 1) "" else "s"}", tone)
                    if (r["possible_duplicate"].bool == true) StatusBadge("possible duplicate", StatusTone.DANGER)
                }
                if (r["possible_duplicate"].bool == true) SalesCaption("Another pending order shares this phone number — likely one sale quoted twice. Confirm one, cancel the twin.", Palette.danger)
                Text(r.salesCustomerName ?: "Walk-in", style = MaterialTheme.typography.bodyMedium)
                SalesCaption(listOfNotNull(r.string("customer_phone"), r.string("customer_email")).joinToString(" · "))
                Row(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalAlignment = Alignment.CenterVertically) {
                    SalesCaption((r.string("source") ?: "").humanized, MaterialTheme.hub.textTertiary)
                    DateText(r.date("created_at"))
                }
            }
            Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(3.dp)) {
                MoneyText(r.money("total_amount"), emphasis = true)
                val kes = r["kes_value"]
                if (!kes.isNull && r.string("currency_code") != "KES") SalesCaption("= ${Money.from(kes, "KES")?.formatted() ?: "—"}")
                else if (kes.isNull) SalesCaption("no reporting rate", Palette.warning)
            }
        }
        FlowRow(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Sales.chatUrl(r.string("customer_phone"), r.string("order_number"))?.let { SalesLinkButton("Open chat in Neema", it) }
            Spacer(Modifier.weight(1f))
            if (env.session.current.can("orders.edit")) {
                OutlinedButton(onClick = onCancel) { Text("Cancel…", color = Palette.danger) }
                Button(onClick = onConfirm) { Text("Confirm") }
            }
        }
    }
}

@Composable
private fun QueueDecisionSheet(row: Record, confirm: Boolean, onDone: () -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var notes by remember { mutableStateOf("") }
    FormSheet("${if (confirm) "Confirm" else "Cancel"} ${row.string("order_number") ?: ""}?", onDismiss, saveLabel = if (confirm) "Confirm order" else "Cancel order", destructive = !confirm, save = {
        val id = row.intId ?: return@FormSheet
        val body = mutableMapOf<String, Any?>("status" to if (confirm) "confirmed" else "cancelled")
        if (notes.isNotBlank()) body["notes"] = notes
        env.client.put("/v1/admin/orders/$id/status", ke.co.bethanyhouse.hub.core.toJson(body))
        env.toasts.show(if (confirm) "Order confirmed" else "Order cancelled")
        onDone()
    }) { error ->
        SalesSecondary(if (confirm) "Confirming books this order as income from now on; the customer still owes the balance until it is paid." else "Cancelling removes the order from the queue and from recognised sales.")
        Field("Note (optional)", notes, { notes = it }, multiline = true)
        SalesFieldErrors(error)
    }
}
