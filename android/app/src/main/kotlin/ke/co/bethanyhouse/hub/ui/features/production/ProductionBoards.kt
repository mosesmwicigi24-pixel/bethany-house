package ke.co.bethanyhouse.hub.ui.features.production

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.rememberScrollState
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.KeyboardArrowLeft
import androidx.compose.material.icons.filled.KeyboardArrowRight
import androidx.compose.material.icons.filled.ListAlt
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.VerifiedUser
import androidx.compose.material.icons.filled.ViewColumn
import androidx.compose.material.icons.filled.ContentCut
import androidx.compose.material.icons.filled.Event
import androidx.compose.material3.Button
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
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
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.AppFlavor
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.HubDates
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.date
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.money
import ke.co.bethanyhouse.hub.core.obj
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.DecimalField
import ke.co.bethanyhouse.hub.ui.components.DetailRow
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.Lookup
import ke.co.bethanyhouse.hub.ui.components.LookupPicker
import ke.co.bethanyhouse.hub.ui.components.ModuleRowCard
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.PriorityBadge
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.StatsRow
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.canViewCost
import ke.co.bethanyhouse.hub.ui.components.personName
import ke.co.bethanyhouse.hub.ui.components.quantityText
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.util.Locale

// ── WIP board (GET /v1/admin/production-orders?status=<board statuses>) ──────

/**
 * Orders moving through the pipeline, one column per status (the console's
 * kanban): pending → in progress → on hold → QC pending → passed → failed.
 * Columns side by side on a wide screen; stacked sections on a phone.
 */
@Composable
internal fun WipBoardScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val compact = LocalConfiguration.current.screenWidthDp < 700
    val list = remember { ProductionRecordList(scope, env.client, "/v1/admin/production-orders", mutableMapOf("status" to ProductionStatus.board.joinToString(",")), perPage = 100) }
    val people = remember { Lookup.assignable(env.client) }
    var personId by remember { mutableStateOf<Int?>(null) }
    var personTasks by remember { mutableStateOf<List<Record>>(emptyList()) }
    LaunchedEffect(Unit) { list.reload() }
    LaunchedEffect(personId) {
        val id = personId
        personTasks = if (id == null) emptyList() else runCatching { env.client.get("/v1/admin/production-tasks", mapOf("tailor_id" to id.toString(), "per_page" to "200")).json["data"].toRecords() }.getOrDefault(emptyList())
    }

    fun filtered(rows: List<Record>): List<Record> {
        if (personId == null) return rows
        val ids = personTasks.mapNotNull { it["production_order"]["id"].int ?: it.int("production_order_id") }.toSet()
        return rows.filter { ids.contains(it.int("id") ?: -1) }
    }

    Page("Work In Progress", subtitle = "Live view of orders moving through the floor", refresh = { list.reload() }, actions = {
        IconButton(onClick = { list.load() }) { Icon(Icons.Default.Refresh, contentDescription = "Refresh") }
    }) {
        if (env.session.current.can("production.manage_assignees")) LookupPicker("Everyone", people, personId, { personId = it })
        LoadableView(list.state, retry = { list.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("Nothing in progress", icon = Icons.Default.ViewColumn) }) { rows ->
            val visible = filtered(rows)
            if (compact) Column(verticalArrangement = Arrangement.spacedBy(Space.m)) {
                ProductionStatus.board.forEach { s -> WipColumn(s, visible.filter { it.status == s }, Modifier.fillMaxWidth()) }
            } else Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(Space.m), verticalAlignment = Alignment.Top) {
                ProductionStatus.board.forEach { s -> WipColumn(s, visible.filter { it.status == s }, Modifier.width(280.dp)) }
            }
        }
    }
}

