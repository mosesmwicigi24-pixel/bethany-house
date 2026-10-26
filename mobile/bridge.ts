/**
 * Messages between the Hub page and this app (People PR 8). Mirrors
 * react-admin/src/lib/native.ts — change both together.
 *
 * Page → app: {type: "geo:start" | "geo:stop" | "push:register" | "push:token?" | "open-external" | "trail:start" | "trail:stop", ...}
 * App → page: {type: "geo:fix" | "geo:error" | "push:token" | "trail:state", ...}
 */
export type PageMessage =
    | { type: "geo:start" }
    | { type: "geo:stop" }
    | { type: "push:register" }
    | { type: "push:token?" }
    | { type: "open-external"; url: string }
    | { type: "trail:start"; interval_minutes?: number; distance_meters?: number; token?: string }
    | { type: "trail:stop" };

export type AppMessage =
    | { type: "geo:fix"; latitude: number; longitude: number; accuracy: number; at: number; mocked: boolean }
    | { type: "geo:error"; code: "denied" | "unavailable"; message: string }
    | { type: "push:token"; token: string | null }
    | { type: "trail:state"; state: "started" | "stopped" | "denied" | "unsupported" };

/** JavaScript the WebView runs on the page before anything else loads. */
export function injectedBefore(info: { platform: string; appVersion: string; deviceId: string; model: string | null }): string {
    return `window.BethanyNative = ${JSON.stringify(info)}; true;`;
}

/** JavaScript that delivers one message to the page. */
export function deliver(message: AppMessage): string {
    return `window.dispatchEvent(new CustomEvent("bh:native", { detail: ${JSON.stringify(message)} })); true;`;
}
