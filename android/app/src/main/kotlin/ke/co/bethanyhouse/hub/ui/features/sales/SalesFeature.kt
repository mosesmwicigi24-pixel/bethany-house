package ke.co.bethanyhouse.hub.ui.features.sales

import android.content.Context
import android.content.Intent
import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.AttachFile
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.ContentCopy
import androidx.compose.material.icons.filled.OpenInNew
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalClipboardManager
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.obj
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.components.DetailRow
import ke.co.bethanyhouse.hub.ui.components.FieldError
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.PagedList
import ke.co.bethanyhouse.hub.ui.components.PickedFile
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.readPicked
import ke.co.bethanyhouse.hub.ui.hub
import ke.co.bethanyhouse.hub.ui.shell.FeatureRegistry
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.jsonObject
import java.math.BigDecimal

/**
 * Sales: orders (per channel), the pending queue, order detail, quotations,
 * invoices, interest carts, shipments, customers and outstanding balances.
 *
 * Every endpoint, query key and payload key here was read from
 * backend/routes/api.php and the controllers it names (ported from the Apple
 * SalesFeature); permissions are the slugs those routes check.
 * `Route.Handoff(id)` stays on the console bridge and is not registered.
 * Money is shown as the server sent it (`Record.money`); nothing is summed,
 * converted or rounded on the device.
 */
object SalesFeature {
    fun register() {
        FeatureRegistry.register({ it is Route.Orders }) { r -> OrdersListScreen((r as Route.Orders).channel) }
        FeatureRegistry.register(Route.PendingQueue) { PendingQueueScreen() }
        FeatureRegistry.registerType<Route.Order> { r -> OrderDetailScreen(r.id) }
        FeatureRegistry.register(Route.Quotations) { QuotationsScreen() }
        FeatureRegistry.register(Route.Invoices) { InvoicesScreen() }
        FeatureRegistry.register(Route.InterestCarts) { InterestCartsScreen() }
        FeatureRegistry.register(Route.Shipments) { ShipmentsListScreen() }
        FeatureRegistry.registerType<Route.Shipment> { r -> ShipmentDetailScreen(r.id) }
        FeatureRegistry.register(Route.Customers) { CustomersListScreen() }
        FeatureRegistry.registerType<Route.Customer> { r -> CustomerDetailScreen(r.id) }
        FeatureRegistry.register(Route.Balances) { OutstandingBalancesScreen() }
    }
}

// ── Shared rules and helpers ─────────────────────────────────────────────────

internal object Sales {
    /** App\Support\OrderStatusMachine::TRANSITIONS — the only legal moves. */
    val transitions: Map<String, List<String>> = mapOf(
        "pending" to listOf("processing", "confirmed", "shipped", "delivered", "completed", "cancelled"),
        "processing" to listOf("confirmed", "shipped", "delivered", "completed", "cancelled"),
        "confirmed" to listOf("processing", "shipped", "delivered", "completed", "cancelled"),
        "shipped" to listOf("delivered", "completed", "cancelled"),
        "delivered" to listOf("completed", "refunded"),
        "completed" to listOf("refunded"),
        "cancelled" to emptyList(),
        "refunded" to emptyList(),
    )

    fun nextStatuses(status: String?): List<String> = status?.let { transitions[it.lowercase()] } ?: emptyList()

    /** An order that takes no more money (Order::isPayable is the server's word). */
    val deadStatuses = setOf("cancelled", "voided", "refunded")
    fun isDead(status: String?): Boolean = (status?.lowercase() ?: "") in deadStatuses

    /** Shipment stages, in the order ShipmentController::STATUS_LABELS lists them. */
    val shipmentStatuses = listOf("order_confirmed", "processing", "ready_to_ship", "picked_up", "in_transit", "out_for_delivery", "delivery_attempted", "delivered", "exception", "cancelled")

    val interestCartStatuses: List<Pair<String, String>> = listOf(
        "active_cart" to "Active cart", "checkout_started" to "Checkout started", "online_order" to "Ordered online",
        "whatsapp_order" to "Ordered on WhatsApp", "abandoned" to "Abandoned",
    )
    val interestCartChannels = listOf("web", "whatsapp", "messenger", "instagram", "facebook")

