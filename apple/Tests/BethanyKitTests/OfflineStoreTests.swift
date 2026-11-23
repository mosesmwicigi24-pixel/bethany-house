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

    private func count(_ pieces: Int) -> OfflineStore.PendingOperation {
        .init(kind: "task_progress", method: "POST", path: "/v1/tailor/tasks/1/progress",
              body: .object(["quantity_done": .number(Double(pieces))]), dedupeKey: "task-1-progress-2")
    }
}
