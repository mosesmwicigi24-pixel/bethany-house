import Foundation

/// Every screen of the Hub, as a value the apps can navigate to, deep-link
/// to and gate. One-to-one with the console routes in react-admin/src/App.tsx;
/// the parity matrix in docs/NATIVE_APPS_AUDIT.md is keyed on these.
public enum Route: Hashable, Sendable {
    // Workspace
    case dashboard, approvals, notifications, comms(channelId: Int? = nil)
    // Me
    case me, updates(id: Int? = nil), forms, handbook(id: Int? = nil), courses(id: Int? = nil), sign(id: Int? = nil), todos, colleagues
    case clock, myShifts, myLeave, myTimesheet, myPayslips, myAdvances, profile
    // People
    case peopleOverview, peopleRequests, attendance, formsManage, roster, timesheets, leave, people(id: Int? = nil), payroll
    // Catalog
    case products, product(id: Int? = nil, new: Bool = false), categories
    // Sales
    case pos, orders(channel: OrderChannel? = nil), order(id: Int), pendingQueue, quotations, invoices, interestCarts
    case shipments, shipment(id: Int), customers, customer(id: Int), balances, tills, eodReports, eodSettings, handoff(id: Int)
    // Production
    case productionMyTasks, productionOrders, productionOrder(id: Int), wip, qc, productionCalendar, bom
    // Inventory
    case stockLevels, serials, adjustments, adjustment(id: Int), transfers, transfer(id: Int), materials, lowStock
    // Procurement
    case purchaseOrders, purchaseOrder(id: Int), suppliers, goodsReceipt, grn(id: Int), purchaseReturns, purchaseReturn(id: Int)
    // Expenses
    case expenses, expense(id: Int), imprest, expenseCategories, expenseAnalytics
    // Marketing & more
    case seasons, campaigns, homeFront, homeFrontProducts, insights
    case paymentTransactions
    // Reports
    case report(ReportPage)
    // Setup
    case setupBusiness, outlets, attendanceRules, automations, holidays, roles, users, countries, currencies, languages
    case taxRates, discountLimits, paymentMethods, shipping, productionStages, autoAssignees, activityLogs, database, trash
    case downloads, myDownloads

    public enum OrderChannel: String, Sendable, CaseIterable { case till, web, chat, quoted }

    public enum ReportPage: String, Sendable, CaseIterable {
        case executive, sales, customers, finance, production, inventory, procurement, performance, signals, explorer, dataQuality = "data_quality"
        public var permission: String {
            switch self {
            case .finance: return "reports.financial"
            case .dataQuality: return "reports.data_quality"
            default: return "reports.\(rawValue)"
            }
        }
        public var title: String {
            switch self {
            case .executive: return "Executive Overview"
            case .sales: return "Sales & Orders"
            case .customers: return "Customers & Neema"
            case .finance: return "Finance & Cash"
            case .production: return "Production & Fulfilment"
            case .inventory: return "Inventory"
            case .procurement: return "Procurement & Suppliers"
            case .performance: return "Staff, Outlets & Performance"
            case .signals: return "Signals"
            case .explorer: return "Business Explorer"
            case .dataQuality: return "Audit & Data Quality"
            }
        }
        /// The API prefix under /v1/admin/reports.
        public var apiSegment: String {
            switch self {
            case .finance: return "financial"
            case .dataQuality: return "data-quality"
            default: return rawValue
            }
        }
    }

