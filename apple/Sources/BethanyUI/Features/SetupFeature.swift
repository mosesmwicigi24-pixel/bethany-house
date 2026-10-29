import SwiftUI
import BethanyKit

/// Setup: business, outlets, attendance rules, automations, holidays, roles,
/// users, reference data, commerce settings, production configuration,
/// activity logs, trash, database and downloads. Money-relevant settings
/// (rates, tax, settlement) go through the server's proposals exactly as the
/// console sends them; the screen only shows the answer.
@MainActor
public enum SetupFeature {
    public static func register() {
        FeatureRegistry.register(.setupBusiness) { _ in AnyView(BusinessSettingsView()) }
        FeatureRegistry.register(.outlets) { _ in AnyView(OutletsView()) }
        FeatureRegistry.register(.attendanceRules) { _ in AnyView(AttendanceRulesView()) }
        FeatureRegistry.register(.automations) { _ in AnyView(AutomationsView()) }
        FeatureRegistry.register(.holidays) { _ in AnyView(HolidaysView()) }
        FeatureRegistry.register(.roles) { _ in AnyView(RolesView()) }
        FeatureRegistry.register(.users) { _ in AnyView(UsersView()) }
        FeatureRegistry.register(.countries) { _ in AnyView(CountriesView()) }
        FeatureRegistry.register(.currencies) { _ in AnyView(CurrenciesView()) }
        FeatureRegistry.register(.languages) { _ in AnyView(LanguagesView()) }
        FeatureRegistry.register(.taxRates) { _ in AnyView(TaxRatesView()) }
        FeatureRegistry.register(.discountLimits) { _ in AnyView(DiscountLimitsView()) }
        FeatureRegistry.register(.paymentMethods) { _ in AnyView(PaymentMethodsView()) }
        FeatureRegistry.register(.shipping) { _ in AnyView(ShippingView()) }
        FeatureRegistry.register(.productionStages) { _ in AnyView(ProductionStagesView()) }
        FeatureRegistry.register(.autoAssignees) { _ in AnyView(AutoAssigneesView()) }
        FeatureRegistry.register(.activityLogs) { _ in AnyView(ActivityLogsView()) }
        FeatureRegistry.register(.trash) { _ in AnyView(TrashView()) }
        FeatureRegistry.register(.database) { _ in AnyView(DatabaseView()) }
        FeatureRegistry.register(.downloads) { _ in AnyView(DownloadApprovalsView()) }
        FeatureRegistry.register(.myDownloads) { _ in AnyView(MyDownloadsView()) }
    }
}

// MARK: - Business (GET/PUT /settings; POST /settings/logo — step-up)

