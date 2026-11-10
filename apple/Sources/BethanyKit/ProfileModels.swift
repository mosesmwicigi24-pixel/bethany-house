import Foundation

/// The profile page beyond sign-in: named devices, my activity and my
/// employment file (react-admin/src/pages/profile/ProfilePage.tsx,
/// components/security/DeviceList.tsx, components/people/MyFileTab.tsx).

/// One row of GET profile/devices.
public struct ProfileDevice: Identifiable, Hashable, Sendable {
    public let id: Int
    public let name: String?
    public let label: String
    public let nameSource: String?
    public let namedBy: String?
    public let isCurrent: Bool
    public let lastSeen: String?
    public let activeSessions: Int

    public init?(json: JSONValue) {
        guard let id = json["id"]?.int else { return nil }
        self.id = id
        name = json["name"]?.string
        label = json["label"]?.string ?? json["name"]?.string ?? "Device"
        nameSource = json["name_source"]?.string
        namedBy = json["named_by"]?.string
        isCurrent = json["is_current"]?.bool ?? false
        lastSeen = json["last_seen"]?.string
        activeSessions = json["active_sessions"]?.int ?? 0
    }

    /// A name an admin gave is theirs to change, not the device holder's.
    public var canRename: Bool { nameSource != "admin" }
    /// The device in hand is signed out, not removed (the server answers 422).
    public var canRemove: Bool { !isCurrent }

    /// The server's rule (`nullable|string|min:2|max:60`): empty clears the name.
    public static func isValidName(_ draft: String) -> Bool {
        let t = draft.trimmingCharacters(in: .whitespacesAndNewlines)
        return t.isEmpty || (2...60).contains(t.count)
    }

    /// What the rename sends: nil clears it.
    public static func nameValue(_ draft: String) -> String? {
        let t = draft.trimmingCharacters(in: .whitespacesAndNewlines)
        return t.isEmpty ? nil : t
    }

    public static func rows(_ response: JSONValue) -> [ProfileDevice] {
        ((response["data"] ?? response).array ?? []).compactMap(ProfileDevice.init(json:))
    }
}

/// One row of GET profile/activity.
public struct ProfileActivity: Identifiable, Hashable, Sendable {
    public let id: String
    public let text: String
    public let createdAt: Date?

    public init(json: JSONValue) {
        id = json["id"]?.string ?? UUID().uuidString
        let action = json["action"]?.string ?? json["event"]?.string ?? "activity"
        let description = json["description"]?.string ?? ""
        text = description.isEmpty ? action.replacingOccurrences(of: "_", with: " ").capitalizedFirst : description
        createdAt = json["created_at"]?.date
    }

    public static func rows(_ response: JSONValue) -> [ProfileActivity] { (response["data"]?.array ?? []).map(ProfileActivity.init(json:)) }
}

/// GET me/file → {person}: what the business holds about me. I keep my own
/// emergency contact and date of birth; the rest is the file keeper's.
public struct MyEmployment: Sendable {
    public let jobTitle: String?
    public let department: String?
    public let employmentType: String?
    public let hireDate: String?
    public let reportsTo: String?
    public let payMethod: String?
    public let bankName: String?
    public let dateOfBirth: String?
    public let emergencyName: String?
    public let emergencyPhone: String?
    public let emergencyRelationship: String?
    /// Masked identifiers (national ID, KRA PIN, NSSF, SHIF, M-Pesa, bank account), as the server masks them.
    public let masked: [(label: String, value: String?)]
    public let documents: [(id: Int, name: String)]

    public init(json: JSONValue) {
        let p = json["person"] ?? json
        let f = p["profile"]
        jobTitle = f?["job_title"]?.string
        department = f?["department"]?.string
        employmentType = f?["employment_type"]?.string
        hireDate = f?["hire_date"]?.string
        reportsTo = p["reports_to"]?["name"]?.string
        payMethod = f?["pay_method"]?.string
        bankName = f?["bank_name"]?.string
        dateOfBirth = f?["date_of_birth"]?.string
        emergencyName = f?["emergency_contact_name"]?.string
        emergencyPhone = f?["emergency_contact_phone"]?.string
        emergencyRelationship = f?["emergency_contact_relationship"]?.string
        let m = p["masked"]
        masked = MyEmployment.identifierLabels.map { ($0.label, m?[$0.key]?.string) }
        documents = (p["documents"]?.array ?? []).compactMap { d in d["id"]?.int.map { ($0, d["original_name"]?.string ?? d["name"]?.string ?? "Document") } }
    }

    public static let identifierLabels: [(key: String, label: String)] = [
        ("national_id_no", "National ID"), ("kra_pin", "KRA PIN"), ("nssf_no", "NSSF"),
        ("shif_no", "SHIF (SHA)"), ("mpesa_phone", "M-Pesa phone"), ("bank_account_no", "Bank account"),
    ]

    /// "M-Pesa · Equity" as the console prints the pay method.
    public var payMethodText: String {
        let m = ["mpesa": "M-Pesa", "bank": "Bank", "cash": "Cash"][payMethod ?? ""] ?? "—"
        return m + (bankName.map { " · \($0)" } ?? "")
    }
}

extension String {
    var capitalizedFirst: String { prefix(1).uppercased() + dropFirst() }
}
