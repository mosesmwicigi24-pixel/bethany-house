package ke.co.bethanyhouse.hub.ui.features.expenses

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ListAlt
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.rememberModalBottomSheetState
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
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.dbl
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
import ke.co.bethanyhouse.hub.ui.components.BasisCaption
import ke.co.bethanyhouse.hub.ui.components.DataTable
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.DayField
import ke.co.bethanyhouse.hub.ui.components.DecimalField
import ke.co.bethanyhouse.hub.ui.components.DetailRow
import ke.co.bethanyhouse.hub.ui.components.DocumentButton
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.JRow
import ke.co.bethanyhouse.hub.ui.components.LinesChart
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.PagedList
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.StatTile
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.TableColumn
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.TileModifier
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.chartPoints
import ke.co.bethanyhouse.hub.ui.components.fmtMoney
import ke.co.bethanyhouse.hub.ui.components.fmtPct
import ke.co.bethanyhouse.hub.ui.components.jrows
import ke.co.bethanyhouse.hub.ui.components.personName
import ke.co.bethanyhouse.hub.ui.hub
import ke.co.bethanyhouse.hub.ui.shell.FeatureRegistry
import kotlinx.coroutines.delay
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonPrimitive
import java.time.Instant

/**
 * Finance: the payment transaction ledger (payments.transactions).
 * Endpoints verified against PaymentController (payment-transactions prefix)
 * and react-admin/src/api/transactions.ts.
 */
object FinanceFeature {
    fun register() {
        FeatureRegistry.register(Route.PaymentTransactions) { PaymentTransactionsScreen() }
    }
}

internal object PaymentVocab {
    val methods = listOf("mpesa" to "M-Pesa", "card_paystack" to "Card (Paystack)", "card_flutterwave" to "Card (Flutterwave)", "cash" to "Cash", "bank_transfer" to "Bank Transfer", "cheque" to "Cheque", "manual" to "Manual")
    val statuses = listOf("paid", "pending", "failed", "refunded", "partially_refunded", "voided")
    fun methodLabel(v: String?): String = methods.firstOrNull { it.first == v }?.second ?: (v ?: "—").humanized
}

internal class PaymentRow(json: JsonElement) {
    val record = Record(json)
    val id: String get() = record.id
    val number: String get() = record.string("payment_number") ?: "#$id"
    val status: String get() = record.status ?: "pending"
    val method: String? get() = record.string("payment_method")
    val currency: String get() = record.string("currency_code") ?: "KES"
    val amount: Money? get() = record.money("amount", currency)
    val refund: Money? get() = record["refund_amount"]?.takeIf { !it.isNull }?.let { Money.from(it, currency) }
    val order: JsonElement? get() = record["order"]?.takeIf { !it.isNull }
    val customer: String? get() = order?.let { o -> listOfNotNull(o["customer_first_name"].str, o["customer_last_name"].str).filter { it.isNotEmpty() }.joinToString(" ") }?.takeIf { it.isNotEmpty() }
    val requiresApproval: Boolean get() = record["requires_approval"].bool ?: false
    val approvalStatus: String? get() = record.string("approval_status")
    val createdAt: Instant? get() = record.date("created_at")
    val paidAt: Instant? get() = record.date("paid_at")
}

