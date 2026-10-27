import SwiftUI
import BethanyKit

// MARK: - Quotations (QuotationController: index/store/update/revise/issue/accept/destroy/reprice/revisions)

struct QuotationsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var list: PagedList<Record>?
    @State private var search = ""
    @State private var status = ""
    @State private var builder: Builder?
    @State private var detail: Record?
    @State private var accepting: Record?

    static let statuses = ["draft", "sent", "accepted", "declined", "expired", "converted", "void"]

    struct Builder: Identifiable {
        let editing: Record?
        let revise: Bool
        var id: String { "\(editing?.id ?? "new")-\(revise)" }
    }

    var body: some View {
        Page("Quotations", subtitle: "Front of the quotation → invoice → receipt flow") {
            HStack(spacing: Theme.Space.s) {
                SalesSearchField(text: $search, placeholder: "Quote no., customer, phone, email") { Task { await reload() } }
                Picker("Status", selection: $status) {
                    Text("All statuses").tag("")
                    ForEach(Self.statuses, id: \.self) { Text($0.humanized).tag($0) }
                }.frame(maxWidth: 170)
                Spacer()
                if env.session.can("quotations.create") {
                    Button { builder = Builder(editing: nil, revise: false) } label: { Label("New quotation", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                }
            }
            .onChange(of: status) { _, _ in Task { await reload() } }
            if let list {
                LoadableView(list.state, retry: { Task { await list.load() } }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No quotations", message: "Draft one for a customer who wants a price in writing.", symbol: "doc.badge.ellipsis") }) { rows in
                    LazyVStack(spacing: Theme.Space.s) { ForEach(rows) { row($0) } }
                    LoadMoreRow(list: list)
                }
            }
        }
        .task { if list == nil { await reload() } }
        .refreshable { await list?.load() }
        .sheet(item: $builder) { b in
            QuotationBuilderSheet(editing: b.editing, revise: b.revise, onDone: { builder = nil; Task { await list?.load() } }, onCancel: { builder = nil })
        }
        .sheet(item: $detail) { q in QuotationDetailSheet(quotation: q, onClose: { detail = nil }) }
        .sheet(item: $accepting) { q in
            AcceptQuotationSheet(quotation: q, onDone: { orderId in accepting = nil; Task { await list?.load() }; if let orderId { env.navigator.push(.order(id: orderId)) } }, onCancel: { accepting = nil })
        }
    }

    private func reload() async {
        if list == nil { list = PagedList(client: env.session.client, path: "/v1/admin/quotations") }
        list?.query = ["search": search.isEmpty ? nil : search, "status": status.isEmpty ? nil : status]
        await list?.load()
    }

    private func row(_ q: Record) -> some View {
        let s = env.session
        let st = q.status ?? ""
        let issued = ["sent", "accepted", "declined", "expired", "converted"].contains(st)
        return SectionCard {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 3) {
                    HStack(spacing: 6) {
                        Button { detail = q } label: { Text(q.string("quote_number") ?? "Draft #\(q.id)").font(Theme.Font.mono) }.buttonStyle(.plain)
                        StatusBadge(st)
                        if st == "void", let reason = q.string("void_reason") { Text(reason).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary).lineLimit(1) }
                    }
                    Text(Sales.name(first: q.string("customer_first_name"), last: q.string("customer_last_name")) ?? q.string("customer_email") ?? q.string("customer_phone") ?? "No customer").font(Theme.Font.body)
                    HStack(spacing: 6) {
                        if let v = q.string("valid_until") { Text("valid until \(v.prefix(10))") }
                        if let by = q.string("served_by") { Text("· \(by)") }
                        Text("· \((q.string("source") ?? "admin").humanized)")
                    }
                    .font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                }
                Spacer()
                VStack(alignment: .trailing, spacing: 2) {
                    MoneyText(q.money("total_amount"), emphasis: true)
                    DateText(q.date("issued_at") ?? q.date("created_at"), style: .relative)
                }
            }
            FlowRow {
                if st == "draft", s.can("quotations.create") { Button("Edit") { builder = Builder(editing: q, revise: false) } }
                if st == "draft", s.can("quotations.issue") {
                    ConfirmButton("Issue quotation", message: "Issuing numbers the quotation and makes it the customer's copy.", action: { await issue(q) }) { Text("Issue") }
                }
                if st == "sent", s.can("quotations.issue") { Button("Accept → Invoice") { accepting = q }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
                if issued, let token = q.string("quote_token"), st != "void" {
                    CopyButton("Copy link", text: env.config.hubURL.appendingPathComponent("quote/\(token)").absoluteString, confirmation: "Customer link copied")
                }
                if issued { DocumentButton("Quote PDF", path: "/v1/admin/pdf/quotations/\(q.id)", suggestedName: "quotation-\(q.id).pdf") }
                if st == "converted", let oid = q["invoice_document"]?["documentable_id"]?.string {
                    DocumentButton("Invoice PDF", path: "/v1/admin/pdf/orders/\(oid)/invoice", suggestedName: "invoice-\(oid).pdf")
                    if let id = Int(oid) { Button("Open order") { env.navigator.push(.order(id: id)) } }
                }
                if s.permissions.isSuperAdmin, ["sent", "accepted", "declined", "expired"].contains(st) {
                    Button("Revise (owner)") { builder = Builder(editing: q, revise: true) }
                }
                if st == "draft", s.can("quotations.delete") {
                    ConfirmButton("Delete draft", message: "The draft is removed.", destructive: true, action: { await delete(q) }) { Text("Delete").foregroundStyle(Theme.Palette.danger) }
                }
            }
            .font(Theme.Font.secondary)
        }
    }

    private func issue(_ q: Record) async {
        do { let r = try await env.session.client.post("/v1/admin/quotations/\(q.id)/issue"); env.toasts.show(r.json?["message"]?.string ?? "Quotation issued"); await list?.load() } catch { env.toasts.error(error) }
    }

    private func delete(_ q: Record) async {
        do { _ = try await env.session.client.delete("/v1/admin/quotations/\(q.id)"); env.toasts.show("Quotation deleted"); list?.remove { $0.id == q.id } } catch { env.toasts.error(error) }
    }
}

