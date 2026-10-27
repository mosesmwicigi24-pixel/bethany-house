import SwiftUI
import BethanyKit

/// Checklists & forms, the manager's side (People C4): today's overview per
/// outlet, templates (create, edit, retire) and the submissions log.
struct FormsManageView: View {
    @Environment(AppEnvironment.self) private var env
    enum Tab: Hashable { case overview, templates, submissions }
    @State private var tab: Tab = .overview
    @State private var date = Day.today()
    @State private var version = 0
    @State private var editing: FormTemplateDraft?
    @State private var openSubmission: IDRef?

    var body: some View {
        Page("Checklists", subtitle: "Daily checklists and reports across the outlets") {
            Tabs(selection: $tab, items: [(.overview, "Today"), (.templates, "Templates"), (.submissions, "Submissions")])
            switch tab {
            case .overview: overview
            case .templates: templates
            case .submissions: FormSubmissionsList(open: $openSubmission)
            }
        }
        .sheet(item: $editing) { d in FormTemplateSheet(draft: d) { version += 1 } }
        .sheet(item: $openSubmission) { ref in FormSubmissionSheet(id: ref.id) }
    }

    private var overview: some View {
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            HStack { DayField("Day", $date); Spacer() }
            RemoteJSON("/v1/admin/forms/overview", query: ["date": date], version: version, emptyTitle: "No outlets with forms", isEmpty: { ($0["outlets"]?.array ?? []).isEmpty }) { j, _ in
                ForEach(j["outlets"]?.records ?? []) { o in
                    SectionCard(o["outlet"]?["name"]?.string ?? "Outlet") {
                        let s = o["summary"] ?? .null
                        HStack(spacing: 6) {
                            Pill("\(s["done"]?.int ?? 0) done", .success)
                            Pill("\(s["late"]?.int ?? 0) late", .warning)
                            Pill("\(s["missed"]?.int ?? 0) missed", .danger)
                            Pill("\(s["pending"]?.int ?? 0) pending", .amber)
                            if (s["skipped"]?.int ?? 0) > 0 { Pill("\(s["skipped"]?.int ?? 0) skipped required", .pink) }
                            Pill("\(s["reports"]?.int ?? 0) reports", .info)
                        }
                        ForEach(o["checklists"]?.records ?? []) { c in
                            HStack {
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(c["template"]?["name"]?.string ?? "Checklist").font(Theme.Font.body)
                                    Text("due \(c["template"]?["due_time"]?.string ?? "—")\(c["submission"]?["user"]?["name"]?.string.map { " · by \($0)" } ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                }
                                Spacer()
                                StatusBadge(c["state"]?.string, tone: stateTone(c["state"]?.string))
                                if let sid = c["submission"]?["id"]?.int { Button("View") { openSubmission = IDRef(sid) }.font(Theme.Font.secondary) }
                            }
                            .padding(.vertical, 2)
                        }
                        ForEach(o["reports"]?.records ?? []) { r in
                            HStack {
                                Text(r["template"]?["name"]?.string ?? "Report").font(Theme.Font.body)
                                Text(r["user"]?["name"]?.string ?? "").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                Spacer()
                                StatusBadge(r.status)
                                Button("View") { openSubmission = IDRef(r.int("id") ?? 0) }.font(Theme.Font.secondary)
                            }
                        }
                    }
                }
            }
        }
    }

    private func stateTone(_ s: String?) -> StatusTone {
        switch s { case "submitted": return .success; case "late": return .warning; case "missed": return .danger; case "overdue": return .danger; case "due": return .amber; default: return .neutral }
    }