    /// The console path, for deep links (`https://hub.bethanyhouse.co.ke/admin<path>`) and sharing.
    public var consolePath: String {
        switch self {
        case .dashboard: return "/dashboard"
        case .approvals: return "/approvals"
        case .notifications: return "/notifications"
        case .comms(let id): return id.map { "/comms/\($0)" } ?? "/comms"
        case .me: return "/me"
        case .updates(let id): return id.map { "/updates/\($0)" } ?? "/updates"
        case .forms: return "/forms"
        case .handbook(let id): return id.map { "/handbook/\($0)" } ?? "/handbook"
        case .courses(let id): return id.map { "/courses/\($0)" } ?? "/courses"
        case .sign(let id): return id.map { "/me/sign/\($0)" } ?? "/me/sign"
        case .todos: return "/todos"
        case .colleagues: return "/colleagues"
        case .clock: return "/me/clock"
        case .myShifts: return "/me/shifts"
        case .myLeave: return "/me/leave"
        case .myTimesheet: return "/me/timesheet"
        case .myPayslips: return "/me/payslips"
        case .myAdvances: return "/me/advances"
        case .profile: return "/settings/profile"
        case .peopleOverview: return "/people/overview"
        case .peopleRequests: return "/people/requests"
        case .attendance: return "/settings/attendance"
        case .formsManage: return "/people/forms"
        case .roster: return "/roster"
        case .timesheets: return "/timesheets"
        case .leave: return "/leave"
        case .people(let id): return id.map { "/people/\($0)" } ?? "/people"
        case .payroll: return "/payroll"
        case .products: return "/catalogue/products"
        case .product(let id, let new): return new ? "/catalogue/products/new" : "/catalogue/products/\(id ?? 0)"
        case .categories: return "/catalogue/categories"
        case .pos: return "/pos"
        case .orders(let ch):
            switch ch {
            case .till: return "/sales/pos-orders"
            case .web: return "/sales/online-orders"
            case .chat: return "/sales/whatsapp-orders"
            case .quoted: return "/sales/quoted-orders"
            case nil: return "/sales/orders"
            }
        case .order(let id): return "/sales/orders/\(id)"
        case .pendingQueue: return "/sales/pending-queue"
        case .quotations: return "/sales/quotations"
        case .invoices: return "/sales/invoices"
        case .interestCarts: return "/sales/interest-carts"
        case .shipments: return "/sales/shipments"
        case .shipment(let id): return "/sales/shipments/\(id)"
        case .customers: return "/sales/customers"
        case .customer(let id): return "/sales/customers/\(id)"
        case .balances: return "/pos/outstanding-balances"
        case .tills: return "/pos/tills"
        case .eodReports: return "/pos/eod-reports"
        case .eodSettings: return "/pos/eod-settings"
        case .handoff(let id): return "/handoff/orders/\(id)"
        case .productionMyTasks: return "/production/my-tasks"
        case .productionOrders: return "/production/orders"
        case .productionOrder(let id): return "/production/orders/\(id)"
        case .wip: return "/production/wip"
        case .qc: return "/production/qc"
        case .productionCalendar: return "/production/calendar"
        case .bom: return "/production/bom"
        case .stockLevels: return "/inventory/stock-levels"
        case .serials: return "/inventory/serials"
        case .adjustments: return "/inventory/adjustments"
        case .adjustment(let id): return "/inventory/adjustments/\(id)"
        case .transfers: return "/inventory/transfers"
        case .transfer(let id): return "/inventory/transfers/\(id)"
        case .materials: return "/inventory/materials"
        case .lowStock: return "/inventory/low-stock"
        case .purchaseOrders: return "/procurement/purchase-orders"
        case .purchaseOrder(let id): return "/procurement/purchase-orders/\(id)"
        case .suppliers: return "/procurement/suppliers"
        case .goodsReceipt: return "/procurement/goods-receipt"
        case .grn(let id): return "/procurement/goods-receipt/\(id)"
        case .purchaseReturns: return "/procurement/returns"
        case .purchaseReturn(let id): return "/procurement/returns/\(id)"
        case .expenses: return "/expenses"
        case .expense(let id): return "/expenses/\(id)"
        case .imprest: return "/expenses/imprest"
        case .expenseCategories: return "/expenses/settings"
        case .expenseAnalytics: return "/expenses/analytics"
        case .seasons: return "/marketing/seasons"
        case .campaigns: return "/marketing/campaigns"
        case .homeFront: return "/home-front/home"
        case .homeFrontProducts: return "/home-front/products"
        case .insights: return "/insights"
        case .paymentTransactions: return "/finance/transactions"
        case .report(let p):
            switch p {
            case .executive: return "/reports"
            case .finance: return "/reports/finance"
            case .dataQuality: return "/reports/data-quality"
            default: return "/reports/\(p.rawValue)"
            }
        case .setupBusiness: return "/settings/business"
        case .outlets: return "/settings/outlets"
        case .attendanceRules: return "/settings/attendance-rules"
        case .automations: return "/settings/automations"
        case .holidays: return "/settings/holidays"
        case .roles: return "/settings/roles"
        case .users: return "/settings/users"
        case .countries: return "/settings/countries"
        case .currencies: return "/settings/currencies"
        case .languages: return "/settings/languages"
        case .taxRates: return "/settings/taxes"
        case .discountLimits: return "/settings/discount-limits"
        case .paymentMethods: return "/settings/payment-methods"
        case .shipping: return "/settings/shipping"
        case .productionStages: return "/settings/production/stages"
        case .autoAssignees: return "/settings/production/auto-assignees"
        case .activityLogs: return "/settings/activity-logs"
        case .database: return "/settings/database"
        case .trash: return "/settings/trash"
        case .downloads: return "/settings/downloads"
        case .myDownloads: return "/settings/my-downloads"
        }
    }

