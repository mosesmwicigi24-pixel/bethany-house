#if os(iOS)
import SwiftUI
import AVFoundation
import UIKit
import BethanyKit

/// The clock-in kiosk (People C2) on the iPad at the door — the console's
/// /kiosk page and the Android KioskScreen.
///
/// Tap your name, type your terminal PIN, the front camera takes one
/// picture, done. Big targets, nothing of the Hub to wander into: the idle
/// timer is off, and Guided Access keeps the iPad on this app (requested
/// automatically on a supervised iPad; otherwise a manager turns it on with
/// a triple-click of the side button). Every rule is the server's.
public struct KioskView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var board: KioskBoard?
    @State private var loadError: String?
    @State private var picked: KioskPerson?
    @State private var pin = ""
    @State private var busy = false
    @State private var banner: (ok: Bool, text: String)?
    @State private var bannerTask: Task<Void, Never>?
    @State private var now = Date()
    @State private var leaving = false
    @State private var camera = KioskCamera()
    @State private var guided = UIAccessibility.isGuidedAccessEnabled

    public init() {}

    private static let time: DateFormatter = { let f = DateFormatter(); f.timeZone = HubDates.nairobi; f.locale = Locale(identifier: "en_GB"); f.dateFormat = "HH:mm"; return f }()
    private static let day: DateFormatter = { let f = DateFormatter(); f.timeZone = HubDates.nairobi; f.locale = Locale(identifier: "en_GB"); f.dateFormat = "EEEE d MMMM"; return f }()
    private let bg = Color(hex: 0x111827), tile = Color(hex: 0x1F2937), tileIn = Color(hex: 0x047857), dim = Color(hex: 0x9CA3AF)

    public var body: some View {
        VStack(spacing: 0) {
            header
            if let banner {
                Text(banner.text).font(.system(size: 20, weight: .semibold)).multilineTextAlignment(.center)
                    .frame(maxWidth: .infinity).padding(Theme.Space.l)
                    .background(banner.ok ? Theme.Palette.success : Theme.Palette.danger, in: RoundedRectangle(cornerRadius: 16))
                    .padding(.horizontal, Theme.Space.xl).padding(.bottom, Theme.Space.m)
                    .accessibilityAddTraits(.updatesFrequently)
            }
            Group {
                if let p = picked { pinPad(p) } else { grid }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            footer
        }
        .foregroundStyle(.white)
        .background(bg.ignoresSafeArea())
        .task { while !Task.isCancelled { await refresh(); try? await Task.sleep(for: .seconds(30)) } }
        .task { while !Task.isCancelled { now = Date(); try? await Task.sleep(for: .seconds(10)) } }
        .onAppear {
            UIApplication.shared.isIdleTimerDisabled = true
            UIAccessibility.requestGuidedAccessSession(enabled: true) { ok in guided = ok || UIAccessibility.isGuidedAccessEnabled }
        }
        .onDisappear { UIApplication.shared.isIdleTimerDisabled = false; camera.stop() }
        .onReceive(NotificationCenter.default.publisher(for: UIAccessibility.guidedAccessStatusDidChangeNotification)) { _ in guided = UIAccessibility.isGuidedAccessEnabled }
        .alert("Leave kiosk mode?", isPresented: $leaving) {
            Button("Yes, leave", role: .destructive) {
                Task {
                    if UIAccessibility.isGuidedAccessEnabled { UIAccessibility.requestGuidedAccessSession(enabled: false) { _ in } }
                    await env.kioskAPI.leave()
                }
            }
            Button("No", role: .cancel) {}
        } message: {
            Text("This tablet stops being the clock-in kiosk and its kiosk key is removed from the Hub. A manager can set it up again from People › Attendance › Kiosks.")
        }
    }

    private var header: some View {
        HStack(alignment: .center) {
            VStack(alignment: .leading, spacing: 2) {
                Text(board?.outlet ?? "Bethany House").font(.system(size: 22, weight: .bold))
                Text("\(board?.kioskName ?? "Kiosk") · \(board?.inNow ?? 0) clocked in").font(.system(size: 13)).foregroundStyle(dim)
            }
            Spacer()
            VStack(alignment: .trailing, spacing: 2) {
                Text(Self.time.string(from: now)).font(.system(size: 34, weight: .bold)).monospacedDigit()
                Text(Self.day.string(from: now)).font(.system(size: 13)).foregroundStyle(dim)
            }
        }
        .padding(.horizontal, Theme.Space.xl).padding(.vertical, Theme.Space.l)
    }

    @ViewBuilder private var grid: some View {
        if board == nil && loadError == nil {
            ProgressView().tint(.white)
        } else if board == nil, let loadError {
            Text(loadError).font(.system(size: 18)).foregroundStyle(Color(hex: 0xFCA5A5)).multilineTextAlignment(.center).padding(Theme.Space.xl)
        } else if let board, board.people.isEmpty {
            Text("Nobody is on this outlet's team yet.").font(.system(size: 18)).foregroundStyle(dim)
        } else if let board {
            VStack(alignment: .leading, spacing: Theme.Space.s) {
                if let loadError { Text(loadError).font(.system(size: 14)).foregroundStyle(Color(hex: 0xFCD34D)).padding(.horizontal, Theme.Space.xl) }
                ScrollView {
                    LazyVGrid(columns: [GridItem(.adaptive(minimum: 220), spacing: Theme.Space.m)], spacing: Theme.Space.m) {
                        ForEach(board.people) { p in personTile(p) }
                    }
                    .padding(.horizontal, Theme.Space.xl)
                }
            }
        }
    }

    private func status(_ p: KioskPerson) -> String {
        let s: String
        switch p.state {
        case "out": s = "Tap to clock in"
        case "break": s = "On break"
        default: s = "In since " + (p.since.map(Self.time.string(from:)) ?? "")
        }
        return s + (p.elsewhere.map { " · at \($0)" } ?? "")
    }

    private func personTile(_ p: KioskPerson) -> some View {
        Button { picked = p; pin = "" } label: {
            HStack(spacing: Theme.Space.m) {
                Text(p.initials).font(.system(size: 18, weight: .bold)).frame(width: 52, height: 52)
                    .background(p.isIn ? Color(hex: 0x10B981) : Color(hex: 0x4B5563), in: Circle())
                VStack(alignment: .leading, spacing: 2) {
                    Text(p.name).font(.system(size: 17, weight: .semibold)).lineLimit(1)
                    Text(status(p)).font(.system(size: 13)).foregroundStyle(Color(hex: 0xD1D5DB)).lineLimit(2)
                }
                Spacer(minLength: 0)
            }
            .padding(Theme.Space.l)
            .frame(maxWidth: .infinity, minHeight: 96, alignment: .leading)
            .background(p.isIn ? tileIn : tile, in: RoundedRectangle(cornerRadius: 20))
        }
        .buttonStyle(.plain)
        .accessibilityLabel("\(p.name), \(status(p))")
    }

    private func pinPad(_ p: KioskPerson) -> some View {
        ViewThatFits(in: .horizontal) {
            HStack(alignment: .top, spacing: Theme.Space.xxl) { pad(p).frame(maxWidth: 420); cameraPane(p).frame(maxWidth: 520) }
            ScrollView { VStack(spacing: Theme.Space.l) { pad(p); cameraPane(p) } }
        }
        .padding(.horizontal, Theme.Space.xl)
    }

    private func pad(_ p: KioskPerson) -> some View {
        VStack(spacing: Theme.Space.m) {
            Button("← Not \(p.name.split(separator: " ").first.map(String.init) ?? p.name)? Go back") { picked = nil; pin = "" }
                .foregroundStyle(dim).frame(maxWidth: .infinity, alignment: .leading)
            Text(p.name).font(.system(size: 26, weight: .bold))
            Text(p.isIn ? "Enter your PIN to clock out." : "Enter your PIN to clock in.").foregroundStyle(Color(hex: 0xD1D5DB))
            if !p.hasPin {
                Text("You have no PIN yet. Set one under Profile › Security on your own phone or computer, then come back.")
                    .font(.system(size: 14)).foregroundStyle(Color(hex: 0xFCD34D)).multilineTextAlignment(.center)
            }
            HStack(spacing: 12) {
                ForEach(0..<6, id: \.self) { i in Circle().fill(i < pin.count ? Color.white : (i < 4 ? Color(hex: 0x4B5563) : tile)).frame(width: 16, height: 16) }
            }
            .padding(.vertical, Theme.Space.s)
            .accessibilityLabel("\(pin.count) digits entered")
            ForEach([["1", "2", "3"], ["4", "5", "6"], ["7", "8", "9"], ["⌫", "0", "OK"]], id: \.self) { row in
                HStack(spacing: Theme.Space.s) {
                    ForEach(row, id: \.self) { k in key(k, p) }
                }
            }
        }
    }

    private func key(_ k: String, _ p: KioskPerson) -> some View {
        let isOK = k == "OK"
        let label = isOK ? (busy ? "…" : (p.isIn ? "Out" : "In")) : k
        return Button {
            switch k {
            case "⌫": if !pin.isEmpty { pin.removeLast() }
            case "OK": Task { await submit(p) }
            default: if pin.count < 6 { pin.append(k) }
            }
        } label: {
            Text(label).font(.system(size: 26, weight: .semibold))
                .foregroundStyle(isOK && pin.count < 4 ? Color(hex: 0x6B7280) : .white)
                .frame(width: 104, height: 72)
                .background(isOK && pin.count >= 4 ? Theme.Palette.brand : tile, in: RoundedRectangle(cornerRadius: 18))
        }
        .buttonStyle(.plain)
        .disabled(busy)
        .accessibilityLabel(k == "⌫" ? "Delete" : (isOK ? (p.isIn ? "Clock out" : "Clock in") : k))
    }

    private func cameraPane(_ p: KioskPerson) -> some View {
        VStack(alignment: .leading, spacing: Theme.Space.s) {
            KioskCameraPreview(camera: camera)
                .aspectRatio(4.0 / 3.0, contentMode: .fit)
                .background(Color.black, in: RoundedRectangle(cornerRadius: 20))
                .clipShape(RoundedRectangle(cornerRadius: 20))
            Text("One picture is taken when you press \(p.isIn ? "Out" : "In"). Your manager can see it on the punch; it is deleted with the shift trail.")
                .font(.system(size: 12)).foregroundStyle(dim)
        }
        .task { await camera.start() }
        .onDisappear { camera.stop() }
    }

    private var footer: some View {
        HStack {
            Text("Bethany House Hub · clock-in kiosk").font(.system(size: 12)).foregroundStyle(dim)
            if !guided { Text("· Guided Access is off (triple-click the side button to keep the iPad on the board)").font(.system(size: 12)).foregroundStyle(dim) }
            Spacer()
            Button("Leave kiosk mode") { leaving = true }.font(.system(size: 12)).foregroundStyle(dim)
        }
        .padding(.horizontal, Theme.Space.xl).padding(.vertical, Theme.Space.m)
    }

    private func show(_ ok: Bool, _ text: String) {
        banner = (ok, text)
        bannerTask?.cancel()
        bannerTask = Task { try? await Task.sleep(for: .seconds(6)); if !Task.isCancelled { banner = nil } }
    }

    private func refresh() async {
        do { board = try await env.kioskAPI.board(); loadError = nil }
        catch let e as APIError { loadError = e.isOffline ? "No connection to the Hub. Punches need a connection." : e.message }
        catch { loadError = error.localizedDescription }
    }

    private func submit(_ p: KioskPerson) async {
        guard pin.count >= 4, !busy else { return }
        busy = true
        defer { busy = false }
        do {
            let photo = await camera.capture()
            let msg = try await env.kioskAPI.punch(userId: p.id, pin: pin, clockIn: !p.isIn, photoDataURL: photo)
            show(true, msg)
            picked = nil; pin = ""
            await refresh()
        } catch let e as APIError {
            show(false, e.message); pin = ""
        } catch {
            show(false, error.localizedDescription); pin = ""
        }
    }
}

