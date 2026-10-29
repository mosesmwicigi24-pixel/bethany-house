package ke.co.bethanyhouse.hub.ui.features.pos

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.Me
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.core.OfflineStore
import ke.co.bethanyhouse.hub.core.Omit
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.date
import ke.co.bethanyhouse.hub.core.dbl
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.long
import ke.co.bethanyhouse.hub.core.obj
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.shell.FeatureRegistry
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import java.math.BigDecimal
import java.math.RoundingMode
import java.time.Instant
import java.util.UUID

/**
 * Point of sale, tills and end-of-day (console: react-admin/src/pages/pos;
 * Apple: PosFeature*.swift, TillsFeature.swift, EodFeature.swift).
 *
 * Money rules (CLAUDE.md §6–§8): every total on these screens is the
 * server's. The cart holds what the cashier picked; the Hub prices it when
 * the pending order is created (`pos/pending-order`) and that reply is the
 * only figure shown. Nothing is summed, converted or rounded here. Money
 * writes are online-only and carry a `client_request_id` per sale attempt
 * so a retry returns the original sale (PosController::findReplayedOrder).
 */
object PosFeature {
    fun register() {
        FeatureRegistry.register(Route.Pos) { PosRootScreen() }
        TillsFeature.register()
        EodFeature.register()
    }
}

// ── API (paths and payload keys verified against backend/routes/api.php and PosController) ──

internal class PosApi(val client: ApiClient) {
    companion object { const val BASE = "/v1/admin/pos" }

    suspend fun outlets(): List<PosOutlet> = client.get("$BASE/outlets").json["data"].records.mapNotNull(PosOutlet::from)

    suspend fun registerStatus(outletId: Int): JsonElement =
        client.get("$BASE/register/status", mapOf("outlet_id" to outletId.toString())).json ?: JsonNull

    suspend fun openRegister(body: JsonElement): JsonElement = client.post("$BASE/register/open", body).json ?: JsonNull
    suspend fun closeRegister(body: JsonElement): JsonElement = client.post("$BASE/register/close", body).json ?: JsonNull

    suspend fun products(outletId: Int, categoryId: Int?, currency: String?): List<PosProduct> =
        client.get("$BASE/products", mapOf("outlet_id" to outletId.toString(), "category_id" to categoryId?.toString(), "currency" to currency))
            .json["data"].records.mapNotNull(PosProduct::from)

    suspend fun searchProducts(q: String, outletId: Int, currency: String?): List<PosProduct> =
        client.get("$BASE/products/search", mapOf("q" to q, "outlet_id" to outletId.toString(), "currency" to currency))
            .json["data"].records.mapNotNull(PosProduct::from)

    suspend fun searchCustomers(q: String): List<JsonElement> = client.get("$BASE/customers/search", mapOf("q" to q)).json["data"].records

    suspend fun shippingMethods(currency: String?): List<JsonElement> =
        client.get("$BASE/shipping-methods", mapOf("currency" to currency)).json["data"].records

    suspend fun checkoutConfig(): JsonElement = client.get("$BASE/checkout-config").json ?: JsonNull

    /** GET /v1/payment-methods?currency= — the sale-facing list (PaymentMethodController::available). */
    suspend fun paymentMethods(currency: String): List<JsonElement> =
        client.get("/v1/payment-methods", mapOf("currency" to currency)).json["data"].records

    suspend fun openPendingOrder(outletId: Int): JsonElement? {
        val j = client.get("$BASE/pending-order/open", mapOf("outlet_id" to outletId.toString())).json
        return if (j["order_id"].isNull) null else j
    }

    suspend fun createPendingOrder(body: JsonElement): JsonElement = client.post("$BASE/pending-order", body).json ?: JsonNull
    suspend fun updatePendingOrder(id: Int, body: JsonElement): JsonElement = client.patch("$BASE/pending-order/$id", body).json ?: JsonNull
    suspend fun recordPay(id: Int, body: JsonElement): JsonElement = client.post("$BASE/pending-order/$id/pay", body).json ?: JsonNull
    suspend fun recordPay(id: Int, parts: List<ApiClient.Part>): JsonElement = client.upload("$BASE/pending-order/$id/pay", parts).json ?: JsonNull

    suspend fun sales(outletId: Int, query: Map<String, String?>): List<PosSale> =
        client.get("$BASE/sales", query + ("outlet_id" to outletId.toString())).json["data"].records.map(::PosSale)