@Composable
private fun WipColumn(status: String, orders: List<Record>, modifier: Modifier) {
    val env = LocalEnv.current
    Column(modifier.background(MaterialTheme.hub.canvas, MaterialTheme.shapes.small).padding(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) { StatusBadge(status); Text("${orders.size}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
        if (orders.isEmpty()) Text("—", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary, modifier = Modifier.padding(vertical = 8.dp))
        orders.forEach { r ->
            val o = ProductionOrderRecord(r)
            ModuleRowCard(onClick = { env.navigator.push(Route.ProductionOrder(o.id)) }) {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(o.number, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                    PriorityBadge(o.priority)
                    if (o.isOverdue) StatusBadge("overdue", StatusTone.DANGER)
                }
                Text("${o.quantity} × ${o.productName}", style = MaterialTheme.typography.bodySmall, maxLines = 2)
                o.customerLabel?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                Row(Modifier.fillMaxWidth()) { DateText(o.dueDate, DateStyle.DATE); Spacer(Modifier.weight(1f)); Text("${o.percent}%", style = MaterialTheme.typography.labelSmall) }
                val names = o.tasks.mapNotNull { personName(it["assigned_to"]) }.toSet().sorted()
                if (names.isNotEmpty()) Text(names.joinToString(", "), style = MaterialTheme.typography.labelSmall, color = Palette.brandDeep, maxLines = 1)
            }
        }
    }
}

// ── QC (GET /v1/admin/production-orders?status=qc_pending|qc_passed|qc_failed) ─

@Composable
internal fun QcScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var tab by remember { mutableStateOf("qc_pending") }
    val list = remember { ProductionRecordList(scope, env.client, "/v1/admin/production-orders", mutableMapOf("status" to "qc_pending"), perPage = 100) }
    var inspecting by remember { mutableStateOf<Record?>(null) }
    var reworking by remember { mutableStateOf<Record?>(null) }
    var reworkReason by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(tab) { list.query["status"] = tab; list.reload() }

    Page("Quality Control", subtitle = "Inspect finished work before it is stocked", refresh = { list.reload() }) {
        val stats = list.extras["stats"]
        StatsRow(listOf(
            Triple("Awaiting", stats["qc_pending"].str ?: "0", if ((stats["qc_pending"].int ?: 0) > 0) StatusTone.AMBER else null),
            Triple("Passed", stats["qc_passed"].str ?: "0", StatusTone.SUCCESS),
            Triple("Failed", stats["qc_failed"].str ?: "0", if ((stats["qc_failed"].int ?: 0) > 0) StatusTone.DANGER else null),
        ))
        SegmentedTabs(listOf("qc_pending" to "Awaiting", "qc_passed" to "Passed", "qc_failed" to "Failed", "qc_pending,qc_passed,qc_failed,completed" to "All"), tab) { tab = it }
        ProductionListView(list, "Nothing here", Icons.Default.VerifiedUser) { r ->
            val o = ProductionOrderRecord(r)
            SectionCard {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(o.number, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold); StatusBadge(o.status); PriorityBadge(o.priority)
                }
                Text("${o.quantity} × ${o.productName}", style = MaterialTheme.typography.bodySmall)
                o.customerLabel?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                DateText(o.dueDate, DateStyle.DATE)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                    TextButton(onClick = { env.navigator.push(Route.ProductionOrder(o.id)) }) { Text("Open") }
                    if (o.status == "qc_pending" && env.session.current.can("production.submit_qc")) Button(onClick = { inspecting = r }) { Text("Inspect") }
                    if (o.status == "qc_failed" && env.session.current.can("production.submit_qc")) OutlinedButton(onClick = { reworking = r }) { Text("Send back for rework") }
                    if (o.status == "qc_passed" && env.session.current.can("production.approve_qc")) OutlinedButton(onClick = { env.navigator.push(Route.ProductionOrder(o.id)) }) { Text("Complete & stock…") }
                }
            }
        }
    }
    // A failed inspection leads straight to "Send back for rework", with the
    // inspector's note as the reason (console Production Cycle 10).
    inspecting?.let { r ->
        QcSheet(ProductionOrderRecord(r), onDismiss = { inspecting = null; if (reworkReason != null) reworking = r }) { passed, notes ->
            reworkReason = if (passed) null else notes
            list.load()
        }
    }
    reworking?.let { r -> ReworkSheet(ProductionOrderRecord(r), onDismiss = { reworking = null; reworkReason = null }, onSaved = { list.load() }, initialReason = reworkReason) }
}

