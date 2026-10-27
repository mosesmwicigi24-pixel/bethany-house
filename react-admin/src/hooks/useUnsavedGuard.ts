import { useEffect } from "react";

/**
 * Ask before leaving a form with unsaved edits.
 *
 * The console runs on <BrowserRouter>, where react-router's useBlocker is not
 * available, so this covers the two ways out that matter: closing or
 * reloading the tab (beforeunload) and clicking any in-app link — the
 * sidebar, a breadcrumb, "← People" (a capture-phase click listener on <a>).
 * The browser's own Back button is not intercepted.
 */
export function useUnsavedGuard(dirty: boolean, message = "You have unsaved changes on this file. Leave without saving?") {
    useEffect(() => {
        if (!dirty) return;
        const onBeforeUnload = (e: BeforeUnloadEvent) => { e.preventDefault(); e.returnValue = ""; };
        const onClick = (e: MouseEvent) => {
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            const a = (e.target as Element | null)?.closest?.("a[href]") as HTMLAnchorElement | null;
            if (!a || a.target === "_blank" || a.hasAttribute("download")) return;
            const href = a.getAttribute("href") ?? "";
            if (href.startsWith("#") || href.startsWith("tel:") || href.startsWith("mailto:")) return;
            if (!window.confirm(message)) { e.preventDefault(); e.stopPropagation(); }
        };
        window.addEventListener("beforeunload", onBeforeUnload);
        document.addEventListener("click", onClick, true);
        return () => {
            window.removeEventListener("beforeunload", onBeforeUnload);
            document.removeEventListener("click", onClick, true);
        };
    }, [dirty, message]);
}
