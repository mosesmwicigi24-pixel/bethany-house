import Foundation
import Observation
import Network

/// Read cache and pending-operation queues (Phase 11), scoped per person.
///
/// Policy (docs/NATIVE_APPS_AUDIT.md §5): money writes are never queued. What
/// may wait for a connection is exactly what the console already queues: one
/// clock punch, tailor task status and progress, to-do ticks, course
/// progress, form drafts. Everything the person has opened is cached for
/// offline reading and purged on sign-out.
///
/// Storage is the app's Application Support directory with
/// `.completeUntilFirstUserAuthentication` file protection on iOS; on macOS
/// the user's own login keychain and FileVault are the boundary.
public actor OfflineStore {
    public let root: URL
    private let userId: Int

    public init(userId: Int) {
        self.userId = userId
        let base = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first!
        root = base.appendingPathComponent("BethanyHub/user-\(userId)", isDirectory: true)
        try? FileManager.default.createDirectory(at: root, withIntermediateDirectories: true)
    }

    private func url(_ key: String) -> URL {
        let safe = key.replacingOccurrences(of: "/", with: "_").replacingOccurrences(of: "?", with: "_")
        return root.appendingPathComponent("\(safe).json")
    }

    private func write(_ data: Data, to url: URL) {
        #if os(iOS)
        try? data.write(to: url, options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication])
        #else
        try? data.write(to: url, options: [.atomic])
        #endif
    }

    // MARK: Read cache

    public struct Cached<T: Sendable>: Sendable {
        public let value: T
        public let at: Date
        public var age: TimeInterval { Date().timeIntervalSince(at) }
    }

    private struct Envelope: Codable { let at: Date; let json: JSONValue }

    public func put(_ json: JSONValue, for key: String) {
        guard let data = try? HubJSON.encoder.encode(Envelope(at: Date(), json: json)) else { return }
        write(data, to: url(key))
    }

    public func get(_ key: String) -> Cached<JSONValue>? {
        guard let data = try? Data(contentsOf: url(key)), let env = try? HubJSON.decoder.decode(Envelope.self, from: data) else { return nil }
        return Cached(value: env.json, at: env.at)
    }

    public func remove(_ key: String) {
        try? FileManager.default.removeItem(at: url(key))
    }

    // MARK: Pending operations

    /// A queued write, replayed in order when the connection returns. The
    /// `dedupeKey` collapses repeats (one punch slot; one progress per stage).
    public struct PendingOperation: Codable, Sendable, Identifiable {
        public let id: UUID
        public let kind: String
        public let method: String
        public let path: String
        public let body: JSONValue
        public let dedupeKey: String?
        public let recordedAt: Date

        public init(kind: String, method: String, path: String, body: JSONValue, dedupeKey: String? = nil) {
            id = UUID(); self.kind = kind; self.method = method; self.path = path; self.body = body; self.dedupeKey = dedupeKey; recordedAt = Date()
        }
    }

    private var queueURL: URL { root.appendingPathComponent("pending-queue.json") }

    public func pending() -> [PendingOperation] {
        guard let data = try? Data(contentsOf: queueURL), let q = try? HubJSON.decoder.decode([PendingOperation].self, from: data) else { return [] }
        return q
    }

    public func enqueue(_ op: PendingOperation) {
        var q = pending()
        if let k = op.dedupeKey { q.removeAll { $0.dedupeKey == k } }
        q.append(op)
        if let data = try? HubJSON.encoder.encode(q) { write(data, to: queueURL) }
    }

    public func dequeue(_ id: UUID) {
        var q = pending()
        q.removeAll { $0.id == id }
        if let data = try? HubJSON.encoder.encode(q) { write(data, to: queueURL) }
    }

    /// Replays the queue. A final 4xx drops the item (the server refused it;
    /// the caller reports `refused`); a transport failure stops and keeps the rest.
    public func replay(with client: APIClient) async -> (sent: [PendingOperation], refused: [(PendingOperation, APIError)]) {
        var sent: [PendingOperation] = []
        var refused: [(PendingOperation, APIError)] = []
        for op in pending() {
            guard let method = APIClient.Method(rawValue: op.method) else { dequeue(op.id); continue }
            do {
                _ = try await client.send(.init(method, op.path, body: .json(op.body)))
                dequeue(op.id)
                sent.append(op)
            } catch let e as APIError where e.status >= 400 && e.status < 500 && e.status != 401 && e.status != 423 {
                dequeue(op.id)
                refused.append((op, e))
            } catch {
                break
            }
        }
        return (sent, refused)
    }

    public func purge() {
        try? FileManager.default.removeItem(at: root)
    }
}

/// Reachability as the apps show it (status pill, pending badge).
@MainActor
@Observable
public final class ConnectionMonitor {
    public private(set) var isOnline = true
    public private(set) var isExpensive = false
    public var pendingCount = 0
    private let monitor = NWPathMonitor()

    public init() {
        monitor.pathUpdateHandler = { [weak self] path in
            Task { @MainActor in
                self?.isOnline = path.status == .satisfied
                self?.isExpensive = path.isExpensive
            }
        }
        monitor.start(queue: DispatchQueue(label: "bh.connection"))
    }
}