// ── Calendar (GET /v1/admin/production/schedule + orders by due date) ────────

@Composable
internal fun ProductionCalendarScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val schedule = remember { Resource(scope) { env.client.get("/v1/admin/production/schedule").json ?: JsonNull } }
    var month by remember { mutableStateOf(HubDates.today().withDayOfMonth(1)) }
    var assignedOrder by remember { mutableStateOf<Int?>(null) }
    var openingOrder by remember { mutableStateOf(false) }
    var showAllOverdue by remember { mutableStateOf(false) }
    val tailors = env.flavor == AppFlavor.TAILORS
    LaunchedEffect(Unit) { schedule.load() }

    Page("Production Calendar", subtitle = if (env.session.current.permissions.isFloorWorker) "Your jobs by due date" else "Delivery promises against the floor's load", refresh = { schedule.reload() }, showTitle = !tailors) {
        LoadableView(schedule.state, retry = { schedule.load() }) { s ->
            // A floor worker's schedule holds only her own jobs (server, Production
            // Cycle 8), so "earliest free slot" — a date-promising figure for
            // whoever runs the floor — would mean nothing to her.
            val floorWorker = env.session.current.permissions.isFloorWorker
            val overdueOrders = s["upcoming_orders"].records.filter { it["due_date"].date?.let { date -> HubDates.dayString(date) < HubDates.dayString(HubDates.today()) } == true }.sortedBy { it["due_date"].date }
            val overdue = overdueOrders.size
            if (tailors) Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                CalendarSummaryTile(if (floorWorker) "Your active orders" else "Active orders", s["active_count"].str ?: "—", false, Modifier.weight(1f))
                CalendarSummaryTile("Past due", overdue.toString(), true, Modifier.weight(1f))
            } else StatsRow(listOfNotNull(
                Triple(if (floorWorker) "Your active orders" else "Active orders", s["active_count"].str ?: "—", null),
                if (tailors) Triple("Past due", overdue.toString(), if (overdue > 0) StatusTone.DANGER else StatusTone.NEUTRAL) else null,
                if (floorWorker) null else Triple("Earliest free slot", s["earliest_free_slot"].str ?: "—", StatusTone.INFO),
            ))
            if (tailors && !floorWorker) s["earliest_free_slot"].str?.let { Text("Earliest free slot: $it", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
            val byStage = s["by_stage"].obj ?: emptyMap()
            if (!tailors && byStage.isNotEmpty()) SectionCard("Open work per stage") {
                byStage.keys.sorted().forEach { k -> DetailRow(s["stage_names"][k].str ?: "Stage $k", byStage[k].str) }
            }
            val openOrder: ((JsonElement) -> Unit)? = if (tailors && env.session.current.can("production.view")) { order ->
                val number = order["order_number"].str
                if (!number.isNullOrBlank() && !openingOrder) {
                    openingOrder = true
                    scope.launch {
                        try {
                            val response = env.client.get("/v1/admin/production-orders", mapOf("search" to number, "per_page" to "100")).json
                            val id = calendarOrderId(response, number)
                            if (id == null) env.toasts.show("Couldn't find this order. Refresh the calendar and try again.", StatusTone.WARNING)
                            else {
                                val assigned = if (env.session.current.can("production.worker")) {
                                    val result = env.client.get("/v1/tailor/tasks", mapOf("include_order_context" to "true")).json
                                    (result.records.ifEmpty { result["data"].records }).any { (it["production_order"]["id"].int ?: it["production_order_id"].int) == id }
                                } else false
                                if (assigned) assignedOrder = id else env.navigator.push(Route.ProductionOrder(id))
                            }
                        } catch (e: Exception) { env.toasts.error(e) } finally { openingOrder = false }
                    }
                }
            } else null
            CalendarMonth(s["upcoming_orders"].records, month, tailors, onPrev = { month = month.minusMonths(1) }, onNext = { month = month.plusMonths(1) }, onToday = { month = HubDates.today().withDayOfMonth(1) }, onSelectMonth = { month = it.withDayOfMonth(1) }, onOpen = openOrder)
            if (tailors && overdueOrders.isNotEmpty()) SectionCard("Past due") {
                Text("These production deadlines have passed.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                (if (showAllOverdue) overdueOrders else overdueOrders.take(3)).forEach { CalendarOrderRow(it, openOrder) }
                if (overdueOrders.size > 3) TextButton(onClick = { showAllOverdue = !showAllOverdue }) { Text(if (showAllOverdue) "Show fewer" else "Show all ${overdueOrders.size} orders") }
            }
            if (tailors && floorWorker) {
                TextButton(onClick = { env.navigator.push(Route.ProductionMyTasks) }) { Text("Open My Tasks") }
            }
        }
    }
    assignedOrder?.let { id ->
        Dialog(onDismissRequest = { assignedOrder = null }, properties = DialogProperties(usePlatformDefaultWidth = false)) {
            Surface(Modifier.fillMaxSize()) { MyTasksScreen(initialOrderId = id, onClose = { assignedOrder = null }) }
        }
    }
}

/** Schedule rows omit IDs; only one exact search match can identify a job. */
internal fun calendarOrderId(response: JsonElement?, number: String): Int? =
    (response.records.ifEmpty { response["data"].records })
        .filter { it["order_number"].str == number }.singleOrNull()?.get("id").int

@Composable
private fun CalendarSummaryTile(label: String, value: String, overdue: Boolean, modifier: Modifier = Modifier) {
    val tint = if (overdue && value != "0") Palette.danger else Palette.brandDeep
    Surface(modifier = modifier, shape = RoundedCornerShape(18.dp), color = MaterialTheme.hub.card) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Icon(if (overdue) Icons.Default.Event else Icons.Default.ContentCut, contentDescription = null, tint = tint, modifier = Modifier.size(28.dp).background(tint.copy(alpha = 0.09f), RoundedCornerShape(8.dp)).padding(6.dp))
            Row(verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(value, style = MaterialTheme.typography.titleLarge)
                Text(label, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            }
        }
    }
}