    /// Parses a console path (from a push `data.url`, a universal link or a
    /// pasted Hub link) back into a route. Unknown paths return nil.
    public static func from(consolePath raw: String) -> Route? {
        var path = raw
        if let q = path.firstIndex(of: "?") { path = String(path[..<q]) }
        if path.hasPrefix("/admin") { path = String(path.dropFirst(6)) }
        if path.isEmpty { path = "/" }
        let parts = path.split(separator: "/").map(String.init)
        func id(_ i: Int) -> Int? { parts.indices.contains(i) ? Int(parts[i]) : nil }

        // Exact matches first.
        if let r = NavCatalogue.routeByPath[path] { return r }
        switch parts.first {
        case "comms": return .comms(channelId: id(1))
        case "updates": return .updates(id: id(1))
        case "handbook": return .handbook(id: id(1))
        case "courses": return .courses(id: id(1))
        case "me": return parts.count > 1 && parts[1] == "sign" ? .sign(id: id(2)) : nil
        case "people": return id(1).map { .people(id: $0) }
        case "catalogue":
            if parts.count > 2, parts[1] == "products" { return parts[2] == "new" ? .product(new: true) : id(2).map { .product(id: $0) } }
            return nil
        case "sales":
            guard parts.count > 2 else { return nil }
            switch parts[1] {
            case "orders": return id(2).map(Route.order)
            case "shipments": return id(2).map(Route.shipment)
            case "customers": return id(2).map(Route.customer)
            default: return nil
            }
        case "handoff": return id(2).map(Route.handoff)
        case "production": return parts.count > 2 && parts[1] == "orders" ? id(2).map(Route.productionOrder) : nil
        case "inventory":
            guard parts.count > 2 else { return nil }
            switch parts[1] {
            case "adjustments": return id(2).map(Route.adjustment)
            case "transfers": return id(2).map(Route.transfer)
            default: return nil
            }
        case "procurement":
            guard parts.count > 2 else { return nil }
            switch parts[1] {
            case "purchase-orders": return id(2).map(Route.purchaseOrder)
            case "goods-receipt": return id(2).map(Route.grn)
            case "returns": return id(2).map(Route.purchaseReturn)
            default: return nil
            }
        case "expenses": return id(1).map(Route.expense)
        case "reports":
            // Moved reports keep working (App.tsx MovedReport).
            if parts.count > 1 {
                switch parts[1] {
                case "financial": return .report(.finance)
                case "order-pipeline": return .report(.sales)
                case "signals": return .report(.signals)
                default: break
                }
            }
            return nil
        case "intelligence": return parts.count > 1 ? .report(.customers) : .report(.signals)
        default: return nil
        }
    }
}

/// A sidebar entry: label, route and the gate the API checks for it.
public struct NavItem: Hashable, Sendable, Identifiable {
    public var id: String { route.consolePath }
    public let label: String
    public let route: Route
    public let gate: NavGate
    public let icon: String   // SF Symbol name

    public init(_ label: String, _ route: Route, gate: NavGate = .open, icon: String) {
        self.label = label
        self.route = route
        self.gate = gate
        self.icon = icon
    }
}

public struct NavGroup: Hashable, Sendable, Identifiable {
    public var id: String { label }
    public let label: String
    public let items: [NavItem]
    public init(_ label: String, _ items: [NavItem]) {
        self.label = label
        self.items = items
    }

    public func visible(for subject: PermissionSet) -> NavGroup? {
        let v = items.filter { $0.gate.allows(subject) }
        return v.isEmpty ? nil : NavGroup(label, v)
    }
}

