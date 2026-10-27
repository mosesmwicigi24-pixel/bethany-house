import SwiftUI
import BethanyKit

/// Point of sale, tills and end-of-day (console: react-admin/src/pages/pos).
///
/// Money rules (CLAUDE.md §6–§8): every total on these screens is the
/// server's. The cart holds what the cashier picked; the Hub prices it when
/// the pending order is created (`pos/pending-order`) and that reply is the
/// only figure shown. Nothing is summed, converted or rounded here. Money
/// writes are online-only and carry a `client_request_id` per sale attempt
/// so a retry returns the original sale (PosController::findReplayedOrder).
@MainActor
public enum PosFeature {
    public static func register() {
        FeatureRegistry.register(.pos) { _ in AnyView(PosRootView()) }
        TillsFeature.register()
        EodFeature.register()
    }
}

// MARK: - API (paths and payload keys verified against backend/routes/api.php and PosController)

struct PosAPI {
    let client: APIClient
    static let base = "/v1/admin/pos"

    func outlets() async throws -> [PosOutlet] {
        (try await client.get("\(Self.base)/outlets").json?["data"]?.array ?? []).compactMap(PosOutlet.init(json:))
    }

    func registerStatus(outletId: Int) async throws -> JSONValue {
        try await client.get("\(Self.base)/register/status", query: ["outlet_id": String(outletId)]).json ?? .null
    }

    func openRegister(_ body: JSONValue) async throws -> JSONValue { try await client.post("\(Self.base)/register/open", json: body).json ?? .null }
    func closeRegister(_ body: JSONValue) async throws -> JSONValue { try await client.post("\(Self.base)/register/close", json: body).json ?? .null }

    func products(outletId: Int, categoryId: Int?, currency: String?) async throws -> [PosProduct] {
        let r = try await client.get("\(Self.base)/products", query: ["outlet_id": String(outletId), "category_id": categoryId.map(String.init), "currency": currency])
        return (r.json?["data"]?.array ?? []).compactMap(PosProduct.init(json:))
    }

    func searchProducts(_ q: String, outletId: Int, currency: String?) async throws -> [PosProduct] {
        let r = try await client.get("\(Self.base)/products/search", query: ["q": q, "outlet_id": String(outletId), "currency": currency])
        return (r.json?["data"]?.array ?? []).compactMap(PosProduct.init(json:))
    }

    func searchCustomers(_ q: String) async throws -> [JSONValue] {
        try await client.get("\(Self.base)/customers/search", query: ["q": q]).json?["data"]?.array ?? []
    }

    func shippingMethods(currency: String?) async throws -> [JSONValue] {
        try await client.get("\(Self.base)/shipping-methods", query: ["currency": currency]).json?["data"]?.array ?? []
    }

    func checkoutConfig() async throws -> JSONValue { try await client.get("\(Self.base)/checkout-config").json ?? .null }

    /// GET /v1/payment-methods?currency= — the sale-facing list (PaymentMethodController::available).
    func paymentMethods(currency: String) async throws -> [JSONValue] {
        try await client.get("/v1/payment-methods", query: ["currency": currency]).json?["data"]?.array ?? []
    }

    func openPendingOrder(outletId: Int) async throws -> JSONValue? {
        let j = try await client.get("\(Self.base)/pending-order/open", query: ["outlet_id": String(outletId)]).json
        return j?["order_id"] == nil ? nil : j
    }

    func createPendingOrder(_ body: JSONValue) async throws -> JSONValue { try await client.post("\(Self.base)/pending-order", json: body).json ?? .null }
    func updatePendingOrder(_ id: Int, _ body: JSONValue) async throws -> JSONValue { try await client.patch("\(Self.base)/pending-order/\(id)", json: body).json ?? .null }
    func recordPay(_ id: Int, _ body: JSONValue) async throws -> JSONValue { try await client.post("\(Self.base)/pending-order/\(id)/pay", json: body).json ?? .null }
    func recordPay(_ id: Int, multipart: [APIClient.MultipartPart]) async throws -> JSONValue {
        try await client.send(.init(.post, "\(Self.base)/pending-order/\(id)/pay", body: .multipart(multipart))).json ?? .null
    }

