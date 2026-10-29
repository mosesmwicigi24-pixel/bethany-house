package ke.co.bethanyhouse.hub.ui.features.people

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ChevronLeft
import androidx.compose.material.icons.filled.ChevronRight
import androidx.compose.material.icons.filled.Paid
import androidx.compose.material3.Button
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Record
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
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.DayField
import ke.co.bethanyhouse.hub.ui.components.DocumentButton
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.ErrorState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FieldError
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.InfoSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.KeyValueGrid
import ke.co.bethanyhouse.hub.ui.components.LoadingSkeleton
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Pill
import ke.co.bethanyhouse.hub.ui.components.RemoteJson
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.StaffPicker
import ke.co.bethanyhouse.hub.ui.components.StatTile
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.SupportListRow
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.TileModifier
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.minutesText
import ke.co.bethanyhouse.hub.ui.components.parseJsonObject
import ke.co.bethanyhouse.hub.ui.components.prettyJson
import ke.co.bethanyhouse.hub.ui.components.toastResult
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement

/**
 * Payroll (People PR 6/7). Every figure is KES as the server computed it
 * from the locked timesheet and the pay structure; nothing is summed here.
 * Writes need payroll.run; approve, pay, payouts, payslips, advances and
 * rate rows are behind step-up on the server.
 */
@Composable
fun PayrollScreen() {
    val env = LocalEnv.current
    var tab by remember { mutableStateOf("runs") }
    var year by remember { mutableIntStateOf(Day.thisYear()) }
    var version by remember { mutableIntStateOf(0) }
    var openRun by remember { mutableStateOf<Int?>(null) }
    Page("Payroll", subtitle = "Runs, advances, statutory returns and rate tables") {
        SegmentedTabs(listOf("runs" to "Runs", "advances" to "Advances & loans", "returns" to "Returns", "rates" to "Statutory rates"), tab) { tab = it }
        when (tab) {
            "runs" -> {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    IconButton(onClick = { year -= 1 }) { Icon(Icons.Default.ChevronLeft, contentDescription = "Previous year") }
                    Text(year.toString(), style = MaterialTheme.typography.titleMedium)
                    IconButton(onClick = { year += 1 }) { Icon(Icons.Default.ChevronRight, contentDescription = "Next year") }
                }
                RemoteJson("/v1/admin/payroll/runs", query = mapOf("year" to year.toString()), version = version) { j, reload ->
                    val unconfirmed = j["rates_unconfirmed"].records.mapNotNull { it.str }
                    if (unconfirmed.isNotEmpty()) Text("Unconfirmed rate tables: ${unconfirmed.joinToString(", ") { peopleRateLabel(it) }}. Confirm them before paying.", style = MaterialTheme.typography.bodySmall, color = Palette.warning)
                    val ready = j["ready_periods"].toRecords()
                    if (j["can_run"].bool == true && ready.isNotEmpty()) SectionCard("Periods ready to run") {
                        ready.forEach { p ->
                            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                                Text("${p.string("group") ?: "Default"} · ${Day.format(p.string("starts_on"))} – ${Day.format(p.string("ends_on"), "d MMM yyyy")}", style = MaterialTheme.typography.bodyMedium, modifier = Modifier.weight(1f))
                                AsyncButton("Draft run") {
                                    val r = env.client.post("/v1/admin/payroll/runs", J.obj("pay_period_id" to J.i(p.int("id"))))
                                    toastResult(env, r); reload()
                                    r.json["run"]["id"].int?.let { openRun = it }
                                }
                            }
                        }
                    }
                    val runs = j["runs"].toRecords()
                    if (runs.isEmpty()) EmptyState("No payroll runs in $year", icon = Icons.Default.Paid)
                    runs.forEach { r ->
                        val t = r["totals"]
                        SupportListRow("${r.string("reference") ?: "Run"} · ${r["group"]["name"].str ?: ""}", "${Day.format(r["period"]["starts_on"].str)} – ${Day.format(r["period"]["ends_on"].str, "d MMM yyyy")} · ${r.int("people") ?: 0} people" + (if (r.string("kind") == "adjustment") " · adjustment" else ""), onClick = { openRun = r.int("id") ?: 0 }) {
                            Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                StatusBadge(r.status)
                                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) { Text("Net", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary); MoneyText(peopleKes(t["net"])) }
                                if (r["reconciles"].bool == false) Pill("does not reconcile", StatusTone.DANGER)
                            }
                        }
                    }
                }
            }
            "advances" -> AdvancesPanel()
            "returns" -> ReturnsPanel()
            "rates" -> RatesPanel()
        }
    }
    openRun?.let { id -> PayrollRunSheet(id, onDismiss = { openRun = null }) { version += 1 } }
}