    /** CustomerContactRevealController::REASONS. */
    val revealReasons: List<Pair<String, String>> = listOf(
        "payment_follow_up" to "Payment follow-up", "delivery" to "Delivery",
        "customer_callback" to "Customer callback", "order_issue" to "Order issue",
    )

    /** A value holding the mask character was masked by contacts.mask (CustomerContacts::MASK_CHAR, never typed by a person). */
    fun isMasked(value: String?): Boolean = value?.contains("•") == true

    /** Neema's inbox, keyed on the order number first (lib/neema.ts). */
    private const val NEEMA_BASE = "https://neema.bethanyhouse.co.ke"
    fun chatUrl(phone: String?, orderNumber: String?): String? {
        val num = waNumber(phone)
        val ref = (orderNumber ?: "").trim()
        if (num == null && ref.isEmpty()) return null
        val b = Uri.parse("$NEEMA_BASE/dashboard").buildUpon().appendQueryParameter("open", num ?: ref)
        if (ref.isNotEmpty()) b.appendQueryParameter("ref", ref)
        return b.build().toString()
    }

    /** lib/whatsapp.ts waNumber: 07… → 2547…; masked values never qualify. */
    fun waNumber(phone: String?): String? {
        if (phone == null || isMasked(phone)) return null
        var d = phone.filter { it.isDigit() }
        if (d.startsWith("0")) d = "254" + d.drop(1) else if (d.length == 9) d = "254$d"
        return if (d.length >= 11) d else null
    }

    fun name(first: String?, last: String?): String? {
        val n = listOfNotNull(first, last).joinToString(" ").trim()
        return n.ifEmpty { null }
    }

    /**
     * A typed amount goes to the server verbatim (Laravel `numeric` accepts the
     * decimal string); the device never turns it into a float.
     */
    fun amountJson(text: String): JsonElement? {
        val t = text.trim().replace(",", "")
        if (t.isEmpty()) return null
        runCatching { BigDecimal(t) }.getOrNull() ?: return null
        return JsonPrimitive(t)
    }

    fun optionalString(text: String): JsonElement { val t = text.trim(); return if (t.isEmpty()) JsonNull else JsonPrimitive(t) }

    fun intOrNull(text: String): Int? = text.trim().toIntOrNull()
}

/** `{data: [...]}` or a bare array, as records. */
internal val JsonElement?.salesRecords: List<Record>
    get() = ((this["data"] as? kotlinx.serialization.json.JsonArray) ?: (this as? kotlinx.serialization.json.JsonArray))?.map(::Record) ?: emptyList()

internal val Record.salesCustomerName: String?
    get() = string("customer_name") ?: Sales.name(string("customer_first_name"), string("customer_last_name"))

/** A file read from the person's device, ready to become a multipart part. */
internal data class SalesPickedFile(val id: Long, val file: PickedFile) {
    fun part(name: String): ApiClient.Part = file.part(name)
}

/** A file to upload with its customer-visibility flag. */
internal data class SalesAttachmentDraft(val file: SalesPickedFile, val isPublic: Boolean) { val id: Long get() = file.id }

/** Multipart `attachments[]` + `is_public[]` (ShipmentController::upload*Attachment). */
internal object SalesShipmentUploads {
    suspend fun upload(files: List<SalesAttachmentDraft>, path: String, client: ApiClient) {
        val parts = mutableListOf<ApiClient.Part>()
        files.forEach { parts += it.file.part("attachments[]") }
        files.forEach { parts += ApiClient.Part.field("is_public[]", if (it.isPublic) "true" else "false") }
        client.upload(path, parts)
    }
}

// ── Platform bits (clipboard, external links, multi-file picking) ───────────

internal fun salesOpenUrl(context: Context, url: String) {
    runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
}

/** Copies text and confirms with a toast. */
@Composable
internal fun SalesCopyButton(label: String, text: String, confirmation: String = "Copied", iconOnly: Boolean = false) {
    val env = LocalEnv.current
    val clipboard = LocalClipboardManager.current
    val copy = { clipboard.setText(AnnotatedString(text)); env.toasts.show(confirmation) }
    if (iconOnly || label.isEmpty()) IconButton(onClick = copy) { Icon(Icons.Default.ContentCopy, contentDescription = "Copy", modifier = Modifier.size(16.dp)) }
    else OutlinedButton(onClick = copy) { Icon(Icons.Default.ContentCopy, contentDescription = null, modifier = Modifier.size(16.dp)); Text(" $label") }
}