/// The front camera while someone is at the PIN pad: one JPEG per punch,
/// about 480 px wide at quality 0.7 as the console sends, or nil when there
/// is no camera or no permission (the punch is then recorded without one).
@MainActor
final class KioskCamera: NSObject, AVCapturePhotoCaptureDelegate {
    let session = AVCaptureSession()
    private let output = AVCapturePhotoOutput()
    private var configured = false
    private var pending: CheckedContinuation<String?, Never>?

    func start() async {
        guard await AVCaptureDevice.requestAccess(for: .video) else { return }
        if !configured {
            session.beginConfiguration()
            session.sessionPreset = .photo
            let device = AVCaptureDevice.default(.builtInWideAngleCamera, for: .video, position: .front) ?? AVCaptureDevice.default(for: .video)
            if let device, let input = try? AVCaptureDeviceInput(device: device), session.canAddInput(input), session.canAddOutput(output) {
                session.addInput(input)
                session.addOutput(output)
                configured = true
            }
            session.commitConfiguration()
        }
        guard configured, !session.isRunning else { return }
        let s = session
        await Task.detached { s.startRunning() }.value
    }

    func stop() {
        guard session.isRunning else { return }
        let s = session
        Task.detached { s.stopRunning() }
    }

    func capture() async -> String? {
        guard configured, session.isRunning, pending == nil else { return nil }
        return await withCheckedContinuation { cont in
            pending = cont
            output.capturePhoto(with: AVCapturePhotoSettings(), delegate: self)
        }
    }