    private var templates: some View {
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            HStack { Spacer(); Button { editing = FormTemplateDraft() } label: { Label("New form", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
            RemoteJSON("/v1/admin/forms/templates", version: version, emptyTitle: "No forms yet", isEmpty: { ($0["data"]?.array ?? []).isEmpty }) { j, reload in
                ForEach(j["data"]?.records ?? []) { t in
                    SupportListRow(t.string("name") ?? "Form", subtitle: "\(t.string("kind")?.humanized ?? "") · \(t["outlet"]?["name"]?.string ?? "Every outlet") · \(t.string("schedule") == "daily" ? "daily\(t.string("due_time").map { " by \($0)" } ?? "")" : "on demand") · \(t["items"]?.array?.count ?? 0) items") {
                        HStack {
                            if t["active"]?.bool == false { Pill("retired", .neutral) }
                            if t["requires_signature"]?.bool == true { Pill("signed", .info) }
                            Button("Edit") { editing = FormTemplateDraft(record: t) }
                            if t["active"]?.bool != false {
                                ConfirmButton("Retire form", message: "It stops being due; past submissions stay.", destructive: true, action: {
                                    do { let r = try await env.session.client.delete("/v1/admin/forms/templates/\(t.id)"); toastResult(env, r); reload() } catch { env.toasts.error(error) }
                                }) { Image(systemName: "archivebox") }
                            }
                        }
                    }
                }
            }
        }
    }
}

struct FormTemplateDraft: Identifiable {
    struct Item: Identifiable { let id = UUID(); var key: String; var label: String; var type: String; var required: Bool }
    var id: Int?
    var name = ""
    var kind = "checklist"
    var outletId: Int?
    var schedule = "daily"
    var weekdays: Set<Int> = [1, 2, 3, 4, 5, 6]
    var dueTime = "09:00"
    var requiresSignature = false
    var active = true
    var items: [Item] = [Item(key: "item_1", label: "", type: "check", required: true)]

    init() {}
    init(record r: Record) {
        id = r.int("id")
        name = r.string("name") ?? ""
        kind = r.string("kind") ?? "checklist"
        outletId = r["outlet"]?["id"]?.int
        schedule = r.string("schedule") ?? "none"
        weekdays = Set(r["weekdays"]?.array?.compactMap(\.int) ?? [1, 2, 3, 4, 5, 6])
        dueTime = r.string("due_time") ?? ""
        requiresSignature = r["requires_signature"]?.bool ?? false
        active = r["active"]?.bool ?? true
        items = (r["items"]?.array ?? []).map { Item(key: $0["key"]?.string ?? "", label: $0["label"]?.string ?? "", type: $0["type"]?.string ?? "check", required: $0["required"]?.bool ?? false) }
    }
}

/// POST/PUT /forms/templates — {name, kind, outlet_id, schedule, weekdays, due_time, requires_signature, active, items[]}
struct FormTemplateSheet: View {
    @Environment(AppEnvironment.self) private var env
    @State var draft: FormTemplateDraft
    let saved: () -> Void

    var body: some View {
        EditSheet(draft.id == nil ? "New form" : "Edit form", canSave: !draft.name.isEmpty && !draft.items.isEmpty, save: {
            let body = J.obj([
                ("name", .string(draft.name)),
                ("kind", .string(draft.kind)),
                ("outlet_id", J.i(draft.outletId)),
                ("schedule", .string(draft.schedule)),
                ("weekdays", draft.schedule == "daily" ? J.ints(Array(draft.weekdays).sorted()) : .null),
                ("due_time", draft.schedule == "daily" ? J.s(draft.dueTime) : .null),
                ("requires_signature", .bool(draft.requiresSignature)),
                ("active", .bool(draft.active)),
                ("items", .array(draft.items.map { J.obj([("key", .string($0.key)), ("label", .string($0.label)), ("type", .string($0.type)), ("required", .bool($0.required))]) })),
            ])
            let response: APIClient.Response
            if let id = draft.id { response = try await env.session.client.put("/v1/admin/forms/templates/\(id)", json: body) }
            else { response = try await env.session.client.post("/v1/admin/forms/templates", json: body) }
            toastResult(env, response)
            saved()
        }) { e in
            Field("Name", $draft.name, error: e?.errors["name"]?.first)
            OptionPicker("Kind", $draft.kind, options: [("checklist", "Checklist (due on a schedule)"), ("report", "Report (filled when needed)")])
            OutletChoice("Outlet", $draft.outletId, allowAll: true)
            OptionPicker("Schedule", $draft.schedule, options: [("daily", "Daily"), ("none", "No schedule")])
            if draft.schedule == "daily" {
                HStack {
                    ForEach(1...7, id: \.self) { d in
                        let on = draft.weekdays.contains(d)
                        Button(["Mo", "Tu", "We", "Th", "Fr", "Sa", "Su"][d - 1]) { if on { draft.weekdays.remove(d) } else { draft.weekdays.insert(d) } }
                            .buttonStyle(.bordered).tint(on ? Theme.Palette.brand : Theme.Palette.neutral)
                    }
                }
                Field("Due by (HH:mm)", $draft.dueTime, placeholder: "09:00", error: e?.errors["due_time"]?.first)
            }
            ToggleRow("Requires a signature", $draft.requiresSignature)
            ToggleRow("Active", $draft.active)
            Text("Items").font(Theme.Font.sectionTitle)
            ForEach($draft.items) { $item in
                HStack(alignment: .bottom, spacing: 6) {
                    Field("Label", $item.label)
                    OptionPicker("Type", $item.type, options: [("check", "Check"), ("text", "Text"), ("number", "Number"), ("photo", "Photo")]).frame(width: 110)
                    Toggle("Req.", isOn: $item.required).toggleStyle(.switch).font(Theme.Font.caption)
                    Button(role: .destructive) { draft.items.removeAll { $0.id == item.id } } label: { Image(systemName: "minus.circle") }
                }
            }
            FieldError(e?.errors["items"]?.first)
            Button { draft.items.append(.init(key: "item_\(draft.items.count + 1)", label: "", type: "check", required: true)) } label: { Label("Add item", systemImage: "plus") }
        }
    }
}

/// GET /forms/submissions?outlet_id&from&to&status&template_id&page → {data, has_more, page}
struct FormSubmissionsList: View {
    @Environment(AppEnvironment.self) private var env
    @Binding var open: IDRef?
    @State private var outlet: Int?
    @State private var status = ""
    @State private var from = Day.add(Day.today(), days: -14)
    @State private var to = Day.today()
    @State private var page = 1
    @State private var rows: [Record] = []
    @State private var hasMore = false
    @State private var loading = false

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            HStack(alignment: .bottom) {
                OutletChoice("Outlet", $outlet, allowAll: true)
                OptionPicker("Status", $status, options: [("", "Any"), ("submitted", "Done"), ("late", "Late"), ("missed", "Missed"), ("flagged", "Flagged")])
                DayField("From", $from)
                DayField("To", $to)
                Button("Apply") { page = 1; rows = []; Task { await load() } }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
            }
            if rows.isEmpty, !loading { EmptyState("No submissions in this range", symbol: "checklist") }
            ForEach(rows) { s in
                Button { open = IDRef(s.int("id") ?? 0) } label: {
                    SupportListRow(s["template"]?["name"]?.string ?? "Form", subtitle: "\(s["outlet"]?["name"]?.string ?? "") · \(s["user"]?["name"]?.string ?? "—") · \(Day.format(s.string("for_date")))") {
                        HStack(spacing: 4) {
                            if s["flagged"]?.bool == true { Pill("flagged", .warning) }
                            if (s.int("skipped_required") ?? 0) > 0 { Pill("\(s.int("skipped_required") ?? 0) skipped", .pink) }
                            StatusBadge(s.status)
                        }
                    }
                }
                .buttonStyle(.plain)
            }
            if hasMore { Button("Load more") { page += 1; Task { await load() } }.disabled(loading) }
            if loading { ProgressView() }
        }
        .task { if rows.isEmpty { await load() } }
    }

