import SwiftUI
import BethanyKit

/// Production: My Tasks, Production Orders (list + detail), WIP board, QC,
/// Calendar and Bill of Materials. Endpoints and gates mirror
/// backend/routes/api.php (production-orders, production, production-tasks,
/// tailor/tasks, products/{id}/bom) and the console pages under
/// react-admin/src/pages/production.
@MainActor
enum ProductionFeature {
    static func register() {
        FeatureRegistry.register(.productionMyTasks) { _ in AnyView(MyTasksView()) }
        FeatureRegistry.register(.productionOrders) { _ in AnyView(ProductionOrdersView()) }
        FeatureRegistry.register({ if case .productionOrder = $0 { return true } else { return false } }) { route in
            if case .productionOrder(let id) = route { return AnyView(ProductionOrderDetailView(orderId: id)) }
            return AnyView(EmptyView())
        }
        FeatureRegistry.register(.wip) { _ in AnyView(WIPBoardView()) }
        FeatureRegistry.register(.qc) { _ in AnyView(QCView()) }
        FeatureRegistry.register(.productionCalendar) { _ in AnyView(ProductionCalendarView()) }
        FeatureRegistry.register(.bom) { _ in AnyView(BomView()) }
    }
}

/// The production order state machine (ProductionOrder::FLOOR_WORK_STATUSES
/// and the status guards in ProductionController).
enum ProductionStatus {
    static let all = ["draft", "pending", "in_progress", "on_hold", "qc_pending", "qc_passed", "qc_failed", "completed", "cancelled"]
    static let floorWork: Set<String> = ["pending", "in_progress", "on_hold"]
    static let cancellable: Set<String> = ["draft", "pending", "on_hold", "qc_failed"]
    static let editable: Set<String> = ["draft", "pending", "in_progress", "on_hold", "qc_pending", "qc_passed", "qc_failed"]
    static let board = ["pending", "in_progress", "on_hold", "qc_pending", "qc_passed", "qc_failed"]
    static let priorities = ["low", "normal", "high", "urgent"]

    static func acceptsFloorWork(_ s: String?) -> Bool { floorWork.contains(s ?? "") }
}

/// Typed reads over a production order payload (ProductionController index/show).
struct ProductionOrderRecord {
    let r: Record
    init(_ r: Record) { self.r = r }
    var id: Int { r.int("id") ?? 0 }
    var number: String { r.string("order_number") ?? "PRD-\(id)" }
    var status: String? { r.status }
    var priority: String? { r.string("priority") }
    var quantity: Int { r.int("quantity") ?? 0 }
    var productName: String { r.string("product_name") ?? r["product"]?["translations"]?[0]?["name"]?.string ?? r["product"]?["sku"]?.string ?? "Product" }
    var productSku: String? { r["product"]?["sku"]?.string }
    var image: String? { r.string("product_image") ?? r["product"]?["images"]?[0]?["image_url"]?.string }
    var dueDate: Date? { r.date("due_date") }
    var customerLabel: String? { r.string("customer_label") }
    var isCustomerJob: Bool { r["is_customer_order"]?.bool ?? (r["customer_order_id"]?.int != nil) }
    var percent: Int { r["progress"]?["percent"]?.int ?? r.int("completion_percentage") ?? 0 }
    var currentStage: String? { r.string("current_stage") }
    var tasks: [JSONValue] { r["tasks"]?.array ?? [] }
    var batches: [JSONValue] { r["batches"]?.array ?? [] }
    var allocations: [JSONValue] { r["material_allocations"]?.array ?? [] }
    var isOverdue: Bool {
        guard let d = dueDate, let s = status else { return false }
        return d < Date() && !["completed", "cancelled", "draft"].contains(s)
    }
}

// MARK: - Orders list (GET /v1/admin/production-orders → {data, meta, stats})

