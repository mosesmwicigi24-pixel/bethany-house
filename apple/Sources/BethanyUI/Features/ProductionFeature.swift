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

    static func label(_ status: String) -> String {
        switch status {
        case "qc_pending": return "Awaiting QC"
        case "qc_passed": return "Passed QC"
        case "qc_failed": return "Failed QC"
        default: return status.humanized
        }
    }
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
                VStack(alignment: .leading, spacing: Theme.Space.s) {
                    ModuleSearchField(text: $search, placeholder: "Order, product, customer or phone") { Task { await reload() } }
                    ViewThatFits(in: .horizontal) {
                        HStack(spacing: Theme.Space.s) { queueFilters(stats: stats) }
                        VStack(alignment: .leading, spacing: 4) { queueFilters(stats: stats) }
                    }
                    ViewThatFits(in: .horizontal) {
                        HStack(spacing: Theme.Space.s) { filters }
                        VStack(alignment: .leading, spacing: Theme.Space.s) { filters }
                    }
                }
                RecordListView(list, emptyTitle: "No production orders match", emptySymbol: "hammer") { r in
                    ProductionOrderRow(order: ProductionOrderRecord(r))
                }
            }
        }
        #if os(iOS)
        .navigationBarTitleDisplayMode(.inline)
        #endif
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

    @ViewBuilder
    private func queueFilters(stats: JSONValue) -> some View {
        queueFilter("All orders", value: "")
        queueFilter("Overdue", value: "overdue", count: stats["overdue"]?.int)
        queueFilter("Awaiting QC", value: "qc_pending", count: stats["qc_pending"]?.int)
    }

    private func queueFilter(_ label: String, value: String, count: Int? = nil) -> some View {
        Button {
            status = value
        } label: {
            Text(count.map { "\(label) · \($0)" } ?? label)
                .font(.caption.weight(.semibold))
                .fixedSize()
                .padding(.horizontal, 9)
                .padding(.vertical, 7)
                .foregroundStyle(status == value ? .white : Theme.text)
                .background(status == value ? Theme.Palette.nav : Theme.card, in: RoundedRectangle(cornerRadius: 7))
                .frame(minHeight: Theme.tapTarget)
                .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityAddTraits(status == value ? .isSelected : [])
    }

    @ViewBuilder
    private var filters: some View {
        Picker("Status", selection: $status) {
            Text("All statuses").tag("")
            ForEach(ProductionStatus.all, id: \.self) { Text(ProductionStatus.label($0)).tag($0) }
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
            Button { showCreate = true } label: { Label("New order", systemImage: "plus") }
                .buttonStyle(.bordered)
                .tint(Theme.Palette.brandDeep)
        }
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
    var body: some View {
        ModuleRowCard(route: .productionOrder(id: order.id)) {
            VStack(alignment: .leading, spacing: 6) {
                Text("\(order.quantity) × \(order.productName)").font(.subheadline.weight(.semibold))
                Text(order.number).font(.caption).monospacedDigit().foregroundStyle(Theme.textSecondary)
                ViewThatFits(in: .horizontal) {
                    HStack(spacing: Theme.Space.s) { badges }
                    VStack(alignment: .leading, spacing: 4) { badges }
                }
                ViewThatFits(in: .horizontal) {
                    HStack(spacing: Theme.Space.s) { metadata }
                    VStack(alignment: .leading, spacing: 4) { metadata }
                }
                .font(.caption).foregroundStyle(Theme.textSecondary)
                ProgressView(value: Double(order.percent), total: 100).tint(Theme.Palette.brand)
            }
        }
    }

    @ViewBuilder
    private var badges: some View {
        if let status = order.status {
            StatusBadge(ProductionStatus.label(status), tone: status == "qc_pending" ? .amber : nil)
        }
        if let priority = order.priority, priority != "normal" { PriorityBadge(priority: priority) }
        if order.isOverdue { StatusBadge("Overdue", tone: .danger) }
    }

    @ViewBuilder
    private var metadata: some View {
        if let customer = order.customerLabel { Label(customer, systemImage: "person") }
        else if order.isCustomerJob {
            // As the console: a customer's job whose name is missing says so.
            Label(order.r["customer_order"]?["order_number"]?.string.map { "\($0) · name missing" } ?? "Name missing",
                  systemImage: "person.crop.circle.badge.questionmark")
                .foregroundStyle(Theme.Palette.warning)
        }
        else { Label("For stock", systemImage: "shippingbox") }
        if let date = order.dueDate { Label { DateText(date, style: .date) } icon: { Image(systemName: "calendar") } }
        if let stage = order.currentStage { Text(stage) }
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