/// POST /quotations/{id}/accept {due_in_days?} → {order: {id}, invoice: {number}}.
struct AcceptQuotationSheet: View {
    @Environment(AppEnvironment.self) private var env
    let quotation: Record
    let onDone: (Int?) -> Void
    let onCancel: () -> Void
    @State private var dueInDays = ""
    var body: some View {
        SheetFrame(title: "Accept \(quotation.string("quote_number") ?? "")", submitLabel: "Create invoice", canSubmit: dueInDays.isEmpty || Int(dueInDays) != nil, onCancel: onCancel, onSubmit: {
            let body: JSONValue = Int(dueInDays).map { JSONValue.object(["due_in_days": .number(Double($0))]) } ?? JSONValue.object([:])
            let r = try await env.session.client.post("/v1/admin/quotations/\(quotation.id)/accept", json: body)
            env.toasts.show("Invoice \(r.json?["invoice"]?["number"]?.string ?? "") created")
            onDone(r.json?["order"]?["id"]?.int)
        }) {
            Text("Accepting creates the order and its invoice; the order is a receivable until it is paid.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            DetailRow("Total") { MoneyText(quotation.money("total_amount")) }
            TextInput(label: "Due in days (optional, 0–365)", text: $dueInDays)
        }
    }
}

/// The quotation's lines and, for the owner, its revisions (GET /quotations/{id}/revisions).
struct QuotationDetailSheet: View {
    @Environment(AppEnvironment.self) private var env
    let quotation: Record
    let onClose: () -> Void
    @State private var revisions: Resource<JSONValue>?
    var body: some View {
        let items = (quotation["items"]?.array ?? []).map(Record.init(json:))
        let cc = quotation.string("currency_code") ?? "KES"
        SheetFrame(title: quotation.string("quote_number") ?? "Draft quotation", submitLabel: "Close", onCancel: onClose, onSubmit: { onClose() }) {
            HStack { StatusBadge(quotation.status); Spacer(); MoneyText(quotation.money("total_amount"), emphasis: true) }
            DetailRow("Customer", Sales.name(first: quotation.string("customer_first_name"), last: quotation.string("customer_last_name")))
            DetailRow("Phone", quotation.string("customer_phone"))
            DetailRow("Email", quotation.string("customer_email"))
            DetailRow("Valid until", quotation.string("valid_until").map { String($0.prefix(10)) })
            ForEach(items) { i in
                HStack {
                    VStack(alignment: .leading) {
                        Text(i.string("product_name") ?? "").font(Theme.Font.body)
                        Text(["\(i.string("quantity") ?? "") × \(i.money("unit_price", currency: cc)?.formatted() ?? "")", i.string("sku")].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    }
                    Spacer()
                    MoneyText(i.money("total_price", currency: cc))
                }
            }
            MoneyBreakdown(record: quotation)
            if let n = quotation.string("notes"), !n.isEmpty { DetailRow("Notes", n) }
            if let t = quotation.string("terms"), !t.isEmpty { DetailRow("Terms", t) }
            if let revisions {
                LoadableView(revisions.state) { json in
                    Text("Revisions (current rev \(json["current_revision"]?.string ?? "0"))").font(Theme.Font.secondary.weight(.semibold))
                    RevisionList(rows: (json["revisions"]?.array ?? []).map(Record.init(json:)))
                }
            }
        }
        .task {
            if revisions == nil, quotation.status != "draft" {
                let client = env.session.client, id = quotation.id
                revisions = Resource { try await client.get("/v1/admin/quotations/\(id)/revisions").json ?? .null }
                revisions?.load()
            }
        }
    }
}

// MARK: Builder — POST /quotations | PUT /quotations/{id} | PUT /quotations/{id}/revise

struct QuotationBuilderSheet: View {
    @Environment(AppEnvironment.self) private var env
    let editing: Record?
    let revise: Bool
    let onDone: () -> Void
    let onCancel: () -> Void

