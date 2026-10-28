package ke.co.bethanyhouse.hub.ui.features.production

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.QrCodeScanner
import androidx.compose.material.icons.filled.ViewInAr
import androidx.compose.material3.Button
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
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
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
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
import ke.co.bethanyhouse.hub.core.money
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.BarcodeScannerSheet
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.DecimalField
import ke.co.bethanyhouse.hub.ui.components.DetailRow
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.InfoSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.Lookup
import ke.co.bethanyhouse.hub.ui.components.LookupPicker
import ke.co.bethanyhouse.hub.ui.components.ModuleRowCard
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.OptionPickerValues
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.StatTile
import ke.co.bethanyhouse.hub.ui.components.StatsRow
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.TileModifier
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.canViewCost
import ke.co.bethanyhouse.hub.ui.components.quantityText
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive

// Raw materials (RawMaterialsController) and product serials
// (ProductSerialController). Unit cost, cost_per_unit and stock_value are
// sent only to products.view_cost holders; the screens gate the same way.

// ── Materials list (GET /v1/admin/inventory/materials → {data, meta, stats}) ─

@Composable
internal fun MaterialsScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var stats by remember { mutableStateOf<JsonElement?>(null) }
    var search by remember { mutableStateOf("") }
    var category by remember { mutableStateOf("") }
    var lowOnly by remember { mutableStateOf(false) }
    var editing by remember { mutableStateOf<Record?>(null) }
    var creating by remember { mutableStateOf(false) }
    var selected by remember { mutableStateOf<Record?>(null) }
    var list by remember { mutableStateOf<ProductionRecordList?>(null) }

    /** The low-stock toggle swaps the source: GET …/materials/low-stock answers `{data, count}` (no paging, no stats), so the header keeps the last full-list stats. */
    suspend fun reload() {
        val path = if (lowOnly) "/v1/admin/inventory/materials/low-stock" else "/v1/admin/inventory/materials"
        val l = ProductionRecordList(scope, env.client, path, perPage = 30)
        if (!lowOnly) l.query = mutableMapOf("search" to search.ifEmpty { null }, "category" to category.ifEmpty { null })
        list = l
        l.reload()
        if (!lowOnly) stats = l.extras["stats"]
    }
    LaunchedEffect(category, lowOnly) { reload() }

    Page("Raw Materials", subtitle = "Fabric, thread, trims — what the garments are made from", refresh = { reload() }, actions = {
        if (env.session.current.can("inventory.adjust")) Button(onClick = { creating = true }) { Text("New") }
    }) {
        val s = stats
        StatsRow(listOf(
            Triple("Materials", s["total"].str ?: "0", null),
            Triple("Active", s["active"].str ?: "0", StatusTone.SUCCESS),
            Triple("Low", s["low_stock"].str ?: "0", if ((s["low_stock"].int ?: 0) > 0) StatusTone.WARNING else null),
            Triple("Out", s["out_of_stock"].str ?: "0", if ((s["out_of_stock"].int ?: 0) > 0) StatusTone.DANGER else null),
        ))
        ModuleSearchField(search, { search = it }, "Code or name", onSubmit = { scope.launch { reload() } })
        OptionPicker("Category", category, s["categories"].records.mapNotNull { it.str }.map { it to it }, { category = it }, allowNone = true)
        ToggleRow("Low stock only", lowOnly, { lowOnly = it })
        list?.let { l ->
            ProductionListView(l, "No materials match", Icons.Default.ViewInAr) { m ->
                ModuleRowCard(onClick = { selected = m }) {
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text(m.string("name") ?: "Material", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                        StatusBadge(m.string("stock_status"), stockTone(m.string("stock_status")))
                        if (m["is_active"].bool == false) StatusBadge("inactive", StatusTone.NEUTRAL)
                    }
                    Text(listOfNotNull(m.string("code"), m.string("category")).joinToString(" · "), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    Text("${quantityText(m["total_stock"])} ${m.string("unit_of_measure") ?: ""} on hand · reorder at ${quantityText(m["reorder_point"])}", style = MaterialTheme.typography.bodySmall)
                    if (env.canViewCost) m["unit_cost"].money?.let { Text("${it.formatted()} / ${m.string("unit_of_measure") ?: "unit"}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                }
            }
        }
    }
    if (creating) MaterialFormSheet(null, onDismiss = { creating = false }) { list?.load() }
    editing?.let { m -> MaterialFormSheet(m, onDismiss = { editing = null }) { list?.load() } }
    selected?.let { m -> MaterialDetailSheet(m.id, onDismiss = { selected = null }, onEdit = { editing = m; selected = null }) { list?.load() } }
}

// ── Create / edit (POST, PUT …/materials[/{id}]) ────────────────────────────

@Composable
private fun MaterialFormSheet(material: Record?, onDismiss: () -> Unit, onSaved: () -> Unit) {
    val env = LocalEnv.current
    val canViewCost = env.canViewCost
    var code by remember { mutableStateOf(material?.string("code") ?: "") }
    var name by remember { mutableStateOf(material?.string("name") ?: "") }
    var description by remember { mutableStateOf(material?.string("description") ?: "") }
    var category by remember { mutableStateOf(material?.string("category") ?: "") }
    var uom by remember { mutableStateOf(material?.string("unit_of_measure") ?: "") }
    var unitCost by remember { mutableStateOf(material?.get("unit_cost").str ?: "") }
    var reorder by remember { mutableStateOf(material?.get("reorder_point").str ?: "") }
    var active by remember { mutableStateOf(material?.get("is_active").bool ?: true) }
    val valid = code.isNotEmpty() && name.isNotEmpty() && uom.isNotEmpty() && (material != null || !canViewCost || J.decimal(unitCost) != null)

    FormSheet(if (material == null) "New material" else "Edit ${material.string("name") ?: "material"}", onDismiss, canSave = valid, save = {
        val body = mutableMapOf<String, JsonElement>("code" to JsonPrimitive(code), "name" to JsonPrimitive(name), "description" to jStr(description), "category" to jStr(category), "unit_of_measure" to JsonPrimitive(uom), "is_active" to JsonPrimitive(active))
        J.decimal(reorder)?.let { body["reorder_point"] = it }
        if (canViewCost) J.decimal(unitCost)?.let { body["unit_cost"] = it }
        val r = if (material != null) env.client.put("/v1/admin/inventory/materials/${material.id}", JsonObject(body)) else env.client.post("/v1/admin/inventory/materials", JsonObject(body))
        val proposal = !r.json["proposal"].isNull
        env.toasts.show(if (proposal) "Saved — the cost change waits for approval" else (r.json["message"].str ?: "Material saved"), if (proposal) StatusTone.WARNING else StatusTone.SUCCESS)
        onSaved()
    }) { error ->
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Field("Code", code, { code = it }, Modifier.weight(1f), placeholder = "FAB-001", error = error?.field("code"))
            Field("Unit of measure", uom, { uom = it }, Modifier.weight(1f), placeholder = "m, kg, pcs", error = error?.field("unit_of_measure"))
        }
        Field("Name", name, { name = it }, error = error?.field("name"))
        Field("Category", category, { category = it }, placeholder = "Fabric, Trim…")
        Field("Description", description, { description = it }, multiline = true)
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            if (canViewCost) DecimalField("Unit cost (KES)", unitCost, { unitCost = it }, Modifier.weight(1f), error = error?.field("unit_cost"))
            DecimalField("Reorder point", reorder, { reorder = it }, Modifier.weight(1f), error = error?.field("reorder_point"))
        }
        if (canViewCost && material != null) Text("A unit-cost change past 5% comes back as a proposal for approval rather than applying at once.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
        ToggleRow("Active", active, { active = it })
    }
}

// ── Detail (GET …/materials/{id} → {material, transactions, bom_usage}; receive / adjust / delete) ─

@Composable
private fun MaterialDetailSheet(materialId: String, onDismiss: () -> Unit, onEdit: () -> Unit, onChanged: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val res = remember(materialId) { Resource(scope) { env.client.get("/v1/admin/inventory/materials/$materialId").json ?: JsonNull } }
    var movement by remember { mutableStateOf<Boolean?>(null) } // true = receive, false = adjust
    LaunchedEffect(res) { res.load() }

    InfoSheet("Material", onDismiss) {
        LoadableView(res.state, retry = { res.load() }) { j ->
            val m = j["material"]
            Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) { Text(m["name"].str ?: "", style = MaterialTheme.typography.titleLarge); StatusBadge(m["stock_status"].str, stockTone(m["stock_status"].str)) }
            Text(listOfNotNull(m["code"].str, m["category"].str, m["description"].str).joinToString(" · "), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
            TileGrid {
                StatTile("On hand", "${quantityText(m["total_stock"])} ${m["unit_of_measure"].str ?: ""}", modifier = TileModifier)
                StatTile("Reorder at", quantityText(m["reorder_point"]), modifier = TileModifier)
                if (env.canViewCost) StatTile("Stock value", m["stock_value"].money?.formatted() ?: "—", hint = m["unit_cost"].money?.let { "${it.formatted()} per unit" }, modifier = TileModifier)
            }
            FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s), modifier = Modifier.fillMaxWidth()) {
                if (env.session.current.can("procurement.receive")) OutlinedButton(onClick = { movement = true }) { Text("Receive") }
                if (env.session.current.can("inventory.adjust")) {
                    OutlinedButton(onClick = { movement = false }) { Text("Adjust") }
                    OutlinedButton(onClick = onEdit) { Text("Edit") }
                    ConfirmButton("Delete material", "Removes the material. The server refuses one still used by a BOM or with stock.", destructive = true, action = {
                        try { env.client.delete("/v1/admin/inventory/materials/$materialId"); env.toasts.show("Material deleted"); onChanged(); onDismiss() } catch (e: ApiError) { env.toasts.error(e) }
                    }) { Text("Delete") }
                }
            }
            val inv = m["inventory"].records
            if (inv.isNotEmpty()) {
                Text("By outlet", style = MaterialTheme.typography.titleMedium)
                inv.forEach { i -> DetailRow(i["outlet"]["name"].str ?: "Outlet ${i["outlet_id"].int ?: 0}", quantityText(i["quantity_on_hand"])) }
            }
            val usage = j["bom_usage"].records
            if (usage.isNotEmpty()) {
                Text("Used by", style = MaterialTheme.typography.titleMedium)
                usage.forEach { u -> DetailRow(u["product_name"].str ?: u["sku"].str ?: "Product", "${quantityText(u["quantity"])} ${u["unit_of_measure"].str ?: ""} each") }
            }
            Text("Movements", style = MaterialTheme.typography.titleMedium)
            val tx = j["transactions"].toRecords()
            if (tx.isEmpty()) Text("No movements yet.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textTertiary)
            tx.forEach { t ->
                StockTransactionRow(t)
                if (env.canViewCost) t["unit_cost"].money?.let { Text("at ${it.formatted()}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
            }
        }
    }
    val mv = movement; val mat = res.value["material"]
    if (mv != null && mat != null) MaterialMovementSheet(Record(mat), receive = mv, onDismiss = { movement = null }) { res.load(); onChanged() }
}

/**
 * POST …/materials/{id}/receive {outlet_id, quantity, transaction_type, unit_cost?, notes?, reference?}
 * POST …/materials/{id}/adjust  {outlet_id, quantity_change, transaction_type, notes}
 */
@Composable
private fun MaterialMovementSheet(material: Record, receive: Boolean, onDismiss: () -> Unit, onDone: () -> Unit) {
    val env = LocalEnv.current
    val outlets = remember { Lookup.outlets(env.client) }
    var outletId by remember { mutableStateOf<Int?>(null) }
    var quantity by remember { mutableStateOf("") }
    var type by remember { mutableStateOf(if (receive) "purchase" else "adjustment") }
    var unitCost by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    var reference by remember { mutableStateOf("") }
    val types = if (receive) listOf("opening_stock", "purchase", "adjustment", "transfer_in") else listOf("adjustment", "damaged", "correction", "transfer_out")
    val uom = material.string("unit_of_measure") ?: ""
    val valid = outletId != null && (quantity.toDoubleOrNull() ?: 0.0) != 0.0 && (receive || notes.isNotBlank())

    FormSheet("${if (receive) "Receive" else "Adjust"} ${material.string("name") ?: ""}", onDismiss, saveLabel = if (receive) "Receive" else "Adjust", canSave = valid, save = {
        val body = mutableMapOf<String, JsonElement>("outlet_id" to jInt(outletId), "transaction_type" to JsonPrimitive(type), "notes" to jStr(notes))
        if (receive) {
            body["quantity"] = J.decimal(quantity) ?: JsonPrimitive(0)
            body["reference"] = jStr(reference)
            if (env.canViewCost) J.decimal(unitCost)?.let { body["unit_cost"] = it }
        } else body["quantity_change"] = J.decimal(quantity) ?: JsonPrimitive(0)
        val r = env.client.post("/v1/admin/inventory/materials/${material.id}/${if (receive) "receive" else "adjust"}", JsonObject(body))
        env.toasts.show(r.json["message"].str ?: "Saved"); onDone()
    }) { error ->
        LookupPicker("Outlet", outlets, outletId, { outletId = it }, error = error?.field("outlet_id"))
        OptionPickerValues("Type", type, types, { type = it })
        DecimalField(if (receive) "Quantity ($uom)" else "Change (± $uom)", quantity, { quantity = it }, error = error?.field(if (receive) "quantity" else "quantity_change"))
        if (receive) {
            if (env.canViewCost) DecimalField("Unit cost (optional)", unitCost, { unitCost = it })
            Field("Reference", reference, { reference = it }, placeholder = "Delivery note, GRN…")
        }
        Field(if (receive) "Notes" else "Notes (required)", notes, { notes = it }, error = error?.field("notes"), multiline = true)
    }
}

// ── Product serials (GET /v1/admin/product-serials → {data, meta, summary, aged_count}) ─

private val serialStatuses = listOf("in_production", "in_stock", "sold", "dispatched", "returned", "cancelled", "missing")

/** A text field with a scan button beside it (ScanField): the camera reads a code into the field. */
@Composable
private fun ScanField(placeholder: String, text: String, onChange: (String) -> Unit, onSubmit: () -> Unit) {
    var scanning by remember { mutableStateOf(false) }
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
        ModuleSearchField(text, onChange, placeholder, onSubmit = onSubmit, modifier = Modifier.weight(1f))
        IconButton(onClick = { scanning = true }) { Icon(Icons.Default.QrCodeScanner, contentDescription = "Scan") }
    }
    if (scanning) BarcodeScannerSheet(title = placeholder, onDismiss = { scanning = false }) { v -> onChange(v); onSubmit() }
}

@Composable
internal fun SerialsScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val list = remember { ProductionRecordList(scope, env.client, "/v1/admin/product-serials", perPage = 30) }
    var search by remember { mutableStateOf("") }
    var status by remember { mutableStateOf("") }
    var agedOnly by remember { mutableStateOf(false) }
    var reconciling by remember { mutableStateOf(false) }
    fun reload() { list.query = mutableMapOf("search" to search.ifEmpty { null }, "status" to status.ifEmpty { null }, "aged" to if (agedOnly) "1" else null); list.load() }
    LaunchedEffect(status, agedOnly) { reload() }

    Page("Product Serials", subtitle = "Every finished piece, one serial each", refresh = { list.reload() }, actions = {
        if (env.session.current.can("inventory.adjust")) Button(onClick = { reconciling = true }) { Text("Reconcile") }
    }) {
        val s = list.extras["summary"]
        StatsRow(listOf(
            Triple("In stock", s["in_stock"].str ?: "0", StatusTone.SUCCESS),
            Triple("In production", s["in_production"].str ?: "0", StatusTone.AMBER),
            Triple("Sold", s["sold"].str ?: "0", null),
            Triple("Aged ${list.extras["aging_days"].int ?: 0}+ days", list.extras["aged_count"].str ?: "0", if ((list.extras["aged_count"].int ?: 0) > 0) StatusTone.WARNING else null),
        ))
        ScanField("Serial, product or order", search, { search = it; reload() }) { reload() }
        OptionPickerValues("Status", status, serialStatuses, { status = it })
        ToggleRow("Aged only", agedOnly, { agedOnly = it })
        ProductionListView(list, "No serials match", Icons.Default.QrCodeScanner) { r ->
            SectionCard {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top) {
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                        FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                            Text(r.string("serial_number") ?: "", style = MaterialTheme.typography.bodyMedium.copy(fontFamily = FontFamily.Monospace)); StatusBadge(r.status); if (r["aged"].bool == true) StatusBadge("aged", StatusTone.WARNING)
                        }
                        Text(listOfNotNull(r.string("product_name"), r.string("product_sku"), r.string("outlet_name")).joinToString(" · "), style = MaterialTheme.typography.bodySmall)
                        FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.m), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                            val po = r.string("production_order_number"); val pid = r.int("production_order_id")
                            if (po != null && pid != null) TextButton(onClick = { env.navigator.push(Route.ProductionOrder(pid)) }) { Text(po) }
                            val on = r.string("order_number"); val oid = r.int("order_id")
                            if (on != null && oid != null) TextButton(onClick = { env.navigator.push(Route.Order(oid)) }) { Text(on) }
                            r.int("days_in_stock")?.let { Text("$it days in stock", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                        }
                    }
                    DateText(r.date("stocked_at") ?: r.date("created_at"), DateStyle.DATE)
                }
            }
        }
    }
    if (reconciling) ReconcileSheet(onDismiss = { reconciling = false }) { list.load() }
}