    func sales(outletId: Int, query: [String: String?]) async throws -> [PosSale] {
        var q = query; q["outlet_id"] = String(outletId)
        return (try await client.get("\(Self.base)/sales", query: q).json?["data"]?.array ?? []).map(PosSale.init(json:))
    }

    func sale(_ id: Int) async throws -> PosSale { PosSale(json: try await client.get("\(Self.base)/sales/\(id)").json?["sale"] ?? .null) }
    func requestVoid(_ id: Int, reason: String) async throws -> JSONValue { try await client.post("\(Self.base)/sales/\(id)/void", json: .object(["reason": .string(reason)])).json ?? .null }
    func emailReceipt(_ id: Int, email: String) async throws -> JSONValue { try await client.post("\(Self.base)/sales/\(id)/email-receipt", json: .object(["email": .string(email)])).json ?? .null }
    func reversals(_ saleId: Int) async throws -> JSONValue { try await client.get("\(Self.base)/sales/\(saleId)/reversals").json ?? .null }
    func approvers(_ approvalId: Int) async throws -> [JSONValue] { try await client.get("\(Self.base)/approvals/\(approvalId)/approvers").json?["data"]?.array ?? [] }
    func pinSign(_ approvalId: Int, _ body: JSONValue) async throws -> JSONValue { try await client.post("\(Self.base)/approvals/\(approvalId)/pin-sign", json: body).json ?? .null }
    func processReturn(_ body: JSONValue) async throws -> JSONValue { try await client.post("\(Self.base)/returns", json: body).json ?? .null }

    func dailySummary(outletId: Int, date: String) async throws -> JSONValue {
        try await client.get("\(Self.base)/reports/daily", query: ["outlet_id": String(outletId), "date": date]).json?["summary"] ?? .null
    }
    func userEod(outletId: Int, date: String) async throws -> JSONValue {
        try await client.get("\(Self.base)/reports/user-eod", query: ["outlet_id": String(outletId), "date": date]).json?["summary"] ?? .null
    }
    func saveUserEod(_ body: JSONValue) async throws -> JSONValue { try await client.post("\(Self.base)/reports/user-eod", json: body).json ?? .null }
    func addEodComment(reportId: Int, body: String) async throws -> JSONValue {
        try await client.post("\(Self.base)/reports/eod/\(reportId)/comments", json: .object(["body": .string(body)])).json ?? .null
    }

    /// Customer contact reveal (CustomerContactRevealController): audited server-side.
    func reveal(customerId: Int, field: String, reason: String, orderId: Int?) async throws -> JSONValue {
        var body: [String: JSONValue] = ["field": .string(field), "reason": .string(reason)]
        if let orderId { body["context"] = .object(["type": .string("order"), "id": .number(Double(orderId))]) }
        return try await client.post("/v1/admin/customers/\(customerId)/reveal", json: .object(body)).json ?? .null
    }

    // Gateway payments against the pending order (PaymentController).
    func initiatePayment(orderId: Int, _ body: JSONValue) async throws -> JSONValue { try await client.post("/v1/admin/orders/\(orderId)/payment", json: body).json ?? .null }
    func order(_ id: Int) async throws -> JSONValue { try await client.get("/v1/admin/orders/\(id)", background: true).json?["order"] ?? .null }
    func verifyMpesa(orderId: Int, paymentId: Int, code: String) async throws -> JSONValue {
        try await client.post("/v1/admin/orders/\(orderId)/payments/\(paymentId)/verify-mpesa", json: .object(["transaction_code": .string(code)])).json ?? .null
    }
}

/// Builds a JSON object, dropping nil values.
func jsonObject(_ pairs: [String: JSONValue?]) -> JSONValue {
    var o: [String: JSONValue] = [:]
    for (k, v) in pairs { if let v { o[k] = v } }
    return .object(o)
}

