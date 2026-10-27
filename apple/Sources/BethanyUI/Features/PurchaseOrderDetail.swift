import SwiftUI
import BethanyKit

/// One purchase order (GET /v1/admin/purchase-orders/{id} →
/// {purchase_order, receiving_history}). Transitions follow
/// PurchaseOrderController: draft → submit → pending_approval →
/// approve/reject → approved → (status ordered) → receive → partially /
/// received; cancel while nothing is received; supplier invoice at any
/// status but cancelled; payments on the payables ledger; owner revisions.
struct PurchaseOrderDetailView: View {
    @Environment(AppEnvironment.self) private var env
    let poId: Int
    @State private var res: Resource<JSONValue>?
    @State private var sheet: Sheet?
    @State private var showAudit = false
    @State private var showRevisions = false

    enum Sheet: Identifiable {
        case reject, cancel, approve, receive, returnGoods, invoice, payment, revise
        var id: Int { hashValue }
    }

    var body: some View {
        Page("Purchase order") {
            if let res {
                LoadableView(res.state, retry: { res.load() }) { j in
                    let po = PurchaseOrderRecord(Record(json: j["purchase_order"] ?? .null))
                    header(po)
                    actions(po)
                    lines(po)
                    SupplierPaymentsSection(po: po)
                    receipts(j["receiving_history"]?.array ?? [])
                    if showRevisions { RevisionsSection(path: "/v1/admin/purchase-orders/\(poId)/revisions") } else { Button("Show revisions") { showRevisions = true }.font(Theme.Font.secondary) }
                    if showAudit { ModuleAuditLog(path: "/v1/admin/purchase-orders/\(poId)/audit-log") } else { Button("Show audit log") { showAudit = true }.font(Theme.Font.secondary) }
                }
            }
        }
        .task { if res == nil { load() } }
        .refreshable { await res?.reload() }
        .sheet(item: $sheet) { s in
            if let j = res?.value {
                let po = PurchaseOrderRecord(Record(json: j["purchase_order"] ?? .null))
                switch s {
                case .approve: TextPromptSheet("Approve \(po.number)", placeholder: "Notes (optional)", required: false, confirmLabel: "Sign approval") { n in await post("approve", ["notes": .str(n)]) }
                case .reject: TextPromptSheet("Reject \(po.number)", placeholder: "Reason (required)", confirmLabel: "Reject", destructive: true) { n in await post("reject", ["reason": .string(n)]) }
                case .cancel: TextPromptSheet("Cancel \(po.number)", placeholder: "Reason (required)", confirmLabel: "Cancel order", destructive: true) { n in await post("cancel", ["reason": .string(n)]) }
                case .receive: ReceiveGoodsSheet(po: po) { reload() }
                case .returnGoods: PurchaseReturnSheet(po: po) { reload() }
                case .invoice: SupplierInvoiceSheet(po: po) { reload() }
                case .payment: RecordSupplierPaymentSheet(po: po) { reload() }
                case .revise: RevisePurchaseOrderSheet(po: po) { reload() }
                }
            }
        }
    }

    private func load() {
        let c = env.session.client, id = poId
        res = Resource { try await c.get("/v1/admin/purchase-orders/\(id)").json ?? .null }
        res?.load()
    }

    private func reload() { res?.load() }

    private func header(_ po: PurchaseOrderRecord) -> some View {
        SectionCard {
            HStack { Text(po.number).font(Theme.Font.display(20)); StatusBadge(po.status); if po.revision > 0 { StatusBadge("rev \(po.revision)", tone: .accent) }; Spacer(); MoneyText(po.total, emphasis: true) }
            Text(po.supplierName).font(Theme.Font.body)
            HStack {
                DetailRow("Currency", po.currency)
                DetailRow("Expected") { DateText(po.expected, style: .date) }
            }
            DetailRow("Subtotal") { MoneyText(po.subtotal) }
            DetailRow("Shipping") { MoneyText(po.shipping) }
            DetailRow("Tax") { MoneyText(po.tax) }
            DetailRow("Payment terms", po.r.string("payment_terms"))
            DetailRow("Raised by", Names.person(po.r["created_by"]))
            DetailRow("Approved by", Names.person(po.r["approved_by"]))
            if let inv = po.r.string("supplier_invoice_number") {
                DetailRow("Supplier invoice", "\(inv) · \(po.r.string("supplier_invoice_date") ?? "") · \(po.r.money("supplier_invoice_amount", currency: po.currency)?.formatted() ?? "")")
            }
            if let n = po.r.string("notes"), !n.isEmpty { DetailRow("Notes", n) }
        }
    }

