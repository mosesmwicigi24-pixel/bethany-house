package ke.co.bethanyhouse.hub.ui.features.people

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Download
import androidx.compose.material.icons.filled.Folder
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.School
import androidx.compose.material3.Button
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.Money
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
import ke.co.bethanyhouse.hub.ui.components.AsyncButton
import ke.co.bethanyhouse.hub.ui.components.AsyncTextButton
import ke.co.bethanyhouse.hub.ui.components.AttachmentPicker
import ke.co.bethanyhouse.hub.ui.components.Avatar
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.DayField
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.KeyValueGrid
import ke.co.bethanyhouse.hub.ui.components.ModuleRowCard
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.PickedFile
import ke.co.bethanyhouse.hub.ui.components.Pill
import ke.co.bethanyhouse.hub.ui.components.RemoteJson
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.SupportListRow
import ke.co.bethanyhouse.hub.ui.components.toastResult
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement

/** Employee files (People PR 2): the list, and one person's file. */
@Composable
fun PeopleListScreen() {
    val env = LocalEnv.current
    var typed by remember { mutableStateOf("") }
    var q by remember { mutableStateOf("") }
    var department by remember { mutableStateOf("") }
    var page by remember { mutableIntStateOf(1) }
    var showExpiring by remember { mutableStateOf(false) }
    Page("Employee files", subtitle = "Everyone on the payroll and their paperwork") {
        ModuleSearchField(typed, { typed = it }, "Search by name, email or phone", onSubmit = { q = typed; page = 1 })
        OutlinedButton(onClick = { showExpiring = !showExpiring }) { Text("Expiring documents") }
        if (showExpiring) RemoteJson("/v1/admin/people/documents/expiring", query = mapOf("days" to "60"), emptyTitle = "Nothing expires in the next 60 days", isEmpty = { it["data"].records.isEmpty() }) { j, _ ->
            SectionCard("Expiring within 60 days") {
                j["data"].toRecords().forEach { d ->
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                        Column(Modifier.weight(1f)) {
                            Text(d["user"]["name"].str ?: "—", style = MaterialTheme.typography.bodyMedium)
                            Text("${d.string("title") ?: ""} (${d.string("kind")?.humanized ?: ""})", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                        }
                        Text(Day.format(d.string("expires_at")), style = MaterialTheme.typography.labelSmall, color = if (d["expired"].bool == true) Palette.danger else Palette.warning)
                        d["user"]["id"].int?.let { uid -> TextButton(onClick = { env.navigator.push(Route.People(uid)) }) { Text("Open") } }
                    }
                }
            }
        }
        RemoteJson("/v1/admin/people", query = mapOf("q" to q.ifEmpty { null }, "department" to department.ifEmpty { null }, "page" to page.toString(), "per_page" to "25"), emptyTitle = "No people match", isEmpty = { it["data"].records.isEmpty() }) { j, _ ->
            val departments = j["departments"].records.mapNotNull { it.str }
            if (departments.isNotEmpty()) PeopleChoice("Department", department, listOf("" to "All departments") + departments.map { it to it }, { department = it; page = 1 })
            j["data"].toRecords().forEach { p ->
                ModuleRowCard(onClick = { env.navigator.push(Route.People(p.int("id") ?: 0)) }) {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.m)) {
                        Avatar(p.string("name") ?: "?", 36.dp)
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Text(p.string("name") ?: "—", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                            Text(listOfNotNull(p.string("job_title"), p.string("department"), p.string("employment_type")?.humanized).joinToString(" · "), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                            Text(p["outlets"].records.mapNotNull { it.str }.joinToString(", "), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
                        }
                        Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            StatusBadge(p.status)
                            Text("${p.int("documents_count") ?: 0} docs", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                            if ((p.int("expiring_count") ?: 0) > 0) Pill("${p.int("expiring_count")} expiring", StatusTone.WARNING)
                        }
                    }
                }
            }
            val current = j["current_page"].int ?: 1; val last = j["last_page"].int ?: 1
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                TextButton(onClick = { page = maxOf(1, page - 1) }, enabled = current > 1) { Text("Previous") }
                Text("Page $current of $last · ${j["total"].int ?: 0} people", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary, modifier = Modifier.weight(1f))
                TextButton(onClick = { page += 1 }, enabled = current < last) { Text("Next") }
            }
        }
    }
}