    suspend fun sale(id: Int): PosSale = PosSale(client.get("$BASE/sales/$id").json["sale"] ?: JsonNull)
    suspend fun requestVoid(id: Int, reason: String): JsonElement = client.post("$BASE/sales/$id/void", jsonOf("reason" to reason)).json ?: JsonNull
    suspend fun emailReceipt(id: Int, email: String): JsonElement = client.post("$BASE/sales/$id/email-receipt", jsonOf("email" to email)).json ?: JsonNull
    suspend fun approvers(approvalId: Int): List<JsonElement> = client.get("$BASE/approvals/$approvalId/approvers").json["data"].records
    suspend fun pinSign(approvalId: Int, body: JsonElement): JsonElement = client.post("$BASE/approvals/$approvalId/pin-sign", body).json ?: JsonNull
    suspend fun processReturn(body: JsonElement): JsonElement = client.post("$BASE/returns", body).json ?: JsonNull

    suspend fun dailySummary(outletId: Int, date: String): JsonElement =
        client.get("$BASE/reports/daily", mapOf("outlet_id" to outletId.toString(), "date" to date)).json["summary"] ?: JsonNull
    suspend fun userEod(outletId: Int, date: String): JsonElement =
        client.get("$BASE/reports/user-eod", mapOf("outlet_id" to outletId.toString(), "date" to date)).json["summary"] ?: JsonNull
    suspend fun saveUserEod(body: JsonElement): JsonElement = client.post("$BASE/reports/user-eod", body).json ?: JsonNull
    suspend fun addEodComment(reportId: Int, body: String): JsonElement =
        client.post("$BASE/reports/eod/$reportId/comments", jsonOf("body" to body)).json ?: JsonNull

    /** Customer contact reveal (CustomerContactRevealController): audited server-side. */
    suspend fun reveal(customerId: Int, field: String, reason: String, orderId: Int?): JsonElement =
        client.post(
            "/v1/admin/customers/$customerId/reveal",
            jsonOf("field" to field, "reason" to reason, "context" to (orderId?.let { jsonOf("type" to "order", "id" to it) } ?: Omit)),
        ).json ?: JsonNull

    // Gateway payments against the pending order (PaymentController).
    suspend fun initiatePayment(orderId: Int, body: JsonElement): JsonElement = client.post("/v1/admin/orders/$orderId/payment", body).json ?: JsonNull
    suspend fun order(id: Int): JsonElement = client.get("/v1/admin/orders/$id", background = true).json["order"] ?: JsonNull

    /**
     * docs/NATIVE_APPS_CHECKLIST.md F2: the console's PaymentModal sends the
     * M-PESA code under `code`, but PaymentController::verifyMpesa validates
     * `transaction_code`. The Swift port sends `transaction_code`; so does this.
     */
    suspend fun verifyMpesa(orderId: Int, paymentId: Int, code: String): JsonElement =
        client.post("/v1/admin/orders/$orderId/payments/$paymentId/verify-mpesa", jsonOf("transaction_code" to code)).json ?: JsonNull
}

/**
 * A typed amount as the person entered it, sent verbatim as a numeric string
 * (Laravel `numeric` accepts it) so no float noise is introduced here.
 */
internal fun amountJson(text: String): JsonElement? {
    val t = text.trim().replace(",", "")
    if (t.isEmpty()) return null
    runCatching { BigDecimal(t) }.getOrNull() ?: return null
    return JsonPrimitive(t)
}

/** "1234.50": a server figure as a plain decimal string, to prefill an amount field. */
internal fun posPlainAmount(m: Money): String = m.amount.setScale(2, RoundingMode.HALF_UP).toPlainString()

internal fun formatCap(cap: Double): String = if (cap == Math.rint(cap)) cap.toInt().toString() else cap.toString()

internal fun jsonInt(v: Int?): JsonElement = v?.let { JsonPrimitive(it) } ?: JsonNull

// ── Models ───────────────────────────────────────────────────────────────────

internal data class PosOutlet(val id: Int, val name: String, val code: String?, val currencyCode: String, val raw: JsonElement) {
    companion object {
        fun from(json: JsonElement): PosOutlet? {
            val id = json["id"].int ?: return null
            val name = json["name"].str ?: return null
            return PosOutlet(id, name, json["code"].str, json["currency_code"].str ?: "KES", json)
        }
    }
}

