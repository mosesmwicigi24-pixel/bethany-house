package ke.co.bethanyhouse.hub.ui.features.pos

import androidx.compose.foundation.background
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
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.History
import androidx.compose.material.icons.filled.PersonOff
import androidx.compose.material.icons.filled.Remove
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.core.Omit
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.DayField
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.formatDate
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull

/**
 * Sales history (SalesHistoryDrawer.tsx): today's sales by this cashier,
 * restore an unpaid order, request a void (Phase 4B: a request, voided only
 * when an approver signs — on this till with their PIN or from the inbox).
 */
@Composable
internal fun SalesHistorySheet(session: PosSession, onDismiss: () -> Unit, onRestore: (PosSale) -> Unit) {
    val scope = rememberCoroutineScope()
    var day by remember { mutableStateOf(Day.today()) }
    var search by remember { mutableStateOf("") }
    var sales by remember { mutableStateOf<Resource<List<PosSale>>?>(null) }
    var selected by remember { mutableStateOf<PosSale?>(null) }

    fun make() {
        val outlet = session.outlet ?: return
        val d = day
        sales = Resource(scope) { session.api.sales(outlet.id, mapOf("date" to d, "my_orders_only" to "1")) }.also { it.load() }
    }
    LaunchedEffect(day) { make() }

    PosSheet("Sales history", subtitle = session.outlet?.name, onDismiss = onDismiss, actions = { TextButton(onClick = onDismiss) { Text("Close") } }) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            DayField("Day", day, { if (it.isNotEmpty()) day = it }, allowClear = false)
            OutlinedTextField(value = search, onValueChange = { search = it }, placeholder = { Text("Order number or customer") }, singleLine = true, modifier = Modifier.weight(1f))
        }
        val res = sales
        if (res != null) LoadableView(res.state, retry = { res.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("No sales on this day", icon = Icons.Default.History) }) { list ->
            val shown = if (search.isEmpty()) list else list.filter { it.orderNumber.contains(search, true) || (it.customerName ?: "").contains(search, true) }
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                shown.forEach { s -> SaleRow(s) { selected = s } }
                CaptionText("Each sale is shown in its own currency; the drawer adds nothing across currencies.")
            }
        }
    }
    selected?.let { s ->
        SaleDetailSheet(session, s, onDismiss = { selected = null }, onRestore = { onRestore(it) }, onChanged = { sales?.load() })
    }
}

@Composable
internal fun SaleRow(sale: PosSale, onClick: () -> Unit) {
    Surface(shape = MaterialTheme.shapes.small, color = MaterialTheme.hub.card, modifier = Modifier.fillMaxWidth().border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.small).clickable(onClick = onClick)) {
        Row(Modifier.padding(Space.m), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    Text(sale.orderNumber, style = MaterialTheme.typography.bodyMedium)
                    if (sale.isRestorable) StatusBadge("awaiting payment", StatusTone.AMBER) else StatusBadge(sale.status)
                }
                Text("${sale.customerName ?: "Walk-in"} · ${sale.json["payment_method"].str?.humanized ?: ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                DateText(sale.createdAt, DateStyle.TIME)
            }
            Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                MoneyText(sale.total)
                if (sale.pendingReversals.isNotEmpty()) StatusBadge("reversal pending", StatusTone.WARNING)
            }
        }
    }
}

