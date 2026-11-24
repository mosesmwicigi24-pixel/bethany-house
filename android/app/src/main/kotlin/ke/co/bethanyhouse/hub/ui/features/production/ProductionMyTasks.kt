package ke.co.bethanyhouse.hub.ui.features.production

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.clickable
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.ContentCut
import androidx.compose.material.icons.filled.Straighten
import androidx.compose.material.icons.filled.Build
import androidx.compose.material.icons.filled.VerifiedUser
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.LockOpen
import androidx.compose.material.icons.filled.Note
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.AppFlavor
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.OfflineStore
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.date
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.DetailRow
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.formatDate
import ke.co.bethanyhouse.hub.ui.components.InfoSheet
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.PriorityBadge
import ke.co.bethanyhouse.hub.ui.components.RemoteImage
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.TextPromptSheet
import ke.co.bethanyhouse.hub.ui.components.stringPairs
import ke.co.bethanyhouse.hub.ui.hub
import ke.co.bethanyhouse.hub.ui.features.people.ChatChannel
import ke.co.bethanyhouse.hub.ui.features.people.ChatMessage
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull

/**
 * My Tasks (/production/my-tasks): the floor worker's workspace, ported from
 * TailorWorkspacePage. GET /v1/tailor/tasks?include_order_context=true (open
 * work plus finished stages on orders still moving) or ?include_completed=true
 * (history). Start / pause / done go to PUT …/status, piece counts to POST
 * …/progress (per batch on a batched order), notes to POST …/note, and a
 * manager may unlock a stage.
 *
 * Offline: a status or progress write that fails with `ApiError.isOffline` is
 * queued in `OfflineStore` (dedupe keys `task-<id>-status` and
 * `task-<id>-progress-<stage>`), the queued value stays on screen, and
 * `AppEnvironment.replayPending` sends it when the connection returns.
 */