struct BusinessSettingsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var f: [String: String] = [:]
    @State private var flags: [String: Bool] = [:]
    @State private var loaded = false
    @State private var logoURL: String?
    @State private var error: APIError?
    static let text: [(String, String)] = [("app_name", "Business name"), ("app_tagline", "Tagline"), ("app_email", "Email"), ("app_phone", "Phone"), ("app_address", "Address"), ("app_city", "City"), ("app_country", "Country code"), ("app_timezone", "Timezone"), ("default_currency", "Default currency"), ("default_language", "Default language"), ("order_prefix", "Order prefix"), ("receipt_footer", "Receipt footer"), ("low_stock_threshold", "Low stock threshold")]
    static let bools: [(String, String)] = [("tax_inclusive", "Prices include tax"), ("enable_guest_checkout", "Guest checkout"), ("enable_reviews", "Product reviews"), ("maintenance_mode", "Maintenance mode")]

    var body: some View {
        Page("Business", subtitle: "Identity, defaults and storefront switches") {
            if !loaded { LoadingSkeleton() } else {
                SectionCard("Logo") {
                    HStack(spacing: Theme.Space.l) {
                        if let logoURL { SignedImage(source: logoURL, maxHeight: 80) }
                        if env.session.can("settings.edit") {
                            AttachFileButton(label: "Upload logo") { file in
                                Task {
                                    do { let r = try await env.session.client.upload("/v1/admin/settings/logo", parts: [file.part(named: "logo")]); toastResult(env, r); logoURL = r.json?["url"]?.string } catch { env.toasts.error(error) }
                                }
                            }
                        }
                    }
                }
                SectionCard("Details") {
                    ForEach(Self.text, id: \.0) { k in Field(k.1, bind(k.0), error: error?.errors[k.0]?.first, multiline: k.0 == "receipt_footer" || k.0 == "app_address") }
                }
                SectionCard("Switches") {
                    ForEach(Self.bools, id: \.0) { k in ToggleRow(k.1, Binding(get: { flags[k.0] ?? false }, set: { flags[k.0] = $0 })) }
                }
                if env.session.can("settings.edit") {
                    AsyncButton("Save (asks you to confirm it's you)") {
                        var pairs: [(String, JSONValue?)] = Self.text.map { ($0.0, J.s(f[$0.0]) as JSONValue?) }
                        pairs.append(("low_stock_threshold", J.typed(f["low_stock_threshold"] ?? "")))
                        for (k, _) in Self.bools { pairs.append((k, .bool(flags[k] ?? false))) }
                        do { let r = try await env.session.client.put("/v1/admin/settings", json: J.obj(pairs)); toastResult(env, r); error = nil } catch let e as APIError { error = e; throw e }
                    }
                    .buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                }
            }
        }
        .task {
            guard !loaded, let s = try? await env.session.client.json("/v1/admin/settings")["settings"] else { return }
            for (k, _) in Self.text { f[k] = s[k]?.string ?? "" }
            for (k, _) in Self.bools { flags[k] = s[k]?.bool ?? false }
            logoURL = s["app_logo_url"]?.string
            loaded = true
        }
    }
    private func bind(_ k: String) -> Binding<String> { Binding(get: { f[k] ?? "" }, set: { f[k] = $0 }) }
}

// MARK: - Attendance rules (GET/PUT /attendance/rules; PUT needs settings.edit)

struct AttendanceRulesView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var f: [String: String] = [:]
    @State private var flags: [String: Bool] = [:]
    @State private var days: Set<Int> = []
    @State private var loaded = false
    static let numbers: [(String, String)] = [("auto_clock_out_time", "Auto clock-out at (HH:mm)"), ("auto_clock_out_after_hours", "Auto clock-out after (hours)"), ("daily_limit_hours", "Daily limit (hours)"), ("over_scheduled_minutes", "Flag when over schedule by (min)"), ("under_scheduled_minutes", "Flag when under schedule by (min)"), ("earliest_clock_in_minutes", "Earliest clock-in before shift (min)"), ("trail_interval_minutes", "Trail every (min)"), ("trail_distance_meters", "Trail every (metres)"), ("leave_site_grace_minutes", "Leaving site grace (min)"), ("leave_site_distance_meters", "Leaving site distance (m)"), ("trail_retention_days", "Keep trails (days)")]

    var body: some View {
        Page("Attendance rules", subtitle: "How the time clock behaves for everyone") {
            if !loaded { LoadingSkeleton() } else {
                SectionCard("Working days") {
                    HStack { ForEach(1...7, id: \.self) { d in Button(["Mo", "Tu", "We", "Th", "Fr", "Sa", "Su"][d - 1]) { if days.contains(d) { days.remove(d) } else { days.insert(d) } }.buttonStyle(.bordered).tint(days.contains(d) ? Theme.Palette.brand : Theme.Palette.neutral) } }
                }
                SectionCard("Clocking") {
                    OptionPicker("Clock in without a rostered shift", bind("clock_in_without_shift"), options: [("allow", "Allow"), ("flag", "Allow and flag"), ("refuse", "Refuse")])
                    ToggleRow("Remind to clock in", Binding(get: { flags["remind_clock_in"] ?? false }, set: { flags["remind_clock_in"] = $0 }))
                    ToggleRow("Remind to clock out", Binding(get: { flags["remind_clock_out"] ?? false }, set: { flags["remind_clock_out"] = $0 }))
                }
                SectionCard("Location") {
                    OptionPicker("Location mode", bind("location_mode"), options: [("off", "Off"), ("punch", "At clock in/out"), ("trail", "Trail while on shift")])
                    OptionPicker("When someone leaves the site", bind("leave_site_action"), options: [("none", "Nothing"), ("flag", "Flag the entry"), ("clock_out", "Clock them out")])
                }
                SectionCard("Limits") {
                    ForEach(Self.numbers, id: \.0) { k in Field(k.1, bind(k.0)) }
                }
                if env.session.can("settings.edit") {
                    AsyncButton("Save rules") {
                        var pairs: [(String, JSONValue?)] = Self.numbers.map { ($0.0, J.typed(f[$0.0] ?? "") as JSONValue?) }
                        pairs.append(("auto_clock_out_time", J.s(f["auto_clock_out_time"])))
                        for k in ["clock_in_without_shift", "location_mode", "leave_site_action"] { pairs.append((k, J.s(f[k]))) }
                        for k in ["remind_clock_in", "remind_clock_out"] { pairs.append((k, .bool(flags[k] ?? false))) }
                        pairs.append(("working_days", J.ints(Array(days).sorted())))
                        let r = try await env.session.client.put("/v1/admin/attendance/rules", json: J.obj(pairs)); toastResult(env, r)
                    }
                    .buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                }
            }
        }
        .task {
            guard !loaded, let r = try? await env.session.client.json("/v1/admin/attendance/rules")["rules"] else { return }
            for (k, _) in Self.numbers { f[k] = r[k]?.string ?? "" }
            for k in ["clock_in_without_shift", "location_mode", "leave_site_action"] { f[k] = r[k]?.string ?? "" }
            for k in ["remind_clock_in", "remind_clock_out"] { flags[k] = r[k]?.bool ?? false }
            days = Set(r["working_days"]?.array?.compactMap(\.int) ?? [])
            loaded = true
        }
    }
    private func bind(_ k: String) -> Binding<String> { Binding(get: { f[k] ?? "" }, set: { f[k] = $0 }) }
}