// ── One person (GET /people/{id}) ────────────────────────────────────────────

@Composable
fun PersonFileScreen(personId: Int) {
    val env = LocalEnv.current
    var tab by remember { mutableStateOf("file") }
    var version by remember { mutableIntStateOf(0) }
    var editing by remember { mutableStateOf(false) }
    RemoteJson("/v1/admin/people/$personId", version = version) { j, reload ->
        val p = j["person"]
        val can = p["can"]
        Page(p["name"].str ?: "Employee file", subtitle = listOfNotNull(p["profile"]["job_title"].str, p["profile"]["department"].str).joinToString(" · "), refresh = { reload() }) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.m)) {
                Avatar(p["name"].str ?: "?", 56.dp)
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(p["name"].str ?: "—", style = MaterialTheme.typography.titleLarge)
                    Text(listOfNotNull(p["email"].str, p["phone"].str).joinToString(" · "), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        p["roles"].records.mapNotNull { it.str }.forEach { Pill(it, StatusTone.INFO) }
                        StatusBadge(p["status"].str)
                    }
                }
                OutlinedButton(onClick = { env.navigator.go(Route.Comms()) }) { Text("Message") }
            }
            SegmentedTabs(listOf("file" to "File", "documents" to "Documents", "pay" to "Pay", "leave" to "Leave", "training" to "Training", "signing" to "Signing", "timesheet" to "Hours"), tab) { tab = it }
            when (tab) {
                "file" -> FileTab(personId, p, can) { editing = true }
                "documents" -> PersonDocumentsPanel(personId, p["documents"].toRecords(), can["edit"].bool ?: false, reload)
                "pay" -> PersonPayPanel(personId, can["view_sensitive"].bool ?: env.session.current.can("hr.view_sensitive"))
                "leave" -> PersonLeavePanel(personId)
                "training" -> TrainingTab(p)
                "signing" -> PersonSigningPanel(personId, p["documents"].toRecords(), can["edit"].bool ?: false)
                "timesheet" -> RemoteJson("/v1/admin/timesheets/person/$personId") { s, _ ->
                    Text("Period ${Day.format(s["period"]["starts_on"].str)} – ${Day.format(s["period"]["ends_on"].str)}", style = MaterialTheme.typography.titleMedium)
                    TimesheetBody(s)
                }
            }
        }
        if (editing) PersonProfileSheet(personId, p["profile"], onDismiss = { editing = false }) { version += 1 }
    }
}

@Composable
private fun FileTab(personId: Int, p: JsonElement?, can: JsonElement?, edit: () -> Unit) {
    val env = LocalEnv.current
    val pr = p["profile"]
    SectionCard("Employment") {
        KeyValueGrid(listOf(
            "Job title" to (pr["job_title"].str ?: "—"), "Department" to (pr["department"].str ?: "—"),
            "Employment" to (pr["employment_type"].str?.humanized ?: "—"), "Hired" to (pr["hire_date"].str?.let { Day.format(it, "d MMM yyyy") } ?: "—"),
            "Ends" to (pr["end_date"].str?.let { Day.format(it, "d MMM yyyy") } ?: "—"), "Date of birth" to (pr["date_of_birth"].str?.let { Day.format(it, "d MMM yyyy") } ?: "—"),
            "Outlets" to p["outlets"].records.mapNotNull { it["name"].str }.joinToString(", "), "Reports to" to (p["reports_to"]["name"].str ?: "—"),
            "Pay method" to (pr["pay_method"].str?.humanized ?: "—"), "Bank" to listOfNotNull(pr["bank_name"].str, pr["bank_branch"].str, pr["bank_account_name"].str).joinToString(" · "),
        ))
        if (can["edit"].bool == true) OutlinedButton(onClick = edit) { Text("Edit file…") }
    }
    SectionCard("Emergency contacts") {
        KeyValueGrid(listOf(
            "Contact 1" to listOfNotNull(pr["emergency_contact_name"].str, pr["emergency_contact_phone"].str, pr["emergency_contact_relationship"].str).joinToString(" · "),
            "Contact 2" to listOfNotNull(pr["emergency_contact_2_name"].str, pr["emergency_contact_2_phone"].str, pr["emergency_contact_2_relationship"].str).joinToString(" · "),
        ))
    }
    pr["notes"].str?.takeIf { it.isNotEmpty() }?.let { SectionCard("Notes") { Text(it, style = MaterialTheme.typography.bodySmall) } }
    if (can["view_sensitive"].bool == true || env.session.current.can("hr.view_sensitive")) SensitiveCard(personId, p["masked"], can["edit_sensitive"].bool ?: false)
    val recs = p["recognitions"].toRecords()
    if (recs.isNotEmpty() || (p["recognition_points"].int ?: 0) > 0) SectionCard("Recognition · ${p["recognition_points"].int ?: 0} points") {
        recs.forEach { r -> Text("${r.string("by") ?: "Someone"}: ${r.string("body") ?: ""}" + (r.int("points")?.let { " (+$it)" } ?: ""), style = MaterialTheme.typography.bodySmall) }
    }
}

