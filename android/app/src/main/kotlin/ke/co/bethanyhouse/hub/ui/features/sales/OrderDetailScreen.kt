package ke.co.bethanyhouse.hub.ui.features.sales

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowForward
import androidx.compose.material3.Button
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
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
import androidx.compose.ui.platform.LocalClipboardManager
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.obj
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.DetailRow
import ke.co.bethanyhouse.hub.ui.components.DocumentButton
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonNull

// ── Order detail (GET /admin/orders/{id} → {order}) ─────────────────────────

private sealed class OrderSheet {
    data object Status : OrderSheet()
    data object Payment : OrderSheet()
    data object Refund : OrderSheet()
    data object Void : OrderSheet()
    data object Deposit : OrderSheet()
    data object ShippingFee : OrderSheet()
    data object Currency : OrderSheet()
    data object AttachCustomer : OrderSheet()
    data object Note : OrderSheet()
    data object CreateShipment : OrderSheet()
    data class Production(val item: Record) : OrderSheet()
    data class VerifyMpesa(val payment: Record) : OrderSheet()
    data class VerifyPaystack(val payment: Record) : OrderSheet()
    data class Reverse(val payment: Record?) : OrderSheet()
    data class Revise(val payment: Record) : OrderSheet()
}

/**
 * One order: header, money, items, customer, payments, notes, comments,
 * history, production, shipments, revisions, audit log. Every action is a
 * route in OrderController (or Payment/Shipment/Comment controllers) and is
 * offered only when the route's permission is held and the order's state
 * allows it — the server decides again.
 */
@Composable
fun OrderDetailScreen(orderId: Int) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val resource = remember(orderId) { Resource(scope) { Record(env.client.get("/v1/admin/orders/$orderId").json["order"] ?: JsonNull) } }
    LaunchedEffect(resource) { resource.load() }
    var sheet by remember { mutableStateOf<OrderSheet?>(null) }
    var showAudit by remember { mutableStateOf(false) }
    var showRevisions by remember { mutableStateOf(false) }
    var paymentLink by remember { mutableStateOf<String?>(null) }
    val reload = { resource.load() }

    Page(resource.value?.string("order_number") ?: "Order", refresh = { resource.reload() }) {
        LoadableView(resource.state, retry = reload) { order ->
            OrderHeader(order)
            OrderActions(order, orderId, showAudit, showRevisions, onToggleAudit = { showAudit = !showAudit }, onToggleRevisions = { showRevisions = !showRevisions }, onSheet = { sheet = it }, onPaymentLink = { paymentLink = it })
            paymentLink?.let { PaymentLinkBanner(it) }
            OrderItemsSection(order, reload = reload, onProduction = { sheet = OrderSheet.Production(it) })
            OrderCustomer(order, orderId)
            SectionCard("Totals") {
                SalesMoneyBreakdown(order)
                order.money("deposit_amount")?.let { dep ->
                    DetailRow("Minimum deposit") { MoneyText(dep) }
                    DetailRow("Balance due by", order.string("balance_due_date"))
                }
                if (order["is_international"].bool == true) StatusBadge("international", StatusTone.INFO)
                order["invoice_document"]["number"].str?.let { DetailRow("Invoice", it) }
                order.string("quotation_number")?.let { DetailRow("From quotation", it) }
            }
            OrderPayments(order, onSheet = { sheet = it }, reload = reload)
            SalesPendingProposalsSection("order", orderId)
            if (env.session.current.can("shipment.view")) OrderShipmentsSection(order, onCreate = { sheet = OrderSheet.CreateShipment })
            OrderProduction(order)
            OrderNotes(order, onAdd = { sheet = OrderSheet.Note })
            SalesCommentsThread("Order", orderId)
            OrderHistory(order)
            if (showRevisions) SalesRevisionsSection(orderId)
            if (showAudit) SalesAuditLogSection("/v1/admin/orders/$orderId/audit-log")
        }
    }

    val order = resource.value
    val which = sheet
    if (order != null && which != null) {
        val close = { sheet = null }
        val done = { reload() }
        when (which) {
            OrderSheet.Status -> OrderStatusSheet(order, done, close)
            OrderSheet.Payment -> RecordPaymentSheet(order, done, close)
            OrderSheet.Refund -> RefundSheet(order, done, close)
            OrderSheet.Void -> VoidOrderSheet(order, done, close)
            OrderSheet.Deposit -> DepositSheet(order, done, close)
            OrderSheet.ShippingFee -> ShippingFeeSheet(order, done, close)
            OrderSheet.Currency -> CurrencySheet(order, done, close)
            OrderSheet.AttachCustomer -> AttachCustomerSheet(order, done, close)
            OrderSheet.Note -> AddNoteSheet(order, done, close)
            OrderSheet.CreateShipment -> CreateShipmentSheet(order, done, close)
            is OrderSheet.Production -> RaiseProductionSheet(order, which.item, done, close)
            is OrderSheet.VerifyMpesa -> VerifyMpesaSheet(order, which.payment, done, close)
            is OrderSheet.VerifyPaystack -> VerifyPaystackSheet(order, which.payment, done, close)
            is OrderSheet.Reverse -> ReversePaymentSheet(order, which.payment, done, close)
            is OrderSheet.Revise -> RevisePaymentSheet(order, which.payment, done, close)
        }
    }
}

