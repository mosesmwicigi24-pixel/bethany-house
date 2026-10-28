package ke.co.bethanyhouse.hub.ui.features.procurement

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toJson
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.DayField
import ke.co.bethanyhouse.hub.ui.components.DecimalField
import ke.co.bethanyhouse.hub.ui.components.DetailRow
import ke.co.bethanyhouse.hub.ui.components.DocumentButton
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.Lookup
import ke.co.bethanyhouse.hub.ui.components.LookupPicker
import ke.co.bethanyhouse.hub.ui.components.ModuleAuditLog
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.OptionPickerValues
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.RevisionsSection
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.StatTile
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.TextPromptSheet
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.TileModifier
import ke.co.bethanyhouse.hub.ui.components.personName
import ke.co.bethanyhouse.hub.ui.components.quantityText
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonPrimitive
import java.math.BigDecimal
import java.util.UUID

/**
 * One purchase order (GET /v1/admin/purchase-orders/{id} →
 * {purchase_order, receiving_history}). Transitions follow
 * PurchaseOrderController: draft → submit → pending_approval →
 * approve/reject → approved → (status ordered) → receive → partially /
 * received; cancel while nothing is received; supplier invoice at any
 * status but cancelled; payments on the payables ledger; owner revisions.
 */
private enum class PoSheet { APPROVE, REJECT, CANCEL, RECEIVE, RETURN, INVOICE, PAYMENT, REVISE }

