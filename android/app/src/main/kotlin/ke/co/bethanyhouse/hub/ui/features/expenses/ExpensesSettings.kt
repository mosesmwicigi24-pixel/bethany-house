package ke.co.bethanyhouse.hub.ui.features.expenses

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.BarChart
import androidx.compose.material.icons.automirrored.filled.Label
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.DataTable
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.DayField
import ke.co.bethanyhouse.hub.ui.components.DecimalField
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.JRow
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.RemoteJson
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.ShareTextButton
import ke.co.bethanyhouse.hub.ui.components.StatTile
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.TableColumn
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.TileModifier
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.BarsChart
import ke.co.bethanyhouse.hub.ui.components.LinesChart
import ke.co.bethanyhouse.hub.ui.components.chartPoints
import ke.co.bethanyhouse.hub.ui.components.fmtMoney
import ke.co.bethanyhouse.hub.ui.components.fmtPct
import ke.co.bethanyhouse.hub.ui.components.jrows
import ke.co.bethanyhouse.hub.ui.components.outletOptions
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonPrimitive

/**
 * Expense categories and budgets (ExpenseCategoriesPage.tsx): both lists
 * read with expenses.view; create/update need expenses.budgets. There is
 * no delete endpoint — a category is deactivated instead.
 */
@Composable
fun ExpenseCategoriesScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var tab by remember { mutableStateOf("categories") }
    var budgetType by remember { mutableStateOf("monthly") }
    var budgetYear by remember { mutableStateOf(Day.thisYear()) }
    val categories = remember { Resource(scope) { jrows(env.client.get("/v1/admin/expenses/categories").json["categories"]) } }
    val budgets = remember(budgetType, budgetYear) { Resource(scope) { jrows(env.client.get("/v1/admin/expenses/budgets", mapOf("period_type" to budgetType, "period_year" to budgetYear.toString())).json["budgets"]) } }
    var editingCategory by remember { mutableStateOf<JsonElement?>(null) }
    var newCategory by remember { mutableStateOf(false) }
    var editingBudget by remember { mutableStateOf<JsonElement?>(null) }
    var newBudget by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { categories.load() }
    LaunchedEffect(budgets) { budgets.load() }
    val canEdit = env.session.current.can("expenses.budgets")

    val flatCategories: List<Pair<Int, String>> = run {
        val out = mutableListOf<Pair<Int, String>>()
        fun walk(rows: List<JRow>, depth: Int) { for (c in rows) { c.int("id")?.let { out += it to ("  ".repeat(depth) + (c.str("name") ?: "")) }; walk(jrows(c["children"]), depth + 1) } }
        walk(categories.value ?: emptyList(), 0)
        out
    }

    Page("Expense categories & budgets", refresh = { categories.reload(); budgets.reload() }, actions = {
        if (canEdit) IconButton(onClick = { if (tab == "categories") newCategory = true else newBudget = true }) { Icon(Icons.Default.Add, contentDescription = if (tab == "categories") "New category" else "New budget") }
    }) {
        SegmentedTabs(listOf("categories" to "Categories", "budgets" to "Budgets"), tab) { tab = it }
        if (tab == "categories") {
            LoadableView(categories.state, retry = { categories.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("No categories yet", icon = Icons.AutoMirrored.Filled.Label) }) { rows ->
                SectionCard { rows.forEach { c -> CategoryRow(c, 0, canEdit) { editingCategory = it } } }
                Text("Current-month spend and budget use are the server's (approved and paid expenses, KES).", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
            }
        } else {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                SelectField("Period", budgetType, listOf("monthly" to "Monthly", "quarterly" to "Quarterly", "annual" to "Annual"), { budgetType = it ?: "monthly" }, Modifier.weight(1f), placeholder = "Monthly")
                YearStepper(budgetYear) { budgetYear = it }
            }
            LoadableView(budgets.state, retry = { budgets.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("No budgets for this period", icon = Icons.Default.BarChart) }) { rows ->
                SectionCard {
                    rows.forEach { b ->
                        Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalAlignment = Alignment.Top) {
                            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                Text("${b["category"]["name"].str ?: "Category"} · ${periodLabel(b)}", style = MaterialTheme.typography.bodyMedium)
                                Text(b["outlet"]["name"].str ?: "All outlets", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                                val cmp = b.str("comparison_currency") ?: "KES"
                                val p = b.dbl("utilization_percent")
                                FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.m)) {
                                    Text("Budget ${fmtMoney(b["budgeted_amount"], b.str("currency_code") ?: "KES")}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                                    Text("Spent ${fmtMoney(b["actual_spend"], cmp)}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                                    Text("Variance ${fmtMoney(b["variance"], cmp)}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                                    if (p != null) Text(fmtPct(p, 0), style = MaterialTheme.typography.labelSmall, color = utilisationColor(p))
                                }
                                if (p != null) LinearProgressIndicator(progress = { (p.coerceIn(0.0, 100.0) / 100.0).toFloat() }, modifier = Modifier.fillMaxWidth(), color = if (p >= 100) Palette.danger else Palette.brand)
                            }
                            if (canEdit) TextButton(onClick = { editingBudget = b.json }) { Text("Edit") }
                        }
                        HorizontalDivider(color = MaterialTheme.hub.line)
                    }
                }
            }
        }
    }

    if (newCategory) CategoryFormSheet(null, flatCategories, onDismiss = { newCategory = false }) { newCategory = false; categories.load() }
    editingCategory?.let { c -> CategoryFormSheet(c, flatCategories, onDismiss = { editingCategory = null }) { editingCategory = null; categories.load() } }
    if (newBudget) BudgetFormSheet(null, flatCategories, onDismiss = { newBudget = false }) { newBudget = false; budgets.load() }
    editingBudget?.let { b -> BudgetFormSheet(b, flatCategories, onDismiss = { editingBudget = null }) { editingBudget = null; budgets.load() } }
}

