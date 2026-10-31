package ke.co.bethanyhouse.hub.ui.features.pos

import android.content.Context
import android.os.Bundle
import android.os.CancellationSignal
import android.os.ParcelFileDescriptor
import android.print.PageRange
import android.print.PrintAttributes
import android.print.PrintDocumentAdapter
import android.print.PrintDocumentInfo
import android.print.PrintManager
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Print
import androidx.compose.material.icons.filled.Schedule
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.DocumentButton
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import java.io.FileOutputStream

/**
 * The receipt after a sale (ReceiptModal.tsx): the Hub's figures for the
 * order, printed as the server's PDF when the person may read it
 * (GET /v1/admin/pdf/orders/{id}/receipt, orders.view) or as HTML of the
 * same payload otherwise; emailed via pos/sales/{id}/email-receipt.
 */
@Composable
internal fun ReceiptSheet(session: PosSession, sale: PosSale, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var email by remember { mutableStateOf(sale.json["customer_email"].str?.takeIf { !it.contains("•") } ?: "") }
    var busy by remember { mutableStateOf(false) }
    val canReadPdf = env.session.current.can("orders.view")

    val needsApproval = sale.payments.any { it["requires_approval"].bool == true && (it["approval_status"].str ?: "pending") != "approved" && it["status"].str != "paid" }

    suspend fun sendEmail() {
        busy = true
        try { val r = session.api.emailReceipt(sale.id, email); env.toasts.show(r["message"].str ?: "Receipt sent") } catch (e: Exception) { env.toasts.error(e) } finally { busy = false }
    }

    suspend fun printReceipt() {
        busy = true
        try {
            val pdf = if (canReadPdf) runCatching { env.client.download("/v1/admin/pdf/orders/${sale.id}/receipt") }.getOrNull()?.takeIf { it.contentType?.contains("pdf") == true } else null
            if (pdf != null) PosPrint.printPdf(context, pdf.bytes, "Receipt ${sale.orderNumber}")
            else PosPrint.printHtml(context, ReceiptHtml.render(sale, session.outlet), "Receipt ${sale.orderNumber}")
        } catch (e: Exception) { env.toasts.error(e) } finally { busy = false }
    }

    PosSheet(if (needsApproval) "Invoice · awaiting approval" else "Receipt", subtitle = "${sale.orderNumber} · ${session.outlet?.name ?: ""}", onDismiss = onDismiss, actions = {
        OutlinedButton(onClick = { scope.launch { printReceipt() } }, enabled = !busy) {
            if (busy) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp) else { Icon(Icons.Default.Print, contentDescription = null, modifier = Modifier.size(16.dp)); Text(" Print") }
        }
        if (canReadPdf) DocumentButton("Share PDF", "/v1/admin/pdf/orders/${sale.id}/receipt", suggestedName = "receipt-${sale.orderNumber}.pdf")
        Button(onClick = onDismiss) { Text("New sale") }
    }) {
        if (needsApproval) Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Default.Schedule, contentDescription = null, tint = Palette.amber, modifier = Modifier.size(16.dp))
            Spacer(Modifier.width(Space.s))
            Text("A payment on this sale is held for admin approval. Hand over an invoice; the receipt follows once it is approved.", style = MaterialTheme.typography.bodySmall, color = Palette.amber)
        }
        ReceiptBody(sale, session.outlet)
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            OutlinedTextField(value = email, onValueChange = { email = it }, placeholder = { Text("Email the receipt to…") }, singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email), modifier = Modifier.weight(1f))
            OutlinedButton(onClick = { scope.launch { sendEmail() } }, enabled = email.contains("@") && !busy) { Text("Send") }
        }
    }
}

