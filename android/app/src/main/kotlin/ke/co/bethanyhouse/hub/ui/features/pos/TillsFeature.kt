package ke.co.bethanyhouse.hub.ui.features.pos

import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Inbox
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.core.Omit
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.date
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.money
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.DetailRow
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.PagedList
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.hub
import ke.co.bethanyhouse.hub.ui.shell.FeatureRegistry
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull

/**
 * Tills (TillsPage.tsx → TillController): the till lifecycle's back office.
 * Verify & finalize (pos.till_verify, verifier ≠ operator), reconcile against
 * the payments ledger (pos.reconcile), record a correction (pos.till_correction).
 * Every figure is the server's, per currency (TillPresenter, ledger truth
 * from TillService::ledgerBreakdown); a finalized till's money is locked and
 * a change is a `till_corrections` row. Nothing is summed across currencies.
 */
internal object TillsFeature {
    fun register() {
        FeatureRegistry.register(Route.Tills) { TillsScreen() }
    }
}

private enum class TillTab(val label: String, val query: Map<String, String?>) {
    ALL("All", emptyMap()),
    AWAITING("Awaiting verification", mapOf("stage" to "awaiting_verification")),
    OPEN("Open", mapOf("stage" to "open")),
    TO_RECONCILE("To reconcile", mapOf("reconciliation" to "none")),
    MISMATCH("Mismatch", mapOf("reconciliation" to "mismatch")),
    DISCREPANCY("Discrepancies held", mapOf("discrepancy" to "awaiting_approval")),
    FINALIZED("Finalized", mapOf("stage" to "finalized")),
}

@Composable
private fun TillsScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val s = env.session.current
    val backOffice = s.can("pos.till_verify") || s.can("pos.reconcile") || s.can("pos.till_correction")
    val tabs = buildList {
        add(TillTab.ALL)
        if (backOffice) add(TillTab.AWAITING)
        add(TillTab.OPEN)
        if (s.can("pos.reconcile")) { add(TillTab.TO_RECONCILE); add(TillTab.MISMATCH) }
        if (backOffice) add(TillTab.DISCREPANCY)
        add(TillTab.FINALIZED)
    }
    var tab by remember { mutableStateOf(if (s.can("pos.till_verify")) TillTab.AWAITING else if (s.can("pos.reconcile")) TillTab.TO_RECONCILE else TillTab.ALL) }
    val list = remember { PagedList(scope, env.client, "${PosApi.BASE}/tills", tab.query.toMutableMap(), ::Record) }
    var selected by remember { mutableStateOf<Int?>(null) }
    LaunchedEffect(tab) { list.query = tab.query.toMutableMap(); list.reload() }

    Page("Tills", subtitle = "Blind to the operator until verified; each currency counted apart", refresh = { list.reload() }) {
        SegmentedTabs(tabs.map { it to it.label }, tab) { tab = it }
        LoadableView(list.state, retry = { list.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("No tills here", icon = Icons.Default.Inbox) }) { rows ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                rows.forEachIndexed { i, t ->
                    TillRow(t) { selected = t.intId }
                    if (i == rows.lastIndex && list.hasMore) LaunchedEffect(rows.size) { list.loadMore() }
                }
                if (list.isLoadingMore) CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp)
            }
        }
    }
    selected?.let { id -> TillDetailSheet(id, onDismiss = { selected = null }) { list.load() } }
}

