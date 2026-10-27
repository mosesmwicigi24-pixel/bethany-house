import SwiftUI
import BethanyKit

// Stock transfers between outlets (StockTransfersController). State
// machine: pending → approve (inventory.approve) → approved → dispatch
// (inventory.transfer, per-line quantities) → in_transit → receive
// (inventory.transfer) → completed. Cancel (inventory.transfer) while
// pending, approved, rejected or returned.

struct TransferRecord {
    let r: Record
    init(_ r: Record) { self.r = r }
    var id: Int { r.int("id") ?? 0 }
    var number: String { r.string("transfer_number") ?? "TRF-\(id)" }
    var status: String? { r.status }
    var from: String { r["from_outlet"]?["name"]?.string ?? "—" }
    var to: String { r["to_outlet"]?["name"]?.string ?? "—" }
    var items: [JSONValue] { r["items"]?.array ?? [] }
    var itemsCount: Int { r.int("items_count") ?? items.count }
}

// MARK: - List (GET /v1/admin/inventory/transfers → {data, meta, stats})

struct TransfersView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var list: RecordList?
    @State private var search = ""
    @State private var status = ""
    @State private var showNew = false

    var body: some View {
        Page("Stock Transfers", subtitle: "Stock moving between outlets") {
            if let list {
                let s = list.extras["stats"] ?? .null
                StatsRow(tiles: [
                    ("Pending", s["pending"]?.string ?? "0", (s["pending"]?.int ?? 0) > 0 ? .amber : nil),
                    ("Approved", s["approved"]?.string ?? "0", .info),
                    ("In transit", s["in_transit"]?.string ?? "0", .pink),
                    ("Completed", s["completed"]?.string ?? "0", .success),
                ])
                HStack(spacing: Theme.Space.s) {
                    ModuleSearchField(text: $search, placeholder: "Transfer number") { Task { await reload() } }
                    Picker("Status", selection: $status) {
                        Text("Any status").tag("")
                        ForEach(["pending", "approved", "in_transit", "completed", "cancelled"], id: \.self) { Text($0.humanized).tag($0) }
                    }
                    .frame(maxWidth: 170)
                    if env.session.can("inventory.transfer") { Button { showNew = true } label: { Label("New", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
                }
                RecordListView(list, emptyTitle: "No transfers match", emptySymbol: "arrow.left.arrow.right") { r in
                    let t = TransferRecord(r)
                    ModuleRowCard(action: { env.navigator.push(.transfer(id: t.id)) }) {
                        VStack(alignment: .leading, spacing: 3) {
                            HStack { Text(t.number).font(Theme.Font.body.weight(.semibold)); StatusBadge(t.status) }
                            Text("\(t.from) → \(t.to)").font(Theme.Font.secondary)
                            HStack { Text("\(t.itemsCount) line\(t.itemsCount == 1 ? "" : "s") · \(Names.person(r["requested_by"]) ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary); Spacer(); DateText(r.date("created_at"), style: .date) }
                        }
                    }
                }
            }
        }
        .task {
            if list == nil {
                list = RecordList(client: env.session.client, path: "/v1/admin/inventory/transfers")
                await list?.load()
            }
        }
        .onChange(of: status) { _, _ in Task { await reload() } }
        .refreshable { await list?.load() }
        .sheet(isPresented: $showNew) { NewTransferSheet { Task { await list?.load() } } }
    }

    private func reload() async {
        guard let list else { return }
        list.query = ["search": search.isEmpty ? nil : search, "status": status.isEmpty ? nil : status]
        await list.load()
    }
}

// MARK: - New (POST {from_outlet_id, to_outlet_id, notes?, items: [{product_id, product_variant_id?, quantity_requested}]})

struct NewTransferSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let onSaved: () -> Void
    @State private var outlets: Lookup?
    @State private var from: Int?
    @State private var to: Int?
    @State private var notes = ""
    @State private var search = ""
    @State private var stockRows: [Record] = []
    @State private var lines: [Line] = []
    @State private var error: APIError?

    struct Line: Identifiable { let id = UUID(); let productId: Int; let variantId: Int?; let title: String; let onHand: String; var qty: String }

    private var valid: Bool { from != nil && to != nil && from != to && !lines.isEmpty && lines.allSatisfy { (Int($0.qty) ?? 0) > 0 } }

    var body: some View {
        ModuleFormSheet("New stock transfer", submitLabel: "Request transfer", canSubmit: valid, error: error, submit: submit) {
            if let outlets {
                HStack {
                    ModuleField("From", error: error?.errors["from_outlet_id"]?.first) { LookupPicker(label: "From", lookup: outlets, selection: $from) }
                    ModuleField("To", error: error?.errors["to_outlet_id"]?.first) { LookupPicker(label: "To", lookup: outlets, selection: $to) }
                }
                .onChange(of: from) { _, _ in Task { await find() } }
            }
            ModuleField("Add products from the source outlet's stock") {
                ModuleSearchField(text: $search, placeholder: "SKU or product") { Task { await find() } }
                ForEach(stockRows.prefix(12)) { s in
                    Button {
                        guard let pid = s.int("product_id") else { return }
                        lines.append(Line(productId: pid, variantId: s.int("product_variant_id"), title: [s["product"]?["name"]?.string, s["variant"]?["variant_name"]?.string].compactMap { $0 }.joined(separator: " · "), onHand: quantityText(s["quantity_available"]), qty: "1"))
                    } label: {
                        HStack { Text([s["product"]?["name"]?.string, s["variant"]?["variant_name"]?.string].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.secondary); Spacer(); Text("avail \(quantityText(s["quantity_available"]))").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary); Image(systemName: "plus.circle") }
                    }
                    .buttonStyle(.plain)
                }
            }
            if !lines.isEmpty {
                Text("Lines").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).textCase(.uppercase)
                ForEach($lines) { $l in
                    HStack {
                        VStack(alignment: .leading) { Text(l.title).font(Theme.Font.secondary); Text("available \(l.onHand)").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                        Spacer()
                        TextField("Qty", text: $l.qty).textFieldStyle(.roundedBorder).frame(width: 70)
                        Button { lines.removeAll { $0.id == l.id } } label: { Image(systemName: "minus.circle") }.buttonStyle(.plain).foregroundStyle(Theme.Palette.danger)
                    }
                }
            }
            ModuleField("Notes") { TextField("Notes", text: $notes, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
        }
        .task { if outlets == nil { outlets = Lookup.outlets(env.session.client) } }
    }

    private func find() async {
        let j = try? await env.session.client.get("/v1/admin/inventory/stock-levels", query: ["search": search.isEmpty ? nil : search, "outlet_id": from.map(String.init), "per_page": "30"]).json
        stockRows = j?["data"]?.decode([Record].self) ?? []
    }

    private func submit() async {
        let items: [JSONValue] = lines.map { .object(["product_id": .int($0.productId), "product_variant_id": .int($0.variantId), "quantity_requested": .int(Int($0.qty) ?? 0)]) }
        do {
            let r = try await env.session.client.post("/v1/admin/inventory/transfers", json: .object(["from_outlet_id": .int(from), "to_outlet_id": .int(to), "notes": .str(notes), "items": .array(items)]))
            env.toasts.show(r.json?["message"]?.string ?? "Transfer requested"); onSaved(); dismiss()
            if let id = r.json?["transfer"]?["id"]?.int { env.navigator.push(.transfer(id: id)) }
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}

// MARK: - Detail (GET …/transfers/{id} → {transfer})

struct TransferDetailView: View {
    @Environment(AppEnvironment.self) private var env
    let transferId: Int
    @State private var res: Resource<Record>?
    @State private var dispatching = false
    @State private var cancelling = false

    var body: some View {
        Page("Stock transfer") {
            if let res {
                LoadableView(res.state, retry: { res.load() }) { r in
                    let t = TransferRecord(r)
                    SectionCard {
                        HStack { Text(t.number).font(Theme.Font.display(20)); StatusBadge(t.status) }
                        Text("\(t.from) → \(t.to)").font(Theme.Font.body)
                        DetailRow("Requested by", Names.person(r["requested_by"]))
                        DetailRow("Requested") { DateText(r.date("requested_at") ?? r.date("created_at")) }
                        DetailRow("Approved by", Names.person(r["approved_by"]))
                        DetailRow("Completed by", Names.person(r["completed_by"]))
                        if let n = r.string("notes"), !n.isEmpty { DetailRow("Notes", n) }
                    }
                    actions(t)
                    SectionCard("Lines") {
                        ForEach(Array(t.items.enumerated()), id: \.offset) { _, i in
                            HStack {
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(i["product"]?["name"]?.string ?? "Product").font(Theme.Font.body)
                                    Text([i["product"]?["sku"]?.string, i["variant"]?["variant_name"]?.string].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                }
                                Spacer()
                                VStack(alignment: .trailing, spacing: 2) {
                                    Text("requested \(quantityText(i["quantity_requested"]))").font(Theme.Font.secondary).monospacedDigit()
                                    Text("sent \(quantityText(i["quantity_received"]))").font(Theme.Font.caption).monospacedDigit().foregroundStyle(Theme.textSecondary)
                                    if let src = i["source_stock"], !src.isNull { Text("at source \(quantityText(src))").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                                }
                            }
                            Divider()
                        }
                    }
                    ModuleAuditLog(path: "/v1/admin/inventory/transfers/\(transferId)/audit-log")
                }
            }
        }
        .task { if res == nil { load() } }
        .refreshable { await res?.reload() }
        .sheet(isPresented: $dispatching) { if let r = res?.value { DispatchSheet(transfer: TransferRecord(r)) { res?.load() } } }
        .sheet(isPresented: $cancelling) {
            TextPromptSheet("Cancel transfer", placeholder: "Reason (optional)", required: false, confirmLabel: "Cancel transfer", destructive: true) { reason in
                await put("cancel", .object(["reason": .str(reason)]))
            }
        }
    }

    private func load() {
        let c = env.session.client, id = transferId
        res = Resource {
            let j = try await c.get("/v1/admin/inventory/transfers/\(id)").json
            return Record(json: j?["transfer"] ?? .null)
        }
        res?.load()
    }

    @ViewBuilder
    private func actions(_ t: TransferRecord) -> some View {
        let s = env.session
        let status = t.status ?? ""
        HStack(spacing: Theme.Space.s) {
            if status == "pending", s.can("inventory.approve") {
                ConfirmButton("Approve transfer", message: "Signs the approval; stock moves when it is dispatched.", action: { await put("approve") }) { Label("Approve", systemImage: "checkmark.seal") }
                    .buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
            }
            if status == "approved", s.can("inventory.transfer") {
                Button { dispatching = true } label: { Label("Dispatch", systemImage: "shippingbox.and.arrow.backward") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
            }
            if status == "in_transit", s.can("inventory.transfer") {
                ConfirmButton("Receive at \(t.to)", message: "Adds the dispatched quantities to \(t.to) and completes the transfer.", action: { await put("receive") }) { Label("Receive", systemImage: "tray.and.arrow.down") }
                    .buttonStyle(.borderedProminent).tint(Theme.Palette.success)
            }
            if ["pending", "approved", "rejected", "returned"].contains(status), s.can("inventory.transfer") {
                Button(role: .destructive) { cancelling = true } label: { Label("Cancel", systemImage: "xmark.circle") }.buttonStyle(.bordered)
            }
            DocumentButton("PDF", path: "/v1/admin/pdf/stock-transfers/\(transferId)", suggestedName: "\(t.number).pdf").buttonStyle(.bordered)
        }
    }

    private func put(_ action: String, _ body: JSONValue = .object([:])) async {
        do {
            let r = try await env.session.client.put("/v1/admin/inventory/transfers/\(transferId)/\(action)", json: body)
            env.toasts.show(r.json?["message"]?.string ?? "Done")
            res?.load()
        } catch { env.toasts.error(error) }
    }
}

/// PUT …/transfers/{id}/dispatch {items: [{id, quantity_received}]} — the quantity actually sent per line.
struct DispatchSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let transfer: TransferRecord
    let onDone: () -> Void
    @State private var qty: [Int: String]
    @State private var error: APIError?

    init(transfer: TransferRecord, onDone: @escaping () -> Void) {
        self.transfer = transfer
        self.onDone = onDone
        var q: [Int: String] = [:]
        for i in transfer.items { if let id = i["id"]?.int { q[id] = i["quantity_requested"]?.string ?? "0" } }
        _qty = State(initialValue: q)
    }

    var body: some View {
        ModuleFormSheet("Dispatch \(transfer.number)", submitLabel: "Dispatch", canSubmit: qty.values.allSatisfy { Int($0) != nil }, error: error, submit: submit) {
            Text("Enter what is actually leaving \(transfer.from). The server refuses a line above the source outlet's stock.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            ForEach(Array(transfer.items.enumerated()), id: \.offset) { _, i in
                let id = i["id"]?.int ?? 0
                HStack {
                    VStack(alignment: .leading) { Text(i["product"]?["name"]?.string ?? "").font(Theme.Font.body); Text("requested \(quantityText(i["quantity_requested"]))").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                    Spacer()
                    TextField("Qty", text: Binding(get: { qty[id] ?? "" }, set: { qty[id] = $0 })).textFieldStyle(.roundedBorder).frame(width: 80)
                }
            }
        }
    }

    private func submit() async {
        let items: [JSONValue] = qty.map { .object(["id": .int($0.key), "quantity_received": .int(Int($0.value) ?? 0)]) }
        do {
            let r = try await env.session.client.put("/v1/admin/inventory/transfers/\(transfer.id)/dispatch", json: .object(["items": .array(items)]))
            env.toasts.show(r.json?["message"]?.string ?? "Dispatched"); onDone(); dismiss()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}
