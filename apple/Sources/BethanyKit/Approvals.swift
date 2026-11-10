import Foundation

/// The tabs of the Approvals page, gated exactly as the console gates them
/// (react-admin/src/pages/approvals/ApprovalsPage.tsx) and as the server
/// gates the actions behind them:
///
/// - To sign: any signing key (`SIGNING_PERMISSIONS`).
/// - My submissions: everyone who reaches the page.
/// - Purchase returns: `procurement.approve` (POST purchase-returns/{id}/approve|reject).
/// - Payment proofs: `payments.approve_international` (POST payments/{id}/approve|reject).
public enum ApprovalsTab: String, CaseIterable, Hashable, Sendable {
    case inbox, mine, purchaseReturns, paymentProofs

    /// The console's SIGNING_PERMISSIONS, verbatim.
    public static let signingPermissions = [
        "procurement.approve", "inventory.approve", "expenses.approve", "approvals.finance_sign",
        "payments.void", "payments.reassign",
    ]

    public var title: String {
        switch self {
        case .inbox: return "To sign"
        case .mine: return "My submissions"
        case .purchaseReturns: return "Purchase returns"
        case .paymentProofs: return "Payment proofs"
        }
    }

    public func isVisible(for p: PermissionSet) -> Bool {
        switch self {
        case .inbox: return p.canAny(Self.signingPermissions)
        case .mine: return true
        case .purchaseReturns: return p.can("procurement.approve")
        case .paymentProofs: return p.can("payments.approve_international")
        }
    }

    public static func visible(for p: PermissionSet) -> [ApprovalsTab] { allCases.filter { $0.isVisible(for: p) } }

    /// The console opens on To sign for a signer, otherwise on My submissions.
    public static func initial(for p: PermissionSet) -> ApprovalsTab { inbox.isVisible(for: p) ? .inbox : .mine }
}

/// One row of GET /v1/admin/payments/pending-approval: a payment that needs
/// a checker's decision, with the order it pays for. Every figure is the
/// Hub's, shown in the payment's own currency; nothing here is summed or
/// converted.
public struct PendingPaymentProof: Identifiable, Hashable, Sendable {
    public let id: Int
    public let paymentNumber: String
    public let method: String
    public let amount: Money?
    public let orderTotal: Money?
    public let orderId: Int
    public let orderNumber: String
    public let customerName: String
    public let customerEmail: String?
    public let customerPhone: String?
    public let countryCode: String?
    public let hasProof: Bool
    public let proofUploadedAt: Date?
    public let createdAt: Date?
    public let waitingHours: Double?

    public init?(json: JSONValue) {
        guard let id = json["id"]?.int, let orderId = json["order_id"]?.int else { return nil }
        let currency = json["currency_code"]?.string ?? "KES"
        self.id = id
        paymentNumber = json["payment_number"]?.string ?? "#\(id)"
        method = json["payment_method"]?.string ?? "other"
        amount = json["amount"].flatMap { Money(json: $0, currency: currency) }
        // order_total is the order's total_amount, in the order's currency,
        // which is the payment's: the console prints both with one code.
        orderTotal = json["order_total"].flatMap { Money(json: $0, currency: currency) }
        self.orderId = orderId
        orderNumber = json["order_number"]?.string ?? "#\(orderId)"
        customerName = json["customer_name"]?.string ?? "Unknown Customer"
        customerEmail = json["customer_email"]?.string
        customerPhone = json["customer_phone"]?.string
        countryCode = json["customer_country_code"]?.string
        hasProof = (json["proof_url"]?.string ?? "").isEmpty == false
        proofUploadedAt = json["proof_uploaded_at"]?.date
        createdAt = json["created_at"]?.date
        waitingHours = json["waiting_hours"]?.double
    }

    /// The issuer of the short-lived signed link to the proof (GET payments/{id}/proof).
    public var proofIssuer: String? { hasProof ? "/v1/admin/payments/\(id)/proof" : nil }

    /// Only a customer outside Kenya is marked international, as on the console.
    public var isInternational: Bool {
        guard let c = countryCode?.trimmingCharacters(in: .whitespaces), !c.isEmpty else { return false }
        return c.uppercased() != "KE"
    }

    /// Waiting two days or more: the console tints the row.
    public var isUrgent: Bool { (waitingHours ?? 0) >= 48 }

    public static let methodLabels: [String: String] = [
        "bank_transfer": "Bank Transfer", "other": "Manual / Other", "card": "Card", "mpesa": "M-Pesa", "cash": "Cash",
    ]
    public var methodLabel: String { Self.methodLabels[method] ?? method }

    /// The rows of a pending-approval response; rows that cannot be read are left out, never guessed.
    public static func rows(_ response: JSONValue) -> [PendingPaymentProof] {
        (response["data"]?.array ?? []).compactMap(PendingPaymentProof.init(json:))
    }
}
