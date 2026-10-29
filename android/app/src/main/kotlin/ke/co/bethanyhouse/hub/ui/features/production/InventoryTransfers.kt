package ke.co.bethanyhouse.hub.ui.features.production

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.AddCircle
import androidx.compose.material.icons.filled.SwapHoriz
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.DetailRow
import ke.co.bethanyhouse.hub.ui.components.DocumentButton
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FieldBox
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.Lookup
import ke.co.bethanyhouse.hub.ui.components.LookupPicker
import ke.co.bethanyhouse.hub.ui.components.ModuleAuditLog
import ke.co.bethanyhouse.hub.ui.components.ModuleRowCard
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.OptionPickerValues
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.StatsRow
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.TextPromptSheet
import ke.co.bethanyhouse.hub.ui.components.personName
import ke.co.bethanyhouse.hub.ui.components.quantityText
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive

// Stock transfers between outlets (StockTransfersController). State machine:
// pending → approve (inventory.approve) → approved → dispatch
// (inventory.transfer, per-line quantities) → in_transit → receive
// (inventory.transfer) → completed. Cancel (inventory.transfer) while
// pending, approved, rejected or returned.

private class TransferRecord(val r: Record) {
    val id: Int get() = r.int("id") ?: 0
    val number: String get() = r.string("transfer_number") ?: "TRF-$id"
    val status: String? get() = r.status
    val from: String get() = r["from_outlet"]["name"].str ?: "—"
    val to: String get() = r["to_outlet"]["name"].str ?: "—"
    val items: List<JsonElement> get() = r["items"].records
    val itemsCount: Int get() = r.int("items_count") ?: items.size
}

// ── List (GET /v1/admin/inventory/transfers → {data, meta, stats}) ──────────

@Composable
internal fun TransfersScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val list = remember { ProductionRecordList(scope, env.client, "/v1/admin/inventory/transfers") }
    var search by remember { mutableStateOf("") }
    var status by remember { mutableStateOf("") }
    var showNew by remember { mutableStateOf(false) }
    fun reload() { list.query = mutableMapOf("search" to search.ifEmpty { null }, "status" to status.ifEmpty { null }); list.load() }
    LaunchedEffect(status) { reload() }

    Page("Stock Transfers", subtitle = "Stock moving between outlets", refresh = { list.reload() }, actions = {
        if (env.session.current.can("inventory.transfer")) Button(onClick = { showNew = true }) { Text("New") }
    }) {
        val s = list.extras["stats"]
        StatsRow(listOf(
            Triple("Pending", s["pending"].str ?: "0", if ((s["pending"].int ?: 0) > 0) StatusTone.AMBER else null),
            Triple("Approved", s["approved"].str ?: "0", StatusTone.INFO),
            Triple("In transit", s["in_transit"].str ?: "0", StatusTone.PINK),
            Triple("Completed", s["completed"].str ?: "0", StatusTone.SUCCESS),
        ))
        ModuleSearchField(search, { search = it }, "Transfer number", onSubmit = { reload() })
        OptionPickerValues("Status", status, listOf("pending", "approved", "in_transit", "completed", "cancelled"), { status = it })
        ProductionListView(list, "No transfers match", Icons.Default.SwapHoriz) { r ->
            val t = TransferRecord(r)
            ModuleRowCard(onClick = { env.navigator.push(Route.Transfer(t.id)) }) {
                Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) { Text(t.number, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold); StatusBadge(t.status) }
                Text("${t.from} → ${t.to}", style = MaterialTheme.typography.bodySmall)
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text("${t.itemsCount} line${if (t.itemsCount == 1) "" else "s"} · ${personName(r["requested_by"]) ?: ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, modifier = Modifier.weight(1f))
                    DateText(r.date("created_at"), DateStyle.DATE)
                }
            }
        }
    }
    if (showNew) NewTransferSheet(onDismiss = { showNew = false }) { list.load() }
}

// ── New (POST {from_outlet_id, to_outlet_id, notes?, items: [{product_id, product_variant_id?, quantity_requested}]}) ─

private class TransferLine(val id: Long = System.nanoTime(), val productId: Int, val variantId: Int?, val title: String, val onHand: String, val qty: String) {
    fun copy(qty: String) = TransferLine(id, productId, variantId, title, onHand, qty)
}