/** POST /v1/admin/product-serials/reconcile {product_id, outlet_id?, serials: [], flag_missing?} */
@Composable
private fun ReconcileSheet(onDismiss: () -> Unit, onDone: () -> Unit) {
    val env = LocalEnv.current
    val products = remember { Lookup.products(env.client) }
    val outlets = remember { Lookup.outlets(env.client) }
    var productId by remember { mutableStateOf<Int?>(null) }
    var outletId by remember { mutableStateOf<Int?>(null) }
    var serials by remember { mutableStateOf<List<String>>(emptyList()) }
    var typed by remember { mutableStateOf("") }
    var flagMissing by remember { mutableStateOf(false) }
    var scanning by remember { mutableStateOf(false) }
    var result by remember { mutableStateOf<JsonElement?>(null) }
    fun add(v: String) { val s = v.trim(); if (s.isNotEmpty() && !serials.contains(s)) serials = serials + s }

    FormSheet("Reconcile serials", onDismiss, saveLabel = "Compare with the system", canSave = productId != null && serials.isNotEmpty(), save = {
        val r = env.client.post("/v1/admin/product-serials/reconcile", JsonObject(mapOf(
            "product_id" to jInt(productId), "outlet_id" to jInt(outletId), "serials" to J.strs(serials), "flag_missing" to JsonPrimitive(flagMissing),
        )))
        result = r.json
        onDone()
        // Keep the sheet open so the result can be read: FormSheet stays up on an ApiError and shows no message for an empty one.
        throw ApiError(0, "", reason = "keep_open")
    }) { error ->
        LookupPicker("Product", products, productId, { productId = it }, error = error?.field("product_id"))
        LookupPicker("Outlet (optional)", outlets, outletId, { outletId = it })
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Text("${serials.size} scanned", style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f))
            OutlinedButton(onClick = { scanning = true }) { Icon(Icons.Default.QrCodeScanner, contentDescription = null); Text(" Scan") }
            TextButton(onClick = { serials = emptyList() }, enabled = serials.isNotEmpty()) { Text("Clear") }
        }
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            OutlinedTextField(value = typed, onValueChange = { typed = it }, placeholder = { Text("Type or scan a code, then add") }, singleLine = true, modifier = Modifier.weight(1f))
            TextButton(onClick = { add(typed); typed = "" }, enabled = typed.isNotBlank()) { Text("Add") }
        }
        if (serials.isNotEmpty()) Text(serials.joinToString("\n"), style = MaterialTheme.typography.bodySmall.copy(fontFamily = FontFamily.Monospace), modifier = Modifier.fillMaxWidth().heightIn(max = 160.dp).verticalScroll(rememberScrollState()))
        ToggleRow("Flag the ones not scanned as missing", flagMissing, { flagMissing = it })
        result?.let { res ->
            Text(res["message"].str ?: "", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
            DetailRow("Matched", res["matched_count"].str)
            val missing = res["missing"].records
            if (missing.isNotEmpty()) Text("Missing: ${missing.mapNotNull { it["serial_number"].str }.joinToString(", ")}", style = MaterialTheme.typography.bodySmall, color = Palette.danger)
            val unexpected = res["unexpected"].records.mapNotNull { it.str }
            if (unexpected.isNotEmpty()) Text("Not on record: ${unexpected.joinToString(", ")}", style = MaterialTheme.typography.bodySmall, color = Palette.warning)
        }
    }
    if (scanning) BarcodeScannerSheet(title = "Scan serials", onDismiss = { scanning = false }) { v -> add(v) }
}