@Composable
private fun OrderHeader(o: Record) {
    SectionCard {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                val name = o.salesCustomerName
                val number = o.string("order_number") ?: ""
                if (name != null) {
                    Text(name, style = MaterialTheme.typography.headlineSmall)
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                        SalesMono(number)
                        SalesCopyButton("", number, "Order number copied", iconOnly = true)
                    }
                } else Text(number, style = MaterialTheme.typography.headlineSmall)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    StatusBadge(o.status)
                    StatusBadge(o.string("payment_status"))
                    StatusBadge((o.string("sales_bucket") ?: o.string("order_type") ?: "").humanized, StatusTone.NEUTRAL)
                    o.string("source_channel")?.let { StatusBadge(it, StatusTone.INFO) }
                    if (!o["dispatched_at"].isNull) StatusBadge("dispatched", StatusTone.SUCCESS)
                }
                FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.m), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    o.string("outlet_name")?.let { SalesSecondary(it) }
                    o.string("cashier_name")?.let { SalesSecondary("Served by $it") }
                    DateText(o.date("created_at"))
                }
            }
            Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(4.dp)) {
                MoneyText(o.money("total_amount"), emphasis = true)
                SalesCaption(o.string("currency_code") ?: "", MaterialTheme.hub.textTertiary)
            }
        }
        Sales.chatUrl(o.string("customer_phone"), o.string("order_number"))?.let { SalesLinkButton("Open chat in Neema", it) }
    }
}

