package ke.co.bethanyhouse.hub.ui.features

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.NotificationsOff
import androidx.compose.material.icons.filled.Verified
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.Surface
import androidx.compose.material3.Switch
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
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.core.MoneyByCurrency
import ke.co.bethanyhouse.hub.core.NavCatalogue
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.money
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.PagedList
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.StatTile
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.TileModifier
import ke.co.bethanyhouse.hub.ui.hub
import ke.co.bethanyhouse.hub.ui.shell.FeatureRegistry
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import java.time.LocalTime

/** Workspace: Dashboard, Notifications, Approvals. */
object WorkspaceFeature {
    fun register() {
        FeatureRegistry.register(Route.Dashboard) { DashboardScreen() }
        FeatureRegistry.register(Route.Notifications) { NotificationsScreen() }
        FeatureRegistry.register(Route.Approvals) { ApprovalsScreen() }
    }
}

// ── Dashboard (GET /admin/dashboard — stats, recent_activity, alerts) ────────

@Composable
fun DashboardScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val resource = remember { Resource(scope) { env.client.get("/v1/admin/dashboard").json ?: JsonNull } }
    LaunchedEffect(Unit) { resource.load() }
    val hour = LocalTime.now().hour
    val greeting = (if (hour < 12) "Good morning" else if (hour < 17) "Good afternoon" else "Good evening") + ", " + (env.session.current.me?.firstName ?: "")

    Page("Dashboard", subtitle = greeting, refresh = { resource.reload() }) {
        LoadableView(resource.state, retry = { resource.load() }) { json ->
            val stats = json["stats"]
            json["alerts"].records.forEach { a ->
                val tone = if (a["type"].str == "danger") StatusTone.DANGER else StatusTone.WARNING
                Row(Modifier.fillMaxWidth().background(tone.background, MaterialTheme.shapes.small).padding(Space.m), verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Default.Warning, contentDescription = null, tint = tone.foreground, modifier = Modifier.size(18.dp))
                    Spacer(Modifier.width(Space.s))
                    Text(a["message"].str ?: "", style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f))
                    val url = a["action_url"].str
                    val r = url?.let { Route.fromConsolePath(it.removePrefix("/admin")) }
                    if (r != null) TextButton(onClick = { env.navigator.go(r) }) { Text(a["action_label"].str ?: "View") }
                }
            }
            QuickActions()
            SectionCard("Today") {
                TileGrid {
                    StatTile("Today's sales", stats["today_sales"].money?.formatted() ?: "—", hint = stats["today_sales_basis"].str, modifier = TileModifier)
                    StatTile("Orders today", stats["today_orders"].str ?: "0", modifier = TileModifier)
                    StatTile("Collected today", stats["today_collected"].money?.formatted() ?: stats["cash_today"]["kes_total"].money?.formatted() ?: "—", modifier = TileModifier)
                    StatTile("Pending orders", stats["pending_orders"].str ?: "0", tone = if ((stats["pending_orders"].int ?: 0) > 0) StatusTone.AMBER else null, modifier = TileModifier)
                }
                MoneyByCurrency.from(stats["today_sales_by_currency"] ?: stats["cash_today"])?.let { if (it.byCurrency.isNotEmpty()) MixedCurrencyLines(it) }
            }
            if (!stats["production_queue"].isNull || !stats["production_in_progress"].isNull) SectionCard("Production") {
                TileGrid {
                    StatTile("Queue", stats["production_queue"].str ?: "0", modifier = TileModifier)
                    StatTile("In progress", stats["production_in_progress"].str ?: "0", modifier = TileModifier)
                    StatTile("QC pending", stats["production_qc_pending"].str ?: "0", tone = if ((stats["production_qc_pending"].int ?: 0) > 0) StatusTone.PINK else null, modifier = TileModifier)
                    StatTile("Overdue", stats["production_overdue"].str ?: "0", tone = if ((stats["production_overdue"].int ?: 0) > 0) StatusTone.DANGER else null, modifier = TileModifier)
                }
            }
            SectionCard("Stock & fulfilment") {
                TileGrid {
                    StatTile("Low stock", stats["low_stock_products"].str ?: "0", tone = if ((stats["low_stock_products"].int ?: 0) > 0) StatusTone.WARNING else null, modifier = TileModifier)
                    StatTile("To dispatch", stats["shipments_pending_dispatch"].str ?: "0", modifier = TileModifier)
                    StatTile("In transit", stats["shipments_in_transit"].str ?: "0", modifier = TileModifier)
                    StatTile("Carts waiting", stats["carts_waiting"].str ?: "0", modifier = TileModifier)
                }
            }
            val activity = json["recent_activity"].records
            if (activity.isNotEmpty()) SectionCard("Recent activity") {
                activity.take(12).forEach { a ->
                    Row(verticalAlignment = Alignment.Top) {
                        Box(Modifier.padding(top = 7.dp).size(6.dp).background(Palette.brand, CircleShape))
                        Spacer(Modifier.width(Space.m))
                        Column {
                            Text(a["description"].str ?: a["type"].str?.humanized ?: "Activity", style = MaterialTheme.typography.bodyMedium)
                            Text("${a["user"].str ?: "System"} · ${a["time"].str ?: ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        }
                    }
                }
            }
        }
    }
}

