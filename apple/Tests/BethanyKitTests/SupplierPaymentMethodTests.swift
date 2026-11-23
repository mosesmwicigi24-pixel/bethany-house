import XCTest
@testable import BethanyKit

/// Supplier payment methods mirror SupplierPayment::METHODS / NEEDS_REFERENCE
/// (backend/app/Models/SupplierPayment.php, 2026-11-14) — fixture typed from
/// the PHP constants, not from this enum.
final class SupplierPaymentMethodTests: XCTestCase {
    private let serverMethods = ["mpesa", "cash", "im_bank", "bank_transfer", "cheque", "card", "other"]
    private let serverNeedsReference: Set<String> = ["im_bank", "bank_transfer", "mpesa", "cheque", "card"]

    func testTheMethodsAreExactlyTheServersInItsOrder() {
        XCTAssertEqual(SupplierPaymentMethod.allCases.map(\.rawValue), serverMethods)
        XCTAssertEqual(Set(SupplierPaymentMethod.allCases.filter(\.needsReference).map(\.rawValue)), serverNeedsReference)
    }

    func testIAndMIsItsOwnMethodNotABankTransfer() {
        XCTAssertEqual(SupplierPaymentMethod.label(for: "im_bank"), "I&M Bank")
        XCTAssertEqual(SupplierPaymentMethod.label(for: "bank_transfer"), "Bank transfer (other bank)")
        XCTAssertEqual(SupplierPaymentMethod.label(for: "legacy_thing"), "legacy_thing", "an unknown stored value is shown as stored")
        XCTAssertNil(SupplierPaymentMethod.label(for: nil))
    }

    func testNothingIsRecordedUnderAMethodNobodyChose() {
        XCTAssertFalse(SupplierPaymentMethod.canRecord(amount: 5_000, method: nil, reference: "ABC"))
        XCTAssertTrue(SupplierPaymentMethod.canRecord(amount: 5_000, method: .cash, reference: ""))
    }

    func testAReferenceIsRequiredWhereTheMoneyLeavesATrail() {
        XCTAssertFalse(SupplierPaymentMethod.canRecord(amount: 5_000, method: .imBank, reference: "  "))
        XCTAssertTrue(SupplierPaymentMethod.canRecord(amount: 5_000, method: .imBank, reference: "IM-778812"))
        XCTAssertFalse(SupplierPaymentMethod.canRecord(amount: 0, method: .cash, reference: ""))
        XCTAssertFalse(SupplierPaymentMethod.canRecord(amount: nil, method: .cash, reference: ""))
    }
}
