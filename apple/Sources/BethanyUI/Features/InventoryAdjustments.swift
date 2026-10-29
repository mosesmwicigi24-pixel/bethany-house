import SwiftUI
import BethanyKit

// Stock adjustments (StockAdjustmentsController): list with stats and
// reason codes, new adjustment (POST), approve / reject / reverse (PUT,
// inventory.approve), PDF and audit log. Statuses: pending_approval,
// approved, rejected. Only an approved, non-reversal row can be reversed.

struct AdjustmentRecord {
    let r: Record
    init(_ r: Record) { self.r = r }
    var id: Int { r.int("id") ?? 0 }
    var status: String? { r.status }
    var productName: String { r["inventory_item"]?["product"]?["name"]?.string ?? "Product" }
    var where_: String { [r["inventory_item"]?["product"]?["sku"]?.string, r["inventory_item"]?["variant"]?["variant_name"]?.string, r["inventory_item"]?["outlet"]?["name"]?.string].compactMap { $0 }.joined(separator: " · ") }
    var change: Double { r["quantity_change"]?.double ?? 0 }
    var isReversal: Bool { (r.string("notes") ?? "").hasPrefix("[REVERSAL") }
    var canApprove: Bool { status == "pending_approval" }
    var canReverse: Bool { status == "approved" && !isReversal && r["quantity_change"] != nil }
}

// MARK: - List (GET /v1/admin/inventory/adjustments → {data, meta, stats, reason_codes})