@Composable
internal fun MyTasksScreen(initialOrderId: Int? = null, onClose: (() -> Unit)? = null) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val haptics = LocalHapticFeedback.current
    var history by remember { mutableStateOf(false) }
    var selectedOrder by remember(initialOrderId) { mutableStateOf(initialOrderId) }
    BackHandler(enabled = selectedOrder != null) { if (initialOrderId != null && onClose != null) onClose() else selectedOrder = null }
    var pendingKeys by remember { mutableStateOf<Set<String>>(emptySet()) }
    var noteFor by remember { mutableStateOf<Record?>(null) }
    var completed by remember { mutableStateOf<Record?>(null) }
    val online by env.connection.isOnline.collectAsState()
    val queued = env.offline?.pendingCount?.collectAsState()?.value ?: env.pendingCount

    val tasks = remember(history) {
        val c = env.client; val h = history; val offline = env.offline
        val key = if (h) "tailor-tasks-history" else "tailor-tasks-active"
        Resource(scope) {
            try {
                val j = c.get("/v1/tailor/tasks", if (h) mapOf("include_completed" to "true") else mapOf("include_order_context" to "true")).json ?: JsonArray(emptyList())
                offline?.put(j, key)
                (j.records.ifEmpty { j["data"].records }).map(::Record)
            } catch (e: ApiError) {
                if (e.isOffline) {
                    val cached = offline?.get(key)
                    if (cached != null) return@Resource cached.value.records.map(::Record)
                }
                throw e
            }
        }
    }
    suspend fun refreshPending() { pendingKeys = (env.offline?.pending() ?: emptyList()).mapNotNull { it.dedupeKey }.toSet() }
    LaunchedEffect(tasks) { tasks.reload(); refreshPending() }
    var wasOnline by remember { mutableStateOf(online) }
    LaunchedEffect(online) {
        if (online && !wasOnline) { env.replayPending(); refreshPending(); tasks.load() }
        wasOnline = online
    }

    suspend fun enqueue(kind: String, method: String, path: String, body: JsonElement, key: String) {
        val offline = env.offline
        if (offline == null) { env.toasts.show("Offline and nothing could be saved on this device.", StatusTone.DANGER); return }
        if (!offline.enqueue(OfflineStore.PendingOperation(kind = kind, method = method, path = path, body = body, dedupeKey = key))) {
            env.toasts.show("Offline, and this change could not be saved on this device. Try again when you are back online.", StatusTone.DANGER); return
        }
        pendingKeys = pendingKeys + key
        env.toasts.show("You're offline — saved on this device and sent when the connection returns.", StatusTone.WARNING)
    }

    suspend fun act(t: Record, action: String) {
        val path = "/v1/tailor/tasks/${t.id}/status"
        val body = jsonOf("action" to action)
        try {
            env.client.put(path, body)
            if (action == "complete") completed = t else env.toasts.show(if (action == "pause") "Stage paused" else "Stage started")
            haptics.performHapticFeedback(if (action == "complete") HapticFeedbackType.LongPress else HapticFeedbackType.TextHandleMove)
            tasks.load()
        } catch (e: ApiError) {
            if (e.isOffline) enqueue("task_status", "PUT", path, body, "task-${t.id}-status") else env.toasts.error(e)
        }
    }

    suspend fun count(t: Record, qty: Int, batch: Int?) {
        val path = "/v1/tailor/tasks/${t.id}/progress"
        val body = jsonOf("quantity_done" to qty, "batch_id" to batch)
        val stage = t["stage"]["id"].int ?: t.int("production_stage_id") ?: 0
        val key = "task-${t.id}-progress-$stage" + (batch?.let { "-batch-$it" } ?: "")
        try {
            env.client.post(path, body)
            haptics.performHapticFeedback(HapticFeedbackType.TextHandleMove)
            tasks.load()
        } catch (e: ApiError) {
            if (e.isOffline) enqueue("task_progress", "POST", path, body, key) else { env.toasts.error(e); tasks.load() }
        }
    }

    val tailors = env.flavor == AppFlavor.TAILORS
    Page(if (tailors && selectedOrder == null) "Tailors Hub" else "My Tasks", subtitle = if (tailors) null else env.session.current.me?.let { "Your stages, ${it.firstName}" }, refresh = { tasks.reload() }) {
        if (tailors && selectedOrder == null) {
            Text(env.session.current.me?.name?.takeIf { it.isNotBlank() }?.let { "Hello, $it" } ?: "Hello", style = MaterialTheme.typography.headlineSmall)
            Text("Your workshop, at a glance.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
            Text("Workshop", style = MaterialTheme.typography.titleSmall, color = Palette.brand)
        }
        if (!tailors || selectedOrder == null) {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                SegmentedTabs(listOf(false to "Active", true to "History"), history) { history = it; selectedOrder = null }
                Spacer(Modifier.weight(1f))
                if (queued > 0 || pendingKeys.isNotEmpty()) Text("${maxOf(queued, pendingKeys.size)} waiting to send", style = MaterialTheme.typography.labelSmall, color = Palette.amber)
            }
        } else TextButton(onClick = { if (initialOrderId != null && onClose != null) onClose() else selectedOrder = null }) { Text(if (initialOrderId != null) "‹  Calendar" else "‹  Task list") }
        LoadableView(tasks.state, retry = { tasks.load() }, isEmpty = { it.isEmpty() }, empty = {
            EmptyState(if (history) "No finished stages yet" else "Nothing on your bench", if (history) null else "When a stage is assigned to you it shows here.", Icons.Default.ContentCut)
        }) { rows ->
            val groups = taskGroups(rows)
            Column(verticalArrangement = Arrangement.spacedBy(Space.m)) {
                if (tailors && selectedOrder == null) {
                    listOf("In progress", "Ready to start", "Waiting", "With Quality Control", "Finished stages").forEach { lane ->
                        val matching = groups.filter { taskLane(it, history) == lane }
                        if (matching.isNotEmpty()) {
                            Text(lane, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.hub.textSecondary)
                            matching.forEach { group ->
                                SectionCard {
                                    Column(Modifier.fillMaxWidth().clickable { selectedOrder = group.id }.padding(vertical = Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                        Text(taskName(group.order), style = MaterialTheme.typography.titleMedium)
                                        Text(taskSummary(group, history), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                                    }
                                }
                            }
                        }
                    }
                } else {
                    val visible = if (tailors) groups.filter { it.id == selectedOrder } else groups
                    if (tailors && visible.isEmpty()) Text("This job is no longer on your task list.", style = MaterialTheme.typography.bodyMedium)
                    visible.forEach { g ->
                        SectionCard {
                            TaskOrderHeader(g.order)
                            g.tasks.forEach { t ->
                                TaskCard(task = t, order = g.order,
                                    pending = pendingKeys.contains("task-${t.id}-status") || pendingKeys.any { it.startsWith("task-${t.id}-progress") },
                                    onAction = { a -> act(t, a) }, onCount = { qty, batch -> count(t, qty, batch) }, onNote = { noteFor = t })
                            }
                        }
                        if (tailors && g.tasks.isNotEmpty()) StageJourney(g.tasks)
                        if (tailors && g.id > 0) JobConversation(g.id, onBeforeOpen = if (initialOrderId != null) onClose else null)
                    }
                }
            }
        }
    }

    noteFor?.let { t ->
        TextPromptSheet("Note on ${t["stage"]["name"].str ?: "stage"}", onDismiss = { noteFor = null }, placeholder = "What the next bench should know", confirmLabel = "Post note") { text ->
            env.client.post("/v1/tailor/tasks/${t.id}/note", jsonOf("note" to text))
            env.toasts.show("Note posted")
        }
    }
    completed?.let { t ->
        InfoSheet("${t["stage"]["name"].str ?: "Stage"} done", onDismiss = { completed = null }) {
            Column(Modifier.fillMaxWidth().padding(vertical = Space.l), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(Space.m)) {
                Icon(Icons.Default.CheckCircle, contentDescription = null, tint = Palette.success, modifier = Modifier.size(48.dp))
                Text(t["production_order"]["order_number"].str ?: "", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                Button(onClick = { completed = null }) { Text("Back to my bench") }
            }
        }
    }
}

// ── Grouping (one card per order, stages in sequence) ───────────────────────

internal class TaskOrderGroup(val id: Int, val order: JsonElement?, val tasks: List<Record>)

internal fun taskGroups(rows: List<Record>): List<TaskOrderGroup> {
    val order = mutableListOf<Int>()
    val byOrder = mutableMapOf<Int, MutableList<Record>>()
    for (t in rows) {
        val oid = t["production_order"]["id"].int ?: t.int("production_order_id") ?: 0
        if (byOrder[oid] == null) order += oid
        byOrder.getOrPut(oid) { mutableListOf() } += t
    }
    return order.map { oid ->
        val ts = byOrder[oid]!!.sortedBy { it["sequence"].int ?: 0 }
        TaskOrderGroup(oid, ts.firstOrNull()?.get("production_order"), ts)
    }
}

@Composable
private fun StageJourney(tasks: List<Record>) {
    SectionCard("Stage journey") {
        Text("Your assigned stages, in order", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
        Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), verticalAlignment = Alignment.Top) {
            tasks.forEachIndexed { index, task ->
                val name = task["stage"]["name"].str ?: "Stage"
                val status = when (task.status) { "completed" -> "Done"; "pending" -> "Not done"; "in_progress" -> "In progress"; "paused" -> "Paused"; else -> task.status?.humanized ?: "Not done" }
                val tint = when (task.status) { "completed" -> Palette.success; "in_progress" -> Palette.brand; else -> MaterialTheme.hub.textTertiary }
                val icon = when {
                    name.contains("cut", true) -> Icons.Default.ContentCut
                    name.contains("quality", true) || name.contains("inspection", true) -> Icons.Default.VerifiedUser
                    else -> Icons.Default.Build
                }
                Column(Modifier.width(96.dp).semantics(mergeDescendants = true) { contentDescription = "$name, $status" }, horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Spacer(Modifier.weight(1f).height(2.dp).background(if (index == 0) androidx.compose.ui.graphics.Color.Transparent else MaterialTheme.hub.line))
                        Box(Modifier.size(44.dp).border(1.5.dp, tint, CircleShape).background(MaterialTheme.hub.canvas, CircleShape), contentAlignment = Alignment.Center) {
                            Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(22.dp))
                        }
                        Spacer(Modifier.weight(1f).height(2.dp).background(if (index == tasks.lastIndex) androidx.compose.ui.graphics.Color.Transparent else MaterialTheme.hub.line))
                    }
                    Text(name, style = MaterialTheme.typography.labelMedium, textAlign = TextAlign.Center)
                    Text(status, style = MaterialTheme.typography.labelSmall, color = tint, textAlign = TextAlign.Center)
                }
            }
        }
    }
}

