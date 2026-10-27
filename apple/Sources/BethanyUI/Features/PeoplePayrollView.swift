import SwiftUI
import BethanyKit

/// Payroll (People PR 6/7). Every figure is KES as the server computed it
/// from the locked timesheet and the pay structure; nothing is summed here.
/// Writes need payroll.run; approve, pay, payouts, payslips, advances and
/// rate rows are behind step-up on the server.
struct PayrollView: View {
    @Environment(AppEnvironment.self) private var env
    enum Tab: Hashable { case runs, advances, returns, rates }
    @State private var tab: Tab = .runs
    @State private var year = Day.thisYear()
    @State private var version = 0
    @State private var openRun: IDRef?

    var body: some View {
        Page("Payroll", subtitle: "Runs, advances, statutory returns and rate tables") {
            Tabs(selection: $tab, items: [(.runs, "Runs"), (.advances, "Advances & loans"), (.returns, "Returns"), (.rates, "Statutory rates")])
            switch tab {
            case .runs: runs
            case .advances: AdvancesPanel()
            case .returns: ReturnsPanel()
            case .rates: RatesPanel()
            }
        }
        .sheet(item: $openRun) { ref in PayrollRunSheet(runId: ref.id) { version += 1 } }
    }

    private var runs: some View {
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            HStack {
                Button { year -= 1 } label: { Image(systemName: "chevron.left") }
                Text(String(year)).font(Theme.Font.sectionTitle)
                Button { year += 1 } label: { Image(systemName: "chevron.right") }
            }
            RemoteJSON("/v1/admin/payroll/runs", query: ["year": String(year)], version: version) { j, reload in
                let unconfirmed = j["rates_unconfirmed"]?.array?.compactMap(\.string) ?? []
                if !unconfirmed.isEmpty {
                    Label("Unconfirmed rate tables: \(unconfirmed.map { rateLabel($0) }.joined(separator: ", ")). Confirm them before paying.", systemImage: "exclamationmark.triangle")
                        .font(Theme.Font.secondary).foregroundStyle(Theme.Palette.warning)
                }
                let ready = j["ready_periods"]?.records ?? []
                if j["can_run"]?.bool == true, !ready.isEmpty {
                    SectionCard("Periods ready to run") {
                        ForEach(ready) { p in
                            HStack {
                                Text("\(p.string("group") ?? "Default") · \(Day.format(p.string("starts_on"))) – \(Day.format(p.string("ends_on"), "d MMM yyyy"))").font(Theme.Font.body)
                                Spacer()
                                AsyncButton("Draft run") {
                                    let r = try await env.session.client.post("/v1/admin/payroll/runs", json: J.obj([("pay_period_id", J.i(p.int("id")))]))
                                    toastResult(env, r); reload()
                                    if let id = r.json?["run"]?["id"]?.int { openRun = IDRef(id) }
                                }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                            }
                        }
                    }
                }
                let runs = j["runs"]?.records ?? []
                if runs.isEmpty { EmptyState("No payroll runs in \(year)", symbol: "dollarsign.circle") }
                ForEach(runs) { r in
                    Button { openRun = IDRef(r.int("id") ?? 0) } label: { PayrollRunRow(run: r) }.buttonStyle(.plain)
                }
            }
        }
    }
}

func rateLabel(_ code: String) -> String {
    switch code { case "paye": return "PAYE"; case "nssf": return "NSSF"; case "shif": return "SHIF"; case "housing_levy": return "Housing Levy"; default: return code.humanized }
}

func kes(_ v: JSONValue?) -> Money? { v.flatMap { Money(json: $0, currency: "KES") } }

struct PayrollRunRow: View {
    let run: Record
    var body: some View {
        let t = run["totals"] ?? .null
        SupportListRow("\(run.string("reference") ?? "Run") · \(run["group"]?["name"]?.string ?? "")", subtitle: "\(Day.format(run["period"]?["starts_on"]?.string)) – \(Day.format(run["period"]?["ends_on"]?.string, "d MMM yyyy")) · \(run.int("people") ?? 0) people\(run.string("kind") == "adjustment" ? " · adjustment" : "")") {
            VStack(alignment: .trailing, spacing: 2) {
                StatusBadge(run.status)
                HStack(spacing: 4) { Text("Net").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary); MoneyText(kes(t["net"])) }
                if run["reconciles"]?.bool == false { Pill("does not reconcile", .danger) }
            }
        }
    }
}