    private func load() async {
        loading = true
        defer { loading = false }
        do {
            let j = try await env.session.client.json("/v1/admin/forms/submissions", query: ["outlet_id": outlet.map(String.init), "status": status.isEmpty ? nil : status, "from": from, "to": to, "page": String(page)])
            let fresh = j["data"]?.records ?? []
            rows = page == 1 ? fresh : rows + fresh
            hasMore = j["has_more"]?.bool ?? false
        } catch { env.toasts.error(error) }
    }
}

/// GET /forms/submissions/{id} → {submission: {answers, notes, items, photo_keys, has_signature}}
struct FormSubmissionSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let id: Int
    @State private var sub: JSONValue?

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.l) {
            HStack { Text("Submission #\(id)").font(Theme.Font.sectionTitle); Spacer(); Button("Close") { dismiss() } }
            if let s = sub {
                ScrollView {
                    VStack(alignment: .leading, spacing: Theme.Space.m) {
                        KeyValueGrid(pairs: [
                            ("Form", s["template"]?["name"]?.string ?? "—"),
                            ("Outlet", s["outlet"]?["name"]?.string ?? "—"),
                            ("By", s["user"]?["name"]?.string ?? "—"),
                            ("For", Day.format(s["for_date"]?.string)),
                            ("Submitted", s["submitted_at"]?.string.map(Day.dateTime) ?? "—"),
                            ("Status", s["status"]?.string?.humanized ?? "—"),
                            ("Signed", s["has_signature"]?.bool == true ? "Yes" : "No"),
                        ])
                        let items = s["items"]?.array ?? []
                        let answers = s["answers"]?.object ?? [:]
                        SectionCard("Answers") {
                            ForEach(Array(items.enumerated()), id: \.offset) { _, it in
                                let key = it["key"]?.string ?? ""
                                HStack(alignment: .top) {
                                    Text(it["label"]?.string ?? key).font(Theme.Font.secondary)
                                    Spacer()
                                    if it["type"]?.string == "photo" {
                                        if (s["photo_keys"]?.array?.compactMap(\.string) ?? []).contains(key) {
                                            SignedImage(source: "/api/v1/admin/forms/submissions/\(id)/file/\(key)", maxHeight: 140)
                                        } else { Text("No photo").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                                    } else {
                                        let v = answers[key]
                                        Text(v?.bool.map { $0 ? "✓" : "✗" } ?? v?.string ?? "—").font(Theme.Font.body).multilineTextAlignment(.trailing)
                                    }
                                }
                            }
                        }
                        if let n = s["notes"]?.string, !n.isEmpty { SectionCard("Notes") { Text(n).font(Theme.Font.secondary) } }
                        if s["has_signature"]?.bool == true { SectionCard("Signature") { SignedImage(source: "/api/v1/admin/forms/submissions/\(id)/file/signature", maxHeight: 120) } }
                    }
                }
            } else { LoadingSkeleton() }
        }
        .padding(Theme.Space.xl)
        #if os(macOS)
        .frame(minWidth: 520, minHeight: 480)
        #endif
        .task { sub = try? await env.session.client.json("/v1/admin/forms/submissions/\(id)")["submission"] }
    }
}
