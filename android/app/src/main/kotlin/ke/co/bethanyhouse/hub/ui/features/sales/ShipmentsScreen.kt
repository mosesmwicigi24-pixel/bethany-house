package ke.co.bethanyhouse.hub.ui.features.sales

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowDownward
import androidx.compose.material.icons.filled.ArrowUpward
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Description
import androidx.compose.material.icons.filled.Image
import androidx.compose.material.icons.filled.LocalShipping
import androidx.compose.material.icons.filled.OpenInNew
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material3.Button
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.HubDates
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.jsonOf
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
import ke.co.bethanyhouse.hub.ui.components.DetailRow
import ke.co.bethanyhouse.hub.ui.components.DocumentButton
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.ModuleRowCard
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.PagedList
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.JsonObject
import java.time.LocalDate

// ── Shipments list (GET /admin/shipments — ShipmentController::index) ───────

/** ShipmentController::index allow-list. */
private val SHIPMENT_SORTS: List<Pair<String, String>> = listOf("shipped_at" to "Shipped", "status" to "Status", "carrier" to "Carrier", "estimated_delivery_date" to "ETA", "created_at" to "Created", "updated_at" to "Updated", "tracking_number" to "Tracking no.")

@Composable
fun ShipmentsListScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var search by remember { mutableStateOf("") }
    var status by remember { mutableStateOf("") }
    var from by remember { mutableStateOf<LocalDate?>(null) }
    var to by remember { mutableStateOf<LocalDate?>(null) }
    var sortBy by remember { mutableStateOf("shipped_at") }
    var sortDesc by remember { mutableStateOf(true) }
    val list = remember { PagedList(scope, env.client, "/v1/admin/shipments", map = ::Record) }
    fun reload() {
        list.query = mutableMapOf(
            "search" to search.ifEmpty { null }, "status" to status.ifEmpty { null },
            "start_date" to from?.let(HubDates::dayString), "end_date" to to?.let(HubDates::dayString),
            "sort_by" to sortBy, "sort_order" to if (sortDesc) "desc" else "asc",
        )
        list.load()
    }
    LaunchedEffect(status, sortBy, sortDesc) { reload() }

    Page("Shipments", subtitle = if (list.total > 0) "${list.total} shipments" else null, refresh = { list.reload() }) {
        Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
            ModuleSearchField(search, { search = it }, "Shipment no., order, tracking, customer", onSubmit = ::reload)
            OptionPicker("Status", status, Sales.shipmentStatuses.map { it to it.humanized }, { status = it }, allowNone = true)
            Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                DateField("Shipped from", from, { from = it }, Modifier.weight(1f))
                DateField("Shipped to", to, { to = it }, Modifier.weight(1f))
            }
            Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                OptionPicker("Sort", sortBy, SHIPMENT_SORTS, { if (it.isNotEmpty()) sortBy = it }, Modifier.weight(1f))
                IconButton(onClick = { sortDesc = !sortDesc }) { Icon(if (sortDesc) Icons.Default.ArrowDownward else Icons.Default.ArrowUpward, contentDescription = "Sort direction") }
                Button(onClick = ::reload) { Text("Apply") }
            }
        }
        LoadableView(list.state, retry = { list.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("No shipments", "Shipments are created from a confirmed order.", Icons.Default.LocalShipping) }) { rows ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                rows.forEachIndexed { i, r ->
                    val id = r.intId
                    ModuleRowCard(onClick = { if (id != null) env.navigator.push(Route.Shipment(id)) }) {
                        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) { SalesMono(r.string("shipment_number") ?: "", Modifier.weight(1f)); StatusBadge(r.status) }
                        Text(Sales.name(r.string("customer_first_name"), r.string("customer_last_name")) ?: r.string("order_number") ?: "", style = MaterialTheme.typography.bodyMedium)
                        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                            SalesCaption(listOfNotNull(r.string("order_number"), r.string("carrier"), r.string("tracking_number")).joinToString(" · "), modifier = Modifier.weight(1f))
                            DateText(r.date("shipped_at"), DateStyle.DATE)
                        }
                    }
                    if (i == rows.lastIndex && list.hasMore) LaunchedEffect(rows.size) { list.loadMore() }
                }
                SalesLoadMore(list)
            }
        }
    }
}