/// A typed amount as the person entered it, sent verbatim as a numeric string
/// (Laravel `numeric` accepts it) so no float noise is introduced here.
func amountJSON(_ text: String) -> JSONValue? {
    let t = text.trimmingCharacters(in: .whitespaces).replacingOccurrences(of: ",", with: "")
    guard !t.isEmpty, Decimal(string: t, locale: Locale(identifier: "en_US_POSIX")) != nil else { return nil }
    return .string(t)
}

// MARK: - Models

struct PosOutlet: Identifiable, Hashable, Sendable {
    let id: Int
    let name: String
    let code: String?
    let currencyCode: String
    let raw: JSONValue
    init?(json: JSONValue) {
        guard let id = json["id"]?.int, let name = json["name"]?.string else { return nil }
        self.id = id; self.name = name; code = json["code"]?.string
        currencyCode = json["currency_code"]?.string ?? "KES"
        raw = json
    }
}

struct MeasurementField: Codable, Hashable, Sendable {
    let name: String
    let unit: String?
    let required: Bool
    init?(json: JSONValue) {
        guard let n = json["name"]?.string else { return nil }
        name = n; unit = json["unit"]?.string; required = json["required"]?.bool ?? false
    }
}

struct PosVariant: Identifiable, Hashable, Sendable {
    let variantId: Int?
    let productId: Int
    let sku: String
    let name: String
    /// The server's price in `currency`, passed back verbatim as `unit_price`.
    let price: JSONValue?
    let salePrice: JSONValue?
    let priceUnavailable: Bool
    let currency: String
    let stock: Int
    let isDefault: Bool
    let taxRate: Double
    let taxName: String?
    var id: String { "\(productId)-\(variantId ?? 0)" }
    var money: Money? { price.flatMap { Money(json: $0, currency: currency) } }
    var sellable: Bool { !priceUnavailable && money != nil }

    init(json: JSONValue, productId: Int) {
        self.productId = productId
        variantId = json["id"]?.int
        sku = json["sku"]?.string ?? ""
        name = json["variant_name"]?.string ?? ""
        price = json["price"].flatMap { $0.isNull ? nil : $0 }
        salePrice = json["sale_price"].flatMap { $0.isNull ? nil : $0 }
        priceUnavailable = json["price_unavailable"]?.bool ?? false
        currency = json["currency"]?.string ?? "KES"
        stock = json["stock"]?.int ?? 0
        isDefault = json["is_default"]?.bool ?? false
        taxRate = json["tax_rate"]?.double ?? 0
        taxName = json["tax_name"]?.string
    }
}

struct PosProduct: Identifiable, Hashable, Sendable {
    let id: Int
    let name: String
    let sku: String
    let isProducible: Bool
    let measurements: [MeasurementField]
    let categoryId: Int?
    let categoryName: String?
    let imageURL: String?
    let variants: [PosVariant]
    init?(json: JSONValue) {
        guard let id = json["id"]?.int, let name = json["name"]?.string else { return nil }
        self.id = id; self.name = name
        sku = json["sku"]?.string ?? ""
        isProducible = json["is_producible"]?.bool ?? false
        measurements = (json["measurements"]?.array ?? []).compactMap(MeasurementField.init(json:))
        categoryId = json["category"]?["id"]?.int
        categoryName = json["category"]?["name"]?.string
        imageURL = json["image_url"]?.string
        variants = (json["variants"]?.array ?? []).map { PosVariant(json: $0, productId: id) }
    }
    var defaultVariant: PosVariant? { variants.first { $0.isDefault } ?? variants.first }
}

/// A cart line as the cashier built it. `unitPrice` is the server's figure
/// for the variant (or a price the cashier typed, which the server checks
/// against the discount rule); it is sent back, never recomputed.
struct CartLine: Identifiable, Codable, Hashable, Sendable {
    var id = UUID()
    var productId: Int
    var variantId: Int?
    var productName: String
    var variantName: String
    var sku: String
    var unitPrice: JSONValue
    var currency: String
    var quantity: Int
    var discountType: String = "none"   // none | flat | percent
    var discountValue: String = "0"
    var isProduction = false
    var productionNotes = ""
    var measurementFields: [MeasurementField] = []
    var measurementValues: [String: String] = [:]
    var imageURL: String?
    var taxRate: Double = 0
    var stock: Int = 0