@Composable
fun PurchaseOrderDetailScreen(poId: Int) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val s = env.session.current
    val res = remember(poId) { Resource(scope) { env.client.get("/v1/admin/purchase-orders/$poId").json ?: JsonNull } }
    var sheet by remember { mutableStateOf<PoSheet?>(null) }
    var showAudit by remember { mutableStateOf(false) }
    var showRevisions by remember { mutableStateOf(false) }
    var paymentsVersion by remember { mutableStateOf(0) }
    LaunchedEffect(res) { res.load() }

    fun reload() { res.load(); paymentsVersion++ }

    suspend fun post(action: String, body: JsonElement) {
        val r = env.client.post("/v1/admin/purchase-orders/$poId/$action", body)
        env.toasts.show(r.json["message"].str ?: "Done")
        reload()
    }
    suspend fun postQuietly(action: String, body: JsonElement) { try { post(action, body) } catch (e: Exception) { env.toasts.error(e) } }
    suspend fun patchStatus(status: String) {
        try {
            val r = env.client.patch("/v1/admin/purchase-orders/$poId/status", jsonOf("status" to status))
            env.toasts.show(r.json["message"].str ?: "Status updated"); reload()
        } catch (e: Exception) { env.toasts.error(e) }
    }
    suspend fun delete() {
        try { env.client.delete("/v1/admin/purchase-orders/$poId"); env.toasts.show("Purchase order deleted"); env.navigator.go(Route.PurchaseOrders) } catch (e: Exception) { env.toasts.error(e) }
    }

    Page("Purchase order", refresh = { res.reload(); paymentsVersion++ }) {
        LoadableView(res.state, retry = { res.load() }) { j ->
            val po = PurchaseOrderRecord(Record(j["purchase_order"] ?: JsonNull))
            val status = po.status ?: ""
            SectionCard {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                    Text(po.number, style = MaterialTheme.typography.titleLarge)
                    StatusBadge(po.status)
                    if (po.revision > 0) StatusBadge("rev ${po.revision}", StatusTone.ACCENT)
                    Spacer(Modifier.weight(1f))
                    MoneyText(po.total, emphasis = true)
                }
                Text(po.supplierName, style = MaterialTheme.typography.bodyMedium)
                DetailRow("Currency", po.currency)
                DetailRow("Expected") { DateText(po.expected, DateStyle.DATE) }
                DetailRow("Subtotal") { MoneyText(po.subtotal) }
                DetailRow("Shipping") { MoneyText(po.shipping) }
                DetailRow("Tax") { MoneyText(po.tax) }
                DetailRow("Payment terms", po.r.string("payment_terms"))
                DetailRow("Raised by", personName(po.r["created_by"]))
                DetailRow("Approved by", personName(po.r["approved_by"]))
                po.r.string("supplier_invoice_number")?.let { inv ->
                    DetailRow("Supplier invoice", "$inv · ${po.r.string("supplier_invoice_date") ?: ""} · ${po.r.money("supplier_invoice_amount", po.currency)?.formatted() ?: ""}")
                }
                po.r.string("notes")?.takeIf { it.isNotEmpty() }?.let { DetailRow("Notes", it) }
            }

            FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                if (status == "draft" && s.can("procurement.create")) ConfirmButton("Submit for approval", "Goes to the approval bands by value; you cannot approve your own order.", action = { postQuietly("submit", jsonOf()) }) { Text("Submit") }
                if (status == "pending_approval" && s.can("procurement.approve")) {
                    Button(onClick = { sheet = PoSheet.APPROVE }) { Text("Approve") }
                    OutlinedButton(onClick = { sheet = PoSheet.REJECT }) { Text("Reject", color = Palette.danger) }
                }
                if (status == "approved" && s.can("procurement.approve")) ConfirmButton("Mark as ordered", "Records that the order has been placed with the supplier.", action = { patchStatus("ordered") }) { Text("Mark ordered") }
                if (status in POStatus.receivable && s.can("procurement.receive")) Button(onClick = { sheet = PoSheet.RECEIVE }, colors = ButtonDefaults.buttonColors(containerColor = Palette.success)) { Text("Receive goods") }
                if (status in POStatus.returnable && s.can("procurement.receive")) OutlinedButton(onClick = { sheet = PoSheet.RETURN }) { Text("Return goods") }
                if (status != "cancelled" && s.can("procurement.create")) OutlinedButton(onClick = { sheet = PoSheet.INVOICE }) { Text("Supplier invoice") }
                if (s.can("procurement.pay") && status in listOf("approved", "ordered", "partially_received", "received")) OutlinedButton(onClick = { sheet = PoSheet.PAYMENT }) { Text("Record payment") }
                if (status != "cancelled" && s.me?.isSuperAdmin == true) OutlinedButton(onClick = { sheet = PoSheet.REVISE }) { Text("Revise (owner)") }
                if (status !in listOf("received", "partially_received", "cancelled") && s.can("procurement.create")) OutlinedButton(onClick = { sheet = PoSheet.CANCEL }) { Text("Cancel", color = Palette.danger) }
                if (status in listOf("draft", "cancelled") && s.can("procurement.create")) ConfirmButton("Delete purchase order", "Removes the draft and its lines permanently.", destructive = true, action = { delete() }) { Text("Delete") }
                DocumentButton("PDF", "/v1/admin/pdf/purchase-orders/$poId", suggestedName = "${po.number}.pdf")
            }

            SectionCard("Lines") {
                po.items.forEach { i ->
                    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top) {
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Text(poLineTitle(i), style = MaterialTheme.typography.bodyMedium)
                            Text("${quantityText(i["quantity"])} @ ${Money.from(i["unit_price"], po.currency)?.formatted() ?: "—"} · received ${quantityText(i["quantity_received"])}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        }
                        MoneyText(Money.from(i["total_price"] ?: i["subtotal"], po.currency))
                    }
                    HorizontalDivider(color = MaterialTheme.hub.line)
                }
            }

            SupplierPaymentsSection(po, paymentsVersion)

            SectionCard("Goods received") {
                val grns = j["receiving_history"].records
                if (grns.isEmpty()) Text("Nothing received yet.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textTertiary)
                grns.forEach { g ->
                    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Text(g["grn_number"].str ?: "GRN", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                            Text("${g["received_date"].str ?: ""} · ${personName(g["received_by"]) ?: ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        }
                        g["id"].int?.let { id -> TextButton(onClick = { env.navigator.push(Route.Grn(id)) }) { Text("Open") } }
                    }
                }
            }

            if (showRevisions) RevisionsSection("/v1/admin/purchase-orders/$poId/revisions") else TextButton(onClick = { showRevisions = true }) { Text("Show revisions") }
            if (showAudit) ModuleAuditLog("/v1/admin/purchase-orders/$poId/audit-log") else TextButton(onClick = { showAudit = true }) { Text("Show audit log") }

            val dismiss = { sheet = null }
            when (sheet) {
                PoSheet.APPROVE -> TextPromptSheet("Approve ${po.number}", dismiss, placeholder = "Notes (optional)", required = false, confirmLabel = "Sign approval") { n -> post("approve", jsonOf("notes" to n.ifEmpty { null })) }
                PoSheet.REJECT -> TextPromptSheet("Reject ${po.number}", dismiss, placeholder = "Reason (required)", confirmLabel = "Reject", destructive = true) { n -> post("reject", jsonOf("reason" to n)) }
                PoSheet.CANCEL -> TextPromptSheet("Cancel ${po.number}", dismiss, placeholder = "Reason (required)", confirmLabel = "Cancel order", destructive = true) { n -> post("cancel", jsonOf("reason" to n)) }
                PoSheet.RECEIVE -> ReceiveGoodsSheet(po, dismiss) { reload() }
                PoSheet.RETURN -> PurchaseReturnSheet(po, dismiss) { reload() }
                PoSheet.INVOICE -> SupplierInvoiceSheet(po, dismiss) { reload() }
                PoSheet.PAYMENT -> RecordSupplierPaymentSheet(po, dismiss) { reload() }
                PoSheet.REVISE -> RevisePurchaseOrderSheet(po, dismiss) { reload() }
                null -> {}
            }
        }
    }
}