/** Opens an external link (Neema, carrier site, payment page) in the browser. */
@Composable
internal fun SalesLinkButton(label: String, url: String, outlined: Boolean = false) {
    val context = LocalContext.current
    if (outlined) OutlinedButton(onClick = { salesOpenUrl(context, url) }) { Icon(Icons.Default.OpenInNew, contentDescription = null, modifier = Modifier.size(16.dp)); Text(" $label") }
    else TextButton(onClick = { salesOpenUrl(context, url) }) { Icon(Icons.Default.OpenInNew, contentDescription = null, modifier = Modifier.size(14.dp)); Text(" $label") }
}

/** Picks one or more files (PDF or image) and hands them over as multipart-ready files. */
@Composable
internal fun SalesFilePickButton(label: String, multiple: Boolean = false, enabled: Boolean = true, busy: Boolean = false, onPicked: (List<SalesPickedFile>) -> Unit) {
    val context = LocalContext.current
    val env = LocalEnv.current
    val read: (List<Uri>) -> Unit = { uris ->
        val files = uris.mapNotNull { uri -> runCatching { readPicked(context, uri) }.onFailure { env.toasts.error(it) }.getOrNull() }
        if (files.isNotEmpty()) onPicked(files.map { SalesPickedFile(System.nanoTime(), it) })
    }
    val many = rememberLauncherForActivityResult(ActivityResultContracts.OpenMultipleDocuments()) { uris -> read(uris) }
    val one = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri -> read(listOfNotNull(uri)) }
    val types = arrayOf("application/pdf", "image/*")
    OutlinedButton(enabled = enabled && !busy, onClick = { if (multiple) many.launch(types) else one.launch(types) }) {
        if (busy) CircularProgressIndicator(Modifier.size(14.dp), strokeWidth = 2.dp) else Icon(Icons.Default.AttachFile, contentDescription = null, modifier = Modifier.size(16.dp))
        Text(" $label")
    }
}

/** Files staged for upload, each with its customer-visibility flag. */
@Composable
internal fun SalesAttachmentDraftList(files: List<SalesAttachmentDraft>, onChange: (List<SalesAttachmentDraft>) -> Unit) {
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text("ATTACHMENTS (WAYBILL, PHOTOS)", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
        files.forEach { f ->
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text(f.file.file.name, style = MaterialTheme.typography.bodySmall, maxLines = 1, modifier = Modifier.weight(1f))
                IconButton(onClick = { onChange(files.filterNot { it.id == f.id }) }) { Icon(Icons.Default.Close, contentDescription = "Remove", tint = MaterialTheme.hub.textTertiary, modifier = Modifier.size(16.dp)) }
            }
            ToggleRow("Customer can see", f.isPublic, { on -> onChange(files.map { if (it.id == f.id) it.copy(isPublic = on) else it }) })
        }
        SalesFilePickButton("Add files", multiple = true) { picked -> onChange(files + picked.map { SalesAttachmentDraft(it, false) }) }
    }
}

// ── List chrome ──────────────────────────────────────────────────────────────

/** "Load more" tail for a [PagedList]; paired with the LaunchedEffect on the last row. */
@Composable
internal fun <T> SalesLoadMore(list: PagedList<T>) {
    if (list.hasMore) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Center) {
            TextButton(enabled = !list.isLoadingMore, onClick = { list.loadMore() }) {
                if (list.isLoadingMore) CircularProgressIndicator(Modifier.size(14.dp), strokeWidth = 2.dp) else Text("Load more (${list.items.size} of ${list.total})")
            }
        }
    } else if (list.total > 0) {
        Text("${list.total} in total", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary, modifier = Modifier.fillMaxWidth().padding(vertical = Space.s))
    }
}

/** The 422 field errors of a [FormSheet] save, listed under the fields (the Apple SheetFrame layout). */
@Composable
internal fun SalesFieldErrors(error: ApiError?) {
    error ?: return
    if (error.errors.isEmpty()) return
    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Text(error.message, style = MaterialTheme.typography.bodySmall, color = Palette.danger)
        error.errors.keys.sorted().forEach { k -> FieldError("${k.humanized}: ${error.errors[k]?.firstOrNull() ?: ""}") }
    }
}