@Composable
private fun utilisationColor(p: Double): Color = if (p >= 100) Palette.danger else if (p >= 80) Palette.warning else MaterialTheme.hub.textSecondary

@Composable
private fun YearStepper(year: Int, onChange: (Int) -> Unit) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        TextButton(enabled = year > 2020, onClick = { onChange(year - 1) }) { Text("−") }
        Text(year.toString(), style = MaterialTheme.typography.bodyMedium)
        TextButton(enabled = year < 2100, onClick = { onChange(year + 1) }) { Text("+") }
    }
}

private fun periodLabel(b: JRow): String {
    val n = b.int("period_number") ?: 1; val y = b.int("period_year") ?: 0
    return when (b.str("period_type")) {
        "monthly" -> "${java.time.Month.of(n.coerceIn(1, 12)).getDisplayName(java.time.format.TextStyle.FULL, java.util.Locale.UK)} $y"
        "quarterly" -> "Q$n $y"
        else -> "$y"
    }
}

private fun colorFromHex(hex: String?): Color? {
    val s = hex?.trim()?.removePrefix("#") ?: return null
    if (s.length != 6) return null
    val v = s.toLongOrNull(16) ?: return null
    return Color(0xFF000000 or v)
}

@Composable
private fun CategoryRow(c: JRow, depth: Int, canEdit: Boolean, edit: (JsonElement) -> Unit) {
    Column {
        Row(Modifier.fillMaxWidth().padding(start = (depth * 20).dp, top = 6.dp, bottom = 6.dp), verticalAlignment = Alignment.Top) {
            colorFromHex(c.str("color"))?.let { Box(Modifier.padding(top = 6.dp, end = Space.s).size(10.dp).background(it, CircleShape)) }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(c.str("name") ?: "", style = MaterialTheme.typography.bodyMedium)
                    Text(c.str("code") ?: "", style = MaterialTheme.typography.labelSmall.copy(fontFamily = FontFamily.Monospace), color = MaterialTheme.hub.textTertiary)
                    if (c["is_active"].bool == false) StatusBadge("inactive", StatusTone.NEUTRAL)
                    if (c["is_tax_deductible"].bool == true) StatusBadge("tax deductible", StatusTone.INFO)
                }
                val meta = listOfNotNull(c.str("description"), c.str("gl_code")?.let { "GL $it" }, c["requires_approval_above"]?.takeIf { !it.isNull }?.let { "approval above ${fmtMoney(it, "KES")}" }).joinToString(" · ")
                if (meta.isNotEmpty()) Text(meta, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.m)) {
                    Text("This month: ${fmtMoney(c["current_month_spend"], "KES")}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    c["budget_monthly"]?.takeIf { !it.isNull }?.let { Text("Monthly budget ${fmtMoney(it, "KES")}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                    c.dbl("budget_utilization_percent")?.let { p -> Text("${fmtPct(p, 0)} used", style = MaterialTheme.typography.labelSmall, color = utilisationColor(p)) }
                }
            }
            if (canEdit) TextButton(onClick = { edit(c.json) }) { Text("Edit") }
        }
        HorizontalDivider(color = MaterialTheme.hub.line)
        jrows(c["children"]).forEach { child -> CategoryRow(child, depth + 1, canEdit, edit) }
    }
}

