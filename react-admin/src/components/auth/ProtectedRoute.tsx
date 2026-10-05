import { Link } from "react-router-dom";
import { usePermissions } from "@/hooks/usePermissions";
import { gateAllows } from "@/lib/navGate";
import { useHomePath } from "@/hooks/useHomePath";

interface ProtectedRouteProps {
    /** Single permission required */
    permission?: string;
    /** Any of these permissions (OR) */
    anyOf?: string[];
    /** All of these permissions (AND) */
    allOf?: string[];
    /**
     * The page's API is role:super_admin (Activity Logs, Database). The only
     * role-shaped guard there is — every other page is gated by the
     * permission its API checks (Role Hardening Plan §16).
     */
    superAdminOnly?: boolean;
    children: React.ReactNode;
}

/**
 * Route-level permission gate.
 *
 * Wrap a route's `element` with this (inside RequireAuth, which only checks
 * authentication) to block navigation entirely for users who lack the
 * required permission - instead of letting the page mount, fire its data
 * queries, get a 403 from the API, and render a confusing blank/empty state.
 *
 * The backend remains the source of truth and enforces every one of these
 * checks independently (see api.php) - this component is a UX improvement,
 * not the security boundary. Its job is to fail clearly and immediately
 * instead of silently.
 *
 * Usage:
 *   <Route
 *     path="/settings/users"
 *     element={
 *       <ProtectedRoute permission="users.view">
 *         <Suspense fallback={<PageLoader />}><UsersPage /></Suspense>
 *       </ProtectedRoute>
 *     }
 *   />
 */
export function ProtectedRoute({
    permission,
    anyOf,
    allOf,
    superAdminOnly,
    children,
}: ProtectedRouteProps) {
    const { can, isSuperAdmin } = usePermissions();

    // The same rule the Sidebar and the command palette apply (lib/navGate).
    const allowed = gateAllows(
        { permission, anyOfPermissions: anyOf, allOfPermissions: allOf, superAdminOnly },
        { can, isSuperAdmin },
    );

    if (!allowed) {
        return <AccessDenied />;
    }

    return <>{children}</>;
}

function AccessDenied() {
    // Back to a page this user CAN open. It used to link to /dashboard, which
    // for a role without the dashboard was this same locked screen again.
    const home = useHomePath();
    return (
        <div className="flex flex-col items-center justify-center text-center px-6 py-24 min-h-[60vh]">
            <div className="w-20 h-20 rounded-2xl bg-warning-light text-warning-dark flex items-center justify-center mb-4">
                <svg
                    className="w-10 h-10"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    strokeWidth={1.5}
                >
                    <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"
                    />
                </svg>
            </div>
            <h3 className="font-semibold text-surface-800 text-base">
                You don't have access to this page
            </h3>
            <p className="mt-1.5 text-sm text-surface-500 max-w-sm leading-relaxed">
                Your role doesn't include the permission needed here. If you
                think this is a mistake, ask an admin to check your account's
                role and permissions.
            </p>
            <Link to={home} className="mt-5 btn btn-secondary btn-sm">
                Go to my home page
            </Link>
        </div>
    );
}