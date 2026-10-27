import Foundation

/// One rule for "may this person reach this page", ported from
/// react-admin/src/lib/navGate.ts (Role Hardening Plan §16): navigation
/// follows permission. A menu item, its route and its command-palette entry
/// carry the same slug(s) the API checks, never a role name. The one
/// exception is a page whose API is itself role-gated (`role:super_admin`):
/// those say `superAdminOnly`.
///
/// Hiding a menu item is a convenience, not the control: the API enforces
/// every one of these independently.
public struct NavGate: Hashable, Sendable {
    public var permission: String?
    public var anyOfPermissions: [String]
    public var allOfPermissions: [String]
    public var superAdminOnly: Bool

    public init(permission: String? = nil, anyOf: [String] = [], allOf: [String] = [], superAdminOnly: Bool = false) {
        self.permission = permission
        self.anyOfPermissions = anyOf
        self.allOfPermissions = allOf
        self.superAdminOnly = superAdminOnly
    }

    public static let open = NavGate()
    public static func perm(_ p: String) -> NavGate { NavGate(permission: p) }
    public static func anyOf(_ p: [String]) -> NavGate { NavGate(anyOf: p) }
    public static func allOf(_ p: [String]) -> NavGate { NavGate(allOf: p) }
    public static let superAdmin = NavGate(superAdminOnly: true)

    public var isOpen: Bool { permission == nil && anyOfPermissions.isEmpty && allOfPermissions.isEmpty && !superAdminOnly }

    public func allows(_ subject: GateSubject) -> Bool {
        if subject.isSuperAdmin { return true }
        if superAdminOnly { return false }
        if let p = permission, !subject.can(p) { return false }
        if !anyOfPermissions.isEmpty, !anyOfPermissions.contains(where: subject.can) { return false }
        if !allOfPermissions.isEmpty, !allOfPermissions.allSatisfy(subject.can) { return false }
        return true
    }
}

public protocol GateSubject {
    func can(_ permission: String) -> Bool
    var isSuperAdmin: Bool { get }
}

/// The effective permission set of the signed-in person.
public struct PermissionSet: GateSubject, Hashable, Sendable {
    public let permissions: Set<String>
    public let isSuperAdmin: Bool

    public init(permissions: [String], isSuperAdmin: Bool) {
        self.permissions = Set(permissions)
        self.isSuperAdmin = isSuperAdmin
    }

    public init(me: Me) {
        self.init(permissions: me.permissions, isSuperAdmin: me.isSuperAdmin)
    }

    public static let none = PermissionSet(permissions: [], isSuperAdmin: false)

    public func can(_ permission: String) -> Bool { isSuperAdmin || permissions.contains(permission) }
    public func canAny(_ list: [String]) -> Bool { list.contains(where: can) }
    public func canAll(_ list: [String]) -> Bool { list.allSatisfy(can) }

    /// Works the floor rather than running it (react-admin/src/lib/homePath.ts):
    /// holds the My Tasks workspace but neither of the keys that mean "runs the floor".
    public var isFloorWorker: Bool {
        if isSuperAdmin { return false }
        return can("production.worker") && !can("production.manage_assignees") && !can("production.confirm_order")
    }
}

/// "Home" for the signed-in person — the first page they can actually use,
/// decided by permission, never by role name (owner-approved order, 2026-10-05):
///   1. a floor worker → My Tasks
///   2. anyone with the dashboard → Dashboard
///   3. otherwise the first sidebar page their permissions open
///   4. otherwise their own profile, which every signed-in user may open
public enum HomePath {
    public static func resolve(for subject: PermissionSet, nav: [NavGroup] = NavCatalogue.groups) -> Route {
        if subject.isFloorWorker { return .productionMyTasks }
        if subject.isSuperAdmin || subject.can("dashboard.view") { return .dashboard }
        for group in nav {
            for item in group.items where item.gate.allows(subject) {
                return item.route
            }
        }
        return .profile
    }
}
