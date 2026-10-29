import SwiftUI
import BethanyKit

/// ⌘K: jump to any page the person may open, or search orders, customers
/// and products through `GET /admin/search` (GlobalSearchController).
public struct CommandPalette: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    @State private var query = ""
    @State private var results: [SearchHit] = []
    @State private var searching = false
    @FocusState private var focused: Bool

    public init() {}

    struct SearchHit: Identifiable, Hashable {
        let id: String
        let title: String
        let subtitle: String?
        let route: Route
        let symbol: String
    }

    private var pages: [NavItem] {
        let all = NavCatalogue.groups.compactMap { $0.visible(for: env.session.permissions) }.flatMap(\.items) + NavCatalogue.userMenu
        let q = query.trimmingCharacters(in: .whitespaces).lowercased()
        guard !q.isEmpty else { return Array(all.prefix(12)) }
        return all.filter { $0.label.lowercased().contains(q) }
    }

    public var body: some View {
        VStack(spacing: 0) {
            TextField("Search pages, orders, customers, products", text: $query)
                .textFieldStyle(.plain)
                .font(Theme.Font.display(18, weight: .medium))
                .padding(Theme.Space.l)
                .focused($focused)
                .onSubmit { if let first = pages.first { open(first.route) } else if let hit = results.first { open(hit.route) } }
            Divider()
            List {
                if !pages.isEmpty {
                    Section("Pages") {
                        ForEach(pages) { item in
                            Button { open(item.route) } label: { Label(item.label, systemImage: item.icon) }.buttonStyle(.plain)
                        }
                    }
                }
                if !results.isEmpty {
                    Section("Records") {
                        ForEach(results) { hit in
                            Button { open(hit.route) } label: {
                                Label { VStack(alignment: .leading) { Text(hit.title); if let s = hit.subtitle { Text(s).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) } } } icon: { Image(systemName: hit.symbol) }
                            }.buttonStyle(.plain)
                        }
                    }
                }
                if searching { ProgressView().controlSize(.small) }
            }
            .listStyle(.inset)
        }
        .frame(minWidth: 520, minHeight: 380)
        .onAppear { focused = true }
        .task(id: query) {
            let q = query.trimmingCharacters(in: .whitespaces)
            guard q.count >= 2 else { results = []; return }
            try? await Task.sleep(for: .milliseconds(250))
            guard !Task.isCancelled else { return }
            await search(q)
        }
    }

    private func open(_ route: Route) {
        dismiss()
        env.navigator.go(route)
    }

    private func search(_ q: String) async {
        searching = true
        defer { searching = false }
        guard let r = try? await env.session.client.get("/v1/admin/search", query: ["q": q, "types[]": nil]) else { return }
        var hits: [SearchHit] = []
        let json = r.json ?? .null
        for o in json["orders"]?.array ?? json["data"]?["orders"]?.array ?? [] {
            if let id = o["id"]?.int { hits.append(SearchHit(id: "o\(id)", title: "Order \(o["order_number"]?.string ?? "#\(id)")", subtitle: o["customer_name"]?.string ?? o["status"]?.string?.humanized, route: .order(id: id), symbol: "bag")) }
        }
        for c in json["customers"]?.array ?? json["data"]?["customers"]?.array ?? [] {
            if let id = c["id"]?.int { hits.append(SearchHit(id: "c\(id)", title: c["name"]?.string ?? "Customer #\(id)", subtitle: c["phone"]?.string ?? c["email"]?.string, route: .customer(id: id), symbol: "person")) }
        }
        for p in json["products"]?.array ?? json["data"]?["products"]?.array ?? [] {
            if let id = p["id"]?.int { hits.append(SearchHit(id: "p\(id)", title: p["name"]?.string ?? "Product #\(id)", subtitle: p["sku"]?.string, route: .product(id: id), symbol: "shippingbox")) }
        }
        results = hits
    }
}
