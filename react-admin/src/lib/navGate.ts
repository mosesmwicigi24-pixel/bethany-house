/**
 * One rule for "may this user reach this page", shared by the Sidebar, the
 * route guards in App.tsx (ProtectedRoute) and the command palette.
 *
 * Role Hardening Plan §16: navigation follows permission. A menu item, its
 * route guard and its palette entry carry the SAME slug(s) the backend route
 * checks — never a role name. The one exception is a page whose API is itself
 * role-gated (`role:super_admin` on Activity Logs and Database Management):
 * those say `superAdminOnly`, here and nowhere else.
 *
 * `npm run check:nav` (scripts/check-nav-gates.mjs) fails when the three
 * disagree for any href. Hiding a menu item is a convenience, not the control:
 * the API enforces every one of these independently.
 */
export interface NavGate {
    /** A single permission the API checks. */
    permission?: string;
    /** Any of these (OR) — for a page that serves several roles' queues. */
    anyOfPermissions?: string[];
    /** All of these (AND) — for a page whose API sits inside two gates. */
    allOfPermissions?: string[];
    /** The API is `role:super_admin`; no permission can grant it. */
    superAdminOnly?: boolean;
}

export interface GateSubject {
    can: (permission: string) => boolean;
    isSuperAdmin: boolean;
}

export function gateAllows(gate: NavGate, { can, isSuperAdmin }: GateSubject): boolean {
    if (isSuperAdmin) return true;
    if (gate.superAdminOnly) return false;
    if (gate.permission && !can(gate.permission)) return false;
    if (gate.anyOfPermissions?.length && !gate.anyOfPermissions.some((p) => can(p))) return false;
    if (gate.allOfPermissions?.length && !gate.allOfPermissions.every((p) => can(p))) return false;
    return true;
}