@Composable
private fun NewTransferSheet(onDismiss: () -> Unit, onSaved: () -> Unit) {
    val env = LocalEnv.current
    val outlets = remember { Lookup.outlets(env.client) }
    var from by remember { mutableStateOf<Int?>(null) }
    var to by remember { mutableStateOf<Int?>(null) }
    var notes by remember { mutableStateOf("") }
    var search by remember { mutableStateOf("") }
    var searchVersion by remember { mutableStateOf(0) }
    var stockRows by remember { mutableStateOf<List<Record>>(emptyList()) }
    var lines by remember { mutableStateOf<List<TransferLine>>(emptyList()) }
    val valid = from != null && to != null && from != to && lines.isNotEmpty() && lines.all { (it.qty.toIntOrNull() ?: 0) > 0 }
    LaunchedEffect(from, searchVersion) {
        stockRows = runCatching { env.client.get("/v1/admin/inventory/stock-levels", mapOf("search" to search.ifEmpty { null }, "outlet_id" to from?.toString(), "per_page" to "30")).json["data"].toRecords() }.getOrDefault(emptyList())
    }

    FormSheet("New stock transfer", onDismiss, saveLabel = "Request transfer", canSave = valid, save = {
        val items = lines.map { JsonObject(mapOf("product_id" to JsonPrimitive(it.productId), "product_variant_id" to jInt(it.variantId), "quantity_requested" to JsonPrimitive(it.qty.toIntOrNull() ?: 0))) }
        val r = env.client.post("/v1/admin/inventory/transfers", JsonObject(mapOf("from_outlet_id" to jInt(from), "to_outlet_id" to jInt(to), "notes" to jStr(notes), "items" to JsonArray(items))))
        env.toasts.show(r.json["message"].str ?: "Transfer requested"); onSaved()
        r.json["transfer"]["id"].int?.let { env.navigator.push(Route.Transfer(it)) }
    }) { error ->
        LookupPicker("From", outlets, from, { from = it }, error = error?.field("from_outlet_id"))
        LookupPicker("To", outlets, to, { to = it }, error = error?.field("to_outlet_id"))
        FieldBox("Add products from the source outlet's stock") {
            ModuleSearchField(search, { search = it }, "SKU or product", onSubmit = { searchVersion++ })
            stockRows.take(12).forEach { s ->
                val title = listOfNotNull(s["product"]["name"].str, s["variant"]["variant_name"].str).joinToString(" · ")
                Row(Modifier.fillMaxWidth().clickable {
                    s.int("product_id")?.let { pid -> lines = lines + TransferLine(productId = pid, variantId = s.int("product_variant_id"), title = title, onHand = quantityText(s["quantity_available"]), qty = "1") }
                }.padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    Text(title, style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f))
                    Text("avail ${quantityText(s["quantity_available"])}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    Icon(Icons.Default.AddCircle, contentDescription = "Add", tint = Palette.brand)
                }
            }
        }
        if (lines.isNotEmpty()) {
            Text("LINES", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            lines.forEach { l ->
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    Column(Modifier.weight(2f)) { Text(l.title, style = MaterialTheme.typography.bodySmall); Text("available ${l.onHand}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
                    Field("Qty", l.qty, { v -> lines = lines.map { if (it.id == l.id) it.copy(qty = v) else it } }, Modifier.weight(1f), keyboard = KeyboardType.Number)
                    TextButton(onClick = { lines = lines.filterNot { it.id == l.id } }) { Text("Remove", color = Palette.danger) }
                }
            }
        }
        Field("Notes", notes, { notes = it }, multiline = true)
    }
}

// ── Detail (GET …/transfers/{id} → {transfer}) ──────────────────────────────

@Composable
internal fun TransferDetailScreen(transferId: Int) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val res = remember(transferId) { Resource(scope) { Record(env.client.get("/v1/admin/inventory/transfers/$transferId").json["transfer"] ?: JsonNull) } }
    var dispatching by remember { mutableStateOf(false) }
    var cancelling by remember { mutableStateOf(false) }
    LaunchedEffect(res) { res.load() }

    suspend fun put(action: String, body: JsonElement = jsonOf()) {
        try {
            val r = env.client.put("/v1/admin/inventory/transfers/$transferId/$action", body)
            env.toasts.show(r.json["message"].str ?: "Done")
            res.load()
        } catch (e: ApiError) { env.toasts.error(e) }
    }

    Page("Stock transfer", refresh = { res.reload() }) {
        LoadableView(res.state, retry = { res.load() }) { r ->
            val t = TransferRecord(r)
            val s = env.session.current
            val status = t.status ?: ""
            SectionCard {
                Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) { Text(t.number, style = MaterialTheme.typography.titleLarge); StatusBadge(t.status) }
                Text("${t.from} → ${t.to}", style = MaterialTheme.typography.bodyMedium)
                DetailRow("Requested by", personName(r["requested_by"]))
                DetailRow("Requested") { DateText(r.date("requested_at") ?: r.date("created_at")) }
                DetailRow("Approved by", personName(r["approved_by"]))
                DetailRow("Completed by", personName(r["completed_by"]))
                r.string("notes")?.takeIf { it.isNotEmpty() }?.let { DetailRow("Notes", it) }
            }
            FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s), modifier = Modifier.fillMaxWidth()) {
                if (status == "pending" && s.can("inventory.approve")) ConfirmButton("Approve transfer", "Signs the approval; stock moves when it is dispatched.", action = { put("approve") }) { Text("Approve") }
                if (status == "approved" && s.can("inventory.transfer")) Button(onClick = { dispatching = true }) { Text("Dispatch") }
                if (status == "in_transit" && s.can("inventory.transfer")) ConfirmButton("Receive at ${t.to}", "Adds the dispatched quantities to ${t.to} and completes the transfer.", action = { put("receive") }) { Text("Receive") }
                if (status in listOf("pending", "approved", "rejected", "returned") && s.can("inventory.transfer")) OutlinedButton(onClick = { cancelling = true }) { Text("Cancel", color = Palette.danger) }
                DocumentButton("PDF", "/v1/admin/pdf/stock-transfers/$transferId", suggestedName = "${t.number}.pdf")
            }
            SectionCard("Lines") {
                t.items.forEach { i ->
                    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top) {
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Text(i["product"]["name"].str ?: "Product", style = MaterialTheme.typography.bodyMedium)
                            Text(listOfNotNull(i["product"]["sku"].str, i["variant"]["variant_name"].str).joinToString(" · "), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        }
                        Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Text("requested ${quantityText(i["quantity_requested"])}", style = MaterialTheme.typography.bodySmall)
                            Text("sent ${quantityText(i["quantity_received"])}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                            if (!i["source_stock"].isNull) Text("at source ${quantityText(i["source_stock"])}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
                        }
                    }
                    HorizontalDivider(color = MaterialTheme.hub.line)
                }
            }
            ModuleAuditLog("/v1/admin/inventory/transfers/$transferId/audit-log")
        }
    }
    val r = res.value
    if (dispatching && r != null) DispatchSheet(TransferRecord(r), onDismiss = { dispatching = false }) { res.load() }
    if (cancelling) TextPromptSheet("Cancel transfer", { cancelling = false }, placeholder = "Reason (optional)", required = false, confirmLabel = "Cancel transfer", destructive = true) { reason ->
        put("cancel", JsonObject(mapOf("reason" to jStr(reason))))
    }
}

