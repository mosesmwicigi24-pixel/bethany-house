package ke.co.bethanyhouse.hub.ui.features.sales

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
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
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material3.Button
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.components.DecimalField
import ke.co.bethanyhouse.hub.ui.components.DetailRow
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive

// ── Order items: list, price adjustment, line editing, send to production ──

/**
 * The lines of an order. Figures are the stored ones (unit_price,
 * discount_amount, tax_amount, total_price); after an edit the server's
 * response is the only source of what they became.
 *
 * - Adjust price: PATCH /orders/{id}/items/{i}/price {unit_price}
 *   (orders.edit; only while payment_status is pending — the server's rule).
 * - Edit lines: PUT /orders/{id}/items {items[], reason?, confirm_paid_change?,
 *   confirm_shipped_change?} (orders.edit_items). A 422 whose `reason` is
 *   confirm_paid_change / confirm_shipped_change / reason_required is a
 *   question, answered by re-sending with the flag or the reason.
 * - Send to production: POST /orders/{id}/items/{i}/production
 *   (production.raise_order; money received; line is_producible; not yet raised).
 */
@Composable
internal fun OrderItemsSection(order: Record, reload: () -> Unit, onProduction: (Record) -> Unit) {
    val env = LocalEnv.current
    var adjusting by remember { mutableStateOf<Record?>(null) }
    var editing by remember { mutableStateOf(false) }
    val items = order["items"].toRecords()
    val currency = order.string("currency_code") ?: "KES"
    val dead = Sales.isDead(order.status)
    val paymentReceived = (order.string("payment_status") ?: "") in listOf("paid", "partial", "deposit")

    SectionCard("Items") {
        if (editing) {
            OrderLineEditor(order, onDone = { editing = false; reload() }, onCancel = { editing = false })
        } else {
            items.forEach { item ->
                val canAdjust = env.session.current.can("orders.edit") && order.string("payment_status") == "pending" && !dead
                val canRaise = env.session.current.can("production.raise_order") && paymentReceived && !dead && item["is_producible"].bool == true && item["production_order_id"].isNull
                Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top) {
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Text(item.string("product_name") ?: "Item", style = MaterialTheme.typography.bodyMedium)
                            val sub = listOfNotNull(item.string("variant_name")?.takeIf { it.isNotEmpty() }, item.string("sku")).joinToString(" · ")
                            if (sub.isNotEmpty()) SalesCaption(sub)
                            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                SalesCaption("${item.string("quantity") ?: "1"} × ${item.money("unit_price", currency)?.formatted() ?: "—"}", MaterialTheme.hub.text)
                                if (item["price_adjusted"].bool == true) item.money("original_price", currency)?.let { orig ->
                                    Text("was ${orig.formatted()}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary, textDecoration = TextDecoration.LineThrough)
                                }
                                item.money("discount_amount", currency)?.takeIf { !it.isZero }?.let { SalesCaption("− ${it.formatted()} discount", Palette.pink) }
                                item["tax_rate"].str?.let { SalesCaption("tax $it%", MaterialTheme.hub.textTertiary) }
                            }
                            item.int("production_order_id")?.let { poId ->
                                TextButton(onClick = { env.navigator.push(Route.ProductionOrder(poId)) }, contentPadding = androidx.compose.foundation.layout.PaddingValues(0.dp)) { Text("In production", style = MaterialTheme.typography.labelSmall) }
                            }
                        }
                        MoneyText(item.money("total_price", currency) ?: item.money("subtotal", currency))
                    }
                    if (canAdjust || canRaise) Row(horizontalArrangement = Arrangement.spacedBy(Space.m)) {
                        if (canAdjust) TextButton(onClick = { adjusting = item }) { Text("Adjust price") }
                        if (canRaise) TextButton(onClick = { onProduction(item) }) { Text("MTO · Send to production") }
                    }
                    SalesDivider()
                }
            }
            if (order["prices_include_tax"].bool == true) SalesCaption("Prices include tax.", MaterialTheme.hub.textTertiary)
            order["tax_breakdown"].records.forEach { b ->
                DetailRow("${b["label"].str ?: "Tax"} @ ${b["rate"].str ?: ""}%") { MoneyText(Money.from(b["amount"], currency)) }
            }
            if (env.session.current.can("orders.edit_items") && !dead) OutlinedButton(onClick = { editing = true }) { Text("Edit lines") }
        }
    }
    adjusting?.let { item -> AdjustPriceSheet(order, item, onDone = reload, onDismiss = { adjusting = null }) }
}