// ── Receive (POST …/receive {items: [{po_item_id, quantity_received, quality_status, notes}], location_type, outlet_id?, notes?}) ──

@Composable
internal fun ReceiveGoodsSheet(po: PurchaseOrderRecord, onDismiss: () -> Unit, onDone: () -> Unit) {
    val env = LocalEnv.current
    val outlets = remember { Lookup.outlets(env.client) }
    var locationType by remember { mutableStateOf("warehouse") }
    var outletId by remember { mutableStateOf<Int?>(null) }
    val qty = remember { mutableStateMapOf<Int, String>() }
    val rejected = remember { mutableStateListOf<Int>() }
    var notes by remember { mutableStateOf("") }
    val anyLine = qty.values.any { (procDecimal(it) ?: BigDecimal.ZERO) > BigDecimal.ZERO }

    FormSheet("Receive goods — ${po.number}", onDismiss, saveLabel = "Confirm receipt", canSave = anyLine && (locationType == "warehouse" || outletId != null), save = {
        val items = qty.mapNotNull { (id, q) ->
            val v = J.decimal(q) ?: return@mapNotNull null
            if ((procDecimal(q) ?: BigDecimal.ZERO) <= BigDecimal.ZERO) return@mapNotNull null
            J.obj("po_item_id" to JsonPrimitive(id), "quantity_received" to v, "quality_status" to JsonPrimitive(if (id in rejected) "rejected" else "passed"))
        }
        val body = J.obj("items" to toJson(items), "location_type" to JsonPrimitive(locationType), "notes" to J.s(notes), "outlet_id" to (if (locationType == "outlet") JsonPrimitive(outletId ?: 0) else null))
        val r = env.client.post("/v1/admin/purchase-orders/${po.id}/receive", body)
        val costNotes = r.json["cost_notes"].records.mapNotNull { it.str }
        env.toasts.show("${r.json["receipt_number"].str ?: "GRN"} recorded" + (if (costNotes.isEmpty()) "" else " — ${costNotes.joinToString(" ")}"), if (costNotes.isEmpty()) StatusTone.SUCCESS else StatusTone.WARNING)
        onDone()
    }) { error ->
        SegmentedTabs(listOf("warehouse" to "Warehouse", "outlet" to "Outlet"), locationType) { locationType = it }
        if (locationType == "outlet") LookupPicker("Outlet", outlets, outletId, { outletId = it }, allowNone = false, error = error?.field("outlet_id"))
        po.items.forEach { i ->
            val id = i["id"].int ?: 0
            val outstanding = procQtyOf(i["quantity"]) - procQtyOf(i["quantity_received"])
            Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Space.xs)) {
                Text(poLineTitle(i), style = MaterialTheme.typography.bodyMedium)
                Text("ordered ${quantityText(i["quantity"])} · received ${quantityText(i["quantity_received"])} · outstanding ${procQty(outstanding)}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                    DecimalField("Qty", qty[id] ?: "", { qty[id] = it }, Modifier.weight(1f))
                    val isRejected = id in rejected
                    OutlinedButton(onClick = { if (isRejected) rejected.remove(id) else rejected.add(id) }) { Text(if (isRejected) "Rejected" else "Passed", color = if (isRejected) Palette.danger else Palette.success) }
                }
                TextButton(enabled = outstanding.signum() > 0, onClick = { qty[id] = procQty(outstanding) }) { Text("All outstanding") }
            }
            HorizontalDivider(color = MaterialTheme.hub.line)
        }
        Field("Notes", notes, { notes = it }, placeholder = "Delivery note, condition…", multiline = true)
        ProcCaption("Whoever approved the order cannot also sign for the goods. Product costs on the book update from the PO price at the reporting rate; a currency without one leaves cost as it was.")
    }
}

