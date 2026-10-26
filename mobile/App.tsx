import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { ActivityIndicator, AppState, AppStateStatus, BackHandler, Linking, Platform, Pressable, StyleSheet, Text, View } from "react-native";
import { WebView, type WebViewMessageEvent, type WebViewNavigation } from "react-native-webview";
import * as Location from "expo-location";
import * as Notifications from "expo-notifications";
import * as Device from "expo-device";
import * as LocalAuthentication from "expo-local-authentication";
import * as Application from "expo-application";
import * as SecureStore from "expo-secure-store";
import Constants from "expo-constants";
import { StatusBar } from "expo-status-bar";
import * as SplashScreen from "expo-splash-screen";
import { deliver, injectedBefore, type AppMessage, type PageMessage } from "./bridge";
import { startTrail, stopTrail } from "./trail";

/**
 * Bethany House Hub — the store app (People PR 8).
 *
 * A native shell around the Hub console at hub.bethanyhouse.co.ke/admin. The
 * console does all the work and holds all the data; this app adds what a
 * browser cannot:
 *   - the phone's own location service, with Android's "mocked" flag so a
 *     fake-GPS clock-in is flagged by the server;
 *   - push through Expo's service (the server already speaks it);
 *   - a stable device id per install, so the named device survives a cache
 *     clear;
 *   - biometric unlock when the app comes back to the foreground;
 *   - location on shift (C1): between clock-in and clock-out, when the Hub's
 *     rule says so, a background task reports the position every few
 *     minutes with a visible notification. Off shift nothing runs.
 */

const HUB_URL: string = Constants.expoConfig?.extra?.hubUrl ?? "https://hub.bethanyhouse.co.ke/admin/";
const HUB_ORIGIN = new URL(HUB_URL).origin;
const APP_VERSION = `${Application.nativeApplicationVersion ?? "0"}+${Application.nativeBuildVersion ?? "0"}`;
const LOCK_AFTER_MS = 5 * 60 * 1000;

void SplashScreen.preventAutoHideAsync();

Notifications.setNotificationHandler({
    handleNotification: async () => ({ shouldShowBanner: true, shouldShowList: true, shouldPlaySound: true, shouldSetBadge: true }),
});

async function deviceId(): Promise<string> {
    const existing = await SecureStore.getItemAsync("bh_device_id");
    if (existing && /^[A-Za-z0-9-]{16,64}$/.test(existing)) return existing;
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    const id = Array.from(bytes, b => b.toString(16).padStart(2, "0")).join("");
    await SecureStore.setItemAsync("bh_device_id", id);
    return id;
}

