import Foundation

/// Payroll › People & pay and Payroll › Commissions (owner, 2026-11-03/04;
/// react-admin/src/components/people/PeopleAndPay.tsx and CommissionsPanel.tsx).
/// Payroll is in shillings: every figure here is the Hub's, shown as KES,
/// never summed on the device.

/// One row of GET payroll/people.
public struct PayPerson: Identifiable, Hashable, Sendable {
    public let id: Int
    public let name: String
    public let salary: Money?
    public let salaryKind: String?
    public let salaryFrom: String?
    public let deductionsApply: Bool
    public let deductionsSince: String?
    /// A change already scheduled: (applies, from).
    public let nextChange: (applies: Bool, from: String)?
    public let advancesOwed: Money
    public let commissionOwed: Money
    public let commissionPending: Money
    public let lastPaidNet: Money?
    public let lastPaidOn: String?
    public let lastPaidMethod: String?
    public let lastPaidRun: String?

    public init?(json: JSONValue) {
        guard let id = json["id"]?.int else { return nil }
        self.id = id
        name = json["name"]?.string ?? "#\(id)"
        let s = json["salary"]
        salary = s?["base_amount"].flatMap { Money(json: $0, currency: "KES") }
        salaryKind = s?["kind"]?.string
        salaryFrom = s?["effective_from"]?.string
        let d = json["deductions"]
        deductionsApply = d?["applies"]?.bool ?? false
        deductionsSince = d?["since"]?.string
        if let n = d?["next"], !n.isNull, let from = n["from"]?.string { nextChange = (n["applies"]?.bool ?? false, from) } else { nextChange = nil }
        advancesOwed = json["advances_owed"].flatMap { Money(json: $0, currency: "KES") } ?? Money(amount: 0)
        commissionOwed = json["commission"]?["owed"].flatMap { Money(json: $0, currency: "KES") } ?? Money(amount: 0)
        commissionPending = json["commission"]?["pending"].flatMap { Money(json: $0, currency: "KES") } ?? Money(amount: 0)
        let lp = json["last_paid"]
        lastPaidNet = lp?["net"].flatMap { Money(json: $0, currency: "KES") }
        lastPaidOn = lp?["paid_on"]?.string
        lastPaidMethod = lp?["method"]?.string
        lastPaidRun = lp?["run"]?.string
    }

    public static func == (a: PayPerson, b: PayPerson) -> Bool { a.id == b.id && a.name == b.name && a.deductionsApply == b.deductionsApply && a.nextChange?.from == b.nextChange?.from }
    public func hash(into h: inout Hasher) { h.combine(id) }

    /// The deductions chip, word for word as the console prints it (dates as the server sends them).
    public var deductionsLabel: String {
        deductionsApply ? "Deductions applied" + (deductionsSince.map { " since \(PayrollText.date($0))" } ?? "") : "No deductions · contract"
    }

    public var nextChangeLabel: String? {
        nextChange.map { "\($0.applies ? "Start" : "Stop") \(PayrollText.date($0.from))" }
    }

    /// The deductions action this person's ⋯ menu offers (the switch flips one way).
    public var deductionsAction: String { deductionsApply ? "Stop government deductions" : "Add government deductions" }

    public static func rows(_ response: JSONValue) -> [PayPerson] { (response["data"]?.array ?? []).compactMap(PayPerson.init(json:)) }

    /// "x of y currently have them."
    public static func enrolledCount(_ rows: [PayPerson]) -> Int { rows.filter(\.deductionsApply).count }

    /// The console's search: a case-insensitive substring of the name.
    public static func filter(_ rows: [PayPerson], query: String) -> [PayPerson] {
        let q = query.trimmingCharacters(in: .whitespaces).lowercased()
        return q.isEmpty ? rows : rows.filter { $0.name.lowercased().contains(q) }
    }

    /// The deductions dialog asks for a reason of three characters or more; the server wants one.
    public static let deductionsReasonMinimum = 3
}

