import SwiftUI
import BethanyKit
#if os(macOS)
import AppKit
import PDFKit
#endif

// The shared report frame (react-admin/src/pages/reports/reportShared.tsx):
// date presets and a custom range in Nairobi time, the outlet filter
// (reports/outlets), Export CSV (?export=csv on the page's own endpoint),
// Download PDF (/v1/admin/reports/pdf/{type}), print on macOS, the
// "records behind this number" drill (reports/drill/{metric}) and the
// scheduled-report sheet (reports/schedules).
//
// Every figure a report shows is the server's (MetricEngine or
// ReportController). This file never aggregates, converts or rounds money.

// MARK: - Date window

enum DatePreset: String, CaseIterable, Identifiable {
    case today, yesterday, this_week, last_week, last_7_days, last_30_days, last_60_days, last_90_days
    case this_month, last_month, this_quarter, last_quarter, this_year, last_year, custom
    var id: String { rawValue }
    var label: String {
        switch self {
        case .today: return "Today"
        case .yesterday: return "Yesterday"
        case .this_week: return "This week"
        case .last_week: return "Last week"
        case .last_7_days: return "Last 7 days"
        case .last_30_days: return "Last 30 days"
        case .last_60_days: return "Last 60 days"
        case .last_90_days: return "Last 90 days"
        case .this_month: return "This month"
        case .last_month: return "Last month"
        case .this_quarter: return "This quarter"
        case .last_quarter: return "Last quarter"
        case .this_year: return "This year"
        case .last_year: return "Last year"
        case .custom: return "Custom range"
        }
    }
    /// A preset MetricEngine::resolvePeriod knows by name (like-for-like comparison);
    /// anything else goes as period=custom with dates.
    var backendPeriod: String? {
        switch self {
        case .today: return "today"
        case .yesterday: return "yesterday"
        case .last_7_days: return "last_7"
        case .last_30_days: return "last_30"
        case .this_month: return "this_month"
        case .last_month: return "last_month"
        case .this_quarter: return "this_quarter"
        case .this_year: return "this_year"
        default: return nil
        }
    }
}

/// The window every report reads, computed in Africa/Nairobi so a period
/// boundary never shifts three hours (CLAUDE.md appendix, clock).
struct ReportWindow: Equatable {
    var preset: DatePreset = .this_month
    var customStart: Date = ReportWindow.calendar.startOfDay(for: Date())
    var customEnd: Date = Date()
    var outletId: Int? = nil

    static let calendar: Calendar = {
        var c = Calendar(identifier: .gregorian)
        c.timeZone = HubDates.nairobi
        c.firstWeekday = 1
        return c
    }()

    /// [start, end] as calendar days (end inclusive), mirroring datePresetRange().
    var range: (Date, Date) {
        let cal = Self.calendar
        let now = Date()
        let today = cal.startOfDay(for: now)
        func day(_ n: Int, from d: Date = today) -> Date { cal.date(byAdding: .day, value: n, to: d)! }
        func startOf(_ comp: Calendar.Component, _ d: Date) -> Date { cal.dateInterval(of: comp, for: d)!.start }
        func endOf(_ comp: Calendar.Component, _ d: Date) -> Date { day(-1, from: cal.dateInterval(of: comp, for: d)!.end) }
        switch preset {
        case .today: return (today, today)
        case .yesterday: return (day(-1), day(-1))
        case .this_week: return (startOf(.weekOfYear, today), today)
        case .last_week:
            let lw = cal.date(byAdding: .weekOfYear, value: -1, to: today)!
            return (startOf(.weekOfYear, lw), endOf(.weekOfYear, lw))
        case .last_7_days: return (day(-6), today)
        case .last_30_days: return (day(-29), today)
        case .last_60_days: return (day(-59), today)
        case .last_90_days: return (day(-89), today)
        case .this_month: return (startOf(.month, today), today)
        case .last_month:
            let lm = cal.date(byAdding: .month, value: -1, to: today)!
            return (startOf(.month, lm), endOf(.month, lm))
        case .this_quarter: return (startOf(.quarter, today), today)
        case .last_quarter:
            let lq = cal.date(byAdding: .month, value: -3, to: today)!
            return (startOf(.quarter, lq), endOf(.quarter, lq))
        case .this_year: return (startOf(.year, today), today)
        case .last_year:
            let ly = cal.date(byAdding: .year, value: -1, to: today)!
            return (startOf(.year, ly), endOf(.year, ly))
        case .custom:
            let s = cal.startOfDay(for: customStart), e = cal.startOfDay(for: customEnd)
            return s <= e ? (s, e) : (e, s)
        }
    }

