package ke.co.bethanyhouse.hub.ui.features.pos

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Print
import androidx.compose.material.icons.filled.Verified
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
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
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.core.date
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.money
import ke.co.bethanyhouse.hub.core.obj
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.DayField
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.StatTile
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.TileModifier
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive

/**
 * The cashier's End of Day report (UserEodModal.tsx): the day's orders
 * with a note each, the day in her words, submitted before the till can be
 * counted; management's acknowledgement and the comment thread below it.
 *
 * docs/NATIVE_APPS_CHECKLIST.md F5: `pos/reports/user-eod` totals carry no
 * currency key; they are labelled in the outlet's currency.
 */
@Composable
internal fun UserEodSheet(
    session: PosSession,
    outletId: Int,
    outletName: String,
    registerId: Int,
    /** Pinned by the morning check to the day the stale till opened. */
    fixedDate: String?,
    onDismiss: () -> Unit,
    onSubmitted: () -> Unit,
) {
    val env = LocalEnv.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var pickedDay by remember { mutableStateOf(Day.today()) }
    val day = fixedDate ?: pickedDay
    var summary by remember { mutableStateOf<Resource<JsonElement>?>(null) }
    val notes = remember { mutableStateMapOf<String, String>() }
    var sentiments by remember { mutableStateOf("") }
    var reply by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var hydratedFor by remember { mutableStateOf<String?>(null) }
    val currency = session.outlet?.currencyCode ?: "KES"

    fun make() {
        val d = day
        summary = Resource(scope) { session.api.userEod(outletId, d) }.also { it.load() }
    }
    LaunchedEffect(day) { make() }

    val value = summary?.value
    val existing: JsonElement? = value["existing_report"]?.takeUnless { it.isNull }
    val loadedDate = value["date"].str
    LaunchedEffect(loadedDate, value) {
        if (loadedDate != null && hydratedFor != loadedDate) {
            hydratedFor = loadedDate
            notes.clear()
            existing["order_notes"].obj?.entries?.forEach { (k, v) -> v.str?.let { notes[k] = it } }
            sentiments = existing["sentiments"].str ?: ""
        }
    }

    suspend fun save() {
        busy = true
        try {
            val r = session.api.saveUserEod(jsonOf(
                "outlet_id" to outletId, "date" to day, "register_id" to registerId,
                "order_notes" to JsonObject(notes.filterValues { it.isNotBlank() }.mapValues { JsonPrimitive(it.value) }),
                "sentiments" to sentiments,
            ))
            env.toasts.show(r["message"].str ?: "End of Day report saved")
            hydratedFor = null
            summary?.reload()
            onSubmitted()
        } catch (e: Exception) { env.toasts.error(e) } finally { busy = false }
    }

    suspend fun sendReply(e: JsonElement) {
        val id = e["id"].int ?: return
        busy = true
        try { session.api.addEodComment(id, reply.trim()); reply = ""; hydratedFor = null; summary?.reload() } catch (ex: Exception) { env.toasts.error(ex) } finally { busy = false }
    }

    fun printReport() {
        val s = value ?: return
        fun m(v: JsonElement?) = v?.let { Money.from(it, currency) }?.formatted() ?: "—"
        val h = StringBuilder("<html><body style='font-family:Helvetica,sans-serif;font-size:12px'><h2>End of Day report</h2>")
        h.append("<div>${ReceiptHtml.esc(outletName)} · ${ReceiptHtml.esc(day)} · ${ReceiptHtml.esc(s["user_name"].str ?: "")}</div>")
        h.append("<p>Orders ${s["order_count"].int ?: 0} · Sales ${m(s["total_sales"])} · Paid ${m(s["total_paid"])} · Balance ${m(s["total_balance"])}</p><table width='100%' border='0'>")
        for (o in s["orders"].records) {
            val note = notes[o["id"].str ?: ""] ?: ""
            h.append("<tr><td>${ReceiptHtml.esc(o["order_number"].str ?: "")}</td><td>${ReceiptHtml.esc(o["customer_name"].str ?: "")}</td><td align='right'>${m(o["total_amount"])}</td><td>${ReceiptHtml.esc(o["payment_status"].str ?: "")}</td></tr>")
            if (note.isNotEmpty()) h.append("<tr><td colspan='4'><i>${ReceiptHtml.esc(note)}</i></td></tr>")
        }
        h.append("</table><h3>How the day went</h3><p>${ReceiptHtml.esc(sentiments)}</p></body></html>")
        PosPrint.printHtml(context, h.toString(), "EoD $day")
    }

    PosSheet("End of Day report", subtitle = outletName, onDismiss = onDismiss, actions = {
        TextButton(onClick = onDismiss) { Text("Close") }
        Button(onClick = { scope.launch { save() } }, enabled = !busy && value != null) {
            if (busy) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp) else Text(if (existing == null) "Submit report" else "Update report")
        }
    }) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            if (fixedDate == null) DayField("Day", pickedDay, { if (it.isNotEmpty() && it <= Day.today()) pickedDay = it }, allowClear = false)
            else Text(day, style = MaterialTheme.typography.bodySmall)
            Spacer(Modifier.weight(1f))
            existing?.let { e ->
                StatusBadge(if (!e["acknowledged_at"].isNull) "acknowledged" else if (!e["submitted_at"].isNull) "submitted" else "draft")
            }
            OutlinedButton(onClick = { printReport() }, enabled = value != null) { Icon(Icons.Default.Print, contentDescription = null, modifier = Modifier.size(16.dp)); Text(" Print") }
        }
        val res = summary
        if (res != null) LoadableView(res.state, retry = { res.load() }) { s ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.m)) {
                TileGrid(minWidth = 140.dp) {
                    StatTile("Orders", s["order_count"].str ?: "0", modifier = TileModifier)
                    StatTile("Sales", Money.from(s["total_sales"], currency)?.formatted() ?: "—", modifier = TileModifier)
                    StatTile("Paid", Money.from(s["total_paid"], currency)?.formatted() ?: "—", modifier = TileModifier)
                    StatTile("Balance", Money.from(s["total_balance"], currency)?.formatted() ?: "—", tone = if (s["total_balance"].money?.isZero == false) StatusTone.WARNING else null, modifier = TileModifier)
                }
                CaptionText("Figures are the Hub's for your orders on this day at this outlet, in $currency.")
                s["orders"].records.forEach { o ->
                    val oid = o["id"].str ?: ""
                    Column(Modifier.fillMaxWidth().background(MaterialTheme.hub.card, MaterialTheme.shapes.small).padding(Space.m), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                            Text(o["order_number"].str ?: "", style = MaterialTheme.typography.bodyMedium)
                            StatusBadge(o["payment_status"].str)
                            Spacer(Modifier.weight(1f))
                            MoneyText(Money.from(o["total_amount"], currency))
                        }
                        Text(
                            "${o["customer_name"].str ?: "Walk-in"} · paid ${Money.from(o["amount_paid"], currency)?.formatted() ?: "—"} · balance ${Money.from(o["balance"], currency)?.formatted() ?: "—"}",
                            style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary,
                        )
                        val items = o["items"].records
                        if (items.isNotEmpty()) CaptionText(items.joinToString(", ") { "${it["quantity"].int ?: 0}× ${it["product_name"].str ?: ""}" })
                        OutlinedTextField(value = notes[oid] ?: "", onValueChange = { notes[oid] = it }, label = { Text("Note on this order (optional)") }, maxLines = 3, modifier = Modifier.fillMaxWidth())
                    }
                }
                Text("How did the day go?", style = MaterialTheme.typography.titleMedium)
                OutlinedTextField(value = sentiments, onValueChange = { sentiments = it }, minLines = 5, modifier = Modifier.fillMaxWidth())
                existing?.let { e ->
                    e["acknowledged_at"].date?.let { ack ->
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Icon(Icons.Default.Verified, contentDescription = null, tint = Palette.success, modifier = Modifier.size(16.dp))
                            Spacer(Modifier.width(Space.s))
                            Text("Read by management", style = MaterialTheme.typography.bodySmall, color = Palette.success)
                            Spacer(Modifier.width(Space.s))
                            DateText(ack)
                        }
                    }
                    val comments = e["comments"].records
                    if (comments.isNotEmpty()) {
                        Text("Conversation", style = MaterialTheme.typography.titleMedium)
                        comments.forEach { c -> EodComment(c) }
                    }
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                        OutlinedTextField(value = reply, onValueChange = { reply = it }, placeholder = { Text("Reply to management") }, singleLine = true, modifier = Modifier.weight(1f))
                        OutlinedButton(onClick = { scope.launch { sendReply(e) } }, enabled = reply.isNotBlank() && !busy) { Text("Send") }
                    }
                }
            }
        }
    }
}

