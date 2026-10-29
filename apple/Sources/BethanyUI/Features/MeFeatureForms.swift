import SwiftUI
import BethanyKit

/// /forms — a staff member's checklists and reports (FormsPage.tsx): what is
/// due today at my outlet, done or missed, and the report forms to send any
/// time. Filling one is a sheet: ticks, numbers, text, photos, a signature.
struct FormsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var today: Resource<JSONValue>?
    @State private var mine: Resource<[Record]>?
    @State private var filling: Record?

    var body: some View {
        Page("Checklists & forms", subtitle: today?.value?["date"]?.string.flatMap(MeFormat.date).map(MeFormat.longDay)) {
            if today?.value?["can_manage"]?.bool == true {
                HStack { Spacer(); Button("Manager view ›") { env.navigator.go(.formsManage) }.font(Theme.Font.caption) }
            }
            if let today {
                LoadableView(today.state, retry: { today.load() }) { d in
                    let rows = d.records
                    let checks = rows.filter { $0["template"]?["kind"]?.string == "checklist" }
                    let reports = rows.filter { $0["template"]?["kind"]?.string == "report" }
                    Text("TODAY'S CHECKLISTS").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                    if checks.isEmpty { Text("No checklist due today.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary) }
                    ForEach(checks) { r in checklistRow(r) }
                    Text("REPORT SOMETHING").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                    if reports.isEmpty { Text("No report forms for your outlet.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary) }
                    TileGrid(minWidth: 160) {
                        ForEach(reports) { r in
                            Button { filling = r } label: {
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(r["template"]?["name"]?.string ?? "").font(Theme.Font.body.weight(.semibold))
                                    Text("\(r["outlet"]?["name"]?.string ?? "")\((r["reports_today"]?.int ?? 0) > 0 ? " · \(r["reports_today"]?.int ?? 0) today" : "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                }
                                .frame(maxWidth: .infinity, alignment: .leading).padding(Theme.Space.l)
                                .background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous))
                                .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).stroke(Theme.line, lineWidth: 1))
                                .foregroundStyle(Theme.text)
                            }
                            .buttonStyle(.plain)
                        }
                    }
                }
            }
            if let mine, let rows = mine.value, !rows.isEmpty {
                Text("YOURS LATELY").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                ListCard {
                    ForEach(rows.prefix(8)) { s in
                        ListRow {
                            HStack {
                                Text(s["template"]?["name"]?.string ?? "").font(Theme.Font.secondary)
                                Text("· \(s.string("for_date") ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                                Spacer()
                                StatusBadge("\(stateLabel(s.status))\((s.int("skipped_required") ?? 0) > 0 ? " · \(s.int("skipped_required") ?? 0) skipped" : "")", tone: s.status == "late" ? .warning : s.status == "missed" ? .danger : .success)
                            }
                        }
                    }
                }
            }
        }
        .task {
            if today == nil {
                let c = env.session.client
                today = Resource { try await c.get("/v1/admin/forms/today").json ?? .null }
                mine = Resource { try await c.get("/v1/admin/forms/mine", background: true).json?.records ?? [] }
                today?.load(); mine?.load()
            }
        }
        .refreshable { await today?.reload(); await mine?.reload() }
        .sheet(item: $filling) { row in
            FillFormSheet(row: row) { filling = nil; today?.load(); mine?.load() }
        }
    }

    private func stateLabel(_ s: String?) -> String {
        switch s { case "due": return "Due"; case "overdue": return "Overdue"; case "submitted": return "Done"; case "late": return "Done late"; case "missed": return "Missed"; default: return "" }
    }

    private func checklistRow(_ r: Record) -> some View {
        let state = r.string("state") ?? ""
        let done = state == "submitted" || state == "late"
        let bad = state == "missed" || state == "overdue"
        let sub = r["submission"].flatMap { $0.object == nil ? nil : $0 }
        return Button { filling = r } label: {
            HStack(spacing: Theme.Space.m) {
                ZStack {
                    Circle().fill(done ? Theme.Palette.success : Theme.Palette.neutralSoft)
                    Image(systemName: done ? "checkmark" : "square").foregroundStyle(done ? .white : Theme.textSecondary)
                }
                .frame(width: 36, height: 36)
                VStack(alignment: .leading, spacing: 2) {
                    Text(r["template"]?["name"]?.string ?? "").font(Theme.Font.body.weight(.semibold))
                    Text("\(r["outlet"]?["name"]?.string ?? "")\(r["due_at"]?.date.map { " · due \(MeFormat.time($0))" } ?? "")\(sub.map { " · \(state == "late" ? "done late" : "done") by \($0["by"]?.string ?? "—") at \(MeFormat.time($0["at"]?.date))" } ?? (state == "missed" ? " · missed — do it now" : state == "overdue" ? " · overdue" : ""))\((sub?["skipped_required"]?.int ?? 0) > 0 ? " · \(sub?["skipped_required"]?.int ?? 0) skipped" : "")")
                        .font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                }
                Spacer()
                if !done { Text("Fill in ›").font(Theme.Font.caption.weight(.semibold)).foregroundStyle(Theme.Palette.brandDeep) }
            }
            .padding(Theme.Space.l).frame(maxWidth: .infinity, alignment: .leading)
            .background(done ? Theme.Palette.successSoft.opacity(0.4) : bad ? Theme.Palette.dangerSoft.opacity(0.3) : Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).stroke(done ? Theme.Palette.success.opacity(0.3) : bad ? Theme.Palette.danger.opacity(0.3) : Theme.line, lineWidth: 1))
            .foregroundStyle(Theme.text)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(done)
    }
}