@Composable
fun PaymentTransactionsScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var search by remember { mutableStateOf("") }
    var status by remember { mutableStateOf("") }
    var method by remember { mutableStateOf("") }
    var currency by remember { mutableStateOf("") }
    var minAmount by remember { mutableStateOf("") }
    var maxAmount by remember { mutableStateOf("") }
    var approvalOnly by remember { mutableStateOf(false) }
    var preset by remember { mutableStateOf(ExpensesDatePreset.THIS_MONTH) }
    var customStart by remember { mutableStateOf(Day.today()) }
    var customEnd by remember { mutableStateOf(Day.today()) }
    var showFilters by remember { mutableStateOf(false) }
    var selected by remember { mutableStateOf<String?>(null) }
    val window = ExpensesDateWindow(preset, customStart, customEnd, null)

    val query: Map<String, String?> = mapOf(
        "search" to search.ifEmpty { null }, "status" to status.ifEmpty { null }, "payment_method" to method.ifEmpty { null },
        "currency_code" to currency.ifEmpty { null }, "min_amount" to minAmount.ifEmpty { null }, "max_amount" to maxAmount.ifEmpty { null },
        "requires_approval" to if (approvalOnly) "1" else null, "start_date" to window.startString, "end_date" to window.endString,
    )
    val queryKey = query.entries.sortedBy { it.key }.joinToString("&") { "${it.key}=${it.value ?: ""}" }
    val list = remember { PagedList(scope, env.client, "/v1/admin/payment-transactions", map = ::PaymentRow) }
    val analyticsQuery = mapOf("start_date" to window.startString, "end_date" to window.endString, "currency_code" to currency.ifEmpty { null })
    val analytics = remember(analyticsQuery) { Resource(scope) { env.client.get("/v1/admin/payment-transactions/analytics", analyticsQuery).json ?: JsonNull } }
    LaunchedEffect(queryKey) { list.query.clear(); list.query.putAll(query); list.reload() }
    LaunchedEffect(analytics) { analytics.load() }

    Page("Payment Transactions", subtitle = "${window.description} · money truth: payments as recorded", refresh = { list.reload(); analytics.reload() }) {
        // Filters
        Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
            var typed by remember { mutableStateOf(search) }
            ModuleSearchField(typed, { typed = it }, "Search reference, order, customer", onSubmit = { search = typed.trim() })
            Row(verticalAlignment = Alignment.CenterVertically) {
                SelectField("Period", preset, ExpensesDatePreset.options, { preset = it ?: ExpensesDatePreset.THIS_MONTH }, Modifier.weight(1f), placeholder = "This month")
                Spacer(Modifier.width(Space.s))
                TextButton(onClick = { showFilters = !showFilters }) { Text(if (showFilters) "Fewer filters" else "More filters") }
            }
            if (preset == ExpensesDatePreset.CUSTOM) Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                DayField("From", customStart, { if (it.isNotEmpty()) customStart = it }, Modifier.weight(1f))
                DayField("To", customEnd, { if (it.isNotEmpty()) customEnd = it }, Modifier.weight(1f))
            }
            if (showFilters) {
                SelectField("Status", status.ifEmpty { null }, PaymentVocab.statuses.map { it to it.humanized }, { status = it ?: "" }, placeholder = "All statuses")
                SelectField("Method", method.ifEmpty { null }, PaymentVocab.methods, { method = it ?: "" }, placeholder = "All methods")
                var curTyped by remember { mutableStateOf(currency) }
                Field("Currency (e.g. USD)", curTyped, { curTyped = it.uppercase(); if (curTyped.length == 3 || curTyped.isEmpty()) currency = curTyped })
                Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    var minTyped by remember { mutableStateOf(minAmount) }
                    var maxTyped by remember { mutableStateOf(maxAmount) }
                    DecimalField("Min amount", minTyped, { minTyped = it }, Modifier.weight(1f))
                    DecimalField("Max amount", maxTyped, { maxTyped = it }, Modifier.weight(1f))
                    TextButton(onClick = { minAmount = minTyped; maxAmount = maxTyped }) { Text("Apply") }
                }
                ToggleRow("Needs approval", approvalOnly, { approvalOnly = it })
            }
        }
        // Analytics header (server figures; basis stated)
        val cur = currency.ifEmpty { "KES" }.uppercase()
        LoadableView(analytics.state, retry = { analytics.load() }) { a ->
            TileGrid {
                StatTile("Paid volume", fmtMoney(a["paid_volume"], cur), hint = "${a["paid_count"].int ?: 0} paid", modifier = TileModifier)
                StatTile("All recorded", fmtMoney(a["total_volume"], cur), hint = "${a["total_count"].int ?: 0} transactions", modifier = TileModifier)
                StatTile("Refunded", fmtMoney(a["refunded_volume"], cur), hint = "${a["refunded_count"].int ?: 0} transactions", modifier = TileModifier)
                StatTile("Average", fmtMoney(a["avg_transaction"], cur), modifier = TileModifier)
                StatTile("Success rate", fmtPct(a["success_rate"].dbl), hint = "${a["failed_count"].int ?: 0} failed · ${a["pending_count"].int ?: 0} pending", tone = if ((a["failed_count"].int ?: 0) > 0) StatusTone.WARNING else null, modifier = TileModifier)
            }
            BasisCaption(if (currency.isEmpty()) "Basis: KES at the reporting rate; payments in a currency without a reporting rate are not in these totals. Filter by currency to see it natively." else "Basis: $cur natively, this currency only.")
            if (a["daily"].records.size > 1) SectionCard("Daily paid volume") { LinesChart(chartPoints(a["daily"], label = "date", y = "volume", series = "Paid"), currency = cur) }
            if (a["by_method"].records.isNotEmpty()) SectionCard("By method") {
                DataTable(columns = listOf(TableColumn("Method", "payment_method", TableColumn.Kind.STATUS), TableColumn("Count", "count", TableColumn.Kind.INT, width = 70.dp), TableColumn("Paid volume", "volume", TableColumn.Kind.MONEY, fixedCurrency = cur)), rows = jrows(a["by_method"]))
            }
        }
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Text("${list.total} transaction${if (list.total == 1) "" else "s"}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary, modifier = Modifier.weight(1f))
            DocumentButton("Export CSV", "/v1/admin/payment-transactions/export", query, "transactions-${window.startString}-${window.endString}.csv")
        }
        LoadableView(list.state, retry = { list.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("No transactions match", icon = Icons.AutoMirrored.Filled.ListAlt) }) { rows ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                rows.forEachIndexed { i, p ->
                    PaymentListRow(p) { selected = p.id }
                    if (i == rows.lastIndex && list.hasMore) LaunchedEffect(rows.size) { list.loadMore() }
                }
            }
        }
    }
    selected?.let { id -> PaymentDetailSheet(id, onDismiss = { selected = null }) { list.load() } }
}

