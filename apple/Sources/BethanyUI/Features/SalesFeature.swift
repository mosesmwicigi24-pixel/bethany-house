import SwiftUI
import BethanyKit
import UniformTypeIdentifiers

/// Sales: orders (per channel), the pending queue, order detail, quotations,
/// invoices, interest carts, shipments, customers and outstanding balances.
///
/// Every endpoint, query key and payload key here was read from
/// backend/routes/api.php and the controllers it names; permissions are the
/// slugs those routes check. `.handoff(id:)` stays on the console bridge.
/// Money is shown as the server sent it (`Record.money`); nothing is summed,
/// converted or rounded on the device.
@MainActor
public enum SalesFeature {
    public static func register() {
        FeatureRegistry.register({ if case .orders = $0 { return true }; return false }) { route in
            if case .orders(let channel) = route { return AnyView(OrdersListView(channel: channel)) }
            return AnyView(OrdersListView(channel: nil))
        }
        FeatureRegistry.register(.pendingQueue) { _ in AnyView(PendingQueueView()) }
        FeatureRegistry.register({ if case .order = $0 { return true }; return false }) { route in
            if case .order(let id) = route { return AnyView(OrderDetailView(orderId: id)) }
            return AnyView(EmptyView())
        }
        FeatureRegistry.register(.quotations) { _ in AnyView(QuotationsView()) }
        FeatureRegistry.register(.invoices) { _ in AnyView(InvoicesView()) }
        FeatureRegistry.register(.interestCarts) { _ in AnyView(InterestCartsView()) }
        FeatureRegistry.register(.shipments) { _ in AnyView(ShipmentsListView()) }
        FeatureRegistry.register({ if case .shipment = $0 { return true }; return false }) { route in
            if case .shipment(let id) = route { return AnyView(ShipmentDetailView(shipmentId: id)) }
            return AnyView(EmptyView())
        }
        FeatureRegistry.register(.customers) { _ in AnyView(CustomersListView()) }
        FeatureRegistry.register({ if case .customer = $0 { return true }; return false }) { route in
            if case .customer(let id) = route { return AnyView(CustomerDetailView(customerId: id)) }
            return AnyView(EmptyView())
        }
        FeatureRegistry.register(.balances) { _ in AnyView(OutstandingBalancesView()) }
    }
}

// MARK: - Shared rules and helpers

enum Sales {
    /// App\Support\OrderStatusMachine::TRANSITIONS — the only legal moves.
    static let transitions: [String: [String]] = [
        "pending":    ["processing", "confirmed", "shipped", "delivered", "completed", "cancelled"],
        "processing": ["confirmed", "shipped", "delivered", "completed", "cancelled"],
        "confirmed":  ["processing", "shipped", "delivered", "completed", "cancelled"],
        "shipped":    ["delivered", "completed", "cancelled"],
        "delivered":  ["completed", "refunded"],
        "completed":  ["refunded"],
        "cancelled":  [],
        "refunded":   [],
    ]

    static func nextStatuses(from status: String?) -> [String] {
        guard let status else { return [] }
        return transitions[status.lowercased()] ?? []
    }

    /// An order that takes no more money (Order::isPayable is the server's word).
    static let deadStatuses: Set<String> = ["cancelled", "voided", "refunded"]
    static func isDead(_ status: String?) -> Bool { deadStatuses.contains(status?.lowercased() ?? "") }

    /// Shipment stages, in the order ShipmentController::STATUS_LABELS lists them.
    static let shipmentStatuses = ["order_confirmed", "processing", "ready_to_ship", "picked_up", "in_transit", "out_for_delivery", "delivery_attempted", "delivered", "exception", "cancelled"]

    static let interestCartStatuses: [(String, String)] = [
        ("active_cart", "Active cart"), ("checkout_started", "Checkout started"), ("online_order", "Ordered online"),
        ("whatsapp_order", "Ordered on WhatsApp"), ("abandoned", "Abandoned"),
    ]
    static let interestCartChannels = ["web", "whatsapp", "messenger", "instagram", "facebook"]

    /// CustomerContactRevealController::REASONS.
    static let revealReasons: [(String, String)] = [
        ("payment_follow_up", "Payment follow-up"), ("delivery", "Delivery"),
        ("customer_callback", "Customer callback"), ("order_issue", "Order issue"),
    ]

    /// A value holding the mask character was masked by contacts.mask
    /// (CustomerContacts::MASK_CHAR, never typed by a person).
    static func isMasked(_ value: String?) -> Bool { value?.contains("•") == true }

