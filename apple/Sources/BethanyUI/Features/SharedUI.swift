import SwiftUI
import Charts
import UniformTypeIdentifiers
import BethanyKit
#if os(iOS)
import PhotosUI
#endif

// Shared building blocks for the Expenses, Finance, Reports and Catalog
// modules: JSON-backed rows and tables, small Swift Charts, form helpers and
// a cross-platform file/photo/camera picker that yields multipart parts.
//
// Nothing here computes money (CLAUDE.md §6): a table cell prints the
// server's figure with the currency the payload names.

// MARK: - JSON rows

/// One element of a JSON array, addressable by index for `ForEach`.
struct JRow: Identifiable, Hashable {
    let id: Int
    let json: JSONValue
    subscript(key: String) -> JSONValue? { json[key] }
    func str(_ key: String) -> String? { json[key]?.string }
    func int(_ key: String) -> Int? { json[key]?.int }
    func dbl(_ key: String) -> Double? { json[key]?.double }
    func money(_ key: String, currency: String? = nil) -> Money? {
        json[key].flatMap { Money(json: $0, currency: currency ?? json["currency"]?.string ?? json["currency_code"]?.string ?? "KES") }
    }
}

func jrows(_ value: JSONValue?) -> [JRow] {
    (value?.array ?? []).enumerated().map { JRow(id: $0.offset, json: $0.element) }
}

/// One `{key: value}` entry of an object, addressable by key for `ForEach`.
struct JPair: Identifiable, Hashable {
    let key: String
    let value: JSONValue
    var id: String { key }
}

/// A `{key: value}` object as rows, keys sorted.
func jpairs(_ value: JSONValue?) -> [JPair] {
    (value?.object ?? [:]).sorted { $0.key < $1.key }.map { JPair(key: $0.key, value: $0.value) }
}

extension JSONValue {
    /// A KES figure as the server sent it.
    var kes: Money? { Money(json: self, currency: "KES") }
}

func fmtNumber(_ d: Double?, decimals: Int = 0) -> String {
    guard let d else { return "—" }
    let f = NumberFormatter()
    f.numberStyle = .decimal
    f.minimumFractionDigits = decimals
    f.maximumFractionDigits = decimals
    f.locale = Locale(identifier: "en_KE")
    return f.string(from: NSNumber(value: d)) ?? "\(d)"
}

func fmtPct(_ d: Double?, decimals: Int = 1) -> String {
    guard let d else { return "—" }
    return String(format: "%.\(decimals)f%%", d)
}

func fmtInt(_ j: JSONValue?) -> String { j?.int.map { fmtNumber(Double($0)) } ?? (j?.double.map { fmtNumber($0) } ?? "—") }

/// "KES 1,234.00" from a JSON number/string at the currency the row names.
func fmtMoney(_ j: JSONValue?, currency: String = "KES") -> String {
    j.flatMap { Money(json: $0, currency: currency) }?.formatted() ?? "—"
}

// MARK: - Data table

struct TableColumn {
    enum Kind { case text, int, number(Int), money, pct, date, status }
    let title: String
    let key: String
    let kind: Kind
    /// For `.money`: the row key naming the currency (else "currency"/"currency_code"/KES).
    var currencyKey: String? = nil
    var fixedCurrency: String? = nil

    init(_ title: String, _ key: String, _ kind: Kind = .text, currencyKey: String? = nil, fixedCurrency: String? = nil) {
        self.title = title; self.key = key; self.kind = kind; self.currencyKey = currencyKey; self.fixedCurrency = fixedCurrency
    }

    var trailing: Bool {
        switch kind {
        case .text, .status, .date: return false
        default: return true
        }
    }

    func text(_ row: JRow) -> String {
        let v = row[key]
        switch kind {
        case .text: return v?.string ?? "—"
        case .int: return fmtInt(v)
        case .number(let d): return fmtNumber(v?.double, decimals: d)
        case .money:
            let cur = fixedCurrency ?? currencyKey.flatMap { row.str($0) } ?? row.str("currency") ?? row.str("currency_code") ?? "KES"
            return fmtMoney(v, currency: cur)
        case .pct: return fmtPct(v?.double)
        case .date:
            guard let d = v?.date else { return v?.string ?? "—" }
            let f = DateFormatter(); f.timeZone = HubDates.nairobi; f.locale = Locale(identifier: "en_KE"); f.dateStyle = .medium; f.timeStyle = .none
            return f.string(from: d)
        case .status: return (v?.string ?? "—").humanized
        }
    }
}

/// A breakdown table from JSON rows. Scrolls sideways on narrow screens.
struct DataTable: View {
    let columns: [TableColumn]
    let rows: [JRow]
    var onTap: ((JRow) -> Void)? = nil
    var emptyText = "Nothing in this period."

