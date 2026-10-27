import SwiftUI
import BethanyKit

/// Clock in on the phone (ClockPage.tsx). One screen, one big button. The
/// device reports where it is and how sure it is; the server decides inside
/// or outside the outlet's zone and keeps the evidence. Every state is
/// explained in words (locating, location off, outside, offline).
///
/// A punch that cannot reach the server is kept in the offline queue
/// (`kind: "punch"`, one slot) with the phone's time as `recorded_at`; the
/// server stores that as the punch time and flags it `sent_late`.
struct ClockView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var status: Resource<JSONValue>?
    @State private var shifts: Resource<JSONValue>?
    @State private var leave: Resource<JSONValue>?
    @State private var location = LocationService()
    @State private var chosenOutletId: Int?
    @State private var pending: OfflineStore.PendingOperation?
    @State private var busy = false
    @State private var askReason = false
    @State private var reason = ""
    @State private var serverAsksOverride = false

    private var canOverride: Bool { env.session.can("attendance.manage") || env.session.can("outlets.all_access") }
    private var data: JSONValue? { status?.value }
    private var active: JSONValue? { data?["active_entry"].flatMap { $0.object == nil ? nil : $0 } }
    private var outlets: [JSONValue] { data?["outlets"]?.array ?? [] }
    private var fix: LocationFix? { location.fix }

    /// Which outlet: the active entry's, else the chosen, else the nearest, else the only one.
    private var outlet: JSONValue? {
        if let active, let oid = active["outlet"]?["id"]?.int { return outlets.first { $0["id"]?.int == oid } }
        if let chosenOutletId, let o = outlets.first(where: { $0["id"]?.int == chosenOutletId }) { return o }
        guard !outlets.isEmpty else { return nil }
        if let fix {
            let withCoords = outlets.filter { $0["latitude"]?.double != nil && $0["longitude"]?.double != nil }
            if let nearest = withCoords.min(by: { distance(fix, to: $0) ?? .infinity < distance(fix, to: $1) ?? .infinity }) { return nearest }
        }
        return outlets[0]
    }

    private func distance(_ fix: LocationFix, to o: JSONValue) -> Double? {
        guard let lat = o["latitude"]?.double, let lng = o["longitude"]?.double else { return nil }
        return Geofence.distanceMeters(lat1: fix.latitude, lng1: fix.longitude, lat2: lat, lng2: lng)
    }

    private var distanceMeters: Double? { fix.flatMap { f in outlet.flatMap { distance(f, to: $0) } } }
    private var radius: Double? { outlet?["geofence_radius_meters"]?.double }
    private var verdict: Geofence.Verdict { Geofence.verdict(distance: distanceMeters, radius: radius, accuracy: fix?.accuracy) }

    var body: some View {
        Page("Time clock", subtitle: MeFormat.longDay(Date())) {
            whereBanner
            if outlets.count > 1 && active == nil {
                Picker("Outlet", selection: Binding(get: { outlet?["id"]?.int ?? 0 }, set: { chosenOutletId = $0 })) {
                    ForEach(outlets, id: \.self) { o in Text(o["name"]?.string ?? "Outlet").tag(o["id"]?.int ?? 0) }
                }
            }
            if location.status == .denied || location.status == .restricted {
                NoticeText("Clocking in needs your location, only at the moment you press the button. Allow location for Bethany House Hub in Settings, then try again.", tone: .danger)
            } else if case .unavailable(let m) = location.status {
                HStack { NoticeText(m, tone: .warning); Button("Try again") { Task { await location.startUpdating() } } }
            }
            if let pending {
                HStack {
                    NoticeText("A \(pending.path.hasSuffix("clock-in") ? "clock-in" : "clock-out") from \(MeFormat.when(pending.recordedAt)) is waiting to be sent.", tone: .info)
                    Button("Send now") { Task { await sendPending() } }.disabled(!env.connection.isOnline)
                }
            }
            clockCard
            summaryCard
            if let today = data?["today_entries"]?.array, !today.isEmpty {
                Text("TODAY'S PUNCHES").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                ListCard {
                    ForEach(Array(today.enumerated()), id: \.offset) { _, e in
                        ListRow {
                            HStack {
                                Text("\(MeFormat.time(e["clock_in_at"]?.date)) – \(e["clock_out_at"]?.date.map(MeFormat.time) ?? "…")").font(Theme.Font.body).monospacedDigit()
                                Spacer()
                                FlagChips(e)
                                Text(e["status"]?.string == "active" ? MeFormat.clockMinutes(elapsedMinutes) : MeFormat.clockMinutes(e["worked_minutes"]?.int)).font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary).monospacedDigit()
                            }
                        }
                    }
                }
            }
        }
        .task {
            if status == nil {
                let c = env.session.client
                status = Resource { try await c.get("/v1/admin/time-clock/status").json ?? .null }
                shifts = Resource { try await c.get("/v1/admin/me/shifts", background: true).json ?? .null }
                leave = Resource { try await c.get("/v1/admin/me/leave", background: true).json ?? .null }
                status?.load(); shifts?.load(); leave?.load()
            }
            await loadPending()
            await location.startUpdating()
            if pending != nil, env.connection.isOnline { await sendPending() }
        }
        .onDisappear { location.stopUpdating() }
        .onChange(of: env.connection.isOnline) { _, online in if online, pending != nil { Task { await sendPending() } } }
        .onChange(of: status?.value?["rules"]?["location_mode"]?.string) { _, _ in syncTrail() }
        .onChange(of: active != nil) { _, _ in syncTrail() }
        .refreshable { await status?.reload() }
    }

    // MARK: Words for every state

    private var whereBanner: some View {
        let (text, tone): (String, StatusTone) = {
            switch location.status {
            case .denied, .restricted: return ("Location is off for this app", .danger)
            case .unavailable: return ("Location unavailable", .danger)
            case .idle, .asking, .locating: return ("Finding you…", .neutral)
            case .fixed:
                guard let fix else { return ("Finding you…", .neutral) }
                guard let outlet else { return ("No outlet to clock into", .danger) }
                guard let d = distanceMeters else { return ("\(outlet["name"]?.string ?? "Outlet") has no location set", .warning) }
                if verdict.inside { return ("\(Int(d.rounded())) m from \(outlet["name"]?.string ?? "the outlet")", .success) }
                if verdict.nearEdge { return ("\(Int(d.rounded())) m away, phone ±\(Int(fix.accuracy)) m", .warning) }
                return ("\(Int(d.rounded())) m away, outside the \(Int(radius ?? 0)) m zone", .danger)
            }
        }()
        return HStack { Spacer(); StatusBadge(text, tone: tone) }
    }

    // MARK: The clock and the button

    private var canPunch: Bool { fix != nil && outlet != nil && !busy && (active != nil || verdict.inside || verdict.nearEdge) }
    private var showForce: Bool { active == nil && fix != nil && outlet != nil && canOverride && (serverAsksOverride || !(verdict.inside || verdict.nearEdge)) }

    private var elapsedMinutes: Int {
        guard let active, let inAt = active["clock_in_at"]?.date else { return 0 }
        return max(0, Int(Date().timeIntervalSince(inAt) / 60) - (active["total_break_minutes"]?.int ?? 0))
    }

    private var clockCard: some View {
        SectionCard {
            TimelineView(.periodic(from: .now, by: 1)) { ctx in
                VStack(spacing: Theme.Space.m) {
                    Text(MeFormat.time(ctx.date)).font(Theme.Font.display(48, weight: .heavy)).monospacedDigit()
                    Text(stateLine).font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                    Button { Task { await punch() } } label: {
                        ZStack {
                            Circle().fill(active != nil ? Theme.Palette.ink : Theme.Palette.brand)
                                .shadow(color: (active != nil ? Color.black : Theme.Palette.brand).opacity(0.3), radius: 14, y: 8)
                            if busy {
                                ProgressView().tint(.white)
                            } else {
                                VStack(spacing: 4) {
                                    Text(active != nil ? "CLOCK OUT" : "CLOCK IN").font(.system(size: 20, weight: .heavy)).tracking(1)
                                    Text(buttonHint).font(Theme.Font.caption).opacity(0.9).multilineTextAlignment(.center).padding(.horizontal, 16)
                                }
                                .foregroundStyle(.white)
                            }
                        }
                        .frame(width: 176, height: 176)
                    }
                    .buttonStyle(.plain)
                    .disabled(!canPunch)
                    .opacity(canPunch ? 1 : 0.4)
                    .accessibilityLabel(active != nil ? "Clock out" : "Clock in")
                }
                .frame(maxWidth: .infinity)
            }
            if let active {
                HStack {
                    Spacer()
                    if active["on_break"]?.bool == true {
                        Button("End break") { Task { await breakToggle(start: false) } }.buttonStyle(.bordered).disabled(busy)
                    } else {
                        Button("Start break") { Task { await breakToggle(start: true) } }.buttonStyle(.bordered).disabled(busy)
                    }
                    Spacer()
                }
            }
            if showForce && !askReason {
                Button("Clock in anyway (manager override)") { askReason = true }.font(Theme.Font.caption).frame(maxWidth: .infinity)
            }
            if showForce && askReason {
                VStack(alignment: .leading, spacing: Theme.Space.s) {
                    Text("Why are you clocking in from outside the zone? This is written to the audit trail.").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    TextField("e.g. delivering to a client first", text: $reason).textFieldStyle(.roundedBorder)
                    HStack {
                        Spacer()
                        Button("Cancel") { askReason = false; reason = "" }
                        Button("Clock in with override") { Task { await punch(force: true) } }
                            .buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                            .disabled(reason.trimmingCharacters(in: .whitespaces).count < 3 || busy)
                    }
                }
            }
            if let fix {
                Text("Phone accuracy ±\(Int(fix.accuracy)) m\(radius.map { " · zone \(Int($0)) m" } ?? ""). Location is read only while this screen is open.")
                    .font(Theme.Font.caption).foregroundStyle(Theme.textTertiary).frame(maxWidth: .infinity)
            }
        }
    }

    private var stateLine: String {
        if status?.value == nil { return "Loading…" }
        guard let active else { return "Not clocked in" }
        let inAt = MeFormat.time(active["clock_in_at"]?.date)
        return active["on_break"]?.bool == true ? "On break · clocked in at \(inAt)" : "Clocked in at \(inAt) · \(MeFormat.clockMinutes(elapsedMinutes))"
    }

    private var buttonHint: String {
        if fix == nil { return "waiting for location" }
        if active != nil { return outlet?["name"]?.string ?? "" }
        if verdict.inside { return "you are at \(outlet?["name"]?.string ?? "the outlet")" }
        if verdict.nearEdge { return "near the zone · will be noted" }
        return "outside the zone"
    }

    // MARK: Today, this week, last punch

    private var summaryCard: some View {
        let mine = shifts?.value
        let today = mine?["today"].flatMap { $0.object == nil ? nil : $0 }
        let next = mine?["next"].flatMap { $0.object == nil ? nil : $0 }
        let balances = leave?.value?["balances"]?.array ?? []
        let annual = balances.first { $0["type"]?["code"]?.string == "annual" } ?? balances.first { !($0["available"]?.isNull ?? true) }
        let rules = data?["rules"]
        let last = data?["last_entry"].flatMap { $0.object == nil ? nil : $0 }
        let todayMinutes = (data?["today_worked_minutes"]?.int ?? 0) + (active != nil ? max(0, elapsedMinutes - (active?["elapsed_minutes"]?.int ?? 0)) : 0)
        return ListCard {
            row("Today's shift") {
                Button { env.navigator.go(.myShifts) } label: {
                    Text(today.map { "\($0["starts_at"]?.string ?? "") – \($0["ends_at"]?.string ?? "")\($0["label"]?.string.map { " · \($0)" } ?? "")" }
                         ?? next.map { "Next: \(MeFormat.weekday(MeFormat.date($0["date"]?.string))) \($0["starts_at"]?.string ?? "")" }
                         ?? "None rostered").fontWeight(.semibold)
                }.buttonStyle(.plain)
            }
            row("Today") { Text(MeFormat.clockMinutes(todayMinutes)).fontWeight(.semibold).monospacedDigit() }
            row("Leave balance") {
                Button { env.navigator.go(.myLeave) } label: { Text(annual?["available"]?.int.map { "\($0) days" } ?? "—").fontWeight(.semibold) }.buttonStyle(.plain)
            }
            row("This week") {
                Button { env.navigator.go(.myTimesheet) } label: { Text(MeFormat.clockMinutes(data?["week_worked_minutes"]?.int ?? 0)).fontWeight(.semibold) }.buttonStyle(.plain)
            }
            row("Last punch") {
                if let last {
                    HStack(spacing: 4) {
                        Text("\(MeFormat.when((last["clock_out_at"]?.date) ?? last["clock_in_at"]?.date)) · \(last["clock_out_at"]?.isNull == false ? "out" : "in")")
                        if last["clock_in_method"]?.string == "override" { StatusBadge("override", tone: .warning) }
                        FlagChips(last)
                    }
                } else { Text("—") }
            }
            if let shift = data?["today_shift"], shift.object != nil {
                row("Today's shift") { Text("\(shift["starts_at"]?.string ?? "")–\(shift["ends_at"]?.string ?? "")\(shift["outlet"]?.string.map { " · \($0)" } ?? "")").fontWeight(.semibold) }
            }
            if rules?["location_mode"]?.string == "trail" {
                ListRow {
                    Text(trailWords(rules)).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                }
            }
            if let auto = rules?["auto_clock_out_time"]?.string {
                ListRow { Text("A shift still open at \(auto) is closed automatically and shown to your manager.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
            }
        }
    }

    private func trailWords(_ rules: JSONValue?) -> String {
        #if os(iOS)
        var s = "While you are clocked in the app records your position every few minutes; it stops when you clock out."
        #else
        var s = "The phone app records your position while you are clocked in; this Mac records only your clock-in and clock-out."
        #endif
        if let action = rules?["leave_site_action"]?.string, action != "none" {
            s += " Leaving the site for more than \(rules?["leave_site_grace_minutes"]?.int ?? 0) minutes is \(action == "clock_out" ? "clocked out" : "noted for your manager")."
        }
        return s
    }

    private func row<V: View>(_ label: String, @ViewBuilder value: () -> V) -> some View {
        ListRow {
            HStack(alignment: .firstTextBaseline) {
                Text(label).font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                Spacer(minLength: Theme.Space.m)
                value().font(Theme.Font.secondary).multilineTextAlignment(.trailing)
            }
        }
    }

    // MARK: Punching (fields as ClockPage.tsx sends them)

    private func punchBody(_ fix: LocationFix) -> [String: JSONValue] {
        [
            "latitude": .number(fix.latitude),
            "longitude": .number(fix.longitude),
            "accuracy": .number(fix.accuracy),
            "recorded_at": .string(HubDates.iso8601.string(from: Date())),
            "mock_location": .bool(false),
        ]
    }

    private func punch(force: Bool = false) async {
        guard let fix, let outlet, let outletId = outlet["id"]?.int else { return }
        busy = true
        defer { busy = false }
        var body = punchBody(fix)
        let path: String
        if active != nil {
            path = "/v1/admin/time-clock/clock-out"
        } else {
            path = "/v1/admin/time-clock/clock-in"
            body["outlet_id"] = .number(Double(outletId))
            body["app_version"] = .string("app-\(env.session.device.platform.rawValue)-\(env.session.device.appVersion)")
            if force {
                body["force"] = .bool(true)
                body["reason"] = .string(reason.trimmingCharacters(in: .whitespaces))
            }
        }
        do {
            let r = try await env.session.client.post(path, json: .object(body))
            env.toasts.show(r.json?["message"]?.string ?? (active != nil ? "Clocked out" : "Clocked in"))
            askReason = false; reason = ""; serverAsksOverride = false
            await status?.reload()
        } catch {
            if isOfflineError(error) && !force, let offline = env.offline {
                let op = OfflineStore.PendingOperation(kind: "punch", method: "POST", path: path, body: .object(body), dedupeKey: "punch")
                await offline.enqueue(op)
                pending = op
                env.connection.pendingCount = await offline.pending().count
                env.toasts.show(active != nil ? "No signal. Your clock-out is saved and will be sent when you are back online." : "No signal. Your clock-in is saved and will be sent when you are back online.", tone: .info)
            } else {
                if let a = error as? APIError, a.raw?["requires_override"]?.bool == true { serverAsksOverride = true }
                env.toasts.error(error)
            }
        }
    }

    private func breakToggle(start: Bool) async {
        busy = true
        defer { busy = false }
        do {
            let r = try await env.session.client.post("/v1/admin/time-clock/break/\(start ? "start" : "end")")
            env.toasts.show(r.json?["message"]?.string ?? (start ? "Break started" : "Break ended"))
            await status?.reload()
        } catch { env.toasts.error(error) }
    }

    private func loadPending() async {
        pending = await env.offline?.pending().first { $0.kind == "punch" }
    }

    /// Sends the kept punch; a refusal drops it (the server said no), a
    /// transport failure keeps it for next time.
    private func sendPending() async {
        guard let pending, let offline = env.offline else { return }
        do {
            _ = try await env.session.client.send(.init(.post, pending.path, body: .json(pending.body)))
            await offline.dequeue(pending.id)
            self.pending = nil
            env.connection.pendingCount = await offline.pending().count
            env.toasts.show("Your \(pending.path.hasSuffix("clock-in") ? "clock-in" : "clock-out") from \(MeFormat.when(pending.recordedAt)) has been sent.")
            await status?.reload()
        } catch {
            if !isOfflineError(error) {
                await offline.dequeue(pending.id)
                self.pending = nil
                env.connection.pendingCount = await offline.pending().count
                env.toasts.show("The saved punch could not be recorded: \((error as? APIError)?.message ?? error.localizedDescription)", tone: .danger)
            }
        }
    }

    // MARK: Location on shift (rules.location_mode == "trail"; iOS only)

    private func syncTrail() {
        #if os(iOS)
        let rules = data?["rules"]
        if active != nil, rules?["location_mode"]?.string == "trail" {
            TrailRecorder.shared.start(client: env.session.client,
                                       intervalMinutes: rules?["trail_interval_minutes"]?.int ?? 5,
                                       distanceMeters: rules?["trail_distance_meters"]?.double ?? 100)
        } else {
            TrailRecorder.shared.stop()
        }
        #endif
    }
}

/// Flags the server attached to a punch, as short words (ClockPage FLAG_SHORT).
struct FlagChips: View {
    let entry: JSONValue
    init(_ entry: JSONValue) { self.entry = entry }
    private static let short: [String: String] = ["accuracy_low": "imprecise", "edge": "near the edge", "sent_late": "sent late", "mock_location": "mock location", "auto_clock_out": "auto closed"]
    var body: some View {
        let flags = entry["flags"]?.array?.compactMap(\.string) ?? []
        if !flags.isEmpty {
            HStack(spacing: 4) { ForEach(flags, id: \.self) { StatusBadge(Self.short[$0] ?? $0, tone: .warning) } }
        }
    }
}

#if os(iOS)
/// Records the position every few minutes between clock-in and clock-out
/// and posts the points to `/v1/admin/time-clock/trail` (TimeClockController::trail
/// validates `points[].latitude|longitude|accuracy|recorded_at|mocked`).
/// Runs in the background with the `location` background mode; stops at
/// clock-out, and the server itself ignores points when the rule is off.
@MainActor
@Observable
final class TrailRecorder {
    static let shared = TrailRecorder()
    private(set) var isRunning = false
    private let location = LocationService()
    private var client: APIClient?
    private var buffer: [LocationFix] = []
    private var ticker: Task<Void, Never>?
    private var lastFlush = Date.distantPast

    func start(client: APIClient, intervalMinutes: Int, distanceMeters: Double) {
        guard !isRunning else { self.client = client; return }
        self.client = client
        isRunning = true
        location.onFix = { [weak self] fix in self?.buffer.append(fix) }
        Task { await location.startBackgroundTrail(distanceMeters: distanceMeters) }
        ticker = Task { [weak self] in
            while !Task.isCancelled {
                try? await Task.sleep(for: .seconds(max(60, intervalMinutes * 60)))
                guard let self else { return }
                if self.buffer.isEmpty, let f = self.location.fix { self.buffer.append(LocationFix(latitude: f.latitude, longitude: f.longitude, accuracy: f.accuracy, timestamp: Date())) }
                await self.flush()
            }
        }
    }

    func stop() {
        guard isRunning else { return }
        ticker?.cancel()
        ticker = nil
        location.onFix = nil
        location.stopBackgroundTrail()
        isRunning = false
        Task { await flush() }
    }

    private func flush() async {
        guard let client, !buffer.isEmpty else { return }
        let points = Array(buffer.suffix(200))
        buffer.removeAll()
        do {
            let r = try await client.post("/v1/admin/time-clock/trail", json: .object(["points": .array(points.map(\.trailPoint))]))
            lastFlush = Date()
            if r.json?["tracking"]?.bool == false { stop() }
        } catch {
            if isOfflineError(error) { buffer = points + buffer }
        }
    }
}
#endif
