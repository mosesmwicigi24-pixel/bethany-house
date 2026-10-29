import SwiftUI
import BethanyKit

// MARK: - WIP board (GET /v1/admin/production-orders?status=<board statuses>)

/// Orders moving through the pipeline, one column per status (the console's
/// kanban): pending → in progress → on hold → QC pending → passed → failed.
/// Columns side by side on a wide window; stacked sections on a phone.
struct WIPBoardView: View {
    @Environment(AppEnvironment.self) private var env
    #if os(iOS)
    @Environment(\.horizontalSizeClass) private var sizeClass
    private var compact: Bool { sizeClass == .compact }
    #else
    private var compact: Bool { false }
    #endif
    @State private var list: RecordList?
    @State private var people: Lookup?
    @State private var personId: Int?
    @State private var personTasks: [Record] = []

    var body: some View {
        Page("Work In Progress", subtitle: "Live view of orders moving through the floor") {
            HStack {
                if env.session.can("production.manage_assignees"), let people {
                    LookupPicker(label: "Everyone", lookup: people, selection: $personId).frame(maxWidth: 260)
                        .onChange(of: personId) { _, id in Task { await loadPerson(id) } }
                }
                Spacer()
                Button { Task { await list?.load() } } label: { Image(systemName: "arrow.clockwise") }
            }
            if let list {
                LoadableView(list.state, retry: { Task { await list.load() } }, isEmpty: { $0.isEmpty }, empty: { EmptyState("Nothing in progress", symbol: "rectangle.split.3x1") }) { rows in
                    let visible = filtered(rows)
                    if compact {
                        ForEach(ProductionStatus.board, id: \.self) { s in column(s, visible.filter { $0.status == s }) }
                    } else {
                        ScrollView(.horizontal, showsIndicators: true) {
                            HStack(alignment: .top, spacing: Theme.Space.m) {
                                ForEach(ProductionStatus.board, id: \.self) { s in column(s, visible.filter { $0.status == s }).frame(width: 280) }
                            }
                        }
                    }
                }
            }
        }
        .task {
            if list == nil {
                list = RecordList(client: env.session.client, path: "/v1/admin/production-orders", query: ["status": ProductionStatus.board.joined(separator: ",")], perPage: 100)
                people = Lookup.assignable(env.session.client)
                await list?.load()
            }
        }
        .refreshable { await list?.load() }
    }

    private func filtered(_ rows: [Record]) -> [Record] {
        guard personId != nil else { return rows }
        let ids = Set(personTasks.compactMap { $0["production_order"]?["id"]?.int ?? $0.int("production_order_id") })
        return rows.filter { ids.contains($0.int("id") ?? -1) }
    }

    private func loadPerson(_ id: Int?) async {
        guard let id else { personTasks = []; return }
        let j = try? await env.session.client.get("/v1/admin/production-tasks", query: ["tailor_id": String(id), "per_page": "200"]).json
        personTasks = j?["data"]?.decode([Record].self) ?? []
    }