@Composable
private fun OrderActions(
    o: Record, orderId: Int, showAudit: Boolean, showRevisions: Boolean,
    onToggleAudit: () -> Unit, onToggleRevisions: () -> Unit, onSheet: (OrderSheet) -> Unit, onPaymentLink: (String) -> Unit,
) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val clipboard = LocalClipboardManager.current
    val s = env.session.current
    val status = o.status ?: ""
    val payStatus = o.string("payment_status") ?: ""
    val dead = Sales.isDead(status)
    val captured = !(o.salesCustomerName ?: "").isEmpty() || !o["user_id"].isNull || !(o.string("customer_phone") ?: "").isEmpty()
    var more by remember { mutableStateOf(false) }

    SectionCard {
        if (dead) SalesSecondary("Payments are closed — this order is $status. A payment can't be taken or recorded against it.", Palette.danger)
        if (o["payments"].records.any { it["requires_approval"].bool == true && it["approval_status"].str == "pending_review" }) {
            SalesSecondary("A payment is awaiting approval; the order cannot progress until it is signed.", Palette.amber)
        }
        FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
            if (s.can("orders.edit") && Sales.nextStatuses(status).isNotEmpty()) Button(onClick = { onSheet(OrderSheet.Status) }) { Text("Update status") }
            if (s.can("payments.record") && !dead && payStatus != "paid") Button(onClick = { onSheet(OrderSheet.Payment) }) { Text("Record payment") }
            if (!dead && payStatus != "paid") OutlinedButton(onClick = {
                scope.launch {
                    try {
                        val r = env.client.get("/v1/admin/orders/$orderId/payment-link")
                        val url = r.json["payment_url"].str ?: r.json["url"].str
                        if (url != null) { onPaymentLink(url); clipboard.setText(AnnotatedString(url)); env.toasts.show("Payment link copied") }
                    } catch (e: Exception) { env.toasts.error(e) }
                }
            }) { Text("Payment link") }
            if (s.can("orders.set_deposit") && !dead && payStatus != "paid") OutlinedButton(onClick = { onSheet(OrderSheet.Deposit) }) { Text("Set deposit") }
            if (s.can("orders.set_shipping_fee") && !dead) OutlinedButton(onClick = { onSheet(OrderSheet.ShippingFee) }) { Text("Shipping fee") }
            if (s.can("orders.edit") && !dead && payStatus == "pending") OutlinedButton(onClick = { onSheet(OrderSheet.Currency) }) { Text("Country & currency") }
            if (s.permissions.canAny(listOf("orders.edit", "orders.create")) && !captured) OutlinedButton(onClick = { onSheet(OrderSheet.AttachCustomer) }) { Text("Add customer details") }
            DocumentButton("Order PDF", "/v1/admin/pdf/orders/$orderId", suggestedName = "order-$orderId.pdf")
            DocumentButton("Invoice PDF", "/v1/admin/pdf/orders/$orderId/invoice", suggestedName = "invoice-$orderId.pdf")
            if (payStatus in listOf("paid", "partial", "deposit", "partially_refunded", "refunded")) DocumentButton("Receipt PDF", "/v1/admin/pdf/orders/$orderId/receipt", suggestedName = "receipt-$orderId.pdf")
            Box {
                OutlinedButton(onClick = { more = true }) { Text("More…") }
                DropdownMenu(expanded = more, onDismissRequest = { more = false }) {
                    if (s.can("orders.edit") && o.string("order_type") != "pos") DropdownMenuItem(text = { Text("Resend confirmation") }, onClick = {
                        more = false
                        scope.launch { try { val r = env.client.post("/v1/admin/orders/$orderId/resend-confirmation"); env.toasts.show(r.json["message"].str ?: "Confirmation sent") } catch (e: Exception) { env.toasts.error(e) } }
                    })
                    if (s.can("orders.edit")) DropdownMenuItem(text = { Text("Add note") }, onClick = { more = false; onSheet(OrderSheet.Note) })
                    DropdownMenuItem(text = { Text(if (showRevisions) "Hide revisions" else "Document revisions") }, onClick = { more = false; onToggleRevisions() })
                    DropdownMenuItem(text = { Text(if (showAudit) "Hide audit log" else "Audit log") }, onClick = { more = false; onToggleAudit() })
                    if (s.can("orders.refund") && status in listOf("delivered", "completed")) {
                        HorizontalDivider()
                        DropdownMenuItem(text = { Text("Refund…") }, onClick = { more = false; onSheet(OrderSheet.Refund) })
                    }
                    if (s.can("orders.cancel") && status in listOf("pending", "processing", "confirmed")) {
                        DropdownMenuItem(text = { Text("Void order…", color = Palette.danger) }, onClick = { more = false; onSheet(OrderSheet.Void) })
                    }
                }
            }
        }
    }
}

@Composable
private fun PaymentLinkBanner(link: String) {
    Row(Modifier.fillMaxWidth().background(Palette.infoSoft, MaterialTheme.shapes.small).padding(Space.m), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            SalesCaption("Customer payment link", Palette.info)
            SalesMono(link)
        }
        SalesCopyButton("", link, "Payment link copied", iconOnly = true)
        SalesLinkButton("Open", link)
    }
}