internal data class MeasurementField(val name: String, val unit: String?, val required: Boolean) {
    companion object {
        fun from(json: JsonElement): MeasurementField? = json["name"].str?.let { MeasurementField(it, json["unit"].str, json["required"].bool ?: false) }
    }
}

internal data class PosVariant(
    val variantId: Int?,
    val productId: Int,
    val sku: String,
    val name: String,
    /** The server's price in `currency`, passed back verbatim as `unit_price`. */
    val price: JsonElement?,
    val salePrice: JsonElement?,
    val priceUnavailable: Boolean,
    val currency: String,
    val stock: Int,
    val isDefault: Boolean,
    val taxRate: Double,
    val taxName: String?,
) {
    val id: String get() = "$productId-${variantId ?: 0}"
    val money: Money? get() = price?.let { Money.from(it, currency) }
    val sellable: Boolean get() = !priceUnavailable && money != null

    companion object {
        fun from(json: JsonElement, productId: Int) = PosVariant(
            variantId = json["id"].int,
            productId = productId,
            sku = json["sku"].str ?: "",
            name = json["variant_name"].str ?: "",
            price = json["price"]?.takeUnless { it.isNull },
            salePrice = json["sale_price"]?.takeUnless { it.isNull },
            priceUnavailable = json["price_unavailable"].bool ?: false,
            currency = json["currency"].str ?: "KES",
            stock = json["stock"].int ?: 0,
            isDefault = json["is_default"].bool ?: false,
            taxRate = json["tax_rate"].dbl ?: 0.0,
            taxName = json["tax_name"].str,
        )
    }
}

internal data class PosProduct(
    val id: Int,
    val name: String,
    val sku: String,
    val isProducible: Boolean,
    val measurements: List<MeasurementField>,
    val categoryId: Int?,
    val categoryName: String?,
    val imageUrl: String?,
    val variants: List<PosVariant>,
) {
    val defaultVariant: PosVariant? get() = variants.firstOrNull { it.isDefault } ?: variants.firstOrNull()

    companion object {
        fun from(json: JsonElement): PosProduct? {
            val id = json["id"].int ?: return null
            val name = json["name"].str ?: return null
            return PosProduct(
                id = id, name = name,
                sku = json["sku"].str ?: "",
                isProducible = json["is_producible"].bool ?: false,
                measurements = json["measurements"].records.mapNotNull(MeasurementField::from),
                categoryId = json["category"]["id"].int,
                categoryName = json["category"]["name"].str,
                imageUrl = json["image_url"].str,
                variants = json["variants"].records.map { PosVariant.from(it, id) },
            )
        }
    }
}

/**
 * A cart line as the cashier built it. `unitPrice` is the server's figure
 * for the variant (or a price the cashier typed, which the server checks
 * against the discount rule); it is sent back, never recomputed.
 */