/** Native figures per currency with the KES line beside them; never a sum across currencies. */
@Composable
fun MixedCurrencyLines(mixed: MoneyByCurrency) {
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        mixed.byCurrency.forEach { line ->
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(line.currency, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, modifier = Modifier.width(44.dp))
                MoneyText(line.native ?: Money.from(line.raw["amount"], line.currency))
                Spacer(Modifier.weight(1f))
                val kes = line.kes ?: Money.from(line.raw["amount_kes"], "KES")
                if (kes != null) Text("= ${kes.formatted()}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                else Text("no reporting rate", style = MaterialTheme.typography.labelSmall, color = Palette.warning)
            }
        }
        if (mixed.unconverted.isNotEmpty()) Text(
            "Unconverted: " + mixed.unconverted.mapNotNull { it.str ?: it["currency_code"].str }.joinToString(", "),
            style = MaterialTheme.typography.labelSmall, color = Palette.warning,
        )
    }
}

@Composable
private fun QuickActions() {
    val env = LocalEnv.current
    val s = env.session.current
    androidx.compose.foundation.layout.FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
        @Composable fun quick(label: String, route: Route) = OutlinedButton(onClick = { env.navigator.go(route) }) { Text(label) }
        if (s.can("pos.access")) quick("Open POS", Route.Pos)
        if (s.can("orders.create")) quick("New Order", Route.Pos)
        if (s.can("customers.create")) quick("New Customer", Route.Customers)
        if (s.can("production.raise_order")) quick("Production Order", Route.ProductionOrders)
        if (s.can("procurement.receive")) quick("Receive Goods", Route.GoodsReceipt)
        if (s.allows(NavCatalogue.approvalsGate)) quick("Approvals", Route.Approvals)
    }
}

// ── Notifications ────────────────────────────────────────────────────────────

@Composable
fun NotificationsScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var unreadOnly by remember { mutableStateOf(false) }
    val list = remember { PagedList(scope, env.client, "/v1/admin/notifications", map = ::Record) }
    LaunchedEffect(unreadOnly) { list.query["unread_only"] = if (unreadOnly) "1" else null; list.reload() }

    suspend fun open(n: Record) {
        val read = n["is_read"].bool ?: !n["read_at"].isNull
        if (!read) { runCatching { env.client.post("/v1/admin/notifications/${n.id}/read") }; env.unreadNotifications = maxOf(0, env.unreadNotifications - 1) }
        n.string("action_url")?.let { Route.fromConsolePath(it.removePrefix("/admin")) }?.let { env.navigator.go(it) }
        list.reload()
    }

    Page("Notifications", refresh = { list.reload() }) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Switch(checked = unreadOnly, onCheckedChange = { unreadOnly = it })
            Spacer(Modifier.width(Space.s))
            Text("Unread only", style = MaterialTheme.typography.bodySmall)
            Spacer(Modifier.weight(1f))
            TextButton(onClick = { scope.launch { runCatching { env.client.post("/v1/admin/notifications/read-all") }; env.unreadNotifications = 0; list.reload() } }) { Text("Mark all read") }
        }
        LoadableView(list.state, retry = { list.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("You're all caught up", "New approvals, orders and messages will show here.", Icons.Default.NotificationsOff) }) { items ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                items.forEachIndexed { i, n ->
                    val read = n["is_read"].bool ?: !n["read_at"].isNull
                    Surface(shape = MaterialTheme.shapes.small, color = MaterialTheme.hub.card, modifier = Modifier.fillMaxWidth().clickable { scope.launch { open(n) } }) {
                        Row(Modifier.padding(Space.m), verticalAlignment = Alignment.Top) {
                            Box(Modifier.padding(top = 7.dp).size(8.dp).background(if (read) androidx.compose.ui.graphics.Color.Transparent else Palette.brand, CircleShape))
                            Spacer(Modifier.width(Space.m))
                            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                                Text(n.string("title") ?: "Notification", style = MaterialTheme.typography.bodyMedium, fontWeight = if (read) FontWeight.Normal else FontWeight.SemiBold)
                                n.string("body")?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary, maxLines = 3) }
                                DateText(n.date("created_at"), DateStyle.RELATIVE)
                            }
                            TextButton(onClick = { scope.launch { try { env.client.delete("/v1/admin/notifications/${n.id}"); list.remove { it.id == n.id } } catch (e: ApiError) { env.toasts.error(e) } } }) { Text("Delete", color = Palette.danger) }
                        }
                    }
                    if (i == items.lastIndex && list.hasMore) LaunchedEffect(items.size) { list.loadMore() }
                }
            }
        }
    }
}

// ── Approvals (ApprovalEngine::present) ──────────────────────────────────────

