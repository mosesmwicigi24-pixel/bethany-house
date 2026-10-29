package ke.co.bethanyhouse.hub.core

/**
 * Every screen of the Hub, as a value the app can navigate to, deep-link to
 * and gate. One-to-one with the console routes in react-admin/src/App.tsx
 * and with the Apple `Route` enum; the parity matrix is keyed on these.
 */
sealed class Route {
    // Workspace
    data object Dashboard : Route()
    data object Approvals : Route()
    data object Notifications : Route()
    data class Comms(val channelId: Int? = null) : Route()
    // Me
    data object Me : Route()
    data class Updates(val id: Int? = null) : Route()
    data object Forms : Route()
    data class Handbook(val id: Int? = null) : Route()
    data class Courses(val id: Int? = null) : Route()
    data class Sign(val id: Int? = null) : Route()
    data object Todos : Route()
    data object Colleagues : Route()
    data object Clock : Route()
    data object MyShifts : Route()
    data object MyLeave : Route()
    data object MyTimesheet : Route()
    data object MyPayslips : Route()
    data object MyAdvances : Route()
    data object Profile : Route()
    // People
    data object PeopleOverview : Route()
    data object PeopleRequests : Route()
    data object Attendance : Route()
    data object FormsManage : Route()
    data object Roster : Route()
    data object Timesheets : Route()
    data object Leave : Route()
    data class People(val id: Int? = null) : Route()
    data object Payroll : Route()
    // Catalog
    data object Products : Route()
    data class Product(val id: Int? = null, val new: Boolean = false) : Route()
    data object Categories : Route()
    // Sales
    data object Pos : Route()
    data class Orders(val channel: OrderChannel? = null) : Route()
    data class Order(val id: Int) : Route()
    data object PendingQueue : Route()
    data object Quotations : Route()
    data object Invoices : Route()
    data object InterestCarts : Route()
    data object Shipments : Route()
    data class Shipment(val id: Int) : Route()
    data object Customers : Route()
    data class Customer(val id: Int) : Route()
    data object Balances : Route()
    data object Tills : Route()
    data object EodReports : Route()
    data object EodSettings : Route()
    data class Handoff(val id: Int) : Route()
    // Production
    data object ProductionMyTasks : Route()
    data object ProductionOrders : Route()
    data class ProductionOrder(val id: Int) : Route()
    data object Wip : Route()
    data object Qc : Route()
    data object ProductionCalendar : Route()
    data object Bom : Route()
    // Inventory
    data object StockLevels : Route()
    data object Serials : Route()
    data object Adjustments : Route()
    data class Adjustment(val id: Int) : Route()
    data object Transfers : Route()
    data class Transfer(val id: Int) : Route()
    data object Materials : Route()
    data object LowStock : Route()
    // Procurement
    data object PurchaseOrders : Route()
    data class PurchaseOrder(val id: Int) : Route()
    data object Suppliers : Route()
    data object GoodsReceipt : Route()
    data class Grn(val id: Int) : Route()
    data object PurchaseReturns : Route()
    data class PurchaseReturn(val id: Int) : Route()
    // Expenses
    data object Expenses : Route()
    data class Expense(val id: Int) : Route()
    data object Imprest : Route()
    data object ExpenseCategories : Route()
    data object ExpenseAnalytics : Route()
    // Marketing & more
    data object Seasons : Route()
    data object Campaigns : Route()
    data object HomeFront : Route()
    data object HomeFrontProducts : Route()
    data object Insights : Route()
    data object PaymentTransactions : Route()
    // Reports
    data class Report(val page: ReportPage) : Route()
    // Setup
    data object SetupBusiness : Route()
    data object Outlets : Route()
    data object AttendanceRules : Route()
    data object Automations : Route()
    data object Holidays : Route()
    data object Roles : Route()
    data object Users : Route()
    data object Countries : Route()
    data object Currencies : Route()
    data object Languages : Route()
    data object TaxRates : Route()
    data object DiscountLimits : Route()
    data object PaymentMethods : Route()
    data object Shipping : Route()
    data object ProductionStages : Route()
    data object AutoAssignees : Route()
    data object ActivityLogs : Route()
    data object Database : Route()
    data object Trash : Route()
    data object Downloads : Route()
    data object MyDownloads : Route()
    // Tablet only
    data object Kiosk : Route()

