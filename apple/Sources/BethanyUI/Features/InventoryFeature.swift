import SwiftUI
import BethanyKit

/// Inventory: Stock Levels, Product Serials, Stock Adjustments, Stock
/// Transfers, Raw Materials and Low Stock Alerts. Endpoints mirror
/// backend/routes/api.php (inventory/*, product-serials) and the console
/// pages under react-admin/src/pages/inventory.
@MainActor
enum InventoryFeature {
    static func register() {
        FeatureRegistry.register(.stockLevels) { _ in AnyView(StockLevelsView()) }
        FeatureRegistry.register(.serials) { _ in AnyView(SerialsView()) }
        FeatureRegistry.register(.adjustments) { _ in AnyView(AdjustmentsView()) }
        FeatureRegistry.register({ if case .adjustment = $0 { return true } else { return false } }) { route in
            if case .adjustment(let id) = route { return AnyView(AdjustmentDetailView(adjustmentId: id)) }
            return AnyView(EmptyView())
        }
        FeatureRegistry.register(.transfers) { _ in AnyView(TransfersView()) }
        FeatureRegistry.register({ if case .transfer = $0 { return true } else { return false } }) { route in
            if case .transfer(let id) = route { return AnyView(TransferDetailView(transferId: id)) }
            return AnyView(EmptyView())
        }
        FeatureRegistry.register(.materials) { _ in AnyView(MaterialsView()) }
        FeatureRegistry.register(.lowStock) { _ in AnyView(LowStockView()) }
    }
}

/// Stock-status tone shared by stock levels, materials and alerts.
func stockTone(_ status: String?) -> StatusTone {
    switch status { case "out_of_stock": return .danger; case "low_stock": return .warning; default: return .success }
}

// MARK: - Stock levels (GET /v1/admin/inventory/stock-levels → {data, meta, stats})

struct StockLevelsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var list: RecordList?
    @State private var outlets: Lookup?
    @State private var search = ""
    @State private var outletId: Int?
    @State private var status = ""
    @State private var opening = false
    @State private var selected: Record?

    var body: some View {
        Page("Stock Levels", subtitle: "Finished goods on hand, per outlet") {
            if let list {
                let s = list.extras["stats"] ?? .null
                StatsRow(tiles: [
                    ("SKUs", s["total_skus"]?.string ?? "0", nil),
                    ("In stock", s["in_stock"]?.string ?? "0", .success),
                    ("Low", s["low_stock"]?.string ?? "0", (s["low_stock"]?.int ?? 0) > 0 ? .warning : nil),
                    ("Out", s["out_of_stock"]?.string ?? "0", (s["out_of_stock"]?.int ?? 0) > 0 ? .danger : nil),
                ])
                HStack(spacing: Theme.Space.s) {
                    ModuleSearchField(text: $search, placeholder: "SKU or product") { Task { await reload() } }
                    if let outlets { LookupPicker(label: "Outlet", lookup: outlets, selection: $outletId).frame(maxWidth: 200) }
                    Picker("Status", selection: $status) { Text("Any").tag(""); Text("In stock").tag("in_stock"); Text("Low").tag("low_stock"); Text("Out").tag("out_of_stock") }.frame(maxWidth: 140)
                    if env.session.can("inventory.adjust") { Button { opening = true } label: { Label("Opening stock", systemImage: "plus") } }
                }
                RecordListView(list, emptyTitle: "No stock rows match", emptySymbol: "chart.bar") { r in
                    ModuleRowCard(action: { selected = r }) {
                        VStack(alignment: .leading, spacing: 3) {
                            HStack { Text(r["product"]?["name"]?.string ?? "Product").font(Theme.Font.body.weight(.semibold)); StatusBadge(r.status, tone: stockTone(r.status)) }
                            Text([r["product"]?["sku"]?.string, r["variant"]?["variant_name"]?.string, r["outlet"]?["name"]?.string].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                            HStack(spacing: Theme.Space.l) {
                                stat("On hand", r["quantity_on_hand"])
                                stat("Reserved", r["quantity_reserved"])
                                stat("Available", r["quantity_available"])
                                stat("Reorder at", r["reorder_point"])
                            }
                        }
                    }
                }
            }
        }
        .task {
            if list == nil {
                list = RecordList(client: env.session.client, path: "/v1/admin/inventory/stock-levels", perPage: 30)
                outlets = Lookup.outlets(env.session.client)
                await list?.load()
            }
        }
        .onChange(of: outletId) { _, _ in Task { await reload() } }
        .onChange(of: status) { _, _ in Task { await reload() } }
        .refreshable { await list?.load() }
        .sheet(isPresented: $opening) { OpeningStockSheet { Task { await list?.load() } } }
        .sheet(item: $selected) { r in StockItemSheet(item: r) { Task { await list?.load() } } }
    }

    private func stat(_ label: String, _ v: JSONValue?) -> some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(label).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            Text(quantityText(v)).font(Theme.Font.secondary).monospacedDigit()
        }
    }

    private func reload() async {
        guard let list else { return }
        list.query = ["search": search.isEmpty ? nil : search, "outlet_id": outletId.map(String.init), "status": status.isEmpty ? nil : status]
        await list.load()
    }
}

