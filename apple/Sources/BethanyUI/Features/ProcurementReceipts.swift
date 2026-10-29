import SwiftUI
import BethanyKit

// Goods received notes (GET /v1/admin/grn, /grn/{id}, corrections,
// revisions, audit-log, pdf/print) and purchase returns
// (GET /v1/admin/purchase-returns, /{id}, approve / reject / complete,
// audit-log). A GRN is recorded against a purchase order through
// POST /purchase-orders/{id}/receive (ReceiveGoodsSheet).

// MARK: - Goods receipt list

struct GoodsReceiptView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var list: RecordList?
    @State private var suppliers: Lookup?
    @State private var search = ""
    @State private var supplierId: Int?
    @State private var picking = false

    var body: some View {
        Page("Goods Receipt", subtitle: "Deliveries recorded against purchase orders") {
            if let list {
                HStack(spacing: Theme.Space.s) {
                    ModuleSearchField(text: $search, placeholder: "GRN or PO number") { Task { await reload() } }
                    if let suppliers { LookupPicker(label: "Supplier", lookup: suppliers, selection: $supplierId).frame(maxWidth: 200) }
                    if env.session.can("procurement.receive") { Button { picking = true } label: { Label("Record GRN", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
                }
                RecordListView(list, emptyTitle: "No goods receipts match", emptySymbol: "shippingbox.and.arrow.backward") { g in
                    ModuleRowCard(action: { env.navigator.push(.grn(id: g.int("id") ?? 0)) }) {
                        VStack(alignment: .leading, spacing: 3) {
                            HStack { Text(g.string("grn_number") ?? "GRN").font(Theme.Font.body.weight(.semibold)); StatusBadge(g.string("location_type"), tone: .neutral) }
                            Text([g.string("po_number"), g.string("supplier_name")].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.secondary)
                            HStack { Text([g.string("outlet_name"), Names.person(g["received_by"])].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary); Spacer(); Text(g.string("received_date") ?? "").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                        }
                    }
                }
            }
        }
        .task {
            if list == nil {
                list = RecordList(client: env.session.client, path: "/v1/admin/grn", perPage: 20)
                suppliers = Lookup.suppliers(env.session.client)
                await list?.load()
            }
        }
        .onChange(of: supplierId) { _, _ in Task { await reload() } }
        .refreshable { await list?.load() }
        .sheet(isPresented: $picking) { ReceivablePOPicker { Task { await list?.load() } } }
    }

    private func reload() async {
        guard let list else { return }
        list.query = ["search": search.isEmpty ? nil : search, "supplier_id": supplierId.map(String.init)]
        await list.load()
    }
}

/// Picks an order that can still be received (approved, ordered or partly
/// received) and opens the receive form for it.
struct ReceivablePOPicker: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let onDone: () -> Void
    @State private var orders: Resource<[Record]>?
    @State private var chosen: Record?

    var body: some View {
        VStack(spacing: 0) {
            HStack { Text("Record goods receipt").font(Theme.Font.sectionTitle); Spacer(); Button("Cancel") { dismiss() } }.padding(Theme.Space.l)
            Divider()
            ScrollView {
                if let orders {
                    LoadableView(orders.state, retry: { orders.load() }, isEmpty: { $0.isEmpty }, empty: { EmptyState("Nothing waiting to be received", symbol: "shippingbox") }) { rows in
                        LazyVStack(spacing: Theme.Space.s) {
                            ForEach(rows) { r in
                                let po = PurchaseOrderRecord(r)
                                ModuleRowCard(action: { Task { await open(po.id) } }) {
                                    VStack(alignment: .leading, spacing: 2) {
                                        HStack { Text(po.number).font(Theme.Font.body.weight(.semibold)); StatusBadge(po.status) }
                                        Text(po.supplierName).font(Theme.Font.secondary)
                                        Text("expected \(po.expected.map { HubDates.dayString($0) } ?? "—") · \(po.items.count) line(s)").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                    }
                                }
                            }
                        }
                        .padding(Theme.Space.l)
                    }
                }
            }
        }
        .frame(minWidth: 440, idealWidth: 560, minHeight: 400)
        .task {
            if orders == nil {
                let c = env.session.client
                orders = Resource { try (await c.get("/v1/admin/purchase-orders", query: ["status": "approved,ordered,partially_received", "per_page": "100"]).json?["data"]?.decode([Record].self)) ?? [] }
                orders?.load()
            }
        }
        .sheet(item: $chosen) { r in ReceiveGoodsSheet(po: PurchaseOrderRecord(r)) { onDone(); dismiss() } }
    }

    /// The list row lacks line details; fetch the full order before receiving.
    private func open(_ id: Int) async {
        do {
            let j = try await env.session.client.get("/v1/admin/purchase-orders/\(id)").json
            chosen = Record(json: j?["purchase_order"] ?? .null)
        } catch { env.toasts.error(error) }
    }
}

// MARK: - GRN detail (GET /v1/admin/grn/{id} → {grn})

struct GRNDetailView: View {
    @Environment(AppEnvironment.self) private var env
    let grnId: Int
    @State private var res: Resource<Record>?
    @State private var correcting = false

    var body: some View {
        Page("Goods received note") {
            if let res {
                LoadableView(res.state, retry: { res.load() }) { g in
                    let po = g["purchase_order"] ?? .null
                    SectionCard {
                        HStack { Text(g.string("grn_number") ?? "GRN").font(Theme.Font.display(20)); if let rev = g.int("revision"), rev > 0 { StatusBadge("rev \(rev)", tone: .accent) } }
                        if let pid = po["id"]?.int { HStack { Text("Purchase order").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary); Spacer(); Button(po["po_number"]?.string ?? "PO") { env.navigator.push(.purchaseOrder(id: pid)) } } }
                        DetailRow("Supplier", po["supplier"]?["name"]?.string)
                        DetailRow("Received on", g.string("received_date").map { String($0.prefix(10)) })
                        DetailRow("Received by", Names.person(g["received_by"]))
                        DetailRow("Outlet", g["outlet"]?["name"]?.string ?? (g["outlet_id"]?.int == nil ? "Warehouse" : nil))
                        DetailRow("Invoice number", g.string("invoice_number"))
                        if let n = g.string("notes"), !n.isEmpty { DetailRow("Notes", n) }
                    }
                    HStack(spacing: Theme.Space.s) {
                        DocumentButton("PDF", path: "/v1/admin/pdf/grn/\(grnId)", suggestedName: "\(g.string("grn_number") ?? "grn").pdf")
                        if env.session.me?.isSuperAdmin == true { Button { correcting = true } label: { Label("Correct quantities (owner)", systemImage: "pencil.and.list.clipboard") } }
                    }
                    .buttonStyle(.bordered)
                    SectionCard("Lines") {
                        ForEach(Array((g["items"]?.array ?? []).enumerated()), id: \.offset) { _, i in
                            let poi = i["purchase_order_item"] ?? .null
                            HStack {
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(poi["product"]?["name"]?.string ?? poi["product"]?["translations"]?[0]?["name"]?.string ?? poi["material"]?["name"]?.string ?? poi["description"]?.string ?? "Line").font(Theme.Font.body)
                                    Text([i["condition"]?.string, i["notes"]?.string].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                }
                                Spacer()
                                VStack(alignment: .trailing, spacing: 2) {
                                    Text("received \(quantityText(i["quantity_received"]))").font(Theme.Font.secondary).monospacedDigit()
                                    if (i["quantity_rejected"]?.double ?? 0) > 0 { Text("rejected \(quantityText(i["quantity_rejected"]))").font(Theme.Font.caption).foregroundStyle(Theme.Palette.danger) }
                                }
                            }
                            Divider()
                        }
                    }
                    RevisionsSection(path: "/v1/admin/grn/\(grnId)/revisions")
                    ModuleAuditLog(path: "/v1/admin/grn/\(grnId)/audit-log")
                }
            }
        }
        .task { if res == nil { load() } }
        .refreshable { await res?.reload() }
        .sheet(isPresented: $correcting) { if let g = res?.value { CorrectGRNSheet(grn: g) { res?.load() } } }
    }

    private func load() {
        let c = env.session.client, id = grnId
        res = Resource {
            let j = try await c.get("/v1/admin/grn/\(id)").json
            return Record(json: j?["grn"] ?? .null)
        }
        res?.load()
    }
}

/// POST /v1/admin/grn/{id}/corrections {reason, lines: [{grn_item_id, quantity_received}]} — super admin only.
struct CorrectGRNSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let grn: Record
    let onDone: () -> Void
    @State private var reason = ""
    @State private var qty: [Int: String]
    @State private var error: APIError?

    init(grn: Record, onDone: @escaping () -> Void) {
        self.grn = grn
        self.onDone = onDone
        var q: [Int: String] = [:]
        for i in grn["items"]?.array ?? [] { if let id = i["id"]?.int { q[id] = i["quantity_received"]?.string ?? "0" } }
        _qty = State(initialValue: q)
    }

    var body: some View {
        ModuleFormSheet("Correct \(grn.string("grn_number") ?? "GRN")", submitLabel: "Save correction", canSubmit: reason.trimmingCharacters(in: .whitespaces).count >= 5 && qty.values.allSatisfy { JSONValue.decimal($0) != nil }, error: error, submit: submit) {
            ModuleField("Reason (required)", error: error?.errors["reason"]?.first) { TextField("Why the receipt changes", text: $reason, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
            ForEach(Array((grn["items"]?.array ?? []).enumerated()), id: \.offset) { _, i in
                let id = i["id"]?.int ?? 0
                HStack {
                    Text(i["purchase_order_item"]?["product"]?["name"]?.string ?? i["purchase_order_item"]?["material"]?["name"]?.string ?? i["purchase_order_item"]?["description"]?.string ?? "Line").font(Theme.Font.secondary).frame(maxWidth: .infinity, alignment: .leading)
                    TextField("Qty", text: Binding(get: { qty[id] ?? "" }, set: { qty[id] = $0 })).textFieldStyle(.roundedBorder).frame(width: 90)
                }
            }
            Text("Stock moves by the difference (with serials); the order's received counts and status follow. The receipt as it was is kept as a revision.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
    }

    private func submit() async {
        let lines: [JSONValue] = qty.map { .object(["grn_item_id": .int($0.key), "quantity_received": JSONValue.decimal($0.value) ?? .number(0)]) }
        do {
            let r = try await env.session.client.post("/v1/admin/grn/\(grn.id)/corrections", json: .object(["reason": .string(reason.trimmingCharacters(in: .whitespaces)), "lines": .array(lines)]))
            env.toasts.show(r.json?["message"]?.string ?? "Corrected"); onDone(); dismiss()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}

// MARK: - Purchase returns list (GET /v1/admin/purchase-returns → paginator)

struct PurchaseReturnsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var list: RecordList?
    @State private var search = ""
    @State private var status = ""
    @State private var picking = false

    var body: some View {
        Page("Purchase Returns", subtitle: "Goods sent back to suppliers") {
            if let list {
                HStack(spacing: Theme.Space.s) {
                    ModuleSearchField(text: $search, placeholder: "Return number") { Task { await reload() } }
                    Picker("Status", selection: $status) { Text("Any").tag(""); ForEach(["pending", "approved", "rejected", "completed"], id: \.self) { Text($0.humanized).tag($0) } }.frame(maxWidth: 150)
                    if env.session.can("procurement.receive") { Button { picking = true } label: { Label("New return", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
                }
                RecordListView(list, emptyTitle: "No purchase returns match", emptySymbol: "arrow.uturn.backward") { r in
                    ModuleRowCard(action: { env.navigator.push(.purchaseReturn(id: r.int("id") ?? 0)) }) {
                        VStack(alignment: .leading, spacing: 3) {
                            HStack { Text(r.string("return_number") ?? "PR").font(Theme.Font.body.weight(.semibold)); StatusBadge(r.status) }
                            Text([r["purchase_order"]?["po_number"]?.string, r["supplier"]?["name"]?.string ?? r["purchase_order"]?["supplier"]?["name"]?.string].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.secondary)
                            HStack { Text("\(r.int("items_count") ?? 0) line(s)").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary); Spacer(); DateText(r.date("created_at"), style: .date) }
                        }
                    }
                }
            }
        }
        .task {
            if list == nil {
                list = RecordList(client: env.session.client, path: "/v1/admin/purchase-returns", perPage: 20)
                await list?.load()
            }
        }
        .onChange(of: status) { _, _ in Task { await reload() } }
        .refreshable { await list?.load() }
        .sheet(isPresented: $picking) { ReturnablePOPicker { Task { await list?.load() } } }
    }

    private func reload() async {
        guard let list else { return }
        list.query = ["search": search.isEmpty ? nil : search, "status": status.isEmpty ? nil : status]
        await list.load()
    }
}

/// Picks a received (or partly received) order and opens the return form.
struct ReturnablePOPicker: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let onDone: () -> Void
    @State private var orders: Resource<[Record]>?
    @State private var chosen: Record?

    var body: some View {
        VStack(spacing: 0) {
            HStack { Text("Return goods from…").font(Theme.Font.sectionTitle); Spacer(); Button("Cancel") { dismiss() } }.padding(Theme.Space.l)
            Divider()
            ScrollView {
                if let orders {
                    LoadableView(orders.state, retry: { orders.load() }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No received orders", symbol: "shippingbox") }) { rows in
                        LazyVStack(spacing: Theme.Space.s) {
                            ForEach(rows) { r in
                                let po = PurchaseOrderRecord(r)
                                ModuleRowCard(action: { Task { await open(po.id) } }) {
                                    VStack(alignment: .leading, spacing: 2) {
                                        HStack { Text(po.number).font(Theme.Font.body.weight(.semibold)); StatusBadge(po.status) }
                                        Text(po.supplierName).font(Theme.Font.secondary)
                                    }
                                }
                            }
                        }
                        .padding(Theme.Space.l)
                    }
                }
            }
        }
        .frame(minWidth: 440, idealWidth: 560, minHeight: 400)
        .task {
            if orders == nil {
                let c = env.session.client
                orders = Resource { try (await c.get("/v1/admin/purchase-orders", query: ["status": "ordered,partially_received,received", "per_page": "100"]).json?["data"]?.decode([Record].self)) ?? [] }
                orders?.load()
            }
        }
        .sheet(item: $chosen) { r in PurchaseReturnSheet(po: PurchaseOrderRecord(r)) { onDone(); dismiss() } }
    }

    private func open(_ id: Int) async {
        do {
            let j = try await env.session.client.get("/v1/admin/purchase-orders/\(id)").json
            chosen = Record(json: j?["purchase_order"] ?? .null)
        } catch { env.toasts.error(error) }
    }
}

// MARK: - Purchase return detail (GET /v1/admin/purchase-returns/{id} → {return})

struct PurchaseReturnDetailView: View {
    @Environment(AppEnvironment.self) private var env
    let returnId: Int
    @State private var res: Resource<Record>?
    @State private var prompt: Prompt?

    enum Prompt: Identifiable { case approve, reject, complete; var id: Int { hashValue } }

    var body: some View {
        Page("Purchase return") {
            if let res {
                LoadableView(res.state, retry: { res.load() }) { r in
                    SectionCard {
                        HStack { Text(r.string("return_number") ?? "PR").font(Theme.Font.display(20)); StatusBadge(r.status) }
                        if let pid = r["purchase_order"]?["id"]?.int { HStack { Text("Purchase order").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary); Spacer(); Button(r["purchase_order"]?["po_number"]?.string ?? "PO") { env.navigator.push(.purchaseOrder(id: pid)) } } }
                        DetailRow("Supplier", r["supplier"]?["name"]?.string ?? r["purchase_order"]?["supplier"]?["name"]?.string)
                        DetailRow("Raised by", Names.person(r["created_by"]))
                        DetailRow("Raised") { DateText(r.date("created_at")) }
                        if let n = r.string("notes"), !n.isEmpty { DetailRow("Notes", n) }
                    }
                    actions(r)
                    SectionCard("Lines") {
                        ForEach(Array((r["items"]?.array ?? []).enumerated()), id: \.offset) { _, i in
                            let poi = i["purchase_order_item"] ?? .null
                            HStack(alignment: .top) {
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(poi["product"]?["name"]?.string ?? poi["material"]?["name"]?.string ?? poi["description"]?.string ?? "Line").font(Theme.Font.body)
                                    Text(i["reason"]?.string ?? "").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                }
                                Spacer()
                                Text("× \(quantityText(i["quantity"]))").font(Theme.Font.secondary).monospacedDigit()
                            }
                            Divider()
                        }
                    }
                    ModuleAuditLog(path: "/v1/admin/purchase-returns/\(returnId)/audit-log")
                }
            }
        }
        .task { if res == nil { load() } }
        .refreshable { await res?.reload() }
        .sheet(item: $prompt) { p in
            switch p {
            case .approve: TextPromptSheet("Approve return", placeholder: "Notes (optional)", required: false, confirmLabel: "Approve") { n in await post("approve", ["notes": .str(n)]) }
            case .reject: TextPromptSheet("Reject return", placeholder: "Reason (required)", confirmLabel: "Reject", destructive: true) { n in await post("reject", ["reason": .string(n)]) }
            case .complete: TextPromptSheet("Complete return", placeholder: "Notes (optional)", required: false, confirmLabel: "Complete") { n in await post("complete", ["notes": .str(n)]) }
            }
        }
    }

    @ViewBuilder
    private func actions(_ r: Record) -> some View {
        let status = r.status ?? ""
        HStack(spacing: Theme.Space.s) {
            if status == "pending", env.session.can("procurement.approve") {
                Button { prompt = .approve } label: { Label("Approve", systemImage: "checkmark.seal") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                Button(role: .destructive) { prompt = .reject } label: { Label("Reject", systemImage: "xmark.circle") }.buttonStyle(.bordered)
            }
            if status == "approved", env.session.can("procurement.receive") {
                Button { prompt = .complete } label: { Label("Mark completed", systemImage: "checkmark") }.buttonStyle(.borderedProminent).tint(Theme.Palette.success)
            }
            DocumentButton("PDF", path: "/v1/admin/pdf/purchase-returns/\(returnId)", suggestedName: "\(r.string("return_number") ?? "return").pdf").buttonStyle(.bordered)
        }
    }

    private func load() {
        let c = env.session.client, id = returnId
        res = Resource {
            let j = try await c.get("/v1/admin/purchase-returns/\(id)").json
            return Record(json: j?["return"] ?? .null)
        }
        res?.load()
    }

    private func post(_ action: String, _ body: [String: JSONValue]) async {
        do {
            let r = try await env.session.client.post("/v1/admin/purchase-returns/\(returnId)/\(action)", json: .object(body))
            env.toasts.show(r.json?["message"]?.string ?? "Done")
            res?.load()
        } catch { env.toasts.error(error) }
    }
}
