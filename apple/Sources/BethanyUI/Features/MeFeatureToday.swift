import SwiftUI
import BethanyKit

/// Me › Today (MePage.tsx): the phone's home for a staff member. Today
/// first — the clock card — then everything that is theirs. A manager with
/// `attendance.view_team` also sees the team now and the requests waiting.
struct MeTodayView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var status: Resource<JSONValue>?
    @State private var leave: Resource<JSONValue>?
    @State private var feed: Resource<JSONValue>?
    @State private var forms: Resource<JSONValue>?
    @State private var signing: Resource<JSONValue>?
    @State private var courses: Resource<JSONValue>?
    @State private var todos: Resource<JSONValue>?
    @State private var overview: Resource<JSONValue>?

    private var teamView: Bool { env.session.can("attendance.view_team") }

    var body: some View {
        Page("Hi \(firstName)", subtitle: MeFormat.longDay(Date())) {
            clockCard
            if let f = feed?.value { updatesCard(f) }
            ListCard {
                ForEach(links, id: \.label) { l in LinkRow(label: l.label, hint: l.hint, route: l.route) }
            }
            if teamView, let o = overview?.value { teamCard(o) }
        }
        .task { await loadAll() }
        .refreshable { await loadAll() }
    }

    private var firstName: String {
        env.session.me?.raw["first_name"]?.string ?? env.session.me?.name.split(separator: " ").first.map(String.init) ?? "there"
    }

    // MARK: Clock card

    private var clockCard: some View {
        let s = status?.value
        let active = s?["active_entry"].flatMap { $0.object == nil ? nil : $0 }
        let shift = s?["today_shift"].flatMap { $0.object == nil ? nil : $0 }
        let onBreak = active?["on_break"]?.bool ?? false
        return Button { env.navigator.go(.clock) } label: {
            VStack(alignment: .leading, spacing: 4) {
                Text("TODAY").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                if let active {
                    Text("Clocked in since \(MeFormat.time(active["clock_in_at"]?.date))\(onBreak ? " · on break" : "")").font(Theme.Font.display(18))
                    Text("\(MeFormat.hm(active["elapsed_minutes"]?.int)) so far · tap to clock out").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                } else {
                    Text("Not clocked in").font(Theme.Font.display(18))
                    Text(shift.map { "Shift \($0["starts_at"]?.string ?? "")–\($0["ends_at"]?.string ?? "") · tap to clock in" } ?? "Tap to clock in").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(Theme.Space.l)
            .background(active != nil ? Theme.Palette.successSoft : Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).stroke(active != nil ? Theme.Palette.success.opacity(0.3) : Theme.line, lineWidth: 1))
            .foregroundStyle(Theme.text)
        }
        .buttonStyle(.plain)
    }

    // MARK: Updates card

    private func updatesCard(_ f: JSONValue) -> some View {
        let posts = f["data"]?.array ?? []
        let mustRead = f["must_read_pending"]?.int ?? 0
        let unread = posts.filter { $0["me"]?["read_at"]?.isNull ?? true }.count
        return Button { env.navigator.go(.updates()) } label: {
            VStack(alignment: .leading, spacing: 4) {
                HStack {
                    Text("Updates").font(Theme.Font.sectionTitle)
                    Spacer()
                    if mustRead > 0 {
                        Text("\(MeFormat.plural(mustRead, "must-read")) waiting").font(Theme.Font.caption).foregroundStyle(Theme.Palette.danger)
                    } else {
                        Text(unread > 0 ? "\(unread) new" : "all read").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                    }
                    Image(systemName: "chevron.right").font(.system(size: 11, weight: .semibold)).foregroundStyle(Theme.textTertiary)
                }
                ForEach(Array(posts.prefix(3).enumerated()), id: \.offset) { _, p in
                    Text(postLine(p)).font(Theme.Font.secondary).fontWeight(p["me"]?["read_at"]?.isNull ?? true ? .medium : .regular)
                        .foregroundStyle(p["me"]?["read_at"]?.isNull ?? true ? Theme.text : Theme.textSecondary).lineLimit(1)
                }
                if posts.isEmpty { Text("Nothing posted yet.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary) }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(Theme.Space.l)
            .background(mustRead > 0 ? Theme.Palette.dangerSoft.opacity(0.4) : Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).stroke(mustRead > 0 ? Theme.Palette.danger.opacity(0.3) : Theme.line, lineWidth: 1))
            .foregroundStyle(Theme.text)
        }
        .buttonStyle(.plain)
    }

    private func postLine(_ p: JSONValue) -> String {
        let title = p["title"]?.string ?? ""
        switch p["kind"]?.string {
        case "recognition": return "⭐ \(p["author"]?["name"]?.string ?? "") recognised \(p["recipient"]?["name"]?.string ?? "")"
        case "celebration": return "🎉 \(title)"
        case "event": return "📅 \(title)"
        default: return "📣 \(title)"
        }
    }

    // MARK: Links

    private struct LinkItem { let label: String; let hint: String?; let route: Route }

    private var links: [LinkItem] {
        let s = status?.value
        let shift = s?["today_shift"].flatMap { $0.object == nil ? nil : $0 }
        let balances = leave?.value?["balances"]?.array ?? []
        let annual = balances.first { $0["type"]?["code"]?.string == "annual" } ?? balances.first { !($0["available"]?.isNull ?? true) }
        let formsRows = forms?.value?["data"]?.array ?? []
        let formsDue = formsRows.filter { $0["template"]?["kind"]?.string == "checklist" && !["submitted", "late"].contains($0["state"]?.string ?? "") }.count
        let todo = todos?.value
        let course = courses?.value
        let pendingSign = signing?.value?["pending"]?.int ?? 0
        var items: [LinkItem] = [
            LinkItem(label: "My shifts", hint: shift.map { "today \($0["starts_at"]?.string ?? "")–\($0["ends_at"]?.string ?? "")" } ?? (s == nil ? nil : "no shift today"), route: .myShifts),
            LinkItem(label: "My leave", hint: annual?["available"]?.int.map { "\($0) days left" }, route: .myLeave),
            LinkItem(label: "My hours", hint: s.map { "\(MeFormat.hm($0["week_worked_minutes"]?.int)) this week" }, route: .myTimesheet),
            LinkItem(label: "My pay", hint: "payslips", route: .myPayslips),
            LinkItem(label: "To-dos", hint: todo.map { t in (t["overdue"]?.int ?? 0) > 0 ? "\(t["overdue"]?.int ?? 0) overdue" : (t["open"]?.int ?? 0) > 0 ? "\(t["open"]?.int ?? 0) open" : "none open" }, route: .todos),
            LinkItem(label: "Colleagues", hint: "the directory", route: .colleagues),
            LinkItem(label: "Checklists & forms", hint: formsDue > 0 ? "\(formsDue) to do" : (forms?.value == nil ? nil : "all done"), route: .forms),
            LinkItem(label: "Handbook", hint: "policies, how-tos", route: .handbook()),
            LinkItem(label: "Training", hint: course.map { c in (c["to_do"]?.int ?? 0) > 0 ? "\(c["to_do"]?.int ?? 0) to complete" : "all done" }, route: .courses()),
        ]
        if pendingSign > 0 { items.append(LinkItem(label: "To sign", hint: "\(pendingSign) waiting", route: .sign())) }
        items.append(LinkItem(label: "Advances & loans", hint: nil, route: .myAdvances))
        items.append(LinkItem(label: "My file", hint: "contact, documents, identifiers", route: .profile))
        return items
    }

    // MARK: Team now (OverviewPage.tsx TeamNow, compact)

    private func teamCard(_ o: JSONValue) -> some View {
        let now = o["now"] ?? .null
        let requests = o["requests"]?["count"]?.int ?? 0
        return SectionCard {
            HStack {
                Text("Your team now").font(Theme.Font.sectionTitle)
                Spacer()
                Button("Overview ›") { env.navigator.go(.peopleOverview) }.font(Theme.Font.caption)
            }
            teamGroup("In now", tone: .success, rows: now["in"]?.array ?? []) { x in
                HStack(spacing: 6) {
                    Text(x["person"]?["name"]?.string ?? "").lineLimit(1)
                    Text("· since \(MeFormat.time(x["since"]?.date))\(x["outlet"]?.string.map { " · \($0)" } ?? "")").foregroundStyle(Theme.textTertiary).lineLimit(1)
                    if x["on_break"]?.bool == true { StatusBadge("break", tone: .neutral) }
                    if let left = x["left_site_at"]?.date { StatusBadge("away since \(MeFormat.time(left))", tone: .warning) }
                    else if let n = x["flags"]?.array?.count, n > 0 { StatusBadge(MeFormat.plural(n, "note"), tone: .warning) }
                }
            }
            teamGroup("Late", tone: .danger, rows: now["late"]?.array ?? []) { x in
                HStack { Text("\(x["person"]?["name"]?.string ?? "") · shift \(x["shift"]?.string ?? "")").lineLimit(1); Spacer(); Text("\(x["minutes_late"]?.int ?? 0) min").foregroundStyle(Theme.Palette.danger).font(Theme.Font.caption) }
            }
            teamGroup("Did not show", tone: .danger, rows: now["absent"]?.array ?? []) { x in
                Text("\(x["person"]?["name"]?.string ?? "") · shift \(x["shift"]?.string ?? "")").lineLimit(1)
            }
            teamGroup("Due later", tone: .neutral, rows: now["expected"]?.array ?? []) { x in
                Text("\(x["person"]?["name"]?.string ?? "") · \(x["shift"]?.string ?? "")").lineLimit(1)
            }
            teamGroup("On leave", tone: .info, rows: now["on_leave"]?.array ?? []) { x in
                Text("\(x["person"]?["name"]?.string ?? "") · \(x["type"]?.string ?? "leave") until \(x["until"]?.string ?? "")").lineLimit(1)
            }
            if (now["in_count"]?.int ?? 0) + (now["late"]?.array?.count ?? 0) + (now["absent"]?.array?.count ?? 0) + (now["expected"]?.array?.count ?? 0) + (now["on_leave"]?.array?.count ?? 0) == 0 {
                Text("Nobody is clocked in and nothing is rostered today.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            }
            if requests > 0 {
                Button { env.navigator.go(.peopleRequests) } label: {
                    NoticeText("\(MeFormat.plural(requests, "request")) waiting on you ›", tone: .warning)
                }
                .buttonStyle(.plain)
            }
        }
    }

    @ViewBuilder
    private func teamGroup<R: View>(_ title: String, tone: StatusTone, rows: [JSONValue], @ViewBuilder row: @escaping (JSONValue) -> R) -> some View {
        if !rows.isEmpty {
            VStack(alignment: .leading, spacing: 4) {
                Text("\(title.uppercased()) · \(rows.count)").font(Theme.Font.caption).foregroundStyle(tone.foreground)
                ForEach(Array(rows.enumerated()), id: \.offset) { _, x in
                    HStack(spacing: Theme.Space.s) {
                        PersonAvatar(name: x["person"]?["name"]?.string, url: x["person"]?["avatar_url"]?.string, size: 24)
                        row(x).font(Theme.Font.secondary)
                    }
                }
            }
        }
    }

    // MARK: Loading

    private func loadAll() async {
        let c = env.session.client
        if status == nil {
            status = Resource { try await c.get("/v1/admin/time-clock/status", background: true).json ?? .null }
            leave = Resource { try await c.get("/v1/admin/me/leave", background: true).json ?? .null }
            feed = Resource { try await c.get("/v1/admin/updates", query: ["page": "1"], background: true).json ?? .null }
            forms = Resource { try await c.get("/v1/admin/forms/today", background: true).json ?? .null }
            signing = Resource { try await c.get("/v1/admin/me/sign", background: true).json ?? .null }
            courses = Resource { try await c.get("/v1/admin/courses", background: true).json ?? .null }
            todos = Resource { try await c.get("/v1/admin/todos", query: ["scope": "mine", "status": "open"], background: true).json ?? .null }
            if teamView { overview = Resource { try await c.get("/v1/admin/people/overview", background: true).json ?? .null } }
        }
        await withTaskGroup(of: Void.self) { g in
            for r in [status, leave, feed, forms, signing, courses, todos, overview].compactMap({ $0 }) {
                g.addTask { @MainActor in await r.reload() }
            }
        }
    }
}
