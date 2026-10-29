package ke.co.bethanyhouse.hub.core

/**
 * One rule for "may this person reach this page", ported from
 * react-admin/src/lib/navGate.ts (Role Hardening Plan §16): navigation
 * follows permission. A menu item, its route and its command-palette entry
 * carry the same slug(s) the API checks, never a role name. The one
 * exception is a page whose API is itself role-gated (`role:super_admin`):
 * those say `superAdminOnly`.
 *
 * Hiding a menu item is a convenience, not the control: the API enforces
 * every one of these independently.
 */
data class NavGate(
    val permission: String? = null,
    val anyOf: List<String> = emptyList(),
    val allOf: List<String> = emptyList(),
    val superAdminOnly: Boolean = false,
) {
    val isOpen: Boolean get() = permission == null && anyOf.isEmpty() && allOf.isEmpty() && !superAdminOnly

    fun allows(subject: PermissionSet): Boolean {
        if (subject.isSuperAdmin) return true
        if (superAdminOnly) return false
        if (permission != null && !subject.can(permission)) return false
        if (anyOf.isNotEmpty() && anyOf.none(subject::can)) return false
        if (allOf.isNotEmpty() && !allOf.all(subject::can)) return false
        return true
    }

    companion object {
        val OPEN = NavGate()
        val SUPER_ADMIN = NavGate(superAdminOnly = true)
        fun perm(p: String) = NavGate(permission = p)
        fun anyOf(vararg p: String) = NavGate(anyOf = p.toList())
        fun allOf(vararg p: String) = NavGate(allOf = p.toList())
    }
}

/** The effective permission set of the signed-in person. */
data class PermissionSet(val permissions: Set<String>, val isSuperAdmin: Boolean) {
    fun can(permission: String) = isSuperAdmin || permission in permissions
    fun canAny(list: List<String>) = list.any(::can)
    fun canAll(list: List<String>) = list.all(::can)

    /** Works the floor rather than running it (homePath.ts). */
    val isFloorWorker: Boolean
        get() = !isSuperAdmin && can("production.worker") && !can("production.manage_assignees") && !can("production.confirm_order")

    companion object {
        val NONE = PermissionSet(emptySet(), false)
        fun of(me: Me) = PermissionSet(me.permissions.toSet(), me.isSuperAdmin)
    }
}

/**
 * "Home" for the signed-in person — the first page they can actually use,
 * decided by permission, never by role name (owner-approved order, 2026-10-05):
 *   1. a floor worker → My Tasks
 *   2. anyone with the dashboard → Dashboard
 *   3. otherwise the first sidebar page their permissions open
 *   4. otherwise their own profile, which every signed-in user may open
 */
object HomePath {
    fun resolve(subject: PermissionSet, nav: List<NavGroup> = NavCatalogue.groups): Route {
        if (subject.isFloorWorker) return Route.ProductionMyTasks
        if (subject.isSuperAdmin || subject.can("dashboard.view")) return Route.Dashboard
        for (group in nav) for (item in group.items) if (item.gate.allows(subject)) return item.route
        return Route.Profile
    }
}
