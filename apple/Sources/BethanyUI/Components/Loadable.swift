import SwiftUI
import BethanyKit

/// The state of anything fetched from the Hub, so every screen shows the
/// same skeleton, empty state and error state (Phase 10 UX expectations).
public enum Loadable<T: Sendable>: Sendable {
    case idle
    case loading(previous: T?)
    case loaded(T)
    case failed(APIError, previous: T?)

    public var value: T? {
        switch self {
        case .loaded(let v): return v
        case .loading(let p), .failed(_, let p): return p
        case .idle: return nil
        }
    }

    public var isLoading: Bool {
        if case .loading = self { return true }
        return false
    }

    public var error: APIError? {
        if case .failed(let e, _) = self { return e }
        return nil
    }
}

/// Runs a fetch into a `Loadable`, keeping the previous value on screen while
/// reloading and surfacing an offline cache when the first fetch fails.
@MainActor
@Observable
public final class Resource<T: Sendable> {
    public private(set) var state: Loadable<T> = .idle
    private let fetch: @Sendable () async throws -> T
    private var task: Task<Void, Never>?

    public init(_ fetch: @escaping @Sendable () async throws -> T) {
        self.fetch = fetch
    }

    public var value: T? { state.value }

    public func load(background: Bool = false) {
        task?.cancel()
        task = Task { await self.run() }
    }

    public func reload() async { await run() }

    private func run() async {
        state = .loading(previous: state.value)
        do {
            let v = try await fetch()
            if !Task.isCancelled { state = .loaded(v) }
        } catch let e as APIError {
            if !Task.isCancelled { state = .failed(e, previous: state.value) }
        } catch is CancellationError {
        } catch {
            if !Task.isCancelled { state = .failed(APIError.transport(error.localizedDescription), previous: state.value) }
        }
    }

    public func set(_ v: T) { state = .loaded(v) }
}

/// A paginated list from a Laravel paginator: loads page 1, appends on demand.
@MainActor
@Observable
public final class PagedList<T: Decodable & Sendable & Identifiable> {
    public private(set) var items: [T] = []
    public private(set) var total: Int = 0
    public private(set) var state: Loadable<[T]> = .idle
    public private(set) var isLoadingMore = false
    public var query: [String: String?] = [:]
    public var perPage = 25

    private let client: APIClient
    private let path: String
    private var nextPage: Int?
    private var token = UUID()

    public init(client: APIClient, path: String, query: [String: String?] = [:]) {
        self.client = client
        self.path = path
        self.query = query
    }

    public var hasMore: Bool { nextPage != nil }

    public func load() async {
        let my = UUID()
        token = my
        state = .loading(previous: items.isEmpty ? nil : items)
        do {
            let page = try await fetchPage(1)
            guard token == my else { return }
            items = page.data
            total = page.total
            nextPage = page.nextPage
            state = .loaded(items)
        } catch let e as APIError {
            guard token == my else { return }
            state = .failed(e, previous: items.isEmpty ? nil : items)
        } catch {
            guard token == my else { return }
            state = .failed(APIError.transport(error.localizedDescription), previous: items.isEmpty ? nil : items)
        }
    }

    public func loadMore() async {
        guard let page = nextPage, !isLoadingMore else { return }
        isLoadingMore = true
        defer { isLoadingMore = false }
        if let p = try? await fetchPage(page) {
            items.append(contentsOf: p.data)
            nextPage = p.nextPage
            state = .loaded(items)
        }
    }

    private func fetchPage(_ page: Int) async throws -> Paginated<T> {
        var q = query
        q["page"] = String(page)
        q["per_page"] = String(perPage)
        let r = try await client.get(path, query: q)
        // Some endpoints return a bare array; wrap it.
        if let arr = r.json?.array {
            let decoded = try arr.map { try HubJSON.decoder.decode(T.self, from: HubJSON.encoder.encode($0)) }
            return Paginated(data: decoded)
        }
        if let inner = r.json?["data"]?["data"], inner.array != nil, let nested = r.json?["data"] {
            // {data: {data: [...], current_page: ...}}
            return try HubJSON.decoder.decode(Paginated<T>.self, from: HubJSON.encoder.encode(nested))
        }
        return try r.decode(Paginated<T>.self)
    }

    public func remove(where predicate: (T) -> Bool) {
        items.removeAll(where: predicate)
        state = .loaded(items)
    }

    public func replace(_ item: T) {
        if let i = items.firstIndex(where: { $0.id == item.id }) { items[i] = item; state = .loaded(items) }
    }
}
