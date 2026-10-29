package ke.co.bethanyhouse.hub.ui.features.production

import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
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
import ke.co.bethanyhouse.hub.ui.components.AttachmentPicker
import ke.co.bethanyhouse.hub.ui.components.Avatar
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.DetailRow
import ke.co.bethanyhouse.hub.ui.components.DocumentButton
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.ModuleAuditLog
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.PriorityBadge
import ke.co.bethanyhouse.hub.ui.components.RemoteImage
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.TextPromptSheet
import ke.co.bethanyhouse.hub.ui.components.canViewCost
import ke.co.bethanyhouse.hub.ui.components.personName
import ke.co.bethanyhouse.hub.ui.components.quantityText
import ke.co.bethanyhouse.hub.ui.components.stringPairs
import ke.co.bethanyhouse.hub.ui.components.uploadFile
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull

/**
 * One production order (GET /v1/admin/production-orders/{id} → {order}).
 * Every action is shown only when the order's status allows it and the person
 * holds the permission the route checks.
 */
private enum class OrderSheet { EDIT, ASSIGN, MATERIALS, QC, REWORK, COMPLETE, BATCHES, CANCEL }

@Composable
internal fun ProductionOrderDetailScreen(orderId: Int) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val res = remember(orderId) { Resource(scope) { val j = env.client.get("/v1/admin/production-orders/$orderId").json; Record(j["order"] ?: j ?: JsonNull) } }
    var sheet by remember { mutableStateOf<OrderSheet?>(null) }
    var showAudit by remember { mutableStateOf(false) }
    LaunchedEffect(res) { res.load() }
    val reload = { res.load() }
    fun canEdit(o: ProductionOrderRecord) = env.session.current.can("production.raise_order") && ProductionStatus.editable.contains(o.status ?: "")

    suspend fun post(action: String, json: JsonElement = jsonOf(), success: String?) {
        try {
            val r = env.client.post("/v1/admin/production-orders/$orderId/$action", json)
            env.toasts.show(success ?: r.json["message"].str ?: "Done")
            reload()
        } catch (e: ApiError) { env.toasts.error(e) }
    }
    suspend fun confirm(force: Boolean) {
        try {
            env.client.post("/v1/admin/production-orders/$orderId/confirm", if (force) jsonOf("force" to true) else jsonOf())
            env.toasts.show("Order confirmed")
            reload()
        } catch (e: ApiError) {
            if (e.reason == "pending_payment_approval" && !force && env.session.current.me?.isSuperAdmin == true) {
                env.toasts.show("${e.message} Forcing past the payment hold…", StatusTone.WARNING)
                confirm(true)
            } else env.toasts.error(e)
        }
    }
    suspend fun forceDelete() {
        try { env.client.delete("/v1/admin/production-orders/$orderId/force"); env.toasts.show("Production order deleted"); env.navigator.go(Route.ProductionOrders) } catch (e: ApiError) { env.toasts.error(e) }
    }

    Page("Production order", refresh = { res.reload() }) {
        LoadableView(res.state, retry = { res.load() }) { r ->
            val o = ProductionOrderRecord(r)
            val s = env.session.current
            val status = o.status ?: ""
            val floor = ProductionStatus.acceptsFloorWork(status)
            // Header
            SectionCard {
                Row(horizontalArrangement = Arrangement.spacedBy(Space.m), verticalAlignment = Alignment.Top) {
                    o.image?.let { RemoteImage(it, 72.dp) }
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                            Text(o.number, style = MaterialTheme.typography.titleLarge); StatusBadge(o.status); PriorityBadge(o.priority); if (o.isOverdue) StatusBadge("overdue", StatusTone.DANGER)
                        }
                        Text("${o.quantity} × ${o.productName}", style = MaterialTheme.typography.bodyMedium)
                        o.productSku?.let { Text("SKU $it", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                        val c = o.customerLabel
                        if (c != null) {
                            Text("For $c", style = MaterialTheme.typography.bodySmall)
                            o.r.string("customer_contact")?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                        } else Text("For stock", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                    }
                    Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Text("${o.percent}%", style = MaterialTheme.typography.headlineSmall)
                        val p = o.r["progress"]; val f = p["finished"].int; val st = p["stages"].int
                        if (f != null && st != null) Text("$f/$st stages", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    }
                }
                LinearProgressIndicator(progress = { o.percent / 100f }, modifier = Modifier.fillMaxWidth(), color = Palette.brand)
                DetailRow("Due") { DateText(o.dueDate, DateStyle.DATE) }
                DetailRow("Started") { DateText(o.r.date("started_at"), DateStyle.DATE) }
            }
            // Actions
            FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s), modifier = Modifier.fillMaxWidth()) {
                if (status == "draft" && s.can("production.confirm_order")) ConfirmButton("Confirm order", "Tasks are created from the product's stages and serials are reserved for ${o.quantity} pieces.", action = { confirm(false) }) { Text("Confirm") }
                if (floor && s.can("production.manage_assignees")) {
                    OutlinedButton(onClick = { sheet = OrderSheet.ASSIGN }) { Text("Assign") }
                    OutlinedButton(onClick = { sheet = OrderSheet.MATERIALS }, enabled = o.allocations.isNotEmpty()) { Text("Issue materials") }
                    ConfirmButton("Add missing tasks", "Seeds a task for every active stage this order lacks. Existing tasks are kept.", action = { post("regenerate-tasks", success = null) }) { Text("Regenerate tasks") }
                }
                if (status == "qc_pending" && s.can("production.submit_qc")) Button(onClick = { sheet = OrderSheet.QC }) { Text("Quality check") }
                if (status == "qc_failed" && s.can("production.submit_qc")) Button(onClick = { sheet = OrderSheet.REWORK }, colors = ButtonDefaults.buttonColors(containerColor = Palette.warning)) { Text("Send back for rework") }
                if (status == "qc_passed" && s.can("production.approve_qc")) Button(onClick = { sheet = OrderSheet.COMPLETE }, colors = ButtonDefaults.buttonColors(containerColor = Palette.success)) { Text("Complete & stock") }
                if (canEdit(o)) {
                    OutlinedButton(onClick = { sheet = OrderSheet.EDIT }) { Text("Edit") }
                    OutlinedButton(onClick = { sheet = OrderSheet.BATCHES }) { Text("Batches") }
                }
                if (ProductionStatus.cancellable.contains(status) && s.can("production.confirm_order")) OutlinedButton(onClick = { sheet = OrderSheet.CANCEL }) { Text("Cancel", color = Palette.danger) }
                if (status != "completed" && s.can("production.delete_order")) ConfirmButton("Delete permanently", "Removes the order and its tasks, batches and allocations; serials are voided and the linked sales line unlinked. This cannot be undone.", destructive = true, action = { forceDelete() }) { Text("Delete") }
                DocumentButton("PDF", "/v1/admin/pdf/production-orders/$orderId", suggestedName = "${o.number}.pdf")
            }
            // Stages
            SectionCard("Stages") {
                if (o.tasks.isEmpty()) Text(if (o.status == "draft") "Tasks are created when the order is confirmed." else "No tasks on this order.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                o.tasks.forEach { t -> ProductionTaskRow(t, o, reload); HorizontalDivider(color = MaterialTheme.hub.line) }
            }
            if (o.batches.isNotEmpty() || canEdit(o)) SectionCard("Colourway batches") {
                if (o.batches.isEmpty()) Text("Not batched — the whole order counts as one.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                o.batches.forEach { b -> BatchRow(orderId, b, canEdit(o), reload) }
            }
            val reqs = o.r["material_requirements"].records
            if (o.allocations.isNotEmpty() || reqs.isNotEmpty()) SectionCard("Materials") {
                reqs.forEach { m ->
                    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(m["material_name"].str ?: "Material", style = MaterialTheme.typography.bodyMedium)
                            Text("Needs ${quantityText(m["required"])} ${m["unit"].str ?: ""} · available ${quantityText(m["available"])}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        }
                        if (m["is_short"].bool == true) StatusBadge("short ${quantityText(m["shortage"])}", StatusTone.DANGER)
                    }
                }
                if (o.allocations.isNotEmpty()) {
                    HorizontalDivider(color = MaterialTheme.hub.line)
                    o.allocations.forEach { a ->
                        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                            Text(a["material"]["name"].str ?: "Material", style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f))
                            Text("issued ${quantityText(a["quantity_allocated"])} / ${quantityText(a["quantity_required"])} ${a["material"]["unit_of_measure"].str ?: ""}", style = MaterialTheme.typography.labelSmall)
                            if (env.canViewCost) a["material"]["unit_cost"].money?.let { Text(" @ ${it.formatted()}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
                        }
                    }
                }
            }
            // Details
            SectionCard("Details") {
                DetailRow("Outlet", o.r["outlet"]["name"].str)
                DetailRow("Raised by", personName(o.r["created_by"]))
                DetailRow("Created") { DateText(o.r.date("created_at")) }
                DetailRow("Fitting") { DateText(o.r.date("fitting_date"), DateStyle.DATE) }
                DetailRow("Collection") { DateText(o.r.date("collection_date"), DateStyle.DATE) }
                o.r.string("notes")?.takeIf { it.isNotEmpty() }?.let { DetailRow("Notes", it) }
                val sale = o.r["customer_order"]["order_number"].str; val sid = o.r.int("customer_order_id")
                if (sale != null && sid != null) DetailRow("Sales order") { TextButton(onClick = { env.navigator.push(Route.Order(sid)) }) { Text(sale) } }
                listOf("Measurements" to "measurements", "Specifications" to "specifications", "Preferences" to "customer_preferences").forEach { (title, key) ->
                    val pairs = o.r[key].stringPairs
                    if (pairs.isNotEmpty()) {
                        Text(title.uppercase(), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, modifier = Modifier.padding(top = 4.dp))
                        pairs.forEach { (k, v) -> DetailRow(k.humanized, v) }
                    }
                }
            }
            ProductionMessagesSection(orderId)
            if (showAudit) ModuleAuditLog("/v1/admin/production-orders/$orderId/audit-log")
            else TextButton(onClick = { showAudit = true }) { Text("Show audit log") }
        }
    }

    val r = res.value
    val sh = sheet
    if (r != null && sh != null) {
        val o = ProductionOrderRecord(r)
        val dismiss = { sheet = null }
        when (sh) {
            OrderSheet.EDIT -> EditProductionOrderSheet(o, dismiss, reload)
            OrderSheet.ASSIGN -> AssignTailorsSheet(o, dismiss, reload)
            OrderSheet.MATERIALS -> IssueMaterialsSheet(o, dismiss, reload)
            OrderSheet.QC -> QcSheet(o, dismiss, reload)
            OrderSheet.REWORK -> ReworkSheet(o, dismiss, reload)
            OrderSheet.COMPLETE -> CompleteAndStockSheet(o, dismiss, reload)
            OrderSheet.BATCHES -> BatchesSheet(o, dismiss, reload)
            OrderSheet.CANCEL -> TextPromptSheet("Cancel ${o.number}", dismiss, placeholder = "Reason (optional)", required = false, confirmLabel = "Cancel order", destructive = true) { reason ->
                post("cancel", jsonOf("reason" to jStr(reason)), "Order cancelled")
            }
        }
    }
}

