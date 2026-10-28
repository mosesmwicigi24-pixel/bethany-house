package ke.co.bethanyhouse.hub.ui.features.expenses

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Cancel
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.filled.Receipt
import androidx.compose.material3.Checkbox
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.AttachmentPicker
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.DayField
import ke.co.bethanyhouse.hub.ui.components.DecimalField
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FieldBox
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.InfoSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.Loadable
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.PickedFile
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.StatTile
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.TileModifier
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.fmtMoney
import ke.co.bethanyhouse.hub.ui.components.jrows
import ke.co.bethanyhouse.hub.ui.components.outletOptions
import ke.co.bethanyhouse.hub.ui.components.uploadFile
import ke.co.bethanyhouse.hub.ui.hub
import ke.co.bethanyhouse.hub.ui.shell.FeatureRegistry
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonPrimitive

/**
 * Expenses: list with filters and bulk actions, new-expense form, detail,
 * imprest, categories & budgets, analytics. Endpoints verified against
 * backend ExpenseController / ImprestController and react-admin/src/api/{expenses,imprest}.ts.
 */
object ExpensesFeature {
    fun register() {
        FeatureRegistry.register(Route.Expenses) { ExpensesListScreen() }
        FeatureRegistry.registerType<Route.Expense> { r -> ExpenseDetailScreen(r.id) }
        FeatureRegistry.register(Route.Imprest) { ImprestScreen() }
        FeatureRegistry.register(Route.ExpenseCategories) { ExpenseCategoriesScreen() }
        FeatureRegistry.register(Route.ExpenseAnalytics) { ExpenseAnalyticsScreen() }
    }
}

/**
 * `GET /expenses` → `{expenses: paginator, stats}`; the paginator is not at
 * the root, so the list is loaded here rather than through PagedList. Pages
 * append as the person scrolls; `stats` is the server's for the filter.
 */
internal class ExpenseListModel(private val scope: CoroutineScope, private val client: ApiClient) {
    val rows = mutableStateListOf<ExpenseRow>()
    var stats: JsonElement by mutableStateOf(JsonNull)
    var page: Int by mutableStateOf(1)
    var lastPage: Int by mutableStateOf(1)
    var total: Int by mutableStateOf(0)
    var state: Loadable<List<ExpenseRow>> by mutableStateOf(Loadable.Idle)
    var loadingMore: Boolean by mutableStateOf(false)
    var query: Map<String, String?> = emptyMap()
    private var generation = 0
    val hasMore: Boolean get() = page < lastPage

    fun load() { scope.launch { reload() } }

    suspend fun reload() {
        val gen = ++generation
        state = Loadable.Loading(rows.toList().takeIf { it.isNotEmpty() })
        try {
            val r = fetch(1)
            if (gen != generation) return
            rows.clear(); rows.addAll(r)
            state = Loadable.Loaded(rows.toList())
        } catch (e: ApiError) {
            if (gen != generation) return
            state = Loadable.Failed(e, rows.toList().takeIf { it.isNotEmpty() })
        } catch (e: CancellationException) { throw e } catch (e: Exception) {
            if (gen != generation) return
            state = Loadable.Failed(ApiError.transport(e.message ?: "Error"), rows.toList().takeIf { it.isNotEmpty() })
        }
    }

    fun loadMore() {
        if (!hasMore || loadingMore) return
        loadingMore = true
        scope.launch {
            try { rows.addAll(fetch(page + 1)); state = Loadable.Loaded(rows.toList()) } catch (_: ApiError) {} finally { loadingMore = false }
        }
    }

    private suspend fun fetch(p: Int): List<ExpenseRow> {
        val q = query + mapOf("page" to p.toString(), "per_page" to "25")
        val r = client.get("/v1/admin/expenses", q).json
        val pag = r["expenses"]
        page = pag["current_page"].int ?: p
        lastPage = pag["last_page"].int ?: 1
        val list = pag["data"].records.map(::ExpenseRow)
        total = pag["total"].int ?: list.size
        stats = r["stats"] ?: JsonNull
        return list
    }
}

// ── List ─────────────────────────────────────────────────────────────────────