// ── Return (POST …/return {items: [{po_item_id, quantity, reason}], notes?}) ──

private val returnReasons = listOf("Defective / Damaged", "Wrong item received", "Quality not as specified", "Overstock / Excess inventory", "Incorrect quantity sent", "Price discrepancy", "Other")

@Composable
internal fun PurchaseReturnSheet(po: PurchaseOrderRecord, onDismiss: () -> Unit, onDone: () -> Unit) {
    val env = LocalEnv.current
    val qty = remember { mutableStateMapOf<Int, String>() }
    val reason = remember { mutableStateMapOf<Int, String>() }
    var notes by remember { mutableStateOf("") }
    val chosen = qty.entries.filter { (procDecimal(it.value) ?: BigDecimal.ZERO) > BigDecimal.ZERO }.map { it.key }

    FormSheet("Return goods — ${po.number}", onDismiss, saveLabel = "Raise return", canSave = chosen.isNotEmpty() && chosen.all { !(reason[it] ?: "").isEmpty() }, save = {
        val items = chosen.map { id -> J.obj("po_item_id" to JsonPrimitive(id), "quantity" to (J.decimal(qty[id] ?: "") ?: JsonPrimitive(0)), "reason" to JsonPrimitive(reason[id] ?: "")) }
        val r = env.client.post("/v1/admin/purchase-orders/${po.id}/return", J.obj("items" to toJson(items), "notes" to J.s(notes)))
        env.toasts.show("Purchase return raised — ${r.json["return_number"].str ?: ""}")
        onDone()
    }) { _ ->
        po.items.forEach { i ->
            val id = i["id"].int ?: 0
            Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Space.xs)) {
                Text(poLineTitle(i), style = MaterialTheme.typography.bodyMedium)
                Text("received ${quantityText(i["quantity_received"])}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                DecimalField("Qty to return", qty[id] ?: "", { qty[id] = it })
                if ((procDecimal(qty[id] ?: "") ?: BigDecimal.ZERO) > BigDecimal.ZERO) OptionPicker("Reason", reason[id] ?: "", returnReasons.map { it to it }, { reason[id] = it })
            }
            HorizontalDivider(color = MaterialTheme.hub.line)
        }
        Field("Notes", notes, { notes = it }, multiline = true)
        ProcCaption("Raising a return takes the goods out of stock now; a rejected return puts them back.")
    }
}

// ── Supplier invoice (PUT …/purchase-orders/{id} {supplier_invoice_number, supplier_invoice_date, supplier_invoice_amount}) ──

@Composable
private fun SupplierInvoiceSheet(po: PurchaseOrderRecord, onDismiss: () -> Unit, onDone: () -> Unit) {
    val env = LocalEnv.current
    var number by remember { mutableStateOf(po.r.string("supplier_invoice_number") ?: "") }
    var date by remember { mutableStateOf(po.r.string("supplier_invoice_date")?.take(10) ?: Day.today()) }
    var amount by remember { mutableStateOf(po.r["supplier_invoice_amount"].str ?: "") }

    FormSheet("Supplier invoice — ${po.number}", onDismiss, saveLabel = "Record", save = {
        env.client.put("/v1/admin/purchase-orders/${po.id}", J.obj("supplier_invoice_number" to J.s(number), "supplier_invoice_date" to JsonPrimitive(date), "supplier_invoice_amount" to (J.decimal(amount) ?: JsonNull)))
        env.toasts.show("Supplier invoice recorded")
        onDone()
    }) { error ->
        Field("Invoice number", number, { number = it }, placeholder = "INV-…", error = error?.field("supplier_invoice_number"))
        DayField("Invoice date", date, { if (it.isNotEmpty()) date = it })
        DecimalField("Invoice amount (${po.currency})", amount, { amount = it }, error = error?.field("supplier_invoice_amount"))
        ProcCaption("When recorded, the invoice amount — not the PO total — is what the payables ledger treats as owed.")
    }
}

// ── Payments (GET/POST …/payments, POST …/payments/{p}/void) ─────────────────