    var body: some View {
        if rows.isEmpty {
            Text(emptyText).font(Theme.Font.secondary).foregroundStyle(Theme.textTertiary).padding(.vertical, Theme.Space.s)
        } else {
            ScrollView(.horizontal, showsIndicators: false) {
                Grid(alignment: .leading, horizontalSpacing: Theme.Space.l, verticalSpacing: 6) {
                    GridRow {
                        ForEach(Array(columns.enumerated()), id: \.offset) { _, c in
                            Text(c.title).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).textCase(.uppercase)
                                .gridColumnAlignment(c.trailing ? .trailing : .leading)
                        }
                    }
                    Divider().gridCellUnsizedAxes(.horizontal)
                    ForEach(rows) { row in
                        GridRow {
                            ForEach(Array(columns.enumerated()), id: \.offset) { _, c in
                                cell(c, row)
                            }
                        }
                        .contentShape(Rectangle())
                        .onTapGesture { onTap?(row) }
                    }
                }
                .font(Theme.Font.secondary)
            }
        }
    }

    @ViewBuilder
    private func cell(_ c: TableColumn, _ row: JRow) -> some View {
        switch c.kind {
        case .status: StatusBadge(row.str(c.key))
        case .money:
            let t = c.text(row)
            Text(t).monospacedDigit().foregroundStyle(t.hasPrefix("-") || t.contains(" -") ? Theme.Palette.danger : Theme.text).lineLimit(1)
        default: Text(c.text(row)).monospacedDigit().lineLimit(2)
        }
    }
}

// MARK: - Charts

struct ChartPoint: Identifiable, Hashable {
    let id: Int
    let label: String
    let value: Double
    var series: String = ""
}

func chartPoints(_ value: JSONValue?, label: String, y: String, series: String = "") -> [ChartPoint] {
    jrows(value).compactMap { r in
        guard let v = r.dbl(y) else { return nil }
        return ChartPoint(id: r.id, label: r.str(label) ?? "\(r.id)", value: v, series: series)
    }
}

/// A simple bar chart; `money` labels the axis in the currency.
struct BarsChart: View {
    let points: [ChartPoint]
    var currency: String? = "KES"
    var height: CGFloat = 180

    var body: some View {
        if points.isEmpty {
            Text("No data to chart.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        } else {
            Chart(points) { p in
                BarMark(x: .value("Label", p.label), y: .value("Value", p.value))
                    .foregroundStyle(Theme.Palette.brand)
            }
            .chartYAxisLabel(currency ?? "")
            .chartXAxis { AxisMarks(values: .automatic(desiredCount: 6)) }
            .frame(height: height)
        }
    }
}

/// One or more lines over an ordered x axis (dates or buckets).
struct LinesChart: View {
    let points: [ChartPoint]
    var currency: String? = "KES"
    var height: CGFloat = 180

    var body: some View {
        if points.isEmpty {
            Text("No data to chart.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        } else {
            Chart(points) { p in
                LineMark(x: .value("Label", p.label), y: .value("Value", p.value))
                    .foregroundStyle(by: .value("Series", p.series))
                    .interpolationMethod(.catmullRom)
                PointMark(x: .value("Label", p.label), y: .value("Value", p.value))
                    .foregroundStyle(by: .value("Series", p.series))
                    .symbolSize(12)
            }
            .chartForegroundStyleScale(range: [Theme.Palette.brand, Theme.Palette.info, Theme.Palette.success, Theme.Palette.accent])
            .chartYAxisLabel(currency ?? "")
            .chartXAxis { AxisMarks(values: .automatic(desiredCount: 6)) }
            .frame(height: height)
        }
    }
}

// MARK: - Form helpers

struct FieldBox<Content: View>: View {
    let label: String
    let error: String?
    let content: Content
    init(_ label: String, error: String? = nil, @ViewBuilder content: () -> Content) {
        self.label = label; self.error = error; self.content = content()
    }
    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(label).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            content
            FieldError(error)
        }
    }
}

/// A sheet frame with a title, cancel and a primary action.
struct BHFormSheet<Content: View>: View {
    @Environment(\.dismiss) private var dismiss
    let title: String
    let primary: String
    let busy: Bool
    let disabled: Bool
    let destructive: Bool
    let action: () async -> Void
    let content: Content