@Composable
internal fun SaleDetailSheet(session: PosSession, initial: PosSale, onDismiss: () -> Unit, onRestore: (PosSale) -> Unit, onChanged: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var sale by remember { mutableStateOf(initial) }
    var askingVoid by remember { mutableStateOf(false) }
    var voidReason by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var pin by remember { mutableStateOf<PinRequest?>(null) }

    suspend fun reload() {
        runCatching { session.api.sale(sale.id) }.getOrNull()?.let { sale = it }
        onChanged()
    }

    suspend fun requestVoid() {
        busy = true
        try {
            val r = session.api.requestVoid(sale.id, voidReason.trim())
            env.toasts.show(r["message"].str ?: "Void requested", StatusTone.AMBER)
            askingVoid = false
            reload()
            r["approval"]["id"].int?.let { aid -> pin = PinRequest(aid, "Void ${sale.orderNumber}", r["approval"]["awaiting"]["permission"].str) }
        } catch (e: Exception) { env.toasts.error(e) } finally { busy = false }
    }

    val canVoid = env.session.current.can("pos.void") && !session.ownerWatchesOnly && sale.status != "voided" && sale.pendingReversals.none { it["kind"].str == "void" } && !askingVoid

    PosSheet(sale.orderNumber, subtitle = sale.createdAt?.let { formatDate(it) }, onDismiss = onDismiss, actions = {
        if (sale.isRestorable && !session.ownerWatchesOnly) OutlinedButton(onClick = { onRestore(sale); onDismiss() }) { Text("Restore to cart") }
        if (canVoid) OutlinedButton(onClick = { askingVoid = true }) { Text("Request void") }
        TextButton(onClick = onDismiss) { Text("Close") }
    }) {
        sale.pendingReversals.forEach { p ->
            Column(Modifier.fillMaxWidth().background(Palette.warningSoft, MaterialTheme.shapes.small).padding(Space.m), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    Text(if (p["kind"].str == "void") "Void requested" else "Refund requested", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
                    p["amount"]?.takeUnless { it.isNull }?.let { MoneyText(Money.from(it, p["currency_code"].str ?: sale.currency)) }
                }
                val askedBy = p["requested_by"].str ?: ""
                Text("Waiting for ${PinRequest.bandLabel(p["awaiting"].str)}${if (askedBy.isEmpty()) "" else " · asked by $askedBy"}. Nothing has changed on the sale yet.", style = MaterialTheme.typography.labelSmall, color = Palette.amber)
                p["approval_id"].int?.let { aid ->
                    OutlinedButton(onClick = { pin = PinRequest(aid, "${if (p["kind"].str == "void") "Void" else "Refund on"} ${sale.orderNumber}", p["awaiting"].str) }) { Text("Approver here? Enter their PIN") }
                }
            }
        }
        if (sale.isRestorable) CaptionText("No payment recorded. Restore it to the cart to adjust it or take payment — the same order is paid, not a second one.", Palette.brandDeep)
        ReceiptBody(sale, session.outlet)
        if (askingVoid) {
            OutlinedTextField(value = voidReason, onValueChange = { voidReason = it }, label = { Text("Reason for the void (required)") }, minLines = 2, modifier = Modifier.fillMaxWidth())
            Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                TextButton(onClick = { askingVoid = false; voidReason = "" }) { Text("Cancel") }
                Button(onClick = { scope.launch { requestVoid() } }, enabled = voidReason.trim().length >= 3 && !busy, colors = ButtonDefaults.buttonColors(containerColor = Palette.danger)) {
                    if (busy) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp) else Text("Request void")
                }
            }
        }
    }
    pin?.let { req -> TillApprovalPinSheet(session, req, onDismiss = { pin = null }) { scope.launch { reload() } } }
}

// ── Approver PIN (PosTillApprovalController: approvers, pin-sign) ────────────

internal data class PinRequest(val approvalId: Int, val title: String, val awaiting: String?) {
    companion object {
        /** Who a band is waiting on, in the words the till uses (TillApprovalPinModal.tsx). */
        fun bandLabel(permission: String?): String = when (permission) {
            "pos.approve_reversal" -> "an outlet manager"
            "approvals.finance_sign" -> "finance"
            "approvals.super_sign" -> "the owner"
            else -> "an approver"
        }
    }
}

