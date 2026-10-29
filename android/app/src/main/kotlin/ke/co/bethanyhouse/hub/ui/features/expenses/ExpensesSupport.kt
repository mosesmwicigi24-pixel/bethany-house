package ke.co.bethanyhouse.hub.ui.features.expenses

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.size
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.HubDates
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.TextPromptSheet
import ke.co.bethanyhouse.hub.ui.components.openFile
import ke.co.bethanyhouse.hub.ui.components.personName
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.JsonElement
import java.io.File
import java.time.Instant
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.util.Locale

// Shared vocabulary and helpers for the Expenses and Finance module
// (react-admin/src/api/expenses.ts, imprest.ts, transactions.ts). Nothing
// here computes money: every figure shown is the server's, in the currency
// the payload names.

internal object ExpenseVocab {
    val statuses = listOf("draft", "pending_approval", "changes_requested", "approved", "rejected", "paid", "cancelled")
    val paymentMethods = listOf("cash" to "Cash", "bank_transfer" to "Bank Transfer", "mpesa" to "M-PESA", "card" to "Card", "cheque" to "Cheque", "other" to "Other")
    val currencies = listOf("KES", "USD", "EUR", "GBP")
    val evidenceKinds = listOf("receipt" to "Receipt", "quotation" to "Quotation", "invoice" to "Bill / invoice", "meter" to "Meter reading", "other" to "Other")
    fun methodLabel(v: String?): String = paymentMethods.firstOrNull { it.first == v }?.second ?: (v ?: "—").humanized
}

/** One expense row as `GET /expenses` lists it (the same fields the detail expands). */
internal class ExpenseRow(val json: JsonElement) {
    val id: Int get() = json["id"].int ?: 0
    val reference: String get() = json["reference_number"].str ?: "—"
    val title: String get() = json["title"].str ?: "Expense"
    val status: String get() = json["status"].str ?: "draft"
    val currency: String get() = json["currency_code"].str ?: "KES"
    val amount: Money? get() = Money.from(json["amount"], currency)
    val amountKes: Money? get() = Money.from(json["amount_kes"], "KES")
    val category: String? get() = json["category"]["name"].str
    val vendor: String? get() = json["vendor_name"].str
    val outlet: String? get() = json["outlet"]["name"].str
    val date: Instant? get() = json["expense_date"].let { it.str?.let(HubDates::parse) }
    val isImprest: Boolean get() = json["imprest_account_id"].int != null
    val imprestResolution: String? get() = json["imprest_resolution"].str
}

/** Categories flattened with their children indented, for pickers: GET /expenses/categories → {categories: tree}. */
internal suspend fun loadExpenseCategories(client: ApiClient): List<Pair<Int, String>> {
    val r = runCatching { client.get("/v1/admin/expenses/categories", background = true) }.getOrNull() ?: return emptyList()
    val out = mutableListOf<Pair<Int, String>>()
    fun walk(list: List<JsonElement>, depth: Int) {
        for (c in list) {
            c["id"].int?.let { id -> out += id to ("  ".repeat(depth) + (c["name"].str ?: "Category $id")) }
            walk(c["children"].records, depth + 1)
        }
    }
    walk(r.json["categories"].records, 0)
    return out
}

/** Staff for the custodian picker: GET /users?exclude_type=customer&status=active. */
internal suspend fun expensesStaffOptions(client: ApiClient): List<Pair<Int, String>> {
    val r = runCatching {
        client.get("/v1/admin/users", mapOf("per_page" to "100", "status" to "active", "exclude_type" to "customer", "sort_by" to "first_name", "sort_order" to "asc"), background = true)
    }.getOrNull() ?: return emptyList()
    return r.json["data"].records.mapNotNull { u -> u["id"].int?.let { id -> id to (personName(u) ?: u["email"].str ?: "User $id") } }
}

internal fun expensesIdOf(v: JsonElement?): Int? = v["id"].int ?: v.int

/** A reason for reject / request changes (the maker reads it). */
@Composable
internal fun ReasonSheet(title: String, primary: String, destructive: Boolean = false, onDismiss: () -> Unit, action: suspend (String) -> Unit) {
    TextPromptSheet(title, onDismiss, placeholder = "Reason (the maker reads this)", required = true, confirmLabel = primary, destructive = destructive, action = action)
}

/** Previous / Page x of y / Next for the lists that page server-side. */
@Composable
internal fun PagerRow(page: Int, lastPage: Int, onPage: (Int) -> Unit) {
    if (lastPage <= 1) return
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically) {
        TextButton(enabled = page > 1, onClick = { onPage(page - 1) }) { Text("Previous") }
        Text("Page $page of $lastPage", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
        TextButton(enabled = page < lastPage, onClick = { onPage(page + 1) }) { Text("Next") }
    }
}

/**
 * A private file behind a short-lived signed link: the issuer endpoint
 * answers `{url, expires_at, name}`; the link itself needs no bearer, so it
 * is fetched with the bare OkHttp client and opened (or shared) from the cache.
 */