@Composable
private fun PaymentListRow(p: PaymentRow, onClick: () -> Unit) {
    Surface(shape = MaterialTheme.shapes.small, color = MaterialTheme.hub.card, modifier = Modifier.fillMaxWidth().border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.small).clickable(onClick = onClick)) {
        Row(Modifier.padding(Space.m), verticalAlignment = Alignment.Top) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(p.number, style = MaterialTheme.typography.bodySmall.copy(fontFamily = FontFamily.Monospace))
                    StatusBadge(p.status)
                    if (p.requiresApproval && p.approvalStatus == "pending_review") StatusBadge("awaiting approval", StatusTone.WARNING)
                }
                val who = listOfNotNull(p.order["order_number"].str, p.customer).joinToString(" · ")
                if (who.isNotEmpty()) Text(who, style = MaterialTheme.typography.bodyMedium)
                Text(PaymentVocab.methodLabel(p.method), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                DateText(p.paidAt ?: p.createdAt)
            }
            Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                MoneyText(p.amount, emphasis = true)
                p.refund?.takeIf { !it.isZero }?.let { Text("−${it.formatted()} refunded", style = MaterialTheme.typography.labelSmall, color = Palette.danger) }
            }
        }
    }
}

private enum class PaymentMode { VOID, REASSIGN, REFUND }

/**
 * GET payment-transactions/{id} → `{payment}` with order and approvedBy;
 * refund (orders.refund), void request, reassign request (Phase 3B: requests
 * that finance executes once the approval engine has its signatures).
 */
