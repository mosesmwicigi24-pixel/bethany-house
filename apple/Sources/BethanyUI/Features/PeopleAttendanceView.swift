import SwiftUI
import MapKit
import BethanyKit

/// Attendance (/settings/attendance): who is in now, flagged entries, the
/// entries list with filters, an entry's detail (kiosk photos, trail map,
/// correction) and the clock-in kiosks.
struct AttendanceView: View {
    @Environment(AppEnvironment.self) private var env
    enum Tab: Hashable { case live, flagged, entries, kiosks }
    @State private var tab: Tab = .live
    @State private var version = 0
    @State private var openEntry: IDRef?

    var body: some View {
        Page("Attendance", subtitle: "Time clock oversight") {
            Tabs(selection: $tab, items: [(.live, "In now"), (.flagged, "Flagged"), (.entries, "Entries")] + (env.session.can("attendance.manage") ? [(.kiosks, "Kiosks")] : []))
            switch tab {
            case .live: liveBoard
            case .flagged: flagged
            case .entries: AttendanceEntriesList(openEntry: $openEntry, version: version)
            case .kiosks: KiosksPanel()
            }
        }
        .sheet(item: $openEntry) { ref in
            AttendanceEntrySheet(entryId: ref.id) { version += 1 }
        }
    }

    private var liveBoard: some View {
        RemoteJSON("/v1/admin/attendance/live", version: version, emptyTitle: "Nobody is clocked in", isEmpty: { ($0["data"]?.array ?? []).isEmpty }) { j, _ in
            TileGrid(minWidth: 140) {
                StatTile("Clocked in", value: j["count"]?.string ?? "0")
                StatTile("On break", value: j["on_break"]?.string ?? "0")
                StatTile("Flagged", value: j["flagged"]?.string ?? "0", tone: (j["flagged"]?.int ?? 0) > 0 ? .warning : nil)
            }
            ForEach(j["data"]?.records ?? []) { e in entryRow(e) }
        }
    }

    private var flagged: some View {
        RemoteJSON("/v1/admin/attendance/flagged", version: version, emptyTitle: "No flagged entries", isEmpty: { ($0["data"]?.array ?? []).isEmpty }) { j, _ in
            ForEach(j["data"]?.records ?? []) { e in entryRow(e) }
        }
    }

    private func entryRow(_ e: Record) -> some View {
        Button { openEntry = e.int("id").map(IDRef.init) } label: {
            AttendanceEntryRow(entry: e)
        }
        .buttonStyle(.plain)
    }
}

struct AttendanceEntryRow: View {
    let entry: Record
    var body: some View {
        SupportListRow(entry["user"]?["name"]?.string ?? "—", subtitle: "\(entry["outlet"]?["name"]?.string ?? "No outlet") · in \(Day.dateTime(entry.string("clock_in_at")))\(entry.string("clock_out_at").map { " · out \(Day.time($0))" } ?? "")") {
            VStack(alignment: .trailing, spacing: 4) {
                HStack(spacing: 4) {
                    StatusBadge(entry.status)
                    if entry["on_break"]?.bool == true { Pill("break", .pink) }
                    Pill(entry.string("clock_in_method") ?? "gps", .neutral)
                }
                let flags = entry["flag_labels"]?.array?.compactMap(\.string) ?? []
                if !flags.isEmpty { Text(flags.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning).lineLimit(2) }
                Text(minutesText(entry.int("worked_minutes") ?? entry.int("elapsed_minutes"))).font(Theme.Font.caption).monospacedDigit().foregroundStyle(Theme.textSecondary)
            }
        }
    }
}

/// GET /attendance/entries?outlet_id&user_id&status&from&to (paginated)
struct AttendanceEntriesList: View {
    @Environment(AppEnvironment.self) private var env
    @Binding var openEntry: IDRef?
    let version: Int
    @State private var list: PagedList<Record>?
    @State private var outlet: Int?
    @State private var user: Int?
    @State private var status = ""
    @State private var from = Day.add(Day.today(), days: -7)
    @State private var to = Day.today()

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            HStack(alignment: .bottom, spacing: Theme.Space.s) {
                OutletChoice("Outlet", $outlet, allowAll: true)
                StaffPicker("Person", $user, allowNone: true)
                OptionPicker("Status", $status, options: [("", "Any"), ("active", "Active"), ("completed", "Completed"), ("flagged", "Flagged")])
            }
            HStack(alignment: .bottom) {
                DayField("From", $from)
                DayField("To", $to)
                Spacer()
                Button("Apply") { Task { await reload() } }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
            }
            if let list {
                LoadableView(list.state, retry: { Task { await list.load() } }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No entries in this range", symbol: "clock") }) { rows in
                    LazyVStack(spacing: Theme.Space.s) {
                        ForEach(rows) { e in
                            Button { openEntry = e.int("id").map(IDRef.init) } label: { AttendanceEntryRow(entry: e) }.buttonStyle(.plain)
                                .onAppear { if e.id == rows.last?.id { Task { await list.loadMore() } } }
                        }
                    }
                    Text("\(list.total) entries").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                }
            }
        }
        .task { if list == nil { list = PagedList(client: env.session.client, path: "/v1/admin/attendance/entries"); await reload() } }
        .onChange(of: version) { _, _ in Task { await reload() } }
    }

    private func reload() async {
        list?.query = ["outlet_id": outlet.map(String.init), "user_id": user.map(String.init), "status": status.isEmpty ? nil : status, "from": from, "to": to]
        await list?.load()
    }
}