@Composable
internal fun ExpenseSignedFileButton(label: String, issuer: String, suggestedName: String) {
    val env = LocalEnv.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    TextButton(enabled = !busy, onClick = {
        busy = true
        scope.launch {
            try {
                val link = env.client.get(issuer).json
                val url = link["url"].str ?: throw ApiError(0, "No link was issued.")
                val name = (link["name"].str ?: suggestedName).replace(Regex("[^A-Za-z0-9._-]"), "_")
                val (file, mime) = withContext(Dispatchers.IO) {
                    val req = okhttp3.Request.Builder().url(url).build()
                    env.client.okHttp.newCall(req).execute().use { r ->
                        if (!r.isSuccessful) throw ApiError(r.code, "The file could not be fetched (${r.code}).")
                        val dir = File(context.cacheDir, "documents").apply { mkdirs() }
                        val f = File(dir, name).apply { writeBytes(r.body?.bytes() ?: ByteArray(0)) }
                        f to (r.header("Content-Type") ?: "application/octet-stream")
                    }
                }
                openFile(context, file, mime)
            } catch (e: Exception) { env.toasts.error(e) } finally { busy = false }
        }
    }) { if (busy) CircularProgressIndicator(Modifier.size(14.dp), strokeWidth = 2.dp) else Text(label) }
}

// ── Date window (ReportShell.swift DatePreset / ReportWindow) ────────────────

/** The presets the analytics and payment pages read, computed in Nairobi so a boundary never shifts. */
internal enum class ExpensesDatePreset(val label: String) {
    TODAY("Today"), YESTERDAY("Yesterday"), THIS_WEEK("This week"), LAST_WEEK("Last week"),
    LAST_7_DAYS("Last 7 days"), LAST_30_DAYS("Last 30 days"), LAST_60_DAYS("Last 60 days"), LAST_90_DAYS("Last 90 days"),
    THIS_MONTH("This month"), LAST_MONTH("Last month"), THIS_QUARTER("This quarter"), LAST_QUARTER("Last quarter"),
    THIS_YEAR("This year"), LAST_YEAR("Last year"), CUSTOM("Custom range");

    companion object { val options: List<Pair<ExpensesDatePreset, String>> = entries.map { it to it.label } }
}

/** [start, end] as calendar days (end inclusive), mirroring datePresetRange(); custom days are yyyy-MM-dd strings. */
internal data class ExpensesDateWindow(val preset: ExpensesDatePreset = ExpensesDatePreset.THIS_MONTH, val customStart: String = Day.today(), val customEnd: String = Day.today(), val outletId: Int? = null) {
    val range: Pair<LocalDate, LocalDate>
        get() {
            val today = HubDates.today()
            fun startOfWeek(d: LocalDate) = d.minusDays(((d.dayOfWeek.value % 7)).toLong()) // Sunday first, as the Apple calendar
            fun quarterStart(d: LocalDate) = LocalDate.of(d.year, ((d.monthValue - 1) / 3) * 3 + 1, 1)
            return when (preset) {
                ExpensesDatePreset.TODAY -> today to today
                ExpensesDatePreset.YESTERDAY -> today.minusDays(1).let { it to it }
                ExpensesDatePreset.THIS_WEEK -> startOfWeek(today) to today
                ExpensesDatePreset.LAST_WEEK -> startOfWeek(today.minusWeeks(1)).let { it to it.plusDays(6) }
                ExpensesDatePreset.LAST_7_DAYS -> today.minusDays(6) to today
                ExpensesDatePreset.LAST_30_DAYS -> today.minusDays(29) to today
                ExpensesDatePreset.LAST_60_DAYS -> today.minusDays(59) to today
                ExpensesDatePreset.LAST_90_DAYS -> today.minusDays(89) to today
                ExpensesDatePreset.THIS_MONTH -> today.withDayOfMonth(1) to today
                ExpensesDatePreset.LAST_MONTH -> today.minusMonths(1).withDayOfMonth(1).let { it to it.withDayOfMonth(it.lengthOfMonth()) }
                ExpensesDatePreset.THIS_QUARTER -> quarterStart(today) to today
                ExpensesDatePreset.LAST_QUARTER -> quarterStart(today.minusMonths(3)).let { it to it.plusMonths(3).minusDays(1) }
                ExpensesDatePreset.THIS_YEAR -> today.withDayOfYear(1) to today
                ExpensesDatePreset.LAST_YEAR -> today.minusYears(1).let { LocalDate.of(it.year, 1, 1) to LocalDate.of(it.year, 12, 31) }
                ExpensesDatePreset.CUSTOM -> {
                    val s = Day.parse(customStart) ?: today; val e = Day.parse(customEnd) ?: today
                    if (s <= e) s to e else e to s
                }
            }
        }
    val startString: String get() = Day.string(range.first)
    val endString: String get() = Day.string(range.second)

    /** ReportController spelling: start_date / end_date / outlet_id. */
    fun dateParams(extra: Map<String, String?> = emptyMap()): Map<String, String?> =
        mapOf("start_date" to startString, "end_date" to endString, "outlet_id" to outletId?.toString()) + extra

    val description: String
        get() {
            val f = DateTimeFormatter.ofPattern("d MMM yyyy", Locale.UK)
            val (s, e) = range
            return if (s == e) s.format(f) else "${s.format(f)} – ${e.format(f)}"
        }
}