/// The sidebar, ported one to one from react-admin/src/components/layout/Sidebar.tsx.
/// `npm run check:nav` keeps the console's three copies in step; this is the
/// fourth, and NavCatalogueTests pins it to the same slugs.
public enum NavCatalogue {
    public static let approvalsGate = NavGate(anyOf: [
        "procurement.approve", "inventory.approve", "expenses.approve", "approvals.finance_sign",
        "payments.void", "payments.reassign", "payments.approve_international", "payments.request_void", "payments.request_reassign",
        "procurement.create", "inventory.adjust", "inventory.transfer", "expenses.create", "products.edit", "products.edit_cost",
        "orders.set_deposit", "settings.financial_propose", "settings.pricing_rate_propose",
    ])

    public static let groups: [NavGroup] = [
        NavGroup("Workspace", [
            NavItem("Dashboard", .dashboard, gate: .perm("dashboard.view"), icon: "square.grid.2x2"),
            NavItem("Approvals", .approvals, gate: approvalsGate, icon: "checkmark.seal"),
            NavItem("Notifications", .notifications, gate: .perm("notifications.view"), icon: "bell"),
            NavItem("Messages", .comms(), icon: "bubble.left.and.bubble.right"),
        ]),
        NavGroup("Me", [
            NavItem("Today", .me, icon: "sun.max"),
            NavItem("Updates", .updates(), icon: "megaphone"),
            NavItem("Checklists", .forms, icon: "checklist"),
            NavItem("Handbook", .handbook(), icon: "book"),
            NavItem("Training", .courses(), icon: "graduationcap"),
            NavItem("To sign", .sign(), icon: "signature"),
            NavItem("To-dos", .todos, icon: "checkmark.circle"),
            NavItem("Colleagues", .colleagues, icon: "person.2"),
            NavItem("Clock in", .clock, icon: "clock"),
            NavItem("My shifts", .myShifts, icon: "calendar"),
            NavItem("My leave", .myLeave, icon: "sun.horizon"),
            NavItem("My hours", .myTimesheet, icon: "hourglass"),
            NavItem("My payslips", .myPayslips, icon: "doc.text"),
            NavItem("My advances", .myAdvances, icon: "banknote"),
        ]),
        NavGroup("People", [
            NavItem("Overview", .peopleOverview, gate: .perm("attendance.view_team"), icon: "person.3"),
            NavItem("Requests", .peopleRequests, gate: .perm("attendance.view_team"), icon: "tray.full"),
            NavItem("Attendance", .attendance, gate: .perm("attendance.view_team"), icon: "location"),
            NavItem("Checklists", .formsManage, gate: .perm("forms.manage"), icon: "list.clipboard"),
            NavItem("Roster", .roster, gate: .perm("attendance.view_team"), icon: "calendar.badge.clock"),
            NavItem("Timesheets", .timesheets, gate: .perm("attendance.view_team"), icon: "tablecells"),
            NavItem("Leave", .leave, gate: .perm("attendance.view_team"), icon: "airplane"),
            NavItem("Employee files", .people(), gate: .perm("hr.view"), icon: "folder.badge.person.crop"),
            NavItem("Payroll", .payroll, gate: .perm("payroll.view"), icon: "dollarsign.circle"),
        ]),
        NavGroup("Catalog", [
            NavItem("Products", .products, gate: .perm("products.view"), icon: "shippingbox"),
            NavItem("Categories", .categories, gate: .perm("products.view"), icon: "square.stack.3d.up"),
        ]),
        NavGroup("Sales", [
            NavItem("POS", .pos, gate: .perm("pos.access"), icon: "cart"),
            NavItem("Till Sales", .orders(channel: .till), gate: .perm("orders.view"), icon: "storefront"),
            NavItem("Web Orders", .orders(channel: .web), gate: .perm("orders.view"), icon: "globe"),
            NavItem("Chat Orders", .orders(channel: .chat), gate: .perm("orders.view"), icon: "message"),
            NavItem("Quoted Sales", .orders(channel: .quoted), gate: .perm("orders.view"), icon: "doc.plaintext"),
            NavItem("Pending Queue", .pendingQueue, gate: .perm("orders.view"), icon: "hourglass.bottomhalf.filled"),
            NavItem("Quotations", .quotations, gate: .perm("quotations.view"), icon: "doc.badge.ellipsis"),
            NavItem("Invoices", .invoices, gate: .perm("orders.view"), icon: "doc.richtext"),
            NavItem("Interest Carts", .interestCarts, gate: .perm("orders.view"), icon: "cart.badge.questionmark"),
            NavItem("Shipments", .shipments, gate: .perm("shipment.view"), icon: "truck.box"),
            NavItem("Customers", .customers, gate: .perm("customers.view"), icon: "person.crop.rectangle.stack"),
            NavItem("Balances", .balances, gate: .perm("receivables.view"), icon: "creditcard"),
            NavItem("Tills", .tills, gate: .anyOf(["pos.access", "pos.tills_view_all", "pos.reconcile", "pos.till_correction"]), icon: "tray.2"),
            NavItem("EoD Reports", .eodReports, gate: .perm("pos.eod_review"), icon: "moon.stars"),
            NavItem("EoD Settings", .eodSettings, gate: .allOf(["pos.access", "settings.edit"]), icon: "gearshape"),
        ]),
        NavGroup("Production", [
            NavItem("My Tasks", .productionMyTasks, gate: .perm("production.worker"), icon: "scissors"),
            NavItem("Production Orders", .productionOrders, gate: .perm("production.view"), icon: "hammer"),
            NavItem("Work In Progress", .wip, gate: .perm("production.view"), icon: "rectangle.split.3x1"),
            NavItem("Quality Control", .qc, gate: .anyOf(["production.submit_qc", "production.approve_qc"]), icon: "checkmark.shield"),
            NavItem("Calendar", .productionCalendar, gate: .perm("production.view"), icon: "calendar"),
            NavItem("Bill of Materials", .bom, gate: .perm("production.view_bom"), icon: "list.bullet.rectangle"),
        ]),
        NavGroup("Inventory", [
            NavItem("Stock Levels", .stockLevels, gate: .perm("inventory.view"), icon: "chart.bar"),
            NavItem("Product Serials", .serials, gate: .perm("inventory.view"), icon: "barcode"),
            NavItem("Stock Adjustments", .adjustments, gate: .perm("inventory.view"), icon: "slider.horizontal.3"),
            NavItem("Stock Transfers", .transfers, gate: .perm("inventory.view"), icon: "arrow.left.arrow.right"),
            NavItem("Raw Materials", .materials, gate: .perm("inventory.view"), icon: "cube"),
            NavItem("Low Stock Alerts", .lowStock, gate: .perm("inventory.view"), icon: "exclamationmark.triangle"),
        ]),
        NavGroup("Procurement", [
            NavItem("Purchase Orders", .purchaseOrders, gate: .perm("procurement.view"), icon: "doc.on.clipboard"),
            NavItem("Suppliers", .suppliers, gate: .perm("procurement.view"), icon: "building.2"),
            NavItem("Goods Receipt", .goodsReceipt, gate: .perm("procurement.view"), icon: "shippingbox.and.arrow.backward"),
            NavItem("Purchase Returns", .purchaseReturns, gate: .perm("procurement.view"), icon: "arrow.uturn.backward"),
        ]),
        NavGroup("Expenses", [
            NavItem("Expenses", .expenses, gate: .perm("expenses.view"), icon: "receipt"),
            NavItem("Imprest", .imprest, gate: .perm("expenses.view"), icon: "wallet.pass"),
            NavItem("Categories", .expenseCategories, gate: .perm("expenses.budgets"), icon: "tag"),
            NavItem("Analytics", .expenseAnalytics, gate: .perm("expenses.view"), icon: "chart.pie"),
        ]),
        NavGroup("Marketing", [
            NavItem("Seasons", .seasons, gate: .perm("marketing.view"), icon: "leaf"),
            NavItem("Campaigns", .campaigns, gate: .perm("marketing.view"), icon: "envelope"),
        ]),
        NavGroup("Home Front Customization", [
            NavItem("Home Page", .homeFront, gate: .perm("marketing.view"), icon: "house"),
            NavItem("Product Pages", .homeFrontProducts, gate: .perm("marketing.view"), icon: "rectangle.3.group"),
        ]),
        NavGroup("Insights", [
            NavItem("Storefront Insights", .insights, gate: .perm("reports.customers"), icon: "globe.europe.africa"),
        ]),
        NavGroup("Finance", [
            NavItem("Payment Transactions", .paymentTransactions, gate: .perm("payments.transactions"), icon: "list.bullet.rectangle.portrait"),
        ]),
        NavGroup("Reports", Route.ReportPage.allCases.map { page in
            NavItem(page.title, .report(page), gate: .perm(page.permission), icon: "chart.xyaxis.line")
        }),
        NavGroup("Setup", [
            NavItem("Business", .setupBusiness, gate: .perm("settings.view"), icon: "building"),
            NavItem("Outlets", .outlets, gate: .perm("outlets.view"), icon: "mappin.and.ellipse"),
            NavItem("Attendance rules", .attendanceRules, gate: .perm("attendance.view_team"), icon: "clock.badge.checkmark"),
            NavItem("Automations", .automations, gate: .perm("attendance.view_team"), icon: "bolt"),
            NavItem("Holidays", .holidays, icon: "party.popper"),
            NavItem("Roles", .roles, gate: .perm("roles.view"), icon: "person.badge.key"),
            NavItem("Users", .users, gate: .perm("users.view"), icon: "person.crop.circle.badge.plus"),
            NavItem("Countries", .countries, gate: .anyOf(["settings.view", "setup.technical"]), icon: "flag"),
            NavItem("Currencies", .currencies, gate: .anyOf(["settings.view", "settings.financial_propose", "settings.pricing_rate_propose"]), icon: "coloncurrencysign.circle"),
            NavItem("Languages", .languages, gate: .anyOf(["settings.view", "setup.technical"]), icon: "character.bubble"),
            NavItem("Tax Rates", .taxRates, gate: .anyOf(["settings.view", "settings.financial_propose"]), icon: "percent"),
            NavItem("Discount limits", .discountLimits, gate: .superAdmin, icon: "tag.slash"),
            NavItem("Payment Methods", .paymentMethods, gate: .anyOf(["settings.view", "settings.financial_propose"]), icon: "creditcard.and.123"),
            NavItem("Shipping", .shipping, gate: .anyOf(["settings.view", "setup.technical"]), icon: "box.truck"),
            NavItem("Production Stages", .productionStages, gate: .perm("production.configure_auto_assignees"), icon: "rectangle.stack"),
            NavItem("Auto-Assignees", .autoAssignees, gate: .perm("production.configure_auto_assignees"), icon: "person.2.badge.gearshape"),
            NavItem("Activity Logs", .activityLogs, gate: .superAdmin, icon: "doc.text.magnifyingglass"),
            NavItem("Database", .database, gate: .superAdmin, icon: "externaldrive"),
            NavItem("Trash", .trash, gate: .perm("settings.manage"), icon: "trash"),
        ]),
    ]