@Composable
private fun SupplierPaymentsSection(po: PurchaseOrderRecord, version: Int) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val res = remember(po.id) { Resource(scope) { env.client.get("/v1/admin/purchase-orders/${po.id}/payments").json ?: JsonNull } }
    var voiding by remember { mutableStateOf<Record?>(null) }
    LaunchedEffect(res, version) { res.reload() }

    SectionCard("Payments to the supplier") {
        LoadableView(res.state, retry = { res.load() }) { j ->
            val sm = j["summary"]
            val cur = sm["currency"].str ?: po.currency
            TileGrid {
                StatTile("Payable", Money.from(sm["payable"], cur)?.formatted() ?: "—", hint = if (sm["payable_source"].str == "supplier_invoice") "supplier invoice" else "PO total less returns", modifier = TileModifier)
                StatTile("Paid", Money.from(sm["paid"], cur)?.formatted() ?: "—", modifier = TileModifier)
                StatTile("Balance", Money.from(sm["balance"], cur)?.formatted() ?: "—", hint = sm["balance_kes"].takeIf { !it.isNull }?.let { Money.from(it, "KES")?.formatted() }, tone = StatusTone.forStatus(sm["status"].str), modifier = TileModifier)
            }
            sm["balance_kes_basis"].str?.let { ProcCaption(it) }
            val le = sm["linked_expenses"]
            if ((le["count"].int ?: 0) > 0) Text("${le["count"].int ?: 0} linked expense(s) in the P&L: ${Money.from(le["amount_kes"], "KES")?.formatted() ?: ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            j["payments"].toRecords().forEach { p ->
                val voided = !p["voided_at"].isNull
                val pcur = p.string("currency_code") ?: cur
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top) {
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                            Text(p.string("payment_number") ?: "", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                            StatusBadge(if (voided) "voided" else p.string("method")?.humanized, if (voided) StatusTone.DANGER else StatusTone.INFO)
                        }
                        Text("${p.string("paid_at") ?: ""} · ${p.string("reference") ?: ""} · ${personName(p["recorder"]) ?: ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        if (voided) p.string("void_reason")?.let { Text("Voided: $it", style = MaterialTheme.typography.labelSmall, color = Palette.danger) }
                    }
                    Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        MoneyText(p.money("amount", pcur), modifier = if (voided) Modifier.alpha(0.45f) else Modifier)
                        val k = p["amount_kes"]
                        if (!k.isNull && pcur != "KES") Text("= ${Money.from(k, "KES")?.formatted() ?: ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        if (!voided && env.session.current.can("procurement.pay_void")) TextButton(onClick = { voiding = p }) { Text("Void", color = Palette.danger) }
                    }
                }
                HorizontalDivider(color = MaterialTheme.hub.line)
            }
        }
    }

    voiding?.let { p ->
        TextPromptSheet("Void ${p.string("payment_number") ?: "payment"}", { voiding = null }, placeholder = "Reason (required, at least 5 characters)", confirmLabel = "Void", destructive = true) { reason ->
            env.client.post("/v1/admin/purchase-orders/${po.id}/payments/${p.id}/void", jsonOf("reason" to reason))
            env.toasts.show("Payment voided"); res.load()
        }
    }
}

/** POST …/payments {amount, method, reference?, paid_at?, notes?, client_request_id} — idempotent on client_request_id. */
private val paymentMethods = listOf("bank_transfer", "mpesa", "cash", "cheque", "card", "other")
private val methodsNeedingReference = setOf("bank_transfer", "mpesa", "cheque", "card")

@Composable
private fun RecordSupplierPaymentSheet(po: PurchaseOrderRecord, onDismiss: () -> Unit, onDone: () -> Unit) {
    val env = LocalEnv.current
    var amount by remember { mutableStateOf("") }
    var method by remember { mutableStateOf("bank_transfer") }
    var reference by remember { mutableStateOf("") }
    var paidAt by remember { mutableStateOf(Day.today()) }
    var notes by remember { mutableStateOf("") }
    val requestId = remember { UUID.randomUUID().toString() }
    val valid = (procDecimal(amount) ?: BigDecimal.ZERO) > BigDecimal.ZERO && (method !in methodsNeedingReference || reference.isNotBlank())

    FormSheet("Record payment — ${po.number}", onDismiss, saveLabel = "Record payment", canSave = valid, save = {
        val r = env.client.post("/v1/admin/purchase-orders/${po.id}/payments", J.obj(
            "amount" to (J.decimal(amount) ?: JsonPrimitive(0)), "method" to JsonPrimitive(method), "reference" to J.s(reference),
            "paid_at" to JsonPrimitive(paidAt), "notes" to J.s(notes), "client_request_id" to JsonPrimitive(requestId),
        ))
        env.toasts.show(r.json["message"].str ?: "Payment recorded")
        onDone()
    }) { error ->
        DecimalField("Amount (${po.currency})", amount, { amount = it }, error = error?.field("amount"))
        OptionPickerValues("Method", method, paymentMethods, { if (it.isNotEmpty()) method = it }, error = error?.field("method"))
        Field(if (method in methodsNeedingReference) "Reference (required)" else "Reference", reference, { reference = it }, placeholder = "Transaction code", error = error?.field("reference"))
        DayField("Paid on", paidAt, { if (it.isNotEmpty()) paidAt = it })
        Field("Notes", notes, { notes = it }, multiline = true)
        ProcCaption("Money paid to a supplier is not an expense; it settles the payables ledger. Retrying this form cannot record it twice.")
    }
}

