import SwiftUI
import PhotosUI
import BethanyKit

/// Me: the staff member's own module (console `/me/*`, `/updates`, `/forms`,
/// `/handbook`, `/courses`, `/me/sign`, `/todos`, `/colleagues`). Screens live
/// in the MeFeature*.swift files; this one registers the routes and holds the
/// pieces they share (formatting, avatars, the signature pad, photo picking).
@MainActor
enum MeFeature {
    static func register() {
        FeatureRegistry.register(.me) { _ in AnyView(MeTodayView()) }
        FeatureRegistry.register(.clock) { _ in AnyView(ClockView()) }
        FeatureRegistry.register(.myShifts) { _ in AnyView(MyShiftsView()) }
        FeatureRegistry.register(.myLeave) { _ in AnyView(MyLeaveView()) }
        FeatureRegistry.register(.myTimesheet) { _ in AnyView(MyTimesheetView()) }
        FeatureRegistry.register(.myPayslips) { _ in AnyView(MyPayslipsView()) }
        FeatureRegistry.register(.myAdvances) { _ in AnyView(MyAdvancesView()) }
        FeatureRegistry.register({ if case .updates = $0 { return true }; return false }) { route in
            if case .updates(let id) = route, let id { return AnyView(PostDetailView(id: id)) }
            return AnyView(UpdatesView())
        }
        FeatureRegistry.register(.forms) { _ in AnyView(FormsView()) }
        FeatureRegistry.register({ if case .handbook = $0 { return true }; return false }) { route in
            if case .handbook(let id) = route, let id { return AnyView(ArticleView(id: id)) }
            return AnyView(HandbookView())
        }
        FeatureRegistry.register({ if case .courses = $0 { return true }; return false }) { route in
            if case .courses(let id) = route, let id { return AnyView(CourseView(id: id)) }
            return AnyView(CoursesView())
        }
        FeatureRegistry.register({ if case .sign = $0 { return true }; return false }) { route in
            if case .sign(let id) = route, let id { return AnyView(SignDetailView(id: id)) }
            return AnyView(SignView())
        }
        FeatureRegistry.register(.todos) { _ in AnyView(TodosView()) }
        FeatureRegistry.register(.colleagues) { _ in AnyView(ColleaguesView()) }
    }
}

// MARK: - Formatting (Nairobi wall-clock, as the console prints it)

enum MeFormat {
    private static func formatter(_ format: String) -> DateFormatter {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_GB")
        f.timeZone = HubDates.nairobi
        f.dateFormat = format
        return f
    }

    /// "2h 05m" — minutes as the console's `hm()`.
    static func hm(_ minutes: Int?) -> String {
        guard let minutes else { return "—" }
        let h = minutes / 60, m = minutes % 60
        return m != 0 ? "\(h)h \(String(format: "%02d", m))m" : "\(h)h"
    }

    /// "2 h 14 m" / "14 m" — ClockPage's `fmtMinutes`.
    static func clockMinutes(_ minutes: Int?) -> String {
        guard let minutes else { return "—" }
        let h = minutes / 60, m = minutes % 60
        return h > 0 ? "\(h) h \(m) m" : "\(m) m"
    }

    static func time(_ date: Date?) -> String { date.map { formatter("HH:mm").string(from: $0) } ?? "" }
    static func day(_ date: Date?) -> String { date.map { formatter("EEE d MMM").string(from: $0) } ?? "" }
    static func longDay(_ date: Date?) -> String { date.map { formatter("EEEE d MMMM").string(from: $0) } ?? "" }
    static func dayYear(_ date: Date?) -> String { date.map { formatter("d MMM yyyy").string(from: $0) } ?? "" }
    static func dayTime(_ date: Date?) -> String { date.map { formatter("d MMM · HH:mm").string(from: $0) } ?? "" }
    static func weekday(_ date: Date?) -> String { date.map { formatter("EEE").string(from: $0) } ?? "" }
    static func dayNumber(_ date: Date?) -> String { date.map { formatter("d").string(from: $0) } ?? "" }