@Composable
private fun CategoryFormSheet(existing: JsonElement?, parents: List<Pair<Int, String>>, onDismiss: () -> Unit, done: () -> Unit) {
    val env = LocalEnv.current
    fun text(key: String) = existing[key]?.takeIf { !it.isNull }?.str ?: ""
    var name by remember { mutableStateOf(text("name")) }
    var code by remember { mutableStateOf(text("code")) }
    var description by remember { mutableStateOf(text("description")) }
    var parentId by remember { mutableStateOf<Int?>(null) }
    var color by remember { mutableStateOf(text("color")) }
    var icon by remember { mutableStateOf("") }
    var approvalAbove by remember { mutableStateOf(text("requires_approval_above")) }
    var budgetMonthly by remember { mutableStateOf(text("budget_monthly")) }
    var budgetAnnual by remember { mutableStateOf(text("budget_annual")) }
    var taxDeductible by remember { mutableStateOf(existing["is_tax_deductible"].bool ?: false) }
    var active by remember { mutableStateOf(existing["is_active"].bool ?: true) }
    var gl by remember { mutableStateOf(text("gl_code")) }
    val isNew = existing == null

    FormSheet(if (isNew) "New category" else "Edit category", onDismiss, saveLabel = if (isNew) "Create" else "Save", canSave = name.isNotEmpty() && (!isNew || code.isNotEmpty()), save = {
        val common = listOf<Pair<String, JsonElement?>>(
            "name" to JsonPrimitive(name), "is_tax_deductible" to JsonPrimitive(taxDeductible),
            "description" to J.sOpt(description), "color" to J.sOpt(color), "gl_code" to J.sOpt(gl),
            "requires_approval_above" to J.decimal(approvalAbove), "budget_monthly" to J.decimal(budgetMonthly), "budget_annual" to J.decimal(budgetAnnual),
        )
        val id = existing["id"].int
        if (existing != null && id != null) {
            env.client.put("/v1/admin/expenses/categories/$id", J.obj(*(common + ("is_active" to JsonPrimitive(active))).toTypedArray()))
        } else {
            env.client.post("/v1/admin/expenses/categories", J.obj(*(common + listOf("code" to JsonPrimitive(code), "parent_id" to parentId?.let { JsonPrimitive(it) }, "icon" to J.sOpt(icon))).toTypedArray()))
        }
        env.toasts.show("Category saved"); done()
    }) { error ->
        Field("Name *", name, { name = it }, error = error?.field("name"))
        if (isNew) {
            Field("Code *", code, { code = it }, placeholder = "e.g. RENT", error = error?.field("code"))
            SelectField("Parent category", parentId, parents, { parentId = it }, placeholder = "None (top level)")
        }
        Field("Description", description, { description = it })
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Field("Colour (#hex)", color, { color = it }, Modifier.weight(1f), placeholder = "#6366f1", error = error?.field("color"))
            Field("GL code", gl, { gl = it }, Modifier.weight(1f))
        }
        DecimalField("Requires approval above (KES) — blank = category default", approvalAbove, { approvalAbove = it }, error = error?.field("requires_approval_above"))
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            DecimalField("Monthly budget (KES)", budgetMonthly, { budgetMonthly = it }, Modifier.weight(1f))
            DecimalField("Annual budget (KES)", budgetAnnual, { budgetAnnual = it }, Modifier.weight(1f))
        }
        ToggleRow("Tax deductible", taxDeductible, { taxDeductible = it })
        if (!isNew) ToggleRow("Active", active, { active = it })
    }
}

