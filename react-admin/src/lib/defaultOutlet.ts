import { useEffect, useRef } from "react";

/**
 * The outlet stock goes to unless somebody says otherwise (owner,
 * 2026-11-28): the Sonalux House branch. One place, so that when a second
 * branch opens the rule changes here and nowhere else. Falls back to the
 * only outlet when there is exactly one; otherwise nothing is pre-chosen.
 */
export function defaultOutletId(outlets: Array<{ id: number; name?: string | null }>): number | null {
    if (!outlets?.length) return null;
    const sonalux = outlets.find((o) => /sonalux/i.test(o.name ?? ""));
    if (sonalux) return sonalux.id;
    return outlets.length === 1 ? outlets[0].id : null;
}

/**
 * Pre-select the default outlet once, when the list arrives and nothing is
 * chosen yet. The picker stays free to change afterwards.
 */
export function useDefaultOutlet(
    outlets: Array<{ id: number; name?: string | null }>,
    isEmpty: boolean,
    choose: (id: number) => void,
): void {
    const done = useRef(false);
    useEffect(() => {
        if (done.current || !outlets?.length) return;
        done.current = true;
        const id = defaultOutletId(outlets);
        if (id !== null && isEmpty) choose(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [outlets?.length]);
}