/** PATCH /orders/{id}/items/{i}/price {unit_price} — upwards only, no payments yet. */
@Composable
private fun AdjustPriceSheet(order: Record, item: Record, onDone: () -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var price by remember { mutableStateOf(item["unit_price"].str ?: "") }
    FormSheet("Adjust unit price", onDismiss, saveLabel = "Update price", canSave = Sales.amountJson(price) != null, save = {
        val p = Sales.amountJson(price) ?: return@FormSheet
        env.client.patch("/v1/admin/orders/${order.id}/items/${item.id}/price", JsonObject(mapOf("unit_price" to p)))
        env.toasts.show("Price updated")
        onDone()
    }) { error ->
        DetailRow("Line", "${item.string("product_name") ?: ""} × ${item.string("quantity") ?: ""}")
        DetailRow("Current unit price") { MoneyText(item.money("unit_price", order.string("currency_code"))) }
        SalesCaption("The catalogue price is kept as original_price for the audit trail. Only while no payment has been recorded.")
        DecimalField("New unit price (${order.string("currency_code") ?: "KES"})", price, { price = it }, error = error?.field("unit_price"))
        SalesFieldErrors(error)
    }
}

// ── Line editor — PUT /orders/{id}/items ────────────────────────────────────

/** A staged line: existing lines keep their record id; new ones carry product + variant. */
internal data class SalesLine(
    val id: Long = System.nanoTime(),
    val recordId: String? = null,
    val productId: String? = null,
    val variantId: String? = null,
    val name: String = "",
    val sku: String? = null,
    val quantity: String = "1",
    val unitPrice: String = "",
    val discount: String = "0",
    /** Already in production: quantity cannot change. */
    val locked: Boolean = false,
) {
    companion object {
        fun from(r: Record) = SalesLine(
            recordId = r.id, productId = r["product_id"].str, variantId = r["product_variant_id"].str ?: r["variant_id"].str,
            name = listOfNotNull(r.string("product_name"), r.string("variant_name")).filter { it.isNotEmpty() }.joinToString(" · "),
            sku = r.string("sku"), quantity = r["quantity"].str ?: "1", unitPrice = r["unit_price"].str ?: "",
            discount = r["discount_amount"].str ?: "0", locked = !r["production_order_id"].isNull,
        )
    }
}

/**
 * Staged, never optimistic: nothing is sent until Update, and no figure is
 * computed here. Existing lines keep their `id`; new lines carry
 * product_id + product_variant_id; a removed line is simply omitted.
 */