    /// "Today 09:12" / "Yesterday 17:40" / "Mon 3 Nov 08:00" — ClockPage's `fmtWhen`.
    static func when(_ date: Date?) -> String {
        guard let date else { return "—" }
        var cal = Calendar(identifier: .gregorian)
        cal.timeZone = HubDates.nairobi
        let dayPart = cal.isDateInToday(date) ? "Today" : cal.isDateInYesterday(date) ? "Yesterday" : day(date)
        return "\(dayPart) \(time(date))"
    }

    /// Parses an API date ("2026-10-27", "2026-10-27 08:00:00" or ISO) for display.
    static func date(_ s: String?) -> Date? { s.flatMap(HubDates.parse) }

    /// "yyyy-MM-dd" in Nairobi, what every date field takes.
    static func dayString(_ date: Date) -> String { HubDates.dayString(date) }

    /// "yyyy-MM-dd HH:mm" in Nairobi, what `due_at`, `event_at` and `publish_at` take.
    static func dateTimeString(_ date: Date) -> String { formatter("yyyy-MM-dd HH:mm").string(from: date) }

    static func plural(_ n: Int, _ one: String, _ many: String? = nil) -> String {
        "\(n) \(n == 1 ? one : (many ?? one + "s"))"
    }
}

/// Transport failures arrive as `APIError.isOffline` or a bare `URLError`.
func isOfflineError(_ error: Error) -> Bool {
    if let a = error as? APIError { return a.isOffline }
    return error is URLError
}

extension JSONValue {
    /// Rows of a `{data: [...]}` or bare-array payload.
    var records: [Record] { (self["data"]?.array ?? array ?? []).map(Record.init(json:)) }
}

extension Record {
    func flag(_ key: String) -> Bool { json[key]?.bool ?? false }
    func double(_ key: String) -> Double? { json[key]?.double }
    func strings(_ key: String) -> [String] { json[key]?.array?.compactMap(\.string) ?? [] }
    func records(_ key: String) -> [Record] { json[key]?.array?.map(Record.init(json:)) ?? [] }
    func record(_ key: String) -> Record? { json[key].flatMap { $0.object == nil ? nil : Record(json: $0) } }
}

// MARK: - Shared pieces

/// A person's avatar: their photo, or initials on brand tint.
struct PersonAvatar: View {
    let name: String?
    let url: String?
    var size: CGFloat = 36

    var body: some View {
        Group {
            if let url, let u = URL(string: url) {
                AsyncImage(url: u) { img in img.resizable().scaledToFill() } placeholder: { initialsView }
            } else {
                initialsView
            }
        }
        .frame(width: size, height: size)
        .clipShape(Circle())
    }

    private var initialsView: some View {
        ZStack {
            Circle().fill(Theme.Palette.brand.opacity(0.15))
            Text(initials).font(.system(size: size * 0.36, weight: .bold)).foregroundStyle(Theme.Palette.brandDeep)
        }
    }

    private var initials: String {
        let parts = (name ?? "BH").split(separator: " ").prefix(2)
        return parts.compactMap { $0.first.map(String.init) }.joined().uppercased()
    }
}

/// A card of rows divided by hairlines (the console's `divide-y` lists).
struct ListCard<Content: View>: View {
    let content: Content
    init(@ViewBuilder content: () -> Content) { self.content = content() }
    var body: some View {
        VStack(alignment: .leading, spacing: 0) { content }
            .background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).stroke(Theme.line, lineWidth: 1))
    }
}

struct ListRow<Content: View>: View {
    let content: Content
    init(@ViewBuilder content: () -> Content) { self.content = content() }
    var body: some View {
        content
            .padding(.horizontal, Theme.Space.l)
            .padding(.vertical, Theme.Space.m)
            .frame(maxWidth: .infinity, alignment: .leading)
            .overlay(alignment: .bottom) { Rectangle().fill(Theme.line).frame(height: 1).padding(.leading, Theme.Space.l) }
    }
}

