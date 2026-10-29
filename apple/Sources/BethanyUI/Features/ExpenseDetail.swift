import SwiftUI
import BethanyKit

/// One expense (GET /expenses/{id}): the record, its line items, approvals
/// and evidence, with every workflow action the console's detail page
/// offers for a person who holds the gate (ExpenseDetailPage.tsx).
struct ExpenseDetailView: View {
    @Environment(AppEnvironment.self) private var env
    let id: Int
    @State private var resource: Resource<JSONValue>?
    @State private var editing = false
    @State private var markingPaid = false
    @State private var rejecting = false
    @State private var requestingChanges = false
    @State private var approving = false
    @State private var evidenceKind = "receipt"
    @State private var uploading = false

    var body: some View {
        Page("Expense") {
            if let resource {
                LoadableView(resource.state, retry: { resource.load() }) { json in
                    let e = ExpenseRow(json: json["expense"] ?? json)
                    header(e)
                    actions(e)
                    details(e)
                    if let items = e.json["lineItems"]?.array ?? e.json["line_items"]?.array, !items.isEmpty { lineItems(items, currency: e.currency) }
                    approvals(e)
                    evidence(e)
                }
            }
        }
        .task { if resource == nil { load() } }
        .refreshable { await resource?.reload() }
        .sheet(isPresented: $editing) { if let e = current { ExpenseEditSheet(expense: e) { editing = false; load() } } }
        .sheet(isPresented: $markingPaid) { if let e = current { MarkPaidSheet(expense: e) { markingPaid = false; load() } } }
        .sheet(isPresented: $approving) { if let e = current { ApproveSheet(expense: e) { approving = false; load() } } }
        .sheet(isPresented: $rejecting) { ReasonSheet(title: "Reject this expense", primary: "Reject", destructive: true) { r in await post("reject", ["reason": .string(r)]); rejecting = false } }
        .sheet(isPresented: $requestingChanges) { ReasonSheet(title: "Send back for changes", primary: "Request changes") { r in await post("request-changes", ["reason": .string(r)]); requestingChanges = false } }
    }

    private var current: ExpenseRow? { resource?.value.map { ExpenseRow(json: $0["expense"] ?? $0) } }

    private func load() {
        let c = env.session.client, id = id
        if resource == nil { resource = Resource { try await c.get("/v1/admin/expenses/\(id)").json ?? .null } }
        resource?.load()
    }

    // MARK: Header & actions

    private func header(_ e: ExpenseRow) -> some View {
        SectionCard {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 4) {
                    HStack { Text(e.reference).font(Theme.Font.mono); StatusBadge(e.status); if e.isImprest { StatusBadge("paid from imprest", tone: .accent) } }
                    Text(e.title).font(Theme.Font.display(20))
                    Text([e.category, e.vendor, e.outlet ?? "Head office"].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                    DateText(e.date, style: .date)
                }
                Spacer()
                VStack(alignment: .trailing, spacing: 2) {
                    MoneyText(e.amount, emphasis: true)
                    if e.currency != "KES" {
                        Text("= \(e.amountKes?.formatted() ?? "—") at \(fmtNumber(e.json["exchange_rate"]?.double, decimals: 4)) KES/\(e.currency) (recorded rate)").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    }
                }
            }
            if e.status == "changes_requested", let note = latestNote(e, action: "changes_requested") {
                Label("Sent back: \(note)", systemImage: "arrow.uturn.backward").font(Theme.Font.secondary).foregroundStyle(Theme.Palette.pink)
            }
            if e.status == "rejected", let r = e.json["rejection_reason"]?.string { Label("Rejected: \(r)", systemImage: "xmark.octagon").font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
        }
    }

    private func latestNote(_ e: ExpenseRow, action: String) -> String? {
        jrows(e.json["approvals"]).filter { $0.str("action") == action }.sorted { ($0.str("acted_at") ?? "") > ($1.str("acted_at") ?? "") }.first?.str("comments")
    }

