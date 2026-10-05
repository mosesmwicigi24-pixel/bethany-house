/**
 * "Home" for the signed-in user — the first page they can actually use.
 *
 * Every way home used to lead to /dashboard: the sign-in default, "/", an
 * unknown address, the "no access" button and the phone Home tab. A role
 * without dashboard.view was sent to a locked page whose only button led
 * back to it, and tailors landed on a dashboard instead of their work.
 *
 * Decided by permission, never by role name (navigation follows permission,
 * Role Hardening Plan §16), in this order — approved by the owner 2026-10-05:
 *   1. a floor worker → My Tasks
 *   2. anyone with the dashboard → Dashboard
 *   3. otherwise the first sidebar page their permissions open
 *   4. otherwise their own profile, which every signed-in user may open
 */
import { gateAllows, type GateSubject } from "@/lib/navGate";
import type { NavGroup } from "@/types";

export const MY_TASKS_PATH = "/production/my-tasks";
export const DASHBOARD_PATH = "/dashboard";
export const PROFILE_PATH = "/settings/profile";

/**
 * Works the floor rather than running it: holds the My Tasks workspace but
 * neither of the keys that mean "runs the floor" — the same two that give
 * floor-wide visibility in ProductionOrder::visibleTo. Admin holds
 * production.* (worker included) and so is not a floor worker.
 */
export function isFloorWorker({ can, isSuperAdmin }: GateSubject): boolean {
    if (isSuperAdmin) return false;
    return (
        can("production.worker") &&
        !can("production.manage_assignees") &&
        !can("production.confirm_order")
    );
}

export function homePath(subject: GateSubject, nav: NavGroup[]): string {
    if (isFloorWorker(subject)) return MY_TASKS_PATH;
    if (subject.isSuperAdmin || subject.can("dashboard.view")) return DASHBOARD_PATH;

    for (const group of nav) {
        for (const item of group.items) {
            if (item.href.startsWith("/") && gateAllows(item, subject)) return item.href;
        }
    }

    return PROFILE_PATH;
}