// ── One run (GET /payroll/runs/{id}) ─────────────────────────────────────────

@Composable
private fun PayrollRunSheet(runId: Int, onDismiss: () -> Unit, changed: () -> Unit) {
    val env = LocalEnv.current
    var run by remember { mutableStateOf<JsonElement?>(null) }
    var readiness by remember { mutableStateOf<JsonElement?>(null) }
    var error by remember { mutableStateOf<ApiError?>(null) }
    var version by remember { mutableIntStateOf(0) }
    var paying by remember { mutableStateOf(false) }
    LaunchedEffect(version) {
        try {
            run = env.client.get("/v1/admin/payroll/runs/$runId").json["run"]; error = null
            if (run["status"].str == "approved") readiness = runCatching { env.client.get("/v1/admin/payroll/runs/$runId/payout-readiness").json }.getOrNull()
        } catch (e: ApiError) { error = e } catch (e: Exception) { error = ApiError.transport(e.message ?: "Error") }
    }
    InfoSheet(run["reference"].str ?: "Payroll run", onDismiss) {
        val r = run
        when {
            r != null -> {
                r["status"].str?.let { StatusBadge(it) }
                RunContent(runId, r, readiness)
                RunActions(runId, r, onDismiss, changed, reload = { version += 1 }, pay = { paying = true })
            }
            error != null -> ErrorState(error!!, retry = { version += 1 })
            else -> LoadingSkeleton()
        }
    }
    if (paying) run?.let { r -> PayRunSheet(r, onDismiss = { paying = false }) { version += 1; changed() } }
}

