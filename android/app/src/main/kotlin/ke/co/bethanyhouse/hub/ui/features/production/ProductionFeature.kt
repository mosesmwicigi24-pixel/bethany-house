package ke.co.bethanyhouse.hub.ui.features.production

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Build
import androidx.compose.material.icons.filled.Inbox
import androidx.compose.material3.Button
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshots.SnapshotStateList
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Paginated
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
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
import ke.co.bethanyhouse.hub.ui.components.DayField
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FieldBox
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.KeyValueEditor
import ke.co.bethanyhouse.hub.ui.components.KeyValueRow
import ke.co.bethanyhouse.hub.ui.components.Loadable
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.Lookup
import ke.co.bethanyhouse.hub.ui.components.LookupPicker
import ke.co.bethanyhouse.hub.ui.components.ModuleRowCard
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.OptionPickerValues
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.PriorityBadge
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.StatsRow
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.hub
import ke.co.bethanyhouse.hub.ui.shell.FeatureRegistry
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonPrimitive
import java.time.Instant

/**
 * Production (My Tasks, Production Orders + detail, WIP, QC, Calendar, BOM)
 * and Inventory (Stock Levels, Serials, Adjustments + detail, Transfers +
 * detail, Raw Materials, Low Stock). Endpoints and gates mirror
 * backend/routes/api.php and the Apple ProductionFeature / InventoryFeature.
 */
object ProductionFeature {
    fun register() {
        FeatureRegistry.register(Route.ProductionMyTasks) { MyTasksScreen() }
        FeatureRegistry.register(Route.ProductionOrders) { ProductionOrdersScreen() }
        FeatureRegistry.registerType<Route.ProductionOrder> { r -> ProductionOrderDetailScreen(r.id) }
        FeatureRegistry.register(Route.Wip) { WipBoardScreen() }
        FeatureRegistry.register(Route.Qc) { QcScreen() }
        FeatureRegistry.register(Route.ProductionCalendar) { ProductionCalendarScreen() }
        FeatureRegistry.register(Route.Bom) { BomScreen() }
        FeatureRegistry.register(Route.StockLevels) { StockLevelsScreen() }
        FeatureRegistry.register(Route.Serials) { SerialsScreen() }
        FeatureRegistry.register(Route.Adjustments) { AdjustmentsScreen() }
        FeatureRegistry.registerType<Route.Adjustment> { r -> AdjustmentDetailScreen(r.id) }
        FeatureRegistry.register(Route.Transfers) { TransfersScreen() }
        FeatureRegistry.registerType<Route.Transfer> { r -> TransferDetailScreen(r.id) }
        FeatureRegistry.register(Route.Materials) { MaterialsScreen() }
        FeatureRegistry.register(Route.LowStock) { LowStockScreen() }
    }
}

/** The production order state machine (ProductionOrder::FLOOR_WORK_STATUSES and ProductionController guards). */
internal object ProductionStatus {
    val all = listOf("draft", "pending", "in_progress", "on_hold", "qc_pending", "qc_passed", "qc_failed", "completed", "cancelled")
    val floorWork = setOf("pending", "in_progress", "on_hold")
    val cancellable = setOf("draft", "pending", "on_hold", "qc_failed")
    val editable = setOf("draft", "pending", "in_progress", "on_hold", "qc_pending", "qc_passed", "qc_failed")
    val board = listOf("pending", "in_progress", "on_hold", "qc_pending", "qc_passed", "qc_failed")
    val priorities = listOf("low", "normal", "high", "urgent")
    fun acceptsFloorWork(s: String?) = floorWork.contains(s ?: "")
}

