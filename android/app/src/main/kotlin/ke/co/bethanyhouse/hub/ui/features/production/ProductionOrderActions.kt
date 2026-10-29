package ke.co.bethanyhouse.hub.ui.features.production

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.width
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.DayField
import ke.co.bethanyhouse.hub.ui.components.DecimalField
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FieldBox
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.KeyValueEditor
import ke.co.bethanyhouse.hub.ui.components.KeyValueRow
import ke.co.bethanyhouse.hub.ui.components.Lookup
import ke.co.bethanyhouse.hub.ui.components.LookupPicker
import ke.co.bethanyhouse.hub.ui.components.OptionPickerValues
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.quantityText
import ke.co.bethanyhouse.hub.ui.components.stringPairs
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive

// The production order's action sheets. Payload keys are exactly those
// ProductionController validates (update, assign, issueMaterials,
// qualityCheck, rework, complete, saveBatches).

private fun dayOf(j: JsonElement?): String = j.str?.take(10) ?: ""

// ── Edit (PUT /production-orders/{id}) ──────────────────────────────────────

@Composable
internal fun EditProductionOrderSheet(order: ProductionOrderRecord, onDismiss: () -> Unit, onSaved: () -> Unit) {
    val env = LocalEnv.current
    var quantity by remember { mutableStateOf(order.quantity.toString()) }
    var priority by remember { mutableStateOf(order.priority ?: "normal") }
    var dueDate by remember { mutableStateOf(dayOf(order.r["due_date"]).ifEmpty { ke.co.bethanyhouse.hub.ui.components.Day.today() }) }
    var fitting by remember { mutableStateOf(dayOf(order.r["fitting_date"])) }
    var collection by remember { mutableStateOf(dayOf(order.r["collection_date"])) }
    var notes by remember { mutableStateOf(order.r.string("notes") ?: "") }
    var measurements by remember { mutableStateOf(order.r["measurements"].stringPairs.map { KeyValueRow(key = it.first, value = it.second) }) }
    /** Quantity is structural: free while draft; a reduction only with production.delete_order afterwards (ProductionController::update). */
    val quantityEditable = order.status == "draft" || env.session.current.can("production.delete_order")

    FormSheet("Edit ${order.number}", onDismiss, save = {
        val body = mutableMapOf<String, JsonElement>(
            "priority" to JsonPrimitive(priority), "due_date" to JsonPrimitive(dueDate), "notes" to jStr(notes),
            "fitting_date" to jStr(fitting), "collection_date" to jStr(collection),
            "measurements" to J.stringMap(measurements.map { it.key to it.value }),
        )
        val q = quantity.toIntOrNull()
        if (quantityEditable && q != null && q > 0 && q != order.quantity) body["quantity"] = JsonPrimitive(q)
        env.client.put("/v1/admin/production-orders/${order.id}", JsonObject(body))
        env.toasts.show("Order updated"); onSaved()
    }) { error ->
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Field("Quantity", quantity, { quantity = it }, Modifier.weight(1f), error = error?.field("quantity"), keyboard = KeyboardType.Number, enabled = quantityEditable)
            OptionPickerValues("Priority", priority, ProductionStatus.priorities, { priority = it }, Modifier.weight(1f))
        }
        if (!quantityEditable) Text("Quantity can only change while the order is a draft.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
        FieldBox("Due date", error?.field("due_date")) { DayField("Due date", dueDate, { dueDate = it }) }
        FieldBox("Fitting date") { DayField("Fitting date", fitting, { fitting = it }, allowClear = true) }
        FieldBox("Collection date") { DayField("Collection date", collection, { collection = it }, allowClear = true) }
        KeyValueEditor("Measurements", measurements) { measurements = it }
        Field("Notes", notes, { notes = it }, multiline = true)
    }
}

// ── Assign (POST …/assign {assignments: [{task_id, tailor_id, estimated_hours}]}) ─

