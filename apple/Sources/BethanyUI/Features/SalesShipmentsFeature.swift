import SwiftUI
import BethanyKit

// MARK: - Shipments list (GET /admin/shipments — ShipmentController::index)

struct ShipmentsListView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var list: PagedList<Record>?
    @State private var search = ""
    @State private var status = ""
    @State private var from: Date?
    @State private var to: Date?
    @State private var sortBy = "shipped_at"
    @State private var sortDesc = true
    @State private var selection: Record.ID?

    /// ShipmentController::index allow-list.
    static let sorts: [(String, String)] = [("shipped_at", "Shipped"), ("status", "Status"), ("carrier", "Carrier"), ("estimated_delivery_date", "ETA"), ("created_at", "Created"), ("updated_at", "Updated"), ("tracking_number", "Tracking no.")]

    var body: some View {
        Page("Shipments", subtitle: list.map { "\($0.total) shipments" }) {
            VStack(alignment: .leading, spacing: Theme.Space.s) {
                HStack(spacing: Theme.Space.s) {
                    SalesSearchField(text: $search, placeholder: "Shipment no., order, tracking, customer") { Task { await reload() } }
                    Picker("Status", selection: $status) {
                        Text("All statuses").tag("")
                        ForEach(Sales.shipmentStatuses, id: \.self) { Text($0.humanized).tag($0) }
                    }.frame(maxWidth: 190)
                    Spacer()
                }
                HStack(spacing: Theme.Space.s) {
                    SalesOptionalDateField(label: "Shipped from", date: $from)
                    SalesOptionalDateField(label: "Shipped to", date: $to)
                    SalesLabeledField(label: "Sort") {
                        HStack(spacing: 4) {
                            Picker("Sort", selection: $sortBy) { ForEach(Self.sorts, id: \.0) { Text($0.1).tag($0.0) } }.labelsHidden()
                            Button { sortDesc.toggle() } label: { Image(systemName: sortDesc ? "arrow.down" : "arrow.up") }
                        }
                    }
                    Spacer()
                    Button("Apply") { Task { await reload() } }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                }
            }
            .onChange(of: status) { _, _ in Task { await reload() } }
            .onChange(of: sortBy) { _, _ in Task { await reload() } }
            .onChange(of: sortDesc) { _, _ in Task { await reload() } }
            if let list {
                LoadableView(list.state, retry: { Task { await list.load() } }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No shipments", message: "Shipments are created from a confirmed order.", symbol: "truck.box") }) { rows in
                    #if os(macOS)
                    Table(rows, selection: $selection) {
                        TableColumn("Shipment") { r in Text(r.string("shipment_number") ?? "#\(r.id)").font(Theme.Font.mono) }.width(min: 120)
                        TableColumn("Order") { r in Text(r.string("order_number") ?? "—").font(Theme.Font.mono) }.width(min: 110)
                        TableColumn("Customer") { r in Text(Sales.name(first: r.string("customer_first_name"), last: r.string("customer_last_name")) ?? "—").lineLimit(1) }
                        TableColumn("Carrier") { r in Text([r.string("carrier"), r.string("tracking_number")].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.secondary).lineLimit(1) }
                        TableColumn("Status") { r in StatusBadge(r.status) }.width(130)
                        TableColumn("Shipped") { r in DateText(r.date("shipped_at"), style: .date) }.width(110)
                        TableColumn("ETA") { r in Text(r.string("estimated_delivery_date").map { String($0.prefix(10)) } ?? "—").font(Theme.Font.secondary) }.width(100)
                    }
                    .frame(height: max(320, CGFloat(rows.count) * 34 + 40))
                    .onChange(of: selection) { _, new in if let new, let id = Int(new) { env.navigator.push(.shipment(id: id)); selection = nil } }
                    #else
                    LazyVStack(spacing: Theme.Space.s) {
                        ForEach(rows) { r in
                            SalesRowCard(route: Int(r.id).map { .shipment(id: $0) }) {
                                VStack(alignment: .leading, spacing: 3) {
                                    HStack { Text(r.string("shipment_number") ?? "").font(Theme.Font.mono); Spacer(); StatusBadge(r.status) }
                                    Text(Sales.name(first: r.string("customer_first_name"), last: r.string("customer_last_name")) ?? r.string("order_number") ?? "").font(Theme.Font.body)
                                    HStack {
                                        Text([r.string("order_number"), r.string("carrier"), r.string("tracking_number")].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                        Spacer()
                                        DateText(r.date("shipped_at"), style: .date)
                                    }
                                }
                            }
                        }
                    }
                    #endif
                    LoadMoreRow(list: list)
                }
            }
        }
        .task { if list == nil { await reload() } }
        .refreshable { await list?.load() }
    }

    private func reload() async {
        if list == nil { list = PagedList(client: env.session.client, path: "/v1/admin/shipments") }
        list?.query = [
            "search": search.isEmpty ? nil : search, "status": status.isEmpty ? nil : status,
            "start_date": from.map(HubDates.dayString), "end_date": to.map(HubDates.dayString),
            "sort_by": sortBy, "sort_order": sortDesc ? "desc" : "asc",
        ]
        await list?.load()
    }
}

