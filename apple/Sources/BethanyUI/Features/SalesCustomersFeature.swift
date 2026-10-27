import SwiftUI
import BethanyKit

// MARK: - Customers list (GET /admin/customers — CustomerController::index)

/// customers.view is the picker: identity and contact (masked by role).
/// Addresses, spend and credit figures come only with customers.insights —
/// the server strips them otherwise, so the rows show what they carry.
struct CustomersListView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var list: PagedList<Record>?
    @State private var search = ""
    @State private var status = ""
    @State private var type = ""
    @State private var sort = "created_at:desc"
    @State private var form: CustomerFormTarget?
    @State private var exporting = false
    @State private var selection: Record.ID?

    struct CustomerFormTarget: Identifiable {
        let editing: Record?
        var id: String { editing?.id ?? "new" }
    }

    var body: some View {
        Page("Customers", subtitle: list.map { "\($0.total) customers" }) {
            HStack(spacing: Theme.Space.s) {
                SalesSearchField(text: $search, placeholder: "Name, phone, email or customer no.") { Task { await reload() } }
                Picker("Status", selection: $status) { Text("All statuses").tag(""); Text("Active").tag("active"); Text("Inactive").tag("inactive"); Text("Suspended").tag("suspended") }.frame(maxWidth: 150)
                Picker("Type", selection: $type) { Text("All types").tag(""); Text("Individual").tag("individual"); Text("Business").tag("business") }.frame(maxWidth: 140)
                Picker("Sort", selection: $sort) {
                    Text("Newest first").tag("created_at:desc"); Text("Oldest first").tag("created_at:asc")
                    Text("Name A–Z").tag("name:asc"); Text("Name Z–A").tag("name:desc")
                }.frame(maxWidth: 150)
                Spacer()
                if env.session.permissions.isSuperAdmin {
                    Button { exporting = true } label: { Label("Google Contacts CSV", systemImage: "arrow.down.doc") }
                }
                if env.session.can("customers.create") {
                    Button { form = CustomerFormTarget(editing: nil) } label: { Label("New customer", systemImage: "person.badge.plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                }
            }
            .onChange(of: status) { _, _ in Task { await reload() } }
            .onChange(of: type) { _, _ in Task { await reload() } }
            .onChange(of: sort) { _, _ in Task { await reload() } }
            if let list {
                LoadableView(list.state, retry: { Task { await list.load() } }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No customers match", symbol: "person.crop.rectangle.stack") }) { rows in
                    #if os(macOS)
                    Table(rows, selection: $selection) {
                        TableColumn("Customer") { c in
                            VStack(alignment: .leading, spacing: 1) {
                                Text(Sales.name(first: c.string("first_name"), last: c.string("last_name")) ?? "—")
                                Text(c.string("customer_number") ?? "").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                            }
                        }
                        TableColumn("Phone") { c in Text(c.string("phone") ?? "—").font(Theme.Font.secondary) }.width(min: 110)
                        TableColumn("Email") { c in Text(c.string("email") ?? "—").font(Theme.Font.secondary).lineLimit(1) }
                        TableColumn("Type") { c in Text((c.string("customer_type") ?? "").humanized).font(Theme.Font.secondary) }.width(90)
                        TableColumn("Status") { c in StatusBadge(c.status) }.width(100)
                        TableColumn("Portal") { c in Text(c["is_portal_user"]?.bool == true ? "Yes" : "—").font(Theme.Font.secondary) }.width(60)
                        TableColumn("Since") { c in DateText(c.date("created_at"), style: .date) }.width(110)
                        TableColumn("") { c in CustomerRowActions(customer: c, onChanged: { Task { await list.load() } }, onEdit: { form = CustomerFormTarget(editing: c) }) }.width(120)
                    }
                    .frame(height: max(320, CGFloat(rows.count) * 34 + 40))
                    .onChange(of: selection) { _, new in if let new, let id = Int(new) { env.navigator.push(.customer(id: id)); selection = nil } }
                    #else
                    LazyVStack(spacing: Theme.Space.s) {
                        ForEach(rows) { c in
                            SalesRowCard(route: Int(c.id).map { .customer(id: $0) }) {
                                VStack(alignment: .leading, spacing: 3) {
                                    HStack { Text(Sales.name(first: c.string("first_name"), last: c.string("last_name")) ?? "—").font(Theme.Font.body); Spacer(); StatusBadge(c.status) }
                                    Text([c.string("customer_number"), c.string("phone"), c.string("email")].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                    CustomerRowActions(customer: c, onChanged: { Task { await list.load() } }, onEdit: { form = CustomerFormTarget(editing: c) })
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
        .sheet(item: $form) { t in CustomerFormSheet(editing: t.editing, onDone: { form = nil; Task { await list?.load() } }, onCancel: { form = nil }) }
        .sheet(isPresented: $exporting) { GoogleContactsExportSheet(onClose: { exporting = false }) }
    }

    private func reload() async {
        if list == nil { list = PagedList(client: env.session.client, path: "/v1/admin/customers") }
        let parts = sort.split(separator: ":").map(String.init)
        list?.query = [
            "search": search.isEmpty ? nil : search, "status": status.isEmpty ? nil : status, "type": type.isEmpty ? nil : type,
            "sort_by": parts.first, "sort_order": parts.count > 1 ? parts[1] : "desc",
        ]
        await list?.load()
    }
}

/// Status (PUT /customers/{id}/status), invite (POST …/invite-to-portal),
/// edit (PUT /customers/{id}), delete (DELETE /customers/{id}).
struct CustomerRowActions: View {
    @Environment(AppEnvironment.self) private var env
    let customer: Record
    let onChanged: () -> Void
    let onEdit: () -> Void

    var body: some View {
        let s = env.session
        let canInvite = s.can("customers.invite") && customer["is_portal_user"]?.bool != true && !(customer.string("email") ?? "").isEmpty
        HStack(spacing: 4) {
            if s.can("customers.edit") || s.can("customers.delete") || canInvite {
                Menu {
                    if s.can("customers.edit") {
                        Button("Edit", action: onEdit)
                        Menu("Set status") {
                            ForEach(["active", "inactive", "suspended"], id: \.self) { st in
                                Button(st.humanized) { Task { await setStatus(st) } }.disabled(customer.status == st)
                            }
                        }
                    }
                    if canInvite { Button("Invite to portal") { Task { await invite() } } }
                    if s.can("customers.delete") {
                        Divider()
                        Button("Delete…", role: .destructive) { Task { await delete() } }
                    }
                } label: { Image(systemName: "ellipsis.circle") }
                .fixedSize()
            }
        }
    }

    private func setStatus(_ st: String) async {
        do { _ = try await env.session.client.put("/v1/admin/customers/\(customer.id)/status", json: .object(["status": .string(st)])); env.toasts.show("Status updated"); onChanged() } catch { env.toasts.error(error) }
    }

    private func invite() async {
        do { let r = try await env.session.client.post("/v1/admin/customers/\(customer.id)/invite-to-portal"); env.toasts.show(r.json?["message"]?.string ?? "Portal invite sent") } catch { env.toasts.error(error) }
    }

    private func delete() async {
        do { _ = try await env.session.client.delete("/v1/admin/customers/\(customer.id)"); env.toasts.show("Customer deleted"); onChanged() } catch { env.toasts.error(error) }
    }
}

// MARK: - Customer form (POST /customers | PUT /customers/{id})

struct CustomerFormSheet: View {
    @Environment(AppEnvironment.self) private var env
    let editing: Record?
    let onDone: () -> Void
    let onCancel: () -> Void
    @State private var firstName = ""
    @State private var lastName = ""
    @State private var email = ""
    @State private var phone = ""
    @State private var type = "individual"
    @State private var company = ""
    @State private var taxNumber = ""
    @State private var language = "en"
    @State private var currency = "KES"
    @State private var notes = ""

    private var canSubmit: Bool {
        !firstName.trimmingCharacters(in: .whitespaces).isEmpty && !lastName.trimmingCharacters(in: .whitespaces).isEmpty
            && (!email.trimmingCharacters(in: .whitespaces).isEmpty || !phone.trimmingCharacters(in: .whitespaces).isEmpty)
            && (type != "business" || !company.trimmingCharacters(in: .whitespaces).isEmpty)
    }

    var body: some View {
        SheetFrame(title: editing == nil ? "New customer" : "Edit customer", submitLabel: "Save", canSubmit: canSubmit, onCancel: onCancel, onSubmit: {
            var body: [String: JSONValue] = [
                "first_name": .string(firstName.trimmingCharacters(in: .whitespaces)), "last_name": .string(lastName.trimmingCharacters(in: .whitespaces)),
                "type": .string(type), "preferred_language": .string(language), "preferred_currency": .string(currency),
                "company_name": Sales.optionalString(company), "tax_number": Sales.optionalString(taxNumber), "notes": Sales.optionalString(notes),
            ]
            if !email.trimmingCharacters(in: .whitespaces).isEmpty { body["email"] = .string(email.trimmingCharacters(in: .whitespaces)) }
            if !phone.trimmingCharacters(in: .whitespaces).isEmpty { body["phone"] = .string(phone.trimmingCharacters(in: .whitespaces)) }
            if let editing { _ = try await env.session.client.put("/v1/admin/customers/\(editing.id)", json: .object(body)) }
            else { _ = try await env.session.client.post("/v1/admin/customers", json: .object(body)) }
            env.toasts.show(editing == nil ? "Customer created" : "Customer updated")
            onDone()
        }) {
            HStack { TextInput(label: "First name", text: $firstName); TextInput(label: "Last name", text: $lastName) }
            HStack { TextInput(label: "Phone", text: $phone); TextInput(label: "Email", text: $email) }
            Text("Email or phone is required. Without an email the customer is a walk-in record without portal access; you can invite them later.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            Picker("Type", selection: $type) { Text("Individual").tag("individual"); Text("Business").tag("business") }.pickerStyle(.segmented)
            if type == "business" { HStack { TextInput(label: "Company (required)", text: $company); TextInput(label: "Tax number", text: $taxNumber) } }
            HStack {
                SalesLabeledField(label: "Language") { Picker("", selection: $language) { Text("English").tag("en"); Text("French").tag("fr"); Text("Portuguese").tag("pt") }.labelsHidden() }
                SalesLabeledField(label: "Currency") { Picker("", selection: $currency) { Text("KES").tag("KES"); Text("USD").tag("USD") }.labelsHidden() }
            }
            TextInput(label: "Notes", text: $notes, multiline: true)
        }
        .onAppear {
            guard let c = editing, firstName.isEmpty else { return }
            firstName = c.string("first_name") ?? ""
            lastName = c.string("last_name") ?? ""
            email = Sales.isMasked(c.string("email")) ? "" : (c.string("email") ?? "")
            phone = Sales.isMasked(c.string("phone")) ? "" : (c.string("phone") ?? "")
            type = c.string("customer_type") ?? "individual"
            company = c.string("company") ?? ""
            taxNumber = c.string("tax_id") ?? ""
            language = c.string("preferred_language") ?? "en"
            currency = c.string("preferred_currency") ?? "KES"
            notes = c.string("notes") ?? ""
        }
    }
}

// MARK: - Google Contacts export (POST /customers/export/google-contacts {password, code})

/// The owner account only: password and two-step code typed each time; the
/// server answers a CSV (and refuses everyone else with 403).
struct GoogleContactsExportSheet: View {
    @Environment(AppEnvironment.self) private var env
    let onClose: () -> Void
    @State private var password = ""
    @State private var code = ""
    @State private var fileURL: URL?

    var body: some View {
        SheetFrame(title: "Export for Google Contacts", submitLabel: "Download CSV", canSubmit: !password.isEmpty && code.trimmingCharacters(in: .whitespaces).count >= 6, onCancel: onClose, onSubmit: {
            let r = try await env.session.client.send(.init(.post, "/v1/admin/customers/export/google-contacts", body: .json(.object(["password": .string(password), "code": .string(code.trimmingCharacters(in: .whitespaces))])), headers: ["Accept": "*/*"]))
            let contacts = r.headers["X-Contacts"] ?? r.headers["x-contacts"] ?? "?"
            DocumentSaver.save(r.data, name: "bethany-house-google-contacts.csv", env: env) { fileURL = $0 }
            env.toasts.show("\(contacts) contacts exported")
            #if os(macOS)
            onClose()
            #endif
        }) {
            Text("A CSV in Google's own import layout: one row per phone number with the best name the hub holds. Owner only; the server checks the password and two-step code every time.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            SalesLabeledField(label: "Password") { SecureField("Password", text: $password).textFieldStyle(.roundedBorder) }
            TextInput(label: "Two-step code", text: $code)
        }
        #if os(iOS)
        .sheet(item: $fileURL) { url in ShareSheet(items: [url]) }
        #endif
    }
}

// MARK: - Customer detail (GET /customers/{id} → {customer, stats})

struct CustomerDetailView: View {
    @Environment(AppEnvironment.self) private var env
    let customerId: Int
    @State private var resource: Resource<JSONValue>?
    @State private var orders: PagedList<Record>?
    @State private var editing = false

    var body: some View {
        let json = resource?.value
        let c = Record(json: json?["customer"] ?? .null)
        Page(Sales.name(first: c.string("first_name"), last: c.string("last_name")) ?? "Customer") {
            if let resource {
                LoadableView(resource.state, retry: { resource.load() }) { json in
                    let c = Record(json: json["customer"] ?? .null)
                    header(c)
                    if let stats = json["stats"], stats.object != nil { statsView(stats) }
                    HStack(alignment: .top, spacing: Theme.Space.l) {
                        contact(c)
                        addresses(c)
                    }
                    if env.session.can("customers.insights"), let orders { ordersSection(orders) }
                    if let n = c.string("notes"), !n.isEmpty { SectionCard("Notes") { Text(n).font(Theme.Font.body).textSelection(.enabled) } }
                }
            }
        }
        .task {
            if resource == nil {
                let client = env.session.client, id = customerId
                resource = Resource { try await client.get("/v1/admin/customers/\(id)").json ?? .null }
                resource?.load()
                if env.session.can("customers.insights") {
                    orders = PagedList(client: client, path: "/v1/admin/customers/\(id)/orders")
                    await orders?.load()
                }
            }
        }
        .refreshable { await resource?.reload(); await orders?.load() }
        .sheet(isPresented: $editing) {
            CustomerFormSheet(editing: c, onDone: { editing = false; resource?.load() }, onCancel: { editing = false })
        }
    }

    private func header(_ c: Record) -> some View {
        SectionCard {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 4) {
                    HStack(spacing: 6) {
                        Text(c.string("customer_number") ?? "").font(Theme.Font.mono).foregroundStyle(Theme.textSecondary)
                        StatusBadge(c.status)
                        StatusBadge(c.string("customer_type"), tone: .neutral)
                        if c["is_portal_user"]?.bool == true { StatusBadge("portal", tone: .info) }
                    }
                    if let co = c.string("company"), !co.isEmpty { Text(co).font(Theme.Font.body) }
                    HStack(spacing: Theme.Space.m) {
                        Text("Prefers \(c.string("preferred_currency") ?? "KES") · \((c.string("preferred_language") ?? "en").uppercased())")
                        DateText(c.date("created_at"), style: .date)
                    }
                    .font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                }
                Spacer()
                CustomerRowActions(customer: c, onChanged: { resource?.load() }, onEdit: { editing = true })
            }
        }
    }

    private func statsView(_ s: JSONValue) -> some View {
        SectionCard("Purchase history") {
            TileGrid(minWidth: 150) {
                StatTile("Orders", value: s["total_orders"]?.string ?? "0", hint: "\(s["recognised_orders"]?.string ?? "0") recognised")
                StatTile("Total spent", value: s["total_spent"].flatMap { Money(json: $0, currency: "KES") }?.formatted() ?? "—", hint: "server figure")
                StatTile("Average order", value: s["average_order_value"].flatMap { Money(json: $0, currency: "KES") }?.formatted() ?? "—")
                StatTile("Online / POS", value: "\(s["online_orders"]?.string ?? "0") / \(s["pos_orders"]?.string ?? "0")")
                StatTile("Cancelled", value: s["cancelled_orders"]?.string ?? "0", tone: (s["cancelled_orders"]?.int ?? 0) > 0 ? .danger : nil)
            }
            if let d = s["last_order_date"]?.date { HStack { Text("Last order").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary); DateText(d) } }
        }
    }

    private func contact(_ c: Record) -> some View {
        SectionCard("Contact") {
            ContactLine(label: "Phone", value: c.string("phone"), field: "phone", customerId: customerId, context: nil)
            ContactLine(label: "Email", value: c.string("email"), field: "email", customerId: customerId, context: nil)
            if let t = c.string("tax_id"), !t.isEmpty { DetailRow("Tax ID", t) }
            if let lp = c["loyalty_points"], !lp.isNull { DetailRow("Loyalty points", lp.string) }
            if let cl = c.money("credit_limit", currency: c.string("preferred_currency") ?? "KES"), !cl.isZero { DetailRow("Credit limit") { MoneyText(cl) } }
        }
    }

    private func addresses(_ c: Record) -> some View {
        let list = (c["addresses"]?.array ?? []).map(Record.init(json:))
        return SectionCard("Addresses") {
            if list.isEmpty { Text(env.session.can("customers.insights") ? "No addresses on file." : "Addresses need customers.insights.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary) }
            ForEach(list) { a in
                VStack(alignment: .leading, spacing: 2) {
                    HStack { Text(a.string("name") ?? "Address").font(Theme.Font.body); if a["is_default"]?.bool == true { StatusBadge("default", tone: .info) } }
                    Text(["address_line_1", "address_line_2", "city", "state", "postal_code", "country"].compactMap { a.string($0) }.filter { !$0.isEmpty }.joined(separator: ", ")).font(Theme.Font.secondary)
                    if let p = a.string("phone") { Text(p).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                }
                .padding(.vertical, 2)
            }
        }
    }

    private func ordersSection(_ orders: PagedList<Record>) -> some View {
        SectionCard("Orders") {
            LoadableView(orders.state, retry: { Task { await orders.load() } }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No orders yet", symbol: "bag") }) { rows in
                ForEach(rows) { o in
                    Button { if let id = Int(o.id) { env.navigator.push(.order(id: id)) } } label: {
                        HStack {
                            Text(o.string("order_number") ?? "").font(Theme.Font.mono)
                            StatusBadge(o.status)
                            StatusBadge(o.string("payment_status"))
                            Spacer()
                            MoneyText(o.money("total_amount"))
                            DateText(o.date("created_at"), style: .date)
                        }
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                    Divider()
                }
                LoadMoreRow(list: orders)
            }
        }
    }
}

// MARK: - Outstanding balances (GET /admin/pos/outstanding-balances — receivables.view)

/// Receivables: orders with money still owed. `amount_paid` and `balance`
/// are the server's figures per row; rows in several currencies are listed
/// in their own currency and never summed here.
struct OutstandingBalancesView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var list: PagedList<Record>?
    @State private var search = ""

    var body: some View {
        Page("Outstanding Balances", subtitle: list.map { "\($0.total) orders with a balance" }) {
            HStack {
                SalesSearchField(text: $search, placeholder: "Order no., customer, phone") { Task { await reload() } }
                Spacer()
            }
            if let list {
                LoadableView(list.state, retry: { Task { await list.load() } }, isEmpty: { $0.isEmpty }, empty: { EmptyState("Nothing outstanding", message: "Every order has been settled.", symbol: "checkmark.circle") }) { rows in
                    LazyVStack(spacing: Theme.Space.s) {
                        ForEach(rows) { r in
                            SalesRowCard(route: Int(r.id).map { .order(id: $0) }) {
                                VStack(alignment: .leading, spacing: 4) {
                                    HStack {
                                        Text(r.string("order_number") ?? "").font(Theme.Font.mono)
                                        StatusBadge(r.string("payment_status"))
                                        Spacer()
                                        VStack(alignment: .trailing, spacing: 1) {
                                            Text("Balance").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                            MoneyText(r.money("balance", currency: r.string("currency_code")), emphasis: true)
                                        }
                                    }
                                    HStack {
                                        VStack(alignment: .leading, spacing: 1) {
                                            Text(r.string("customer_name") ?? "Walk-in").font(Theme.Font.body)
                                            Text([r.string("customer_phone"), r.string("outlet_name")].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                        }
                                        Spacer()
                                        VStack(alignment: .trailing, spacing: 1) {
                                            Text("Total \(r.money("total_amount", currency: r.string("currency_code"))?.formatted() ?? "—") · paid \(r.money("amount_paid", currency: r.string("currency_code"))?.formatted() ?? "—")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                            if let due = r.string("balance_due_date") { Text("due \(due.prefix(10))").font(Theme.Font.caption).foregroundStyle(Theme.Palette.amber) }
                                        }
                                    }
                                }
                            }
                        }
                    }
                    LoadMoreRow(list: list)
                }
            }
        }
        .task { if list == nil { await reload() } }
        .refreshable { await list?.load() }
    }

    private func reload() async {
        if list == nil { list = PagedList(client: env.session.client, path: "/v1/admin/pos/outstanding-balances") }
        list?.query = ["search": search.isEmpty ? nil : search]
        await list?.load()
    }
}
