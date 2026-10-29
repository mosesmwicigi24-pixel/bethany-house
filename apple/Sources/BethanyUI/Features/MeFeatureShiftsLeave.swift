import SwiftUI
import BethanyKit

// MARK: - My shifts (ShiftsPage.tsx): the next two weeks, open shifts to claim, swap requests

struct MyShiftsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var mine: Resource<JSONValue>?
    @State private var swapping: Record?
    @State private var note = ""
    @State private var busy = false

    var body: some View {
        Page("My shifts", subtitle: "Next two weeks") {
            HStack { Spacer(); Button { env.navigator.go(.clock) } label: { Label("Clock", systemImage: "clock") }.buttonStyle(.bordered) }
            if let mine {
                LoadableView(mine.state, retry: { mine.load() }) { data in
                    let shifts = data["shifts"]?.array?.map(Record.init(json:)) ?? []
                    let open = data["open_shifts"]?.array?.map(Record.init(json:)) ?? []
                    let pending = data["pending_swaps"]?.array?.map(Record.init(json:)) ?? []
                    let askedIds = Set(pending.compactMap { $0["shift"]?["id"]?.int })
                    if shifts.isEmpty {
                        SectionCard { Text("No shifts published for you yet.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary).frame(maxWidth: .infinity) }
                    } else {
                        ListCard {
                            ForEach(shifts) { s in
                                ShiftRow(s) {
                                    if askedIds.contains(s.int("id") ?? -1) {
                                        StatusBadge("swap asked", tone: .warning)
                                    } else {
                                        Button("Swap") { note = ""; swapping = s }.font(Theme.Font.caption)
                                    }
                                }
                            }
                        }
                    }
                    if !pending.isEmpty {
                        Text("WAITING ON THE MANAGER").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                        ListCard {
                            ForEach(pending) { r in
                                ListRow {
                                    HStack(alignment: .top) {
                                        VStack(alignment: .leading, spacing: 2) {
                                            let sh = r["shift"]
                                            Text("\(sh.map { "\(MeFormat.day(MeFormat.date($0["date"]?.string))) \($0["starts_at"]?.string ?? "")–\($0["ends_at"]?.string ?? "")" } ?? "Shift") → \(r["to_user"]?["name"]?.string ?? "anyone")").font(Theme.Font.secondary)
                                            if let n = r.string("note") { Text("“\(n)”").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                                        }
                                        Spacer()
                                        Button("Withdraw") { Task { await withdraw(r) } }.font(Theme.Font.caption).disabled(busy)
                                    }
                                }
                            }
                        }
                    }
                    Text("OPEN SHIFTS YOU CAN TAKE").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                    if open.isEmpty {
                        Text("None right now.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                    } else {
                        ListCard {
                            ForEach(open) { s in
                                ShiftRow(s) {
                                    Button("Take it") { Task { await claim(s) } }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand).disabled(busy)
                                }
                            }
                        }
                    }
                }
            }
        }
        .task {
            if mine == nil {
                let c = env.session.client
                mine = Resource { try await c.get("/v1/admin/me/shifts").json ?? .null }
                mine?.load()
            }
        }
        .refreshable { await mine?.reload() }
        .sheet(item: $swapping) { s in
            MeSheet("Ask to swap", primary: "Send to manager", busy: busy, action: { await requestSwap(s) }) {
                Text("\(MeFormat.longDay(MeFormat.date(s.string("date")))) \(s.string("starts_at") ?? "")–\(s.string("ends_at") ?? "") at \(s["outlet"]?["name"]?.string ?? ""). The manager decides who takes it; the shift stays yours until then.")
                    .font(Theme.Font.body)
                FieldLabel("Why? (optional)") { TextField("Clinic that morning", text: $note).textFieldStyle(.roundedBorder) }
            }
        }
    }

    private func claim(_ s: Record) async {
        busy = true; defer { busy = false }
        do {
            let r = try await env.session.client.post("/v1/admin/me/shifts/\(s.id)/claim")
            env.toasts.show(r.json?["message"]?.string ?? "Shift taken")
            await mine?.reload()
        } catch { env.toasts.error(error) }
    }

    private func requestSwap(_ s: Record) async {
        busy = true; defer { busy = false }
        var body: [String: JSONValue] = [:]
        let n = note.trimmingCharacters(in: .whitespacesAndNewlines)
        if !n.isEmpty { body["note"] = .string(n) }
        do {
            let r = try await env.session.client.post("/v1/admin/me/shifts/\(s.id)/swap", json: .object(body))
            env.toasts.show(r.json?["message"]?.string ?? "Sent to the manager")
            swapping = nil; note = ""
            await mine?.reload()
        } catch { env.toasts.error(error) }
    }

    private func withdraw(_ r: Record) async {
        busy = true; defer { busy = false }
        do {
            let res = try await env.session.client.delete("/v1/admin/me/swaps/\(r.id)")
            env.toasts.show(res.json?["message"]?.string ?? "Withdrawn")
            await mine?.reload()
        } catch { env.toasts.error(error) }
    }
}

struct ShiftRow<Right: View>: View {
    let shift: Record
    let right: Right
    init(_ shift: Record, @ViewBuilder right: () -> Right) { self.shift = shift; self.right = right() }

    var body: some View {
        let date = MeFormat.date(shift.string("date"))
        let isToday = shift.string("date") == MeFormat.dayString(Date())
        ListRow {
            HStack(spacing: Theme.Space.m) {
                VStack(spacing: 0) {
                    Text(MeFormat.weekday(date).uppercased()).font(.system(size: 9, weight: .semibold))
                    Text(MeFormat.dayNumber(date)).font(.system(size: 18, weight: .bold))
                }
                .frame(width: 48).padding(.vertical, 4)
                .background(isToday ? Theme.Palette.brand : Theme.Palette.neutralSoft, in: RoundedRectangle(cornerRadius: Theme.Radius.field, style: .continuous))
                .foregroundStyle(isToday ? Color.white : Theme.Palette.neutral)
                VStack(alignment: .leading, spacing: 2) {
                    HStack(spacing: 4) {
                        Text("\(shift.string("starts_at") ?? "") – \(shift.string("ends_at") ?? "")\(shift.flag("overnight") ? " (next day)" : "")").font(Theme.Font.body.weight(.semibold)).monospacedDigit()
                        Text("· \(MeFormat.hm(shift.int("minutes")))").font(Theme.Font.secondary).foregroundStyle(Theme.textTertiary)
                    }
                    Text([shift["outlet"]?["name"]?.string, shift.string("label"), shift.string("notes")].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).lineLimit(1)
                }
                Spacer()
                right
            }
        }
    }
}

// MARK: - My leave (LeavePage.tsx): balances, a request with a live preview, what happened to it

struct MyLeaveView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var mine: Resource<JSONValue>?
    @State private var asking = false
    @State private var typeId: Int = 0
    @State private var startsOn = Date()
    @State private var endsOn = Date()
    @State private var reason = ""
    @State private var preview: JSONValue?
    @State private var busy = false

    var body: some View {
        Page("My leave", subtitle: mine?.value?["year"]?.string) {
            HStack { Spacer(); Button("Request leave") { openForm() }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
            if let mine {
                LoadableView(mine.state, retry: { mine.load() }) { data in
                    let balances = (data["balances"]?.array ?? []).filter { !($0["available"]?.isNull ?? true) }
                    TileGrid(minWidth: 150) {
                        ForEach(Array(balances.enumerated()), id: \.offset) { _, b in
                            VStack(alignment: .leading, spacing: 2) {
                                Text(b["type"]?["name"]?.string?.uppercased() ?? "").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary).lineLimit(1)
                                HStack(alignment: .firstTextBaseline, spacing: 2) {
                                    Text(b["available"]?.string ?? "—").font(Theme.Font.stat).monospacedDigit()
                                    Text("/ \(entitlement(b))").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                                }
                                Text("\(b["used"]?.string ?? "0") used\((b["requested"]?.double ?? 0) > 0 ? " · \(b["requested"]?.string ?? "") asked" : "")\(b["type"]?["accrual"]?.string == "monthly" ? " · earns monthly" : "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                            }
                            .padding(Theme.Space.m)
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous))
                            .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).stroke(Theme.line, lineWidth: 1))
                        }
                    }
                    let approvers = data["approvers"]?.array?.compactMap(\.string) ?? []
                    Text("\(approvers.isEmpty ? "Nobody is set to approve your leave yet; ask the owner to set who you report to." : "Decided by \(approvers.joined(separator: " or ")).") Days are working days; public holidays do not count.")
                        .font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                    Text("REQUESTS").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                    let requests = data["requests"]?.array?.map(Record.init(json:)) ?? []
                    if requests.isEmpty {
                        Text("None yet.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                    } else {
                        ListCard { ForEach(requests) { r in leaveRow(r) } }
                    }
                    Button("Back to the clock") { env.navigator.go(.clock) }.font(Theme.Font.caption).frame(maxWidth: .infinity)
                }
            }
        }
        .task {
            if mine == nil {
                let c = env.session.client
                mine = Resource { try await c.get("/v1/admin/me/leave").json ?? .null }
                mine?.load()
            }
        }
        .refreshable { await mine?.reload() }
        .sheet(isPresented: $asking) { requestSheet }
    }

    private func leaveRow(_ r: Record) -> some View {
        let today = MeFormat.dayString(Date())
        let cancellable = r.status == "pending" || (r.status == "approved" && (r.string("starts_on") ?? "") > today)
        return ListRow {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 2) {
                    HStack(spacing: 4) {
                        Text(span(r)).font(Theme.Font.body.weight(.semibold))
                        Text("· \(MeFormat.plural(r.int("days") ?? 0, "day"))").font(Theme.Font.secondary).foregroundStyle(Theme.textTertiary)
                    }
                    Text([r["type"]?["name"]?.string, r.string("reason")].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).lineLimit(2)
                    if let n = r.string("decision_note") { Text("“\(n)”\(r.string("decided_by").map { " — \($0)" } ?? "")").font(Theme.Font.caption).italic().foregroundStyle(Theme.textSecondary) }
                }
                Spacer()
                VStack(alignment: .trailing, spacing: 4) {
                    StatusBadge(r.status)
                    if cancellable {
                        ConfirmButton("Cancel this request", message: "Your request for \(span(r)) will be cancelled.", destructive: true, action: { await cancel(r) }) { Text("Cancel").font(Theme.Font.caption) }
                    }
                }
            }
        }
    }

    /// "entitled + adjusted" as LeavePage prints it — leave days, not money.
    private func entitlement(_ b: JSONValue) -> String {
        let total = (b["entitled"]?.double ?? 0) + (b["adjusted"]?.double ?? 0)
        return total == total.rounded() ? String(Int(total)) : String(total)
    }

    private func span(_ r: Record) -> String {
        let a = MeFormat.date(r.string("starts_on")), b = MeFormat.date(r.string("ends_on"))
        return r.string("starts_on") == r.string("ends_on") ? MeFormat.day(a) : "\(MeFormat.day(a)) – \(MeFormat.day(b))"
    }

    // MARK: Request

    private var types: [JSONValue] { mine?.value?["types"]?.array ?? [] }

    private func openForm() {
        if typeId == 0 { typeId = types.first?["id"]?.int ?? 0 }
        startsOn = Date(); endsOn = Date(); reason = ""; preview = nil
        asking = true
        Task { await loadPreview() }
    }

    private var requestSheet: some View {
        let ok = typeId > 0 && endsOn >= startsOn
        let enough = (preview?["enough"]?.bool ?? false) && (preview?["days"]?.double ?? 0) > 0
        return MeSheet("Request leave", primary: "Send", disabled: !ok || !enough, busy: busy, action: { await submit() }) {
            FieldLabel("Type") {
                Picker("Type", selection: $typeId) {
                    ForEach(types, id: \.self) { t in
                        Text("\(t["name"]?.string ?? "")\(t["is_paid"]?.bool == false ? " (unpaid)" : "")").tag(t["id"]?.int ?? 0)
                    }
                }
                .labelsHidden()
            }
            HStack {
                FieldLabel("From") { DatePicker("From", selection: $startsOn, displayedComponents: .date).labelsHidden() }
                FieldLabel("To") { DatePicker("To", selection: $endsOn, in: startsOn..., displayedComponents: .date).labelsHidden() }
            }
            FieldLabel("Reason (optional)") { TextField("", text: $reason).textFieldStyle(.roundedBorder) }
            if let p = preview {
                let days = p["days"]?.double ?? 0
                if days <= 0 {
                    NoticeText("Those dates contain no working days.", tone: .danger)
                } else {
                    let skipped = p["holidays_skipped"]?.array?.compactMap(\.string) ?? []
                    let available = p["available"]?.string
                    NoticeText("\(MeFormat.plural(Int(days), "day"))\(skipped.isEmpty ? "" : " (skipping \(skipped.joined(separator: ", ")))")\(available.map { " · \($0) available\(p["enough"]?.bool == false ? " — not enough" : "")" } ?? "")", tone: enough ? .success : .danger)
                }
            }
        }
        .onChange(of: typeId) { _, _ in Task { await loadPreview() } }
        .onChange(of: startsOn) { _, v in if endsOn < v { endsOn = v }; Task { await loadPreview() } }
        .onChange(of: endsOn) { _, _ in Task { await loadPreview() } }
    }

    private func loadPreview() async {
        guard typeId > 0, endsOn >= startsOn else { preview = nil; return }
        preview = try? await env.session.client.get("/v1/admin/me/leave/preview", query: [
            "leave_type_id": String(typeId), "starts_on": MeFormat.dayString(startsOn), "ends_on": MeFormat.dayString(endsOn),
        ]).json
    }

    private func submit() async {
        busy = true; defer { busy = false }
        var body: [String: JSONValue] = ["leave_type_id": .number(Double(typeId)), "starts_on": .string(MeFormat.dayString(startsOn)), "ends_on": .string(MeFormat.dayString(endsOn))]
        let r = reason.trimmingCharacters(in: .whitespacesAndNewlines)
        if !r.isEmpty { body["reason"] = .string(r) }
        do {
            let res = try await env.session.client.post("/v1/admin/me/leave", json: .object(body))
            env.toasts.show(res.json?["message"]?.string ?? "Request sent")
            asking = false
            await mine?.reload()
        } catch { env.toasts.error(error) }
    }

    private func cancel(_ r: Record) async {
        do {
            let res = try await env.session.client.delete("/v1/admin/me/leave/\(r.id)")
            env.toasts.show(res.json?["message"]?.string ?? "Cancelled")
            await mine?.reload()
        } catch { env.toasts.error(error) }
    }
}
