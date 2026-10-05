/**
 * The page side of the offline queue (the service worker replays it — see
 * replayQueuedTaskUpdates in sw.ts). One database, "bh-offline-queue", with
 * the same stores the worker creates, so whichever opens it first on a fresh
 * device builds it. Before, the page opened it without creating the store,
 * and the first offline tap on a new phone threw instead of queueing.
 *
 * Each item records the full request (url, method, body) so the worker can
 * replay piece counts (POST) as well as Start/Pause/Done (PUT).
 */
import { tokenStorage } from "@/api/client";

const DB_NAME = "bh-offline-queue";
const DB_VERSION = 1;
const STORE = "task-updates";

export interface QueuedRequest {
    url: string;
    method: "PUT" | "POST";
    body: unknown;
    /**
     * Requests with the same key replace one another: a piece count is an
     * absolute number, so only the latest tap for a stage needs sending.
     */
    dedupeKey?: string;
}

function open(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains("task-updates")) {
                db.createObjectStore("task-updates", { autoIncrement: true, keyPath: "id" });
            }
            if (!db.objectStoreNames.contains("pos-sales")) {
                db.createObjectStore("pos-sales", { autoIncrement: true, keyPath: "id" });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

/** Queue a request for replay, then ask the worker to sync. */
export async function enqueueOffline(item: QueuedRequest): Promise<void> {
    const db = await open();
    await new Promise<void>((resolve, reject) => {
        const tx = db.transaction(STORE, "readwrite");
        const store = tx.objectStore(STORE);
        const write = () => store.add({ ...item, token: tokenStorage.get() ?? "", queuedAt: Date.now() });
        if (item.dedupeKey) {
            // Drop an older entry for the same stage, then add the latest.
            const all = store.getAll();
            all.onsuccess = () => {
                for (const row of all.result as Array<{ id: IDBValidKey; dedupeKey?: string }>) {
                    if (row.dedupeKey === item.dedupeKey) store.delete(row.id);
                }
                write();
            };
        } else {
            write();
        }
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
    db.close();
    requestReplay();
}

/** How many updates are waiting to sync (for the offline banner). */
export async function pendingCount(): Promise<number> {
    try {
        const db = await open();
        const n = await new Promise<number>((resolve, reject) => {
            const req = db.transaction(STORE, "readonly").objectStore(STORE).count();
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        });
        db.close();
        return n;
    } catch {
        return 0;
    }
}

/**
 * Ask the worker to replay now. Background Sync does it when the browser
 * supports it; where it doesn't (iOS Safari and others), the queue would sit
 * until the next visit, so the page also asks directly — called on enqueue
 * and whenever the connection comes back.
 */
export function requestReplay(): void {
    const sw = navigator.serviceWorker;
    if (!sw) return;
    sw.ready.then((reg) => {
        (reg as any).sync?.register("task-status-update").catch(() => {});
        if (navigator.onLine) reg.active?.postMessage({ type: "REPLAY_TASK_UPDATES" });
    }).catch(() => {});
}

/** A request that never reached the server (no HTTP status), as opposed to a refusal. */
export const isNetworkFailure = (e: unknown) =>
    !!e && typeof e === "object" && (e as { status?: number }).status === undefined;