    enum class OrderChannel(val wire: String) { TILL("till"), WEB("web"), CHAT("chat"), QUOTED("quoted") }

    enum class ReportPage(val wire: String, val title: String, val permission: String, val apiSegment: String) {
        EXECUTIVE("executive", "Executive Overview", "reports.executive", "executive"),
        SALES("sales", "Sales & Orders", "reports.sales", "sales"),
        CUSTOMERS("customers", "Customers & Neema", "reports.customers", "customers"),
        FINANCE("finance", "Finance & Cash", "reports.financial", "financial"),
        PRODUCTION("production", "Production & Fulfilment", "reports.production", "production"),
        INVENTORY("inventory", "Inventory", "reports.inventory", "inventory"),
        PROCUREMENT("procurement", "Procurement & Suppliers", "reports.procurement", "procurement"),
        PERFORMANCE("performance", "Staff, Outlets & Performance", "reports.performance", "performance"),
        SIGNALS("signals", "Signals", "reports.signals", "signals"),
        EXPLORER("explorer", "Business Explorer", "reports.explorer", "explorer"),
        DATA_QUALITY("data_quality", "Audit & Data Quality", "reports.data_quality", "data-quality"),
    }

    /** The console path, for deep links and sharing. */
    val consolePath: String
        get() = when (this) {
            Dashboard -> "/dashboard"
            Approvals -> "/approvals"
            Notifications -> "/notifications"
            is Comms -> channelId?.let { "/comms/$it" } ?: "/comms"
            Me -> "/me"
            is Updates -> id?.let { "/updates/$it" } ?: "/updates"
            Forms -> "/forms"
            is Handbook -> id?.let { "/handbook/$it" } ?: "/handbook"
            is Courses -> id?.let { "/courses/$it" } ?: "/courses"
            is Sign -> id?.let { "/me/sign/$it" } ?: "/me/sign"
            Todos -> "/todos"
            Colleagues -> "/colleagues"
            Clock -> "/me/clock"
            MyShifts -> "/me/shifts"
            MyLeave -> "/me/leave"
            MyTimesheet -> "/me/timesheet"
            MyPayslips -> "/me/payslips"
            MyAdvances -> "/me/advances"
            Profile -> "/settings/profile"
            PeopleOverview -> "/people/overview"
            PeopleRequests -> "/people/requests"
            Attendance -> "/settings/attendance"
            FormsManage -> "/people/forms"
            Roster -> "/roster"
            Timesheets -> "/timesheets"
            Leave -> "/leave"
            is People -> id?.let { "/people/$it" } ?: "/people"
            Payroll -> "/payroll"
            Products -> "/catalogue/products"
            is Product -> if (new) "/catalogue/products/new" else "/catalogue/products/${id ?: 0}"
            Categories -> "/catalogue/categories"
            Pos -> "/pos"
            is Orders -> when (channel) {
                OrderChannel.TILL -> "/sales/pos-orders"
                OrderChannel.WEB -> "/sales/online-orders"
                OrderChannel.CHAT -> "/sales/whatsapp-orders"
                OrderChannel.QUOTED -> "/sales/quoted-orders"
                null -> "/sales/orders"
            }
            is Order -> "/sales/orders/$id"
            PendingQueue -> "/sales/pending-queue"
            Quotations -> "/sales/quotations"
            Invoices -> "/sales/invoices"
            InterestCarts -> "/sales/interest-carts"
            Shipments -> "/sales/shipments"
            is Shipment -> "/sales/shipments/$id"
            Customers -> "/sales/customers"
            is Customer -> "/sales/customers/$id"
            Balances -> "/pos/outstanding-balances"
            Tills -> "/pos/tills"
            EodReports -> "/pos/eod-reports"
            EodSettings -> "/pos/eod-settings"
            is Handoff -> "/handoff/orders/$id"
            ProductionMyTasks -> "/production/my-tasks"
            ProductionOrders -> "/production/orders"
            is ProductionOrder -> "/production/orders/$id"
            Wip -> "/production/wip"
            Qc -> "/production/qc"
            ProductionCalendar -> "/production/calendar"
            Bom -> "/production/bom"
            StockLevels -> "/inventory/stock-levels"
            Serials -> "/inventory/serials"
            Adjustments -> "/inventory/adjustments"
            is Adjustment -> "/inventory/adjustments/$id"
            Transfers -> "/inventory/transfers"
            is Transfer -> "/inventory/transfers/$id"
            Materials -> "/inventory/materials"
            LowStock -> "/inventory/low-stock"
            PurchaseOrders -> "/procurement/purchase-orders"
            is PurchaseOrder -> "/procurement/purchase-orders/$id"
            Suppliers -> "/procurement/suppliers"
            GoodsReceipt -> "/procurement/goods-receipt"
            is Grn -> "/procurement/goods-receipt/$id"
            PurchaseReturns -> "/procurement/returns"
            is PurchaseReturn -> "/procurement/returns/$id"
            Expenses -> "/expenses"
            is Expense -> "/expenses/$id"
            Imprest -> "/expenses/imprest"
            ExpenseCategories -> "/expenses/settings"
            ExpenseAnalytics -> "/expenses/analytics"
            Seasons -> "/marketing/seasons"
            Campaigns -> "/marketing/campaigns"
            HomeFront -> "/home-front/home"
            HomeFrontProducts -> "/home-front/products"
            Insights -> "/insights"
            PaymentTransactions -> "/finance/transactions"
            is Report -> when (page) {
                ReportPage.EXECUTIVE -> "/reports"
                ReportPage.FINANCE -> "/reports/finance"
                ReportPage.DATA_QUALITY -> "/reports/data-quality"
                else -> "/reports/${page.wire}"
            }
            SetupBusiness -> "/settings/business"
            Outlets -> "/settings/outlets"
            AttendanceRules -> "/settings/attendance-rules"
            Automations -> "/settings/automations"
            Holidays -> "/settings/holidays"
            Roles -> "/settings/roles"
            Users -> "/settings/users"
            Countries -> "/settings/countries"
            Currencies -> "/settings/currencies"
            Languages -> "/settings/languages"
            TaxRates -> "/settings/taxes"
            DiscountLimits -> "/settings/discount-limits"
            PaymentMethods -> "/settings/payment-methods"
            Shipping -> "/settings/shipping"
            ProductionStages -> "/settings/production/stages"
            AutoAssignees -> "/settings/production/auto-assignees"
            ActivityLogs -> "/settings/activity-logs"
            Database -> "/settings/database"
            Trash -> "/settings/trash"
            Downloads -> "/settings/downloads"
            MyDownloads -> "/settings/my-downloads"
            Kiosk -> "/kiosk"
        }