    @ViewBuilder
    private func actions(_ po: PurchaseOrderRecord) -> some View {
        let s = env.session
        let status = po.status ?? ""
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: Theme.Space.s) {
                if status == "draft", s.can("procurement.create") {
                    ConfirmButton("Submit for approval", message: "Goes to the approval bands by value; you cannot approve your own order.", action: { await post("submit", [:]) }) { Label("Submit", systemImage: "paperplane") }
                        .buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                }
                if status == "pending_approval", s.can("procurement.approve") {
                    Button { sheet = .approve } label: { Label("Approve", systemImage: "checkmark.seal") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                    Button(role: .destructive) { sheet = .reject } label: { Label("Reject", systemImage: "xmark.circle") }
                }
                if status == "approved", s.can("procurement.approve") {
                    ConfirmButton("Mark as ordered", message: "Records that the order has been placed with the supplier.", action: { await patchStatus("ordered") }) { Label("Mark ordered", systemImage: "cart") }
                }
                if POStatus.receivable.contains(status), s.can("procurement.receive") {
                    Button { sheet = .receive } label: { Label("Receive goods", systemImage: "shippingbox.and.arrow.backward") }.buttonStyle(.borderedProminent).tint(Theme.Palette.success)
                }
                if POStatus.returnable.contains(status), s.can("procurement.receive") {
                    Button { sheet = .returnGoods } label: { Label("Return goods", systemImage: "arrow.uturn.backward") }
                }
                if status != "cancelled", s.can("procurement.create") {
                    Button { sheet = .invoice } label: { Label("Supplier invoice", systemImage: "doc.text") }
                }
                if s.can("procurement.pay"), ["approved", "ordered", "partially_received", "received"].contains(status) {
                    Button { sheet = .payment } label: { Label("Record payment", systemImage: "banknote") }
                }
                if status != "cancelled", s.me?.isSuperAdmin == true {
                    Button { sheet = .revise } label: { Label("Revise (owner)", systemImage: "pencil.and.list.clipboard") }
                }
                if !["received", "partially_received", "cancelled"].contains(status), s.can("procurement.create") {
                    Button(role: .destructive) { sheet = .cancel } label: { Label("Cancel", systemImage: "xmark.circle") }
                }
                if ["draft", "cancelled"].contains(status), s.can("procurement.create") {
                    ConfirmButton("Delete purchase order", message: "Removes the draft and its lines permanently.", destructive: true, action: { await delete() }) { Label("Delete", systemImage: "trash") }
                }
                DocumentButton("PDF", path: "/v1/admin/pdf/purchase-orders/\(poId)", suggestedName: "\(po.number).pdf")
            }
        }
        .buttonStyle(.bordered)
    }

    private func lines(_ po: PurchaseOrderRecord) -> some View {
        SectionCard("Lines") {
            ForEach(Array(po.items.enumerated()), id: \.offset) { _, i in
                HStack(alignment: .top) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(lineTitle(i)).font(Theme.Font.body)
                        Text("\(quantityText(i["quantity"])) @ \(Money(json: i["unit_price"] ?? .null, currency: po.currency)?.formatted() ?? "—") · received \(quantityText(i["quantity_received"]))").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).monospacedDigit()
                    }
                    Spacer()
                    MoneyText((i["total_price"] ?? i["subtotal"]).flatMap { Money(json: $0, currency: po.currency) })
                }
                Divider()
            }
        }
    }

    static func lineTitle(_ i: JSONValue) -> String {
        if let p = i["product"] { return [p["translations"]?[0]?["name"]?.string ?? p["name"]?.string ?? p["sku"]?.string, i["variant"]?["variant_name"]?.string].compactMap { $0 }.joined(separator: " · ") }
        if let m = i["material"] { return m["name"]?.string ?? "Material" }
        return i["description"]?.string ?? "Line"
    }
    private func lineTitle(_ i: JSONValue) -> String { Self.lineTitle(i) }

    private func receipts(_ grns: [JSONValue]) -> some View {
        SectionCard("Goods received") {
            if grns.isEmpty { Text("Nothing received yet.").font(Theme.Font.secondary).foregroundStyle(Theme.textTertiary) }
            ForEach(Array(grns.enumerated()), id: \.offset) { _, g in
                HStack {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(g["grn_number"]?.string ?? "GRN").font(Theme.Font.body.weight(.semibold))
                        Text("\(g["received_date"]?.string ?? "") · \(Names.person(g["received_by"]) ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    }
                    Spacer()
                    if let id = g["id"]?.int { Button("Open") { env.navigator.push(.grn(id: id)) } }
                }
            }
        }
    }

    // MARK: Actions

    private func post(_ action: String, _ body: [String: JSONValue]) async {
        do {
            let r = try await env.session.client.post("/v1/admin/purchase-orders/\(poId)/\(action)", json: .object(body))
            env.toasts.show(r.json?["message"]?.string ?? "Done")
            reload()
        } catch { env.toasts.error(error) }
    }

    private func patchStatus(_ status: String) async {
        do {
            let r = try await env.session.client.patch("/v1/admin/purchase-orders/\(poId)/status", json: .object(["status": .string(status)]))
            env.toasts.show(r.json?["message"]?.string ?? "Status updated")
            reload()
        } catch { env.toasts.error(error) }
    }

    private func delete() async {
        do { _ = try await env.session.client.delete("/v1/admin/purchase-orders/\(poId)"); env.toasts.show("Purchase order deleted"); env.navigator.go(.purchaseOrders) } catch { env.toasts.error(error) }
    }
}

