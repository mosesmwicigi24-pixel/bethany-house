import SwiftUI
import BethanyKit

/// Expenses: list with filters and bulk actions, new-expense form, detail,
/// imprest, categories & budgets, analytics. Endpoints verified against
/// backend ExpenseController / ImprestController and react-admin/src/api/{expenses,imprest}.ts.
@MainActor
enum ExpensesFeature {
    static func register() {
        FeatureRegistry.register(.expenses) { _ in AnyView(ExpensesListView()) }
        FeatureRegistry.register({ if case .expense = $0 { return true }; return false }) { route in
            if case .expense(let id) = route { return AnyView(ExpenseDetailView(id: id)) }
            return AnyView(EmptyView())
        }
        FeatureRegistry.register(.imprest) { _ in AnyView(ImprestView()) }
        FeatureRegistry.register(.expenseCategories) { _ in AnyView(ExpenseCategoriesView()) }
        FeatureRegistry.register(.expenseAnalytics) { _ in AnyView(ExpenseAnalyticsView()) }
    }
}

// MARK: - Shared vocabulary (expenses.ts)

enum ExpenseVocab {
    static let statuses = ["draft", "pending_approval", "changes_requested", "approved", "rejected", "paid", "cancelled"]
    static let paymentMethods: [(String, String)] = [("cash", "Cash"), ("bank_transfer", "Bank Transfer"), ("mpesa", "M-PESA"), ("card", "Card"), ("cheque", "Cheque"), ("other", "Other")]
    static let currencies = ["KES", "USD", "EUR", "GBP"]
    static let evidenceKinds: [(String, String)] = [("receipt", "Receipt"), ("quotation", "Quotation"), ("invoice", "Bill / invoice"), ("meter", "Meter reading"), ("other", "Other")]
    static func methodLabel(_ v: String?) -> String { paymentMethods.first { $0.0 == v }?.1 ?? (v ?? "—").humanized }
}

/// One expense row as `GET /expenses` lists it (the same fields the detail expands).
struct ExpenseRow: Identifiable, Hashable {
    let json: JSONValue
    var id: Int { json["id"]?.int ?? 0 }
    var reference: String { json["reference_number"]?.string ?? "—" }
    var title: String { json["title"]?.string ?? "Expense" }
    var status: String { json["status"]?.string ?? "draft" }
    var currency: String { json["currency_code"]?.string ?? "KES" }
    var amount: Money? { json["amount"].flatMap { Money(json: $0, currency: currency) } }
    var amountKes: Money? { json["amount_kes"]?.kes }
    var category: String? { json["category"]?["name"]?.string }
    var vendor: String? { json["vendor_name"]?.string }
    var outlet: String? { json["outlet"]?["name"]?.string }
    var date: Date? { json["expense_date"]?.date }
    var isImprest: Bool { json["imprest_account_id"]?.int != nil }
    var imprestResolution: String? { json["imprest_resolution"]?.string }
}

/// `GET /expenses` → `{expenses: paginator, stats}`; the paginator is not at
/// the root, so the list is loaded here rather than through PagedList.
@MainActor
@Observable
final class ExpenseListModel {
    var rows: [ExpenseRow] = []
    var stats: JSONValue = .null
    var page = 1
    var lastPage = 1
    var total = 0
    var state: Loadable<[ExpenseRow]> = .idle
    var query: [String: String?] = [:]
    private let client: APIClient
    private var token = UUID()

    init(client: APIClient) { self.client = client }

    func load(page: Int = 1) async {
        let my = UUID(); token = my
        self.page = page
        state = .loading(previous: rows.isEmpty ? nil : rows)
        var q = query
        q["page"] = String(page); q["per_page"] = "25"
        do {
            let r = try await client.get("/v1/admin/expenses", query: q)
            guard token == my else { return }
            let pag = r.json?["expenses"]
            rows = (pag?["data"]?.array ?? []).map(ExpenseRow.init(json:))
            lastPage = pag?["last_page"]?.int ?? 1
            total = pag?["total"]?.int ?? rows.count
            stats = r.json?["stats"] ?? .null
            state = .loaded(rows)
        } catch let e as APIError { if token == my { state = .failed(e, previous: rows.isEmpty ? nil : rows) } }
        catch { if token == my { state = .failed(.transport(error.localizedDescription), previous: nil) } }
    }
}