@Composable
internal fun AssignTailorsSheet(order: ProductionOrderRecord, onDismiss: () -> Unit, onSaved: () -> Unit) {
    val env = LocalEnv.current
    val people = remember { Lookup.assignable(env.client) }
    var chosen by remember { mutableStateOf<Map<Int, Int?>>(emptyMap()) }
    var hours by remember { mutableStateOf<Map<Int, String>>(emptyMap()) }

    FormSheet("Assign stages — ${order.number}", onDismiss, saveLabel = "Save assignments", canSave = chosen.values.any { it != null }, save = {
        val rows = chosen.mapNotNull { (tid, who) ->
            who ?: return@mapNotNull null
            val row = mutableMapOf<String, JsonElement>("task_id" to JsonPrimitive(tid), "tailor_id" to JsonPrimitive(who))
            hours[tid]?.let { h -> J.decimal(h)?.let { row["estimated_hours"] = it } }
            JsonObject(row)
        }
        env.client.post("/v1/admin/production-orders/${order.id}/assign", JsonObject(mapOf("assignments" to JsonArray(rows))))
        env.toasts.show("Assignments saved"); onSaved()
    }) {
        order.tasks.forEach { t ->
            val tid = t["id"].int ?: 0
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) { Text(t["stage"]["name"].str ?: "Stage", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold); StatusBadge(t["status"].str) }
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                    LookupPicker("Person", people, if (chosen.containsKey(tid)) chosen[tid] else (t["assigned_to"].int ?: t["assigned_to"]["id"].int), { chosen = chosen + (tid to it) }, Modifier.weight(2f))
                    DecimalField("Hours", hours[tid] ?: (t["estimated_hours"].str ?: ""), { hours = hours + (tid to it) }, Modifier.weight(1f))
                }
            }
            HorizontalDivider(color = MaterialTheme.hub.line)
        }
        Text("Handing a stage to someone keeps its progress. Only changed rows are sent.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
    }
}

// ── Issue materials (POST …/materials {allocations: [{allocation_id, quantity}]}) ─

