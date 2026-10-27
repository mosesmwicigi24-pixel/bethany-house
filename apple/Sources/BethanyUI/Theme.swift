import SwiftUI

/// The Hub's visual identity on Apple platforms (Phase 10 design system).
///
/// Tokens come from the console's tailwind palette so the five experiences
/// read as one product: brand orange `#f05423` for the primary action and
/// `#b23514` for brand text, warm greys for surfaces, a deep navy sidebar,
/// and semantic ramps for status. Dark appearance is derived here rather
/// than inverted, so the sidebar stays navy and brand stays orange on both.
public enum Theme {
    // MARK: Colours

    public enum Palette {
        public static let brand = Color(hex: 0xF05423)
        public static let brandDeep = Color(hex: 0xB23514)
        public static let brandTint = Color(hex: 0xF48364)
        public static let ink = Color(hex: 0x373A36)
        public static let nav = Color(hex: 0x0E1729)
        public static let navBorder = Color(hex: 0x1E2A44)
        public static let navHover = Color(hex: 0x1B2740)

        // Semantic (console badge families)
        public static let success = Color(hex: 0x15803D)
        public static let successSoft = Color(hex: 0xDCFCE7)
        public static let amber = Color(hex: 0xB45309)
        public static let amberSoft = Color(hex: 0xFEF3C7)
        public static let pink = Color(hex: 0xBE185D)
        public static let pinkSoft = Color(hex: 0xFCE7F3)
        public static let warning = Color(hex: 0xC2410C)
        public static let warningSoft = Color(hex: 0xFFEDD5)
        public static let danger = Color(hex: 0xB91C1C)
        public static let dangerSoft = Color(hex: 0xFEE2E2)
        public static let info = Color(hex: 0x1D4ED8)
        public static let infoSoft = Color(hex: 0xDBEAFE)
        public static let accent = Color(hex: 0x6D28D9)
        public static let accentSoft = Color(hex: 0xEDE9FE)
        public static let neutral = Color(hex: 0x4B5563)
        public static let neutralSoft = Color(hex: 0xF3F4F6)
    }

    /// Surfaces adapt to light/dark; the brand does not.
    public static let canvas = Color.adaptive(light: Color(hex: 0xF2F3F1), dark: Color(hex: 0x121413))
    public static let card = Color.adaptive(light: .white, dark: Color(hex: 0x1C1F1D))
    public static let field = Color.adaptive(light: Color(hex: 0xFAFBFA), dark: Color(hex: 0x24282A))
    public static let line = Color.adaptive(light: Color(hex: 0xE5E7E3), dark: Color(hex: 0x30353A))
    public static let text = Color.adaptive(light: Palette.ink, dark: Color(hex: 0xECEDEA))
    public static let textSecondary = Color.adaptive(light: Color(hex: 0x646B61), dark: Color(hex: 0xA7ACA4))
    public static let textTertiary = Color.adaptive(light: Color(hex: 0x8A9086), dark: Color(hex: 0x7C827A))

    // MARK: Type

    public enum Font {
        public static func display(_ size: CGFloat, weight: SwiftUI.Font.Weight = .bold) -> SwiftUI.Font {
            .system(size: size, weight: weight, design: .rounded)
        }
        public static let pageTitle = display(26)
        public static let sectionTitle = SwiftUI.Font.system(size: 17, weight: .semibold)
        public static let body = SwiftUI.Font.system(size: 15)
        public static let secondary = SwiftUI.Font.system(size: 13)
        public static let caption = SwiftUI.Font.system(size: 11, weight: .medium)
        public static let mono = SwiftUI.Font.system(size: 13, design: .monospaced)
        public static let stat = display(28)
    }

    // MARK: Shape & spacing

    public enum Radius {
        public static let field: CGFloat = 12
        public static let control: CGFloat = 14
        public static let card: CGFloat = 24
        public static let sheet: CGFloat = 28
        public static let badge: CGFloat = 999
    }