@Composable
private fun TillRow(t: Record, onClick: () -> Unit) {
    val j = t.json
    val home = j["currency_code"].str ?: "KES"
    Surface(shape = MaterialTheme.shapes.small, color = MaterialTheme.hub.card, modifier = Modifier.fillMaxWidth().border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.small).clickable(onClick = onClick)) {
        Row(Modifier.padding(Space.m), verticalAlignment = Alignment.Top) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                androidx.compose.foundation.layout.FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(j["register_name"].str ?: j["register_number"].str ?: "Till #${j["id"].int ?: 0}", style = MaterialTheme.typography.bodyMedium)
                    StatusBadge(j["stage"].str)
                    if (j["legacy"].bool == true) StatusBadge("legacy", StatusTone.NEUTRAL)
                    if (j["left_open_overnight"].bool == true) StatusBadge("left open overnight", StatusTone.WARNING)
                }
                Text("${j["outlet_name"].str ?: ""} · ${j["opened_by"].str ?: ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                DateText(j["opened_at"].date)
            }
            Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                j["balances"].records.forEach { b ->
                    val code = b["currency_code"].str ?: home
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text(code, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
                        val c = b["counted_cash"]
                        if (c != null && !c.isNull) MoneyText(Money.from(c, code)) else Text("open", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
                        VarianceText(b["variance"], code)
                    }
                }
                if (j["blind"].bool == true) Text("blind", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
            }
        }
    }
}

@Composable
internal fun VarianceText(value: JsonElement?, code: String) {
    if (value == null || value.isNull) return
    val m = Money.from(value, code) ?: return
    Text(
        if (m.isZero) "balanced" else if (m.isNegative) "short ${m.formatted()}" else "over ${m.formatted()}",
        style = MaterialTheme.typography.labelSmall, color = if (m.isZero) Palette.success else Palette.danger,
    )
}

// ── Detail (GET pos/tills/{id}) ──────────────────────────────────────────────

