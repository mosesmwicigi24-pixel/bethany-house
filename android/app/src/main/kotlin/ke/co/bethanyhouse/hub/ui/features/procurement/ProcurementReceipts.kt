package ke.co.bethanyhouse.hub.ui.features.procurement

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.dbl
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toJson
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.DecimalField
import ke.co.bethanyhouse.hub.ui.components.DetailRow
import ke.co.bethanyhouse.hub.ui.components.DocumentButton
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.InfoSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.Lookup
import ke.co.bethanyhouse.hub.ui.components.LookupPicker
import ke.co.bethanyhouse.hub.ui.components.ModuleAuditLog
import ke.co.bethanyhouse.hub.ui.components.ModuleRowCard
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.OptionPickerValues
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.PagedList
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.RevisionsSection
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.TextPromptSheet
import ke.co.bethanyhouse.hub.ui.components.formatDate
import ke.co.bethanyhouse.hub.ui.components.personName
import ke.co.bethanyhouse.hub.ui.components.quantityText
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonPrimitive

// Goods received notes (GET /v1/admin/grn, /grn/{id}, corrections,
// revisions, audit-log, pdf) and purchase returns (GET
// /v1/admin/purchase-returns, /{id}, approve / reject / complete,
// audit-log). A GRN is recorded against a purchase order through
// POST /purchase-orders/{id}/receive (ReceiveGoodsSheet).

// ── Goods receipt list ───────────────────────────────────────────────────────

@Composable
fun GoodsReceiptScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val list = remember { PagedList(scope, env.client, "/v1/admin/grn", map = ::Record, perPage = 20) }
    val suppliers = remember { Lookup.suppliers(env.client) }
    var search by remember { mutableStateOf("") }
    var supplierId by remember { mutableStateOf<Int?>(null) }
    var picking by remember { mutableStateOf(false) }
    var chosen by remember { mutableStateOf<PurchaseOrderRecord?>(null) }

    fun apply() { list.query = mutableMapOf("search" to search.ifEmpty { null }, "supplier_id" to supplierId?.toString()); list.load() }
    LaunchedEffect(supplierId) { apply() }

    Page("Goods Receipt", subtitle = "Deliveries recorded against purchase orders", refresh = { list.reload() }) {
        ModuleSearchField(search, { search = it }, "GRN or PO number", onSubmit = { apply() })
        LookupPicker("Supplier", suppliers, supplierId, { supplierId = it })
        if (env.session.current.can("procurement.receive")) Button(onClick = { picking = true }) { Text("Record GRN") }
        LoadableView(list.state, retry = { list.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("No goods receipts match") }) { items ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                items.forEachIndexed { i, g ->
                    ModuleRowCard(onClick = { env.navigator.push(Route.Grn(g.int("id") ?: 0)) }) {
                        Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                            Text(g.string("grn_number") ?: "GRN", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                            StatusBadge(g.string("location_type"), StatusTone.NEUTRAL)
                        }
                        Text(listOfNotNull(g.string("po_number"), g.string("supplier_name")).joinToString(" · "), style = MaterialTheme.typography.bodySmall)
                        Row(Modifier.fillMaxWidth()) {
                            Text(listOfNotNull(g.string("outlet_name"), personName(g["received_by"])).joinToString(" · "), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, modifier = Modifier.weight(1f))
                            Text(g.string("received_date") ?: "", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        }
                    }
                    if (i == items.lastIndex && list.hasMore) LaunchedEffect(items.size) { list.loadMore() }
                }
            }
        }
    }

    if (picking) PurchaseOrderPicker("Record goods receipt", "approved,ordered,partially_received", "Nothing waiting to be received", onDismiss = { picking = false }) { po -> picking = false; chosen = po }
    chosen?.let { po -> ReceiveGoodsSheet(po, onDismiss = { chosen = null }) { list.load() } }
}

/**
 * Picks an order in one of the given statuses and hands back the full
 * order (the list row lacks line details) so the receive / return form
 * can open for it.
 */
