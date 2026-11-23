import Foundation

/// Where a bill-of-materials line's cost came from (#563; console
/// BomTab.tsx `costSourceText`, `SetMaterialCost`).
///
/// A line's cost is its material's one cost — what was last paid on a
/// purchase order, or a recorded cost change. A material with no cost is
/// costed on the material itself (PUT inventory/materials/{id} {unit_cost}),
/// never as a second price kept in the BOM; a change past the threshold comes
/// back as a proposal for approval. `cost_source` and `uncosted_count` are
/// sent only to people who may see costs.
public enum BomCost {
    /// "last bought 3 Oct · PO-0123" / "set by Moses, 10 Nov" / nil.
    public static func sourceText(_ source: JSONValue?) -> String? {
        guard let s = source, !s.isNull else { return nil }
        let when = s["at"]?.string.flatMap(day)
        switch s["kind"]?.string {
        case "purchase":
            return "last bought" + (when.map { " \($0)" } ?? "") + (s["ref"]?.string.map { " · \($0)" } ?? "")
        case "manual":
            return "set" + (s["by"]?.string.map { " by \($0)" } ?? "") + (when.map { ", \($0)" } ?? "")
        default:
            return nil
        }
    }

    /// Does this BOM line's material carry a cost? (No material: a free line, shown as is.)
    public static func hasCost(_ item: JSONValue) -> Bool {
        guard let m = item["material"], !m.isNull else { return true }
        return (m["cost_per_unit"]?.double ?? 0) > 0
    }

    /// "+ 1 material without a cost" — the total leaves them out, and says so.
    public static func uncostedNote(_ count: Int) -> String? {
        count > 0 ? "+ \(count) material\(count == 1 ? "" : "s") without a cost" : nil
    }

    /// A cost may be set when it is a positive number.
    public static func validCost(_ text: String) -> Double? {
        guard let v = Double(text.trimmingCharacters(in: .whitespaces)), v.isFinite, v > 0 else { return nil }
        return v
    }

    /// Server timestamps are Nairobi wall-clock ("2026-10-03 10:15:00") or ISO 8601.
    static func day(_ raw: String) -> String? {
        let nairobi = TimeZone(identifier: "Africa/Nairobi")
        let iso = ISO8601DateFormatter()
        var date = iso.date(from: raw)
        if date == nil { iso.formatOptions = [.withInternetDateTime, .withFractionalSeconds]; date = iso.date(from: raw) }
        if date == nil {
            for fmt in ["yyyy-MM-dd HH:mm:ss", "yyyy-MM-dd'T'HH:mm:ss", "yyyy-MM-dd"] {
                let f = DateFormatter()
                f.locale = Locale(identifier: "en_US_POSIX"); f.timeZone = nairobi; f.dateFormat = fmt
                if let d = f.date(from: raw) { date = d; break }
            }
        }
        guard let date else { return nil }
        let out = DateFormatter()
        out.locale = Locale(identifier: "en_US_POSIX"); out.timeZone = nairobi; out.dateFormat = "d MMM"
        return out.string(from: date)
    }
}
