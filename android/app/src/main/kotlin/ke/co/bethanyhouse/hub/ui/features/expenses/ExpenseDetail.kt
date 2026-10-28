package ke.co.bethanyhouse.hub.ui.features.expenses

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
import androidx.compose.material.icons.filled.Description
import androidx.compose.material.icons.filled.Image
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
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
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.date
import ke.co.bethanyhouse.hub.core.dbl
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.AsyncButton
import ke.co.bethanyhouse.hub.ui.components.AttachmentPicker
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.DataTable
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.DayField
import ke.co.bethanyhouse.hub.ui.components.DecimalField
import ke.co.bethanyhouse.hub.ui.components.DetailRow
import ke.co.bethanyhouse.hub.ui.components.DocumentButton
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FieldBox
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.TableColumn
import ke.co.bethanyhouse.hub.ui.components.fmtNumber
import ke.co.bethanyhouse.hub.ui.components.jrows
import ke.co.bethanyhouse.hub.ui.components.personName
import ke.co.bethanyhouse.hub.ui.components.uploadFile
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonPrimitive

/**
 * One expense (GET /expenses/{id}): the record, its line items, approvals
 * and evidence, with every workflow action the console's detail page
 * offers for a person who holds the gate (ExpenseDetailPage.tsx).
 */
