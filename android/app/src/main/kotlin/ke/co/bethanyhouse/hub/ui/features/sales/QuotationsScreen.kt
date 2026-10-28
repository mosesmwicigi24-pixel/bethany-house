package ke.co.bethanyhouse.hub.ui.features.sales

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Description
import androidx.compose.material.icons.filled.RemoveShoppingCart
import androidx.compose.material.icons.filled.RequestQuote
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.HubDates
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.DateField
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.DecimalField
import ke.co.bethanyhouse.hub.ui.components.DetailRow
import ke.co.bethanyhouse.hub.ui.components.DocumentButton
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.InfoSheet
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.PagedList
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import java.time.LocalDate

// ── Quotations (QuotationController: index/store/update/revise/issue/accept/destroy/reprice/revisions) ─

private val QUOTE_STATUSES = listOf("draft", "sent", "accepted", "declined", "expired", "converted", "void")

private data class QuoteBuilder(val editing: Record?, val revise: Boolean)

@Composable
fun QuotationsScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var search by remember { mutableStateOf("") }
    var status by remember { mutableStateOf("") }
    var builder by remember { mutableStateOf<QuoteBuilder?>(null) }
    var detail by remember { mutableStateOf<Record?>(null) }
    var accepting by remember { mutableStateOf<Record?>(null) }
    val list = remember { PagedList(scope, env.client, "/v1/admin/quotations", map = ::Record) }
    fun reload() { list.query = mutableMapOf("search" to search.ifEmpty { null }, "status" to status.ifEmpty { null }); list.load() }
    LaunchedEffect(status) { reload() }

    Page("Quotations", subtitle = "Front of the quotation → invoice → receipt flow", refresh = { list.reload() }) {
        ModuleSearchField(search, { search = it }, "Quote no., customer, phone, email", onSubmit = ::reload)
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
            OptionPicker("Status", status, QUOTE_STATUSES.map { it to it.humanized }, { status = it }, Modifier.weight(1f), allowNone = true)
            if (env.session.current.can("quotations.create")) Button(onClick = { builder = QuoteBuilder(null, false) }) { Text("New quotation") }
        }
        LoadableView(list.state, retry = { list.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("No quotations", "Draft one for a customer who wants a price in writing.", Icons.Default.RequestQuote) }) { rows ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                rows.forEachIndexed { i, q ->
                    QuotationRow(q, onDetail = { detail = q }, onEdit = { builder = QuoteBuilder(q, false) }, onRevise = { builder = QuoteBuilder(q, true) }, onAccept = { accepting = q }, onChanged = { list.load() }, onDeleted = { list.remove { it.id == q.id } })
                    if (i == rows.lastIndex && list.hasMore) LaunchedEffect(rows.size) { list.loadMore() }
                }
                SalesLoadMore(list)
            }
        }
    }
    builder?.let { b -> QuotationBuilderSheet(b.editing, b.revise, onDone = { list.load() }, onDismiss = { builder = null }) }
    detail?.let { q -> QuotationDetailSheet(q, onDismiss = { detail = null }) }
    accepting?.let { q -> AcceptQuotationSheet(q, onDone = { orderId -> list.load(); if (orderId != null) env.navigator.push(Route.Order(orderId)) }, onDismiss = { accepting = null }) }
}

