package ke.co.bethanyhouse.hub.ui.features.pos

import android.net.Uri
import androidx.browser.customtabs.CustomTabsIntent
import androidx.compose.foundation.background
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.RemoveCircleOutline
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.runtime.toMutableStateList
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.core.Omit
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.money
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.PickedFile
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.rememberFilePicker
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import java.math.BigDecimal
import java.util.UUID

/**
 * Payment against the pending order (PaymentModal.tsx → POST
 * pos/pending-order/{id}/pay). Split tender across the configured methods,
 * M-PESA STK push with Daraja verification, Paystack, cash with change, bank
 * or other with a reference and proof. Every figure shown is the Hub's;
 * the only client arithmetic is the cash-change hint, labelled as such.
 */
private data class Tender(val id: String = UUID.randomUUID().toString(), val method: String, val amount: String, val reference: String = "", val cashReceived: String = "")

private sealed class StkState {
    data object Idle : StkState()
    data object Pushing : StkState()
    data object Waiting : StkState()
    data object Confirmed : StkState()
    data class Failed(val message: String) : StkState()
}

private enum class MethodKind {
    CASH, MPESA, PAYSTACK, CARD, BANK, OTHER;
    companion object {
        fun of(m: JsonElement?): MethodKind {
            val code = m["code"].str ?: ""
            val type = m["type"].str ?: ""
            val provider = m["provider"].str ?: ""
            return when {
                code == "cash" || type == "cash" -> CASH
                code == "mpesa" || provider == "safaricom" -> MPESA
                code == "paystack" || code == "card_paystack" || provider == "paystack" -> PAYSTACK
                type == "card" || code.contains("card") || code.contains("flutterwave") -> CARD
                type == "bank_transfer" || code == "bank_transfer" -> BANK
                else -> OTHER
            }
        }
    }
}