    var unitMoney: Money? { Money(json: unitPrice, currency: currency) }
    var title: String { variantName.isEmpty || variantName.lowercased() == "default" ? productName : "\(productName) · \(variantName)" }

    init(product: PosProduct, variant: PosVariant, production: Bool) {
        productId = product.id
        variantId = variant.variantId
        productName = product.name
        variantName = variant.name
        sku = variant.sku
        unitPrice = variant.price ?? .number(0)
        currency = variant.currency
        quantity = 1
        isProduction = production
        measurementFields = product.measurements
        imageURL = product.imageURL
        taxRate = variant.taxRate
        stock = variant.stock
    }

    /// From a saved sale's line (transformSaleOrder) when the order is restored to the cart.
    init?(saleItem i: JSONValue, currency: String) {
        guard let pid = i["product_id"]?.int ?? i["variant_id"]?.int else { return nil }
        productId = i["product_id"]?.int ?? pid
        variantId = i["variant_id"]?.int
        productName = i["product_name"]?.string ?? "Item"
        variantName = i["variant_name"]?.string ?? ""
        sku = i["sku"]?.string ?? ""
        unitPrice = i["unit_price"] ?? .number(0)
        self.currency = currency
        quantity = i["quantity"]?.int ?? 1
        discountType = i["discount_type"]?.string ?? "none"
        discountValue = i["discount_value"]?.string ?? "0"
        isProduction = i["is_production"]?.bool ?? false
        productionNotes = i["production_notes"]?.string ?? ""
        if let mv = i["measurement_values"]?.object { measurementValues = mv.compactMapValues(\.string) }
        taxRate = i["tax_rate"]?.double ?? 0
    }

    var payload: JSONValue {
        jsonObject([
            "variant_id": variantId.map { .number(Double($0)) } ?? .null,
            "product_id": .number(Double(productId)),
            "quantity": .number(Double(quantity)),
            "unit_price": unitPrice,
            "discount_type": .string(discountType),
            "discount_value": amountJSON(discountValue) ?? .number(0),
        ])
    }

    var productionPayload: JSONValue {
        jsonObject([
            "variant_id": variantId.map { .number(Double($0)) } ?? .null,
            "product_id": .number(Double(productId)),
            "quantity": .number(Double(quantity)),
            "unit_price": unitPrice,
            "production_notes": productionNotes.isEmpty ? nil : .string(productionNotes),
            "measurement_values": measurementValues.isEmpty ? nil : .object(measurementValues.mapValues { .string($0) }),
        ])
    }
}

struct PosCustomer: Codable, Hashable, Sendable {
    /// Server id; 0 for a walk-in typed by name; -1 for a new record (`new_customer`).
    var id: Int
    var firstName = ""
    var lastName = ""
    var phone = ""
    var email = ""
    var company = ""
    var displayName: String { [firstName, lastName].filter { !$0.isEmpty }.joined(separator: " ").isEmpty ? "Walk-in" : [firstName, lastName].filter { !$0.isEmpty }.joined(separator: " ") }
}

/// A sale as `transformSaleOrder` returns it. Figures are read, never rebuilt.
struct PosSale: Identifiable, Hashable, Sendable {
    let json: JSONValue
    var id: Int { json["id"]?.int ?? 0 }
    var orderNumber: String { json["order_number"]?.string ?? "—" }
    var currency: String { json["currency_code"]?.string ?? "KES" }
    var status: String? { json["status"]?.string }
    var paymentStatus: String? { json["payment_status"]?.string }
    var customerName: String? { json["customer_name"]?.string }
    var customerId: Int? { json["customer_id"]?.int }
    var items: [JSONValue] { json["items"]?.array ?? [] }
    var payments: [JSONValue] { json["payments"]?.array ?? [] }
    var pendingReversals: [JSONValue] { json["pending_reversals"]?.array ?? [] }
    var createdAt: Date? { json["created_at"]?.date }
    func money(_ key: String) -> Money? { json[key].flatMap { Money(json: $0, currency: currency) } }
    var total: Money? { money("total") }
    /// Created but unpaid: may be restored to the cart.
    var isRestorable: Bool { status == "pending" && (paymentStatus == "pending" || paymentStatus == nil) }
}

