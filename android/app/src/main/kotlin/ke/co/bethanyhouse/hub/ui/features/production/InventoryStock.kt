package ke.co.bethanyhouse.hub.ui.features.production

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.BarChart
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material3.Button
import androidx.compose.material3.HorizontalDivider
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
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.dbl
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.DetailRow
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.Lookup
import ke.co.bethanyhouse.hub.ui.components.LookupPicker
import ke.co.bethanyhouse.hub.ui.components.ModuleRowCard
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.StatTile
import ke.co.bethanyhouse.hub.ui.components.StatsRow
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.TileModifier
import ke.co.bethanyhouse.hub.ui.components.personName
import ke.co.bethanyhouse.hub.ui.components.quantityText
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive

/** Stock-status tone shared by stock levels, materials and alerts. */
internal fun stockTone(status: String?): StatusTone = when (status) { "out_of_stock" -> StatusTone.DANGER; "low_stock" -> StatusTone.WARNING; else -> StatusTone.SUCCESS }

// ── Stock levels (GET /v1/admin/inventory/stock-levels → {data, meta, stats}) ─

@Composable
internal fun StockLevelsScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val list = remember { ProductionRecordList(scope, env.client, "/v1/admin/inventory/stock-levels", perPage = 30) }
    val outlets = remember { Lookup.outlets(env.client) }
    var search by remember { mutableStateOf("") }
    var outletId by remember { mutableStateOf<Int?>(null) }
    var status by remember { mutableStateOf("") }
    var opening by remember { mutableStateOf(false) }
    var selected by remember { mutableStateOf<Record?>(null) }
    fun reload() { list.query = mutableMapOf("search" to search.ifEmpty { null }, "outlet_id" to outletId?.toString(), "status" to status.ifEmpty { null }); list.load() }
    LaunchedEffect(outletId, status) { reload() }

    Page("Stock Levels", subtitle = "Finished goods on hand, per outlet", refresh = { list.reload() }, actions = {
        if (env.session.current.can("inventory.adjust")) OutlinedButton(onClick = { opening = true }) { Text("Opening stock") }
    }) {
        val s = list.extras["stats"]
        StatsRow(listOf(
            Triple("SKUs", s["total_skus"].str ?: "0", null),
            Triple("In stock", s["in_stock"].str ?: "0", StatusTone.SUCCESS),
            Triple("Low", s["low_stock"].str ?: "0", if ((s["low_stock"].int ?: 0) > 0) StatusTone.WARNING else null),
            Triple("Out", s["out_of_stock"].str ?: "0", if ((s["out_of_stock"].int ?: 0) > 0) StatusTone.DANGER else null),
        ))
        ModuleSearchField(search, { search = it }, "SKU or product", onSubmit = { reload() })
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            LookupPicker("Outlet", outlets, outletId, { outletId = it }, Modifier.weight(1f))
            OptionPicker("Status", status, listOf("in_stock" to "In stock", "low_stock" to "Low", "out_of_stock" to "Out"), { status = it }, Modifier.weight(1f), allowNone = true)
        }
        ProductionListView(list, "No stock rows match", Icons.Default.BarChart) { r ->
            ModuleRowCard(onClick = { selected = r }) {
                Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) { Text(r["product"]["name"].str ?: "Product", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold); StatusBadge(r.status, stockTone(r.status)) }
                Text(listOfNotNull(r["product"]["sku"].str, r["variant"]["variant_name"].str, r["outlet"]["name"].str).joinToString(" · "), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.l), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    QtyStat("On hand", r["quantity_on_hand"]); QtyStat("Reserved", r["quantity_reserved"]); QtyStat("Available", r["quantity_available"]); QtyStat("Reorder at", r["reorder_point"])
                }
            }
        }
    }
    if (opening) OpeningStockSheet(onDismiss = { opening = false }) { list.load() }
    selected?.let { r -> StockItemSheet(r, onDismiss = { selected = null }) { list.load() } }
}

