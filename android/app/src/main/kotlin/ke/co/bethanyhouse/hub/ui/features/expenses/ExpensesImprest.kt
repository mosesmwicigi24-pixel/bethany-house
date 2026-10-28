package ke.co.bethanyhouse.hub.ui.features.expenses

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.AccountBalanceWallet
import androidx.compose.material.icons.filled.Book
import androidx.compose.material.icons.filled.Download
import androidx.compose.material.icons.filled.Numbers
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
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.date
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
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.DataTable
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.DecimalField
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.JRow
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.StatTile
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.TableColumn
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.TileModifier
import ke.co.bethanyhouse.hub.ui.components.fmtMoney
import ke.co.bethanyhouse.hub.ui.components.jrows
import ke.co.bethanyhouse.hub.ui.components.kes
import ke.co.bethanyhouse.hub.ui.components.outletOptions
import ke.co.bethanyhouse.hub.ui.components.personName
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonPrimitive

/**
 * The imprest (petty-cash float): GET /expenses/imprest lists accounts with
 * balance, open top-up and counts; every money move runs on the server
 * (ImprestService). Actions mirror ImprestPage.tsx: request top-up, send
 * (or send directly), confirm received, decline, cancel, record a count,
 * approve/reject a count, resolve a rejected/cancelled imprest spend.
 */
@Composable
fun ImprestScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val summary = remember { Resource(scope) { env.client.get("/v1/admin/expenses/imprest").json ?: JsonNull } }
    LaunchedEffect(Unit) { summary.load() }
    var settingUp by remember { mutableStateOf(false) }

    Page("Imprest", subtitle = "Petty-cash float", refresh = { summary.reload() }) {
        LoadableView(summary.state, retry = { summary.load() }) { json ->
            val accounts = jrows(json["accounts"])
            if (accounts.isEmpty()) {
                if (json["can_set_up"].bool == true) EmptyState("No imprest yet", "Set up the float: who holds it, how much it is, and what is in the box today.", Icons.Default.AccountBalanceWallet, actionLabel = "Set up imprest") { settingUp = true }
                else EmptyState("No imprest yet", "The super admin sets up the float.", Icons.Default.AccountBalanceWallet)
            }
            accounts.forEach { a -> ImprestAccountCard(a, isSuperAdmin = json["is_super_admin"].bool ?: false) { summary.load() } }
        }
    }
    if (settingUp) ImprestSetupSheet(onDismiss = { settingUp = false }) { settingUp = false; summary.load() }
}

private enum class ImprestAction { REQUEST, SEND, DIRECT, DECLINE, RECEIVE, COUNT, SETTINGS }

