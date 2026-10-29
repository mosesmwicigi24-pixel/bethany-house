package ke.co.bethanyhouse.hub.ui.features.sales

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
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
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
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.HubDates
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toJson
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.AsyncButton
import ke.co.bethanyhouse.hub.ui.components.DateField
import ke.co.bethanyhouse.hub.ui.components.DecimalField
import ke.co.bethanyhouse.hub.ui.components.DetailRow
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.KeyValueEditor
import ke.co.bethanyhouse.hub.ui.components.KeyValueRow
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import java.time.LocalDate
import java.time.format.DateTimeFormatter

// Every sheet an order's actions open. Payload keys are the controllers'
// validation rules (OrderController, PaymentController,
// PaymentApprovalController, SalesDocumentRevisionController).

// ── Status — PUT /orders/{id}/status {status, tracking_number?, notes?} ─────

@Composable
internal fun OrderStatusSheet(order: Record, onDone: () -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    val next = Sales.nextStatuses(order.status)
    var status by remember { mutableStateOf(next.firstOrNull() ?: "") }
    var tracking by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    FormSheet("Update order status", onDismiss, saveLabel = "Update", canSave = status.isNotEmpty(), save = {
        val body = mutableMapOf<String, Any?>("status" to status)
        if (tracking.isNotBlank()) body["tracking_number"] = tracking
        if (notes.isNotBlank()) body["notes"] = notes
        env.client.put("/v1/admin/orders/${order.id}/status", toJson(body))
        env.toasts.show("Status updated")
        onDone()
    }) { error ->
        SalesSecondary("From ${(order.status ?: "").humanized} the order may move to:")
        OptionPicker("New status", status, next.map { it to it.humanized }, { status = it }, error = error?.field("status"))
        if (status == "shipped") Field("Tracking number (optional)", tracking, { tracking = it })
        if (status == "cancelled") SalesCaption("Cancelling takes the order out of recognised sales; settled money stays until reversed or refunded.", Palette.danger)
        if (status == "completed" && order.string("payment_status") != "paid") SalesCaption("The order is not fully paid; the server may refuse Completed.", Palette.warning)
        Field("Note (optional)", notes, { notes = it }, multiline = true)
        SalesFieldErrors(error)
    }
}

// ── Record payment — POST /orders/{id}/payments, then POST /payments/{p}/upload-proof ─

/**
 * Methods come from GET /v1/payment-methods?currency= (the sale picker, not
 * Setup); `requires_approval` is the server's classification. "Other" sends
 * method=other with custom_method_name. An M-PESA STK push is
 * POST /orders/{id}/payment {payment_method: mpesa, phone}; the payment
 * then settles when Safaricom answers (verify from the payment row).
 */
