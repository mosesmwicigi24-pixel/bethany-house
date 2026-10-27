import SwiftUI
import BethanyKit

/// The imprest (petty-cash float): GET /expenses/imprest lists accounts with
/// balance, open top-up and counts; every money move runs on the server
/// (ImprestService). Actions mirror ImprestPage.tsx: request top-up, send
/// (or send directly), confirm received, decline, cancel, record a count,
/// approve/reject a count, resolve a rejected/cancelled imprest spend.
struct ImprestView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var summary: Resource<JSONValue>?
    @State private var settingUp = false

    var body: some View {
        Page("Imprest", subtitle: "Petty-cash float") {
            if let summary {
                LoadableView(summary.state, retry: { summary.load() }) { json in
                    let accounts = jrows(json["accounts"])
                    if accounts.isEmpty {
                        if json["can_set_up"]?.bool == true {
                            EmptyState("No imprest yet", message: "Set up the float: who holds it, how much it is, and what is in the box today.", symbol: "wallet.pass", actionLabel: "Set up imprest") { settingUp = true }
                        } else {
                            EmptyState("No imprest yet", message: "The super admin sets up the float.", symbol: "wallet.pass")
                        }
                    }
                    ForEach(accounts) { a in
                        ImprestAccountCard(account: a, isSuperAdmin: json["is_super_admin"]?.bool ?? false) { summary.load() }
                    }
                }
            }
        }
        .task {
            if summary == nil {
                let c = env.session.client
                summary = Resource { try await c.get("/v1/admin/expenses/imprest").json ?? .null }
                summary?.load()
            }
        }
        .refreshable { await summary?.reload() }
        .sheet(isPresented: $settingUp) { ImprestSetupSheet { settingUp = false; summary?.load() } }
    }
}

struct ImprestAccountCard: View {
    @Environment(AppEnvironment.self) private var env
    let account: JRow
    let isSuperAdmin: Bool
    let reload: () -> Void
    @State private var action: ImprestAction?
    @State private var tab = "statement"

    enum ImprestAction: String, Identifiable { case request, send, direct, decline, receive, count, settings; var id: String { rawValue } }

    var body: some View {
        let a = account
        let cur = a.str("currency_code") ?? "KES"
        let custodian = a["you_are_custodian"]?.bool ?? false
        let open = a["open_topup"]
        SectionCard(a.str("name") ?? "Imprest") {
            TileGrid(minWidth: 160) {
                StatTile("Balance (book)", value: fmtMoney(a["balance"], currency: cur), hint: "of a \(fmtMoney(a["float_amount"], currency: cur)) float", tone: a["is_low"]?.bool == true ? .warning : nil)
                StatTile("Suggested top-up", value: fmtMoney(a["suggested_topup"], currency: cur), hint: "low below \(fmtMoney(a["low_threshold"], currency: cur))")
                StatTile("Pending counts", value: a["pending_counts"]?.string ?? "0", tone: (a.int("pending_counts") ?? 0) > 0 ? .amber : nil)
                StatTile("Unresolved spends", value: a["unresolved_expenses"]?.string ?? "0", tone: (a.int("unresolved_expenses") ?? 0) > 0 ? .warning : nil)
            }
            Text("Held by \(personName(a["custodian"]) ?? "—")\(custodian ? " (you)" : "") · \(a["outlet"]?["name"]?.string ?? "Head office")").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            if let open, !open.isNull { openTopup(open, cur: cur, custodian: custodian) }
            HStack(spacing: Theme.Space.s) {
                if open?.isNull != false, custodian || env.session.can("expenses.create") { Button("Request top-up") { action = .request } }
                if isSuperAdmin && open?.isNull != false { Button("Send money directly") { action = .direct } }
                if custodian || isSuperAdmin || env.session.can("expenses.create") { Button("Record a count") { action = .count } }
                if isSuperAdmin { Button("Settings") { action = .settings } }
                Spacer()
            }
            .font(Theme.Font.secondary)
            if a["can_read_book"]?.bool ?? true {
                SegmentedTabs(tabs: [("statement", "Statement"), ("topups", "Top-ups"), ("counts", "Counts & unresolved")], selection: $tab)
                switch tab {
                case "topups": ImprestTopupsList(accountId: a.int("id") ?? 0, cur: cur)
                case "counts": ImprestCountsList(accountId: a.int("id") ?? 0, cur: cur, isSuperAdmin: isSuperAdmin, reload: reload)
                default: ImprestStatementList(accountId: a.int("id") ?? 0, cur: cur)
                }
            }
        }
        .sheet(item: $action) { act in ImprestActionSheet(action: act, account: a, open: open) { action = nil; reload() } }
    }