/** Typed reads over a production order payload (ProductionController index/show). */
internal class ProductionOrderRecord(val r: Record) {
    val id: Int get() = r.int("id") ?: 0
    val number: String get() = r.string("order_number") ?: "PRD-$id"
    val status: String? get() = r.status
    val priority: String? get() = r.string("priority")
    val quantity: Int get() = r.int("quantity") ?: 0
    val productName: String get() = r.string("product_name") ?: r["product"]["translations"][0]["name"].str ?: r["product"]["sku"].str ?: "Product"
    val productSku: String? get() = r["product"]["sku"].str
    val image: String? get() = r.string("product_image") ?: r["product"]["images"][0]["image_url"].str
    val dueDate: Instant? get() = r.date("due_date")
    val customerLabel: String? get() = r.string("customer_label")
    val isCustomerJob: Boolean get() = r["is_customer_order"].bool ?: (r["customer_order_id"].int != null)
    val percent: Int get() = r["progress"]["percent"].int ?: r.int("completion_percentage") ?: 0
    val currentStage: String? get() = r.string("current_stage")
    val tasks: List<JsonElement> get() = r["tasks"].records
    val batches: List<JsonElement> get() = r["batches"].records
    val allocations: List<JsonElement> get() = r["material_allocations"].records
    val isOverdue: Boolean
        get() {
            val d = dueDate ?: return false
            val s = status ?: return false
            return d.isBefore(Instant.now()) && s !in listOf("completed", "cancelled", "draft")
        }
}

// ── A paged list that keeps the envelope (stats, reason_codes, summary) ──────

/**
 * Like the kit's PagedList, but keeps the page envelope (`stats`,
 * `reason_codes`, `summary`, `aged_count`) the production and inventory
 * index endpoints send beside `data`/`meta`.
 */
internal class ProductionRecordList(
    private val scope: CoroutineScope,
    private val client: ApiClient,
    private val path: String,
    var query: MutableMap<String, String?> = mutableMapOf(),
    var perPage: Int = 25,
) {
    val items: SnapshotStateList<Record> = mutableStateListOf()
    var state: Loadable<List<Record>> by mutableStateOf(Loadable.Idle)
        private set
    var extras: JsonElement? by mutableStateOf(null)
        private set
    var isLoadingMore: Boolean by mutableStateOf(false)
        private set
    private var nextPage: Int? = null
    private var generation = 0
    val hasMore: Boolean get() = nextPage != null

    fun load() { scope.launch { reload() } }

    suspend fun reload() {
        val gen = ++generation
        state = Loadable.Loading(items.toList().takeIf { it.isNotEmpty() })
        try {
            val (page, json) = fetchPage(1)
            if (gen != generation) return
            items.clear(); items.addAll(page.data)
            nextPage = page.nextPage
            extras = json
            state = Loadable.Loaded(items.toList())
        } catch (e: ApiError) {
            if (gen != generation) return
            state = Loadable.Failed(e, items.toList().takeIf { it.isNotEmpty() })
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            if (gen != generation) return
            state = Loadable.Failed(ApiError.transport(e.message ?: "Error"), items.toList().takeIf { it.isNotEmpty() })
        }
    }

    fun loadMore() {
        val page = nextPage ?: return
        if (isLoadingMore) return
        isLoadingMore = true
        scope.launch {
            try {
                val (p, _) = fetchPage(page)
                items.addAll(p.data); nextPage = p.nextPage
                state = Loadable.Loaded(items.toList())
            } catch (_: ApiError) {
            } finally { isLoadingMore = false }
        }
    }

    private suspend fun fetchPage(page: Int): Pair<Paginated<Record>, JsonElement?> {
        val q = query.entries.mapNotNull { (k, v) -> v?.let { k to it } } + listOf("page" to page.toString(), "per_page" to perPage.toString())
        val json = client.get(path, q).json
        return Paginated.from(json, ::Record) to json
    }
}

/** Renders a [ProductionRecordList]: skeleton, error, empty, rows, and loads the next page at the last row. */
@Composable
internal fun ProductionListView(list: ProductionRecordList, emptyTitle: String, icon: ImageVector = Icons.Default.Inbox, row: @Composable (Record) -> Unit) {
    LoadableView(list.state, retry = { list.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState(emptyTitle, icon = icon) }) { items ->
        Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
            items.forEachIndexed { i, r ->
                row(r)
                if (i == items.lastIndex && list.hasMore) LaunchedEffect(items.size) { list.loadMore() }
            }
        }
    }
}