@Composable
fun ExpenseDetailScreen(id: Int) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val resource = remember(id) { Resource(scope) { env.client.get("/v1/admin/expenses/$id").json ?: JsonNull } }
    LaunchedEffect(resource) { resource.load() }
    var editing by remember { mutableStateOf(false) }
    var markingPaid by remember { mutableStateOf(false) }
    var rejecting by remember { mutableStateOf(false) }
    var requestingChanges by remember { mutableStateOf(false) }
    var approving by remember { mutableStateOf(false) }
    var evidenceKind by remember { mutableStateOf("receipt") }
    var uploading by remember { mutableStateOf(false) }

    fun load() = resource.load()
    val current: ExpenseRow? = resource.value?.let { ExpenseRow(it["expense"] ?: it) }

    suspend fun post(action: String, body: JsonElement = J.obj()) {
        try {
            val r = env.client.post("/v1/admin/expenses/$id/$action", body)
            env.toasts.show(r.json["message"].str ?: "Done")
            load()
        } catch (e: ApiError) { env.toasts.error(e) }
    }
    suspend fun delete() {
        try { env.client.delete("/v1/admin/expenses/$id"); env.toasts.show("Expense deleted"); env.navigator.go(Route.Expenses) } catch (e: ApiError) { env.toasts.error(e) }
    }
    suspend fun resolve(resolution: String) {
        try {
            val r = env.client.post("/v1/admin/expenses/$id/imprest-resolution", J.obj("resolution" to JsonPrimitive(resolution)))
            env.toasts.show(r.json["message"].str ?: "Resolved"); load()
        } catch (e: ApiError) { env.toasts.error(e) }
    }
    suspend fun removeAttachment(aid: Int) {
        try { val r = env.client.delete("/v1/admin/expenses/$id/attachments/$aid"); env.toasts.show(r.json["message"].str ?: "Removed"); load() } catch (e: ApiError) { env.toasts.error(e) }
    }

    Page("Expense", refresh = { resource.reload() }) {
        LoadableView(resource.state, retry = { load() }) { json ->
            val e = ExpenseRow(json["expense"] ?: json)
            ExpenseHeader(e)
            // Actions
            val s = env.session.current
            val me = s.me
            val recordedByMe = me != null && (expensesIdOf(e.json["created_by"]) == me.id || expensesIdOf(e.json["submitted_by"]) == me.id)
            val ownerOverride = (me?.isOwner ?: false) && recordedByMe
            val canApproveThis = s.can("expenses.approve") && (!recordedByMe || ownerOverride)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                DocumentButton("PDF", "/v1/admin/pdf/expenses/${e.id}", suggestedName = "${e.reference}.pdf")
                if (e.status in listOf("draft", "changes_requested") && s.can("expenses.create")) AsyncButton("Submit for approval") { post("submit") }
                if (e.status == "pending_approval") {
                    if (canApproveThis) Button(onClick = { approving = true }, colors = ButtonDefaults.buttonColors(containerColor = Palette.success)) { Text(if (ownerOverride) "Approve (owner override)" else "Approve") }
                    if (s.can("expenses.approve")) {
                        OutlinedButton(onClick = { requestingChanges = true }) { Text("Request changes") }
                        OutlinedButton(onClick = { rejecting = true }) { Text("Reject", color = Palette.danger) }
                    }
                }
                if (e.status == "approved" && s.can("expenses.approve")) Button(onClick = { markingPaid = true }) { Text("Mark as paid") }
                if (e.status in listOf("draft", "rejected", "changes_requested") && s.can("expenses.edit")) OutlinedButton(onClick = { editing = true }) { Text("Edit") }
                if (e.status in listOf("draft", "pending_approval", "changes_requested", "approved") && s.can("expenses.edit")) {
                    ConfirmButton("Cancel expense", "The expense is cancelled; an imprest spend then waits on the super admin to return or write off the cash.", destructive = true, action = { post("cancel") }) { Text("Cancel") }
                }
                if (e.status in listOf("draft", "rejected", "cancelled") && s.can("expenses.delete")) {
                    ConfirmButton("Delete expense", "This cannot be undone.", destructive = true, action = { delete() }) { Text("Delete") }
                }
            }
            if (e.status == "pending_approval" && s.can("expenses.approve") && !canApproveThis) Text("You recorded this; someone else must approve it.", style = MaterialTheme.typography.labelSmall, color = Palette.warning)
            if (e.isImprest && e.imprestResolution == "pending" && me?.isSuperAdmin == true) SectionCard("Imprest resolution") {
                Text("This expense was paid from the imprest and is ${e.status.humanized.lowercase()}. Was the cash returned to the box, or is it written off?", style = MaterialTheme.typography.bodySmall)
                Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    AsyncButton("Cash returned") { resolve("returned") }
                    AsyncButton("Write off", outlined = true) { resolve("written_off") }
                }
            }
            ExpenseDetails(e)
            val items = e.json["lineItems"].records.ifEmpty { e.json["line_items"].records }
            if (items.isNotEmpty()) SectionCard("Line items") {
                DataTable(columns = listOf(
                    TableColumn("Description", "description", width = 180.dp), TableColumn("Qty", "quantity", TableColumn.Kind.NUMBER, width = 60.dp),
                    TableColumn("Unit price", "unit_price", TableColumn.Kind.MONEY, fixedCurrency = e.currency), TableColumn("Tax", "tax_amount", TableColumn.Kind.MONEY, fixedCurrency = e.currency),
                    TableColumn("Amount", "amount", TableColumn.Kind.MONEY, fixedCurrency = e.currency),
                ), rows = jrows(kotlinx.serialization.json.JsonArray(items)))
            }
            ExpenseApprovals(e)
            // Evidence (append-only; a removed file stays listed as removed)
            SectionCard("Evidence") {
                val rows = jrows(e.json["attachments"])
                if (rows.isEmpty() && e.json["receipt_path"].str == null) Text("No receipt or photo yet.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textTertiary)
                rows.forEach { a ->
                    val removed = !a["removed_at"].isNull
                    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(if ((a.str("mime_type") ?: "").startsWith("image")) Icons.Default.Image else Icons.Default.Description, contentDescription = null, tint = MaterialTheme.hub.textTertiary, modifier = Modifier.size(20.dp))
                        Spacer(Modifier.width(Space.s))
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.xs), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                Text(a.str("original_name") ?: "File", style = MaterialTheme.typography.bodyMedium, maxLines = 1)
                                StatusBadge(a.str("kind"), StatusTone.NEUTRAL)
                                if (removed) StatusBadge("removed", StatusTone.DANGER)
                            }
                            Text("${personName(a["uploader"]) ?: ""} · ${a.int("size_bytes")?.let(::byteCount) ?: ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        }
                        val aid = a.int("id")
                        if (!removed && aid != null) {
                            ExpenseSignedFileButton("Open", "/v1/admin/expenses/${e.id}/attachments/$aid", a.str("original_name") ?: "attachment")
                            if (a["can_remove"].bool == true) ConfirmButton("Withdraw this file", "It stays on record as removed.", destructive = true, action = { removeAttachment(aid) }) { Text("Remove") }
                        }
                    }
                }
                if (e.json["receipt_path"].str != null && rows.isEmpty()) ExpenseSignedFileButton("Open receipt", "/v1/admin/expenses/${e.id}/receipt", "receipt")
                if (s.can("expenses.create")) {
                    OptionPicker("Kind", evidenceKind, ExpenseVocab.evidenceKinds, { evidenceKind = it })
                    if (uploading) Text("Uploading…", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    else AttachmentPicker("Add evidence") { files ->
                        scope.launch {
                            uploading = true
                            try {
                                for (f in files) {
                                    try { val r = env.client.uploadFile("/v1/admin/expenses/$id/attachments", f, fields = mapOf("kind" to evidenceKind)); env.toasts.show(r.json["message"].str ?: "Added") }
                                    catch (ex: ApiError) { env.toasts.error(ex) }
                                }
                            } finally { uploading = false }
                            load()
                        }
                    }
                }
            }
        }
    }

    val c = current
    if (editing && c != null) ExpenseEditSheet(c, onDismiss = { editing = false }) { editing = false; load() }
    if (markingPaid && c != null) MarkPaidSheet(c, onDismiss = { markingPaid = false }) { markingPaid = false; load() }
    if (approving && c != null) ApproveSheet(c, onDismiss = { approving = false }) { approving = false; load() }
    if (rejecting) ReasonSheet("Reject this expense", "Reject", destructive = true, onDismiss = { rejecting = false }) { r -> post("reject", J.obj("reason" to JsonPrimitive(r))); rejecting = false }
    if (requestingChanges) ReasonSheet("Send back for changes", "Request changes", onDismiss = { requestingChanges = false }) { r -> post("request-changes", J.obj("reason" to JsonPrimitive(r))); requestingChanges = false }
}