/// A label + hint row that navigates (MePage's list of links).
struct LinkRow: View {
    @Environment(AppEnvironment.self) private var env
    let label: String
    let hint: String?
    let route: Route
    var body: some View {
        Button { env.navigator.go(route) } label: {
            ListRow {
                HStack {
                    Text(label).font(Theme.Font.body.weight(.medium)).foregroundStyle(Theme.text)
                    Spacer()
                    if let hint { Text(hint).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                    Image(systemName: "chevron.right").font(.system(size: 11, weight: .semibold)).foregroundStyle(Theme.textTertiary)
                }
            }
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

/// A small tinted notice line (the console's rounded `bg-*-light` paragraphs).
struct NoticeText: View {
    let text: String
    let tone: StatusTone
    init(_ text: String, tone: StatusTone = .info) { self.text = text; self.tone = tone }
    var body: some View {
        Text(text)
            .font(Theme.Font.secondary)
            .foregroundStyle(tone.foreground)
            .padding(.horizontal, Theme.Space.m).padding(.vertical, Theme.Space.s)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(tone.background, in: RoundedRectangle(cornerRadius: Theme.Radius.field, style: .continuous))
    }
}

/// A pill chip row for filters (kind, scope, status).
struct ChipPicker<T: Hashable>: View {
    let options: [(T, String)]
    @Binding var selection: T
    var body: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: Theme.Space.s) {
                ForEach(Array(options.enumerated()), id: \.offset) { _, o in
                    Button(o.1) { selection = o.0 }
                        .font(Theme.Font.caption)
                        .foregroundStyle(selection == o.0 ? .white : Theme.textSecondary)
                        .padding(.horizontal, 12).padding(.vertical, 6)
                        .background(selection == o.0 ? Theme.Palette.brandDeep : Theme.card, in: Capsule())
                        .overlay(Capsule().stroke(selection == o.0 ? Color.clear : Theme.line, lineWidth: 1))
                        .buttonStyle(.plain)
                }
            }
        }
    }
}

/// The frame every Me form sheet uses: a title, Cancel, one primary action,
/// scrolling content that gives way to the keyboard on iOS.
struct MeSheet<Content: View>: View {
    @Environment(\.dismiss) private var dismiss
    let title: String
    let primary: String
    let disabled: Bool
    let busy: Bool
    let action: () async -> Void
    let content: Content

    init(_ title: String, primary: String, disabled: Bool = false, busy: Bool = false, action: @escaping () async -> Void, @ViewBuilder content: () -> Content) {
        self.title = title
        self.primary = primary
        self.disabled = disabled
        self.busy = busy
        self.action = action
        self.content = content()
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: Theme.Space.m) { content }
                    .padding(Theme.Space.l)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
            #if os(iOS)
            .scrollDismissesKeyboard(.interactively)
            .navigationBarTitleDisplayMode(.inline)
            #endif
            .background(Theme.canvas)
            .navigationTitle(title)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } }
                ToolbarItem(placement: .confirmationAction) {
                    Button { Task { await action() } } label: {
                        if busy { ProgressView().controlSize(.small) } else { Text(primary).bold() }
                    }
                    .disabled(disabled || busy)
                }
            }
        }
        #if os(macOS)
        .frame(minWidth: 520, idealWidth: 560, minHeight: 420, idealHeight: 560)
        #endif
    }
}

/// A labelled field group inside a sheet.
struct FieldLabel<Content: View>: View {
    let label: String
    let content: Content
    init(_ label: String, @ViewBuilder content: () -> Content) { self.label = label; self.content = content() }
    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(label).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            content
        }
    }
}

// MARK: - Signature pad (Canvas → PNG data URL, as the console's SignaturePad)

