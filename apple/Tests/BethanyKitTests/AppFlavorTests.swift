import XCTest
@testable import BethanyKit

/// Tailors Hub (owner, 2026-11-23): workshop staff only; production, own time
/// and pay, messages, updates, training, profile. Expected lists are written
/// out by hand, not derived from TailorsHub.allows. Same cases as Android's
/// AppFlavorTest.
final class AppFlavorTests: XCTestCase {
    private func person(_ p: String...) -> PermissionSet { PermissionSet(permissions: p, isSuperAdmin: false) }
    private lazy var tailor = person("production.worker", "production.view")
    private lazy var inspector = person("production.submit_qc", "production.view")
    private lazy var manager = person("production.view", "production.manage_assignees", "production.confirm_order", "production.worker")
    private lazy var cashier = person("pos.access", "orders.view", "customers.view")
    private lazy var finance = person("reports.financial", "payments.transactions", "expenses.view")
    private let owner = PermissionSet(permissions: [], isSuperAdmin: true)

    func testWorkshopStaffMayUseTailorsHubOthersMayNot() {
        XCTAssertTrue(AppFlavor.tailors.mayUse(tailor))
        XCTAssertTrue(AppFlavor.tailors.mayUse(inspector))
        XCTAssertTrue(AppFlavor.tailors.mayUse(manager))
        XCTAssertTrue(AppFlavor.tailors.mayUse(person("production.approve_qc")))
        XCTAssertFalse(AppFlavor.tailors.mayUse(cashier))
        XCTAssertFalse(AppFlavor.tailors.mayUse(finance))
        XCTAssertFalse(AppFlavor.tailors.mayUse(.none))
        XCTAssertTrue(AppFlavor.tailors.mayUse(owner), "the owner can open it to test")
        XCTAssertTrue(AppFlavor.hub.mayUse(cashier))
    }

    func testTailorsHubAllowsWorkshopWorkdayAndPeopleRoutes() {
        let shown: [Route] = [
            .productionMyTasks, .productionOrders, .productionOrder(id: 7), .wip, .qc, .productionCalendar,
            .clock, .myShifts, .myLeave, .myTimesheet, .myPayslips,
            .me, .forms, .handbook(), .handbook(id: 2), .sign(), .sign(id: 3),
            .todos, .colleagues, .myAdvances,
            .peopleOverview, .peopleRequests, .attendance, .formsManage, .roster, .timesheets, .leave, .people(), .people(id: 5), .payroll,
            .comms(), .comms(channelId: 3), .updates(), .updates(id: 2), .courses(), .courses(id: 4), .profile,
        ]
        for r in shown { XCTAssertTrue(AppFlavor.tailors.allows(r), "\(r) should be in Tailors Hub") }
        let hidden: [Route] = [
            .dashboard, .pos, .orders(), .order(id: 1), .customers, .customer(id: 1), .balances, .tills,
            .bom, .stockLevels, .materials, .purchaseOrders, .expenses, .paymentTransactions,
            .report(.finance), .report(.production), .users, .roles,
            .approvals, .notifications, .downloads, .myDownloads,
        ]
        for r in hidden {
            XCTAssertFalse(AppFlavor.tailors.allows(r), "\(r) must not be in Tailors Hub")
            XCTAssertTrue(AppFlavor.hub.allows(r), "Bethany Hub keeps \(r)")
        }
    }

    func testEveryoneLandsOnTheirOwnWorkshopPage() {
        XCTAssertEqual(AppFlavor.tailors.home(for: person("production.worker")), .productionMyTasks)
        XCTAssertEqual(AppFlavor.tailors.home(for: tailor), .productionMyTasks)
        XCTAssertEqual(AppFlavor.tailors.home(for: manager), .productionOrders)
        XCTAssertEqual(AppFlavor.tailors.home(for: person("production.submit_qc")), .qc)
        XCTAssertEqual(AppFlavor.tailors.home(for: owner), .productionOrders)
        XCTAssertEqual(AppFlavor.hub.home(for: cashier), HomePath.resolve(for: cashier), "Bethany Hub's home is unchanged")
    }

    func testTheMenuNeverOffersAScreenOutsideTailorsHub() {
        let labels = AppFlavor.tailors.groups(for: owner).flatMap { g in g.items.map { "\(g.label)/\($0.label)" } }
        XCTAssertEqual(labels, [
            "Workspace/Messages",
            "Me/Today", "Me/Updates", "Me/Checklists", "Me/Handbook", "Me/Training", "Me/To sign", "Me/To-dos", "Me/Colleagues", "Me/Clock in", "Me/My shifts", "Me/My leave", "Me/My hours", "Me/My payslips", "Me/My advances",
            "People/Overview", "People/Requests", "People/Attendance", "People/Checklists", "People/Roster", "People/Timesheets", "People/Leave", "People/Employee files", "People/Payroll",
            "Production/My Tasks", "Production/Production Orders", "Production/Work In Progress", "Production/Quality Control", "Production/Calendar",
        ])
        XCTAssertEqual(AppFlavor.tailors.userMenu.map(\.label), ["My profile"])
        XCTAssertTrue(AppFlavor.tailors.searchable(for: owner).allSatisfy { TailorsHub.allows($0.route) })
        XCTAssertFalse(AppFlavor.tailors.searchable(for: owner).contains { $0.route == .pos })
    }

    func testPeopleManagementMenuFollowsHubPermissions() {
        let workerGroups = AppFlavor.tailors.groups(for: tailor)
        XCTAssertFalse(workerGroups.contains { $0.label == "People" })
        let managerItems = AppFlavor.tailors.groups(for: person("production.worker", "attendance.view_team"))
            .first { $0.label == "People" }?.items.map(\.route) ?? []
        XCTAssertTrue(managerItems.contains(.peopleOverview))
        XCTAssertTrue(managerItems.contains(.roster))
        XCTAssertFalse(managerItems.contains(.people()))
        XCTAssertFalse(managerItems.contains(.payroll))
    }

    func testATailorsTabsAreHerTasksCalendarMessagesAndClock() {
        let tabs = AppFlavor.tailors.phoneTabs(for: tailor, home: AppFlavor.tailors.home(for: tailor))
        XCTAssertEqual(tabs.map(\.route), [.productionMyTasks, .productionCalendar, .comms(), .clock])
        let noCalendar = AppFlavor.tailors.phoneTabs(for: person("production.worker"), home: .productionMyTasks)
        XCTAssertEqual(noCalendar.map(\.route), [.productionMyTasks, .comms(), .clock])
    }
}