struct ProductionOrdersView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var list: RecordList?
    @State private var search = ""
    @State private var status = ""
    @State private var type = ""
    @State private var showCreate = false

    var body: some View {
        Page("Production Orders", subtitle: "Every job on the floor you may see") {
            if let list {
                let stats = list.extras["stats"] ?? .null
                StatsRow(tiles: [
                    ("Pending", stats["pending"]?.string ?? "0", nil),
                    ("In progress", stats["in_progress"]?.string ?? "0", .pink),
                    ("QC pending", stats["qc_pending"]?.string ?? "0", (stats["qc_pending"]?.int ?? 0) > 0 ? .amber : nil),
                    ("Overdue", stats["overdue"]?.string ?? "0", (stats["overdue"]?.int ?? 0) > 0 ? .danger : nil),
                ])
                HStack(spacing: Theme.Space.s) {
                    ModuleSearchField(text: $search, placeholder: "Order, product, customer or phone") { Task { await reload() } }
                    Picker("Status", selection: $status) {
                        Text("All statuses").tag("")
                        ForEach(ProductionStatus.all, id: \.self) { Text($0.humanized).tag($0) }
                        Text("Overdue").tag("overdue")
                    }
                    .frame(maxWidth: 180)
                    Picker("Type", selection: $type) {
                        Text("All types").tag("")
                        Text("For stock").tag("stock")
                        Text("Customer orders").tag("customer")
                    }
                    .frame(maxWidth: 160)
                    if env.session.can("production.raise_order") {
                        Button { showCreate = true } label: { Label("New", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                    }
                }
                RecordListView(list, emptyTitle: "No production orders match", emptySymbol: "hammer") { r in
                    ProductionOrderRow(order: ProductionOrderRecord(r)) { env.navigator.push(.productionOrder(id: ProductionOrderRecord(r).id)) }
                }
            }
        }
        .task {
            if list == nil {
                list = RecordList(client: env.session.client, path: "/v1/admin/production-orders", perPage: 20)
                await list?.load()
            }
        }
        .onChange(of: status) { _, _ in Task { await reload() } }
        .onChange(of: type) { _, _ in Task { await reload() } }
        .refreshable { await list?.load() }
        .sheet(isPresented: $showCreate) { ProductionOrderCreateSheet { Task { await list?.load() } } }
    }

    private func reload() async {
        guard let list else { return }
        list.query = [
            "search": search.isEmpty ? nil : search,
            "status": (status.isEmpty || status == "overdue") ? nil : status,
            "overdue": status == "overdue" ? "1" : nil,
            "type": type.isEmpty ? nil : type,
        ]
        await list.load()
    }
}

struct ProductionOrderRow: View {
    let order: ProductionOrderRecord
    let open: () -> Void
    var body: some View {
        ModuleRowCard(action: open) {
            VStack(alignment: .leading, spacing: 4) {
                HStack(spacing: Theme.Space.s) {
                    Text(order.number).font(Theme.Font.body.weight(.semibold))
                    StatusBadge(order.status)
                    PriorityBadge(priority: order.priority)
                    if order.isOverdue { StatusBadge("overdue", tone: .danger) }
                }
                Text("\(order.quantity) × \(order.productName)").font(Theme.Font.secondary)
                HStack(spacing: Theme.Space.s) {
                    if let c = order.customerLabel { Label(c, systemImage: "person").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                    else { Label("For stock", systemImage: "shippingbox").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                    if let d = order.dueDate { Label { DateText(d, style: .date) } icon: { Image(systemName: "calendar") }.font(Theme.Font.caption) }
                    if let s = order.currentStage { Text(s).font(Theme.Font.caption).foregroundStyle(Theme.Palette.brandDeep) }
                }
                ProgressView(value: Double(order.percent), total: 100).tint(Theme.Palette.brand)
            }
        }
    }
}

// MARK: - Create (POST /v1/admin/production-orders)

struct ProductionOrderCreateSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let onCreated: () -> Void
    @State private var products: Lookup?
    @State private var outlets: Lookup?
    @State private var productId: Int?
    @State private var variantId: Int?
    @State private var variants: [Record] = []
    @State private var quantity = "1"
    @State private var priority = "normal"
    @State private var dueDate = Calendar.current.date(byAdding: .day, value: 7, to: Date()) ?? Date()
    @State private var outletId: Int?
    @State private var customerJob = false
    @State private var customerOrderId = ""
    @State private var notes = ""
    @State private var measurements: [KeyValueRow] = []
    @State private var preferences: [KeyValueRow] = []
    @State private var error: APIError?

    var body: some View {
        ModuleFormSheet("New production order", submitLabel: "Create", canSubmit: productId != nil && (Int(quantity) ?? 0) > 0, error: error, submit: submit) {
            if let products, let outlets {
                ModuleField("Product", error: error?.errors["product_id"]?.first) {
                    LookupPicker(label: "Product", lookup: products, selection: $productId)
                        .onChange(of: productId) { _, id in Task { await loadVariants(id) } }
                }
                if !variants.isEmpty {
                    ModuleField("Variant") {
                        Picker("Variant", selection: $variantId) {
                            Text("Any / base product").tag(Int?.none)
                            ForEach(variants) { v in Text("\(v.string("variant_name") ?? v.string("sku") ?? "") ").tag(v.int("id")) }
                        }
                    }
                }
                HStack {
                    ModuleField("Quantity", error: error?.errors["quantity"]?.first) { TextField("1", text: $quantity).textFieldStyle(.roundedBorder) }
                    ModuleField("Priority") {
                        Picker("Priority", selection: $priority) { ForEach(ProductionStatus.priorities, id: \.self) { Text($0.humanized).tag($0) } }
                    }
                }
                ModuleField("Due date", error: error?.errors["due_date"]?.first) { DatePicker("", selection: $dueDate, in: Date()..., displayedComponents: .date).labelsHidden() }
                ModuleField("Outlet") { LookupPicker(label: "Outlet", lookup: outlets, selection: $outletId) }
                Toggle("Made for a customer order", isOn: $customerJob)
                if customerJob {
                    ModuleField("Sales order id", error: error?.errors["customer_order_id"]?.first) { TextField("Order id", text: $customerOrderId).textFieldStyle(.roundedBorder) }
                    KeyValueEditor(title: "Measurements", rows: $measurements)
                    KeyValueEditor(title: "Customer preferences", rows: $preferences)
                }
                ModuleField("Notes") { TextField("Notes", text: $notes, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...5) }
            }
        }
        .task {
            if products == nil {
                products = Lookup.products(env.session.client, producibleOnly: true)
                outlets = Lookup.outlets(env.session.client)
            }
        }
    }

    private func loadVariants(_ id: Int?) async {
        variantId = nil
        guard let id else { variants = []; return }
        let json = try? await env.session.client.get("/v1/admin/products/\(id)").json
        variants = (json?["product"]?["variants"] ?? json?["variants"])?.decode([Record].self) ?? []
    }

    private func submit() async {
        var body: [String: JSONValue] = [
            "product_id": .int(productId), "product_variant_id": .int(variantId),
            "quantity": .int(Int(quantity) ?? 1), "priority": .string(priority), "due_date": .string(apiDay(dueDate)),
            "outlet_id": .int(outletId), "notes": .str(notes), "is_customer_order": .bool(customerJob),
        ]
        if customerJob {
            body["customer_order_id"] = .int(Int(customerOrderId))
            body["measurements"] = .stringMap(measurements.map { ($0.key, $0.value) })
            body["customer_preferences"] = .stringMap(preferences.map { ($0.key, $0.value) })
        }
        do {
            let r = try await env.session.client.post("/v1/admin/production-orders", json: .object(body))
            let warnings = r.json?["warnings"]?.array?.compactMap(\.string) ?? []
            env.toasts.show(warnings.isEmpty ? "Production order created" : "Created — \(warnings.joined(separator: " "))", tone: warnings.isEmpty ? .success : .warning)
            onCreated()
            dismiss()
            if let id = r.json?["order"]?["id"]?.int { env.navigator.push(.productionOrder(id: id)) }
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}