// MARK: - Receive (POST …/receive {items: [{po_item_id, quantity_received, quality_status, notes}], location_type, outlet_id?, notes?})

struct ReceiveGoodsSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let po: PurchaseOrderRecord
    let onDone: () -> Void
    @State private var outlets: Lookup?
    @State private var locationType = "warehouse"
    @State private var outletId: Int?
    @State private var qty: [Int: String] = [:]
    @State private var rejected: Set<Int> = []
    @State private var notes = ""
    @State private var error: APIError?

    private var anyLine: Bool { qty.values.contains { (JSONValue.decimal($0)?.double ?? 0) > 0 } }

    var body: some View {
        ModuleFormSheet("Receive goods — \(po.number)", submitLabel: "Confirm receipt", canSubmit: anyLine && (locationType == "warehouse" || outletId != nil), error: error, submit: submit) {
            Picker("Into", selection: $locationType) { Text("Warehouse").tag("warehouse"); Text("Outlet").tag("outlet") }.pickerStyle(.segmented)
            if locationType == "outlet", let outlets { ModuleField("Outlet", error: error?.errors["outlet_id"]?.first) { LookupPicker(label: "Outlet", lookup: outlets, selection: $outletId) } }
            ForEach(Array(po.items.enumerated()), id: \.offset) { _, i in
                let id = i["id"]?.int ?? 0
                let outstanding = (i["quantity"]?.double ?? 0) - (i["quantity_received"]?.double ?? 0)
                HStack {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(PurchaseOrderDetailView.lineTitle(i)).font(Theme.Font.body)
                        Text("ordered \(quantityText(i["quantity"])) · received \(quantityText(i["quantity_received"])) · outstanding \(outstanding.formatted(.number.precision(.fractionLength(0...2))))").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    }
                    Spacer()
                    TextField("Qty", text: Binding(get: { qty[id] ?? "" }, set: { qty[id] = $0 })).textFieldStyle(.roundedBorder).frame(width: 80)
                    Toggle("Rejected", isOn: Binding(get: { rejected.contains(id) }, set: { if $0 { rejected.insert(id) } else { rejected.remove(id) } })).toggleStyle(.button).controlSize(.small)
                }
                Button("All outstanding") { qty[id] = outstanding.formatted(.number.precision(.fractionLength(0...2)).grouping(.never)) }.font(Theme.Font.caption).disabled(outstanding <= 0)
            }
            ModuleField("Notes") { TextField("Delivery note, condition…", text: $notes, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
            Text("Whoever approved the order cannot also sign for the goods. Product costs on the book update from the PO price at the reporting rate; a currency without one leaves cost as it was.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
        .task { if outlets == nil { outlets = Lookup.outlets(env.session.client) } }
    }

    private func submit() async {
        let items: [JSONValue] = qty.compactMap { id, q in
            guard let v = JSONValue.decimal(q), (v.double ?? 0) > 0 else { return nil }
            return .object(["po_item_id": .int(id), "quantity_received": v, "quality_status": .string(rejected.contains(id) ? "rejected" : "passed")])
        }
        var body: [String: JSONValue] = ["items": .array(items), "location_type": .string(locationType), "notes": .str(notes)]
        if locationType == "outlet" { body["outlet_id"] = .int(outletId) }
        do {
            let r = try await env.session.client.post("/v1/admin/purchase-orders/\(po.id)/receive", json: .object(body))
            let costNotes = r.json?["cost_notes"]?.array?.compactMap(\.string) ?? []
            env.toasts.show("\(r.json?["receipt_number"]?.string ?? "GRN") recorded" + (costNotes.isEmpty ? "" : " — \(costNotes.joined(separator: " "))"), tone: costNotes.isEmpty ? .success : .warning)
            onDone(); dismiss()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}

// MARK: - Return (POST …/return {items: [{po_item_id, quantity, reason}], notes?})

struct PurchaseReturnSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let po: PurchaseOrderRecord
    let onDone: () -> Void
    @State private var qty: [Int: String] = [:]
    @State private var reason: [Int: String] = [:]
    @State private var notes = ""
    @State private var error: APIError?

    static let reasons = ["Defective / Damaged", "Wrong item received", "Quality not as specified", "Overstock / Excess inventory", "Incorrect quantity sent", "Price discrepancy", "Other"]

    private var chosen: [Int] { qty.compactMap { (JSONValue.decimal($0.value)?.double ?? 0) > 0 ? $0.key : nil } }

    var body: some View {
        ModuleFormSheet("Return goods — \(po.number)", submitLabel: "Raise return", canSubmit: !chosen.isEmpty && chosen.allSatisfy { !(reason[$0] ?? "").isEmpty }, error: error, submit: submit) {
            ForEach(Array(po.items.enumerated()), id: \.offset) { _, i in
                let id = i["id"]?.int ?? 0
                VStack(alignment: .leading, spacing: 4) {
                    HStack {
                        Text(PurchaseOrderDetailView.lineTitle(i)).font(Theme.Font.body)
                        Spacer()
                        Text("received \(quantityText(i["quantity_received"]))").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        TextField("Qty", text: Binding(get: { qty[id] ?? "" }, set: { qty[id] = $0 })).textFieldStyle(.roundedBorder).frame(width: 80)
                    }
                    if (JSONValue.decimal(qty[id] ?? "")?.double ?? 0) > 0 {
                        Picker("Reason", selection: Binding(get: { reason[id] ?? "" }, set: { reason[id] = $0 })) { Text("Reason…").tag(""); ForEach(Self.reasons, id: \.self) { Text($0).tag($0) } }
                    }
                }
                Divider()
            }
            ModuleField("Notes") { TextField("Notes", text: $notes, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
            Text("Raising a return takes the goods out of stock now; a rejected return puts them back.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
    }

    private func submit() async {
        let items: [JSONValue] = chosen.map { .object(["po_item_id": .int($0), "quantity": JSONValue.decimal(qty[$0] ?? "") ?? .number(0), "reason": .string(reason[$0] ?? "")]) }
        do {
            let r = try await env.session.client.post("/v1/admin/purchase-orders/\(po.id)/return", json: .object(["items": .array(items), "notes": .str(notes)]))
            env.toasts.show("Purchase return raised — \(r.json?["return_number"]?.string ?? "")"); onDone(); dismiss()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}

// MARK: - Supplier invoice (PUT …/purchase-orders/{id} {supplier_invoice_number, supplier_invoice_date, supplier_invoice_amount})

struct SupplierInvoiceSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let po: PurchaseOrderRecord
    let onDone: () -> Void
    @State private var number: String
    @State private var date: Date
    @State private var amount: String
    @State private var error: APIError?

    init(po: PurchaseOrderRecord, onDone: @escaping () -> Void) {
        self.po = po
        self.onDone = onDone
        _number = State(initialValue: po.r.string("supplier_invoice_number") ?? "")
        _date = State(initialValue: po.r.date("supplier_invoice_date") ?? Date())
        _amount = State(initialValue: po.r["supplier_invoice_amount"]?.string ?? "")
    }

    var body: some View {
        ModuleFormSheet("Supplier invoice — \(po.number)", submitLabel: "Record", error: error, submit: submit) {
            ModuleField("Invoice number", error: error?.errors["supplier_invoice_number"]?.first) { TextField("INV-…", text: $number).textFieldStyle(.roundedBorder) }
            ModuleField("Invoice date") { DatePicker("", selection: $date, displayedComponents: .date).labelsHidden() }
            ModuleField("Invoice amount (\(po.currency))", error: error?.errors["supplier_invoice_amount"]?.first) { TextField("As printed on the invoice", text: $amount).textFieldStyle(.roundedBorder) }
            Text("When recorded, the invoice amount — not the PO total — is what the payables ledger treats as owed.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
    }

    private func submit() async {
        do {
            _ = try await env.session.client.put("/v1/admin/purchase-orders/\(po.id)", json: .object([
                "supplier_invoice_number": .str(number), "supplier_invoice_date": .string(apiDay(date)), "supplier_invoice_amount": JSONValue.decimal(amount) ?? .null,
            ]))
            env.toasts.show("Supplier invoice recorded"); onDone(); dismiss()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}

// MARK: - Payments (GET/POST …/payments, POST …/payments/{p}/void)

struct SupplierPaymentsSection: View {
    @Environment(AppEnvironment.self) private var env
    let po: PurchaseOrderRecord
    @State private var res: Resource<JSONValue>?
    @State private var voiding: Record?

    var body: some View {
        SectionCard("Payments to the supplier") {
            if let res {
                LoadableView(res.state, retry: { res.load() }) { j in
                    let s = j["summary"] ?? .null
                    let cur = s["currency"]?.string ?? po.currency
                    HStack {
                        StatTile("Payable", value: s["payable"].flatMap { Money(json: $0, currency: cur) }?.formatted() ?? "—", hint: s["payable_source"]?.string == "supplier_invoice" ? "supplier invoice" : "PO total less returns")
                        StatTile("Paid", value: s["paid"].flatMap { Money(json: $0, currency: cur) }?.formatted() ?? "—")
                        StatTile("Balance", value: s["balance"].flatMap { Money(json: $0, currency: cur) }?.formatted() ?? "—", hint: s["balance_kes"].flatMap { $0.isNull ? nil : Money(json: $0, currency: "KES")?.formatted() }, tone: StatusTone.forStatus(s["status"]?.string))
                    }
                    if let basis = s["balance_kes_basis"]?.string { Text(basis).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                    if let le = s["linked_expenses"], (le["count"]?.int ?? 0) > 0 { Text("\(le["count"]?.int ?? 0) linked expense(s) in the P&L: \(Money(json: le["amount_kes"] ?? .null, currency: "KES")?.formatted() ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                    let payments = (j["payments"]?.array ?? []).map(Record.init(json:))
                    ForEach(payments) { p in
                        let voided = p["voided_at"]?.isNull == false
                        HStack {
                            VStack(alignment: .leading, spacing: 2) {
                                HStack { Text(p.string("payment_number") ?? "").font(Theme.Font.body.weight(.semibold)); StatusBadge(voided ? "voided" : p.string("method")?.humanized, tone: voided ? .danger : .info) }
                                Text("\(p.string("paid_at") ?? "") · \(p.string("reference") ?? "") · \(Names.person(p["recorder"]) ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                if voided, let why = p.string("void_reason") { Text("Voided: \(why)").font(Theme.Font.caption).foregroundStyle(Theme.Palette.danger) }
                            }
                            Spacer()
                            VStack(alignment: .trailing, spacing: 2) {
                                MoneyText(p.money("amount", currency: p.string("currency_code") ?? cur)).opacity(voided ? 0.45 : 1)
                                if let k = p["amount_kes"], !k.isNull, (p.string("currency_code") ?? cur) != "KES" { Text("= \(Money(json: k, currency: "KES")?.formatted() ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                            }
                            if !voided, env.session.can("procurement.pay_void") { Button("Void") { voiding = p }.font(Theme.Font.caption) }
                        }
                        Divider()
                    }
                }
            }
        }
        .task { if res == nil { load() } }
        .sheet(item: $voiding) { p in
            TextPromptSheet("Void \(p.string("payment_number") ?? "payment")", placeholder: "Reason (required, at least 5 characters)", confirmLabel: "Void", destructive: true) { reason in
                do {
                    _ = try await env.session.client.post("/v1/admin/purchase-orders/\(po.id)/payments/\(p.id)/void", json: .object(["reason": .string(reason)]))
                    env.toasts.show("Payment voided"); load()
                } catch { env.toasts.error(error) }
            }
        }
    }

    private func load() {
        let c = env.session.client, id = po.id
        res = Resource { try await c.get("/v1/admin/purchase-orders/\(id)/payments").json ?? .null }
        res?.load()
    }
}

/// POST …/payments {amount, method, reference?, paid_at?, notes?, client_request_id} — idempotent on client_request_id.
struct RecordSupplierPaymentSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let po: PurchaseOrderRecord
    let onDone: () -> Void
    @State private var amount = ""
    @State private var method = "bank_transfer"
    @State private var reference = ""
    @State private var paidAt = Date()
    @State private var notes = ""
    @State private var requestId = UUID().uuidString
    @State private var error: APIError?

    static let methods = ["bank_transfer", "mpesa", "cash", "cheque", "card", "other"]
    static let needsReference: Set<String> = ["bank_transfer", "mpesa", "cheque", "card"]

    private var valid: Bool { (JSONValue.decimal(amount)?.double ?? 0) > 0 && (!Self.needsReference.contains(method) || !reference.trimmingCharacters(in: .whitespaces).isEmpty) }

    var body: some View {
        ModuleFormSheet("Record payment — \(po.number)", submitLabel: "Record payment", canSubmit: valid, error: error, submit: submit) {
            ModuleField("Amount (\(po.currency))", error: error?.errors["amount"]?.first) { TextField("0.00", text: $amount).textFieldStyle(.roundedBorder) }
            ModuleField("Method", error: error?.errors["method"]?.first) { Picker("Method", selection: $method) { ForEach(Self.methods, id: \.self) { Text($0.humanized).tag($0) } } }
            ModuleField(Self.needsReference.contains(method) ? "Reference (required)" : "Reference", error: error?.errors["reference"]?.first) { TextField("Transaction code", text: $reference).textFieldStyle(.roundedBorder) }
            ModuleField("Paid on") { DatePicker("", selection: $paidAt, displayedComponents: .date).labelsHidden() }
            ModuleField("Notes") { TextField("Notes", text: $notes, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
            Text("Money paid to a supplier is not an expense; it settles the payables ledger. Retrying this form cannot record it twice.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
    }

    private func submit() async {
        do {
            let r = try await env.session.client.post("/v1/admin/purchase-orders/\(po.id)/payments", json: .object([
                "amount": JSONValue.decimal(amount) ?? .number(0), "method": .string(method), "reference": .str(reference), "paid_at": .string(apiDay(paidAt)), "notes": .str(notes), "client_request_id": .string(requestId),
            ]))
            env.toasts.show(r.json?["message"]?.string ?? "Payment recorded"); onDone(); dismiss()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}

// MARK: - Owner revision (PUT …/revise — super admin only; whole order, reason required)

struct RevisePurchaseOrderSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let po: PurchaseOrderRecord
    let onDone: () -> Void
    @State private var reason = ""
    @State private var expected: Date
    @State private var terms: String
    @State private var notes: String
    @State private var shipping: String
    @State private var tax: String
    @State private var lines: [Line]
    @State private var error: APIError?

    struct Line: Identifiable { let id = UUID(); let existingId: Int?; let type: String; let itemId: Int; let variantId: Int?; let title: String; var qty: String; var price: String }

    init(po: PurchaseOrderRecord, onDone: @escaping () -> Void) {
        self.po = po
        self.onDone = onDone
        _expected = State(initialValue: po.expected ?? Date())
        _terms = State(initialValue: po.r.string("payment_terms") ?? "")
        _notes = State(initialValue: po.r.string("notes") ?? "")
        _shipping = State(initialValue: po.r["shipping_amount"]?.string ?? po.r["shipping_cost"]?.string ?? "")
        _tax = State(initialValue: po.r["tax_amount"]?.string ?? po.r["tax"]?.string ?? "")
        _lines = State(initialValue: po.items.map { i in
            Line(existingId: i["id"]?.int, type: i["item_type"]?.string ?? (i["material_id"]?.int != nil ? "material" : "product"), itemId: i["product_id"]?.int ?? i["material_id"]?.int ?? i["item_id"]?.int ?? 0, variantId: i["product_variant_id"]?.int, title: PurchaseOrderDetailView.lineTitle(i), qty: i["quantity"]?.string ?? "", price: i["unit_price"]?.string ?? "")
        })
    }

    private var valid: Bool { reason.trimmingCharacters(in: .whitespaces).count >= 5 && !lines.isEmpty && lines.allSatisfy { JSONValue.decimal($0.qty) != nil && JSONValue.decimal($0.price) != nil } }

    var body: some View {
        ModuleFormSheet("Revise \(po.number) (owner)", submitLabel: "Save revision", canSubmit: valid, error: error, submit: submit) {
            ModuleField("Reason for the revision (required)", error: error?.errors["reason"]?.first) { TextField("Why the issued order changes", text: $reason, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
            ModuleField("Expected delivery") { DatePicker("", selection: $expected, displayedComponents: .date).labelsHidden() }
            ForEach($lines) { $l in
                HStack {
                    Text(l.title).font(Theme.Font.secondary).frame(maxWidth: .infinity, alignment: .leading)
                    TextField("Qty", text: $l.qty).textFieldStyle(.roundedBorder).frame(width: 70)
                    TextField("Unit price", text: $l.price).textFieldStyle(.roundedBorder).frame(width: 110)
                    Button { lines.removeAll { $0.id == l.id } } label: { Image(systemName: "minus.circle") }.buttonStyle(.plain).foregroundStyle(Theme.Palette.danger)
                }
            }
            HStack {
                ModuleField("Shipping") { TextField("0", text: $shipping).textFieldStyle(.roundedBorder) }
                ModuleField("Tax") { TextField("0", text: $tax).textFieldStyle(.roundedBorder) }
            }
            ModuleField("Payment terms") { TextField("", text: $terms).textFieldStyle(.roundedBorder) }
            ModuleField("Notes") { TextField("", text: $notes, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
            Text("The order as it was is kept as a revision; received quantities are not changed here (correct the GRN instead).").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
    }

    private func submit() async {
        let items: [JSONValue] = lines.map { l in
            var row: [String: JSONValue] = ["type": .string(l.type), "item_id": .int(l.itemId), "quantity": JSONValue.decimal(l.qty) ?? .number(0), "unit_price": JSONValue.decimal(l.price) ?? .number(0)]
            if let e = l.existingId { row["id"] = .int(e) }
            if let v = l.variantId { row["product_variant_id"] = .int(v) }
            return .object(row)
        }
        var body: [String: JSONValue] = ["reason": .string(reason.trimmingCharacters(in: .whitespaces)), "expected_delivery_date": .string(apiDay(expected)), "payment_terms": .str(terms), "notes": .str(notes), "items": .array(items)]
        if let s = JSONValue.decimal(shipping) { body["shipping_cost"] = s }
        if let t = JSONValue.decimal(tax) { body["tax"] = t }
        do {
            let r = try await env.session.client.put("/v1/admin/purchase-orders/\(po.id)/revise", json: .object(body))
            env.toasts.show(r.json?["message"]?.string ?? "Revised"); onDone(); dismiss()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}