    /// Neema's inbox, keyed on the order number first (lib/neema.ts).
    static let neemaBase = URL(string: "https://neema.bethanyhouse.co.ke")!
    static func chatURL(phone: String?, orderNumber: String?) -> URL? {
        let num = waNumber(phone)
        let ref = (orderNumber ?? "").trimmingCharacters(in: .whitespaces)
        guard num != nil || !ref.isEmpty else { return nil }
        var c = URLComponents(url: neemaBase.appendingPathComponent("dashboard"), resolvingAgainstBaseURL: false)!
        var items = [URLQueryItem(name: "open", value: num ?? ref)]
        if !ref.isEmpty { items.append(URLQueryItem(name: "ref", value: ref)) }
        c.queryItems = items
        return c.url
    }

    /// lib/whatsapp.ts waNumber: 07… → 2547…; masked values never qualify.
    static func waNumber(_ phone: String?) -> String? {
        guard let phone, !isMasked(phone) else { return nil }
        var d = phone.filter(\.isNumber)
        if d.hasPrefix("0") { d = "254" + d.dropFirst() } else if d.count == 9 { d = "254" + d }
        return d.count >= 11 ? d : nil
    }

    static func name(first: String?, last: String?) -> String? {
        let n = [first, last].compactMap { $0 }.joined(separator: " ").trimmingCharacters(in: .whitespaces)
        return n.isEmpty ? nil : n
    }

    /// A typed amount goes to the server verbatim (Laravel `numeric` accepts
    /// the decimal string); the device never turns it into a float.
    static func amountJSON(_ text: String) -> JSONValue? {
        let t = text.trimmingCharacters(in: .whitespaces).replacingOccurrences(of: ",", with: "")
        guard !t.isEmpty, Decimal(string: t, locale: Locale(identifier: "en_US_POSIX")) != nil else { return nil }
        return .string(t)
    }

    static func optionalString(_ text: String) -> JSONValue {
        let t = text.trimmingCharacters(in: .whitespacesAndNewlines)
        return t.isEmpty ? .null : .string(t)
    }

    static func mimeType(for url: URL) -> String {
        UTType(filenameExtension: url.pathExtension)?.preferredMIMEType ?? "application/octet-stream"
    }

    /// Reads a file the person picked with `.fileImporter`.
    static func readPickedFile(_ url: URL) throws -> SalesPickedFile {
        let access = url.startAccessingSecurityScopedResource()
        defer { if access { url.stopAccessingSecurityScopedResource() } }
        let data = try Data(contentsOf: url)
        return SalesPickedFile(data: data, filename: url.lastPathComponent, mimeType: mimeType(for: url))
    }
}

/// A file read from the person's device, ready to become a multipart part.
struct SalesPickedFile: Identifiable, Sendable {
    let id = UUID()
    let data: Data
    let filename: String
    let mimeType: String
    func part(named name: String) -> APIClient.MultipartPart {
        APIClient.MultipartPart(name: name, data: data, filename: filename, mimeType: mimeType)
    }
}

extension JSONValue {
    /// `{data: [...]}` or a bare array, as records.
    var salesRecords: [Record] { (self["data"]?.array ?? self.array ?? []).map(Record.init(json:)) }
}

extension Record {
    var salesCustomerName: String? {
        string("customer_name") ?? Sales.name(first: string("customer_first_name"), last: string("customer_last_name"))
    }
}

// MARK: - Sheet scaffolding

/// A form sheet: title, fields, cancel and one submit that reports an API
/// error inline (422 field errors first) and closes on success.
struct SheetFrame<Content: View>: View {
    let title: String
    let submitLabel: String
    var canSubmit: Bool = true
    var destructive: Bool = false
    let onCancel: () -> Void
    let onSubmit: () async throws -> Void
    @ViewBuilder let content: () -> Content
    @State private var busy = false
    @State private var error: APIError?

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(title).font(Theme.Font.sectionTitle).padding(Theme.Space.xl).padding(.bottom, 0)
            ScrollView {
                VStack(alignment: .leading, spacing: Theme.Space.m) {
                    content()
                    if let error {
                        VStack(alignment: .leading, spacing: 2) {
                            Text(error.message).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger)
                            ForEach(error.errors.keys.sorted(), id: \.self) { k in
                                FieldError("\(k.humanized): \(error.errors[k]?.first ?? "")")
                            }
                        }
                    }
                }
                .padding(.horizontal, Theme.Space.xl)
            }
            HStack {
                Spacer()
                Button("Cancel", action: onCancel).keyboardShortcut(.cancelAction)
                Button(submitLabel, role: destructive ? .destructive : nil) {
                    busy = true
                    error = nil
                    Task {
                        defer { busy = false }
                        do { try await onSubmit() } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
                    }
                }
                .buttonStyle(.borderedProminent)
                .tint(destructive ? Theme.Palette.danger : Theme.Palette.brand)
                .disabled(!canSubmit || busy)
                .keyboardShortcut(.defaultAction)
            }
            .padding(Theme.Space.xl)
        }
        #if os(macOS)
        .frame(minWidth: 440, idealWidth: 520, minHeight: 200, maxHeight: 720)
        #endif
        .overlay { if busy { ProgressView().controlSize(.small) } }
    }
}

