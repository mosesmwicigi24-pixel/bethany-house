package ke.co.bethanyhouse.hub.ui.features.sales

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
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
import ke.co.bethanyhouse.hub.core.HubDates
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.obj
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.DateField
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.PagedList
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonNull
import java.time.LocalDate

// ── Comments thread (GET/POST/DELETE /admin/comments — CommentController) ───

/**
 * The unified thread: `?model=Order&id=` lists, POST {model, id, body, type,
 * is_internal, parent_id?} adds, DELETE /comments/{id} removes one's own.
 */
@Composable
internal fun SalesCommentsThread(model: String, id: Int) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val resource = remember(model, id) { Resource(scope) { env.client.get("/v1/admin/comments", mapOf("model" to model, "id" to id.toString())).json["comments"].toRecords() } }
    LaunchedEffect(resource) { resource.load() }
    var body by remember { mutableStateOf("") }
    var internalNote by remember { mutableStateOf(true) }
    var posting by remember { mutableStateOf(false) }

    SectionCard("Comments") {
        LoadableView(resource.state, retry = { resource.load() }) { comments ->
            if (comments.isEmpty()) SalesSecondary("No comments yet.")
            comments.forEach { c -> CommentRow(c, 0, onDeleted = { resource.load() }) }
        }
        OutlinedTextField(value = body, onValueChange = { body = it }, placeholder = { Text("Write a comment… (@name to mention)") }, minLines = 1, maxLines = 5, modifier = Modifier.fillMaxWidth())
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Switch(checked = internalNote, onCheckedChange = { internalNote = it })
            Spacer(Modifier.width(Space.s))
            SalesCaption("Internal")
            Spacer(Modifier.weight(1f))
            TextButton(enabled = !posting && body.isNotBlank(), onClick = {
                posting = true
                scope.launch {
                    try {
                        env.client.post("/v1/admin/comments", jsonOf("model" to model, "id" to id, "body" to body.trim(), "type" to "comment", "is_internal" to internalNote))
                        body = ""
                        resource.load()
                    } catch (e: Exception) { env.toasts.error(e) } finally { posting = false }
                }
            }) { Text("Post") }
        }
    }
}

@Composable
private fun CommentRow(c: Record, depth: Int, onDeleted: () -> Unit) {
    val env = LocalEnv.current
    val mine = c["user"]["id"].int == env.session.current.me?.id
    Column(Modifier.padding(start = (depth * 16).dp, top = 4.dp, bottom = 4.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(c["user"]["name"].str ?: "System", style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold)
            if (c.string("type") == "system") StatusBadge("system", StatusTone.NEUTRAL)
            else if (c["is_internal"].bool == true) StatusBadge("internal", StatusTone.NEUTRAL)
            if (!c["edited_at"].isNull) SalesCaption("edited", MaterialTheme.hub.textTertiary)
            Spacer(Modifier.weight(1f))
            DateText(c.date("created_at"), DateStyle.RELATIVE)
            if (mine && c.string("type") != "system") {
                ConfirmButton("Delete comment", "This removes your comment from the thread.", destructive = true, action = {
                    try { env.client.delete("/v1/admin/comments/${c.id}"); onDeleted() } catch (e: Exception) { env.toasts.error(e) }
                }) { Icon(Icons.Default.Delete, contentDescription = "Delete", modifier = Modifier.size(14.dp)) }
            }
        }
        Text(c.string("plain_body") ?: c.string("body") ?: "", style = MaterialTheme.typography.bodyMedium)
        c["replies"].toRecords().forEach { r -> CommentRow(r, depth + 1, onDeleted) }
    }
}

// ── Audit log (a paginated {data: [...]} of activity rows) ─────────────────