    var startString: String { HubDates.dayString(range.0) }
    var endString: String { HubDates.dayString(range.1) }

    /// ReportController spelling: start_date / end_date / outlet_id.
    func dateParams(_ extra: [String: String?] = [:]) -> [String: String?] {
        var q: [String: String?] = ["start_date": startString, "end_date": endString, "outlet_id": outletId.map(String.init)]
        for (k, v) in extra { q[k] = v }
        return q
    }

    /// MetricEngine spelling: a known preset by name, else period=custom with from/to.
    func periodParams(_ extra: [String: String?] = [:]) -> [String: String?] {
        var q: [String: String?] = ["outlet_id": outletId.map(String.init)]
        if let p = preset.backendPeriod { q["period"] = p } else { q["period"] = "custom"; q["from"] = startString; q["to"] = endString }
        for (k, v) in extra { q[k] = v }
        return q
    }

    var description: String {
        let f = DateFormatter(); f.timeZone = HubDates.nairobi; f.locale = Locale(identifier: "en_KE"); f.dateFormat = "d MMM yyyy"
        let (s, e) = range
        return s == e ? f.string(from: s) : "\(f.string(from: s)) – \(f.string(from: e))"
    }
}

/// The period and outlet follow the reader from page to page (reportShared
/// useDateRange remembers them for the session), and the open drill.
@MainActor
@Observable
final class ReportFilters {
    static let shared = ReportFilters()
    var window = ReportWindow()
    var outlets: [(Int, String)] = []
    var outletsLoaded = false
    var drill: DrillRequest?
    private init() {}

    func loadOutletList(_ client: APIClient) async {
        guard !outletsLoaded else { return }
        outlets = await outletOptions(client, reports: true)
        outletsLoaded = true
        if let id = window.outletId, !outlets.contains(where: { $0.0 == id }) { window.outletId = nil }
    }

    func outletName(_ id: Int?) -> String { id.flatMap { i in outlets.first { $0.0 == i }?.1 } ?? "All outlets" }
}

struct DrillRequest: Identifiable, Hashable {
    let metric: String
    let title: String
    var bucket: String? = nil
    var id: String { metric + (bucket ?? "") }
}

// MARK: - Export permissions

extension SessionStore {
    /// May this person take a file out of this page (ReportPages::canExport)?
    func canExportReport(_ page: Route.ReportPage) -> Bool {
        guard can(page.permission) else { return false }
        if can("reports.export") { return true }
        return (page == .inventory || page == .procurement) && can("reports.export_supply")
    }
}

// MARK: - Shell

/// CSV export description: the page endpoint that answers `?export=csv`.
struct CSVExport {
    let path: String
    let query: [String: String?]
    let name: String
}

struct ReportShell<Content: View>: View {
    @Environment(AppEnvironment.self) private var env
    let page: Route.ReportPage
    var subtitle: String? = nil
    var csv: ((ReportWindow) -> CSVExport)? = nil
    /// The /reports/pdf/{type} segment when this page prints one.
    var pdfType: String? = nil
    /// report_type for schedules (sales, customers, inventory, financial, production, procurement).
    var scheduleType: String? = nil
    /// Hide the date controls on "as of now" pages.
    var dated = true
    @ViewBuilder let content: (ReportWindow) -> Content

