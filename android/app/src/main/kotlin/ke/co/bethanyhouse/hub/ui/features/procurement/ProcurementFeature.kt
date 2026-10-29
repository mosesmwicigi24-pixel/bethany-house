package ke.co.bethanyhouse.hub.ui.features.procurement

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
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
import ke.co.bethanyhouse.hub.core.Money
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
import ke.co.bethanyhouse.hub.core.toJson
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DayField
import ke.co.bethanyhouse.hub.ui.components.formatDate
import ke.co.bethanyhouse.hub.ui.components.DecimalField
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FieldBox
import ke.co.bethanyhouse.hub.ui.components.FieldError
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.InfoSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.Lookup
import ke.co.bethanyhouse.hub.ui.components.LookupPicker
import ke.co.bethanyhouse.hub.ui.components.ModuleRowCard
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.PagedList
import ke.co.bethanyhouse.hub.ui.components.RemoteJson
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.StatTile
import ke.co.bethanyhouse.hub.ui.components.StatsRow
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.canViewCost
import ke.co.bethanyhouse.hub.ui.hub
import ke.co.bethanyhouse.hub.ui.shell.FeatureRegistry
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonPrimitive
import java.math.BigDecimal

/**
 * Procurement: Purchase Orders (list + detail), Suppliers, Goods Receipt
 * (GRN list + detail) and Purchase Returns (list + detail). Endpoints
 * mirror backend/routes/api.php (suppliers, purchase-orders, grn,
 * purchase-returns, pdf) — the same ones ProcurementFeature.swift calls.
 */
object ProcurementFeature {
    fun register() {
        FeatureRegistry.register(Route.PurchaseOrders) { PurchaseOrdersScreen() }
        FeatureRegistry.registerType<Route.PurchaseOrder> { r -> PurchaseOrderDetailScreen(r.id) }
        FeatureRegistry.register(Route.Suppliers) { SuppliersScreen() }
        FeatureRegistry.register(Route.GoodsReceipt) { GoodsReceiptScreen() }
        FeatureRegistry.registerType<Route.Grn> { r -> GrnDetailScreen(r.id) }
        FeatureRegistry.register(Route.PurchaseReturns) { PurchaseReturnsScreen() }
        FeatureRegistry.registerType<Route.PurchaseReturn> { r -> PurchaseReturnDetailScreen(r.id) }
    }
}

// ── List (GET /v1/admin/purchase-orders → Laravel paginator) + statistics ────