@Composable
internal fun TillApprovalPinSheet(session: PosSession, request: PinRequest, onDismiss: () -> Unit, onDecided: (String) -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val approvers = remember(request.approvalId) { Resource(scope) { session.api.approvers(request.approvalId) } }
    var approverId by remember { mutableStateOf<Int?>(null) }
    var pin by remember { mutableStateOf("") }
    var reject by remember { mutableStateOf(false) }
    var reason by remember { mutableStateOf("") }
    var waitingOn by remember { mutableStateOf<String?>(null) }
    var note by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    LaunchedEffect(approvers) { approvers.load() }

    val canSubmit = approverId != null && pin.length in 4..6 && pin.all { it.isDigit() } && (!reject || reason.trim().length >= 3) && !busy

    suspend fun sign() {
        val aid = approverId ?: return
        busy = true
        try {
            val r = session.api.pinSign(request.approvalId, jsonOf(
                "approver_id" to aid, "pin" to pin, "decision" to (if (reject) "reject" else "approve"), "reason" to (if (reject) reason else Omit),
            ))
            pin = ""; error = null
            val status = r["request"]["status"].str ?: ""
            if (status == "pending") {
                approverId = null
                waitingOn = r["request"]["awaiting"]["permission"].str
                note = r["message"].str
                approvers.load()
                return
            }
            env.toasts.show(r["message"].str ?: "Signed")
            onDecided(status)
            onDismiss()
        } catch (e: ApiError) { pin = ""; error = e.message } catch (e: Exception) { pin = ""; error = e.message ?: "Error" } finally { busy = false }
    }

    PosSheet("Approver at the till", subtitle = request.title, onDismiss = onDismiss, actions = {
        TextButton(onClick = onDismiss) { Text("Leave for the inbox") }
        Button(onClick = { scope.launch { sign() } }, enabled = canSubmit, colors = if (reject) ButtonDefaults.buttonColors(containerColor = Palette.danger) else ButtonDefaults.buttonColors()) {
            if (busy) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp) else Text(if (reject) "Reject" else "Sign")
        }
    }) {
        Text(
            "Waiting for ${PinRequest.bandLabel(waitingOn ?: request.awaiting)}. They enter their own PIN here — never yours.",
            style = MaterialTheme.typography.labelSmall, color = Palette.amber, modifier = Modifier.fillMaxWidth().background(Palette.amberSoft, MaterialTheme.shapes.small).padding(Space.s),
        )
        note?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = Palette.success) }
        LoadableView(approvers.state, retry = { approvers.load() }, isEmpty = { it.isEmpty() }, empty = {
            EmptyState("Nobody at this outlet can sign this now", "An approver can sign from their Approvals inbox instead.", Icons.Default.PersonOff)
        }) { list ->
            val options = list.mapNotNull { a -> a["id"].int?.let { it to ((a["name"].str ?: "") + (if (a["pin_set"].bool == false) " (no PIN set)" else "")) } }
            SelectField("Approver", approverId, options, { approverId = it }, Modifier.fillMaxWidth(), placeholder = "Choose…")
        }
        SingleChoiceSegmentedButtonRow(Modifier.widthIn(max = 240.dp)) {
            SegmentedButton(selected = !reject, onClick = { reject = false }, shape = SegmentedButtonDefaults.itemShape(0, 2)) { Text("Approve") }
            SegmentedButton(selected = reject, onClick = { reject = true }, shape = SegmentedButtonDefaults.itemShape(1, 2)) { Text("Reject") }
        }
        if (reject) OutlinedTextField(value = reason, onValueChange = { reason = it }, label = { Text("Reason (required)") }, minLines = 2, modifier = Modifier.fillMaxWidth())
        OutlinedTextField(
            value = pin, onValueChange = { pin = it.filter { c -> c.isDigit() }.take(6) }, label = { Text("Approver's terminal PIN") }, singleLine = true,
            visualTransformation = PasswordVisualTransformation(), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword), modifier = Modifier.widthIn(max = 220.dp),
        )
        error?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = Palette.danger) }
    }
}

// ── Returns (POST pos/returns → a refund REQUEST, signed with a PIN) ─────────

private val RETURN_REASONS = listOf("Defective / damaged product", "Wrong item received", "Customer changed mind", "Size / fit issue", "Duplicate purchase", "Other")
private val REFUND_METHODS = listOf("cash" to "Cash", "mpesa" to "M-Pesa", "store_credit" to "Store credit", "card" to "Card")