@Composable
private fun PaymentDetailSheet(id: String, onDismiss: () -> Unit, changed: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val resource = remember(id) { Resource(scope) { env.client.get("/v1/admin/payment-transactions/$id").json ?: JsonNull } }
    LaunchedEffect(resource) { resource.load() }
    var mode by remember { mutableStateOf<PaymentMode?>(null) }
    var reason by remember { mutableStateOf("") }
    var amount by remember { mutableStateOf("") }
    var orderQuery by remember { mutableStateOf("") }
    var orderResults by remember { mutableStateOf<List<JRow>>(emptyList()) }
    var orderId by remember { mutableStateOf<Int?>(null) }
    var orderLabel by remember { mutableStateOf("") }
    LaunchedEffect(orderQuery) {
        if (orderQuery.length < 3) { orderResults = emptyList(); return@LaunchedEffect }
        delay(300)
        runCatching { env.client.get("/v1/admin/orders", mapOf("search" to orderQuery, "per_page" to "8"), background = true) }.getOrNull()?.let { orderResults = jrows(it.json["data"]) }
    }

    suspend fun submit(m: PaymentMode) {
        val path = when (m) { PaymentMode.REFUND -> "refund"; PaymentMode.VOID -> "void-request"; PaymentMode.REASSIGN -> "reassign-request" }
        val body = J.obj(
            "reason" to JsonPrimitive(reason.trim()),
            "amount" to if (m == PaymentMode.REFUND) J.decimal(amount) else null,
            "order_id" to if (m == PaymentMode.REASSIGN) orderId?.let { JsonPrimitive(it) } else null,
        )
        try {
            val r = env.client.post("/v1/admin/payment-transactions/$id/$path", body)
            env.toasts.show(r.json["message"].str ?: "Done")
            mode = null; reason = ""; amount = ""
            resource.load(); changed()
        } catch (e: ApiError) { env.toasts.error(e) }
    }

    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = Space.l).padding(bottom = Space.xl).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(Space.m)) {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text("Payment", style = MaterialTheme.typography.titleLarge, modifier = Modifier.weight(1f))
                TextButton(onClick = onDismiss) { Text("Close") }
            }
            LoadableView(resource.state, retry = { resource.load() }) { json ->
                val p = PaymentRow(json["payment"] ?: json)
                val s = env.session.current
                val canVoid = s.can("payments.void") || s.can("payments.request_void")
                val canReassign = s.can("payments.reassign") || s.can("payments.request_reassign")
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    Text(p.number, style = MaterialTheme.typography.bodySmall.copy(fontFamily = FontFamily.Monospace))
                    StatusBadge(p.status)
                    Spacer(Modifier.weight(1f))
                    MoneyText(p.amount, emphasis = true)
                }
                DetailRow("Method", PaymentVocab.methodLabel(p.method))
                DetailRow("Provider", listOfNotNull(p.record.string("provider"), p.record.string("provider_transaction_id") ?: p.record.string("provider_reference")).joinToString(" · ").ifEmpty { null })
                DetailRow("Customer", p.customer)
                val o = p.order
                val oid = o["id"].int
                if (o != null && oid != null) {
                    DetailRow("Order") { TextButton(onClick = { onDismiss(); env.navigator.go(Route.Order(oid)) }) { Text(o["order_number"].str ?: "#$oid") } }
                    o["currency_code"].str?.takeIf { it != p.currency }?.let { Text("Order currency $it differs from the payment currency ${p.currency}.", style = MaterialTheme.typography.labelSmall, color = Palette.warning) }
                }
                DetailRow("Recorded") { DateText(p.createdAt) }
                p.paidAt?.let { d -> DetailRow("Paid at") { DateText(d) } }
                p.refund?.takeIf { !it.isZero }?.let { r -> DetailRow("Refunded") { Row(horizontalArrangement = Arrangement.spacedBy(Space.xs)) { MoneyText(r); DateText(p.record.date("refunded_at")) } } }
                if (p.requiresApproval) DetailRow("Approval") { StatusBadge(p.approvalStatus) }
                p.record["approvedBy"]?.takeIf { !it.isNull }?.let { DetailRow("Approved by", personName(it)) }
                if (p.status == "voided") {
                    DetailRow("Void reason", p.record.string("void_reason"))
                    DetailRow("Voided") { DateText(p.record.date("voided_at")) }
                }
                val m = mode
                if (m != null) {
                    Column(Modifier.fillMaxWidth().background(MaterialTheme.hub.field, MaterialTheme.shapes.small).padding(Space.m), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                        when (m) {
                            PaymentMode.REFUND -> {
                                Text("Refund part or all of ${p.amount?.formatted() ?: "this payment"}. The server refuses more than is still refundable.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                                DecimalField("Amount (${p.currency}) *", amount, { amount = it })
                            }
                            PaymentMode.VOID -> Text("A void is a request: nothing happens to the payment until finance approves it (above KES 50,000 the super admin too). It then shows in Approvals.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                            PaymentMode.REASSIGN -> {
                                Text("Moving a payment to another order is a request finance approves. A payment moves only between orders in the same currency (${p.currency}).", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                                Field("Find the order *", orderQuery, { orderQuery = it }, placeholder = "Order number or customer (3+ characters)")
                                if (orderId == null) orderResults.forEach { r ->
                                    val name = listOfNotNull(r.str("customer_first_name"), r.str("customer_last_name")).joinToString(" ")
                                    Row(Modifier.fillMaxWidth().clickable { orderId = r.int("id"); orderLabel = "${r.str("order_number") ?: ""} · $name · ${(r.str("status") ?: "").humanized}"; orderResults = emptyList() }.padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                                        Text(r.str("order_number") ?: "", style = MaterialTheme.typography.bodySmall.copy(fontFamily = FontFamily.Monospace))
                                        Spacer(Modifier.width(Space.s))
                                        Text(name, style = MaterialTheme.typography.labelSmall, modifier = Modifier.weight(1f))
                                        Text(r.str("currency_code") ?: "", style = MaterialTheme.typography.labelSmall)
                                    }
                                } else Row(verticalAlignment = Alignment.CenterVertically) {
                                    Text(orderLabel, style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f))
                                    TextButton(onClick = { orderId = null }) { Text("Change") }
                                }
                            }
                        }
                        Field("Reason *", reason, { reason = it }, multiline = true)
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End, verticalAlignment = Alignment.CenterVertically) {
                            TextButton(onClick = { mode = null }) { Text("Cancel") }
                            AsyncButton(
                                if (m == PaymentMode.REFUND) "Record refund" else "Send for approval", destructive = m == PaymentMode.REFUND,
                                enabled = reason.isNotBlank() && (m != PaymentMode.REFUND || J.decimal(amount) != null) && (m != PaymentMode.REASSIGN || orderId != null),
                            ) { submit(m) }
                        }
                    }
                } else FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                    if (p.status == "paid" && s.can("orders.refund")) OutlinedButton(onClick = { mode = PaymentMode.REFUND }) { Text("Refund…") }
                    if (p.status != "voided" && canVoid) OutlinedButton(onClick = { mode = PaymentMode.VOID }) { Text("Request void…") }
                    if (p.status != "voided" && canReassign) OutlinedButton(onClick = { mode = PaymentMode.REASSIGN }) { Text("Request reassign…") }
                }
            }
        }
    }
}
