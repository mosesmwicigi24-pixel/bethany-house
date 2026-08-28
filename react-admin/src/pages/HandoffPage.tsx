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
 */

import { useEffect } from "react";
import { Navigate, useLocation, useParams } from "react-router-dom";
import { tokenStorage } from "@/api/client";

export default function HandoffPage() {
    const { id } = useParams<{ id: string }>();
    const location = useLocation();
    const token = new URLSearchParams(location.hash.replace(/^#/, "")).get("v");

    // Stash it for OrderDetailPage: a staff member who IS signed in but lacks
    // orders.view gets a 403 there, and can then be dropped to the customer
    // view rather than a dead end. Session-scoped — it dies with the tab.
    useEffect(() => {
        if (token && id) {
            try { sessionStorage.setItem(`ho:${id}`, token); } catch { /* private mode */ }
        }
    }, [token, id]);

    if (tokenStorage.get()) {
        return <Navigate to={`/sales/orders/${id}`} replace />;
    }
    if (token) {
        return <Navigate to={`/order/${token}`} replace />;
    }
    // No hub session and no capability: this is just a deep link into the
    // admin app. Ask for a login — a bare handoff URL must never become a
    // public read of an order.
    return <Navigate to="/login" state={{ from: { pathname: `/sales/orders/${id}` } }} replace />;
}