struct SignaturePad: View {
    @Binding var dataURL: String?
    var height: CGFloat = 140
    @State private var strokes: [[CGPoint]] = []
    @State private var current: [CGPoint] = []
    @State private var size: CGSize = .zero

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            GeometryReader { geo in
                SignatureCanvas(strokes: strokes + (current.isEmpty ? [] : [current]))
                    .background(Color.white)
                    .clipShape(RoundedRectangle(cornerRadius: Theme.Radius.field, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: Theme.Radius.field, style: .continuous).stroke(Theme.line, lineWidth: 1))
                    .contentShape(Rectangle())
                    .gesture(
                        DragGesture(minimumDistance: 0, coordinateSpace: .local)
                            .onChanged { v in current.append(v.location) }
                            .onEnded { v in
                                current.append(v.location)
                                strokes.append(current)
                                current = []
                                render()
                            }
                    )
                    .onAppear { size = geo.size }
                    .onChange(of: geo.size) { _, s in size = s }
            }
            .frame(height: height)
            HStack {
                Text(strokes.isEmpty ? "Sign here with your finger" : "Signed").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                Spacer()
                Button("Clear") { strokes = []; current = []; dataURL = nil }.font(Theme.Font.caption).disabled(strokes.isEmpty)
            }
        }
        .accessibilityLabel("Signature")
    }

    /// Rasterises the strokes to a white PNG at 1× (the server caps the
    /// decoded PNG at 220 KB; a 2 px pen on white stays far under that).
    @MainActor private func render() {
        guard !strokes.isEmpty, size.width > 0 else { dataURL = nil; return }
        let renderer = ImageRenderer(content: SignatureCanvas(strokes: strokes).frame(width: size.width, height: size.height).background(Color.white))
        renderer.scale = 1
        guard let cg = renderer.cgImage, let png = PlatformImage.pngData(cg) else { dataURL = nil; return }
        dataURL = "data:image/png;base64," + png.base64EncodedString()
    }
}

private struct SignatureCanvas: View {
    let strokes: [[CGPoint]]
    var body: some View {
        Canvas { ctx, _ in
            for s in strokes {
                guard let first = s.first else { continue }
                var path = Path()
                path.move(to: first)
                if s.count == 1 { path.addLine(to: CGPoint(x: first.x + 0.5, y: first.y)) }
                for p in s.dropFirst() { path.addLine(to: p) }
                ctx.stroke(path, with: .color(Color(hex: 0x111111)), style: StrokeStyle(lineWidth: 2, lineCap: .round, lineJoin: .round))
            }
        }
    }
}

enum PlatformImage {
    static func pngData(_ cg: CGImage) -> Data? {
        #if os(macOS)
        let rep = NSBitmapImageRep(cgImage: cg)
        return rep.representation(using: .png, properties: [:])
        #else
        return UIImage(cgImage: cg).pngData()
        #endif
    }

    /// Re-encodes a picked photo as JPEG under ~2 MB so a checklist photo or
    /// post photo uploads over a shop connection (server limits 8 MB / 5 MB).
    static func jpegForUpload(_ data: Data, maxDimension: CGFloat = 1600) -> Data? {
        #if os(macOS)
        guard let image = NSImage(data: data) else { return nil }
        let scale = min(1, maxDimension / max(image.size.width, image.size.height, 1))
        let target = NSSize(width: image.size.width * scale, height: image.size.height * scale)
        let scaled = NSImage(size: target)
        scaled.lockFocus()
        image.draw(in: NSRect(origin: .zero, size: target), from: .zero, operation: .copy, fraction: 1)
        scaled.unlockFocus()
        guard let tiff = scaled.tiffRepresentation, let rep = NSBitmapImageRep(data: tiff) else { return nil }
        return rep.representation(using: .jpeg, properties: [.compressionFactor: 0.8])
        #else
        guard let image = UIImage(data: data) else { return nil }
        let scale = min(1, maxDimension / max(image.size.width, image.size.height, 1))
        let target = CGSize(width: image.size.width * scale, height: image.size.height * scale)
        let format = UIGraphicsImageRendererFormat.default()
        format.scale = 1
        let scaled = UIGraphicsImageRenderer(size: target, format: format).image { _ in image.draw(in: CGRect(origin: .zero, size: target)) }
        return scaled.jpegData(compressionQuality: 0.8)
        #endif
    }
}

// MARK: - Photo picking (library on both platforms, camera on iOS)

struct PickedPhoto: Equatable, Sendable {
    let data: Data
    let filename: String
    let mimeType = "image/jpeg"
}