internal data class CartLine(
    val id: String = UUID.randomUUID().toString(),
    val productId: Int,
    val variantId: Int?,
    val productName: String,
    val variantName: String,
    val sku: String,
    val unitPrice: JsonElement,
    val currency: String,
    val quantity: Int,
    val discountType: String = "none",   // none | flat | percent
    val discountValue: String = "0",
    val isProduction: Boolean = false,
    val productionNotes: String = "",
    val measurementFields: List<MeasurementField> = emptyList(),
    val measurementValues: Map<String, String> = emptyMap(),
    val imageUrl: String? = null,
    val taxRate: Double = 0.0,
    val stock: Int = 0,
) {
    val unitMoney: Money? get() = Money.from(unitPrice, currency)
    val title: String get() = if (variantName.isEmpty() || variantName.lowercase() == "default") productName else "$productName · $variantName"

    val payload: JsonObject
        get() = jsonOf(
            "variant_id" to jsonInt(variantId),
            "product_id" to productId,
            "quantity" to quantity,
            "unit_price" to unitPrice,
            "discount_type" to discountType,
            "discount_value" to (amountJson(discountValue) ?: JsonPrimitive(0)),
        )

    val productionPayload: JsonObject
        get() = jsonOf(
            "variant_id" to jsonInt(variantId),
            "product_id" to productId,
            "quantity" to quantity,
            "unit_price" to unitPrice,
            "production_notes" to (productionNotes.ifEmpty { null } ?: Omit),
            "measurement_values" to (if (measurementValues.isEmpty()) Omit else JsonObject(measurementValues.mapValues { JsonPrimitive(it.value) })),
        )

    /** Draft persistence (OfflineStore): the line as built, restored on relaunch. */
    fun toDraft(): JsonObject = jsonOf(
        "id" to id, "product_id" to productId, "variant_id" to jsonInt(variantId), "product_name" to productName, "variant_name" to variantName,
        "sku" to sku, "unit_price" to unitPrice, "currency" to currency, "quantity" to quantity, "discount_type" to discountType,
        "discount_value" to discountValue, "is_production" to isProduction, "production_notes" to productionNotes,
        "measurement_fields" to JsonArray(measurementFields.map { jsonOf("name" to it.name, "unit" to it.unit, "required" to it.required) }),
        "measurement_values" to JsonObject(measurementValues.mapValues { JsonPrimitive(it.value) }),
        "image_url" to imageUrl, "tax_rate" to taxRate, "stock" to stock,
    )

    companion object {
        fun from(product: PosProduct, variant: PosVariant, production: Boolean) = CartLine(
            productId = product.id, variantId = variant.variantId, productName = product.name, variantName = variant.name, sku = variant.sku,
            unitPrice = variant.price ?: JsonPrimitive(0), currency = variant.currency, quantity = 1, isProduction = production,
            measurementFields = product.measurements, imageUrl = product.imageUrl, taxRate = variant.taxRate, stock = variant.stock,
        )

        /** From a saved sale's line (transformSaleOrder) when the order is restored to the cart. */
        fun fromSaleItem(i: JsonElement, currency: String): CartLine? {
            val pid = i["product_id"].int ?: i["variant_id"].int ?: return null
            return CartLine(
                productId = i["product_id"].int ?: pid,
                variantId = i["variant_id"].int,
                productName = i["product_name"].str ?: "Item",
                variantName = i["variant_name"].str ?: "",
                sku = i["sku"].str ?: "",
                unitPrice = i["unit_price"] ?: JsonPrimitive(0),
                currency = currency,
                quantity = i["quantity"].int ?: 1,
                discountType = i["discount_type"].str ?: "none",
                discountValue = i["discount_value"].str ?: "0",
                isProduction = i["is_production"].bool ?: false,
                productionNotes = i["production_notes"].str ?: "",
                measurementValues = i["measurement_values"].obj?.entries?.mapNotNull { (k, v) -> v.str?.let { k to it } }?.toMap() ?: emptyMap(),
                taxRate = i["tax_rate"].dbl ?: 0.0,
            )
        }

        fun fromDraft(j: JsonElement): CartLine? {
            val pid = j["product_id"].int ?: return null
            return CartLine(
                id = j["id"].str ?: UUID.randomUUID().toString(), productId = pid, variantId = j["variant_id"].int,
                productName = j["product_name"].str ?: "Item", variantName = j["variant_name"].str ?: "", sku = j["sku"].str ?: "",
                unitPrice = j["unit_price"] ?: JsonPrimitive(0), currency = j["currency"].str ?: "KES", quantity = j["quantity"].int ?: 1,
                discountType = j["discount_type"].str ?: "none", discountValue = j["discount_value"].str ?: "0",
                isProduction = j["is_production"].bool ?: false, productionNotes = j["production_notes"].str ?: "",
                measurementFields = j["measurement_fields"].records.mapNotNull(MeasurementField::from),
                measurementValues = j["measurement_values"].obj?.entries?.mapNotNull { (k, v) -> v.str?.let { k to it } }?.toMap() ?: emptyMap(),
                imageUrl = j["image_url"].str, taxRate = j["tax_rate"].dbl ?: 0.0, stock = j["stock"].int ?: 0,
            )
        }
    }
}

internal data class PosCustomer(
    /** Server id; 0 for a walk-in typed by name; -1 for a new record (`new_customer`). */
    val id: Int,
    val firstName: String = "",
    val lastName: String = "",
    val phone: String = "",
    val email: String = "",
    val company: String = "",
) {
    val displayName: String get() = listOf(firstName, lastName).filter { it.isNotEmpty() }.joinToString(" ").ifEmpty { "Walk-in" }

    fun toJson(): JsonObject = jsonOf("id" to id, "first_name" to firstName, "last_name" to lastName, "phone" to phone, "email" to email, "company" to company)

    companion object {
        fun fromJson(j: JsonElement?): PosCustomer? {
            val id = j["id"].int ?: return null
            return PosCustomer(id, j["first_name"].str ?: "", j["last_name"].str ?: "", j["phone"].str ?: "", j["email"].str ?: "", j["company"].str ?: "")
        }
        /** "First Last" split the way the till does it. */
        fun fromName(id: Int, name: String?, phone: String?, email: String?): PosCustomer {
            val parts = (name ?: "").trim().split(" ", limit = 2)
            return PosCustomer(id, parts.getOrNull(0) ?: "", parts.getOrNull(1) ?: "", phone ?: "", email ?: "")
        }
    }
}