    @ViewBuilder
    private func actions(_ e: ExpenseRow) -> some View {
        let s = env.session
        let me = s.me?.id
        let recordedByMe = me != nil && (idOf(e.json["created_by"]) == me || idOf(e.json["submitted_by"]) == me)
        let ownerOverride = (s.me?.isOwner ?? false) && recordedByMe
        let canApproveThis = s.can("expenses.approve") && (!recordedByMe || ownerOverride)
        HStack(spacing: Theme.Space.s) {
            DocumentButton("PDF", path: "/v1/admin/pdf/expenses/\(e.id)", suggestedName: "\(e.reference).pdf")
            if ["draft", "changes_requested"].contains(e.status) && s.can("expenses.create") {
                Button("Submit for approval") { Task { await post("submit") } }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
            }
            if e.status == "pending_approval" {
                if canApproveThis { Button(ownerOverride ? "Approve (owner override)" : "Approve") { approving = true }.buttonStyle(.borderedProminent).tint(Theme.Palette.success) }
                else if s.can("expenses.approve") { Text("You recorded this; someone else must approve it.").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning) }
                if s.can("expenses.approve") {
                    Button("Request changes") { requestingChanges = true }
                    Button("Reject", role: .destructive) { rejecting = true }
                }
            }
            if e.status == "approved" && s.can("expenses.approve") { Button("Mark as paid") { markingPaid = true }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
            if ["draft", "rejected", "changes_requested"].contains(e.status) && s.can("expenses.edit") { Button("Edit") { editing = true } }
            if ["draft", "pending_approval", "changes_requested", "approved"].contains(e.status) && s.can("expenses.edit") {
                ConfirmButton("Cancel expense", message: "The expense is cancelled; an imprest spend then waits on the super admin to return or write off the cash.", destructive: true, action: { await post("cancel") }) { Text("Cancel") }
            }
            if ["draft", "rejected", "cancelled"].contains(e.status) && s.can("expenses.delete") {
                ConfirmButton("Delete expense", message: "This cannot be undone.", destructive: true, action: { await delete() }) { Text("Delete") }
            }
            Spacer()
        }
        .font(Theme.Font.secondary)
        if e.isImprest && e.imprestResolution == "pending" && s.me?.isSuperAdmin == true {
            SectionCard("Imprest resolution") {
                Text("This expense was paid from the imprest and is \(e.status.humanized.lowercased()). Was the cash returned to the box, or is it written off?").font(Theme.Font.secondary)
                HStack {
                    Button("Cash returned") { Task { await resolve("returned") } }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                    Button("Write off") { Task { await resolve("written_off") } }
                }
            }
        }
    }

    private func idOf(_ v: JSONValue?) -> Int? { v?["id"]?.int ?? v?.int }

    // MARK: Details

    private func details(_ e: ExpenseRow) -> some View {
        SectionCard("Details") {
            DetailRow("Payment method", ExpenseVocab.methodLabel(e.json["payment_method"]?.string))
            DetailRow("Payment reference", e.json["payment_reference"]?.string)
            DetailRow("Vendor contact", e.json["vendor_contact"]?.string)
            DetailRow("Department", e.json["department"]?.string)
            DetailRow("Recurring", e.json["is_recurring"]?.bool == true ? (e.json["recurrence_frequency"]?.string?.humanized ?? "Yes") : "No")
            if let d = e.json["description"]?.string, !d.isEmpty { DetailRow("Description", d) }
            if let n = e.json["notes"]?.string, !n.isEmpty { DetailRow("Notes", n) }
            if let tags = e.json["tags"]?.array?.compactMap(\.string), !tags.isEmpty { DetailRow("Tags", tags.joined(separator: ", ")) }
            DetailRow("Recorded by", personName(e.json["createdBy"]) ?? personName(e.json["created_by"]) ?? e.json["created_by"]?.string)
            if let s = e.json["submitted_at"]?.date { DetailRow("Submitted") { DateText(s) } }
            if let a = e.json["approved_at"]?.date { DetailRow("Approved") { HStack { Text(personName(e.json["approvedBy"]) ?? "").font(Theme.Font.secondary); DateText(a) } } }
            if let p = e.json["paid_at"]?.date { DetailRow("Paid") { DateText(p) } }
            if let r = e.imprestResolution { DetailRow("Imprest resolution") { StatusBadge(r) } }
        }
    }

    private func lineItems(_ items: [JSONValue], currency: String) -> some View {
        SectionCard("Line items") {
            DataTable(columns: [
                TableColumn("Description", "description"), TableColumn("Qty", "quantity", .number(2)),
                TableColumn("Unit price", "unit_price", .money, fixedCurrency: currency), TableColumn("Tax", "tax_amount", .money, fixedCurrency: currency),
                TableColumn("Amount", "amount", .money, fixedCurrency: currency),
            ], rows: items.enumerated().map { JRow(id: $0.offset, json: $0.element) })
        }
    }