// ── Shipment detail (GET /admin/shipments/{id}) ─────────────────────────────

private enum class ShipmentSheet { EDIT, TRACKING, DELIVER, CANCEL }

/**
 * The shipment with its tracking history, attachments and the public
 * tracking link. Edit needs shipment.edit; tracking events, delivery,
 * cancellation and attachments need shipment.manage_tracking.
 */
@Composable
fun ShipmentDetailScreen(shipmentId: Int) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val resource = remember(shipmentId) { Resource(scope) { Record(env.client.get("/v1/admin/shipments/$shipmentId").json ?: JsonNull) } }
    LaunchedEffect(resource) { resource.load() }
    var sheet by remember { mutableStateOf<ShipmentSheet?>(null) }
    var showAudit by remember { mutableStateOf(false) }
    var uploading by remember { mutableStateOf(false) }
    val reload = { resource.load() }

    suspend fun upload(files: List<SalesPickedFile>, path: String) {
        uploading = true
        try {
            SalesShipmentUploads.upload(files.map { SalesAttachmentDraft(it, false) }, path, env.client)
            env.toasts.show("Uploaded")
            reload()
        } catch (e: Exception) { env.toasts.error(e) } finally { uploading = false }
    }

    Page(resource.value?.string("shipment_number") ?: "Shipment", refresh = { resource.reload() }) {
        LoadableView(resource.state, retry = reload) { s ->
            val terminal = (s.status ?: "") in listOf("delivered", "cancelled")
            val can = env.session.current
            // Header
            SectionCard {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top) {
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text(s.string("shipment_number") ?: "", style = MaterialTheme.typography.headlineSmall)
                        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                            StatusBadge(s["status_labels"][s.status ?: ""].str ?: s.status, StatusTone.forStatus(s.status))
                            val oid = s.int("order_id")
                            s.string("order_number")?.let { o -> if (oid != null) TextButton(onClick = { env.navigator.push(Route.Order(oid)) }, contentPadding = androidx.compose.foundation.layout.PaddingValues(0.dp)) { SalesMono("Order $o") } }
                        }
                        FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.m), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            s.string("carrier")?.let { SalesSecondary(it) }
                            s.string("tracking_number")?.let { SalesSecondary("#$it") }
                            s.string("outlet_name")?.let { SalesSecondary(it) }
                        }
                    }
                    Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        DateText(s.date("shipped_at"))
                        s.string("estimated_delivery_date")?.let { SalesCaption("ETA ${it.take(10)}") }
                        s.date("delivered_at")?.let { d -> SalesCaption("Delivered", Palette.success); DateText(d) }
                    }
                }
                // Milestones: TRACKING_MILESTONES up to milestone_index.
                val milestones = Sales.shipmentStatuses.filter { it !in listOf("exception", "cancelled") }
                val index = s.int("milestone_index") ?: -1
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    milestones.forEachIndexed { i, _ -> Box(Modifier.weight(1f).height(6.dp).background(if (i <= index) Palette.brand else MaterialTheme.hub.line, CircleShape)) }
                }
                if (index in milestones.indices) SalesCaption(s["status_labels"][milestones[index]].str ?: milestones[index].humanized, MaterialTheme.hub.textTertiary)
            }
            // Actions
            SectionCard {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                    s.string("tracking_url")?.let { SalesCopyButton("Copy tracking link", it, "Tracking link copied") }
                    s.string("carrier_tracking_url")?.let { SalesLinkButton("Carrier site", it, outlined = true) }
                    DocumentButton("Download PDF", "/v1/admin/pdf/shipments/$shipmentId", suggestedName = "shipment-$shipmentId.pdf")
                    if (can.can("shipment.edit") && !terminal) OutlinedButton(onClick = { sheet = ShipmentSheet.EDIT }) { Text("Edit") }
                    if (can.can("shipment.manage_tracking") && !terminal) {
                        Button(onClick = { sheet = ShipmentSheet.TRACKING }) { Text("Add tracking event") }
                        OutlinedButton(onClick = { sheet = ShipmentSheet.DELIVER }) { Text("Mark delivered") }
                        OutlinedButton(onClick = { sheet = ShipmentSheet.CANCEL }) { Text("Cancel shipment", color = Palette.danger) }
                    }
                    OutlinedButton(onClick = { showAudit = !showAudit }) { Text(if (showAudit) "Hide audit log" else "Audit log") }
                }
            }
            // Customer
            val ctx = SalesRevealContext("shipment", shipmentId)
            SectionCard("Customer") {
                DetailRow("Name", Sales.name(s.string("customer_first_name"), s.string("customer_last_name")))
                SalesContactLine("Phone", s.string("customer_phone"), "phone", s.int("customer_id"), ctx)
                SalesContactLine("Email", s.string("customer_email"), "email", s.int("customer_id"), ctx)
                s.string("notes")?.takeIf { it.isNotEmpty() }?.let { DetailRow("Internal notes", it) }
            }
            // Attachments
            val attachments = s["attachments"].toRecords()
            SectionCard("Attachments") {
                if (attachments.isEmpty()) SalesSecondary("No waybill or documents attached.")
                attachments.forEach { a -> ShipmentAttachmentRow(shipmentId, a, onChanged = reload) }
                if (can.can("shipment.manage_tracking")) {
                    SalesFilePickButton(if (uploading) "Uploading…" else "Add attachment", multiple = true, busy = uploading) { files -> scope.launch { upload(files, "/v1/admin/shipments/$shipmentId/upload-attachment") } }
                    SalesCaption("New files are hidden from the customer until you make them visible.", MaterialTheme.hub.textTertiary)
                }
            }
            // Tracking history
            val events = s["tracking_history"].toRecords()
            SectionCard("Tracking history") {
                if (events.isEmpty()) SalesSecondary("No events yet.")
                events.forEach { e ->
                    Column(Modifier.padding(vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            StatusBadge(e.string("label") ?: e.status, StatusTone.forStatus(e.status))
                            if (e["is_public"].bool == false) StatusBadge("staff only", StatusTone.NEUTRAL)
                            Spacer(Modifier.weight(1f))
                            DateText(e.date("event_time"))
                        }
                        e.string("location")?.takeIf { it.isNotEmpty() }?.let { SalesCaption("📍 $it") }
                        e.string("description")?.takeIf { it.isNotEmpty() }?.let { SalesSecondary(it, MaterialTheme.hub.text) }
                        e.string("added_by_name")?.let { SalesCaption("by $it", MaterialTheme.hub.textTertiary) }
                        e["attachments"].toRecords().forEach { a -> ShipmentAttachmentRow(shipmentId, a, onChanged = reload) }
                        if (can.can("shipment.manage_tracking") && e["id"] != null) {
                            SalesFilePickButton("Attach to this event", multiple = true, busy = uploading) { files -> scope.launch { upload(files, "/v1/admin/shipments/$shipmentId/tracking/${e.id}/upload-attachment") } }
                        }
                    }
                    SalesDivider()
                }
            }
            if (showAudit) ShipmentAuditLog(shipmentId)
        }
    }

    val s = resource.value
    val which = sheet
    if (s != null && which != null) {
        val close = { sheet = null }
        when (which) {
            ShipmentSheet.EDIT -> EditShipmentSheet(s, reload, close)
            ShipmentSheet.TRACKING -> AddTrackingSheet(s, reload, close)
            ShipmentSheet.DELIVER -> MarkDeliveredSheet(s, reload, close)
            ShipmentSheet.CANCEL -> CancelShipmentSheet(s, reload, close)
        }
    }
}