struct AdjustmentsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var list: RecordList?
    @State private var outlets: Lookup?
    @State private var search = ""
    @State private var status = ""
    @State private var outletId: Int?
    @State private var showNew = false

    var body: some View {
        Page("Stock Adjustments", subtitle: "Every correction to finished-goods stock, with its reason") {
            if let list {
                let s = list.extras["stats"] ?? .null
                StatsRow(tiles: [
                    ("Total", s["total"]?.string ?? "0", nil),
                    ("Awaiting approval", s["pending_approval"]?.string ?? "0", (s["pending_approval"]?.int ?? 0) > 0 ? .amber : nil),
                    ("Approved", s["approved"]?.string ?? "0", .success),
                    ("Rejected", s["rejected"]?.string ?? "0", nil),
                ])
                HStack(spacing: Theme.Space.s) {
                    ModuleSearchField(text: $search, placeholder: "SKU or product") { Task { await reload() } }
                    Picker("Status", selection: $status) { Text("Any status").tag(""); Text("Awaiting approval").tag("pending_approval"); Text("Approved").tag("approved"); Text("Rejected").tag("rejected") }.frame(maxWidth: 190)
                    if let outlets { LookupPicker(label: "Outlet", lookup: outlets, selection: $outletId).frame(maxWidth: 180) }
                    if env.session.can("inventory.adjust") { Button { showNew = true } label: { Label("New", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
                }
                RecordListView(list, emptyTitle: "No adjustments match", emptySymbol: "slider.horizontal.3") { r in
                    let a = AdjustmentRecord(r)
                    ModuleRowCard(action: { env.navigator.push(.adjustment(id: a.id)) }) {
                        VStack(alignment: .leading, spacing: 3) {
                            HStack { Text(a.productName).font(Theme.Font.body.weight(.semibold)); StatusBadge(a.status); if a.isReversal { StatusBadge("reversal", tone: .accent) } }
                            Text(a.where_).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                            HStack {
                                Text(r.string("reason_label") ?? r.string("reason_code")?.humanized ?? "").font(Theme.Font.secondary)
                                Spacer()
                                Text((a.change > 0 ? "+" : "") + quantityText(r["quantity_change"])).font(Theme.Font.body.weight(.semibold)).monospacedDigit().foregroundStyle(a.change < 0 ? Theme.Palette.danger : Theme.Palette.success)
                            }
                            HStack { Text(Names.person(r["created_by"]) ?? "").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary); Spacer(); DateText(r.date("created_at"), style: .dateTime) }
                        }
                    }
                }
            }
        }
        .task {
            if list == nil {
                list = RecordList(client: env.session.client, path: "/v1/admin/inventory/adjustments")
                outlets = Lookup.outlets(env.session.client)
                await list?.load()
            }
        }
        .onChange(of: status) { _, _ in Task { await reload() } }
        .onChange(of: outletId) { _, _ in Task { await reload() } }
        .refreshable { await list?.load() }
        .sheet(isPresented: $showNew) { NewAdjustmentSheet(reasonCodes: list?.extras["reason_codes"] ?? .null) { Task { await list?.load() } } }
    }

    private func reload() async {
        guard let list else { return }
        list.query = ["search": search.isEmpty ? nil : search, "status": status.isEmpty ? nil : status, "outlet_id": outletId.map(String.init)]
        await list.load()
    }
}

// MARK: - New (POST {inventory_item_id, quantity_change, reason_code, notes?, reference_number?})

struct NewAdjustmentSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let reasonCodes: JSONValue
    let onSaved: () -> Void
    @State private var search = ""
    @State private var items: [Record] = []
    @State private var itemId: Int?
    @State private var change = ""
    @State private var reason = ""
    @State private var notes = ""
    @State private var reference = ""
    @State private var error: APIError?

    private var codes: [(String, JSONValue)] { (reasonCodes.object ?? [:]).sorted { $0.key < $1.key } }
    private var valid: Bool { itemId != nil && (Int(change) ?? 0) != 0 && !reason.isEmpty }

    var body: some View {
        ModuleFormSheet("New stock adjustment", submitLabel: "Submit", canSubmit: valid, error: error, submit: submit) {
            ModuleField("Stock row", error: error?.errors["inventory_item_id"]?.first) {
                ModuleSearchField(text: $search, placeholder: "Find by SKU or product") { Task { await find() } }
                Picker("Stock row", selection: $itemId) {
                    Text("—").tag(Int?.none)
                    ForEach(items) { i in
                        Text("\(i["product"]?["name"]?.string ?? "") \(i["variant"]?["variant_name"]?.string ?? "") @ \(i["outlet"]?["name"]?.string ?? "") — on hand \(quantityText(i["quantity_on_hand"]))").tag(i.int("id"))
                    }
                }
            }
            ModuleField("Reason", error: error?.errors["reason_code"]?.first) {
                Picker("Reason", selection: $reason) {
                    Text("—").tag("")
                    ForEach(codes, id: \.0) { code, cfg in
                        Text("\(cfg["label"]?.string ?? code.humanized)\(cfg["requires_approval"]?.bool == true ? " (needs approval)" : "")").tag(code)
                    }
                }
            }
            if let cfg = codes.first(where: { $0.0 == reason })?.1, let dir = cfg["direction"]?.string, dir != "either" {
                Text(dir == "decrease" ? "This reason only reduces stock — enter a negative quantity." : "This reason only adds stock — enter a positive quantity.").font(Theme.Font.caption).foregroundStyle(Theme.Palette.amber)
            }
            ModuleField("Quantity change (± pieces)", error: error?.errors["quantity_change"]?.first) { TextField("-3", text: $change).textFieldStyle(.roundedBorder) }
            ModuleField("Reference number") { TextField("Count sheet, ticket…", text: $reference).textFieldStyle(.roundedBorder) }
            ModuleField("Notes") { TextField("Why", text: $notes, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
            Text("Some reasons, and any change above your band, wait for an approver before stock moves.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
        .task { await find() }
    }

    private func find() async {
        let j = try? await env.session.client.get("/v1/admin/inventory/stock-levels", query: ["search": search.isEmpty ? nil : search, "per_page": "30"]).json
        items = j?["data"]?.decode([Record].self) ?? []
    }

    private func submit() async {
        do {
            let r = try await env.session.client.post("/v1/admin/inventory/adjustments", json: .object([
                "inventory_item_id": .int(itemId), "quantity_change": .int(Int(change) ?? 0), "reason_code": .string(reason), "notes": .str(notes), "reference_number": .str(reference),
            ]))
            let needs = r.json?["requires_approval"]?.bool ?? false
            env.toasts.show(needs ? "Submitted — waiting for approval" : (r.json?["message"]?.string ?? "Adjustment applied"), tone: needs ? .warning : .success)
            onSaved(); dismiss()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}

// MARK: - Detail (GET …/adjustments/{id} → {adjustment})

struct AdjustmentDetailView: View {
    @Environment(AppEnvironment.self) private var env
    let adjustmentId: Int
    @State private var res: Resource<Record>?
    @State private var prompt: Prompt?

    enum Prompt: Identifiable { case approve, reject, reverse; var id: Int { hashValue } }

    var body: some View {
        Page("Stock adjustment") {
            if let res {
                LoadableView(res.state, retry: { res.load() }) { r in
                    let a = AdjustmentRecord(r)
                    SectionCard {
                        HStack { Text(a.productName).font(Theme.Font.display(20)); StatusBadge(a.status); if a.isReversal { StatusBadge("reversal", tone: .accent) } }
                        Text(a.where_).font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                        HStack {
                            StatTile("Change", value: (a.change > 0 ? "+" : "") + quantityText(r["quantity_change"]), tone: a.change < 0 ? .danger : .success)
                            StatTile("Before", value: quantityText(r["quantity_before"]))
                            StatTile("After", value: quantityText(r["quantity_after"]))
                        }
                        DetailRow("Reason", r.string("reason_label") ?? r.string("reason_code")?.humanized)
                        DetailRow("Reference", r.string("reference_number"))
                        DetailRow("Notes", r.string("notes"))
                        DetailRow("Raised by", Names.person(r["created_by"]))
                        DetailRow("Raised") { DateText(r.date("created_at")) }
                        if r["approved_by"] != nil, !(r["approved_by"]?.isNull ?? true) {
                            DetailRow("Approved by", Names.person(r["approved_by"]))
                            DetailRow("Approved") { DateText(r.date("approved_at")) }
                        }
                        DetailRow("Approval notes", r.string("approval_notes"))
                    }
                    HStack(spacing: Theme.Space.s) {
                        if a.canApprove, env.session.can("inventory.approve") {
                            Button { prompt = .approve } label: { Label("Approve", systemImage: "checkmark.seal") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                            Button(role: .destructive) { prompt = .reject } label: { Label("Reject", systemImage: "xmark.circle") }.buttonStyle(.bordered)
                        }
                        if a.canReverse, env.session.can("inventory.approve") {
                            Button { prompt = .reverse } label: { Label("Reverse", systemImage: "arrow.uturn.backward") }.buttonStyle(.bordered)
                        }
                        DocumentButton("PDF", path: "/v1/admin/pdf/stock-adjustments/\(adjustmentId)", suggestedName: "adjustment-\(adjustmentId).pdf").buttonStyle(.bordered)
                    }
                    ModuleAuditLog(path: "/v1/admin/inventory/adjustments/\(adjustmentId)/audit-log")
                }
            }
        }
        .task { if res == nil { load() } }
        .refreshable { await res?.reload() }
        .sheet(item: $prompt) { p in
            switch p {
            case .approve: TextPromptSheet("Approve adjustment", placeholder: "Notes (optional)", required: false, confirmLabel: "Sign approval") { n in await put("approve", ["notes": .str(n)], "Signed") }
            case .reject: TextPromptSheet("Reject adjustment", placeholder: "Reason (required)", confirmLabel: "Reject", destructive: true) { n in await put("reject", ["reason": .string(n)], "Adjustment rejected") }
            case .reverse: TextPromptSheet("Reverse adjustment", placeholder: "Notes (optional)", required: false, confirmLabel: "Reverse", destructive: true) { n in await put("reverse", ["notes": .str(n)], "Reversed") }
            }
        }
    }

    private func load() {
        let c = env.session.client, id = adjustmentId
        res = Resource {
            let j = try await c.get("/v1/admin/inventory/adjustments/\(id)").json
            return Record(json: j?["adjustment"] ?? .null)
        }
        res?.load()
    }

    private func put(_ action: String, _ body: [String: JSONValue], _ success: String) async {
        do {
            let r = try await env.session.client.put("/v1/admin/inventory/adjustments/\(adjustmentId)/\(action)", json: .object(body))
            env.toasts.show(r.json?["message"]?.string ?? success)
            res?.load()
        } catch { env.toasts.error(error) }
    }
}
