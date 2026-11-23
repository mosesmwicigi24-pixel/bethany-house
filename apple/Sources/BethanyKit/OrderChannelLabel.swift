import Foundation

/// Which door a sale came through, as the console names it
/// (react-admin/src/components/orders/OrderRow.tsx `orderChannel`): the
/// stored `sales_bucket` first; legacy rows by `order_type`
/// (Order::LEGACY_CHANNEL_MAP: pos → till, online → web, whatsapp → chat).
/// A chat sale names its source where one is known.
public enum OrderChannelLabel {
    public static let chatSources = [
        "whatsapp": "WhatsApp", "messenger": "Messenger", "instagram": "Instagram",
        "website": "Website", "walk_in": "Walk-in",
    ]

    public static func of(salesBucket: String?, orderType: String?, sourceChannel: String? = nil) -> String {
        let legacy: [String: String] = ["pos": "till", "online": "web", "whatsapp": "chat"]
        let bucket = salesBucket ?? orderType.flatMap { legacy[$0] }
        switch bucket {
        case "till": return "POS"
        case "web": return "Web"
        case "quoted": return "Quoted"
        case "chat": return chatSources[sourceChannel ?? ""] ?? "Chat"
        default: return orderType ?? "—"
        }
    }

    /// The same, read from an order row of the API.
    public static func of(_ row: JSONValue) -> String {
        of(salesBucket: row["sales_bucket"]?.string, orderType: row["order_type"]?.string, sourceChannel: row["source_channel"]?.string)
    }
}