@Composable
private fun OrderLineEditor(order: Record, onDone: () -> Unit, onCancel: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var lines by remember { mutableStateOf(order["items"].toRecords().map(SalesLine::from)) }
    var reason by remember { mutableStateOf("") }
    var adding by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var question by remember { mutableStateOf<Pair<String, String>?>(null) }
    var confirmedPaid by remember { mutableStateOf(false) }
    var confirmedShipped by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val currency = order.string("currency_code") ?: "KES"

    suspend fun submit(answering: String?) {
        if (answering == "confirm_paid_change") confirmedPaid = true
        if (answering == "confirm_shipped_change") confirmedShipped = true
        question = null; error = null; busy = true
        try {
            val items = JsonArray(lines.map { l ->
                val o = mutableMapOf<String, JsonElement>("quantity" to JsonPrimitive(l.quantity.trim().toIntOrNull() ?: 1))
                if (l.recordId != null) o["id"] = JsonPrimitive(l.recordId) else {
                    l.productId?.let { o["product_id"] = JsonPrimitive(it) }
                    o["product_variant_id"] = l.variantId?.let { JsonPrimitive(it) } ?: JsonNull
                }
                Sales.amountJson(l.unitPrice)?.let { o["unit_price"] = it }
                Sales.amountJson(l.discount)?.let { o["discount_amount"] = it }
                JsonObject(o)
            })
            val body = mutableMapOf<String, JsonElement>("items" to items)
            if (reason.isNotBlank()) body["reason"] = JsonPrimitive(reason.trim())
            if (confirmedPaid) body["confirm_paid_change"] = JsonPrimitive(true)
            if (confirmedShipped) body["confirm_shipped_change"] = JsonPrimitive(true)
            val r = env.client.put("/v1/admin/orders/${order.id}/items", JsonObject(body))
            var msg = r.json["message"].str ?: "Lines updated"
            Money.from(r.json["overpaid"], currency)?.takeIf { !it.isZero }?.let { msg += " — overpaid by ${it.formatted()}" }
            env.toasts.show(msg)
            onDone()
        } catch (e: ApiError) {
            if ((e.reason ?: "") in listOf("confirm_paid_change", "confirm_shipped_change", "reason_required")) question = (e.reason ?: "") to e.message
            else error = e.firstFieldError ?: e.message
        } catch (e: Exception) { error = e.message } finally { busy = false }
    }

    Column(verticalArrangement = Arrangement.spacedBy(Space.m)) {
        SalesCaption("Send the lines you want to end up with; subtotal, tax, total, payment status and stock are recomputed by the server.")
        lines.forEach { line ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Text(line.name, style = MaterialTheme.typography.bodyMedium)
                        line.sku?.let { SalesCaption(it, MaterialTheme.hub.textTertiary) }
                        if (line.locked) SalesCaption("In production — quantity locked", Palette.amber)
                    }
                    IconButton(enabled = !line.locked, onClick = { lines = lines.filterNot { it.id == line.id } }) { Icon(Icons.Default.Delete, contentDescription = "Remove", tint = Palette.danger) }
                }
                Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    DecimalField("Qty", line.quantity, { v -> lines = lines.map { if (it.id == line.id) it.copy(quantity = v) else it } }, Modifier.weight(0.7f), enabled = !line.locked)
                    DecimalField("Unit price", line.unitPrice, { v -> lines = lines.map { if (it.id == line.id) it.copy(unitPrice = v) else it } }, Modifier.weight(1.2f))
                    DecimalField("Discount", line.discount, { v -> lines = lines.map { if (it.id == line.id) it.copy(discount = v) else it } }, Modifier.weight(1f))
                }
                SalesDivider()
            }
        }
        if (lines.isEmpty()) SalesCaption("An order needs at least one line.", Palette.danger)
        TextButton(onClick = { adding = true }) { Text("+ Add item") }
        env.session.current.me?.discountCapPercent?.let { cap ->
            SalesCaption("Your discount limit is ${cap}% of a line; the server enforces it (and measures a lowered unit price as well).", MaterialTheme.hub.textTertiary)
        }
        Field("Reason (required once money or goods have moved)", reason, { reason = it })
        question?.let { (qReason, message) ->
            Column(Modifier.fillMaxWidth().background(Palette.amberSoft, MaterialTheme.shapes.small).padding(Space.m), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                SalesSecondary(message, Palette.amber)
                Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    TextButton(onClick = { question = null }) { Text("Go back") }
                    Button(enabled = !(qReason == "reason_required" && reason.isBlank()), onClick = { scope.launch { submit(qReason) } }) {
                        Text(if (qReason == "reason_required") "Send with reason" else "Yes, apply the change")
                    }
                }
            }
        }
        error?.let { SalesSecondary(it, Palette.danger) }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Spacer(Modifier.weight(1f))
            TextButton(onClick = onCancel) { Text("Cancel") }
            Button(enabled = !busy && lines.isNotEmpty(), onClick = { scope.launch { submit(null) } }) { Text("Update lines") }
        }
    }
    if (adding) ProductPickerSheet(currency, onPicked = { lines = lines + it; adding = false }, onDismiss = { adding = false })
}