@Composable
internal fun SalesAuditLogSection(path: String) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val list = remember(path) { PagedList(scope, env.client, path, map = ::Record) }
    LaunchedEffect(list) { list.reload() }
    SectionCard("Audit log") {
        LoadableView(list.state, retry = { list.load() }, isEmpty = { it.isEmpty() }, empty = { SalesSecondary("No activity recorded") }) { rows ->
            rows.forEach { r ->
                Column(Modifier.padding(vertical = 3.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Row(Modifier.fillMaxWidth()) {
                        Text((r.string("action") ?: r.string("event") ?: "activity").humanized, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                        DateText(r.date("created_at"))
                    }
                    r.string("description")?.let { SalesSecondary(it, MaterialTheme.hub.text) }
                    SalesCaption(listOfNotNull(r.string("actor_name") ?: "System", r.string("ip_address")).joinToString(" · "), MaterialTheme.hub.textTertiary)
                }
                SalesDivider()
            }
            SalesLoadMore(list)
        }
    }
}

// ── Document revisions (GET /orders/{id}/document-revisions) ───────────────

/**
 * The invoice copy's history and each payment's corrections
 * (SalesDocumentRevisionController::index): `{invoice: {number, status,
 * revision, revisions[]} | null, payments: {"<payment id>": revisions[]}}`.
 */
@Composable
internal fun SalesRevisionsSection(orderId: Int) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val resource = remember(orderId) { Resource(scope) { env.client.get("/v1/admin/orders/$orderId/document-revisions").json ?: JsonNull } }
    LaunchedEffect(resource) { resource.load() }
    SectionCard("Document revisions") {
        LoadableView(resource.state, retry = { resource.load() }) { json ->
            val inv = json["invoice"]
            if (!inv.isNull) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text("Invoice ${inv["number"].str ?: ""}", style = MaterialTheme.typography.bodyMedium)
                    StatusBadge(inv["status"].str)
                    SalesCaption("Rev ${inv["revision"].str ?: "0"}")
                }
                SalesRevisionList(inv["revisions"].toRecords())
            } else SalesSecondary("No invoice document on this order.")
            val payments = json["payments"].obj ?: emptyMap()
            payments.keys.sorted().forEach { pid ->
                val rows = payments[pid].toRecords()
                if (rows.isNotEmpty()) {
                    Text("Payment #$pid", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 4.dp))
                    SalesRevisionList(rows)
                }
            }
        }
    }
}

/** DocumentRevision rows: revision, reason, changes[], by, created_at. */
@Composable
internal fun SalesRevisionList(rows: List<Record>) {
    if (rows.isEmpty()) SalesCaption("No revisions.", MaterialTheme.hub.textTertiary)
    rows.forEach { r ->
        Column(Modifier.padding(vertical = 2.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                Text("Rev ${r.string("revision") ?: ""}", style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold)
                SalesCaption(r.string("by") ?: "—")
                Spacer(Modifier.weight(1f))
                DateText(r.date("created_at"))
            }
            SalesSecondary(r.string("reason") ?: "", MaterialTheme.hub.text)
            r["changes"].records.forEach { c -> SalesCaption("· ${c.str ?: ""}") }
        }
    }
}

// ── Pending proposals (GET /proposals?subject_type=&subject_ids=&status=open) ─

/**
 * Phase 3C changes waiting for a signature on this record (deposit credit
 * past the band, and others); `can_withdraw` offers POST /proposals/{id}/withdraw.
 */
@Composable
internal fun SalesPendingProposalsSection(subjectType: String, subjectId: Int) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val resource = remember(subjectType, subjectId) {
        Resource(scope) { env.client.get("/v1/admin/proposals", mapOf("subject_type" to subjectType, "subject_ids" to subjectId.toString(), "status" to "open")).json.salesRecords }
    }
    LaunchedEffect(resource) { resource.load() }
    val rows = resource.value ?: return
    if (rows.isEmpty()) return
    SectionCard("Pending changes") {
        rows.forEach { p ->
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top) {
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text(p.string("title") ?: (p.string("event") ?: "").humanized, style = MaterialTheme.typography.bodyMedium)
                        StatusBadge(p.status)
                    }
                    p["changes"].records.forEach { c -> SalesCaption("${c["label"].str ?: c["field"].str ?: ""}: ${c["old_display"].str ?: "—"} → ${c["new_display"].str ?: "—"}") }
                    p["request"]["awaiting"].str?.let { SalesCaption("Awaiting: ${it.humanized}", Palette.amber) }
                    SalesCaption("Raised by ${p["maker"]["name"].str ?: "—"} · ${p.string("reference") ?: ""}", MaterialTheme.hub.textTertiary)
                }
                if (p["can_withdraw"].bool == true) {
                    ConfirmButton("Withdraw", "The proposed change is dropped without being applied.", destructive = true, action = {
                        try { env.client.post("/v1/admin/proposals/${p.id}/withdraw"); env.toasts.show("Withdrawn"); resource.load() } catch (e: Exception) { env.toasts.error(e) }
                    }) { Text("Withdraw") }
                }
            }
        }
    }
}