private fun byteCount(b: Int): String = when {
    b >= 1_000_000 -> String.format(java.util.Locale.US, "%.1f MB", b / 1_000_000.0)
    b >= 1_000 -> "${b / 1_000} KB"
    else -> "$b bytes"
}

private fun latestNote(e: ExpenseRow, action: String): String? =
    jrows(e.json["approvals"]).filter { it.str("action") == action }.sortedByDescending { it.str("acted_at") ?: "" }.firstOrNull()?.str("comments")

@Composable
private fun ExpenseHeader(e: ExpenseRow) {
    SectionCard {
        Row(verticalAlignment = Alignment.Top) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(e.reference, style = MaterialTheme.typography.bodySmall.copy(fontFamily = FontFamily.Monospace))
                    StatusBadge(e.status)
                    if (e.isImprest) StatusBadge("paid from imprest", StatusTone.ACCENT)
                }
                Text(e.title, style = MaterialTheme.typography.titleLarge)
                Text(listOfNotNull(e.category, e.vendor, e.outlet ?: "Head office").joinToString(" · "), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                DateText(e.date, DateStyle.DATE)
            }
            Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                MoneyText(e.amount, emphasis = true)
                if (e.currency != "KES") {
                    val k = e.amountKes
                    Text(
                        if (k != null) "= ${k.formatted()} at ${fmtNumber(e.json["exchange_rate"].dbl, 4)} KES/${e.currency} (recorded rate)" else "no reporting rate",
                        style = MaterialTheme.typography.labelSmall, color = if (k != null) MaterialTheme.hub.textSecondary else Palette.warning,
                    )
                }
            }
        }
        if (e.status == "changes_requested") latestNote(e, "changes_requested")?.let { Text("Sent back: $it", style = MaterialTheme.typography.bodySmall, color = Palette.pink) }
        if (e.status == "rejected") e.json["rejection_reason"].str?.let { Text("Rejected: $it", style = MaterialTheme.typography.bodySmall, color = Palette.danger) }
    }
}