@Composable
private fun ImprestAccountCard(account: JRow, isSuperAdmin: Boolean, reload: () -> Unit) {
    val env = LocalEnv.current
    val a = account
    val cur = a.str("currency_code") ?: "KES"
    val custodian = a["you_are_custodian"].bool ?: false
    val open = a["open_topup"]?.takeIf { !it.isNull }
    var action by remember { mutableStateOf<ImprestAction?>(null) }
    var tab by remember { mutableStateOf("statement") }
    val accountId = a.int("id") ?: 0

    suspend fun cancel(uuid: String) {
        try { env.client.post("/v1/admin/expenses/imprest/topups/$uuid/cancel"); env.toasts.show("Request cancelled"); reload() } catch (e: ApiError) { env.toasts.error(e) }
    }

    SectionCard(a.str("name") ?: "Imprest") {
        TileGrid {
            StatTile("Balance (book)", fmtMoney(a["balance"], cur), hint = "of a ${fmtMoney(a["float_amount"], cur)} float", tone = if (a["is_low"].bool == true) StatusTone.WARNING else null, modifier = TileModifier)
            StatTile("Suggested top-up", fmtMoney(a["suggested_topup"], cur), hint = "low below ${fmtMoney(a["low_threshold"], cur)}", modifier = TileModifier)
            StatTile("Pending counts", a["pending_counts"].str ?: "0", tone = if ((a.int("pending_counts") ?: 0) > 0) StatusTone.AMBER else null, modifier = TileModifier)
            StatTile("Unresolved spends", a["unresolved_expenses"].str ?: "0", tone = if ((a.int("unresolved_expenses") ?: 0) > 0) StatusTone.WARNING else null, modifier = TileModifier)
        }
        Text("Held by ${personName(a["custodian"]) ?: "—"}${if (custodian) " (you)" else ""} · ${a["outlet"]["name"].str ?: "Head office"}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
        if (open != null) {
            val status = open["status"].str ?: "pending"
            Column(Modifier.fillMaxWidth().background(Palette.amberSoft, MaterialTheme.shapes.small).padding(Space.m), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) { Text("Open top-up", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold); StatusBadge(status) }
                Text("Requested ${fmtMoney(open["requested_amount"], cur)} by ${personName(open["requester"]) ?: "—"}${open["reason"].str?.let { " · $it" } ?: ""}", style = MaterialTheme.typography.bodySmall)
                if (status == "sent") Text("Sent ${fmtMoney(open["sent_amount"], cur)} by ${ExpenseVocab.methodLabel(open["sent_method"].str)}${open["sent_reference"].str?.let { " · $it" } ?: ""} — waiting for the custodian to confirm what arrived.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                    if (status == "pending" && isSuperAdmin) { Button(onClick = { action = ImprestAction.SEND }) { Text("Mark sent") }; OutlinedButton(onClick = { action = ImprestAction.DECLINE }) { Text("Decline") } }
                    if (status == "sent" && (custodian || isSuperAdmin)) Button(onClick = { action = ImprestAction.RECEIVE }) { Text("Confirm received") }
                    val uuid = open["uuid"].str
                    if (status == "pending" && uuid != null && (custodian || isSuperAdmin)) ConfirmButton("Cancel request", "The top-up request is withdrawn.", destructive = true, action = { cancel(uuid) }) { Text("Cancel request") }
                }
            }
        }
        FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
            if (open == null && (custodian || env.session.current.can("expenses.create"))) OutlinedButton(onClick = { action = ImprestAction.REQUEST }) { Text("Request top-up") }
            if (isSuperAdmin && open == null) OutlinedButton(onClick = { action = ImprestAction.DIRECT }) { Text("Send money directly") }
            if (custodian || isSuperAdmin || env.session.current.can("expenses.create")) OutlinedButton(onClick = { action = ImprestAction.COUNT }) { Text("Record a count") }
            if (isSuperAdmin) OutlinedButton(onClick = { action = ImprestAction.SETTINGS }) { Text("Settings") }
        }
        if (a["can_read_book"].bool ?: true) {
            SegmentedTabs(listOf("statement" to "Statement", "topups" to "Top-ups", "counts" to "Counts & unresolved"), tab) { tab = it }
            when (tab) {
                "topups" -> ImprestTopupsList(accountId, cur)
                "counts" -> ImprestCountsList(accountId, cur, isSuperAdmin, reload)
                else -> ImprestStatementList(accountId, cur)
            }
        }
    }
    action?.let { act -> ImprestActionSheet(act, a, open, onDismiss = { action = null }) { action = null; reload() } }
}