@Composable
private fun PurchaseOrderPicker(title: String, statuses: String, emptyTitle: String, onDismiss: () -> Unit, onChoose: (PurchaseOrderRecord) -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val orders = remember { Resource(scope) { env.client.get("/v1/admin/purchase-orders", mapOf("status" to statuses, "per_page" to "100")).json["data"].toRecords() } }
    var opening by remember { mutableStateOf(false) }
    LaunchedEffect(orders) { orders.load() }

    InfoSheet(title, onDismiss) {
        LoadableView(orders.state, retry = { orders.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState(emptyTitle) }) { rows ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                rows.forEach { r ->
                    val po = PurchaseOrderRecord(r)
                    ModuleRowCard(onClick = {
                        if (opening) return@ModuleRowCard
                        opening = true
                        scope.launch {
                            try {
                                val j = env.client.get("/v1/admin/purchase-orders/${po.id}").json
                                onChoose(PurchaseOrderRecord(Record(j["purchase_order"] ?: JsonNull)))
                            } catch (e: Exception) { env.toasts.error(e) } finally { opening = false }
                        }
                    }) {
                        Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                            Text(po.number, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                            StatusBadge(po.status)
                        }
                        Text(po.supplierName, style = MaterialTheme.typography.bodySmall)
                        Text("expected ${formatDate(po.expected, DateStyle.DATE)} · ${po.items.size} line(s)", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    }
                }
            }
        }
    }
}

// ── GRN detail (GET /v1/admin/grn/{id} → {grn}) ──────────────────────────────

private fun grnLineTitle(i: JsonElement?): String {
    val poi = i["purchase_order_item"]
    return poi["product"]["name"].str ?: poi["product"]["translations"][0]["name"].str ?: poi["material"]["name"].str ?: poi["description"].str ?: "Line"
}

@Composable
fun GrnDetailScreen(grnId: Int) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val res = remember(grnId) { Resource(scope) { Record(env.client.get("/v1/admin/grn/$grnId").json["grn"] ?: JsonNull) } }
    var correcting by remember { mutableStateOf(false) }
    LaunchedEffect(res) { res.load() }

    Page("Goods received note", refresh = { res.reload() }) {
        LoadableView(res.state, retry = { res.load() }) { g ->
            val po = g["purchase_order"]
            SectionCard {
                Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                    Text(g.string("grn_number") ?: "GRN", style = MaterialTheme.typography.titleLarge)
                    g.int("revision")?.takeIf { it > 0 }?.let { StatusBadge("rev $it", StatusTone.ACCENT) }
                }
                po["id"].int?.let { pid -> DetailRow("Purchase order") { TextButton(onClick = { env.navigator.push(Route.PurchaseOrder(pid)) }) { Text(po["po_number"].str ?: "PO") } } }
                DetailRow("Supplier", po["supplier"]["name"].str)
                DetailRow("Received on", g.string("received_date")?.take(10))
                DetailRow("Received by", personName(g["received_by"]))
                DetailRow("Outlet", g["outlet"]["name"].str ?: (if (g["outlet_id"].int == null) "Warehouse" else null))
                DetailRow("Invoice number", g.string("invoice_number"))
                g.string("notes")?.takeIf { it.isNotEmpty() }?.let { DetailRow("Notes", it) }
            }
            FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                DocumentButton("PDF", "/v1/admin/pdf/grn/$grnId", suggestedName = "${g.string("grn_number") ?: "grn"}.pdf")
                if (env.session.current.me?.isSuperAdmin == true) OutlinedButton(onClick = { correcting = true }) { Text("Correct quantities (owner)") }
            }
            SectionCard("Lines") {
                g["items"].records.forEach { i ->
                    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top) {
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Text(grnLineTitle(i), style = MaterialTheme.typography.bodyMedium)
                            Text(listOfNotNull(i["condition"].str, i["notes"].str).joinToString(" · "), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        }
                        Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Text("received ${quantityText(i["quantity_received"])}", style = MaterialTheme.typography.bodySmall)
                            if ((i["quantity_rejected"].dbl ?: 0.0) > 0) Text("rejected ${quantityText(i["quantity_rejected"])}", style = MaterialTheme.typography.labelSmall, color = Palette.danger)
                        }
                    }
                    HorizontalDivider(color = MaterialTheme.hub.line)
                }
            }
            RevisionsSection("/v1/admin/grn/$grnId/revisions")
            ModuleAuditLog("/v1/admin/grn/$grnId/audit-log")
            if (correcting) CorrectGrnSheet(g, onDismiss = { correcting = false }) { res.load() }
        }
    }
}