@Composable
private fun TrainingTab(p: JsonElement?) {
    val courses = p["courses"].toRecords()
    if (courses.isEmpty()) EmptyState("No courses assigned", icon = Icons.Default.School)
    courses.forEach { c ->
        SupportListRow(c.string("title") ?: "", "${c.int("attempts") ?: 0} attempts" + (c["best_score"].str?.let { " · best $it%" } ?: "") + (c.string("completed_at")?.let { " · done ${Day.format(it)}" } ?: "")) {
            Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(4.dp)) {
                if (c["required"].bool == true) Pill("required", StatusTone.INFO)
                val passed = c["passed"].bool == true
                Pill(if (passed) "passed" else "not yet", if (passed) StatusTone.SUCCESS else StatusTone.AMBER)
            }
        }
    }
}

// ── Sensitive card (GET/PUT /people/{id}/sensitive — step-up) ────────────────

private val sensitiveKeys = listOf("national_id_no" to "National ID", "kra_pin" to "KRA PIN", "nssf_no" to "NSSF", "shif_no" to "SHIF", "mpesa_phone" to "M-Pesa phone", "bank_account_no" to "Bank account")

@Composable
private fun SensitiveCard(personId: Int, masked: JsonElement?, canEdit: Boolean) {
    val env = LocalEnv.current
    var full by remember { mutableStateOf<JsonElement?>(null) }
    var editing by remember { mutableStateOf(false) }
    SectionCard("Identifiers & pay details") {
        val src = full ?: masked
        KeyValueGrid(sensitiveKeys.map { (k, label) -> label to (src[k].str ?: "—") })
        FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
            if (full == null) AsyncButton("Reveal (asks you to confirm it's you)", outlined = true) { full = env.client.get("/v1/admin/people/$personId/sensitive").json["sensitive"] }
            else {
                OutlinedButton(onClick = { full = null }) { Text("Hide") }
                if (canEdit) OutlinedButton(onClick = { editing = true }) { Text("Edit…") }
            }
        }
    }
    if (editing) SensitiveEditSheet(personId, full, onDismiss = { editing = false }) { full = it }
}

@Composable
private fun SensitiveEditSheet(personId: Int, values: JsonElement?, onDismiss: () -> Unit, saved: (JsonElement) -> Unit) {
    val env = LocalEnv.current
    var v by remember { mutableStateOf(sensitiveKeys.associate { (k, _) -> k to (values[k].str ?: "") }) }
    FormSheet("Edit identifiers", onDismiss, save = {
        val r = env.client.put("/v1/admin/people/$personId/sensitive", J.obj(*sensitiveKeys.map { (k, _) -> k to J.s(v[k]) }.toTypedArray()))
        toastResult(env, r)
        r.json["sensitive"]?.let(saved)
    }) { e ->
        sensitiveKeys.forEach { (k, label) -> Field(label, v[k] ?: "", { x -> v = v + (k to x) }, error = e?.field(k)) }
    }
}

// ── Profile (PUT /people/{id}) ───────────────────────────────────────────────