// MARK: - List

struct ExpensesListView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var model: ExpenseListModel?
    @State private var status = ""
    @State private var categoryId: Int? = nil
    @State private var outletId: Int? = nil
    @State private var imprest = ""
    @State private var search = ""
    @State private var dated = false
    @State private var start = Date()
    @State private var end = Date()
    @State private var sort = "expense_date"
    @State private var categories: [(Int, String)] = []
    @State private var outlets: [(Int, String)] = []
    @State private var selected: Set<Int> = []
    @State private var creating = false
    @State private var bulk: BulkRequest?
    @State private var bulkResult: JSONItem?
    @State private var reasonFor: ReasonAction?

    struct BulkRequest: Identifiable { let action: String; let label: String; let needsReason: Bool; var id: String { action } }
    struct ReasonAction: Identifiable { let row: ExpenseRow; let action: String; var id: String { "\(row.id)-\(action)" } }

    var body: some View {
        Page("Expenses", subtitle: model.map { "\($0.total) record\($0.total == 1 ? "" : "s")" }) {
            if let model {
                statsTiles(model.stats)
                filters
                if env.session.can("expenses.approve") && !selected.isEmpty { bulkBar }
                LoadableView(model.state, retry: { Task { await model.load() } }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No expenses match", message: "Change the filters or record a new expense.", symbol: "receipt", actionLabel: env.session.can("expenses.create") ? "New expense" : nil, action: { creating = true }) }) { rows in
                    LazyVStack(spacing: Theme.Space.s) {
                        ForEach(rows) { row in expenseRow(row) }
                    }
                    pager(model)
                }
            }
        }
        .toolbar {
            ToolbarItemGroup(placement: .primaryAction) {
                if env.session.can("expenses.create") {
                    Button { creating = true } label: { Label("New expense", systemImage: "plus") }
                }
            }
        }
        .task {
            if model == nil {
                let m = ExpenseListModel(client: env.session.client)
                model = m
                await m.load()
                categories = await loadExpenseCategories(env.session.client)
                outlets = await outletOptions(env.session.client, reports: false)
            }
        }
        .onChange(of: filterKey) { _, _ in applyFilters() }
        .refreshable { await model?.load(page: model?.page ?? 1) }
        .sheet(isPresented: $creating) { NewExpenseSheet(categories: categories, outlets: outlets) { id in creating = false; Task { await model?.load() }; env.navigator.push(.expense(id: id)) } }
        .sheet(item: $bulk) { b in BulkConfirmSheet(request: b, ids: Array(selected)) { response in bulk = nil; bulkResult = JSONItem(json: response); selected = []; Task { await model?.load(page: model?.page ?? 1) } } }
        .sheet(item: $bulkResult) { r in BulkResultSheet(item: r) }
        .sheet(item: $reasonFor) { r in ReasonSheet(title: r.action == "reject" ? "Reject \(r.row.reference)" : "Send \(r.row.reference) back for changes", primary: r.action == "reject" ? "Reject" : "Request changes", destructive: r.action == "reject") { reason in
            await single(r.row, r.action == "reject" ? "reject" : "request-changes", body: ["reason": .string(reason)]); reasonFor = nil
        } }
    }

    private var filterKey: String { "\(status)|\(categoryId ?? 0)|\(outletId ?? 0)|\(imprest)|\(search)|\(dated)|\(HubDates.dayString(start))|\(HubDates.dayString(end))|\(sort)" }

    private func applyFilters() {
        guard let model else { return }
        model.query = [
            "status": status.isEmpty ? nil : status,
            "category_id": categoryId.map(String.init),
            "outlet_id": outletId.map(String.init),
            "imprest": imprest.isEmpty ? nil : imprest,
            "search": search.isEmpty ? nil : search,
            "start_date": dated ? HubDates.dayString(start) : nil,
            "end_date": dated ? HubDates.dayString(end) : nil,
            "sort": sort, "direction": "desc",
        ]
        selected = []
        Task { await model.load() }
    }

    @ViewBuilder
    private func statsTiles(_ stats: JSONValue) -> some View {
        TileGrid(minWidth: 180) {
            StatTile("Approved & paid (period)", value: fmtMoney(stats["approved_total"], currency: "KES"), hint: "KES at the recorded exchange rate (amount_kes)")
            StatTile("Pending approval", value: fmtMoney(stats["pending_total"], currency: "KES"), hint: "\(stats["pending_count"]?.int ?? 0) waiting", tone: (stats["pending_count"]?.int ?? 0) > 0 ? .amber : nil)
            StatTile("Records", value: stats["total_count"]?.string ?? "0")
        }
    }

    private var filters: some View {
        VStack(alignment: .leading, spacing: Theme.Space.s) {
            HStack(spacing: Theme.Space.s) {
                TextField("Search title, reference, vendor", text: $search).textFieldStyle(.roundedBorder).frame(maxWidth: 280)
                Picker("Status", selection: $status) { Text("All statuses").tag(""); ForEach(ExpenseVocab.statuses, id: \.self) { Text($0.humanized).tag($0) } }.labelsHidden()
                Picker("Category", selection: $categoryId) { Text("All categories").tag(Int?.none); ForEach(categories, id: \.0) { Text($0.1).tag(Int?.some($0.0)) } }.labelsHidden()
            }
            HStack(spacing: Theme.Space.s) {
                if outlets.count > 1 {
                    Picker("Outlet", selection: $outletId) { Text("All outlets").tag(Int?.none); ForEach(outlets, id: \.0) { Text($0.1).tag(Int?.some($0.0)) } }.labelsHidden()
                }
                Picker("Imprest", selection: $imprest) { Text("Any funding").tag(""); Text("From imprest").tag("yes"); Text("Not imprest").tag("no"); Text("Unresolved imprest").tag("unresolved") }.labelsHidden()
                Picker("Sort", selection: $sort) { Text("By date").tag("expense_date"); Text("By amount").tag("amount_kes"); Text("By created").tag("created_at"); Text("By title").tag("title") }.labelsHidden()
                Toggle("Dates", isOn: $dated).toggleStyle(.switch)
                if dated {
                    DatePicker("From", selection: $start, displayedComponents: .date).labelsHidden()
                    DatePicker("To", selection: $end, displayedComponents: .date).labelsHidden()
                }
            }
            .environment(\.timeZone, HubDates.nairobi)
        }
        .font(Theme.Font.secondary)
    }

    private var bulkBar: some View {
        HStack(spacing: Theme.Space.s) {
            Text("\(selected.count) selected").font(Theme.Font.secondary.weight(.semibold))
            Button("Approve") { bulk = BulkRequest(action: "approve", label: "Approve", needsReason: false) }
            Button("Request changes") { bulk = BulkRequest(action: "request_changes", label: "Send back for changes", needsReason: true) }
            Button("Reject") { bulk = BulkRequest(action: "reject", label: "Reject", needsReason: true) }
            Button("Mark as paid") { bulk = BulkRequest(action: "mark_paid", label: "Mark as paid", needsReason: false) }
            Spacer()
            Button("Clear") { selected = [] }
        }
        .font(Theme.Font.secondary)
        .padding(Theme.Space.m)
        .background(Theme.Palette.infoSoft, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
    }

    private func expenseRow(_ row: ExpenseRow) -> some View {
        HStack(alignment: .top, spacing: Theme.Space.m) {
            if env.session.can("expenses.approve") {
                SelectBox(on: Binding(get: { selected.contains(row.id) }, set: { on in if on { selected.insert(row.id) } else { selected.remove(row.id) } }))
            }
            Button { env.navigator.push(.expense(id: row.id)) } label: {
                HStack(alignment: .top) {
                    VStack(alignment: .leading, spacing: 3) {
                        HStack { Text(row.reference).font(Theme.Font.mono); StatusBadge(row.status); if row.isImprest { StatusBadge("imprest", tone: .accent) }; if row.imprestResolution == "pending" { StatusBadge("awaiting resolution", tone: .warning) } }
                        Text(row.title).font(Theme.Font.body)
                        Text([row.category, row.vendor, row.outlet].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        DateText(row.date, style: .date)
                    }
                    Spacer()
                    VStack(alignment: .trailing, spacing: 2) {
                        MoneyText(row.amount, emphasis: true)
                        if row.currency != "KES", let k = row.amountKes { Text("= \(k.formatted())").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                    }
                }
                .padding(Theme.Space.m)
                .background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            .contextMenu { rowMenu(row) }
        }
    }

    @ViewBuilder
    private func rowMenu(_ row: ExpenseRow) -> some View {
        Button("Open") { env.navigator.push(.expense(id: row.id)) }
        if row.status == "draft" && env.session.can("expenses.create") { Button("Submit for approval") { Task { await single(row, "submit") } } }
        if row.status == "pending_approval" && env.session.can("expenses.approve") {
            Button("Approve") { Task { await single(row, "approve") } }
            Button("Request changes") { reasonFor = ReasonAction(row: row, action: "request_changes") }
            Button("Reject") { reasonFor = ReasonAction(row: row, action: "reject") }
        }
        if row.status == "approved" && env.session.can("expenses.approve") { Button("Mark as paid") { Task { await single(row, "mark-paid") } } }
        if ["draft", "rejected", "cancelled"].contains(row.status) && env.session.can("expenses.delete") {
            Button("Delete", role: .destructive) { Task { await deleteRow(row) } }
        }
    }

    private func pager(_ model: ExpenseListModel) -> some View {
        HStack {
            Button("Previous") { Task { await model.load(page: model.page - 1) } }.disabled(model.page <= 1)
            Text("Page \(model.page) of \(model.lastPage)").font(Theme.Font.caption).monospacedDigit()
            Button("Next") { Task { await model.load(page: model.page + 1) } }.disabled(model.page >= model.lastPage)
        }
        .font(Theme.Font.secondary)
    }

    private func single(_ row: ExpenseRow, _ action: String, body: [String: JSONValue] = [:]) async {
        do {
            _ = try await env.session.client.post("/v1/admin/expenses/\(row.id)/\(action)", json: .object(body))
            env.toasts.show("\(row.reference): \(action.humanized.lowercased()) done")
            await model?.load(page: model?.page ?? 1)
        } catch { env.toasts.error(error) }
    }

    private func deleteRow(_ row: ExpenseRow) async {
        do { _ = try await env.session.client.delete("/v1/admin/expenses/\(row.id)"); env.toasts.show("Deleted"); await model?.load(page: model?.page ?? 1) } catch { env.toasts.error(error) }
    }
}

@MainActor
func loadExpenseCategories(_ client: APIClient) async -> [(Int, String)] {
    guard let r = try? await client.get("/v1/admin/expenses/categories", background: true) else { return [] }
    var out: [(Int, String)] = []
    func walk(_ list: [JSONValue], depth: Int) {
        for c in list {
            if let id = c["id"]?.int { out.append((id, String(repeating: "  ", count: depth) + (c["name"]?.string ?? "Category \(id)"))) }
            walk(c["children"]?.array ?? [], depth: depth + 1)
        }
    }
    walk(r.json?["categories"]?.array ?? [], depth: 0)
    return out
}

/// A reason for reject / request changes.
struct ReasonSheet: View {
    let title: String
    let primary: String
    var destructive = false
    let action: (String) async -> Void
    @State private var reason = ""
    @State private var busy = false
    var body: some View {
        BHFormSheet(title, primary: primary, busy: busy, disabled: reason.trimmingCharacters(in: .whitespaces).isEmpty, destructive: destructive, action: { busy = true; await action(reason.trimmingCharacters(in: .whitespaces)); busy = false }) {
            TextField("Reason (the maker reads this)", text: $reason, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(3...6)
        }
    }
}

// MARK: - Bulk (POST /expenses/bulk, up to 100 ids; each item reported on its own)

struct BulkConfirmSheet: View {
    @Environment(AppEnvironment.self) private var env
    let request: ExpensesListView.BulkRequest
    let ids: [Int]
    let done: (JSONValue) -> Void
    @State private var reason = ""
    @State private var busy = false
    var body: some View {
        BHFormSheet("\(request.label) \(ids.count) expense\(ids.count == 1 ? "" : "s")", primary: request.label, busy: busy, disabled: request.needsReason && reason.trimmingCharacters(in: .whitespaces).isEmpty, destructive: request.action == "reject", action: run) {
            Text("Each expense runs its own rule on the server and is reported on its own — never all-or-nothing. Items not in the right state are left alone. At most 100 per run.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            if request.needsReason { TextField("Reason (required)", text: $reason, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(3...6) }
        }
    }
    private func run() async {
        busy = true; defer { busy = false }
        var body: [String: JSONValue] = ["ids": .array(ids.prefix(100).map { .number(Double($0)) }), "action": .string(request.action)]
        body.put("reason", jstr(reason))
        do {
            let r = try await env.session.client.post("/v1/admin/expenses/bulk", json: .object(body))
            done(r.json ?? .null)
        } catch { env.toasts.error(error) }
    }
}

/// A JSON payload that a `.sheet(item:)` can present.
struct JSONItem: Identifiable {
    let id = UUID()
    let json: JSONValue
}

/// A selection checkbox that looks native on both platforms.
struct SelectBox: View {
    @Binding var on: Bool
    var body: some View {
        Button { on.toggle() } label: {
            Image(systemName: on ? "checkmark.square.fill" : "square").font(.system(size: 18)).foregroundStyle(on ? Theme.Palette.brand : Theme.textTertiary)
        }
        .buttonStyle(.plain).frame(width: 28, height: Theme.tapTarget)
        .accessibilityLabel(on ? "Selected" : "Not selected")
    }
}

struct BulkResultSheet: View {
    @Environment(\.dismiss) private var dismiss
    let item: JSONItem
    var body: some View {
        let response = item.json
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            let s = response["summary"]
            Text("\((response["action"]?.string ?? "").humanized): \(s?["succeeded"]?.int ?? 0) done, \(s?["failed"]?.int ?? 0) not").font(Theme.Font.sectionTitle)
            ScrollView {
                VStack(alignment: .leading, spacing: 6) {
                    ForEach(jrows(response["results"])) { r in
                        HStack(alignment: .top) {
                            Image(systemName: r["ok"]?.bool == true ? "checkmark.circle.fill" : "xmark.circle.fill").foregroundStyle(r["ok"]?.bool == true ? Theme.Palette.success : Theme.Palette.danger)
                            VStack(alignment: .leading, spacing: 2) {
                                Text(r.str("reference") ?? "Expense #\(r.str("id") ?? "")").font(Theme.Font.mono)
                                Text(r.str("message") ?? r.str("code") ?? "").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                            }
                            Spacer()
                            if let st = r.str("status_after") { StatusBadge(st) }
                        }
                    }
                }
            }
            HStack { Spacer(); Button("Done") { dismiss() }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
        }
        .padding(Theme.Space.xl).frame(minWidth: 420, idealWidth: 520, minHeight: 320)
    }
}

// MARK: - New expense (POST /expenses, then attachments)

struct NewExpenseSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let categories: [(Int, String)]
    let outlets: [(Int, String)]
    let created: (Int) -> Void

    @State private var title = ""
    @State private var description = ""
    @State private var categoryId: Int? = nil
    @State private var date = Date()
    @State private var amount = ""
    @State private var currency = "KES"
    @State private var exchangeRate = ""
    @State private var method = "cash"
    @State private var reference = ""
    @State private var vendor = ""
    @State private var vendorContact = ""
    @State private var outletId: Int? = nil
    @State private var department = ""
    @State private var recurring = false
    @State private var frequency = "monthly"
    @State private var notes = ""
    @State private var useImprest: Bool? = nil
    @State private var imprest: JSONValue? = nil
    @State private var lines: [LineItem] = []
    @State private var files: [UploadFile] = []
    @State private var suggestions: JSONValue? = nil
    @State private var error: APIError?
    @State private var busy = false

    struct LineItem: Identifiable { let id = UUID(); var description = ""; var categoryId: Int? = nil; var quantity = "1"; var unitPrice = ""; var tax = "" }

    var body: some View {
        BHFormSheet("New expense", primary: "Save expense", busy: busy, disabled: title.isEmpty || categoryId == nil || amount.isEmpty || (imprest != nil && useImprest == nil), action: submit) {
            if let s = suggestions, let usuals = s["usuals"]?.array, !usuals.isEmpty { usualsRow(usuals) }
            FieldBox("Title *", error: error?.errors["title"]?.first) { TextField("e.g. Office rent – October", text: $title).textFieldStyle(.roundedBorder) }
            HStack(alignment: .top) {
                FieldBox("Category *", error: error?.errors["category_id"]?.first) {
                    Picker("", selection: $categoryId) { Text("Choose…").tag(Int?.none); ForEach(categories, id: \.0) { Text($0.1).tag(Int?.some($0.0)) } }.labelsHidden()
                }
                FieldBox("Date *", error: error?.errors["expense_date"]?.first) { DatePicker("", selection: $date, in: ...Date(), displayedComponents: .date).labelsHidden().environment(\.timeZone, HubDates.nairobi) }
            }
            if let hint = categoryHint { Text(hint).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
            HStack(alignment: .top) {
                FieldBox("Amount *", error: error?.errors["amount"]?.first) { DecimalField("0.00", text: $amount) }
                FieldBox("Currency") { Picker("", selection: $currency) { ForEach(ExpenseVocab.currencies, id: \.self) { Text($0).tag($0) } }.labelsHidden() }
                if currency != "KES" {
                    FieldBox("KES per \(currency) (blank = server rate)", error: error?.errors["exchange_rate"]?.first) { DecimalField("", text: $exchangeRate) }
                }
            }
            HStack(alignment: .top) {
                FieldBox("Payment method *") { Picker("", selection: $method) { ForEach(ExpenseVocab.paymentMethods, id: \.0) { Text($0.1).tag($0.0) } }.labelsHidden() }
                FieldBox("Payment reference") { TextField("M-PESA code, cheque no.", text: $reference).textFieldStyle(.roundedBorder) }
            }
            if let imprest {
                imprestChoice(imprest)
            }
            HStack(alignment: .top) {
                FieldBox("Vendor") { TextField("Who was paid", text: $vendor).textFieldStyle(.roundedBorder) }
                FieldBox("Vendor contact") { TextField("Phone or email", text: $vendorContact).textFieldStyle(.roundedBorder) }
            }
            if let hint = vendorHint { Text(hint).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
            HStack(alignment: .top) {
                FieldBox("Outlet", error: error?.errors["outlet_id"]?.first) { Picker("", selection: $outletId) { Text("Head office").tag(Int?.none); ForEach(outlets, id: \.0) { Text($0.1).tag(Int?.some($0.0)) } }.labelsHidden() }
                FieldBox("Department") { TextField("", text: $department).textFieldStyle(.roundedBorder) }
            }
            Toggle("Recurring", isOn: $recurring)
            if recurring { Picker("Every", selection: $frequency) { Text("Week").tag("weekly"); Text("Month").tag("monthly"); Text("Quarter").tag("quarterly"); Text("Year").tag("annually") } }
            FieldBox("Description") { TextField("", text: $description, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
            FieldBox("Notes") { TextField("", text: $notes, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
            lineItems
            FieldBox("Evidence (uploaded after the expense is saved)") {
                HStack {
                    AttachmentPicker(label: "Add receipt or photo", allowsMultiple: true) { files.append(contentsOf: $0) }
                    ForEach(files) { f in Text(f.name).font(Theme.Font.caption).lineLimit(1) }
                }
            }
            if let e = error, e.errors.isEmpty { Text(e.message).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
            if let e = error?.errors["use_imprest"]?.first { Text(e).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
        }
        .task {
            let c = env.session.client
            if env.session.can("expenses.create") { suggestions = try? await c.get("/v1/admin/expenses/suggestions", background: true).json }
            if let j = try? await c.get("/v1/admin/expenses/imprest", background: true).json, let first = j["accounts"]?.array?.first { imprest = first }
        }
    }

    private var categoryHint: String? {
        guard let id = categoryId, let h = suggestions?["by_category"]?[String(id)] else { return nil }
        let parts = [h["vendor_name"]?.string.map { "usually \($0)" }, h["payment_method"]?.string.map { "paid by \(ExpenseVocab.methodLabel($0))" }, h["department"]?.string].compactMap { $0 }
        return parts.isEmpty ? nil : "This category: " + parts.joined(separator: ", ")
    }

    private var vendorHint: String? {
        let key = vendor.trimmingCharacters(in: .whitespaces).lowercased()
        guard !key.isEmpty, let h = suggestions?["by_vendor"]?[key] else { return nil }
        let cat = h["category_id"]?.int.flatMap { id in categories.first { $0.0 == id }?.1.trimmingCharacters(in: .whitespaces) }
        let parts = [cat.map { "usually \($0)" }, h["payment_method"]?.string.map { "paid by \(ExpenseVocab.methodLabel($0))" }].compactMap { $0 }
        return parts.isEmpty ? nil : "\(h["vendor_name"]?.string ?? vendor): " + parts.joined(separator: ", ")
    }

    @ViewBuilder
    private func usualsRow(_ usuals: [JSONValue]) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Text("Usual expenses (learned from history — suggestions only; the amount always needs your own entry)").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: Theme.Space.s) {
                    ForEach(Array(usuals.enumerated()), id: \.offset) { _, u in
                        Button { apply(usual: u) } label: {
                            VStack(alignment: .leading, spacing: 2) {
                                HStack { Text(u["title_suggestion"]?.string ?? u["title"]?.string ?? "").font(Theme.Font.secondary.weight(.semibold)).lineLimit(1); if let d = u["due_state"]?.string, d != "done" { StatusBadge(d, tone: d == "due" ? .warning : .amber) } }
                                Text([u["category_name"]?.string, u["cadence"]?.string, u["last_amount"].flatMap { Money(json: $0, currency: u["currency_code"]?.string ?? "KES") }.map { "last \($0.formatted())" }].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).lineLimit(1)
                            }
                            .padding(8).background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.field)).overlay(RoundedRectangle(cornerRadius: Theme.Radius.field).stroke(Theme.line))
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
        }
    }

    private func apply(usual u: JSONValue) {
        title = u["title_suggestion"]?.string ?? u["title"]?.string ?? title
        if let c = u["category_id"]?.int { categoryId = c }
        if let v = u["vendor_name"]?.string { vendor = v }
        if let m = u["payment_method"]?.string { method = m }
        outletId = u["outlet_id"]?.int
        if let d = u["department"]?.string { department = d }
        if let c = u["currency_code"]?.string { currency = c }
    }

    @ViewBuilder
    private func imprestChoice(_ a: JSONValue) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text("Pay from the imprest? *").font(Theme.Font.secondary.weight(.semibold))
            Text("\(a["name"]?.string ?? "Imprest") · balance \(fmtMoney(a["balance"], currency: a["currency_code"]?.string ?? "KES"))").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            Picker("", selection: $useImprest) {
                Text("Yes — paid from the imprest").tag(Bool?.some(true))
                Text("No — paid another way").tag(Bool?.some(false))
            }
            .pickerStyle(.segmented).labelsHidden()
            if useImprest == true && currency != "KES" { Text("An imprest spend is recorded in KES.").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning) }
        }
        .padding(Theme.Space.m).background(Theme.field, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
    }

    private var lineItems: some View {
        VStack(alignment: .leading, spacing: Theme.Space.s) {
            HStack { Text("Line items (optional)").font(Theme.Font.secondary.weight(.semibold)); Spacer(); Button { lines.append(LineItem()) } label: { Label("Add line", systemImage: "plus") }.font(Theme.Font.secondary) }
            ForEach($lines) { $line in
                HStack(alignment: .top, spacing: Theme.Space.s) {
                    TextField("Description", text: $line.description).textFieldStyle(.roundedBorder)
                    Picker("", selection: $line.categoryId) { Text("Same category").tag(Int?.none); ForEach(categories, id: \.0) { Text($0.1).tag(Int?.some($0.0)) } }.labelsHidden().frame(maxWidth: 160)
                    DecimalField("Qty", text: $line.quantity).frame(width: 64)
                    DecimalField("Unit price", text: $line.unitPrice).frame(width: 110)
                    DecimalField("Tax", text: $line.tax).frame(width: 80)
                    Button { lines.removeAll { $0.id == line.id } } label: { Image(systemName: "minus.circle") }.buttonStyle(.plain).foregroundStyle(Theme.Palette.danger)
                }
            }
            if !lines.isEmpty { Text("The expense amount above is what is recorded; the server checks the lines.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
        }
    }

    private func submit() async {
        busy = true; defer { busy = false }
        error = nil
        var body: [String: JSONValue] = [
            "title": .string(title), "category_id": .number(Double(categoryId ?? 0)), "expense_date": .string(HubDates.dayString(date)),
            "currency_code": .string(currency), "payment_method": .string(method), "is_recurring": .bool(recurring),
        ]
        body.put("amount", jnum(amount))
        body.put("exchange_rate", currency == "KES" ? nil : jnum(exchangeRate))
        body.put("description", jstr(description)); body.put("payment_reference", jstr(reference))
        body.put("vendor_name", jstr(vendor)); body.put("vendor_contact", jstr(vendorContact))
        body.put("outlet_id", outletId.map { .number(Double($0)) }); body.put("department", jstr(department))
        body.put("recurrence_frequency", recurring ? .string(frequency) : nil); body.put("notes", jstr(notes))
        if imprest != nil, let u = useImprest { body["use_imprest"] = .bool(u) }
        let items: [JSONValue] = lines.compactMap { l in
            guard !l.description.isEmpty else { return nil }
            var o: [String: JSONValue] = ["description": .string(l.description)]
            o.put("category_id", l.categoryId.map { .number(Double($0)) }); o.put("quantity", jnum(l.quantity)); o.put("unit_price", jnum(l.unitPrice)); o.put("tax_amount", jnum(l.tax))
            return .object(o)
        }
        if !items.isEmpty { body["line_items"] = .array(items) }
        do {
            let r = try await env.session.client.post("/v1/admin/expenses", json: .object(body))
            guard let id = r.json?["expense"]?["id"]?.int else { env.toasts.show("Saved, but the expense id was not returned.", tone: .warning); dismiss(); return }
            var failed = 0
            for f in files {
                let req = APIClient.Request(.post, "/v1/admin/expenses/\(id)/attachments", body: .multipart([APIClient.MultipartPart(name: "kind", value: "receipt"), f.part(name: "file")]))
                if (try? await env.session.client.send(req)) == nil { failed += 1 }
            }
            env.toasts.show(failed == 0 ? (r.json?["message"]?.string ?? "Expense created") : "Expense created; \(failed) file(s) did not upload — add them on the expense.", tone: failed == 0 ? .success : .warning)
            created(id)
        } catch let e as APIError { error = e } catch { env.toasts.error(error) }
    }
}
