import SwiftUI
import BethanyKit

/// Finance: the payment transaction ledger (payments.transactions).
/// Endpoints verified against PaymentController (payment-transactions prefix)
/// and react-admin/src/api/transactions.ts.
@MainActor
enum FinanceFeature {
    static func register() {
        FeatureRegistry.register(.paymentTransactions) { _ in AnyView(PaymentTransactionsView()) }
    }
}

enum PaymentVocab {
    static let methods: [(String, String)] = [("mpesa", "M-Pesa"), ("card_paystack", "Card (Paystack)"), ("card_flutterwave", "Card (Flutterwave)"), ("cash", "Cash"), ("bank_transfer", "Bank Transfer"), ("cheque", "Cheque"), ("manual", "Manual")]
    static let statuses = ["paid", "pending", "failed", "refunded", "partially_refunded", "voided"]
    static func methodLabel(_ v: String?) -> String { methods.first { $0.0 == v }?.1 ?? (v ?? "—").humanized }
}

struct PaymentRow: Identifiable, Decodable, Hashable, Sendable {
    let record: Record
    var id: String { record.id }
    init(from decoder: Decoder) throws { record = try Record(from: decoder) }
    init(json: JSONValue) { record = Record(json: json) }
    var number: String { record.string("payment_number") ?? "#\(id)" }
    var status: String { record.status ?? "pending" }
    var method: String? { record.string("payment_method") }
    var currency: String { record.string("currency_code") ?? "KES" }
    var amount: Money? { record.money("amount", currency: currency) }
    var refund: Money? { record["refund_amount"].flatMap { $0.isNull ? nil : Money(json: $0, currency: currency) } }
    var order: JSONValue? { record["order"].flatMap { $0.isNull ? nil : $0 } }
    var customer: String? { order.flatMap { [$0["customer_first_name"]?.string, $0["customer_last_name"]?.string].compactMap { $0 }.filter { !$0.isEmpty }.joined(separator: " ") }.flatMap { $0.isEmpty ? nil : $0 } }
    var requiresApproval: Bool { record["requires_approval"]?.bool ?? false }
    var approvalStatus: String? { record.string("approval_status") }
    var createdAt: Date? { record.date("created_at") }
    var paidAt: Date? { record.date("paid_at") }
}