// ── Owner revision (PUT …/revise — super admin only; whole order, reason required) ──

private data class RevLine(val id: Long = System.nanoTime(), val existingId: Int?, val type: String, val itemId: Int, val variantId: Int?, val title: String, val qty: String, val price: String)

@Composable
private fun RevisePurchaseOrderSheet(po: PurchaseOrderRecord, onDismiss: () -> Unit, onDone: () -> Unit) {
    val env = LocalEnv.current
    var reason by remember { mutableStateOf("") }
    var expected by remember { mutableStateOf(po.r.string("expected_delivery_date")?.take(10) ?: Day.today()) }
    var terms by remember { mutableStateOf(po.r.string("payment_terms") ?: "") }
    var notes by remember { mutableStateOf(po.r.string("notes") ?: "") }
    var shipping by remember { mutableStateOf(po.r["shipping_amount"].str ?: po.r["shipping_cost"].str ?: "") }
    var tax by remember { mutableStateOf(po.r["tax_amount"].str ?: po.r["tax"].str ?: "") }
    val lines = remember {
        mutableStateListOf<RevLine>().apply {
            po.items.forEach { i ->
                add(RevLine(existingId = i["id"].int, type = i["item_type"].str ?: (if (i["material_id"].int != null) "material" else "product"), itemId = i["product_id"].int ?: i["material_id"].int ?: i["item_id"].int ?: 0, variantId = i["product_variant_id"].int, title = poLineTitle(i), qty = i["quantity"].str ?: "", price = i["unit_price"].str ?: ""))
            }
        }
    }
    val valid = reason.trim().length >= 5 && lines.isNotEmpty() && lines.all { procDecimal(it.qty) != null && procDecimal(it.price) != null }

    FormSheet("Revise ${po.number} (owner)", onDismiss, saveLabel = "Save revision", canSave = valid, save = {
        val items = lines.map { l ->
            J.obj("type" to JsonPrimitive(l.type), "item_id" to JsonPrimitive(l.itemId), "quantity" to (J.decimal(l.qty) ?: JsonPrimitive(0)), "unit_price" to (J.decimal(l.price) ?: JsonPrimitive(0)), "id" to l.existingId?.let { JsonPrimitive(it) }, "product_variant_id" to l.variantId?.let { JsonPrimitive(it) })
        }
        val body = J.obj("reason" to JsonPrimitive(reason.trim()), "expected_delivery_date" to JsonPrimitive(expected), "payment_terms" to J.s(terms), "notes" to J.s(notes), "items" to toJson(items), "shipping_cost" to J.decimal(shipping), "tax" to J.decimal(tax))
        val r = env.client.put("/v1/admin/purchase-orders/${po.id}/revise", body)
        env.toasts.show(r.json["message"].str ?: "Revised")
        onDone()
    }) { error ->
        Field("Reason for the revision (required)", reason, { reason = it }, placeholder = "Why the issued order changes", multiline = true, error = error?.field("reason"))
        DayField("Expected delivery", expected, { if (it.isNotEmpty()) expected = it })
        lines.forEachIndexed { i, l ->
            ProcLineEditor(l.title, qty = l.qty, onQty = { v -> lines[i] = l.copy(qty = v) }, price = l.price, onPrice = { v -> lines[i] = l.copy(price = v) }) { lines.removeAll { it.id == l.id } }
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            DecimalField("Shipping", shipping, { shipping = it }, Modifier.weight(1f))
            DecimalField("Tax", tax, { tax = it }, Modifier.weight(1f))
        }
        Field("Payment terms", terms, { terms = it })
        Field("Notes", notes, { notes = it }, multiline = true)
        ProcCaption("The order as it was is kept as a revision; received quantities are not changed here (correct the GRN instead).")
    }
}