// ── Task row (stage actions through /v1/tailor/tasks/{id}/status, unlock) ───

@Composable
private fun ProductionTaskRow(task: JsonElement, order: ProductionOrderRecord, onChanged: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var menu by remember { mutableStateOf(false) }
    val status = task["status"].str ?: "pending"
    val assignee = personName(task["assigned_to_user"] ?: task["assigned_to"])
    val done = task["quantity_done"].int ?: 0
    val id = task["id"].int

    suspend fun act(action: String) {
        id ?: return
        try {
            env.client.put("/v1/tailor/tasks/$id/status", jsonOf("action" to action))
            env.toasts.show("Stage ${when (action) { "complete" -> "completed"; "pause" -> "paused"; else -> "started" }}")
            onChanged()
        } catch (e: ApiError) { env.toasts.error(e) }
    }
    suspend fun unlock(allow: Boolean) {
        id ?: return
        try {
            env.client.post("/v1/tailor/tasks/$id/unlock", jsonOf("allow" to allow))
            env.toasts.show(if (allow) "Stage unlocked — it may run alongside earlier stages" else "Stage re-locked")
            onChanged()
        } catch (e: ApiError) { env.toasts.error(e) }
    }

    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.Top) {
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                task["sequence"].int?.let { Text("$it.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
                Text(task["stage"]["name"].str ?: "Stage", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                StatusBadge(status)
                if (task["concurrent_allowed"].bool == true) StatusBadge("unlocked", StatusTone.ACCENT)
            }
            Text(assignee?.let { "Assigned to $it" } ?: "Unassigned", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            Text("$done of ${order.quantity} pieces", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
        }
        if (env.session.current.can("production.manage_assignees") && ProductionStatus.acceptsFloorWork(order.status)) Box {
            IconButton(onClick = { menu = true }) { Icon(Icons.Default.MoreVert, contentDescription = "Stage actions") }
            DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                if (status == "pending" || status == "paused") DropdownMenuItem(text = { Text("Start") }, onClick = { menu = false; scope.launch { act("start") } })
                if (status == "in_progress") DropdownMenuItem(text = { Text("Pause") }, onClick = { menu = false; scope.launch { act("pause") } })
                if (status != "completed") DropdownMenuItem(text = { Text("Mark complete") }, onClick = { menu = false; scope.launch { act("complete") } })
                if (task["started_at"].isNull && task["concurrent_allowed"].bool != true) DropdownMenuItem(text = { Text("Unlock to run in parallel") }, onClick = { menu = false; scope.launch { unlock(true) } })
                else if (task["concurrent_allowed"].bool == true && task["started_at"].isNull) DropdownMenuItem(text = { Text("Re-lock") }, onClick = { menu = false; scope.launch { unlock(false) } })
            }
        }
    }
}

// ── Batch row with reference photos (multipart POST …/batches/{b}/images) ───

@Composable
private fun BatchRow(orderId: Int, batch: JsonElement, canEdit: Boolean, onChanged: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val bid = batch["id"].int
    Column(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Text(batch["label"].str ?: "Batch", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
            Text("× ${batch["quantity"].int ?: 0}", style = MaterialTheme.typography.bodySmall)
            Spacer(Modifier.weight(1f))
            if (canEdit && bid != null) AttachmentPicker("Photo") { files ->
                scope.launch {
                    for (f in files) {
                        try { env.client.uploadFile("/v1/admin/production-orders/$orderId/batches/$bid/images", f, field = "image"); env.toasts.show("Photo added"); onChanged() } catch (e: ApiError) { env.toasts.error(e) }
                    }
                }
            }
        }
        val attrs = batch["attributes"].stringPairs
        if (attrs.isNotEmpty()) Text(attrs.joinToString(" · ") { "${it.first}: ${it.second}" }, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
        val images = batch["images"].records.mapNotNull { it.str }
        if (images.isNotEmpty()) Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            images.forEach { u ->
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    RemoteImage(u, 64.dp)
                    if (canEdit && bid != null) TextButton(onClick = {
                        scope.launch { try { env.client.delete("/v1/admin/production-orders/$orderId/batches/$bid/images", jsonOf("url" to u)); onChanged() } catch (e: ApiError) { env.toasts.error(e) } }
                    }) { Text("Remove", style = MaterialTheme.typography.labelSmall, color = Palette.danger) }
                }
            }
        }
    }
}