private data class BulkRequest(val action: String, val label: String, val needsReason: Boolean)
private data class ReasonAction(val row: ExpenseRow, val action: String)

@Composable
fun ExpensesListScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val s = env.session.current
    val model = remember { ExpenseListModel(scope, env.client) }
    var status by remember { mutableStateOf("") }
    var categoryId by remember { mutableStateOf<Int?>(null) }
    var outletId by remember { mutableStateOf<Int?>(null) }
    var imprest by remember { mutableStateOf("") }
    var search by remember { mutableStateOf("") }
    var dated by remember { mutableStateOf(false) }
    var start by remember { mutableStateOf(Day.today()) }
    var end by remember { mutableStateOf(Day.today()) }
    var sort by remember { mutableStateOf("expense_date") }
    var showFilters by remember { mutableStateOf(false) }
    var categories by remember { mutableStateOf<List<Pair<Int, String>>>(emptyList()) }
    var outlets by remember { mutableStateOf<List<Pair<Int, String>>>(emptyList()) }
    val selected = remember { mutableStateListOf<Int>() }
    var creating by remember { mutableStateOf(false) }
    var bulk by remember { mutableStateOf<BulkRequest?>(null) }
    var bulkResult by remember { mutableStateOf<JsonElement?>(null) }
    var reasonFor by remember { mutableStateOf<ReasonAction?>(null) }

    val filterKey = "$status|${categoryId ?: 0}|${outletId ?: 0}|$imprest|$search|$dated|$start|$end|$sort"
    LaunchedEffect(filterKey) {
        model.query = mapOf(
            "status" to status.ifEmpty { null }, "category_id" to categoryId?.toString(), "outlet_id" to outletId?.toString(),
            "imprest" to imprest.ifEmpty { null }, "search" to search.ifEmpty { null },
            "start_date" to if (dated) start else null, "end_date" to if (dated) end else null,
            "sort" to sort, "direction" to "desc",
        )
        selected.clear()
        model.reload()
    }
    LaunchedEffect(Unit) { categories = loadExpenseCategories(env.client); outlets = outletOptions(env.client, reports = false) }

    suspend fun single(row: ExpenseRow, action: String, body: JsonElement = J.obj()) {
        try {
            env.client.post("/v1/admin/expenses/${row.id}/$action", body)
            env.toasts.show("${row.reference}: ${action.humanized.lowercase()} done")
            model.reload()
        } catch (e: ApiError) { env.toasts.error(e) }
    }
    suspend fun deleteRow(row: ExpenseRow) {
        try { env.client.delete("/v1/admin/expenses/${row.id}"); env.toasts.show("Deleted"); model.reload() } catch (e: ApiError) { env.toasts.error(e) }
    }

    Page("Expenses", subtitle = "${model.total} record${if (model.total == 1) "" else "s"}", refresh = { model.reload() }, actions = {
        if (s.can("expenses.create")) IconButton(onClick = { creating = true }) { Icon(Icons.Default.Add, contentDescription = "New expense") }
    }) {
        val stats = model.stats
        TileGrid {
            StatTile("Approved & paid (period)", fmtMoney(stats["approved_total"], "KES"), hint = "KES at the recorded exchange rate (amount_kes)", modifier = TileModifier)
            StatTile("Pending approval", fmtMoney(stats["pending_total"], "KES"), hint = "${stats["pending_count"].int ?: 0} waiting", tone = if ((stats["pending_count"].int ?: 0) > 0) StatusTone.AMBER else null, modifier = TileModifier)
            StatTile("Records", stats["total_count"].str ?: "0", modifier = TileModifier)
        }
        // Filters
        Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
            var typed by remember { mutableStateOf(search) }
            ModuleSearchField(typed, { typed = it }, "Search title, reference, vendor", onSubmit = { search = typed.trim() })
            Row(verticalAlignment = Alignment.CenterVertically) {
                SelectField("Status", status.ifEmpty { null }, ExpenseVocab.statuses.map { it to it.humanized }, { status = it ?: "" }, Modifier.weight(1f), placeholder = "All statuses")
                Spacer(Modifier.width(Space.s))
                TextButton(onClick = { showFilters = !showFilters }) { Text(if (showFilters) "Fewer filters" else "More filters") }
            }
            if (showFilters) {
                SelectField("Category", categoryId, categories, { categoryId = it }, placeholder = "All categories")
                if (outlets.size > 1) SelectField("Outlet", outletId, outlets, { outletId = it }, placeholder = "All outlets")
                SelectField("Imprest", imprest.ifEmpty { null }, listOf("yes" to "From imprest", "no" to "Not imprest", "unresolved" to "Unresolved imprest"), { imprest = it ?: "" }, placeholder = "Any funding")
                SelectField("Sort", sort, listOf("expense_date" to "By date", "amount_kes" to "By amount", "created_at" to "By created", "title" to "By title"), { sort = it ?: "expense_date" }, placeholder = "By date")
                ToggleRow("Dates", dated, { dated = it })
                if (dated) Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    DayField("From", start, { start = it.ifEmpty { Day.today() } }, Modifier.weight(1f))
                    DayField("To", end, { end = it.ifEmpty { Day.today() } }, Modifier.weight(1f))
                }
            }
        }
        if (s.can("expenses.approve") && selected.isNotEmpty()) {
            Column(Modifier.fillMaxWidth().background(Palette.infoSoft, MaterialTheme.shapes.small).padding(Space.m), verticalArrangement = Arrangement.spacedBy(Space.xs)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("${selected.size} selected", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                    TextButton(onClick = { selected.clear() }) { Text("Clear") }
                }
                Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    OutlinedButton(onClick = { bulk = BulkRequest("approve", "Approve", false) }) { Text("Approve") }
                    OutlinedButton(onClick = { bulk = BulkRequest("request_changes", "Send back for changes", true) }) { Text("Request changes") }
                    OutlinedButton(onClick = { bulk = BulkRequest("reject", "Reject", true) }) { Text("Reject") }
                    OutlinedButton(onClick = { bulk = BulkRequest("mark_paid", "Mark as paid", false) }) { Text("Mark as paid") }
                }
            }
        }
        LoadableView(model.state, retry = { model.load() }, isEmpty = { it.isEmpty() }, empty = {
            EmptyState("No expenses match", "Change the filters or record a new expense.", Icons.Default.Receipt, actionLabel = if (s.can("expenses.create")) "New expense" else null, action = { creating = true })
        }) { rows ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                rows.forEachIndexed { i, row ->
                    ExpenseListRow(
                        row, selectable = s.can("expenses.approve"), selected = row.id in selected,
                        onSelect = { on -> if (on) { if (row.id !in selected) selected += row.id } else selected.remove(row.id) },
                        onOpen = { env.navigator.push(Route.Expense(row.id)) },
                    ) { close ->
                        DropdownMenuItem(text = { Text("Open") }, onClick = { close(); env.navigator.push(Route.Expense(row.id)) })
                        if (row.status == "draft" && s.can("expenses.create")) DropdownMenuItem(text = { Text("Submit for approval") }, onClick = { close(); scope.launch { single(row, "submit") } })
                        if (row.status == "pending_approval" && s.can("expenses.approve")) {
                            DropdownMenuItem(text = { Text("Approve") }, onClick = { close(); scope.launch { single(row, "approve") } })
                            DropdownMenuItem(text = { Text("Request changes") }, onClick = { close(); reasonFor = ReasonAction(row, "request_changes") })
                            DropdownMenuItem(text = { Text("Reject") }, onClick = { close(); reasonFor = ReasonAction(row, "reject") })
                        }
                        if (row.status == "approved" && s.can("expenses.approve")) DropdownMenuItem(text = { Text("Mark as paid") }, onClick = { close(); scope.launch { single(row, "mark-paid") } })
                        if (row.status in listOf("draft", "rejected", "cancelled") && s.can("expenses.delete")) DropdownMenuItem(text = { Text("Delete", color = Palette.danger) }, onClick = { close(); scope.launch { deleteRow(row) } })
                    }
                    if (i == rows.lastIndex && model.hasMore) LaunchedEffect(rows.size) { model.loadMore() }
                }
                if (model.lastPage > 1) Text("Page ${model.page} of ${model.lastPage}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
            }
        }
    }

    if (creating) NewExpenseSheet(categories, outlets, onDismiss = { creating = false }) { id -> creating = false; model.load(); env.navigator.push(Route.Expense(id)) }
    bulk?.let { b -> BulkConfirmSheet(b, selected.toList(), onDismiss = { bulk = null }) { response -> bulk = null; bulkResult = response; selected.clear(); model.load() } }
    bulkResult?.let { r -> BulkResultSheet(r) { bulkResult = null } }
    reasonFor?.let { r ->
        ReasonSheet(
            title = if (r.action == "reject") "Reject ${r.row.reference}" else "Send ${r.row.reference} back for changes",
            primary = if (r.action == "reject") "Reject" else "Request changes", destructive = r.action == "reject", onDismiss = { reasonFor = null },
        ) { reason -> single(r.row, if (r.action == "reject") "reject" else "request-changes", J.obj("reason" to JsonPrimitive(reason))); reasonFor = null }
    }
}