@Composable
private fun BudgetFormSheet(existing: JsonElement?, categories: List<Pair<Int, String>>, onDismiss: () -> Unit, done: () -> Unit) {
    val env = LocalEnv.current
    var categoryId by remember { mutableStateOf<Int?>(null) }
    var outletId by remember { mutableStateOf<Int?>(null) }
    var outlets by remember { mutableStateOf<List<Pair<Int, String>>>(emptyList()) }
    var periodType by remember { mutableStateOf("monthly") }
    var year by remember { mutableStateOf(Day.thisYear()) }
    var number by remember { mutableStateOf(java.time.LocalDate.now(ke.co.bethanyhouse.hub.core.HubDates.nairobi).monthValue) }
    var amount by remember { mutableStateOf(existing["budgeted_amount"].str ?: "") }
    var currency by remember { mutableStateOf("KES") }
    var notes by remember { mutableStateOf(existing["notes"].str ?: "") }
    val isNew = existing == null
    LaunchedEffect(Unit) { if (isNew) outlets = outletOptions(env.client, reports = false) }

    FormSheet(if (isNew) "New budget" else "Edit budget", onDismiss, saveLabel = if (isNew) "Create" else "Save", canSave = J.decimal(amount) != null && (!isNew || categoryId != null), save = {
        val id = existing["id"].int
        if (existing != null && id != null) {
            env.client.put("/v1/admin/expenses/budgets/$id", J.obj("budgeted_amount" to J.decimal(amount), "notes" to J.sOpt(notes)))
        } else {
            env.client.post("/v1/admin/expenses/budgets", J.obj(
                "budgeted_amount" to J.decimal(amount), "notes" to J.sOpt(notes),
                "category_id" to categoryId?.let { JsonPrimitive(it) }, "outlet_id" to outletId?.let { JsonPrimitive(it) },
                "period_type" to JsonPrimitive(periodType), "period_year" to JsonPrimitive(year), "period_number" to JsonPrimitive(if (periodType == "annual") 1 else number), "currency_code" to JsonPrimitive(currency),
            ))
        }
        env.toasts.show("Budget saved"); done()
    }) { error ->
        if (isNew) {
            SelectField("Category *", categoryId, categories, { categoryId = it }, placeholder = "Choose…")
            error?.field("category_id")?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = Palette.danger) }
            if (outlets.size > 1) SelectField("Outlet", outletId, outlets, { outletId = it }, placeholder = "All outlets")
            OptionPicker("Period", periodType, listOf("monthly" to "Monthly", "quarterly" to "Quarterly", "annual" to "Annual"), { periodType = it; if (periodType == "quarterly") number = number.coerceIn(1, 4) })
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.m)) {
                Text("Year", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary); YearStepper(year) { year = it }
            }
            if (periodType != "annual") {
                val max = if (periodType == "monthly") 12 else 4
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.m)) {
                    Text(if (periodType == "monthly") "Month" else "Quarter", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    TextButton(enabled = number > 1, onClick = { number -= 1 }) { Text("−") }
                    Text(number.toString(), style = MaterialTheme.typography.bodyMedium)
                    TextButton(enabled = number < max, onClick = { number += 1 }) { Text("+") }
                }
            }
            OptionPicker("Currency", currency, listOf("KES" to "KES", "USD" to "USD"), { currency = it })
        } else {
            Text("${existing["category"]["name"].str ?: ""} · ${existing["period_type"].str?.humanized ?: ""} ${existing["period_number"].int ?: 0}/${existing["period_year"].int ?: 0}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
        }
        DecimalField("Budgeted amount *", amount, { amount = it }, error = error?.field("budgeted_amount"))
        Field("Notes", notes, { notes = it })
    }
}

// ── Analytics (GET /expenses/summary) ────────────────────────────────────────