    @ViewBuilder
    private func openTopup(_ t: JSONValue, cur: String, custodian: Bool) -> some View {
        let status = t["status"]?.string ?? "pending"
        VStack(alignment: .leading, spacing: 6) {
            HStack { Text("Open top-up").font(Theme.Font.secondary.weight(.semibold)); StatusBadge(status) }
            Text("Requested \(fmtMoney(t["requested_amount"], currency: cur)) by \(personName(t["requester"]) ?? "—")\(t["reason"]?.string.map { " · \($0)" } ?? "")").font(Theme.Font.secondary)
            if status == "sent" { Text("Sent \(fmtMoney(t["sent_amount"], currency: cur)) by \(ExpenseVocab.methodLabel(t["sent_method"]?.string))\(t["sent_reference"]?.string.map { " · \($0)" } ?? "") — waiting for the custodian to confirm what arrived.").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
            HStack(spacing: Theme.Space.s) {
                if status == "pending" && isSuperAdmin { Button("Mark sent") { action = .send }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand); Button("Decline") { action = .decline } }
                if status == "sent" && (custodian || isSuperAdmin) { Button("Confirm received") { action = .receive }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
                if status == "pending", let uuid = t["uuid"]?.string, custodian || isSuperAdmin {
                    ConfirmButton("Cancel request", message: "The top-up request is withdrawn.", destructive: true, action: { await cancel(uuid) }) { Text("Cancel request") }
                }
            }
            .font(Theme.Font.secondary)
        }
        .padding(Theme.Space.m).background(Theme.Palette.amberSoft, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
    }

    private func cancel(_ uuid: String) async {
        do { _ = try await env.session.client.post("/v1/admin/expenses/imprest/topups/\(uuid)/cancel"); env.toasts.show("Request cancelled"); reload() } catch { env.toasts.error(error) }
    }
}

/// One sheet for every imprest money move; the fields follow ImprestController's validation.
struct ImprestActionSheet: View {
    @Environment(AppEnvironment.self) private var env
    let action: ImprestAccountCard.ImprestAction
    let account: JRow
    let open: JSONValue?
    let done: () -> Void
    @State private var amount = ""
    @State private var method = "mpesa"
    @State private var reference = ""
    @State private var note = ""
    @State private var reason = ""
    @State private var name = ""
    @State private var floatAmount = ""
    @State private var lowPercent = ""
    @State private var custodianId: Int? = nil
    @State private var staff: [(Int, String)] = []
    @State private var unreplenished: JSONValue?
    @State private var error: APIError?
    @State private var busy = false

    private var cur: String { account.str("currency_code") ?? "KES" }

    var body: some View {
        BHFormSheet(title, primary: primary, busy: busy, disabled: disabled, destructive: action == .decline, action: run) {
            switch action {
            case .request:
                if let u = unreplenished { Text("Unreplenished spends: \(fmtMoney(u["total"], currency: cur)) across \(u["data"]?.array?.count ?? 0) expense\((u["data"]?.array?.count ?? 0) == 1 ? "" : "s"). Suggested top-up \(fmtMoney(account["suggested_topup"], currency: cur)).").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary) }
                FieldBox("Amount (blank = suggested)", error: error?.errors["amount"]?.first) { DecimalField("", text: $amount) }
                FieldBox("Reason") { TextField("Optional", text: $reason, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
            case .send, .direct:
                FieldBox("Amount sent *", error: error?.errors["amount"]?.first) { DecimalField("", text: $amount) }
                FieldBox("Method *") { Picker("", selection: $method) { Text("M-PESA").tag("mpesa"); Text("Cash").tag("cash"); Text("Bank transfer").tag("bank_transfer"); Text("Other").tag("other") }.labelsHidden() }
                FieldBox("Reference") { TextField("M-PESA code, etc.", text: $reference).textFieldStyle(.roundedBorder) }
                FieldBox("Note") { TextField("Optional", text: $note).textFieldStyle(.roundedBorder) }
            case .decline:
                FieldBox("Reason *", error: error?.errors["reason"]?.first) { TextField("", text: $reason, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
            case .receive:
                Text("Sent: \(fmtMoney(open?["sent_amount"], currency: cur)). Enter what actually arrived; a difference is recorded as such.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                FieldBox("Amount received *", error: error?.errors["amount"]?.first) { DecimalField("", text: $amount) }
                FieldBox("Note") { TextField("Optional", text: $note).textFieldStyle(.roundedBorder) }
            case .count:
                Text("Book balance: \(fmtMoney(account["balance"], currency: cur)). Count the cash in the box; the server works out the variance and the super admin approves any difference.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                FieldBox("Counted amount *", error: error?.errors["counted_amount"]?.first) { DecimalField("", text: $amount) }
                FieldBox("Note") { TextField("Optional", text: $note).textFieldStyle(.roundedBorder) }
            case .settings:
                FieldBox("Name", error: error?.errors["name"]?.first) { TextField("", text: $name).textFieldStyle(.roundedBorder) }
                FieldBox("Custodian") { Picker("", selection: $custodianId) { Text("Keep").tag(Int?.none); ForEach(staff, id: \.0) { Text($0.1).tag(Int?.some($0.0)) } }.labelsHidden() }
                FieldBox("Float amount", error: error?.errors["float_amount"]?.first) { DecimalField("", text: $floatAmount) }
                FieldBox("Low-balance warning at % of float", error: error?.errors["low_balance_percent"]?.first) { DecimalField("", text: $lowPercent) }
            }
            if let e = error, e.errors.isEmpty { Text(e.message).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
        }
        .task {
            let c = env.session.client
            if action == .request, let id = account.int("id") { unreplenished = try? await c.get("/v1/admin/expenses/imprest/\(id)/unreplenished", background: true).json }
            if action == .settings {
                name = account.str("name") ?? ""; floatAmount = account.str("float_amount") ?? ""; lowPercent = account.str("low_balance_percent") ?? ""
                staff = await staffOptions(c)
            }
        }
    }

    private var title: String {
        switch action {
        case .request: return "Request a top-up"
        case .send: return "Mark the top-up as sent"
        case .direct: return "Send money to the imprest"
        case .decline: return "Decline the top-up"
        case .receive: return "Confirm what arrived"
        case .count: return "Record a cash count"
        case .settings: return "Imprest settings"
        }
    }
    private var primary: String {
        switch action {
        case .request: return "Request"
        case .send, .direct: return "Mark sent"
        case .decline: return "Decline"
        case .receive: return "Confirm received"
        case .count: return "Record count"
        case .settings: return "Save"
        }
    }
    private var disabled: Bool {
        switch action {
        case .request, .settings: return false
        case .decline: return reason.trimmingCharacters(in: .whitespaces).count < 3
        default: return decimal(amount) == nil
        }
    }

    private func run() async {
        busy = true; defer { busy = false }
        error = nil
        let id = account.int("id") ?? 0
        let uuid = open?["uuid"]?.string ?? ""
        let base = "/v1/admin/expenses/imprest"
        var body: [String: JSONValue] = [:]
        let path: String
        switch action {
        case .request: path = "\(base)/\(id)/topups"; body.put("amount", jnum(amount)); body.put("reason", jstr(reason))
        case .send: path = "\(base)/topups/\(uuid)/send"; body.put("amount", jnum(amount)); body["method"] = .string(method); body.put("reference", jstr(reference)); body.put("note", jstr(note))
        case .direct: path = "\(base)/\(id)/topups/direct"; body.put("amount", jnum(amount)); body["method"] = .string(method); body.put("reference", jstr(reference)); body.put("note", jstr(note))
        case .decline: path = "\(base)/topups/\(uuid)/decline"; body["reason"] = .string(reason)
        case .receive: path = "\(base)/topups/\(uuid)/receive"; body.put("amount", jnum(amount)); body.put("note", jstr(note))
        case .count: path = "\(base)/\(id)/counts"; body.put("counted_amount", jnum(amount)); body.put("note", jstr(note))
        case .settings:
            body.put("name", jstr(name)); body.put("float_amount", jnum(floatAmount)); body.put("low_balance_percent", jnum(lowPercent)); body.put("custodian_id", custodianId.map { .number(Double($0)) })
            do { _ = try await env.session.client.put("\(base)/\(id)", json: .object(body)); env.toasts.show("Saved"); done() } catch let e as APIError { error = e } catch { env.toasts.error(error) }
            return
        }
        do { let r = try await env.session.client.post(path, json: .object(body)); env.toasts.show(r.json?["message"]?.string ?? "Done"); done() }
        catch let e as APIError { error = e } catch { env.toasts.error(error) }
    }
}

/// Staff for the custodian picker: GET /users?exclude_type=customer&status=active.
@MainActor
func staffOptions(_ client: APIClient) async -> [(Int, String)] {
    guard let r = try? await client.get("/v1/admin/users", query: ["per_page": "100", "status": "active", "exclude_type": "customer", "sort_by": "first_name", "sort_order": "asc"], background: true) else { return [] }
    return (r.json?["data"]?.array ?? []).compactMap { u in
        guard let id = u["id"]?.int else { return nil }
        return (id, personName(u) ?? u["email"]?.string ?? "User \(id)")
    }
}

struct ImprestSetupSheet: View {
    @Environment(AppEnvironment.self) private var env
    let done: () -> Void
    @State private var name = "Petty cash"
    @State private var custodianId: Int? = nil
    @State private var floatAmount = ""
    @State private var opening = ""
    @State private var lowPercent = ""
    @State private var outletId: Int? = nil
    @State private var staff: [(Int, String)] = []
    @State private var outlets: [(Int, String)] = []
    @State private var error: APIError?
    @State private var busy = false

    var body: some View {
        BHFormSheet("Set up the imprest", primary: "Create", busy: busy, disabled: custodianId == nil || decimal(floatAmount) == nil || decimal(opening) == nil, action: run) {
            FieldBox("Name", error: error?.errors["name"]?.first) { TextField("", text: $name).textFieldStyle(.roundedBorder) }
            FieldBox("Custodian *", error: error?.errors["custodian_id"]?.first) { Picker("", selection: $custodianId) { Text("Choose…").tag(Int?.none); ForEach(staff, id: \.0) { Text($0.1).tag(Int?.some($0.0)) } }.labelsHidden() }
            FieldBox("Float amount (KES) *", error: error?.errors["float_amount"]?.first) { DecimalField("", text: $floatAmount) }
            FieldBox("Opening balance — what is in the box today (KES) *", error: error?.errors["opening_balance"]?.first) { DecimalField("", text: $opening) }
            FieldBox("Low-balance warning at % of float") { DecimalField("Default on the server", text: $lowPercent) }
            if outlets.count > 1 { FieldBox("Outlet") { Picker("", selection: $outletId) { Text("Head office").tag(Int?.none); ForEach(outlets, id: \.0) { Text($0.1).tag(Int?.some($0.0)) } }.labelsHidden() } }
            if let e = error, e.errors.isEmpty { Text(e.message).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
        }
        .task { staff = await staffOptions(env.session.client); outlets = await outletOptions(env.session.client, reports: false) }
    }

    private func run() async {
        busy = true; defer { busy = false }
        var body: [String: JSONValue] = ["name": .string(name)]
        body.put("custodian_id", custodianId.map { .number(Double($0)) }); body.put("float_amount", jnum(floatAmount)); body.put("opening_balance", jnum(opening))
        body.put("low_balance_percent", jnum(lowPercent)); body.put("outlet_id", outletId.map { .number(Double($0)) })
        do { _ = try await env.session.client.post("/v1/admin/expenses/imprest", json: .object(body)); env.toasts.show("Imprest set up"); done() }
        catch let e as APIError { error = e } catch { env.toasts.error(error) }
    }
}

// MARK: - Lists under an account

struct ImprestStatementList: View {
    @Environment(AppEnvironment.self) private var env
    let accountId: Int
    let cur: String
    @State private var page = 1
    @State private var data: Resource<JSONValue>?

    var body: some View {
        Group {
            if let data {
                LoadableView(data.state, retry: { data.load() }, isEmpty: { ($0["data"]?.array ?? []).isEmpty }, empty: { EmptyState("No movements yet", symbol: "book") }) { json in
                    ForEach(jrows(json["data"])) { r in
                        HStack(alignment: .top) {
                            VStack(alignment: .leading, spacing: 2) {
                                HStack { StatusBadge(r.str("type"), tone: .neutral); if let e = r["expense"], !e.isNull { Button(e["reference_number"]?.string ?? "expense") { if let id = e["id"]?.int { env.navigator.push(.expense(id: id)) } }.font(Theme.Font.caption) } }
                                if let n = r.str("note"), !n.isEmpty { Text(n).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                                HStack { DateText(r["created_at"]?.date); if let who = personName(r["creator"]) { Text("· \(who)").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) } }
                            }
                            Spacer()
                            VStack(alignment: .trailing, spacing: 2) {
                                MoneyText(r.money("amount", currency: cur))
                                Text("balance \(fmtMoney(r["balance_after"], currency: cur))").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                            }
                        }
                        .padding(.vertical, 4)
                        Divider()
                    }
                    if let last = json["last_page"]?.int, last > 1 {
                        HStack { Button("Previous") { page -= 1 }.disabled(page <= 1); Text("\(page) / \(last)").font(Theme.Font.caption); Button("Next") { page += 1 }.disabled(page >= last) }.font(Theme.Font.secondary)
                    }
                }
            }
        }
        .task(id: page) {
            let c = env.session.client, id = accountId, p = page
            let r = Resource { try await c.get("/v1/admin/expenses/imprest/\(id)/statement", query: ["page": String(p)]).json ?? .null }
            data = r; r.load()
        }
    }
}

struct ImprestTopupsList: View {
    @Environment(AppEnvironment.self) private var env
    let accountId: Int
    let cur: String
    @State private var data: Resource<JSONValue>?
    var body: some View {
        Group {
            if let data {
                LoadableView(data.state, retry: { data.load() }, isEmpty: { ($0["data"]?.array ?? []).isEmpty }, empty: { EmptyState("No top-ups yet", symbol: "arrow.down.circle") }) { json in
                    DataTable(columns: [
                        TableColumn("Requested", "created_at", .date), TableColumn("Status", "status", .status),
                        TableColumn("Requested", "requested_amount", .money, fixedCurrency: cur), TableColumn("Sent", "sent_amount", .money, fixedCurrency: cur),
                        TableColumn("Received", "received_amount", .money, fixedCurrency: cur), TableColumn("Method", "sent_method", .status), TableColumn("Reference", "sent_reference"),
                    ], rows: jrows(json["data"]))
                }
            }
        }
        .task {
            let c = env.session.client, id = accountId
            let r = Resource { try await c.get("/v1/admin/expenses/imprest/\(id)/topups").json ?? .null }
            data = r; r.load()
        }
    }
}

struct ImprestCountsList: View {
    @Environment(AppEnvironment.self) private var env
    let accountId: Int
    let cur: String
    let isSuperAdmin: Bool
    let reload: () -> Void
    @State private var counts: Resource<JSONValue>?
    @State private var unresolved: Resource<[JRow]>?

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            if let unresolved {
                Text("Unresolved imprest spends").font(Theme.Font.secondary.weight(.semibold))
                LoadableView(unresolved.state, retry: { unresolved.load() }, isEmpty: { $0.isEmpty }, empty: { EmptyState("Nothing waiting", symbol: "checkmark.circle") }) { rows in
                    ForEach(rows) { e in
                        HStack {
                            VStack(alignment: .leading, spacing: 2) {
                                Button(e.str("reference_number") ?? "Expense") { if let id = e.int("id") { env.navigator.push(.expense(id: id)) } }.font(Theme.Font.mono)
                                Text("\(e.str("title") ?? "") · \(StatusTone.forStatus(e.str("status")) == .danger ? (e.str("status") ?? "").humanized : (e.str("status") ?? "").humanized)").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                            }
                            Spacer()
                            MoneyText(e["amount_kes"]?.kes)
                            if isSuperAdmin, let id = e.int("id") {
                                Button("Cash returned") { Task { await resolve(id, "returned") } }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                                Button("Write off") { Task { await resolve(id, "written_off") } }
                            }
                        }
                        .font(Theme.Font.secondary).padding(.vertical, 4)
                    }
                }
            }
            if let counts {
                Text("Cash counts").font(Theme.Font.secondary.weight(.semibold))
                LoadableView(counts.state, retry: { counts.load() }, isEmpty: { ($0["data"]?.array ?? []).isEmpty }, empty: { EmptyState("No counts recorded", symbol: "number") }) { json in
                    ForEach(jrows(json["data"])) { c in
                        HStack {
                            VStack(alignment: .leading, spacing: 2) {
                                HStack { StatusBadge(c.str("status")); Text("counted \(fmtMoney(c["counted_amount"], currency: cur)) vs book \(fmtMoney(c["book_balance"], currency: cur))").font(Theme.Font.secondary) }
                                Text("variance \(fmtMoney(c["variance"], currency: cur))\(c.str("note").map { " · \($0)" } ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                HStack { DateText(c["created_at"]?.date); if let who = personName(c["counter"]) { Text("· \(who)").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) } }
                            }
                            Spacer()
                            if c.str("status") == "pending" && isSuperAdmin, let id = c.int("id") {
                                Button("Approve adjustment") { Task { await decide(id, true) } }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                                Button("Reject") { Task { await decide(id, false) } }
                            }
                        }
                        .font(Theme.Font.secondary).padding(.vertical, 4)
                        Divider()
                    }
                }
            }
        }
        .task {
            let c = env.session.client, id = accountId
            if counts == nil {
                counts = Resource { try await c.get("/v1/admin/expenses/imprest/\(id)/counts").json ?? .null }
                unresolved = Resource { let j = try await c.get("/v1/admin/expenses/imprest/unresolved").json; return jrows(j?.array != nil ? j : j?["data"]) }
                counts?.load(); unresolved?.load()
            }
        }
    }

    private func decide(_ id: Int, _ approve: Bool) async {
        do { let r = try await env.session.client.post("/v1/admin/expenses/imprest/counts/\(id)/decide", json: .object(["approve": .bool(approve)])); env.toasts.show(r.json?["message"]?.string ?? "Done"); counts?.load(); reload() } catch { env.toasts.error(error) }
    }

    private func resolve(_ id: Int, _ resolution: String) async {
        do { let r = try await env.session.client.post("/v1/admin/expenses/\(id)/imprest-resolution", json: .object(["resolution": .string(resolution)])); env.toasts.show(r.json?["message"]?.string ?? "Done"); unresolved?.load(); reload() } catch { env.toasts.error(error) }
    }
}