/** POST /v1/admin/grn/{id}/corrections {reason, lines: [{grn_item_id, quantity_received}]} — super admin only. */
@Composable
private fun CorrectGrnSheet(grn: Record, onDismiss: () -> Unit, onDone: () -> Unit) {
    val env = LocalEnv.current
    var reason by remember { mutableStateOf("") }
    val qty = remember { mutableStateMapOf<Int, String>().apply { grn["items"].records.forEach { i -> i["id"].int?.let { put(it, i["quantity_received"].str ?: "0") } } } }

    FormSheet("Correct ${grn.string("grn_number") ?: "GRN"}", onDismiss, saveLabel = "Save correction", canSave = reason.trim().length >= 5 && qty.values.all { procDecimal(it) != null }, save = {
        val lines = qty.map { (id, q) -> J.obj("grn_item_id" to JsonPrimitive(id), "quantity_received" to (J.decimal(q) ?: JsonPrimitive(0))) }
        val r = env.client.post("/v1/admin/grn/${grn.id}/corrections", J.obj("reason" to JsonPrimitive(reason.trim()), "lines" to toJson(lines)))
        env.toasts.show(r.json["message"].str ?: "Corrected")
        onDone()
    }) { error ->
        Field("Reason (required)", reason, { reason = it }, placeholder = "Why the receipt changes", multiline = true, error = error?.field("reason"))
        grn["items"].records.forEach { i ->
            val id = i["id"].int ?: 0
            DecimalField(grnLineTitle(i), qty[id] ?: "", { qty[id] = it })
        }
        ProcCaption("Stock moves by the difference (with serials); the order's received counts and status follow. The receipt as it was is kept as a revision.")
    }
}

// ── Purchase returns list (GET /v1/admin/purchase-returns → paginator) ───────

@Composable
fun PurchaseReturnsScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val list = remember { PagedList(scope, env.client, "/v1/admin/purchase-returns", map = ::Record, perPage = 20) }
    var search by remember { mutableStateOf("") }
    var status by remember { mutableStateOf("") }
    var picking by remember { mutableStateOf(false) }
    var chosen by remember { mutableStateOf<PurchaseOrderRecord?>(null) }

    fun apply() { list.query = mutableMapOf("search" to search.ifEmpty { null }, "status" to status.ifEmpty { null }); list.load() }
    LaunchedEffect(status) { apply() }

    Page("Purchase Returns", subtitle = "Goods sent back to suppliers", refresh = { list.reload() }) {
        ModuleSearchField(search, { search = it }, "Return number", onSubmit = { apply() })
        OptionPickerValues("Status", status, listOf("pending", "approved", "rejected", "completed"), { status = it })
        if (env.session.current.can("procurement.receive")) Button(onClick = { picking = true }) { Text("New return") }
        LoadableView(list.state, retry = { list.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("No purchase returns match") }) { items ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                items.forEachIndexed { i, r ->
                    ModuleRowCard(onClick = { env.navigator.push(Route.PurchaseReturn(r.int("id") ?: 0)) }) {
                        Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                            Text(r.string("return_number") ?: "PR", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                            StatusBadge(r.status)
                        }
                        Text(listOfNotNull(r["purchase_order"]["po_number"].str, r["supplier"]["name"].str ?: r["purchase_order"]["supplier"]["name"].str).joinToString(" · "), style = MaterialTheme.typography.bodySmall)
                        Row(Modifier.fillMaxWidth()) {
                            Text("${r.int("items_count") ?: 0} line(s)", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, modifier = Modifier.weight(1f))
                            DateText(r.date("created_at"), DateStyle.DATE)
                        }
                    }
                    if (i == items.lastIndex && list.hasMore) LaunchedEffect(items.size) { list.loadMore() }
                }
            }
        }
    }

    if (picking) PurchaseOrderPicker("Return goods from…", "ordered,partially_received,received", "No received orders", onDismiss = { picking = false }) { po -> picking = false; chosen = po }
    chosen?.let { po -> PurchaseReturnSheet(po, onDismiss = { chosen = null }) { list.load() } }
}

