import SwiftUI
import BethanyKit

// MARK: - Orders list (GET /admin/orders — OrderController::index / screenQuery)

/// Till, Web, Chat and Quoted Sales and the all-orders view. Filters are the
/// ones `screenQuery` reads: search, status, sales_channel, channel,
/// outlet_id, start_date, end_date, sort_by (SORTABLE_COLUMNS), sort_order.
struct OrdersListView: View {
    @Environment(AppEnvironment.self) private var env
    let channel: Route.OrderChannel?

    @State private var list: PagedList<Record>?
    @State private var search = ""
    @State private var status = ""
    @State private var orderType = ""        // all-orders view only: order_type online|pos
    @State private var from: Date?
    @State private var to: Date?
    @State private var sortBy = "created_at"
    @State private var sortDesc = true
    @State private var cancelling: Record?
    @State private var selection: Record.ID?

    static let statuses = ["pending", "processing", "confirmed", "shipped", "delivered", "completed", "cancelled", "refunded"]
    /// OrderController::SORTABLE_COLUMNS.
    static let sorts: [(String, String)] = [("created_at", "Date"), ("updated_at", "Updated"), ("order_number", "Order number"), ("total_amount", "Total"), ("status", "Status"), ("payment_status", "Payment"), ("order_type", "Type")]

    private var title: String {
        switch channel {
        case .till: return "Till Sales"
        case .web: return "Web Orders"
        case .chat: return "Chat Orders"
        case .quoted: return "Quoted Sales"
        case nil: return "Orders"
        }
    }

    var body: some View {
        Page(title, subtitle: list.map { "\($0.total) orders" }) {
            filters
            if let list {
                LoadableView(list.state, retry: { Task { await list.load() } }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No orders match", message: "Try another status, date range or search.", symbol: "bag") }) { rows in
                    #if os(macOS)
                    ordersTable(rows)
                    #else
                    LazyVStack(spacing: Theme.Space.s) { ForEach(rows) { orderCard($0) } }
                    #endif
                    LoadMoreRow(list: list)
                }
            }
        }
        .task { if list == nil { await reload() } }
        .onChange(of: channel) { _, _ in Task { await reload() } }
        .refreshable { await list?.load() }
        .sheet(item: $cancelling) { order in
            CancelOrderSheet(order: order) { cancelling = nil; Task { await list?.load() } } onCancel: { cancelling = nil }
        }
    }

    private var query: [String: String?] {
        [
            "search": search.isEmpty ? nil : search,
            "status": status.isEmpty ? nil : status,
            "sales_channel": channel?.rawValue,
            "channel": channel == nil && !orderType.isEmpty ? orderType : nil,
            "start_date": from.map(HubDates.dayString),
            "end_date": to.map(HubDates.dayString),
            "sort_by": sortBy,
            "sort_order": sortDesc ? "desc" : "asc",
        ]
    }

    private func reload() async {
        if list == nil { list = PagedList(client: env.session.client, path: "/v1/admin/orders") }
        list?.query = query
        await list?.load()
    }

    @ViewBuilder private var filters: some View {
        VStack(alignment: .leading, spacing: Theme.Space.s) {
            HStack(spacing: Theme.Space.s) {
                SalesSearchField(text: $search, placeholder: "Order no., customer, phone, email") { Task { await reload() } }
                Picker("Status", selection: $status) {
                    Text("All statuses").tag("")
                    ForEach(Self.statuses, id: \.self) { Text($0.humanized).tag($0) }
                }
                .frame(maxWidth: 180)
                if channel == nil {
                    Picker("Type", selection: $orderType) {
                        Text("All channels").tag("")
                        Text("Online").tag("online")
                        Text("POS").tag("pos")
                    }
                    .frame(maxWidth: 160)
                }
                Spacer()
                if env.session.can("orders.view") {
                    DocumentButton("Export CSV", path: "/v1/admin/orders/export", query: query, suggestedName: "orders.csv")
                }
            }
            HStack(spacing: Theme.Space.s) {
                SalesOptionalDateField(label: "From", date: $from)
                SalesOptionalDateField(label: "To", date: $to)
                SalesLabeledField(label: "Sort") {
                    HStack(spacing: 4) {
                        Picker("Sort", selection: $sortBy) { ForEach(Self.sorts, id: \.0) { Text($0.1).tag($0.0) } }.labelsHidden()
                        Button { sortDesc.toggle() } label: { Image(systemName: sortDesc ? "arrow.down" : "arrow.up") }
                    }
                }
                Spacer()
                Button("Apply") { Task { await reload() } }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
            }
        }
        .onChange(of: status) { _, _ in Task { await reload() } }
        .onChange(of: orderType) { _, _ in Task { await reload() } }
        .onChange(of: sortBy) { _, _ in Task { await reload() } }
        .onChange(of: sortDesc) { _, _ in Task { await reload() } }
    }