@Composable
internal fun RecordPaymentSheet(order: Record, onDone: () -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    val currency = order.string("currency_code") ?: "KES"
    var methods by remember { mutableStateOf<List<Record>>(emptyList()) }
    var method by remember { mutableStateOf("") }
    var customName by remember { mutableStateOf("") }
    var amount by remember { mutableStateOf("") }
    var reference by remember { mutableStateOf("") }
    var phone by remember { mutableStateOf("") }
    var taxInclusive by remember { mutableStateOf(true) }
    var notes by remember { mutableStateOf("") }
    var proof by remember { mutableStateOf<SalesPickedFile?>(null) }

    LaunchedEffect(currency) {
        try {
            methods = env.client.get("/v1/payment-methods", mapOf("currency" to currency)).json.salesRecords.filter { m ->
                if (m["is_active"].bool == false) return@filter false
                val supported = m["supported_currencies"].records.mapNotNull { it.str }
                supported.isEmpty() || currency in supported
            }
            if (method.isEmpty()) method = methods.firstOrNull()?.string("code") ?: ""
        } catch (e: Exception) { env.toasts.error(e) }
    }

    val selected = methods.firstOrNull { it.string("code") == method }
    val isOther = method == "__other__"
    val effectiveMethod = if (isOther) "other" else method
    val requiresApproval = if (isOther) true else (selected?.get("requires_approval").bool ?: (effectiveMethod !in listOf("cash", "mpesa", "card", "card_paystack", "card_flutterwave", "paystack")))
    val referenceRequired = effectiveMethod == "bank_transfer" || isOther
    val canSubmit = Sales.amountJson(amount) != null && method.isNotEmpty() && (!referenceRequired || reference.isNotBlank()) && (!isOther || customName.isNotBlank())

    FormSheet("Record payment", onDismiss, saveLabel = if (requiresApproval) "Submit for approval" else "Record", canSave = canSubmit, save = {
        val amt = Sales.amountJson(amount) ?: return@FormSheet
        val body = mutableMapOf<String, JsonElement>("method" to JsonPrimitive(effectiveMethod), "amount" to amt, "tax_inclusive" to JsonPrimitive(taxInclusive))
        if (reference.isNotBlank()) body["reference"] = JsonPrimitive(reference)
        if (phone.isNotBlank()) body["phone"] = JsonPrimitive(phone)
        if (notes.isNotBlank()) body["notes"] = JsonPrimitive(notes)
        if (isOther) body["custom_method_name"] = JsonPrimitive(customName)
        val r = env.client.post("/v1/admin/orders/${order.id}/payments", JsonObject(body))
        val pid = r.json["payment"]["id"].str
        val p = proof
        if (p != null && pid != null) env.client.upload("/v1/admin/payments/$pid/upload-proof", listOf(p.part("proof")))
        val needs = r.json["requires_approval"].bool ?: requiresApproval
        env.toasts.show(if (needs) "Payment submitted — awaiting approval before it takes effect" else "Payment recorded")
        onDone()
    }) { error ->
        DetailRow("Order total") { MoneyText(order.money("total_amount")) }
        DetailRow("Payment status", order.string("payment_status")?.humanized)
        if (methods.isEmpty()) SalesCaption("No payment method is set up for $currency. Set them up in Setup › Payment Methods.", Palette.warning)
        OptionPicker("Method", method, methods.map { (it.string("code") ?: "") to (it.string("name") ?: it.string("code") ?: "") } + ("__other__" to "Other"), { method = it }, error = error?.field("method"))
        if (isOther) Field("Method name (e.g. Cheque, Wire transfer)", customName, { customName = it }, error = error?.field("custom_method_name"))
        DecimalField("Amount ($currency)", amount, { amount = it }, error = error?.field("amount"))
        if (effectiveMethod == "mpesa") {
            Field("Customer phone", phone, { phone = it }, error = error?.field("phone"))
            AsyncButton("Send STK push", outlined = true, enabled = phone.isNotBlank()) {
                env.client.post("/v1/admin/orders/${order.id}/payment", jsonOf("payment_method" to "mpesa", "phone" to phone))
                env.toasts.show("STK push sent — the payment settles when the customer confirms; verify it from the payment row if it stays pending", StatusTone.INFO)
                onDone(); onDismiss()
            }
            SalesCaption("or record a payment already received with its receipt below", MaterialTheme.hub.textTertiary)
        }
        Field(if (referenceRequired) "Reference (required)" else "Reference / receipt", reference, { reference = it }, error = error?.field("reference"))
        ToggleRow("Amount includes tax", taxInclusive, { taxInclusive = it })
        Field("Notes", notes, { notes = it }, multiline = true)
        if (requiresApproval) {
            SalesCaption("This method needs an approver's signature; attach proof (PDF or image, max 10 MB).", Palette.amber)
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                SalesFilePickButton(if (proof == null) "Attach proof" else "Replace proof") { proof = it.firstOrNull() }
                proof?.let { SalesCaption(it.file.name) }
            }
        }
        SalesFieldErrors(error)
    }
}