/**
 * One attachment: name, visibility toggle (PATCH …/attachments/{a} {is_public})
 * and delete (DELETE …/attachments/{a}), both shipment.manage_tracking.
 */
@Composable
private fun ShipmentAttachmentRow(shipmentId: Int, attachment: Record, onChanged: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val isPublic = attachment["is_public"].bool == true
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Icon(if (attachment["is_image"].bool == true) Icons.Default.Image else Icons.Default.Description, contentDescription = null, tint = MaterialTheme.hub.textSecondary, modifier = Modifier.size(18.dp))
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(1.dp)) {
            Text(attachment.string("name") ?: "file", style = MaterialTheme.typography.bodySmall, maxLines = 1)
            SalesCaption(if (isPublic) "Customer can see" else "Staff only", if (isPublic) Palette.success else MaterialTheme.hub.textTertiary)
        }
        attachment.string("url")?.let { url -> IconButton(onClick = { salesOpenUrl(context, url) }) { Icon(Icons.Default.OpenInNew, contentDescription = "Open", modifier = Modifier.size(16.dp)) } }
        if (env.session.current.can("shipment.manage_tracking")) {
            IconButton(onClick = {
                scope.launch { try { env.client.patch("/v1/admin/shipments/$shipmentId/attachments/${attachment.id}", jsonOf("is_public" to !isPublic)); onChanged() } catch (e: Exception) { env.toasts.error(e) } }
            }) { Icon(if (isPublic) Icons.Default.VisibilityOff else Icons.Default.Visibility, contentDescription = "Toggle customer visibility", modifier = Modifier.size(16.dp)) }
            ConfirmButton("Remove attachment", "The file is deleted from the shipment.", destructive = true, action = {
                try { env.client.delete("/v1/admin/shipments/$shipmentId/attachments/${attachment.id}"); onChanged() } catch (e: Exception) { env.toasts.error(e) }
            }) { Icon(Icons.Default.Delete, contentDescription = "Remove", modifier = Modifier.size(14.dp)) }
        }
    }
}