/** One sheet for every imprest money move; the fields follow ImprestController's validation. */
@Composable
private fun ImprestActionSheet(action: ImprestAction, account: JRow, open: JsonElement?, onDismiss: () -> Unit, done: () -> Unit) {
    val env = LocalEnv.current
    val cur = account.str("currency_code") ?: "KES"
    var amount by remember { mutableStateOf("") }
    var method by remember { mutableStateOf("mpesa") }
    var reference by remember { mutableStateOf("") }
    var note by remember { mutableStateOf("") }
    var reason by remember { mutableStateOf("") }
    var name by remember { mutableStateOf(account.str("name") ?: "") }
    var floatAmount by remember { mutableStateOf(account.str("float_amount") ?: "") }
    var lowPercent by remember { mutableStateOf(account.str("low_balance_percent") ?: "") }
    var custodianId by remember { mutableStateOf<Int?>(null) }
    var staff by remember { mutableStateOf<List<Pair<Int, String>>>(emptyList()) }
    var unreplenished by remember { mutableStateOf<JsonElement?>(null) }
    val id = account.int("id") ?: 0
    LaunchedEffect(action) {
        if (action == ImprestAction.REQUEST) unreplenished = runCatching { env.client.get("/v1/admin/expenses/imprest/$id/unreplenished", background = true).json }.getOrNull()
        if (action == ImprestAction.SETTINGS) staff = expensesStaffOptions(env.client)
    }
    val title = when (action) {
        ImprestAction.REQUEST -> "Request a top-up"; ImprestAction.SEND -> "Mark the top-up as sent"; ImprestAction.DIRECT -> "Send money to the imprest"
        ImprestAction.DECLINE -> "Decline the top-up"; ImprestAction.RECEIVE -> "Confirm what arrived"; ImprestAction.COUNT -> "Record a cash count"; ImprestAction.SETTINGS -> "Imprest settings"
    }
    val primary = when (action) {
        ImprestAction.REQUEST -> "Request"; ImprestAction.SEND, ImprestAction.DIRECT -> "Mark sent"; ImprestAction.DECLINE -> "Decline"
        ImprestAction.RECEIVE -> "Confirm received"; ImprestAction.COUNT -> "Record count"; ImprestAction.SETTINGS -> "Save"
    }
    val canSave = when (action) {
        ImprestAction.REQUEST, ImprestAction.SETTINGS -> true
        ImprestAction.DECLINE -> reason.trim().length >= 3
        else -> J.decimal(amount) != null
    }
    val methods = listOf("mpesa" to "M-PESA", "cash" to "Cash", "bank_transfer" to "Bank transfer", "other" to "Other")

    FormSheet(title, onDismiss, saveLabel = primary, canSave = canSave, destructive = action == ImprestAction.DECLINE, save = {
        val uuid = open["uuid"].str ?: ""
        val base = "/v1/admin/expenses/imprest"
        when (action) {
            ImprestAction.REQUEST -> env.client.post("$base/$id/topups", J.obj("amount" to J.decimal(amount), "reason" to J.sOpt(reason)))
            ImprestAction.SEND -> env.client.post("$base/topups/$uuid/send", J.obj("amount" to J.decimal(amount), "method" to JsonPrimitive(method), "reference" to J.sOpt(reference), "note" to J.sOpt(note)))
            ImprestAction.DIRECT -> env.client.post("$base/$id/topups/direct", J.obj("amount" to J.decimal(amount), "method" to JsonPrimitive(method), "reference" to J.sOpt(reference), "note" to J.sOpt(note)))
            ImprestAction.DECLINE -> env.client.post("$base/topups/$uuid/decline", J.obj("reason" to JsonPrimitive(reason)))
            ImprestAction.RECEIVE -> env.client.post("$base/topups/$uuid/receive", J.obj("amount" to J.decimal(amount), "note" to J.sOpt(note)))
            ImprestAction.COUNT -> env.client.post("$base/$id/counts", J.obj("counted_amount" to J.decimal(amount), "note" to J.sOpt(note)))
            ImprestAction.SETTINGS -> env.client.put("$base/$id", J.obj("name" to J.sOpt(name), "float_amount" to J.decimal(floatAmount), "low_balance_percent" to J.decimal(lowPercent), "custodian_id" to custodianId?.let { JsonPrimitive(it) }))
        }.let { r -> env.toasts.show(if (action == ImprestAction.SETTINGS) "Saved" else (r.json["message"].str ?: "Done")) }
        done()
    }) { error ->
        when (action) {
            ImprestAction.REQUEST -> {
                unreplenished?.let { u ->
                    val n = u["data"].records.size
                    Text("Unreplenished spends: ${fmtMoney(u["total"], cur)} across $n expense${if (n == 1) "" else "s"}. Suggested top-up ${fmtMoney(account["suggested_topup"], cur)}.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                }
                DecimalField("Amount (blank = suggested)", amount, { amount = it }, error = error?.field("amount"))
                Field("Reason", reason, { reason = it }, placeholder = "Optional", multiline = true)
            }
            ImprestAction.SEND, ImprestAction.DIRECT -> {
                DecimalField("Amount sent *", amount, { amount = it }, error = error?.field("amount"))
                OptionPicker("Method *", method, methods, { method = it })
                Field("Reference", reference, { reference = it }, placeholder = "M-PESA code, etc.")
                Field("Note", note, { note = it }, placeholder = "Optional")
            }
            ImprestAction.DECLINE -> Field("Reason *", reason, { reason = it }, multiline = true, error = error?.field("reason"))
            ImprestAction.RECEIVE -> {
                Text("Sent: ${fmtMoney(open["sent_amount"], cur)}. Enter what actually arrived; a difference is recorded as such.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                DecimalField("Amount received *", amount, { amount = it }, error = error?.field("amount"))
                Field("Note", note, { note = it }, placeholder = "Optional")
            }
            ImprestAction.COUNT -> {
                Text("Book balance: ${fmtMoney(account["balance"], cur)}. Count the cash in the box; the server works out the variance and the super admin approves any difference.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                DecimalField("Counted amount *", amount, { amount = it }, error = error?.field("counted_amount"))
                Field("Note", note, { note = it }, placeholder = "Optional")
            }
            ImprestAction.SETTINGS -> {
                Field("Name", name, { name = it }, error = error?.field("name"))
                SelectField("Custodian", custodianId, staff, { custodianId = it }, placeholder = "Keep")
                DecimalField("Float amount", floatAmount, { floatAmount = it }, error = error?.field("float_amount"))
                DecimalField("Low-balance warning at % of float", lowPercent, { lowPercent = it }, error = error?.field("low_balance_percent"))
            }
        }
    }
}

@Composable
private fun ImprestSetupSheet(onDismiss: () -> Unit, done: () -> Unit) {
    val env = LocalEnv.current
    var name by remember { mutableStateOf("Petty cash") }
    var custodianId by remember { mutableStateOf<Int?>(null) }
    var floatAmount by remember { mutableStateOf("") }
    var opening by remember { mutableStateOf("") }
    var lowPercent by remember { mutableStateOf("") }
    var outletId by remember { mutableStateOf<Int?>(null) }
    var staff by remember { mutableStateOf<List<Pair<Int, String>>>(emptyList()) }
    var outlets by remember { mutableStateOf<List<Pair<Int, String>>>(emptyList()) }
    LaunchedEffect(Unit) { staff = expensesStaffOptions(env.client); outlets = outletOptions(env.client, reports = false) }

    FormSheet("Set up the imprest", onDismiss, saveLabel = "Create", canSave = custodianId != null && J.decimal(floatAmount) != null && J.decimal(opening) != null, save = {
        val body = J.obj(
            "name" to JsonPrimitive(name), "custodian_id" to custodianId?.let { JsonPrimitive(it) }, "float_amount" to J.decimal(floatAmount), "opening_balance" to J.decimal(opening),
            "low_balance_percent" to J.decimal(lowPercent), "outlet_id" to outletId?.let { JsonPrimitive(it) },
        )
        env.client.post("/v1/admin/expenses/imprest", body); env.toasts.show("Imprest set up"); done()
    }) { error ->
        Field("Name", name, { name = it }, error = error?.field("name"))
        SelectField("Custodian *", custodianId, staff, { custodianId = it }, placeholder = "Choose…")
        error?.field("custodian_id")?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = Palette.danger) }
        DecimalField("Float amount (KES) *", floatAmount, { floatAmount = it }, error = error?.field("float_amount"))
        DecimalField("Opening balance — what is in the box today (KES) *", opening, { opening = it }, error = error?.field("opening_balance"))
        DecimalField("Low-balance warning at % of float (blank = server default)", lowPercent, { lowPercent = it })
        if (outlets.size > 1) SelectField("Outlet", outletId, outlets, { outletId = it }, placeholder = "Head office")
    }
}

// ── Lists under an account ───────────────────────────────────────────────────

@Composable
private fun ImprestStatementList(accountId: Int, cur: String) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var page by remember { mutableStateOf(1) }
    val data = remember(accountId, page) { Resource(scope) { env.client.get("/v1/admin/expenses/imprest/$accountId/statement", mapOf("page" to page.toString())).json ?: JsonNull } }
    LaunchedEffect(data) { data.load() }
    LoadableView(data.state, retry = { data.load() }, isEmpty = { it["data"].records.isEmpty() }, empty = { EmptyState("No movements yet", icon = Icons.Default.Book) }) { json ->
        Column {
            jrows(json["data"]).forEach { r ->
                Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.Top) {
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                            StatusBadge(r.str("type"), StatusTone.NEUTRAL)
                            val e = r["expense"]?.takeIf { !it.isNull }
                            if (e != null) TextButton(onClick = { e["id"].int?.let { env.navigator.push(Route.Expense(it)) } }) { Text(e["reference_number"].str ?: "expense", style = MaterialTheme.typography.labelSmall) }
                        }
                        r.str("note")?.takeIf { it.isNotEmpty() }?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                        Row(horizontalArrangement = Arrangement.spacedBy(Space.xs)) {
                            DateText(r["created_at"].date)
                            personName(r["creator"])?.let { Text("· $it", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
                        }
                    }
                    Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        MoneyText(r.money("amount", cur))
                        Text("balance ${fmtMoney(r["balance_after"], cur)}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    }
                }
                HorizontalDivider(color = MaterialTheme.hub.line)
            }
            PagerRow(page, json["last_page"].int ?: 1) { page = it }
        }
    }
}

@Composable
private fun ImprestTopupsList(accountId: Int, cur: String) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val data = remember(accountId) { Resource(scope) { env.client.get("/v1/admin/expenses/imprest/$accountId/topups").json ?: JsonNull } }
    LaunchedEffect(data) { data.load() }
    LoadableView(data.state, retry = { data.load() }, isEmpty = { it["data"].records.isEmpty() }, empty = { EmptyState("No top-ups yet", icon = Icons.Default.Download) }) { json ->
        DataTable(columns = listOf(
            TableColumn("Requested", "created_at", TableColumn.Kind.DATE), TableColumn("Status", "status", TableColumn.Kind.STATUS),
            TableColumn("Requested", "requested_amount", TableColumn.Kind.MONEY, fixedCurrency = cur), TableColumn("Sent", "sent_amount", TableColumn.Kind.MONEY, fixedCurrency = cur),
            TableColumn("Received", "received_amount", TableColumn.Kind.MONEY, fixedCurrency = cur), TableColumn("Method", "sent_method", TableColumn.Kind.STATUS), TableColumn("Reference", "sent_reference"),
        ), rows = jrows(json["data"]))
    }
}