/** A sale as `transformSaleOrder` returns it. Figures are read, never rebuilt. */
internal data class PosSale(val json: JsonElement) {
    val id: Int get() = json["id"].int ?: 0
    val orderNumber: String get() = json["order_number"].str ?: "—"
    val currency: String get() = json["currency_code"].str ?: "KES"
    val status: String? get() = json["status"].str
    val paymentStatus: String? get() = json["payment_status"].str
    val customerName: String? get() = json["customer_name"].str
    val customerId: Int? get() = json["customer_id"].int
    val items: List<JsonElement> get() = json["items"].records
    val payments: List<JsonElement> get() = json["payments"].records
    val pendingReversals: List<JsonElement> get() = json["pending_reversals"].records
    val createdAt: Instant? get() = json["created_at"].date
    fun money(key: String): Money? = json[key]?.let { Money.from(it, currency) }
    val total: Money? get() = money("total")
    /** Created but unpaid: may be restored to the cart. */
    val isRestorable: Boolean get() = status == "pending" && (paymentStatus == "pending" || paymentStatus == null)
}

// ── Gates mirrored from the server ───────────────────────────────────────────

internal object PosGates {
    /**
     * owner.no_transact (App\Http\Middleware\OwnerDoesNotTransact): the
     * owner's roles watch the till but never open, close, ring or reverse.
     * Approving with a PIN is allowed.
     */
    fun ownerWatchesOnly(me: Me?): Boolean = me != null && (me.roleNames.contains("super_admin") || me.roleNames.contains("system_admin"))

    /**
     * This person's till discount limit (react-admin/src/lib/discountCap.ts):
     * `discount_cap_percent` from /me; null means no ceiling; a session from
     * before the field existed falls back to 5 unless super admin.
     */
    fun discountCap(me: Me?): Double? {
        me ?: return 5.0
        val v = me.raw["discount_cap_percent"]
        if (v != null) return if (v.isNull) null else v.dbl
        return if (me.isSuperAdmin) null else 5.0
    }
}

// ── Session (cart, register, pending order, draft) ───────────────────────────

/**
 * The till session for one cashier: register state, the cart and the
 * pending order it became. The cart draft is kept in [OfflineStore] under
 * "pos-cart-draft" so a crash does not lose it; a sale is never queued.
 */
internal class PosSession(val api: PosApi, val offline: OfflineStore?, val me: Me?, private val scope: CoroutineScope) {
    var outlets by mutableStateOf<List<PosOutlet>>(emptyList())
    var outlet by mutableStateOf<PosOutlet?>(null)
    var registerStatus by mutableStateOf<JsonElement?>(null)
    var checkoutConfig by mutableStateOf<JsonElement?>(null)
    var shippingMethods by mutableStateOf<List<JsonElement>>(emptyList())
    var paymentMethods by mutableStateOf<List<JsonElement>>(emptyList())

    val cart = mutableStateListOf<CartLine>()
    var cartDiscountType by mutableStateOf("none")
    var cartDiscountValue by mutableStateOf("0")
    var customer by mutableStateOf<PosCustomer?>(null)
    var customerCountryCode by mutableStateOf("")
    var note by mutableStateOf("")
    var shippingMethodId by mutableStateOf<Int?>(null)

    /** The Hub's pricing of this cart (createPendingOrder / updatePendingOrder reply). */
    var pendingOrder by mutableStateOf<JsonElement?>(null)
    var pendingOrderId by mutableStateOf<Int?>(null)
    var isResumedOrder by mutableStateOf(false)
    /** Set whenever the cart changes after the Hub priced it. */
    var totalsStale by mutableStateOf(false)
    private var clientRequestId: String? = null
    private var saveJob: Job? = null