@Composable
internal fun IssueMaterialsSheet(order: ProductionOrderRecord, onDismiss: () -> Unit, onSaved: () -> Unit) {
    val env = LocalEnv.current
    var qty by remember { mutableStateOf<Map<Int, String>>(emptyMap()) }
    FormSheet("Issue materials — ${order.number}", onDismiss, saveLabel = "Issue", canSave = qty.values.any { (it.toDoubleOrNull() ?: 0.0) > 0 }, save = {
        val rows = qty.mapNotNull { (id, text) ->
            val v = J.decimal(text) ?: return@mapNotNull null
            if ((text.toDoubleOrNull() ?: 0.0) <= 0) return@mapNotNull null
            J.obj("allocation_id" to JsonPrimitive(id), "quantity" to v)
        }
        env.client.post("/v1/admin/production-orders/${order.id}/materials", JsonObject(mapOf("allocations" to JsonArray(rows))))
        env.toasts.show("Materials issued"); onSaved()
    }) {
        order.allocations.forEach { a ->
            val aid = a["id"].int ?: 0
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                Column(Modifier.weight(2f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(a["material"]["name"].str ?: "Material", style = MaterialTheme.typography.bodyMedium)
                    Text("required ${quantityText(a["quantity_required"])} · issued ${quantityText(a["quantity_allocated"])} ${a["material"]["unit_of_measure"].str ?: ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                }
                DecimalField("Qty", qty[aid] ?: "", { qty = qty + (aid to it) }, Modifier.weight(1f))
            }
        }
        Text("Stock is deducted from material inventory as issued; a shortage is refused by the server with the available figure.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
    }
}

// ── QC (POST …/qc {passed, passed_quantity, failed_quantity, defect_types, notes}) ─

private val qcDefectTypes = listOf("stitching", "measurement", "fabric", "finishing", "embroidery", "colour", "other")

/**
 * NATIVE_APPS_CHECKLIST F1: the server validates `images[]` on this route but
 * never stores them, so no photos are sent here — reference photos go on the
 * order's batches, which persist.
 */
@Composable
internal fun QcSheet(order: ProductionOrderRecord, onDismiss: () -> Unit, onDone: () -> Unit) {
    val env = LocalEnv.current
    var passed by remember { mutableStateOf(true) }
    var passedQty by remember { mutableStateOf(order.quantity.toString()) }
    var failedQty by remember { mutableStateOf("0") }
    var defects by remember { mutableStateOf<Set<String>>(emptySet()) }
    var notes by remember { mutableStateOf("") }

    FormSheet("Quality check — ${order.number}", onDismiss, saveLabel = if (passed) "Record pass" else "Record fail", save = {
        val body = J.obj(
            "passed" to JsonPrimitive(passed), "passed_quantity" to JsonPrimitive(passedQty.toIntOrNull() ?: 0), "failed_quantity" to JsonPrimitive(failedQty.toIntOrNull() ?: 0),
            "defect_types" to J.strs(defects.sorted()), "notes" to jStr(notes),
        )
        env.client.post("/v1/admin/production-orders/${order.id}/qc", body)
        env.toasts.show(if (passed) "QC passed" else "QC failed — recorded on the order", if (passed) StatusTone.SUCCESS else StatusTone.WARNING)
        onDone()
    }) { error ->
        SegmentedTabs(listOf(true to "Passed", false to "Failed"), passed) { passed = it }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Field("Passed pieces", passedQty, { passedQty = it }, Modifier.weight(1f), error = error?.field("passed_quantity"), keyboard = KeyboardType.Number)
            Field("Failed pieces", failedQty, { failedQty = it }, Modifier.weight(1f), error = error?.field("failed_quantity"), keyboard = KeyboardType.Number)
        }
        if (!passed) FieldBox("Defects") {
            FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                qcDefectTypes.forEach { d -> FilterChip(selected = defects.contains(d), onClick = { defects = if (defects.contains(d)) defects - d else defects + d }, label = { Text(d.humanized) }) }
            }
        }
        Field("Notes", notes, { notes = it }, placeholder = "What the inspector found", multiline = true)
        Text("Whoever worked on this order cannot record its QC — the server refuses a maker's own check. Reference photos belong on the order's batches.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
    }
}

// ── Rework (POST …/rework {reason, stages: [{task_id, pieces?, assigned_to?}]}) ─

@Composable
internal fun ReworkSheet(order: ProductionOrderRecord, onDismiss: () -> Unit, onSaved: () -> Unit) {
    val env = LocalEnv.current
    val people = remember { Lookup.assignable(env.client) }
    var reason by remember { mutableStateOf("") }
    var picked by remember { mutableStateOf<Set<Int>>(emptySet()) }
    var pieces by remember { mutableStateOf<Map<Int, String>>(emptyMap()) }
    var assignee by remember { mutableStateOf<Map<Int, Int?>>(emptyMap()) }

    FormSheet("Send back for rework — ${order.number}", onDismiss, saveLabel = "Send back", canSave = picked.isNotEmpty() && reason.isNotBlank(), save = {
        val stages = picked.sorted().map { tid ->
            val row = mutableMapOf<String, JsonElement>("task_id" to JsonPrimitive(tid))
            pieces[tid]?.toIntOrNull()?.takeIf { it > 0 }?.let { row["pieces"] = JsonPrimitive(it) }
            assignee[tid]?.let { row["assigned_to"] = JsonPrimitive(it) }
            JsonObject(row)
        }
        env.client.post("/v1/admin/production-orders/${order.id}/rework", JsonObject(mapOf("reason" to JsonPrimitive(reason.trim()), "stages" to JsonArray(stages))))
        env.toasts.show("Sent back for rework — the tailors have been told"); onSaved()
    }) { error ->
        Field("Reason", reason, { reason = it }, placeholder = "What must be redone", error = error?.field("reason"), multiline = true)
        order.tasks.forEach { t ->
            val tid = t["id"].int ?: 0
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                ToggleRow(t["stage"]["name"].str ?: "Stage", picked.contains(tid), { picked = if (it) picked + tid else picked - tid })
                if (picked.contains(tid)) Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                    Field("Pieces (blank = whole stage)", pieces[tid] ?: "", { pieces = pieces + (tid to it) }, Modifier.weight(1f), keyboard = KeyboardType.Number)
                    LookupPicker("Reassign to", people, assignee[tid], { assignee = assignee + (tid to it) }, Modifier.weight(1f))
                }
            }
        }
    }
}

// ── Complete & stock (POST …/complete {outlet_id?, final_quantity?}) ────────

