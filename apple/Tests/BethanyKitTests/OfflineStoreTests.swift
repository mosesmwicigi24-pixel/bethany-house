import Foundation
import XCTest
@testable import BethanyKit

final class OfflineStoreTests: XCTestCase {
    func testQueueOnlyAcknowledgesCountsAfterWritingAndKeepsTheLatestValue() async {
        let root = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString)
        let store = OfflineStore(root: root)
        defer { try? FileManager.default.removeItem(at: root) }

        let first = count(4)
        let latest = count(5)
        let firstSaved = await store.enqueue(first)
        let latestSaved = await store.enqueue(latest)
        let restored = await OfflineStore(root: root).pending()

        XCTAssertTrue(firstSaved)
        XCTAssertTrue(latestSaved)
        XCTAssertEqual(restored.map(\.id), [latest.id])
        XCTAssertEqual(restored.first?.body["quantity_done"]?.int, 5)
    }

    func testUnreadableQueueIsNotOverwrittenOrReportedSaved() async throws {
        let root = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString)
        try FileManager.default.createDirectory(at: root, withIntermediateDirectories: true)
        defer { try? FileManager.default.removeItem(at: root) }
        let queue = root.appendingPathComponent("pending-queue.json")
        let invalid = Data("invalid queue".utf8)
        try invalid.write(to: queue)

        let store = OfflineStore(root: root)
        let saved = await store.enqueue(count(6))

        XCTAssertFalse(saved)
        XCTAssertEqual(try Data(contentsOf: queue), invalid)
        do {
            _ = try await store.pendingChecked()
            XCTFail("An unreadable queue must not be reported as empty")
        } catch {
            XCTAssertEqual(try Data(contentsOf: queue), invalid)
        }
    }

    func testTemporaryServerResponsesKeepTheWorkForRetry() {
        for status in [0, 401, 408, 423, 429, 500, 503] {
            XCTAssertFalse(OfflineStore.isFinalRefusal(status), "HTTP \(status)")
        }
        XCTAssertTrue(OfflineStore.isFinalRefusal(403))
        XCTAssertTrue(OfflineStore.isFinalRefusal(422))
    }

    // MARK: One sending path (docs/hub-parity/PROGRESS.md, offline P0)

    /// The clock screen's "Send now" and the automatic replay used to send
    /// the same punch independently. Both now go through `send`, so a punch
    /// in flight is sent once, however many senders ask at the same moment.
    func testTwoSendersAtOnceSendAPunchOnce() async {
        let (store, root) = makeStore()
        defer { try? FileManager.default.removeItem(at: root) }
        let op = punch()
        await store.enqueue(op)
        let calls = Counter()
        let slow: @Sendable (OfflineStore.PendingOperation) async throws -> Void = { _ in
            await calls.add()
            try await Task.sleep(nanoseconds: 200_000_000)
        }

        async let manual = store.send(op.id, using: slow)
        async let replay = store.replay(using: slow)
        let (a, b) = await (manual, replay)

        let sent = await calls.value
        XCTAssertEqual(sent, 1, "the punch reached the server \(sent) times")
        XCTAssertTrue(a == .sent || b.sent.map(\.id) == [op.id])
        let left = await store.pending()
        XCTAssertTrue(left.isEmpty)
    }

    /// A second replay that meets an operation already in flight stops there:
    /// nothing queued after it may reach the server first.
    func testReplayKeepsOrderWhenAnotherSenderHoldsTheFirstItem() async {
        let (store, root) = makeStore()
        defer { try? FileManager.default.removeItem(at: root) }
        let first = punch()
        let second = count(7)
        await store.enqueue(first)
        await store.enqueue(second)
        let order = Recorder()
        let slow: @Sendable (OfflineStore.PendingOperation) async throws -> Void = { op in
            await order.append(op.id)
            try await Task.sleep(nanoseconds: 200_000_000)
        }

        async let holder = store.send(first.id, using: slow)
        try? await Task.sleep(nanoseconds: 50_000_000)
        let early = await store.replay(using: { op in await order.append(op.id) })
        _ = await holder

        XCTAssertTrue(early.sent.isEmpty, "the replay must not jump ahead of the punch in flight")
        let seen = await order.ids
        XCTAssertEqual(seen, [first.id])
        let left = await store.pending()
        XCTAssertEqual(left.map(\.id), [second.id])
    }

    /// A temporary failure keeps the work; a final refusal removes it and says so.
    func testTemporaryFailureKeepsAndFinalRefusalRemoves() async {
        let (store, root) = makeStore()
        defer { try? FileManager.default.removeItem(at: root) }
        let op = punch()
        await store.enqueue(op)

        let kept = await store.send(op.id, using: { _ in throw APIError(status: 503, message: "Busy") })
        XCTAssertEqual(kept, .keptForRetry)
        let stillThere = await store.pending()
        XCTAssertEqual(stillThere.map(\.id), [op.id])

        let refused = await store.send(op.id, using: { _ in throw APIError(status: 422, message: "Already clocked in") })
        XCTAssertEqual(refused, .refused(status: 422, message: "Already clocked in"))
        let gone = await store.pending()
        XCTAssertTrue(gone.isEmpty)
    }

    /// A punch saved with no signal is sent once on reconnect, with the time
    /// it was made (`recorded_at`), not the time it was sent (owner, 2026-11-24).
    func testAnOfflinePunchIsSentOnceWithItsOriginalTime() async {
        let (store, root) = makeStore()
        defer { try? FileManager.default.removeItem(at: root) }
        let madeAt = "2026-11-24T07:58:00+03:00"
        let op = OfflineStore.PendingOperation(kind: "punch", method: "POST", path: "/v1/admin/time-clock/clock-in",
                                               body: .object(["recorded_at": .string(madeAt), "outlet_id": .number(2)]), dedupeKey: "punch")
        let saved = await store.enqueue(op)
        XCTAssertTrue(saved)

        // Still offline: replay keeps it.
        let offlineTry = await store.replay(using: { _ in throw APIError.transport("offline") })
        XCTAssertTrue(offlineTry.sent.isEmpty)
        let waiting = await OfflineStore(root: root).pending()
        XCTAssertEqual(waiting.map(\.id), [op.id], "the punch survives on disk while offline")

        // Back online: sent once, with the original time.
        let seen = Recorder()
        let bodies = BodyRecorder()
        let online: @Sendable (OfflineStore.PendingOperation) async throws -> Void = { sent in
            await seen.append(sent.id)
            await bodies.append(sent.body)
        }
        let first = await store.replay(using: online)
        let second = await store.replay(using: online)
        XCTAssertEqual(first.sent.map(\.id), [op.id])
        XCTAssertTrue(second.sent.isEmpty)
        let ids = await seen.ids
        XCTAssertEqual(ids, [op.id], "sent exactly once")
        let sentBody = await bodies.values.first
        XCTAssertEqual(sentBody?["recorded_at"]?.string, madeAt)
        let left = await store.pending()
        XCTAssertTrue(left.isEmpty)
    }

    // MARK: Accepted, but the queue file could not be rewritten

    /// If the server accepted a change but the queue could not be updated,
    /// the change must not be sent again, and must not count as waiting.
    func testAcceptedButUnclearedIsNotSentAgain() async throws {
        let (store, root) = makeStore()
        defer { try? FileManager.default.removeItem(at: root) }
        let op = punch()
        await store.enqueue(op)
        let queue = root.appendingPathComponent("pending-queue.json")
        let aside = root.appendingPathComponent("aside.json")
        let calls = Counter()
        // While the request is out, the queue file is swapped for a folder, so
        // the store can neither read nor rewrite it (works even as root).
        let send: @Sendable (OfflineStore.PendingOperation) async throws -> Void = { _ in
            await calls.add()
            if FileManager.default.fileExists(atPath: queue.path) {
                try FileManager.default.moveItem(at: queue, to: aside)
                try FileManager.default.createDirectory(at: queue, withIntermediateDirectories: false)
                try Data("x".utf8).write(to: queue.appendingPathComponent("keep"))
            }
        }

        let outcome = await store.send(op.id, using: send)
        XCTAssertEqual(outcome, .sentButNotCleared)

        // The file is back, still holding the accepted punch.
        try FileManager.default.removeItem(at: queue)
        try FileManager.default.moveItem(at: aside, to: queue)
        let waiting = try await store.pendingChecked()
        XCTAssertTrue(waiting.isEmpty, "an accepted change must not show as waiting")
        let again = await store.replay(using: send)
        XCTAssertTrue(again.sent.isEmpty)
        let n = await calls.value
        XCTAssertEqual(n, 1, "the accepted punch was sent again")

        // The next successful write leaves it off disk for good.
        await store.enqueue(count(3))
        let onDisk = try await OfflineStore(root: root).pendingChecked()
        XCTAssertFalse(onDisk.contains { $0.id == op.id })
        XCTAssertEqual(onDisk.count, 1)
    }

    // MARK: Unreadable is never "nothing waiting"

    func testReplayOfAnUnreadableQueueSaysSoAndSendsNothing() async throws {
        let (store, root) = makeStore()
        defer { try? FileManager.default.removeItem(at: root) }
        try FileManager.default.createDirectory(at: root, withIntermediateDirectories: true)
        let invalid = Data("invalid queue".utf8)
        try invalid.write(to: root.appendingPathComponent("pending-queue.json"))
        let calls = Counter()

        let result = await store.replay(using: { _ in await calls.add() })

        XCTAssertTrue(result.unreadable)
        XCTAssertTrue(result.sent.isEmpty)
        let n = await calls.value
        XCTAssertEqual(n, 0)
        XCTAssertEqual(try Data(contentsOf: root.appendingPathComponent("pending-queue.json")), invalid)
    }

    private func makeStore() -> (OfflineStore, URL) {
        let root = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString)
        return (OfflineStore(root: root), root)
    }

    private func punch() -> OfflineStore.PendingOperation {
        .init(kind: "punch", method: "POST", path: "/v1/admin/time-clock/clock-in",
              body: .object(["recorded_at": .string("2026-11-23T08:00:00+03:00")]), dedupeKey: "punch")
    }

    private func count(_ pieces: Int) -> OfflineStore.PendingOperation {
        .init(kind: "task_progress", method: "POST", path: "/v1/tailor/tasks/1/progress",
              body: .object(["quantity_done": .number(Double(pieces))]), dedupeKey: "task-1-progress-2")
    }
}

private actor Counter {
    private(set) var value = 0
    func add() { value += 1 }
}

private actor Recorder {
    private(set) var ids: [UUID] = []
    func append(_ id: UUID) { ids.append(id) }
}

private actor BodyRecorder {
    private(set) var values: [JSONValue] = []
    func append(_ v: JSONValue) { values.append(v) }
}