@Composable
private fun QuotationRow(q: Record, onDetail: () -> Unit, onEdit: () -> Unit, onRevise: () -> Unit, onAccept: () -> Unit, onChanged: () -> Unit, onDeleted: () -> Unit) {
    val env = LocalEnv.current
    val s = env.session.current
    val st = q.status ?: ""
    val issued = st in listOf("sent", "accepted", "declined", "expired", "converted")
    SectionCard {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    TextButton(onClick = onDetail, contentPadding = androidx.compose.foundation.layout.PaddingValues(0.dp)) { SalesMono(q.string("quote_number") ?: "Draft #${q.id}") }
                    StatusBadge(st)
                    if (st == "void") q.string("void_reason")?.let { SalesCaption(it, MaterialTheme.hub.textTertiary) }
                }
                Text(Sales.name(q.string("customer_first_name"), q.string("customer_last_name")) ?: q.string("customer_email") ?: q.string("customer_phone") ?: "No customer", style = MaterialTheme.typography.bodyMedium)
                SalesCaption(listOfNotNull(q.string("valid_until")?.let { "valid until ${it.take(10)}" }, q.string("served_by"), (q.string("source") ?: "admin").humanized).joinToString(" · "))
            }
            Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                MoneyText(q.money("total_amount"), emphasis = true)
                DateText(q.date("issued_at") ?: q.date("created_at"), DateStyle.RELATIVE)
            }
        }
        FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            if (st == "draft" && s.can("quotations.create")) OutlinedButton(onClick = onEdit) { Text("Edit") }
            if (st == "draft" && s.can("quotations.issue")) ConfirmButton("Issue quotation", "Issuing numbers the quotation and makes it the customer's copy.", action = {
                try { val r = env.client.post("/v1/admin/quotations/${q.id}/issue"); env.toasts.show(r.json["message"].str ?: "Quotation issued"); onChanged() } catch (e: Exception) { env.toasts.error(e) }
            }) { Text("Issue") }
            if (st == "sent" && s.can("quotations.issue")) Button(onClick = onAccept) { Text("Accept → Invoice") }
            if (issued && st != "void") q.string("quote_token")?.let { token -> SalesCopyButton("Copy link", "${env.config.hubUrl.trimEnd('/')}/quote/$token", "Customer link copied") }
            if (issued) DocumentButton("Quote PDF", "/v1/admin/pdf/quotations/${q.id}", suggestedName = "quotation-${q.id}.pdf")
            if (st == "converted") q["invoice_document"]["documentable_id"].str?.let { oid ->
                DocumentButton("Invoice PDF", "/v1/admin/pdf/orders/$oid/invoice", suggestedName = "invoice-$oid.pdf")
                oid.toIntOrNull()?.let { id -> OutlinedButton(onClick = { env.navigator.push(Route.Order(id)) }) { Text("Open order") } }
            }
            if (s.permissions.isSuperAdmin && st in listOf("sent", "accepted", "declined", "expired")) OutlinedButton(onClick = onRevise) { Text("Revise (owner)") }
            if (st == "draft" && s.can("quotations.delete")) ConfirmButton("Delete draft", "The draft is removed.", destructive = true, action = {
                try { env.client.delete("/v1/admin/quotations/${q.id}"); env.toasts.show("Quotation deleted"); onDeleted() } catch (e: Exception) { env.toasts.error(e) }
            }) { Text("Delete") }
        }
    }
}

/** POST /quotations/{id}/accept {due_in_days?} → {order: {id}, invoice: {number}}. */
@Composable
private fun AcceptQuotationSheet(quotation: Record, onDone: (Int?) -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var dueInDays by remember { mutableStateOf("") }
    FormSheet("Accept ${quotation.string("quote_number") ?: ""}", onDismiss, saveLabel = "Create invoice", canSave = dueInDays.isEmpty() || dueInDays.toIntOrNull() != null, save = {
        val body = dueInDays.toIntOrNull()?.let { jsonOf("due_in_days" to it) } ?: jsonOf()
        val r = env.client.post("/v1/admin/quotations/${quotation.id}/accept", body)
        env.toasts.show("Invoice ${r.json["invoice"]["number"].str ?: ""} created")
        onDone(r.json["order"]["id"].int)
    }) { error ->
        SalesSecondary("Accepting creates the order and its invoice; the order is a receivable until it is paid.")
        DetailRow("Total") { MoneyText(quotation.money("total_amount")) }
        Field("Due in days (optional, 0–365)", dueInDays, { dueInDays = it.filter { c -> c.isDigit() } }, error = error?.field("due_in_days"))
        SalesFieldErrors(error)
    }
}