    init(_ title: String, primary: String, busy: Bool = false, disabled: Bool = false, destructive: Bool = false, action: @escaping () async -> Void, @ViewBuilder content: () -> Content) {
        self.title = title; self.primary = primary; self.busy = busy; self.disabled = disabled; self.destructive = destructive; self.action = action; self.content = content()
    }

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.l) {
            Text(title).font(Theme.Font.sectionTitle)
            ScrollView {
                VStack(alignment: .leading, spacing: Theme.Space.m) { content }
            }
            HStack {
                Spacer()
                Button("Cancel") { dismiss() }.disabled(busy)
                Button { Task { await action() } } label: {
                    if busy { ProgressView().controlSize(.small) } else { Text(primary) }
                }
                .buttonStyle(.borderedProminent)
                .tint(destructive ? Theme.Palette.danger : Theme.Palette.brand)
                .disabled(busy || disabled)
            }
        }
        .padding(Theme.Space.xl)
        .frame(minWidth: 420, idealWidth: 560, maxWidth: 720, minHeight: 200, idealHeight: 520)
    }
}

/// A decimal text field bound to an optional Decimal (no Double on money).
struct DecimalField: View {
    let label: String
    @Binding var text: String
    init(_ label: String, text: Binding<String>) { self.label = label; _text = text }
    var body: some View {
        TextField(label, text: $text)
            .textFieldStyle(.roundedBorder)
            #if os(iOS)
            .keyboardType(.decimalPad)
            #endif
    }
}

func decimal(_ s: String) -> Decimal? {
    let t = s.replacingOccurrences(of: ",", with: "").trimmingCharacters(in: .whitespaces)
    return t.isEmpty ? nil : Decimal(string: t, locale: Locale(identifier: "en_US_POSIX"))
}

/// A money field encodes as a JSON number from its decimal text.
func jnum(_ s: String) -> JSONValue? {
    decimal(s).map { JSONValue.number(NSDecimalNumber(decimal: $0).doubleValue) }
}

func jstr(_ s: String) -> JSONValue? {
    let t = s.trimmingCharacters(in: .whitespacesAndNewlines)
    return t.isEmpty ? nil : .string(t)
}

extension Dictionary where Key == String, Value == JSONValue {
    /// Adds a key only when its value is present.
    mutating func put(_ key: String, _ value: JSONValue?) { if let value { self[key] = value } }
}

struct SegmentedTabs<T: Hashable>: View {
    let tabs: [(T, String)]
    @Binding var selection: T
    var body: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: Theme.Space.xs) {
                ForEach(Array(tabs.enumerated()), id: \.offset) { _, t in
                    Button(t.1) { selection = t.0 }
                        .font(Theme.Font.secondary.weight(selection == t.0 ? .semibold : .regular))
                        .foregroundStyle(selection == t.0 ? .white : Theme.text)
                        .padding(.horizontal, 12).padding(.vertical, 6)
                        .background(selection == t.0 ? Theme.Palette.brandDeep : Theme.card, in: Capsule())
                        .overlay(Capsule().stroke(Theme.line, lineWidth: selection == t.0 ? 0 : 1))
                        .buttonStyle(.plain)
                }
            }
        }
    }
}

/// A caption stating the basis of a figure (reporting rate, payment date…).
struct BasisCaption: View {
    let text: String?
    init(_ text: String?) { self.text = text }
    var body: some View {
        if let text, !text.isEmpty {
            Label(text, systemImage: "info.circle").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
    }
}

// MARK: - Files, photos and camera

/// A file chosen by the person, ready to send as a multipart part.
struct UploadFile: Identifiable, Sendable {
    let id = UUID()
    let data: Data
    let name: String
    let mime: String

    func part(name field: String) -> APIClient.MultipartPart {
        APIClient.MultipartPart(name: field, data: data, filename: name, mimeType: mime)
    }

    static func load(_ url: URL) -> UploadFile? {
        let scoped = url.startAccessingSecurityScopedResource()
        defer { if scoped { url.stopAccessingSecurityScopedResource() } }
        guard let data = try? Data(contentsOf: url) else { return nil }
        let type = UTType(filenameExtension: url.pathExtension)
        return UploadFile(data: data, name: url.lastPathComponent, mime: type?.preferredMIMEType ?? "application/octet-stream")
    }
}

/// One button that offers every way to attach a file this platform has:
/// files (both), photo library and camera (iOS). Returns PickedFiles.
struct AttachmentPicker: View {
    let label: String
    var types: [UTType] = [.image, .pdf]
    var allowsMultiple = false
    var camera = true
    let onPick: ([UploadFile]) -> Void

    @State private var importing = false
    #if os(iOS)
    @State private var photoItems: [PhotosPickerItem] = []
    @State private var showCamera = false
    #endif