// MARK: - One run (GET /payroll/runs/{id})

struct PayrollRunSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let runId: Int
    let changed: () -> Void
    @State private var run: JSONValue?
    @State private var readiness: JSONValue?
    @State private var error: APIError?
    @State private var paying = false
    @State private var share: URL?

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.l) {
            HStack { Text(run?["reference"]?.string ?? "Payroll run").font(Theme.Font.sectionTitle); if let s = run?["status"]?.string { StatusBadge(s) }; Spacer(); Button("Close") { dismiss() } }
            if let r = run {
                ScrollView { content(r) }
                actions(r)
            } else if let error { ErrorState(error, retry: { Task { await load() } }) } else { LoadingSkeleton() }
        }
        .padding(Theme.Space.xl)
        #if os(macOS)
        .frame(minWidth: 720, minHeight: 600)
        #endif
        .task { await load() }
        .sheet(isPresented: $paying) { if let r = run { PayRunSheet(run: r) { Task { await load() }; changed() } } }
        .shareFile($share)
    }

    @ViewBuilder
    private func content(_ r: JSONValue) -> some View {
        let t = r["totals"] ?? .null
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            if let breaks = r["breaks"]?.array, !breaks.isEmpty {
                SectionCard("This run does not reconcile") { ForEach(breaks.compactMap(\.string), id: \.self) { Text($0).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) } }
            }
            TileGrid(minWidth: 150) {
                StatTile("Gross", value: kes(t["gross"])?.formatted() ?? "—")
                StatTile("PAYE", value: kes(t["paye"])?.formatted() ?? "—")
                StatTile("NSSF (employee)", value: kes(t["nssf_employee"])?.formatted() ?? "—")
                StatTile("SHIF", value: kes(t["shif"])?.formatted() ?? "—")
                StatTile("Housing (employee)", value: kes(t["housing_employee"])?.formatted() ?? "—")
                StatTile("Other deductions", value: kes(t["other_deductions"])?.formatted() ?? "—")
                StatTile("Net pay", value: kes(t["net"])?.formatted() ?? "—", tone: .success)
                StatTile("Employer cost", value: kes(t["employer_cost"])?.formatted() ?? "—", hint: "NSSF \(kes(t["nssf_employer"])?.formatted() ?? "—") · Housing \(kes(t["housing_employer"])?.formatted() ?? "—")")
            }
            KeyValueGrid(pairs: [
                ("Period", "\(Day.format(r["period"]?["starts_on"]?.string)) – \(Day.format(r["period"]?["ends_on"]?.string, "d MMM yyyy")) (\(r["period"]?["status"]?.string ?? ""))"),
                ("Approved", r["approved_by"]?.string.map { "\($0) · \(Day.dateTime(r["approved_at"]?.string))" } ?? "—"),
                ("Paid", r["paid_at"]?.string.map(Day.dateTime) ?? "—"),
                ("Expense", r["expense"]?["reference"]?.string.map { "\($0) (\(r["expense"]?["status"]?.string ?? ""))" } ?? "—"),
            ])
            let unpayable = r["unpayable"]?.records ?? []
            if !unpayable.isEmpty { SectionCard("Not payable") { ForEach(unpayable) { u in Text("\(u.string("name") ?? ""): \(u.string("why") ?? "")").font(Theme.Font.secondary).foregroundStyle(Theme.Palette.warning) } } }
            if let rates = r["rates_used"]?.array, !rates.isEmpty {
                Text("Rates used: " + rates.map { "\(rateLabel($0["code"]?.string ?? "")) from \($0["effective_from"]?.string ?? "")\($0["is_confirmed"]?.bool == false ? " (unconfirmed)" : "")" }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            }
            if let ready = readiness {
                SectionCard("Payout readiness") {
                    Text("M-Pesa: \(ready["mpesa"]?["ready"]?.int ?? 0) ready, \(ready["mpesa"]?["total"]?.string ?? "—")\((ready["mpesa"]?["missing"]?.array ?? []).isEmpty ? "" : " · missing: \((ready["mpesa"]?["missing"]?.array ?? []).compactMap(\.string).joined(separator: ", "))")").font(Theme.Font.secondary)
                    Text("Bank: \(ready["bank"]?["ready"]?.int ?? 0) ready, \(ready["bank"]?["total"]?.string ?? "—")\((ready["bank"]?["missing"]?.array ?? []).isEmpty ? "" : " · missing: \((ready["bank"]?["missing"]?.array ?? []).compactMap(\.string).joined(separator: ", "))")").font(Theme.Font.secondary)
                    Text("Cash: \(ready["cash"]?.int ?? 0)\((ready["no_pay_method"]?.array ?? []).isEmpty ? "" : " · no pay method: \((ready["no_pay_method"]?.array ?? []).compactMap(\.string).joined(separator: ", "))")").font(Theme.Font.secondary)
                }
            }
            SectionCard("Lines") {
                ForEach(r["lines"]?.records ?? []) { l in
                    VStack(alignment: .leading, spacing: 4) {
                        HStack {
                            Text(l["user"]?["name"]?.string ?? "#\(l["user"]?["id"]?.int ?? 0)").font(Theme.Font.body.weight(.semibold))
                            Spacer()
                            MoneyText(kes(l["net"]), emphasis: true)
                            AsyncButton {
                                let (data, name, _) = try await env.session.client.download("/v1/admin/payroll/runs/\(runId)/lines/\(l.id)/payslip")
                                presentFile(env, data: data, name: name ?? "payslip-\(r["reference"]?.string ?? "")-\(l["user"]?["id"]?.int ?? 0).pdf", share: $share)
                            } label: { Image(systemName: "doc.text") }
                        }
                        Text("Gross \(kes(l["gross"])?.formatted() ?? "—") · PAYE \(kes(l["paye"])?.formatted() ?? "—") · NSSF \(kes(l["nssf_employee"])?.formatted() ?? "—") · SHIF \(kes(l["shif"])?.formatted() ?? "—") · Housing \(kes(l["housing_employee"])?.formatted() ?? "—") · Other \(kes(l["other_deductions"])?.formatted() ?? "—")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        let h = l["hours"] ?? .null
                        Text("\(h["days_worked"]?.int ?? 0) days · regular \(minutesText(h["regular_minutes"]?.int)) · OT \(minutesText(h["overtime_minutes"]?.int)) · \(l["structure"]?["kind"]?.string ?? "no structure")\(l["payment_method"]?.string.map { " · \($0)" } ?? "")\(l["payment_reference"]?.string.map { " \($0)" } ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                    }
                    Divider()
                }
            }
        }
    }

    @ViewBuilder
    private func actions(_ r: JSONValue) -> some View {
        let canRun = env.session.can("payroll.run")
        HStack {
            if r["status"]?.string == "approved" || r["status"]?.string == "paid" {
                DocumentButton("M-Pesa file", path: "/v1/admin/payroll/runs/\(runId)/payout", query: ["method": "mpesa"], suggestedName: "payout-mpesa.csv")
                DocumentButton("Bank file", path: "/v1/admin/payroll/runs/\(runId)/payout", query: ["method": "bank"], suggestedName: "payout-bank.csv")
            }
            Spacer()
            if canRun {
                switch r["status"]?.string {
                case "draft":
                    AsyncButton("Recalculate") { let x = try await env.session.client.post("/v1/admin/payroll/runs/\(runId)/recalculate"); toastResult(env, x); changed(); await load() }
                    ConfirmButton("Discard run", message: "The draft is deleted; the period can be drafted again.", destructive: true, action: {
                        do { let x = try await env.session.client.delete("/v1/admin/payroll/runs/\(runId)"); toastResult(env, x); changed(); dismiss() } catch { env.toasts.error(error) }
                    }) { Text("Discard") }
                    ConfirmButton("Approve run", message: "Approving fixes these figures and raises the payroll expense. You will be asked to confirm it is you.", action: {
                        do { let x = try await env.session.client.post("/v1/admin/payroll/runs/\(runId)/approve"); toastResult(env, x); changed(); await load() } catch { env.toasts.error(error) }
                    }) { Text("Approve") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                case "approved":
                    Button("Record payments…") { paying = true }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                default: EmptyView()
                }
            }
        }
    }

    private func load() async {
        do {
            run = try await env.session.client.json("/v1/admin/payroll/runs/\(runId)")["run"]
            if run?["status"]?.string == "approved" { readiness = try? await env.session.client.json("/v1/admin/payroll/runs/\(runId)/payout-readiness") }
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}

/// POST /payroll/runs/{id}/pay {lines: [{line_id, payment_method, payment_reference}]} — step-up.
struct PayRunSheet: View {
    @Environment(AppEnvironment.self) private var env
    let run: JSONValue
    let done: () -> Void
    @State private var methods: [String: String] = [:]
    @State private var refs: [String: String] = [:]

    var body: some View {
        let lines = run["lines"]?.records ?? []
        EditSheet("Record payments", saveLabel: "Mark paid", canSave: lines.allSatisfy { methods[$0.id] != nil }, save: {
            let body = J.obj([("lines", .array(lines.map { l in J.obj([("line_id", J.i(l.int("id"))), ("payment_method", .string(methods[l.id] ?? "cash")), ("payment_reference", J.sOpt(refs[l.id]))]) }))])
            let r = try await env.session.client.post("/v1/admin/payroll/runs/\(run["id"]?.int ?? 0)/pay", json: body)
            toastResult(env, r); done()
        }) { e in
            Text("How each person was paid. Marking paid closes the run; you will be asked to confirm it is you.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            ForEach(lines) { l in
                HStack(alignment: .bottom) {
                    VStack(alignment: .leading) { Text(l["user"]?["name"]?.string ?? "").font(Theme.Font.secondary); MoneyText(kes(l["net"])) }.frame(minWidth: 150, alignment: .leading)
                    OptionPicker("Method", Binding(get: { methods[l.id] ?? l.string("payment_method") ?? "" }, set: { methods[l.id] = $0 }), options: [("", "—"), ("mpesa", "M-Pesa"), ("bank", "Bank"), ("cash", "Cash")])
                    Field("Reference", Binding(get: { refs[l.id] ?? "" }, set: { refs[l.id] = $0 }))
                }
            }
            if let f = e?.firstFieldError { FieldError(f) }
        }
        .task { for l in lines { if let m = l.string("payment_method"), methods[l.id] == nil { methods[l.id] = m } } }
    }
}

// MARK: - Advances & loans (GET /payroll/advances?status)

struct AdvancesPanel: View {
    @Environment(AppEnvironment.self) private var env
    @State private var status = "requested"
    @State private var version = 0
    @State private var action: AdvanceAction?
    @State private var creating = false

    struct AdvanceAction: Identifiable { let id = UUID(); let advance: Record; let kind: String }

    var body: some View {
        HStack {
            OptionPicker("Status", $status, options: [("requested", "Waiting"), ("approved", "Approved"), ("paid", "Paid out"), ("settled", "Settled"), ("declined", "Declined"), ("", "All")])
            Spacer()
            if env.session.can("payroll.run") { Button { creating = true } label: { Label("New for someone", systemImage: "plus") } }
        }
        RemoteJSON("/v1/admin/payroll/advances", query: ["status": status.isEmpty ? nil : status], version: version, emptyTitle: "No advances here", isEmpty: { ($0["data"]?.array ?? []).isEmpty }) { j, _ in
            HStack { Text("Open balance across everyone").font(Theme.Font.secondary); Spacer(); MoneyText(kes(j["open_balance"]), emphasis: true) }
            let canRun = j["can_run"]?.bool ?? env.session.can("payroll.run")
            ForEach(j["data"]?.records ?? []) { a in
                SectionCard {
                    HStack(alignment: .top) {
                        VStack(alignment: .leading, spacing: 3) {
                            Text("\(a["user"]?["name"]?.string ?? "—") · \(a.string("kind")?.humanized ?? "")").font(Theme.Font.body.weight(.semibold))
                            Text("Instalment \(kes(a["instalment"])?.formatted() ?? "—") · balance \(kes(a["balance"])?.formatted() ?? "—")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                            if let r = a.string("reason") { Text("“\(r)”").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                            if let n = a.string("decision_note") { Text("Decision: \(n)").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                            let reps = a["repayments"]?.records ?? []
                            if !reps.isEmpty { Text("Repayments: " + reps.map { "\(kes($0["amount"])?.formatted() ?? "") (\($0.string("kind") ?? "") \(Day.format($0.string("on"))))" }.joined(separator: ", ")).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                        }
                        Spacer()
                        VStack(alignment: .trailing) { MoneyText(kes(a["amount"]), emphasis: true); StatusBadge(a.status) }
                    }
                    if canRun {
                        HStack {
                            Spacer()
                            switch a.status {
                            case "requested":
                                Button("Decline") { action = AdvanceAction(advance: a, kind: "decline") }
                                Button("Approve") { action = AdvanceAction(advance: a, kind: "approve") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                            case "approved":
                                Button("Pay out") { action = AdvanceAction(advance: a, kind: "payout") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                            case "paid":
                                Button("Record cash repayment") { action = AdvanceAction(advance: a, kind: "repay") }
                            default: EmptyView()
                            }
                        }
                    }
                }
            }
        }
        .sheet(item: $action) { a in AdvanceActionSheet(advance: a.advance, kind: a.kind) { version += 1 } }
        .sheet(isPresented: $creating) { NewAdvanceSheet { version += 1 } }
    }
}

/// POST /payroll/advances/{id}/approve {instalment, note} | decline {note} | payout {payment_method, payment_reference} | repay {amount, reference}
struct AdvanceActionSheet: View {
    @Environment(AppEnvironment.self) private var env
    let advance: Record
    let kind: String
    let done: () -> Void
    @State private var note = ""
    @State private var instalment = ""
    @State private var method = "mpesa"
    @State private var reference = ""
    @State private var amount = ""

    var body: some View {
        EditSheet(kind.humanized, saveLabel: kind.humanized, destructive: kind == "decline", save: {
            let body: JSONValue
            switch kind {
            case "approve": body = J.obj([("instalment", instalment.isEmpty ? nil : J.typed(instalment)), ("note", J.sOpt(note))])
            case "decline": body = J.obj([("note", .string(note))])
            case "payout": body = J.obj([("payment_method", .string(method)), ("payment_reference", J.sOpt(reference))])
            default: body = J.obj([("amount", J.typed(amount)), ("reference", J.sOpt(reference))])
            }
            let r = try await env.session.client.post("/v1/admin/payroll/advances/\(advance.id)/\(kind)", json: body)
            toastResult(env, r); done()
        }) { e in
            HStack { Text(advance["user"]?["name"]?.string ?? ""); Spacer(); MoneyText(kes(advance["amount"])) }
            switch kind {
            case "approve":
                Field("Instalment per payroll (KES, blank keeps the request)", $instalment, placeholder: advance["instalment"]?.string ?? "", error: e?.errors["instalment"]?.first)
                Field("Note", $note, multiline: true)
            case "decline":
                Field("Why (the person sees this)", $note, error: e?.errors["note"]?.first, multiline: true)
            case "payout":
                OptionPicker("Paid by", $method, options: [("mpesa", "M-Pesa"), ("bank", "Bank"), ("cash", "Cash")])
                Field("Reference", $reference)
            default:
                Field("Amount repaid in cash (KES)", $amount, error: e?.errors["amount"]?.first)
                Field("Reference", $reference)
            }
            Text("This asks you to confirm it is you.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
    }
}

/// POST /payroll/advances {user_id, kind, amount, instalment, reason}
struct NewAdvanceSheet: View {
    @Environment(AppEnvironment.self) private var env
    let done: () -> Void
    @State private var user: Int?
    @State private var kind = "advance"
    @State private var amount = ""
    @State private var instalment = ""
    @State private var reason = ""
    var body: some View {
        EditSheet("Advance or loan for someone", canSave: user != nil && !amount.isEmpty && !reason.isEmpty, save: {
            let r = try await env.session.client.post("/v1/admin/payroll/advances", json: J.obj([("user_id", J.i(user)), ("kind", .string(kind)), ("amount", J.typed(amount)), ("instalment", instalment.isEmpty ? nil : J.typed(instalment)), ("reason", .string(reason))]))
            toastResult(env, r); done()
        }) { e in
            StaffPicker("Person", $user)
            OptionPicker("Kind", $kind, options: [("advance", "Salary advance (one payroll)"), ("loan", "Loan (instalments)")])
            Field("Amount (KES)", $amount, error: e?.errors["amount"]?.first)
            if kind == "loan" { Field("Instalment per payroll (KES)", $instalment, error: e?.errors["instalment"]?.first) }
            Field("Reason", $reason, multiline: true)
        }
    }
}

// MARK: - Returns (GET /payroll/returns?month&kind — step-up)

struct ReturnsPanel: View {
    @State private var month = String(Day.today().prefix(7))
    @State private var kind = "paye"
    var body: some View {
        SectionCard("Statutory returns") {
            Text("A working file per month for KRA, NSSF, SHA and the Housing Levy, built from paid runs. Downloading asks you to confirm it is you.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            HStack(alignment: .bottom) {
                Field("Month (yyyy-mm)", $month)
                OptionPicker("Return", $kind, options: [("paye", "PAYE (P10 working file)"), ("nssf", "NSSF"), ("shif", "SHIF"), ("housing_levy", "Housing Levy")])
                DocumentButton("Download", path: "/v1/admin/payroll/returns", query: ["month": month, "kind": kind], suggestedName: "\(kind)-\(month).csv")
            }
        }
    }
}

// MARK: - Statutory rate tables (GET/POST /payroll/rates, POST /rates/{id}/confirm)

struct RatesPanel: View {
    @Environment(AppEnvironment.self) private var env
    @State private var version = 0
    @State private var adding: String?

    var body: some View {
        Text("Each table is a dated row: a change is a new row from a date, never an edit, so old runs keep the rates they used. Confirm a row once it is checked against KRA, NSSF or SHA.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
        RemoteJSON("/v1/admin/payroll/rates", version: version) { j, reload in
            let rows = j["data"]?.records ?? []
            ForEach(["paye", "nssf", "shif", "housing_levy"], id: \.self) { code in
                SectionCard(rateLabel(code)) {
                    let mine = rows.filter { $0.string("code") == code }.sorted { ($0.string("effective_from") ?? "") > ($1.string("effective_from") ?? "") }
                    if mine.isEmpty { Text("No table yet").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                    ForEach(mine) { r in
                        VStack(alignment: .leading, spacing: 4) {
                            HStack {
                                Text("From \(r.string("effective_from") ?? "")").font(Theme.Font.body.weight(.semibold))
                                if r["reduces_taxable_pay"]?.bool == true { Pill("reduces taxable pay", .info) }
                                Pill(r["is_confirmed"]?.bool == true ? "confirmed" : "unconfirmed", r["is_confirmed"]?.bool == true ? .success : .warning)
                                Spacer()
                                if r["is_confirmed"]?.bool != true, env.session.can("payroll.run") {
                                    AsyncButton("Confirm") { let x = try await env.session.client.post("/v1/admin/payroll/rates/\(r.id)/confirm"); toastResult(env, x); reload() }
                                }
                            }
                            Text(prettyJSON(r["config"])).font(Theme.Font.mono).foregroundStyle(Theme.textSecondary)
                            if let s = r.string("source") { Text("Source: \(s)").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                        }
                        Divider()
                    }
                    if env.session.can("payroll.run") { Button { adding = code } label: { Label("New row from a date", systemImage: "plus") } }
                }
            }
        }
        .sheet(item: Binding(get: { adding.map { RateCode(code: $0) } }, set: { adding = $0?.code })) { c in NewRateSheet(code: c.code) { version += 1 } }
    }
    struct RateCode: Identifiable { let code: String; var id: String { code } }
}

struct NewRateSheet: View {
    @Environment(AppEnvironment.self) private var env
    let code: String
    let done: () -> Void
    @State private var from = Day.today()
    @State private var json = "{\n}"
    @State private var reduces = false
    @State private var source = ""
    @State private var parseError: String?

    var body: some View {
        EditSheet("New \(rateLabel(code)) table", saveLabel: "Save (asks you to confirm it's you)", canSave: parseJSONObject(json) != nil, save: {
            guard let config = parseJSONObject(json) else { throw APIError(status: 422, message: "The figures must be valid JSON.") }
            let r = try await env.session.client.post("/v1/admin/payroll/rates", json: J.obj([("code", .string(code)), ("effective_from", .string(from)), ("config", config), ("reduces_taxable_pay", .bool(reduces)), ("source", J.sOpt(source))]))
            toastResult(env, r); done()
        }) { e in
            DayField("Effective from", $from)
            Field("Figures (JSON)", $json, error: e?.errors["config"]?.first, multiline: true, mono: true)
            if parseJSONObject(json) == nil { FieldError("Not valid JSON yet") }
            ToggleRow("Employee's share reduces taxable pay", $reduces)
            Field("Source (gazette, circular, URL)", $source)
        }
        .task {
            if let latest = (try? await env.session.client.json("/v1/admin/payroll/rates"))?["data"]?.records.filter({ $0.string("code") == code }).sorted(by: { ($0.string("effective_from") ?? "") > ($1.string("effective_from") ?? "") }).first {
                json = prettyJSON(latest["config"]); reduces = latest["reduces_taxable_pay"]?.bool ?? false
            }
        }
    }
}