    @State private var scheduling = false

    var body: some View {
        @Bindable var filters = ReportFilters.shared
        Page(page.title, subtitle: subtitle ?? "\(filters.window.description) · \(filters.outletName(filters.window.outletId)) · Africa/Nairobi") {
            ReportToolbar(filters: filters, page: page, dated: dated, csv: csv?(filters.window), pdfType: pdfType, canSchedule: scheduleType != nil) { scheduling = true }
            content(filters.window)
            Text("Figures are computed by the server (MetricEngine / reports); money is shown in the currency each payload names, converted to KES only where the server states a reporting rate. Nothing is summed on this device.")
                .font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
        .task { await filters.loadOutletList(env.session.client) }
        .sheet(item: $filters.drill) { d in DrillPanel(request: d, window: filters.window) }
        .sheet(isPresented: $scheduling) { if let t = scheduleType { SchedulesSheet(reportType: t, page: page, filters: filters.window.dateParams()) } }
    }

}

struct ReportToolbar: View {
    @Environment(AppEnvironment.self) private var env
    @Bindable var filters: ReportFilters
    let page: Route.ReportPage
    let dated: Bool
    let csv: CSVExport?
    let pdfType: String?
    let canSchedule: Bool
    let schedule: () -> Void

    private func csvQuery(_ x: CSVExport) -> [String: String?] { var q = x.query; q["export"] = "csv"; return q }

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.s) {
            HStack(spacing: Theme.Space.s) {
                if dated {
                    Picker("Period", selection: $filters.window.preset) {
                        ForEach(DatePreset.allCases) { Text($0.label).tag($0) }
                    }
                    .labelsHidden().frame(maxWidth: 200)
                }
                if filters.outlets.count > 1 || filters.window.outletId != nil {
                    Picker("Outlet", selection: $filters.window.outletId) {
                        Text("All outlets").tag(Int?.none)
                        ForEach(filters.outlets, id: \.0) { o in Text(o.1).tag(Int?.some(o.0)) }
                    }
                    .labelsHidden().frame(maxWidth: 220)
                }
                Spacer(minLength: 0)
            }
            if dated && filters.window.preset == .custom {
                HStack {
                    DatePicker("From", selection: $filters.window.customStart, displayedComponents: .date)
                    DatePicker("To", selection: $filters.window.customEnd, displayedComponents: .date)
                }
                .environment(\.timeZone, HubDates.nairobi)
                .font(Theme.Font.secondary)
            }
            HStack(spacing: Theme.Space.s) {
                if env.session.canExportReport(page) {
                    if let x = csv {
                        DocumentButton("Export CSV", path: x.path, query: csvQuery(x), suggestedName: x.name)
                    }
                    if let pdfType {
                        let q = filters.window.dateParams()
                        DocumentButton("Download PDF", path: "/v1/admin/reports/pdf/\(pdfType)", query: q, suggestedName: "\(pdfType)-report-\(filters.window.startString).pdf")
                        #if os(macOS)
                        PrintPdfButton(path: "/v1/admin/reports/pdf/\(pdfType)", query: q)
                        #endif
                    }
                    if canSchedule {
                        Button(action: schedule) { Label("Schedule", systemImage: "calendar.badge.clock") }
                    }
                }
                Spacer(minLength: 0)
            }
            .font(Theme.Font.secondary)
        }
    }
}

#if os(macOS)
/// Fetches the report PDF and hands it to the system print dialog.
struct PrintPdfButton: View {
    @Environment(AppEnvironment.self) private var env
    let path: String
    let query: [String: String?]
    @State private var busy = false
    var body: some View {
        Button {
            busy = true
            Task {
                defer { busy = false }
                do {
                    let (data, _, _) = try await env.session.client.download(path, query: query)
                    guard let doc = PDFDocument(data: data) else { env.toasts.show("The PDF could not be read.", tone: .danger); return }
                    let info = NSPrintInfo.shared
                    info.horizontalPagination = .fit
                    info.verticalPagination = .fit
                    doc.printOperation(for: info, scalingMode: .pageScaleToFit, autoRotate: true)?.run()
                } catch { env.toasts.error(error) }
            }
        } label: { if busy { ProgressView().controlSize(.small) } else { Label("Print", systemImage: "printer") } }
        .disabled(busy)
    }
}
#endif