/// One row of GET payroll/commissions.
public struct CommissionRow: Identifiable, Hashable, Sendable {
    public let id: Int
    public let userId: Int?
    public let userName: String
    public let transactionRef: String
    public let orderId: Int?
    public let amount: Money?
    public let earnedOn: String?
    public let note: String?
    public let status: String
    public let recordedBy: String?
    public let approvedBy: String?
    public let run: String?
    public let paidOn: String?
    public let voidReason: String?
    /// The server says what this person may do with this row.
    public let canApprove: Bool
    public let canVoid: Bool

    public init?(json: JSONValue) {
        guard let id = json["id"]?.int else { return nil }
        self.id = id
        userId = json["user"]?["id"]?.int
        userName = json["user"]?["name"]?.string ?? "—"
        transactionRef = json["transaction_ref"]?.string ?? ""
        orderId = json["order"]?["id"]?.int
        amount = json["amount"].flatMap { Money(json: $0, currency: "KES") }
        earnedOn = json["earned_on"]?.string
        note = json["note"]?.string
        status = json["status"]?.string ?? "recorded"
        recordedBy = json["recorded_by"]?.string
        approvedBy = json["approved_by"]?.string
        run = json["run"]?.string
        paidOn = json["paid_on"]?.string
        voidReason = json["void_reason"]?.string
        canApprove = json["can_approve"]?.bool ?? false
        canVoid = json["can_void"]?.bool ?? false
    }

    public static let statusLabels = ["recorded": "Awaiting approval", "approved": "Approved · next pay run", "paid": "Paid", "void": "Void"]
    public var statusLabel: String { Self.statusLabels[status] ?? status }

    /// The detail beside the status chip (console StatusChip).
    public var statusDetail: String? {
        switch status {
        case "paid": return paidOn.map { PayrollText.date($0) + (run.map { " · \($0)" } ?? "") }
        case "approved": return run.map { "on \($0)" }
        case "void": return voidReason
        default: return nil
        }
    }

    /// The filter the panel offers, in the console's order; "" is All.
    public static let filters: [(value: String, label: String)] = [
        ("open", "Open (awaiting approval or owed)"), ("recorded", "Awaiting approval"),
        ("approved", "Approved · next pay run"), ("paid", "Paid"), ("void", "Void"), ("", "All"),
    ]

    public static let voidReasonMinimum = 3

    /// The record dialog's own check before it sends (the server validates again).
    public static func mayRecord(userId: Int?, transactionRef: String, amount: Decimal?, earnedOn: String) -> Bool {
        guard userId != nil, let a = amount, a > 0, !earnedOn.isEmpty else { return false }
        return transactionRef.trimmingCharacters(in: .whitespaces).count >= 2
    }
}

/// GET payroll/commissions as a whole: rows, the server's totals and the two switches.
public struct CommissionsPage: Sendable {
    public let rows: [CommissionRow]
    public let awaitingApproval: Money
    public let owed: Money
    public let paid: Money
    public let payeApplies: Bool
    public let canRecord: Bool
    public let canApprove: Bool

    public init(json: JSONValue) {
        rows = (json["data"]?.array ?? []).compactMap(CommissionRow.init(json:))
        let t = json["totals"]
        let kes = { (k: String) in t?[k].flatMap { Money(json: $0, currency: "KES") } ?? Money(amount: 0) }
        awaitingApproval = kes("awaiting_approval"); owed = kes("owed"); paid = kes("paid")
        payeApplies = json["paye_applies"]?.bool ?? false
        canRecord = json["can_record"]?.bool ?? false
        canApprove = json["can_approve"]?.bool ?? false
    }
}

public enum PayrollText {
    /// "1 Dec 2026" from the server's "2026-12-01", read as a calendar date (no time zone shift).
    public static func date(_ iso: String) -> String {
        let p = iso.prefix(10).split(separator: "-").compactMap { Int($0) }
        guard p.count == 3, (1...12).contains(p[1]) else { return iso }
        let months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"]
        return "\(p[2]) \(months[p[1] - 1]) \(p[0])"
    }
}