struct SalesLabeledField<Field: View>: View {
    let label: String
    @ViewBuilder let field: () -> Field
    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(label).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            field()
        }
    }
}

struct TextInput: View {
    let label: String
    @Binding var text: String
    var multiline = false
    var body: some View {
        SalesLabeledField(label: label) {
            if multiline {
                TextField(label, text: $text, axis: .vertical).lineLimit(3...8).textFieldStyle(.roundedBorder)
            } else {
                TextField(label, text: $text).textFieldStyle(.roundedBorder)
            }
        }
    }
}

/// A date that may be unset; the API gets "yyyy-MM-dd" in Nairobi time.
struct SalesOptionalDateField: View {
    let label: String
    @Binding var date: Date?
    var body: some View {
        SalesLabeledField(label: label) {
            HStack {
                if let d = date {
                    DatePicker("", selection: Binding(get: { d }, set: { date = $0 }), displayedComponents: .date).labelsHidden()
                    Button { date = nil } label: { Image(systemName: "xmark.circle.fill") }.buttonStyle(.plain).foregroundStyle(Theme.textTertiary)
                } else {
                    Button("Set date") { date = Date() }
                }
            }
        }
    }
}

// MARK: - List chrome

struct SalesSearchField: View {
    @Binding var text: String
    var placeholder = "Search"
    let onSubmit: () -> Void
    var body: some View {
        HStack {
            Image(systemName: "magnifyingglass").foregroundStyle(Theme.textTertiary)
            TextField(placeholder, text: $text).textFieldStyle(.plain).onSubmit(onSubmit)
            if !text.isEmpty { Button { text = ""; onSubmit() } label: { Image(systemName: "xmark.circle.fill") }.buttonStyle(.plain).foregroundStyle(Theme.textTertiary) }
        }
        .padding(.horizontal, 10).padding(.vertical, 7)
        .background(Theme.field, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.field).stroke(Theme.line))
        .frame(maxWidth: 360)
    }
}

/// "Load more" tail for a `PagedList`.
struct LoadMoreRow<T: Decodable & Sendable & Identifiable>: View {
    let list: PagedList<T>
    var body: some View {
        if list.hasMore {
            HStack {
                Spacer()
                Button { Task { await list.loadMore() } } label: {
                    if list.isLoadingMore { ProgressView().controlSize(.small) } else { Text("Load more (\(list.items.count) of \(list.total))") }
                }
                .disabled(list.isLoadingMore)
                Spacer()
            }
            .padding(.vertical, Theme.Space.s)
        } else if list.total > 0 {
            Text("\(list.total) in total").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary).frame(maxWidth: .infinity)
        }
    }
}

/// A tappable list row card (iPhone/iPad) that opens a route.
struct SalesRowCard<Content: View>: View {
    @Environment(AppEnvironment.self) private var env
    let route: Route?
    @ViewBuilder let content: () -> Content
    var body: some View {
        Button {
            if let route { env.navigator.push(route) }
        } label: {
            content()
                .padding(Theme.Space.m)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
                .overlay(RoundedRectangle(cornerRadius: Theme.Radius.field).stroke(Theme.line))
                .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(route == nil)
    }
}

/// Copies text and confirms with a toast.
struct CopyButton: View {
    @Environment(AppEnvironment.self) private var env
    let label: String
    let text: String
    let confirmation: String
    init(_ label: String, text: String, confirmation: String = "Copied") { self.label = label; self.text = text; self.confirmation = confirmation }
    var body: some View {
        Button { Clipboard.copy(text); env.toasts.show(confirmation) } label: { Label(label, systemImage: "doc.on.doc") }
    }
}

/// Totals as the server computed them, in the order's currency.
struct MoneyBreakdown: View {
    let record: Record
    var body: some View {
        VStack(spacing: 2) {
            DetailRow("Subtotal") { MoneyText(record.money("subtotal")) }
            if let d = record.money("discount_amount"), !d.isZero { DetailRow("Discount") { MoneyText(d) } }
            DetailRow("Tax") { MoneyText(record.money("tax_amount")) }
            if let s = record.money("shipping_amount"), !s.isZero || record["shipping_fee_overridden"]?.bool == true {
                DetailRow("Shipping\(record["shipping_fee_overridden"]?.bool == true ? " (set by staff)" : "")") { MoneyText(s) }
            }
            Divider()
            DetailRow("Total") { MoneyText(record.money("total_amount"), emphasis: true) }
        }
    }
}

// MARK: - Masked contacts and reveal

/// A contact the API may have masked (contacts.mask). Shows what came back;
/// when masked and the person may reveal, offers the audited reveal
/// (POST /customers/{id}/reveal — one field, one reason, one sale or shipment).
struct ContactLine: View {
    @Environment(AppEnvironment.self) private var env
    let label: String
    let value: String?
    let field: String          // phone | email | address
    let customerId: Int?
    let context: (type: String, id: Int)?
    @State private var revealing = false
    @State private var revealed: String?