/** The quotation's lines and, for the owner, its revisions (GET /quotations/{id}/revisions). */
@Composable
private fun QuotationDetailSheet(quotation: Record, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val items = quotation["items"].toRecords()
    val cc = quotation.string("currency_code") ?: "KES"
    val revisions = remember(quotation.id) { if (quotation.status != "draft") Resource(scope) { env.client.get("/v1/admin/quotations/${quotation.id}/revisions").json ?: JsonNull } else null }
    LaunchedEffect(revisions) { revisions?.load() }
    InfoSheet(quotation.string("quote_number") ?: "Draft quotation", onDismiss) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) { StatusBadge(quotation.status); Spacer(Modifier.weight(1f)); MoneyText(quotation.money("total_amount"), emphasis = true) }
        DetailRow("Customer", Sales.name(quotation.string("customer_first_name"), quotation.string("customer_last_name")))
        DetailRow("Phone", quotation.string("customer_phone"))
        DetailRow("Email", quotation.string("customer_email"))
        DetailRow("Valid until", quotation.string("valid_until")?.take(10))
        items.forEach { i ->
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(i.string("product_name") ?: "", style = MaterialTheme.typography.bodyMedium)
                    SalesCaption(listOfNotNull("${i.string("quantity") ?: ""} × ${i.money("unit_price", cc)?.formatted() ?: ""}", i.string("sku")).joinToString(" · "))
                }
                MoneyText(i.money("total_price", cc))
            }
        }
        SalesMoneyBreakdown(quotation)
        quotation.string("notes")?.takeIf { it.isNotEmpty() }?.let { DetailRow("Notes", it) }
        quotation.string("terms")?.takeIf { it.isNotEmpty() }?.let { DetailRow("Terms", it) }
        if (revisions != null) LoadableView(revisions.state, retry = { revisions.load() }) { json ->
            Text("Revisions (current rev ${json["current_revision"].str ?: "0"})", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
            SalesRevisionList(json["revisions"].toRecords())
        }
    }
}

// ── Builder — POST /quotations | PUT /quotations/{id} | PUT /quotations/{id}/revise ─