@Composable
private fun JobConversation(orderId: Int, onBeforeOpen: (() -> Unit)? = null) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val conversation = remember(orderId) {
        Resource(scope) {
            val channels = env.client.get("/v1/admin/channels").json
            val channel = channels["channels"].records.map(::ChatChannel)
                .firstOrNull { it.contextType == "production_order" && it.contextId == orderId }
            val messages = if (channel == null) emptyList() else
                env.client.get("/v1/admin/channels/${channel.id}/messages", mapOf("per_page" to "3")).json["messages"].records.map(::ChatMessage)
            channel to messages
        }
    }
    LaunchedEffect(orderId) { conversation.load() }
    SectionCard("Job conversation") {
        LoadableView(conversation.state, retry = { conversation.load() }) { (channel, messages) ->
            when {
                channel == null -> Text("No conversation is available for this job in Messages.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                messages.isEmpty() -> Text("No messages yet.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                else -> messages.forEach { message ->
                    Column(Modifier.fillMaxWidth().padding(vertical = Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                            Text(message.userName, style = MaterialTheme.typography.labelMedium)
                            Spacer(Modifier.weight(1f))
                            message.createdAt?.let { DateText(it, DateStyle.RELATIVE) }
                        }
                        Text(message.parsed.text.ifBlank { "Attachment" }, style = MaterialTheme.typography.bodySmall, maxLines = 3)
                    }
                }
            }
            if (channel != null) TextButton(onClick = { onBeforeOpen?.invoke(); env.navigator.push(Route.Comms(channel.id)) }) { Text("Open conversation") }
        }
    }
}

