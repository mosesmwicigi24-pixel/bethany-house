import { useCallback, useEffect, useRef, useState } from "react";

/**
 * The phone's position for a punch (Bethany People PR 1).
 *
 * Watches rather than asks once: the first fix from a cold GPS is often
 * 50–500 m wide and tightens over the next seconds, and the Clock In button
 * shows "12 m away" live. Location is read only while the clock screen is
 * open; nothing follows anyone through the day.
 */
export interface Fix {
    latitude: number;
    longitude: number;
    /** Metres of uncertainty the phone reports for this fix. */
    accuracy: number;
    at: number;
}

export type GeoState =
    | { status: "unsupported" }
    | { status: "asking" }
    | { status: "denied" }
    | { status: "unavailable"; message: string }
    | { status: "ok"; fix: Fix };

export function useGeolocation(active = true) {
    const [state, setState] = useState<GeoState>(() =>
        typeof navigator !== "undefined" && "geolocation" in navigator ? { status: "asking" } : { status: "unsupported" },
    );
    const watchId = useRef<number | null>(null);

    const stop = useCallback(() => {
        if (watchId.current !== null) {
            navigator.geolocation.clearWatch(watchId.current);
            watchId.current = null;
        }
    }, []);

    const start = useCallback(() => {
        if (!("geolocation" in navigator)) return;
        stop();
        setState(s => (s.status === "ok" ? s : { status: "asking" }));
        watchId.current = navigator.geolocation.watchPosition(
            pos => setState({ status: "ok", fix: {
                latitude: pos.coords.latitude, longitude: pos.coords.longitude,
                accuracy: Math.round(pos.coords.accuracy), at: pos.timestamp,
            } }),
            err => {
                if (err.code === err.PERMISSION_DENIED) setState({ status: "denied" });
                else setState({ status: "unavailable", message: err.message || "Location is not available right now." });
            },
            { enableHighAccuracy: true, maximumAge: 5_000, timeout: 20_000 },
        );
    }, [stop]);

    useEffect(() => {
        if (!active) { stop(); return; }
        start();
        return stop;
    }, [active, start, stop]);

    return { state, retry: start };
}

/** Great-circle distance in metres; the same formula the server checks with. */
export function distanceMeters(lat1: number, lng1: number, lat2: number, lng2: number): number {
    const R = 6371000;
    const toRad = (d: number) => (d * Math.PI) / 180;
    const dLat = toRad(lat2 - lat1), dLng = toRad(lng2 - lng1);
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}
