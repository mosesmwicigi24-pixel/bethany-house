import SwiftUI
import BethanyKit
import UniformTypeIdentifiers

// Suppliers (SupplierController): list, create/edit (procurement.create),
// detail with stats, recent orders, performance, rating, contact history,
// documents and export (step-up on the server).

// MARK: - List (GET /v1/admin/suppliers → Laravel paginator)

struct SuppliersView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var list: RecordList?
    @State private var search = ""
    @State private var status = ""
    @State private var creating = false
    @State private var editing: Record?
    @State private var selected: Record?
    @State private var exporting = false

    var body: some View {
        Page("Suppliers", subtitle: "Who the business buys from") {
            if let list {
                HStack(spacing: Theme.Space.s) {
                    ModuleSearchField(text: $search, placeholder: "Name, email, phone or code") { Task { await reload() } }
                    Picker("Status", selection: $status) { Text("Any").tag(""); Text("Active").tag("active"); Text("Inactive").tag("inactive"); Text("Suspended").tag("suspended") }.frame(maxWidth: 140)
                    Button { Task { await export() } } label: { if exporting { ProgressView().controlSize(.small) } else { Label("Export", systemImage: "square.and.arrow.up") } }.disabled(exporting)
                    if env.session.can("procurement.create") { Button { creating = true } label: { Label("New", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
                }
                RecordListView(list, emptyTitle: "No suppliers match", emptySymbol: "building.2") { s in
                    ModuleRowCard(action: { selected = s }) {
                        VStack(alignment: .leading, spacing: 3) {
                            HStack { Text(s.string("name") ?? "").font(Theme.Font.body.weight(.semibold)); StatusBadge(s.status); if let t = s.string("type") { StatusBadge(t, tone: .neutral) } }
                            Text([s.string("company_code"), s.string("contact_person"), s.string("phone"), s.string("email")].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).lineLimit(1)
                            HStack {
                                if let c = s.string("city") ?? s.string("country") { Text(c).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                                Spacer()
                                if let r = s["rating"]?.double, r > 0 { Label(String(format: "%.1f", r), systemImage: "star.fill").font(Theme.Font.caption).foregroundStyle(Theme.Palette.amber) }
                            }
                        }
                    }
                }
            }
        }
        .task {
            if list == nil {
                list = RecordList(client: env.session.client, path: "/v1/admin/suppliers", perPage: 20)
                await list?.load()
            }
        }
        .onChange(of: status) { _, _ in Task { await reload() } }
        .refreshable { await list?.load() }
        .sheet(isPresented: $creating) { SupplierFormSheet(supplier: nil) { Task { await list?.load() } } }
        .sheet(item: $editing) { s in SupplierFormSheet(supplier: s) { Task { await list?.load() } } }
        .sheet(item: $selected) { s in SupplierDetailSheet(supplierId: s.id, onEdit: { selected = nil; editing = s }, onChanged: { Task { await list?.load() } }) }
    }

    private func reload() async {
        guard let list else { return }
        list.query = ["search": search.isEmpty ? nil : search, "status": status.isEmpty ? nil : status]
        await list.load()
    }

    /// GET …/suppliers/export answers JSON `{message, data}` (step-up gated); saved as a .json file to share.
    private func export() async {
        exporting = true
        defer { exporting = false }
        do {
            let r = try await env.session.client.get("/v1/admin/suppliers/export")
            let url = FileManager.default.temporaryDirectory.appendingPathComponent("suppliers-\(HubDates.dayString(Date())).json")
            try r.data.write(to: url)
            #if os(macOS)
            NSWorkspace.shared.activateFileViewerSelecting([url])
            #endif
            env.toasts.show("Export saved: \(url.lastPathComponent)")
        } catch { env.toasts.error(error) }
    }
}

// MARK: - Create / edit (POST, PUT …/suppliers[/{id}])

struct SupplierFormSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let supplier: Record?
    let onSaved: () -> Void
    @State private var f: [String: String]
    @State private var error: APIError?

    static let types = ["manufacturer", "wholesaler", "distributor", "other"]
    static let text: [(String, String)] = [
        ("company_code", "Company code"), ("contact_person", "Contact person"), ("email", "Email"), ("phone", "Phone"), ("alternate_phone", "Alternate phone"), ("website", "Website"),
        ("address_line_1", "Address line 1"), ("address_line_2", "Address line 2"), ("city", "City"), ("state", "State / county"), ("country", "Country"), ("postal_code", "Postal code"),
        ("tax_number", "Tax number"), ("registration_number", "Registration number"), ("payment_terms", "Payment terms"), ("credit_limit", "Credit limit"),
        ("bank_name", "Bank"), ("bank_account_name", "Account name"), ("bank_account_number", "Account number"), ("bank_swift_code", "SWIFT"),
    ]

    init(supplier: Record?, onSaved: @escaping () -> Void) {
        self.supplier = supplier
        self.onSaved = onSaved
        var initial: [String: String] = ["name": supplier?.string("name") ?? "", "type": supplier?.string("type") ?? "wholesaler", "currency": supplier?.string("currency") ?? "KES", "status": supplier?.status ?? "active", "notes": supplier?.string("notes") ?? ""]
        for (k, _) in Self.text { initial[k] = supplier?[k]?.string ?? "" }
        _f = State(initialValue: initial)
    }

    private func bind(_ k: String) -> Binding<String> { Binding(get: { f[k] ?? "" }, set: { f[k] = $0 }) }

    var body: some View {
        ModuleFormSheet(supplier == nil ? "New supplier" : "Edit \(supplier?.string("name") ?? "")", canSubmit: !(f["name"] ?? "").isEmpty, error: error, submit: submit) {
            ModuleField("Name", error: error?.errors["name"]?.first) { TextField("Name", text: bind("name")).textFieldStyle(.roundedBorder) }
            HStack {
                ModuleField("Type") { Picker("", selection: bind("type")) { ForEach(Self.types, id: \.self) { Text($0.humanized).tag($0) } } }
                ModuleField("Currency") { Picker("", selection: bind("currency")) { Text("KES").tag("KES"); Text("USD").tag("USD") } }
                ModuleField("Status") { Picker("", selection: bind("status")) { Text("Active").tag("active"); Text("Inactive").tag("inactive"); Text("Suspended").tag("suspended") } }
            }
            ForEach(Self.text, id: \.0) { key, label in
                ModuleField(label, error: error?.errors[key]?.first) { TextField(label, text: bind(key)).textFieldStyle(.roundedBorder) }
            }
            ModuleField("Notes") { TextField("Notes", text: bind("notes"), axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
        }
    }

    private func submit() async {
        var body: [String: JSONValue] = [:]
        for (k, v) in f {
            if k == "credit_limit" { if let d = JSONValue.decimal(v) { body[k] = d }; continue }
            body[k] = (k == "name" || k == "type" || k == "status" || k == "currency") ? .string(v) : .str(v)
        }
        if supplier == nil, (f["company_code"] ?? "").isEmpty { body.removeValue(forKey: "company_code") }
        do {
            if let s = supplier { _ = try await env.session.client.put("/v1/admin/suppliers/\(s.id)", json: .object(body)) }
            else { _ = try await env.session.client.post("/v1/admin/suppliers", json: .object(body)) }
            env.toasts.show("Supplier saved"); onSaved(); dismiss()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}

// MARK: - Detail (GET …/suppliers/{id} → {supplier, stats, recent_orders}; performance, contacts, documents, rating)

struct SupplierDetailSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let supplierId: String
    let onEdit: () -> Void
    let onChanged: () -> Void
    @State private var res: Resource<JSONValue>?
    @State private var perf: Resource<JSONValue>?
    @State private var contacts: Resource<[Record]>?
    @State private var documents: Resource<[Record]>?
    @State private var rating = 0.0
    @State private var review = ""
    @State private var addingContact = false

    var body: some View {
        VStack(spacing: 0) {
            HStack { Text("Supplier").font(Theme.Font.sectionTitle); Spacer(); if env.session.can("procurement.create") { Button("Edit") { onEdit() } }; Button("Close") { dismiss() } }.padding(Theme.Space.l)
            Divider()
            ScrollView {
                if let res {
                    LoadableView(res.state, retry: { res.load() }) { j in
                        let s = j["supplier"] ?? .null
                        let st = j["stats"] ?? .null
                        VStack(alignment: .leading, spacing: Theme.Space.m) {
                            HStack { Text(s["name"]?.string ?? "").font(Theme.Font.display(20)); StatusBadge(s["status"]?.string); Spacer() }
                            Text([s["company_code"]?.string, s["type"]?.string?.humanized, s["contact_person"]?.string, s["phone"]?.string, s["email"]?.string].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                            HStack {
                                StatTile("Orders", value: st["total_orders"]?.string ?? "0")
                                StatTile("Open", value: st["pending_orders"]?.string ?? "0")
                                StatTile("Bought (KES)", value: st["total_value"]?.money?.formatted() ?? "—", hint: (st["unconverted_orders"]?.int ?? 0) > 0 ? "\(st["unconverted_orders"]?.int ?? 0) order(s) without a reporting rate left out" : "at the reporting rate")
                            }
                            DetailRow("Payment terms", s["payment_terms"]?.string)
                            DetailRow("Currency", s["currency"]?.string)
                            DetailRow("Address", [s["address_line_1"]?.string, s["city"]?.string, s["country"]?.string].compactMap { $0 }.joined(separator: ", "))
                            DetailRow("Last order", st["last_order_date"]?.string)
                            if env.session.can("procurement.create") { ratingEditor(s) }
                            if let perf { performance(perf) }
                            recentOrders(j["recent_orders"]?.array ?? [])
                            contactsSection
                            documentsSection
                        }
                        .padding(Theme.Space.l)
                    }
                }
            }
        }
        .frame(minWidth: 480, idealWidth: 640, minHeight: 480)
        .task { if res == nil { load() } }
        .sheet(isPresented: $addingContact) { AddContactSheet(supplierId: supplierId) { contacts?.load() } }
    }

    private func load() {
        let c = env.session.client, id = supplierId
        res = Resource { try await c.get("/v1/admin/suppliers/\(id)").json ?? .null }
        perf = Resource { try await c.get("/v1/admin/suppliers/\(id)/performance").json ?? .null }
        contacts = Resource { let j = try await c.get("/v1/admin/suppliers/\(id)/contacts").json; return (j?["data"] ?? j)?.decode([Record].self) ?? [] }
        documents = Resource { let j = try await c.get("/v1/admin/suppliers/\(id)/documents").json; return (j?["data"] ?? j)?.decode([Record].self) ?? [] }
        res?.load(); perf?.load(); contacts?.load(); documents?.load()
    }

    private func ratingEditor(_ s: JSONValue) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Text("Rating").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).textCase(.uppercase)
            HStack {
                ForEach(1...5, id: \.self) { i in
                    Button { rating = Double(i) } label: { Image(systemName: Double(i) <= (rating > 0 ? rating : (s["rating"]?.double ?? 0)) ? "star.fill" : "star") }.buttonStyle(.plain).foregroundStyle(Theme.Palette.amber)
                }
                TextField("Review (optional)", text: $review).textFieldStyle(.roundedBorder)
                Button("Save") { Task { await saveRating() } }.disabled(rating == 0)
            }
        }
    }

    private func saveRating() async {
        do {
            _ = try await env.session.client.put("/v1/admin/suppliers/\(supplierId)/rating", json: .object(["rating": .number(rating), "review": .str(review)]))
            env.toasts.show("Rating saved"); rating = 0; review = ""; load(); onChanged()
        } catch { env.toasts.error(error) }
    }

    private func performance(_ perf: Resource<JSONValue>) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Text("Performance").font(Theme.Font.sectionTitle)
            LoadableView(perf.state, retry: { perf.load() }) { p in
                HStack {
                    StatTile("On time", value: p["on_time_deliveries"]?.string ?? "0", tone: .success)
                    StatTile("Late", value: p["late_deliveries"]?.string ?? "0", tone: (p["late_deliveries"]?.int ?? 0) > 0 ? .danger : nil)
                    StatTile("Avg variance (days)", value: p["avg_delivery_variance"]?.string ?? "—")
                }
                HStack {
                    StatTile("Received", value: p["total_received"]?.string ?? "0")
                    StatTile("Rejected", value: p["total_rejected"]?.string ?? "0")
                    StatTile("Quality rate", value: p["quality_rate"].map { "\($0.string ?? "—")%" } ?? "—")
                }
            }
        }
    }

    private func recentOrders(_ orders: [JSONValue]) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Text("Recent orders").font(Theme.Font.sectionTitle)
            if orders.isEmpty { Text("No purchase orders yet.").font(Theme.Font.secondary).foregroundStyle(Theme.textTertiary) }
            ForEach(Array(orders.enumerated()), id: \.offset) { _, o in
                let po = PurchaseOrderRecord(Record(json: o))
                HStack { Button(po.number) { dismiss(); env.navigator.push(.purchaseOrder(id: po.id)) }; StatusBadge(po.status); Spacer(); MoneyText(po.total) }
            }
        }
    }

    @ViewBuilder
    private var contactsSection: some View {
        HStack { Text("Contact history").font(Theme.Font.sectionTitle); Spacer(); if env.session.can("procurement.create") { Button("Add") { addingContact = true } } }
        if let contacts {
            LoadableView(contacts.state, retry: { contacts.load() }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No contact recorded", symbol: "phone") }) { rows in
                ForEach(rows) { c in
                    VStack(alignment: .leading, spacing: 2) {
                        HStack { Text(c.string("subject") ?? "").font(Theme.Font.body.weight(.semibold)); StatusBadge(c.string("contact_type"), tone: .neutral); Spacer(); Text(c.string("contact_date") ?? "").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                        Text(c.string("notes") ?? "").font(Theme.Font.secondary)
                    }
                    .padding(.vertical, 3)
                }
            }
        }
    }

    @ViewBuilder
    private var documentsSection: some View {
        HStack {
            Text("Documents").font(Theme.Font.sectionTitle); Spacer()
            if env.session.can("procurement.create") { SupplierDocumentUpload(supplierId: supplierId) { documents?.load() } }
        }
        if let documents {
            LoadableView(documents.state, retry: { documents.load() }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No documents", symbol: "doc") }) { rows in
                ForEach(rows) { d in
                    HStack {
                        Image(systemName: "doc").foregroundStyle(Theme.textTertiary)
                        VStack(alignment: .leading, spacing: 2) {
                            Text(d.string("file_name") ?? d.string("name") ?? "Document").font(Theme.Font.body)
                            Text([d.string("document_type")?.humanized, d.string("description")].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        }
                        Spacer()
                        DateText(d.date("created_at"), style: .date)
                    }
                }
            }
        }
    }
}

/// POST …/suppliers/{id}/contacts {contact_type, subject, notes, contact_date}
struct AddContactSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let supplierId: String
    let onSaved: () -> Void
    @State private var type = "phone"
    @State private var subject = ""
    @State private var notes = ""
    @State private var date = Date()
    @State private var error: APIError?

    var body: some View {
        ModuleFormSheet("Record a contact", canSubmit: !subject.isEmpty && !notes.isEmpty, error: error, submit: submit) {
            ModuleField("Type") { Picker("", selection: $type) { ForEach(["email", "phone", "meeting", "other"], id: \.self) { Text($0.humanized).tag($0) } } }
            ModuleField("Subject", error: error?.errors["subject"]?.first) { TextField("Subject", text: $subject).textFieldStyle(.roundedBorder) }
            ModuleField("Notes", error: error?.errors["notes"]?.first) { TextField("What was discussed", text: $notes, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(3...6) }
            ModuleField("Date") { DatePicker("", selection: $date, displayedComponents: .date).labelsHidden() }
        }
    }

    private func submit() async {
        do {
            _ = try await env.session.client.post("/v1/admin/suppliers/\(supplierId)/contacts", json: .object(["contact_type": .string(type), "subject": .string(subject), "notes": .string(notes), "contact_date": .string(apiDay(date))]))
            env.toasts.show("Contact recorded"); onSaved(); dismiss()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}

/// POST …/suppliers/{id}/documents multipart {document, document_type, description?}
struct SupplierDocumentUpload: View {
    @Environment(AppEnvironment.self) private var env
    let supplierId: String
    let onUploaded: () -> Void
    @State private var picking = false
    @State private var docType = "other"

    var body: some View {
        HStack {
            Picker("", selection: $docType) { ForEach(["contract", "certificate", "tax_document", "other"], id: \.self) { Text($0.humanized).tag($0) } }.frame(maxWidth: 140)
            Button { picking = true } label: { Label("Upload", systemImage: "arrow.up.doc") }
        }
        .fileImporter(isPresented: $picking, allowedContentTypes: [.pdf, .image, .data], allowsMultipleSelection: false) { result in
            guard case .success(let urls) = result, let url = urls.first else { return }
            Task { await upload(url) }
        }
    }

    private func upload(_ url: URL) async {
        let access = url.startAccessingSecurityScopedResource()
        defer { if access { url.stopAccessingSecurityScopedResource() } }
        guard let data = try? Data(contentsOf: url) else { env.toasts.show("Could not read the file", tone: .danger); return }
        let mime = url.pathExtension.lowercased() == "pdf" ? "application/pdf" : (["jpg", "jpeg"].contains(url.pathExtension.lowercased()) ? "image/jpeg" : (url.pathExtension.lowercased() == "png" ? "image/png" : "application/octet-stream"))
        do {
            _ = try await env.session.client.uploadFile("/v1/admin/suppliers/\(supplierId)/documents", file: data, field: "document", filename: url.lastPathComponent, mimeType: mime, fields: ["document_type": docType])
            env.toasts.show("Document uploaded"); onUploaded()
        } catch { env.toasts.error(error) }
    }
}
