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
            if !env.session.mayUseApp {
                WorkshopOnlyView()
            } else if sizeClass == .regular {
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

    var body: some View {
        let tabs = env.session.flavor.phoneTabs(for: env.session.permissions, home: env.session.home)
        TabView(selection: $tab) {
            ForEach(Array(tabs.enumerated()), id: \.offset) { i, item in
                NavigationStack(path: pathBinding(i)) {
                    RouteView(item.route)
                        .navigationDestination(for: Route.self) { RouteView($0) }
                }
                .environment(\.currentRoutePath, pathBinding(i))
                .tabItem { Label(item.label, systemImage: item.icon) }
                .tag(i)
            }
            NavigationStack(path: pathBinding(99)) {
                MoreList(tabRoutes: Set(tabs.map(\.route))) { route in
                    paths[99, default: []].append(route)
                }
                    .navigationDestination(for: Route.self) { RouteView($0) }
            }
            .environment(\.currentRoutePath, pathBinding(99))
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
    let tabRoutes: Set<Route>
    let open: (Route) -> Void
    @State private var unsent = 0

    private var groups: [NavGroup] {
        let groups = env.session.flavor.groups(for: env.session.permissions)
        guard env.session.flavor == .tailors else { return groups }
        let priority = groups.filter { $0.label == "Production" } + groups.filter { $0.label != "Production" }
        return priority.flatMap { group -> [NavGroup] in
            let items = group.items.filter { !tabRoutes.contains($0.route) }
            guard !items.isEmpty else { return [] }
            if group.label == "Me" {
                let personal: Set<Route> = [.myShifts, .myLeave, .myTimesheet, .myPayslips, .myAdvances]
                let workday = items.filter { !personal.contains($0.route) }
                let timeAndPay = items.filter { personal.contains($0.route) }
                return [NavGroup("My workday", workday), NavGroup("Time & pay", timeAndPay)]
                    .filter { !$0.items.isEmpty }
            }
            return [NavGroup(group.label == "Production" ? "Workshop" : group.label, items)]
        }
    }

    var body: some View {
        List {
            ForEach(groups) { group in
                Section(group.label) {
                    ForEach(group.items) { item in
                        row(item)
                            .badge(item.route == .peopleRequests ? env.peopleRequests : item.route == .notifications ? env.unreadNotifications : 0)
                    }
                }
            }
            .task { await env.refreshCounts() }
            Section {
                ForEach(env.session.flavor.userMenu) { item in row(item) }
                Button(role: .destructive) { Task { unsent = await env.requestSignOut() } } label: { Label("Sign out", systemImage: "rectangle.portrait.and.arrow.right") }
            } header: {
                Text(env.session.me?.name ?? "")
            }
        }
        .navigationTitle("More")
        .navigationBarTitleDisplayMode(.inline)
        .listStyle(.insetGrouped)
        .environment(\.defaultMinListRowHeight, Theme.tapTarget)
        .modifier(SignOutGuard(unsent: $unsent))
    }

    private func row(_ item: NavItem) -> some View {
        Button { open(item.route) } label: {
            HStack(spacing: 12) {
                Image(systemName: item.icon)
                    .font(.body)
                    .foregroundStyle(Theme.Palette.brandDeep)
                    .frame(width: 22)
                    .accessibilityHidden(true)
                Text(item.label).font(.subheadline).foregroundStyle(Theme.text)
                Spacer(minLength: 4)
                Image(systemName: "chevron.right")
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(Theme.textTertiary)
                    .accessibilityHidden(true)
            }
            .frame(minHeight: Theme.tapTarget)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .listRowInsets(EdgeInsets(top: 0, leading: 16, bottom: 0, trailing: 16))
    }
}

struct PadShell: View {
    @Environment(AppEnvironment.self) private var env
    @State private var visibility: NavigationSplitViewVisibility = .automatic
    @State private var unsent = 0

    var body: some View {
        @Bindable var nav = env.navigator
        NavigationSplitView(columnVisibility: $visibility) {
            List(selection: $nav.selection) {
                ForEach(env.session.flavor.groups(for: env.session.permissions)) { group in
                    Section(group.label) {
                        ForEach(group.items) { item in
                            NavigationLink(value: item.route) { Label(item.label, systemImage: item.icon) }
                                .badge(item.route == .peopleRequests ? env.peopleRequests : item.route == .notifications ? env.unreadNotifications : 0)
                        }
                    }
                }
            }
            .task {
                // Every minute while the iPad sidebar is up, as the console's sidebar does.
                while !Task.isCancelled {
                    await env.refreshCounts()
                    try? await Task.sleep(nanoseconds: 60_000_000_000)
                }
            }
            .navigationTitle(env.session.flavor == .tailors ? "Workshop" : "Hub")
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Menu {
                        ForEach(env.session.flavor.userMenu) { item in Button(item.label) { env.navigator.go(item.route) } }
                        Divider()
                        Button("Sign out", role: .destructive) { Task { unsent = await env.requestSignOut() } }
                    } label: { Image(systemName: "person.crop.circle") }
                }
            }
        } detail: {
            NavigationStack(path: $nav.path) {
                RouteView(nav.selection ?? env.session.home)
                    .id(nav.selection)
                    .navigationDestination(for: Route.self) { RouteView($0) }
                    .toolbar {
                        // Search reaches orders, customers and products: Bethany Hub's only.
                        if env.session.flavor == .hub {
                            ToolbarItem(placement: .topBarTrailing) {
                                Button { env.navigator.searchPresented = true } label: { Image(systemName: "magnifyingglass") }
                                    .keyboardShortcut("k", modifiers: [.command])
                            }
                        }
                    }
            }
        }
        .sheet(isPresented: $nav.searchPresented) { CommandPalette() }
        .onAppear { if nav.selection == nil { nav.selection = env.session.home } }
        .modifier(SignOutGuard(unsent: $unsent))
    }
}
#endif
