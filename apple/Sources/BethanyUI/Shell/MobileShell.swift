#if os(iOS)
import SwiftUI
import BethanyKit

/// iPhone and iPad shell (Steps 4–5).
///
/// iPhone: the console's bottom tab bar (Home, Messages, Me, Production,
/// Orders, More) with a navigation stack per tab; "More" is the full sidebar
/// as a list. iPad: the same `NavigationSplitView` sidebar as the Mac, with
/// a detail column, so the tablet is a workstation rather than a large phone.
public struct MobileShell: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.horizontalSizeClass) private var sizeClass

    public init() {}

    public var body: some View {
        Group {
            if sizeClass == .regular {
                PadShell()
            } else {
                PhoneShell()
            }
        }
        .securityPrompts()
        .tracksActivity()
        .overlay(alignment: .bottom) { ToastHost(env.toasts).padding(.bottom, 60) }
        .overlay { BiometricLockOverlay(lock: env.biometrics) { await env.session.signOut(); await env.sessionDidChange() } }
    }
}

struct PhoneShell: View {
    @Environment(AppEnvironment.self) private var env
    @State private var tab: Int = 0
    @State private var paths: [Int: [Route]] = [:]
    @State private var moreRoute: Route?

    var body: some View {
        let tabs = NavCatalogue.phoneTabs(for: env.session.permissions, home: env.session.home)
        TabView(selection: $tab) {
            ForEach(Array(tabs.enumerated()), id: \.offset) { i, item in
                NavigationStack(path: pathBinding(i)) {
                    RouteView(item.route)
                        .navigationDestination(for: Route.self) { RouteView($0) }
                }
                .tabItem { Label(item.label, systemImage: item.icon) }
                .tag(i)
            }
            NavigationStack(path: pathBinding(99)) {
                MoreList()
                    .navigationDestination(for: Route.self) { RouteView($0) }
            }
            .tabItem { Label("More", systemImage: "line.3.horizontal") }
            .tag(99)
        }
        .tint(Theme.Palette.brandDeep)
        .onChange(of: env.navigator.path) { _, p in
            // Programmatic navigation (push tap, deep link, palette): route into the right tab.
            guard let target = env.navigator.selection else { return }
            if let i = tabs.firstIndex(where: { $0.route == target }) { tab = i; paths[i] = p }
            else { tab = 99; paths[99] = [target] + p }
        }
        .onChange(of: env.navigator.selection) { _, s in
            guard let s else { return }
            if let i = tabs.firstIndex(where: { $0.route == s }) { tab = i; paths[i] = env.navigator.path }
            else { tab = 99; paths[99] = [s] + env.navigator.path }
        }
    }

    private func pathBinding(_ i: Int) -> Binding<[Route]> {
        Binding(get: { paths[i] ?? [] }, set: { paths[i] = $0 })
    }
}

struct MoreList: View {
    @Environment(AppEnvironment.self) private var env
    var body: some View {
        List {
            ForEach(NavCatalogue.groups.compactMap { $0.visible(for: env.session.permissions) }) { group in
                Section(group.label) {
                    ForEach(group.items) { item in
                        NavigationLink(value: item.route) { Label(item.label, systemImage: item.icon) }
                    }
                }
            }
            Section {
                ForEach(NavCatalogue.userMenu) { item in NavigationLink(value: item.route) { Label(item.label, systemImage: item.icon) } }
                Button(role: .destructive) { Task { await env.session.signOut(); await env.sessionDidChange() } } label: { Label("Sign out", systemImage: "rectangle.portrait.and.arrow.right") }
            } header: {
                Text(env.session.me?.name ?? "")
            }
        }
        .navigationTitle("More")
        .listStyle(.insetGrouped)
    }
}

struct PadShell: View {
    @Environment(AppEnvironment.self) private var env
    @State private var visibility: NavigationSplitViewVisibility = .automatic

    var body: some View {
        @Bindable var nav = env.navigator
        NavigationSplitView(columnVisibility: $visibility) {
            List(selection: $nav.selection) {
                ForEach(NavCatalogue.groups.compactMap { $0.visible(for: env.session.permissions) }) { group in
                    Section(group.label) {
                        ForEach(group.items) { item in
                            NavigationLink(value: item.route) { Label(item.label, systemImage: item.icon) }
                        }
                    }
                }
            }
            .navigationTitle("Hub")
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Menu {
                        ForEach(NavCatalogue.userMenu) { item in Button(item.label) { env.navigator.go(item.route) } }
                        Divider()
                        Button("Sign out", role: .destructive) { Task { await env.session.signOut(); await env.sessionDidChange() } }
                    } label: { Image(systemName: "person.crop.circle") }
                }
            }
        } detail: {
            NavigationStack(path: $nav.path) {
                RouteView(nav.selection ?? env.session.home)
                    .id(nav.selection)
                    .navigationDestination(for: Route.self) { RouteView($0) }
                    .toolbar {
                        ToolbarItem(placement: .topBarTrailing) {
                            Button { env.navigator.searchPresented = true } label: { Image(systemName: "magnifyingglass") }
                                .keyboardShortcut("k", modifiers: [.command])
                        }
                    }
            }
        }
        .sheet(isPresented: $nav.searchPresented) { CommandPalette() }
        .onAppear { if nav.selection == nil { nav.selection = env.session.home } }
    }
}
#endif