@Composable
private fun ImprestCountsList(accountId: Int, cur: String, isSuperAdmin: Boolean, reload: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val counts = remember(accountId) { Resource(scope) { env.client.get("/v1/admin/expenses/imprest/$accountId/counts").json ?: JsonNull } }
    val unresolved = remember(accountId) { Resource(scope) { val j = env.client.get("/v1/admin/expenses/imprest/unresolved").json; jrows(if (j is kotlinx.serialization.json.JsonArray) j else j["data"]) } }
    LaunchedEffect(counts) { counts.load(); unresolved.load() }

    suspend fun decide(id: Int, approve: Boolean) {
        try { val r = env.client.post("/v1/admin/expenses/imprest/counts/$id/decide", J.obj("approve" to JsonPrimitive(approve))); env.toasts.show(r.json["message"].str ?: "Done"); counts.load(); reload() } catch (e: ApiError) { env.toasts.error(e) }
    }
    suspend fun resolve(id: Int, resolution: String) {
        try { val r = env.client.post("/v1/admin/expenses/$id/imprest-resolution", J.obj("resolution" to JsonPrimitive(resolution))); env.toasts.show(r.json["message"].str ?: "Done"); unresolved.load(); reload() } catch (e: ApiError) { env.toasts.error(e) }
    }

    Column(verticalArrangement = Arrangement.spacedBy(Space.m)) {
        Text("Unresolved imprest spends", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
        LoadableView(unresolved.state, retry = { unresolved.load() }, isEmpty = { it.isEmpty() }, empty = { Text("Nothing waiting", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textTertiary) }) { rows ->
            rows.forEach { e ->
                val eid = e.int("id")
                Column(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            TextButton(onClick = { eid?.let { env.navigator.push(Route.Expense(it)) } }, contentPadding = androidx.compose.foundation.layout.PaddingValues(0.dp)) { Text(e.str("reference_number") ?: "Expense", style = MaterialTheme.typography.bodySmall.copy(fontFamily = FontFamily.Monospace)) }
                            Text("${e.str("title") ?: ""} · ${(e.str("status") ?: "").humanized}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        }
                        MoneyText(e["amount_kes"].kes)
                    }
                    if (isSuperAdmin && eid != null) Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                        AsyncButton("Cash returned") { resolve(eid, "returned") }
                        AsyncButton("Write off", outlined = true) { resolve(eid, "written_off") }
                    }
                }
            }
        }
        Text("Cash counts", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
        LoadableView(counts.state, retry = { counts.load() }, isEmpty = { it["data"].records.isEmpty() }, empty = { EmptyState("No counts recorded", icon = Icons.Default.Numbers) }) { json ->
            jrows(json["data"]).forEach { c ->
                Column(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        StatusBadge(c.str("status"))
                        Text("counted ${fmtMoney(c["counted_amount"], cur)} vs book ${fmtMoney(c["book_balance"], cur)}", style = MaterialTheme.typography.bodySmall)
                    }
                    Text("variance ${fmtMoney(c["variance"], cur)}${c.str("note")?.let { " · $it" } ?: ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    Row(horizontalArrangement = Arrangement.spacedBy(Space.xs)) {
                        DateText(c["created_at"].date)
                        personName(c["counter"])?.let { Text("· $it", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
                    }
                    val cid = c.int("id")
                    if (c.str("status") == "pending" && isSuperAdmin && cid != null) Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                        AsyncButton("Approve adjustment") { decide(cid, true) }
                        AsyncButton("Reject", outlined = true) { decide(cid, false) }
                    }
                }
                HorizontalDivider(color = MaterialTheme.hub.line)
            }
        }
    }
}
