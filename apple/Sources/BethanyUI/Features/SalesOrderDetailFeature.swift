import SwiftUI
import BethanyKit

// MARK: - Order detail (GET /admin/orders/{id} → {order})

/// One order: header, money, items, customer, payments, notes, comments,
/// history, production, shipments, revisions, audit log. Every action is a
/// route in OrderController (or Payment/Shipment/Comment controllers) and is
/// offered only when the route's permission is held and the order's state
/// allows it — the server decides again.
struct OrderDetailView: View {
    @Environment(AppEnvironment.self) private var env
    let orderId: Int
    @State private var resource: Resource<Record>?
    @State private var sheet: OrderSheet?
    @State private var showAudit = false
    @State private var showRevisions = false
    @State private var paymentLink: String?

    enum OrderSheet: Identifiable {
        case status, payment, refund, void, deposit, shippingFee, currency, attachCustomer, note, createShipment
        case production(Record), verifyMpesa(Record), verifyPaystack(Record), reverse(Record?), revise(Record)
        var id: String {
            switch self {
            case .status: return "status"
            case .payment: return "payment"
            case .refund: return "refund"
            case .void: return "void"
            case .deposit: return "deposit"
            case .shippingFee: return "shippingFee"
            case .currency: return "currency"
            case .attachCustomer: return "attachCustomer"
            case .note: return "note"
            case .createShipment: return "createShipment"
            case .production(let r): return "production-\(r.id)"
            case .verifyMpesa(let r): return "mpesa-\(r.id)"
            case .verifyPaystack(let r): return "paystack-\(r.id)"
            case .reverse(let r): return "reverse-\(r?.id ?? "all")"
            case .revise(let r): return "revise-\(r.id)"
            }
        }
    }

    var body: some View {
        Page(resource?.value?.string("order_number") ?? "Order") {
            if let resource {
                LoadableView(resource.state, retry: { resource.load() }) { order in
                    header(order)
                    actions(order)
                    if let link = paymentLink { paymentLinkBanner(link) }
                    OrderItemsSection(order: order, reload: reload, onProduction: { sheet = .production($0) })
                    HStack(alignment: .top, spacing: Theme.Space.l) {
                        customer(order)
                        SectionCard("Totals") {
                            MoneyBreakdown(record: order)
                            if let dep = order.money("deposit_amount") {
                                DetailRow("Minimum deposit") { MoneyText(dep) }
                                DetailRow("Balance due by", order.string("balance_due_date"))
                            }
                            if order["is_international"]?.bool == true { StatusBadge("international", tone: .info) }
                            if let inv = order["invoice_document"], let n = inv["number"]?.string { DetailRow("Invoice", n) }
                            if let q = order.string("quotation_number") { DetailRow("From quotation", q) }
                        }
                    }
                    payments(order)
                    PendingProposalsSection(subjectType: "order", subjectId: orderId)
                    if env.session.can("shipment.view") { OrderShipmentsSection(order: order, onCreate: { sheet = .createShipment }) }
                    productionOrders(order)
                    notes(order)
                    CommentsThread(model: "Order", id: orderId)
                    history(order)
                    if showRevisions { SalesRevisionsSection(orderId: orderId, order: order, onRevise: { sheet = .revise($0) }) }
                    if showAudit { SalesAuditLogSection(path: "/v1/admin/orders/\(orderId)/audit-log") }
                }
            }
        }
        .task {
            if resource == nil {
                let client = env.session.client
                let id = orderId
                resource = Resource {
                    let r = try await client.get("/v1/admin/orders/\(id)")
                    return Record(json: r.json?["order"] ?? .null)
                }
                resource?.load()
            }
        }
        .refreshable { await resource?.reload() }
        .sheet(item: $sheet) { which in
            if let order = resource?.value {
                sheetView(which, order: order)
            }
        }
    }

    private func reload() { resource?.load() }