private fun taskName(order: JsonElement?): String = order["product"]["translations"][0]["name"].str ?: order["product_name"].str ?: "Garment"

private fun openTaskStages(group: TaskOrderGroup, history: Boolean): List<Record> =
    if (history || !ProductionStatus.acceptsFloorWork(group.order["status"].str)) emptyList()
    else group.tasks.filter { it.status in listOf("pending", "in_progress", "paused") }

internal fun taskLane(group: TaskOrderGroup, history: Boolean): String {
    val status = group.order["status"].str
    val open = openTaskStages(group, history)
    return when {
        history -> "Finished stages"
        status == "qc_pending" || status == "qc_passed" -> "With Quality Control"
        open.any { it.status == "in_progress" || it.status == "paused" } -> "In progress"
        open.any { it["blocked_by_stage"].str == null } -> "Ready to start"
        else -> "Waiting"
    }
}

internal fun taskSummary(group: TaskOrderGroup, history: Boolean): String {
    if (!history) when (group.order["status"].str) {
        "qc_pending" -> return "Awaiting inspection · No action needed"
        "qc_passed" -> return "Inspection passed · No action needed"
        "qc_failed" -> return "Inspection needs follow-up from a manager"
    }
    val open = openTaskStages(group, history)
    val stage = open.firstOrNull { it.status == "in_progress" || it.status == "paused" } ?: open.firstOrNull()
    if (stage != null) {
        stage["blocked_by_stage"].str?.let { return "Waiting on $it" }
        val label = when (stage.status) { "in_progress" -> "In progress"; "paused" -> "Paused"; else -> "Ready to start" }
        return "${stage["stage"]["name"].str ?: "Stage"} · $label"
    }
    return if (history) "${group.tasks.size} ${if (group.tasks.size == 1) "stage" else "stages"}" else "Your stages are finished"
}