struct PaymentTransactionsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var list: PagedList<PaymentRow>?
    @State private var analytics: Resource<JSONValue>?
    @State private var search = ""
    @State private var status = ""
    @State private var method = ""
    @State private var currency = ""
    @State private var minAmount = ""
    @State private var maxAmount = ""
    @State private var approvalOnly = false
    @State private var preset: DatePreset = .this_month
    @State private var customStart = Date()
    @State private var customEnd = Date()
    @State private var selected: PaymentRow?

    private var window: ReportWindow { ReportWindow(preset: preset, customStart: customStart, customEnd: customEnd, outletId: nil) }

    private var query: [String: String?] {
        [
            "search": search.isEmpty ? nil : search, "status": status.isEmpty ? nil : status, "payment_method": method.isEmpty ? nil : method,
            "currency_code": currency.isEmpty ? nil : currency, "min_amount": minAmount.isEmpty ? nil : minAmount, "max_amount": maxAmount.isEmpty ? nil : maxAmount,
            "requires_approval": approvalOnly ? "1" : nil, "start_date": window.startString, "end_date": window.endString,
        ]
    }

    var body: some View {
        Page("Payment Transactions", subtitle: "\(window.description) · money truth: payments as recorded") {
            filters
            if let analytics { analyticsHeader(analytics) }
            if let list {
                HStack {
                    Text("\(list.total) transaction\(list.total == 1 ? "" : "s")").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                    Spacer()
                    DocumentButton("Export CSV", path: "/v1/admin/payment-transactions/export", query: query, suggestedName: "transactions-\(window.startString)-\(window.endString).csv")
                        .font(Theme.Font.secondary)
                }
                LoadableView(list.state, retry: { Task { await list.load() } }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No transactions match", symbol: "list.bullet.rectangle.portrait") }) { rows in
                    LazyVStack(spacing: Theme.Space.s) {
                        ForEach(rows) { p in
                            Button { selected = p } label: { paymentRow(p) }.buttonStyle(.plain)
                                .onAppear { if p.id == rows.last?.id { Task { await list.loadMore() } } }
                        }
                    }
                }
            }
        }
        .task {
            if list == nil {
                list = PagedList(client: env.session.client, path: "/v1/admin/payment-transactions", query: query)
                await list?.load()
                loadAnalytics()
            }
        }
        .onChange(of: queryKey) { _, _ in list?.query = query; Task { await list?.load() }; loadAnalytics() }
        .refreshable { await list?.load(); loadAnalytics() }
        .sheet(item: $selected) { p in PaymentDetailSheet(id: p.id) { selected = nil; Task { await list?.load() } } }
    }

    private var queryKey: String { query.sorted { $0.key < $1.key }.map { "\($0.key)=\($0.value ?? "")" }.joined(separator: "&") }

    private func loadAnalytics() {
        let c = env.session.client, q: [String: String?] = ["start_date": window.startString, "end_date": window.endString, "currency_code": currency.isEmpty ? nil : currency]
        analytics = Resource { try await c.get("/v1/admin/payment-transactions/analytics", query: q).json ?? .null }
        analytics?.load()
    }

    private var filters: some View {
        VStack(alignment: .leading, spacing: Theme.Space.s) {
            HStack(spacing: Theme.Space.s) {
                TextField("Search reference, order, customer", text: $search).textFieldStyle(.roundedBorder).frame(maxWidth: 280)
                Picker("Period", selection: $preset) { ForEach(DatePreset.allCases) { Text($0.label).tag($0) } }.labelsHidden().frame(maxWidth: 180)
                Picker("Status", selection: $status) { Text("All statuses").tag(""); ForEach(PaymentVocab.statuses, id: \.self) { Text($0.humanized).tag($0) } }.labelsHidden()
                Picker("Method", selection: $method) { Text("All methods").tag(""); ForEach(PaymentVocab.methods, id: \.0) { Text($0.1).tag($0.0) } }.labelsHidden()
            }
            if preset == .custom {
                HStack { DatePicker("From", selection: $customStart, displayedComponents: .date); DatePicker("To", selection: $customEnd, displayedComponents: .date) }.environment(\.timeZone, HubDates.nairobi)
            }
            HStack(spacing: Theme.Space.s) {
                TextField("Currency (e.g. USD)", text: $currency).textFieldStyle(.roundedBorder).frame(maxWidth: 140)
                DecimalField("Min amount", text: $minAmount).frame(maxWidth: 120)
                DecimalField("Max amount", text: $maxAmount).frame(maxWidth: 120)
                Toggle("Needs approval", isOn: $approvalOnly).toggleStyle(.switch)
                Spacer()
            }
        }
        .font(Theme.Font.secondary)
    }

    @ViewBuilder
    private func analyticsHeader(_ res: Resource<JSONValue>) -> some View {
        let cur = currency.isEmpty ? "KES" : currency.uppercased()
        LoadableView(res.state, retry: { res.load() }) { a in
            TileGrid(minWidth: 170) {
                StatTile("Paid volume", value: fmtMoney(a["paid_volume"], currency: cur), hint: "\(a["paid_count"]?.int ?? 0) paid")
                StatTile("All recorded", value: fmtMoney(a["total_volume"], currency: cur), hint: "\(a["total_count"]?.int ?? 0) transactions")
                StatTile("Refunded", value: fmtMoney(a["refunded_volume"], currency: cur), hint: "\(a["refunded_count"]?.int ?? 0) transactions")
                StatTile("Average", value: fmtMoney(a["avg_transaction"], currency: cur))
                StatTile("Success rate", value: fmtPct(a["success_rate"]?.double), hint: "\(a["failed_count"]?.int ?? 0) failed · \(a["pending_count"]?.int ?? 0) pending", tone: (a["failed_count"]?.int ?? 0) > 0 ? .warning : nil)
            }
            BasisCaption(currency.isEmpty ? "Basis: KES at the reporting rate; payments in a currency without a reporting rate are not in these totals. Filter by currency to see it natively." : "Basis: \(cur) natively, this currency only.")
            if let daily = a["daily"]?.array, daily.count > 1 {
                SectionCard("Daily paid volume") { LinesChart(points: chartPoints(a["daily"], label: "date", y: "volume", series: "Paid"), currency: cur) }
            }
            if let by = a["by_method"]?.array, !by.isEmpty {
                SectionCard("By method") {
                    DataTable(columns: [TableColumn("Method", "payment_method", .status), TableColumn("Count", "count", .int), TableColumn("Paid volume", "volume", .money, fixedCurrency: cur)], rows: jrows(a["by_method"]))
                }
            }
        }
    }

    private func paymentRow(_ p: PaymentRow) -> some View {
        HStack(alignment: .top) {
            VStack(alignment: .leading, spacing: 3) {
                HStack { Text(p.number).font(Theme.Font.mono); StatusBadge(p.status); if p.requiresApproval && p.approvalStatus == "pending_review" { StatusBadge("awaiting approval", tone: .warning) } }
                Text([p.order?["order_number"]?.string, p.customer].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.body)
                Text(PaymentVocab.methodLabel(p.method)).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                DateText(p.paidAt ?? p.createdAt)
            }
            Spacer()
            VStack(alignment: .trailing, spacing: 2) {
                MoneyText(p.amount, emphasis: true)
                if let r = p.refund, !r.isZero { Text("−\(r.formatted()) refunded").font(Theme.Font.caption).foregroundStyle(Theme.Palette.danger) }
            }
        }
        .padding(Theme.Space.m)
        .background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
        .contentShape(Rectangle())
    }
}