// ── Messages thread (GET/POST …/messages) ───────────────────────────────────

@Composable
private fun ProductionMessagesSection(orderId: Int) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val res = remember(orderId) { Resource(scope) { env.client.get("/v1/admin/production-orders/$orderId/messages").json["messages"].toRecords() } }
    var text by remember { mutableStateOf("") }
    var type by remember { mutableStateOf("message") }
    LaunchedEffect(res) { res.load() }

    suspend fun send() {
        val body = text.trim()
        if (body.isEmpty()) return
        try {
            env.client.post("/v1/admin/production-orders/$orderId/messages", jsonOf("body" to body, "type" to type))
            text = ""
            res.load()
        } catch (e: ApiError) { env.toasts.error(e) }
    }

    SectionCard("Thread") {
        LoadableView(res.state, retry = { res.load() }, isEmpty = { it.isEmpty() }, empty = { Text("No messages yet", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textTertiary) }) { msgs ->
            Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                msgs.forEach { m ->
                    Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                        Avatar(m["user"]["initials"].str ?: personName(m["user"]) ?: "?", 28.dp)
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                                Text(personName(m["user"]) ?: "", style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold)
                                if (m.string("type") == "note") StatusBadge("note", StatusTone.AMBER)
                                Spacer(Modifier.weight(1f))
                                DateText(m.date("created_at"), DateStyle.RELATIVE)
                            }
                            Text(m.string("body") ?: "", style = MaterialTheme.typography.bodySmall)
                        }
                    }
                }
            }
        }
        SegmentedTabs(listOf("message" to "Message", "note" to "Note"), type) { type = it }
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            OutlinedTextField(value = text, onValueChange = { text = it }, placeholder = { Text("Write to the people on this order…") }, modifier = Modifier.weight(1f))
            Button(onClick = { scope.launch { send() } }, enabled = text.isNotBlank()) { Text("Send") }
        }
    }
}