@Composable
private fun ExpenseDetails(e: ExpenseRow) {
    val j = e.json
    SectionCard("Details") {
        DetailRow("Payment method", ExpenseVocab.methodLabel(j["payment_method"].str))
        DetailRow("Payment reference", j["payment_reference"].str)
        DetailRow("Vendor contact", j["vendor_contact"].str)
        DetailRow("Department", j["department"].str)
        DetailRow("Recurring", if (j["is_recurring"].bool == true) (j["recurrence_frequency"].str?.humanized ?: "Yes") else "No")
        j["description"].str?.takeIf { it.isNotEmpty() }?.let { DetailRow("Description", it) }
        j["notes"].str?.takeIf { it.isNotEmpty() }?.let { DetailRow("Notes", it) }
        j["tags"].records.mapNotNull { it.str }.takeIf { it.isNotEmpty() }?.let { DetailRow("Tags", it.joinToString(", ")) }
        DetailRow("Recorded by", personName(j["createdBy"]) ?: personName(j["created_by"]) ?: j["created_by"].str)
        j["submitted_at"].date?.let { d -> DetailRow("Submitted") { DateText(d) } }
        j["approved_at"].date?.let { d -> DetailRow("Approved") { Row(horizontalArrangement = Arrangement.spacedBy(Space.xs)) { Text(personName(j["approvedBy"]) ?: "", style = MaterialTheme.typography.bodySmall); DateText(d) } } }
        j["paid_at"].date?.let { d -> DetailRow("Paid") { DateText(d) } }
        e.imprestResolution?.let { r -> DetailRow("Imprest resolution") { StatusBadge(r) } }
    }
}

@Composable
private fun ExpenseApprovals(e: ExpenseRow) {
    val rows = jrows(e.json["approvals"])
    if (rows.isEmpty()) return
    SectionCard("Approval history") {
        rows.forEach { a ->
            Row(Modifier.padding(vertical = 4.dp), verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                StatusBadge(a.str("action"))
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.xs), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Text(personName(a["approver"]) ?: "Approver", style = MaterialTheme.typography.bodyMedium)
                        if (a["owner_override"].bool == true) StatusBadge("owner override", StatusTone.WARNING)
                        a.int("step")?.let { Text("step $it", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
                    }
                    a.str("comments")?.takeIf { it.isNotEmpty() }?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary) }
                    DateText(a["acted_at"].date)
                }
            }
        }
    }
}

// ── Edit (PUT /expenses/{id}; drafts, rejected and sent-back only) ───────────