    @ViewBuilder
    private func approvals(_ e: ExpenseRow) -> some View {
        let rows = jrows(e.json["approvals"])
        if !rows.isEmpty {
            SectionCard("Approval history") {
                ForEach(rows) { a in
                    HStack(alignment: .top) {
                        StatusBadge(a.str("action"))
                        VStack(alignment: .leading, spacing: 2) {
                            HStack { Text(personName(a["approver"]) ?? "Approver").font(Theme.Font.body); if a["owner_override"]?.bool == true { StatusBadge("owner override", tone: .warning) }; if let st = a.int("step") { Text("step \(st)").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) } }
                            if let c = a.str("comments"), !c.isEmpty { Text(c).font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary) }
                            DateText(a["acted_at"]?.date)
                        }
                        Spacer()
                    }
                    .padding(.vertical, 4)
                }
            }
        }
    }

    // MARK: Evidence (append-only; a removed file stays listed as removed)

    private func evidence(_ e: ExpenseRow) -> some View {
        SectionCard("Evidence") {
            let rows = jrows(e.json["attachments"])
            if rows.isEmpty && e.json["receipt_path"]?.string == nil { Text("No receipt or photo yet.").font(Theme.Font.secondary).foregroundStyle(Theme.textTertiary) }
            ForEach(rows) { a in
                HStack {
                    Image(systemName: (a.str("mime_type") ?? "").hasPrefix("image") ? "photo" : "doc").foregroundStyle(Theme.textTertiary)
                    VStack(alignment: .leading, spacing: 2) {
                        HStack { Text(a.str("original_name") ?? "File").font(Theme.Font.body).lineLimit(1); StatusBadge(a.str("kind"), tone: .neutral); if a["removed_at"]?.isNull == false { StatusBadge("removed", tone: .danger) } }
                        Text("\(personName(a["uploader"]) ?? "") · \(a["size_bytes"]?.int.map { ByteCountFormatter.string(fromByteCount: Int64($0), countStyle: .file) } ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    }
                    Spacer()
                    if a["removed_at"]?.isNull != false, let aid = a.int("id") {
                        SignedFileButton(label: "Open", issuer: "/v1/admin/expenses/\(e.id)/attachments/\(aid)", suggestedName: a.str("original_name") ?? "attachment")
                        if a["can_remove"]?.bool == true {
                            ConfirmButton("Withdraw this file", message: "It stays on record as removed.", destructive: true, action: { await removeAttachment(aid) }) { Image(systemName: "trash") }
                        }
                    }
                }
                .padding(.vertical, 4)
            }
            if e.json["receipt_path"]?.string != nil && rows.isEmpty {
                SignedFileButton(label: "Open receipt", issuer: "/v1/admin/expenses/\(e.id)/receipt", suggestedName: "receipt")
            }
            if env.session.can("expenses.create") {
                HStack {
                    Picker("Kind", selection: $evidenceKind) { ForEach(ExpenseVocab.evidenceKinds, id: \.0) { Text($0.1).tag($0.0) } }.frame(maxWidth: 200)
                    AttachmentPicker(label: uploading ? "Uploading…" : "Add evidence", allowsMultiple: true) { files in Task { await upload(files) } }.disabled(uploading)
                }
                .font(Theme.Font.secondary)
            }
        }
    }

    // MARK: Calls

    private func post(_ action: String, _ body: [String: JSONValue] = [:]) async {
        do {
            let r = try await env.session.client.post("/v1/admin/expenses/\(id)/\(action)", json: .object(body))
            env.toasts.show(r.json?["message"]?.string ?? "Done")
            load()
        } catch { env.toasts.error(error) }
    }

    private func delete() async {
        do { _ = try await env.session.client.delete("/v1/admin/expenses/\(id)"); env.toasts.show("Expense deleted"); env.navigator.go(.expenses) } catch { env.toasts.error(error) }
    }

    private func resolve(_ resolution: String) async {
        do {
            let r = try await env.session.client.post("/v1/admin/expenses/\(id)/imprest-resolution", json: .object(["resolution": .string(resolution)]))
            env.toasts.show(r.json?["message"]?.string ?? "Resolved"); load()
        } catch { env.toasts.error(error) }
    }

    private func upload(_ files: [UploadFile]) async {
        uploading = true; defer { uploading = false }
        for f in files {
            do {
                let req = APIClient.Request(.post, "/v1/admin/expenses/\(id)/attachments", body: .multipart([APIClient.MultipartPart(name: "kind", value: evidenceKind), f.part(name: "file")]))
                let r = try await env.session.client.send(req)
                env.toasts.show(r.json?["message"]?.string ?? "Added")
            } catch { env.toasts.error(error) }
        }
        load()
    }

    private func removeAttachment(_ aid: Int) async {
        do { let r = try await env.session.client.delete("/v1/admin/expenses/\(id)/attachments/\(aid)"); env.toasts.show(r.json?["message"]?.string ?? "Removed"); load() } catch { env.toasts.error(error) }
    }
}

/// A private file behind a short-lived signed link: the issuer endpoint
/// answers `{url, expires_at, name}`; the link itself needs no bearer.
struct SignedFileButton: View {
    @Environment(AppEnvironment.self) private var env
    let label: String
    let issuer: String
    let suggestedName: String
    @State private var busy = false
    @State private var fileURL: URL?

    var body: some View {
        Button {
            busy = true
            Task {
                defer { busy = false }
                do {
                    let link = try await env.session.client.get(issuer).json
                    guard let s = link?["url"]?.string, let url = URL(string: s) else { throw APIError(status: 0, message: "No link was issued.") }
                    let (data, _) = try await URLSession.shared.data(from: url)
                    let dest = FileManager.default.temporaryDirectory.appendingPathComponent(link?["name"]?.string ?? suggestedName)
                    try data.write(to: dest)
                    fileURL = dest
                    #if os(macOS)
                    NSWorkspace.shared.open(dest)
                    #endif
                } catch { env.toasts.error(error) }
            }
        } label: { if busy { ProgressView().controlSize(.small) } else { Text(label) } }
        .font(Theme.Font.secondary)
        .disabled(busy)
        #if os(iOS)
        .sheet(item: $fileURL) { url in ShareSheet(items: [url]) }
        #endif
    }
}

// MARK: - Edit (PUT /expenses/{id}; drafts, rejected and sent-back only)

struct ExpenseEditSheet: View {
    @Environment(AppEnvironment.self) private var env
    let expense: ExpenseRow
    let done: () -> Void
    @State private var title: String
    @State private var description: String
    @State private var amount: String
    @State private var date: Date
    @State private var method: String
    @State private var reference: String
    @State private var vendor: String
    @State private var department: String
    @State private var notes: String
    @State private var categoryId: Int?
    @State private var categories: [(Int, String)] = []
    @State private var error: APIError?
    @State private var busy = false

    init(expense: ExpenseRow, done: @escaping () -> Void) {
        self.expense = expense; self.done = done
        let j = expense.json
        _title = State(initialValue: expense.title)
        _description = State(initialValue: j["description"]?.string ?? "")
        _amount = State(initialValue: expense.amount.map { "\($0.amount)" } ?? "")
        _date = State(initialValue: expense.date ?? Date())
        _method = State(initialValue: j["payment_method"]?.string ?? "cash")
        _reference = State(initialValue: j["payment_reference"]?.string ?? "")
        _vendor = State(initialValue: j["vendor_name"]?.string ?? "")
        _department = State(initialValue: j["department"]?.string ?? "")
        _notes = State(initialValue: j["notes"]?.string ?? "")
        _categoryId = State(initialValue: j["category_id"]?.int)
    }

    var body: some View {
        BHFormSheet("Edit \(expense.reference)", primary: "Save changes", busy: busy, disabled: title.isEmpty || amount.isEmpty, action: save) {
            FieldBox("Title", error: error?.errors["title"]?.first) { TextField("", text: $title).textFieldStyle(.roundedBorder) }
            HStack {
                FieldBox("Category") { Picker("", selection: $categoryId) { Text("Keep").tag(Int?.none); ForEach(categories, id: \.0) { Text($0.1).tag(Int?.some($0.0)) } }.labelsHidden() }
                FieldBox("Date", error: error?.errors["expense_date"]?.first) { DatePicker("", selection: $date, in: ...Date(), displayedComponents: .date).labelsHidden().environment(\.timeZone, HubDates.nairobi) }
            }
            HStack {
                FieldBox("Amount (\(expense.currency))", error: error?.errors["amount"]?.first) { DecimalField("", text: $amount) }
                FieldBox("Payment method") { Picker("", selection: $method) { ForEach(ExpenseVocab.paymentMethods, id: \.0) { Text($0.1).tag($0.0) } }.labelsHidden() }
            }
            FieldBox("Payment reference") { TextField("", text: $reference).textFieldStyle(.roundedBorder) }
            FieldBox("Vendor") { TextField("", text: $vendor).textFieldStyle(.roundedBorder) }
            FieldBox("Department") { TextField("", text: $department).textFieldStyle(.roundedBorder) }
            FieldBox("Description") { TextField("", text: $description, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
            FieldBox("Notes") { TextField("", text: $notes, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
            if let e = error, e.errors.isEmpty { Text(e.message).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
        }
        .task { categories = await loadExpenseCategories(env.session.client) }
    }

    private func save() async {
        busy = true; defer { busy = false }
        var body: [String: JSONValue] = ["title": .string(title), "expense_date": .string(HubDates.dayString(date)), "payment_method": .string(method), "currency_code": .string(expense.currency)]
        body.put("amount", jnum(amount)); body.put("category_id", (categoryId ?? expense.json["category_id"]?.int).map { .number(Double($0)) })
        body.put("payment_reference", jstr(reference)); body.put("vendor_name", jstr(vendor)); body.put("department", jstr(department))
        body.put("description", jstr(description)); body.put("notes", jstr(notes))
        do { _ = try await env.session.client.put("/v1/admin/expenses/\(expense.id)", json: .object(body)); env.toasts.show("Saved"); done() }
        catch let e as APIError { error = e } catch { env.toasts.error(error) }
    }
}

struct MarkPaidSheet: View {
    @Environment(AppEnvironment.self) private var env
    let expense: ExpenseRow
    let done: () -> Void
    @State private var reference = ""
    @State private var method: String = ""
    @State private var busy = false
    var body: some View {
        BHFormSheet("Mark \(expense.reference) as paid", primary: "Mark as paid", busy: busy, action: run) {
            Text("Records that the money went out. The amount stays \(expense.amount?.formatted() ?? "—").").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            FieldBox("Payment reference") { TextField("Optional", text: $reference).textFieldStyle(.roundedBorder) }
            FieldBox("Payment method") { Picker("", selection: $method) { Text("As recorded").tag(""); ForEach(ExpenseVocab.paymentMethods, id: \.0) { Text($0.1).tag($0.0) } }.labelsHidden() }
        }
    }
    private func run() async {
        busy = true; defer { busy = false }
        var body: [String: JSONValue] = [:]
        body.put("payment_reference", jstr(reference)); body.put("payment_method", jstr(method))
        do { let r = try await env.session.client.post("/v1/admin/expenses/\(expense.id)/mark-paid", json: .object(body)); env.toasts.show(r.json?["message"]?.string ?? "Marked as paid"); done() } catch { env.toasts.error(error) }
    }
}

struct ApproveSheet: View {
    @Environment(AppEnvironment.self) private var env
    let expense: ExpenseRow
    let done: () -> Void
    @State private var comments = ""
    @State private var busy = false
    var body: some View {
        let me = env.session.me
        let mine = me != nil && (expense.json["created_by"]?["id"]?.int ?? expense.json["created_by"]?.int) == me?.id
        let override = (me?.isOwner ?? false) && mine
        BHFormSheet("Approve \(expense.reference)", primary: override ? "Approve as owner" : "Approve", busy: busy, action: { await run(override: override) }) {
            Text("Your signature approves \(expense.amount?.formatted() ?? "this expense") at the current band. Above the band the engine waits for the next signature.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            if override { Text("You recorded this expense. The owner alone may approve their own record; the server will first confirm it is you, and the approval is marked as an owner override.").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning) }
            FieldBox("Comments") { TextField("Optional", text: $comments, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
        }
    }
    private func run(override: Bool) async {
        busy = true; defer { busy = false }
        var body: [String: JSONValue] = [:]
        body.put("comments", jstr(comments))
        if override { body["owner_override"] = .bool(true) }
        do { let r = try await env.session.client.post("/v1/admin/expenses/\(expense.id)/approve", json: .object(body)); env.toasts.show(r.json?["message"]?.string ?? "Approved"); done() } catch { env.toasts.error(error) }
    }
}