// MARK: - Shipment detail (GET /admin/shipments/{id})

/// The shipment with its tracking history, attachments and the public
/// tracking link. Edit needs shipment.edit; tracking events, delivery,
/// cancellation and attachments need shipment.manage_tracking.
struct ShipmentDetailView: View {
    @Environment(AppEnvironment.self) private var env
    let shipmentId: Int
    @State private var resource: Resource<Record>?
    @State private var sheet: Sheet?
    @State private var showAudit = false
    @State private var uploading = false

    enum Sheet: Identifiable {
        case edit, tracking, deliver, cancel
        var id: String { String(describing: self) }
    }

    var body: some View {
        Page(resource?.value?.string("shipment_number") ?? "Shipment") {
            if let resource {
                LoadableView(resource.state, retry: { resource.load() }) { s in
                    header(s)
                    actions(s)
                    HStack(alignment: .top, spacing: Theme.Space.l) {
                        details(s)
                        attachments(s)
                    }
                    tracking(s)
                    if showAudit { ShipmentAuditLog(shipmentId: shipmentId) }
                }
            }
        }
        .task {
            if resource == nil {
                let client = env.session.client, id = shipmentId
                resource = Resource {
                    let json = try await client.get("/v1/admin/shipments/\(id)").json
                    return Record(json: json ?? .null)
                }
                resource?.load()
            }
        }
        .refreshable { await resource?.reload() }
        .sheet(item: $sheet) { which in
            if let s = resource?.value {
                let done: () -> Void = { sheet = nil; resource?.load() }
                let close: () -> Void = { sheet = nil }
                switch which {
                case .edit: EditShipmentSheet(shipment: s, onDone: done, onCancel: close)
                case .tracking: AddTrackingSheet(shipment: s, onDone: done, onCancel: close)
                case .deliver: MarkDeliveredSheet(shipment: s, onDone: done, onCancel: close)
                case .cancel: CancelShipmentSheet(shipment: s, onDone: done, onCancel: close)
                }
            }
        }
    }

    private var isTerminal: Bool { ["delivered", "cancelled"].contains(resource?.value?.status ?? "") }