@Composable
internal fun SalesMono(text: String, modifier: Modifier = Modifier) =
    Text(text, style = MaterialTheme.typography.bodyMedium.copy(fontFamily = FontFamily.Monospace), modifier = modifier)

@Composable
internal fun SalesCaption(text: String, color: androidx.compose.ui.graphics.Color? = null, modifier: Modifier = Modifier) =
    Text(text, style = MaterialTheme.typography.labelSmall, color = color ?: MaterialTheme.hub.textSecondary, modifier = modifier)

@Composable
internal fun SalesSecondary(text: String, color: androidx.compose.ui.graphics.Color? = null, modifier: Modifier = Modifier) =
    Text(text, style = MaterialTheme.typography.bodySmall, color = color ?: MaterialTheme.hub.textSecondary, modifier = modifier)

@Composable
internal fun SalesDivider() = HorizontalDivider(color = MaterialTheme.hub.line)

/** Totals as the server computed them, in the order's currency. */
@Composable
internal fun SalesMoneyBreakdown(record: Record) {
    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
        DetailRow("Subtotal") { MoneyText(record.money("subtotal")) }
        record.money("discount_amount")?.takeIf { !it.isZero }?.let { d -> DetailRow("Discount") { MoneyText(d) } }
        DetailRow("Tax") { MoneyText(record.money("tax_amount")) }
        val shipping = record.money("shipping_amount")
        val overridden = record["shipping_fee_overridden"].bool == true
        if (shipping != null && (!shipping.isZero || overridden)) {
            DetailRow("Shipping" + if (overridden) " (set by staff)" else "") { MoneyText(shipping) }
        }
        SalesDivider()
        DetailRow("Total") { MoneyText(record.money("total_amount"), emphasis = true) }
    }
}

// ── Masked contacts and reveal ───────────────────────────────────────────────

internal data class SalesRevealContext(val type: String, val id: Int)

/**
 * A contact the API may have masked (contacts.mask). Shows what came back;
 * when masked and the person may reveal, offers the audited reveal
 * (POST /customers/{id}/reveal — one field, one reason, one sale or shipment).
 */
@Composable
internal fun SalesContactLine(label: String, value: String?, field: String, customerId: Int?, context: SalesRevealContext?) {
    val env = LocalEnv.current
    var revealing by remember { mutableStateOf(false) }
    var revealed by remember { mutableStateOf<String?>(null) }
    DetailRow(label) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(revealed ?: value ?: "—", style = MaterialTheme.typography.bodyMedium)
            if (revealed == null && Sales.isMasked(value) && customerId != null && env.session.current.permissions.canAny(listOf("customers.view", "pos.access"))) {
                IconButton(onClick = { revealing = true }, modifier = Modifier.size(24.dp)) { Icon(Icons.Default.Visibility, contentDescription = "Reveal (audited)", modifier = Modifier.size(16.dp)) }
            }
        }
    }
    if (revealing && customerId != null) {
        SalesRevealSheet(customerId, field, context, onRevealed = { revealed = it }, onDismiss = { revealing = false })
    }
}

@Composable
internal fun SalesRevealSheet(customerId: Int, field: String, context: SalesRevealContext?, onRevealed: (String) -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var reason by remember { mutableStateOf(Sales.revealReasons[0].first) }
    FormSheet("Reveal $field", onDismiss, saveLabel = "Reveal", save = {
        val body = mutableMapOf<String, Any?>("field" to field, "reason" to reason)
        if (context != null) body["context"] = mapOf("type" to context.type, "id" to context.id)
        val r = env.client.post("/v1/admin/customers/$customerId/reveal", ke.co.bethanyhouse.hub.core.toJson(body))
        val v = r.json["value"]
        val text = v.str ?: v.obj?.let { o -> listOf("address_line_1", "address_line_2", "city", "postal_code", "country_code").mapNotNull { o[it].str }.filter { it.isNotEmpty() }.joinToString(", ") } ?: "—"
        onRevealed(text)
    }) { error ->
        SalesSecondary("Every reveal is recorded with your name, the reason and the sale it is for; 20 an hour.")
        OptionPicker("Reason", reason, Sales.revealReasons, { reason = it })
        if (context == null) SalesCaption("Without an open sale or shipment the server allows a reveal only to roles that see contacts in full.", Palette.warning)
        SalesFieldErrors(error)
    }
}
