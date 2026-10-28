package ke.co.bethanyhouse.hub.ui.features.production

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Tune
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
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
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.dbl
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.obj
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
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
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.StatTile
import ke.co.bethanyhouse.hub.ui.components.StatsRow
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.TextPromptSheet
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.TileModifier
import ke.co.bethanyhouse.hub.ui.components.personName
import ke.co.bethanyhouse.hub.ui.components.quantityText
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive

// Stock adjustments (StockAdjustmentsController): list with stats and reason
// codes, new adjustment (POST), approve / reject / reverse (PUT,
// inventory.approve), PDF and audit log. Statuses: pending_approval,
// approved, rejected. Only an approved, non-reversal row can be reversed.

private class AdjustmentRecord(val r: Record) {
    val id: Int get() = r.int("id") ?: 0
    val status: String? get() = r.status
    val productName: String get() = r["inventory_item"]["product"]["name"].str ?: "Product"
    val where: String get() = listOfNotNull(r["inventory_item"]["product"]["sku"].str, r["inventory_item"]["variant"]["variant_name"].str, r["inventory_item"]["outlet"]["name"].str).joinToString(" · ")
    val change: Double get() = r["quantity_change"].dbl ?: 0.0
    val isReversal: Boolean get() = (r.string("notes") ?: "").startsWith("[REVERSAL")
    val canApprove: Boolean get() = status == "pending_approval"
    val canReverse: Boolean get() = status == "approved" && !isReversal && !r["quantity_change"].isNull
}

// ── List (GET /v1/admin/inventory/adjustments → {data, meta, stats, reason_codes}) ─

@Composable
internal fun AdjustmentsScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val list = remember { ProductionRecordList(scope, env.client, "/v1/admin/inventory/adjustments") }
    val outlets = remember { Lookup.outlets(env.client) }
    var search by remember { mutableStateOf("") }
    var status by remember { mutableStateOf("") }
    var outletId by remember { mutableStateOf<Int?>(null) }
    var showNew by remember { mutableStateOf(false) }
    fun reload() { list.query = mutableMapOf("search" to search.ifEmpty { null }, "status" to status.ifEmpty { null }, "outlet_id" to outletId?.toString()); list.load() }
    LaunchedEffect(status, outletId) { reload() }

    Page("Stock Adjustments", subtitle = "Every correction to finished-goods stock, with its reason", refresh = { list.reload() }, actions = {
        if (env.session.current.can("inventory.adjust")) Button(onClick = { showNew = true }) { Text("New") }
    }) {
        val s = list.extras["stats"]
        StatsRow(listOf(
            Triple("Total", s["total"].str ?: "0", null),
            Triple("Awaiting approval", s["pending_approval"].str ?: "0", if ((s["pending_approval"].int ?: 0) > 0) StatusTone.AMBER else null),
            Triple("Approved", s["approved"].str ?: "0", StatusTone.SUCCESS),
            Triple("Rejected", s["rejected"].str ?: "0", null),
        ))
        ModuleSearchField(search, { search = it }, "SKU or product", onSubmit = { reload() })
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            OptionPicker("Status", status, listOf("pending_approval" to "Awaiting approval", "approved" to "Approved", "rejected" to "Rejected"), { status = it }, Modifier.weight(1f), allowNone = true)
            LookupPicker("Outlet", outlets, outletId, { outletId = it }, Modifier.weight(1f))
        }
        ProductionListView(list, "No adjustments match", Icons.Default.Tune) { r ->
            val a = AdjustmentRecord(r)
            ModuleRowCard(onClick = { env.navigator.push(Route.Adjustment(a.id)) }) {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(a.productName, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold); StatusBadge(a.status); if (a.isReversal) StatusBadge("reversal", StatusTone.ACCENT)
                }
                Text(a.where, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text(r.string("reason_label") ?: r.string("reason_code")?.humanized ?: "", style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f))
                    Text((if (a.change > 0) "+" else "") + quantityText(r["quantity_change"]), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold, color = if (a.change < 0) Palette.danger else Palette.success)
                }
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text(personName(r["created_by"]) ?: "", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary, modifier = Modifier.weight(1f))
                    DateText(r.date("created_at"), DateStyle.DATE_TIME)
                }
            }
        }
    }
    if (showNew) NewAdjustmentSheet(list.extras["reason_codes"], onDismiss = { showNew = false }) { list.load() }
}