@Composable
fun PurchaseOrdersScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val s = env.session.current
    val list = remember { PagedList(scope, env.client, "/v1/admin/purchase-orders", map = ::Record, perPage = 20) }
    val stats = remember { Resource(scope) { env.client.get("/v1/admin/purchase-orders/statistics").json ?: JsonNull } }
    val suppliers = remember { Lookup.suppliers(env.client) }
    var search by remember { mutableStateOf("") }
    var status by remember { mutableStateOf("") }
    var supplierId by remember { mutableStateOf<Int?>(null) }
    var showCreate by remember { mutableStateOf(false) }
    var showPayables by remember { mutableStateOf(false) }

    fun apply() {
        list.query = mutableMapOf("search" to search.ifEmpty { null }, "status" to status.ifEmpty { null }, "supplier_id" to supplierId?.toString())
        list.load()
    }
    LaunchedEffect(status, supplierId) { apply() }
    LaunchedEffect(Unit) { stats.load() }

    Page("Purchase Orders", subtitle = "What has been ordered from suppliers, and where each order stands", refresh = { list.reload(); stats.reload() }) {
        LoadableView(stats.state, retry = { stats.load() }) { st ->
            StatsRow(listOf(
                Triple("Awaiting approval", st["pending_approval"].str ?: "0", if ((st["pending_approval"].int ?: 0) > 0) StatusTone.AMBER else null),
                Triple("Ordered", st["ordered"].str ?: "0", StatusTone.INFO),
                Triple("Partly received", st["partially_received"].str ?: "0", StatusTone.PINK),
                Triple("This month (KES)", st["total_value"].money?.formatted() ?: "—", null),
                Triple("Outstanding (KES)", st["outstanding_value"].money?.formatted() ?: "—", null),
            ))
            ProcCaption("KES totals use the reporting rate; orders in a currency with no rate are left out.")
        }
        ModuleSearchField(search, { search = it }, "PO number", onSubmit = { apply() })
        OptionPicker("Status", status, POStatus.all.map { it to it.humanized }, { status = it }, allowNone = true)
        LookupPicker("Supplier", suppliers, supplierId, { supplierId = it })
        FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
            if (s.can("procurement.pay") || s.can("reports.financial")) OutlinedButton(onClick = { showPayables = true }) { Text("Payables") }
            if (s.can("procurement.create")) Button(onClick = { showCreate = true }) { Text("New purchase order") }
        }
        LoadableView(list.state, retry = { list.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("No purchase orders match") }) { items ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                items.forEachIndexed { i, r ->
                    val po = PurchaseOrderRecord(r)
                    ModuleRowCard(onClick = { env.navigator.push(Route.PurchaseOrder(po.id)) }) {
                        Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                            Text(po.number, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                            StatusBadge(po.status)
                            if (po.revision > 0) StatusBadge("rev ${po.revision}", StatusTone.ACCENT)
                        }
                        Text(po.supplierName, style = MaterialTheme.typography.bodySmall)
                        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                            val n = po.items.size
                            Text("$n line${if (n == 1) "" else "s"} · expected ${formatDate(po.expected, DateStyle.DATE)}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, modifier = Modifier.weight(1f))
                            MoneyText(po.total, emphasis = true)
                        }
                    }
                    if (i == items.lastIndex && list.hasMore) LaunchedEffect(items.size) { list.loadMore() }
                }
            }
        }
    }

    if (showCreate) PurchaseOrderCreateSheet(onDismiss = { showCreate = false }) { list.load(); stats.load() }
    if (showPayables) PayablesSheet { showPayables = false }
}