// MARK: - Entry detail (GET /attendance/entries/{id}, /photo/{in|out}, /trail; PUT entries/{id})

struct AttendanceEntrySheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let entryId: Int
    let changed: () -> Void
    @State private var entry: Record?
    @State private var error: APIError?
    @State private var editing = false
    @State private var showTrail = false

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.l) {
            HStack {
                Text("Entry #\(entryId)").font(Theme.Font.sectionTitle)
                Spacer()
                Button("Close") { dismiss() }
            }
            if let e = entry {
                ScrollView {
                    VStack(alignment: .leading, spacing: Theme.Space.m) {
                        HStack { Avatar(name: e["user"]?["name"]?.string ?? "?"); Text(e["user"]?["name"]?.string ?? "—").font(Theme.Font.body.weight(.semibold)); Spacer(); StatusBadge(e.status) }
                        KeyValueGrid(pairs: [
                            ("Outlet", e["outlet"]?["name"]?.string ?? "—"),
                            ("Clock in", Day.dateTime(e.string("clock_in_at"))),
                            ("Clock out", e.string("clock_out_at").map(Day.dateTime) ?? "—"),
                            ("Method", e.string("clock_in_method")?.humanized ?? "—"),
                            ("Worked", minutesText(e.int("worked_minutes"))),
                            ("Breaks", minutesText(e.int("total_break_minutes"))),
                            ("Distance at clock-in", e.int("clock_in_distance_meters").map { "\($0) m" } ?? "—"),
                            ("Accuracy", e.int("clock_in_accuracy_meters").map { "±\($0) m" } ?? "—"),
                            ("Device", e.string("device_info") ?? "—"),
                            ("Left site", e.string("left_site_at").map(Day.dateTime) ?? "—"),
                            ("Trail points", e.int("trail_points").map(String.init) ?? "0"),
                            ("Overridden by", e.string("overridden_by") ?? "—"),
                            ("Corrected by", e.string("corrected_by") ?? "—"),
                        ])
                        let flags = e["flag_labels"]?.array?.compactMap(\.string) ?? []
                        if !flags.isEmpty {
                            SectionCard("Why a manager should look") { ForEach(flags, id: \.self) { Label($0, systemImage: "flag").font(Theme.Font.secondary).foregroundStyle(Theme.Palette.warning) } }
                        }
                        if let reason = e.string("flagged_reason") { Text(reason).font(Theme.Font.secondary) }
                        if let notes = e.string("notes"), !notes.isEmpty { SectionCard("Notes") { Text(notes).font(Theme.Font.secondary) } }
                        if let breaks = e["breaks"]?.array, !breaks.isEmpty {
                            SectionCard("Breaks") {
                                ForEach(Array(breaks.enumerated()), id: \.offset) { _, b in
                                    Text("\(Day.time(b["started_at"]?.string)) – \(b["ended_at"]?.string.map(Day.time) ?? "open")").font(Theme.Font.secondary)
                                }
                            }
                        }
                        if e["photos"]?["in"]?.bool == true || e["photos"]?["out"]?.bool == true {
                            SectionCard("Kiosk photos") {
                                HStack(spacing: Theme.Space.m) {
                                    if e["photos"]?["in"]?.bool == true { VStack { Text("In").font(Theme.Font.caption); SignedImage(source: "/api/v1/admin/attendance/entries/\(entryId)/photo/in", maxHeight: 180) } }
                                    if e["photos"]?["out"]?.bool == true { VStack { Text("Out").font(Theme.Font.caption); SignedImage(source: "/api/v1/admin/attendance/entries/\(entryId)/photo/out", maxHeight: 180) } }
                                }
                            }
                        }
                        if showTrail { TrailMapView(entryId: entryId) } else {
                            Button { showTrail = true } label: { Label("Show location trail", systemImage: "map") }
                        }
                    }
                }
                HStack {
                    Spacer()
                    if env.session.can("attendance.manage") {
                        Button("Correct entry…") { editing = true }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                    }
                }
            } else if let error {
                ErrorState(error, retry: { Task { await load() } })
            } else {
                LoadingSkeleton()
            }
        }
        .padding(Theme.Space.xl)
        #if os(macOS)
        .frame(minWidth: 560, minHeight: 520)
        #endif
        .task { await load() }
        .sheet(isPresented: $editing) {
            if let e = entry { AttendanceEntryEditSheet(entry: e) { Task { await load() }; changed() } }
        }
    }

    private func load() async {
        do {
            let j = try await env.session.client.json("/v1/admin/attendance/entries/\(entryId)")
            entry = (j["entry"]).map(Record.init(json:))
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}

struct AttendanceEntryEditSheet: View {
    @Environment(AppEnvironment.self) private var env
    let entry: Record
    let saved: () -> Void
    @State private var clockIn: Date
    @State private var hasOut: Bool
    @State private var clockOut: Date
    @State private var status: String
    @State private var notes: String

    init(entry: Record, saved: @escaping () -> Void) {
        self.entry = entry
        self.saved = saved
        _clockIn = State(initialValue: entry.date("clock_in_at") ?? Date())
        _hasOut = State(initialValue: entry.string("clock_out_at") != nil)
        _clockOut = State(initialValue: entry.date("clock_out_at") ?? Date())
        _status = State(initialValue: entry.status ?? "completed")
        _notes = State(initialValue: entry.string("notes") ?? "")
    }

    var body: some View {
        EditSheet("Correct entry #\(entry.id)", save: {
            let r = try await env.session.client.put("/v1/admin/attendance/entries/\(entry.id)", json: J.obj([
                ("clock_in_at", .string(HubDates.iso8601.string(from: clockIn))),
                ("clock_out_at", hasOut ? .string(HubDates.iso8601.string(from: clockOut)) : .null),
                ("status", .string(status)),
                ("notes", J.s(notes)),
            ]))
            toastResult(env, r, fallback: "Time entry updated")
            saved()
        }) { e in
            DatePicker("Clock in", selection: $clockIn).environment(\.timeZone, Day.nairobi)
            ToggleRow("Clocked out", $hasOut)
            if hasOut { DatePicker("Clock out", selection: $clockOut).environment(\.timeZone, Day.nairobi) }
            FieldError(e?.errors["clock_out_at"]?.first)
            OptionPicker("Status", $status, options: [("active", "Active"), ("completed", "Completed"), ("flagged", "Flagged")])
            Field("Notes", $notes, error: e?.errors["notes"]?.first, multiline: true)
        }
    }
}

// MARK: - Trail map (GET /attendance/entries/{id}/trail)

struct TrailMapView: View {
    @Environment(AppEnvironment.self) private var env
    let entryId: Int
    @State private var trail: JSONValue?
    @State private var error: APIError?

    var body: some View {
        SectionCard("Location trail") {
            if let t = trail {
                let points = (t["points"]?.array ?? []).compactMap { p -> CLLocationCoordinate2D? in
                    guard let la = p["latitude"]?.double, let lo = p["longitude"]?.double else { return nil }
                    return CLLocationCoordinate2D(latitude: la, longitude: lo)
                }
                let inPt = coord(t["clock_in"]), outPt = coord(t["clock_out"]), site = coord(t["outlet"])
                let all = points + [inPt, outPt, site].compactMap { $0 }
                if all.isEmpty {
                    Text("No location was recorded for this entry.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                } else {
                    Map(initialPosition: .region(region(for: all))) {
                        if let site, let r = t["outlet"]?["radius"]?.double {
                            MapCircle(center: site, radius: r).foregroundStyle(Theme.Palette.brand.opacity(0.15)).stroke(Theme.Palette.brand, lineWidth: 1)
                            Annotation(t["outlet"]?["name"]?.string ?? "Outlet", coordinate: site) { Image(systemName: "building.2.fill").foregroundStyle(Theme.Palette.brandDeep) }
                        }
                        if points.count > 1 { MapPolyline(coordinates: points).stroke(Theme.Palette.info, lineWidth: 3) }
                        ForEach(Array((t["points"]?.array ?? []).enumerated()), id: \.offset) { _, p in
                            if p["mocked"]?.bool == true, let c = coord(p) {
                                Annotation("Mock location", coordinate: c) { Image(systemName: "exclamationmark.triangle.fill").foregroundStyle(Theme.Palette.danger) }
                            }
                        }
                        if let inPt { Annotation("In \(Day.time(t["clock_in"]?["at"]?.string))", coordinate: inPt) { Image(systemName: "arrow.down.circle.fill").foregroundStyle(Theme.Palette.success) } }
                        if let outPt { Annotation("Out \(Day.time(t["clock_out"]?["at"]?.string))", coordinate: outPt) { Image(systemName: "arrow.up.circle.fill").foregroundStyle(Theme.Palette.danger) } }
                    }
                    .frame(height: 300)
                    .clipShape(RoundedRectangle(cornerRadius: Theme.Radius.field))
                    Text("\(points.count) points · kept \(t["retention_days"]?.int ?? 0) days\(t["left_site_at"]?.string.map { " · left site \(Day.time($0))" } ?? "")")
                        .font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                }
            } else if let error {
                ErrorState(error, retry: { Task { await load() } })
            } else {
                ProgressView()
            }
        }
        .task { await load() }
    }

    private func coord(_ v: JSONValue?) -> CLLocationCoordinate2D? {
        guard let v, let la = v["latitude"]?.double, let lo = v["longitude"]?.double else { return nil }
        return CLLocationCoordinate2D(latitude: la, longitude: lo)
    }

    private func region(for pts: [CLLocationCoordinate2D]) -> MKCoordinateRegion {
        let lats = pts.map(\.latitude), lons = pts.map(\.longitude)
        let center = CLLocationCoordinate2D(latitude: (lats.min()! + lats.max()!) / 2, longitude: (lons.min()! + lons.max()!) / 2)
        let span = MKCoordinateSpan(latitudeDelta: max(0.004, (lats.max()! - lats.min()!) * 1.5), longitudeDelta: max(0.004, (lons.max()! - lons.min()!) * 1.5))
        return MKCoordinateRegion(center: center, span: span)
    }

    private func load() async {
        do { trail = try await env.session.client.json("/v1/admin/attendance/entries/\(entryId)/trail") }
        catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}

// MARK: - Kiosks (GET/POST/DELETE /attendance/kiosks; POST needs step-up)

struct KiosksPanel: View {
    @Environment(AppEnvironment.self) private var env
    @State private var adding = false

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            HStack {
                Text("Clock-in kiosks").font(Theme.Font.sectionTitle)
                Spacer()
                #if os(iOS)
                Button { adding = true } label: { Label("Make this tablet a kiosk", systemImage: "ipad.landscape") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                #endif
            }
            Text("A kiosk is a tablet at the door: staff tap their name and type their terminal PIN to clock in or out. It reaches only the clock-in board, and lasts until it is removed here or reaches its time limit.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            RemoteJSON("/v1/admin/attendance/kiosks", emptyTitle: "No kiosks set up", isEmpty: { ($0["data"]?.array ?? []).isEmpty }) { j, reload in
                ForEach(j["data"]?.records ?? []) { k in
                    let expires = k.string("expires_at").map { " · works until \(Day.format($0, "d MMM yyyy"))" } ?? ""
                    SupportListRow(k.string("name") ?? "Kiosk", subtitle: "\(k.string("outlet") ?? "—") · set up by \(k.string("set_up_by") ?? "—") · last used \(k.string("last_used_at").map(Day.dateTime) ?? "never")\(expires)") {
                        ConfirmButton("Remove kiosk", message: "The tablet goes back to the sign-in screen next time it checks in.", destructive: true, action: {
                            do { let r = try await env.session.client.delete("/v1/admin/attendance/kiosks/\(k.id)"); toastResult(env, r); reload() } catch { env.toasts.error(error) }
                        }) { Image(systemName: "trash") }
                    }
                }
            }
        }
        .sheet(isPresented: $adding) { KioskSetupSheet() }
    }
}

/// Makes THIS device the kiosk, as the console does: the Hub mints the
/// kiosk-only token (step-up), the app keeps it under its own Keychain
/// account, the manager is signed out and the clock-in board opens. The
/// token is never shown or shared.
struct KioskSetupSheet: View {
    @Environment(AppEnvironment.self) private var env
    @State private var outlet: Int?
    @State private var name = ""

    var body: some View {
        EditSheet("Make this tablet a kiosk", saveLabel: "Make this tablet a kiosk", canSave: outlet != nil && !name.trimmingCharacters(in: .whitespaces).isEmpty, save: {
            let r = try await env.session.client.post("/v1/admin/attendance/kiosks", json: J.obj([("outlet_id", J.i(outlet)), ("name", .string(name.trimmingCharacters(in: .whitespaces)))]))
            guard let token = r.json?["token"]?.string else { throw APIError(status: 0, message: "The Hub did not return a kiosk token.") }
            env.kiosk.enter(token)
            await env.session.signOut()
            await env.sessionDidChange()
        }) { e in
            OutletChoice("Outlet", $outlet)
            Field("Tablet name", $name, placeholder: "Front door", error: e?.errors["name"]?.first)
            Text("You will be signed out of this device and the clock-in board opens. Staff need a terminal PIN (Profile › Security) to punch. You will be asked to confirm it is you.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
    }
}
