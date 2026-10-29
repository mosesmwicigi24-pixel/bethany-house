import SwiftUI
import BethanyKit
import BethanyUI
import UserNotifications

/// Bethany House Hub for macOS (native apps programme, Step 3).
///
/// A single `AppEnvironment` owns the session; `MacShell` is the window.
/// Push is APNs through the app delegate; a tap on a notification opens the
/// route its `url` names. Window state (sidebar selection, collapsed groups)
/// restores through SceneStorage.
@main
struct HubMacApp: App {
    @NSApplicationDelegateAdaptor(AppDelegate.self) private var delegate
    @State private var env = AppEnvironment(config: HubConfig.current)

    var body: some Scene {
        WindowGroup("Bethany House Hub") {
            RootView()
                .environment(env)
                .environment(env.session)
                .task {
                    delegate.env = env
                    await env.start()
                    await delegate.requestPushAuthorization()
                }
                .onOpenURL { url in
                    // bethanyhub:// and https://hub.bethanyhouse.co.ke/admin/... (universal links)
                    if let route = Route.from(consolePath: url.path) { env.navigator.go(route) }
                }
                .onReceive(NotificationCenter.default.publisher(for: NSApplication.didBecomeActiveNotification)) { _ in
                    Task { await env.session.didBecomeActive(); await env.replayPending() }
                }
        }
        .windowToolbarStyle(.unified(showsTitle: true))
        .commands { HubCommands(env: env) }
        .defaultSize(width: 1280, height: 820)

        Settings {
            SettingsView().environment(env).environment(env.session)
        }
    }
}

struct RootView: View {
    @Environment(AppEnvironment.self) private var env

    var body: some View {
        Group {
            switch env.session.state {
            case .starting:
                ProgressView("Opening the Hub…").frame(maxWidth: .infinity, maxHeight: .infinity).background(Theme.canvas)
            case .signedIn:
                MacShell()
                    .onChange(of: env.session.state) { _, _ in Task { await env.sessionDidChange() } }
            default:
                SignInFlow()
                    .frame(minWidth: 520, minHeight: 640)
                    .onChange(of: env.session.state) { _, s in if s == .signedIn { Task { await env.sessionDidChange() } } }
            }
        }
    }
}

/// App settings pane: which Hub to talk to (for a staging build) and appearance.
struct SettingsView: View {
    @Environment(AppEnvironment.self) private var env
    @AppStorage("bh.appearance") private var appearance = "system"

    var body: some View {
        Form {
            Section("Hub") {
                LabeledContent("Server", value: env.config.hubURL.absoluteString)
                LabeledContent("Device id", value: env.session.device.id)
                LabeledContent("App", value: env.session.device.appVersion)
            }
            Section("Appearance") {
                Picker("Appearance", selection: $appearance) {
                    Text("System").tag("system"); Text("Light").tag("light"); Text("Dark").tag("dark")
                }
                .onChange(of: appearance) { _, v in
                    NSApp.appearance = v == "light" ? NSAppearance(named: .aqua) : v == "dark" ? NSAppearance(named: .darkAqua) : nil
                }
            }
        }
        .formStyle(.grouped)
        .frame(width: 460)
        .padding()
    }
}

/// Build-time configuration. The production Hub is the default; a scheme
/// may override with `HUB_URL` / `REVERB_KEY` in the environment.
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

final class AppDelegate: NSObject, NSApplicationDelegate, UNUserNotificationCenterDelegate {
    var env: AppEnvironment?

    func applicationDidFinishLaunching(_ notification: Notification) {
        UNUserNotificationCenter.current().delegate = self
    }

    @MainActor
    func requestPushAuthorization() async {
        let granted = (try? await UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .badge, .sound])) ?? false
        if granted { NSApplication.shared.registerForRemoteNotifications() }
    }

    func application(_ application: NSApplication, didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data) {
        Task { @MainActor in
            guard let env else { return }
            env.session.pushRegistrar?.didReceive(deviceToken: deviceToken)
            await env.session.pushRegistrar?.register(after: env.session)
        }
    }

    func application(_ application: NSApplication, didFailToRegisterForRemoteNotificationsWithError error: Error) {
        NSLog("APNs registration failed: \(error.localizedDescription)")
    }

    func userNotificationCenter(_ center: UNUserNotificationCenter, willPresent notification: UNNotification) async -> UNNotificationPresentationOptions {
        [.banner, .sound, .badge]
    }

    func userNotificationCenter(_ center: UNUserNotificationCenter, didReceive response: UNNotificationResponse) async {
        let payload = PushPayload(userInfo: response.notification.request.content.userInfo)
        if let route = payload.route {
            await MainActor.run { env?.navigator.go(route) }
        }
    }
}
