import SwiftUI
import BethanyKit

// MARK: - Comments thread (GET/POST/DELETE /admin/comments — CommentController)

/// The unified thread: `?model=Order&id=` lists, POST {model, id, body, type,
/// is_internal, parent_id?} adds, DELETE /comments/{id} removes one's own.
struct CommentsThread: View {
    @Environment(AppEnvironment.self) private var env
    let model: String
    let id: Int
    @State private var resource: Resource<[Record]>?
    @State private var body_ = ""
    @State private var internalNote = true
    @State private var posting = false

    var body: some View {
        SectionCard("Comments") {
            if let resource {
                LoadableView(resource.state, retry: { resource.load() }) { comments in
                    if comments.isEmpty { Text("No comments yet.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary) }
                    ForEach(comments) { c in commentRow(c, depth: 0) }
                }
            }
            HStack(alignment: .bottom) {
                TextField("Write a comment… (@name to mention)", text: $body_, axis: .vertical).lineLimit(1...5).textFieldStyle(.roundedBorder)
                Toggle("Internal", isOn: $internalNote).toggleStyle(.switch).font(Theme.Font.caption).fixedSize()
                Button("Post") { Task { await post() } }.disabled(posting || body_.trimmingCharacters(in: .whitespaces).isEmpty)
            }
        }
        .task {
            if resource == nil {
                let client = env.session.client
                let model = model, id = id
                resource = Resource {
                    let r = try await client.get("/v1/admin/comments", query: ["model": model, "id": String(id)])
                    return (r.json?["comments"]?.array ?? []).map(Record.init(json:))
                }
                resource?.load()
            }
        }
    }

    private func commentRow(_ c: Record, depth: Int) -> AnyView {
        let mine = c["user"]?["id"]?.int == env.session.me?.id
        return AnyView(
            VStack(alignment: .leading, spacing: 2) {
                HStack(spacing: 6) {
                    Text(c["user"]?["name"]?.string ?? "System").font(Theme.Font.caption.weight(.semibold))
                    if c.string("type") == "system" { StatusBadge("system", tone: .neutral) }
                    else if c["is_internal"]?.bool == true { StatusBadge("internal", tone: .neutral) }
                    if c["edited_at"]?.isNull == false { Text("edited").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                    Spacer()
                    DateText(c.date("created_at"), style: .relative)
                    if mine, c.string("type") != "system" {
                        ConfirmButton("Delete comment", message: "This removes your comment from the thread.", destructive: true, action: { await delete(c) }) { Image(systemName: "trash").font(Theme.Font.caption) }.buttonStyle(.plain)
                    }
                }
                Text(c.string("plain_body") ?? c.string("body") ?? "").font(Theme.Font.body).textSelection(.enabled)
                ForEach((c["replies"]?.array ?? []).map(Record.init(json:))) { r in commentRow(r, depth: depth + 1) }
            }
            .padding(.leading, CGFloat(depth) * 16)
            .padding(.vertical, 4)
        )
    }

    private func post() async {
        posting = true
        defer { posting = false }
        do {
            _ = try await env.session.client.post("/v1/admin/comments", json: .object([
                "model": .string(model), "id": .number(Double(id)), "body": .string(body_.trimmingCharacters(in: .whitespacesAndNewlines)),
                "type": .string("comment"), "is_internal": .bool(internalNote),
            ]))
            body_ = ""
            resource?.load()
        } catch { env.toasts.error(error) }
    }

    private func delete(_ c: Record) async {
        do { _ = try await env.session.client.delete("/v1/admin/comments/\(c.id)"); resource?.load() } catch { env.toasts.error(error) }
    }
}

// MARK: - Audit log (a paginated {data: [...]} of activity rows)

struct SalesAuditLogSection: View {
    @Environment(AppEnvironment.self) private var env
    let path: String
    @State private var list: PagedList<Record>?
    var body: some View {
        SectionCard("Audit log") {
            if let list {
                LoadableView(list.state, retry: { Task { await list.load() } }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No activity recorded", symbol: "doc.text.magnifyingglass") }) { rows in
                    ForEach(rows) { r in
                        VStack(alignment: .leading, spacing: 2) {
                            HStack {
                                Text((r.string("action") ?? r.string("event") ?? "activity").humanized).font(Theme.Font.secondary.weight(.semibold))
                                Spacer()
                                DateText(r.date("created_at"))
                            }
                            if let d = r.string("description") { Text(d).font(Theme.Font.secondary) }
                            Text([r.string("actor_name") ?? "System", r.string("ip_address")].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                        }
                        .padding(.vertical, 3)
                        Divider()
                    }
                    LoadMoreRow(list: list)
                }
            }
        }
        .task {
            if list == nil {
                list = PagedList(client: env.session.client, path: path)
                await list?.load()
            }
        }
    }
}

// MARK: - Document revisions (GET /orders/{id}/document-revisions)

/// The invoice copy's history and each payment's corrections
/// (SalesDocumentRevisionController::index): `{invoice: {number, status,
/// revision, revisions[]} | null, payments: {"<payment id>": revisions[]}}`.
struct SalesRevisionsSection: View {
    @Environment(AppEnvironment.self) private var env
    let orderId: Int
    let order: Record
    let onRevise: (Record) -> Void
    @State private var resource: Resource<JSONValue>?

    var body: some View {
        SectionCard("Document revisions") {
            if let resource {
                LoadableView(resource.state, retry: { resource.load() }) { json in
                    if let inv = json["invoice"], !inv.isNull {
                        HStack {
                            Text("Invoice \(inv["number"]?.string ?? "")").font(Theme.Font.body)
                            StatusBadge(inv["status"]?.string)
                            Text("Rev \(inv["revision"]?.string ?? "0")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        }
                        RevisionList(rows: (inv["revisions"]?.array ?? []).map(Record.init(json:)))
                    } else {
                        Text("No invoice document on this order.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                    }
                    let payments = json["payments"]?.object ?? [:]
                    ForEach(payments.keys.sorted(), id: \.self) { pid in
                        let rows = (payments[pid]?.array ?? []).map(Record.init(json:))
                        if !rows.isEmpty {
                            Text("Payment #\(pid)").font(Theme.Font.secondary.weight(.semibold)).padding(.top, 4)
                            RevisionList(rows: rows)
                        }
                    }
                }
            }
        }
        .task {
            if resource == nil {
                let client = env.session.client, id = orderId
                resource = Resource { try await client.get("/v1/admin/orders/\(id)/document-revisions").json ?? .null }
                resource?.load()
            }
        }
    }
}

/// DocumentRevision rows: revision, reason, changes[], by, created_at.
struct RevisionList: View {
    let rows: [Record]
    var body: some View {
        if rows.isEmpty {
            Text("No revisions.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
        ForEach(rows) { r in
            VStack(alignment: .leading, spacing: 2) {
                HStack {
                    Text("Rev \(r.string("revision") ?? "")").font(Theme.Font.caption.weight(.semibold))
                    Text(r.string("by") ?? "—").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    Spacer()
                    DateText(r.date("created_at"))
                }
                Text(r.string("reason") ?? "").font(Theme.Font.secondary)
                ForEach(Array((r["changes"]?.array ?? []).enumerated()), id: \.offset) { _, c in
                    Text("· \(c.string ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                }
            }
            .padding(.vertical, 2)
        }
    }
}

// MARK: - Pending proposals (GET /proposals?subject_type=&subject_ids=&status=open)

/// Phase 3C changes waiting for a signature on this record (deposit credit
/// past the band, and others); `can_withdraw` offers POST /proposals/{id}/withdraw.
struct PendingProposalsSection: View {
    @Environment(AppEnvironment.self) private var env
    let subjectType: String
    let subjectId: Int
    @State private var resource: Resource<[Record]>?

    var body: some View {
        Group {
            if let resource, let rows = resource.value, !rows.isEmpty {
                SectionCard("Pending changes") {
                    ForEach(rows) { p in
                        HStack(alignment: .top) {
                            VStack(alignment: .leading, spacing: 2) {
                                HStack(spacing: 6) {
                                    Text(p.string("title") ?? (p.string("event") ?? "").humanized).font(Theme.Font.body)
                                    StatusBadge(p.status)
                                }
                                ForEach(Array((p["changes"]?.array ?? []).enumerated()), id: \.offset) { _, c in
                                    Text("\(c["label"]?.string ?? c["field"]?.string ?? ""): \(c["old_display"]?.string ?? "—") → \(c["new_display"]?.string ?? "—")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                }
                                if let a = p["request"]?["awaiting"]?.string { Text("Awaiting: \(a.humanized)").font(Theme.Font.caption).foregroundStyle(Theme.Palette.amber) }
                                Text("Raised by \(p["maker"]?["name"]?.string ?? "—") · \(p.string("reference") ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                            }
                            Spacer()
                            if p["can_withdraw"]?.bool == true {
                                ConfirmButton("Withdraw", message: "The proposed change is dropped without being applied.", destructive: true, action: { await withdraw(p) }) { Text("Withdraw") }.font(Theme.Font.secondary)
                            }
                        }
                    }
                }
            }
        }
        .task {
            if resource == nil {
                let client = env.session.client, type = subjectType, id = subjectId
                resource = Resource {
                    let r = try await client.get("/v1/admin/proposals", query: ["subject_type": type, "subject_ids": String(id), "status": "open"])
                    return r.json?.salesRecords ?? []
                }
                resource?.load()
            }
        }
    }

    private func withdraw(_ p: Record) async {
        do { _ = try await env.session.client.post("/v1/admin/proposals/\(p.id)/withdraw"); env.toasts.show("Withdrawn"); resource?.load() } catch { env.toasts.error(error) }
    }
}

// MARK: - Shipments on an order (GET /shipments?order_id=; POST /orders/{id}/shipments)

struct OrderShipmentsSection: View {
    @Environment(AppEnvironment.self) private var env
    let order: Record
    let onCreate: () -> Void
    @State private var resource: Resource<[Record]>?

    var body: some View {
        SectionCard("Shipments") {
            if let resource {
                LoadableView(resource.state, retry: { resource.load() }) { rows in
                    if rows.isEmpty { Text("No shipment yet.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary) }
                    ForEach(rows) { s in
                        HStack {
                            VStack(alignment: .leading, spacing: 2) {
                                HStack(spacing: 6) { Text(s.string("shipment_number") ?? "#\(s.id)").font(Theme.Font.mono); StatusBadge(s.status) }
                                Text([s.string("carrier"), s.string("tracking_number")].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                            }
                            Spacer()
                            DateText(s.date("shipped_at"))
                            if let id = Int(s.id) { Button("Open") { env.navigator.push(.shipment(id: id)) } }
                        }
                    }
                    let active = rows.contains { !["delivered", "cancelled"].contains($0.status ?? "") }
                    if env.session.can("shipment.create") {
                        if ["confirmed", "shipped"].contains(order.status ?? ""), !active {
                            Button { onCreate() } label: { Label("Create shipment", systemImage: "shippingbox") }.font(Theme.Font.secondary)
                        } else if !active {
                            Text(shipmentHint).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                        }
                    }
                }
            }
        }
        .task {
            if resource == nil {
                let client = env.session.client, id = order.id
                resource = Resource { try await client.get("/v1/admin/shipments", query: ["order_id": id, "per_page": "20"]).json?.salesRecords ?? [] }
                resource?.load()
            }
        }
    }

    private var shipmentHint: String {
        switch order.status ?? "" {
        case "pending", "pending_payment": return "Payment must be received before a shipment can be created."
        case "processing": return "Partial payment or pending approval — once fully paid and confirmed, a shipment can be created."
        default: return "The order must be Confirmed before a shipment can be created."
        }
    }
}

/// POST /orders/{id}/shipments {carrier, tracking_number?, carrier_tracking_url?,
/// estimated_delivery_date?, notes?, description?}, then attachments.
struct CreateShipmentSheet: View {
    @Environment(AppEnvironment.self) private var env
    let order: Record
    let onDone: () -> Void
    let onCancel: () -> Void
    @State private var carrier = ""
    @State private var tracking = ""
    @State private var trackingURL = ""
    @State private var eta: Date?
    @State private var notes = ""
    @State private var description = ""
    @State private var files: [AttachmentDraft] = []

    var body: some View {
        SheetFrame(title: "Create shipment", submitLabel: "Create", canSubmit: !carrier.trimmingCharacters(in: .whitespaces).isEmpty, onCancel: onCancel, onSubmit: {
            var body: [String: JSONValue] = ["carrier": .string(carrier.trimmingCharacters(in: .whitespaces))]
            if !tracking.trimmingCharacters(in: .whitespaces).isEmpty { body["tracking_number"] = .string(tracking) }
            if !trackingURL.trimmingCharacters(in: .whitespaces).isEmpty { body["carrier_tracking_url"] = .string(trackingURL) }
            if let eta { body["estimated_delivery_date"] = .string(HubDates.dayString(eta)) }
            if !notes.trimmingCharacters(in: .whitespaces).isEmpty { body["notes"] = .string(notes) }
            if !description.trimmingCharacters(in: .whitespaces).isEmpty { body["description"] = .string(description) }
            let r = try await env.session.client.post("/v1/admin/orders/\(order.id)/shipments", json: .object(body))
            if let sid = r.json?["shipment"]?["id"]?.string, !files.isEmpty {
                try await ShipmentUploads.upload(files, to: "/v1/admin/shipments/\(sid)/upload-attachment", client: env.session.client)
            }
            env.toasts.show(r.json?["message"]?.string ?? "Shipment created")
            onDone()
        }) {
            TextInput(label: "Carrier (required)", text: $carrier)
            TextInput(label: "Tracking number", text: $tracking)
            TextInput(label: "Carrier tracking URL", text: $trackingURL)
            SalesOptionalDateField(label: "Estimated delivery", date: $eta)
            TextInput(label: "Customer-facing note for the first tracking event", text: $description, multiline: true)
            TextInput(label: "Internal notes", text: $notes, multiline: true)
            AttachmentDraftList(files: $files)
        }
    }
}

/// A file to upload with its customer-visibility flag.
struct AttachmentDraft: Identifiable {
    let file: SalesPickedFile
    var isPublic: Bool
    var id: UUID { file.id }
}

struct AttachmentDraftList: View {
    @Binding var files: [AttachmentDraft]
    var body: some View {
        SalesLabeledField(label: "Attachments (waybill, photos)") {
            ForEach($files) { $f in
                HStack {
                    Text(f.file.filename).font(Theme.Font.caption).lineLimit(1)
                    Spacer()
                    Toggle("Customer can see", isOn: $f.isPublic).toggleStyle(.switch).font(Theme.Font.caption).fixedSize()
                    Button { files.removeAll { $0.id == f.id } } label: { Image(systemName: "xmark.circle.fill") }.buttonStyle(.plain).foregroundStyle(Theme.textTertiary)
                }
            }
            SalesFilePickButton(label: "Add files", multiple: true) { picked in files.append(contentsOf: picked.map { AttachmentDraft(file: $0, isPublic: false) }) }
        }
    }
}

/// Multipart `attachments[]` + `is_public[]` (ShipmentController::upload*Attachment).
enum ShipmentUploads {
    static func upload(_ files: [AttachmentDraft], to path: String, client: APIClient) async throws {
        var parts: [APIClient.MultipartPart] = []
        for f in files { parts.append(f.file.part(named: "attachments[]")) }
        for f in files { parts.append(APIClient.MultipartPart(name: "is_public[]", value: f.isPublic ? "true" : "false")) }
        _ = try await client.send(.init(.post, path, body: .multipart(parts)))
    }
}