/** PUT …/transfers/{id}/dispatch {items: [{id, quantity_received}]} — the quantity actually sent per line. */
@Composable
private fun DispatchSheet(transfer: TransferRecord, onDismiss: () -> Unit, onDone: () -> Unit) {
    val env = LocalEnv.current
    var qty by remember { mutableStateOf(transfer.items.mapNotNull { i -> i["id"].int?.let { it to (i["quantity_requested"].str ?: "0") } }.toMap()) }
    FormSheet("Dispatch ${transfer.number}", onDismiss, saveLabel = "Dispatch", canSave = qty.values.all { it.toIntOrNull() != null }, save = {
        val items = qty.map { (id, v) -> JsonObject(mapOf("id" to JsonPrimitive(id), "quantity_received" to JsonPrimitive(v.toIntOrNull() ?: 0))) }
        val r = env.client.put("/v1/admin/inventory/transfers/${transfer.id}/dispatch", JsonObject(mapOf("items" to JsonArray(items))))
        env.toasts.show(r.json["message"].str ?: "Dispatched"); onDone()
    }) {
        Text("Enter what is actually leaving ${transfer.from}. The server refuses a line above the source outlet's stock.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
        transfer.items.forEach { i ->
            val id = i["id"].int ?: 0
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                Column(Modifier.weight(2f)) { Text(i["product"]["name"].str ?: "", style = MaterialTheme.typography.bodyMedium); Text("requested ${quantityText(i["quantity_requested"])}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                Field("Qty", qty[id] ?: "", { qty = qty + (id to it) }, Modifier.weight(1f), keyboard = KeyboardType.Number)
            }
        }
    }
}
