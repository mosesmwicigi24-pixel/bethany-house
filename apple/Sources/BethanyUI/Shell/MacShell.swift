#if os(macOS)
import SwiftUI
import BethanyKit

/// The macOS window: a three-column `NavigationSplitView` with the Hub
/// sidebar (groups collapse, selection restores), a content column that
/// renders the selected route, and a detail column for records opened from
/// lists. Keyboard shortcuts and menus live in `HubCommands`.
public struct MacShell: View {
    @Environment(AppEnvironment.self) private var env
    @SceneStorage("bh.sidebar.selection") private var storedSelection: String?
    @State private var columnVisibility: NavigationSplitViewVisibility = .all

    public init() {}

    public var body: some View {
        @Bindable var nav = env.navigator
        NavigationSplitView(columnVisibility: $columnVisibility) {
            SidebarView()
                .navigationSplitViewColumnWidth(min: 220, ideal: 250, max: 320)
        } detail: {
            NavigationStack(path: $nav.path) {
                Group {
                    if let route = nav.selection {
                        RouteView(route)
                            .id(route)
                    } else {
                        RouteView(env.session.home)
                    }
                }
                .navigationDestination(for: Route.self) { RouteView($0) }
            }
            .background(Theme.canvas)
        }
        .navigationSplitViewStyle(.balanced)
        .toolbar {
            ToolbarItemGroup(placement: .primaryAction) {
                Button { env.navigator.go(.notifications) } label: { Label("Notifications", systemImage: "bell") }
                    .badge(env.unreadNotifications)
                    .help("Notifications")
                Button { env.navigator.searchPresented = true } label: { Label("Search", systemImage: "magnifyingglass") }
                    .help("Search (⌘K)")
                Button { Task { await env.session.refreshMe(background: false) } } label: { Label("Refresh", systemImage: "arrow.clockwise") }
                    .help("Refresh (⌘R)")
            }
        }
        .sheet(isPresented: $nav.searchPresented) { CommandPalette() }
        .onAppear {
            if nav.selection == nil {
                nav.selection = storedSelection.flatMap(Route.from(consolePath:)).flatMap { env.session.allows(NavCatalogue.item(for: $0)?.gate ?? .open) ? $0 : nil } ?? env.session.home
            }
        }
        .onChange(of: nav.selection) { _, new in storedSelection = new?.consolePath }
        .frame(minWidth: 960, minHeight: 600)
        .securityPrompts()
        .tracksActivity()
        .overlay(alignment: .bottom) { ToastHost(env.toasts) }
    }
}

struct SidebarView: View {
    @Environment(AppEnvironment.self) private var env
    @SceneStorage("bh.sidebar.collapsed") private var collapsedCSV = ""

    var body: some View {
        @Bindable var nav = env.navigator
        let subject = env.session.permissions
        List(selection: $nav.selection) {
            ForEach(NavCatalogue.groups.compactMap { $0.visible(for: subject) }) { group in
                Section(isExpanded: expandedBinding(group.label)) {
                    ForEach(group.items) { item in
                        NavigationLink(value: item.route) {
                            Label(item.label, systemImage: item.icon)
                        }
                        .badge(badge(for: item.route))
                    }
                } header: {
                    Text(group.label)
                }
            }
        }
        .listStyle(.sidebar)
        .safeAreaInset(edge: .bottom) { SidebarFooter() }
    }

    private func badge(for route: Route) -> Int {
        switch route {
        case .notifications: return env.unreadNotifications
        default: return 0
        }
    }

    private func expandedBinding(_ label: String) -> Binding<Bool> {
        Binding(
            get: { !collapsedCSV.split(separator: "|").contains(Substring(label)) },
            set: { expanded in
                var set = Set(collapsedCSV.split(separator: "|").map(String.init))
                if expanded { set.remove(label) } else { set.insert(label) }
                collapsedCSV = set.sorted().joined(separator: "|")
            }
        )
    }
}

struct SidebarFooter: View {
    @Environment(AppEnvironment.self) private var env
    var body: some View {
        VStack(spacing: 6) {
            if !env.connection.isOnline {
                Label("Offline", systemImage: "wifi.slash").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning)
            }
            if env.connection.pendingCount > 0 {
                Label("\(env.connection.pendingCount) pending", systemImage: "arrow.triangle.2.circlepath").font(Theme.Font.caption).foregroundStyle(Theme.Palette.amber)
            }
            Menu {
                ForEach(NavCatalogue.userMenu) { item in
                    Button(item.label) { env.navigator.go(item.route) }
                }
                Divider()
                Button("Sign out") { Task { await env.session.signOut(); await env.sessionDidChange() } }
            } label: {
                HStack {
                    Image(systemName: "person.crop.circle.fill").foregroundStyle(Theme.Palette.brand)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(env.session.me?.name ?? "").font(Theme.Font.secondary).lineLimit(1)
                        Text(env.session.me?.roleNames.first?.humanized ?? "").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                    }
                    Spacer()
                }
                .contentShape(Rectangle())
            }
            .menuStyle(.borderlessButton)
        }
        .padding(Theme.Space.m)
        .background(.bar)
    }
}

/// Application menu and shortcuts (Phase 3). Navigation items get ⌘1…⌘9
/// for the first nine visible sidebar pages; search is ⌘K.
public struct HubCommands: Commands {
    let env: AppEnvironment
    public init(env: AppEnvironment) { self.env = env }

    public var body: some Commands {
        CommandGroup(replacing: .newItem) {
            Button("New Order…") { env.navigator.go(.pos) }.keyboardShortcut("n", modifiers: [.command])
            Button("New Customer…") { env.navigator.go(.customers) }.keyboardShortcut("n", modifiers: [.command, .shift])
        }
        CommandMenu("Go") {
            Button("Home") { env.navigator.go(env.session.home) }.keyboardShortcut("h", modifiers: [.command, .shift])
            Button("Search…") { env.navigator.searchPresented = true }.keyboardShortcut("k", modifiers: [.command])
            Divider()
            let visible = NavCatalogue.groups.compactMap { $0.visible(for: env.session.permissions) }.flatMap(\.items).prefix(9)
            ForEach(Array(visible.enumerated()), id: \.element.id) { i, item in
                Button(item.label) { env.navigator.go(item.route) }
                    .keyboardShortcut(KeyEquivalent(Character(String(i + 1))), modifiers: [.command])
            }
        }
        CommandGroup(after: .toolbar) {
            Button("Refresh") { Task { await env.session.refreshMe(background: false) } }.keyboardShortcut("r", modifiers: [.command])
        }
        CommandGroup(replacing: .appSettings) {
            Button("Settings…") { env.navigator.go(.profile) }.keyboardShortcut(",", modifiers: [.command])
        }
    }
}
#endif
