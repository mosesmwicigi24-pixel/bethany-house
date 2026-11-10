import XCTest
@testable import BethanyKit

/// The super admin's record history. The fixture is the real answer of a
/// local Hub on a scratch database (2026-11-10, order 5 after its payment
/// was deleted), trimmed to one entry.
final class RecordHistoryTests: XCTestCase {
    private func json(_ s: String) -> JSONValue { try! HubJSON.decoder.decode(JSONValue.self, from: Data(s.utf8)) }

    func testTheChangesInsideThePropertiesStringAreRead() throws {
        let page = RecordHistoryEntry.page(json(#"{"current_page":1,"data":[{"id":287,"log_name":"model","description":"Updated Order #5: payment_status partial → pending","subject_type":"App\\Models\\Order","subject_id":5,"event":"updated","causer_type":"App\\Models\\User","causer_id":1,"properties":"{\"_audit\": {\"roles\": [\"super_admin\"], \"channel\": \"web_console\", \"outcome\": \"success\", \"token_id\": \"pat:4\", \"outlet_id\": 1, \"outlet_source\": \"subject\"}, \"changes\": {\"payment_status\": {\"new\": \"pending\", \"old\": \"partial\"}}}","batch_uuid":null,"action":"updated","ip_address":"127.0.0.1","user_agent":"curl\/8.5.0","created_at":"2026-11-10 11:21:12","updated_at":"2026-11-10 11:21:12","request_id":"6cf9f322-c0a6-4e4d-bcdf-2236800e6d87","user_name":"Super Admin","user_email":"mwicigi@icloud.com"}],"last_page":3,"total":5}"#))
        XCTAssertEqual(page.page, 1)
        XCTAssertEqual(page.lastPage, 3)
        let e = try XCTUnwrap(page.entries.first)
        XCTAssertEqual(e.action, "updated")
        XCTAssertEqual(e.who, "Super Admin")
        XCTAssertEqual(e.ip, "127.0.0.1")
        XCTAssertNotNil(e.createdAt)
        XCTAssertEqual(e.changes, [.init(field: "payment_status", old: "partial", new: "pending")])
    }

    func testAnEntryWithoutChangesOrAPersonIsTheSystems() throws {
        let e = try XCTUnwrap(RecordHistoryEntry(json: json(#"{"id":277,"description":"Created Order #5","event":"created","properties":{"attributes":{}},"user_name":"  ","created_at":"2026-11-10 08:41:18"}"#)))
        XCTAssertEqual(e.who, "System")
        XCTAssertEqual(e.action, "created")
        XCTAssertTrue(e.changes.isEmpty)
        let n = try XCTUnwrap(RecordHistoryEntry(json: json(#"{"id":3,"properties":{"changes":{"discount":{"old":null,"new":150}}}}"#)))
        XCTAssertEqual(n.changes, [.init(field: "discount", old: "—", new: "150")])
    }
}