private val profileText = listOf("job_title" to "Job title", "department" to "Department", "hire_date" to "Hire date (yyyy-mm-dd)", "end_date" to "End date", "date_of_birth" to "Date of birth",
    "emergency_contact_name" to "Emergency contact", "emergency_contact_phone" to "Phone", "emergency_contact_relationship" to "Relationship",
    "emergency_contact_2_name" to "Second contact", "emergency_contact_2_phone" to "Phone", "emergency_contact_2_relationship" to "Relationship",
    "bank_name" to "Bank", "bank_branch" to "Branch", "bank_account_name" to "Account name", "notes" to "Notes")

@Composable
private fun PersonProfileSheet(personId: Int, profile: JsonElement?, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    var f by remember { mutableStateOf(profileText.associate { (k, _) -> k to (profile[k].str ?: "") } + mapOf("employment_type" to (profile["employment_type"].str ?: "permanent"), "pay_method" to (profile["pay_method"].str ?: ""))) }
    var payGroup by remember { mutableStateOf(profile["pay_group_id"].int) }
    var groups by remember { mutableStateOf<List<Record>>(emptyList()) }
    LaunchedEffect(Unit) { groups = runCatching { env.client.get("/v1/admin/pay-groups").json["data"].toRecords() }.getOrDefault(emptyList()) }
    FormSheet("Edit employee file", onDismiss, save = {
        val pairs = profileText.map { (k, _) -> k to J.s(f[k]) } + listOf("employment_type" to J.s(f["employment_type"]), "pay_method" to J.s(f["pay_method"]), "pay_group_id" to J.i(payGroup))
        val r = env.client.put("/v1/admin/people/$personId", J.obj(*pairs.toTypedArray()))
        toastResult(env, r); saved()
    }) { e ->
        PeopleChoice("Employment type", f["employment_type"] ?: "", listOf("permanent" to "Permanent", "contract" to "Contract", "casual" to "Casual", "intern" to "Intern"), { x -> f = f + ("employment_type" to x) })
        PeopleChoice("Pay method", f["pay_method"] ?: "", listOf("" to "—", "mpesa" to "M-Pesa", "bank" to "Bank", "cash" to "Cash"), { x -> f = f + ("pay_method" to x) })
        PeopleIntChoice("Pay group", payGroup, groups.mapNotNull { g -> g.intId?.let { it to (g.string("name") ?: "") } }, { payGroup = it }, placeholder = "—")
        profileText.forEach { (k, label) -> Field(label, f[k] ?: "", { x -> f = f + (k to x) }, error = e?.field(k), multiline = k == "notes") }
    }
}

// ── Documents: list, open (signed link), upload (multipart), remove ──────────

private val documentKinds = listOf("national_id" to "National ID", "kra_pin" to "KRA PIN certificate", "nssf" to "NSSF card", "shif" to "SHIF card", "contract" to "Contract", "certificate" to "Certificate", "passport_photo" to "Passport photo", "medical" to "Medical", "signed" to "Signed record", "other" to "Other")