    /** The sidebar entry a detail route belongs under (null when it is itself an entry). */
    val parent: Route?
        get() = when (this) {
            is Order, is Handoff -> Orders()
            is Shipment -> Shipments
            is Customer -> Customers
            is ProductionOrder -> ProductionOrders
            is Adjustment -> Adjustments
            is Transfer -> Transfers
            is PurchaseOrder -> PurchaseOrders
            is Grn -> GoodsReceipt
            is PurchaseReturn -> PurchaseReturns
            is Expense -> Expenses
            is Product -> if (id != null || new) Products else null
            is People -> if (id != null) People() else null
            is Updates -> if (id != null) Updates() else null
            is Handbook -> if (id != null) Handbook() else null
            is Courses -> if (id != null) Courses() else null
            is Sign -> if (id != null) Sign() else null
            is Comms -> if (channelId != null) Comms() else null
            else -> null
        }

    companion object {
        /** Parses a console path (push `data.url`, App Link, pasted Hub link) back into a route. */
        fun fromConsolePath(raw: String): Route? {
            var path = raw.substringBefore('?')
            if (path.startsWith("/admin")) path = path.removePrefix("/admin")
            if (path.isEmpty()) path = "/"
            NavCatalogue.routeByPath[path]?.let { return it }
            val parts = path.split('/').filter { it.isNotEmpty() }
            fun id(i: Int): Int? = parts.getOrNull(i)?.toIntOrNull()
            return when (parts.firstOrNull()) {
                "comms" -> Comms(id(1))
                "updates" -> Updates(id(1))
                "handbook" -> Handbook(id(1))
                "courses" -> Courses(id(1))
                "me" -> if (parts.getOrNull(1) == "sign") Sign(id(2)) else null
                "people" -> id(1)?.let { People(it) }
                "catalogue" -> if (parts.size > 2 && parts[1] == "products") (if (parts[2] == "new") Product(new = true) else id(2)?.let { Product(it) }) else null
                "sales" -> when (parts.getOrNull(1)) {
                    "orders" -> id(2)?.let { Order(it) }
                    "shipments" -> id(2)?.let { Shipment(it) }
                    "customers" -> id(2)?.let { Customer(it) }
                    else -> null
                }
                "handoff" -> id(2)?.let { Handoff(it) }
                "production" -> if (parts.getOrNull(1) == "orders") id(2)?.let { ProductionOrder(it) } else null
                "inventory" -> when (parts.getOrNull(1)) {
                    "adjustments" -> id(2)?.let { Adjustment(it) }
                    "transfers" -> id(2)?.let { Transfer(it) }
                    else -> null
                }
                "procurement" -> when (parts.getOrNull(1)) {
                    "purchase-orders" -> id(2)?.let { PurchaseOrder(it) }
                    "goods-receipt" -> id(2)?.let { Grn(it) }
                    "returns" -> id(2)?.let { PurchaseReturn(it) }
                    else -> null
                }
                "expenses" -> id(1)?.let { Expense(it) }
                "reports" -> when (parts.getOrNull(1)) {
                    "financial" -> Report(ReportPage.FINANCE)
                    "order-pipeline" -> Report(ReportPage.SALES)
                    "signals" -> Report(ReportPage.SIGNALS)
                    else -> null
                }
                "intelligence" -> if (parts.size > 1) Report(ReportPage.CUSTOMERS) else Report(ReportPage.SIGNALS)
                else -> null
            }
        }
    }
}

