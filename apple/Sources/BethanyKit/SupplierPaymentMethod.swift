import Foundation

/// How money was paid to a supplier — mirrors `SupplierPayment::METHODS` and
/// `NEEDS_REFERENCE` (backend/app/Models/SupplierPayment.php) and the console's
/// list (react-admin/src/pages/procurement/SupplierPayments.tsx).
///
/// `im_bank` is the business's own I&M Bank account (owner, 2026-11-14), so
/// the bank is on the record rather than in a note; `bank_transfer` is any
/// other bank. Nothing is preselected: a payment is filed under the method
/// someone chose, never a default nobody looked at.
public enum SupplierPaymentMethod: String, CaseIterable, Sendable, Identifiable {
    case mpesa, cash, imBank = "im_bank", bankTransfer = "bank_transfer", cheque, card, other

    public var id: String { rawValue }

    public var label: String {
        switch self {
        case .mpesa: return "M-PESA"
        case .cash: return "Cash"
        case .imBank: return "I&M Bank"
        case .bankTransfer: return "Bank transfer (other bank)"
        case .cheque: return "Cheque"
        case .card: return "Card"
        case .other: return "Other"
        }
    }

    /// Methods that leave a trail the reference must name (the server refuses without one).
    public var needsReference: Bool {
        switch self {
        case .imBank, .bankTransfer, .mpesa, .cheque, .card: return true
        case .cash, .other: return false
        }
    }

    public var referenceHint: String {
        switch self {
        case .mpesa: return "M-PESA code, e.g. SKA1B2C3D4"
        case .imBank: return "I&M transaction reference"
        case .bankTransfer: return "Bank and transaction reference"
        case .cheque: return "Cheque number"
        case .card: return "Card slip or authorisation number"
        case .cash, .other: return "Receipt or note"
        }
    }

    /// The label for a stored method; an unknown value is shown as stored.
    public static func label(for raw: String?) -> String? {
        guard let raw else { return nil }
        return SupplierPaymentMethod(rawValue: raw)?.label ?? raw
    }

    /// May the form be sent: a positive amount, a chosen method, and a reference where one is needed.
    public static func canRecord(amount: Double?, method: SupplierPaymentMethod?, reference: String) -> Bool {
        guard let method, (amount ?? 0) > 0 else { return false }
        return !method.needsReference || !reference.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
    }
}
