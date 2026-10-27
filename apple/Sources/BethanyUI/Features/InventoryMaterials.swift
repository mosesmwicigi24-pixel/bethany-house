import SwiftUI
import BethanyKit

// Raw materials (RawMaterialsController) and product serials
// (ProductSerialController). Unit cost, cost_per_unit and stock_value are
// sent only to products.view_cost holders; the screens gate the same way.

// MARK: - Materials list (GET /v1/admin/inventory/materials → {data, meta, stats})

struct MaterialsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var list: RecordList?
    @State private var stats: JSONValue = .null
    @State private var search = ""
    @State private var category = ""
    @State private var lowOnly = false
    @State private var editing: Record?
    @State private var creating = false
    @State private var selected: Record?

    var body: some View {
        Page("Raw Materials", subtitle: "Fabric, thread, trims — what the garments are made from") {
            if let list {
                let s = stats
                StatsRow(tiles: [
                    ("Materials", s["total"]?.string ?? "0", nil),
                    ("Active", s["active"]?.string ?? "0", .success),
                    ("Low", s["low_stock"]?.string ?? "0", (s["low_stock"]?.int ?? 0) > 0 ? .warning : nil),
                    ("Out", s["out_of_stock"]?.string ?? "0", (s["out_of_stock"]?.int ?? 0) > 0 ? .danger : nil),
                ])
                HStack(spacing: Theme.Space.s) {
                    ModuleSearchField(text: $search, placeholder: "Code or name") { Task { await reload() } }
                    Picker("Category", selection: $category) {
                        Text("All categories").tag("")
                        ForEach(s["categories"]?.array?.compactMap(\.string) ?? [], id: \.self) { Text($0).tag($0) }
                    }
                    .frame(maxWidth: 180)
                    Toggle("Low stock", isOn: $lowOnly).toggleStyle(.switch).font(Theme.Font.secondary)
                    if env.session.can("inventory.adjust") { Button { creating = true } label: { Label("New", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
                }
                RecordListView(list, emptyTitle: "No materials match", emptySymbol: "cube") { m in
                    ModuleRowCard(action: { selected = m }) {
                        VStack(alignment: .leading, spacing: 3) {
                            HStack { Text(m.string("name") ?? "Material").font(Theme.Font.body.weight(.semibold)); StatusBadge(m.string("stock_status"), tone: stockTone(m.string("stock_status"))); if m["is_active"]?.bool == false { StatusBadge("inactive", tone: .neutral) } }
                            Text([m.string("code"), m.string("category")].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                            HStack {
                                Text("\(quantityText(m["total_stock"])) \(m.string("unit_of_measure") ?? "") on hand · reorder at \(quantityText(m["reorder_point"]))").font(Theme.Font.secondary).monospacedDigit()
                                Spacer()
                                if env.session.canViewCost, let c = m["unit_cost"]?.money { Text("\(c.formatted()) / \(m.string("unit_of_measure") ?? "unit")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                            }
                        }
                    }
                }
            }
        }
        .task { if list == nil { await reload() } }
        .onChange(of: category) { _, _ in Task { await reload() } }
        .onChange(of: lowOnly) { _, _ in Task { await reload() } }
        .refreshable { await reload() }
        .sheet(isPresented: $creating) { MaterialFormSheet(material: nil) { Task { await list?.load() } } }
        .sheet(item: $editing) { m in MaterialFormSheet(material: m) { Task { await list?.load() } } }
        .sheet(item: $selected) { m in MaterialDetailSheet(materialId: m.id, onEdit: { editing = m; selected = nil }, onChanged: { Task { await list?.load() } }) }
    }

    /// The low-stock toggle swaps the source: GET …/materials/low-stock
    /// answers `{data, count}` (no paging, no stats), so the header keeps
    /// the last full-list stats.
    private func reload() async {
        let path = lowOnly ? "/v1/admin/inventory/materials/low-stock" : "/v1/admin/inventory/materials"
        let l = RecordList(client: env.session.client, path: path, perPage: 30)
        if !lowOnly { l.query = ["search": search.isEmpty ? nil : search, "category": category.isEmpty ? nil : category] }
        list = l
        await l.load()
        if !lowOnly { stats = l.extras["stats"] ?? .null }
    }
}

// MARK: - Create / edit (POST, PUT …/materials[/{id}])

struct MaterialFormSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let material: Record?
    let onSaved: () -> Void
    @State private var code: String
    @State private var name: String
    @State private var description: String
    @State private var category: String
    @State private var uom: String
    @State private var unitCost: String
    @State private var reorder: String
    @State private var active: Bool
    @State private var error: APIError?

    init(material: Record?, onSaved: @escaping () -> Void) {
        self.material = material
        self.onSaved = onSaved
        _code = State(initialValue: material?.string("code") ?? "")
        _name = State(initialValue: material?.string("name") ?? "")
        _description = State(initialValue: material?.string("description") ?? "")
        _category = State(initialValue: material?.string("category") ?? "")
        _uom = State(initialValue: material?.string("unit_of_measure") ?? "")
        _unitCost = State(initialValue: material?["unit_cost"]?.string ?? "")
        _reorder = State(initialValue: material?["reorder_point"]?.string ?? "")
        _active = State(initialValue: material?["is_active"]?.bool ?? true)
    }

    private var valid: Bool { !code.isEmpty && !name.isEmpty && !uom.isEmpty && (material != nil || !env.session.canViewCost || JSONValue.decimal(unitCost) != nil) }

    var body: some View {
        ModuleFormSheet(material == nil ? "New material" : "Edit \(material?.string("name") ?? "material")", canSubmit: valid, error: error, submit: submit) {
            HStack {
                ModuleField("Code", error: error?.errors["code"]?.first) { TextField("FAB-001", text: $code).textFieldStyle(.roundedBorder) }
                ModuleField("Unit of measure", error: error?.errors["unit_of_measure"]?.first) { TextField("m, kg, pcs", text: $uom).textFieldStyle(.roundedBorder) }
            }
            ModuleField("Name", error: error?.errors["name"]?.first) { TextField("Name", text: $name).textFieldStyle(.roundedBorder) }
            ModuleField("Category") { TextField("Fabric, Trim…", text: $category).textFieldStyle(.roundedBorder) }
            ModuleField("Description") { TextField("Description", text: $description, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
            HStack {
                if env.session.canViewCost {
                    ModuleField("Unit cost (KES)", error: error?.errors["unit_cost"]?.first) { TextField("0.00", text: $unitCost).textFieldStyle(.roundedBorder) }
                }
                ModuleField("Reorder point", error: error?.errors["reorder_point"]?.first) { TextField("0", text: $reorder).textFieldStyle(.roundedBorder) }
            }
            if env.session.canViewCost, material != nil { Text("A unit-cost change past 5% comes back as a proposal for approval rather than applying at once.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
            Toggle("Active", isOn: $active)
        }
    }

    private func submit() async {
        var body: [String: JSONValue] = ["code": .string(code), "name": .string(name), "description": .str(description), "category": .str(category), "unit_of_measure": .string(uom), "is_active": .bool(active)]
        if let rp = JSONValue.decimal(reorder) { body["reorder_point"] = rp }
        if env.session.canViewCost, let uc = JSONValue.decimal(unitCost) { body["unit_cost"] = uc }
        do {
            let r: APIClient.Response
            if let m = material { r = try await env.session.client.put("/v1/admin/inventory/materials/\(m.id)", json: .object(body)) }
            else { r = try await env.session.client.post("/v1/admin/inventory/materials", json: .object(body)) }
            let proposal = r.json?["proposal"]
            env.toasts.show(proposal != nil && proposal?.isNull == false ? "Saved — the cost change waits for approval" : (r.json?["message"]?.string ?? "Material saved"), tone: proposal != nil && proposal?.isNull == false ? .warning : .success)
            onSaved(); dismiss()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}

// MARK: - Detail (GET …/materials/{id} → {material, transactions, bom_usage}; receive / adjust / delete)

struct MaterialDetailSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let materialId: String
    let onEdit: () -> Void
    let onChanged: () -> Void
    @State private var res: Resource<JSONValue>?
    @State private var movement: Movement?

    enum Movement: Identifiable { case receive, adjust; var id: Int { hashValue } }

    var body: some View {
        VStack(spacing: 0) {
            HStack { Text("Material").font(Theme.Font.sectionTitle); Spacer(); Button("Close") { dismiss() } }.padding(Theme.Space.l)
            Divider()
            ScrollView {
                if let res {
                    LoadableView(res.state, retry: { res.load() }) { j in
                        let m = j["material"] ?? .null
                        VStack(alignment: .leading, spacing: Theme.Space.m) {
                            HStack { Text(m["name"]?.string ?? "").font(Theme.Font.display(20)); StatusBadge(m["stock_status"]?.string, tone: stockTone(m["stock_status"]?.string)) }
                            Text([m["code"]?.string, m["category"]?.string, m["description"]?.string].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                            HStack {
                                StatTile("On hand", value: "\(quantityText(m["total_stock"])) \(m["unit_of_measure"]?.string ?? "")")
                                StatTile("Reorder at", value: quantityText(m["reorder_point"]))
                                if env.session.canViewCost { StatTile("Stock value", value: m["stock_value"]?.money?.formatted() ?? "—", hint: m["unit_cost"]?.money.map { "\($0.formatted()) per unit" }) }
                            }
                            HStack(spacing: Theme.Space.s) {
                                if env.session.can("procurement.receive") { Button { movement = .receive } label: { Label("Receive", systemImage: "tray.and.arrow.down") } }
                                if env.session.can("inventory.adjust") {
                                    Button { movement = .adjust } label: { Label("Adjust", systemImage: "slider.horizontal.3") }
                                    Button { onEdit() } label: { Label("Edit", systemImage: "pencil") }
                                    ConfirmButton("Delete material", message: "Removes the material. The server refuses one still used by a BOM or with stock.", destructive: true, action: { await delete() }) { Label("Delete", systemImage: "trash") }
                                }
                            }
                            .buttonStyle(.bordered)
                            let inv = m["inventory"]?.array ?? []
                            if !inv.isEmpty {
                                Text("By outlet").font(Theme.Font.sectionTitle)
                                ForEach(Array(inv.enumerated()), id: \.offset) { _, i in DetailRow(i["outlet"]?["name"]?.string ?? "Outlet \(i["outlet_id"]?.int ?? 0)", quantityText(i["quantity_on_hand"])) }
                            }
                            let usage = j["bom_usage"]?.array ?? []
                            if !usage.isEmpty {
                                Text("Used by").font(Theme.Font.sectionTitle)
                                ForEach(Array(usage.enumerated()), id: \.offset) { _, u in DetailRow(u["product_name"]?.string ?? u["sku"]?.string ?? "Product", "\(quantityText(u["quantity"])) \(u["unit_of_measure"]?.string ?? "") each") }
                            }
                            Text("Movements").font(Theme.Font.sectionTitle)
                            let tx = (j["transactions"]?.array ?? []).map(Record.init(json:))
                            if tx.isEmpty { Text("No movements yet.").font(Theme.Font.secondary).foregroundStyle(Theme.textTertiary) }
                            ForEach(tx) { t in
                                StockTransactionRow(t)
                                if env.session.canViewCost, let c = t["unit_cost"]?.money { Text("at \(c.formatted())").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                            }
                        }
                        .padding(Theme.Space.l)
                    }
                }
            }
        }
        .frame(minWidth: 460, idealWidth: 600, minHeight: 420)
        .task { if res == nil { load() } }
        .sheet(item: $movement) { mv in
            if let m = res?.value?["material"] { MaterialMovementSheet(material: Record(json: m), receive: mv == .receive) { load(); onChanged() } }
        }
    }

    private func load() {
        let c = env.session.client, id = materialId
        res = Resource { try await c.get("/v1/admin/inventory/materials/\(id)").json ?? .null }
        res?.load()
    }

    private func delete() async {
        do { _ = try await env.session.client.delete("/v1/admin/inventory/materials/\(materialId)"); env.toasts.show("Material deleted"); onChanged(); dismiss() } catch { env.toasts.error(error) }
    }
}

/// POST …/materials/{id}/receive {outlet_id, quantity, transaction_type, unit_cost?, notes?, reference?}
/// POST …/materials/{id}/adjust  {outlet_id, quantity_change, transaction_type, notes}
struct MaterialMovementSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let material: Record
    let receive: Bool
    let onDone: () -> Void
    @State private var outlets: Lookup?
    @State private var outletId: Int?
    @State private var quantity = ""
    @State private var type: String
    @State private var unitCost = ""
    @State private var notes = ""
    @State private var reference = ""
    @State private var error: APIError?

    init(material: Record, receive: Bool, onDone: @escaping () -> Void) {
        self.material = material
        self.receive = receive
        self.onDone = onDone
        _type = State(initialValue: receive ? "purchase" : "adjustment")
    }

    private var types: [String] { receive ? ["opening_stock", "purchase", "adjustment", "transfer_in"] : ["adjustment", "damaged", "correction", "transfer_out"] }
    private var valid: Bool { outletId != nil && (JSONValue.decimal(quantity)?.double ?? 0) != 0 && (receive || !notes.trimmingCharacters(in: .whitespaces).isEmpty) }

    var body: some View {
        ModuleFormSheet("\(receive ? "Receive" : "Adjust") \(material.string("name") ?? "")", submitLabel: receive ? "Receive" : "Adjust", canSubmit: valid, error: error, submit: submit) {
            if let outlets { ModuleField("Outlet", error: error?.errors["outlet_id"]?.first) { LookupPicker(label: "Outlet", lookup: outlets, selection: $outletId) } }
            ModuleField("Type") { Picker("Type", selection: $type) { ForEach(types, id: \.self) { Text($0.humanized).tag($0) } } }
            ModuleField(receive ? "Quantity (\(material.string("unit_of_measure") ?? ""))" : "Change (± \(material.string("unit_of_measure") ?? ""))", error: error?.errors[receive ? "quantity" : "quantity_change"]?.first) { TextField("0", text: $quantity).textFieldStyle(.roundedBorder) }
            if receive {
                if env.session.canViewCost { ModuleField("Unit cost (optional)") { TextField("0.00", text: $unitCost).textFieldStyle(.roundedBorder) } }
                ModuleField("Reference") { TextField("Delivery note, GRN…", text: $reference).textFieldStyle(.roundedBorder) }
            }
            ModuleField(receive ? "Notes" : "Notes (required)", error: error?.errors["notes"]?.first) { TextField("", text: $notes, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
        }
        .task { if outlets == nil { outlets = Lookup.outlets(env.session.client) } }
    }

    private func submit() async {
        var body: [String: JSONValue] = ["outlet_id": .int(outletId), "transaction_type": .string(type), "notes": .str(notes)]
        if receive {
            body["quantity"] = JSONValue.decimal(quantity) ?? .number(0)
            body["reference"] = .str(reference)
            if env.session.canViewCost, let uc = JSONValue.decimal(unitCost) { body["unit_cost"] = uc }
        } else {
            body["quantity_change"] = JSONValue.decimal(quantity) ?? .number(0)
        }
        do {
            let r = try await env.session.client.post("/v1/admin/inventory/materials/\(material.id)/\(receive ? "receive" : "adjust")", json: .object(body))
            env.toasts.show(r.json?["message"]?.string ?? "Saved"); onDone(); dismiss()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}

// MARK: - Product serials (GET /v1/admin/product-serials → {data, meta, summary, aged_count})

struct SerialsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var list: RecordList?
    @State private var search = ""
    @State private var status = ""
    @State private var agedOnly = false
    @State private var reconciling = false

    static let statuses = ["in_production", "in_stock", "sold", "dispatched", "returned", "cancelled", "missing"]

    var body: some View {
        Page("Product Serials", subtitle: "Every finished piece, one serial each") {
            if let list {
                let s = list.extras["summary"] ?? .null
                StatsRow(tiles: [
                    ("In stock", s["in_stock"]?.string ?? "0", .success),
                    ("In production", s["in_production"]?.string ?? "0", .amber),
                    ("Sold", s["sold"]?.string ?? "0", nil),
                    ("Aged \(list.extras["aging_days"]?.int ?? 0)+ days", list.extras["aged_count"]?.string ?? "0", (list.extras["aged_count"]?.int ?? 0) > 0 ? .warning : nil),
                ])
                HStack(spacing: Theme.Space.s) {
                    ScanField(placeholder: "Serial, product or order", text: $search).onSubmit { Task { await reload() } }
                    Picker("Status", selection: $status) { Text("Any").tag(""); ForEach(Self.statuses, id: \.self) { Text($0.humanized).tag($0) } }.frame(maxWidth: 160)
                    Toggle("Aged", isOn: $agedOnly).toggleStyle(.switch).font(Theme.Font.secondary)
                    if env.session.can("inventory.adjust") { Button { reconciling = true } label: { Label("Reconcile", systemImage: "checklist") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
                }
                .onChange(of: search) { _, _ in Task { await reload() } }
                RecordListView(list, emptyTitle: "No serials match", emptySymbol: "barcode") { r in
                    SectionCard {
                        HStack(alignment: .top) {
                            VStack(alignment: .leading, spacing: 3) {
                                HStack { Text(r.string("serial_number") ?? "").font(Theme.Font.mono); StatusBadge(r.status); if r["aged"]?.bool == true { StatusBadge("aged", tone: .warning) } }
                                Text([r.string("product_name"), r.string("product_sku"), r.string("outlet_name")].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.secondary)
                                HStack(spacing: Theme.Space.m) {
                                    if let po = r.string("production_order_number"), let pid = r.int("production_order_id") { Button(po) { env.navigator.push(.productionOrder(id: pid)) }.font(Theme.Font.caption) }
                                    if let on = r.string("order_number"), let oid = r.int("order_id") { Button(on) { env.navigator.push(.order(id: oid)) }.font(Theme.Font.caption) }
                                    if let d = r.int("days_in_stock") { Text("\(d) days in stock").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                                }
                            }
                            Spacer()
                            DateText(r.date("stocked_at") ?? r.date("created_at"), style: .date)
                        }
                    }
                }
            }
        }
        .task {
            if list == nil {
                list = RecordList(client: env.session.client, path: "/v1/admin/product-serials", perPage: 30)
                await list?.load()
            }
        }
        .onChange(of: status) { _, _ in Task { await reload() } }
        .onChange(of: agedOnly) { _, _ in Task { await reload() } }
        .refreshable { await list?.load() }
        .sheet(isPresented: $reconciling) { ReconcileSheet { Task { await list?.load() } } }
    }

    private func reload() async {
        guard let list else { return }
        list.query = ["search": search.isEmpty ? nil : search, "status": status.isEmpty ? nil : status, "aged": agedOnly ? "1" : nil]
        await list.load()
    }
}

/// POST /v1/admin/product-serials/reconcile {product_id, outlet_id?, serials: [], flag_missing?}
struct ReconcileSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let onDone: () -> Void
    @State private var products: Lookup?
    @State private var outlets: Lookup?
    @State private var productId: Int?
    @State private var outletId: Int?
    @State private var serials: [String] = []
    @State private var flagMissing = false
    @State private var scanning = false
    @State private var result: JSONValue?
    @State private var error: APIError?

    var body: some View {
        ModuleFormSheet("Reconcile serials", submitLabel: "Compare with the system", canSubmit: productId != nil && !serials.isEmpty, error: error, submit: submit) {
            if let products, let outlets {
                ModuleField("Product", error: error?.errors["product_id"]?.first) { LookupPicker(label: "Product", lookup: products, selection: $productId) }
                ModuleField("Outlet (optional)") { LookupPicker(label: "Outlet", lookup: outlets, selection: $outletId) }
            }
            HStack {
                Text("\(serials.count) scanned").font(Theme.Font.secondary)
                Spacer()
                Button { scanning = true } label: { Label("Scan", systemImage: "barcode.viewfinder") }
                Button("Clear") { serials = [] }.disabled(serials.isEmpty)
            }
            ScrollView { Text(serials.joined(separator: "\n")).font(Theme.Font.mono).frame(maxWidth: .infinity, alignment: .leading) }.frame(maxHeight: 160)
            Toggle("Flag the ones not scanned as missing", isOn: $flagMissing)
            if let result {
                Divider()
                Text(result["message"]?.string ?? "").font(Theme.Font.body.weight(.semibold))
                DetailRow("Matched", result["matched_count"]?.string)
                let missing = result["missing"]?.array ?? []
                if !missing.isEmpty { Text("Missing: \(missing.compactMap { $0["serial_number"]?.string }.joined(separator: ", "))").font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
                let unexpected = result["unexpected"]?.array?.compactMap(\.string) ?? []
                if !unexpected.isEmpty { Text("Not on record: \(unexpected.joined(separator: ", "))").font(Theme.Font.secondary).foregroundStyle(Theme.Palette.warning) }
            }
        }
        .task { if products == nil { products = Lookup.products(env.session.client); outlets = Lookup.outlets(env.session.client) } }
        .sheet(isPresented: $scanning) { ScanView(title: "Scan serials") { v in if !serials.contains(v) { serials.append(v) } } }
    }

    private func submit() async {
        do {
            let r = try await env.session.client.post("/v1/admin/product-serials/reconcile", json: .object([
                "product_id": .int(productId), "outlet_id": .int(outletId), "serials": .array(serials.map { JSONValue.string($0) }), "flag_missing": .bool(flagMissing),
            ]))
            result = r.json
            onDone()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}
