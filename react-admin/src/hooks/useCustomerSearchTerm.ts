import { useEffect, useState } from "react";

/**
 * Phase 4A anti-scraping: the server refuses a customer search with fewer
 * than 3 real characters (wildcards and spaces do not count) and allows 30
 * searches a minute per user across every customer-search door. This turns
 * what the user types into what may be sent: settled for `delayMs`, and
 * either empty (no search) or at least 3 real characters.
 */
export const CUSTOMER_SEARCH_MIN = 3;

export function isSearchableCustomerTerm(term: string): boolean {
    return term.replace(/[\s%_*?]+/g, "").length >= CUSTOMER_SEARCH_MIN;
}

export function useCustomerSearchTerm(input: string, delayMs = 350): string {
    const [settled, setSettled] = useState("");

    useEffect(() => {
        const term = input.trim();
        const next = isSearchableCustomerTerm(term) ? term : "";
        const t = setTimeout(() => setSettled(next), next === "" ? 0 : delayMs);
        return () => clearTimeout(t);
    }, [input, delayMs]);

    return settled;
}