@Composable
private fun ExpenseListRow(row: ExpenseRow, selectable: Boolean, selected: Boolean, onSelect: (Boolean) -> Unit, onOpen: () -> Unit, menu: @Composable (close: () -> Unit) -> Unit) {
    var menuOpen by remember { mutableStateOf(false) }
    Row(verticalAlignment = Alignment.Top) {
        if (selectable) Checkbox(checked = selected, onCheckedChange = onSelect)
        Surface(shape = MaterialTheme.shapes.small, color = MaterialTheme.hub.card, modifier = Modifier.weight(1f).border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.small).clickable(onClick = onOpen)) {
            Row(Modifier.padding(Space.m), verticalAlignment = Alignment.Top) {
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text(row.reference, style = MaterialTheme.typography.bodySmall.copy(fontFamily = FontFamily.Monospace))
                        StatusBadge(row.status)
                        if (row.isImprest) StatusBadge("imprest", StatusTone.ACCENT)
                        if (row.imprestResolution == "pending") StatusBadge("awaiting resolution", StatusTone.WARNING)
                    }
                    Text(row.title, style = MaterialTheme.typography.bodyMedium)
                    val meta = listOfNotNull(row.category, row.vendor, row.outlet).joinToString(" · ")
                    if (meta.isNotEmpty()) Text(meta, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    DateText(row.date, DateStyle.DATE)
                }
                Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    MoneyText(row.amount, emphasis = true)
                    val k = row.amountKes
                    if (row.currency != "KES") Text(if (k != null) "= ${k.formatted()}" else "no reporting rate", style = MaterialTheme.typography.labelSmall, color = if (k != null) MaterialTheme.hub.textSecondary else Palette.warning)
                }
                Box {
                    IconButton(onClick = { menuOpen = true }, modifier = Modifier.size(28.dp)) { Icon(Icons.Default.MoreVert, contentDescription = "Actions", tint = MaterialTheme.hub.textTertiary) }
                    DropdownMenu(expanded = menuOpen, onDismissRequest = { menuOpen = false }) { menu { menuOpen = false } }
                }
            }
        }
    }
}