@Composable
private fun CalendarOrderRow(order: JsonElement, onOpen: ((JsonElement) -> Unit)?) {
    val navigable = onOpen != null && !order["order_number"].str.isNullOrBlank()
    Row(Modifier.fillMaxWidth().background(MaterialTheme.hub.canvas, RoundedCornerShape(10.dp)).then(if (navigable) Modifier.clickable { onOpen?.invoke(order) } else Modifier).padding(10.dp), horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.Top) {
        Icon(Icons.Default.Event, contentDescription = null, tint = Palette.brandDeep, modifier = Modifier.size(28.dp).background(Palette.brandDeep.copy(alpha = 0.08f), RoundedCornerShape(7.dp)).padding(5.dp))
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(order["product_name"].str ?: "Production order", style = MaterialTheme.typography.bodyMedium)
            order["order_number"].str?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
            order["due_date"].date?.let { Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) { Text("Due", style = MaterialTheme.typography.labelSmall); DateText(it, DateStyle.DATE) } }
            order["quantity"].int?.takeIf { it > 0 }?.let { Text("$it ${if (it == 1) "piece" else "pieces"}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
        }
        order["completion_percentage"].int?.let { Text("$it%", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
        if (navigable) Icon(Icons.Default.KeyboardArrowRight, contentDescription = null, tint = MaterialTheme.hub.textSecondary)
    }
}

@Composable
internal fun CalendarMonth(orders: List<JsonElement>, start: LocalDate, tailors: Boolean, onPrev: () -> Unit, onNext: () -> Unit, onToday: () -> Unit, onSelectMonth: (LocalDate) -> Unit, onOpen: ((JsonElement) -> Unit)?) {
    var selected by remember { mutableStateOf<LocalDate?>(null) }
    val byDay = orders.filter { it["due_date"].date != null }.groupBy { HubDates.dayString(it["due_date"].date!!) }
    val title = start.format(DateTimeFormatter.ofPattern("LLLL yyyy", Locale.UK))
    val today = HubDates.today()
    SectionCard {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            IconButton(onClick = { selected = null; onPrev() }) { Icon(Icons.Default.KeyboardArrowLeft, contentDescription = "Previous month") }
            Spacer(Modifier.weight(1f)); Column(horizontalAlignment = Alignment.CenterHorizontally) {
                Text(title, style = MaterialTheme.typography.titleMedium)
                if (tailors) Text("Production deadlines", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            }; Spacer(Modifier.weight(1f))
            IconButton(onClick = { selected = null; onNext() }) { Icon(Icons.Default.KeyboardArrowRight, contentDescription = "Next month") }
        }
        if (tailors) {
            FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                TextButton(onClick = { onToday(); selected = HubDates.today() }) { Text("Today") }
                val next = orders.mapNotNull { it["due_date"].date?.atZone(HubDates.nairobi)?.toLocalDate() }.filter { !it.isBefore(today) }.minOrNull()
                if (next != null) TextButton(onClick = { onSelectMonth(next); selected = next }) { Text("Next deadline") }
                if (selected != null) TextButton(onClick = { selected = null }) { Text("Show month") }
            }
            Row(Modifier.fillMaxWidth()) {
                listOf("M", "T", "W", "T", "F", "S", "S").forEach { day ->
                    androidx.compose.material3.Text(day, modifier = Modifier.weight(1f), textAlign = androidx.compose.ui.text.style.TextAlign.Center, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                }
            }
            val offset = start.dayOfWeek.value - 1
            val cells = offset + start.lengthOfMonth()
            repeat((cells + 6) / 7) { week ->
                Row(Modifier.fillMaxWidth()) {
                    repeat(7) { weekday ->
                        val day = week * 7 + weekday - offset + 1
                        if (day !in 1..start.lengthOfMonth()) {
                            Spacer(Modifier.weight(1f).height(48.dp))
                        } else {
                            val date = start.withDayOfMonth(day)
                            val count = byDay[HubDates.dayString(date)]?.size ?: 0
                            TextButton(onClick = { selected = date }, modifier = Modifier.weight(1f).height(48.dp).semantics { contentDescription = "${date.format(DateTimeFormatter.ofPattern("d MMMM yyyy", Locale.UK))}, $count due orders" }, contentPadding = androidx.compose.foundation.layout.PaddingValues(0.dp)) {
                                Column(Modifier.size(38.dp).background(if (selected == date) Palette.brandDeep else androidx.compose.ui.graphics.Color.Transparent, RoundedCornerShape(11.dp)).border(1.dp, if (date == today && selected != date) Palette.brandDeep.copy(alpha = 0.55f) else androidx.compose.ui.graphics.Color.Transparent, RoundedCornerShape(11.dp)), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
                                    Text(day.toString(), color = if (selected == date) androidx.compose.ui.graphics.Color.White else MaterialTheme.hub.text, style = MaterialTheme.typography.bodySmall)
                                    Text(if (count > 0) "•" else " ", color = if (selected == date) androidx.compose.ui.graphics.Color.White else Palette.brandDeep, style = MaterialTheme.typography.labelSmall)
                                }
                            }
                        }
                    }
                }
            }
            Text("Dot marks a production deadline", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            HorizontalDivider(color = MaterialTheme.hub.line)
            val visibleCount = (1..start.lengthOfMonth()).sumOf { day ->
                val date = start.withDayOfMonth(day)
                if (selected == null || selected == date) byDay[HubDates.dayString(date)]?.size ?: 0 else 0
            }
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text(selected?.format(DateTimeFormatter.ofPattern("d MMM yyyy", Locale.UK)) ?: "Due this month", style = MaterialTheme.typography.titleSmall, modifier = Modifier.weight(1f))
                Text("$visibleCount ${if (visibleCount == 1) "order" else "orders"}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            }
        }
        var any = false
        (1..start.lengthOfMonth()).forEach { d ->
            val date = start.withDayOfMonth(d)
            if (tailors && selected != null && selected != date) return@forEach
            val due = byDay[HubDates.dayString(date)] ?: return@forEach
            if (due.isEmpty()) return@forEach
            any = true
            Column(Modifier.padding(vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                if (!tailors) Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                    DateText(date.atStartOfDay(HubDates.nairobi).toInstant(), DateStyle.DATE)
                    if (date.isBefore(today)) StatusBadge("past due", StatusTone.DANGER)
                }
                due.forEach { o ->
                    if (tailors) {
                        CalendarOrderRow(o, onOpen)
                    } else {
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp), modifier = Modifier.fillMaxWidth().then(if (onOpen != null && !o["order_number"].str.isNullOrBlank()) Modifier.clickable { onOpen(o) } else Modifier)) {
                        Text(o["order_number"].str ?: "", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                        StatusBadge(o["status"].str)
                        PriorityBadge(o["priority"].str)
                        Text("${o["quantity"].int ?: 0} × ${o["product_name"].str ?: ""}", style = MaterialTheme.typography.bodySmall, maxLines = 1)
                        o["customer_name"].str?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                        Text("${o["completion_percentage"].int ?: 0}%", style = MaterialTheme.typography.labelSmall)
                    }
                    }
                }
            }
            HorizontalDivider(color = MaterialTheme.hub.line)
        }
        if (!any) Text(if (tailors && selected != null) "No production deadlines on this day." else if (tailors) "No production deadlines this month." else "No due dates this month.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textTertiary)
    }
}

// ── Bill of Materials (GET /v1/admin/products/{p}/bom → {data, product}) ────

private class BomEdit(val productId: Int, val bom: JsonElement?)

@Composable
internal fun BomScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val products = remember { Lookup.products(env.client, producibleOnly = true) }
    var productId by remember { mutableStateOf<Int?>(null) }
    var version by remember { mutableStateOf(0) }
    var editing by remember { mutableStateOf<BomEdit?>(null) }
    val boms = remember(productId) { productId?.let { id -> Resource(scope) { env.client.get("/v1/admin/products/$id/bom").json ?: JsonNull } } }
    LaunchedEffect(boms, version) { boms?.reload() }
    val canEdit = env.session.current.can("bom.edit")

    suspend fun activate(pid: Int, bid: Int) {
        try { env.client.put("/v1/admin/products/$pid/bom/$bid/activate"); env.toasts.show("BOM activated"); version++ } catch (e: ApiError) { env.toasts.error(e) }
    }
    suspend fun delete(pid: Int, bid: Int) {
        try { env.client.delete("/v1/admin/products/$pid/bom/$bid"); env.toasts.show("BOM deleted"); version++ } catch (e: ApiError) { env.toasts.error(e) }
    }

    Page("Bill of Materials", subtitle = "What each garment is made of", refresh = { boms?.reload() }, actions = {
        val pid = productId
        if (pid != null && canEdit) Button(onClick = { editing = BomEdit(pid, null) }) { Text("New version") }
    }) {
        LookupPicker("Product", products, productId, { productId = it })
        if (boms == null) EmptyState("Pick a producible product", "Its BOM versions are listed here; the active one drives material requirements.", Icons.Default.ListAlt)
        else LoadableView(boms.state, retry = { boms.load() }) { j ->
            val versions = j["data"].records
            if (versions.isEmpty()) EmptyState("No BOM yet for this product", icon = Icons.Default.ListAlt)
            versions.forEach { b ->
                SectionCard {
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp), modifier = Modifier.fillMaxWidth()) {
                        Text("Version ${b["version"].int ?: 0}", style = MaterialTheme.typography.titleMedium)
                        if (b["is_active"].bool == true) StatusBadge("active", StatusTone.SUCCESS)
                        b["variant"]["variant_name"].str?.let { StatusBadge(it, StatusTone.INFO) }
                        if (env.canViewCost) b["total_cost"].money?.let { MoneyText(it, emphasis = true) }
                    }
                    b["notes"].str?.takeIf { it.isNotEmpty() }?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary) }
                    b["items"].records.forEach { it ->
                        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(it["material"]["name"].str ?: "Material ${it["material_id"].int ?: 0}", style = MaterialTheme.typography.bodyMedium)
                                it["material"]["code"].str?.let { c -> Text(c, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
                            }
                            Text("${quantityText(it["quantity"])} ${it["unit_of_measure"].str ?: ""}", style = MaterialTheme.typography.bodySmall)
                            if (env.canViewCost) it["line_cost"].money?.let { lc -> Text(lc.formatted(), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, modifier = Modifier.padding(start = Space.m)) }
                        }
                    }
                    val pid = productId; val bid = b["id"].int
                    if (canEdit && pid != null) FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s), modifier = Modifier.fillMaxWidth()) {
                        if (b["is_active"].bool != true && bid != null) TextButton(onClick = { scope.launch { activate(pid, bid) } }) { Text("Make active") }
                        TextButton(onClick = { editing = BomEdit(pid, b) }) { Text("Edit as new version") }
                        if (bid != null) ConfirmButton("Delete version ${b["version"].int ?: 0}", "Removes this BOM version.", destructive = true, action = { delete(pid, bid) }) { Text("Delete") }
                    }
                }
            }
        }
    }
    editing?.let { e -> ProductionBomEditSheet(e.productId, e.bom, onDismiss = { editing = null }) { version++ } }
}