@Composable
private fun OrderCustomer(o: Record, orderId: Int) {
    val env = LocalEnv.current
    val ctx = SalesRevealContext("order", orderId)
    val customerId = o.int("customer_id")
    SectionCard("Customer") {
        val name = o.salesCustomerName
        if (name != null) DetailRow("Name", name) else SalesSecondary("Walk-in — no customer attached")
        o.string("customer_number")?.let { DetailRow("Customer no.", it) }
        SalesContactLine("Phone", o.string("customer_phone"), "phone", customerId, ctx)
        SalesContactLine("Email", o.string("customer_email"), "email", customerId, ctx)
        val a = o["shipping_address"]
        if (a.obj != null) {
            val line = listOf("name", "address_line_1", "address_line_2", "city", "state", "postal_code", "country").mapNotNull { a[it].str }.filter { it.isNotEmpty() }.joinToString(", ")
            SalesContactLine("Ships to", line, "address", customerId, ctx)
        }
        o.string("delivery_type")?.let { DetailRow("Delivery", it.humanized) }
        o.string("tracking_number")?.let { DetailRow("Tracking no.", it) }
        if (customerId != null && env.session.current.can("customers.view")) TextButton(onClick = { env.navigator.push(Route.Customer(customerId)) }) { Text("Open customer") }
    }
}

@Composable
private fun OrderPayments(o: Record, onSheet: (OrderSheet) -> Unit, reload: () -> Unit) {
    val env = LocalEnv.current
    val s = env.session.current
    val list = o["payments"].toRecords()
    val canReverse = s.permissions.canAny(listOf("payments.void", "payments.request_void"))
    SectionCard("Payments") {
        if (list.isEmpty()) SalesSecondary("No payment recorded yet.")
        list.forEach { p -> PaymentRow(p, o, canReverse, onSheet, reload) }
        if (canReverse && list.any { it.status == "paid" }) TextButton(onClick = { onSheet(OrderSheet.Reverse(null)) }) { Text("Reverse every settled payment…", color = Palette.danger) }
    }
}

/** One payment, with its own currency and (when the payload gives it) the reporting rate used for KES. */
@Composable
private fun PaymentRow(p: Record, o: Record, canReverse: Boolean, onSheet: (OrderSheet) -> Unit, reload: () -> Unit) {
    val env = LocalEnv.current
    val s = env.session.current
    val approved = p["requires_approval"].bool != true || p.string("approval_status") == "approved"
    val method = p.string("payment_method") ?: ""
    val verification = p["provider_response"]["mpesa_verification"]
    val reference = p.string("provider_reference")?.let { " · $it" } ?: ""
    val currency = p.string("currency_code") ?: o.string("currency_code")
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    SalesMono(p.string("payment_number") ?: "#${p.id}")
                    StatusBadge(if (p["requires_approval"].bool == true && !approved) "pending approval" else p.status)
                    if (p["requires_approval"].bool == true) p.string("approval_status")?.let { StatusBadge(it) }
                    if (!p["received_after_cancel_at"].isNull) StatusBadge("paid after cancel", StatusTone.WARNING)
                }
                SalesSecondary(method.humanized.uppercase() + reference)
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                    DateText(p.date("paid_at") ?: p.date("created_at"))
                    if (p["tax_inclusive"].bool == false) p.money("tax_amount_collected", currency)?.let { SalesCaption("tax collected ${it.formatted()}", MaterialTheme.hub.textTertiary) }
                }
                if (!verification.isNull) {
                    val state = verification["state"].str ?: ""
                    if (state == "pending" || state == "querying") SalesCaption("Waiting for Safaricom… (${verification["receipt"].str ?: ""})", Palette.amber)
                    else if (state in listOf("refused", "unmatched", "timed_out")) verification["message"].str?.let { SalesCaption(it, Palette.danger) }
                }
            }
            Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                MoneyText(p.money("amount", currency))
                p.money("refund_amount", currency)?.takeIf { !it.isZero }?.let { SalesCaption("−${it.formatted()} refunded", Palette.danger) }
                p["reporting_rate_to_kes"].str?.let { rate -> if (currency != "KES") SalesCaption("reporting rate $rate KES/$currency", MaterialTheme.hub.textTertiary) }
            }
        }
        FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            if (s.can("payments.upload_proof") && p["requires_approval"].bool == true && p.status != "refunded" && p.string("approval_status") != "approved") ProofUploadButton(p.id, reload)
            if (s.can("payments.record") && p.status == "pending" && method == "mpesa") TextButton(onClick = { onSheet(OrderSheet.VerifyMpesa(p)) }) { Text("Verify with Daraja") }
            if (s.can("payments.record") && p.status == "pending" && method in listOf("card_paystack", "paystack")) TextButton(onClick = { onSheet(OrderSheet.VerifyPaystack(p)) }) { Text("Verify with Paystack") }
            if (canReverse && p.status == "paid" && approved) TextButton(onClick = { onSheet(OrderSheet.Reverse(p)) }) { Text("Reverse…", color = Palette.danger) }
            if (s.permissions.isSuperAdmin && (p.status ?: "") !in listOf("voided", "failed", "cancelled")) TextButton(onClick = { onSheet(OrderSheet.Revise(p)) }) { Text("Correct details…") }
        }
        SalesDivider()
    }
}