struct PhotoPickerField: View {
    let label: String
    @Binding var photo: PickedPhoto?
    @State private var item: PhotosPickerItem?
    #if os(iOS)
    @State private var showCamera = false
    #endif

    var body: some View {
        HStack(spacing: Theme.Space.s) {
            PhotosPicker(selection: $item, matching: .images) { Label(photo == nil ? label : "Change photo", systemImage: "photo") }
                .buttonStyle(.bordered)
            #if os(iOS)
            if UIImagePickerController.isSourceTypeAvailable(.camera) {
                Button { showCamera = true } label: { Label("Camera", systemImage: "camera") }.buttonStyle(.bordered)
            }
            #endif
            if let photo {
                Text(photo.filename).font(Theme.Font.caption).foregroundStyle(Theme.Palette.success).lineLimit(1)
                Button { self.photo = nil; item = nil } label: { Image(systemName: "xmark.circle.fill") }.buttonStyle(.plain).foregroundStyle(Theme.textTertiary)
            }
        }
        .onChange(of: item) { _, newItem in
            guard let newItem else { return }
            Task {
                if let data = try? await newItem.loadTransferable(type: Data.self), let jpeg = PlatformImage.jpegForUpload(data) {
                    photo = PickedPhoto(data: jpeg, filename: "photo-\(Int(Date().timeIntervalSince1970)).jpg")
                }
            }
        }
        #if os(iOS)
        .fullScreenCover(isPresented: $showCamera) {
            CameraPicker { data in
                if let data, let jpeg = PlatformImage.jpegForUpload(data) { photo = PickedPhoto(data: jpeg, filename: "camera-\(Int(Date().timeIntervalSince1970)).jpg") }
                showCamera = false
            }
            .ignoresSafeArea()
        }
        #endif
    }
}

#if os(iOS)
struct CameraPicker: UIViewControllerRepresentable {
    let done: (Data?) -> Void
    func makeCoordinator() -> Coordinator { Coordinator(done: done) }
    func makeUIViewController(context: Context) -> UIImagePickerController {
        let p = UIImagePickerController()
        p.sourceType = .camera
        p.cameraCaptureMode = .photo
        p.delegate = context.coordinator
        return p
    }
    func updateUIViewController(_ vc: UIImagePickerController, context: Context) {}
    final class Coordinator: NSObject, UIImagePickerControllerDelegate, UINavigationControllerDelegate {
        let done: (Data?) -> Void
        init(done: @escaping (Data?) -> Void) { self.done = done }
        func imagePickerController(_ picker: UIImagePickerController, didFinishPickingMediaWithInfo info: [UIImagePickerController.InfoKey: Any]) {
            done((info[.originalImage] as? UIImage)?.jpegData(compressionQuality: 0.9))
        }
        func imagePickerControllerDidCancel(_ picker: UIImagePickerController) { done(nil) }
    }
}
#endif

// MARK: - Outlet choice for posts, articles and courses ("For: Everyone / outlet")

/// Outlets to address a post to. `/v1/admin/outlets` needs `outlets.view`;
/// otherwise the person's own outlets from the time clock stand in.
@MainActor
@Observable
final class OutletChoices {
    private(set) var outlets: [(id: Int, name: String)] = []
    private var loaded = false

    func load(_ env: AppEnvironment) async {
        guard !loaded else { return }
        loaded = true
        let client = env.session.client
        let path = env.session.can("outlets.view") ? "/v1/admin/outlets" : "/v1/admin/time-clock/outlets"
        guard let json = try? await client.get(path).json else { return }
        outlets = json.records.compactMap { r -> (id: Int, name: String)? in
            guard let id = r.int("id"), let name = r.string("name") else { return nil }
            return (id: id, name: name)
        }
    }
}

struct OutletPicker: View {
    let choices: OutletChoices
    @Binding var outletId: Int?
    var body: some View {
        Picker("For", selection: $outletId) {
            Text("Everyone").tag(Int?.none)
            ForEach(choices.outlets, id: \.id) { o in Text(o.name).tag(Int?.some(o.id)) }
        }
    }
}