    @ViewBuilder
    private func sheetView(_ which: OrderSheet, order: Record) -> some View {
        let close: () -> Void = { sheet = nil }
        let done: () -> Void = { sheet = nil; reload() }
        switch which {
        case .status: OrderStatusSheet(order: order, onDone: done, onCancel: close)
        case .payment: RecordPaymentSheet(order: order, onDone: done, onCancel: close)
        case .refund: RefundSheet(order: order, onDone: done, onCancel: close)
        case .void: VoidOrderSheet(order: order, onDone: done, onCancel: close)
        case .deposit: DepositSheet(order: order, onDone: done, onCancel: close)
        case .shippingFee: ShippingFeeSheet(order: order, onDone: done, onCancel: close)
        case .currency: CurrencySheet(order: order, onDone: done, onCancel: close)
        case .attachCustomer: AttachCustomerSheet(order: order, onDone: done, onCancel: close)
        case .note: AddNoteSheet(order: order, onDone: done, onCancel: close)
        case .createShipment: CreateShipmentSheet(order: order, onDone: done, onCancel: close)
        case .production(let item): RaiseProductionSheet(order: order, item: item, onDone: done, onCancel: close)
        case .verifyMpesa(let p): VerifyMpesaSheet(order: order, payment: p, onDone: done, onCancel: close)
        case .verifyPaystack(let p): VerifyPaystackSheet(order: order, payment: p, onDone: done, onCancel: close)
        case .reverse(let p): ReversePaymentSheet(order: order, payment: p, onDone: done, onCancel: close)
        case .revise(let p): RevisePaymentSheet(order: order, payment: p, onDone: done, onCancel: close)
        }
    }

    // MARK: Header