// MARK: - Gates mirrored from the server

enum PosGates {
    /// owner.no_transact (App\Http\Middleware\OwnerDoesNotTransact): the
    /// owner's roles watch the till but never open, close, ring or reverse.
    /// Approving with a PIN is allowed.
    static func ownerWatchesOnly(_ me: Me?) -> Bool {
        guard let me else { return false }
        return me.roleNames.contains("super_admin") || me.roleNames.contains("system_admin")
    }

    /// This person's till discount limit (react-admin/src/lib/discountCap.ts):
    /// `discount_cap_percent` from /me; null means no ceiling; a session from
    /// before the field existed falls back to 5 unless super admin.
    static func discountCap(_ me: Me?) -> Double? {
        guard let me else { return 5 }
        if let v = me.raw["discount_cap_percent"] { return v.isNull ? nil : v.double }
        return me.isSuperAdmin ? nil : 5
    }
}

// MARK: - Session (cart, register, pending order, draft)

/// The till session for one cashier: register state, the cart and the
/// pending order it became. The cart draft is kept in `OfflineStore` under
/// "pos-cart-draft" so a crash does not lose it; a sale is never queued.
@MainActor
@Observable
final class PosSession {
    let api: PosAPI
    let offline: OfflineStore?
    let me: Me?

    var outlets: [PosOutlet] = []
    var outlet: PosOutlet?
    var registerStatus: JSONValue?
    var checkoutConfig: JSONValue?
    var shippingMethods: [JSONValue] = []
    var paymentMethods: [JSONValue] = []

    var cart: [CartLine] = []
    var cartDiscountType = "none"
    var cartDiscountValue = "0"
    var customer: PosCustomer?
    var customerCountryCode = ""
    var note = ""
    var shippingMethodId: Int?

    /// The Hub's pricing of this cart (createPendingOrder / updatePendingOrder reply).
    var pendingOrder: JSONValue?
    var pendingOrderId: Int?
    var isResumedOrder = false
    /// Set whenever the cart changes after the Hub priced it.
    var totalsStale = false
    private var clientRequestId: String?
    private var saveTask: Task<Void, Never>?

    static let draftKey = "pos-cart-draft"
    static let draftMaxAge: TimeInterval = 8 * 3600

    init(api: PosAPI, offline: OfflineStore?, me: Me?) {
        self.api = api
        self.offline = offline
        self.me = me
    }

    var register: JSONValue? { registerStatus?["register"].flatMap { $0.isNull ? nil : $0 } }
    var hasOpenRegister: Bool { registerStatus?["has_open_register"]?.bool ?? false }
    var eodSubmitted: Bool { registerStatus?["eod_submitted"]?.bool ?? false }
    var staleTill: JSONValue? { registerStatus?["stale_till"].flatMap { $0.isNull ? nil : $0 } }
    var registerId: Int? { register?["id"]?.int }
    var currency: String { pendingOrder?["currency_code"]?.string ?? outlet?.currencyCode ?? "KES" }
    var ownerWatchesOnly: Bool { PosGates.ownerWatchesOnly(me) }
    var discountCap: Double? { PosGates.discountCap(me) }

    /// One key per sale attempt: minted on first use, reused by every retry of
    /// this cart, cleared with the cart (PosPage.tsx saleAttemptKey).
    func saleAttemptKey() -> String {
        if let k = clientRequestId { return k }
        let k = UUID().uuidString.lowercased()
        clientRequestId = k
        return k
    }

    func refreshRegister() async {
        guard let outlet else { return }
        registerStatus = try? await api.registerStatus(outletId: outlet.id)
    }

    // MARK: Cart edits