@Composable
private fun QuotationBuilderSheet(editing: Record?, revise: Boolean, onDone: () -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var firstName by remember { mutableStateOf(editing?.string("customer_first_name") ?: "") }
    var lastName by remember { mutableStateOf(editing?.string("customer_last_name") ?: "") }
    var email by remember { mutableStateOf(editing?.string("customer_email") ?: "") }
    var phone by remember { mutableStateOf(editing?.string("customer_phone") ?: "") }
    var servedBy by remember { mutableStateOf(editing?.string("served_by") ?: "") }
    var currency by remember { mutableStateOf(editing?.string("currency_code") ?: "KES") }
    var currencies by remember { mutableStateOf<List<Record>>(emptyList()) }
    var shipping by remember { mutableStateOf(editing?.get("shipping_amount").str ?: "") }
    var validUntil by remember { mutableStateOf(editing?.date("valid_until")?.atZone(HubDates.nairobi)?.toLocalDate()) }
    var notes by remember { mutableStateOf(editing?.string("notes") ?: "") }
    var terms by remember { mutableStateOf(editing?.string("terms") ?: "") }
    var reason by remember { mutableStateOf("") }
    var lines by remember {
        mutableStateOf(editing?.get("items").toRecords().map { i ->
            SalesLine(productId = i["product_id"].str, variantId = i["product_variant_id"].str, name = i.string("product_name") ?: "", sku = i.string("sku"),
                quantity = i["quantity"].str ?: "1", unitPrice = i["unit_price"].str ?: "", discount = i["discount_amount"].str ?: "0")
        })
    }
    var adding by remember { mutableStateOf(false) }
    var repricing by remember { mutableStateOf(false) }
    var keptKeys by remember { mutableStateOf<Set<Long>>(emptySet()) }

    LaunchedEffect(Unit) { runCatching { currencies = env.client.get("/v1/settings/currencies").json.salesRecords.filter { it["sellable"].bool != false && it["is_active"].bool != false } } }

    val canSubmit = lines.isNotEmpty() && lines.all { (it.quantity.toIntOrNull() ?: 0) > 0 && Sales.amountJson(it.unitPrice) != null } && (!revise || reason.trim().length >= 5)

    /**
     * POST /quotations/reprice {from, to, lines[{key, product_id?, unit_price, name}]}:
     * catalogue lines get the catalogue price in the new currency, free
     * lines are converted, a human-typed price is kept and named.
     */
    suspend fun reprice(from: String, to: String) {
        if (from == to || lines.isEmpty()) { keptKeys = emptySet(); return }
        repricing = true
        try {
            val r = env.client.post("/v1/admin/quotations/reprice", JsonObject(mapOf(
                "from" to JsonPrimitive(from), "to" to JsonPrimitive(to),
                "lines" to JsonArray(lines.map { l ->
                    val o = mutableMapOf<String, JsonElement>("key" to JsonPrimitive(l.id.toString()), "unit_price" to (Sales.amountJson(l.unitPrice) ?: JsonPrimitive("0")), "name" to JsonPrimitive(l.name.ifEmpty { "This line" }))
                    l.productId?.let { o["product_id"] = JsonPrimitive(it) }
                    JsonObject(o)
                }),
            )))
            val kept = mutableSetOf<Long>()
            var updated = lines
            r.json["lines"].records.forEach { priced ->
                val key = priced["key"].str?.toLongOrNull() ?: return@forEach
                updated = updated.map { l -> if (l.id == key) l.copy(unitPrice = priced["unit_price"].str ?: l.unitPrice) else l }
                if (priced["source"].str == "kept") kept += key
            }
            lines = updated
            keptKeys = kept
            r.json["warnings"].records.mapNotNull { it.str }.forEach { env.toasts.show(it, StatusTone.WARNING) }
        } catch (e: Exception) { env.toasts.error(e) } finally { repricing = false }
    }

    FormSheet(if (revise) "Revise ${editing?.string("quote_number") ?: ""}" else if (editing == null) "New quotation" else "Edit quotation", onDismiss, saveLabel = if (revise) "Save revision" else "Save", canSave = canSubmit, save = {
        val body = mutableMapOf<String, JsonElement>(
            "currency_code" to JsonPrimitive(currency),
            "customer_first_name" to Sales.optionalString(firstName), "customer_last_name" to Sales.optionalString(lastName),
            "customer_email" to Sales.optionalString(email), "customer_phone" to Sales.optionalString(phone),
            "served_by" to Sales.optionalString(servedBy), "notes" to Sales.optionalString(notes), "terms" to Sales.optionalString(terms),
            "valid_until" to (validUntil?.let { JsonPrimitive(HubDates.dayString(it)) } ?: JsonNull),
            "items" to JsonArray(lines.map { l ->
                val o = mutableMapOf<String, JsonElement>(
                    "product_name" to JsonPrimitive(l.name), "quantity" to JsonPrimitive(l.quantity.toIntOrNull() ?: 1),
                    "unit_price" to (Sales.amountJson(l.unitPrice) ?: JsonPrimitive("0")),
                    "product_id" to (l.productId?.let { JsonPrimitive(it) } ?: JsonNull),
                    "product_variant_id" to (l.variantId?.let { JsonPrimitive(it) } ?: JsonNull),
                )
                l.sku?.let { o["sku"] = JsonPrimitive(it) }
                Sales.amountJson(l.discount)?.let { o["discount_amount"] = it }
                JsonObject(o)
            }),
        )
        Sales.amountJson(shipping)?.let { body["shipping_amount"] = it }
        if (revise) body["reason"] = JsonPrimitive(reason.trim())
        if (editing != null) env.client.put(if (revise) "/v1/admin/quotations/${editing.id}/revise" else "/v1/admin/quotations/${editing.id}", JsonObject(body))
        else env.client.post("/v1/admin/quotations", JsonObject(body))
        env.toasts.show(if (revise) "Revision saved — the issued version is kept" else "Quotation saved")
        onDone()
    }) { error ->
        if (revise) Field("Reason for the revision (required)", reason, { reason = it }, multiline = true, error = error?.field("reason"))
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Field("First name", firstName, { firstName = it }, Modifier.weight(1f), error = error?.field("customer_first_name"))
            Field("Last name", lastName, { lastName = it }, Modifier.weight(1f), error = error?.field("customer_last_name"))
        }
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Field("Phone", phone, { phone = it }, Modifier.weight(1f), error = error?.field("customer_phone"))
            Field("Email", email, { email = it }, Modifier.weight(1f), error = error?.field("customer_email"))
        }
        val currencyOptions = currencies.map { (it.string("code") ?: "") to "${it.string("code") ?: ""} — ${it.string("name") ?: ""}" }.let { if (it.none { c -> c.first == currency }) it + (currency to currency) else it }
        OptionPicker("Currency", currency, currencyOptions, { new -> if (new.isNotEmpty() && new != currency) { val old = currency; currency = new; scope.launch { reprice(old, new) } } }, error = error?.field("currency_code"))
        Field("Served by", servedBy, { servedBy = it })
        DateField("Valid until", validUntil, { validUntil = it })
        Text("LINES", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
        lines.forEach { l ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Field("Description", l.name, { v -> lines = lines.map { if (it.id == l.id) it.copy(name = v) else it } }, Modifier.weight(1f))
                    IconButton(onClick = { lines = lines.filterNot { it.id == l.id } }) { Icon(Icons.Default.Delete, contentDescription = "Remove", tint = Palette.danger) }
                }
                l.sku?.let { SalesCaption(it, MaterialTheme.hub.textTertiary) }
                if (l.id in keptKeys) SalesCaption("Typed price kept — not converted", Palette.amber)
                Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    DecimalField("Qty", l.quantity, { v -> lines = lines.map { if (it.id == l.id) it.copy(quantity = v) else it } }, Modifier.weight(0.7f))
                    DecimalField("Unit price", l.unitPrice, { v -> lines = lines.map { if (it.id == l.id) it.copy(unitPrice = v) else it } }, Modifier.weight(1.2f))
                    DecimalField("Discount", l.discount, { v -> lines = lines.map { if (it.id == l.id) it.copy(discount = v) else it } }, Modifier.weight(1f))
                }
                SalesDivider()
            }
        }
        FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            OutlinedButton(onClick = { adding = true }) { Text("Add from catalogue") }
            OutlinedButton(onClick = { lines = lines + SalesLine(quantity = "1", unitPrice = "", discount = "0") }) { Text("Add free line") }
            if (repricing) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
        }
        DecimalField("Shipping ($currency)", shipping, { shipping = it }, error = error?.field("shipping_amount"))
        Field("Notes", notes, { notes = it }, multiline = true)
        Field("Terms", terms, { terms = it }, multiline = true)
        SalesCaption("Subtotal, tax and total are computed by the server when saved.", MaterialTheme.hub.textTertiary)
        SalesFieldErrors(error)
    }
    if (adding) ProductPickerSheet(currency, onPicked = { lines = lines + it; adding = false }, onDismiss = { adding = false })
}