    private func header(_ s: Record) -> some View {
        SectionCard {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 6) {
                    Text(s.string("shipment_number") ?? "").font(Theme.Font.pageTitle)
                    HStack(spacing: 6) {
                        StatusBadge(s["status_labels"]?[s.status ?? ""]?.string ?? s.status, tone: StatusTone.forStatus(s.status))
                        if let o = s.string("order_number"), let oid = s.int("order_id") {
                            Button { env.navigator.push(.order(id: oid)) } label: { Text("Order \(o)").font(Theme.Font.mono) }.buttonStyle(.plain)
                        }
                    }
                    HStack(spacing: Theme.Space.m) {
                        if let c = s.string("carrier") { Label(c, systemImage: "truck.box") }
                        if let t = s.string("tracking_number") { Label(t, systemImage: "number") }
                        if let o = s.string("outlet_name") { Label(o, systemImage: "storefront") }
                    }
                    .font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                }
                Spacer()
                VStack(alignment: .trailing, spacing: 2) {
                    DateText(s.date("shipped_at"))
                    if let eta = s.string("estimated_delivery_date") { Text("ETA \(eta.prefix(10))").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                    if let d = s.date("delivered_at") { HStack(spacing: 4) { Text("Delivered").font(Theme.Font.caption).foregroundStyle(Theme.Palette.success); DateText(d) } }
                }
            }
            // Milestones: TRACKING_MILESTONES up to milestone_index.
            let milestones = Sales.shipmentStatuses.filter { !["exception", "cancelled"].contains($0) }
            let index = s.int("milestone_index") ?? -1
            HStack(spacing: 4) {
                ForEach(Array(milestones.enumerated()), id: \.offset) { i, m in
                    Capsule().fill(i <= index ? Theme.Palette.brand : Theme.line).frame(height: 6)
                        .help(s["status_labels"]?[m]?.string ?? m.humanized)
                }
            }
        }
    }

    private func actions(_ s: Record) -> some View {
        let can = env.session.permissions
        return SectionCard {
            FlowRow {
                if let url = s.string("tracking_url") { CopyButton("Copy tracking link", text: url, confirmation: "Tracking link copied") }
                if let raw = s.string("carrier_tracking_url"), let url = URL(string: raw) { Link(destination: url) { Label("Carrier site", systemImage: "safari") } }
                DocumentButton("Download PDF", path: "/v1/admin/pdf/shipments/\(shipmentId)", suggestedName: "shipment-\(shipmentId).pdf")
                if can.can("shipment.edit"), !isTerminal { Button { sheet = .edit } label: { Label("Edit", systemImage: "pencil") } }
                if can.can("shipment.manage_tracking"), !isTerminal {
                    Button { sheet = .tracking } label: { Label("Add tracking event", systemImage: "plus.circle") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                    Button { sheet = .deliver } label: { Label("Mark delivered", systemImage: "checkmark.seal") }
                    Button(role: .destructive) { sheet = .cancel } label: { Label("Cancel shipment", systemImage: "xmark.octagon") }
                }
                Button { showAudit.toggle() } label: { Label(showAudit ? "Hide audit log" : "Audit log", systemImage: "doc.text.magnifyingglass") }
            }
        }
    }

    private func details(_ s: Record) -> some View {
        let ctx: (type: String, id: Int) = (type: "shipment", id: shipmentId)
        return SectionCard("Customer") {
            DetailRow("Name", Sales.name(first: s.string("customer_first_name"), last: s.string("customer_last_name")))
            ContactLine(label: "Phone", value: s.string("customer_phone"), field: "phone", customerId: s.int("customer_id"), context: ctx)
            ContactLine(label: "Email", value: s.string("customer_email"), field: "email", customerId: s.int("customer_id"), context: ctx)
            if let n = s.string("notes"), !n.isEmpty { DetailRow("Internal notes", n) }
        }
    }

    private func attachments(_ s: Record) -> some View {
        let list = (s["attachments"]?.array ?? []).map(Record.init(json:))
        return SectionCard("Attachments") {
            if list.isEmpty { Text("No waybill or documents attached.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary) }
            ForEach(list) { a in AttachmentRow(shipmentId: shipmentId, attachment: a, onChanged: { resource?.load() }) }
            if env.session.can("shipment.manage_tracking") {
                SalesFilePickButton(label: uploading ? "Uploading…" : "Add attachment", multiple: true, types: [.pdf, .image]) { files in
                    Task { await upload(files, to: "/v1/admin/shipments/\(shipmentId)/upload-attachment") }
                }
                .disabled(uploading)
                Text("New files are hidden from the customer until you make them visible.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            }
        }
    }

    private func upload(_ files: [SalesPickedFile], to path: String) async {
        uploading = true
        defer { uploading = false }
        do {
            try await ShipmentUploads.upload(files.map { AttachmentDraft(file: $0, isPublic: false) }, to: path, client: env.session.client)
            env.toasts.show("Uploaded")
            resource?.load()
        } catch { env.toasts.error(error) }
    }

    private func tracking(_ s: Record) -> some View {
        let events = (s["tracking_history"]?.array ?? []).map(Record.init(json:))
        return SectionCard("Tracking history") {
            if events.isEmpty { Text("No events yet.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary) }
            ForEach(events) { e in
                VStack(alignment: .leading, spacing: 3) {
                    HStack(spacing: 6) {
                        StatusBadge(e.string("label") ?? e.status, tone: StatusTone.forStatus(e.status))
                        if e["is_public"]?.bool == false { StatusBadge("staff only", tone: .neutral) }
                        if let l = e.string("location"), !l.isEmpty { Label(l, systemImage: "mappin").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                        Spacer()
                        DateText(e.date("event_time"))
                    }
                    if let d = e.string("description"), !d.isEmpty { Text(d).font(Theme.Font.secondary) }
                    if let by = e.string("added_by_name") { Text("by \(by)").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                    ForEach((e["attachments"]?.array ?? []).map(Record.init(json:))) { a in
                        AttachmentRow(shipmentId: shipmentId, attachment: a, onChanged: { resource?.load() })
                    }
                    if env.session.can("shipment.manage_tracking"), e["id"] != nil {
                        SalesFilePickButton(label: "Attach to this event", multiple: true) { files in
                            Task { await upload(files, to: "/v1/admin/shipments/\(shipmentId)/tracking/\(e.id)/upload-attachment") }
                        }
                        .font(Theme.Font.caption)
                    }
                }
                .padding(.vertical, 4)
                Divider()
            }
        }
    }
}

/// One attachment: name, visibility toggle (PATCH …/attachments/{a} {is_public})
/// and delete (DELETE …/attachments/{a}), both shipment.manage_tracking.
struct AttachmentRow: View {
    @Environment(AppEnvironment.self) private var env
    let shipmentId: Int
    let attachment: Record
    let onChanged: () -> Void
    var body: some View {
        HStack(spacing: 8) {
            Image(systemName: attachment["is_image"]?.bool == true ? "photo" : "doc")
            VStack(alignment: .leading, spacing: 1) {
                Text(attachment.string("name") ?? "file").font(Theme.Font.secondary).lineLimit(1)
                Text(attachment["is_public"]?.bool == true ? "Customer can see" : "Staff only").font(Theme.Font.caption).foregroundStyle(attachment["is_public"]?.bool == true ? Theme.Palette.success : Theme.textTertiary)
            }
            Spacer()
            if let raw = attachment.string("url"), let url = URL(string: raw) { Link(destination: url) { Image(systemName: "arrow.up.right.square") } }
            if env.session.can("shipment.manage_tracking") {
                Button { Task { await toggle() } } label: { Image(systemName: attachment["is_public"]?.bool == true ? "eye.slash" : "eye") }.buttonStyle(.plain).help("Toggle customer visibility")
                ConfirmButton("Remove attachment", message: "The file is deleted from the shipment.", destructive: true, action: { await delete() }) { Image(systemName: "trash") }.buttonStyle(.plain).foregroundStyle(Theme.Palette.danger)
            }
        }
        .font(Theme.Font.secondary)
    }

    private func toggle() async {
        do {
            _ = try await env.session.client.patch("/v1/admin/shipments/\(shipmentId)/attachments/\(attachment.id)", json: .object(["is_public": .bool(!(attachment["is_public"]?.bool ?? false))]))
            onChanged()
        } catch { env.toasts.error(error) }
    }

    private func delete() async {
        do { _ = try await env.session.client.delete("/v1/admin/shipments/\(shipmentId)/attachments/\(attachment.id)"); onChanged() } catch { env.toasts.error(error) }
    }
}

/// GET /shipments/{id}/audit-log → {logs: [{event, label, description, actor_name, created_at}]}.
struct ShipmentAuditLog: View {
    @Environment(AppEnvironment.self) private var env
    let shipmentId: Int
    @State private var resource: Resource<[Record]>?
    var body: some View {
        SectionCard("Audit log") {
            if let resource {
                LoadableView(resource.state, retry: { resource.load() }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No activity recorded", symbol: "doc.text.magnifyingglass") }) { rows in
                    ForEach(rows) { r in
                        VStack(alignment: .leading, spacing: 2) {
                            HStack { Text(r.string("label") ?? (r.string("event") ?? "").humanized).font(Theme.Font.secondary.weight(.semibold)); Spacer(); DateText(r.date("created_at")) }
                            if let d = r.string("description") { Text(d).font(Theme.Font.secondary) }
                            Text(r.string("actor_name") ?? "System").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                        }
                        Divider()
                    }
                }
            }
        }
        .task {
            if resource == nil {
                let client = env.session.client, id = shipmentId
                resource = Resource { (try await client.get("/v1/admin/shipments/\(id)/audit-log").json?["logs"]?.array ?? []).map(Record.init(json:)) }
                resource?.load()
            }
        }
    }
}

// MARK: Sheets

/// PUT /shipments/{id} {carrier?, tracking_number?, carrier_tracking_url?,
/// estimated_delivery_date?, notes?, description?} — shipment.edit.
struct EditShipmentSheet: View {
    @Environment(AppEnvironment.self) private var env
    let shipment: Record
    let onDone: () -> Void
    let onCancel: () -> Void
    @State private var carrier = ""
    @State private var tracking = ""
    @State private var trackingURL = ""
    @State private var eta: Date?
    @State private var notes = ""
    @State private var description = ""
    var body: some View {
        SheetFrame(title: "Edit shipment", submitLabel: "Save", canSubmit: !carrier.trimmingCharacters(in: .whitespaces).isEmpty, onCancel: onCancel, onSubmit: {
            var body: [String: JSONValue] = ["carrier": .string(carrier.trimmingCharacters(in: .whitespaces))]
            body["tracking_number"] = Sales.optionalString(tracking)
            body["carrier_tracking_url"] = Sales.optionalString(trackingURL)
            body["estimated_delivery_date"] = eta.map { JSONValue.string(HubDates.dayString($0)) } ?? JSONValue.null
            body["notes"] = Sales.optionalString(notes)
            body["description"] = Sales.optionalString(description)
            _ = try await env.session.client.put("/v1/admin/shipments/\(shipment.id)", json: .object(body))
            env.toasts.show("Shipment updated")
            onDone()
        }) {
            TextInput(label: "Carrier", text: $carrier)
            TextInput(label: "Tracking number", text: $tracking)
            TextInput(label: "Carrier tracking URL", text: $trackingURL)
            SalesOptionalDateField(label: "Estimated delivery", date: $eta)
            TextInput(label: "Customer-facing note (on the first tracking event)", text: $description, multiline: true)
            TextInput(label: "Internal notes", text: $notes, multiline: true)
        }
        .onAppear {
            carrier = shipment.string("carrier") ?? ""
            tracking = shipment.string("tracking_number") ?? ""
            trackingURL = shipment.string("carrier_tracking_url") ?? ""
            eta = shipment.date("estimated_delivery_date")
            notes = shipment.string("notes") ?? ""
            description = (shipment["tracking_history"]?.array ?? []).first { $0["status"]?.string == "order_confirmed" }?["description"]?.string ?? ""
        }
    }
}

/// POST /shipments/{id}/tracking {status, location?, description?, event_time?, is_public}.
struct AddTrackingSheet: View {
    @Environment(AppEnvironment.self) private var env
    let shipment: Record
    let onDone: () -> Void
    let onCancel: () -> Void
    @State private var status = "in_transit"
    @State private var location = ""
    @State private var description = ""
    @State private var isPublic = true
    @State private var files: [AttachmentDraft] = []
    var body: some View {
        SheetFrame(title: "Add tracking event", submitLabel: "Add event", onCancel: onCancel, onSubmit: {
            var body: [String: JSONValue] = ["status": .string(status), "is_public": .bool(isPublic)]
            if !location.trimmingCharacters(in: .whitespaces).isEmpty { body["location"] = .string(location) }
            if !description.trimmingCharacters(in: .whitespaces).isEmpty { body["description"] = .string(description) }
            let client = env.session.client
            _ = try await client.post("/v1/admin/shipments/\(shipment.id)/tracking", json: .object(body))
            if !files.isEmpty {
                // The new event is the latest in the refreshed history.
                let fresh = try await client.get("/v1/admin/shipments/\(shipment.id)").json
                if let latest = fresh?["tracking_history"]?.array?.last?["id"]?.string {
                    try await ShipmentUploads.upload(files, to: "/v1/admin/shipments/\(shipment.id)/tracking/\(latest)/upload-attachment", client: client)
                }
            }
            env.toasts.show("Tracking event added")
            onDone()
        }) {
            Picker("Stage", selection: $status) {
                ForEach(Sales.shipmentStatuses.filter { $0 != "cancelled" }, id: \.self) { Text(shipment["status_labels"]?[$0]?.string ?? $0.humanized).tag($0) }
            }
            TextInput(label: "Location", text: $location)
            TextInput(label: "Note for the customer (optional)", text: $description, multiline: true)
            Toggle("Visible on the customer's tracking page", isOn: $isPublic)
            AttachmentDraftList(files: $files)
        }
    }
}

/// POST /shipments/{id}/mark-delivered {delivered_to?, signature?, notes?}.
struct MarkDeliveredSheet: View {
    @Environment(AppEnvironment.self) private var env
    let shipment: Record
    let onDone: () -> Void
    let onCancel: () -> Void
    @State private var deliveredTo = ""
    @State private var notes = ""
    var body: some View {
        SheetFrame(title: "Mark delivered", submitLabel: "Mark delivered", onCancel: onCancel, onSubmit: {
            var body: [String: JSONValue] = [:]
            if !deliveredTo.trimmingCharacters(in: .whitespaces).isEmpty { body["delivered_to"] = .string(deliveredTo) }
            if !notes.trimmingCharacters(in: .whitespaces).isEmpty { body["notes"] = .string(notes) }
            let r = try await env.session.client.post("/v1/admin/shipments/\(shipment.id)/mark-delivered", json: .object(body))
            env.toasts.show(r.json?["message"]?.string ?? "Marked delivered")
            onDone()
        }) {
            Text("The order moves to Delivered as well.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            TextInput(label: "Delivered to", text: $deliveredTo)
            TextInput(label: "Notes", text: $notes, multiline: true)
        }
    }
}

/// POST /shipments/{id}/cancel {reason}.
struct CancelShipmentSheet: View {
    @Environment(AppEnvironment.self) private var env
    let shipment: Record
    let onDone: () -> Void
    let onCancel: () -> Void
    @State private var reason = ""
    var body: some View {
        SheetFrame(title: "Cancel shipment", submitLabel: "Cancel shipment", canSubmit: !reason.trimmingCharacters(in: .whitespaces).isEmpty, destructive: true, onCancel: onCancel, onSubmit: {
            let r = try await env.session.client.post("/v1/admin/shipments/\(shipment.id)/cancel", json: .object(["reason": .string(reason)]))
            env.toasts.show(r.json?["message"]?.string ?? "Shipment cancelled")
            onDone()
        }) {
            Text("The order returns to Confirmed so a new shipment can be created.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            TextInput(label: "Reason (required)", text: $reason, multiline: true)
        }
    }
}