@Composable
private fun RunContent(runId: Int, r: JsonElement, readiness: JsonElement?) {
    val t = r["totals"]
    fun k(v: JsonElement?) = peopleKes(v)?.formatted() ?: "—"
    val breaks = r["breaks"].records.mapNotNull { it.str }
    if (breaks.isNotEmpty()) SectionCard("This run does not reconcile") { breaks.forEach { Text(it, style = MaterialTheme.typography.bodySmall, color = Palette.danger) } }
    TileGrid {
        StatTile("Gross", k(t["gross"]), modifier = TileModifier)
        StatTile("PAYE", k(t["paye"]), modifier = TileModifier)
        StatTile("NSSF (employee)", k(t["nssf_employee"]), modifier = TileModifier)
        StatTile("SHIF", k(t["shif"]), modifier = TileModifier)
        StatTile("Housing (employee)", k(t["housing_employee"]), modifier = TileModifier)
        StatTile("Other deductions", k(t["other_deductions"]), modifier = TileModifier)
        StatTile("Net pay", k(t["net"]), tone = StatusTone.SUCCESS, modifier = TileModifier)
        StatTile("Employer cost", k(t["employer_cost"]), hint = "NSSF ${k(t["nssf_employer"])} · Housing ${k(t["housing_employer"])}", modifier = TileModifier)
    }
    KeyValueGrid(listOf(
        "Period" to "${Day.format(r["period"]["starts_on"].str)} – ${Day.format(r["period"]["ends_on"].str, "d MMM yyyy")} (${r["period"]["status"].str ?: ""})",
        "Approved" to (r["approved_by"].str?.let { "$it · ${Day.dateTime(r["approved_at"].str)}" } ?: "—"),
        "Paid" to (r["paid_at"].str?.let { Day.dateTime(it) } ?: "—"),
        "Expense" to (r["expense"]["reference"].str?.let { "$it (${r["expense"]["status"].str ?: ""})" } ?: "—"),
    ))
    val unpayable = r["unpayable"].toRecords()
    if (unpayable.isNotEmpty()) SectionCard("Not payable") { unpayable.forEach { u -> Text("${u.string("name") ?: ""}: ${u.string("why") ?: ""}", style = MaterialTheme.typography.bodySmall, color = Palette.warning) } }
    val rates = r["rates_used"].records
    if (rates.isNotEmpty()) Text("Rates used: " + rates.joinToString(" · ") { "${peopleRateLabel(it["code"].str ?: "")} from ${it["effective_from"].str ?: ""}${if (it["is_confirmed"].bool == false) " (unconfirmed)" else ""}" }, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
    readiness?.let { ready ->
        fun missing(v: JsonElement?, label: String) = v.records.mapNotNull { it.str }.takeIf { it.isNotEmpty() }?.let { " · $label: ${it.joinToString(", ")}" } ?: ""
        SectionCard("Payout readiness") {
            Text("M-Pesa: ${ready["mpesa"]["ready"].int ?: 0} ready, ${ready["mpesa"]["total"].str ?: "—"}" + missing(ready["mpesa"]["missing"], "missing"), style = MaterialTheme.typography.bodySmall)
            Text("Bank: ${ready["bank"]["ready"].int ?: 0} ready, ${ready["bank"]["total"].str ?: "—"}" + missing(ready["bank"]["missing"], "missing"), style = MaterialTheme.typography.bodySmall)
            Text("Cash: ${ready["cash"].int ?: 0}" + missing(ready["no_pay_method"], "no pay method"), style = MaterialTheme.typography.bodySmall)
        }
    }
    SectionCard("Lines") {
        r["lines"].toRecords().forEach { l ->
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    Text(l["user"]["name"].str ?: "#${l["user"]["id"].int ?: 0}", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                    MoneyText(peopleKes(l["net"]), emphasis = true)
                }
                DocumentButton("Payslip", "/v1/admin/payroll/runs/$runId/lines/${l.id}/payslip", suggestedName = "payslip-${r["reference"].str ?: ""}-${l["user"]["id"].int ?: 0}.pdf")
                Text("Gross ${k(l["gross"])} · PAYE ${k(l["paye"])} · NSSF ${k(l["nssf_employee"])} · SHIF ${k(l["shif"])} · Housing ${k(l["housing_employee"])} · Other ${k(l["other_deductions"])}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                val h = l["hours"]
                Text("${h["days_worked"].int ?: 0} days · regular ${minutesText(h["regular_minutes"].int)} · OT ${minutesText(h["overtime_minutes"].int)} · ${l["structure"]["kind"].str ?: "no structure"}" + (l["payment_method"].str?.let { " · $it" } ?: "") + (l["payment_reference"].str?.let { " $it" } ?: ""), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
            }
            HorizontalDivider(color = MaterialTheme.hub.line)
        }
    }
}

@Composable
private fun RunActions(runId: Int, r: JsonElement, dismiss: () -> Unit, changed: () -> Unit, reload: () -> Unit, pay: () -> Unit) {
    val env = LocalEnv.current
    val status = r["status"].str
    FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s), modifier = Modifier.fillMaxWidth()) {
        if (status == "approved" || status == "paid") {
            DocumentButton("M-Pesa file", "/v1/admin/payroll/runs/$runId/payout", mapOf("method" to "mpesa"), "payout-mpesa.csv")
            DocumentButton("Bank file", "/v1/admin/payroll/runs/$runId/payout", mapOf("method" to "bank"), "payout-bank.csv")
        }
        if (env.session.current.can("payroll.run")) when (status) {
            "draft" -> {
                AsyncButton("Recalculate", outlined = true) { val x = env.client.post("/v1/admin/payroll/runs/$runId/recalculate"); toastResult(env, x); changed(); reload() }
                ConfirmButton("Discard run", "The draft is deleted; the period can be drafted again.", destructive = true, action = {
                    try { val x = env.client.delete("/v1/admin/payroll/runs/$runId"); toastResult(env, x); changed(); dismiss() } catch (e: Exception) { env.toasts.error(e) }
                }) { Text("Discard") }
                ConfirmButton("Approve run", "Approving fixes these figures and raises the payroll expense. You will be asked to confirm it is you.", action = {
                    try { val x = env.client.post("/v1/admin/payroll/runs/$runId/approve"); toastResult(env, x); changed(); reload() } catch (e: Exception) { env.toasts.error(e) }
                }) { Text("Approve") }
            }
            "approved" -> Button(onClick = pay) { Text("Record payments…") }
        }
    }
}