@Composable
private fun QtyStat(label: String, v: JsonElement?) {
    Column {
        Text(label, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
        Text(quantityText(v), style = MaterialTheme.typography.bodySmall)
    }
}

/** One stock row: history (GET …/stock-levels/{id}/history) and reorder settings (PUT …/stock-levels/{id}). */
@Composable
private fun StockItemSheet(item: Record, onDismiss: () -> Unit, onChanged: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val canAdjust = env.session.current.can("inventory.adjust")
    val history = remember { ProductionRecordList(scope, env.client, "/v1/admin/inventory/stock-levels/${item.id}/history", perPage = 50) }
    var reorderPoint by remember { mutableStateOf(item["reorder_point"].str ?: "0") }
    var reorderQty by remember { mutableStateOf(item["reorder_quantity"].str ?: "0") }
    LaunchedEffect(Unit) { history.reload() }

    FormSheet(item["product"]["name"].str ?: "Stock item", onDismiss, saveLabel = "Save thresholds", canSave = canAdjust, save = {
        env.client.put("/v1/admin/inventory/stock-levels/${item.id}", JsonObject(mapOf("reorder_point" to JsonPrimitive(reorderPoint.toIntOrNull() ?: 0), "reorder_quantity" to JsonPrimitive(reorderQty.toIntOrNull() ?: 0))))
        env.toasts.show("Stock settings updated"); onChanged()
    }) { error ->
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) { StatusBadge(item.status, stockTone(item.status)); Text(item["outlet"]["name"].str ?: "", style = MaterialTheme.typography.bodySmall) }
        TileGrid {
            StatTile("On hand", quantityText(item["quantity_on_hand"]), modifier = TileModifier)
            StatTile("Reserved", quantityText(item["quantity_reserved"]), modifier = TileModifier)
            StatTile("Available", quantityText(item["quantity_available"]), modifier = TileModifier)
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Field("Reorder point", reorderPoint, { reorderPoint = it }, Modifier.weight(1f), error = error?.field("reorder_point"), keyboard = KeyboardType.Number, enabled = canAdjust)
            Field("Reorder quantity", reorderQty, { reorderQty = it }, Modifier.weight(1f), error = error?.field("reorder_quantity"), keyboard = KeyboardType.Number, enabled = canAdjust)
        }
        Text("History", style = MaterialTheme.typography.titleMedium, modifier = Modifier.padding(top = 8.dp))
        ProductionListView(history, "No movements recorded") { t -> StockTransactionRow(t) }
    }
}

@Composable
internal fun StockTransactionRow(t: Record) {
    val change = t["quantity_change"].dbl ?: 0.0
    Row(Modifier.fillMaxWidth().padding(vertical = 3.dp), verticalAlignment = Alignment.Top) {
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(t.string("type_label") ?: t.string("transaction_type")?.humanized ?: "Movement", style = MaterialTheme.typography.bodyMedium)
            Text(listOfNotNull(personName(t["created_by"]), t.string("notes")).joinToString(" · "), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, maxLines = 2)
        }
        Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text((if (change > 0) "+" else "") + quantityText(t["quantity_change"]), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold, color = if (change < 0) Palette.danger else Palette.success)
            Text("${quantityText(t["quantity_before"])} → ${quantityText(t["quantity_after"])}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            DateText(t.date("created_at"), DateStyle.DATE_TIME)
        }
    }
}

private class OpeningRow(val id: Long = System.nanoTime(), val productId: Int? = null, val variantId: Int? = null, val outletId: Int? = null, val quantity: String = "", val reorderPoint: String = "", val notes: String = "") {
    fun copy(productId: Int? = this.productId, outletId: Int? = this.outletId, quantity: String = this.quantity, reorderPoint: String = this.reorderPoint) = OpeningRow(id, productId, variantId, outletId, quantity, reorderPoint, notes)
}

/** POST /v1/admin/inventory/stock-levels/opening {entries: [{product_id, product_variant_id?, outlet_id, quantity, reorder_point?, notes?}]} */
@Composable
private fun OpeningStockSheet(onDismiss: () -> Unit, onSaved: () -> Unit) {
    val env = LocalEnv.current
    val products = remember { Lookup.products(env.client) }
    val outlets = remember { Lookup.outlets(env.client) }
    var rows by remember { mutableStateOf(listOf(OpeningRow())) }
    val valid = rows.any { it.productId != null && it.outletId != null && it.quantity.toIntOrNull() != null }
    fun update(r: OpeningRow) { rows = rows.map { if (it.id == r.id) r else it } }

    FormSheet("Opening stock", onDismiss, canSave = valid, save = {
        val entries = rows.mapNotNull { r ->
            val p = r.productId ?: return@mapNotNull null; val o = r.outletId ?: return@mapNotNull null; val q = r.quantity.toIntOrNull() ?: return@mapNotNull null
            val e = mutableMapOf<String, JsonElement>("product_id" to JsonPrimitive(p), "outlet_id" to JsonPrimitive(o), "quantity" to JsonPrimitive(q), "notes" to jStr(r.notes))
            r.variantId?.let { e["product_variant_id"] = JsonPrimitive(it) }
            r.reorderPoint.toIntOrNull()?.let { e["reorder_point"] = JsonPrimitive(it) }
            JsonObject(e)
        }
        val res = env.client.post("/v1/admin/inventory/stock-levels/opening", JsonObject(mapOf("entries" to JsonArray(entries))))
        env.toasts.show(res.json["message"].str ?: "Opening stock saved"); onSaved()
    }) {
        rows.forEach { row ->
            Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                LookupPicker("Product", products, row.productId, { update(row.copy(productId = it)) })
                LookupPicker("Outlet", outlets, row.outletId, { update(row.copy(outletId = it)) })
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                    Field("Qty", row.quantity, { update(row.copy(quantity = it)) }, Modifier.weight(1f), keyboard = KeyboardType.Number)
                    Field("Reorder at", row.reorderPoint, { update(row.copy(reorderPoint = it)) }, Modifier.weight(1f), keyboard = KeyboardType.Number)
                    TextButton(onClick = { rows = rows.filterNot { it.id == row.id } }) { Text("Remove", color = Palette.danger) }
                }
            }
            HorizontalDivider(color = MaterialTheme.hub.line)
        }
        TextButton(onClick = { rows = rows + OpeningRow() }) { Text("+ Add row") }
        Text("Sets the opening count for a product at an outlet; rows for a variant are set from the product's stock page.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
    }
}