// ── Bulk (POST /expenses/bulk, up to 100 ids; each item reported on its own) ──

@Composable
private fun BulkConfirmSheet(request: BulkRequest, ids: List<Int>, onDismiss: () -> Unit, done: (JsonElement) -> Unit) {
    val env = LocalEnv.current
    var reason by remember { mutableStateOf("") }
    FormSheet(
        "${request.label} ${ids.size} expense${if (ids.size == 1) "" else "s"}", onDismiss, saveLabel = request.label,
        canSave = !request.needsReason || reason.isNotBlank(), destructive = request.action == "reject",
        save = {
            val body = J.obj("ids" to J.ints(ids.take(100)), "action" to JsonPrimitive(request.action), "reason" to J.sOpt(reason.trim()))
            val r = env.client.post("/v1/admin/expenses/bulk", body)
            done(r.json ?: JsonNull)
        },
    ) { error ->
        Text("Each expense runs its own rule on the server and is reported on its own — never all-or-nothing. Items not in the right state are left alone. At most 100 per run.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
        if (request.needsReason) Field("Reason (required)", reason, { reason = it }, multiline = true, error = error?.field("reason"))
    }
}

@Composable
private fun BulkResultSheet(response: JsonElement, onDismiss: () -> Unit) {
    val summary = response["summary"]
    InfoSheet("${(response["action"].str ?: "").humanized}: ${summary["succeeded"].int ?: 0} done, ${summary["failed"].int ?: 0} not", onDismiss) {
        jrows(response["results"]).forEach { r ->
            val ok = r["ok"].bool == true
            Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                Icon(if (ok) Icons.Default.CheckCircle else Icons.Default.Cancel, contentDescription = null, tint = if (ok) Palette.success else Palette.danger, modifier = Modifier.size(18.dp))
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(r.str("reference") ?: "Expense #${r.str("id") ?: ""}", style = MaterialTheme.typography.bodySmall.copy(fontFamily = FontFamily.Monospace))
                    Text(r.str("message") ?: r.str("code") ?: "", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                }
                r.str("status_after")?.let { StatusBadge(it) }
            }
        }
        TextButton(onClick = onDismiss, modifier = Modifier.align(Alignment.End)) { Text("Done") }
    }
}

