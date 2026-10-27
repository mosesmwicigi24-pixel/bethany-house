import SwiftUI
import BethanyKit

/// Procurement: Purchase Orders (list + detail), Suppliers, Goods Receipt
/// (GRN list + detail) and Purchase Returns (list + detail). Endpoints
/// mirror backend/routes/api.php (suppliers, purchase-orders, grn,
/// purchase-returns, pdf/*) and react-admin/src/api/procurement.ts.
///
/// Money on a purchase order is what the business agreed to pay the
/// supplier, in the order's currency; every figure shown is the server's.
/// A unit price is typed by a person, never derived here.
@MainActor
enum ProcurementFeature {
    static func register() {
        FeatureRegistry.register(.purchaseOrders) { _ in AnyView(PurchaseOrdersView()) }
        FeatureRegistry.register({ if case .purchaseOrder = $0 { return true } else { return false } }) { route in
            if case .purchaseOrder(let id) = route { return AnyView(PurchaseOrderDetailView(poId: id)) }
            return AnyView(EmptyView())
        }
        FeatureRegistry.register(.suppliers) { _ in AnyView(SuppliersView()) }
        FeatureRegistry.register(.goodsReceipt) { _ in AnyView(GoodsReceiptView()) }
        FeatureRegistry.register({ if case .grn = $0 { return true } else { return false } }) { route in
            if case .grn(let id) = route { return AnyView(GRNDetailView(grnId: id)) }
            return AnyView(EmptyView())
        }
        FeatureRegistry.register(.purchaseReturns) { _ in AnyView(PurchaseReturnsView()) }
        FeatureRegistry.register({ if case .purchaseReturn = $0 { return true } else { return false } }) { route in
            if case .purchaseReturn(let id) = route { return AnyView(PurchaseReturnDetailView(returnId: id)) }
            return AnyView(EmptyView())
        }
    }
}

enum POStatus {
    static let all = ["draft", "pending_approval", "approved", "ordered", "partially_received", "received", "cancelled"]
    static let editable: Set<String> = ["draft", "pending_approval", "approved"]
    static let receivable: Set<String> = ["approved", "ordered", "partially_received"]
    static let returnable: Set<String> = ["ordered", "partially_received", "received"]
}

/// Typed reads over a purchase order (PurchaseOrderController index/show).
struct PurchaseOrderRecord {
    let r: Record
    init(_ r: Record) { self.r = r }
    var id: Int { r.int("id") ?? 0 }
    var number: String { r.string("po_number") ?? "PO-\(id)" }
    var status: String? { r.status }
    var currency: String { r.string("currency_code") ?? r.string("currency") ?? "KES" }
    var supplierName: String { r["supplier"]?["name"]?.string ?? "Supplier" }
    var total: Money? { r.money("total_amount", currency: currency) ?? r.money("total", currency: currency) }
    var subtotal: Money? { r.money("subtotal", currency: currency) }
    var tax: Money? { r.money("tax_amount", currency: currency) ?? r.money("tax", currency: currency) }
    var shipping: Money? { r.money("shipping_amount", currency: currency) ?? r.money("shipping_cost", currency: currency) }
    var items: [JSONValue] { r["items"]?.array ?? [] }
    var revision: Int { r.int("revision") ?? 0 }
    var expected: Date? { r.date("expected_delivery_date") }
}

// MARK: - List (GET /v1/admin/purchase-orders → Laravel paginator) + statistics