    var body: some View {
        Menu {
            Button { importing = true } label: { Label("Choose a file…", systemImage: "folder") }
            #if os(iOS)
            if types.contains(.image) {
                PhotosPicker(selection: $photoItems, maxSelectionCount: allowsMultiple ? 10 : 1, matching: .images) {
                    Label("Photo library…", systemImage: "photo.on.rectangle")
                }
                if camera && UIImagePickerController.isSourceTypeAvailable(.camera) {
                    Button { showCamera = true } label: { Label("Take a photo…", systemImage: "camera") }
                }
            }
            #endif
        } label: {
            Label(label, systemImage: "paperclip")
        }
        .fileImporter(isPresented: $importing, allowedContentTypes: types, allowsMultipleSelection: allowsMultiple) { result in
            if case .success(let urls) = result { onPick(urls.compactMap(UploadFile.load)) }
        }
        #if os(iOS)
        .onChange(of: photoItems) { _, items in
            guard !items.isEmpty else { return }
            Task {
                var files: [UploadFile] = []
                for (i, item) in items.enumerated() {
                    if let data = try? await item.loadTransferable(type: Data.self) {
                        let type = item.supportedContentTypes.first
                        files.append(UploadFile(data: data, name: "photo-\(i + 1).\(type?.preferredFilenameExtension ?? "jpg")", mime: type?.preferredMIMEType ?? "image/jpeg"))
                    }
                }
                photoItems = []
                if !files.isEmpty { onPick(files) }
            }
        }
        .fullScreenCover(isPresented: $showCamera) {
            CameraCapture { image in
                showCamera = false
                if let image, let data = image.jpegData(compressionQuality: 0.85) {
                    onPick([UploadFile(data: data, name: "capture-\(Int(Date().timeIntervalSince1970)).jpg", mime: "image/jpeg")])
                }
            }
            .ignoresSafeArea()
        }
        #endif
    }
}

#if os(iOS)
struct CameraCapture: UIViewControllerRepresentable {
    let done: (UIImage?) -> Void

    func makeUIViewController(context: Context) -> UIImagePickerController {
        let p = UIImagePickerController()
        p.sourceType = .camera
        p.cameraCaptureMode = .photo
        p.delegate = context.coordinator
        return p
    }
    func updateUIViewController(_ vc: UIImagePickerController, context: Context) {}
    func makeCoordinator() -> Coordinator { Coordinator(done: done) }

    final class Coordinator: NSObject, UIImagePickerControllerDelegate, UINavigationControllerDelegate {
        let done: (UIImage?) -> Void
        init(done: @escaping (UIImage?) -> Void) { self.done = done }
        func imagePickerController(_ picker: UIImagePickerController, didFinishPickingMediaWithInfo info: [UIImagePickerController.InfoKey: Any]) {
            done(info[.originalImage] as? UIImage)
        }
        func imagePickerControllerDidCancel(_ picker: UIImagePickerController) { done(nil) }
    }
}
#endif

/// Shares text (analytics summary, a link) with the platform share sheet.
struct ShareTextButton: View {
    let label: String
    let text: String
    var body: some View {
        #if os(iOS)
        ShareLink(item: text) { Label(label, systemImage: "square.and.arrow.up") }
        #else
        ShareLink(item: text) { Label(label, systemImage: "square.and.arrow.up") }
        #endif
    }
}

/// A remote image (product, category, receipt) with a placeholder.
struct RemoteImage: View {
    let url: String?
    var size: CGFloat = 56
    var body: some View {
        Group {
            if let url, let u = URL(string: url) {
                AsyncImage(url: u) { phase in
                    if let image = phase.image { image.resizable().scaledToFill() } else { placeholder }
                }
            } else { placeholder }
        }
        .frame(width: size, height: size)
        .clipShape(RoundedRectangle(cornerRadius: Theme.Radius.field))
    }
    private var placeholder: some View {
        RoundedRectangle(cornerRadius: Theme.Radius.field).fill(Theme.line).overlay(Image(systemName: "photo").foregroundStyle(Theme.textTertiary))
    }
}

/// A person `{first_name,last_name}` or `{name}` as one line.
func personName(_ j: JSONValue?) -> String? {
    guard let j, !j.isNull else { return nil }
    if let n = j["name"]?.string { return n }
    let parts = [j["first_name"]?.string, j["last_name"]?.string].compactMap { $0 }.filter { !$0.isEmpty }
    return parts.isEmpty ? j.string : parts.joined(separator: " ")
}

/// Lists outlets for pickers: the reports list first (any report page), else POS outlets.
@MainActor
func outletOptions(_ client: APIClient, reports: Bool) async -> [(Int, String)] {
    let path = reports ? "/v1/admin/reports/outlets" : "/v1/admin/outlets"
    guard let r = try? await client.get(path, background: true) else { return [] }
    let arr = r.json?["data"]?.array ?? r.json?.array ?? []
    return arr.compactMap { o in
        guard let id = o["id"]?.int else { return nil }
        return (id, o["name"]?.string ?? "Outlet \(id)")
    }
}
