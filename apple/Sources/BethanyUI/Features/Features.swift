import SwiftUI
import BethanyKit

/// Native screens register their routes here. Each module keeps its own
/// `register()`; a route with no native screen falls back to the console
/// bridge (RouteView). docs/NATIVE_APPS_CHECKLIST.md is the list of what is
/// native, by target.
@MainActor
public enum Features {
    public static func registerAll() {
        WorkspaceFeature.register()
        ProfileFeature.register()
        MeFeature.register()
        PosFeature.register()   // also registers Tills and EoD
        SalesFeature.register()
        ProductionFeature.register()
        InventoryFeature.register()
        ProcurementFeature.register()
        ExpensesFeature.register()
        FinanceFeature.register()
        ReportsFeature.register()
        CatalogFeature.register()
        // Modules added by Steps 3–5 register below; order is irrelevant.
        for register in extraRegistrars { register() }
    }

    /// Modules compiled into the package append here from their own files
    /// via a static initialiser (see each `*Feature.swift`).
    public static var extraRegistrars: [@MainActor () -> Void] = []
}

/// A page frame shared by every native screen: title, optional subtitle,
/// toolbar actions, scrolling content with the right gutters per platform.
public struct Page<Content: View>: View {
    let title: String
    let subtitle: String?
    let content: Content

    public init(_ title: String, subtitle: String? = nil, @ViewBuilder content: () -> Content) {
        self.title = title
        self.subtitle = subtitle
        self.content = content()
    }

    public var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: Theme.Space.l) {
                content
            }
            .padding(.horizontal, Theme.Space.gutter)
            .padding(.bottom, Theme.Space.xxl)
            .frame(maxWidth: 1200, alignment: .leading)
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .background(Theme.canvas)
        .navigationTitle(title)
        #if os(macOS)
        .navigationSubtitle(subtitle ?? "")
        #endif
    }
}

/// Adaptive grid: as many tiles per row as the width allows.
public struct TileGrid<Content: View>: View {
    let minWidth: CGFloat
    let content: Content
    public init(minWidth: CGFloat = 200, @ViewBuilder content: () -> Content) {
        self.minWidth = minWidth
        self.content = content()
    }
    public var body: some View {
        LazyVGrid(columns: [GridItem(.adaptive(minimum: minWidth), spacing: Theme.Space.m)], spacing: Theme.Space.m) { content }
    }
}

/// Opens a console path from server data (`action_url`) natively when it maps.
public struct ActionLink: View {
    @Environment(AppEnvironment.self) private var env
    let label: String
    let url: String?
    public init(_ label: String, url: String?) { self.label = label; self.url = url }
    public var body: some View {
        if let url, let route = Route.from(consolePath: url) {
            Button(label) { env.navigator.go(route) }.font(Theme.Font.secondary)
        }
    }
}

/// Shares or saves a server document (PDF/CSV) from a download endpoint.
public struct DocumentButton: View {
    @Environment(AppEnvironment.self) private var env
    let label: String
    let path: String
    let query: [String: String?]
    let suggestedName: String
    @State private var busy = false
    @State private var fileURL: URL?

    public init(_ label: String, path: String, query: [String: String?] = [:], suggestedName: String) {
        self.label = label
        self.path = path
        self.query = query
        self.suggestedName = suggestedName
    }

    public var body: some View {
        Button {
            busy = true
            Task {
                defer { busy = false }
                do {
                    let (data, name, _) = try await env.session.client.download(path, query: query)
                    let url = FileManager.default.temporaryDirectory.appendingPathComponent(name ?? suggestedName)
                    try data.write(to: url)
                    fileURL = url
                    #if os(macOS)
                    NSWorkspace.shared.open(url)
                    #endif
                } catch {
                    env.toasts.error(error)
                }
            }
        } label: {
            if busy { ProgressView().controlSize(.small) } else { Label(label, systemImage: "arrow.down.doc") }
        }
        .disabled(busy)
        #if os(iOS)
        .sheet(item: $fileURL) { url in ShareSheet(items: [url]) }
        #endif
    }
}

extension URL: Identifiable { public var id: String { absoluteString } }

#if os(iOS)
public struct ShareSheet: UIViewControllerRepresentable {
    let items: [Any]
    public init(items: [Any]) { self.items = items }
    public func makeUIViewController(context: Context) -> UIActivityViewController { UIActivityViewController(activityItems: items, applicationActivities: nil) }
    public func updateUIViewController(_ vc: UIActivityViewController, context: Context) {}
}
#endif