    nonisolated func photoOutput(_ output: AVCapturePhotoOutput, didFinishProcessingPhoto photo: AVCapturePhoto, error: Error?) {
        let url = photo.fileDataRepresentation().flatMap(Self.jpegDataURL)
        Task { @MainActor in self.pending?.resume(returning: url); self.pending = nil }
    }

    nonisolated static func jpegDataURL(_ data: Data) -> String? {
        guard let image = UIImage(data: data) else { return nil }
        let width: CGFloat = 480
        let size = CGSize(width: width, height: (image.size.height * width / max(image.size.width, 1)).rounded())
        let scaled = UIGraphicsImageRenderer(size: size).image { _ in image.draw(in: CGRect(origin: .zero, size: size)) }
        guard let jpeg = scaled.jpegData(compressionQuality: 0.7), jpeg.count <= 450_000 else { return nil }
        return "data:image/jpeg;base64," + jpeg.base64EncodedString()
    }
}

struct KioskCameraPreview: UIViewRepresentable {
    let camera: KioskCamera

    func makeUIView(context: Context) -> PreviewView {
        let v = PreviewView()
        v.previewLayer.session = camera.session
        v.previewLayer.videoGravity = .resizeAspectFill
        return v
    }

    func updateUIView(_ view: PreviewView, context: Context) {}

    final class PreviewView: UIView {
        override class var layerClass: AnyClass { AVCaptureVideoPreviewLayer.self }
        var previewLayer: AVCaptureVideoPreviewLayer { layer as! AVCaptureVideoPreviewLayer }
    }
}
#endif
