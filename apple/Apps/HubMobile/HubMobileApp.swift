import SwiftUI
import BethanyKit
import BethanyUI
import UserNotifications

/// Bethany House Hub for iPhone and iPad (native apps programme, Steps 4–5).
@main
struct HubMobileApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) private var delegate
    @Environment(\.scenePhase) private var scenePhase
    @State private var env = AppEnvironment(config: HubConfig.current)

    var body: some Scene {
        WindowGroup {
            RootView()
                .environment(env)
                .environment(env.session)
                .task {
                    delegate.env = env
                    await env.start()
                    await delegate.requestPushAuthorization()
                }
                .onOpenURL { url in
                    if let route = Route.from(consolePath: url.path) { env.navigator.go(route) }
                }
                .onContinueUserActivity(NSUserActivityTypeBrowsingWeb) { activity in
                    if let url = activity.webpageURL, let route = Route.from(consolePath: url.path) { env.navigator.go(route) }
                }
                .onChange(of: scenePhase) { _, phase in
                    switch phase {
                    case .background: if !env.kiosk.isKiosk { env.biometrics.didEnterBackground() }
                    case .active:
                        // A kiosk is a shared tablet: no one's biometric re-lock applies to it.
                        if !env.kiosk.isKiosk { env.biometrics.willEnterForeground() }
                        Task { await env.session.didBecomeActive(); await env.replayPending() }
                    default: break
                    }
                }
        }
    }
}

struct RootView: View {
    @Environment(AppEnvironment.self) private var env

    var body: some View {
        // A kiosk tablet shows only the clock-in board, whatever session exists.
        if env.kiosk.isKiosk {
            KioskView()
        } else {
            sessionRoot
        }
    }

    @ViewBuilder private var sessionRoot: some View {
        switch env.session.state {
        case .starting:
            ProgressView("Opening the Hub…").frame(maxWidth: .infinity, maxHeight: .infinity).background(Theme.canvas)
        case .signedIn:
            MobileShell()
                .onChange(of: env.session.state) { _, _ in Task { await env.sessionDidChange() } }
        default:
            SignInFlow()
                .onChange(of: env.session.state) { _, s in if s == .signedIn { Task { await env.sessionDidChange() } } }
        }
    }
}

enum HubConfig {
    static var current: AppEnvironment.Config {
        let envVars = ProcessInfo.processInfo.environment
        let url = envVars["HUB_URL"].flatMap(URL.init(string:)) ?? URL(string: "https://hub.bethanyhouse.co.ke")!
        return AppEnvironment.Config(
            hubURL: url,
            reverbKey: envVars["REVERB_KEY"] ?? (Bundle.main.infoDictionary?["BHReverbKey"] as? String),
            reverbHost: envVars["REVERB_HOST"] ?? url.host,
            reverbPort: Int(envVars["REVERB_PORT"] ?? "") ?? 443,
            reverbScheme: envVars["REVERB_SCHEME"] ?? "wss"
        )
    }
}

final class AppDelegate: NSObject, UIApplicationDelegate, UNUserNotificationCenterDelegate {
    var env: AppEnvironment?

    func application(_ application: UIApplication, didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil) -> Bool {
        UNUserNotificationCenter.current().delegate = self
        return true
    }

    @MainActor
    func requestPushAuthorization() async {
        let granted = (try? await UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .badge, .sound])) ?? false
        if granted { UIApplication.shared.registerForRemoteNotifications() }
    }

    func application(_ application: UIApplication, didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data) {
        Task { @MainActor in
            guard let env else { return }
            env.session.pushRegistrar?.didReceive(deviceToken: deviceToken)
            await env.session.pushRegistrar?.register(after: env.session)
        }
    }

    func application(_ application: UIApplication, didFailToRegisterForRemoteNotificationsWithError error: Error) {
        NSLog("APNs registration failed: \(error.localizedDescription)")
    }

    func userNotificationCenter(_ center: UNUserNotificationCenter, willPresent notification: UNNotification) async -> UNNotificationPresentationOptions {
        [.banner, .sound, .badge]
    }

    func userNotificationCenter(_ center: UNUserNotificationCenter, didReceive response: UNNotificationResponse) async {
        let payload = PushPayload(userInfo: response.notification.request.content.userInfo)
        if let route = payload.route { await MainActor.run { env?.navigator.go(route) } }
    }
}