    var body: some View {
        DetailRow(label) {
            HStack(spacing: 6) {
                Text(revealed ?? value ?? "—").textSelection(.enabled)
                if revealed == nil, Sales.isMasked(value), let customerId, env.session.permissions.canAny(["customers.view", "pos.access"]) {
                    Button { revealing = true } label: { Image(systemName: "eye") }.buttonStyle(.plain).help("Reveal (audited)")
                        .sheet(isPresented: $revealing) {
                            RevealSheet(customerId: customerId, field: field, context: context) { revealed = $0; revealing = false } onCancel: { revealing = false }
                        }
                }
            }
        }
    }
}

struct RevealSheet: View {
    @Environment(AppEnvironment.self) private var env
    let customerId: Int
    let field: String
    let context: (type: String, id: Int)?
    let onRevealed: (String) -> Void
    let onCancel: () -> Void
    @State private var reason = Sales.revealReasons[0].0

    var body: some View {
        SheetFrame(title: "Reveal \(field)", submitLabel: "Reveal", onCancel: onCancel, onSubmit: {
            var body: [String: JSONValue] = ["field": .string(field), "reason": .string(reason)]
            if let context { body["context"] = .object(["type": .string(context.type), "id": .number(Double(context.id))]) }
            let r = try await env.session.client.post("/v1/admin/customers/\(customerId)/reveal", json: .object(body))
            let v = r.json?["value"]
            let text: String
            if let s = v?.string { text = s }
            else if let o = v?.object { text = ["address_line_1", "address_line_2", "city", "postal_code", "country_code"].compactMap { o[$0]?.string }.filter { !$0.isEmpty }.joined(separator: ", ") }
            else { text = "—" }
            onRevealed(text)
        }) {
            Text("Every reveal is recorded with your name, the reason and the sale it is for; 20 an hour.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            Picker("Reason", selection: $reason) {
                ForEach(Sales.revealReasons, id: \.0) { Text($0.1).tag($0.0) }
            }
            if context == nil {
                Text("Without an open sale or shipment the server allows a reveal only to roles that see contacts in full.").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning)
            }
        }
    }
}

// MARK: - File picking for multipart uploads

/// Picks one or more files and hands them over as multipart parts.
struct SalesFilePickButton: View {
    @Environment(AppEnvironment.self) private var env
    let label: String
    var multiple = false
    var types: [UTType] = [.pdf, .image]
    let onPicked: ([SalesPickedFile]) -> Void
    @State private var picking = false

    var body: some View {
        Button { picking = true } label: { Label(label, systemImage: "paperclip") }
            .fileImporter(isPresented: $picking, allowedContentTypes: types, allowsMultipleSelection: multiple) { result in
                switch result {
                case .success(let urls):
                    do { onPicked(try urls.map(Sales.readPickedFile)) } catch { env.toasts.error(error) }
                case .failure(let e):
                    env.toasts.show(e.localizedDescription, tone: .danger)
                }
            }
    }
}

/// A download that needs a POST (the Google Contacts CSV asks for the
/// password and two-step code in the body). Same session handling as
/// `DocumentButton`; the file is saved to a temporary location and opened
/// or shared.
enum DocumentSaver {
    @MainActor
    static func save(_ data: Data, name: String, env: AppEnvironment, present: (URL) -> Void) {
        let url = FileManager.default.temporaryDirectory.appendingPathComponent(name)
        do {
            try data.write(to: url)
            #if os(macOS)
            NSWorkspace.shared.open(url)
            #else
            present(url)
            #endif
        } catch { env.toasts.error(error) }
    }
}
