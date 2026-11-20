import Foundation

/// Where a production job's materials come from (owner, 2026-11-18/19; #573).
/// Mirrors react-admin/src/components/production/MaterialSourcing.tsx.
///
/// For each material on the product's bill of materials the person raising
/// the job says FROM STORE (reserved for the job), TO BUY (asked of
/// procurement) or SPLIT. Nothing here names a supplier or a price: who we buy
/// from is procurement's to see. The server re-checks every figure
/// (`MaterialSourcing::choose`); these rules only stop a form the server would
/// refuse from being sent.
public enum MaterialSourcing {
    public struct Need: Identifiable, Equatable, Sendable {
        public let materialId: Int
        public let name: String
        public let unit: String?
        /// What still has to be found for the job.
        public let required: Double
        /// Free in the store (on hand less held for other jobs).
        public let free: Double
        public var id: Int { materialId }
        public init(materialId: Int, name: String, unit: String?, required: Double, free: Double) {
            self.materialId = materialId; self.name = name; self.unit = unit; self.required = required; self.free = free
        }
        var unitText: String { unit ?? "" }
    }

    public enum Mode: String, CaseIterable, Sendable {
        case store, buy, split
        public var label: String {
            switch self { case .store: return "From store"; case .buy: return "To buy"; case .split: return "Split" }
        }
    }

    public struct Choice: Equatable, Sendable {
        public var mode: Mode
        /// The typed "from store" figure of a split.
        public var store: String
        public init(mode: Mode, store: String = "") { self.mode = mode; self.store = store }
    }

    /// Quantities carry two decimals, as the server stores them.
    public static func n2(_ v: Double) -> Double { (v * 100).rounded() / 100 }

    /// 5, 2.5, 0.25, 1,250 — no trailing zeros.
    public static func qty(_ v: Double) -> String {
        let f = NumberFormatter()
        f.numberStyle = .decimal
        f.minimumFractionDigits = 0
        f.maximumFractionDigits = 2
        f.groupingSeparator = ","
        f.decimalSeparator = "."
        f.usesGroupingSeparator = true
        return f.string(from: NSNumber(value: n2(v))) ?? String(n2(v))
    }

    // MARK: Send to production (GET orders/{id}/items/{itemId}/production-materials)

    public struct Requirements: Sendable {
        public let hasBom: Bool
        public let needs: [Need]
    }

    public static func requirements(_ json: JSONValue) -> Requirements {
        Requirements(hasBom: json["has_bom"]?.bool ?? false, needs: (json["materials"]?.array ?? []).compactMap { m in
            guard let id = m["material_id"]?.int else { return nil }
            return Need(materialId: id, name: m["name"]?.string ?? "Material", unit: m["unit"]?.string,
                        required: m["required"]?.double ?? 0, free: m["free"]?.double ?? 0)
        })
    }

    /// The sensible start: the store when it has enough, otherwise buy.
    public static func defaultChoices(_ needs: [Need]) -> [Int: Choice] {
        Dictionary(uniqueKeysWithValues: needs.map { ($0.materialId, Choice(mode: $0.free >= $0.required ? .store : .buy)) })
    }

    /// Split is offered only when something is free.
    public static func canSplit(_ need: Need) -> Bool { need.free > 0 }

    /// What a split starts at: as much as is free, short of the whole need.
    public static func splitStart(_ need: Need) -> String {
        let v = min(need.free, n2(need.required - 0.01))
        return v > 0 ? qty(v).replacingOccurrences(of: ",", with: "") : ""
    }

    /// from store / to buy for one material under a choice.
    public static func split(_ need: Need, _ choice: Choice?) -> (store: Double, buy: Double) {
        switch choice?.mode ?? .buy {
        case .store: return (need.required, 0)
        case .buy: return (0, need.required)
        case .split:
            let s = max(0, min(need.required, n2(Double(choice?.store ?? "") ?? 0)))
            return (s, n2(need.required - s))
        }
    }

    /// Why a choice cannot be saved, or nil.
    public static func problem(_ need: Need, _ choice: Choice?) -> String? {
        let (store, _) = split(need, choice)
        if store > need.free + 0.001 { return "Only \(qty(need.free)) \(need.unitText) free in the store." }
        if choice?.mode == .split {
            let typed = Double(choice?.store ?? "") ?? 0
            if !(typed > 0) { return "Enter how much comes from the store." }
            if typed >= need.required { return "Less than \(qty(need.required)) \(need.unitText), or choose From store." }
        }
        return nil
    }