/** A sidebar entry: label, route and the gate the API checks for it. `icon` is a Material icon name. */
data class NavItem(val label: String, val route: Route, val gate: NavGate = NavGate.OPEN, val icon: String)

data class NavGroup(val label: String, val items: List<NavItem>) {
    fun visible(subject: PermissionSet): NavGroup? = items.filter { it.gate.allows(subject) }.takeIf { it.isNotEmpty() }?.let { NavGroup(label, it) }
}

/**
 * The sidebar, ported one to one from react-admin/src/components/layout/Sidebar.tsx
 * (and the Apple NavCatalogue). PermissionsTest pins it to the same slugs.
 */
object NavCatalogue {
    val approvalsGate = NavGate(anyOf = listOf(
        "procurement.approve", "inventory.approve", "expenses.approve", "approvals.finance_sign",
        "payments.void", "payments.reassign", "payments.approve_international", "payments.request_void", "payments.request_reassign",
        "procurement.create", "inventory.adjust", "inventory.transfer", "expenses.create", "products.edit", "products.edit_cost",
        "orders.set_deposit", "settings.financial_propose", "settings.pricing_rate_propose",
    ))

    private fun p(s: String) = NavGate.perm(s)

    val groups: List<NavGroup> = listOf(
        NavGroup("Workspace", listOf(
            NavItem("Dashboard", Route.Dashboard, p("dashboard.view"), "Dashboard"),
            NavItem("Approvals", Route.Approvals, approvalsGate, "Verified"),
            NavItem("Notifications", Route.Notifications, p("notifications.view"), "Notifications"),
            NavItem("Messages", Route.Comms(), icon = "Forum"),
        )),
        NavGroup("Me", listOf(
            NavItem("Today", Route.Me, icon = "WbSunny"),
            NavItem("Updates", Route.Updates(), icon = "Campaign"),
            NavItem("Checklists", Route.Forms, icon = "Checklist"),
            NavItem("Handbook", Route.Handbook(), icon = "MenuBook"),
            NavItem("Training", Route.Courses(), icon = "School"),
            NavItem("To sign", Route.Sign(), icon = "Draw"),
            NavItem("To-dos", Route.Todos, icon = "CheckCircle"),
            NavItem("Colleagues", Route.Colleagues, icon = "Groups"),
            NavItem("Clock in", Route.Clock, icon = "Schedule"),
            NavItem("My shifts", Route.MyShifts, icon = "CalendarMonth"),
            NavItem("My leave", Route.MyLeave, icon = "BeachAccess"),
            NavItem("My hours", Route.MyTimesheet, icon = "HourglassBottom"),
            NavItem("My payslips", Route.MyPayslips, icon = "Description"),
            NavItem("My advances", Route.MyAdvances, icon = "Payments"),
        )),
        NavGroup("People", listOf(
            NavItem("Overview", Route.PeopleOverview, p("attendance.view_team"), "Groups"),
            NavItem("Requests", Route.PeopleRequests, p("attendance.view_team"), "Inbox"),
            NavItem("Attendance", Route.Attendance, p("attendance.view_team"), "LocationOn"),
            NavItem("Checklists", Route.FormsManage, p("forms.manage"), "FactCheck"),
            NavItem("Roster", Route.Roster, p("attendance.view_team"), "EventNote"),
            NavItem("Timesheets", Route.Timesheets, p("attendance.view_team"), "TableChart"),
            NavItem("Leave", Route.Leave, p("attendance.view_team"), "Flight"),
            NavItem("Employee files", Route.People(), p("hr.view"), "FolderShared"),
            NavItem("Payroll", Route.Payroll, p("payroll.view"), "AccountBalance"),
        )),
        NavGroup("Catalog", listOf(
            NavItem("Products", Route.Products, p("products.view"), "Inventory2"),
            NavItem("Categories", Route.Categories, p("products.view"), "Category"),
        )),
        NavGroup("Sales", listOf(
            NavItem("POS", Route.Pos, p("pos.access"), "PointOfSale"),
            NavItem("Till Sales", Route.Orders(Route.OrderChannel.TILL), p("orders.view"), "Storefront"),
            NavItem("Web Orders", Route.Orders(Route.OrderChannel.WEB), p("orders.view"), "Language"),
            NavItem("Chat Orders", Route.Orders(Route.OrderChannel.CHAT), p("orders.view"), "Chat"),
            NavItem("Quoted Sales", Route.Orders(Route.OrderChannel.QUOTED), p("orders.view"), "RequestQuote"),
            NavItem("Pending Queue", Route.PendingQueue, p("orders.view"), "HourglassBottom"),
            NavItem("Quotations", Route.Quotations, p("quotations.view"), "RequestQuote"),
            NavItem("Invoices", Route.Invoices, p("orders.view"), "Receipt"),
            NavItem("Interest Carts", Route.InterestCarts, p("orders.view"), "ShoppingCart"),
            NavItem("Shipments", Route.Shipments, p("shipment.view"), "LocalShipping"),
            NavItem("Customers", Route.Customers, p("customers.view"), "People"),
            NavItem("Balances", Route.Balances, p("receivables.view"), "CreditCard"),
            NavItem("Tills", Route.Tills, NavGate.anyOf("pos.access", "pos.tills_view_all", "pos.reconcile", "pos.till_correction"), "Inbox"),
            NavItem("EoD Reports", Route.EodReports, p("pos.eod_review"), "NightsStay"),
            NavItem("EoD Settings", Route.EodSettings, NavGate.allOf("pos.access", "settings.edit"), "Settings"),
        )),
        NavGroup("Production", listOf(
            NavItem("My Tasks", Route.ProductionMyTasks, p("production.worker"), "ContentCut"),
            NavItem("Production Orders", Route.ProductionOrders, p("production.view"), "Construction"),
            NavItem("Work In Progress", Route.Wip, p("production.view"), "ViewColumn"),
            NavItem("Quality Control", Route.Qc, NavGate.anyOf("production.submit_qc", "production.approve_qc"), "VerifiedUser"),
            NavItem("Calendar", Route.ProductionCalendar, p("production.view"), "CalendarMonth"),
            NavItem("Bill of Materials", Route.Bom, p("production.view_bom"), "ListAlt"),
        )),
        NavGroup("Inventory", listOf(
            NavItem("Stock Levels", Route.StockLevels, p("inventory.view"), "BarChart"),
            NavItem("Product Serials", Route.Serials, p("inventory.view"), "QrCode"),
            NavItem("Stock Adjustments", Route.Adjustments, p("inventory.view"), "Tune"),
            NavItem("Stock Transfers", Route.Transfers, p("inventory.view"), "SwapHoriz"),
            NavItem("Raw Materials", Route.Materials, p("inventory.view"), "ViewInAr"),
            NavItem("Low Stock Alerts", Route.LowStock, p("inventory.view"), "Warning"),
        )),
        NavGroup("Procurement", listOf(
            NavItem("Purchase Orders", Route.PurchaseOrders, p("procurement.view"), "Assignment"),
            NavItem("Suppliers", Route.Suppliers, p("procurement.view"), "Business"),
            NavItem("Goods Receipt", Route.GoodsReceipt, p("procurement.view"), "MoveToInbox"),
            NavItem("Purchase Returns", Route.PurchaseReturns, p("procurement.view"), "Undo"),
        )),
        NavGroup("Expenses", listOf(
            NavItem("Expenses", Route.Expenses, p("expenses.view"), "ReceiptLong"),
            NavItem("Imprest", Route.Imprest, p("expenses.view"), "AccountBalanceWallet"),
            NavItem("Categories", Route.ExpenseCategories, p("expenses.budgets"), "Label"),
            NavItem("Analytics", Route.ExpenseAnalytics, p("expenses.view"), "PieChart"),
        )),
        NavGroup("Marketing", listOf(
            NavItem("Seasons", Route.Seasons, p("marketing.view"), "Eco"),
            NavItem("Campaigns", Route.Campaigns, p("marketing.view"), "Email"),
        )),
        NavGroup("Home Front Customization", listOf(
            NavItem("Home Page", Route.HomeFront, p("marketing.view"), "Home"),
            NavItem("Product Pages", Route.HomeFrontProducts, p("marketing.view"), "ViewQuilt"),
        )),
        NavGroup("Insights", listOf(
            NavItem("Storefront Insights", Route.Insights, p("reports.customers"), "Public"),
        )),
        NavGroup("Finance", listOf(
            NavItem("Payment Transactions", Route.PaymentTransactions, p("payments.transactions"), "ListAlt"),
        )),
        NavGroup("Reports", Route.ReportPage.entries.map { NavItem(it.title, Route.Report(it), p(it.permission), "ShowChart") }),
        NavGroup("Setup", listOf(
            NavItem("Business", Route.SetupBusiness, p("settings.view"), "Apartment"),
            NavItem("Outlets", Route.Outlets, p("outlets.view"), "Place"),
            NavItem("Attendance rules", Route.AttendanceRules, p("attendance.view_team"), "Rule"),
            NavItem("Automations", Route.Automations, p("attendance.view_team"), "Bolt"),
            NavItem("Holidays", Route.Holidays, icon = "Celebration"),
            NavItem("Roles", Route.Roles, p("roles.view"), "Key"),
            NavItem("Users", Route.Users, p("users.view"), "PersonAdd"),
            NavItem("Countries", Route.Countries, NavGate.anyOf("settings.view", "setup.technical"), "Flag"),
            NavItem("Currencies", Route.Currencies, NavGate.anyOf("settings.view", "settings.financial_propose", "settings.pricing_rate_propose"), "CurrencyExchange"),
            NavItem("Languages", Route.Languages, NavGate.anyOf("settings.view", "setup.technical"), "Translate"),
            NavItem("Tax Rates", Route.TaxRates, NavGate.anyOf("settings.view", "settings.financial_propose"), "Percent"),
            NavItem("Discount limits", Route.DiscountLimits, NavGate.SUPER_ADMIN, "LocalOffer"),
            NavItem("Payment Methods", Route.PaymentMethods, NavGate.anyOf("settings.view", "settings.financial_propose"), "CreditCard"),
            NavItem("Shipping", Route.Shipping, NavGate.anyOf("settings.view", "setup.technical"), "LocalShipping"),
            NavItem("Production Stages", Route.ProductionStages, p("production.configure_auto_assignees"), "Layers"),
            NavItem("Auto-Assignees", Route.AutoAssignees, p("production.configure_auto_assignees"), "ManageAccounts"),
            NavItem("Activity Logs", Route.ActivityLogs, NavGate.SUPER_ADMIN, "History"),
            NavItem("Database", Route.Database, NavGate.SUPER_ADMIN, "Storage"),
            NavItem("Trash", Route.Trash, p("settings.manage"), "Delete"),
        )),
    )