@Composable
private fun PersonDocumentsPanel(personId: Int, documents: List<Record>, canEdit: Boolean, reload: () -> Unit) {
    val env = LocalEnv.current
    var picked by remember { mutableStateOf<PickedFile?>(null) }
    var kind by remember { mutableStateOf("other") }
    var title by remember { mutableStateOf("") }
    var expires by remember { mutableStateOf("") }
    if (documents.isEmpty()) EmptyState("No documents on file", icon = Icons.Default.Folder)
    documents.forEach { d ->
        SupportListRow(d.string("title") ?: "", "${d.string("kind")?.humanized ?: ""} · ${d.string("original_name") ?: ""}" + (d.string("expires_at")?.let { " · expires ${Day.format(it)}" } ?: "")) {
            Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                if (d["expired"].bool == true) Pill("expired", StatusTone.DANGER)
                Row {
                    PeopleSignedFileButton(null, "/api/v1/admin/people/$personId/documents/${d.id}", d.string("original_name") ?: "document", icon = Icons.Default.Download)
                    if (canEdit) ConfirmButton("Remove document", "The file is deleted from the employee's record.", destructive = true, action = {
                        try { val r = env.client.delete("/v1/admin/people/$personId/documents/${d.id}"); toastResult(env, r); reload() } catch (e: Exception) { env.toasts.error(e) }
                    }) { Icon(Icons.Default.Delete, contentDescription = "Remove") }
                }
            }
        }
    }
    if (canEdit) SectionCard("Add a document") {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            AttachmentPicker(picked?.name ?: "Choose file", images = false, camera = false) { files -> files.firstOrNull()?.let { picked = it; if (title.isEmpty()) title = it.name } }
        }
        PeopleChoice("Kind", kind, documentKinds, { kind = it })
        Field("Title", title, { title = it })
        DayField("Expires (optional)", expires, { expires = it }, allowClear = true)
        AsyncButton("Upload", enabled = picked != null && title.isNotEmpty()) {
            val f = picked ?: return@AsyncButton
            val parts = mutableListOf(ApiClient.Part.field("kind", kind), ApiClient.Part.field("title", title), f.part("file"))
            if (expires.isNotEmpty()) parts += ApiClient.Part.field("expires_at", expires)
            val r = env.client.upload("/v1/admin/people/$personId/documents", parts)
            toastResult(env, r); picked = null; title = ""; expires = ""; reload()
        }
    }
}

// ── Pay structures (GET/POST /people/{id}/pay — hr.view_sensitive + step-up). Figures are KES as stored. ──

@Composable
private fun PersonPayPanel(personId: Int, canView: Boolean) {
    val env = LocalEnv.current
    var loaded by remember { mutableStateOf<JsonElement?>(null) }
    var adding by remember { mutableStateOf(false) }
    val j = loaded
    when {
        !canView -> EmptyState("Pay details need hr.view_sensitive", icon = Icons.Default.Lock)
        j == null -> AsyncButton("Show pay structures (asks you to confirm it's you)", outlined = true) { loaded = env.client.get("/v1/admin/people/$personId/pay").json }
        else -> {
            if (j["data"].records.isEmpty()) EmptyState("No pay structure yet")
            j["data"].toRecords().forEach { s ->
                SectionCard("From ${Day.format(s.string("effective_from"), "d MMM yyyy")} · ${s.string("kind")?.humanized ?: ""}") {
                    Row(verticalAlignment = Alignment.CenterVertically) { Text("Base", modifier = Modifier.weight(1f)); MoneyText(s.money("base_amount", s.string("currency_code") ?: "KES"), emphasis = true) }
                    s["allowances"].records.forEach { a ->
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text("${a["name"].str ?: "Allowance"}${if (a["taxable"].bool == false) " (non-taxable)" else ""}", style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f))
                            MoneyText(Money.from(a["amount"], "KES"))
                        }
                    }
                    s.string("notes")?.takeIf { it.isNotEmpty() }?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                }
            }
            if (j["can_edit"].bool == true) OutlinedButton(onClick = { adding = true }) { Text("New pay structure") }
        }
    }
    if (adding) PayStructureSheet(personId, onDismiss = { adding = false }) { loaded = runCatching { env.client.get("/v1/admin/people/$personId/pay").json }.getOrNull() ?: loaded }
}

private data class AllowanceDraft(val name: String = "", val amount: String = "", val taxable: Boolean = true)