export default function App() {
    // The package types the class as WebView<P = undefined>, and `WebViewProps & undefined` is never; name P.
    const web = useRef<WebView<object>>(null);
    const [info, setInfo] = useState<{ platform: string; appVersion: string; deviceId: string; model: string | null } | null>(null);
    const [locked, setLocked] = useState(false);
    const [canGoBack, setCanGoBack] = useState(false);
    const [offline, setOffline] = useState(false);
    const watcher = useRef<Location.LocationSubscription | null>(null);
    const pushToken = useRef<string | null>(null);
    const backgroundedAt = useRef<number | null>(null);
    const tokenResolver = useRef<((t: string | null) => void) | null>(null);

    useEffect(() => {
        void (async () => {
            setInfo({ platform: Platform.OS, appVersion: APP_VERSION, deviceId: await deviceId(), model: Device.modelName });
        })();
    }, []);

    const send = useCallback((m: AppMessage) => web.current?.injectJavaScript(deliver(m)), []);

    // ── Location: on while the page asks, off when it stops or the app hides ──
    const stopGeo = useCallback(() => { watcher.current?.remove(); watcher.current = null; }, []);
    const startGeo = useCallback(async () => {
        stopGeo();
        const perm = await Location.requestForegroundPermissionsAsync();
        if (!perm.granted) { send({ type: "geo:error", code: "denied", message: "Location permission was not given." }); return; }
        try {
            watcher.current = await Location.watchPositionAsync(
                { accuracy: Location.Accuracy.BestForNavigation, timeInterval: 3000, distanceInterval: 1 },
                pos => send({ type: "geo:fix", latitude: pos.coords.latitude, longitude: pos.coords.longitude, accuracy: Math.round(pos.coords.accuracy ?? 0), at: pos.timestamp, mocked: Boolean(pos.mocked) }),
            );
        } catch (e) {
            send({ type: "geo:error", code: "unavailable", message: (e as Error).message || "Location is not available right now." });
        }
    }, [send, stopGeo]);

    // ── Push: Expo token, handed to the page which registers it with the server ──
    const getPushToken = useCallback(async (): Promise<string | null> => {
        if (pushToken.current) return pushToken.current;
        if (!Device.isDevice) return null;
        const perm = await Notifications.getPermissionsAsync();
        const granted = perm.granted || (await Notifications.requestPermissionsAsync()).granted;
        if (!granted) return null;
        if (Platform.OS === "android") {
            await Notifications.setNotificationChannelAsync("default", { name: "Bethany House", importance: Notifications.AndroidImportance.HIGH });
        }
        const projectId = Constants.expoConfig?.extra?.eas?.projectId;
        const t = await Notifications.getExpoPushTokenAsync(projectId ? { projectId } : undefined);
        pushToken.current = t.data;
        return t.data;
    }, []);

    const onMessage = useCallback(async (e: WebViewMessageEvent) => {
        let m: PageMessage | { type: "token"; token: string | null };
        try { m = JSON.parse(e.nativeEvent.data) as PageMessage | { type: "token"; token: string | null }; } catch { return; }
        if (m.type === "token") { tokenResolver.current?.(m.token); tokenResolver.current = null; return; }
        switch (m.type) {
            case "geo:start": await startGeo(); break;
            case "geo:stop": stopGeo(); break;
            case "push:register": send({ type: "push:token", token: await getPushToken().catch(() => null) }); break;
            case "push:token?": send({ type: "push:token", token: pushToken.current }); break;
            case "open-external": if (typeof m.url === "string") void Linking.openURL(m.url); break;
            case "trail:start": {
                // The page's bearer token is read from its storage so the task can post without the page.
                const token = m.token ?? (await new Promise<string | null>(resolve => {
                    tokenResolver.current = resolve;
                    web.current?.injectJavaScript(`try { window.ReactNativeWebView.postMessage(JSON.stringify({type: "token", token: localStorage.getItem("bh_admin_token")})); } catch (e) {} true;`);
                    setTimeout(() => resolve(null), 3000);
                }));
                if (!token) { send({ type: "trail:state", state: "denied" }); break; }
                const state = await startTrail(`${HUB_ORIGIN}/api/v1/admin/time-clock/trail`, token, m.interval_minutes ?? 5, m.distance_meters ?? 100).catch(() => "unsupported" as const);
                send({ type: "trail:state", state });
                break;
            }
            case "trail:stop": await stopTrail(); send({ type: "trail:state", state: "stopped" }); break;
        }
    }, [getPushToken, send, startGeo, stopGeo]);

    // A tap on a push notification opens the page it points at.
    useEffect(() => {
        const sub = Notifications.addNotificationResponseReceivedListener(r => {
            const url = (r.notification.request.content.data as { url?: string } | undefined)?.url;
            if (typeof url === "string" && url.startsWith("/")) web.current?.injectJavaScript(`window.location.assign(${JSON.stringify(HUB_URL.replace(/\/admin\/$/, "/admin") + url)}); true;`);
        });
        return () => sub.remove();
    }, []);

    // ── Biometric lock after five minutes in the background ──
    const unlock = useCallback(async () => {
        const has = await LocalAuthentication.hasHardwareAsync();
        const enrolled = has && (await LocalAuthentication.isEnrolledAsync());
        if (!enrolled) { setLocked(false); return; }
        const r = await LocalAuthentication.authenticateAsync({ promptMessage: "Unlock Bethany House Hub", cancelLabel: "Cancel" });
        if (r.success) setLocked(false);
    }, []);
    useEffect(() => {
        const sub = AppState.addEventListener("change", (s: AppStateStatus) => {
            if (s !== "active") { backgroundedAt.current = Date.now(); stopGeo(); return; }
            if (backgroundedAt.current && Date.now() - backgroundedAt.current > LOCK_AFTER_MS) { setLocked(true); void unlock(); }
            backgroundedAt.current = null;
        });
        return () => sub.remove();
    }, [stopGeo, unlock]);

    // Android back button walks the page history before leaving the app.
    useEffect(() => {
        const sub = BackHandler.addEventListener("hardwareBackPress", () => { if (canGoBack) { web.current?.goBack(); return true; } return false; });
        return () => sub.remove();
    }, [canGoBack]);

    // Only the Hub loads inside; anything else (M-Pesa, maps, a PDF link) opens outside.
    const onShouldStart = useCallback((nav: WebViewNavigation) => {
        if (nav.url.startsWith(HUB_ORIGIN) || nav.url.startsWith("about:") || nav.url.startsWith("blob:")) return true;
        void Linking.openURL(nav.url);
        return false;
    }, []);

    const before = useMemo(() => (info ? injectedBefore(info) : ""), [info]);
    if (!info) return <View style={styles.center}><ActivityIndicator /></View>;

    return (
        <View style={styles.root}>
            <StatusBar style="dark" />
            <WebView<object>
                ref={web}
                source={{ uri: HUB_URL }}
                injectedJavaScriptBeforeContentLoaded={before}
                onMessage={m => void onMessage(m)}
                onNavigationStateChange={n => setCanGoBack(n.canGoBack)}
                onShouldStartLoadWithRequest={onShouldStart}
                onLoadEnd={() => { void SplashScreen.hideAsync(); setOffline(false); }}
                onError={() => setOffline(true)}
                applicationNameForUserAgent={`BethanyHub/${APP_VERSION}`}
                geolocationEnabled={false}
                allowsBackForwardNavigationGestures
                pullToRefreshEnabled={false}
                setSupportMultipleWindows={false}
                sharedCookiesEnabled
                domStorageEnabled
                javaScriptEnabled
                allowsInlineMediaPlayback
                mediaPlaybackRequiresUserAction={false}
                style={styles.web}
            />
            {offline && (
                <View style={styles.overlay}>
                    <Text style={styles.title}>No connection</Text>
                    <Text style={styles.body}>The Hub could not be reached. A clock-in made while offline is kept on the phone and sent when you are back.</Text>
                    <Pressable onPress={() => { setOffline(false); web.current?.reload(); }} style={styles.button}><Text style={styles.buttonText}>Try again</Text></Pressable>
                </View>
            )}
            {locked && (
                <View style={styles.overlay}>
                    <Text style={styles.title}>Locked</Text>
                    <Text style={styles.body}>Unlock to continue.</Text>
                    <Pressable onPress={() => void unlock()} style={styles.button}><Text style={styles.buttonText}>Unlock</Text></Pressable>
                </View>
            )}
        </View>
    );
}

const styles = StyleSheet.create({
    root: { flex: 1, backgroundColor: "#f8f7f4" },
    web: { flex: 1, backgroundColor: "#f8f7f4" },
    center: { flex: 1, alignItems: "center", justifyContent: "center", backgroundColor: "#f8f7f4" },
    overlay: { position: "absolute", top: 0, left: 0, right: 0, bottom: 0, backgroundColor: "#f8f7f4", alignItems: "center", justifyContent: "center", padding: 32 },
    title: { fontSize: 22, fontWeight: "700", color: "#1c1917", marginBottom: 8 },
    body: { fontSize: 15, color: "#57534e", textAlign: "center", marginBottom: 24 },
    button: { backgroundColor: "#c2410c", paddingHorizontal: 24, paddingVertical: 12, borderRadius: 14 },
    buttonText: { color: "#fff", fontWeight: "600", fontSize: 15 },
});