// MARK: - Metric tiles

/// A MetricEngine figure `{current, previous, series}`; `drill` opens the
/// records behind it. Money is KES at the reporting rate (DRILL_DEFINITIONS).
struct MetricTile: View {
    let label: String
    let metric: JSONValue?
    var money = true
    var drill: String? = nil
    var hint: String? = nil

    var body: some View {
        let cur = metric?["current"]
        let prev = metric?["previous"]
        let value = money ? fmtMoney(cur, currency: "KES") : (cur?.string ?? "—")
        let prevText = prev.map { money ? fmtMoney($0, currency: "KES") : ($0.string ?? "—") }
        let tile = StatTile(label, value: value, hint: hint ?? prevText.map { "Previous period: \($0)" })
        if let drill {
            DrillLink(metric: drill, title: label) { tile }
        } else {
            tile
        }
    }
}

struct DrillLink<Label: View>: View {
    let metric: String
    let title: String
    var bucket: String? = nil
    @ViewBuilder let label: () -> Label
    var body: some View {
        Button { ReportFilters.shared.drill = DrillRequest(metric: metric, title: title, bucket: bucket) } label: { label() }
            .buttonStyle(.plain)
            .help("Records behind this number")
    }
}

// MARK: - Drill-down

/// The records behind a figure: GET reports/drill/{metric} with the page's
/// own window. The backend states what the number is (`definition`) and
/// where each row may lead (`links`, already permission-filtered).
struct DrillPanel: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let request: DrillRequest
    let window: ReportWindow
    @State private var page = 1
    @State private var result: Loadable<JSONValue> = .idle

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 2) {
                    Text("\(request.title) · \(result.value?["total"]?.int.map { "\($0) record\($0 == 1 ? "" : "s")" } ?? "Loading…")").font(Theme.Font.sectionTitle)
                    Text("\(window.description) · \(ReportFilters.shared.outletName(window.outletId))").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    if let d = result.value?["definition"]?.string { Text(d).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                }
                Spacer()
                Button { dismiss() } label: { Image(systemName: "xmark.circle.fill") }.buttonStyle(.plain).foregroundStyle(Theme.textTertiary)
            }
            LoadableView(result, retry: { Task { await load() } }, isEmpty: { ($0["rows"]?.array ?? []).isEmpty }, empty: { EmptyState("No records for these filters", symbol: "doc.text.magnifyingglass") }) { json in
                ScrollView {
                    LazyVStack(spacing: 0) {
                        ForEach(jrows(json["rows"])) { r in
                            row(r)
                            Divider()
                        }
                    }
                }
            }
            if let total = result.value?["total"]?.int, let per = result.value?["per_page"]?.int, per > 0, total > per {
                let pages = Int((Double(total) / Double(per)).rounded(.up))
                HStack {
                    Button("Previous") { page -= 1; Task { await load() } }.disabled(page <= 1)
                    Text("\(page) / \(pages)").font(Theme.Font.caption).monospacedDigit()
                    Button("Next") { page += 1; Task { await load() } }.disabled(page >= pages)
                }
                .font(Theme.Font.secondary)
            }
        }
        .padding(Theme.Space.xl)
        .frame(minWidth: 480, idealWidth: 640, minHeight: 360, idealHeight: 560)
        .task { await load() }
    }

    private func row(_ r: JRow) -> some View {
        HStack(alignment: .top, spacing: Theme.Space.m) {
            VStack(alignment: .leading, spacing: 2) {
                Text(r.str("ref") ?? r.str("order_number") ?? "#\(r.str("id") ?? "")").font(Theme.Font.mono)
                HStack(spacing: 6) {
                    DateText((r["at"] ?? r["date"])?.date, style: .date)
                    if let who = r.str("who") ?? r.str("customer"), !who.isEmpty { Text("· \(who)").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                    if let d = r.str("detail"), !d.isEmpty { Text("· \(d.humanized)").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                }
                HStack(spacing: Theme.Space.m) {
                    ForEach(jpairs(r["links"]), id: \.key) { link in
                        if let to = link.value.string, let route = Route.from(consolePath: to) {
                            Button(link.key.humanized + " →") { dismiss(); env.navigator.go(route) }.font(Theme.Font.caption)
                        }
                    }
                }
            }
            Spacer()
            if r["amount"] != nil && r["amount"]?.isNull == false {
                VStack(alignment: .trailing, spacing: 2) {
                    Text(fmtMoney(r["amount"], currency: "KES")).font(Theme.Font.secondary.weight(.semibold)).monospacedDigit()
                    if let cur = r.str("currency"), cur != "KES", let orig = r["amount_original"] {
                        Text(fmtMoney(orig, currency: cur)).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                    }
                }
            }
        }
        .padding(.vertical, 8)
    }

    private func load() async {
        result = .loading(previous: result.value)
        var q = window.periodParams(["page": String(page)])
        q["bucket"] = request.bucket
        do {
            let r = try await env.session.client.get("/v1/admin/reports/drill/\(request.metric)", query: q)
            result = .loaded(r.json ?? .null)
        } catch let e as APIError { result = .failed(e, previous: result.value) } catch { result = .failed(.transport(error.localizedDescription), previous: result.value) }
    }
}

// MARK: - Schedules

/// reports/schedules: list (filtered to this report_type), create, delete.
struct SchedulesSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let reportType: String
    let page: Route.ReportPage
    let filters: [String: String?]
    @State private var list: Resource<[JRow]>?
    @State private var name = ""
    @State private var frequency = "weekly"
    @State private var format = "csv"
    @State private var recipients = ""
    @State private var error: APIError?
    @State private var busy = false

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.l) {
            HStack { Text("Scheduled reports · \(page.title)").font(Theme.Font.sectionTitle); Spacer(); Button("Done") { dismiss() } }
            if let list {
                LoadableView(list.state, retry: { list.load() }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No schedules for this report", symbol: "calendar.badge.clock") }) { rows in
                    ForEach(rows) { s in
                        HStack {
                            VStack(alignment: .leading, spacing: 2) {
                                Text(s.str("name") ?? "Schedule").font(Theme.Font.body)
                                Text("\((s.str("frequency") ?? "").capitalizedFirst) · \((s.str("format") ?? "").uppercased()) · \((s["recipients"]?.array ?? []).compactMap(\.string).joined(separator: ", "))")
                                    .font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                            }
                            Spacer()
                            if let id = s.str("id") {
                                ConfirmButton("Delete schedule", message: "This report will no longer be mailed.", destructive: true, action: { await delete(id) }) { Image(systemName: "trash") }
                            }
                        }
                        .padding(.vertical, 4)
                    }
                }
            }
            Divider()
            Text("New schedule").font(Theme.Font.sectionTitle)
            FieldBox("Name", error: error?.errors["name"]?.first) { TextField("e.g. Weekly sales to finance", text: $name).textFieldStyle(.roundedBorder) }
            HStack {
                Picker("Frequency", selection: $frequency) { Text("Daily").tag("daily"); Text("Weekly").tag("weekly"); Text("Monthly").tag("monthly") }
                Picker("Format", selection: $format) { Text("CSV").tag("csv"); Text("PDF").tag("pdf") }
            }
            FieldBox("Recipients (comma-separated emails)", error: error?.errors["recipients"]?.first ?? error?.errors["recipients.0"]?.first) {
                TextField("finance@bethanyhouse.co.ke", text: $recipients).textFieldStyle(.roundedBorder)
            }
            if let e = error, e.errors.isEmpty { Text(e.message).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
            HStack { Spacer(); Button { Task { await save() } } label: { if busy { ProgressView().controlSize(.small) } else { Text("Save schedule") } }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand).disabled(busy || name.isEmpty || recipients.isEmpty) }
        }
        .padding(Theme.Space.xl)
        .frame(minWidth: 460, idealWidth: 560, minHeight: 420)
        .task {
            if list == nil {
                let c = env.session.client, type = reportType
                list = Resource { let j = try await c.get("/v1/admin/reports/schedules").json; return jrows(j?["schedules"]).filter { $0.str("report_type") == type } }
                list?.load()
            }
        }
    }

    private func save() async {
        busy = true; defer { busy = false }
        error = nil
        let emails = recipients.split(separator: ",").map { $0.trimmingCharacters(in: .whitespaces) }.filter { !$0.isEmpty }
        var f: [String: JSONValue] = [:]
        for (k, v) in filters { if let v { f[k] = .string(v) } }
        let body: JSONValue = .object([
            "name": .string(name), "report_type": .string(reportType), "frequency": .string(frequency),
            "recipients": .array(emails.map { .string($0) }), "format": .string(format), "filters": .object(f), "is_active": .bool(true),
        ])
        do {
            _ = try await env.session.client.post("/v1/admin/reports/schedules", json: body)
            env.toasts.show("Schedule saved")
            name = ""; recipients = ""
            list?.load()
        } catch let e as APIError { error = e } catch { env.toasts.error(error) }
    }

    private func delete(_ id: String) async {
        do { _ = try await env.session.client.delete("/v1/admin/reports/schedules/\(id)"); list?.load() } catch { env.toasts.error(error) }
    }
}