/** POST /payments/{id}/upload-proof (multipart `proof`) — payments.upload_proof. */
@Composable
internal fun ProofUploadButton(paymentId: String, onDone: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    SalesFilePickButton(if (busy) "Uploading…" else "Upload proof", busy = busy) { files ->
        val f = files.firstOrNull() ?: return@SalesFilePickButton
        busy = true
        scope.launch {
            try {
                env.client.upload("/v1/admin/payments/$paymentId/upload-proof", listOf(f.part("proof")))
                env.toasts.show("Proof uploaded — awaiting review")
                onDone()
            } catch (e: Exception) { env.toasts.error(e) } finally { busy = false }
        }
    }
}

// ── Verify — POST /orders/{id}/payments/{p}/verify-mpesa | verify-paystack ─

@Composable
internal fun VerifyMpesaSheet(order: Record, payment: Record, onDone: () -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var code by remember { mutableStateOf("") }
    FormSheet("Verify with Daraja", onDismiss, saveLabel = "Verify", save = {
        val body = if (code.isBlank()) jsonOf() else jsonOf("transaction_code" to code.trim())
        val r = env.client.post("/v1/admin/orders/${order.id}/payments/${payment.id}/verify-mpesa", body)
        val pending = r.status == 202 || r.json["pending"].bool == true
        env.toasts.show(r.json["message"].str ?: if (pending) "Safaricom was asked; the payment updates when they answer" else "Payment confirmed", if (pending) StatusTone.INFO else StatusTone.SUCCESS)
        onDone()
    }) { error ->
        SalesSecondary("Leave the code empty to query the STK push; enter the M-PESA receipt code for a payment made offline.")
        Field("M-PESA receipt code (optional)", code, { code = it }, error = error?.field("transaction_code"))
        SalesFieldErrors(error)
    }
}

@Composable
internal fun VerifyPaystackSheet(order: Record, payment: Record, onDone: () -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var reference by remember { mutableStateOf(payment.string("provider_reference") ?: "") }
    FormSheet("Verify with Paystack", onDismiss, saveLabel = "Verify", canSave = reference.isNotBlank(), save = {
        val r = env.client.post("/v1/admin/orders/${order.id}/payments/${payment.id}/verify-paystack", jsonOf("reference" to reference.trim()))
        env.toasts.show(r.json["message"].str ?: "Paystack payment confirmed")
        onDone()
    }) { error ->
        Field("Paystack reference", reference, { reference = it }, error = error?.field("reference"))
        SalesFieldErrors(error)
    }
}

// ── Reverse — POST /orders/{id}/payments[/{p}]/reverse {reason, restore_status} ─

@Composable
internal fun ReversePaymentSheet(order: Record, payment: Record?, onDone: () -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var reason by remember { mutableStateOf("") }
    var restore by remember { mutableStateOf(true) }
    val superAdmin = env.session.current.permissions.isSuperAdmin
    FormSheet(if (payment == null) "Reverse every settled payment" else "Reverse payment ${payment.string("payment_number") ?: ""}", onDismiss,
        saveLabel = if (superAdmin) "Reverse now" else "Ask finance to reverse", canSave = reason.trim().length >= 3, destructive = true, save = {
            val path = if (payment != null) "/v1/admin/orders/${order.id}/payments/${payment.id}/reverse" else "/v1/admin/orders/${order.id}/payments/reverse"
            val r = env.client.post(path, jsonOf("reason" to reason.trim(), "restore_status" to restore))
            env.toasts.show(r.json["message"].str ?: if (r.status == 202) "Reversal requested — finance signs it" else "Payment reversed", if (r.status == 202) StatusTone.INFO else StatusTone.SUCCESS)
            onDone()
        }) { error ->
        if (payment != null) DetailRow("Amount") { MoneyText(payment.money("amount", payment.string("currency_code") ?: order.string("currency_code"))) }
        SalesSecondary("Nothing is deleted: the payment is voided with the reason; stock and the receipt follow. The super admin executes at once; everyone else raises a payment_void request finance signs.")
        Field("Why was this payment a mistake?", reason, { reason = it }, multiline = true, error = error?.field("reason"))
        ToggleRow("Restore the order status the payment had advanced", restore, { restore = it })
        SalesFieldErrors(error)
    }
}