// ── New expense (POST /expenses, then attachments) ───────────────────────────

private class LineItemDraft {
    var description by mutableStateOf("")
    var categoryId by mutableStateOf<Int?>(null)
    var quantity by mutableStateOf("1")
    var unitPrice by mutableStateOf("")
    var tax by mutableStateOf("")
}

@Composable
private fun NewExpenseSheet(categories: List<Pair<Int, String>>, outlets: List<Pair<Int, String>>, onDismiss: () -> Unit, created: (Int) -> Unit) {
    val env = LocalEnv.current
    var title by remember { mutableStateOf("") }
    var description by remember { mutableStateOf("") }
    var categoryId by remember { mutableStateOf<Int?>(null) }
    var date by remember { mutableStateOf(Day.today()) }
    var amount by remember { mutableStateOf("") }
    var currency by remember { mutableStateOf("KES") }
    var exchangeRate by remember { mutableStateOf("") }
    var method by remember { mutableStateOf("cash") }
    var reference by remember { mutableStateOf("") }
    var vendor by remember { mutableStateOf("") }
    var vendorContact by remember { mutableStateOf("") }
    var outletId by remember { mutableStateOf<Int?>(null) }
    var department by remember { mutableStateOf("") }
    var recurring by remember { mutableStateOf(false) }
    var frequency by remember { mutableStateOf("monthly") }
    var notes by remember { mutableStateOf("") }
    var useImprest by remember { mutableStateOf<Boolean?>(null) }
    var imprest by remember { mutableStateOf<JsonElement?>(null) }
    val lines = remember { mutableStateListOf<LineItemDraft>() }
    val files = remember { mutableStateListOf<PickedFile>() }
    var suggestions by remember { mutableStateOf<JsonElement?>(null) }

    LaunchedEffect(Unit) {
        if (env.session.current.can("expenses.create")) suggestions = runCatching { env.client.get("/v1/admin/expenses/suggestions", background = true).json }.getOrNull()
        runCatching { env.client.get("/v1/admin/expenses/imprest", background = true).json }.getOrNull()?.let { j -> j["accounts"].records.firstOrNull()?.let { imprest = it } }
    }

    val categoryHint: String? = categoryId?.let { id -> suggestions["by_category"][id.toString()] }?.let { h ->
        val parts = listOfNotNull(h["vendor_name"].str?.let { "usually $it" }, h["payment_method"].str?.let { "paid by ${ExpenseVocab.methodLabel(it)}" }, h["department"].str)
        if (parts.isEmpty()) null else "This category: " + parts.joinToString(", ")
    }
    val vendorHint: String? = vendor.trim().lowercase().takeIf { it.isNotEmpty() }?.let { key -> suggestions["by_vendor"][key] }?.let { h ->
        val cat = h["category_id"].int?.let { id -> categories.firstOrNull { it.first == id }?.second?.trim() }
        val parts = listOfNotNull(cat?.let { "usually $it" }, h["payment_method"].str?.let { "paid by ${ExpenseVocab.methodLabel(it)}" })
        if (parts.isEmpty()) null else "${h["vendor_name"].str ?: vendor}: " + parts.joinToString(", ")
    }

    fun apply(u: JsonElement) {
        title = u["title_suggestion"].str ?: u["title"].str ?: title
        u["category_id"].int?.let { categoryId = it }
        u["vendor_name"].str?.let { vendor = it }
        u["payment_method"].str?.let { method = it }
        outletId = u["outlet_id"].int
        u["department"].str?.let { department = it }
        u["currency_code"].str?.let { currency = it }
    }

    FormSheet(
        "New expense", onDismiss, saveLabel = "Save expense",
        canSave = title.isNotEmpty() && categoryId != null && amount.isNotEmpty() && !(imprest != null && useImprest == null),
        save = {
            val items = lines.filter { it.description.isNotEmpty() }.map { l ->
                J.obj("description" to JsonPrimitive(l.description), "category_id" to l.categoryId?.let { JsonPrimitive(it) }, "quantity" to J.decimal(l.quantity), "unit_price" to J.decimal(l.unitPrice), "tax_amount" to J.decimal(l.tax))
            }
            val body = J.obj(
                "title" to JsonPrimitive(title), "category_id" to JsonPrimitive(categoryId ?: 0), "expense_date" to JsonPrimitive(date),
                "currency_code" to JsonPrimitive(currency), "payment_method" to JsonPrimitive(method), "is_recurring" to JsonPrimitive(recurring),
                "amount" to J.decimal(amount), "exchange_rate" to if (currency == "KES") null else J.decimal(exchangeRate),
                "description" to J.sOpt(description), "payment_reference" to J.sOpt(reference),
                "vendor_name" to J.sOpt(vendor), "vendor_contact" to J.sOpt(vendorContact),
                "outlet_id" to outletId?.let { JsonPrimitive(it) }, "department" to J.sOpt(department),
                "recurrence_frequency" to if (recurring) JsonPrimitive(frequency) else null, "notes" to J.sOpt(notes),
                "use_imprest" to if (imprest != null) useImprest?.let { JsonPrimitive(it) } else null,
                "line_items" to if (items.isEmpty()) null else JsonArray(items),
            )
            val r = env.client.post("/v1/admin/expenses", body)
            val id = r.json["expense"]["id"].int
            if (id == null) { env.toasts.show("Saved, but the expense id was not returned.", StatusTone.WARNING); return@FormSheet }
            var failed = 0
            for (f in files) {
                if (runCatching { env.client.uploadFile("/v1/admin/expenses/$id/attachments", f, fields = mapOf("kind" to "receipt")) }.isFailure) failed++
            }
            env.toasts.show(if (failed == 0) (r.json["message"].str ?: "Expense created") else "Expense created; $failed file(s) did not upload — add them on the expense.", if (failed == 0) StatusTone.SUCCESS else StatusTone.WARNING)
            created(id)
        },
    ) { error ->
        val usuals = suggestions["usuals"].records
        if (usuals.isNotEmpty()) {
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text("Usual expenses (learned from history — suggestions only; the amount always needs your own entry)", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    usuals.forEach { u ->
                        Surface(shape = MaterialTheme.shapes.small, color = MaterialTheme.hub.card, modifier = Modifier.widthIn(max = 240.dp).border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.small).clickable { apply(u) }) {
                            Column(Modifier.padding(8.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                Row(horizontalArrangement = Arrangement.spacedBy(Space.xs), verticalAlignment = Alignment.CenterVertically) {
                                    Text(u["title_suggestion"].str ?: u["title"].str ?: "", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, maxLines = 1)
                                    u["due_state"].str?.takeIf { it != "done" }?.let { StatusBadge(it, if (it == "due") StatusTone.WARNING else StatusTone.AMBER) }
                                }
                                val last = Money.from(u["last_amount"], u["currency_code"].str ?: "KES")?.let { "last ${it.formatted()}" }
                                Text(listOfNotNull(u["category_name"].str, u["cadence"].str, last).joinToString(" · "), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, maxLines = 1)
                            }
                        }
                    }
                }
            }
        }
        Field("Title *", title, { title = it }, placeholder = "e.g. Office rent – October", error = error?.field("title"))
        SelectField("Category *", categoryId, categories, { categoryId = it }, placeholder = "Choose…")
        error?.field("category_id")?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = Palette.danger) }
        categoryHint?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
        FieldBox("Date *", error = error?.field("expense_date")) { DayField("Date", date, { if (it.isNotEmpty()) date = it }) }
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            DecimalField("Amount *", amount, { amount = it }, Modifier.weight(1f), error = error?.field("amount"))
            SelectField("Currency", currency, ExpenseVocab.currencies.map { it to it }, { currency = it ?: "KES" }, Modifier.width(120.dp), placeholder = "KES")
        }
        if (currency != "KES") DecimalField("KES per $currency (blank = server rate)", exchangeRate, { exchangeRate = it }, error = error?.field("exchange_rate"))
        OptionPicker("Payment method *", method, ExpenseVocab.paymentMethods, { method = it })
        Field("Payment reference", reference, { reference = it }, placeholder = "M-PESA code, cheque no.")
        imprest?.let { a ->
            Column(Modifier.fillMaxWidth().background(MaterialTheme.hub.field, MaterialTheme.shapes.small).padding(Space.m), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Text("Pay from the imprest? *", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
                Text("${a["name"].str ?: "Imprest"} · balance ${fmtMoney(a["balance"], a["currency_code"].str ?: "KES")}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                SingleChoiceSegmentedButtonRow(Modifier.fillMaxWidth()) {
                    SegmentedButton(selected = useImprest == true, onClick = { useImprest = true }, shape = SegmentedButtonDefaults.itemShape(0, 2)) { Text("Yes — from imprest") }
                    SegmentedButton(selected = useImprest == false, onClick = { useImprest = false }, shape = SegmentedButtonDefaults.itemShape(1, 2)) { Text("No — another way") }
                }
                if (useImprest == true && currency != "KES") Text("An imprest spend is recorded in KES.", style = MaterialTheme.typography.labelSmall, color = Palette.warning)
                error?.field("use_imprest")?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = Palette.danger) }
            }
        }
        Field("Vendor", vendor, { vendor = it }, placeholder = "Who was paid")
        vendorHint?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
        Field("Vendor contact", vendorContact, { vendorContact = it }, placeholder = "Phone or email")
        SelectField("Outlet", outletId, outlets, { outletId = it }, placeholder = "Head office")
        error?.field("outlet_id")?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = Palette.danger) }
        Field("Department", department, { department = it })
        ToggleRow("Recurring", recurring, { recurring = it })
        if (recurring) OptionPicker("Every", frequency, listOf("weekly" to "Week", "monthly" to "Month", "quarterly" to "Quarter", "annually" to "Year"), { frequency = it })
        Field("Description", description, { description = it }, multiline = true)
        Field("Notes", notes, { notes = it }, multiline = true)
        // Line items (optional)
        Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("Line items (optional)", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                TextButton(onClick = { lines += LineItemDraft() }) { Icon(Icons.Default.Add, contentDescription = null, modifier = Modifier.size(16.dp)); Text(" Add line") }
            }
            lines.forEach { line ->
                Column(Modifier.fillMaxWidth().border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.small).padding(Space.s), verticalArrangement = Arrangement.spacedBy(Space.xs)) {
                    Field("Description", line.description, { line.description = it })
                    SelectField("Category", line.categoryId, categories, { line.categoryId = it }, placeholder = "Same category")
                    Row(horizontalArrangement = Arrangement.spacedBy(Space.xs)) {
                        DecimalField("Qty", line.quantity, { line.quantity = it }, Modifier.weight(1f))
                        DecimalField("Unit price", line.unitPrice, { line.unitPrice = it }, Modifier.weight(1.4f))
                        DecimalField("Tax", line.tax, { line.tax = it }, Modifier.weight(1f))
                    }
                    TextButton(onClick = { lines.remove(line) }) { Text("Remove line", color = Palette.danger) }
                }
            }
            if (lines.isNotEmpty()) Text("The expense amount above is what is recorded; the server checks the lines.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
        }
        FieldBox("Evidence (uploaded after the expense is saved)") {
            AttachmentPicker("Add receipt or photo") { files += it }
            files.forEach { f -> Text(f.name, style = MaterialTheme.typography.labelSmall, maxLines = 1) }
        }
    }
}