@Composable
fun ExpenseAnalyticsScreen() {
    val env = LocalEnv.current
    var preset by remember { mutableStateOf(ExpensesDatePreset.THIS_MONTH) }
    var customStart by remember { mutableStateOf(Day.today()) }
    var customEnd by remember { mutableStateOf(Day.today()) }
    var outletId by remember { mutableStateOf<Int?>(null) }
    var outlets by remember { mutableStateOf<List<Pair<Int, String>>>(emptyList()) }
    LaunchedEffect(Unit) { outlets = outletOptions(env.client, reports = false) }
    val window = ExpensesDateWindow(preset, customStart, customEnd, outletId)

    fun shareText(json: JsonElement): String {
        val t = json["totals"]
        val lines = mutableListOf(
            "Bethany House — expenses ${window.description}",
            "Spend (approved + paid): ${fmtMoney(t["total_amount"], "KES")} across ${t["total_count"].int ?: 0} expenses",
            "Paid out: ${fmtMoney(t["paid_amount"], "KES")} · Approved unpaid: ${fmtMoney(t["approved_unpaid_amount"], "KES")}",
            "Pending approval (not included): ${fmtMoney(json["pending"]["total"], "KES")}", "By category:",
        )
        jrows(json["by_category"]).forEach { r -> lines += "  ${r.str("name") ?: ""}: ${fmtMoney(r["total"], "KES")} (${r.int("count") ?: 0})" }
        lines += "Figures: server summary, KES at each expense's recorded rate."
        return lines.joinToString("\n")
    }

    Page("Expense analytics", subtitle = "${window.description} · ${outletId?.let { id -> outlets.firstOrNull { it.first == id }?.second } ?: "All outlets"}") {
        SelectField("Period", preset, ExpensesDatePreset.options, { preset = it ?: ExpensesDatePreset.THIS_MONTH }, placeholder = "This month")
        if (outlets.size > 1) SelectField("Outlet", outletId, outlets, { outletId = it }, placeholder = "All outlets")
        if (preset == ExpensesDatePreset.CUSTOM) Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            DayField("From", customStart, { if (it.isNotEmpty()) customStart = it }, Modifier.weight(1f))
            DayField("To", customEnd, { if (it.isNotEmpty()) customEnd = it }, Modifier.weight(1f))
        }
        RemoteJson("/v1/admin/expenses/summary", window.dateParams()) { json, _ ->
            val t = json["totals"]
            val pendingCount = json["pending"]["count"].int ?: 0
            TileGrid {
                StatTile("Spend (approved + paid)", fmtMoney(t["total_amount"], "KES"), hint = "${t["total_count"].int ?: 0} expenses · KES at the recorded rate", modifier = TileModifier)
                StatTile("Paid out", fmtMoney(t["paid_amount"], "KES"), modifier = TileModifier)
                StatTile("Approved, unpaid", fmtMoney(t["approved_unpaid_amount"], "KES"), modifier = TileModifier)
                StatTile("Average", fmtMoney(t["avg_amount"], "KES"), modifier = TileModifier)
                StatTile("Pending approval", fmtMoney(json["pending"]["total"], "KES"), hint = "$pendingCount waiting — not in the spend above", tone = if (pendingCount > 0) StatusTone.AMBER else null, modifier = TileModifier)
            }
            SectionCard("By category") {
                BarsChart(chartPoints(json["by_category"], label = "name", y = "total"))
                DataTable(columns = listOf(TableColumn("Category", "name", width = 160.dp), TableColumn("Code", "code", width = 80.dp), TableColumn("Count", "count", TableColumn.Kind.INT, width = 70.dp), TableColumn("Total", "total", TableColumn.Kind.MONEY, fixedCurrency = "KES")), rows = jrows(json["by_category"]))
            }
            SectionCard("Monthly trend (last 12 months, all statuses that count as spend)") {
                LinesChart(chartPoints(json["monthly_trend"], label = "month", y = "total", series = "Spend"))
            }
            SectionCard("By payment method") {
                DataTable(columns = listOf(TableColumn("Method", "payment_method", TableColumn.Kind.STATUS), TableColumn("Count", "count", TableColumn.Kind.INT, width = 70.dp), TableColumn("Total", "total", TableColumn.Kind.MONEY, fixedCurrency = "KES")), rows = jrows(json["by_payment_method"]))
            }
            ShareTextButton("Share summary", shareText(json))
        }
    }
}