/** GET /v1/admin/purchase-orders/payables → {data: [{supplier, currency, orders, payable, paid, balance, balance_kes}], total_owed_kes, unconverted, basis} */
@Composable
private fun PayablesSheet(onDismiss: () -> Unit) {
    InfoSheet("Owed to suppliers", onDismiss) {
        RemoteJson("/v1/admin/purchase-orders/payables") { j, _ ->
            StatTile("Total owed (KES)", j["total_owed_kes"].money?.formatted() ?: "—", hint = "at today's reporting rate")
            val rows = j["data"].records
            if (rows.isEmpty()) Text("Nothing outstanding.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textTertiary)
            rows.forEach { p ->
                val cur = p["currency"].str ?: "KES"
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Text(p["supplier"].str ?: "", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                        Text("${p["orders"].int ?: 0} order(s) · payable ${Money.from(p["payable"], cur)?.formatted() ?: "—"} · paid ${Money.from(p["paid"], cur)?.formatted() ?: "—"}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    }
                    Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        MoneyText(Money.from(p["balance"], cur), emphasis = true)
                        val k = p["balance_kes"]
                        if (!k.isNull && cur != "KES") Text("= ${Money.from(k, "KES")?.formatted() ?: ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        else if (cur != "KES") Text("no reporting rate", style = MaterialTheme.typography.labelSmall, color = Palette.warning)
                    }
                }
                HorizontalDivider(color = MaterialTheme.hub.line)
            }
            j["basis"].str?.let { ProcCaption(it) }
        }
    }
}

// ── Create (POST /v1/admin/purchase-orders) ──────────────────────────────────

internal data class POLine(val id: Long = System.nanoTime(), val type: String, val itemId: Int, val variantId: Int?, val title: String, val qty: String, val price: String, val costMissing: Boolean)

/**
 * Line items carry the person's unit price. For a product the form asks
 * GET …/purchase-orders/item-costs/{p}?currency= for the book cost in the
 * PO's currency (null when there is no cost, no rate, or the viewer may
 * not see cost) and pre-fills it; the "all variants" grid is one line per
 * variant with a quantity each.
 */
@Composable
private fun PurchaseOrderCreateSheet(onDismiss: () -> Unit, onCreated: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val suppliers = remember { Lookup.suppliers(env.client) }
    val products = remember { Lookup.products(env.client) }
    val materials = remember { Lookup.materials(env.client) }
    var currencies by remember { mutableStateOf<List<Record>>(emptyList()) }
    var supplierId by remember { mutableStateOf<Int?>(null) }
    var currency by remember { mutableStateOf("KES") }
    var expected by remember { mutableStateOf(Day.add(Day.today(), 14)) }
    var shipping by remember { mutableStateOf("") }
    var tax by remember { mutableStateOf("") }
    var terms by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    val lines = remember { mutableStateListOf<POLine>() }
    var addingProduct by remember { mutableStateOf<Int?>(null) }
    var addingMaterial by remember { mutableStateOf<Int?>(null) }
    var grid by remember { mutableStateOf<JsonElement?>(null) }
    val gridQty = remember { mutableStateMapOf<Int, String>() }
    val gridPrice = remember { mutableStateMapOf<Int, String>() }
    var adding by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) {
        products.load(); materials.load()
        currencies = runCatching { env.client.get("/v1/admin/purchase-orders/currencies").json["data"].toRecords() }.getOrDefault(emptyList())
    }

    val valid = supplierId != null && lines.isNotEmpty() &&
        lines.all { (procDecimal(it.qty) ?: BigDecimal.ZERO) > BigDecimal.ZERO && procDecimal(it.price) != null } &&
        (currencies.firstOrNull { it.string("code") == currency }?.get("allowed").bool ?: true)

    suspend fun addProduct(allVariants: Boolean) {
        val pid = addingProduct ?: return
        adding = true
        try {
            val j = runCatching { env.client.get("/v1/admin/purchase-orders/item-costs/$pid", mapOf("currency" to currency)).json }.getOrNull()
            if (allVariants && j != null && j["variants"].records.isNotEmpty()) { grid = j; return }
            val p = j["product"]
            val title = p["name"].str ?: products.options.firstOrNull { it.intId == pid }?.let(Lookup::title) ?: "Product $pid"
            lines += POLine(type = "product", itemId = pid, variantId = null, title = title, qty = "1", price = p["unit_price"].str ?: "", costMissing = p["unit_price"].isNull)
            addingProduct = null
        } finally { adding = false }
    }

    fun addMaterial() {
        val mid = addingMaterial ?: return
        val m = materials.options.firstOrNull { it.intId == mid } ?: return
        // A material's book cost is KES; in another currency the person types the agreed price.
        val kes = if (env.canViewCost && currency == "KES") (m["unit_cost"].str ?: "") else ""
        lines += POLine(type = "material", itemId = mid, variantId = null, title = "${m.string("name") ?: ""} (${m.string("unit_of_measure") ?: ""})", qty = "1", price = kes, costMissing = kes.isEmpty())
        addingMaterial = null
    }

    FormSheet("New purchase order", onDismiss, saveLabel = "Create draft", canSave = valid, save = {
        val items = lines.map { l ->
            J.obj(
                "type" to JsonPrimitive(l.type), "item_id" to JsonPrimitive(l.itemId),
                "quantity" to (J.decimal(l.qty) ?: JsonPrimitive(0)), "unit_price" to (J.decimal(l.price) ?: JsonPrimitive(0)),
                "product_variant_id" to l.variantId?.let { JsonPrimitive(it) },
            )
        }
        val body = J.obj(
            "supplier_id" to JsonPrimitive(supplierId ?: 0), "expected_delivery_date" to JsonPrimitive(expected), "currency" to JsonPrimitive(currency),
            "items" to toJson(items), "notes" to J.s(notes), "payment_terms" to J.s(terms),
            "shipping_cost" to J.decimal(shipping), "tax" to J.decimal(tax),
        )
        val r = env.client.post("/v1/admin/purchase-orders", body)
        env.toasts.show(r.json["message"].str ?: "Purchase order created")
        onCreated()
        r.json["purchase_order"]["id"].int?.let { env.navigator.push(Route.PurchaseOrder(it)) }
    }) { error ->
        LookupPicker("Supplier", suppliers, supplierId, { supplierId = it }, allowNone = false, error = error?.field("supplier_id"))
        OptionPicker("Currency", currency, currencies.map { c -> val code = c.string("code") ?: ""; code to (if (c["allowed"].bool == true) code else "$code (no reporting rate)") }.ifEmpty { listOf("KES" to "KES") }, { if (it.isNotEmpty() && it != currency) { currency = it; lines.clear(); grid = null } }, error = error?.field("currency"))
        DayField("Expected delivery", expected, { expected = it })
        FieldError(error?.field("expected_delivery_date"))
        FieldBox("Add a product") {
            LookupPicker("Product", products, addingProduct, { addingProduct = it })
            Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                OutlinedButton(enabled = addingProduct != null && !adding, onClick = { scope.launch { addProduct(false) } }) { Text("Add") }
                OutlinedButton(enabled = addingProduct != null && !adding, onClick = { scope.launch { addProduct(true) } }) { Text("All variants…") }
            }
        }
        grid?.let { g ->
            val variants = g["variants"].records
            Column(Modifier.fillMaxWidth().background(MaterialTheme.hub.field, MaterialTheme.shapes.small).padding(Space.m), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text("${g["product"]["name"].str ?: ""} — variants", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                    TextButton(onClick = { grid = null }) { Text("Close grid") }
                }
                if (g["rate_missing"].bool == true) ProcCaption("No reporting rate for $currency: prices must be typed.", Palette.warning)
                variants.forEach { v ->
                    val vid = v["id"].int ?: 0
                    Text(v["variant_name"].str ?: v["sku"].str ?: "", style = MaterialTheme.typography.bodySmall)
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                        DecimalField("Qty", gridQty[vid] ?: "", { gridQty[vid] = it }, Modifier.weight(1f))
                        DecimalField(if (!v["unit_price"].isNull) "Price" else "No cost — enter", gridPrice[vid] ?: v["unit_price"].str ?: "", { gridPrice[vid] = it }, Modifier.weight(1.4f))
                    }
                }
                OutlinedButton(onClick = {
                    variants.forEach { v ->
                        val vid = v["id"].int ?: 0
                        val q = gridQty[vid] ?: return@forEach
                        if ((procDecimal(q) ?: BigDecimal.ZERO) <= BigDecimal.ZERO) return@forEach
                        lines += POLine(type = "product", itemId = g["product"]["id"].int ?: 0, variantId = vid, title = "${g["product"]["name"].str ?: ""} · ${v["variant_name"].str ?: v["sku"].str ?: ""}", qty = q, price = gridPrice[vid] ?: v["unit_price"].str ?: "", costMissing = v["unit_price"].isNull)
                    }
                    grid = null; gridQty.clear(); gridPrice.clear()
                }) { Text("Add the variants with a quantity") }
            }
        }
        FieldBox("Add a material") {
            LookupPicker("Material", materials, addingMaterial, { addingMaterial = it })
            OutlinedButton(enabled = addingMaterial != null, onClick = { addMaterial() }) { Text("Add") }
        }
        if (lines.isNotEmpty()) {
            Text("LINES ($currency)", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            lines.forEachIndexed { i, l ->
                ProcLineEditor(l.title, note = if (l.costMissing) "No book cost — enter the agreed price" else null, qty = l.qty, onQty = { v -> lines[i] = l.copy(qty = v) }, price = l.price, onPrice = { v -> lines[i] = l.copy(price = v) }) { lines.removeAll { it.id == l.id } }
            }
            FieldError(error?.errors?.entries?.firstOrNull { it.key.startsWith("items") }?.value?.firstOrNull())
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            DecimalField("Shipping ($currency)", shipping, { shipping = it }, Modifier.weight(1f))
            DecimalField("Tax ($currency)", tax, { tax = it }, Modifier.weight(1f))
        }
        Field("Payment terms", terms, { terms = it }, placeholder = "e.g. 30 days")
        Field("Notes", notes, { notes = it }, multiline = true)
        ProcCaption("Totals are computed by the server when the draft is saved.")
    }
}