/// One stock row: history (GET …/stock-levels/{id}/history) and reorder settings (PUT …/stock-levels/{id}).
struct StockItemSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let item: Record
    let onChanged: () -> Void
    @State private var history: RecordList?
    @State private var reorderPoint: String
    @State private var reorderQty: String
    @State private var error: APIError?

    init(item: Record, onChanged: @escaping () -> Void) {
        self.item = item
        self.onChanged = onChanged
        _reorderPoint = State(initialValue: item["reorder_point"]?.string ?? "0")
        _reorderQty = State(initialValue: item["reorder_quantity"]?.string ?? "0")
    }

    var body: some View {
        ModuleFormSheet(item["product"]?["name"]?.string ?? "Stock item", submitLabel: "Save thresholds", canSubmit: env.session.can("inventory.adjust"), error: error, submit: save) {
            HStack { StatusBadge(item.status, tone: stockTone(item.status)); Text(item["outlet"]?["name"]?.string ?? "").font(Theme.Font.secondary) }
            HStack {
                StatTile("On hand", value: quantityText(item["quantity_on_hand"]))
                StatTile("Reserved", value: quantityText(item["quantity_reserved"]))
                StatTile("Available", value: quantityText(item["quantity_available"]))
            }
            HStack {
                ModuleField("Reorder point", error: error?.errors["reorder_point"]?.first) { TextField("", text: $reorderPoint).textFieldStyle(.roundedBorder) }
                ModuleField("Reorder quantity", error: error?.errors["reorder_quantity"]?.first) { TextField("", text: $reorderQty).textFieldStyle(.roundedBorder) }
            }
            .disabled(!env.session.can("inventory.adjust"))
            Text("History").font(Theme.Font.sectionTitle).padding(.top, 8)
            if let history {
                RecordListView(history, emptyTitle: "No movements recorded", emptySymbol: "clock") { t in StockTransactionRow(t) }
            }
        }
        .task {
            if history == nil {
                history = RecordList(client: env.session.client, path: "/v1/admin/inventory/stock-levels/\(item.id)/history", perPage: 50)
                await history?.load()
            }
        }
    }

    private func save() async {
        do {
            _ = try await env.session.client.put("/v1/admin/inventory/stock-levels/\(item.id)", json: .object(["reorder_point": .int(Int(reorderPoint) ?? 0), "reorder_quantity": .int(Int(reorderQty) ?? 0)]))
            env.toasts.show("Stock settings updated"); onChanged(); dismiss()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}

struct StockTransactionRow: View {
    let t: Record
    init(_ t: Record) { self.t = t }
    var body: some View {
        let change = t["quantity_change"]?.double ?? 0
        HStack {
            VStack(alignment: .leading, spacing: 2) {
                Text(t.string("type_label") ?? t.string("transaction_type")?.humanized ?? "Movement").font(Theme.Font.body)
                Text([Names.person(t["created_by"]), t.string("notes")].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).lineLimit(2)
            }
            Spacer()
            VStack(alignment: .trailing, spacing: 2) {
                Text((change > 0 ? "+" : "") + quantityText(t["quantity_change"])).font(Theme.Font.body.weight(.semibold)).monospacedDigit().foregroundStyle(change < 0 ? Theme.Palette.danger : Theme.Palette.success)
                Text("\(quantityText(t["quantity_before"])) → \(quantityText(t["quantity_after"]))").font(Theme.Font.caption).monospacedDigit().foregroundStyle(Theme.textSecondary)
                DateText(t.date("created_at"), style: .dateTime)
            }
        }
        .padding(.vertical, 3)
    }
}

/// POST /v1/admin/inventory/stock-levels/opening {entries: [{product_id, product_variant_id?, outlet_id, quantity, reorder_point?, notes?}]}
struct OpeningStockSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let onSaved: () -> Void
    @State private var products: Lookup?
    @State private var outlets: Lookup?
    @State private var rows: [Row] = [Row()]
    @State private var error: APIError?

    struct Row: Identifiable { let id = UUID(); var productId: Int? = nil; var variantId: Int? = nil; var outletId: Int? = nil; var quantity = ""; var reorderPoint = ""; var notes = "" }

    private var valid: Bool { rows.contains { $0.productId != nil && $0.outletId != nil && Int($0.quantity) != nil } }

    var body: some View {
        ModuleFormSheet("Opening stock", submitLabel: "Save", canSubmit: valid, error: error, submit: submit) {
            if let products, let outlets {
                ForEach($rows) { $row in
                    VStack(alignment: .leading, spacing: 6) {
                        LookupPicker(label: "Product", lookup: products, selection: $row.productId)
                        HStack {
                            LookupPicker(label: "Outlet", lookup: outlets, selection: $row.outletId)
                            TextField("Qty", text: $row.quantity).textFieldStyle(.roundedBorder).frame(width: 80)
                            TextField("Reorder at", text: $row.reorderPoint).textFieldStyle(.roundedBorder).frame(width: 90)
                            Button { rows.removeAll { $0.id == row.id } } label: { Image(systemName: "minus.circle") }.buttonStyle(.plain).foregroundStyle(Theme.Palette.danger)
                        }
                    }
                    Divider()
                }
                Button { rows.append(Row()) } label: { Label("Add row", systemImage: "plus") }
                Text("Sets the opening count for a product at an outlet; rows for a variant are set from the product's stock page.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            }
        }
        .task { if products == nil { products = Lookup.products(env.session.client); outlets = Lookup.outlets(env.session.client) } }
    }

    private func submit() async {
        let entries: [JSONValue] = rows.compactMap { r in
            guard let p = r.productId, let o = r.outletId, let q = Int(r.quantity) else { return nil }
            var e: [String: JSONValue] = ["product_id": .int(p), "outlet_id": .int(o), "quantity": .int(q), "notes": .str(r.notes)]
            if let v = r.variantId { e["product_variant_id"] = .int(v) }
            if let rp = Int(r.reorderPoint) { e["reorder_point"] = .int(rp) }
            return .object(e)
        }
        do {
            let r = try await env.session.client.post("/v1/admin/inventory/stock-levels/opening", json: .object(["entries": .array(entries)]))
            env.toasts.show(r.json?["message"]?.string ?? "Opening stock saved"); onSaved(); dismiss()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}

// MARK: - Low stock alerts (GET /v1/admin/inventory/low-stock-alerts?type=)

struct LowStockView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var res: Resource<JSONValue>?
    @State private var type = "all"
    @State private var editing: Record?

    var body: some View {
        Page("Low Stock Alerts", subtitle: "Products and materials at or under their reorder point") {
            Picker("", selection: $type) { Text("All").tag("all"); Text("Products").tag("products"); Text("Materials").tag("materials") }.pickerStyle(.segmented).frame(maxWidth: 320)
            if let res {
                LoadableView(res.state, retry: { res.load() }) { j in
                    let s = j["summary"] ?? .null
                    StatsRow(tiles: [
                        ("Products out", s["products_out_of_stock"]?.string ?? "0", .danger),
                        ("Products low", s["products_low_stock"]?.string ?? "0", .warning),
                        ("Materials out", s["materials_out_of_stock"]?.string ?? "0", .danger),
                        ("Materials low", s["materials_low_stock"]?.string ?? "0", .warning),
                    ])
                    let alerts = (j["data"]?.array ?? []).map(Record.init(json:))
                    if alerts.isEmpty { EmptyState("Nothing is running low", symbol: "checkmark.circle") }
                    ForEach(alerts) { a in alertRow(a) }
                }
            }
        }
        .task { if res == nil { load() } }
        .onChange(of: type) { _, _ in load() }
        .refreshable { await res?.reload() }
        .sheet(item: $editing) { a in ThresholdSheet(alert: a) { res?.load() } }
    }

    private func load() {
        let c = env.session.client, t = type
        res = Resource { try await c.get("/v1/admin/inventory/low-stock-alerts", query: ["type": t == "all" ? nil : t]).json ?? .null }
        res?.load()
    }

    private func alertRow(_ a: Record) -> some View {
        let isProduct = a.string("type") == "product"
        return SectionCard {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 3) {
                    HStack {
                        Text(isProduct ? (a["product"]?["name"]?.string ?? "Product") : (a["material"]?["name"]?.string ?? "Material")).font(Theme.Font.body.weight(.semibold))
                        StatusBadge(a.string("severity"), tone: stockTone(a.string("severity")))
                        StatusBadge(isProduct ? "product" : "material", tone: .neutral)
                    }
                    Text([isProduct ? a["product"]?["sku"]?.string : a["material"]?["code"]?.string, a["variant"]?["variant_name"]?.string, a["outlet"]?["name"]?.string].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    Text("\(quantityText(a["quantity"])) \(a.string("unit") ?? "") on hand · reorder at \(quantityText(a["reorder_point"]))").font(Theme.Font.secondary).monospacedDigit()
                    ForEach(Array((a["by_outlet"]?.array ?? []).enumerated()), id: \.offset) { _, o in
                        Text("\(o["outlet_name"]?.string ?? ""): \(quantityText(o["quantity_on_hand"]))").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                    }
                }
                Spacer()
                if isProduct, env.session.can("inventory.adjust") { Button("Threshold…") { editing = a } }
                if !isProduct { Button("Raw materials") { env.navigator.go(.materials) } }
            }
        }
    }
}

/// PUT /v1/admin/inventory/low-stock-alerts/{inventoryItemId}/threshold {reorder_point, reorder_quantity?}
struct ThresholdSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let alert: Record
    let onSaved: () -> Void
    @State private var point: String
    @State private var qty = ""
    @State private var error: APIError?

    init(alert: Record, onSaved: @escaping () -> Void) {
        self.alert = alert
        self.onSaved = onSaved
        _point = State(initialValue: alert["reorder_point"]?.string ?? "0")
    }

    var body: some View {
        ModuleFormSheet("Reorder threshold — \(alert["product"]?["name"]?.string ?? "")", canSubmit: Int(point) != nil, error: error, submit: submit) {
            ModuleField("Reorder point", error: error?.errors["reorder_point"]?.first) { TextField("", text: $point).textFieldStyle(.roundedBorder) }
            ModuleField("Reorder quantity (optional)", error: error?.errors["reorder_quantity"]?.first) { TextField("", text: $qty).textFieldStyle(.roundedBorder) }
        }
    }

    private func submit() async {
        var body: [String: JSONValue] = ["reorder_point": .int(Int(point) ?? 0)]
        if let q = Int(qty) { body["reorder_quantity"] = .int(q) }
        do {
            _ = try await env.session.client.put("/v1/admin/inventory/low-stock-alerts/\(alert.id)/threshold", json: .object(body))
            env.toasts.show("Threshold updated"); onSaved(); dismiss()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}