@Composable
internal fun PaymentSheet(session: PosSession, onDismiss: () -> Unit, onComplete: (PosSale) -> Unit) {
    val env = LocalEnv.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()

    val tenders = remember { mutableListOf<Tender>().toMutableStateList() }
    var method by remember { mutableStateOf("") }
    var amount by remember { mutableStateOf("") }
    var reference by remember { mutableStateOf("") }
    var cashReceived by remember { mutableStateOf("") }
    var split by remember { mutableStateOf(false) }
    var deposit by remember { mutableStateOf(false) }
    var depositAmount by remember { mutableStateOf("") }
    var proof by remember { mutableStateOf<PickedFile?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<ApiError?>(null) }
    // M-PESA
    var phone by remember { mutableStateOf("") }
    var stkState by remember { mutableStateOf<StkState>(StkState.Idle) }
    var stkCode by remember { mutableStateOf("") }
    var pollJob by remember { mutableStateOf<Job?>(null) }
    // Paystack
    var email by remember { mutableStateOf("") }
    var payUrl by remember { mutableStateOf<String?>(null) }

    val pickProof = rememberFilePicker(arrayOf("application/pdf", "image/*")) { proof = it }

    val order = session.pendingOrder["order"]
    val orderId = session.pendingOrderId
    val currency = session.currency
    val outstanding: Money? = (order["outstanding"] ?: session.pendingOrder["total_amount"])?.let { Money.from(it, currency) }
    val methods: List<JsonElement> = remember(session.paymentMethods) {
        val list = session.paymentMethods.filter { it["is_active"].bool != false }.toMutableList()
        if (list.none { it["code"].str == "__other__" }) list += jsonOf("code" to "__other__", "name" to "Other", "type" to "other", "requires_approval" to true)
        list
    }
    val selected = methods.firstOrNull { it["code"].str == method }
    val kind = MethodKind.of(selected)
    fun methodName(code: String) = methods.firstOrNull { it["code"].str == code }["name"].str ?: code.humanized

    LaunchedEffect(Unit) {
        if (method.isEmpty()) method = (methods.firstOrNull { it["is_default"].bool == true } ?: methods.firstOrNull())["code"].str ?: "cash"
        if (amount.isEmpty() && outstanding != null) amount = posPlainAmount(outstanding)
        session.customer?.phone?.takeIf { it.isNotEmpty() && !it.contains("•") }?.let { if (phone.isEmpty()) phone = it }
        session.customer?.email?.takeIf { it.isNotEmpty() && !it.contains("•") }?.let { if (email.isEmpty()) email = it }
    }
    LaunchedEffect(deposit) { if (deposit && depositAmount.isEmpty()) depositAmount = amount }
    DisposableEffect(Unit) { onDispose { pollJob?.cancel() } }

    /** The gateway settled server-side: read the sale back and show its receipt. */
    suspend fun completeFromServer() {
        val id = orderId ?: return
        val sale = runCatching { session.api.sale(id) }.getOrNull() ?: return
        env.toasts.show("Payment confirmed — sale complete")
        session.clearCart()
        onComplete(sale)
    }

    fun startPolling(everySeconds: Long, times: Int, accept: List<String>, onTimeout: () -> Unit) {
        val id = orderId ?: return
        pollJob?.cancel()
        pollJob = scope.launch {
            repeat(times) {
                delay(everySeconds * 1000)
                val o = runCatching { session.api.order(id) }.getOrNull()
                val st = o["payment_status"].str
                if (st != null && accept.contains(st)) { stkState = StkState.Confirmed; completeFromServer(); return@launch }
            }
            onTimeout()
        }
    }

    suspend fun stkPush() {
        val id = orderId ?: return
        stkState = StkState.Pushing; error = null
        try {
            session.api.initiatePayment(id, jsonOf("payment_method" to "mpesa", "phone" to phone.trim()))
            stkState = StkState.Waiting
            startPolling(10, 18, listOf("paid", "partial")) { stkState = StkState.Failed("No confirmation within 3 minutes. Verify the code the customer shows, or record it manually.") }
        } catch (e: ApiError) { stkState = StkState.Failed(e.message) } catch (e: Exception) { stkState = StkState.Failed(e.message ?: "Error") }
    }

    suspend fun verifyMpesa() {
        val id = orderId ?: return
        busy = true
        try {
            val o = session.api.order(id)
            val pending = o["payments"].records.firstOrNull { it["status"].str == "pending" && it["payment_method"].str == "mpesa" }
            val pid = pending["id"].int
            if (pid == null) {
                error = ApiError(0, "No pending M-Pesa payment on this order to verify — send an STK push first, or record the code manually.")
                return
            }
            val r = session.api.verifyMpesa(id, pid, stkCode.trim())
            if (r["pending"].bool == true) {
                env.toasts.show(r["message"].str ?: "Asked Safaricom to confirm; it will settle automatically.", StatusTone.INFO)
                startPolling(10, 18, listOf("paid", "partial")) { }
            } else {
                pollJob?.cancel(); stkState = StkState.Confirmed
                completeFromServer()
            }
        } catch (e: ApiError) { error = e } catch (e: Exception) { error = ApiError.transport(e.message ?: "Error") } finally { busy = false }
    }

    fun openUrl(url: String) {
        runCatching { CustomTabsIntent.Builder().build().launchUrl(context, Uri.parse(url)) }
            .onFailure { env.toasts.show("Could not open the payment page: ${it.message}", StatusTone.DANGER) }
    }

    suspend fun paystack() {
        val id = orderId ?: return
        busy = true
        try {
            val r = session.api.initiatePayment(id, jsonOf("payment_method" to "card_paystack", "email" to email.trim()))
            r["authorization_url"].str?.let { u ->
                payUrl = u
                openUrl(u)
                startPolling(5, 24, listOf("paid")) { payUrl = null; env.toasts.show("Paystack has not confirmed yet — try again or record the reference manually.", StatusTone.WARNING) }
            }
        } catch (e: ApiError) { error = e } catch (e: Exception) { error = ApiError.transport(e.message ?: "Error") } finally { busy = false }
    }

    fun addTender() {
        tenders += Tender(method = method, amount = amount, reference = reference, cashReceived = cashReceived)
        amount = ""; reference = ""; cashReceived = ""
    }

    suspend fun post(list: List<Tender>) {
        val id = orderId ?: return
        if (list.isEmpty()) return
        busy = true; error = null
        try {
            val rows = list.map { t ->
                listOfNotNull(
                    "method" to (if (t.method == "__other__") "other" else t.method),
                    amountJson(t.amount)?.let { "amount" to it.str!! },
                    t.reference.ifEmpty { null }?.let { "reference" to it },
                    amountJson(t.cashReceived)?.let { "cash_received" to it.str!! },
                )
            }
            val p = proof
            val reply: JsonElement = if (p != null) {
                val parts = mutableListOf<ApiClient.Part>()
                rows.forEachIndexed { i, r -> r.forEach { (k, v) -> parts += ApiClient.Part.field("payments[$i][$k]", v) } }
                if (deposit) amountJson(depositAmount)?.str?.let { d -> parts += ApiClient.Part.field("is_deposit", "true"); parts += ApiClient.Part.field("deposit_amount", d) }
                parts += p.part("proof_of_payment")
                session.api.recordPay(id, parts)
            } else {
                session.api.recordPay(id, jsonOf(
                    "payments" to JsonArray(rows.map { r -> jsonOf(*r.map { (k, v) -> k to (if (k == "amount" || k == "cash_received") amountJson(v)!! else v) }.toTypedArray()) }),
                    "is_deposit" to (if (deposit) true else Omit),
                    "deposit_amount" to (if (deposit) (amountJson(depositAmount) ?: Omit) else Omit),
                ))
            }
            pollJob?.cancel()
            val sale = PosSale(reply["order"] ?: kotlinx.serialization.json.JsonNull)
            if (reply["needs_approval"].bool == true) env.toasts.show("Payment submitted — awaiting admin approval", StatusTone.AMBER)
            else env.toasts.show(reply["message"].str ?: "Payment recorded")
            session.clearCart()
            onComplete(sale)
        } catch (e: ApiError) {
            error = e
            if (e.reason == "TILL_FROM_EARLIER_DAY" || e.reason == "TILL_NOT_OPEN") { session.refreshRegister(); onDismiss() }
        } catch (e: Exception) { error = ApiError.transport(e.message ?: "Error") } finally { busy = false }
    }

    /** Cash tendered minus the Hub's outstanding figure — a hint only, never persisted. */
    val changeHint: Money? = run {
        val o = outstanding ?: return@run null
        val c = amountJson(cashReceived)?.str ?: return@run null
        val tendered = runCatching { BigDecimal(c) }.getOrNull() ?: return@run null
        if (tendered > o.amount) Money(tendered - o.amount, o.currency) else null
    }

    val locked = busy || session.ownerWatchesOnly

    PosSheet("Take payment", subtitle = "${session.pendingOrder["order_number"].str ?: ""} · ${session.customer?.displayName ?: "Walk-in"}", onDismiss = { pollJob?.cancel(); onDismiss() }, actions = {
        TextButton(onClick = { pollJob?.cancel(); onDismiss() }) { Text("Cancel") }
        if (split) {
            OutlinedButton(onClick = { addTender() }, enabled = amountJson(amount) != null && method.isNotEmpty()) { Text("Add tender") }
            Button(onClick = { scope.launch { post(tenders.toList()) } }, enabled = tenders.isNotEmpty() && !locked) {
                Text("Post ${tenders.size} tender${if (tenders.size == 1) "" else "s"}")
            }
        } else if (kind != MethodKind.MPESA && kind != MethodKind.PAYSTACK) {
            Button(onClick = { scope.launch { post(listOf(Tender(method = method, amount = amount, reference = reference, cashReceived = cashReceived))) } }, enabled = amountJson(amount) != null && method.isNotEmpty() && !locked) {
                if (busy) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp) else Text("Record payment")
            }
        }
    }) {
        // Summary: the Hub's figures.
        Column(Modifier.fillMaxWidth().background(MaterialTheme.hub.card, MaterialTheme.shapes.small).padding(Space.m), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            TotalRow("Total", order["total"]?.let { Money.from(it, currency) })
            order["amount_paid"].money?.takeIf { !it.isZero }?.let { TotalRow("Paid so far", Money.from(order["amount_paid"], currency)) }
            TotalRow("Outstanding", outstanding, strong = true)
        }
        if (session.ownerWatchesOnly) OwnerWatchBanner()
        if (methods.size <= 1) Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Default.Warning, contentDescription = null, tint = Palette.warning, modifier = Modifier.size(16.dp))
            Spacer(Modifier.width(Space.s))
            Text("No payment methods are configured for $currency. Set them up under Setup › Payment Methods.", style = MaterialTheme.typography.bodySmall, color = Palette.warning)
        }
        ToggleRow("Split tender", split, { split = it })
        ToggleRow("Deposit / part payment", deposit, { deposit = it })
        if (deposit) {
            LabelledRow("Deposit amount ($currency)") { AmountField("0.00", depositAmount, { depositAmount = it }) }
            CaptionText("The Hub records the rest as outstanding (receivable), never as revenue collected.")
        }
        if (split && tenders.isNotEmpty()) {
            tenders.forEach { t ->
                Row(Modifier.fillMaxWidth().background(MaterialTheme.hub.card, MaterialTheme.shapes.small).padding(Space.s), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    Text(methodName(t.method), style = MaterialTheme.typography.bodySmall)
                    Text(t.amount, style = MaterialTheme.typography.bodyMedium)
                    if (t.reference.isNotEmpty()) Text(t.reference, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    Spacer(Modifier.weight(1f))
                    IconButton(onClick = { tenders.removeAll { it.id == t.id } }, modifier = Modifier.size(28.dp)) { Icon(Icons.Default.RemoveCircleOutline, contentDescription = "Remove", modifier = Modifier.size(18.dp)) }
                }
            }
            CaptionText("The Hub checks the tenders against the outstanding balance when they are posted.")
        }
        // Method picker
        Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            methods.forEach { m ->
                val code = m["code"].str ?: ""
                Chip(m["name"].str ?: code, method == code) { method = code; stkState = StkState.Idle; error = null }
            }
        }
        // Method panel
        LabelledRow("Amount ($currency)") { AmountField("0.00", amount, { amount = it }) }
        when (kind) {
            MethodKind.CASH -> {
                LabelledRow("Cash received") { AmountField("0.00", cashReceived, { cashReceived = it }) }
                changeHint?.let { CaptionText("Change: ${it.formatted()} (indicative — the Hub records the final figure on the receipt)", Palette.success) }
            }
            MethodKind.MPESA -> {
                if (currency != "KES") Text("M-Pesa takes shillings; this order is in $currency. Use card or bank transfer.", style = MaterialTheme.typography.bodySmall, color = Palette.warning)
                else {
                    when (val st = stkState) {
                        is StkState.Idle, is StkState.Failed -> {
                            OutlinedTextField(value = phone, onValueChange = { phone = it }, label = { Text("Customer phone") }, placeholder = { Text("07…") }, singleLine = true,
                                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone), modifier = Modifier.widthIn(max = 240.dp))
                            if (st is StkState.Failed) Text(st.message, style = MaterialTheme.typography.labelSmall, color = Palette.danger)
                            Button(onClick = { scope.launch { stkPush() } }, enabled = phone.trim().length >= 9 && !locked, colors = ButtonDefaults.buttonColors(containerColor = Palette.success)) { Text("Send STK push") }
                        }
                        StkState.Pushing -> Row(verticalAlignment = Alignment.CenterVertically) { CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp); Spacer(Modifier.width(Space.s)); Text("Sending to the phone…", style = MaterialTheme.typography.bodySmall) }
                        StkState.Waiting -> {
                            Row(verticalAlignment = Alignment.CenterVertically) { CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp); Spacer(Modifier.width(Space.s)); Text("Waiting for the customer to enter their PIN… (up to 3 minutes)", style = MaterialTheme.typography.bodySmall) }
                            TextButton(onClick = { pollJob?.cancel(); stkState = StkState.Idle }) { Text("Cancel push") }
                        }
                        StkState.Confirmed -> Row(verticalAlignment = Alignment.CenterVertically) { Icon(Icons.Default.CheckCircle, contentDescription = null, tint = Palette.success); Spacer(Modifier.width(Space.s)); Text("M-Pesa payment confirmed", color = Palette.success) }
                    }
                    HorizontalDivider(color = MaterialTheme.hub.line)
                    Text("Or confirm a payment the customer already made", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                        OutlinedTextField(value = stkCode, onValueChange = { stkCode = it.uppercase() }, placeholder = { Text("M-Pesa code (e.g. QJL3ABC7DE)") }, singleLine = true, modifier = Modifier.weight(1f))
                        OutlinedButton(onClick = { scope.launch { verifyMpesa() } }, enabled = stkCode.isNotBlank() && !busy) { Text("Verify") }
                    }
                    CaptionText("Verify asks Daraja for the code (sent as transaction_code).")
                    OutlinedButton(onClick = { scope.launch { post(listOf(Tender(method = method, amount = amount, reference = stkCode.trim()))) } }, enabled = stkCode.isNotBlank() && amountJson(amount) != null && !locked) { Text("Record manually with this code") }
                }
            }
            MethodKind.PAYSTACK -> {
                OutlinedTextField(value = email, onValueChange = { email = it }, label = { Text("Customer email") }, placeholder = { Text("name@example.com") }, singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email), modifier = Modifier.fillMaxWidth())
                val u = payUrl
                if (u != null) {
                    CaptionText("Payment page opened. Waiting for Paystack to confirm…", MaterialTheme.hub.textSecondary)
                    TextButton(onClick = { openUrl(u) }) { Text("Open the payment page again") }
                } else Button(onClick = { scope.launch { paystack() } }, enabled = email.contains("@") && !locked, colors = ButtonDefaults.buttonColors(containerColor = Palette.info)) { Text("Start card payment") }
                HorizontalDivider(color = MaterialTheme.hub.line)
                OutlinedTextField(value = reference, onValueChange = { reference = it }, label = { Text("Or record a card reference manually") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedButton(onClick = { scope.launch { post(listOf(Tender(method = method, amount = amount, reference = reference))) } }, enabled = reference.isNotEmpty() && amountJson(amount) != null && !locked) { Text("Record manually") }
            }
            MethodKind.CARD -> OutlinedTextField(value = reference, onValueChange = { reference = it }, label = { Text("Card / terminal reference") }, singleLine = true, modifier = Modifier.fillMaxWidth())
            MethodKind.BANK, MethodKind.OTHER -> {
                OutlinedTextField(value = reference, onValueChange = { reference = it }, label = { Text(if (kind == MethodKind.BANK) "Transfer reference" else "Reference / method name") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    OutlinedButton(onClick = { pickProof() }) { Text(if (proof == null) "Attach proof of payment" else "Replace proof") }
                    proof?.let { p ->
                        Text(p.name, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, modifier = Modifier.weight(1f, fill = false))
                        IconButton(onClick = { proof = null }, modifier = Modifier.size(28.dp)) { Icon(Icons.Default.Close, contentDescription = "Remove proof", modifier = Modifier.size(16.dp)) }
                    }
                }
                if (selected["requires_approval"].bool ?: true) CaptionText("This method is held for admin approval; the sale shows an invoice until it is approved.", Palette.amber)
            }
        }
        error?.let { Text(it.firstFieldError ?: it.message, style = MaterialTheme.typography.bodySmall, color = Palette.danger) }
    }
}
