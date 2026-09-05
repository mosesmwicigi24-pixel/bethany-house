/**
 * HandoffPage.tsx — /handoff/orders/:id#v={public_token}
 *
 * The bridge from Neema's Orders list into the hub. The owner's rule:
 *
 *   "when the order is clicked, it opens the order in the hub. If you have
 *    access to neema but you do not have access to hub, it should lead you to
 *    see that order as the customer sees it."
 *
 * ── Why the token rides in the URL FRAGMENT ─────────────────────────────────
 * The naive version of this feature is a public fallback on /sales/orders/{id}
 * when the API returns 401. That would turn every order into a public read,
 * because {id} is a small sequential integer: anyone could walk 1, 2, 3… and
 * harvest customer names, phones and totals. Knowing an order NUMBER must not
 * be enough either — it travels through invoices, WhatsApp and staff screens.
 *
 * So the fallback is authorised by a capability the viewer HOLDS: the order's
 * own public_token, carried after the '#'. A fragment is never sent to a
 * server, never lands in nginx or Laravel access logs, and never leaks through
 * the Referer header. Neema's dashboard is itself authenticated, so only a
 * logged-in Neema operator is ever handed one.
 *
 * The server's answer never changes: /api/v1/admin/orders/{id} stays 401/403
 * for everyone without a hub session, and the public data is served ONLY by
 * /api/v1/order/{public_token}, a route that takes no id at all.
 *
 * ── Why two of these three exits are a FULL PAGE LOAD ───────────────────────
 * This page is mounted by the PUBLIC router in App.tsx — App picks it by URL
 * (`isPublicPage`) before React Router starts, and that minimal router knows
 * only /pay, /track, /order and /handoff. The admin app is a SEPARATE
 * BrowserRouter under basename=BASE_URL ("/admin/").
 *
 * So a client-side <Navigate to="/sales/orders/540"> rewrote the address bar
 * and then matched NOTHING, because no such route exists in the router that is
 * actually mounted — which is why "Open in hub" landed on a blank white page.
 * Reaching the admin app means asking the browser for its URL, so nginx serves
 * the admin document and the admin router mounts. Only /order/:token lives in
 * this same router, and only that one can stay a client-side navigation.
 */

import { useEffect } from "react";
import { Navigate, useLocation, useParams } from "react-router-dom";
import { tokenStorage } from "@/api/client";
import { Spinner } from "@/components/ui/Spinner";

/** Where the admin app is served from — "/admin/" in production, "/" in dev. */
const adminBase = import.meta.env.BASE_URL || "/";

export default function HandoffPage() {
    const { id } = useParams<{ id: string }>();
    const location = useLocation();
    const token = new URLSearchParams(location.hash.replace(/^#/, "")).get("v");

    const signedIn = Boolean(tokenStorage.get());
    const target = `sales/orders/${id}`;

    // Stash it for OrderDetailPage: a staff member who IS signed in but lacks
    // orders.view gets a 403 there, and can then be dropped to the customer
    // view rather than a dead end. Session-scoped — it dies with the tab, and
    // it survives the full page load below because that stays in this tab.
    useEffect(() => {
        if (token && id) {
            try { sessionStorage.setItem(`ho:${id}`, token); } catch { /* private mode */ }
        }
    }, [token, id]);

    // Leaving the app entirely, so it belongs in an effect rather than in the
    // middle of a render.
    useEffect(() => {
        if (!id) return;
        if (signedIn) {
            window.location.replace(`${adminBase}${target}`);
        } else if (!token) {
            // No hub session and no capability: this is just a deep link into
            // the admin app. Ask for a login — a bare handoff URL must never
            // become a public read of an order. `next` carries them back to
            // the order once they are in; router state cannot cross a page
            // load, which is the whole reason this is a page load.
            window.location.replace(
                `${adminBase}login?next=${encodeURIComponent(`/${target}`)}`,
            );
        }
    }, [signedIn, token, id, target]);

    // Same router as this page, so this one stays client-side.
    if (!signedIn && token) {
        return <Navigate to={`/order/${token}`} replace />;
    }

    return (
        <div className="flex items-center justify-center h-64">
            <Spinner size="lg" />
        </div>
    );
}