    companion object {
        const val DRAFT_KEY = "pos-cart-draft"
        const val DRAFT_MAX_AGE_SECONDS = 8L * 3600
    }

    val register: JsonElement? get() = registerStatus["register"]?.takeUnless { it.isNull }
    val hasOpenRegister: Boolean get() = registerStatus["has_open_register"].bool ?: false
    val eodSubmitted: Boolean get() = registerStatus["eod_submitted"].bool ?: false
    val staleTill: JsonElement? get() = registerStatus["stale_till"]?.takeUnless { it.isNull }
    val registerId: Int? get() = register["id"].int
    val currency: String get() = pendingOrder["currency_code"].str ?: outlet?.currencyCode ?: "KES"
    val ownerWatchesOnly: Boolean get() = PosGates.ownerWatchesOnly(me)
    val discountCap: Double? get() = PosGates.discountCap(me)
    val itemCount: Int get() = cart.sumOf { it.quantity }

    /**
     * One key per sale attempt: minted on first use, reused by every retry of
     * this cart, cleared with the cart (PosPage.tsx saleAttemptKey).
     */
    fun saleAttemptKey(): String = clientRequestId ?: UUID.randomUUID().toString().lowercase().also { clientRequestId = it }

    suspend fun refreshRegister() {
        val o = outlet ?: return
        registerStatus = runCatching { api.registerStatus(o.id) }.getOrNull() ?: registerStatus
    }

    // Cart edits

    fun add(product: PosProduct, variant: PosVariant, production: Boolean) {
        val i = if (production) -1 else cart.indexOfFirst { it.productId == product.id && it.variantId == variant.variantId && !it.isProduction }
        if (i >= 0) cart[i] = cart[i].copy(quantity = cart[i].quantity + 1)
        else cart += CartLine.from(product, variant, production)
        cartChanged()
    }

    fun update(line: CartLine) {
        val i = cart.indexOfFirst { it.id == line.id }
        if (i < 0) return
        cart[i] = line
        cartChanged()
    }

    fun remove(line: CartLine) {
        cart.removeAll { it.id == line.id }
        cartChanged()
    }

    fun cartChanged() {
        if (pendingOrder != null) totalsStale = true
        scheduleDraftSave()
    }

    fun clearCart() {
        cart.clear()
        cartDiscountType = "none"; cartDiscountValue = "0"
        customer = null; customerCountryCode = ""; note = ""; shippingMethodId = null
        pendingOrder = null; pendingOrderId = null; isResumedOrder = false; totalsStale = false
        clientRequestId = null
        saveJob?.cancel()
        scope.launch { offline?.remove(DRAFT_KEY) }
    }

    /**
     * A saved, unpaid order from the history drawer (or the open pending
     * order found on arrival) becomes the cart again; paying it updates the
     * same order instead of ringing a second one.
     */
    fun restore(sale: PosSale) {
        cart.clear()
        cart += sale.items.mapNotNull { CartLine.fromSaleItem(it, sale.currency) }
        cartDiscountType = sale.json["cart_discount_type"].str ?: "none"
        cartDiscountValue = sale.json["cart_discount_value"].str ?: "0"
        note = sale.json["notes"].str ?: ""
        val cid = sale.customerId
        val name = sale.customerName
        customer = when {
            cid != null && cid > 0 -> PosCustomer.fromName(cid, name, sale.json["customer_phone"].str, sale.json["customer_email"].str)
            !name.isNullOrEmpty() -> PosCustomer.fromName(0, name, sale.json["customer_phone"].str, sale.json["customer_email"].str)
            else -> null
        }
        pendingOrderId = sale.id
        pendingOrder = jsonOf("order_id" to sale.id, "order_number" to sale.orderNumber, "total_amount" to (sale.json["total"] ?: JsonNull), "currency_code" to sale.currency, "order" to sale.json)
        isResumedOrder = true
        totalsStale = false
        clientRequestId = null
        scheduleDraftSave()
    }

    // Payloads