    #if os(macOS)
    private func ordersTable(_ rows: [Record]) -> some View {
        Table(rows, selection: $selection) {
            TableColumn("Order") { r in
                VStack(alignment: .leading, spacing: 1) {
                    Text(r.string("order_number") ?? "#\(r.id)").font(Theme.Font.mono)
                    if let q = r.string("quotation_number") { Text("from \(q)").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                }
            }.width(min: 130)
            TableColumn("Customer") { r in
                VStack(alignment: .leading, spacing: 1) {
                    Text(r.salesCustomerName ?? "Walk-in").lineLimit(1)
                    if let n = r.string("customer_number") { Text(n).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                }
            }
            TableColumn("Status") { r in StatusBadge(r.status) }.width(110)
            TableColumn("Payment") { r in PaymentSummaryCell(record: r) }.width(min: 120)
            TableColumn("Total") { r in MoneyText(r.money("total_amount")) }.width(min: 120)
            TableColumn("Channel") { r in Text((r.string("sales_bucket") ?? r.string("order_type") ?? "—").humanized).font(Theme.Font.secondary) }.width(80)
            TableColumn("Outlet") { r in Text(r["outlet"]?["name"]?.string ?? r.string("outlet_name") ?? "—").font(Theme.Font.secondary) }.width(min: 90)
            TableColumn("Date") { r in DateText(r.date("created_at")) }.width(140)
            TableColumn("") { r in OrderRowActions(order: r, onChanged: { Task { await list?.load() } }, onCancel: { cancelling = r }) }.width(150)
        }
        .frame(height: max(320, CGFloat(rows.count) * 34 + 40))
        .onChange(of: selection) { _, new in
            if let new, let id = Int(new) { env.navigator.push(.order(id: id)); selection = nil }
        }
    }
    #endif

    private func orderCard(_ r: Record) -> some View {
        SalesRowCard(route: Int(r.id).map { .order(id: $0) }) {
            VStack(alignment: .leading, spacing: 4) {
                HStack {
                    Text(r.string("order_number") ?? "#\(r.id)").font(Theme.Font.mono)
                    Spacer()
                    MoneyText(r.money("total_amount"))
                }
                HStack {
                    Text(r.salesCustomerName ?? "Walk-in").font(Theme.Font.body).lineLimit(1)
                    Spacer()
                    StatusBadge(r.status)
                    PaymentSummaryCell(record: r)
                }
                HStack {
                    Text("\((r.string("sales_bucket") ?? r.string("order_type") ?? "").humanized) · \(r["outlet"]?["name"]?.string ?? r.string("outlet_name") ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                    Spacer()
                    DateText(r.date("created_at"), style: .relative)
                }
                OrderRowActions(order: r, onChanged: { Task { await list?.load() } }, onCancel: { cancelling = r })
            }
        }
    }
}

/// `payment_summary` from the list row: one state, the methods that paid, when.
struct PaymentSummaryCell: View {
    let record: Record
    var body: some View {
        let summary = record["payment_summary"]
        let state = summary?["state"]?.string ?? record.string("payment_status")
        VStack(alignment: .leading, spacing: 1) {
            StatusBadge(state)
            if let methods = summary?["methods"]?.array, !methods.isEmpty {
                Text(methods.compactMap { $0["name"]?.string ?? $0["code"]?.string }.joined(separator: ", ")).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary).lineLimit(1)
            }
        }
    }
}

/// The list row's actions (OrdersListPage StageMenu + payment link): Confirm,
/// Mark processed, Mark completed, Cancel — only the moves
/// OrderStatusMachine allows from the row's status — and the payment link.
struct OrderRowActions: View {
    @Environment(AppEnvironment.self) private var env
    let order: Record
    let onChanged: () -> Void
    let onCancel: () -> Void
    @State private var busy = false

    static let stages: [(String, String)] = [("confirmed", "Confirm"), ("processing", "Mark processed"), ("completed", "Mark completed")]

    var body: some View {
        let allowed = Sales.nextStatuses(from: order.status)
        let stages = Self.stages.filter { allowed.contains($0.0) }
        let canCancel = allowed.contains("cancelled") && env.session.can("orders.cancel")
        let canLink = !Sales.isDead(order.status) && order.string("payment_status") != "paid"
        HStack(spacing: 4) {
            if env.session.can("orders.edit"), !stages.isEmpty || canCancel {
                Menu {
                    ForEach(stages, id: \.0) { stage in
                        Button(stage.1) { Task { await move(to: stage.0, label: stage.1) } }
                    }
                    if canCancel { Divider(); Button("Cancel order…", role: .destructive, action: onCancel) }
                } label: {
                    if busy { ProgressView().controlSize(.mini) } else { Label("Stage", systemImage: "arrow.right.circle") }
                }
                .fixedSize()
                .disabled(busy)
            }
            if canLink {
                Button { Task { await copyPaymentLink() } } label: { Image(systemName: "link") }
                    .buttonStyle(.plain).help("Generate & copy payment link")
            }
        }
    }

    private func move(to status: String, label: String) async {
        guard let id = Int(order.id) else { return }
        busy = true
        defer { busy = false }
        do {
            _ = try await env.session.client.put("/v1/admin/orders/\(id)/status", json: .object(["status": .string(status)]))
            env.toasts.show("\(order.string("order_number") ?? "Order") — \(label.lowercased())")
            onChanged()
        } catch { env.toasts.error(error) }
    }

    private func copyPaymentLink() async {
        guard let id = Int(order.id) else { return }
        do {
            let r = try await env.session.client.get("/v1/admin/orders/\(id)/payment-link")
            if let url = r.json?["payment_url"]?.string ?? r.json?["url"]?.string {
                Clipboard.copy(url)
                env.toasts.show("Payment link copied")
            }
        } catch { env.toasts.error(error) }
    }
}

/// PUT /orders/{id}/status {status: cancelled, notes} — orders.edit for the
/// route, orders.cancel to offer it (OrdersListPage).
struct CancelOrderSheet: View {
    @Environment(AppEnvironment.self) private var env
    let order: Record
    let onDone: () -> Void
    let onCancel: () -> Void
    @State private var notes = ""

    var body: some View {
        SheetFrame(title: "Cancel \(order.string("order_number") ?? "order")?", submitLabel: "Cancel order", destructive: true, onCancel: onCancel, onSubmit: {
            guard let id = Int(order.id) else { return }
            var body: [String: JSONValue] = ["status": .string("cancelled")]
            if !notes.trimmingCharacters(in: .whitespaces).isEmpty { body["notes"] = .string(notes) }
            _ = try await env.session.client.put("/v1/admin/orders/\(id)/status", json: .object(body))
            env.toasts.show("Order cancelled")
            onDone()
        }) {
            Text("Cancelling takes the order out of recognised sales. Settled money stays on the order until it is reversed or refunded.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            TextInput(label: "Note (optional)", text: $notes, multiline: true)
        }
    }
}

// MARK: - Pending queue (GET /admin/orders/pending-queue)

/// The unconfirmed-order worklist: `{summary, rows}` sorted by value × age
/// on the server. `kes_value` is null when the currency has no reporting
/// rate — listed, never summed; `excluded_currencies` names them.
struct PendingQueueView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var resource: Resource<JSONValue>?
    @State private var action: QueueAction?

    struct QueueAction: Identifiable {
        let row: Record
        let confirm: Bool
        var id: String { "\(row.id)-\(confirm)" }
    }

    var body: some View {
        Page("Pending Queue", subtitle: "Unconfirmed orders — confirm, record a payment on the order, or cancel") {
            if let resource {
                LoadableView(resource.state, retry: { resource.load() }) { json in
                    summary(json["summary"] ?? .null)
                    let rows = (json["rows"]?.array ?? []).map(Record.init(json:))
                    if rows.isEmpty {
                        EmptyState("Nothing is waiting", message: "Every order has been confirmed or cancelled.", symbol: "checkmark.circle")
                    } else {
                        LazyVStack(spacing: Theme.Space.s) { ForEach(rows) { queueRow($0) } }
                    }
                }
            }
        }
        .task {
            if resource == nil {
                let client = env.session.client
                resource = Resource { try await client.get("/v1/admin/orders/pending-queue").json ?? .null }
                resource?.load()
            }
        }
        .refreshable { await resource?.reload() }
        .sheet(item: $action) { a in
            QueueDecisionSheet(row: a.row, confirm: a.confirm) { action = nil; resource?.load() } onCancel: { action = nil }
        }
    }

    private func summary(_ s: JSONValue) -> some View {
        SectionCard {
            TileGrid(minWidth: 150) {
                StatTile("Waiting", value: s["total_count"]?.string ?? "0")
                StatTile("Value (KES at reporting rate)", value: s["total_kes"].flatMap { Money(json: $0, currency: "KES") }?.formatted() ?? "—")
                StatTile("Fresh (≤ 2 days)", value: s["fresh_count"]?.string ?? "0", tone: .success)
                StatTile("Aging", value: s["aging_count"]?.string ?? "0", tone: .amber)
                StatTile("Stale", value: s["stale_count"]?.string ?? "0", tone: .danger)
            }
            if let excluded = s["excluded_currencies"]?.array, !excluded.isEmpty {
                Text("Not in the KES figure (no reporting rate): " + excluded.map { e in
                    let code = e["code"]?.string ?? "?"
                    let amt = e["amount"].flatMap { Money(json: $0, currency: code) }?.formatted() ?? ""
                    return "\(e["n"]?.string ?? "0") × \(code) \(amt)"
                }.joined(separator: "; "))
                .font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning)
            }
        }
    }

    private func queueRow(_ r: Record) -> some View {
        let days = r.int("days_pending") ?? 0
        let tone: StatusTone = days <= 2 ? .success : days <= 7 ? .amber : .danger
        let id = Int(r.id)
        return SectionCard {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 3) {
                    HStack(spacing: 6) {
                        Button { if let id { env.navigator.push(.order(id: id)) } } label: { Text(r.string("order_number") ?? "#\(r.id)").font(Theme.Font.mono) }.buttonStyle(.plain)
                        StatusBadge("\(days) day\(days == 1 ? "" : "s")", tone: tone)
                        if r["possible_duplicate"]?.bool == true { StatusBadge("possible duplicate", tone: .danger).help("Another pending order shares this phone number — likely one sale quoted twice. Confirm one, cancel the twin.") }
                    }
                    Text(r.salesCustomerName ?? "Walk-in").font(Theme.Font.body)
                    Text([r.string("customer_phone"), r.string("customer_email")].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    HStack(spacing: 4) {
                        Text((r.string("source") ?? "").humanized).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                        DateText(r.date("created_at"))
                    }
                }
                Spacer()
                VStack(alignment: .trailing, spacing: 3) {
                    MoneyText(r.money("total_amount"), emphasis: true)
                    if let kes = r["kes_value"], !kes.isNull, r.string("currency_code") != "KES" {
                        Text("= \(Money(json: kes, currency: "KES")?.formatted() ?? "—")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    } else if r["kes_value"]?.isNull == true {
                        Text("no reporting rate").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning)
                    }
                }
            }
            HStack {
                if let chat = Sales.chatURL(phone: r.string("customer_phone"), orderNumber: r.string("order_number")) {
                    Link(destination: chat) { Label("Open chat in Neema", systemImage: "bubble.left") }
                }
                Spacer()
                if env.session.can("orders.edit") {
                    Button("Cancel…", role: .destructive) { action = QueueAction(row: r, confirm: false) }
                    Button("Confirm") { action = QueueAction(row: r, confirm: true) }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                }
            }
        }
    }
}

struct QueueDecisionSheet: View {
    @Environment(AppEnvironment.self) private var env
    let row: Record
    let confirm: Bool
    let onDone: () -> Void
    let onCancel: () -> Void
    @State private var notes = ""

    var body: some View {
        SheetFrame(title: "\(confirm ? "Confirm" : "Cancel") \(row.string("order_number") ?? "")?", submitLabel: confirm ? "Confirm order" : "Cancel order", destructive: !confirm, onCancel: onCancel, onSubmit: {
            guard let id = Int(row.id) else { return }
            var body: [String: JSONValue] = ["status": .string(confirm ? "confirmed" : "cancelled")]
            if !notes.trimmingCharacters(in: .whitespaces).isEmpty { body["notes"] = .string(notes) }
            _ = try await env.session.client.put("/v1/admin/orders/\(id)/status", json: .object(body))
            env.toasts.show(confirm ? "Order confirmed" : "Order cancelled")
            onDone()
        }) {
            Text(confirm ? "Confirming books this order as income from now on; the customer still owes the balance until it is paid." : "Cancelling removes the order from the queue and from recognised sales.")
                .font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            TextInput(label: "Note (optional)", text: $notes, multiline: true)
        }
    }
}