/** GET /shipments/{id}/audit-log → {logs: [{event, label, description, actor_name, created_at}]}. */
@Composable
private fun ShipmentAuditLog(shipmentId: Int) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val resource = remember(shipmentId) { Resource(scope) { env.client.get("/v1/admin/shipments/$shipmentId/audit-log").json["logs"].toRecords() } }
    LaunchedEffect(resource) { resource.load() }
    SectionCard("Audit log") {
        LoadableView(resource.state, retry = { resource.load() }, isEmpty = { it.isEmpty() }, empty = { SalesSecondary("No activity recorded") }) { rows ->
            rows.forEach { r ->
                Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Row(Modifier.fillMaxWidth()) {
                        Text(r.string("label") ?: (r.string("event") ?: "").humanized, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                        DateText(r.date("created_at"))
                    }
                    r.string("description")?.let { SalesSecondary(it, MaterialTheme.hub.text) }
                    SalesCaption(r.string("actor_name") ?: "System", MaterialTheme.hub.textTertiary)
                }
                SalesDivider()
            }
        }
    }
}

// ── Sheets ───────────────────────────────────────────────────────────────────

/**
 * PUT /shipments/{id} {carrier?, tracking_number?, carrier_tracking_url?,
 * estimated_delivery_date?, notes?, description?} — shipment.edit.
 */
@Composable
private fun EditShipmentSheet(shipment: Record, onDone: () -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var carrier by remember { mutableStateOf(shipment.string("carrier") ?: "") }
    var tracking by remember { mutableStateOf(shipment.string("tracking_number") ?: "") }
    var trackingUrl by remember { mutableStateOf(shipment.string("carrier_tracking_url") ?: "") }
    var eta by remember { mutableStateOf(shipment.date("estimated_delivery_date")?.atZone(HubDates.nairobi)?.toLocalDate()) }
    var notes by remember { mutableStateOf(shipment.string("notes") ?: "") }
    var description by remember { mutableStateOf(shipment["tracking_history"].records.firstOrNull { it["status"].str == "order_confirmed" }?.get("description").str ?: "") }
    FormSheet("Edit shipment", onDismiss, saveLabel = "Save", canSave = carrier.isNotBlank(), save = {
        val body = mutableMapOf<String, JsonElement>("carrier" to JsonPrimitive(carrier.trim()))
        body["tracking_number"] = Sales.optionalString(tracking)
        body["carrier_tracking_url"] = Sales.optionalString(trackingUrl)
        body["estimated_delivery_date"] = eta?.let { JsonPrimitive(HubDates.dayString(it)) } ?: JsonNull
        body["notes"] = Sales.optionalString(notes)
        body["description"] = Sales.optionalString(description)
        env.client.put("/v1/admin/shipments/${shipment.id}", JsonObject(body))
        env.toasts.show("Shipment updated")
        onDone()
    }) { error ->
        Field("Carrier", carrier, { carrier = it }, error = error?.field("carrier"))
        Field("Tracking number", tracking, { tracking = it }, error = error?.field("tracking_number"))
        Field("Carrier tracking URL", trackingUrl, { trackingUrl = it }, error = error?.field("carrier_tracking_url"))
        DateField("Estimated delivery", eta, { eta = it })
        Field("Customer-facing note (on the first tracking event)", description, { description = it }, multiline = true)
        Field("Internal notes", notes, { notes = it }, multiline = true)
        SalesFieldErrors(error)
    }
}