@Composable
internal fun CompleteAndStockSheet(order: ProductionOrderRecord, onDismiss: () -> Unit, onDone: () -> Unit) {
    val env = LocalEnv.current
    val outlets = remember { Lookup.outlets(env.client) }
    var outletId by remember { mutableStateOf(order.r.int("outlet_id")) }
    var finalQty by remember { mutableStateOf(order.quantity.toString()) }
    FormSheet("Complete & stock — ${order.number}", onDismiss, saveLabel = "Complete", canSave = (finalQty.toIntOrNull() ?: 0) > 0, save = {
        val r = env.client.post("/v1/admin/production-orders/${order.id}/complete", JsonObject(mapOf("outlet_id" to jInt(outletId), "final_quantity" to JsonPrimitive(finalQty.toIntOrNull() ?: order.quantity))))
        env.toasts.show(r.json["message"].str ?: "Completed and stocked"); onDone()
    }) { error ->
        LookupPicker("Stock into outlet", outlets, outletId, { outletId = it }, error = error?.field("outlet_id"))
        Field("Final quantity", finalQty, { finalQty = it }, error = error?.field("final_quantity"), keyboard = KeyboardType.Number)
        Text(if (order.isCustomerJob) "A customer's garment is held for them and leaves stock when collected." else "Finished goods enter stock once; the server refuses a second completion.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
    }
}

// ── Batches (PUT …/batches {batches: [{label, quantity, attributes}]}) ───────

private class BatchDraft(val id: Long = System.nanoTime(), val label: String, val quantity: String, val attrs: String) {
    fun copy(label: String = this.label, quantity: String = this.quantity, attrs: String = this.attrs) = BatchDraft(id, label, quantity, attrs)
}

@Composable
internal fun BatchesSheet(order: ProductionOrderRecord, onDismiss: () -> Unit, onSaved: () -> Unit) {
    val env = LocalEnv.current
    var rows by remember { mutableStateOf(order.batches.map { b -> BatchDraft(label = b["label"].str ?: "", quantity = b["quantity"].str ?: "", attrs = b["attributes"].stringPairs.joinToString(", ") { "${it.first}=${it.second}" }) }) }
    val sum = rows.sumOf { it.quantity.toIntOrNull() ?: 0 }
    val ok = rows.isEmpty() || (sum == order.quantity && rows.all { it.label.isNotBlank() && (it.quantity.toIntOrNull() ?: 0) > 0 })
    fun update(r: BatchDraft) { rows = rows.map { if (it.id == r.id) r else it } }

    FormSheet("Colourway batches — ${order.number}", onDismiss, canSave = ok, save = {
        val batches = rows.map { r ->
            val pairs = r.attrs.split(",").mapNotNull { part -> val kv = part.split("=", limit = 2).map { it.trim() }; if (kv.size == 2) kv[0] to kv[1] else null }
            J.obj("label" to JsonPrimitive(r.label.trim()), "quantity" to JsonPrimitive(r.quantity.toIntOrNull() ?: 0), "attributes" to if (pairs.isEmpty()) JsonNull else J.stringMap(pairs))
        }
        env.client.put("/v1/admin/production-orders/${order.id}/batches", JsonObject(mapOf("batches" to JsonArray(batches))))
        env.toasts.show("Batches saved"); onSaved()
    }) {
        Text("Quantities must add up to exactly ${order.quantity}. Batches can only be redefined before any pieces are counted; an empty set removes batching.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
        rows.forEach { row ->
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                    Field("Label", row.label, { update(row.copy(label = it)) }, Modifier.weight(2f))
                    Field("Qty", row.quantity, { update(row.copy(quantity = it)) }, Modifier.weight(1f), keyboard = KeyboardType.Number)
                }
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                    Field("Attributes", row.attrs, { update(row.copy(attrs = it)) }, Modifier.weight(1f), placeholder = "trim=blue, size=M")
                    TextButton(onClick = { rows = rows.filterNot { it.id == row.id } }) { Text("Remove", color = Palette.danger) }
                }
            }
            HorizontalDivider(color = MaterialTheme.hub.line)
        }
        TextButton(onClick = { rows = rows + BatchDraft(label = "", quantity = "", attrs = "") }) { Text("+ Add batch") }
        Text("Total $sum of ${order.quantity}", style = MaterialTheme.typography.bodySmall, color = if (sum == order.quantity || rows.isEmpty()) Palette.success else Palette.danger)
    }
}