// ── New (POST {inventory_item_id, quantity_change, reason_code, notes?, reference_number?}) ─

@Composable
private fun NewAdjustmentSheet(reasonCodes: JsonElement?, onDismiss: () -> Unit, onSaved: () -> Unit) {
    val env = LocalEnv.current
    var search by remember { mutableStateOf("") }
    var items by remember { mutableStateOf<List<Record>>(emptyList()) }
    var itemId by remember { mutableStateOf<Int?>(null) }
    var change by remember { mutableStateOf("") }
    var reason by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    var reference by remember { mutableStateOf("") }
    var searchVersion by remember { mutableStateOf(0) }
    val codes = (reasonCodes.obj ?: emptyMap()).entries.sortedBy { it.key }
    val valid = itemId != null && (change.toIntOrNull() ?: 0) != 0 && reason.isNotEmpty()
    LaunchedEffect(searchVersion) {
        items = runCatching { env.client.get("/v1/admin/inventory/stock-levels", mapOf("search" to search.ifEmpty { null }, "per_page" to "30")).json["data"].toRecords() }.getOrDefault(emptyList())
    }

    FormSheet("New stock adjustment", onDismiss, saveLabel = "Submit", canSave = valid, save = {
        val r = env.client.post("/v1/admin/inventory/adjustments", JsonObject(mapOf(
            "inventory_item_id" to jInt(itemId), "quantity_change" to JsonPrimitive(change.toIntOrNull() ?: 0), "reason_code" to JsonPrimitive(reason), "notes" to jStr(notes), "reference_number" to jStr(reference),
        )))
        val needs = r.json["requires_approval"].bool ?: false
        env.toasts.show(if (needs) "Submitted — waiting for approval" else (r.json["message"].str ?: "Adjustment applied"), if (needs) StatusTone.WARNING else StatusTone.SUCCESS)
        onSaved()
    }) { error ->
        FieldBox("Stock row", error?.field("inventory_item_id")) {
            ModuleSearchField(search, { search = it }, "Find by SKU or product", onSubmit = { searchVersion++ })
            SelectField("Stock row", itemId, items.mapNotNull { i -> i.int("id")?.let { it to "${i["product"]["name"].str ?: ""} ${i["variant"]["variant_name"].str ?: ""} @ ${i["outlet"]["name"].str ?: ""} — on hand ${quantityText(i["quantity_on_hand"])}" } }, { itemId = it }, placeholder = "—")
        }
        OptionPicker("Reason", reason, codes.map { (code, cfg) -> code to ((cfg["label"].str ?: code.humanized) + if (cfg["requires_approval"].bool == true) " (needs approval)" else "") }, { reason = it }, error = error?.field("reason_code"))
        val dir = codes.firstOrNull { it.key == reason }?.value["direction"].str
        if (dir != null && dir != "either") Text(if (dir == "decrease") "This reason only reduces stock — enter a negative quantity." else "This reason only adds stock — enter a positive quantity.", style = MaterialTheme.typography.labelSmall, color = Palette.amber)
        Field("Quantity change (± pieces)", change, { change = it.filter { c -> c.isDigit() || c == '-' } }, placeholder = "-3", error = error?.field("quantity_change"), keyboard = KeyboardType.Number)
        Field("Reference number", reference, { reference = it }, placeholder = "Count sheet, ticket…")
        Field("Notes", notes, { notes = it }, placeholder = "Why", multiline = true)
        Text("Some reasons, and any change above your band, wait for an approver before stock moves.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
    }
}

// ── Detail (GET …/adjustments/{id} → {adjustment}) ──────────────────────────

private enum class AdjustmentPrompt { APPROVE, REJECT, REVERSE }

@Composable
internal fun AdjustmentDetailScreen(adjustmentId: Int) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val res = remember(adjustmentId) { Resource(scope) { Record(env.client.get("/v1/admin/inventory/adjustments/$adjustmentId").json["adjustment"] ?: JsonNull) } }
    var prompt by remember { mutableStateOf<AdjustmentPrompt?>(null) }
    LaunchedEffect(res) { res.load() }

    suspend fun put(action: String, body: JsonObject, success: String) {
        try {
            val r = env.client.put("/v1/admin/inventory/adjustments/$adjustmentId/$action", body)
            env.toasts.show(r.json["message"].str ?: success)
            res.load()
        } catch (e: ApiError) { env.toasts.error(e) }
    }

    Page("Stock adjustment", refresh = { res.reload() }) {
        LoadableView(res.state, retry = { res.load() }) { r ->
            val a = AdjustmentRecord(r)
            SectionCard {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(a.productName, style = MaterialTheme.typography.titleLarge); StatusBadge(a.status); if (a.isReversal) StatusBadge("reversal", StatusTone.ACCENT)
                }
                Text(a.where, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                TileGrid {
                    StatTile("Change", (if (a.change > 0) "+" else "") + quantityText(r["quantity_change"]), tone = if (a.change < 0) StatusTone.DANGER else StatusTone.SUCCESS, modifier = TileModifier)
                    StatTile("Before", quantityText(r["quantity_before"]), modifier = TileModifier)
                    StatTile("After", quantityText(r["quantity_after"]), modifier = TileModifier)
                }
                DetailRow("Reason", r.string("reason_label") ?: r.string("reason_code")?.humanized)
                DetailRow("Reference", r.string("reference_number"))
                DetailRow("Notes", r.string("notes"))
                DetailRow("Raised by", personName(r["created_by"]))
                DetailRow("Raised") { DateText(r.date("created_at")) }
                if (!r["approved_by"].isNull) {
                    DetailRow("Approved by", personName(r["approved_by"]))
                    DetailRow("Approved") { DateText(r.date("approved_at")) }
                }
                DetailRow("Approval notes", r.string("approval_notes"))
            }
            FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s), modifier = Modifier.fillMaxWidth()) {
                if (a.canApprove && env.session.current.can("inventory.approve")) {
                    Button(onClick = { prompt = AdjustmentPrompt.APPROVE }) { Text("Approve") }
                    OutlinedButton(onClick = { prompt = AdjustmentPrompt.REJECT }) { Text("Reject", color = Palette.danger) }
                }
                if (a.canReverse && env.session.current.can("inventory.approve")) OutlinedButton(onClick = { prompt = AdjustmentPrompt.REVERSE }) { Text("Reverse") }
                DocumentButton("PDF", "/v1/admin/pdf/stock-adjustments/$adjustmentId", suggestedName = "adjustment-$adjustmentId.pdf")
            }
            ModuleAuditLog("/v1/admin/inventory/adjustments/$adjustmentId/audit-log")
        }
    }
    when (prompt) {
        AdjustmentPrompt.APPROVE -> TextPromptSheet("Approve adjustment", { prompt = null }, placeholder = "Notes (optional)", required = false, confirmLabel = "Sign approval") { n -> put("approve", JsonObject(mapOf("notes" to jStr(n))), "Signed") }
        AdjustmentPrompt.REJECT -> TextPromptSheet("Reject adjustment", { prompt = null }, placeholder = "Reason (required)", confirmLabel = "Reject", destructive = true) { n -> put("reject", JsonObject(mapOf("reason" to JsonPrimitive(n))), "Adjustment rejected") }
        AdjustmentPrompt.REVERSE -> TextPromptSheet("Reverse adjustment", { prompt = null }, placeholder = "Notes (optional)", required = false, confirmLabel = "Reverse", destructive = true) { n -> put("reverse", JsonObject(mapOf("notes" to jStr(n))), "Reversed") }
        null -> {}
    }
}
