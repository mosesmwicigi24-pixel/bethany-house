import * as Location from "expo-location";
import * as TaskManager from "expo-task-manager";
import * as SecureStore from "expo-secure-store";

/**
 * Location on shift (People C1). The Hub page tells the app when a shift
 * starts and stops (trail:start / trail:stop); between those the phone's
 * location service wakes this task every few minutes or on movement and the
 * points go to /time-clock/trail. The server owns the retention and the
 * "left the site" decision; the app only reports. Nothing runs off shift.
 */
export const TRAIL_TASK = "bethany-trail";
const KEY_URL = "bh_trail_url";
const KEY_TOKEN = "bh_trail_token";
const KEY_DEVICE = "bh_device_id";
const KEY_QUEUE = "bh_trail_queue";

type Point = { latitude: number; longitude: number; accuracy: number | null; recorded_at: string; mocked: boolean };

async function flush(points: Point[]): Promise<void> {
    const url = await SecureStore.getItemAsync(KEY_URL);
    const token = await SecureStore.getItemAsync(KEY_TOKEN);
    const device = await SecureStore.getItemAsync(KEY_DEVICE);
    if (!url || !token) return;
    const queued: Point[] = JSON.parse((await SecureStore.getItemAsync(KEY_QUEUE)) ?? "[]");
    const batch = [...queued, ...points].slice(-200);
    try {
        const res = await fetch(url, { method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json", Authorization: `Bearer ${token}`, ...(device ? { "X-Device-Id": device } : {}) }, body: JSON.stringify({ points: batch }) });
        if (res.ok) {
            await SecureStore.setItemAsync(KEY_QUEUE, "[]");
            const body = (await res.json()) as { tracking?: boolean };
            if (body.tracking === false) await stopTrail(); // clocked out (or the rule is off): stop on the server's word
        } else if (res.status === 401) {
            await stopTrail();
        } else {
            await SecureStore.setItemAsync(KEY_QUEUE, JSON.stringify(batch));
        }
    } catch {
        await SecureStore.setItemAsync(KEY_QUEUE, JSON.stringify(batch)); // no signal: keep for the next wake
    }
}

TaskManager.defineTask(TRAIL_TASK, async ({ data, error }) => {
    if (error || !data) return;
    const { locations } = data as { locations: Location.LocationObject[] };
    await flush(locations.map(l => ({ latitude: l.coords.latitude, longitude: l.coords.longitude, accuracy: l.coords.accuracy != null ? Math.round(l.coords.accuracy) : null, recorded_at: new Date(l.timestamp).toISOString(), mocked: Boolean(l.mocked) })));
});

/** Called from the page's trail:start. $apiUrl is the absolute /time-clock/trail URL; the token is the page's bearer. */
export async function startTrail(apiUrl: string, token: string, intervalMinutes: number, distanceMeters: number): Promise<"started" | "denied" | "unsupported"> {
    await SecureStore.setItemAsync(KEY_URL, apiUrl);
    await SecureStore.setItemAsync(KEY_TOKEN, token);
    const fg = await Location.requestForegroundPermissionsAsync();
    if (!fg.granted) return "denied";
    const bg = await Location.requestBackgroundPermissionsAsync();
    if (!bg.granted) return "denied";
    if (!(await Location.isBackgroundLocationAvailableAsync())) return "unsupported";
    if (await Location.hasStartedLocationUpdatesAsync(TRAIL_TASK)) return "started";
    await Location.startLocationUpdatesAsync(TRAIL_TASK, {
        accuracy: Location.Accuracy.Balanced,
        timeInterval: Math.max(1, intervalMinutes) * 60_000,
        distanceInterval: Math.max(10, distanceMeters),
        deferredUpdatesInterval: Math.max(1, intervalMinutes) * 60_000,
        pausesUpdatesAutomatically: false,
        showsBackgroundLocationIndicator: true,
        foregroundService: { notificationTitle: "Clocked in at Bethany House", notificationBody: "Your position is recorded while you are on shift. It stops when you clock out.", notificationColor: "#c2410c" },
    });
    return "started";
}

export async function stopTrail(): Promise<void> {
    if (await Location.hasStartedLocationUpdatesAsync(TRAIL_TASK)) await Location.stopLocationUpdatesAsync(TRAIL_TASK);
    await SecureStore.deleteItemAsync(KEY_TOKEN);
    await SecureStore.setItemAsync(KEY_QUEUE, "[]");
}

export async function trailRunning(): Promise<boolean> {
    return Location.hasStartedLocationUpdatesAsync(TRAIL_TASK);
}
