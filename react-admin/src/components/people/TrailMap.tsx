import { useEffect, useRef } from "react";
import L from "leaflet";
import "leaflet/dist/leaflet.css";
import type { Trail } from "@/api/people-c1";

/**
 * One entry's trail on a map (People C1): the fence, the clock-in and
 * clock-out pins, the points in order, the stretch after "left the site"
 * in amber. OpenStreetMap tiles; nothing is sent anywhere but the tile
 * request. Loaded only when a manager opens it.
 */
export function TrailMap({ trail }: { trail: Trail }) {
    const el = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (!el.current) return;
        const map = L.map(el.current, { zoomControl: true, attributionControl: true });
        L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19, attribution: "© OpenStreetMap" }).addTo(map);
        const bounds: L.LatLngExpression[] = [];
        if (trail.outlet?.latitude != null && trail.outlet.longitude != null) {
            const c: L.LatLngExpression = [trail.outlet.latitude, trail.outlet.longitude];
            L.circle(c, { radius: trail.outlet.radius ?? 50, color: "#2f6b3a", fillColor: "#2f6b3a", fillOpacity: 0.12, weight: 1.5 }).addTo(map).bindTooltip(`${trail.outlet.name} · ${trail.outlet.radius ?? 50} m`);
            bounds.push(c);
        }
        const dot = (p: { latitude: number; longitude: number }, color: string, r = 4) => L.circleMarker([p.latitude, p.longitude], { radius: r, color, fillColor: color, fillOpacity: 0.9, weight: 1 });
        const leftAt = trail.left_site_at ? new Date(trail.left_site_at).getTime() : null;
        const before: L.LatLngExpression[] = []; const after: L.LatLngExpression[] = [];
        for (const p of trail.points) {
            const ll: L.LatLngExpression = [p.latitude, p.longitude];
            (leftAt && new Date(p.at).getTime() >= leftAt ? after : before).push(ll);
            bounds.push(ll);
            dot(p, p.mocked ? "#9a2f2f" : leftAt && new Date(p.at).getTime() >= leftAt ? "#b7791f" : "#4b5563", 3).addTo(map)
                .bindTooltip(`${new Date(p.at).toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" })}${p.distance != null ? ` · ${p.distance} m from the shop` : ""}${p.accuracy != null ? ` · ±${p.accuracy} m` : ""}${p.mocked ? " · mocked" : ""}`);
        }
        if (before.length > 1) L.polyline(before, { color: "#4b5563", weight: 2, opacity: 0.8 }).addTo(map);
        if (after.length > 0) L.polyline([...(before.length ? [before[before.length - 1]] : []), ...after], { color: "#b7791f", weight: 3, opacity: 0.9, dashArray: "6 4" }).addTo(map);
        if (trail.clock_in) { dot(trail.clock_in, "#2f6b3a", 7).addTo(map).bindTooltip("Clock in " + new Date(trail.clock_in.at).toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" })); bounds.push([trail.clock_in.latitude, trail.clock_in.longitude]); }
        if (trail.clock_out) { dot(trail.clock_out, "#8a3b12", 7).addTo(map).bindTooltip("Clock out " + new Date(trail.clock_out.at).toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" })); bounds.push([trail.clock_out.latitude, trail.clock_out.longitude]); }
        if (bounds.length) map.fitBounds(L.latLngBounds(bounds).pad(0.3), { maxZoom: 17 }); else map.setView([-1.2921, 36.8219], 15);
        return () => { map.remove(); };
    }, [trail]);
    return (
        <div className="space-y-2">
            <div ref={el} className="h-72 w-full rounded-xl overflow-hidden border border-line" aria-label="Map of the shift's positions" />
            <p className="text-2xs text-surface-500">
                {trail.points.length} point{trail.points.length === 1 ? "" : "s"} on shift{trail.left_site_at ? `; left the site at ${new Date(trail.left_site_at).toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" })} (amber)` : ""}. Green ring is the clock-in zone. Points are kept {trail.retention_days} days, then deleted.
            </p>
        </div>
    );
}