@Composable
private fun OrderNotes(o: Record, onAdd: () -> Unit) {
    val env = LocalEnv.current
    val list = o["order_notes"].toRecords()
    SectionCard("Notes") {
        if (list.isEmpty()) SalesSecondary("No notes.")
        list.forEach { n ->
            val internal = n["is_internal"].bool == true
            Column(Modifier.padding(vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    SalesCaption(n.string("user_name") ?: "Staff")
                    StatusBadge(if (internal) "internal" else "customer", if (internal) StatusTone.NEUTRAL else StatusTone.INFO)
                    Spacer(Modifier.weight(1f))
                    DateText(n.date("created_at"), DateStyle.RELATIVE)
                }
                Text(n.string("note") ?: "", style = MaterialTheme.typography.bodyMedium)
            }
        }
        if (env.session.current.can("orders.edit")) TextButton(onClick = onAdd) { Text("Add note") }
    }
}

@Composable
private fun OrderHistory(o: Record) {
    val list = o["status_history"].toRecords()
    if (list.isEmpty()) return
    SectionCard("Status history") {
        list.forEach { h ->
            Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    StatusBadge(h.string("old_status"))
                    Icon(Icons.Default.ArrowForward, contentDescription = null, tint = MaterialTheme.hub.textTertiary, modifier = Modifier.size(12.dp))
                    StatusBadge(h.string("new_status"))
                    Spacer(Modifier.weight(1f))
                    DateText(h.date("created_at"))
                }
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    SalesCaption(h.string("changed_by_name") ?: "System")
                    h.string("notes")?.takeIf { it.isNotEmpty() }?.let { SalesCaption(it, MaterialTheme.hub.textTertiary) }
                }
            }
        }
    }
}

@Composable
private fun OrderProduction(o: Record) {
    val env = LocalEnv.current
    val list = o["production_orders"].toRecords()
    if (list.isEmpty()) return
    SectionCard("Production") {
        list.forEach { po ->
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text("${po.string("order_number") ?: ""} · ${po.string("product_name") ?: ""} × ${po.string("quantity") ?: ""}", style = MaterialTheme.typography.bodyMedium)
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        StatusBadge(po.status)
                        po.string("priority")?.let { StatusBadge(it, StatusTone.NEUTRAL) }
                        po.string("due_date")?.let { SalesCaption("due $it") }
                    }
                }
                val id = po.intId
                if (id != null && env.session.current.can("production.view")) TextButton(onClick = { env.navigator.push(Route.ProductionOrder(id)) }) { Text("Open") }
            }
        }
        if (list.any { it.status == "draft" }) SalesCaption("Draft production orders must be confirmed in the Production module before work begins.", Palette.warning)
    }
}