@Composable
internal fun EodComment(c: JsonElement) {
    Column(Modifier.fillMaxWidth().background(MaterialTheme.hub.card, MaterialTheme.shapes.small).padding(Space.s), verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Text(c["user_name"].str ?: "", style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold)
            DateText(c["created_at"].date, DateStyle.RELATIVE)
        }
        Text(c["body"].str ?: "", style = MaterialTheme.typography.bodySmall)
    }
}

// ── Day summary (GET pos/reports/daily — EndOfDayModal.tsx) ─────────────────

@Composable
internal fun DailySummarySheet(session: PosSession, outlet: PosOutlet, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var day by remember { mutableStateOf(Day.today()) }
    var summary by remember { mutableStateOf<Resource<JsonElement>?>(null) }

    /** The daily summary's top-level figures are KES at each order's reporting rate (Step 5, `money_basis`). */
    fun kes(v: JsonElement?): String = v?.let { Money.from(it, "KES") }?.formatted() ?: "—"

    LaunchedEffect(day) {
        val d = day
        summary = Resource(scope) { session.api.dailySummary(outlet.id, d) }.also { it.load() }
    }

    fun printSummary() {
        val s = summary?.value ?: return
        val h = StringBuilder("<html><body style='font-family:Helvetica,sans-serif;font-size:12px'><h2>Day summary · ${ReceiptHtml.esc(outlet.name)} · $day</h2>")
        s["money_basis"].str?.let { h.append("<div>${ReceiptHtml.esc(it)}</div>") }
        h.append("<table>")
        for ((l, k) in listOf("Gross sales" to "total_sales", "Returns" to "total_returns", "Net sales" to "net_sales", "Transactions" to "total_transactions", "Cash" to "cash_sales", "M-Pesa" to "mpesa_sales", "Card" to "card_sales", "Other" to "other_sales")) {
            h.append("<tr><td>$l</td><td align='right'>${if (k == "total_transactions") (s[k].str ?: "0") else kes(s[k])}</td></tr>")
        }
        h.append("</table></body></html>")
        PosPrint.printHtml(context, h.toString(), "Day summary $day")
    }

    PosSheet("Day summary", subtitle = outlet.name, onDismiss = onDismiss, actions = { TextButton(onClick = onDismiss) { Text("Close") } }) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            DayField("Day", day, { if (it.isNotEmpty() && it <= Day.today()) day = it }, allowClear = false)
            Spacer(Modifier.weight(1f))
            OutlinedButton(onClick = { printSummary() }, enabled = summary?.value != null) { Icon(Icons.Default.Print, contentDescription = null, modifier = Modifier.size(16.dp)); Text(" Print") }
        }
        val res = summary
        if (res != null) LoadableView(res.state, retry = { res.load() }) { s ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.m)) {
                val basis = s["money_basis"].str
                TileGrid(minWidth = 150.dp) {
                    StatTile("Net sales", kes(s["net_sales"]), hint = basis, modifier = TileModifier)
                    StatTile("Transactions", s["total_transactions"].str ?: "0", modifier = TileModifier)
                    StatTile("Avg. transaction", kes(s["average_transaction"]), modifier = TileModifier)
                    StatTile("Returns", kes(s["total_returns"]), tone = if (s["total_returns"].money?.isZero == false) StatusTone.WARNING else null, modifier = TileModifier)
                }
                SectionCard("By tender") {
                    KesRow("Cash", s["cash_sales"]); KesRow("M-Pesa", s["mpesa_sales"]); KesRow("Card", s["card_sales"]); KesRow("Other", s["other_sales"])
                    HorizontalDivider(color = MaterialTheme.hub.line)
                    KesRow("Gross sales", s["total_sales"]); KesRow("Returns", s["total_returns"]); KesRow("Net sales", s["net_sales"])
                }
                val by = s["by_currency"].records
                if (by.isNotEmpty()) SectionCard("By currency (native, with the shilling figure at the reporting rate)") {
                    by.forEach { c ->
                        val code = c["currency_code"].str ?: "?"
                        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                            Text("$code · ${c["count"].int ?: 0} sale${if (c["count"].int == 1) "" else "s"}", style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f))
                            MoneyText(Money.from(c["amount"], code))
                            val k = c["amount_kes"]
                            if (k != null && !k.isNull) Text("= ${Money.from(k, "KES")?.formatted() ?: ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                            else Text("no reporting rate", style = MaterialTheme.typography.labelSmall, color = Palette.warning)
                        }
                    }
                    val u = s["unconverted"].records
                    if (u.isNotEmpty()) CaptionText("Not converted: ${u.mapNotNull { it.str }.joinToString(", ")}", Palette.warning)
                }
                val top = s["top_products"].records
                if (top.isNotEmpty()) SectionCard("Top products") {
                    top.take(8).forEachIndexed { i, p ->
                        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                            Text("${i + 1}. ${p["name"].str ?: ""}", style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f))
                            Text("×${p["qty"].int ?: 0}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                            Text(kes(p["revenue"]), style = MaterialTheme.typography.bodySmall)
                        }
                    }
                }
                val hours = s["hourly_breakdown"].records
                if (hours.isNotEmpty()) SectionCard("By hour") {
                    hours.forEach { h ->
                        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                            Text(String.format(java.util.Locale.US, "%02d:00", h["hour"].int ?: 0), style = MaterialTheme.typography.bodySmall.copy(fontFamily = FontFamily.Monospace), modifier = Modifier.weight(1f))
                            Text("${h["transactions"].int ?: 0} txns", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                            Text(kes(h["sales"]), style = MaterialTheme.typography.bodySmall)
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun KesRow(label: String, v: JsonElement?) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Text(label, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary, modifier = Modifier.weight(1f))
        MoneyText(v?.let { Money.from(it, "KES") })
    }
}