/**
 * Admin catalogue search (GET /products?search=&per_page=20, products.view),
 * then GET /products/{id} for a variable product's variants. The unit price
 * is left to the server unless the person types one.
 */
@Composable
internal fun ProductPickerSheet(currency: String, onPicked: (SalesLine) -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var search by remember { mutableStateOf("") }
    var results by remember { mutableStateOf<List<Record>>(emptyList()) }
    var picked by remember { mutableStateOf<Record?>(null) }
    var variants by remember { mutableStateOf<List<Record>>(emptyList()) }
    var variantId by remember { mutableStateOf("") }
    var quantity by remember { mutableStateOf("1") }
    var price by remember { mutableStateOf("") }
    val needsVariant = (picked?.int("variants_count") ?: 0) > 0
    val ready = picked != null && (!needsVariant || variantId.isNotEmpty()) && (quantity.toIntOrNull() ?: 0) > 0

    fun find() {
        val term = search.trim()
        if (term.length < 2) { results = emptyList(); return }
        scope.launch {
            try { results = env.client.get("/v1/admin/products", mapOf("search" to term, "per_page" to "20")).json.salesRecords } catch (e: Exception) { env.toasts.error(e) }
        }
    }

    fun pick(p: Record) {
        picked = p; variantId = ""; variants = emptyList()
        if ((p.int("variants_count") ?: 0) <= 0) return
        scope.launch {
            try { variants = env.client.get("/v1/admin/products/${p.id}").json["product"]["variants"].toRecords().filter { it["is_active"].bool != false } } catch (e: Exception) { env.toasts.error(e) }
        }
    }

    FormSheet("Add item", onDismiss, saveLabel = "Add line", canSave = ready, save = {
        val p = picked ?: return@FormSheet
        val v = variants.firstOrNull { it.id == variantId }
        onPicked(SalesLine(
            productId = p.id, variantId = v?.id,
            name = listOfNotNull(p["en_translation"]["name"].str ?: p.string("sku") ?: "Product", v?.string("variant_name")).joinToString(" · "),
            sku = v?.string("sku") ?: p.string("sku"), quantity = quantity, unitPrice = price, discount = "0",
        ))
    }) { _ ->
        ModuleSearchField(search, { search = it }, "Search the catalogue", onSubmit = ::find)
        results.forEach { p ->
            Row(Modifier.fillMaxWidth().clickable { pick(p) }.padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(p["en_translation"]["name"].str ?: p.string("sku") ?: "", style = MaterialTheme.typography.bodyMedium)
                    val vc = p.int("variants_count") ?: 0
                    SalesCaption("${p.string("sku") ?: ""}${if (vc > 0) " · $vc variants" else ""}")
                }
                val bp = p["base_price"]
                Money.from(bp["sale_price"] ?: bp["regular_price"], "KES")?.let { SalesCaption(it.formatted(), MaterialTheme.hub.textTertiary) }
                if (picked?.id == p.id) { Spacer(Modifier.width(6.dp)); Icon(Icons.Default.CheckCircle, contentDescription = "Selected", tint = Palette.brand, modifier = Modifier.size(18.dp)) }
            }
        }
        if (needsVariant) OptionPicker("Variant", variantId, variants.map { it.id to "${it.string("variant_name") ?: ""} · ${it.string("sku") ?: ""}" }, { variantId = it })
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            DecimalField("Quantity", quantity, { quantity = it }, Modifier.weight(0.6f))
            DecimalField("Unit price ($currency, blank = catalogue)", price, { price = it }, Modifier.weight(1.4f))
        }
    }
}