    /** User-menu pages that are not in the sidebar (Topbar.tsx). */
    val userMenu: List<NavItem> = listOf(
        NavItem("My profile", Route.Profile, icon = "AccountCircle"),
        NavItem("My downloads", Route.MyDownloads, icon = "Download"),
        NavItem("Download approvals", Route.Downloads, icon = "Verified"),
    )

    val allItems: List<NavItem> = groups.flatMap { it.items } + userMenu

    val routeByPath: Map<String, Route> = buildMap {
        allItems.forEach { put(it.route.consolePath, it.route) }
        put("/", Route.Dashboard)
        put("/sales/orders", Route.Orders())
        put("/reports/financial", Route.Report(Route.ReportPage.FINANCE))
        put("/kiosk", Route.Kiosk)
    }

    fun item(route: Route): NavItem? = allItems.firstOrNull { it.route == route }

    /** The phone's bottom bar (BottomTabBar.tsx): Home, Messages, Me, Production, Orders (+ More). */
    fun phoneTabs(subject: PermissionSet, home: Route): List<NavItem> {
        val tabs = mutableListOf(NavItem("Home", home, icon = "Home"), NavItem("Messages", Route.Comms(), icon = "Forum"), NavItem("Me", Route.Me, icon = "Person"))
        if (subject.isFloorWorker) tabs[0] = NavItem("My Tasks", Route.ProductionMyTasks, icon = "ContentCut")
        else if (subject.can("production.view")) tabs += NavItem("Production", Route.ProductionOrders, icon = "Construction")
        if (subject.can("orders.view")) tabs += NavItem("Orders", Route.Orders(Route.OrderChannel.WEB), icon = "ShoppingBag")
        return tabs
    }
}