    private func header(_ o: Record) -> some View {
        SectionCard {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 6) {
                    if let name = o.salesCustomerName {
                        Text(name).font(Theme.Font.pageTitle)
                        HStack(spacing: 4) {
                            Text(o.string("order_number") ?? "").font(Theme.Font.mono).foregroundStyle(Theme.textSecondary)
                            CopyButton("", text: o.string("order_number") ?? "", confirmation: "Order number copied").labelStyle(.iconOnly).buttonStyle(.plain)
                        }
                    } else {
                        Text(o.string("order_number") ?? "").font(Theme.Font.display(24))
                    }
                    HStack(spacing: 6) {
                        StatusBadge(o.status)
                        StatusBadge(o.string("payment_status"))
                        StatusBadge((o.string("sales_bucket") ?? o.string("order_type") ?? "").humanized, tone: .neutral)
                        if let src = o.string("source_channel") { StatusBadge(src, tone: .info) }
                        if o["dispatched_at"]?.isNull == false { StatusBadge("dispatched", tone: .success) }
                    }
                    HStack(spacing: Theme.Space.m) {
                        if let outlet = o.string("outlet_name") { Label(outlet, systemImage: "storefront") }
                        if let who = o.string("cashier_name") { Label("Served by \(who)", systemImage: "person") }
                        DateText(o.date("created_at"))
                    }
                    .font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                }
                Spacer()
                VStack(alignment: .trailing, spacing: 4) {
                    MoneyText(o.money("total_amount"), emphasis: true)
                    Text(o.string("currency_code") ?? "").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                }
            }
            if let chat = Sales.chatURL(phone: o.string("customer_phone"), orderNumber: o.string("order_number")) {
                Link(destination: chat) { Label("Open chat in Neema", systemImage: "bubble.left") }.font(Theme.Font.secondary)
            }
        }
    }

    // MARK: Actions

    private func actions(_ o: Record) -> some View {
        let s = env.session
        let status = o.status ?? ""
        let payStatus = o.string("payment_status") ?? ""
        let dead = Sales.isDead(status)
        let captured = !(o.salesCustomerName ?? "").isEmpty || o["user_id"]?.isNull == false || !(o.string("customer_phone") ?? "").isEmpty
        return SectionCard {
            if dead { Text("Payments are closed — this order is \(status). A payment can't be taken or recorded against it.").font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
            if o["payments"]?.array?.contains(where: { $0["requires_approval"]?.bool == true && $0["approval_status"]?.string == "pending_review" }) == true {
                Text("A payment is awaiting approval; the order cannot progress until it is signed.").font(Theme.Font.secondary).foregroundStyle(Theme.Palette.amber)
            }
            FlowRow {
                if s.can("orders.edit"), !Sales.nextStatuses(from: status).isEmpty {
                    Button { sheet = .status } label: { Label("Update status", systemImage: "arrow.right.circle") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                }
                if s.can("payments.record"), !dead, payStatus != "paid" {
                    Button { sheet = .payment } label: { Label("Record payment", systemImage: "plus.circle") }.buttonStyle(.borderedProminent).tint(Theme.Palette.success)
                }
                if !dead, payStatus != "paid" {
                    Button { Task { await fetchPaymentLink() } } label: { Label("Payment link", systemImage: "link") }
                }
                if s.can("orders.set_deposit"), !dead, payStatus != "paid" {
                    Button { sheet = .deposit } label: { Label("Set deposit", systemImage: "banknote") }
                }
                if s.can("orders.set_shipping_fee"), !dead {
                    Button { sheet = .shippingFee } label: { Label("Shipping fee", systemImage: "box.truck") }
                }
                if s.can("orders.edit"), !dead, payStatus == "pending" {
                    Button { sheet = .currency } label: { Label("Country & currency", systemImage: "globe") }
                }
                if s.permissions.canAny(["orders.edit", "orders.create"]), !captured {
                    Button { sheet = .attachCustomer } label: { Label("Add customer details", systemImage: "person.badge.plus") }
                }
                DocumentButton("Order PDF", path: "/v1/admin/pdf/orders/\(orderId)", suggestedName: "order-\(orderId).pdf")
                DocumentButton("Invoice PDF", path: "/v1/admin/pdf/orders/\(orderId)/invoice", suggestedName: "invoice-\(orderId).pdf")
                if ["paid", "partial", "deposit", "partially_refunded", "refunded"].contains(payStatus) {
                    DocumentButton("Receipt PDF", path: "/v1/admin/pdf/orders/\(orderId)/receipt", suggestedName: "receipt-\(orderId).pdf")
                }
                Menu {
                    if s.can("orders.edit"), o.string("order_type") != "pos" {
                        Button { Task { await resendConfirmation() } } label: { Label("Resend confirmation", systemImage: "envelope") }
                    }
                    if s.can("orders.edit") { Button { sheet = .note } label: { Label("Add note", systemImage: "note.text") } }
                    Button { showRevisions.toggle() } label: { Label(showRevisions ? "Hide revisions" : "Document revisions", systemImage: "clock.arrow.circlepath") }
                    Button { showAudit.toggle() } label: { Label(showAudit ? "Hide audit log" : "Audit log", systemImage: "doc.text.magnifyingglass") }
                    if s.can("orders.refund"), ["delivered", "completed"].contains(status) {
                        Divider()
                        Button { sheet = .refund } label: { Label("Refund…", systemImage: "arrow.uturn.backward") }
                    }
                    if s.can("orders.cancel"), ["pending", "processing", "confirmed"].contains(status) {
                        Button(role: .destructive) { sheet = .void } label: { Label("Void order…", systemImage: "xmark.octagon") }
                    }
                } label: { Label("More", systemImage: "ellipsis.circle") }
            }
        }
    }

    private func paymentLinkBanner(_ link: String) -> some View {
        HStack {
            VStack(alignment: .leading, spacing: 2) {
                Text("Customer payment link").font(Theme.Font.caption).foregroundStyle(Theme.Palette.info)
                Text(link).font(Theme.Font.mono).lineLimit(1).textSelection(.enabled)
            }
            Spacer()
            CopyButton("Copy", text: link, confirmation: "Payment link copied")
            if let url = URL(string: link) { Link("Open", destination: url) }
        }
        .padding(Theme.Space.m)
        .background(Theme.Palette.infoSoft, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
    }

    private func fetchPaymentLink() async {
        do {
            let r = try await env.session.client.get("/v1/admin/orders/\(orderId)/payment-link")
            if let url = r.json?["payment_url"]?.string ?? r.json?["url"]?.string {
                paymentLink = url
                Clipboard.copy(url)
                env.toasts.show("Payment link copied")
            }
        } catch { env.toasts.error(error) }
    }

    private func resendConfirmation() async {
        do {
            let r = try await env.session.client.post("/v1/admin/orders/\(orderId)/resend-confirmation")
            env.toasts.show(r.json?["message"]?.string ?? "Confirmation sent")
        } catch { env.toasts.error(error) }
    }

    // MARK: Customer

    private func customer(_ o: Record) -> some View {
        let ctx: (type: String, id: Int) = (type: "order", id: orderId)
        return SectionCard("Customer") {
            if let name = o.salesCustomerName { DetailRow("Name", name) } else { Text("Walk-in — no customer attached").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary) }
            if let n = o.string("customer_number") { DetailRow("Customer no.", n) }
            ContactLine(label: "Phone", value: o.string("customer_phone"), field: "phone", customerId: o.int("customer_id"), context: ctx)
            ContactLine(label: "Email", value: o.string("customer_email"), field: "email", customerId: o.int("customer_id"), context: ctx)
            if let a = o["shipping_address"], a.object != nil {
                let line = ["name", "address_line_1", "address_line_2", "city", "state", "postal_code", "country"].compactMap { a[$0]?.string }.filter { !$0.isEmpty }.joined(separator: ", ")
                ContactLine(label: "Ships to", value: line, field: "address", customerId: o.int("customer_id"), context: ctx)
            }
            if let d = o.string("delivery_type") { DetailRow("Delivery", d.humanized) }
            if let t = o.string("tracking_number") { DetailRow("Tracking no.", t) }
            if let cid = o.int("customer_id"), env.session.can("customers.view") {
                Button("Open customer") { env.navigator.push(.customer(id: cid)) }.font(Theme.Font.secondary)
            }
        }
    }

    // MARK: Payments

    private func payments(_ o: Record) -> some View {
        let list = (o["payments"]?.array ?? []).map(Record.init(json:))
        let s = env.session
        let canReverse = s.permissions.canAny(["payments.void", "payments.request_void"])
        return SectionCard("Payments") {
            if list.isEmpty {
                Text("No payment recorded yet.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            }
            ForEach(list) { p in paymentRow(p, order: o, canReverse: canReverse) }
            if canReverse, list.contains(where: { $0.status == "paid" }) {
                Button("Reverse every settled payment…", role: .destructive) { sheet = .reverse(nil) }.font(Theme.Font.secondary)
            }
        }
    }

    private func paymentRow(_ p: Record, order o: Record, canReverse: Bool) -> some View {
        let s = env.session
        let approved = p["requires_approval"]?.bool != true || p.string("approval_status") == "approved"
        let method = p.string("payment_method") ?? ""
        let verification = p["provider_response"]?["mpesa_verification"]
        let reference = p.string("provider_reference").map { " · " + $0 } ?? ""
        return VStack(alignment: .leading, spacing: 4) {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 2) {
                    HStack(spacing: 6) {
                        Text(p.string("payment_number") ?? "#\(p.id)").font(Theme.Font.mono)
                        StatusBadge(p["requires_approval"]?.bool == true && !approved ? "pending approval" : p.status)
                        if p["requires_approval"]?.bool == true, let a = p.string("approval_status") { StatusBadge(a) }
                        if p["received_after_cancel_at"]?.isNull == false { StatusBadge("paid after cancel", tone: .warning) }
                    }
                    Text(method.humanized.uppercased() + reference).font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                    HStack(spacing: 6) {
                        DateText(p.date("paid_at") ?? p.date("created_at"))
                        if p["tax_inclusive"]?.bool == false, let t = p.money("tax_amount_collected") { Text("tax collected \(t.formatted())").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                    }
                    if let v = verification {
                        if v["state"]?.string == "pending" || v["state"]?.string == "querying" {
                            Text("Waiting for Safaricom… (\(v["receipt"]?.string ?? ""))").font(Theme.Font.caption).foregroundStyle(Theme.Palette.amber)
                        } else if let m = v["message"]?.string, ["refused", "unmatched", "timed_out"].contains(v["state"]?.string ?? "") {
                            Text(m).font(Theme.Font.caption).foregroundStyle(Theme.Palette.danger)
                        }
                    }
                }
                Spacer()
                VStack(alignment: .trailing, spacing: 2) {
                    MoneyText(p.money("amount", currency: p.string("currency_code") ?? o.string("currency_code")))
                    if let r = p.money("refund_amount", currency: p.string("currency_code") ?? o.string("currency_code")), !r.isZero {
                        Text("−\(r.formatted()) refunded").font(Theme.Font.caption).foregroundStyle(Theme.Palette.danger)
                    }
                }
            }
            FlowRow {
                if s.can("payments.upload_proof"), p["requires_approval"]?.bool == true, p.status != "refunded", p.string("approval_status") != "approved" {
                    ProofUploadButton(paymentId: p.id, onDone: reload)
                }
                if s.can("payments.record"), p.status == "pending", method == "mpesa" {
                    Button("Verify with Daraja") { sheet = .verifyMpesa(p) }
                }
                if s.can("payments.record"), p.status == "pending", ["card_paystack", "paystack"].contains(method) {
                    Button("Verify with Paystack") { sheet = .verifyPaystack(p) }
                }
                if canReverse, p.status == "paid", approved {
                    Button("Reverse…", role: .destructive) { sheet = .reverse(p) }
                }
                if s.permissions.isSuperAdmin, !["voided", "failed", "cancelled"].contains(p.status ?? "") {
                    Button("Correct details…") { sheet = .revise(p) }
                }
            }
            .font(Theme.Font.secondary)
            Divider()
        }
    }

    // MARK: Notes, history, production

    private func notes(_ o: Record) -> some View {
        let list = (o["order_notes"]?.array ?? []).map(Record.init(json:))
        return SectionCard("Notes") {
            if list.isEmpty { Text("No notes.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary) }
            ForEach(list) { n in
                VStack(alignment: .leading, spacing: 2) {
                    HStack {
                        Text(n.string("user_name") ?? "Staff").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        StatusBadge(n["is_internal"]?.bool == true ? "internal" : "customer", tone: n["is_internal"]?.bool == true ? .neutral : .info)
                        Spacer()
                        DateText(n.date("created_at"), style: .relative)
                    }
                    Text(n.string("note") ?? "").font(Theme.Font.body).textSelection(.enabled)
                }
                .padding(.vertical, 4)
            }
            if env.session.can("orders.edit") { Button("Add note") { sheet = .note }.font(Theme.Font.secondary) }
        }
    }

    private func history(_ o: Record) -> some View {
        let list = (o["status_history"]?.array ?? []).map(Record.init(json:))
        return Group {
            if !list.isEmpty {
                SectionCard("Status history") {
                    ForEach(list) { h in
                        HStack {
                            StatusBadge(h.string("old_status"))
                            Image(systemName: "arrow.right").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                            StatusBadge(h.string("new_status"))
                            Text(h.string("changed_by_name") ?? "System").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                            if let n = h.string("notes"), !n.isEmpty { Text(n).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary).lineLimit(1) }
                            Spacer()
                            DateText(h.date("created_at"))
                        }
                    }
                }
            }
        }
    }

    private func productionOrders(_ o: Record) -> some View {
        let list = (o["production_orders"]?.array ?? []).map(Record.init(json:))
        return Group {
            if !list.isEmpty {
                SectionCard("Production") {
                    ForEach(list) { po in
                        HStack {
                            VStack(alignment: .leading, spacing: 2) {
                                Text("\(po.string("order_number") ?? "") · \(po.string("product_name") ?? "") × \(po.string("quantity") ?? "")").font(Theme.Font.body)
                                HStack(spacing: 6) {
                                    StatusBadge(po.status)
                                    if let pr = po.string("priority") { StatusBadge(pr, tone: .neutral) }
                                    if let d = po.string("due_date") { Text("due \(d)").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                                }
                            }
                            Spacer()
                            if let id = Int(po.id), env.session.can("production.view") { Button("Open") { env.navigator.push(.productionOrder(id: id)) } }
                        }
                    }
                    if list.contains(where: { $0.status == "draft" }) {
                        Text("Draft production orders must be confirmed in the Production module before work begins.").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning)
                    }
                }
            }
        }
    }
}

/// Buttons that wrap onto new lines when the width runs out.
struct FlowRow<Content: View>: View {
    @ViewBuilder let content: () -> Content
    var body: some View {
        FlowLayout(spacing: Theme.Space.s) { content() }
    }
}

struct FlowLayout: Layout {
    var spacing: CGFloat = 8
    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let width = proposal.width ?? .infinity
        var x: CGFloat = 0, y: CGFloat = 0, rowHeight: CGFloat = 0
        for s in subviews {
            let size = s.sizeThatFits(.unspecified)
            if x > 0, x + size.width > width { x = 0; y += rowHeight + spacing; rowHeight = 0 }
            x += size.width + spacing
            rowHeight = max(rowHeight, size.height)
        }
        return CGSize(width: width == .infinity ? x : width, height: y + rowHeight)
    }
    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        var x = bounds.minX, y = bounds.minY, rowHeight: CGFloat = 0
        for s in subviews {
            let size = s.sizeThatFits(.unspecified)
            if x > bounds.minX, x + size.width > bounds.maxX { x = bounds.minX; y += rowHeight + spacing; rowHeight = 0 }
            s.place(at: CGPoint(x: x, y: y), proposal: ProposedViewSize(size))
            x += size.width + spacing
            rowHeight = max(rowHeight, size.height)
        }
    }
}