/** POST /payroll/runs/{id}/pay {lines: [{line_id, payment_method, payment_reference}]} — step-up. */
@Composable
private fun PayRunSheet(run: JsonElement, onDismiss: () -> Unit, done: () -> Unit) {
    val env = LocalEnv.current
    val lines = remember(run) { run["lines"].toRecords() }
    var methods by remember { mutableStateOf(lines.mapNotNull { l -> l.string("payment_method")?.let { l.id to it } }.toMap()) }
    var refs by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    FormSheet("Record payments", onDismiss, saveLabel = "Mark paid", canSave = lines.all { !methods[it.id].isNullOrEmpty() }, save = {
        val body = J.obj("lines" to JsonArray(lines.map { l -> J.obj("line_id" to J.i(l.int("id")), "payment_method" to J.s(methods[l.id] ?: "cash"), "payment_reference" to J.sOpt(refs[l.id])) }))
        val r = env.client.post("/v1/admin/payroll/runs/${run["id"].int ?: 0}/pay", body)
        toastResult(env, r); done()
    }) { e ->
        Text("How each person was paid. Marking paid closes the run; you will be asked to confirm it is you.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
        lines.forEach { l ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.xs)) {
                Row(verticalAlignment = Alignment.CenterVertically) { Text(l["user"]["name"].str ?: "", style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f)); MoneyText(peopleKes(l["net"])) }
                PeopleChoice("Method", methods[l.id] ?: "", listOf("" to "—", "mpesa" to "M-Pesa", "bank" to "Bank", "cash" to "Cash"), { x -> methods = methods + (l.id to x) })
                Field("Reference", refs[l.id] ?: "", { x -> refs = refs + (l.id to x) })
            }
            HorizontalDivider(color = MaterialTheme.hub.line)
        }
        FieldError(e?.firstFieldError)
    }
}

// ── Advances & loans (GET /payroll/advances?status) ──────────────────────────

private data class AdvanceAction(val advance: Record, val kind: String)

@Composable
private fun AdvancesPanel() {
    val env = LocalEnv.current
    var status by remember { mutableStateOf("requested") }
    var version by remember { mutableIntStateOf(0) }
    var action by remember { mutableStateOf<AdvanceAction?>(null) }
    var creating by remember { mutableStateOf(false) }
    PeopleChoice("Status", status, listOf("requested" to "Waiting", "approved" to "Approved", "paid" to "Paid out", "settled" to "Settled", "declined" to "Declined", "" to "All"), { status = it })
    if (env.session.current.can("payroll.run")) OutlinedButton(onClick = { creating = true }) { Text("New for someone") }
    RemoteJson("/v1/admin/payroll/advances", query = mapOf("status" to status.ifEmpty { null }), version = version, emptyTitle = "No advances here", isEmpty = { it["data"].records.isEmpty() }) { j, _ ->
        Row(verticalAlignment = Alignment.CenterVertically) { Text("Open balance across everyone", style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f)); MoneyText(peopleKes(j["open_balance"]), emphasis = true) }
        val canRun = j["can_run"].bool ?: env.session.current.can("payroll.run")
        j["data"].toRecords().forEach { a ->
            SectionCard {
                Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                        Text("${a["user"]["name"].str ?: "—"} · ${a.string("kind")?.humanized ?: ""}", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                        Text("Instalment ${peopleKes(a["instalment"])?.formatted() ?: "—"} · balance ${peopleKes(a["balance"])?.formatted() ?: "—"}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        a.string("reason")?.let { Text("“$it”", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                        a.string("decision_note")?.let { Text("Decision: $it", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
                        val reps = a["repayments"].toRecords()
                        if (reps.isNotEmpty()) Text("Repayments: " + reps.joinToString(", ") { "${peopleKes(it["amount"])?.formatted() ?: ""} (${it.string("kind") ?: ""} ${Day.format(it.string("on"))})" }, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
                    }
                    Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) { MoneyText(peopleKes(a["amount"]), emphasis = true); StatusBadge(a.status) }
                }
                if (canRun) Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s, Alignment.End)) {
                    when (a.status) {
                        "requested" -> { OutlinedButton(onClick = { action = AdvanceAction(a, "decline") }) { Text("Decline") }; Button(onClick = { action = AdvanceAction(a, "approve") }) { Text("Approve") } }
                        "approved" -> Button(onClick = { action = AdvanceAction(a, "payout") }) { Text("Pay out") }
                        "paid" -> OutlinedButton(onClick = { action = AdvanceAction(a, "repay") }) { Text("Record cash repayment") }
                    }
                }
            }
        }
    }
    action?.let { a -> AdvanceActionSheet(a.advance, a.kind, onDismiss = { action = null }) { version += 1 } }
    if (creating) NewAdvanceSheet(onDismiss = { creating = false }) { version += 1 }
}