// ── Correct payment details — PUT /orders/{id}/payments/{p}/revise (super admin) ─

private val PAID_AT_FORMAT: DateTimeFormatter = DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm")

@Composable
internal fun RevisePaymentSheet(order: Record, payment: Record, onDone: () -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var reason by remember { mutableStateOf("") }
    var reference by remember { mutableStateOf(payment.string("provider_reference") ?: "") }
    var phone by remember { mutableStateOf(payment.string("phone_number") ?: "") }
    var method by remember { mutableStateOf("") }
    var paidAt by remember { mutableStateOf(payment.date("paid_at")?.atZone(HubDates.nairobi)?.format(PAID_AT_FORMAT) ?: "") }
    var methods by remember { mutableStateOf<List<Record>>(emptyList()) }
    LaunchedEffect(Unit) { runCatching { methods = env.client.get("/v1/payment-methods", mapOf("currency" to order.string("currency_code"))).json.salesRecords } }
    val paidAtInstant = paidAt.trim().takeIf { it.isNotEmpty() }?.let(HubDates::parse)
    val paidAtInvalid = paidAt.isNotBlank() && paidAtInstant == null

    FormSheet("Correct payment details", onDismiss, saveLabel = "Save correction", canSave = reason.trim().length >= 5 && !paidAtInvalid, save = {
        val body = mutableMapOf<String, JsonElement>("reason" to JsonPrimitive(reason.trim()))
        body["provider_reference"] = Sales.optionalString(reference)
        body["phone_number"] = Sales.optionalString(phone)
        if (method.isNotEmpty()) body["payment_method"] = JsonPrimitive(method)
        paidAtInstant?.let { body["paid_at"] = JsonPrimitive(HubDates.iso(it)) }
        env.client.put("/v1/admin/orders/${order.id}/payments/${payment.id}/revise", JsonObject(body))
        env.toasts.show("Payment details corrected")
        onDone()
    }) { error ->
        SalesSecondary("The amount of a recorded payment is never edited here: void it and record it again. The previous details are kept as a revision.")
        Field("Reason (required)", reason, { reason = it }, multiline = true, error = error?.field("reason"))
        Field("Provider reference", reference, { reference = it }, error = error?.field("provider_reference"))
        Field("Phone number", phone, { phone = it }, error = error?.field("phone_number"))
        OptionPicker("Method", method, listOf("" to "Keep ${(payment.string("payment_method") ?: "").humanized}") + methods.map { (it.string("code") ?: "") to (it.string("name") ?: "") }, { method = it }, allowNone = true)
        Field("Paid at (yyyy-MM-dd HH:mm, Nairobi)", paidAt, { paidAt = it }, error = if (paidAtInvalid) "Not a date" else error?.field("paid_at"))
        SalesFieldErrors(error)
    }
}

// ── Refund — POST /orders/{id}/refund {amount, reason, refund_shipping} ─────

@Composable
internal fun RefundSheet(order: Record, onDone: () -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var amount by remember { mutableStateOf("") }
    var reason by remember { mutableStateOf("") }
    var refundShipping by remember { mutableStateOf(false) }
    FormSheet("Refund ${order.string("order_number") ?: ""}", onDismiss, saveLabel = "Refund", canSave = Sales.amountJson(amount) != null && reason.isNotBlank(), destructive = true, save = {
        val amt = Sales.amountJson(amount) ?: return@FormSheet
        val r = env.client.post("/v1/admin/orders/${order.id}/refund", JsonObject(mapOf("amount" to amt, "reason" to JsonPrimitive(reason), "refund_shipping" to JsonPrimitive(refundShipping))))
        env.toasts.show(r.json["message"].str ?: "Refund recorded")
        onDone()
    }) { error ->
        SalesSecondary("A refund moves cash, revenue, tax, stock and COGS; the server refuses more than was collected.")
        DecimalField("Amount (${order.string("currency_code") ?: "KES"})", amount, { amount = it }, error = error?.field("amount"))
        Field("Reason", reason, { reason = it }, multiline = true, error = error?.field("reason"))
        ToggleRow("Refund the shipping fee as well", refundShipping, { refundShipping = it })
        SalesFieldErrors(error)
    }
}

