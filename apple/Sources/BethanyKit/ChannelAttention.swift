import Foundation

/// GET channels/attention: what still needs me — unread, floating notices
/// and threads waiting on my reply (react-admin/src/api/channels.ts,
/// components/comms/MessageNotices.tsx, pages/comms/CommsHub.tsx).
///
/// Three different acts, kept apart as the server keeps them:
/// - dismissing a notice (`notice-dismiss`) hides the floating notice only;
///   the message stays unread and still waits on me;
/// - "Handled" (`acknowledge`) says nothing here needs my reply, and reads it;
/// - reading a thread marks it read but does not answer it.
public struct ChannelAttention: Sendable {
    public struct Item: Identifiable, Hashable, Sendable {
        public let channelId: Int
        public let name: String
        public let type: String
        public let unread: Int
        public let pending: Int
        /// "waiting" | "warn" | "escalated" — how long it has waited, by the server's thresholds.
        public let level: String?
        public let notice: Notice?
        public var id: Int { channelId }
        public var waitsOnMe: Bool { pending > 0 }
    }

    public struct Notice: Hashable, Sendable {
        public let messageId: Int
        public let body: String
        public let from: String
        public let mentionsMe: Bool
        public let count: Int
    }

    public let items: [Item]
    public let unread: Int
    public let pending: Int
    public let notices: Int

    public init(json: JSONValue) {
        items = (json["channels"]?.array ?? []).compactMap { c in
            guard let id = c["channel_id"]?.int else { return nil }
            let n = c["notice"]
            let notice: Notice? = (n == nil || n!.isNull) ? nil : n!["message_id"]?.int.map {
                Notice(messageId: $0, body: n!["body"]?.string ?? "", from: n!["user"]?["name"]?.string ?? "",
                       mentionsMe: n!["mentions_me"]?.bool ?? false, count: n!["count"]?.int ?? 1)
            }
            return Item(channelId: id, name: c["name"]?.string ?? "Conversation", type: c["type"]?.string ?? "",
                        unread: c["unread_count"]?.int ?? 0, pending: c["pending_count"]?.int ?? 0,
                        level: c["level"]?.string, notice: notice)
        }
        let t = json["totals"]
        unread = t?["unread"]?.int ?? 0
        pending = t?["pending"]?.int ?? 0
        notices = t?["notices"]?.int ?? 0
    }

    public static let empty = ChannelAttention(json: .null)

    public func item(_ channelId: Int) -> Item? { items.first { $0.channelId == channelId } }
    /// Does this thread wait on my reply? Then the thread offers "Handled".
    public func waitsOnMe(_ channelId: Int) -> Bool { item(channelId)?.waitsOnMe ?? false }
    /// The floating notices, newest server order kept.
    public var withNotices: [Item] { items.filter { $0.notice != nil } }
}

/// The Assist rail (POST channels/{id}/assist): four actions, one tap each.
/// The server answers 503 `assist_not_configured` with no model key and 422
/// `assist_unavailable` with a sentence to show when it cannot answer.
public enum ChannelAssist {
    public enum Action: String, CaseIterable, Sendable {
        case summarise, translate, draft, replies
        public var title: String {
            switch self {
            case .summarise: return "Summarise the thread"
            case .translate: return "Translate my draft"
            case .draft: return "Draft a message"
            case .replies: return "Suggest replies"
            }
        }
    }

    /// The request body: `text` only for a translation, `audience` only for a draft.
    public static func body(_ action: Action, language: String, draft: String, audience: String?) -> JSONValue {
        var o: [String: JSONValue] = ["action": .string(action.rawValue), "language": .string(language == "sw" ? "sw" : "en")]
        if action == .translate { o["text"] = .string(draft) }
        if action == .draft, let a = audience { o["audience"] = .string(a == "customer" ? "customer" : "team") }
        return .object(o)
    }

    /// The answer: the text and, for "replies", the options to pick from.
    public static func answer(_ json: JSONValue) -> (text: String, options: [String]) {
        (json["text"]?.string ?? "", (json["options"]?.array ?? []).compactMap(\.string))
    }

    /// A translation needs something to translate.
    public static func canRun(_ action: Action, draft: String) -> Bool {
        action != .translate || !draft.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
    }
}
