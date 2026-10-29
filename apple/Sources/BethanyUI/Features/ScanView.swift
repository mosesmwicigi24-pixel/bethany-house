import SwiftUI
import BethanyKit
#if os(iOS)
import VisionKit
import AVFoundation
#endif

/// Barcode / serial capture. On iPhone and iPad the camera reads barcodes
/// and printed serials through VisionKit's `DataScannerViewController`; on
/// the Mac (and on a device without a usable camera) a text field takes the
/// value — a USB scanner types into it like a keyboard, ending with return.
struct ScanView: View {
    @Environment(\.dismiss) private var dismiss
    let title: String
    let onScan: (String) -> Void
    @State private var typed = ""

    init(title: String = "Scan", onScan: @escaping (String) -> Void) {
        self.title = title
        self.onScan = onScan
    }

    var body: some View {
        VStack(spacing: Theme.Space.l) {
            HStack {
                Text(title).font(Theme.Font.sectionTitle)
                Spacer()
                Button("Done") { dismiss() }
            }
            #if os(iOS)
            if DataScannerViewController.isSupported && DataScannerViewController.isAvailable {
                DataScannerView { value in
                    onScan(value)
                }
                .frame(maxWidth: .infinity, minHeight: 320)
                .clipShape(RoundedRectangle(cornerRadius: Theme.Radius.card))
                Text("Point the camera at a barcode or printed serial. Each new code is added once.").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            } else {
                Text("The camera scanner is not available on this device.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            }
            #endif
            HStack {
                TextField("Type or scan a code, then return", text: $typed)
                    .textFieldStyle(.roundedBorder)
                    #if os(iOS)
                    .textInputAutocapitalization(.characters)
                    #endif
                    .autocorrectionDisabled()
                    .onSubmit(submitTyped)
                Button("Add", action: submitTyped).disabled(typed.trimmingCharacters(in: .whitespaces).isEmpty)
            }
        }
        .padding(Theme.Space.xl)
        .frame(minWidth: 380)
    }

    private func submitTyped() {
        let v = typed.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !v.isEmpty else { return }
        onScan(v)
        typed = ""
    }
}

/// A text field with a scan button beside it.
struct ScanField: View {
    let placeholder: String
    @Binding var text: String
    @State private var scanning = false

    var body: some View {
        HStack {
            TextField(placeholder, text: $text).textFieldStyle(.roundedBorder).autocorrectionDisabled()
            Button { scanning = true } label: { Image(systemName: "barcode.viewfinder") }
                .accessibilityLabel("Scan")
        }
        .sheet(isPresented: $scanning) {
            ScanView(title: placeholder) { value in
                text = value
                scanning = false
            }
        }
    }
}

#if os(iOS)
/// VisionKit scanner wrapped for SwiftUI. Reports each recognised barcode or
/// text item once per session.
struct DataScannerView: UIViewControllerRepresentable {
    let onScan: (String) -> Void

    func makeUIViewController(context: Context) -> DataScannerViewController {
        let vc = DataScannerViewController(
            recognizedDataTypes: [.barcode(), .text()],
            qualityLevel: .balanced,
            recognizesMultipleItems: false,
            isHighFrameRateTrackingEnabled: true,
            isHighlightingEnabled: true
        )
        vc.delegate = context.coordinator
        try? vc.startScanning()
        return vc
    }

    func updateUIViewController(_ uiViewController: DataScannerViewController, context: Context) {}

    static func dismantleUIViewController(_ uiViewController: DataScannerViewController, coordinator: Coordinator) {
        uiViewController.stopScanning()
    }

    func makeCoordinator() -> Coordinator { Coordinator(onScan: onScan) }

    final class Coordinator: NSObject, DataScannerViewControllerDelegate {
        let onScan: (String) -> Void
        private var seen: Set<String> = []
        init(onScan: @escaping (String) -> Void) { self.onScan = onScan }

        func dataScanner(_ dataScanner: DataScannerViewController, didAdd addedItems: [RecognizedItem], allItems: [RecognizedItem]) {
            for item in addedItems {
                let value: String?
                switch item {
                case .barcode(let b): value = b.payloadStringValue
                case .text(let t): value = t.transcript
                @unknown default: value = nil
                }
                guard let v = value?.trimmingCharacters(in: .whitespacesAndNewlines), !v.isEmpty, !seen.contains(v) else { continue }
                seen.insert(v)
                UINotificationFeedbackGenerator().notificationOccurred(.success)
                onScan(v)
            }
        }

        func dataScanner(_ dataScanner: DataScannerViewController, didTapOn item: RecognizedItem) {
            if case .barcode(let b) = item, let v = b.payloadStringValue { onScan(v) }
            if case .text(let t) = item { onScan(t.transcript) }
        }
    }
}
#endif