// MARK: - Report fetch helper

/// A JSON resource keyed on the window: re-fetches when the window changes.
struct ReportData<Content: View>: View {
    @Environment(AppEnvironment.self) private var env
    let path: String
    let query: [String: String?]
    @ViewBuilder let content: (JSONValue) -> Content
    @State private var resource: Resource<JSONValue>?

    init(_ path: String, query: [String: String?], @ViewBuilder content: @escaping (JSONValue) -> Content) {
        self.path = path; self.query = query; self.content = content
    }

    var body: some View {
        Group {
            if let resource {
                LoadableView(resource.state, retry: { resource.load() }) { json in content(json) }
            } else {
                LoadingSkeleton()
            }
        }
        .task(id: key) {
            let c = env.session.client, p = path, q = query
            let r = Resource { try await c.get(p, query: q).json ?? .null }
            resource = r
            r.load()
        }
    }

    private var key: String { path + "?" + query.sorted { $0.key < $1.key }.map { "\($0.key)=\($0.value ?? "")" }.joined(separator: "&") }
}

/// Foreign-currency honesty notes the sales endpoints carry: converted rows
/// (in the KES totals at the reporting rate) and excluded ones (no rate).
struct CurrencyNotes: View {
    let json: JSONValue
    var body: some View {
        let converted = jrows(json["converted_currencies"])
        let excluded = jrows(json["excluded_currencies"])
        if !converted.isEmpty || !excluded.isEmpty {
            VStack(alignment: .leading, spacing: 4) {
                ForEach(converted) { r in
                    Text("Included at the reporting rate: \(r.int("orders") ?? 0) orders · \(fmtMoney(r["total_native"], currency: r.str("currency_code") ?? "?")) → \(fmtMoney(r["total_kes"], currency: "KES")) (rate \(fmtNumber(r.dbl("rate"), decimals: 2)))")
                }
                ForEach(excluded) { r in
                    Text("Not in the totals (no reporting rate): \(r.int("orders") ?? 0) orders · \(fmtMoney(r["total"], currency: r.str("currency_code") ?? "?"))").foregroundStyle(Theme.Palette.warning)
                }
            }
            .font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
        }
    }
}