@Composable
private fun TillDetailSheet(id: Int, onDismiss: () -> Unit, onChanged: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val api = env.client
    val till = remember(id) { Resource(scope) { api.get("${PosApi.BASE}/tills/$id").json["till"] ?: JsonNull } }
    var varianceReason by remember { mutableStateOf("") }
    var verifyNotes by remember { mutableStateOf("") }
    var recNotes by remember { mutableStateOf("") }
    var corrReason by remember { mutableStateOf("") }
    var corrCurrency by remember { mutableStateOf("") }
    var corrActual by remember { mutableStateOf("") }
    var corrExpected by remember { mutableStateOf("") }
    var closingNotes by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    LaunchedEffect(till) { till.load() }
    val loadedNotes = till.value["closing_notes"].str
    LaunchedEffect(loadedNotes) { closingNotes = loadedNotes ?: "" }

    suspend fun run(success: String, work: suspend () -> JsonElement) {
        busy = true
        try {
            val r = work()
            env.toasts.show(r["message"].str ?: success)
            till.reload()
            onChanged()
        } catch (e: Exception) { env.toasts.error(e) } finally { busy = false }
    }

    PosSheet("Till #$id", onDismiss = onDismiss, actions = { TextButton(onClick = onDismiss) { Text("Close") } }) {
        LoadableView(till.state, retry = { till.load() }) { t ->
            val home = t["currency_code"].str ?: "KES"
            val blind = t["blind"].bool ?: false
            val balances = t["balances"].records
            val multi = balances.size > 1
            val meId = env.session.current.me?.id
            val isOperator = t["opened_by_id"].int == meId
            Column(verticalArrangement = Arrangement.spacedBy(Space.m)) {
                androidx.compose.foundation.layout.FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(t["register_name"].str ?: t["register_number"].str ?: "", style = MaterialTheme.typography.titleMedium)
                    StatusBadge(t["stage"].str)
                    if (t["left_open_overnight"].bool == true) StatusBadge("left open overnight", StatusTone.WARNING)
                }
                DetailRow("Outlet", t["outlet_name"].str)
                DetailRow("Operator", t["opened_by"].str)
                DetailRow("Opened") { DateText(t["opened_at"].date) }
                t["closed_at"].date?.let { c -> DetailRow("Counted") { DateText(c) } }
                t["finalized_at"].date?.let { f -> DetailRow("Finalized by ${t["verified_by"].str ?: ""}") { DateText(f) } }
                DetailRow("Transactions", t["transaction_count"].str)
                if (blind) Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Default.VisibilityOff, contentDescription = null, tint = Palette.amber, modifier = Modifier.size(16.dp))
                    Spacer(Modifier.width(Space.s))
                    Text("Blind count: the expected figure and any variance are shown once your outlet manager has finalized this till.", style = MaterialTheme.typography.labelSmall, color = Palette.amber)
                }

                balances.forEach { b ->
                    val code = b["currency_code"].str ?: home
                    SectionCard(if (multi) code + (if (b["is_home"].bool == true) " (till's own)" else "") else "Drawer ($code)") {
                        MoneyRow("Opening float", b["opening_cash"], code)
                        MoneyRow("Count", b["counted_cash"], code)
                        if (!blind) {
                            b["breakdown"]?.let { br ->
                                MoneyRow("+ Cash sales", br["cash_sales"], code)
                                br["cash_in"].money?.takeIf { !it.isZero }?.let { MoneyRow("+ Cash in", br["cash_in"], code) }
                                MoneyRow("− Cash refunds", br["refunds"], code)
                                MoneyRow("− Voids", br["voids"], code)
                                MoneyRow("− Paid-outs", br["paid_outs"], code)
                            }
                            MoneyRow("Expected cash", b["expected_cash_at_count"]?.takeUnless { it.isNull } ?: b["breakdown"]["expected"] ?: b["expected_cash"], code, strong = true)
                            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                                Text("Variance", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary, modifier = Modifier.weight(1f))
                                VarianceText(b["variance"], code)
                            }
                            b["expected_basis_mismatch"]?.takeUnless { it.isNull }?.let { Money.from(it, code) }?.takeIf { !it.isZero }?.let {
                                CaptionText("Legacy register: the ledger figure differs from the old running column by ${it.formatted()}.", Palette.warning)
                            }
                            MoneyRow("Sales", b["total_sales"], code); MoneyRow("Cash sales", b["total_cash_sales"], code); MoneyRow("Card", b["total_card_sales"], code)
                            MoneyRow("M-Pesa", b["total_mpesa_sales"], code); MoneyRow("Refunds", b["total_refunds"], code)
                        }
                    }
                }
                val k = t["kes_summary"]
                if (!blind && k != null && !k.isNull && multi) SectionCard("Whole till in shillings (${k["basis"].str ?: "reporting rate"})") {
                    MoneyRow("Expected", k["expected"], "KES"); MoneyRow("Counted", k["counted"], "KES")
                    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                        Text("Variance", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary, modifier = Modifier.weight(1f))
                        VarianceText(k["variance"], "KES")
                    }
                    val u = k["unconverted"].records
                    if (u.isNotEmpty()) CaptionText("Not converted: ${u.mapNotNull { it.str }.joinToString(", ")}", Palette.warning)
                }
                t["notes"].str?.takeIf { it.isNotEmpty() }?.let { DetailRow("Opening notes", it) }
                t["variance_reason"].str?.takeIf { it.isNotEmpty() }?.let { DetailRow("Variance reason", it) }
                val d = t["discrepancies"].records
                if (d.isNotEmpty()) SectionCard("Discrepancies") {
                    d.forEach { x ->
                        val code = x["currency_code"].str ?: home
                        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                                    Text("${(x["direction"].str ?: "").replaceFirstChar { c -> c.uppercase() }} by", style = MaterialTheme.typography.bodySmall)
                                    MoneyText(Money.from(x["amount"], code))
                                    StatusBadge(x["status"].str)
                                }
                                Text(x["reason"].str ?: "", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                            }
                            val kes = x["amount_kes"]
                            if (kes != null && !kes.isNull && code != "KES") Text("= ${Money.from(kes, "KES")?.formatted() ?: ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        }
                    }
                }

                // Operator notes
                if (isOperator && t["finalized_at"].isNull && t["status"].str != "closed") SectionCard("Your closing notes") {
                    OutlinedTextField(value = closingNotes, onValueChange = { closingNotes = it }, label = { Text("Notes (before the till is finalized)") }, minLines = 2, modifier = Modifier.fillMaxWidth())
                    OutlinedButton(onClick = { scope.launch { run("Notes saved") { api.patch("${PosApi.BASE}/tills/$id", jsonOf("notes" to closingNotes)).json ?: JsonNull } } }, enabled = !busy) { Text("Save notes") }
                } else t["closing_notes"].str?.takeIf { it.isNotEmpty() }?.let { DetailRow("Closing notes", it) }

                // Verify & finalize
                if (env.session.current.can("pos.till_verify") && t["stage"].str == "awaiting_verification") {
                    val hasVariance = balances.any { b -> b["variance"]?.let { Money.from(it) }?.isZero == false }
                    SectionCard("Verify & finalize") {
                        if (isOperator) Text("You counted this till; someone else verifies it (maker ≠ checker).", style = MaterialTheme.typography.bodySmall, color = Palette.warning)
                        else {
                            if (hasVariance) OutlinedTextField(value = varianceReason, onValueChange = { varianceReason = it }, label = { Text("Why is the till over or short? (required)") }, minLines = 2, modifier = Modifier.fillMaxWidth())
                            OutlinedTextField(value = verifyNotes, onValueChange = { verifyNotes = it }, label = { Text("Verification notes (optional)") }, modifier = Modifier.fillMaxWidth())
                            CaptionText("Finalizing locks the till's money. A variance above the logged limit (at the reporting rate) is held for approval.")
                            ConfirmButton(
                                "Finalize till", "The till's figures are locked after this; finance can only add a linked correction.",
                                enabled = !busy && !(hasVariance && varianceReason.trim().length < 3),
                                action = {
                                    run("Till verified and finalized") {
                                        api.post("${PosApi.BASE}/tills/$id/finalize", jsonOf(
                                            "variance_reason" to (if (hasVariance) varianceReason.trim() else (varianceReason.ifEmpty { null } ?: Omit)),
                                            "notes" to (verifyNotes.ifEmpty { null } ?: Omit),
                                        )).json ?: JsonNull
                                    }
                                },
                            ) { Text("Verify & finalize") }
                        }
                    }
                }

                // Reconciliations
                val recs = t["reconciliations"]?.records?.takeIf { it.isNotEmpty() } ?: t["latest_reconciliations"].records
                if (recs.isNotEmpty()) SectionCard("Reconciliations against the payments ledger") {
                    recs.forEach { r ->
                        val code = r["currency_code"].str ?: home
                        Column(Modifier.padding(vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                                Text(code, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
                                StatusBadge(r["status"].str)
                                if (r["flagged_for_finance"].bool == true) StatusBadge("flagged for finance", StatusTone.DANGER)
                                Spacer(Modifier.weight(1f))
                                DateText(r["created_at"].date, DateStyle.RELATIVE)
                            }
                            MoneyRow("Till cash sales", r["till_cash_sales"], code)
                            MoneyRow("Payments ledger", r["payments_cash"], code)
                            MoneyRow("Difference", r["difference"], code)
                            r["missing_from_till_count"].int?.takeIf { it > 0 }?.let { n -> MoneyRow("Cash that reached no till ($n)", r["missing_from_till_amount"], code) }
                            r["reconciled_by"].str?.let { Text("By $it", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                            r["notes"].str?.takeIf { it.isNotEmpty() }?.let { Text(it, style = MaterialTheme.typography.labelSmall) }
                        }
                    }
                }
                if (env.session.current.can("pos.reconcile") && t["stage"].str == "finalized") {
                    val excluded = meId != null && (t["opened_by_id"].int == meId || t["verified_by_id"].int == meId)
                    SectionCard("Reconcile") {
                        if (excluded) Text("You counted or verified this till; reconciliation is independent.", style = MaterialTheme.typography.bodySmall, color = Palette.warning)
                        else {
                            OutlinedTextField(value = recNotes, onValueChange = { recNotes = it }, label = { Text("Notes (optional)") }, modifier = Modifier.fillMaxWidth())
                            Button(onClick = { scope.launch { run("Reconciled") { api.post("${PosApi.BASE}/tills/$id/reconcile", jsonOf("notes" to (recNotes.ifEmpty { null } ?: Omit))).json ?: JsonNull }; recNotes = "" } }, enabled = !busy) {
                                Text(if (busy) "Reconciling…" else "Reconcile against payments")
                            }
                        }
                    }
                }

                // Corrections
                val cs = t["corrections"].records
                if (cs.isNotEmpty()) SectionCard("Corrections") {
                    cs.forEach { c ->
                        val code = c["currency_code"].str ?: home
                        Column(Modifier.padding(vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                                Text(code, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
                                StatusBadge(c["status"].str)
                                Spacer(Modifier.weight(1f))
                                DateText(c["created_at"].date, DateStyle.RELATIVE)
                            }
                            Text(c["reason"].str ?: "", style = MaterialTheme.typography.bodySmall)
                            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                                Text("Count", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                                MoneyText(c["original_actual_cash"]?.let { Money.from(it, code) }); Text("→"); MoneyText(c["corrected_actual_cash"]?.let { Money.from(it, code) })
                            }
                            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                                Text("Variance", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                                MoneyText(c["original_variance"]?.let { Money.from(it, code) }); Text("→"); MoneyText(c["corrected_variance"]?.let { Money.from(it, code) })
                            }
                            c["opened_by"].str?.let { Text("By $it", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                        }
                    }
                }
                if (env.session.current.can("pos.till_correction") && t["stage"].str == "finalized") SectionCard("Record a correction") {
                    CaptionText("The original stays as it was counted; the correction is a linked record.")
                    if (balances.size > 1) {
                        val options = listOf("" to "Till's own ($home)") + balances.mapNotNull { it["currency_code"].str }.filter { it != home }.map { it to it }
                        SelectField("Currency", corrCurrency, options, { corrCurrency = it ?: "" }, Modifier.fillMaxWidth(), placeholder = "Till's own ($home)")
                    }
                    OutlinedTextField(value = corrReason, onValueChange = { corrReason = it }, label = { Text("Reason (at least 10 characters)") }, minLines = 2, modifier = Modifier.fillMaxWidth())
                    LabelledRow("Corrected count") { AmountField("leave blank to keep", corrActual, { corrActual = it }) }
                    LabelledRow("Corrected expected") { AmountField("leave blank to keep", corrExpected, { corrExpected = it }) }
                    OutlinedButton(onClick = {
                        scope.launch {
                            run("Correction recorded") {
                                api.post("${PosApi.BASE}/tills/$id/corrections", jsonOf(
                                    "reason" to corrReason.trim(),
                                    "currency_code" to (corrCurrency.ifEmpty { null } ?: Omit),
                                    "corrected_actual_cash" to (amountJson(corrActual) ?: Omit),
                                    "corrected_expected_cash" to (amountJson(corrExpected) ?: Omit),
                                )).json ?: JsonNull
                            }
                            corrReason = ""; corrActual = ""; corrExpected = ""
                        }
                    }, enabled = !busy && corrReason.trim().length >= 10) { Text(if (busy) "Recording…" else "Record correction") }
                }
            }
        }
    }
}

@Composable
private fun MoneyRow(label: String, v: JsonElement?, code: String, strong: Boolean = false) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Text(
            label, style = if (strong) MaterialTheme.typography.bodyMedium else MaterialTheme.typography.bodySmall, fontWeight = if (strong) FontWeight.SemiBold else FontWeight.Normal,
            color = if (strong) MaterialTheme.hub.text else MaterialTheme.hub.textSecondary, modifier = Modifier.weight(1f),
        )
        MoneyText(v?.takeUnless { it.isNull }?.let { Money.from(it, code) }, emphasis = strong)
    }
}
