import SwiftUI
import BethanyKit

struct WorkshopMeasurements: View {
    @Environment(\.dynamicTypeSize) private var typeSize
    let measurements: MeasurementSheet.Measured

    private var columns: [GridItem] {
        typeSize.isAccessibilitySize
            ? [GridItem(.flexible())]
            : [GridItem(.adaptive(minimum: typeSize >= .xLarge ? 132 : 102), spacing: 8, alignment: .topLeading)]
    }

    var body: some View {
        if measurements.gender != nil || !measurements.body.isEmpty {
            VStack(alignment: .leading, spacing: 9) {
                if let gender = measurements.gender {
                    Label(gender.capitalized, systemImage: "person")
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(Theme.Palette.brandDeep)
                        .padding(.horizontal, 9)
                        .padding(.vertical, 5)
                        .background(Theme.Palette.brand.opacity(0.09), in: Capsule())
                        .accessibilityLabel("Measurements for \(gender.capitalized)")
                }
                LazyVGrid(columns: columns, alignment: .leading, spacing: 8) {
                    ForEach(measurements.body, id: \.0) { key, value in
                        VStack(alignment: .leading, spacing: 5) {
                            HStack(alignment: .top, spacing: 5) {
                                Image(systemName: symbol(for: key))
                                    .foregroundStyle(iconColor(for: key))
                                    .accessibilityHidden(true)
                                Text(key.humanized).foregroundStyle(Theme.textSecondary)
                                    .fixedSize(horizontal: false, vertical: true)
                            }.font(.caption)
                            Text(value)
                                .font(.headline)
                                .monospacedDigit()
                                .foregroundStyle(Theme.text)
                                .fixedSize(horizontal: false, vertical: true)
                        }
                        .padding(8)
                        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
                        .background(Theme.card, in: RoundedRectangle(cornerRadius: 8))
                        .accessibilityElement(children: .ignore)
                        .accessibilityLabel(key.humanized)
                        .accessibilityValue(value)
                    }
                }
            }
            .padding(10)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(Theme.canvas, in: RoundedRectangle(cornerRadius: 12))
        }
    }

    private func iconColor(for key: String) -> Color {
        switch MeasurementSheet.rank(key) % 3 {
        case 0: return Theme.Palette.brandDeep
        case 1: return Theme.Palette.info
        default: return Theme.Palette.amber
        }
    }

    private func symbol(for key: String) -> String {
        let rank = MeasurementSheet.rank(key)
        guard MeasurementSheet.order.indices.contains(rank) else { return "ruler" }
        switch MeasurementSheet.order[rank] {
        case "neck": return "tshirt"
        case "shoulders": return "arrow.left.and.right"
        case "sleeves", "shirt_length", "blouse_length", "full_length": return "arrow.up.and.down"
        default: return "ruler"
        }
    }
}