// ── Low stock alerts (GET /v1/admin/inventory/low-stock-alerts?type=) ───────

@Composable
internal fun LowStockScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var type by remember { mutableStateOf("all") }
    var editing by remember { mutableStateOf<Record?>(null) }
    val res = remember(type) { val t = type; Resource(scope) { env.client.get("/v1/admin/inventory/low-stock-alerts", mapOf("type" to if (t == "all") null else t)).json ?: JsonNull } }
    LaunchedEffect(res) { res.reload() }

    Page("Low Stock Alerts", subtitle = "Products and materials at or under their reorder point", refresh = { res.reload() }) {
        SegmentedTabs(listOf("all" to "All", "products" to "Products", "materials" to "Materials"), type) { type = it }
        LoadableView(res.state, retry = { res.load() }) { j ->
            val s = j["summary"]
            StatsRow(listOf(
                Triple("Products out", s["products_out_of_stock"].str ?: "0", StatusTone.DANGER),
                Triple("Products low", s["products_low_stock"].str ?: "0", StatusTone.WARNING),
                Triple("Materials out", s["materials_out_of_stock"].str ?: "0", StatusTone.DANGER),
                Triple("Materials low", s["materials_low_stock"].str ?: "0", StatusTone.WARNING),
            ))
            val alerts = j["data"].toRecords()
            if (alerts.isEmpty()) EmptyState("Nothing is running low", icon = Icons.Default.CheckCircle)
            alerts.forEach { a ->
                val isProduct = a.string("type") == "product"
                SectionCard {
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text(if (isProduct) (a["product"]["name"].str ?: "Product") else (a["material"]["name"].str ?: "Material"), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                        StatusBadge(a.string("severity"), stockTone(a.string("severity")))
                        StatusBadge(if (isProduct) "product" else "material", StatusTone.NEUTRAL)
                    }
                    Text(listOfNotNull(if (isProduct) a["product"]["sku"].str else a["material"]["code"].str, a["variant"]["variant_name"].str, a["outlet"]["name"].str).joinToString(" · "), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    Text("${quantityText(a["quantity"])} ${a.string("unit") ?: ""} on hand · reorder at ${quantityText(a["reorder_point"])}", style = MaterialTheme.typography.bodySmall)
                    a["by_outlet"].records.forEach { o -> Text("${o["outlet_name"].str ?: ""}: ${quantityText(o["quantity_on_hand"])}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) {
                        if (isProduct && env.session.current.can("inventory.adjust")) OutlinedButton(onClick = { editing = a }) { Text("Threshold…") }
                        if (!isProduct) OutlinedButton(onClick = { env.navigator.go(Route.Materials) }) { Text("Raw materials") }
                    }
                }
            }
        }
    }
    editing?.let { a -> ThresholdSheet(a, onDismiss = { editing = null }) { res.load() } }
}

/** PUT /v1/admin/inventory/low-stock-alerts/{inventoryItemId}/threshold {reorder_point, reorder_quantity?} */
@Composable
private fun ThresholdSheet(alert: Record, onDismiss: () -> Unit, onSaved: () -> Unit) {
    val env = LocalEnv.current
    var point by remember { mutableStateOf(alert["reorder_point"].str ?: "0") }
    var qty by remember { mutableStateOf("") }
    FormSheet("Reorder threshold — ${alert["product"]["name"].str ?: ""}", onDismiss, canSave = point.toIntOrNull() != null, save = {
        val body = mutableMapOf<String, JsonElement>("reorder_point" to JsonPrimitive(point.toIntOrNull() ?: 0))
        qty.toIntOrNull()?.let { body["reorder_quantity"] = JsonPrimitive(it) }
        env.client.put("/v1/admin/inventory/low-stock-alerts/${alert.id}/threshold", JsonObject(body))
        env.toasts.show("Threshold updated"); onSaved()
    }) { error ->
        Field("Reorder point", point, { point = it }, error = error?.field("reorder_point"), keyboard = KeyboardType.Number)
        Field("Reorder quantity (optional)", qty, { qty = it }, error = error?.field("reorder_quantity"), keyboard = KeyboardType.Number)
    }
}