private class ApprovalRequest(val record: Record) {
    val id get() = record.id
    val event get() = record.string("event") ?: "approval"
    val type get() = record.string("approvable_type") ?: ""
    val status get() = record.status
    val maker get() = record["maker"]["name"].str
    val counterparty get() = record.string("counterparty")
    val amount get() = Money.from(record["amount"], record.string("currency_code") ?: "KES")
    val amountKes get() = Money.from(record["amount_kes"], "KES")
    val valueUnknown get() = record.bool("value_unknown") ?: false
    val awaiting get() = record["awaiting"]["label"].str ?: record["awaiting"]["permission"].str
    val escalated get() = record.bool("escalated") ?: false
    val createdAt get() = record.date("created_at")
    val detailRoute: Route?
        get() {
            val aid = record.int("approvable_id") ?: 0
            return when (type) {
                "Expense" -> Route.Expense(aid); "PurchaseOrder" -> Route.PurchaseOrder(aid); "InventoryTransfer" -> Route.Transfer(aid)
                "InventoryTransaction", "StockAdjustment" -> Route.Adjustment(aid); "Order" -> Route.Order(aid); else -> null
            }
        }
}

@Composable
fun ApprovalsScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var tab by remember { mutableStateOf(0) }
    val inbox = remember { Resource(scope) { env.client.get("/v1/admin/approvals/inbox").json["data"].toRecords().map(::ApprovalRequest) } }
    val mine = remember { Resource(scope) { env.client.get("/v1/admin/approvals/mine").json["data"].toRecords().map(::ApprovalRequest) } }
    var rejecting by remember { mutableStateOf<ApprovalRequest?>(null) }
    var reason by remember { mutableStateOf("") }
    suspend fun loadAll() { inbox.reload(); mine.reload() }
    LaunchedEffect(Unit) { loadAll() }

    suspend fun decide(r: ApprovalRequest, sign: Boolean) {
        try {
            if (sign) env.client.post("/v1/admin/approvals/${r.id}/sign") else env.client.post("/v1/admin/approvals/${r.id}/reject", jsonOf("reason" to reason))
            rejecting = null
            env.toasts.show(if (sign) "Signed" else "Rejected")
            loadAll()
        } catch (e: ApiError) { env.toasts.error(e) }
    }

    Page("Approvals", subtitle = "Signatures the thresholds ask of you", refresh = { loadAll() }) {
        SingleChoiceSegmentedButtonRow {
            listOf("To sign", "My submissions").forEachIndexed { i, label ->
                SegmentedButton(selected = tab == i, onClick = { tab = i }, shape = SegmentedButtonDefaults.itemShape(i, 2)) { Text(label) }
            }
        }
        val res = if (tab == 0) inbox else mine
        LoadableView(res.state, retry = { res.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState(if (tab == 0) "Nothing waiting for your signature" else "You have no open submissions", icon = Icons.Default.Verified) }) { items ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                items.forEach { r ->
                    SectionCard {
                        Row(verticalAlignment = Alignment.Top) {
                            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                                    Text(r.event.humanized, style = MaterialTheme.typography.titleMedium)
                                    StatusBadge(r.status)
                                    if (r.escalated) StatusBadge("escalated", StatusTone.WARNING)
                                }
                                r.maker?.let { Text("Raised by $it", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary) }
                                r.counterparty?.let { Text(it, style = MaterialTheme.typography.bodySmall) }
                                r.awaiting?.let { Text("Awaiting: ${it.humanized}", style = MaterialTheme.typography.labelSmall, color = Palette.amber) }
                                DateText(r.createdAt, DateStyle.RELATIVE)
                            }
                            Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                if (r.valueUnknown) Text("Value unknown", style = MaterialTheme.typography.labelSmall, color = Palette.warning)
                                MoneyText(r.amount, emphasis = true)
                                val k = r.amountKes
                                if (k != null && r.amount?.currency != "KES") Text(k.formatted(), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                            }
                        }
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                            r.detailRoute?.let { route -> TextButton(onClick = { env.navigator.go(route) }) { Text("Open") } }
                            Spacer(Modifier.weight(1f))
                            if (tab == 0) {
                                OutlinedButton(onClick = { reason = ""; rejecting = r }) { Text("Reject") }
                                ConfirmButton("Sign", "Your signature approves this ${r.event.humanized.lowercase()} at the current band.", action = { decide(r, true) }) { Text("Sign") }
                            } else if (r.status == "rejected") {
                                Button(onClick = { scope.launch { try { env.client.post("/v1/admin/approvals/${r.id}/resubmit"); env.toasts.show("Resubmitted"); loadAll() } catch (e: ApiError) { env.toasts.error(e) } } }) { Text("Resubmit") }
                            }
                        }
                    }
                }
            }
        }
    }

    rejecting?.let { r ->
        AlertDialog(
            onDismissRequest = { rejecting = null },
            title = { Text("Reject ${r.event.humanized}") },
            text = { OutlinedTextField(value = reason, onValueChange = { reason = it }, label = { Text("Reason (required)") }, minLines = 3) },
            confirmButton = { TextButton(enabled = reason.isNotBlank(), onClick = { scope.launch { decide(r, false) } }) { Text("Reject", color = Palette.danger) } },
            dismissButton = { TextButton(onClick = { rejecting = null }) { Text("Cancel") } },
        )
    }
}