@Composable
private fun TaskOrderHeader(o: JsonElement?) {
    val tailors = LocalEnv.current.flavor == AppFlavor.TAILORS
    var specsOpen by remember { mutableStateOf(tailors) }
    val name = o["product"]["translations"][0]["name"].str ?: o["product_name"].str ?: "Garment"
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Text(o["order_number"].str ?: "PRD", style = MaterialTheme.typography.titleMedium)
            PriorityBadge(o["priority"].str)
            StatusBadge(o["status"].str)
            Spacer(Modifier.weight(1f))
            o["progress"]["percent"].int?.let { Text("$it%", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
            o["product"]["images"][0]["image_url"].str?.let { RemoteImage(it, 44.dp) }
            Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text("${o["quantity"].int ?: 0} × $name", style = MaterialTheme.typography.bodyMedium)
                Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    o["customer_label"].str?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                    DateText(o["due_date"].date, DateStyle.DATE)
                }
            }
        }
        // Measurements in the clergy sheet's order (MeasurementSheet), not alphabetical.
        val measured = MeasurementSheet.of(o["measurements"])
        val specs = o["specifications"].stringPairs + measured.body
        if (specs.isNotEmpty() || measured.gender != null) {
            TextButton(onClick = { specsOpen = !specsOpen }) { Text(if (specsOpen) "Hide specs & measurements" else "Specs & measurements") }
            if (specsOpen) {
                measured.gender?.let { DetailRow("For", it.humanized) }
                if (tailors) {
                    o["specifications"].stringPairs.forEach { (key, value) -> DetailRow(key.humanized, value) }
                    measured.body.chunked(2).forEach { row ->
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                            row.forEachIndexed { index, (key, value) ->
                                Column(Modifier.weight(1f).background(MaterialTheme.hub.canvas, RoundedCornerShape(10.dp)).padding(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                                        Icon(Icons.Default.Straighten, contentDescription = null, tint = if (index == 0) Palette.brand else Palette.info, modifier = Modifier.size(16.dp))
                                        Text(key.humanized, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                                    }
                                    Text(value, style = MaterialTheme.typography.titleMedium)
                                }
                            }
                            if (row.size == 1) Spacer(Modifier.weight(1f))
                        }
                    }
                } else specs.forEach { (k, v) -> DetailRow(k.humanized, v) }
            }
        }
        // Notes keep their line breaks: a QC note and a rework reason are separate lines.
        o["notes"].str?.takeIf { it.isNotEmpty() }?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
    }
}

// ── One stage on the bench ───────────────────────────────────────────────────