@Composable
private fun ExpenseEditSheet(expense: ExpenseRow, onDismiss: () -> Unit, done: () -> Unit) {
    val env = LocalEnv.current
    val j = expense.json
    var title by remember { mutableStateOf(expense.title) }
    var description by remember { mutableStateOf(j["description"].str ?: "") }
    var amount by remember { mutableStateOf(expense.amount?.amount?.toPlainString() ?: "") }
    var date by remember { mutableStateOf(j["expense_date"].str?.take(10) ?: Day.today()) }
    var method by remember { mutableStateOf(j["payment_method"].str ?: "cash") }
    var reference by remember { mutableStateOf(j["payment_reference"].str ?: "") }
    var vendor by remember { mutableStateOf(j["vendor_name"].str ?: "") }
    var department by remember { mutableStateOf(j["department"].str ?: "") }
    var notes by remember { mutableStateOf(j["notes"].str ?: "") }
    var categoryId by remember { mutableStateOf(j["category_id"].int) }
    var categories by remember { mutableStateOf<List<Pair<Int, String>>>(emptyList()) }
    LaunchedEffect(Unit) { categories = loadExpenseCategories(env.client) }

    FormSheet("Edit ${expense.reference}", onDismiss, saveLabel = "Save changes", canSave = title.isNotEmpty() && amount.isNotEmpty(), save = {
        val body = J.obj(
            "title" to JsonPrimitive(title), "expense_date" to JsonPrimitive(date), "payment_method" to JsonPrimitive(method), "currency_code" to JsonPrimitive(expense.currency),
            "amount" to J.decimal(amount), "category_id" to (categoryId ?: j["category_id"].int)?.let { JsonPrimitive(it) },
            "payment_reference" to J.sOpt(reference), "vendor_name" to J.sOpt(vendor), "department" to J.sOpt(department),
            "description" to J.sOpt(description), "notes" to J.sOpt(notes),
        )
        env.client.put("/v1/admin/expenses/${expense.id}", body)
        env.toasts.show("Saved"); done()
    }) { error ->
        Field("Title", title, { title = it }, error = error?.field("title"))
        SelectField("Category", categoryId, categories, { categoryId = it }, placeholder = "Keep")
        FieldBox("Date", error = error?.field("expense_date")) { DayField("Date", date, { if (it.isNotEmpty()) date = it }) }
        DecimalField("Amount (${expense.currency})", amount, { amount = it }, error = error?.field("amount"))
        OptionPicker("Payment method", method, ExpenseVocab.paymentMethods, { method = it })
        Field("Payment reference", reference, { reference = it })
        Field("Vendor", vendor, { vendor = it })
        Field("Department", department, { department = it })
        Field("Description", description, { description = it }, multiline = true)
        Field("Notes", notes, { notes = it }, multiline = true)
    }
}

@Composable
private fun MarkPaidSheet(expense: ExpenseRow, onDismiss: () -> Unit, done: () -> Unit) {
    val env = LocalEnv.current
    var reference by remember { mutableStateOf("") }
    var method by remember { mutableStateOf("") }
    FormSheet("Mark ${expense.reference} as paid", onDismiss, saveLabel = "Mark as paid", save = {
        val r = env.client.post("/v1/admin/expenses/${expense.id}/mark-paid", J.obj("payment_reference" to J.sOpt(reference), "payment_method" to J.sOpt(method)))
        env.toasts.show(r.json["message"].str ?: "Marked as paid"); done()
    }) { _ ->
        Text("Records that the money went out. The amount stays ${expense.amount?.formatted() ?: "—"}.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
        Field("Payment reference", reference, { reference = it }, placeholder = "Optional")
        OptionPicker("Payment method", method, ExpenseVocab.paymentMethods, { method = it }, allowNone = true)
        Text("Leave the method blank to keep it as recorded.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
    }
}

@Composable
private fun ApproveSheet(expense: ExpenseRow, onDismiss: () -> Unit, done: () -> Unit) {
    val env = LocalEnv.current
    val me = env.session.current.me
    val mine = me != null && expensesIdOf(expense.json["created_by"]) == me.id
    val override = (me?.isOwner ?: false) && mine
    var comments by remember { mutableStateOf("") }
    FormSheet("Approve ${expense.reference}", onDismiss, saveLabel = if (override) "Approve as owner" else "Approve", save = {
        val r = env.client.post("/v1/admin/expenses/${expense.id}/approve", J.obj("comments" to J.sOpt(comments), "owner_override" to if (override) JsonPrimitive(true) else null))
        env.toasts.show(r.json["message"].str ?: "Approved"); done()
    }) { _ ->
        Text("Your signature approves ${expense.amount?.formatted() ?: "this expense"} at the current band. Above the band the engine waits for the next signature.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
        if (override) Text("You recorded this expense. The owner alone may approve their own record; the server will first confirm it is you, and the approval is marked as an owner override.", style = MaterialTheme.typography.labelSmall, color = Palette.warning)
        Field("Comments", comments, { comments = it }, placeholder = "Optional", multiline = true)
    }
}