// ── Invoices (InvoiceController: index/show/void) ───────────────────────────

@Composable
fun InvoicesScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var search by remember { mutableStateOf("") }
    var status by remember { mutableStateOf("") }
    var voiding by remember { mutableStateOf<Record?>(null) }
    val list = remember { PagedList(scope, env.client, "/v1/admin/invoices", map = ::Record) }
    fun reload() { list.query = mutableMapOf("search" to search.ifEmpty { null }, "status" to status.ifEmpty { null }); list.load() }
    LaunchedEffect(status) { reload() }

    Page("Invoices", subtitle = "The invoice stage of quotation → invoice → receipt", refresh = { list.reload() }) {
        ModuleSearchField(search, { search = it }, "Invoice no., customer, order", onSubmit = ::reload)
        OptionPicker("Status", status, listOf("issued" to "Open (issued)", "paid" to "Paid", "void" to "Void"), { status = it }, allowNone = true)
        LoadableView(list.state, retry = { list.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("No invoices", "An invoice is created when a quotation is accepted.", Icons.Default.Description) }) { rows ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                rows.forEachIndexed { i, inv ->
                    InvoiceRow(inv, onVoid = { voiding = inv })
                    if (i == rows.lastIndex && list.hasMore) LaunchedEffect(rows.size) { list.loadMore() }
                }
                SalesLoadMore(list)
            }
        }
    }
    voiding?.let { inv -> VoidInvoiceSheet(inv, onDone = { list.load() }, onDismiss = { voiding = null }) }
}