@Composable
private fun TaskCard(task: Record, order: JsonElement?, pending: Boolean, onAction: suspend (String) -> Unit, onCount: suspend (Int, Int?) -> Unit, onNote: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    val status = task.status ?: "pending"
    val blocked = task["blocked_by_stage"].str
    val orderOpen = ProductionStatus.acceptsFloorWork(order["status"].str)
    val quantity = maxOf(1, order["quantity"].int ?: 1)
    val stageName = task["stage"]["name"].str ?: "Stage"
    fun run(op: suspend () -> Unit) { busy = true; scope.launch { try { op() } finally { busy = false } } }

    Column(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalArrangement = Arrangement.spacedBy(Space.s)) {
        FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            if (env.flavor == AppFlavor.TAILORS) {
                val symbol = when {
                    stageName.contains("cut", ignoreCase = true) -> Icons.Default.ContentCut
                    stageName.contains("quality", ignoreCase = true) || stageName.contains("inspection", ignoreCase = true) -> Icons.Default.VerifiedUser
                    else -> Icons.Default.Build
                }
                Icon(symbol, contentDescription = null, tint = if (status == "completed") Palette.success else if (status == "in_progress") Palette.brand else MaterialTheme.hub.textTertiary, modifier = Modifier.size(20.dp))
            }
            Text(stageName, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
            StatusBadge(status)
            if (pending) StatusBadge("waiting to send", StatusTone.AMBER)
            if (task["concurrent_allowed"].bool == true) StatusBadge("unlocked", StatusTone.ACCENT)
            task["estimated_hours"].str?.takeIf { it != "0" }?.let { Text("$it h", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
        }
        task["stage"]["description"].str?.takeIf { it.isNotEmpty() }?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
        if (env.flavor == AppFlavor.TAILORS) {
            val timestamp = when (status) {
                "completed" -> task["completed_at"].date
                "in_progress", "paused" -> task["started_at"].date
                else -> null
            }
            timestamp?.let { Text("${if (status == "completed") "Done" else "Started"} · ${formatDate(it, DateStyle.DATE_TIME)}", style = MaterialTheme.typography.labelSmall, color = Palette.brand) }
        }
        if (blocked != null && task["started_at"].isNull) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Default.Lock, contentDescription = null, tint = Palette.amber, modifier = Modifier.size(14.dp))
                Spacer(Modifier.width(4.dp))
                Text("Waiting on $blocked", style = MaterialTheme.typography.labelSmall, color = Palette.amber)
            }
        }
        if (status != "completed" && orderOpen) {
            val batches = order["batches"].records
            if (batches.isEmpty()) {
                PieceCounter("Pieces through me", task["quantity_done"].int ?: 0, quantity, enabled = !busy) { v -> run { onCount(v, null) } }
            } else batches.forEach { b ->
                val bid = b["id"].int ?: 0
                val done = task["batch_progress"].records.firstOrNull { it["production_order_batch_id"].int == bid }["quantity_done"].int ?: 0
                PieceCounter(b["label"].str ?: "Batch", done, b["quantity"].int ?: quantity, enabled = !busy) { v -> run { onCount(v, bid) } }
            }
        }
        if (status == "completed") Text("Done — ${task["quantity_done"].int ?: 0} pieces", style = MaterialTheme.typography.labelSmall, color = Palette.success)
        if (orderOpen) {
            FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s), modifier = Modifier.fillMaxWidth()) {
                if (status == "pending" || status == "paused") Button(enabled = !busy, onClick = { run { onAction("start") } }) { Text("Start") }
                if (status == "in_progress") OutlinedButton(enabled = !busy, onClick = { run { onAction("pause") } }) { Text("Pause") }
                if (status == "in_progress" || status == "paused") ConfirmButton("Mark $stageName done", "Tells the next bench this stage is finished for the whole order.", enabled = !busy, action = { onAction("complete") }) { Text("Done") }
                IconButton(onClick = onNote, enabled = !busy) { Icon(Icons.Default.Note, contentDescription = "Add note") }
                if (env.session.current.can("production.manage_assignees") && task["started_at"].isNull && task["concurrent_allowed"].bool != true) {
                    IconButton(enabled = !busy, onClick = {
                        scope.launch { try { env.client.post("/v1/tailor/tasks/${task.id}/unlock", jsonOf("allow" to true)); env.toasts.show("Stage unlocked") } catch (e: ApiError) { env.toasts.error(e) } }
                    }) { Icon(Icons.Default.LockOpen, contentDescription = "Unlock stage") }
                }
            }
        } else order["status"].str?.let { s ->
            if (s == "qc_pending" || s == "qc_failed") Text(if (s == "qc_pending") "With Quality Control — counts are locked." else "Failed QC — a manager decides what happens next.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
        }
    }
}

/** A big-button piece counter: the absolute cumulative count is sent, and the server validates it against the pipeline. */
@Composable
private fun PieceCounter(label: String, value: Int, max: Int, enabled: Boolean, onChange: (Int) -> Unit) {
    var shown by remember { mutableStateOf<Int?>(null) }
    LaunchedEffect(value) { shown = null }
    val current = shown ?: value
    fun set(v: Int) { val c = v.coerceIn(0, max); shown = c; onChange(c) }
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(label, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            Text("$current / $max", style = MaterialTheme.typography.titleLarge)
        }
        OutlinedButton(onClick = { set(current - 1) }, enabled = enabled && current > 0, contentPadding = ButtonDefaults.TextButtonContentPadding) { Text("−") }
        OutlinedButton(onClick = { set(current + 1) }, enabled = enabled && current < max, contentPadding = ButtonDefaults.TextButtonContentPadding) { Text("+") }
        OutlinedButton(onClick = { set(max) }, enabled = enabled && current < max, contentPadding = ButtonDefaults.TextButtonContentPadding) { Text("All") }
    }
}
