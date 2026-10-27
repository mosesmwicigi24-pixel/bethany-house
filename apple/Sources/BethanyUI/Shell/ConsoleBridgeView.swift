import SwiftUI
import WebKit
import BethanyKit

/// The Hub console page for a route, signed in as the person, inside the app.
///
/// This is the bridge for screens not yet ported to native views (see
/// RouteView). It is not the product: the shell, navigation, session, push,
/// location, offline and every ported module are native. The page gets the
/// bearer token through the console's own storage key before it loads, the
/// same contract the Expo shell used, and `window.BethanyNative` so the
/// console hides its install banner and reports the device id.
public struct ConsoleBridgeView: View {
    @Environment(AppEnvironment.self) private var env
    let route: Route

    public init(route: Route) { self.route = route }

    public var body: some View {
        ConsoleWebView(url: env.consoleURL(for: route), token: tokenProvider, device: env.session.device, onNavigate: { r in env.navigator.go(r) })
            .overlay(alignment: .bottomTrailing) {
                Text("Console page").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                    .padding(6).background(.thinMaterial, in: Capsule()).padding(8)
                    .help("This page is shown from the Hub console until its native screen ships.")
            }
    }

    private var tokenProvider: @Sendable () async -> String? {
        let s = env.session
        return { await s.bearerToken() }
    }
}

#if os(macOS)
typealias PlatformViewRepresentable = NSViewRepresentable
#else
typealias PlatformViewRepresentable = UIViewRepresentable
#endif

struct ConsoleWebView: PlatformViewRepresentable {
    let url: URL
    let token: @Sendable () async -> String?
    let device: DeviceIdentity
    let onNavigate: (Route) -> Void

    func makeCoordinator() -> Coordinator { Coordinator(self) }

    private func makeWebView(context: Context) -> WKWebView {
        let config = WKWebViewConfiguration()
        config.applicationNameForUserAgent = device.userAgent
        config.userContentController.add(context.coordinator, name: "bethanyNative")
        let native = """
        window.BethanyNative = { platform: "\(device.platform.rawValue)", appVersion: "\(device.appVersion)", deviceId: "\(device.id)", model: "\(device.model)" };
        """
        config.userContentController.addUserScript(WKUserScript(source: native, injectionTime: .atDocumentStart, forMainFrameOnly: true))
        let web = WKWebView(frame: .zero, configuration: config)
        web.navigationDelegate = context.coordinator
        context.coordinator.web = web
        return web
    }

    #if os(macOS)
    func makeNSView(context: Context) -> WKWebView { let w = makeWebView(context: context); context.coordinator.load(url); return w }
    func updateNSView(_ view: WKWebView, context: Context) { context.coordinator.loadIfChanged(url) }
    #else
    func makeUIView(context: Context) -> WKWebView { let w = makeWebView(context: context); context.coordinator.load(url); return w }
    func updateUIView(_ view: WKWebView, context: Context) { context.coordinator.loadIfChanged(url) }
    #endif

    final class Coordinator: NSObject, WKNavigationDelegate, WKScriptMessageHandler {
        let parent: ConsoleWebView
        weak var web: WKWebView?
        private var current: URL?

        init(_ parent: ConsoleWebView) { self.parent = parent }

        func loadIfChanged(_ url: URL) { if url != current { load(url) } }

        func load(_ url: URL) {
            current = url
            Task { @MainActor in
                guard let web else { return }
                // The console reads its token from localStorage.bh_admin_token (client.ts).
                if let token = await parent.token() {
                    let script = WKUserScript(source: "try { localStorage.setItem('bh_admin_token', '\(token)'); } catch (e) {}", injectionTime: .atDocumentStart, forMainFrameOnly: true)
                    web.configuration.userContentController.addUserScript(script)
                }
                web.load(URLRequest(url: url))
            }
        }

        func userContentController(_ userContentController: WKUserContentController, didReceive message: WKScriptMessage) {}

        /// Keep the person inside the Hub origin; anything else (M-Pesa, maps,
        /// PDFs served elsewhere) opens in the system browser, as the shell did.
        func webView(_ webView: WKWebView, decidePolicyFor navigationAction: WKNavigationAction, decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {
            guard let url = navigationAction.request.url, let host = url.host else { decisionHandler(.allow); return }
            if host == parent.url.host || url.scheme == "about" || url.scheme == "blob" {
                // A console link to a page we have natively: take over.
                if navigationAction.navigationType == .linkActivated, let r = Route.from(consolePath: url.path), FeatureRegistry.isNative(r) {
                    parent.onNavigate(r)
                    decisionHandler(.cancel)
                    return
                }
                decisionHandler(.allow)
            } else {
                #if os(macOS)
                NSWorkspace.shared.open(url)
                #else
                UIApplication.shared.open(url)
                #endif
                decisionHandler(.cancel)
            }
        }
    }
}