/** A string, or JSON null when nil (Swift `.int(Int?)` / `.str`). */
internal fun jInt(v: Int?): JsonElement = v?.let { JsonPrimitive(it) } ?: JsonNull
internal fun jStr(v: String?): JsonElement = if (v.isNullOrEmpty()) JsonNull else JsonPrimitive(v)

// ── Orders list (GET /v1/admin/production-orders → {data, meta, stats}) ──────

@Composable
internal fun ProductionOrdersScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val list = remember { ProductionRecordList(scope, env.client, "/v1/admin/production-orders", perPage = 20) }
    var search by remember { mutableStateOf("") }
    var status by remember { mutableStateOf("") }
    var type by remember { mutableStateOf("") }
    var showCreate by remember { mutableStateOf(false) }

    fun reload() {
        list.query = mutableMapOf(
            "search" to search.ifEmpty { null },
            "status" to if (status.isEmpty() || status == "overdue") null else status,
            "overdue" to if (status == "overdue") "1" else null,
            "type" to type.ifEmpty { null },
        )
        list.load()
    }
    LaunchedEffect(status, type) { reload() }

    Page("Production Orders", subtitle = "Every job on the floor you may see", refresh = { list.reload() }, actions = {
        if (env.session.current.can("production.raise_order")) Button(onClick = { showCreate = true }) { Text("New") }
    }) {
        val stats = list.extras["stats"]
        StatsRow(listOf(
            Triple("Pending", stats["pending"].str ?: "0", null),
            Triple("In progress", stats["in_progress"].str ?: "0", StatusTone.PINK),
            Triple("QC pending", stats["qc_pending"].str ?: "0", if ((stats["qc_pending"].int ?: 0) > 0) StatusTone.AMBER else null),
            Triple("Overdue", stats["overdue"].str ?: "0", if ((stats["overdue"].int ?: 0) > 0) StatusTone.DANGER else null),
        ))
        ModuleSearchField(search, { search = it }, "Order, product, customer or phone", onSubmit = { reload() })
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            OptionPicker("Status", status, ProductionStatus.all.map { it to it.humanized } + ("overdue" to "Overdue"), { status = it }, Modifier.weight(1f), allowNone = true)
            OptionPicker("Type", type, listOf("stock" to "For stock", "customer" to "Customer orders"), { type = it }, Modifier.weight(1f), allowNone = true)
        }
        ProductionListView(list, "No production orders match", Icons.Default.Build) { r ->
            val o = ProductionOrderRecord(r)
            ProductionOrderRow(o) { env.navigator.push(Route.ProductionOrder(o.id)) }
        }
    }
    if (showCreate) ProductionOrderCreateSheet(onDismiss = { showCreate = false }) { list.load() }
}

@Composable
internal fun ProductionOrderRow(order: ProductionOrderRecord, open: () -> Unit) {
    ModuleRowCard(onClick = open) {
        androidx.compose.foundation.layout.FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(order.number, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
            StatusBadge(order.status)
            PriorityBadge(order.priority)
            if (order.isOverdue) StatusBadge("overdue", StatusTone.DANGER)
        }
        Text("${order.quantity} × ${order.productName}", style = MaterialTheme.typography.bodySmall)
        androidx.compose.foundation.layout.FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Text(order.customerLabel ?: "For stock", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            order.dueDate?.let { DateText(it, DateStyle.DATE) }
            order.currentStage?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = Palette.brandDeep) }
        }
        LinearProgressIndicator(progress = { order.percent / 100f }, modifier = Modifier.fillMaxWidth(), color = Palette.brand)
    }
}

// ── Create (POST /v1/admin/production-orders) ────────────────────────────────

