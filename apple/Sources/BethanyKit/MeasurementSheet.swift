import Foundation

/// An order's measurements as the tailor reads them: the clergy measurement
/// sheet's order, top of the body down (fields the sheet does not know come
/// after it), empty values dropped, and gender pulled out — it is who the
/// garment is for, not a body measurement.
///
/// The same rule as the console's `orderMeasurements` (react-admin
/// productionUi.tsx, Production Cycle 5) and Android's `MeasurementSheet`.
/// The generic `stringPairs` sorts keys alphabetically, which put "Arm Hole"
/// first and "Neck" in the middle. A JSON object reaches Swift unordered, so
/// fields outside the sheet follow it alphabetically rather than as typed.
public enum MeasurementSheet {
    /// Both sheets merged (men's and ladies'), top of the body down.
    public static let order = [
        "neck", "shoulders", "sleeves", "wrist", "arm_hole", "upper_arm",
        "chest", "bodice", "stomach", "waist", "hips", "shirt_length", "blouse_length", "full_length",
    ]

    /// Spellings seen on older orders and other forms.
    private static let alias = [
        "hip": "hips", "shoulder": "shoulders", "sleeve": "sleeves", "armhole": "arm_hole",
        "sleeve_length": "sleeves", "shoulder_width": "shoulders", "length": "full_length",
    ]

    private static func normalise(_ key: String) -> String {
        let n = key.lowercased().trimmingCharacters(in: .whitespaces)
            .replacingOccurrences(of: "[\\s-]+", with: "_", options: .regularExpression)
        return alias[n] ?? n
    }

    public static func rank(_ key: String) -> Int {
        order.firstIndex(of: normalise(key)) ?? order.count
    }

    private static func isGender(_ key: String) -> Bool {
        key.lowercased().filter { $0.isLetter } == "gender"
    }

    public struct Measured: Sendable {
        public let gender: String?
        public let body: [(String, String)]
    }

    public static func of(_ record: JSONValue?) -> Measured {
        let entries: [(String, String)] = (record?.object ?? [:]).compactMap { k, v in
            guard let s = v.string?.trimmingCharacters(in: .whitespaces), !s.isEmpty else { return nil }
            return (k, s)
        }
        let gender = entries.first { isGender($0.0) }?.1
        let body = entries.filter { !isGender($0.0) }.sorted { a, b in
            let ra = rank(a.0), rb = rank(b.0)
            return ra != rb ? ra < rb : a.0 < b.0
        }
        return Measured(gender: gender, body: body)
    }

    /// Rows to show: who the garment is for ("for", e.g. "Ladies") first, then
    /// the measurements in sheet order.
    public static func rows(_ record: JSONValue?) -> [(String, String)] {
        let m = of(record)
        return (m.gender.map { [("for", $0.capitalized)] } ?? []) + m.body
    }

    /// The latest inspection note on an order. The QC endpoint appends it to
    /// the order's notes as a line "QC: …" (the word "Passed" or "Failed" when
    /// the inspector wrote nothing, which is no reason at all). The rework
    /// sheet starts its reason from it (console Production Cycle 10).
    public static func lastQcNote(_ notes: String?) -> String? {
        guard let notes else { return nil }
        let lines = notes.components(separatedBy: "\n")
        guard let line = lines.last(where: { $0.hasPrefix("QC: ") }) else { return nil }
        let note = String(line.dropFirst(4)).trimmingCharacters(in: .whitespaces)
        return note.isEmpty || note == "Failed" || note == "Passed" ? nil : note
    }
}