    public enum Space {
        public static let xs: CGFloat = 4
        public static let s: CGFloat = 8
        public static let m: CGFloat = 12
        public static let l: CGFloat = 16
        public static let xl: CGFloat = 24
        public static let xxl: CGFloat = 32
        /// Side gutter for phone layouts.
        public static let gutter: CGFloat = 16
    }

    /// Minimum hit target on touch platforms.
    public static let tapTarget: CGFloat = 44
}

/// The status families every module's badge resolves to (index.css `.badge-*`).
public enum StatusTone: Sendable {
    case success, amber, pink, warning, danger, info, accent, neutral

    public var foreground: Color {
        switch self {
        case .success: return Theme.Palette.success
        case .amber: return Theme.Palette.amber
        case .pink: return Theme.Palette.pink
        case .warning: return Theme.Palette.warning
        case .danger: return Theme.Palette.danger
        case .info: return Theme.Palette.info
        case .accent: return Theme.Palette.accent
        case .neutral: return Theme.Palette.neutral
        }
    }

    public var background: Color {
        switch self {
        case .success: return Theme.Palette.successSoft
        case .amber: return Theme.Palette.amberSoft
        case .pink: return Theme.Palette.pinkSoft
        case .warning: return Theme.Palette.warningSoft
        case .danger: return Theme.Palette.dangerSoft
        case .info: return Theme.Palette.infoSoft
        case .accent: return Theme.Palette.accentSoft
        case .neutral: return Theme.Palette.neutralSoft
        }
    }

    /// One mapping for the status strings the API uses across modules, so an
    /// order, an expense and a leave request colour the same way everywhere.
    public static func forStatus(_ status: String?) -> StatusTone {
        switch status?.lowercased() ?? "" {
        case "paid", "completed", "approved", "delivered", "active", "published", "received", "done", "matched", "signed", "applied", "settled", "verified", "finalized", "closed":
            return .success
        case "pending", "pending_approval", "processing", "submitted", "requested", "open", "draft", "ordered", "scheduled", "in_transit", "sent", "counted":
            return .amber
        case "in_progress", "partial", "deposit", "partially_received", "changes_requested", "on_hold", "sent_back", "held":
            return .pink
        case "unpaid", "pending_review", "flagged", "late", "missed", "expired", "returned", "short", "over", "mismatch", "awaiting_approval":
            return .warning
        case "cancelled", "canceled", "rejected", "declined", "voided", "refunded", "failed", "deleted", "denied", "blocked", "withdrawn", "skipped":
            return .danger
        case "confirmed", "shipped", "dispatched", "accepted", "issued", "locked":
            return .info
        default:
            return .neutral
        }
    }
}

public extension Color {
    init(hex: UInt32, alpha: Double = 1) {
        self.init(.sRGB,
                  red: Double((hex >> 16) & 0xFF) / 255,
                  green: Double((hex >> 8) & 0xFF) / 255,
                  blue: Double(hex & 0xFF) / 255,
                  opacity: alpha)
    }

    static func adaptive(light: Color, dark: Color) -> Color {
        #if os(macOS)
        return Color(nsColor: NSColor(name: nil) { appearance in
            appearance.bestMatch(from: [.darkAqua, .aqua]) == .darkAqua ? NSColor(dark) : NSColor(light)
        })
        #else
        return Color(uiColor: UIColor { traits in
            traits.userInterfaceStyle == .dark ? UIColor(dark) : UIColor(light)
        })
        #endif
    }
}

/// Human labels for API status strings: "pending_approval" → "Pending approval".
public extension String {
    var humanized: String {
        replacingOccurrences(of: "_", with: " ").replacingOccurrences(of: "-", with: " ").capitalizedFirst
    }
    var capitalizedFirst: String {
        guard let f = first else { return self }
        return f.uppercased() + dropFirst()
    }
}
