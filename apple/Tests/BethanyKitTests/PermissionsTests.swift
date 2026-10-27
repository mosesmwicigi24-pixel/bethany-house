import XCTest
@testable import BethanyKit

final class PermissionsTests: XCTestCase {
    func testGateAllowsExactlyAsTheConsole() {
        let admin = PermissionSet(permissions: ["orders.view", "pos.access"], isSuperAdmin: false)
        let owner = PermissionSet(permissions: [], isSuperAdmin: true)
        let nobody = PermissionSet.none

        XCTAssertTrue(NavGate.open.allows(nobody))
        XCTAssertTrue(NavGate.perm("orders.view").allows(admin))
        XCTAssertFalse(NavGate.perm("payroll.view").allows(admin))
        XCTAssertTrue(NavGate.anyOf(["payroll.view", "pos.access"]).allows(admin))
        XCTAssertFalse(NavGate.allOf(["orders.view", "settings.edit"]).allows(admin))
        XCTAssertTrue(NavGate.allOf(["orders.view", "pos.access"]).allows(admin))
        XCTAssertFalse(NavGate.superAdmin.allows(admin), "no permission can grant a role-gated page")
        XCTAssertTrue(NavGate.superAdmin.allows(owner))
        XCTAssertTrue(NavGate.perm("anything").allows(owner), "super admin passes every gate")
    }

    func testFloorWorkerIsAWorkerWhoDoesNotRunTheFloor() {
        XCTAssertTrue(PermissionSet(permissions: ["production.worker", "production.view"], isSuperAdmin: false).isFloorWorker)
        XCTAssertFalse(PermissionSet(permissions: ["production.worker", "production.confirm_order"], isSuperAdmin: false).isFloorWorker)
        XCTAssertFalse(PermissionSet(permissions: ["production.worker", "production.manage_assignees"], isSuperAdmin: false).isFloorWorker)
        XCTAssertFalse(PermissionSet(permissions: ["production.worker"], isSuperAdmin: true).isFloorWorker)
    }

    func testHomePathFollowsTheOwnerApprovedOrder() {
        XCTAssertEqual(HomePath.resolve(for: PermissionSet(permissions: ["production.worker"], isSuperAdmin: false)), .productionMyTasks)
        XCTAssertEqual(HomePath.resolve(for: PermissionSet(permissions: ["dashboard.view", "production.worker", "production.confirm_order"], isSuperAdmin: false)), .dashboard)
        XCTAssertEqual(HomePath.resolve(for: PermissionSet(permissions: [], isSuperAdmin: true)), .dashboard)
        // No dashboard: the first sidebar page the permissions open — Approvals needs any approval slug; Messages is open to all.
        XCTAssertEqual(HomePath.resolve(for: PermissionSet(permissions: ["orders.view"], isSuperAdmin: false)), .comms())
        XCTAssertEqual(HomePath.resolve(for: PermissionSet(permissions: ["expenses.approve"], isSuperAdmin: false)), .approvals)
    }

    func testSidebarGatesMatchTheConsoleSlugs() {
        // A handful pinned verbatim from react-admin/src/components/layout/Sidebar.tsx.
        func gate(_ route: Route) -> NavGate { NavCatalogue.item(for: route)!.gate }
        XCTAssertEqual(gate(.dashboard), .perm("dashboard.view"))
        XCTAssertEqual(gate(.pos), .perm("pos.access"))
        XCTAssertEqual(gate(.tills), .anyOf(["pos.access", "pos.tills_view_all", "pos.reconcile", "pos.till_correction"]))
        XCTAssertEqual(gate(.eodSettings), .allOf(["pos.access", "settings.edit"]))
        XCTAssertEqual(gate(.qc), .anyOf(["production.submit_qc", "production.approve_qc"]))
        XCTAssertEqual(gate(.discountLimits), .superAdmin)
        XCTAssertEqual(gate(.database), .superAdmin)
        XCTAssertEqual(gate(.holidays), .open)
        XCTAssertEqual(gate(.comms()), .open)
        XCTAssertEqual(gate(.report(.finance)), .perm("reports.financial"))
        XCTAssertEqual(gate(.report(.dataQuality)), .perm("reports.data_quality"))
        XCTAssertEqual(gate(.currencies), .anyOf(["settings.view", "settings.financial_propose", "settings.pricing_rate_propose"]))
        XCTAssertEqual(NavCatalogue.approvalsGate.anyOfPermissions.count, 18)
    }

    func testEveryMeItemIsOpenToEverySignedInPerson() {
        let me = NavCatalogue.groups.first { $0.label == "Me" }!
        XCTAssertEqual(me.items.count, 14)
        XCTAssertTrue(me.items.allSatisfy { $0.gate.isOpen })
    }

    func testPhoneTabsFollowBottomTabBar() {
        let clerk = PermissionSet(permissions: ["orders.view"], isSuperAdmin: false)
        XCTAssertEqual(NavCatalogue.phoneTabs(for: clerk, home: .dashboard).map(\.label), ["Home", "Messages", "Me", "Orders"])
        let tailor = PermissionSet(permissions: ["production.worker", "production.view"], isSuperAdmin: false)
        XCTAssertEqual(NavCatalogue.phoneTabs(for: tailor, home: .productionMyTasks).map(\.label), ["My Tasks", "Messages", "Me"])
        let manager = PermissionSet(permissions: ["production.view", "production.confirm_order", "orders.view"], isSuperAdmin: false)
        XCTAssertEqual(NavCatalogue.phoneTabs(for: manager, home: .dashboard).map(\.label), ["Home", "Messages", "Me", "Production", "Orders"])
    }
}