    func add(product: PosProduct, variant: PosVariant, production: Bool) {
        if !production, let i = cart.firstIndex(where: { $0.productId == product.id && $0.variantId == variant.variantId && !$0.isProduction }) {
            cart[i].quantity += 1
        } else {
            cart.append(CartLine(product: product, variant: variant, production: production))
        }
        cartChanged()
    }

    func update(_ line: CartLine) {
        guard let i = cart.firstIndex(where: { $0.id == line.id }) else { return }
        cart[i] = line
        cartChanged()
    }

    func remove(_ line: CartLine) {
        cart.removeAll { $0.id == line.id }
        cartChanged()
    }

    func cartChanged() {
        if pendingOrder != nil { totalsStale = true }
        scheduleDraftSave()
    }

    func clearCart() {
        cart = []
        cartDiscountType = "none"; cartDiscountValue = "0"
        customer = nil; customerCountryCode = ""; note = ""; shippingMethodId = nil
        pendingOrder = nil; pendingOrderId = nil; isResumedOrder = false; totalsStale = false
        clientRequestId = nil
        saveTask?.cancel()
        Task { await offline?.remove(Self.draftKey) }
    }

    /// A saved, unpaid order from the history drawer (or the open pending
    /// order found on arrival) becomes the cart again; paying it updates the
    /// same order instead of ringing a second one.
    func restore(sale: PosSale) {
        cart = sale.items.compactMap { CartLine(saleItem: $0, currency: sale.currency) }
        cartDiscountType = sale.json["cart_discount_type"]?.string ?? "none"
        cartDiscountValue = sale.json["cart_discount_value"]?.string ?? "0"
        note = sale.json["notes"]?.string ?? ""
        if let cid = sale.customerId, cid > 0 {
            let parts = (sale.customerName ?? "").split(separator: " ", maxSplits: 1).map(String.init)
            customer = PosCustomer(id: cid, firstName: parts.first ?? "", lastName: parts.count > 1 ? parts[1] : "", phone: sale.json["customer_phone"]?.string ?? "", email: sale.json["customer_email"]?.string ?? "")
        } else if let name = sale.customerName, !name.isEmpty {
            let parts = name.split(separator: " ", maxSplits: 1).map(String.init)
            customer = PosCustomer(id: 0, firstName: parts.first ?? "", lastName: parts.count > 1 ? parts[1] : "", phone: sale.json["customer_phone"]?.string ?? "", email: sale.json["customer_email"]?.string ?? "")
        } else {
            customer = nil
        }
        pendingOrderId = sale.id
        pendingOrder = jsonObject(["order_id": .number(Double(sale.id)), "order_number": .string(sale.orderNumber), "total_amount": sale.json["total"], "currency_code": .string(sale.currency), "order": sale.json])
        isResumedOrder = true
        totalsStale = false
        clientRequestId = nil
        scheduleDraftSave()
    }

    // MARK: Payloads

    func cartPayload() -> JSONValue {
        let shipping = shippingMethods.first { $0["id"]?.int == shippingMethodId }
        let fee: JSONValue? = shipping.flatMap { m in
            if m["cost_type"]?.string == "free" { return nil }
            return m["fee"].flatMap { $0.isNull ? nil : $0 }
        }
        var customerFields: [String: JSONValue?] = [:]
        if let c = customer {
            if c.id > 0 { customerFields["customer_id"] = .number(Double(c.id)) }
            if c.id == -1 {
                customerFields["new_customer"] = jsonObject([
                    "first_name": .string(c.firstName), "last_name": c.lastName.isEmpty ? nil : .string(c.lastName),
                    "phone": .string(c.phone), "email": c.email.isEmpty ? nil : .string(c.email), "company": c.company.isEmpty ? nil : .string(c.company),
                ])
            }
            customerFields["customer_first_name"] = c.firstName.isEmpty ? nil : .string(c.firstName)
            customerFields["customer_last_name"] = c.lastName.isEmpty ? nil : .string(c.lastName)
            customerFields["customer_phone"] = c.phone.isEmpty ? nil : .string(c.phone)
            customerFields["customer_email"] = c.email.isEmpty ? nil : .string(c.email)
        }
        var pairs: [String: JSONValue?] = [
            "outlet_id": outlet.map { .number(Double($0.id)) },
            "client_request_id": .string(saleAttemptKey()),
            "customer_country_code": customerCountryCode.count == 2 ? .string(customerCountryCode.uppercased()) : nil,
            "notes": note.trimmingCharacters(in: .whitespaces).isEmpty ? nil : .string(note),
            // MTO lines travel only in production_items (no stock check).
            "items": .array(cart.filter { !$0.isProduction }.map(\.payload)),
            "cart_discount_type": .string(cartDiscountType),
            "cart_discount_value": amountJSON(cartDiscountValue) ?? .number(0),
            "shipping_amount": fee,
            "shipping_method_id": shippingMethodId.map { .number(Double($0)) },
            "production_items": cart.contains { $0.isProduction } ? .array(cart.filter(\.isProduction).map(\.productionPayload)) : nil,
        ]
        for (k, v) in customerFields { pairs[k] = v }
        return jsonObject(pairs)
    }