    /// User-menu pages that are not in the sidebar (Topbar.tsx).
    public static let userMenu: [NavItem] = [
        NavItem("My profile", .profile, icon: "person.crop.circle"),
        NavItem("My downloads", .myDownloads, icon: "arrow.down.circle"),
        NavItem("Download approvals", .downloads, icon: "checkmark.circle.badge.questionmark"),
    ]

    public static let allItems: [NavItem] = groups.flatMap(\.items) + userMenu

    public static let routeByPath: [String: Route] = {
        var m: [String: Route] = [:]
        for item in allItems { m[item.route.consolePath] = item.route }
        m["/"] = .dashboard
        m["/sales/orders"] = .orders()
        m["/reports/financial"] = .report(.finance)
        return m
    }()

    public static func item(for route: Route) -> NavItem? { allItems.first { $0.route == route } }

    /// The phone's bottom tab bar (BottomTabBar.tsx): Home, Messages, Me,
    /// Production (production.view), Orders (orders.view), More.
    public static func phoneTabs(for subject: PermissionSet, home: Route) -> [NavItem] {
        var tabs: [NavItem] = [NavItem("Home", home, icon: "house")]
        tabs.append(NavItem("Messages", .comms(), icon: "bubble.left.and.bubble.right"))
        tabs.append(NavItem("Me", .me, icon: "person"))
        if subject.isFloorWorker {
            tabs[0] = NavItem("My Tasks", .productionMyTasks, icon: "scissors")
        } else if subject.can("production.view") {
            tabs.append(NavItem("Production", .productionOrders, icon: "hammer"))
        }
        if subject.can("orders.view") {
            tabs.append(NavItem("Orders", .orders(channel: .web), icon: "bag"))
        }
        return tabs
    }
}
