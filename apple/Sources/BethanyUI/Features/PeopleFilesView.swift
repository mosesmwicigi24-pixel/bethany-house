import SwiftUI
import BethanyKit

/// Employee files (People PR 2): the list, and one person's file with the
/// profile, the sensitive card (hr.view_sensitive + step-up), documents,
/// pay structures, leave, training, signing requests and the timesheet.
struct PeopleListView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var q = ""
    @State private var department = ""
    @State private var page = 1
    @State private var showExpiring = false

    var body: some View {
        Page("Employee files", subtitle: "Everyone on the payroll and their paperwork") {
            HStack {
                TextField("Search by name, email or phone", text: $q).textFieldStyle(.roundedBorder).onSubmit { page = 1 }
                Button("Expiring documents") { showExpiring.toggle() }
            }
            if showExpiring {
                RemoteJSON("/v1/admin/people/documents/expiring", query: ["days": "60"], emptyTitle: "Nothing expires in the next 60 days", isEmpty: { ($0["data"]?.array ?? []).isEmpty }) { j, _ in
                    SectionCard("Expiring within 60 days") {
                        ForEach(j["data"]?.records ?? []) { d in
                            HStack {
                                Text(d["user"]?["name"]?.string ?? "—").font(Theme.Font.body)
                                Text("\(d.string("title") ?? "") (\(d.string("kind")?.humanized ?? ""))").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                                Spacer()
                                Text(Day.format(d.string("expires_at"))).font(Theme.Font.caption).foregroundStyle(d["expired"]?.bool == true ? Theme.Palette.danger : Theme.Palette.warning)
                                if let uid = d["user"]?["id"]?.int { Button("Open") { env.navigator.push(.people(id: uid)) }.font(Theme.Font.secondary) }
                            }
                        }
                    }
                }
            }
            RemoteJSON("/v1/admin/people", query: ["q": q.isEmpty ? nil : q, "department": department.isEmpty ? nil : department, "page": String(page), "per_page": "25"], emptyTitle: "No people match", isEmpty: { ($0["data"]?.array ?? []).isEmpty }) { j, _ in
                let departments = j["departments"]?.array?.compactMap(\.string) ?? []
                if !departments.isEmpty {
                    OptionPicker("Department", $department, options: [("", "All departments")] + departments.map { ($0, $0) })
                }
                ForEach(j["data"]?.records ?? []) { p in
                    Button { env.navigator.push(.people(id: p.int("id") ?? 0)) } label: {
                        HStack(spacing: Theme.Space.m) {
                            Avatar(name: p.string("name") ?? "?", size: 36)
                            VStack(alignment: .leading, spacing: 2) {
                                Text(p.string("name") ?? "—").font(Theme.Font.body.weight(.semibold))
                                Text([p.string("job_title"), p.string("department"), p.string("employment_type")?.humanized].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                Text((p["outlets"]?.array?.compactMap(\.string) ?? []).joined(separator: ", ")).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                            }
                            Spacer()
                            VStack(alignment: .trailing, spacing: 2) {
                                StatusBadge(p.status)
                                Text("\(p.int("documents_count") ?? 0) docs").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                if (p.int("expiring_count") ?? 0) > 0 { Pill("\(p.int("expiring_count") ?? 0) expiring", .warning) }
                            }
                        }
                        .padding(Theme.Space.m)
                        .background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                }
                HStack {
                    Button("Previous") { page = max(1, page - 1) }.disabled((j["current_page"]?.int ?? 1) <= 1)
                    Text("Page \(j["current_page"]?.int ?? 1) of \(j["last_page"]?.int ?? 1) · \(j["total"]?.int ?? 0) people").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                    Button("Next") { page += 1 }.disabled((j["current_page"]?.int ?? 1) >= (j["last_page"]?.int ?? 1))
                }
            }
        }
    }
}

// MARK: - One person (GET /people/{id})

struct PersonFileView: View {
    @Environment(AppEnvironment.self) private var env
    let personId: Int
    enum Tab: Hashable { case file, documents, pay, leave, training, signing, timesheet }
    @State private var tab: Tab = .file
    @State private var version = 0
    @State private var editing = false

    var body: some View {
        RemoteJSON("/v1/admin/people/\(personId)", version: version) { j, reload in
            let p = j["person"] ?? .null
            let can = p["can"] ?? .null
            Page(p["name"]?.string ?? "Employee file", subtitle: ([p["profile"]?["job_title"]?.string, p["profile"]?["department"]?.string].compactMap { $0 }).joined(separator: " · ")) {
                HStack(spacing: Theme.Space.m) {
                    Avatar(name: p["name"]?.string ?? "?", size: 56)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(p["name"]?.string ?? "—").font(Theme.Font.display(20))
                        Text([p["email"]?.string, p["phone"]?.string].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                        HStack { ForEach(p["roles"]?.array?.compactMap(\.string) ?? [], id: \.self) { Pill($0, .info) }; StatusBadge(p["status"]?.string) }
                    }
                    Spacer()
                    Button { env.navigator.go(.comms()) } label: { Label("Message", systemImage: "bubble.left") }
                }
                Tabs(selection: $tab, items: [(.file, "File"), (.documents, "Documents"), (.pay, "Pay"), (.leave, "Leave"), (.training, "Training"), (.signing, "Signing"), (.timesheet, "Hours")])
                switch tab {
                case .file: fileTab(p, can: can)
                case .documents: PersonDocumentsPanel(personId: personId, documents: p["documents"]?.records ?? [], canEdit: can["edit"]?.bool ?? false, reload: reload)
                case .pay: PersonPayPanel(personId: personId, canView: can["view_sensitive"]?.bool ?? env.session.can("hr.view_sensitive"))
                case .leave: PersonLeavePanel(personId: personId)
                case .training: trainingTab(p)
                case .signing: PersonSigningPanel(personId: personId, documents: p["documents"]?.records ?? [], canEdit: can["edit"]?.bool ?? false)
                case .timesheet: PersonTimesheetPanel(personId: personId)
                }
            }
            .sheet(isPresented: $editing) { PersonProfileSheet(personId: personId, profile: p["profile"] ?? .null) { version += 1 } }
        }
    }

    @ViewBuilder
    private func fileTab(_ p: JSONValue, can: JSONValue) -> some View {
        let pr = p["profile"] ?? .null
        SectionCard("Employment") {
            KeyValueGrid(pairs: [
                ("Job title", pr["job_title"]?.string ?? "—"), ("Department", pr["department"]?.string ?? "—"),
                ("Employment", pr["employment_type"]?.string?.humanized ?? "—"), ("Hired", pr["hire_date"]?.string.map { Day.format($0, "d MMM yyyy") } ?? "—"),
                ("Ends", pr["end_date"]?.string.map { Day.format($0, "d MMM yyyy") } ?? "—"), ("Date of birth", pr["date_of_birth"]?.string.map { Day.format($0, "d MMM yyyy") } ?? "—"),
                ("Outlets", (p["outlets"]?.array ?? []).compactMap { $0["name"]?.string }.joined(separator: ", ")), ("Reports to", p["reports_to"]?["name"]?.string ?? "—"),
                ("Pay method", pr["pay_method"]?.string?.humanized ?? "—"), ("Bank", [pr["bank_name"]?.string, pr["bank_branch"]?.string, pr["bank_account_name"]?.string].compactMap { $0 }.joined(separator: " · ")),
            ])
            if can["edit"]?.bool == true { Button("Edit file…") { editing = true } }
        }
        SectionCard("Emergency contacts") {
            KeyValueGrid(pairs: [
                ("Contact 1", [pr["emergency_contact_name"]?.string, pr["emergency_contact_phone"]?.string, pr["emergency_contact_relationship"]?.string].compactMap { $0 }.joined(separator: " · ")),
                ("Contact 2", [pr["emergency_contact_2_name"]?.string, pr["emergency_contact_2_phone"]?.string, pr["emergency_contact_2_relationship"]?.string].compactMap { $0 }.joined(separator: " · ")),
            ])
        }
        if let n = pr["notes"]?.string, !n.isEmpty { SectionCard("Notes") { Text(n).font(Theme.Font.secondary) } }
        if can["view_sensitive"]?.bool == true || env.session.can("hr.view_sensitive") {
            SensitiveCard(personId: personId, masked: p["masked"] ?? .null, canEdit: can["edit_sensitive"]?.bool ?? false)
        }
        let recs = p["recognitions"]?.records ?? []
        if !recs.isEmpty || (p["recognition_points"]?.int ?? 0) > 0 {
            SectionCard("Recognition · \(p["recognition_points"]?.int ?? 0) points") {
                ForEach(recs) { r in Text("\(r.string("by") ?? "Someone"): \(r.string("body") ?? "")\(r.int("points").map { " (+\($0))" } ?? "")").font(Theme.Font.secondary) }
            }
        }
    }

    @ViewBuilder
    private func trainingTab(_ p: JSONValue) -> some View {
        let courses = p["courses"]?.records ?? []
        if courses.isEmpty { EmptyState("No courses assigned", symbol: "graduationcap") }
        ForEach(courses) { c in
            SupportListRow(c.string("title") ?? "", subtitle: "\(c.int("attempts") ?? 0) attempts\(c["best_score"]?.string.map { " · best \($0)%" } ?? "")\(c.string("completed_at").map { " · done \(Day.format($0))" } ?? "")") {
                HStack { if c["required"]?.bool == true { Pill("required", .info) }; Pill(c["passed"]?.bool == true ? "passed" : "not yet", c["passed"]?.bool == true ? .success : .amber) }
            }
        }
    }
}

/// The identifiers and pay details: masked until revealed (GET /sensitive,
/// which the server puts behind step-up); edit with PUT /sensitive.
struct SensitiveCard: View {
    @Environment(AppEnvironment.self) private var env
    let personId: Int
    let masked: JSONValue
    let canEdit: Bool
    @State private var full: JSONValue?
    @State private var editing = false
    static let keys: [(String, String)] = [("national_id_no", "National ID"), ("kra_pin", "KRA PIN"), ("nssf_no", "NSSF"), ("shif_no", "SHIF"), ("mpesa_phone", "M-Pesa phone"), ("bank_account_no", "Bank account")]

    var body: some View {
        SectionCard("Identifiers & pay details") {
            let src = full ?? masked
            KeyValueGrid(pairs: Self.keys.map { ($0.1, src[$0.0]?.string ?? "—") })
            HStack {
                if full == nil {
                    AsyncButton("Reveal (asks you to confirm it's you)") { full = try await env.session.client.json("/v1/admin/people/\(personId)/sensitive")["sensitive"] }
                } else {
                    Button("Hide") { full = nil }
                    if canEdit { Button("Edit…") { editing = true } }
                }
            }
        }
        .sheet(isPresented: $editing) {
            SensitiveEditSheet(personId: personId, values: full ?? .null) { full = $0 }
        }
    }
}

struct SensitiveEditSheet: View {
    @Environment(AppEnvironment.self) private var env
    let personId: Int
    let saved: (JSONValue) -> Void
    @State private var values: [String: String]
    init(personId: Int, values: JSONValue, saved: @escaping (JSONValue) -> Void) {
        self.personId = personId; self.saved = saved
        var v: [String: String] = [:]
        for (k, _) in SensitiveCard.keys { v[k] = values[k]?.string ?? "" }
        _values = State(initialValue: v)
    }
    var body: some View {
        EditSheet("Edit identifiers", save: {
            let r = try await env.session.client.put("/v1/admin/people/\(personId)/sensitive", json: J.obj(SensitiveCard.keys.map { ($0.0, J.s(values[$0.0]) as JSONValue?) }))
            toastResult(env, r)
            if let s = r.json?["sensitive"] { saved(s) }
        }) { e in
            ForEach(SensitiveCard.keys, id: \.0) { k in
                Field(k.1, Binding(get: { values[k.0] ?? "" }, set: { values[k.0] = $0 }), error: e?.errors[k.0]?.first)
            }
        }
    }
}

/// PUT /people/{id} — the profile fields (hr.edit).
struct PersonProfileSheet: View {
    @Environment(AppEnvironment.self) private var env
    let personId: Int
    let saved: () -> Void
    @State private var f: [String: String]
    @State private var payGroup: Int?
    @State private var groups: [Record] = []
    static let text: [(String, String)] = [("job_title", "Job title"), ("department", "Department"), ("hire_date", "Hire date (yyyy-mm-dd)"), ("end_date", "End date"), ("date_of_birth", "Date of birth"),
        ("emergency_contact_name", "Emergency contact"), ("emergency_contact_phone", "Phone"), ("emergency_contact_relationship", "Relationship"),
        ("emergency_contact_2_name", "Second contact"), ("emergency_contact_2_phone", "Phone"), ("emergency_contact_2_relationship", "Relationship"),
        ("bank_name", "Bank"), ("bank_branch", "Branch"), ("bank_account_name", "Account name"), ("notes", "Notes")]

    init(personId: Int, profile: JSONValue, saved: @escaping () -> Void) {
        self.personId = personId; self.saved = saved
        var v: [String: String] = [:]
        for (k, _) in Self.text { v[k] = profile[k]?.string ?? "" }
        v["employment_type"] = profile["employment_type"]?.string ?? "permanent"
        v["pay_method"] = profile["pay_method"]?.string ?? ""
        _f = State(initialValue: v)
        _payGroup = State(initialValue: profile["pay_group_id"]?.int)
    }

    var body: some View {
        EditSheet("Edit employee file", save: {
            var pairs: [(String, JSONValue?)] = Self.text.map { ($0.0, J.s(f[$0.0]) as JSONValue?) }
            pairs.append(("employment_type", J.s(f["employment_type"])))
            pairs.append(("pay_method", J.s(f["pay_method"])))
            pairs.append(("pay_group_id", J.i(payGroup)))
            let r = try await env.session.client.put("/v1/admin/people/\(personId)", json: J.obj(pairs))
            toastResult(env, r); saved()
        }) { e in
            OptionPicker("Employment type", bind("employment_type"), options: [("permanent", "Permanent"), ("contract", "Contract"), ("casual", "Casual"), ("intern", "Intern")])
            OptionPicker("Pay method", bind("pay_method"), options: [("", "—"), ("mpesa", "M-Pesa"), ("bank", "Bank"), ("cash", "Cash")])
            VStack(alignment: .leading, spacing: 4) {
                Text("Pay group").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                Picker("", selection: $payGroup) { Text("—").tag(Int?.none); ForEach(groups) { g in Text(g.string("name") ?? "").tag(g.int("id")) } }.labelsHidden().pickerStyle(.menu)
            }
            ForEach(Self.text, id: \.0) { k in Field(k.1, bind(k.0), error: e?.errors[k.0]?.first, multiline: k.0 == "notes") }
        }
        .task { groups = (try? await env.session.client.json("/v1/admin/pay-groups"))?["data"]?.records ?? [] }
    }

    private func bind(_ key: String) -> Binding<String> { Binding(get: { f[key] ?? "" }, set: { f[key] = $0 }) }
}

/// Documents: list, open (signed link), upload (multipart), remove.
struct PersonDocumentsPanel: View {
    @Environment(AppEnvironment.self) private var env
    let personId: Int
    let documents: [Record]
    let canEdit: Bool
    let reload: () -> Void
    @State private var picked: LocalFile?
    @State private var kind = "other"
    @State private var title = ""
    @State private var expires = ""
    @State private var share: URL?
    static let kinds = [("national_id", "National ID"), ("kra_pin", "KRA PIN certificate"), ("nssf", "NSSF card"), ("shif", "SHIF card"), ("contract", "Contract"), ("certificate", "Certificate"), ("passport_photo", "Passport photo"), ("medical", "Medical"), ("signed", "Signed record"), ("other", "Other")]

    var body: some View {
        if documents.isEmpty { EmptyState("No documents on file", symbol: "doc") }
        ForEach(documents) { d in
            SupportListRow(d.string("title") ?? "", subtitle: "\(d.string("kind")?.humanized ?? "") · \(d.string("original_name") ?? "")\(d.string("expires_at").map { " · expires \(Day.format($0))" } ?? "")") {
                HStack {
                    if d["expired"]?.bool == true { Pill("expired", .danger) }
                    AsyncButton { let data = try await SignedFiles.fetch(env, "/api/v1/admin/people/\(personId)/documents/\(d.id)"); presentFile(env, data: data, name: d.string("original_name") ?? "document", share: $share) } label: { Image(systemName: "arrow.down.doc") }
                    if canEdit {
                        ConfirmButton("Remove document", message: "The file is deleted from the employee's record.", destructive: true, action: {
                            do { let r = try await env.session.client.delete("/v1/admin/people/\(personId)/documents/\(d.id)"); toastResult(env, r); reload() } catch { env.toasts.error(error) }
                        }) { Image(systemName: "trash") }
                    }
                }
            }
        }
        if canEdit {
            SectionCard("Add a document") {
                HStack { AttachFileButton(label: picked?.name ?? "Choose file") { picked = $0; if title.isEmpty { title = $0.name } } }
                OptionPicker("Kind", $kind, options: Self.kinds)
                Field("Title", $title)
                Field("Expires (yyyy-mm-dd, optional)", $expires)
                AsyncButton("Upload") {
                    guard let f = picked else { return }
                    var parts = [APIClient.MultipartPart(name: "kind", value: kind), APIClient.MultipartPart(name: "title", value: title), f.part]
                    if !expires.isEmpty { parts.append(APIClient.MultipartPart(name: "expires_at", value: expires)) }
                    let r = try await env.session.client.upload("/v1/admin/people/\(personId)/documents", parts: parts)
                    toastResult(env, r); picked = nil; title = ""; expires = ""; reload()
                }
                .buttonStyle(.borderedProminent).tint(Theme.Palette.brand).disabled(picked == nil || title.isEmpty)
            }
        }
        EmptyView().shareFile($share)
    }
}

/// GET/POST /people/{id}/pay — pay structures (hr.view_sensitive + step-up). Figures are KES as stored.
struct PersonPayPanel: View {
    @Environment(AppEnvironment.self) private var env
    let personId: Int
    let canView: Bool
    @State private var loaded: JSONValue?
    @State private var adding = false
    @State private var version = 0

    var body: some View {
        if !canView {
            EmptyState("Pay details need hr.view_sensitive", symbol: "lock")
        } else if loaded == nil {
            AsyncButton("Show pay structures (asks you to confirm it's you)") { loaded = try await env.session.client.json("/v1/admin/people/\(personId)/pay") }
        } else if let j = loaded {
            if (j["data"]?.array ?? []).isEmpty { EmptyState("No pay structure yet", symbol: "banknote") }
            ForEach(j["data"]?.records ?? []) { s in
                SectionCard("From \(Day.format(s.string("effective_from"), "d MMM yyyy")) · \(s.string("kind")?.humanized ?? "")") {
                    HStack { Text("Base"); Spacer(); MoneyText(s.money("base_amount", currency: s.string("currency_code") ?? "KES"), emphasis: true) }
                    ForEach(Array((s["allowances"]?.array ?? []).enumerated()), id: \.offset) { _, a in
                        HStack { Text("\(a["name"]?.string ?? "Allowance")\(a["taxable"]?.bool == false ? " (non-taxable)" : "")").font(Theme.Font.secondary); Spacer(); MoneyText(a["amount"].flatMap { Money(json: $0, currency: "KES") }) }
                    }
                    if let n = s.string("notes"), !n.isEmpty { Text(n).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                }
            }
            if j["can_edit"]?.bool == true { Button { adding = true } label: { Label("New pay structure", systemImage: "plus") } }
        }
        EmptyView().sheet(isPresented: $adding) { PayStructureSheet(personId: personId) { Task { loaded = try? await env.session.client.json("/v1/admin/people/\(personId)/pay") } } }
    }
}

struct PayStructureSheet: View {
    @Environment(AppEnvironment.self) private var env
    let personId: Int
    let saved: () -> Void
    @State private var from = Day.today()
    @State private var kind = "monthly"
    @State private var base = ""
    @State private var notes = ""
    @State private var allowances: [(String, String, Bool)] = []

    var body: some View {
        EditSheet("New pay structure", canSave: !base.isEmpty, save: {
            let r = try await env.session.client.post("/v1/admin/people/\(personId)/pay", json: J.obj([
                ("effective_from", .string(from)), ("kind", .string(kind)), ("base_amount", J.typed(base)), ("notes", J.sOpt(notes)),
                ("allowances", .array(allowances.filter { !$0.0.isEmpty }.map { J.obj([("name", .string($0.0)), ("amount", J.typed($0.1)), ("taxable", .bool($0.2))]) })),
            ]))
            toastResult(env, r); saved()
        }) { e in
            Text("A change is a new structure from a date; old payroll keeps what it used.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            DayField("Effective from", $from)
            OptionPicker("Kind", $kind, options: [("monthly", "Monthly salary"), ("daily", "Daily rate"), ("hourly", "Hourly rate")])
            Field("Base amount (KES)", $base, error: e?.errors["base_amount"]?.first ?? e?.errors["effective_from"]?.first)
            ForEach(allowances.indices, id: \.self) { i in
                HStack(alignment: .bottom) {
                    Field("Allowance", Binding(get: { allowances[i].0 }, set: { allowances[i].0 = $0 }))
                    Field("Amount", Binding(get: { allowances[i].1 }, set: { allowances[i].1 = $0 }))
                    Toggle("Taxable", isOn: Binding(get: { allowances[i].2 }, set: { allowances[i].2 = $0 })).toggleStyle(.switch).font(Theme.Font.caption)
                }
            }
            Button { allowances.append(("", "", true)) } label: { Label("Add allowance", systemImage: "plus") }.disabled(allowances.count >= 10)
            Field("Notes", $notes, multiline: true)
        }
    }
}

/// GET /people/{id}/leave — balances, requests, adjustments; record / adjust.
struct PersonLeavePanel: View {
    @Environment(AppEnvironment.self) private var env
    let personId: Int
    @State private var version = 0
    @State private var recording = false
    @State private var adjusting = false

    var body: some View {
        RemoteJSON("/v1/admin/people/\(personId)/leave", version: version) { j, _ in
            SectionCard("Balances \(j["year"]?.int ?? Day.thisYear())") {
                ForEach(j["balances"]?.records ?? []) { b in
                    HStack {
                        Text(b["type"]?["name"]?.string ?? "").font(Theme.Font.body)
                        Spacer()
                        Text("\(b["available"]?.string ?? "∞") left · \(b["used"]?.string ?? "0") used · \(b["requested"]?.string ?? "0") requested · \(b["entitled"]?.string ?? "0") entitled").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    }
                }
                HStack {
                    if j["can_record"]?.bool == true { Button("Record leave") { recording = true } }
                    if j["can_adjust"]?.bool == true { Button("Adjust balance") { adjusting = true } }
                }
            }
            ForEach(j["requests"]?.records ?? []) { r in SupportListRow(leaveSummary(r), subtitle: r.string("reason")) { StatusBadge(r.status) } }
            let adj = j["adjustments"]?.records ?? []
            if !adj.isEmpty { SectionCard("Adjustments") { ForEach(adj) { a in Text("\(a.string("type") ?? ""): \(a["days"]?.string ?? "") days — \(a.string("reason") ?? "")").font(Theme.Font.secondary) } } }
        }
        .sheet(isPresented: $recording) { RecordLeaveSheet(userId: personId) { version += 1 } }
        .sheet(isPresented: $adjusting) { LeaveAdjustSheet(personId: personId) { version += 1 } }
    }
}

/// POST /leave/adjustments {user_id, leave_type_id, year, days, reason}
struct LeaveAdjustSheet: View {
    @Environment(AppEnvironment.self) private var env
    let personId: Int
    let saved: () -> Void
    @State private var types: [Record] = []
    @State private var typeId: Int?
    @State private var year = String(Day.thisYear())
    @State private var days = ""
    @State private var reason = ""
    var body: some View {
        EditSheet("Adjust leave balance", canSave: typeId != nil && !days.isEmpty && !reason.isEmpty, save: {
            let r = try await env.session.client.post("/v1/admin/leave/adjustments", json: J.obj([("user_id", J.i(personId)), ("leave_type_id", J.i(typeId)), ("year", J.typed(year)), ("days", J.typed(days)), ("reason", .string(reason))]))
            toastResult(env, r); saved()
        }) { e in
            Picker("Type", selection: $typeId) { Text("Choose…").tag(Int?.none); ForEach(types) { t in Text(t.string("name") ?? "").tag(t.int("id")) } }
            Field("Year", $year)
            Field("Days (negative to deduct)", $days, error: e?.errors["days"]?.first)
            Field("Reason", $reason, multiline: true)
        }
        .task { types = (try? await env.session.client.json("/v1/admin/leave/types"))?["data"]?.records ?? [] }
    }
}

/// GET/POST/DELETE /people/{id}/signing — ask the person to sign a document, a handbook article or text.
struct PersonSigningPanel: View {
    @Environment(AppEnvironment.self) private var env
    let personId: Int
    let documents: [Record]
    let canEdit: Bool
    @State private var version = 0
    @State private var asking = false

    var body: some View {
        if canEdit { HStack { Spacer(); Button { asking = true } label: { Label("Ask for a signature", systemImage: "signature") } } }
        RemoteJSON("/v1/admin/people/\(personId)/signing", version: version, emptyTitle: "Nothing to sign", isEmpty: { ($0["data"]?.array ?? []).isEmpty }) { j, reload in
            ForEach(j["data"]?.records ?? []) { s in
                SupportListRow(s.string("title") ?? "", subtitle: "\(s.string("kind")?.humanized ?? "") · asked by \(s.string("requested_by") ?? "—")\(s.string("due_at").map { " · due \(Day.format($0))" } ?? "")\(s.string("signed_at").map { " · signed \(Day.dateTime($0))" } ?? "")") {
                    HStack {
                        StatusBadge(s.status)
                        if canEdit, s.status == "pending" {
                            AsyncButton(role: .destructive) { let r = try await env.session.client.delete("/v1/admin/people/\(personId)/signing/\(s.id)"); toastResult(env, r); reload() } label: { Image(systemName: "xmark.circle") }
                        }
                    }
                }
            }
        }
        .sheet(isPresented: $asking) { SigningRequestSheet(personId: personId, documents: documents) { version += 1 } }
    }
}

struct SigningRequestSheet: View {
    @Environment(AppEnvironment.self) private var env
    let personId: Int
    let documents: [Record]
    let saved: () -> Void
    @State private var kind = "text"
    @State private var docId: Int?
    @State private var articleId: Int?
    @State private var articles: [Record] = []
    @State private var title = ""
    @State private var body_ = ""
    @State private var due = ""

    var body: some View {
        EditSheet("Ask for a signature", save: {
            let r = try await env.session.client.post("/v1/admin/people/\(personId)/signing", json: J.obj([
                ("kind", .string(kind)), ("staff_document_id", kind == "document" ? J.i(docId) : nil), ("article_id", kind == "article" ? J.i(articleId) : nil),
                ("title", J.sOpt(title)), ("body", kind == "text" ? J.sOpt(body_) : nil), ("due_at", J.sOpt(due)),
            ]))
            toastResult(env, r); saved()
        }) { e in
            OptionPicker("What to sign", $kind, options: [("text", "Words typed here"), ("document", "One of their documents"), ("article", "A handbook article")])
            if kind == "document" {
                Picker("Document", selection: $docId) { Text("Choose…").tag(Int?.none); ForEach(documents) { d in Text(d.string("title") ?? "").tag(d.int("id")) } }
            } else if kind == "article" {
                Picker("Article", selection: $articleId) { Text("Choose…").tag(Int?.none); ForEach(articles) { a in Text(a.string("title") ?? "").tag(a.int("id")) } }
            }
            Field("Title", $title, error: e?.errors["title"]?.first)
            if kind == "text" { Field("Words to sign", $body_, multiline: true) }
            Field("Due (yyyy-mm-dd, optional)", $due)
        }
        .task { articles = (try? await env.session.client.json("/v1/admin/handbook"))?["data"]?.records ?? [] }
    }
}

/// GET /timesheets/person/{id} — the current period's sheet, read-only here.
struct PersonTimesheetPanel: View {
    let personId: Int
    var body: some View {
        RemoteJSON("/v1/admin/timesheets/person/\(personId)") { j, _ in
            Text("Period \(Day.format(j["period"]?["starts_on"]?.string)) – \(Day.format(j["period"]?["ends_on"]?.string))").font(Theme.Font.sectionTitle)
            TimesheetBody(sheet: j).frame(minHeight: 300)
        }
    }
}