/** The receipt lines as the server returned them. */
@Composable
internal fun ReceiptBody(sale: PosSale, outlet: PosOutlet?) {
    Column(Modifier.fillMaxWidth().background(MaterialTheme.hub.card, MaterialTheme.shapes.small).padding(Space.l), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(outlet?.name ?: sale.json["outlet_name"].str ?: "Bethany House", style = MaterialTheme.typography.titleMedium)
        Text("${sale.orderNumber} · ${sale.json["cashier_name"].str ?: ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
        DateText(sale.createdAt)
        sale.customerName?.let { Text("Customer: $it", style = MaterialTheme.typography.bodySmall) }
        HorizontalDivider(color = MaterialTheme.hub.line)
        sale.items.forEach { i ->
            val variant = i["variant_name"].str ?: ""
            val title = if (variant.isEmpty()) (i["product_name"].str ?: "Item") else "${i["product_name"].str ?: "Item"} · $variant"
            val unit = Money.from(i["unit_price"], sale.currency)?.formatted() ?: "—"
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top) {
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(1.dp)) {
                    Text(title, style = MaterialTheme.typography.bodySmall)
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text("${i["quantity"].int ?: 0} × $unit", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        Money.from(i["discount_amount"], sale.currency)?.takeIf { !it.isZero }?.let { Text("less ${it.formatted()}", style = MaterialTheme.typography.labelSmall, color = Palette.pink) }
                        if (i["is_production"].bool == true) StatusBadge("made to order", StatusTone.ACCENT)
                    }
                }
                MoneyText(Money.from(i["subtotal"], sale.currency))
            }
        }
        HorizontalDivider(color = MaterialTheme.hub.line)
        TotalRow("Subtotal", sale.money("subtotal"))
        sale.money("discount_amount")?.takeIf { !it.isZero }?.let { TotalRow("Discount", it) }
        TotalRow(if (sale.json["prices_include_tax"].bool == true) "Tax (included)" else "Tax", sale.money("tax_amount"))
        TotalRow("Total", sale.total, strong = true)
        val paid = sale.money("amount_paid"); val out = sale.money("outstanding")
        if (paid != null && out != null && !out.isZero) { TotalRow("Paid", paid); TotalRow("Outstanding", out) }
        if (sale.payments.isNotEmpty()) {
            HorizontalDivider(color = MaterialTheme.hub.line)
            sale.payments.forEach { p ->
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text((p["payment_method"].str ?: "payment").humanized, style = MaterialTheme.typography.bodySmall)
                    p["reference"].str?.takeIf { it.isNotEmpty() }?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                    if (p["requires_approval"].bool == true) StatusBadge(p["approval_status"].str ?: "pending") else StatusBadge(p["status"].str)
                    Spacer(Modifier.weight(1f))
                    MoneyText(Money.from(p["amount"], p["currency_code"].str ?: sale.currency))
                }
                p["cash_received"]?.takeUnless { it.isNull }?.let { TotalRow("Cash received", Money.from(it, sale.currency)) }
                p["change_given"]?.takeUnless { it.isNull }?.let { TotalRow("Change", Money.from(it, sale.currency)) }
            }
        }
        if (sale.pendingReversals.isNotEmpty()) CaptionText("Void / refund requests are pending on this sale.", Palette.warning)
    }
}

/**
 * Plain HTML of the server's sale payload for printing when the PDF is not
 * available to this person. Figures are copied, never recomputed.
 */
internal object ReceiptHtml {
    fun esc(s: String): String = s.replace("&", "&amp;").replace("<", "&lt;")

    fun render(sale: PosSale, outlet: PosOutlet?): String {
        fun m(v: JsonElement?, cur: String? = null): String = v?.let { Money.from(it, cur ?: sale.currency) }?.formatted() ?: "—"
        val h = StringBuilder("<html><body style='font-family:Helvetica,sans-serif;font-size:12px;width:280px'>")
        h.append("<h3 style='margin:0'>${esc(outlet?.name ?: "Bethany House")}</h3>")
        outlet?.raw["address"].str?.let { h.append("<div>${esc(it)}</div>") }
        h.append("<div>${esc(sale.orderNumber)} · ${esc(sale.json["created_at"].str ?: "")}</div>")
        sale.json["cashier_name"].str?.let { h.append("<div>Cashier: ${esc(it)}</div>") }
        sale.customerName?.let { h.append("<div>Customer: ${esc(it)}</div>") }
        h.append("<hr><table width='100%'>")
        for (i in sale.items) {
            h.append("<tr><td>${esc(i["product_name"].str ?: "")}<br><small>${i["quantity"].int ?: 0} × ${m(i["unit_price"])}</small></td><td align='right'>${m(i["subtotal"])}</td></tr>")
        }
        h.append("</table><hr><table width='100%'>")
        h.append("<tr><td>Subtotal</td><td align='right'>${m(sale.json["subtotal"])}</td></tr>")
        sale.money("discount_amount")?.takeIf { !it.isZero }?.let { h.append("<tr><td>Discount</td><td align='right'>${it.formatted()}</td></tr>") }
        h.append("<tr><td>Tax</td><td align='right'>${m(sale.json["tax_amount"])}</td></tr>")
        h.append("<tr><td><b>Total</b></td><td align='right'><b>${m(sale.json["total"])}</b></td></tr>")
        for (p in sale.payments) {
            h.append("<tr><td>${esc((p["payment_method"].str ?: "").humanized)} ${esc(p["reference"].str ?: "")}</td><td align='right'>${m(p["amount"], p["currency_code"].str)}</td></tr>")
            p["cash_received"]?.takeUnless { it.isNull }?.let { h.append("<tr><td>Cash received</td><td align='right'>${m(it)}</td></tr>") }
            p["change_given"]?.takeUnless { it.isNull }?.let { h.append("<tr><td>Change</td><td align='right'>${m(it)}</td></tr>") }
        }
        sale.money("outstanding")?.takeIf { !it.isZero }?.let { h.append("<tr><td>Outstanding</td><td align='right'>${it.formatted()}</td></tr>") }
        h.append("</table><hr><div style='text-align:center'>Thank you</div></body></html>")
        return h.toString()
    }
}

