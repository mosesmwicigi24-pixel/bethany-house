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

    /// The queue as best known. An unreadable queue reads as empty here, so use
    /// `pendingChecked()` wherever "nothing waiting" must be true (sign-out,
    /// the pending badge, replay).
    public func pending() -> [PendingOperation] {
        (try? pendingChecked()) ?? []
    }

    /// Use when an unreadable queue must be distinguished from an empty one.
    /// Operations already accepted by the server are left out even when the
    /// file could not be rewritten afterwards (`delivered`).
    public func pendingChecked() throws -> [PendingOperation] {
        try readQueue().filter { !delivered.contains($0.id) }
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
            try store(queue)
            return true
        } catch {
            return false
        }
    }

    /// Removes an operation from the stored queue. False when the queue could
    /// not be read or rewritten; the operation then stays on disk.
    @discardableResult
    public func dequeue(_ id: UUID) -> Bool {
        do {
            var queue = try readQueue()
            guard queue.contains(where: { $0.id == id }) else { return true }
            queue.removeAll { $0.id == id }
            try store(queue)
            return true
        } catch {
            return false
        }
    }

    /// Writes the queue, dropping anything already delivered; once that write
    /// succeeds, nothing delivered remains on disk.
    private func store(_ queue: [PendingOperation]) throws {
        try write(HubJSON.encoder.encode(queue.filter { !delivered.contains($0.id) }), to: queueURL)
        delivered.removeAll()
    }

    // MARK: Sending (one path for replay and for a screen's "Send now")

    /// Operations being sent right now. A second sender for the same
    /// operation is told `.alreadySending` instead of sending it again.
    private var inFlight: Set<UUID> = []
    /// Sent and accepted, but the queue file could not be rewritten. Hidden
    /// from `pendingChecked()` and never sent again while the app runs; the
    /// next successful write (or `forgetDelivered`) clears it from disk.
    private var delivered: Set<UUID> = []

    public enum SendOutcome: Sendable, Equatable {
        /// Accepted by the server and removed from the queue.
        case sent
        /// Accepted by the server, but the queue file could not be updated.
        /// It will not be sent again while the app is running.
        case sentButNotCleared
        /// The server refused it for good (`isFinalRefusal`); removed.
        case refused(status: Int, message: String)
        /// Not sent (offline, temporary error, session expired); kept for retry.
        case keptForRetry
        /// Another sender has this operation in flight.
        case alreadySending
        /// No longer queued (sent, replaced by a newer value, or never queued).
        case notQueued
        /// The stored queue could not be read.
        case unreadable
    }

    /// Sends one queued operation exactly once at a time. Replay and the
    /// clock screen's resend both come here, so the same operation is never
    /// in flight twice.
    public func send(_ id: UUID, using transport: @Sendable (PendingOperation) async throws -> Void) async -> SendOutcome {
        guard !inFlight.contains(id) else { return .alreadySending }
        let queue: [PendingOperation]
        do { queue = try pendingChecked() } catch { return .unreadable }
        guard let op = queue.first(where: { $0.id == id }) else { return .notQueued }
        inFlight.insert(id)
        defer { inFlight.remove(id) }
        do {
            try await transport(op)
        } catch let e as APIError where Self.isFinalRefusal(e.status) {
            if !dequeue(id) { delivered.insert(id) }
            return .refused(status: e.status, message: e.message)
        } catch {
            return .keptForRetry
        }
        if dequeue(id) { return .sent }
        delivered.insert(id)
        return .sentButNotCleared
    }

    /// The Hub client as a transport.
    public static func transport(_ client: APIClient) -> @Sendable (PendingOperation) async throws -> Void {
        { op in
            guard let method = APIClient.Method(rawValue: op.method) else {
                throw APIError(status: 400, message: "This saved change cannot be sent (unknown method \(op.method)).")
            }
            _ = try await client.send(.init(method, op.path, body: .json(op.body)))
        }
    }

    public struct ReplayResult: Sendable {
        public var sent: [PendingOperation] = []
        public var refused: [(PendingOperation, APIError)] = []
        /// Accepted by the server but still in the stored queue file.
        public var notCleared: [PendingOperation] = []
        /// The stored queue could not be read: nothing was sent, nothing lost.
        public var unreadable = false
    }

    /// Replays the queue in order. A final 4xx drops the item (the server
    /// refused it; the caller reports `refused`); a transport failure stops
    /// and keeps the rest. An unreadable queue is reported, never treated as empty.
    public func replay(with client: APIClient) async -> ReplayResult {
        await replay(using: Self.transport(client))
    }

    public func replay(using transport: @Sendable (PendingOperation) async throws -> Void) async -> ReplayResult {
        var result = ReplayResult()
        let queue: [PendingOperation]
        do { queue = try pendingChecked() } catch { result.unreadable = true; return result }
        for op in queue {
            switch await send(op.id, using: transport) {
            case .sent: result.sent.append(op)
            case .sentButNotCleared: result.sent.append(op); result.notCleared.append(op)
            case .refused(let status, let message): result.refused.append((op, APIError(status: status, message: message)))
            case .notQueued: continue
            // Another sender has this one in flight: stop here so nothing
            // after it reaches the server first. That sender carries on.
            case .alreadySending: return result
            case .unreadable: result.unreadable = true; return result
            case .keptForRetry: return result
            }
        }
        return result
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
    /// The saved queue could not be read: `pendingCount` is not known.
    public var pendingUnreadable = false
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