@Composable
private fun InvoiceRow(inv: Record, onVoid: () -> Unit) {
    val env = LocalEnv.current
    val order = inv["order"]
    val docStatus = inv.string("doc_status") ?: ""
    val orderStatus = order["status"].str
    val orderDead = Sales.isDead(orderStatus)
    val payable = order["payable"].bool ?: !orderDead
    val canVoid = env.session.current.permissions.isSuperAdmin && docStatus == "issued" && order["payment_status"].str == "pending" && (orderStatus ?: "pending") in listOf("pending", "confirmed")
    SectionCard {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    SalesMono(inv.string("invoice_number") ?: "")
                    inv.int("revision")?.takeIf { it > 0 }?.let { SalesCaption("Rev $it") }
                    StatusBadge(docStatus)
                    if (docStatus != "void") order["payment_status"].str?.let { StatusBadge(it) }
                }
                Text(inv.string("customer_name") ?: "—", style = MaterialTheme.typography.bodyMedium)
                SalesCaption(listOfNotNull(order["order_number"].str, inv["quotation"]["number"].str?.let { "from $it" }, inv.string("served_by")).joinToString(" · "))
                SalesCaption(listOfNotNull(inv.string("issued_at")?.let { "issued ${it.take(10)}" }, inv.string("due_date")?.let { "due ${it.take(10)}" }).joinToString(" · "), MaterialTheme.hub.textTertiary)
            }
            MoneyText(inv.money("amount"), emphasis = true)
        }
        FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            order["id"].int?.let { oid -> OutlinedButton(onClick = { env.navigator.push(Route.Order(oid)) }) { Text("Open order") } }
            order["id"].str?.let { oid -> DocumentButton("Invoice PDF", "/v1/admin/pdf/orders/$oid/invoice", suggestedName = "invoice-$oid.pdf") }
            val token = order["pay_token"].str
            if (docStatus != "void" && order["payment_status"].str != "paid" && payable && token != null) SalesCopyButton("Copy pay link", "${env.config.hubUrl.trimEnd('/')}/pay/$token", "Pay link copied")
            else if (docStatus != "void" && orderDead) SalesCaption("Not payable — the order is ${orderStatus ?: "closed"}.", MaterialTheme.hub.textTertiary)
            if (canVoid) OutlinedButton(onClick = onVoid) { Text("Void…", color = Palette.danger) }
        }
    }
}