/**
 * Printing through `android.print.PrintManager`: HTML in an offscreen
 * WebView (printing only, never shown) via `createPrintDocumentAdapter`, or
 * the server's PDF bytes copied through a [PrintDocumentAdapter].
 */
internal object PosPrint {
    /** Held until the print job has been handed over, so the WebView is not collected mid-render. */
    private var pending: WebView? = null

    fun printHtml(context: Context, html: String, jobName: String) {
        val view = WebView(context)
        pending = view
        view.webViewClient = object : WebViewClient() {
            override fun onPageFinished(v: WebView, url: String?) {
                val manager = context.getSystemService(Context.PRINT_SERVICE) as PrintManager
                manager.print(jobName, v.createPrintDocumentAdapter(jobName), PrintAttributes.Builder().build())
                pending = null
            }
        }
        view.loadDataWithBaseURL(null, html, "text/html", "utf-8", null)
    }

    fun printPdf(context: Context, bytes: ByteArray, jobName: String) {
        val manager = context.getSystemService(Context.PRINT_SERVICE) as PrintManager
        manager.print(jobName, PdfBytesAdapter(bytes, jobName), PrintAttributes.Builder().build())
    }

    private class PdfBytesAdapter(private val bytes: ByteArray, private val name: String) : PrintDocumentAdapter() {
        override fun onLayout(old: PrintAttributes?, new: PrintAttributes, cancel: CancellationSignal?, callback: LayoutResultCallback, extras: Bundle?) {
            if (cancel?.isCanceled == true) { callback.onLayoutCancelled(); return }
            callback.onLayoutFinished(PrintDocumentInfo.Builder(name).setContentType(PrintDocumentInfo.CONTENT_TYPE_DOCUMENT).setPageCount(PrintDocumentInfo.PAGE_COUNT_UNKNOWN).build(), true)
        }
        override fun onWrite(pages: Array<out PageRange>?, dest: ParcelFileDescriptor, cancel: CancellationSignal?, callback: WriteResultCallback) {
            try {
                FileOutputStream(dest.fileDescriptor).use { it.write(bytes) }
                callback.onWriteFinished(arrayOf(PageRange.ALL_PAGES))
            } catch (e: Exception) { callback.onWriteFailed(e.message) }
        }
    }
}

// ── Customer (pos/customers/search, quick create, reveal) ────────────────────

private enum class CustomerMode(val label: String) { SEARCH("Find"), CREATE("New customer"), WALK_IN("Walk-in name") }

private val REVEAL_REASONS = listOf("payment_follow_up" to "Payment follow-up", "delivery" to "Delivery", "customer_callback" to "Calling the customer back", "order_issue" to "A problem with the order")