// ── Purchase return detail (GET /v1/admin/purchase-returns/{id} → {return}) ──

private enum class ReturnPrompt { APPROVE, REJECT, COMPLETE }

@Composable
fun PurchaseReturnDetailScreen(returnId: Int) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val res = remember(returnId) { Resource(scope) { Record(env.client.get("/v1/admin/purchase-returns/$returnId").json["return"] ?: JsonNull) } }
    var prompt by remember { mutableStateOf<ReturnPrompt?>(null) }
    LaunchedEffect(res) { res.load() }

    suspend fun post(action: String, body: JsonElement) {
        val r = env.client.post("/v1/admin/purchase-returns/$returnId/$action", body)
        env.toasts.show(r.json["message"].str ?: "Done")
        res.load()
    }

    Page("Purchase return", refresh = { res.reload() }) {
        LoadableView(res.state, retry = { res.load() }) { r ->
            val status = r.status ?: ""
            SectionCard {
                Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                    Text(r.string("return_number") ?: "PR", style = MaterialTheme.typography.titleLarge)
                    StatusBadge(r.status)
                }
                r["purchase_order"]["id"].int?.let { pid -> DetailRow("Purchase order") { TextButton(onClick = { env.navigator.push(Route.PurchaseOrder(pid)) }) { Text(r["purchase_order"]["po_number"].str ?: "PO") } } }
                DetailRow("Supplier", r["supplier"]["name"].str ?: r["purchase_order"]["supplier"]["name"].str)
                DetailRow("Raised by", personName(r["created_by"]))
                DetailRow("Raised") { DateText(r.date("created_at")) }
                r.string("notes")?.takeIf { it.isNotEmpty() }?.let { DetailRow("Notes", it) }
            }
            FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                if (status == "pending" && env.session.current.can("procurement.approve")) {
                    Button(onClick = { prompt = ReturnPrompt.APPROVE }) { Text("Approve") }
                    OutlinedButton(onClick = { prompt = ReturnPrompt.REJECT }) { Text("Reject", color = Palette.danger) }
                }
                if (status == "approved" && env.session.current.can("procurement.receive")) Button(onClick = { prompt = ReturnPrompt.COMPLETE }, colors = ButtonDefaults.buttonColors(containerColor = Palette.success)) { Text("Mark completed") }
                DocumentButton("PDF", "/v1/admin/pdf/purchase-returns/$returnId", suggestedName = "${r.string("return_number") ?: "return"}.pdf")
            }
            SectionCard("Lines") {
                r["items"].records.forEach { i ->
                    val poi = i["purchase_order_item"]
                    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top) {
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Text(poi["product"]["name"].str ?: poi["material"]["name"].str ?: poi["description"].str ?: "Line", style = MaterialTheme.typography.bodyMedium)
                            Text(i["reason"].str ?: "", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        }
                        Spacer(Modifier.weight(0.05f))
                        Text("× ${quantityText(i["quantity"])}", style = MaterialTheme.typography.bodySmall)
                    }
                    HorizontalDivider(color = MaterialTheme.hub.line)
                }
            }
            ModuleAuditLog("/v1/admin/purchase-returns/$returnId/audit-log")
        }
    }

    val dismiss = { prompt = null }
    when (prompt) {
        ReturnPrompt.APPROVE -> TextPromptSheet("Approve return", dismiss, placeholder = "Notes (optional)", required = false, confirmLabel = "Approve") { n -> post("approve", jsonOf("notes" to n.ifEmpty { null })) }
        ReturnPrompt.REJECT -> TextPromptSheet("Reject return", dismiss, placeholder = "Reason (required)", confirmLabel = "Reject", destructive = true) { n -> post("reject", jsonOf("reason" to n)) }
        ReturnPrompt.COMPLETE -> TextPromptSheet("Complete return", dismiss, placeholder = "Notes (optional)", required = false, confirmLabel = "Complete") { n -> post("complete", jsonOf("notes" to n.ifEmpty { null })) }
        null -> {}
    }
}