// ── Void — POST /orders/{id}/void {reason} ──────────────────────────────────

@Composable
internal fun VoidOrderSheet(order: Record, onDone: () -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var reason by remember { mutableStateOf("") }
    FormSheet("Void ${order.string("order_number") ?: "order"}", onDismiss, saveLabel = "Void order", canSave = reason.isNotBlank(), destructive = true, save = {
        val r = env.client.post("/v1/admin/orders/${order.id}/void", jsonOf("reason" to reason))
        env.toasts.show(r.json["message"].str ?: "Order voided")
        onDone()
    }) { error ->
        SalesSecondary("For test orders, duplicates or fraud: stock is returned and pending payments voided. Use Refund for a completed order.")
        Field("Reason (required)", reason, { reason = it }, multiline = true, error = error?.field("reason"))
        SalesFieldErrors(error)
    }
}

// ── Deposit — POST /orders/{id}/set-deposit {deposit_amount, balance_due_date} ─

@Composable
internal fun DepositSheet(order: Record, onDone: () -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var amount by remember { mutableStateOf(order["deposit_amount"].takeIf { !it.isNull }?.str ?: "") }
    var dueDate by remember { mutableStateOf(order.date("balance_due_date")?.atZone(HubDates.nairobi)?.toLocalDate()) }
    FormSheet("Deposit terms", onDismiss, saveLabel = "Save terms", canSave = Sales.amountJson(amount) != null, save = {
        val amt = Sales.amountJson(amount) ?: return@FormSheet
        val body = mutableMapOf<String, JsonElement>("deposit_amount" to amt)
        dueDate?.let { body["balance_due_date"] = JsonPrimitive(HubDates.dayString(it)) }
        val r = env.client.post("/v1/admin/orders/${order.id}/set-deposit", JsonObject(body))
        val p = r.json["proposal"]
        if (!p.isNull && p["status"].str != "applied") env.toasts.show(r.json["message"].str ?: "Credit past your band — waiting for finance to sign", StatusTone.INFO)
        else env.toasts.show("Deposit terms saved")
        onDone()
    }) { error ->
        DetailRow("Order total") { MoneyText(order.money("total_amount")) }
        SalesCaption("Credit past the maker's band waits for finance (a proposal); the terms stay until signed.")
        DecimalField("Minimum deposit (${order.string("currency_code") ?: "KES"})", amount, { amount = it }, error = error?.field("deposit_amount"))
        DateField("Balance due by", dueDate, { dueDate = it })
        SalesFieldErrors(error)
    }
}

// ── Shipping fee — PATCH /orders/{id}/shipping-fee {amount, note?, shipping_method_id?} ─

@Composable
internal fun ShippingFeeSheet(order: Record, onDone: () -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var methods by remember { mutableStateOf<List<Record>>(emptyList()) }
    var methodId by remember { mutableStateOf("") }
    var amount by remember { mutableStateOf("") }
    var note by remember { mutableStateOf("") }
    LaunchedEffect(Unit) { runCatching { methods = env.client.get("/v1/admin/orders/shipping-methods", mapOf("currency" to order.string("currency_code"))).json.salesRecords } }
    val chosen = methods.firstOrNull { it.id == methodId }
    val fee = chosen?.get("fee")
    val currency = order.string("currency_code") ?: "KES"
    val canSave = if (chosen != null) (fee != null && !fee.isNull) else Sales.amountJson(amount) != null

    FormSheet("Shipping fee", onDismiss, saveLabel = "Save fee", canSave = canSave, save = {
        val body = mutableMapOf<String, JsonElement>()
        if (chosen != null && fee != null && !fee.isNull) { body["amount"] = fee; body["shipping_method_id"] = JsonPrimitive(chosen.id) }
        else body["amount"] = Sales.amountJson(amount) ?: return@FormSheet
        if (note.isNotBlank()) body["note"] = JsonPrimitive(note)
        val r = env.client.patch("/v1/admin/orders/${order.id}/shipping-fee", JsonObject(body))
        env.toasts.show(r.json["message"].str ?: "Shipping fee saved")
        onDone()
    }) { error ->
        DetailRow("Current fee") { MoneyText(order.money("shipping_amount")) }
        val paid = order.string("payment_status")
        if (paid != null && paid != "pending" && !env.session.current.can("orders.reduce_shipping_fee")) SalesCaption("Money has been collected on this order; lowering the fee needs orders.reduce_shipping_fee.", Palette.warning)
        OptionPicker("Shipping method", methodId, listOf("" to "Type an amount") + methods.map { m ->
            m.id to "${m.string("name") ?: ""} — ${Money.from(m["fee"], m.string("fee_currency") ?: currency)?.formatted() ?: "no pricing rate"}"
        }, { methodId = it }, allowNone = true)
        if (chosen == null) DecimalField("Fee ($currency)", amount, { amount = it }, error = error?.field("amount"))
        else if (fee.isNull) SalesCaption("This method has no fee in the order's currency (no pricing rate).", Palette.warning)
        Field("Note", note, { note = it })
        SalesFieldErrors(error)
    }
}

