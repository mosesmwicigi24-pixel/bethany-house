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

    func testTailorsHubIsTheWorkshopTheirWorkdayAndPermittedPeopleAdmin() {
        let shown: [Route] = [
            .productionMyTasks, .productionOrders, .productionOrder(id: 7), .wip, .qc, .productionCalendar,
            .clock, .myShifts, .myLeave, .myTimesheet, .myPayslips, .myAdvances,
            .forms, .handbook(), .handbook(id: 2), .sign(), .sign(id: 3), .todos, .colleagues,
            .comms(), .comms(channelId: 3), .updates(), .updates(id: 2), .courses(), .courses(id: 4), .profile,
            // Restored (owner, 2026-11-23), each still behind its own Hub permission.
            .peopleOverview, .peopleRequests, .attendance, .formsManage, .roster, .timesheets, .leave,
            .people(), .people(id: 5), .payroll,
        ]
        for r in shown { XCTAssertTrue(AppFlavor.tailors.allows(r), "\(r) should be in Tailors Hub") }
        let hidden: [Route] = [
            .dashboard, .pos, .orders(), .order(id: 1), .customers, .customer(id: 1), .balances, .tills,
            .bom, .stockLevels, .materials, .purchaseOrders, .expenses, .paymentTransactions,
            .report(.finance), .report(.production), .users, .roles,
            .approvals, .notifications, .downloads, .myDownloads, .me,
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
            "My Workday/Updates", "My Workday/Checklists", "My Workday/Handbook", "My Workday/Training", "My Workday/To sign",
            "My Workday/To-dos", "My Workday/Colleagues", "My Workday/Clock in", "My Workday/My shifts", "My Workday/My leave",
            "My Workday/My hours", "My Workday/My payslips", "My Workday/My advances",
            "People/Overview", "People/Requests", "People/Attendance", "People/Checklists", "People/Roster",
            "People/Timesheets", "People/Leave", "People/Employee files", "People/Payroll",
            "Production/My Tasks", "Production/Production Orders", "Production/Work In Progress", "Production/Quality Control", "Production/Calendar",
        ])
        XCTAssertEqual(AppFlavor.tailors.userMenu.map(\.label), ["My profile"])
        XCTAssertTrue(AppFlavor.tailors.searchable(for: owner).allSatisfy { TailorsHub.allows($0.route) })
        XCTAssertFalse(AppFlavor.tailors.searchable(for: owner).contains { $0.route == .pos })
    }

    /// People administration, employee files and team payroll are in Tailors
    /// Hub only for those whose existing Hub permissions open them; the gates
    /// are the catalogue's own, unchanged.
    func testPeopleAdminFollowsEachPersonsHubPermissions() {
        let hrManager = person("production.view", "production.manage_assignees", "production.confirm_order",
                               "attendance.view_team", "hr.view", "payroll.view", "forms.manage")
        let teamLead = person("production.view", "production.manage_assignees", "attendance.view_team")
        func people(_ p: PermissionSet) -> [String] {
            AppFlavor.tailors.groups(for: p).first { $0.label == "People" }?.items.map(\.label) ?? []
        }
        XCTAssertEqual(people(hrManager), ["Overview", "Requests", "Attendance", "Checklists", "Roster", "Timesheets", "Leave", "Employee files", "Payroll"])
        XCTAssertEqual(people(teamLead), ["Overview", "Requests", "Attendance", "Roster", "Timesheets", "Leave"],
                       "no checklist management, employee files or payroll without forms.manage, hr.view, payroll.view")
        XCTAssertEqual(people(tailor), [], "a tailor without management rights sees no People")
        XCTAssertTrue(AppFlavor.tailors.phoneTabs(for: hrManager, home: AppFlavor.tailors.home(for: hrManager))
            .allSatisfy { ![.people(), .payroll].contains($0.route) }, "People stays in the menu, not the tab bar")
    }

    /// One employee's file is not a menu item; it needs its list's gate
    /// (hr.view). Before this, a guard that looked up the exact route found no
    /// gate for `.people(id:)` and would have let a direct link through.
    func testAnEmployeeFileNeedsHrViewEvenByDirectLink() throws {
        let file = try XCTUnwrap(Route.from(consolePath: "/people/5"))
        XCTAssertEqual(file, .people(id: 5))
        XCTAssertEqual(NavCatalogue.gate(for: file), .perm("hr.view"))
        XCTAssertEqual(NavCatalogue.gate(for: .people()), .perm("hr.view"))
        XCTAssertEqual(NavCatalogue.gate(for: .payroll), .perm("payroll.view"))
        XCTAssertEqual(NavCatalogue.gate(for: .productionOrder(id: 3)), .perm("production.view"))
        XCTAssertEqual(NavCatalogue.gate(for: .updates(id: 2))?.isOpen, true, "an update is open to staff, as its list is")

        let tailorGate = NavCatalogue.gate(for: file)!
        XCTAssertTrue(AppFlavor.tailors.allows(file))
        XCTAssertFalse(tailorGate.allows(tailor), "a tailor's direct link to an employee file is refused")
        XCTAssertFalse(tailorGate.allows(person("production.view", "attendance.view_team")), "team lead without hr.view")
        XCTAssertTrue(tailorGate.allows(person("production.view", "hr.view")))
        XCTAssertTrue(tailorGate.allows(owner))
    }

    func testTailorsTabsRespectProductionPermissions() {
        let tabs = AppFlavor.tailors.phoneTabs(for: tailor, home: AppFlavor.tailors.home(for: tailor))
        XCTAssertEqual(tabs.map(\.route), [.productionOrders, .wip, .productionMyTasks, .clock])
        let noCalendar = AppFlavor.tailors.phoneTabs(for: person("production.worker"), home: .productionMyTasks)
        XCTAssertEqual(noCalendar.map(\.route), [.productionMyTasks, .clock])
        let managerTabs = AppFlavor.tailors.phoneTabs(for: manager, home: .productionOrders)
        XCTAssertEqual(managerTabs.first?.label, "Orders")
        XCTAssertEqual(managerTabs.filter { $0.route == .productionOrders }.count, 1)
    }
}