@Composable
internal fun ReturnsSheet(session: PosSession, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var query by remember { mutableStateOf("") }
    var results by remember { mutableStateOf<List<PosSale>>(emptyList()) }
    var searchJob by remember { mutableStateOf<Job?>(null) }
    var sale by remember { mutableStateOf<PosSale?>(null) }
    val quantities = remember { mutableStateMapOf<Int, Int>() }
    var reason by remember { mutableStateOf(RETURN_REASONS[0]) }
    var customReason by remember { mutableStateOf("") }
    var refundMethod by remember { mutableStateOf("cash") }
    var busy by remember { mutableStateOf(false) }
    var pin by remember { mutableStateOf<PinRequest?>(null) }

    LaunchedEffect(query) {
        searchJob?.cancel()
        val outlet = session.outlet
        if (query.length < 3 || outlet == null) { results = emptyList(); return@LaunchedEffect }
        searchJob = scope.launch {
            delay(350)
            results = runCatching { session.api.sales(outlet.id, mapOf("search" to query)) }.getOrDefault(emptyList())
        }
    }

    suspend fun submit() {
        val s = sale ?: return
        busy = true
        try {
            val items = s.items.mapNotNull { i ->
                val id = i["id"].int ?: return@mapNotNull null
                val q = quantities[id]?.takeIf { it > 0 } ?: return@mapNotNull null
                jsonOf("variant_id" to (i["variant_id"] ?: JsonNull), "quantity" to q)
            }
            val r = session.api.processReturn(jsonOf(
                "original_order_id" to s.id, "items" to JsonArray(items),
                "reason" to (if (reason == "Other") customReason else reason), "refund_method" to refundMethod,
            ))
            env.toasts.show(r["message"].str ?: "Refund requested", StatusTone.AMBER)
            val amount = r["refund_amount"]?.let { Money.from(it, s.currency) }?.formatted() ?: ""
            r["approval"]["id"].int?.let { aid -> pin = PinRequest(aid, "Refund on ${s.orderNumber} · $amount", r["approval"]["awaiting"]["permission"].str) }
        } catch (e: Exception) { env.toasts.error(e) } finally { busy = false }
    }

    PosSheet("Returns & refunds", subtitle = "A refund is a request; an approver signs it before anything is refunded.", onDismiss = onDismiss, actions = {
        TextButton(onClick = onDismiss) { Text("Close") }
        if (sale != null) Button(onClick = { scope.launch { submit() } }, enabled = !busy && !session.ownerWatchesOnly && quantities.values.any { it > 0 } && !(reason == "Other" && customReason.isEmpty())) {
            if (busy) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp) else Text("Request refund")
        }
    }) {
        val s = sale
        if (s != null) {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                Text(s.orderNumber, style = MaterialTheme.typography.titleMedium)
                StatusBadge(s.status)
                Spacer(Modifier.weight(1f))
                MoneyText(s.total)
                TextButton(onClick = { sale = null }) { Text("Change") }
            }
            s.items.forEach { i ->
                val itemId = i["id"].int ?: 0
                val sold = i["quantity"].int ?: 0
                val q = quantities[itemId] ?: 0
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(1.dp)) {
                        Text(i["product_name"].str ?: "Item", style = MaterialTheme.typography.bodySmall)
                        Text("$sold sold · ${Money.from(i["unit_price"], s.currency)?.formatted() ?: ""} each", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    }
                    IconButton(onClick = { if (q > 0) quantities[itemId] = q - 1 }, enabled = q > 0) { Icon(Icons.Default.Remove, contentDescription = "Less") }
                    Text("Return $q", style = MaterialTheme.typography.bodySmall, textAlign = TextAlign.Center, modifier = Modifier.widthIn(min = 64.dp))
                    IconButton(onClick = { if (q < sold) quantities[itemId] = q + 1 }, enabled = q < sold) { Icon(Icons.Default.Add, contentDescription = "More") }
                }
            }
            SelectField("Reason", reason, RETURN_REASONS.map { it to it }, { reason = it ?: RETURN_REASONS[0] }, Modifier.fillMaxWidth(), placeholder = "Reason")
            if (reason == "Other") OutlinedTextField(value = customReason, onValueChange = { customReason = it }, label = { Text("Describe the reason") }, singleLine = true, modifier = Modifier.fillMaxWidth())
            SelectField("Refund by", refundMethod, REFUND_METHODS, { refundMethod = it ?: "cash" }, Modifier.fillMaxWidth(), placeholder = "Refund by")
            CaptionText("The refund amount is computed by the Hub from the lines returned and shown with the approval request.")
        } else {
            OutlinedTextField(value = query, onValueChange = { query = it }, label = { Text("Order number, name or phone (3+ characters)") }, singleLine = true, modifier = Modifier.fillMaxWidth())
            results.forEach { r -> SaleRow(r) { sale = r; quantities.clear() } }
        }
    }
    pin?.let { req -> TillApprovalPinSheet(session, req, onDismiss = { pin = null }) { onDismiss() } }
}
