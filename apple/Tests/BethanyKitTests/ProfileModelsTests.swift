import XCTest
@testable import BethanyKit

/// Devices, activity and My employment. The me/file fixture is the real
/// response from a local Hub on a scratch database (2026-11-10), trimmed;
/// device and activity rows follow the console's DeviceRow / activity types
/// (the scratch Hub had none: `{"data":[]}` for both).
final class ProfileModelsTests: XCTestCase {
    private func json(_ s: String) -> JSONValue { try! HubJSON.decoder.decode(JSONValue.self, from: Data(s.utf8)) }

    func testTheDeviceInHandIsSignedOutNotRemovedAndAnAdminsNameIsLocked() throws {
        let rows = ProfileDevice.rows(json(#"{"data":[{"id":4,"label":"iPad (Westlands)","name":"Till iPad","name_source":"admin","named_by":"Grace","is_current":true,"last_seen":"2026-11-10 09:00:00","active_sessions":1},{"id":5,"label":"MacBook","name":null,"name_source":null,"is_current":false,"active_sessions":0}]}"#))
        let ipad = try XCTUnwrap(rows.first { $0.id == 4 })
        XCTAssertFalse(ipad.canRemove)
        XCTAssertFalse(ipad.canRename, "an admin named it")
        let mac = try XCTUnwrap(rows.first { $0.id == 5 })
        XCTAssertTrue(mac.canRemove)
        XCTAssertTrue(mac.canRename)
        XCTAssertNil(mac.name)
        XCTAssertEqual(mac.label, "MacBook")
        XCTAssertEqual(ProfileDevice.rows(json(#"{"data":[]}"#)), [])
    }

    func testANameIsTwoToSixtyCharactersOrClearedWhenEmpty() {
        XCTAssertTrue(ProfileDevice.isValidName(""))
        XCTAssertTrue(ProfileDevice.isValidName("   "))
        XCTAssertNil(ProfileDevice.nameValue("  "))
        XCTAssertFalse(ProfileDevice.isValidName("A"))
        XCTAssertTrue(ProfileDevice.isValidName("Till 1"))
        XCTAssertEqual(ProfileDevice.nameValue(" Till 1 "), "Till 1")
        XCTAssertTrue(ProfileDevice.isValidName(String(repeating: "x", count: 60)))
        XCTAssertFalse(ProfileDevice.isValidName(String(repeating: "x", count: 61)))
    }

    func testActivityReadsItsDescriptionOrItsAction() {
        let rows = ProfileActivity.rows(json(#"{"data":[{"id":1,"action":"payment_recorded","description":"Recorded PAY-1","created_at":"2026-11-10 09:00:00"},{"id":2,"action":"logged_in","description":"","created_at":"2026-11-10 08:00:00"}]}"#))
        XCTAssertEqual(rows.map(\.text), ["Recorded PAY-1", "Logged in"])
        XCTAssertNotNil(rows.first?.createdAt)
        XCTAssertEqual(ProfileActivity.rows(json(#"{"data":[],"meta":[]}"#)).count, 0, "the scratch Hub's answer")
    }

    func testMyFileShowsWhatTheBusinessHoldsWithIdentifiersMasked() {
        let me = MyEmployment(json: json(#"{"person":{"id":1,"name":"Super Admin","reports_to":null,"profile":{"job_title":null,"department":null,"employment_type":"permanent","hire_date":null,"date_of_birth":null,"emergency_contact_name":null,"emergency_contact_phone":null,"emergency_contact_relationship":null,"pay_method":null,"bank_name":null},"masked":{"national_id_no":null,"kra_pin":null,"nssf_no":null,"shif_no":null,"mpesa_phone":null,"bank_account_no":null},"documents":[]}}"#))
        XCTAssertEqual(me.employmentType, "permanent")
        XCTAssertNil(me.reportsTo)
        XCTAssertEqual(me.payMethodText, "—")
        XCTAssertEqual(me.masked.map(\.label), ["National ID", "KRA PIN", "NSSF", "SHIF (SHA)", "M-Pesa phone", "Bank account"])
        XCTAssertTrue(me.masked.allSatisfy { $0.value == nil })
        XCTAssertTrue(me.documents.isEmpty)
    }

    func testAPayMethodReadsAsTheConsolePrintsIt() {
        let me = MyEmployment(json: json(#"{"person":{"profile":{"pay_method":"bank","bank_name":"Equity"},"masked":{"kra_pin":"A0****21Z"},"documents":[{"id":9,"original_name":"Contract.pdf"}]}}"#))
        XCTAssertEqual(me.payMethodText, "Bank · Equity")
        XCTAssertEqual(me.masked.first { $0.label == "KRA PIN" }?.value, "A0****21Z")
        XCTAssertEqual(me.documents.first?.id, 9)
        XCTAssertEqual(me.documents.first?.name, "Contract.pdf")
    }
}