// MARK: - Automations (GET /attendance/automations; PUT /automations/{key}; PUT /recognition-points)

struct AutomationsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var version = 0
    @State private var pointKes = ""

    var body: some View {
        Page("Automations", subtitle: "The alerts the hub sends managers, and the value of a recognition point") {
            RemoteJSON("/v1/admin/attendance/automations", version: version) { j, reload in
                let people = j["people"]?.records ?? []
                ForEach(j["automations"]?.records ?? []) { a in AutomationCard(automation: a, people: people, reload: reload) }
                SectionCard("Recognition points") {
                    Text("A point given on the Updates feed is worth this much in KES on the person's file.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                    HStack(alignment: .bottom) {
                        Field("KES per point", $pointKes, placeholder: j["recognition_point_kes"]?.string ?? "")
                        if env.session.can("settings.edit") {
                            AsyncButton("Save") { let r = try await env.session.client.put("/v1/admin/attendance/recognition-points", json: J.obj([("kes", J.typed(pointKes))])); toastResult(env, r); reload() }.disabled(pointKes.isEmpty)
                        }
                    }
                }
            }
        }
    }
}

struct AutomationCard: View {
    @Environment(AppEnvironment.self) private var env
    let automation: Record
    let people: [Record]
    let reload: () -> Void
    @State private var enabled: Bool
    @State private var threshold: String
    @State private var recipients: String
    @State private var extra: Set<Int>

    init(automation: Record, people: [Record], reload: @escaping () -> Void) {
        self.automation = automation; self.people = people; self.reload = reload
        _enabled = State(initialValue: automation["enabled"]?.bool ?? false)
        _threshold = State(initialValue: automation["threshold"]?.string ?? "")
        _recipients = State(initialValue: automation.string("recipients") ?? "managers")
        _extra = State(initialValue: Set(automation["extra_user_ids"]?.array?.compactMap(\.int) ?? []))
    }