// ── Country & currency — POST /orders/{id}/update-currency {country_code} ───

@Composable
internal fun CurrencySheet(order: Record, onDone: () -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var countries by remember { mutableStateOf<List<Record>>(emptyList()) }
    var code by remember { mutableStateOf(order.string("customer_country_code") ?: "") }
    LaunchedEffect(Unit) { runCatching { countries = env.client.get("/v1/admin/countries").json.salesRecords.filter { it["is_active"].bool != false } } }
    FormSheet("Country & currency", onDismiss, saveLabel = "Apply", canSave = code.length == 2, save = {
        val r = env.client.post("/v1/admin/orders/${order.id}/update-currency", jsonOf("country_code" to code))
        env.toasts.show(if (r.json["changed"].bool == true) "Currency set to ${r.json["currency_code"].str ?: ""}" else "Currency unchanged (${r.json["currency_code"].str ?: ""})")
        onDone()
    }) { error ->
        DetailRow("Current", "${order.string("customer_country_code") ?: "—"} · ${order.string("currency_code") ?: ""}")
        SalesCaption("Only while no payment has started. The currency follows the country's default when it is sellable (active with a reporting rate); prices are re-quoted by the server.")
        OptionPicker("Country", code, countries.map { (it.string("code") ?: "") to "${it.string("name") ?: ""} (${it.string("default_currency_code") ?: "—"})" }, { code = it }, error = error?.field("country_code"))
        SalesFieldErrors(error)
    }
}

// ── Attach customer — POST /orders/{id}/attach-customer {customer_id} | {new_customer} ─