// MARK: - The form renderer (FormTemplate::ITEM_TYPES: check, text, number, photo; requires_signature)

/// Posts `multipart: template_id, outlet_id, answers (JSON), notes?,
/// signature (PNG data URL)?, photo_<key> files` exactly as FormsController::submit validates.
struct FillFormSheet: View {
    @Environment(AppEnvironment.self) private var env
    let row: Record
    let onDone: () -> Void
    @State private var checks: [String: Bool] = [:]
    @State private var texts: [String: String] = [:]
    @State private var photos: [String: PickedPhoto] = [:]
    @State private var notes = ""
    @State private var signature: String?
    @State private var busy = false

    private var template: JSONValue { row["template"] ?? .null }
    private var items: [JSONValue] { template["items"]?.array ?? [] }
    private var requiresSignature: Bool { template["requires_signature"]?.bool ?? false }
    private var isReport: Bool { template["kind"]?.string == "report" }

    private var missing: Int {
        items.filter { i in
            guard i["required"]?.bool == true, let key = i["key"]?.string else { return false }
            switch i["type"]?.string {
            case "photo": return photos[key] == nil
            case "check": return checks[key] != true
            default: return (texts[key] ?? "").trimmingCharacters(in: .whitespaces).isEmpty
            }
        }.count
    }

    var body: some View {
        MeSheet(template["name"]?.string ?? "Form", primary: isReport ? "Send" : "Done", disabled: requiresSignature && signature == nil, busy: busy, action: { await submit() }) {
            Text("\(row["outlet"]?["name"]?.string ?? "")\(row["due_at"]?.date.map { " · due \(MeFormat.time($0))" } ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            ForEach(Array(items.enumerated()), id: \.offset) { _, i in
                if let key = i["key"]?.string { itemView(i, key: key) }
            }
            FieldLabel("Notes (optional)") { TextField("", text: $notes, axis: .vertical).lineLimit(2...5).textFieldStyle(.roundedBorder) }
            if requiresSignature {
                VStack(alignment: .leading, spacing: 4) {
                    HStack(spacing: 2) { Text("Signature").font(Theme.Font.body.weight(.medium)); Text("*").foregroundStyle(Theme.Palette.danger) }
                    SignaturePad(dataURL: $signature)
                }
            }
            Text(missing > 0 ? "\(MeFormat.plural(missing, "required item")) blank — your manager will see that." : "All required items done.")
                .font(Theme.Font.caption).foregroundStyle(missing > 0 ? Theme.Palette.warning : Theme.Palette.success)
        }
    }

    @ViewBuilder
    private func itemView(_ i: JSONValue, key: String) -> some View {
        let required = i["required"]?.bool ?? false
        let label = HStack(spacing: 2) { Text(i["label"]?.string ?? key).font(Theme.Font.body.weight(.medium)); if required { Text("*").foregroundStyle(Theme.Palette.danger) } }
        VStack(alignment: .leading, spacing: Theme.Space.s) {
            switch i["type"]?.string {
            case "check":
                Toggle(isOn: Binding(get: { checks[key] ?? false }, set: { checks[key] = $0 })) { label }
                    #if os(iOS)
                    .toggleStyle(.switch)
                    #else
                    .toggleStyle(.checkbox)
                    #endif
            case "number":
                label
                TextField("", text: Binding(get: { texts[key] ?? "" }, set: { texts[key] = $0 })).textFieldStyle(.roundedBorder).decimalKeyboard()
            case "photo":
                label
                PhotoPickerField(label: "Take or choose a photo", photo: Binding(get: { photos[key] }, set: { photos[key] = $0 }))
            default:
                label
                TextField("", text: Binding(get: { texts[key] ?? "" }, set: { texts[key] = $0 }), axis: .vertical).lineLimit(3...6).textFieldStyle(.roundedBorder)
            }
        }
        .padding(Theme.Space.m).frame(maxWidth: .infinity, alignment: .leading)
        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.field).stroke(Theme.line, lineWidth: 1))
    }

    private func submit() async {
        guard let templateId = template["id"]?.int, let outletId = row["outlet"]?["id"]?.int else { return }
        busy = true; defer { busy = false }
        var answers: [String: JSONValue] = [:]
        for i in items {
            guard let key = i["key"]?.string else { continue }
            switch i["type"]?.string {
            case "check": answers[key] = .bool(checks[key] ?? false)
            case "photo": continue
            default: if let t = texts[key], !t.isEmpty { answers[key] = .string(t) }
            }
        }
        guard let answersData = try? HubJSON.encoder.encode(JSONValue.object(answers)), let answersJSON = String(data: answersData, encoding: .utf8) else { return }
        var parts: [APIClient.MultipartPart] = [
            .init(name: "template_id", value: String(templateId)),
            .init(name: "outlet_id", value: String(outletId)),
            .init(name: "answers", value: answersJSON),
        ]
        let n = notes.trimmingCharacters(in: .whitespacesAndNewlines)
        if !n.isEmpty { parts.append(.init(name: "notes", value: n)) }
        if let signature { parts.append(.init(name: "signature", value: signature)) }
        for (key, p) in photos { parts.append(.init(name: "photo_\(key)", data: p.data, filename: p.filename, mimeType: p.mimeType)) }
        do {
            let r = try await env.session.client.send(.init(.post, "/v1/admin/forms/submissions", body: .multipart(parts)))
            env.toasts.show(r.json?["message"]?.string ?? (isReport ? "Sent" : "Done"))
            onDone()
        } catch { env.toasts.error(error) }
    }
}