    var body: some View {
        SectionCard(automation.string("label") ?? automation.string("key") ?? "") {
            Text(automation.string("text") ?? "").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            ToggleRow("Enabled", $enabled)
            if let unit = automation.string("unit") { Field("Threshold (\(unit), \(automation.int("min") ?? 0)–\(automation.int("max") ?? 0))", $threshold) }
            OptionPicker("Send to", $recipients, options: [("managers", "Outlet managers"), ("hr", "HR"), ("owner", "Owner"), ("finance", "Finance")])
            Menu("Also: \(extra.count) people") {
                ForEach(people) { p in
                    let id = p.int("id") ?? 0
                    Button { if extra.contains(id) { extra.remove(id) } else { extra.insert(id) } } label: { Label(p.string("name") ?? "", systemImage: extra.contains(id) ? "checkmark.circle.fill" : "circle") }
                }
            }
            if automation["has_recipients"]?.bool == false { Text("Nobody would receive this yet.").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning) }
            if env.session.can("settings.edit") {
                AsyncButton("Save") {
                    let r = try await env.session.client.put("/v1/admin/attendance/automations/\(automation.string("key") ?? "")", json: J.obj([("enabled", .bool(enabled)), ("threshold", J.typed(threshold)), ("recipients", .string(recipients)), ("extra_user_ids", J.ints(Array(extra)))]))
                    toastResult(env, r); reload()
                }
            }
        }
    }
}

// MARK: - Holidays (GET /holidays?year; POST/PUT/DELETE need hr.edit)

struct HolidaysView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var year = Day.thisYear()
    @State private var version = 0
    @State private var editing: HolidayDraft?

    var body: some View {
        Page("Public holidays", subtitle: "Days the roster and leave count as holidays") {
            HStack {
                Button { year -= 1 } label: { Image(systemName: "chevron.left") }
                Text(String(year)).font(Theme.Font.sectionTitle)
                Button { year += 1 } label: { Image(systemName: "chevron.right") }
                Spacer()
                if env.session.can("hr.edit") { Button { editing = HolidayDraft() } label: { Label("Add holiday", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
            }
            RemoteJSON("/v1/admin/holidays", query: ["year": String(year)], version: version, emptyTitle: "No holidays recorded for \(year)", isEmpty: { ($0["data"]?.array ?? []).isEmpty }) { j, reload in
                ForEach(j["data"]?.records ?? []) { h in
                    SupportListRow(h.string("name") ?? "", subtitle: "\(Day.format(h.string("date"), "EEEE d MMMM")) · \(h.string("country") ?? "")\(h.string("notes").map { " · \($0)" } ?? "")") {
                        HStack {
                            Pill(h["is_confirmed"]?.bool == true ? "confirmed" : "expected", h["is_confirmed"]?.bool == true ? .success : .amber)
                            if env.session.can("hr.edit") {
                                Button("Edit") { editing = HolidayDraft(record: h) }
                                AsyncButton(role: .destructive) { let r = try await env.session.client.delete("/v1/admin/holidays/\(h.id)"); toastResult(env, r); reload() } label: { Image(systemName: "trash") }
                            }
                        }
                    }
                }
            }
        }
        .sheet(item: $editing) { d in HolidaySheet(draft: d) { version += 1 } }
    }
}

struct HolidayDraft: Identifiable {
    var id: Int?
    var date = Day.today()
    var name = ""
    var confirmed = true
    var notes = ""
    init() {}
    init(record r: Record) { id = r.int("id"); date = r.string("date") ?? Day.today(); name = r.string("name") ?? ""; confirmed = r["is_confirmed"]?.bool ?? true; notes = r.string("notes") ?? "" }
}

struct HolidaySheet: View {
    @Environment(AppEnvironment.self) private var env
    @State var draft: HolidayDraft
    let saved: () -> Void
    var body: some View {
        EditSheet(draft.id == nil ? "Add holiday" : "Edit holiday", canSave: !draft.name.isEmpty, save: {
            let body = J.obj([("date", .string(draft.date)), ("name", .string(draft.name)), ("is_confirmed", .bool(draft.confirmed)), ("notes", J.s(draft.notes))])
            let r: APIClient.Response
            if let id = draft.id { r = try await env.session.client.put("/v1/admin/holidays/\(id)", json: body) } else { r = try await env.session.client.post("/v1/admin/holidays", json: body) }
            toastResult(env, r); saved()
        }) { e in
            DayField("Date", $draft.date)
            Field("Name", $draft.name, error: e?.errors["name"]?.first ?? e?.errors["date"]?.first)
            ToggleRow("Confirmed (gazetted)", $draft.confirmed)
            Field("Notes", $draft.notes)
        }
    }
}