/** POST /shipments/{id}/tracking {status, location?, description?, event_time?, is_public}. */
@Composable
private fun AddTrackingSheet(shipment: Record, onDone: () -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var status by remember { mutableStateOf("in_transit") }
    var location by remember { mutableStateOf("") }
    var description by remember { mutableStateOf("") }
    var isPublic by remember { mutableStateOf(true) }
    var files by remember { mutableStateOf<List<SalesAttachmentDraft>>(emptyList()) }
    FormSheet("Add tracking event", onDismiss, saveLabel = "Add event", save = {
        val body = mutableMapOf<String, Any?>("status" to status, "is_public" to isPublic)
        if (location.isNotBlank()) body["location"] = location
        if (description.isNotBlank()) body["description"] = description
        env.client.post("/v1/admin/shipments/${shipment.id}/tracking", ke.co.bethanyhouse.hub.core.toJson(body))
        if (files.isNotEmpty()) {
            // The new event is the latest in the refreshed history.
            val fresh = env.client.get("/v1/admin/shipments/${shipment.id}").json
            val latest = fresh["tracking_history"].records.lastOrNull()?.get("id").str
            if (latest != null) SalesShipmentUploads.upload(files, "/v1/admin/shipments/${shipment.id}/tracking/$latest/upload-attachment", env.client)
        }
        env.toasts.show("Tracking event added")
        onDone()
    }) { error ->
        OptionPicker("Stage", status, Sales.shipmentStatuses.filter { it != "cancelled" }.map { it to (shipment["status_labels"][it].str ?: it.humanized) }, { if (it.isNotEmpty()) status = it }, error = error?.field("status"))
        Field("Location", location, { location = it })
        Field("Note for the customer (optional)", description, { description = it }, multiline = true)
        ToggleRow("Visible on the customer's tracking page", isPublic, { isPublic = it })
        SalesAttachmentDraftList(files) { files = it }
        SalesFieldErrors(error)
    }
}

/** POST /shipments/{id}/mark-delivered {delivered_to?, signature?, notes?}. */
@Composable
private fun MarkDeliveredSheet(shipment: Record, onDone: () -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var deliveredTo by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    FormSheet("Mark delivered", onDismiss, saveLabel = "Mark delivered", save = {
        val body = mutableMapOf<String, Any?>()
        if (deliveredTo.isNotBlank()) body["delivered_to"] = deliveredTo
        if (notes.isNotBlank()) body["notes"] = notes
        val r = env.client.post("/v1/admin/shipments/${shipment.id}/mark-delivered", ke.co.bethanyhouse.hub.core.toJson(body))
        env.toasts.show(r.json["message"].str ?: "Marked delivered")
        onDone()
    }) { error ->
        SalesSecondary("The order moves to Delivered as well.")
        Field("Delivered to", deliveredTo, { deliveredTo = it })
        Field("Notes", notes, { notes = it }, multiline = true)
        SalesFieldErrors(error)
    }
}

/** POST /shipments/{id}/cancel {reason}. */
@Composable
private fun CancelShipmentSheet(shipment: Record, onDone: () -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var reason by remember { mutableStateOf("") }
    FormSheet("Cancel shipment", onDismiss, saveLabel = "Cancel shipment", canSave = reason.isNotBlank(), destructive = true, save = {
        val r = env.client.post("/v1/admin/shipments/${shipment.id}/cancel", jsonOf("reason" to reason))
        env.toasts.show(r.json["message"].str ?: "Shipment cancelled")
        onDone()
    }) { error ->
        SalesSecondary("The order returns to Confirmed so a new shipment can be created.")
        Field("Reason (required)", reason, { reason = it }, multiline = true, error = error?.field("reason"))
        SalesFieldErrors(error)
    }
}