// ── Shipments on an order (GET /shipments?order_id=; POST /orders/{id}/shipments) ─

@Composable
internal fun OrderShipmentsSection(order: Record, onCreate: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val resource = remember(order.id) { Resource(scope) { env.client.get("/v1/admin/shipments", mapOf("order_id" to order.id, "per_page" to "20")).json.salesRecords } }
    LaunchedEffect(resource) { resource.load() }
    SectionCard("Shipments") {
        LoadableView(resource.state, retry = { resource.load() }) { rows ->
            if (rows.isEmpty()) SalesSecondary("No shipment yet.")
            rows.forEach { s ->
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) { SalesMono(s.string("shipment_number") ?: "#${s.id}"); StatusBadge(s.status) }
                        SalesCaption(listOfNotNull(s.string("carrier"), s.string("tracking_number")).joinToString(" · "))
                        DateText(s.date("shipped_at"))
                    }
                    s.intId?.let { id -> TextButton(onClick = { env.navigator.push(Route.Shipment(id)) }) { Text("Open") } }
                }
            }
            val active = rows.any { (it.status ?: "") !in listOf("delivered", "cancelled") }
            if (env.session.current.can("shipment.create")) {
                if ((order.status ?: "") in listOf("confirmed", "shipped") && !active) OutlinedButton(onClick = onCreate) { Text("Create shipment") }
                else if (!active) SalesCaption(when (order.status ?: "") {
                    "pending", "pending_payment" -> "Payment must be received before a shipment can be created."
                    "processing" -> "Partial payment or pending approval — once fully paid and confirmed, a shipment can be created."
                    else -> "The order must be Confirmed before a shipment can be created."
                }, MaterialTheme.hub.textTertiary)
            }
        }
    }
}

/**
 * POST /orders/{id}/shipments {carrier, tracking_number?, carrier_tracking_url?,
 * estimated_delivery_date?, notes?, description?}, then attachments.
 */
@Composable
internal fun CreateShipmentSheet(order: Record, onDone: () -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var carrier by remember { mutableStateOf("") }
    var tracking by remember { mutableStateOf("") }
    var trackingUrl by remember { mutableStateOf("") }
    var eta by remember { mutableStateOf<LocalDate?>(null) }
    var notes by remember { mutableStateOf("") }
    var description by remember { mutableStateOf("") }
    var files by remember { mutableStateOf<List<SalesAttachmentDraft>>(emptyList()) }
    FormSheet("Create shipment", onDismiss, saveLabel = "Create", canSave = carrier.isNotBlank(), save = {
        val body = mutableMapOf<String, Any?>("carrier" to carrier.trim())
        if (tracking.isNotBlank()) body["tracking_number"] = tracking
        if (trackingUrl.isNotBlank()) body["carrier_tracking_url"] = trackingUrl
        eta?.let { body["estimated_delivery_date"] = HubDates.dayString(it) }
        if (notes.isNotBlank()) body["notes"] = notes
        if (description.isNotBlank()) body["description"] = description
        val r = env.client.post("/v1/admin/orders/${order.id}/shipments", ke.co.bethanyhouse.hub.core.toJson(body))
        val sid = r.json["shipment"]["id"].str
        if (sid != null && files.isNotEmpty()) SalesShipmentUploads.upload(files, "/v1/admin/shipments/$sid/upload-attachment", env.client)
        env.toasts.show(r.json["message"].str ?: "Shipment created")
        onDone()
    }) { error ->
        Field("Carrier (required)", carrier, { carrier = it }, error = error?.field("carrier"))
        Field("Tracking number", tracking, { tracking = it }, error = error?.field("tracking_number"))
        Field("Carrier tracking URL", trackingUrl, { trackingUrl = it }, error = error?.field("carrier_tracking_url"))
        DateField("Estimated delivery", eta, { eta = it })
        Field("Customer-facing note for the first tracking event", description, { description = it }, multiline = true)
        Field("Internal notes", notes, { notes = it }, multiline = true)
        SalesAttachmentDraftList(files) { files = it }
        SalesFieldErrors(error)
    }
}