    public static func hasProblem(_ needs: [Need], _ choices: [Int: Choice]) -> Bool {
        needs.contains { problem($0, choices[$0.materialId]) != nil }
    }

    /// What the choice will do, under the row.
    public static func outcome(_ need: Need, _ choice: Choice?) -> String {
        let (s, b) = split(need, choice)
        return [s > 0 ? "\(qty(s)) \(need.unitText) will be reserved for this job." : nil,
                b > 0 ? "\(qty(b)) \(need.unitText) will be requested from procurement." : nil]
            .compactMap { $0 }.joined(separator: " ")
    }

    /// `materials: [{material_id, from_store, to_buy}]`.
    public static func payload(_ needs: [Need], _ choices: [Int: Choice]) -> JSONValue {
        .array(needs.map { m in
            let s = split(m, choices[m.materialId])
            return .object(["material_id": .number(Double(m.materialId)), "from_store": .number(s.store), "to_buy": .number(s.buy)])
        })
    }

    /// "On raising: 1 material reserved from the store · 1 requested from procurement. Nothing is ordered until procurement approves it."
    public static func summary(_ needs: [Need], _ choices: [Int: Choice]) -> String? {
        var store = 0, buy = 0
        for m in needs { let s = split(m, choices[m.materialId]); if s.store > 0 { store += 1 }; if s.buy > 0 { buy += 1 } }
        guard store + buy > 0 else { return nil }
        var parts: [String] = []
        if store > 0 { parts.append("\(store) material\(store == 1 ? "" : "s") reserved from the store") }
        if buy > 0 { parts.append("\(buy) requested from procurement") }
        return "On raising: " + parts.joined(separator: " · ") + (buy > 0 ? ". Nothing is ordered until procurement approves it." : ".")
    }

    /// The toast after raising (`materials_status` "waiting" adds the procurement note).
    public static func raisedMessage(_ json: JSONValue) -> String {
        "Production order \(json["production_order_number"]?.string ?? "") raised"
            + (json["materials_status"]?.string == "waiting" ? " · materials requested from procurement" : "")
    }

    // MARK: On the job (GET/POST production-orders/{id}/material-sourcing)

    public struct JobMaterial: Identifiable, Equatable, Sendable {
        public let allocationId: Int
        public let materialId: Int
        public let name: String
        public let unit: String?
        public let required, issued, reserved, toBuy, unsourced, free: Double
        public var id: Int { allocationId }
    }

    public struct Job: Sendable {
        /// none · unsourced · waiting · ready
        public let status: String
        public let materials: [JobMaterial]

        public var statusLabel: String {
            switch status {
            case "unsourced": return "Materials not yet sourced"
            case "waiting": return "Waiting for materials"
            case "ready": return "Materials ready"
            default: return "No materials listed"
            }
        }

        /// What is still to be found per material, and the store stock this job
        /// may draw on: what is free plus what it already holds.
        public var outstanding: [Need] {
            materials.map { Need(materialId: $0.materialId, name: $0.name, unit: $0.unit, required: n2($0.required - $0.issued), free: n2($0.free + $0.reserved)) }
                .filter { $0.required > 0 }
        }

        /// The current decision as choices, for "Change".
        public var currentChoices: [Int: Choice] {
            var out: [Int: Choice] = [:]
            for m in materials where m.required - m.issued > 0 {
                let outstanding = n2(m.required - m.issued)
                let decided = m.reserved + m.toBuy > 0
                let mode: Mode = !decided ? (m.free >= outstanding ? .store : .buy) : (m.toBuy <= 0 ? .store : (m.reserved <= 0 ? .buy : .split))
                out[m.materialId] = Choice(mode: mode, store: mode == .split ? qty(m.reserved).replacingOccurrences(of: ",", with: "") : "")
            }
            return out
        }
    }

    public static func job(_ json: JSONValue) -> Job {
        Job(status: json["status"]?.string ?? "none", materials: (json["materials"]?.array ?? []).compactMap { m in
            guard let a = m["allocation_id"]?.int, let id = m["material_id"]?.int else { return nil }
            let d = { (k: String) in m[k]?.double ?? 0 }
            return JobMaterial(allocationId: a, materialId: id, name: m["name"]?.string ?? "Material", unit: m["unit"]?.string,
                               required: d("required"), issued: d("issued"), reserved: d("reserved"), toBuy: d("to_buy"), unsourced: d("unsourced"), free: d("free"))
        })
    }
}