@Composable
private fun PayStructureSheet(personId: Int, onDismiss: () -> Unit, saved: suspend () -> Unit) {
    val env = LocalEnv.current
    var from by remember { mutableStateOf(Day.today()) }
    var kind by remember { mutableStateOf("monthly") }
    var base by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    var allowances by remember { mutableStateOf<List<AllowanceDraft>>(emptyList()) }
    FormSheet("New pay structure", onDismiss, canSave = base.isNotEmpty(), save = {
        val r = env.client.post("/v1/admin/people/$personId/pay", J.obj(
            "effective_from" to J.s(from), "kind" to J.s(kind), "base_amount" to J.typed(base), "notes" to J.sOpt(notes),
            "allowances" to JsonArray(allowances.filter { it.name.isNotEmpty() }.map { J.obj("name" to J.s(it.name), "amount" to J.typed(it.amount), "taxable" to J.b(it.taxable)) }),
        ))
        toastResult(env, r); saved()
    }) { e ->
        Text("A change is a new structure from a date; old payroll keeps what it used.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
        DayField("Effective from", from, { from = it })
        PeopleChoice("Kind", kind, listOf("monthly" to "Monthly salary", "daily" to "Daily rate", "hourly" to "Hourly rate"), { kind = it })
        Field("Base amount (KES)", base, { base = it }, error = e?.field("base_amount") ?: e?.field("effective_from"), keyboard = androidx.compose.ui.text.input.KeyboardType.Decimal)
        allowances.forEachIndexed { i, a ->
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                Field("Allowance", a.name, { x -> allowances = allowances.mapIndexed { j, b -> if (j == i) b.copy(name = x) else b } }, Modifier.weight(1f))
                Field("Amount", a.amount, { x -> allowances = allowances.mapIndexed { j, b -> if (j == i) b.copy(amount = x) else b } }, Modifier.weight(1f), keyboard = androidx.compose.ui.text.input.KeyboardType.Decimal)
                Column(horizontalAlignment = Alignment.CenterHorizontally) { Text("Taxable", style = MaterialTheme.typography.labelSmall); Switch(checked = a.taxable, onCheckedChange = { x -> allowances = allowances.mapIndexed { j, b -> if (j == i) b.copy(taxable = x) else b } }) }
            }
        }
        TextButton(onClick = { allowances = allowances + AllowanceDraft() }, enabled = allowances.size < 10) { Text("Add allowance") }
        Field("Notes", notes, { notes = it }, multiline = true)
    }
}

// ── Leave (GET /people/{id}/leave; record / adjust) ──────────────────────────

@Composable
private fun PersonLeavePanel(personId: Int) {
    var version by remember { mutableIntStateOf(0) }
    var recording by remember { mutableStateOf(false) }
    var adjusting by remember { mutableStateOf(false) }
    RemoteJson("/v1/admin/people/$personId/leave", version = version) { j, _ ->
        SectionCard("Balances ${j["year"].int ?: Day.thisYear()}") {
            j["balances"].toRecords().forEach { b ->
                Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(b["type"]["name"].str ?: "", style = MaterialTheme.typography.bodyMedium)
                    Text("${b["available"].str ?: "∞"} left · ${b["used"].str ?: "0"} used · ${b["requested"].str ?: "0"} requested · ${b["entitled"].str ?: "0"} entitled", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                }
            }
            FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                if (j["can_record"].bool == true) OutlinedButton(onClick = { recording = true }) { Text("Record leave") }
                if (j["can_adjust"].bool == true) OutlinedButton(onClick = { adjusting = true }) { Text("Adjust balance") }
            }
        }
        j["requests"].toRecords().forEach { r -> SupportListRow(peopleLeaveSummary(r), r.string("reason")) { StatusBadge(r.status) } }
        val adj = j["adjustments"].toRecords()
        if (adj.isNotEmpty()) SectionCard("Adjustments") { adj.forEach { a -> Text("${a.string("type") ?: ""}: ${a["days"].str ?: ""} days — ${a.string("reason") ?: ""}", style = MaterialTheme.typography.bodySmall) } }
    }
    if (recording) RecordLeaveSheet(personId, onDismiss = { recording = false }) { version += 1 }
    if (adjusting) LeaveAdjustSheet(personId, onDismiss = { adjusting = false }) { version += 1 }
}

