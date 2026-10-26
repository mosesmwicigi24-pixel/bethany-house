/**
 * The store app (Bethany People PR 8) is a native shell around this console:
 * an Expo WebView that injects `window.BethanyNative` before the page loads
 * and talks over postMessage. Everything here no-ops in a plain browser, so
 * the PWA and the app run the same bundle.
 *
 * Page → app:  window.ReactNativeWebView.postMessage(JSON.stringify({type, ...}))
 * App → page:  window.dispatchEvent(new CustomEvent("bh:native", {detail: {type, ...}}))
 */

export interface NativeInfo {
    platform: "ios" | "android";
    appVersion: string;
    /** Stable per-install id the app keeps in secure storage. */
    deviceId: string;
    model?: string;
}

interface RNWebView { postMessage: (s: string) => void }

declare global {
    interface Window {
        BethanyNative?: NativeInfo;
        ReactNativeWebView?: RNWebView;
    }
}

export function nativeInfo(): NativeInfo | null {
    return typeof window !== "undefined" && window.BethanyNative && window.ReactNativeWebView ? window.BethanyNative : null;
}

export const isNative = (): boolean => nativeInfo() !== null;

export function postNative(message: Record<string, unknown> & { type: string }): boolean {
    const rn = typeof window !== "undefined" ? window.ReactNativeWebView : undefined;
    if (!rn) return false;
    try { rn.postMessage(JSON.stringify(message)); return true; } catch { return false; }
}

export type NativeMessage = Record<string, unknown> & { type: string };

/** Listen for one message type from the app; returns the unsubscribe. */
export function onNative(type: string, handler: (m: NativeMessage) => void): () => void {
    const listener = (e: Event) => {
        const d = (e as CustomEvent<NativeMessage>).detail;
        if (d && d.type === type) handler(d);
    };
    window.addEventListener("bh:native", listener);
    return () => window.removeEventListener("bh:native", listener);
}

/** Ask the app for one reply of `replyType` after posting `message`. */
export function askNative<T extends NativeMessage>(message: NativeMessage, replyType: string, timeoutMs = 15_000): Promise<T | null> {
    return new Promise(resolve => {
        if (!postNative(message)) return resolve(null);
        const off = onNative(replyType, m => { off(); clearTimeout(t); resolve(m as T); });
        const t = setTimeout(() => { off(); resolve(null); }, timeoutMs);
    });
}
