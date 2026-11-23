import SwiftUI
import BethanyKit

struct ProductionStageIcon: View {
    let stage: JSONValue?
    var tint: Color = Theme.textSecondary
    var completed = false

    private enum Motif {
        case desk, sewingMachine, garmentButton, symbol(String)

        init?(key: String?) {
            let key = key?.lowercased().split { !$0.isLetter && !$0.isNumber }.joined(separator: "-")
            switch key {
            case "information-desk", "info-desk": self = .desk
            case "payment", "payments": self = .symbol("banknote")
            case "material-sourcing", "materials-sourcing", "sourcing": self = .symbol("shippingbox")
            case "cutting": self = .symbol("scissors")
            case "stitching", "sewing": self = .sewingMachine
            case "button", "buttons", "buttoning": self = .garmentButton
            case "finishing": self = .symbol("sparkles")
            case "quality-check", "quality-control", "inspection", "qc": self = .symbol("checkmark.shield")
            default: return nil
            }
        }
    }

    var body: some View {
        let motif = Motif(key: stage?["slug"]?.string) ?? Motif(key: stage?["name"]?.string) ?? .symbol("list.bullet.clipboard")
        glyph(motif)
            .foregroundStyle(tint)
            .frame(width: 28, height: 28)
            .background(tint.opacity(0.10), in: RoundedRectangle(cornerRadius: 8))
            .overlay(alignment: .bottomTrailing) {
                if completed {
                    Image(systemName: "checkmark")
                        .font(.system(size: 7, weight: .bold))
                        .foregroundStyle(Theme.Palette.success)
                        .frame(width: 12, height: 12)
                        .background(Theme.card, in: Circle())
                        .offset(x: 3, y: 3)
                }
            }
            .accessibilityHidden(true)
    }

    @ViewBuilder
    private func glyph(_ motif: Motif) -> some View {
        switch motif {
        case .symbol(let name):
            Image(systemName: name).font(.system(size: 14, weight: .medium))
        case .desk, .sewingMachine, .garmentButton:
            outline(motif)
                .stroke(style: StrokeStyle(lineWidth: 1.6, lineCap: .round, lineJoin: .round))
                .frame(width: 20, height: 20)
        }
    }

    private func outline(_ motif: Motif) -> Path {
        Path { path in
            func line(_ points: [CGPoint]) {
                guard let start = points.first else { return }
                path.move(to: start)
                for point in points.dropFirst() { path.addLine(to: point) }
            }

            switch motif {
            case .desk:
                path.addRoundedRect(in: CGRect(x: 1.5, y: 7, width: 17, height: 3), cornerSize: CGSize(width: 1, height: 1))
                line([CGPoint(x: 4, y: 10), CGPoint(x: 4, y: 17)])
                line([CGPoint(x: 16, y: 10), CGPoint(x: 16, y: 17)])
                line([CGPoint(x: 10, y: 10), CGPoint(x: 10, y: 14), CGPoint(x: 16, y: 14)])
            case .sewingMachine:
                path.addRoundedRect(in: CGRect(x: 1.5, y: 15, width: 17, height: 3), cornerSize: CGSize(width: 1, height: 1))
                line([CGPoint(x: 16, y: 15), CGPoint(x: 16, y: 4), CGPoint(x: 4, y: 4),
                      CGPoint(x: 4, y: 9), CGPoint(x: 11, y: 9), CGPoint(x: 11, y: 15)])
                line([CGPoint(x: 6, y: 9), CGPoint(x: 6, y: 12)])
                line([CGPoint(x: 12, y: 2), CGPoint(x: 12, y: 4)])
            case .garmentButton:
                path.addEllipse(in: CGRect(x: 2.5, y: 2.5, width: 15, height: 15))
                for point in [CGPoint(x: 8, y: 8), CGPoint(x: 12, y: 8), CGPoint(x: 8, y: 12), CGPoint(x: 12, y: 12)] {
                    path.addEllipse(in: CGRect(x: point.x - 0.45, y: point.y - 0.45, width: 0.9, height: 0.9))
                }
            case .symbol:
                break
            }
        }
    }
}
