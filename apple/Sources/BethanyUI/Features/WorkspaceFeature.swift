import SwiftUI
import BethanyKit

/// Workspace: Dashboard, Notifications, Approvals.
@MainActor
enum WorkspaceFeature {
    static func register() {
        FeatureRegistry.register(.dashboard) { _ in AnyView(DashboardView()) }
        FeatureRegistry.register(.notifications) { _ in AnyView(NotificationsView()) }
        FeatureRegistry.register(.approvals) { _ in AnyView(ApprovalsView()) }
    }
}

// MARK: - Dashboard (GET /admin/dashboard — stats, recent_activity, alerts)

struct DashboardView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var resource: Resource<JSONValue>?

    var body: some View {
        Page("Dashboard", subtitle: greeting) {
            if let resource {
                LoadableView(resource.state, retry: { resource.load() }) { json in
                    let stats = json["stats"] ?? .null
                    alerts(json["alerts"]?.array ?? [])
                    QuickActions()
                    SectionCard("Today") {
                        TileGrid(minWidth: 180) {
                            StatTile("Today's sales", value: stats["today_sales"]?.money?.formatted() ?? "—", hint: stats["today_sales_basis"]?.string)
                            StatTile("Orders today", value: stats["today_orders"]?.string ?? "0")
                            StatTile("Collected today", value: stats["today_collected"]?.money?.formatted() ?? (stats["cash_today"]?["kes_total"]?.money?.formatted() ?? "—"))
                            StatTile("Pending orders", value: stats["pending_orders"]?.string ?? "0", tone: (stats["pending_orders"]?.int ?? 0) > 0 ? .amber : nil)
                        }
                        if let mixed = MoneyByCurrency(json: stats["today_sales_by_currency"] ?? stats["cash_today"]), !mixed.byCurrency.isEmpty {
                            MixedCurrencyLines(mixed)
                        }
                    }
                    if stats["production_queue"] != nil || stats["production_in_progress"] != nil {
                        SectionCard("Production") {
                            TileGrid(minWidth: 160) {
                                StatTile("Queue", value: stats["production_queue"]?.string ?? "0")
                                StatTile("In progress", value: stats["production_in_progress"]?.string ?? "0")
                                StatTile("QC pending", value: stats["production_qc_pending"]?.string ?? "0", tone: (stats["production_qc_pending"]?.int ?? 0) > 0 ? .pink : nil)
                                StatTile("Overdue", value: stats["production_overdue"]?.string ?? "0", tone: (stats["production_overdue"]?.int ?? 0) > 0 ? .danger : nil)
                            }
                        }
                    }
                    SectionCard("Stock & fulfilment") {
                        TileGrid(minWidth: 160) {
                            StatTile("Low stock", value: stats["low_stock_products"]?.string ?? "0", tone: (stats["low_stock_products"]?.int ?? 0) > 0 ? .warning : nil)
                            StatTile("To dispatch", value: stats["shipments_pending_dispatch"]?.string ?? "0")
                            StatTile("In transit", value: stats["shipments_in_transit"]?.string ?? "0")
                            StatTile("Carts waiting", value: stats["carts_waiting"]?.string ?? "0")
                        }
                    }
                    if let activity = json["recent_activity"]?.array, !activity.isEmpty {
                        SectionCard("Recent activity") {
                            ForEach(Array(activity.prefix(12).enumerated()), id: \.offset) { _, a in
                                HStack(alignment: .top) {
                                    Image(systemName: "circle.fill").font(.system(size: 6)).foregroundStyle(Theme.Palette.brand).padding(.top, 6)
                                    VStack(alignment: .leading, spacing: 2) {
                                        Text(a["description"]?.string ?? a["type"]?.string?.humanized ?? "Activity").font(Theme.Font.body)
                                        Text("\(a["user"]?.string ?? "System") · \(a["time"]?.string ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        .task {
            if resource == nil {
                let client = env.session.client
                resource = Resource { try await client.get("/v1/admin/dashboard").json ?? .null }
                resource?.load()
            }
        }
        .refreshable { await resource?.reload() }
    }

    private var greeting: String {
        let hour = Calendar.current.component(.hour, from: Date())
        let part = hour < 12 ? "Good morning" : hour < 17 ? "Good afternoon" : "Good evening"
        return "\(part), \(env.session.me?.name.split(separator: " ").first.map(String.init) ?? "")"
    }

    @ViewBuilder
    private func alerts(_ list: [JSONValue]) -> some View {
        ForEach(Array(list.enumerated()), id: \.offset) { _, a in
            let tone: StatusTone = a["type"]?.string == "danger" ? .danger : .warning
            HStack {
                Image(systemName: tone == .danger ? "exclamationmark.octagon" : "exclamationmark.triangle").foregroundStyle(tone.foreground)
                Text(a["message"]?.string ?? "").font(Theme.Font.secondary)
                Spacer()
                ActionLink(a["action_label"]?.string ?? "View", url: a["action_url"]?.string)
            }
            .padding(Theme.Space.m)
            .background(tone.background, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
        }
    }
}

struct MixedCurrencyLines: View {
    let mixed: MoneyByCurrency
    init(_ mixed: MoneyByCurrency) { self.mixed = mixed }
    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            ForEach(Array(mixed.byCurrency.enumerated()), id: \.offset) { _, line in
                HStack {
                    Text(line.currency).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).frame(width: 40, alignment: .leading)
                    MoneyText(line.native ?? line.raw["amount"].flatMap { Money(json: $0, currency: line.currency) })
                    Spacer()
                    if let kes = line.kes ?? line.raw["amount_kes"].flatMap({ Money(json: $0, currency: "KES") }) {
                        Text("= ").foregroundStyle(Theme.textTertiary) + Text(kes.formatted()).foregroundStyle(Theme.textSecondary)
                    } else {
                        Text("no reporting rate").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning)
                    }
                }
                .font(Theme.Font.secondary)
            }
            if !mixed.unconverted.isEmpty {
                Text("Unconverted: \(mixed.unconverted.compactMap { $0.string ?? $0["currency_code"]?.string }.joined(separator: ", "))")
                    .font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning)
            }
        }
    }
}

struct QuickActions: View {
    @Environment(AppEnvironment.self) private var env
    var body: some View {
        let s = env.session
        HStack(spacing: Theme.Space.s) {
            if s.can("pos.access") { quick("Open POS", "cart", .pos) }
            if s.can("orders.create") { quick("New Order", "plus.circle", .pos) }
            if s.can("customers.create") { quick("New Customer", "person.badge.plus", .customers) }
            if s.can("production.raise_order") { quick("Production Order", "hammer", .productionOrders) }
            if s.can("procurement.receive") { quick("Receive Goods", "shippingbox.and.arrow.backward", .goodsReceipt) }
            if s.allows(NavCatalogue.approvalsGate) { quick("Approvals", "checkmark.seal", .approvals) }
        }
    }
    private func quick(_ label: String, _ symbol: String, _ route: Route) -> some View {
        Button { env.navigator.go(route) } label: { Label(label, systemImage: symbol) }
            .buttonStyle(.bordered).tint(Theme.Palette.brandDeep)
    }
}

// MARK: - Notifications

struct NotificationItem: Identifiable, Decodable, Sendable, Hashable {
    let record: Record
    var id: String { record.id }
    init(from decoder: Decoder) throws { record = try Record(from: decoder) }
    var title: String { record.string("title") ?? "Notification" }
    var body: String? { record.string("body") }
    var isRead: Bool { record["is_read"]?.bool ?? (record["read_at"]?.isNull == false) }
    var actionURL: String? { record.string("action_url") }
    var createdAt: Date? { record.date("created_at") }
}

struct NotificationsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var list: PagedList<NotificationItem>?
    @State private var unreadOnly = false

    var body: some View {
        Page("Notifications") {
            if let list {
                HStack {
                    Toggle("Unread only", isOn: $unreadOnly).toggleStyle(.switch).font(Theme.Font.secondary)
                    Spacer()
                    Button("Mark all read") { Task { await markAllRead() } }.disabled(list.items.allSatisfy(\.isRead))
                }
                LoadableView(list.state, retry: { Task { await list.load() } }, isEmpty: { $0.isEmpty }, empty: { EmptyState("You're all caught up", message: "New approvals, orders and messages will show here.", symbol: "bell.slash") }) { items in
                    LazyVStack(spacing: Theme.Space.s) {
                        ForEach(items) { n in
                            Button { Task { await open(n) } } label: {
                                HStack(alignment: .top, spacing: Theme.Space.m) {
                                    Circle().fill(n.isRead ? Color.clear : Theme.Palette.brand).frame(width: 8, height: 8).padding(.top, 7)
                                    VStack(alignment: .leading, spacing: 3) {
                                        Text(n.title).font(n.isRead ? Theme.Font.body : Theme.Font.body.weight(.semibold))
                                        if let b = n.body { Text(b).font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary).lineLimit(3) }
                                        DateText(n.createdAt, style: .relative)
                                    }
                                    Spacer()
                                }
                                .padding(Theme.Space.m)
                                .background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
                                .contentShape(Rectangle())
                            }
                            .buttonStyle(.plain)
                            .contextMenu {
                                Button("Delete", role: .destructive) { Task { await delete(n) } }
                            }
                            .swipeActions(edge: .trailing) {
                                Button("Delete", role: .destructive) { Task { await delete(n) } }
                            }
                            .onAppear { if n.id == items.last?.id { Task { await list.loadMore() } } }
                        }
                    }
                }
            }
        }
        .task {
            if list == nil {
                list = PagedList(client: env.session.client, path: "/v1/admin/notifications", query: ["unread_only": unreadOnly ? "1" : nil])
                await list?.load()
            }
        }
        .onChange(of: unreadOnly) { _, v in
            list?.query["unread_only"] = v ? "1" : nil
            Task { await list?.load() }
        }
        .refreshable { await list?.load() }
    }

    private func open(_ n: NotificationItem) async {
        if !n.isRead { _ = try? await env.session.client.post("/v1/admin/notifications/\(n.id)/read") ; env.unreadNotifications = max(0, env.unreadNotifications - 1) }
        if let url = n.actionURL, let route = Route.from(consolePath: url) { env.navigator.go(route) }
        await list?.load()
    }

    private func markAllRead() async {
        _ = try? await env.session.client.post("/v1/admin/notifications/read-all")
        env.unreadNotifications = 0
        await list?.load()
    }

    private func delete(_ n: NotificationItem) async {
        do {
            _ = try await env.session.client.delete("/v1/admin/notifications/\(n.id)")
            list?.remove { $0.id == n.id }
        } catch { env.toasts.error(error) }
    }
}

// MARK: - Approvals (ApprovalEngine::present)

struct ApprovalRequest: Identifiable, Decodable, Sendable, Hashable {
    let record: Record
    var id: String { record.id }
    init(from decoder: Decoder) throws { record = try Record(from: decoder) }
    var event: String { record.string("event") ?? "approval" }
    var type: String { record.string("approvable_type") ?? "" }
    var status: String? { record.status }
    var maker: String? { record["maker"]?["name"]?.string }
    var counterparty: String? { record.string("counterparty") }
    var amount: Money? { record["amount"].flatMap { Money(json: $0, currency: record.string("currency_code") ?? "KES") } }
    var amountKes: Money? { record["amount_kes"].flatMap { Money(json: $0, currency: "KES") } }
    var valueUnknown: Bool { record["value_unknown"]?.bool ?? false }
    var awaiting: String? { record["awaiting"]?["label"]?.string ?? record["awaiting"]?["permission"]?.string }
    var escalated: Bool { record["escalated"]?.bool ?? false }
    var canSign: Bool { record["can_sign"]?.bool ?? record["may_sign"]?.bool ?? true }
    var createdAt: Date? { record.date("created_at") }
    var detailRoute: Route? {
        let aid = record.int("approvable_id") ?? 0
        switch type {
        case "Expense": return .expense(id: aid)
        case "PurchaseOrder": return .purchaseOrder(id: aid)
        case "InventoryTransfer": return .transfer(id: aid)
        case "InventoryTransaction", "StockAdjustment": return .adjustment(id: aid)
        case "Order": return .order(id: aid)
        default: return nil
        }
    }
}

struct ApprovalsView: View {
    @Environment(AppEnvironment.self) private var env
    enum Tab: String, CaseIterable { case inbox = "To sign", mine = "My submissions" }
    @State private var tab: Tab = .inbox
    @State private var inbox: Resource<[ApprovalRequest]>?
    @State private var mine: Resource<[ApprovalRequest]>?
    @State private var rejecting: ApprovalRequest?
    @State private var reason = ""

    var body: some View {
        Page("Approvals", subtitle: "Signatures the thresholds ask of you") {
            Picker("", selection: $tab) { ForEach(Tab.allCases, id: \.self) { Text($0.rawValue) } }.pickerStyle(.segmented).frame(maxWidth: 360)
            if let res = tab == .inbox ? inbox : mine {
                LoadableView(res.state, retry: { res.load() }, isEmpty: { $0.isEmpty }, empty: { EmptyState(tab == .inbox ? "Nothing waiting for your signature" : "You have no open submissions", symbol: "checkmark.seal") }) { items in
                    LazyVStack(spacing: Theme.Space.s) {
                        ForEach(items) { r in approvalRow(r) }
                    }
                }
            }
        }
        .task { await loadAll() }
        .refreshable { await loadAll() }
        .sheet(item: $rejecting) { r in
            VStack(alignment: .leading, spacing: Theme.Space.l) {
                Text("Reject \(r.event.humanized)").font(Theme.Font.sectionTitle)
                TextField("Reason (required)", text: $reason, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(3...6)
                HStack { Spacer(); Button("Cancel") { rejecting = nil }; Button("Reject", role: .destructive) { Task { await decide(r, sign: false) } }.disabled(reason.trimmingCharacters(in: .whitespaces).isEmpty) }
            }
            .padding(Theme.Space.xl).frame(minWidth: 380)
        }
    }

    private func approvalRow(_ r: ApprovalRequest) -> some View {
        SectionCard {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 4) {
                    HStack { Text(r.event.humanized).font(Theme.Font.sectionTitle); StatusBadge(r.status) ; if r.escalated { StatusBadge("escalated", tone: .warning) } }
                    if let m = r.maker { Text("Raised by \(m)").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary) }
                    if let c = r.counterparty { Text(c).font(Theme.Font.secondary) }
                    if let a = r.awaiting { Text("Awaiting: \(a.humanized)").font(Theme.Font.caption).foregroundStyle(Theme.Palette.amber) }
                    DateText(r.createdAt, style: .relative)
                }
                Spacer()
                VStack(alignment: .trailing, spacing: 4) {
                    if r.valueUnknown { Text("Value unknown").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning) }
                    MoneyText(r.amount, emphasis: true)
                    if let k = r.amountKes, r.amount?.currency != "KES" { Text(k.formatted()).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                }
            }
            HStack {
                if let route = r.detailRoute { Button("Open") { env.navigator.go(route) } }
                Spacer()
                if tab == .inbox {
                    Button("Reject") { reason = ""; rejecting = r }
                    ConfirmButton("Sign", message: "Your signature approves this \(r.event.humanized.lowercased()) at the current band.", action: { await decide(r, sign: true) }) { Text("Sign") }
                        .buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                } else if r.status == "rejected" {
                    Button("Resubmit") { Task { await resubmit(r) } }
                }
            }
        }
    }

    private func loadAll() async {
        let c = env.session.client
        if inbox == nil {
            inbox = Resource { try (await c.get("/v1/admin/approvals/inbox").json?["data"]?.decode([ApprovalRequest].self)) ?? [] }
            mine = Resource { try (await c.get("/v1/admin/approvals/mine").json?["data"]?.decode([ApprovalRequest].self)) ?? [] }
        }
        await inbox?.reload()
        await mine?.reload()
    }

    private func decide(_ r: ApprovalRequest, sign: Bool) async {
        do {
            if sign { _ = try await env.session.client.post("/v1/admin/approvals/\(r.id)/sign") }
            else { _ = try await env.session.client.post("/v1/admin/approvals/\(r.id)/reject", json: .object(["reason": .string(reason)])) }
            rejecting = nil
            env.toasts.show(sign ? "Signed" : "Rejected")
            await loadAll()
        } catch { env.toasts.error(error) }
    }

    private func resubmit(_ r: ApprovalRequest) async {
        do { _ = try await env.session.client.post("/v1/admin/approvals/\(r.id)/resubmit"); env.toasts.show("Resubmitted"); await loadAll() } catch { env.toasts.error(error) }
    }
}