/// GET payment-transactions/{id} → `{payment}` with order and approvedBy;
/// refund (orders.refund), void request, reassign request (Phase 3B: requests
/// that finance executes once the approval engine has its signatures).
struct PaymentDetailSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let id: String
    let changed: () -> Void
    @State private var resource: Resource<JSONValue>?
    @State private var mode: Mode?
    @State private var reason = ""
    @State private var amount = ""
    @State private var orderQuery = ""
    @State private var orderResults: [JRow] = []
    @State private var orderId: Int?
    @State private var orderLabel = ""
    @State private var busy = false

    enum Mode: String, Identifiable { case void_, reassign, refund; var id: String { rawValue } }

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.l) {
            HStack { Text("Payment").font(Theme.Font.sectionTitle); Spacer(); Button("Close") { dismiss() } }
            if let resource {
                LoadableView(resource.state, retry: { resource.load() }) { json in
                    let p = PaymentRow(json: json["payment"] ?? json)
                    ScrollView { detail(p) }
                }
            }
        }
        .padding(Theme.Space.xl).frame(minWidth: 460, idealWidth: 600, minHeight: 420, idealHeight: 640)
        .task {
            let c = env.session.client, id = id
            resource = Resource { try await c.get("/v1/admin/payment-transactions/\(id)").json ?? .null }
            resource?.load()
        }
        .onChange(of: orderQuery) { _, q in Task { await searchOrders(q) } }
    }

    @ViewBuilder
    private func detail(_ p: PaymentRow) -> some View {
        let s = env.session
        let canVoid = s.can("payments.void") || s.can("payments.request_void")
        let canReassign = s.can("payments.reassign") || s.can("payments.request_reassign")
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            HStack { Text(p.number).font(Theme.Font.mono); StatusBadge(p.status); Spacer(); MoneyText(p.amount, emphasis: true) }
            DetailRow("Method", PaymentVocab.methodLabel(p.method))
            DetailRow("Provider", [p.record.string("provider"), p.record.string("provider_transaction_id") ?? p.record.string("provider_reference")].compactMap { $0 }.joined(separator: " · "))
            DetailRow("Customer", p.customer)
            if let o = p.order, let oid = o["id"]?.int {
                DetailRow("Order") { Button(o["order_number"]?.string ?? "#\(oid)") { dismiss(); env.navigator.go(.order(id: oid)) }.font(Theme.Font.secondary) }
                if let oc = o["currency_code"]?.string, oc != p.currency { Text("Order currency \(oc) differs from the payment currency \(p.currency).").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning) }
            }
            DetailRow("Recorded") { DateText(p.createdAt) }
            if let d = p.paidAt { DetailRow("Paid at") { DateText(d) } }
            if let r = p.refund, !r.isZero { DetailRow("Refunded") { HStack { MoneyText(r); DateText(p.record.date("refunded_at")) } } }
            if p.requiresApproval { DetailRow("Approval") { StatusBadge(p.approvalStatus) } }
            if let a = p.record["approvedBy"], !a.isNull { DetailRow("Approved by", personName(a)) }
            if p.status == "voided" {
                DetailRow("Void reason", p.record.string("void_reason"))
                DetailRow("Voided") { DateText(p.record.date("voided_at")) }
            }
            if let mode { form(mode, p) } else {
                HStack(spacing: Theme.Space.s) {
                    if p.status == "paid" && s.can("orders.refund") { Button("Refund…") { mode = .refund } }
                    if p.status != "voided" && canVoid { Button("Request void…") { mode = .void_ } }
                    if p.status != "voided" && canReassign { Button("Request reassign…") { mode = .reassign } }
                    Spacer()
                }
                .font(Theme.Font.secondary)
            }
        }
    }

    @ViewBuilder
    private func form(_ mode: Mode, _ p: PaymentRow) -> some View {
        VStack(alignment: .leading, spacing: Theme.Space.s) {
            switch mode {
            case .refund:
                Text("Refund part or all of \(p.amount?.formatted() ?? "this payment"). The server refuses more than is still refundable.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                FieldBox("Amount (\(p.currency)) *") { DecimalField("", text: $amount) }
            case .void_:
                Text("A void is a request: nothing happens to the payment until finance approves it (above KES 50,000 the super admin too). It then shows in Approvals.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            case .reassign:
                Text("Moving a payment to another order is a request finance approves. A payment moves only between orders in the same currency (\(p.currency)).").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                FieldBox("Find the order *") { TextField("Order number or customer (3+ characters)", text: $orderQuery).textFieldStyle(.roundedBorder) }
                if orderId == nil {
                    ForEach(orderResults) { o in
                        Button { orderId = o.int("id"); orderLabel = "\(o.str("order_number") ?? "") · \([o.str("customer_first_name"), o.str("customer_last_name")].compactMap { $0 }.joined(separator: " ")) · \((o.str("status") ?? "").humanized)"; orderResults = [] } label: {
                            HStack { Text(o.str("order_number") ?? "").font(Theme.Font.mono); Text([o.str("customer_first_name"), o.str("customer_last_name")].compactMap { $0 }.joined(separator: " ")).font(Theme.Font.caption); Spacer(); Text(o.str("currency_code") ?? "").font(Theme.Font.caption) }
                        }.buttonStyle(.plain)
                    }
                } else {
                    HStack { Text(orderLabel).font(Theme.Font.secondary); Button("Change") { orderId = nil } }
                }
            }
            FieldBox("Reason *") { TextField("", text: $reason, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
            HStack {
                Spacer()
                Button("Cancel") { self.mode = nil }
                Button { Task { await submit(mode, p) } } label: { if busy { ProgressView().controlSize(.small) } else { Text(mode == .refund ? "Record refund" : "Send for approval") } }
                    .buttonStyle(.borderedProminent).tint(mode == .refund ? Theme.Palette.danger : Theme.Palette.brand)
                    .disabled(busy || reason.trimmingCharacters(in: .whitespaces).isEmpty || (mode == .refund && decimal(amount) == nil) || (mode == .reassign && orderId == nil))
            }
        }
        .padding(Theme.Space.m).background(Theme.field, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
    }

    private func searchOrders(_ q: String) async {
        guard q.count >= 3 else { orderResults = []; return }
        if let r = try? await env.session.client.get("/v1/admin/orders", query: ["search": q, "per_page": "8"], background: true) { orderResults = jrows(r.json?["data"]) }
    }

    private func submit(_ mode: Mode, _ p: PaymentRow) async {
        busy = true; defer { busy = false }
        var body: [String: JSONValue] = ["reason": .string(reason.trimmingCharacters(in: .whitespaces))]
        let path: String
        switch mode {
        case .refund: path = "refund"; body.put("amount", jnum(amount))
        case .void_: path = "void-request"
        case .reassign: path = "reassign-request"; body.put("order_id", orderId.map { .number(Double($0)) })
        }
        do {
            let r = try await env.session.client.post("/v1/admin/payment-transactions/\(id)/\(path)", json: .object(body))
            env.toasts.show(r.json?["message"]?.string ?? "Done")
            self.mode = nil; reason = ""; amount = ""
            resource?.load(); changed()
        } catch { env.toasts.error(error) }
    }
}