/** POST /payroll/advances/{id}/approve {instalment, note} | decline {note} | payout {payment_method, payment_reference} | repay {amount, reference} */
@Composable
private fun AdvanceActionSheet(advance: Record, kind: String, onDismiss: () -> Unit, done: () -> Unit) {
    val env = LocalEnv.current
    var note by remember { mutableStateOf("") }
    var instalment by remember { mutableStateOf("") }
    var method by remember { mutableStateOf("mpesa") }
    var reference by remember { mutableStateOf("") }
    var amount by remember { mutableStateOf("") }
    FormSheet(kind.humanized, onDismiss, saveLabel = kind.humanized, destructive = kind == "decline", save = {
        val body = when (kind) {
            "approve" -> J.obj("instalment" to (if (instalment.isEmpty()) null else J.typed(instalment)), "note" to J.sOpt(note))
            "decline" -> J.obj("note" to J.s(note))
            "payout" -> J.obj("payment_method" to J.s(method), "payment_reference" to J.sOpt(reference))
            else -> J.obj("amount" to J.typed(amount), "reference" to J.sOpt(reference))
        }
        val r = env.client.post("/v1/admin/payroll/advances/${advance.id}/$kind", body)
        toastResult(env, r); done()
    }) { e ->
        Row(verticalAlignment = Alignment.CenterVertically) { Text(advance["user"]["name"].str ?: "", modifier = Modifier.weight(1f)); MoneyText(peopleKes(advance["amount"])) }
        when (kind) {
            "approve" -> { Field("Instalment per payroll (KES, blank keeps the request)", instalment, { instalment = it }, placeholder = advance["instalment"].str ?: "", error = e?.field("instalment")); Field("Note", note, { note = it }, multiline = true) }
            "decline" -> Field("Why (the person sees this)", note, { note = it }, error = e?.field("note"), multiline = true)
            "payout" -> { PeopleChoice("Paid by", method, listOf("mpesa" to "M-Pesa", "bank" to "Bank", "cash" to "Cash"), { method = it }); Field("Reference", reference, { reference = it }) }
            else -> { Field("Amount repaid in cash (KES)", amount, { amount = it }, error = e?.field("amount")); Field("Reference", reference, { reference = it }) }
        }
        Text("This asks you to confirm it is you.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
    }
}

/** POST /payroll/advances {user_id, kind, amount, instalment, reason} */
@Composable
private fun NewAdvanceSheet(onDismiss: () -> Unit, done: () -> Unit) {
    val env = LocalEnv.current
    var user by remember { mutableStateOf<Int?>(null) }
    var kind by remember { mutableStateOf("advance") }
    var amount by remember { mutableStateOf("") }
    var instalment by remember { mutableStateOf("") }
    var reason by remember { mutableStateOf("") }
    FormSheet("Advance or loan for someone", onDismiss, canSave = user != null && amount.isNotEmpty() && reason.isNotEmpty(), save = {
        val r = env.client.post("/v1/admin/payroll/advances", J.obj("user_id" to J.i(user), "kind" to J.s(kind), "amount" to J.typed(amount), "instalment" to (if (instalment.isEmpty()) null else J.typed(instalment)), "reason" to J.s(reason)))
        toastResult(env, r); done()
    }) { e ->
        StaffPicker("Person", user, { user = it })
        PeopleChoice("Kind", kind, listOf("advance" to "Salary advance (one payroll)", "loan" to "Loan (instalments)"), { kind = it })
        Field("Amount (KES)", amount, { amount = it }, error = e?.field("amount"))
        if (kind == "loan") Field("Instalment per payroll (KES)", instalment, { instalment = it }, error = e?.field("instalment"))
        Field("Reason", reason, { reason = it }, multiline = true)
    }
}

// ── Returns (GET /payroll/returns?month&kind — step-up) ──────────────────────

@Composable
private fun ReturnsPanel() {
    var month by remember { mutableStateOf(Day.today().take(7)) }
    var kind by remember { mutableStateOf("paye") }
    SectionCard("Statutory returns") {
        Text("A working file per month for KRA, NSSF, SHA and the Housing Levy, built from paid runs. Downloading asks you to confirm it is you.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
        Field("Month (yyyy-mm)", month, { month = it })
        PeopleChoice("Return", kind, listOf("paye" to "PAYE (P10 working file)", "nssf" to "NSSF", "shif" to "SHIF", "housing_levy" to "Housing Levy"), { kind = it })
        DocumentButton("Download", "/v1/admin/payroll/returns", mapOf("month" to month, "kind" to kind), "$kind-$month.csv")
    }
}

// ── Statutory rate tables (GET/POST /payroll/rates, POST /rates/{id}/confirm) ──

@Composable
private fun RatesPanel() {
    val env = LocalEnv.current
    var version by remember { mutableIntStateOf(0) }
    var adding by remember { mutableStateOf<String?>(null) }
    val canRun = env.session.current.can("payroll.run")
    Text("Each table is a dated row: a change is a new row from a date, never an edit, so old runs keep the rates they used. Confirm a row once it is checked against KRA, NSSF or SHA.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
    RemoteJson("/v1/admin/payroll/rates", version = version) { j, reload ->
        val rows = j["data"].toRecords()
        listOf("paye", "nssf", "shif", "housing_levy").forEach { code ->
            SectionCard(peopleRateLabel(code)) {
                val mine = rows.filter { it.string("code") == code }.sortedByDescending { it.string("effective_from") ?: "" }
                if (mine.isEmpty()) Text("No table yet", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
                mine.forEach { r ->
                    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.xs), verticalArrangement = Arrangement.spacedBy(Space.xs), modifier = Modifier.fillMaxWidth()) {
                            Text("From ${r.string("effective_from") ?: ""}", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                            if (r["reduces_taxable_pay"].bool == true) Pill("reduces taxable pay", StatusTone.INFO)
                            val confirmed = r["is_confirmed"].bool == true
                            Pill(if (confirmed) "confirmed" else "unconfirmed", if (confirmed) StatusTone.SUCCESS else StatusTone.WARNING)
                        }
                        if (r["is_confirmed"].bool != true && canRun) AsyncButton("Confirm", outlined = true) { val x = env.client.post("/v1/admin/payroll/rates/${r.id}/confirm"); toastResult(env, x); reload() }
                        Text(prettyJson(r["config"]), style = MaterialTheme.typography.labelSmall.copy(fontFamily = FontFamily.Monospace), color = MaterialTheme.hub.textSecondary)
                        r.string("source")?.let { Text("Source: $it", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
                    }
                    HorizontalDivider(color = MaterialTheme.hub.line)
                }
                if (canRun) OutlinedButton(onClick = { adding = code }) { Text("New row from a date") }
            }
        }
    }
    adding?.let { code -> NewRateSheet(code, onDismiss = { adding = null }) { version += 1 } }
}

@Composable
private fun NewRateSheet(code: String, onDismiss: () -> Unit, done: () -> Unit) {
    val env = LocalEnv.current
    var from by remember { mutableStateOf(Day.today()) }
    var json by remember { mutableStateOf("{\n}") }
    var reduces by remember { mutableStateOf(false) }
    var source by remember { mutableStateOf("") }
    LaunchedEffect(Unit) {
        val latest = runCatching { env.client.get("/v1/admin/payroll/rates").json["data"].toRecords() }.getOrDefault(emptyList()).filter { it.string("code") == code }.maxByOrNull { it.string("effective_from") ?: "" }
        if (latest != null) { json = prettyJson(latest["config"]); reduces = latest["reduces_taxable_pay"].bool ?: false }
    }
    val parsed = parseJsonObject(json)
    FormSheet("New ${peopleRateLabel(code)} table", onDismiss, saveLabel = "Save (asks you to confirm it's you)", canSave = parsed != null, save = {
        val config = parseJsonObject(json) ?: throw ApiError(422, "The figures must be valid JSON.")
        val r = env.client.post("/v1/admin/payroll/rates", J.obj("code" to J.s(code), "effective_from" to J.s(from), "config" to config, "reduces_taxable_pay" to J.b(reduces), "source" to J.sOpt(source)))
        toastResult(env, r); done()
    }) { e ->
        DayField("Effective from", from, { from = it })
        Field("Figures (JSON)", json, { json = it }, error = e?.field("config"), multiline = true, mono = true)
        if (parsed == null) FieldError("Not valid JSON yet")
        ToggleRow("Employee's share reduces taxable pay", reduces, { reduces = it })
        Field("Source (gazette, circular, URL)", source, { source = it })
    }
}