    /// Creates the pending order, or updates the one this cart already is.
    /// The reply carries the Hub's totals.
    func priceWithHub() async throws -> JSONValue {
        let body = cartPayload()
        let reply: JSONValue
        if let id = pendingOrderId, totalsStale {
            reply = try await api.updatePendingOrder(id, body)
        } else if pendingOrderId != nil, let priced = pendingOrder {
            return priced
        } else {
            reply = try await api.createPendingOrder(body)
        }
        pendingOrder = reply
        pendingOrderId = reply["order_id"]?.int ?? pendingOrderId
        totalsStale = false
        scheduleDraftSave()
        return reply
    }

    // MARK: Draft

    private struct Draft: Codable {
        var outletId: Int?
        var cart: [CartLine]
        var cartDiscountType: String
        var cartDiscountValue: String
        var customer: PosCustomer?
        var customerCountryCode: String
        var note: String
        var shippingMethodId: Int?
        var pendingOrderId: Int?
        var isResumedOrder: Bool
        var clientRequestId: String?
        var savedAt: Date
    }

    private func scheduleDraftSave() {
        saveTask?.cancel()
        saveTask = Task { [weak self] in
            try? await Task.sleep(for: .milliseconds(400))
            guard !Task.isCancelled, let self else { return }
            await self.saveDraft()
        }
    }

    func saveDraft() async {
        guard let offline else { return }
        let d = Draft(outletId: outlet?.id, cart: cart, cartDiscountType: cartDiscountType, cartDiscountValue: cartDiscountValue, customer: customer,
                      customerCountryCode: customerCountryCode, note: note, shippingMethodId: shippingMethodId, pendingOrderId: pendingOrderId,
                      isResumedOrder: isResumedOrder, clientRequestId: clientRequestId, savedAt: Date())
        guard let data = try? HubJSON.encoder.encode(d), let json = try? HubJSON.decoder.decode(JSONValue.self, from: data) else { return }
        await offline.put(json, for: Self.draftKey)
    }

    /// Returns the draft's outlet id so the caller can select it.
    func restoreDraft() async -> Int? {
        guard let offline, let cached = await offline.get(Self.draftKey),
              let data = try? HubJSON.encoder.encode(cached.value),
              let d = try? HubJSON.decoder.decode(Draft.self, from: data) else { return nil }
        if Date().timeIntervalSince(d.savedAt) > Self.draftMaxAge { await offline.remove(Self.draftKey); return nil }
        cart = d.cart; cartDiscountType = d.cartDiscountType; cartDiscountValue = d.cartDiscountValue
        customer = d.customer; customerCountryCode = d.customerCountryCode; note = d.note; shippingMethodId = d.shippingMethodId
        pendingOrderId = d.pendingOrderId; isResumedOrder = d.isResumedOrder; clientRequestId = d.clientRequestId
        totalsStale = d.pendingOrderId != nil
        return d.outletId
    }
}