private class BomLine(val id: Long = System.nanoTime(), val materialId: Int?, val quantity: String, val uom: String, val notes: String) {
    fun copy(materialId: Int? = this.materialId, quantity: String = this.quantity, uom: String = this.uom, notes: String = this.notes) = BomLine(id, materialId, quantity, uom, notes)
}

/** POST /products/{p}/bom (new version) — {product_variant_id?, notes, items: [{material_id, quantity, unit_of_measure, notes}]}. */
@Composable
private fun ProductionBomEditSheet(productId: Int, bom: JsonElement?, onDismiss: () -> Unit, onSaved: () -> Unit) {
    val env = LocalEnv.current
    val materials = remember { Lookup.materials(env.client) }
    var notes by remember { mutableStateOf(bom["notes"].str ?: "") }
    var lines by remember { mutableStateOf(bom["items"].records.map { BomLine(materialId = it["material_id"].int, quantity = it["quantity"].str ?: "", uom = it["unit_of_measure"].str ?: "", notes = it["notes"].str ?: "") }) }
    val valid = lines.isNotEmpty() && lines.all { it.materialId != null && (it.quantity.toDoubleOrNull() ?: 0.0) > 0 && it.uom.isNotEmpty() }
    fun update(l: BomLine) { lines = lines.map { if (it.id == l.id) l else it } }

    FormSheet(if (bom == null) "New BOM version" else "New version from v${bom["version"].int ?: 0}", onDismiss, saveLabel = "Save as new version", canSave = valid, save = {
        val items = lines.map { l -> J.obj("material_id" to jInt(l.materialId), "quantity" to (J.decimal(l.quantity) ?: JsonPrimitive(0)), "unit_of_measure" to JsonPrimitive(l.uom), "notes" to jStr(l.notes)) }
        val body = mutableMapOf<String, JsonElement>("notes" to jStr(notes), "items" to JsonArray(items))
        bom["product_variant_id"].int?.let { body["product_variant_id"] = JsonPrimitive(it) }
        env.client.post("/v1/admin/products/$productId/bom", JsonObject(body))
        env.toasts.show("BOM saved"); onSaved()
    }) {
        lines.forEach { line ->
            Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                LookupPicker("Material", materials, line.materialId, { id ->
                    val uom = if (line.uom.isEmpty()) materials.options.firstOrNull { it.intId == id }?.string("unit_of_measure") ?: "" else line.uom
                    update(line.copy(materialId = id, uom = uom))
                })
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                    DecimalField("Qty", line.quantity, { update(line.copy(quantity = it)) }, Modifier.weight(1f))
                    Field("Unit", line.uom, { update(line.copy(uom = it)) }, Modifier.weight(1f))
                    TextButton(onClick = { lines = lines.filterNot { it.id == line.id } }) { Text("Remove", color = Palette.danger) }
                }
            }
            HorizontalDivider(color = MaterialTheme.hub.line)
        }
        TextButton(onClick = { lines = lines + BomLine(materialId = null, quantity = "", uom = "", notes = "") }) { Text("+ Add material") }
        Field("Notes", notes, { notes = it }, multiline = true)
        Text("Saving creates the next version and makes it active; the previous version is kept.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
    }
}