@Composable
internal fun CustomerSheet(session: PosSession, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var query by remember { mutableStateOf("") }
    var results by remember { mutableStateOf<List<JsonElement>>(emptyList()) }
    var searching by remember { mutableStateOf(false) }
    var searchJob by remember { mutableStateOf<Job?>(null) }
    var draft by remember { mutableStateOf(PosCustomer(id = -1)) }
    var mode by remember { mutableStateOf(CustomerMode.SEARCH) }
    var revealReason by remember { mutableStateOf("payment_follow_up") }
    val revealed = remember { mutableStateMapOf<String, String>() }
    var revealMenu by remember { mutableStateOf(false) }

    LaunchedEffect(query) {
        searchJob?.cancel()
        if (query.length < 3) { results = emptyList(); return@LaunchedEffect }
        searchJob = scope.launch {
            delay(350)
            searching = true
            results = runCatching { session.api.searchCustomers(query) }.getOrDefault(emptyList())
            searching = false
        }
    }

    suspend fun reveal(field: String) {
        val c = session.customer ?: return
        if (c.id <= 0) return
        try {
            val r = session.api.reveal(c.id, field, revealReason, session.pendingOrderId)
            r["value"].str?.let { revealed[field] = it; env.toasts.show("${field.replaceFirstChar { ch -> ch.uppercase() }} revealed — this is audited", StatusTone.INFO) }
        } catch (e: Exception) { env.toasts.error(e) }
    }

    PosSheet("Customer", subtitle = "Who this sale is for.", onDismiss = onDismiss, actions = { TextButton(onClick = onDismiss) { Text("Done") } }) {
        session.customer?.let { c ->
            Row(Modifier.fillMaxWidth().background(MaterialTheme.hub.card, MaterialTheme.shapes.small).padding(Space.m), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(c.displayName, style = MaterialTheme.typography.bodyMedium)
                    Text(listOf(revealed["phone"] ?: c.phone, revealed["email"] ?: c.email).filter { it.isNotEmpty() }.joinToString(" · "), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                }
                if (c.id > 0) Box {
                    TextButton(onClick = { revealMenu = true }) { Text("Reveal…") }
                    DropdownMenu(expanded = revealMenu, onDismissRequest = { revealMenu = false }) {
                        REVEAL_REASONS.forEach { (v, l) -> DropdownMenuItem(text = { Text((if (revealReason == v) "● " else "○ ") + l) }, onClick = { revealReason = v }) }
                        HorizontalDivider()
                        DropdownMenuItem(text = { Text("Reveal phone") }, onClick = { revealMenu = false; scope.launch { reveal("phone") } })
                        DropdownMenuItem(text = { Text("Reveal email") }, onClick = { revealMenu = false; scope.launch { reveal("email") } })
                    }
                }
                TextButton(onClick = { session.customer = null; revealed.clear(); session.cartChanged() }) { Text("Remove") }
            }
        }
        SingleChoiceSegmentedButtonRow(Modifier.fillMaxWidth()) {
            CustomerMode.entries.forEachIndexed { i, m ->
                SegmentedButton(selected = mode == m, onClick = { mode = m }, shape = SegmentedButtonDefaults.itemShape(i, CustomerMode.entries.size)) { Text(m.label, maxLines = 1) }
            }
        }
        when (mode) {
            CustomerMode.SEARCH -> {
                OutlinedTextField(value = query, onValueChange = { query = it }, label = { Text("Name or phone (3+ characters)") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                if (searching) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp)
                results.forEach { r ->
                    Row(
                        Modifier.fillMaxWidth().border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.small).clickable {
                            session.customer = PosCustomer.fromName(r["id"].int ?: 0, r["name"].str, r["phone"].str, r["email"].str)
                            revealed.clear(); session.cartChanged(); onDismiss()
                        }.padding(Space.s),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(r["name"].str ?: "Customer", style = MaterialTheme.typography.bodyMedium, modifier = Modifier.weight(1f))
                        Text(r["phone"].str ?: "", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    }
                }
            }
            CustomerMode.CREATE -> {
                OutlinedTextField(value = draft.firstName, onValueChange = { draft = draft.copy(firstName = it) }, label = { Text("First name *") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(value = draft.lastName, onValueChange = { draft = draft.copy(lastName = it) }, label = { Text("Last name") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(value = draft.phone, onValueChange = { draft = draft.copy(phone = it) }, label = { Text("Phone *") }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone), modifier = Modifier.fillMaxWidth())
                OutlinedTextField(value = draft.email, onValueChange = { draft = draft.copy(email = it) }, label = { Text("Email") }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email), modifier = Modifier.fillMaxWidth())
                OutlinedTextField(value = draft.company, onValueChange = { draft = draft.copy(company = it) }, label = { Text("Organisation (a parish, a school, a bank)") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                Button(onClick = { session.customer = draft.copy(id = -1); session.cartChanged(); onDismiss() }, enabled = draft.firstName.isNotEmpty() && draft.phone.isNotEmpty()) { Text("Attach new customer") }
            }
            CustomerMode.WALK_IN -> {
                OutlinedTextField(value = draft.firstName, onValueChange = { draft = draft.copy(firstName = it) }, label = { Text("First name") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(value = draft.lastName, onValueChange = { draft = draft.copy(lastName = it) }, label = { Text("Last name") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(value = draft.phone, onValueChange = { draft = draft.copy(phone = it) }, label = { Text("Phone (optional)") }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone), modifier = Modifier.fillMaxWidth())
                OutlinedButton(onClick = { session.customer = draft.copy(id = 0); session.cartChanged(); onDismiss() }, enabled = draft.firstName.isNotEmpty()) { Text("Use for this sale") }
            }
        }
        LabelledRow("Customer country (2 letters, for currency)") {
            OutlinedTextField(
                value = session.customerCountryCode, onValueChange = { session.customerCountryCode = it.take(2).uppercase(); session.cartChanged() },
                placeholder = { Text("KE") }, singleLine = true, modifier = Modifier.width(84.dp),
            )
        }
    }
}