    @State private var firstName = ""
    @State private var lastName = ""
    @State private var email = ""
    @State private var phone = ""
    @State private var servedBy = ""
    @State private var currency = "KES"
    @State private var currencies: [Record] = []
    @State private var shipping = ""
    @State private var validUntil: Date?
    @State private var notes = ""
    @State private var terms = ""
    @State private var reason = ""
    @State private var lines: [OrderLineEditor.Line] = []
    @State private var adding = false
    @State private var repricing = false
    @State private var keptKeys: Set<UUID> = []

    private var canSubmit: Bool {
        !lines.isEmpty && lines.allSatisfy { (Int($0.quantity) ?? 0) > 0 && Sales.amountJSON($0.unitPrice) != nil }
            && (!revise || reason.trimmingCharacters(in: .whitespaces).count >= 5)
    }

    var body: some View {
        SheetFrame(title: revise ? "Revise \(editing?.string("quote_number") ?? "")" : (editing == nil ? "New quotation" : "Edit quotation"), submitLabel: revise ? "Save revision" : "Save", canSubmit: canSubmit, onCancel: onCancel, onSubmit: {
            var body: [String: JSONValue] = [
                "currency_code": .string(currency),
                "customer_first_name": Sales.optionalString(firstName), "customer_last_name": Sales.optionalString(lastName),
                "customer_email": Sales.optionalString(email), "customer_phone": Sales.optionalString(phone),
                "served_by": Sales.optionalString(servedBy), "notes": Sales.optionalString(notes), "terms": Sales.optionalString(terms),
                "valid_until": validUntil.map { JSONValue.string(HubDates.dayString($0)) } ?? JSONValue.null,
                "items": .array(lines.map { l in
                    var o: [String: JSONValue] = [
                        "product_name": .string(l.name), "quantity": .number(Double(Int(l.quantity) ?? 1)),
                        "unit_price": Sales.amountJSON(l.unitPrice) ?? .string("0"),
                        "product_id": l.productId.map { JSONValue.string($0) } ?? .null,
                        "product_variant_id": l.variantId.map { JSONValue.string($0) } ?? .null,
                    ]
                    if let sku = l.sku { o["sku"] = .string(sku) }
                    if let d = Sales.amountJSON(l.discount) { o["discount_amount"] = d }
                    return .object(o)
                }),
            ]
            if let s = Sales.amountJSON(shipping) { body["shipping_amount"] = s }
            if revise { body["reason"] = .string(reason.trimmingCharacters(in: .whitespaces)) }
            let client = env.session.client
            if let editing {
                _ = try await client.put(revise ? "/v1/admin/quotations/\(editing.id)/revise" : "/v1/admin/quotations/\(editing.id)", json: .object(body))
            } else {
                _ = try await client.post("/v1/admin/quotations", json: .object(body))
            }
            env.toasts.show(revise ? "Revision saved — the issued version is kept" : "Quotation saved")
            onDone()
        }) {
            if revise { TextInput(label: "Reason for the revision (required)", text: $reason, multiline: true) }
            HStack { TextInput(label: "First name", text: $firstName); TextInput(label: "Last name", text: $lastName) }
            HStack { TextInput(label: "Phone", text: $phone); TextInput(label: "Email", text: $email) }
            HStack {
                SalesLabeledField(label: "Currency") {
                    Picker("Currency", selection: $currency) {
                        ForEach(currencies) { c in Text("\(c.string("code") ?? "") — \(c.string("name") ?? "")").tag(c.string("code") ?? "") }
                        if !currencies.contains(where: { $0.string("code") == currency }) { Text(currency).tag(currency) }
                    }.labelsHidden()
                }
                TextInput(label: "Served by", text: $servedBy)
                SalesOptionalDateField(label: "Valid until", date: $validUntil)
            }
            .onChange(of: currency) { old, new in Task { await reprice(from: old, to: new) } }
            SalesLabeledField(label: "Lines") {
                ForEach($lines) { $l in
                    HStack(alignment: .top, spacing: Theme.Space.s) {
                        VStack(alignment: .leading, spacing: 2) {
                            TextField("Description", text: $l.name).textFieldStyle(.roundedBorder)
                            if let sku = l.sku { Text(sku).font(Theme.Font.mono).foregroundStyle(Theme.textTertiary) }
                            if keptKeys.contains(l.id) { Text("Typed price kept — not converted").font(Theme.Font.caption).foregroundStyle(Theme.Palette.amber) }
                        }
                        TextField("Qty", text: $l.quantity).textFieldStyle(.roundedBorder).frame(width: 56)
                        TextField("Unit price", text: $l.unitPrice).textFieldStyle(.roundedBorder).frame(width: 100)
                        TextField("Discount", text: $l.discount).textFieldStyle(.roundedBorder).frame(width: 80)
                        Button(role: .destructive) { lines.removeAll { $0.id == l.id } } label: { Image(systemName: "trash") }.buttonStyle(.plain).foregroundStyle(Theme.Palette.danger)
                    }
                }
                HStack {
                    Button { adding = true } label: { Label("Add from catalogue", systemImage: "plus") }
                    Button("Add free line") { lines.append(OrderLineEditor.Line(recordId: nil, productId: nil, variantId: nil, name: "", sku: nil, quantity: "1", unitPrice: "", discount: "0", locked: false)) }
                    if repricing { ProgressView().controlSize(.small) }
                }
                .font(Theme.Font.secondary)
            }
            SalesLabeledField(label: "Shipping (\(currency))") { TextField("0.00", text: $shipping).textFieldStyle(.roundedBorder).frame(maxWidth: 160) }
            TextInput(label: "Notes", text: $notes, multiline: true)
            TextInput(label: "Terms", text: $terms, multiline: true)
            Text("Subtotal, tax and total are computed by the server when saved.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
        .onAppear(perform: seed)
        .task {
            if let r = try? await env.session.client.get("/v1/settings/currencies") {
                currencies = r.json?.salesRecords.filter { $0["sellable"]?.bool != false && $0["is_active"]?.bool != false } ?? []
            }
        }
        .sheet(isPresented: $adding) {
            ProductPickerSheet(currency: currency, onPicked: { lines.append($0); adding = false }, onCancel: { adding = false })
        }
    }

    private func seed() {
        guard lines.isEmpty, let q = editing else { return }
        firstName = q.string("customer_first_name") ?? ""
        lastName = q.string("customer_last_name") ?? ""
        email = q.string("customer_email") ?? ""
        phone = q.string("customer_phone") ?? ""
        servedBy = q.string("served_by") ?? ""
        currency = q.string("currency_code") ?? "KES"
        shipping = q["shipping_amount"]?.string ?? ""
        validUntil = q.date("valid_until")
        notes = q.string("notes") ?? ""
        terms = q.string("terms") ?? ""
        lines = (q["items"]?.array ?? []).map(Record.init(json:)).map { i in
            OrderLineEditor.Line(recordId: nil, productId: i["product_id"]?.string, variantId: i["product_variant_id"]?.string, name: i.string("product_name") ?? "", sku: i.string("sku"),
                                 quantity: i["quantity"]?.string ?? "1", unitPrice: i["unit_price"]?.string ?? "", discount: i["discount_amount"]?.string ?? "0", locked: false)
        }
    }

    /// POST /quotations/reprice {from, to, lines[{key, product_id?, unit_price, name}]}:
    /// catalogue lines get the catalogue price in the new currency, free
    /// lines are converted, a human-typed price is kept and named.
    private func reprice(from: String, to: String) async {
        guard from != to, !lines.isEmpty else { keptKeys = []; return }
        repricing = true
        defer { repricing = false }
        do {
            let r = try await env.session.client.post("/v1/admin/quotations/reprice", json: .object([
                "from": .string(from), "to": .string(to),
                "lines": .array(lines.map { l in
                    var o: [String: JSONValue] = ["key": .string(l.id.uuidString), "unit_price": Sales.amountJSON(l.unitPrice) ?? .string("0"), "name": .string(l.name.isEmpty ? "This line" : l.name)]
                    if let p = l.productId { o["product_id"] = .string(p) }
                    return .object(o)
                }),
            ]))
            var kept: Set<UUID> = []
            for priced in r.json?["lines"]?.array ?? [] {
                guard let key = priced["key"]?.string, let uuid = UUID(uuidString: key), let i = lines.firstIndex(where: { $0.id == uuid }) else { continue }
                lines[i].unitPrice = priced["unit_price"]?.string ?? lines[i].unitPrice
                if priced["source"]?.string == "kept" { kept.insert(uuid) }
            }
            keptKeys = kept
            for w in r.json?["warnings"]?.array?.compactMap(\.string) ?? [] { env.toasts.show(w, tone: .warning) }
        } catch { env.toasts.error(error) }
    }
}

// MARK: - Invoices (InvoiceController: index/show/void)

struct InvoicesView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var list: PagedList<Record>?
    @State private var search = ""
    @State private var status = ""
    @State private var voiding: Record?

    var body: some View {
        Page("Invoices", subtitle: "The invoice stage of quotation → invoice → receipt") {
            HStack(spacing: Theme.Space.s) {
                SalesSearchField(text: $search, placeholder: "Invoice no., customer, order") { Task { await reload() } }
                Picker("Status", selection: $status) {
                    Text("All").tag(""); Text("Open (issued)").tag("issued"); Text("Paid").tag("paid"); Text("Void").tag("void")
                }.frame(maxWidth: 170)
                Spacer()
            }
            .onChange(of: status) { _, _ in Task { await reload() } }
            if let list {
                LoadableView(list.state, retry: { Task { await list.load() } }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No invoices", message: "An invoice is created when a quotation is accepted.", symbol: "doc.richtext") }) { rows in
                    LazyVStack(spacing: Theme.Space.s) { ForEach(rows) { row($0) } }
                    LoadMoreRow(list: list)
                }
            }
        }
        .task { if list == nil { await reload() } }
        .refreshable { await list?.load() }
        .sheet(item: $voiding) { inv in VoidInvoiceSheet(invoice: inv, onDone: { voiding = nil; Task { await list?.load() } }, onCancel: { voiding = nil }) }
    }

    private func reload() async {
        if list == nil { list = PagedList(client: env.session.client, path: "/v1/admin/invoices") }
        list?.query = ["search": search.isEmpty ? nil : search, "status": status.isEmpty ? nil : status]
        await list?.load()
    }

    private func row(_ inv: Record) -> some View {
        let order = inv["order"]
        let docStatus = inv.string("doc_status") ?? ""
        let orderDead = Sales.isDead(order?["status"]?.string)
        let payable = order?["payable"]?.bool ?? !orderDead
        let canVoid = env.session.permissions.isSuperAdmin && docStatus == "issued" && order?["payment_status"]?.string == "pending" && ["pending", "confirmed"].contains(order?["status"]?.string ?? "pending")
        return SectionCard {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 3) {
                    HStack(spacing: 6) {
                        Text(inv.string("invoice_number") ?? "").font(Theme.Font.mono)
                        if let rev = inv.int("revision"), rev > 0 { Text("Rev \(rev)").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                        StatusBadge(docStatus)
                        if docStatus != "void", let ps = order?["payment_status"]?.string { StatusBadge(ps) }
                    }
                    Text(inv.string("customer_name") ?? "—").font(Theme.Font.body)
                    HStack(spacing: 6) {
                        if let on = order?["order_number"]?.string { Text(on) }
                        if let q = inv["quotation"]?["number"]?.string { Text("· from \(q)") }
                        if let by = inv.string("served_by") { Text("· \(by)") }
                    }
                    .font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    HStack(spacing: 6) {
                        if let i = inv.string("issued_at") { Text("issued \(i.prefix(10))") }
                        if let d = inv.string("due_date") { Text("· due \(d.prefix(10))") }
                    }
                    .font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                }
                Spacer()
                MoneyText(inv.money("amount"), emphasis: true)
            }
            FlowRow {
                if let oid = order?["id"]?.int { Button("Open order") { env.navigator.push(.order(id: oid)) } }
                if let oid = order?["id"]?.string { DocumentButton("Invoice PDF", path: "/v1/admin/pdf/orders/\(oid)/invoice", suggestedName: "invoice-\(oid).pdf") }
                if docStatus != "void", order?["payment_status"]?.string != "paid", payable, let token = order?["pay_token"]?.string {
                    CopyButton("Copy pay link", text: env.config.hubURL.appendingPathComponent("pay/\(token)").absoluteString, confirmation: "Pay link copied")
                } else if docStatus != "void", orderDead {
                    Text("Not payable — the order is \(order?["status"]?.string ?? "closed").").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                }
                if canVoid { Button("Void…", role: .destructive) { voiding = inv } }
            }
            .font(Theme.Font.secondary)
        }
    }
}

/// POST /invoices/{id}/void {reason} — owner.only + step-up (the client
/// runs the step-up itself).
struct VoidInvoiceSheet: View {
    @Environment(AppEnvironment.self) private var env
    let invoice: Record
    let onDone: () -> Void
    let onCancel: () -> Void
    @State private var reason = ""
    var body: some View {
        SheetFrame(title: "Void \(invoice.string("invoice_number") ?? "invoice")", submitLabel: "Void invoice", canSubmit: !reason.trimmingCharacters(in: .whitespaces).isEmpty, destructive: true, onCancel: onCancel, onSubmit: {
            let r = try await env.session.client.post("/v1/admin/invoices/\(invoice.id)/void", json: .object(["reason": .string(reason)]))
            env.toasts.show(r.json?["message"]?.string ?? "Invoice voided")
            onDone()
        }) {
            Text("Voids the unpaid invoice, cancels its order and voids its quotation. You will be asked to confirm your identity.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            TextInput(label: "Reason (required)", text: $reason, multiline: true)
        }
    }
}

// MARK: - Interest carts (GET /admin/interest-carts — read-only pipeline)

struct InterestCartsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var list: PagedList<Record>?
    @State private var q = ""
    @State private var status = ""
    @State private var channel = ""