/** POST /leave/adjustments {user_id, leave_type_id, year, days, reason} */
@Composable
private fun LeaveAdjustSheet(personId: Int, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    var types by remember { mutableStateOf<List<Record>>(emptyList()) }
    var typeId by remember { mutableStateOf<Int?>(null) }
    var year by remember { mutableStateOf(Day.thisYear().toString()) }
    var days by remember { mutableStateOf("") }
    var reason by remember { mutableStateOf("") }
    LaunchedEffect(Unit) { types = runCatching { env.client.get("/v1/admin/leave/types").json["data"].toRecords() }.getOrDefault(emptyList()) }
    FormSheet("Adjust leave balance", onDismiss, canSave = typeId != null && days.isNotEmpty() && reason.isNotEmpty(), save = {
        val r = env.client.post("/v1/admin/leave/adjustments", J.obj("user_id" to J.i(personId), "leave_type_id" to J.i(typeId), "year" to J.typed(year), "days" to J.typed(days), "reason" to J.s(reason)))
        toastResult(env, r); saved()
    }) { e ->
        PeopleIntChoice("Type", typeId, types.mapNotNull { t -> t.intId?.let { it to (t.string("name") ?: "") } }, { typeId = it })
        Field("Year", year, { year = it })
        Field("Days (negative to deduct)", days, { days = it }, error = e?.field("days"))
        Field("Reason", reason, { reason = it }, multiline = true)
    }
}

// ── Signing (GET/POST/DELETE /people/{id}/signing) ───────────────────────────

@Composable
private fun PersonSigningPanel(personId: Int, documents: List<Record>, canEdit: Boolean) {
    val env = LocalEnv.current
    var version by remember { mutableIntStateOf(0) }
    var asking by remember { mutableStateOf(false) }
    if (canEdit) OutlinedButton(onClick = { asking = true }) { Text("Ask for a signature") }
    RemoteJson("/v1/admin/people/$personId/signing", version = version, emptyTitle = "Nothing to sign", isEmpty = { it["data"].records.isEmpty() }) { j, reload ->
        j["data"].toRecords().forEach { s ->
            SupportListRow(s.string("title") ?: "", "${s.string("kind")?.humanized ?: ""} · asked by ${s.string("requested_by") ?: "—"}" + (s.string("due_at")?.let { " · due ${Day.format(it)}" } ?: "") + (s.string("signed_at")?.let { " · signed ${Day.dateTime(it)}" } ?: "")) {
                Column(horizontalAlignment = Alignment.End) {
                    StatusBadge(s.status)
                    if (canEdit && s.status == "pending") AsyncTextButton("Withdraw", color = Palette.danger) { val r = env.client.delete("/v1/admin/people/$personId/signing/${s.id}"); toastResult(env, r); reload() }
                }
            }
        }
    }
    if (asking) SigningRequestSheet(personId, documents, onDismiss = { asking = false }) { version += 1 }
}

@Composable
private fun SigningRequestSheet(personId: Int, documents: List<Record>, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    var kind by remember { mutableStateOf("text") }
    var docId by remember { mutableStateOf<Int?>(null) }
    var articleId by remember { mutableStateOf<Int?>(null) }
    var articles by remember { mutableStateOf<List<Record>>(emptyList()) }
    var title by remember { mutableStateOf("") }
    var body by remember { mutableStateOf("") }
    var due by remember { mutableStateOf("") }
    LaunchedEffect(Unit) { articles = runCatching { env.client.get("/v1/admin/handbook").json["data"].toRecords() }.getOrDefault(emptyList()) }
    FormSheet("Ask for a signature", onDismiss, save = {
        val r = env.client.post("/v1/admin/people/$personId/signing", J.obj(
            "kind" to J.s(kind), "staff_document_id" to (if (kind == "document") J.i(docId) else null), "article_id" to (if (kind == "article") J.i(articleId) else null),
            "title" to J.sOpt(title), "body" to (if (kind == "text") J.sOpt(body) else null), "due_at" to J.sOpt(due),
        ))
        toastResult(env, r); saved()
    }) { e ->
        PeopleChoice("What to sign", kind, listOf("text" to "Words typed here", "document" to "One of their documents", "article" to "A handbook article"), { kind = it })
        if (kind == "document") PeopleIntChoice("Document", docId, documents.mapNotNull { d -> d.intId?.let { it to (d.string("title") ?: "") } }, { docId = it })
        else if (kind == "article") PeopleIntChoice("Article", articleId, articles.mapNotNull { a -> a.intId?.let { it to (a.string("title") ?: "") } }, { articleId = it })
        Field("Title", title, { title = it }, error = e?.field("title"))
        if (kind == "text") Field("Words to sign", body, { body = it }, multiline = true)
        DayField("Due (optional)", due, { due = it }, allowClear = true)
    }
}