@Composable
internal fun AttachCustomerSheet(order: Record, onDone: () -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var existing by remember { mutableStateOf(true) }
    var search by remember { mutableStateOf("") }
    var results by remember { mutableStateOf<List<Record>>(emptyList()) }
    var picked by remember { mutableStateOf<Record?>(null) }
    var firstName by remember { mutableStateOf("") }
    var lastName by remember { mutableStateOf("") }
    var phone by remember { mutableStateOf("") }
    var email by remember { mutableStateOf("") }
    var company by remember { mutableStateOf("") }
    val canSubmit = if (existing) picked != null else firstName.isNotBlank() && phone.isNotBlank()

    fun find() {
        val term = search.trim()
        if (term.length < 3) { results = emptyList(); return }
        scope.launch { try { results = env.client.get("/v1/admin/customers", mapOf("search" to term, "per_page" to "8")).json.salesRecords } catch (e: Exception) { env.toasts.error(e) } }
    }

    FormSheet("Add customer details", onDismiss, saveLabel = "Attach", canSave = canSubmit, save = {
        val body: JsonElement = if (existing) jsonOf("customer_id" to (picked?.id ?: return@FormSheet)) else {
            val nc = mutableMapOf<String, Any?>("first_name" to firstName, "phone" to phone)
            if (lastName.isNotEmpty()) nc["last_name"] = lastName
            if (email.isNotEmpty()) nc["email"] = email
            if (company.isNotBlank()) nc["company"] = company
            jsonOf("new_customer" to nc)
        }
        env.client.post("/v1/admin/orders/${order.id}/attach-customer", body)
        env.toasts.show("Customer attached")
        onDone()
    }) { error ->
        SalesCaption("Captured once, then frozen — the server keeps the first details a sale gets.")
        SegmentedTabs(listOf(true to "Existing customer", false to "New customer"), existing) { existing = it }
        if (existing) {
            ModuleSearchField(search, { search = it }, "Name, phone or email (3+ characters)", onSubmit = ::find)
            results.forEach { c ->
                Row(Modifier.fillMaxWidth().clickable { picked = c }.padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(Sales.name(c.string("first_name"), c.string("last_name")) ?: c.string("customer_number") ?: "", style = MaterialTheme.typography.bodyMedium)
                        SalesCaption(listOfNotNull(c.string("customer_number"), c.string("phone"), c.string("email")).joinToString(" · "))
                    }
                    if (picked?.id == c.id) Icon(Icons.Default.CheckCircle, contentDescription = "Selected", tint = Palette.brand, modifier = Modifier.size(18.dp))
                }
            }
        } else {
            Field("First name", firstName, { firstName = it }, error = error?.field("new_customer.first_name"))
            Field("Last name", lastName, { lastName = it })
            Field("Phone (required)", phone, { phone = it }, error = error?.field("new_customer.phone"))
            Field("Email", email, { email = it }, error = error?.field("new_customer.email"))
            Field("Company", company, { company = it })
        }
        SalesFieldErrors(error)
    }
}

// ── Note — POST /orders/{id}/notes {note, is_internal} ──────────────────────

@Composable
internal fun AddNoteSheet(order: Record, onDone: () -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var note by remember { mutableStateOf("") }
    var internalNote by remember { mutableStateOf(true) }
    FormSheet("Add note", onDismiss, saveLabel = "Add", canSave = note.isNotBlank(), save = {
        env.client.post("/v1/admin/orders/${order.id}/notes", jsonOf("note" to note, "is_internal" to internalNote))
        env.toasts.show("Note added")
        onDone()
    }) { error ->
        Field("Note", note, { note = it }, multiline = true, error = error?.field("note"))
        ToggleRow("Internal (staff only)", internalNote, { internalNote = it })
        SalesFieldErrors(error)
    }
}

// ── Send to production — POST /orders/{id}/items/{i}/production ────────────

@Composable
internal fun RaiseProductionSheet(order: Record, item: Record, onDone: () -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var color by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    var dueDate by remember { mutableStateOf<LocalDate?>(null) }
    var measurements by remember { mutableStateOf(listOf(KeyValueRow())) }
    FormSheet("Send to production", onDismiss, saveLabel = "Raise production order", save = {
        val body = mutableMapOf<String, Any?>()
        if (color.isNotBlank()) body["color"] = color
        if (notes.isNotBlank()) body["notes"] = notes
        dueDate?.let { body["due_date"] = HubDates.dayString(it) }
        val m = measurements.filter { it.key.isNotBlank() }
        if (m.isNotEmpty()) body["measurements"] = m.associate { it.key.trim() to it.value }
        val r = env.client.post("/v1/admin/orders/${order.id}/items/${item.id}/production", toJson(body))
        env.toasts.show("Production order ${r.json["production_order_number"].str ?: ""} raised")
        onDone()
    }) { error ->
        DetailRow("Line", "${item.string("product_name") ?: ""} × ${item.string("quantity") ?: ""}")
        Field("Colour", color, { color = it })
        DateField("Due date", dueDate, { dueDate = it })
        KeyValueEditor("Measurements", measurements) { measurements = it }
        Field("Notes for the workshop", notes, { notes = it }, multiline = true)
        SalesFieldErrors(error)
    }
}