    var body: some View {
        Page("Interest Carts", subtitle: "Pipeline, not sales — the BH-XXXX tokens Neema hands customers") {
            HStack(spacing: Theme.Space.s) {
                SalesSearchField(text: $q, placeholder: "Token (BH-0QVP2358), name or phone") { Task { await reload() } }
                Picker("Status", selection: $status) {
                    Text("All statuses").tag("")
                    ForEach(Sales.interestCartStatuses, id: \.0) { Text($0.1).tag($0.0) }
                }.frame(maxWidth: 190)
                Picker("Channel", selection: $channel) {
                    Text("All channels").tag("")
                    ForEach(Sales.interestCartChannels, id: \.self) { Text($0.capitalizedFirst).tag($0) }
                }.frame(maxWidth: 160)
                Spacer()
            }
            .onChange(of: status) { _, _ in Task { await reload() } }
            .onChange(of: channel) { _, _ in Task { await reload() } }
            if let list {
                LoadableView(list.state, retry: { Task { await list.load() } }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No carts found", message: "Check the code for typos — tokens look like BH-0QVP2358. Carts started before this ledger went live were never captured.", symbol: "cart.badge.questionmark") }) { rows in
                    LazyVStack(spacing: Theme.Space.s) { ForEach(rows) { row($0) } }
                    LoadMoreRow(list: list)
                }
            }
        }
        .task { if list == nil { await reload() } }
        .refreshable { await list?.load() }
    }