    private func column(_ status: String, _ orders: [Record]) -> some View {
        VStack(alignment: .leading, spacing: Theme.Space.s) {
            HStack { StatusBadge(status); Text("\(orders.count)").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
            if orders.isEmpty { Text("—").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary).padding(.vertical, 8) }
            ForEach(orders) { r in
                let o = ProductionOrderRecord(r)
                ModuleRowCard(action: { env.navigator.push(.productionOrder(id: o.id)) }) {
                    VStack(alignment: .leading, spacing: 3) {
                        HStack { Text(o.number).font(Theme.Font.body.weight(.semibold)); PriorityBadge(priority: o.priority); if o.isOverdue { StatusBadge("overdue", tone: .danger) } }
                        Text("\(o.quantity) × \(o.productName)").font(Theme.Font.secondary).lineLimit(2)
                        if let c = o.customerLabel { Text(c).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                        HStack { DateText(o.dueDate, style: .date); Spacer(); Text("\(o.percent)%").font(Theme.Font.caption).monospacedDigit() }
                        let names = o.tasks.compactMap { Names.person($0["assigned_to"]) }
                        if !names.isEmpty { Text(Array(Set(names)).sorted().joined(separator: ", ")).font(Theme.Font.caption).foregroundStyle(Theme.Palette.brandDeep).lineLimit(1) }
                    }
                }
            }
        }
        .padding(Theme.Space.s)
        .background(Theme.canvas, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
    }
}

// MARK: - QC (GET /v1/admin/production-orders?status=qc_pending|qc_passed|qc_failed)

struct QCView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var list: RecordList?
    @State private var tab = "qc_pending"
    @State private var inspecting: Record?
    @State private var reworking: Record?

    var body: some View {
        Page("Quality Control", subtitle: "Inspect finished work before it is stocked") {
            if let list {
                let stats = list.extras["stats"] ?? .null
                StatsRow(tiles: [
                    ("Awaiting", stats["qc_pending"]?.string ?? "0", (stats["qc_pending"]?.int ?? 0) > 0 ? .amber : nil),
                    ("Passed", stats["qc_passed"]?.string ?? "0", .success),
                    ("Failed", stats["qc_failed"]?.string ?? "0", (stats["qc_failed"]?.int ?? 0) > 0 ? .danger : nil),
                ])
                Picker("", selection: $tab) { Text("Awaiting").tag("qc_pending"); Text("Passed").tag("qc_passed"); Text("Failed").tag("qc_failed"); Text("All").tag("qc_pending,qc_passed,qc_failed,completed") }
                    .pickerStyle(.segmented)
                RecordListView(list, emptyTitle: "Nothing here", emptySymbol: "checkmark.shield") { r in
                    let o = ProductionOrderRecord(r)
                    SectionCard {
                        HStack(alignment: .top) {
                            VStack(alignment: .leading, spacing: 3) {
                                HStack { Text(o.number).font(Theme.Font.body.weight(.semibold)); StatusBadge(o.status); PriorityBadge(priority: o.priority) }
                                Text("\(o.quantity) × \(o.productName)").font(Theme.Font.secondary)
                                if let c = o.customerLabel { Text(c).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                                DateText(o.dueDate, style: .date)
                            }
                            Spacer()
                            Button("Open") { env.navigator.push(.productionOrder(id: o.id)) }
                        }
                        HStack {
                            if o.status == "qc_pending", env.session.can("production.submit_qc") {
                                Button { inspecting = r } label: { Label("Inspect", systemImage: "checkmark.shield") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                            }
                            if o.status == "qc_failed", env.session.can("production.submit_qc") {
                                Button { reworking = r } label: { Label("Send back for rework", systemImage: "arrow.uturn.backward") }.buttonStyle(.bordered)
                            }
                            if o.status == "qc_passed", env.session.can("production.approve_qc") {
                                Button("Complete & stock…") { env.navigator.push(.productionOrder(id: o.id)) }.buttonStyle(.bordered)
                            }
                        }
                    }
                }
            }
        }
        .task {
            if list == nil {
                list = RecordList(client: env.session.client, path: "/v1/admin/production-orders", query: ["status": tab], perPage: 100)
                await list?.load()
            }
        }
        .onChange(of: tab) { _, t in list?.query["status"] = t; Task { await list?.load() } }
        .refreshable { await list?.load() }
        .sheet(item: $inspecting) { r in QCSheet(order: ProductionOrderRecord(r)) { Task { await list?.load() } } }
        .sheet(item: $reworking) { r in ReworkSheet(order: ProductionOrderRecord(r)) { Task { await list?.load() } } }
    }
}

// MARK: - Calendar (GET /v1/admin/production/schedule + orders by due date)

struct ProductionCalendarView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var schedule: Resource<JSONValue>?
    @State private var month = Date()

    var body: some View {
        Page("Production Calendar", subtitle: "Delivery promises against the floor's load") {
            if let schedule {
                LoadableView(schedule.state, retry: { schedule.load() }) { s in
                    StatsRow(tiles: [
                        ("Active orders", s["active_count"]?.string ?? "0", nil),
                        ("Earliest free slot", s["earliest_free_slot"]?.string ?? "—", .info),
                    ])
                    let byStage = s["by_stage"]?.object ?? [:]
                    if !byStage.isEmpty {
                        SectionCard("Open work per stage") {
                            ForEach(byStage.keys.sorted(), id: \.self) { k in
                                DetailRow(s["stage_names"]?[k]?.string ?? "Stage \(k)", byStage[k]?.string)
                            }
                        }
                    }
                    monthView(s["upcoming_orders"]?.array ?? [])
                }
            }
        }
        .task {
            if schedule == nil {
                let c = env.session.client
                schedule = Resource { try await c.get("/v1/admin/production/schedule").json ?? .null }
                schedule?.load()
            }
        }
        .refreshable { await schedule?.reload() }
    }

    private func monthView(_ orders: [JSONValue]) -> some View {
        let cal = Calendar.current
        let start = cal.date(from: cal.dateComponents([.year, .month], from: month)) ?? month
        let days = cal.range(of: .day, in: .month, for: start)?.count ?? 30
        let byDay = Dictionary(grouping: orders.filter { $0["due_date"]?.date != nil }) { HubDates.dayString($0["due_date"]!.date!) }
        let f = DateFormatter(); f.dateFormat = "LLLL yyyy"
        return SectionCard {
            HStack {
                Button { month = cal.date(byAdding: .month, value: -1, to: month) ?? month } label: { Image(systemName: "chevron.left") }
                Spacer(); Text(f.string(from: start)).font(Theme.Font.sectionTitle); Spacer()
                Button { month = cal.date(byAdding: .month, value: 1, to: month) ?? month } label: { Image(systemName: "chevron.right") }
            }
            .buttonStyle(.plain)
            ForEach(1...days, id: \.self) { d in
                let date = cal.date(byAdding: .day, value: d - 1, to: start)!
                let key = HubDates.dayString(date)
                if let due = byDay[key], !due.isEmpty {
                    VStack(alignment: .leading, spacing: 4) {
                        HStack { DateText(date, style: .date); if date < cal.startOfDay(for: Date()) { StatusBadge("past due", tone: .danger) } }
                        ForEach(Array(due.enumerated()), id: \.offset) { _, o in
                            HStack {
                                Text(o["order_number"]?.string ?? "").font(Theme.Font.body.weight(.semibold))
                                StatusBadge(o["status"]?.string)
                                PriorityBadge(priority: o["priority"]?.string)
                                Text("\(o["quantity"]?.int ?? 0) × \(o["product_name"]?.string ?? "")").font(Theme.Font.secondary).lineLimit(1)
                                Spacer()
                                if let c = o["customer_name"]?.string { Text(c).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                                Text("\(o["completion_percentage"]?.int ?? 0)%").font(Theme.Font.caption).monospacedDigit()
                            }
                        }
                    }
                    .padding(.vertical, 4)
                    Divider()
                }
            }
            if !byDay.keys.contains(where: { $0.hasPrefix(String(HubDates.dayString(start).prefix(7))) }) {
                Text("No due dates this month.").font(Theme.Font.secondary).foregroundStyle(Theme.textTertiary)
            }
        }
    }
}

// MARK: - Bill of Materials (GET /v1/admin/products/{p}/bom → {data, product})

struct BomView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var products: Lookup?
    @State private var productId: Int?
    @State private var boms: Resource<JSONValue>?
    @State private var editing: BomEdit?

    struct BomEdit: Identifiable { let id = UUID(); let productId: Int; let bom: JSONValue? }

    var body: some View {
        Page("Bill of Materials", subtitle: "What each garment is made of") {
            if let products {
                HStack {
                    LookupPicker(label: "Product", lookup: products, selection: $productId).frame(maxWidth: 360)
                    Spacer()
                    if let pid = productId, env.session.can("bom.edit") {
                        Button { editing = BomEdit(productId: pid, bom: nil) } label: { Label("New version", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                    }
                }
            }
            if productId == nil { EmptyState("Pick a producible product", message: "Its BOM versions are listed here; the active one drives material requirements.", symbol: "list.bullet.rectangle") }
            if let boms {
                LoadableView(boms.state, retry: { boms.load() }) { j in
                    let versions = j["data"]?.array ?? []
                    if versions.isEmpty { EmptyState("No BOM yet for this product", symbol: "list.bullet.rectangle") }
                    ForEach(Array(versions.enumerated()), id: \.offset) { _, b in bomCard(b) }
                }
            }
        }
        .task { if products == nil { products = Lookup.products(env.session.client, producibleOnly: true) } }
        .onChange(of: productId) { _, id in load(id) }
        .sheet(item: $editing) { e in ProductionBomEditSheet(productId: e.productId, bom: e.bom) { load(productId) } }
    }

    private func load(_ id: Int?) {
        guard let id else { boms = nil; return }
        let c = env.session.client
        boms = Resource { try await c.get("/v1/admin/products/\(id)/bom").json ?? .null }
        boms?.load()
    }

    private func bomCard(_ b: JSONValue) -> some View {
        SectionCard {
            HStack {
                Text("Version \(b["version"]?.int ?? 0)").font(Theme.Font.sectionTitle)
                if b["is_active"]?.bool == true { StatusBadge("active", tone: .success) }
                if let v = b["variant"]?["variant_name"]?.string { StatusBadge(v, tone: .info) }
                Spacer()
                if env.session.canViewCost, let t = b["total_cost"]?.money { MoneyText(t, emphasis: true) }
            }
            if let n = b["notes"]?.string, !n.isEmpty { Text(n).font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary) }
            ForEach(Array((b["items"]?.array ?? []).enumerated()), id: \.offset) { _, it in
                HStack {
                    VStack(alignment: .leading, spacing: 1) {
                        Text(it["material"]?["name"]?.string ?? "Material \(it["material_id"]?.int ?? 0)").font(Theme.Font.body)
                        if let code = it["material"]?["code"]?.string { Text(code).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                    }
                    Spacer()
                    Text("\(quantityText(it["quantity"])) \(it["unit_of_measure"]?.string ?? "")").font(Theme.Font.secondary).monospacedDigit()
                    if env.session.canViewCost, let lc = it["line_cost"]?.money { Text(lc.formatted()).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).frame(width: 110, alignment: .trailing) }
                }
            }
            if env.session.can("bom.edit") {
                HStack {
                    if b["is_active"]?.bool != true, let pid = productId, let bid = b["id"]?.int {
                        Button("Make active") { Task { await activate(pid, bid) } }
                    }
                    if let pid = productId { Button("Edit as new version") { editing = BomEdit(productId: pid, bom: b) } }
                    Spacer()
                    if let pid = productId, let bid = b["id"]?.int {
                        ConfirmButton("Delete version \(b["version"]?.int ?? 0)", message: "Removes this BOM version.", destructive: true, action: { await delete(pid, bid) }) { Text("Delete") }
                    }
                }
                .font(Theme.Font.secondary)
            }
        }
    }

    private func activate(_ pid: Int, _ bid: Int) async {
        do { _ = try await env.session.client.put("/v1/admin/products/\(pid)/bom/\(bid)/activate"); env.toasts.show("BOM activated"); load(pid) } catch { env.toasts.error(error) }
    }

    private func delete(_ pid: Int, _ bid: Int) async {
        do { _ = try await env.session.client.delete("/v1/admin/products/\(pid)/bom/\(bid)"); env.toasts.show("BOM deleted"); load(pid) } catch { env.toasts.error(error) }
    }
}

/// POST /products/{p}/bom (new version) — {product_variant_id?, notes, items: [{material_id, quantity, unit_of_measure, notes}]}.
struct ProductionBomEditSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let productId: Int
    let bom: JSONValue?
    let onSaved: () -> Void
    @State private var materials: Lookup?
    @State private var notes: String
    @State private var lines: [Line]
    @State private var error: APIError?

    struct Line: Identifiable { let id = UUID(); var materialId: Int?; var quantity: String; var uom: String; var notes: String }

    init(productId: Int, bom: JSONValue?, onSaved: @escaping () -> Void) {
        self.productId = productId
        self.bom = bom
        self.onSaved = onSaved
        _notes = State(initialValue: bom?["notes"]?.string ?? "")
        _lines = State(initialValue: (bom?["items"]?.array ?? []).map { Line(materialId: $0["material_id"]?.int, quantity: $0["quantity"]?.string ?? "", uom: $0["unit_of_measure"]?.string ?? "", notes: $0["notes"]?.string ?? "") })
    }

    private var valid: Bool { !lines.isEmpty && lines.allSatisfy { $0.materialId != nil && (Double($0.quantity) ?? 0) > 0 && !$0.uom.isEmpty } }

    var body: some View {
        ModuleFormSheet(bom == nil ? "New BOM version" : "New version from v\(bom?["version"]?.int ?? 0)", submitLabel: "Save as new version", canSubmit: valid, error: error, submit: submit) {
            if let materials {
                ForEach($lines) { $line in
                    HStack {
                        LookupPicker(label: "Material", lookup: materials, selection: $line.materialId)
                            .onChange(of: line.materialId) { _, id in
                                if line.uom.isEmpty, let m = materials.options.first(where: { $0.int("id") == id }) { line.uom = m.string("unit_of_measure") ?? "" }
                            }
                        TextField("Qty", text: $line.quantity).textFieldStyle(.roundedBorder).frame(width: 70)
                        TextField("Unit", text: $line.uom).textFieldStyle(.roundedBorder).frame(width: 70)
                        Button { lines.removeAll { $0.id == line.id } } label: { Image(systemName: "minus.circle") }.buttonStyle(.plain).foregroundStyle(Theme.Palette.danger)
                    }
                }
                Button { lines.append(Line(materialId: nil, quantity: "", uom: "", notes: "")) } label: { Label("Add material", systemImage: "plus") }
            }
            ModuleField("Notes") { TextField("Notes", text: $notes, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
            Text("Saving creates the next version and makes it active; the previous version is kept.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
        .task { if materials == nil { materials = Lookup.materials(env.session.client) } }
    }

    private func submit() async {
        let items: [JSONValue] = lines.map { l in
            .object(["material_id": .int(l.materialId), "quantity": JSONValue.decimal(l.quantity) ?? .number(0), "unit_of_measure": .string(l.uom), "notes": .str(l.notes)])
        }
        var body: [String: JSONValue] = ["notes": .str(notes), "items": .array(items)]
        if let v = bom?["product_variant_id"]?.int { body["product_variant_id"] = .int(v) }
        do {
            _ = try await env.session.client.post("/v1/admin/products/\(productId)/bom", json: .object(body))
            env.toasts.show("BOM saved"); onSaved(); dismiss()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}