    fun cartPayload(): JsonObject {
        val shipping = shippingMethods.firstOrNull { it["id"].int == shippingMethodId }
        val fee: JsonElement? = shipping?.let { m -> if (m["cost_type"].str == "free") null else m["fee"]?.takeUnless { it.isNull } }
        val pairs = mutableMapOf<String, Any?>(
            "outlet_id" to (outlet?.id ?: Omit),
            "client_request_id" to saleAttemptKey(),
            "customer_country_code" to (if (customerCountryCode.length == 2) customerCountryCode.uppercase() else Omit),
            "notes" to (note.trim().ifEmpty { null } ?: Omit),
            // MTO lines travel only in production_items (no stock check).
            "items" to JsonArray(cart.filter { !it.isProduction }.map { it.payload }),
            "cart_discount_type" to cartDiscountType,
            "cart_discount_value" to (amountJson(cartDiscountValue) ?: JsonPrimitive(0)),
            "shipping_amount" to (fee ?: Omit),
            "shipping_method_id" to (shippingMethodId ?: Omit),
            "production_items" to (if (cart.any { it.isProduction }) JsonArray(cart.filter { it.isProduction }.map { it.productionPayload }) else Omit),
        )
        customer?.let { c ->
            if (c.id > 0) pairs["customer_id"] = c.id
            if (c.id == -1) pairs["new_customer"] = jsonOf(
                "first_name" to c.firstName, "last_name" to (c.lastName.ifEmpty { null } ?: Omit),
                "phone" to c.phone, "email" to (c.email.ifEmpty { null } ?: Omit), "company" to (c.company.ifEmpty { null } ?: Omit),
            )
            if (c.firstName.isNotEmpty()) pairs["customer_first_name"] = c.firstName
            if (c.lastName.isNotEmpty()) pairs["customer_last_name"] = c.lastName
            if (c.phone.isNotEmpty()) pairs["customer_phone"] = c.phone
            if (c.email.isNotEmpty()) pairs["customer_email"] = c.email
        }
        return jsonOf(*pairs.entries.map { it.key to it.value }.toTypedArray())
    }

    /**
     * Creates the pending order, or updates the one this cart already is.
     * The reply carries the Hub's totals.
     */
    suspend fun priceWithHub(): JsonElement {
        val body = cartPayload()
        val id = pendingOrderId
        val reply: JsonElement = when {
            id != null && totalsStale -> api.updatePendingOrder(id, body)
            id != null && pendingOrder != null -> return pendingOrder!!
            else -> api.createPendingOrder(body)
        }
        pendingOrder = reply
        pendingOrderId = reply["order_id"].int ?: pendingOrderId
        totalsStale = false
        scheduleDraftSave()
        return reply
    }

    // Draft

    private fun scheduleDraftSave() {
        saveJob?.cancel()
        saveJob = scope.launch { delay(400); saveDraft() }
    }

    suspend fun saveDraft() {
        val store = offline ?: return
        val d = jsonOf(
            "outlet_id" to (outlet?.id ?: JsonNull),
            "cart" to JsonArray(cart.map { it.toDraft() }),
            "cart_discount_type" to cartDiscountType, "cart_discount_value" to cartDiscountValue,
            "customer" to (customer?.toJson() ?: JsonNull),
            "customer_country_code" to customerCountryCode, "note" to note,
            "shipping_method_id" to jsonInt(shippingMethodId), "pending_order_id" to jsonInt(pendingOrderId),
            "is_resumed_order" to isResumedOrder, "client_request_id" to (clientRequestId ?: JsonNull),
            "saved_at" to Instant.now().epochSecond,
        )
        store.put(d, DRAFT_KEY)
    }

    /** Returns the draft's outlet id so the caller can select it. */
    suspend fun restoreDraft(): Int? {
        val store = offline ?: return null
        val cached = store.get(DRAFT_KEY) ?: return null
        val d = cached.value
        val savedAt = d["saved_at"].long ?: cached.at.epochSecond
        if (Instant.now().epochSecond - savedAt > DRAFT_MAX_AGE_SECONDS) { store.remove(DRAFT_KEY); return null }
        cart.clear(); cart += d["cart"].records.mapNotNull(CartLine::fromDraft)
        cartDiscountType = d["cart_discount_type"].str ?: "none"; cartDiscountValue = d["cart_discount_value"].str ?: "0"
        customer = PosCustomer.fromJson(d["customer"]); customerCountryCode = d["customer_country_code"].str ?: ""; note = d["note"].str ?: ""
        shippingMethodId = d["shipping_method_id"].int
        pendingOrderId = d["pending_order_id"].int; isResumedOrder = d["is_resumed_order"].bool ?: false; clientRequestId = d["client_request_id"].str
        totalsStale = pendingOrderId != null
        return d["outlet_id"].int
    }
}