@Composable
internal fun ProductionOrderCreateSheet(onDismiss: () -> Unit, onCreated: () -> Unit) {
    val env = LocalEnv.current
    val products = remember { Lookup.products(env.client, producibleOnly = true) }
    val outlets = remember { Lookup.outlets(env.client) }
    var productId by remember { mutableStateOf<Int?>(null) }
    var variantId by remember { mutableStateOf<Int?>(null) }
    var variants by remember { mutableStateOf<List<Record>>(emptyList()) }
    var quantity by remember { mutableStateOf("1") }
    var priority by remember { mutableStateOf("normal") }
    var dueDate by remember { mutableStateOf(Day.add(Day.today(), 7)) }
    var outletId by remember { mutableStateOf<Int?>(null) }
    var customerJob by remember { mutableStateOf(false) }
    var customerOrderId by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    var measurements by remember { mutableStateOf<List<KeyValueRow>>(emptyList()) }
    var preferences by remember { mutableStateOf<List<KeyValueRow>>(emptyList()) }

    LaunchedEffect(productId) {
        variantId = null
        val id = productId
        if (id == null) { variants = emptyList(); return@LaunchedEffect }
        val json = runCatching { env.client.get("/v1/admin/products/$id").json }.getOrNull()
        variants = (json["product"]["variants"] ?: json["variants"]).toRecords()
    }

    FormSheet("New production order", onDismiss, saveLabel = "Create", canSave = productId != null && (quantity.toIntOrNull() ?: 0) > 0, save = {
        val body = mutableMapOf<String, JsonElement>(
            "product_id" to jInt(productId), "product_variant_id" to jInt(variantId),
            "quantity" to JsonPrimitive(quantity.toIntOrNull() ?: 1), "priority" to JsonPrimitive(priority), "due_date" to JsonPrimitive(dueDate),
            "outlet_id" to jInt(outletId), "notes" to jStr(notes), "is_customer_order" to JsonPrimitive(customerJob),
        )
        if (customerJob) {
            body["customer_order_id"] = jInt(customerOrderId.toIntOrNull())
            body["measurements"] = J.stringMap(measurements.map { it.key to it.value })
            body["customer_preferences"] = J.stringMap(preferences.map { it.key to it.value })
        }
        val r = env.client.post("/v1/admin/production-orders", kotlinx.serialization.json.JsonObject(body))
        val warnings = r.json["warnings"].records.mapNotNull { it.str }
        env.toasts.show(if (warnings.isEmpty()) "Production order created" else "Created — ${warnings.joinToString(" ")}", if (warnings.isEmpty()) StatusTone.SUCCESS else StatusTone.WARNING)
        onCreated()
        r.json["order"]["id"].int?.let { env.navigator.push(Route.ProductionOrder(it)) }
    }) { error ->
        LookupPicker("Product", products, productId, { productId = it }, error = error?.field("product_id"))
        if (variants.isNotEmpty()) {
            SelectField("Variant", variantId, variants.mapNotNull { v -> v.int("id")?.let { it to (v.string("variant_name") ?: v.string("sku") ?: "") } }, { variantId = it }, Modifier.fillMaxWidth(), placeholder = "Any / base product")
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Field("Quantity", quantity, { quantity = it }, Modifier.weight(1f), error = error?.field("quantity"), keyboard = androidx.compose.ui.text.input.KeyboardType.Number)
            OptionPickerValues("Priority", priority, ProductionStatus.priorities, { priority = it }, Modifier.weight(1f))
        }
        FieldBox("Due date", error?.field("due_date")) { DayField("Due date", dueDate, { dueDate = it }) }
        LookupPicker("Outlet", outlets, outletId, { outletId = it })
        ToggleRow("Made for a customer order", customerJob, { customerJob = it })
        if (customerJob) {
            Field("Sales order id", customerOrderId, { customerOrderId = it }, error = error?.field("customer_order_id"), keyboard = androidx.compose.ui.text.input.KeyboardType.Number)
            KeyValueEditor("Measurements", measurements) { measurements = it }
            KeyValueEditor("Customer preferences", preferences) { preferences = it }
        }
        Field("Notes", notes, { notes = it }, multiline = true)
    }
}