/** POST /invoices/{id}/void {reason} — owner.only + step-up (the client runs the step-up itself). */
@Composable
private fun VoidInvoiceSheet(invoice: Record, onDone: () -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var reason by remember { mutableStateOf("") }
    FormSheet("Void ${invoice.string("invoice_number") ?: "invoice"}", onDismiss, saveLabel = "Void invoice", canSave = reason.isNotBlank(), destructive = true, save = {
        val r = env.client.post("/v1/admin/invoices/${invoice.id}/void", jsonOf("reason" to reason))
        env.toasts.show(r.json["message"].str ?: "Invoice voided")
        onDone()
    }) { error ->
        SalesSecondary("Voids the unpaid invoice, cancels its order and voids its quotation. You will be asked to confirm your identity.")
        Field("Reason (required)", reason, { reason = it }, multiline = true, error = error?.field("reason"))
        SalesFieldErrors(error)
    }
}

// ── Interest carts (GET /admin/interest-carts — read-only pipeline) ────────

@Composable
fun InterestCartsScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var q by remember { mutableStateOf("") }
    var status by remember { mutableStateOf("") }
    var channel by remember { mutableStateOf("") }
    val list = remember { PagedList(scope, env.client, "/v1/admin/interest-carts", map = ::Record) }
    fun reload() { list.query = mutableMapOf("q" to q.ifEmpty { null }, "status" to status.ifEmpty { null }, "channel" to channel.ifEmpty { null }); list.load() }
    LaunchedEffect(status, channel) { reload() }

    Page("Interest Carts", subtitle = "Pipeline, not sales — the BH-XXXX tokens Neema hands customers", refresh = { list.reload() }) {
        ModuleSearchField(q, { q = it }, "Token (BH-0QVP2358), name or phone", onSubmit = ::reload)
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            OptionPicker("Status", status, Sales.interestCartStatuses, { status = it }, Modifier.weight(1f), allowNone = true)
            OptionPicker("Channel", channel, Sales.interestCartChannels.map { it to it.replaceFirstChar { c -> c.uppercase() } }, { channel = it }, Modifier.weight(1f), allowNone = true)
        }
        LoadableView(list.state, retry = { list.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("No carts found", "Check the code for typos — tokens look like BH-0QVP2358. Carts started before this ledger went live were never captured.", Icons.Default.RemoveShoppingCart) }) { rows ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                rows.forEachIndexed { i, r ->
                    InterestCartRow(r)
                    if (i == rows.lastIndex && list.hasMore) LaunchedEffect(rows.size) { list.loadMore() }
                }
                SalesLoadMore(list)
            }
        }
    }
}

@Composable
private fun InterestCartRow(r: Record) {
    val items = r["items"].records
    val st = r.status
    SectionCard {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(r.string("token") ?: "", style = MaterialTheme.typography.bodyMedium.copy(fontFamily = androidx.compose.ui.text.font.FontFamily.Monospace), fontWeight = FontWeight.Bold)
                    StatusBadge(Sales.interestCartStatuses.firstOrNull { it.first == st }?.second ?: st, if (st == "abandoned") StatusTone.NEUTRAL else if (st?.endsWith("_order") == true) StatusTone.SUCCESS else StatusTone.AMBER)
                    StatusBadge(r.string("channel"), StatusTone.INFO)
                }
                SalesSecondary(listOfNotNull(r.string("name"), r.string("phone"), r.string("church")).joinToString(" · "), MaterialTheme.hub.text)
                items.forEach { it ->
                    val size = it["size"].str?.let { s -> " ($s)" } ?: ""
                    val slug = (it["slug"].str ?: "").replace("-", " ").split(" ").joinToString(" ") { w -> w.replaceFirstChar { c -> c.uppercase() } }
                    SalesCaption("${it["quantity"].str ?: "1"} × $slug$size")
                }
                r.string("order_ref")?.let { SalesCaption("Order $it", Palette.success) }
            }
            Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                val cur = r.string("currency")
                if (cur != null && !r["subtotal"].isNull) MoneyText(Money.from(r["subtotal"], cur))
                DateText(r.date("updated_at"), DateStyle.RELATIVE)
            }
        }
    }
}
