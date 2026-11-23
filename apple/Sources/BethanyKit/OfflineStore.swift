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
    private var isReplaying = false

    public init(userId: Int) {
        let base = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first!
        root = base.appendingPathComponent("BethanyHub/user-\(userId)", isDirectory: true)
    }

    init(root: URL) {
        self.root = root
    }

    private func url(_ key: String) -> URL {
        let safe = key.replacingOccurrences(of: "/", with: "_").replacingOccurrences(of: "?", with: "_")
        return root.appendingPathComponent("\(safe).json")
    }

    private func write(_ data: Data, to url: URL) throws {
        try FileManager.default.createDirectory(at: root, withIntermediateDirectories: true)
        #if os(iOS)
        try data.write(to: url, options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication])
        #else
        try data.write(to: url, options: [.atomic])
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
        try? write(data, to: url(key))
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
        (try? readQueue()) ?? []
    }

    /// Use when an unreadable queue must be distinguished from an empty one.
    public func pendingChecked() throws -> [PendingOperation] {
        try readQueue()
    }

    private func readQueue() throws -> [PendingOperation] {
        do {
            return try HubJSON.decoder.decode([PendingOperation].self, from: Data(contentsOf: queueURL))
        } catch CocoaError.fileReadNoSuchFile {
            return []
        }
    }

    /// True only after the new queue is stored. An unreadable queue cannot be
    /// replaced with an empty one, since it may contain work we still owe the Hub.
    @discardableResult
    public func enqueue(_ op: PendingOperation) -> Bool {
        do {
            var queue = try readQueue()
            if let key = op.dedupeKey { queue.removeAll { $0.dedupeKey == key } }
            queue.append(op)
            try write(HubJSON.encoder.encode(queue), to: queueURL)
            return true
        } catch {
            return false
        }
    }

    public func dequeue(_ id: UUID) {
        guard var queue = try? readQueue(), queue.contains(where: { $0.id == id }) else { return }
        queue.removeAll { $0.id == id }
        if let data = try? HubJSON.encoder.encode(queue) { try? write(data, to: queueURL) }
    }

    /// Replays the queue. A final 4xx drops the item (the server refused it;
    /// the caller reports `refused`); a transport failure stops and keeps the rest.
    public func replay(with client: APIClient) async -> (sent: [PendingOperation], refused: [(PendingOperation, APIError)]) {
        guard !isReplaying else { return ([], []) }
        isReplaying = true
        defer { isReplaying = false }
        var sent: [PendingOperation] = []
        var refused: [(PendingOperation, APIError)] = []
        for op in pending() {
            // A newer value may have replaced an unsent count while a previous
            // request was in flight. Only replay the still-queued operations.
            guard pending().contains(where: { $0.id == op.id }) else { continue }
            guard let method = APIClient.Method(rawValue: op.method) else { dequeue(op.id); continue }
            do {
                _ = try await client.send(.init(method, op.path, body: .json(op.body)))
                dequeue(op.id)
                sent.append(op)
            } catch let e as APIError where Self.isFinalRefusal(e.status) {
                dequeue(op.id)
                refused.append((op, e))
            } catch {
                break
            }
        }
        return (sent, refused)
    }

    public static func isFinalRefusal(_ status: Int) -> Bool {
        (400..<500).contains(status) && ![401, 408, 423, 429].contains(status)
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