    private func reload() async {
        if list == nil { list = PagedList(client: env.session.client, path: "/v1/admin/interest-carts") }
        list?.query = ["q": q.isEmpty ? nil : q, "status": status.isEmpty ? nil : status, "channel": channel.isEmpty ? nil : channel]
        await list?.load()
    }

    private func row(_ r: Record) -> some View {
        let items = r["items"]?.array ?? []
        return SectionCard {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 3) {
                    HStack(spacing: 6) {
                        Text(r.string("token") ?? "").font(Theme.Font.mono.weight(.bold))
                        StatusBadge(Sales.interestCartStatuses.first { $0.0 == r.status }?.1 ?? r.status, tone: r.status == "abandoned" ? .neutral : (r.status?.hasSuffix("_order") == true ? .success : .amber))
                        StatusBadge(r.string("channel"), tone: .info)
                    }
                    Text([r.string("name"), r.string("phone"), r.string("church")].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.secondary)
                    ForEach(Array(items.enumerated()), id: \.offset) { _, it in
                        let size = it["size"]?.string.map { " (" + $0 + ")" } ?? ""
                        Text("\(it["quantity"]?.string ?? "1") × \((it["slug"]?.string ?? "").replacingOccurrences(of: "-", with: " ").capitalized)" + size).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    }
                    if let ref = r.string("order_ref") { Text("Order \(ref)").font(Theme.Font.caption).foregroundStyle(Theme.Palette.success) }
                }
                Spacer()
                VStack(alignment: .trailing, spacing: 2) {
                    if let cur = r.string("currency"), let sub = r["subtotal"], !sub.isNull { MoneyText(Money(json: sub, currency: cur)) }
                    DateText(r.date("updated_at"), style: .relative)
                }
            }
        }
    }
}