struct PurchaseOrdersView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var list: RecordList?
    @State private var stats: Resource<JSONValue>?
    @State private var suppliers: Lookup?
    @State private var search = ""
    @State private var status = ""
    @State private var supplierId: Int?
    @State private var showCreate = false
    @State private var showPayables = false

    var body: some View {
        Page("Purchase Orders", subtitle: "What has been ordered from suppliers, and where each order stands") {
            if let stats {
                LoadableView(stats.state, retry: { stats.load() }) { s in
                    StatsRow(tiles: [
                        ("Awaiting approval", s["pending_approval"]?.string ?? "0", (s["pending_approval"]?.int ?? 0) > 0 ? .amber : nil),
                        ("Ordered", s["ordered"]?.string ?? "0", .info),
                        ("Partly received", s["partially_received"]?.string ?? "0", .pink),
                        ("This month (KES)", s["total_value"]?.money.map { $0.formatted() } ?? "—", nil),
                        ("Outstanding (KES)", s["outstanding_value"]?.money.map { $0.formatted() } ?? "—", nil),
                    ])
                    Text("KES totals use the reporting rate; orders in a currency with no rate are left out.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                }
            }
            if let list {
                HStack(spacing: Theme.Space.s) {
                    ModuleSearchField(text: $search, placeholder: "PO number") { Task { await reload() } }
                    Picker("Status", selection: $status) { Text("Any status").tag(""); ForEach(POStatus.all, id: \.self) { Text($0.humanized).tag($0) } }.frame(maxWidth: 180)
                    if let suppliers { LookupPicker(label: "Supplier", lookup: suppliers, selection: $supplierId).frame(maxWidth: 200) }
                    if env.session.can("procurement.pay") || env.session.can("reports.financial") { Button { showPayables = true } label: { Label("Payables", systemImage: "banknote") } }
                    if env.session.can("procurement.create") { Button { showCreate = true } label: { Label("New", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
                }
                RecordListView(list, emptyTitle: "No purchase orders match", emptySymbol: "doc.on.clipboard") { r in
                    let po = PurchaseOrderRecord(r)
                    ModuleRowCard(action: { env.navigator.push(.purchaseOrder(id: po.id)) }) {
                        VStack(alignment: .leading, spacing: 3) {
                            HStack { Text(po.number).font(Theme.Font.body.weight(.semibold)); StatusBadge(po.status); if po.revision > 0 { StatusBadge("rev \(po.revision)", tone: .accent) } }
                            Text(po.supplierName).font(Theme.Font.secondary)
                            HStack {
                                Text("\(po.items.count) line\(po.items.count == 1 ? "" : "s") · expected \(po.expected.map { HubDates.dayString($0) } ?? "—")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                Spacer()
                                MoneyText(po.total, emphasis: true)
                            }
                        }
                    }
                }
            }
        }
        .task {
            if list == nil {
                let c = env.session.client
                list = RecordList(client: c, path: "/v1/admin/purchase-orders", perPage: 20)
                stats = Resource { try await c.get("/v1/admin/purchase-orders/statistics").json ?? .null }
                suppliers = Lookup.suppliers(c)
                stats?.load()
                await list?.load()
            }
        }
        .onChange(of: status) { _, _ in Task { await reload() } }
        .onChange(of: supplierId) { _, _ in Task { await reload() } }
        .refreshable { await list?.load(); await stats?.reload() }
        .sheet(isPresented: $showCreate) { PurchaseOrderCreateSheet { Task { await list?.load(); await stats?.reload() } } }
        .sheet(isPresented: $showPayables) { PayablesSheet() }
    }

    private func reload() async {
        guard let list else { return }
        list.query = ["search": search.isEmpty ? nil : search, "status": status.isEmpty ? nil : status, "supplier_id": supplierId.map(String.init)]
        await list.load()
    }
}

/// GET /v1/admin/purchase-orders/payables → {data: [{supplier, currency, orders, payable, paid, balance, balance_kes}], total_owed_kes, unconverted, basis}
struct PayablesSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    @State private var res: Resource<JSONValue>?

    var body: some View {
        VStack(spacing: 0) {
            HStack { Text("Owed to suppliers").font(Theme.Font.sectionTitle); Spacer(); Button("Close") { dismiss() } }.padding(Theme.Space.l)
            Divider()
            ScrollView {
                if let res {
                    LoadableView(res.state, retry: { res.load() }) { j in
                        VStack(alignment: .leading, spacing: Theme.Space.m) {
                            StatTile("Total owed (KES)", value: j["total_owed_kes"]?.money?.formatted() ?? "—", hint: "at today's reporting rate")
                            let rows = j["data"]?.array ?? []
                            if rows.isEmpty { Text("Nothing outstanding.").font(Theme.Font.secondary).foregroundStyle(Theme.textTertiary) }
                            ForEach(Array(rows.enumerated()), id: \.offset) { _, p in
                                let cur = p["currency"]?.string ?? "KES"
                                HStack {
                                    VStack(alignment: .leading, spacing: 2) {
                                        Text(p["supplier"]?.string ?? "").font(Theme.Font.body.weight(.semibold))
                                        Text("\(p["orders"]?.int ?? 0) order(s) · payable \(p["payable"].flatMap { Money(json: $0, currency: cur) }?.formatted() ?? "—") · paid \(p["paid"].flatMap { Money(json: $0, currency: cur) }?.formatted() ?? "—")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                    }
                                    Spacer()
                                    VStack(alignment: .trailing, spacing: 2) {
                                        MoneyText(p["balance"].flatMap { Money(json: $0, currency: cur) }, emphasis: true)
                                        if let k = p["balance_kes"], !k.isNull, cur != "KES" { Text("= \(Money(json: k, currency: "KES")?.formatted() ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                                        else if cur != "KES" { Text("no reporting rate").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning) }
                                    }
                                }
                                Divider()
                            }
                            if let basis = j["basis"]?.string { Text(basis).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                        }
                        .padding(Theme.Space.l)
                    }
                }
            }
        }
        .frame(minWidth: 460, idealWidth: 600, minHeight: 400)
        .task {
            if res == nil {
                let c = env.session.client
                res = Resource { try await c.get("/v1/admin/purchase-orders/payables").json ?? .null }
                res?.load()
            }
        }
    }
}

// MARK: - Create (POST /v1/admin/purchase-orders)

/// Line items carry the person's unit price. For a product the form asks
/// GET …/purchase-orders/item-costs/{p}?currency= for the book cost in the
/// PO's currency (null when there is no cost, no rate, or the viewer may
/// not see cost) and pre-fills it; the "all variants" grid is one line per
/// variant with a quantity each.
struct PurchaseOrderCreateSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let onCreated: () -> Void
    @State private var suppliers: Lookup?
    @State private var products: Lookup?
    @State private var materials: Lookup?
    @State private var currencies: [Record] = []
    @State private var supplierId: Int?
    @State private var currency = "KES"
    @State private var expected = Calendar.current.date(byAdding: .day, value: 14, to: Date()) ?? Date()
    @State private var shipping = ""
    @State private var tax = ""
    @State private var terms = ""
    @State private var notes = ""
    @State private var lines: [POLine] = []
    @State private var addingProduct: Int?
    @State private var addingMaterial: Int?
    @State private var grid: JSONValue?
    @State private var gridQty: [Int: String] = [:]
    @State private var gridPrice: [Int: String] = [:]
    @State private var error: APIError?

    struct POLine: Identifiable { let id = UUID(); let type: String; let itemId: Int; let variantId: Int?; let title: String; var qty: String; var price: String; let costMissing: Bool }

    private var valid: Bool {
        supplierId != nil && !lines.isEmpty && lines.allSatisfy { (JSONValue.decimal($0.qty)?.double ?? 0) > 0 && JSONValue.decimal($0.price) != nil } && (currencies.first { $0.string("code") == currency }?["allowed"]?.bool ?? true)
    }

    var body: some View {
        ModuleFormSheet("New purchase order", submitLabel: "Create draft", canSubmit: valid, error: error, submit: submit) {
            if let suppliers, let products, let materials {
                ModuleField("Supplier", error: error?.errors["supplier_id"]?.first) { LookupPicker(label: "Supplier", lookup: suppliers, selection: $supplierId) }
                HStack {
                    ModuleField("Currency", error: error?.errors["currency"]?.first) {
                        Picker("Currency", selection: $currency) {
                            ForEach(currencies) { c in
                                let code = c.string("code") ?? ""
                                Text(c["allowed"]?.bool == true ? code : "\(code) (no reporting rate)").tag(code)
                            }
                        }
                        .onChange(of: currency) { _, _ in lines.removeAll(); grid = nil }
                    }
                    ModuleField("Expected delivery", error: error?.errors["expected_delivery_date"]?.first) { DatePicker("", selection: $expected, in: Date()..., displayedComponents: .date).labelsHidden() }
                }
                ModuleField("Add a product") {
                    HStack {
                        LookupPicker(label: "Product", lookup: products, selection: $addingProduct)
                        Button("Add") { Task { await addProduct(allVariants: false) } }.disabled(addingProduct == nil)
                        Button("All variants…") { Task { await addProduct(allVariants: true) } }.disabled(addingProduct == nil)
                    }
                }
                if let grid { variantGrid(grid) }
                ModuleField("Add a material") {
                    HStack {
                        LookupPicker(label: "Material", lookup: materials, selection: $addingMaterial)
                        Button("Add") { addMaterial(materials) }.disabled(addingMaterial == nil)
                    }
                }
                if !lines.isEmpty {
                    Text("Lines (\(currency))").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).textCase(.uppercase)
                    ForEach($lines) { $l in
                        HStack {
                            VStack(alignment: .leading) { Text(l.title).font(Theme.Font.secondary); if l.costMissing { Text("No book cost — enter the agreed price").font(Theme.Font.caption).foregroundStyle(Theme.Palette.amber) } }
                            Spacer()
                            TextField("Qty", text: $l.qty).textFieldStyle(.roundedBorder).frame(width: 70)
                            TextField("Unit price", text: $l.price).textFieldStyle(.roundedBorder).frame(width: 110)
                            Button { lines.removeAll { $0.id == l.id } } label: { Image(systemName: "minus.circle") }.buttonStyle(.plain).foregroundStyle(Theme.Palette.danger)
                        }
                    }
                    FieldError(error?.errors.first { $0.key.hasPrefix("items") }?.value.first)
                }
                HStack {
                    ModuleField("Shipping (\(currency))") { TextField("0", text: $shipping).textFieldStyle(.roundedBorder) }
                    ModuleField("Tax (\(currency))") { TextField("0", text: $tax).textFieldStyle(.roundedBorder) }
                }
                ModuleField("Payment terms") { TextField("e.g. 30 days", text: $terms).textFieldStyle(.roundedBorder) }
                ModuleField("Notes") { TextField("Notes", text: $notes, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
                Text("Totals are computed by the server when the draft is saved.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            }
        }
        .task {
            if suppliers == nil {
                let c = env.session.client
                suppliers = Lookup.suppliers(c); products = Lookup.products(c); materials = Lookup.materials(c)
                currencies = (try? await c.get("/v1/admin/purchase-orders/currencies").json?["data"]?.decode([Record].self)) ?? []
            }
        }
    }

    private func variantGrid(_ g: JSONValue) -> some View {
        let variants = g["variants"]?.array ?? []
        return VStack(alignment: .leading, spacing: 6) {
            HStack { Text("\(g["product"]?["name"]?.string ?? "") — variants").font(Theme.Font.body.weight(.semibold)); Spacer(); Button("Close grid") { grid = nil } }
            if g["rate_missing"]?.bool == true { Text("No reporting rate for \(currency): prices must be typed.").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning) }
            ForEach(Array(variants.enumerated()), id: \.offset) { _, v in
                let vid = v["id"]?.int ?? 0
                HStack {
                    Text(v["variant_name"]?.string ?? v["sku"]?.string ?? "").font(Theme.Font.secondary).frame(maxWidth: .infinity, alignment: .leading)
                    TextField("Qty", text: Binding(get: { gridQty[vid] ?? "" }, set: { gridQty[vid] = $0 })).textFieldStyle(.roundedBorder).frame(width: 70)
                    TextField(v["unit_price"]?.isNull == false ? "Price" : "No cost — enter", text: Binding(get: { gridPrice[vid] ?? v["unit_price"]?.string ?? "" }, set: { gridPrice[vid] = $0 })).textFieldStyle(.roundedBorder).frame(width: 110)
                }
            }
            Button("Add the variants with a quantity") {
                for v in variants {
                    let vid = v["id"]?.int ?? 0
                    guard let q = gridQty[vid], (JSONValue.decimal(q)?.double ?? 0) > 0 else { continue }
                    lines.append(POLine(type: "product", itemId: g["product"]?["id"]?.int ?? 0, variantId: vid, title: "\(g["product"]?["name"]?.string ?? "") · \(v["variant_name"]?.string ?? v["sku"]?.string ?? "")", qty: q, price: gridPrice[vid] ?? v["unit_price"]?.string ?? "", costMissing: v["unit_price"]?.isNull != false))
                }
                grid = nil; gridQty = [:]; gridPrice = [:]
            }
        }
        .padding(Theme.Space.m).background(Theme.field, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
    }

    private func addProduct(allVariants: Bool) async {
        guard let pid = addingProduct else { return }
        let j = try? await env.session.client.get("/v1/admin/purchase-orders/item-costs/\(pid)", query: ["currency": currency]).json
        if allVariants, let j, !(j["variants"]?.array ?? []).isEmpty { grid = j; return }
        let p = j?["product"]
        lines.append(POLine(type: "product", itemId: pid, variantId: nil, title: p?["name"]?.string ?? Lookup.title(products?.options.first { $0.int("id") == pid } ?? Record(json: .null)), qty: "1", price: p?["unit_price"]?.string ?? "", costMissing: p?["unit_price"]?.isNull != false))
        addingProduct = nil
    }

    private func addMaterial(_ materials: Lookup) {
        guard let mid = addingMaterial, let m = materials.options.first(where: { $0.int("id") == mid }) else { return }
        // A material's book cost is KES; in another currency the person types the agreed price.
        let kes = env.session.canViewCost && currency == "KES" ? (m["unit_cost"]?.string ?? "") : ""
        lines.append(POLine(type: "material", itemId: mid, variantId: nil, title: "\(m.string("name") ?? "") (\(m.string("unit_of_measure") ?? ""))", qty: "1", price: kes, costMissing: kes.isEmpty))
        addingMaterial = nil
    }

    private func submit() async {
        let items: [JSONValue] = lines.map { l in
            var row: [String: JSONValue] = ["type": .string(l.type), "item_id": .int(l.itemId), "quantity": JSONValue.decimal(l.qty) ?? .number(0), "unit_price": JSONValue.decimal(l.price) ?? .number(0)]
            if let v = l.variantId { row["product_variant_id"] = .int(v) }
            return .object(row)
        }
        var body: [String: JSONValue] = ["supplier_id": .int(supplierId), "expected_delivery_date": .string(apiDay(expected)), "currency": .string(currency), "items": .array(items), "notes": .str(notes), "payment_terms": .str(terms)]
        if let s = JSONValue.decimal(shipping) { body["shipping_cost"] = s }
        if let t = JSONValue.decimal(tax) { body["tax"] = t }
        do {
            let r = try await env.session.client.post("/v1/admin/purchase-orders", json: .object(body))
            env.toasts.show(r.json?["message"]?.string ?? "Purchase order created"); onCreated(); dismiss()
            if let id = r.json?["purchase_order"]?["id"]?.int { env.navigator.push(.purchaseOrder(id: id)) }
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}
