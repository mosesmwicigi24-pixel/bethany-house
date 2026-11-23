import Foundation

/// Clerk terminal PINs on the attendance page (components/security/ClerkPinsPanel.tsx,
/// Phase 4C): an outlet manager clears a clerk's forgotten PIN; the clerk then
/// sets a new one. The super admin sees every clerk; a manager, the clerks at
/// their own outlet. The server checks the outlet is shared, whatever the list
/// shows (POST users/{id}/terminal-pin/reset).
public enum ClerkPins {
    public struct Clerk: Identifiable, Hashable, Sendable {
        public let id: Int
        public let name: String
        public let outletId: Int?
        public let outletName: String?
    }

    /// Who is offered the panel: the super admin or an outlet manager (by role, as on the console).
    public static func isOffered(roles: [String], isSuperAdmin: Bool) -> Bool {
        isSuperAdmin || roles.contains("outlet_manager")
    }

    /// GET users/role/pos_clerk, narrowed for a manager to their own outlet.
    public static func rows(_ json: JSONValue, isSuperAdmin: Bool, myOutletId: Int?) -> [Clerk] {
        let all = (json["data"]?.array ?? []).compactMap { c -> Clerk? in
            guard let id = c["id"]?.int else { return nil }
            let name = [c["first_name"]?.string, c["last_name"]?.string].compactMap { $0 }.joined(separator: " ")
            return Clerk(id: id, name: name.isEmpty ? "#\(id)" : name, outletId: c["outlet"]?["id"]?.int, outletName: c["outlet"]?["name"]?.string)
        }
        if isSuperAdmin { return all }
        guard let mine = myOutletId else { return [] }
        return all.filter { $0.outletId == mine }
    }
}
