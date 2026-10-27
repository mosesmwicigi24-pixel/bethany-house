import SwiftUI
import BethanyKit

// MARK: - Badges, money, dates

public struct StatusBadge: View {
    let text: String
    let tone: StatusTone

    public init(_ status: String?, tone: StatusTone? = nil) {
        self.text = (status ?? "—").humanized
        self.tone = tone ?? StatusTone.forStatus(status)
    }

    public var body: some View {
        Text(text)
            .font(Theme.Font.caption)
            .foregroundStyle(tone.foreground)
            .padding(.horizontal, 8)
            .padding(.vertical, 3)
            .background(tone.background, in: Capsule())
            .accessibilityLabel("Status: \(text)")
    }
}

/// Money exactly as the server sent it; never computed here.
public struct MoneyText: View {
    let money: Money?
    let emphasis: Bool

    public init(_ money: Money?, emphasis: Bool = false) {
        self.money = money
        self.emphasis = emphasis
    }

    public var body: some View {
        Text(money?.formatted() ?? "—")
            .font(emphasis ? Theme.Font.sectionTitle : Theme.Font.body)
            .monospacedDigit()
            .foregroundStyle(money?.isNegative == true ? Theme.Palette.danger : Theme.text)
    }
}

public struct DateText: View {
    let date: Date?
    let style: Style
    public enum Style { case date, dateTime, time, relative }

    public init(_ date: Date?, style: Style = .dateTime) {
        self.date = date
        self.style = style
    }

    public var body: some View {
        Text(formatted).foregroundStyle(Theme.textSecondary).font(Theme.Font.secondary)
    }

    private var formatted: String {
        guard let date else { return "—" }
        let f = DateFormatter()
        f.timeZone = HubDates.nairobi
        f.locale = Locale(identifier: "en_KE")
        switch style {
        case .date: f.dateStyle = .medium; f.timeStyle = .none
        case .dateTime: f.dateStyle = .medium; f.timeStyle = .short
        case .time: f.dateStyle = .none; f.timeStyle = .short
        case .relative:
            let r = RelativeDateTimeFormatter()
            r.unitsStyle = .short
            return r.localizedString(for: date, relativeTo: Date())
        }
        return f.string(from: date)
    }
}

// MARK: - States

public struct EmptyState: View {
    let title: String
    let message: String?
    let symbol: String
    let action: (() -> Void)?
    let actionLabel: String?

    public init(_ title: String, message: String? = nil, symbol: String = "tray", actionLabel: String? = nil, action: (() -> Void)? = nil) {
        self.title = title
        self.message = message
        self.symbol = symbol
        self.action = action
        self.actionLabel = actionLabel
    }

    public var body: some View {
        VStack(spacing: Theme.Space.m) {
            Image(systemName: symbol).font(.system(size: 34)).foregroundStyle(Theme.textTertiary)
            Text(title).font(Theme.Font.sectionTitle)
            if let message { Text(message).font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary).multilineTextAlignment(.center) }
            if let action, let actionLabel {
                Button(actionLabel, action: action).buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
            }
        }
        .frame(maxWidth: 360)
        .padding(Theme.Space.xxl)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }
}

public struct ErrorState: View {
    let error: APIError
    let retry: (() -> Void)?

    public init(_ error: APIError, retry: (() -> Void)? = nil) {
        self.error = error
        self.retry = retry
    }

    public var body: some View {
        EmptyState(
            error.isOffline ? "Can't reach Bethany House" : (error.isDeleted ? "This record was deleted" : "Something went wrong"),
            message: error.isOffline ? "Check the connection and try again. Anything you opened before is still available." : error.message,
            symbol: error.isOffline ? "wifi.slash" : (error.isDeleted ? "trash" : "exclamationmark.triangle"),
            actionLabel: retry == nil ? nil : "Try again",
            action: retry
        )
    }
}

public struct LoadingSkeleton: View {
    let rows: Int
    public init(rows: Int = 6) { self.rows = rows }

    public var body: some View {
        VStack(spacing: Theme.Space.m) {
            ForEach(0..<rows, id: \.self) { i in
                RoundedRectangle(cornerRadius: Theme.Radius.field)
                    .fill(Theme.line)
                    .frame(height: 18)
                    .frame(maxWidth: i % 3 == 0 ? 240 : .infinity)
            }
        }
        .padding(Theme.Space.l)
        .redacted(reason: .placeholder)
        .accessibilityLabel("Loading")
    }
}

/// Renders a `Loadable` with the shared states; `content` gets the value.
public struct LoadableView<T: Sendable, Content: View>: View {
    let state: Loadable<T>
    let retry: (() -> Void)?
    let empty: (() -> EmptyState)?
    let isEmpty: (T) -> Bool
    let content: (T) -> Content

    public init(_ state: Loadable<T>, retry: (() -> Void)? = nil, isEmpty: @escaping (T) -> Bool = { _ in false }, empty: (() -> EmptyState)? = nil, @ViewBuilder content: @escaping (T) -> Content) {
        self.state = state
        self.retry = retry
        self.empty = empty
        self.isEmpty = isEmpty
        self.content = content
    }

    public var body: some View {
        switch state {
        case .idle, .loading(nil):
            LoadingSkeleton()
        case .failed(let e, nil):
            ErrorState(e, retry: retry)
        case .loaded(let v), .loading(let v?), .failed(_, let v?):
            if isEmpty(v), let empty {
                empty()
            } else {
                content(v)
                    .overlay(alignment: .top) {
                        if case .failed(let e, _) = state, e.isOffline {
                            OfflineBanner()
                        }
                    }
            }
        }
    }
}

public struct OfflineBanner: View {
    public init() {}
    public var body: some View {
        Label("Offline — showing what was last loaded", systemImage: "wifi.slash")
            .font(Theme.Font.caption)
            .foregroundStyle(Theme.Palette.warning)
            .padding(.horizontal, 12).padding(.vertical, 6)
            .background(Theme.Palette.warningSoft, in: Capsule())
            .padding(.top, 8)
    }
}

// MARK: - Layout

public struct SectionCard<Content: View>: View {
    let title: String?
    let content: Content

    public init(_ title: String? = nil, @ViewBuilder content: () -> Content) {
        self.title = title
        self.content = content()
    }

    public var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            if let title { Text(title).font(Theme.Font.sectionTitle) }
            content
        }
        .padding(Theme.Space.l)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).stroke(Theme.line, lineWidth: 1))
    }
}

/// A key-value row for detail screens.
public struct DetailRow<Value: View>: View {
    let label: String
    let value: Value

    public init(_ label: String, @ViewBuilder value: () -> Value) {
        self.label = label
        self.value = value()
    }

    public var body: some View {
        HStack(alignment: .firstTextBaseline) {
            Text(label).font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary).frame(minWidth: 120, alignment: .leading)
            Spacer(minLength: Theme.Space.m)
            value.multilineTextAlignment(.trailing)
        }
        .padding(.vertical, 4)
    }
}

public extension DetailRow where Value == Text {
    init(_ label: String, _ text: String?) {
        self.init(label) { Text(text ?? "—") }
    }
}

/// A stat tile for dashboards and report headers.
public struct StatTile: View {
    let label: String
    let value: String
    let hint: String?
    let tone: StatusTone?

    public init(_ label: String, value: String, hint: String? = nil, tone: StatusTone? = nil) {
        self.label = label
        self.value = value
        self.hint = hint
        self.tone = tone
    }

    public var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(label).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).textCase(.uppercase)
            Text(value).font(Theme.Font.stat).monospacedDigit().foregroundStyle(tone?.foreground ?? Theme.text).lineLimit(1).minimumScaleFactor(0.6)
            if let hint { Text(hint).font(Theme.Font.secondary).foregroundStyle(Theme.textTertiary) }
        }
        .padding(Theme.Space.l)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).stroke(Theme.line, lineWidth: 1))
    }
}

public struct PageHeader: View {
    let title: String
    let subtitle: String?
    public init(_ title: String, subtitle: String? = nil) { self.title = title; self.subtitle = subtitle }
    public var body: some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(title).font(Theme.Font.pageTitle)
            if let subtitle { Text(subtitle).font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary) }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.horizontal, Theme.Space.gutter)
        .padding(.top, Theme.Space.l)
    }
}

/// Primary call to action in brand orange.
public struct PrimaryButtonStyle: ButtonStyle {
    public init() {}
    public func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .font(.system(size: 15, weight: .semibold))
            .foregroundStyle(.white)
            .padding(.horizontal, 18)
            .frame(minHeight: Theme.tapTarget)
            .frame(maxWidth: .infinity)
            .background(Theme.Palette.brand.opacity(configuration.isPressed ? 0.85 : 1), in: Capsule())
    }
}

public extension ButtonStyle where Self == PrimaryButtonStyle {
    static var primary: PrimaryButtonStyle { PrimaryButtonStyle() }
}

/// A validation message under a field (422 errors).
public struct FieldError: View {
    let message: String?
    public init(_ message: String?) { self.message = message }
    public var body: some View {
        if let message {
            Text(message).font(Theme.Font.caption).foregroundStyle(Theme.Palette.danger)
        }
    }
}

/// Confirmation before anything the person cannot undo.
public struct ConfirmButton<Label: View>: View {
    let title: String
    let message: String
    let destructive: Bool
    let action: () async -> Void
    let label: Label
    @State private var showing = false
    @State private var busy = false

    public init(_ title: String, message: String, destructive: Bool = false, action: @escaping () async -> Void, @ViewBuilder label: () -> Label) {
        self.title = title
        self.message = message
        self.destructive = destructive
        self.action = action
        self.label = label()
    }

    public var body: some View {
        Button { showing = true } label: { label }
            .disabled(busy)
            .confirmationDialog(title, isPresented: $showing, titleVisibility: .visible) {
                Button(title, role: destructive ? .destructive : nil) {
                    busy = true
                    Task { await action(); busy = false }
                }
                Button("Cancel", role: .cancel) {}
            } message: { Text(message) }
    }
}

/// Toasts (toast.store.ts): one place to confirm an action or report an error.
@MainActor
@Observable
public final class Toasts {
    public struct Toast: Identifiable, Equatable {
        public let id = UUID()
        public let text: String
        public let tone: StatusTone
    }
    public private(set) var items: [Toast] = []
    public init() {}

    public func show(_ text: String, tone: StatusTone = .success) {
        let t = Toast(text: text, tone: tone)
        items.append(t)
        Task {
            try? await Task.sleep(for: .seconds(4))
            items.removeAll { $0.id == t.id }
        }
    }

    public func error(_ e: Error) {
        if let a = e as? APIError { show(a.firstFieldError ?? a.message, tone: .danger) } else { show(e.localizedDescription, tone: .danger) }
    }
}

public struct ToastHost: View {
    let toasts: Toasts
    public init(_ toasts: Toasts) { self.toasts = toasts }
    public var body: some View {
        VStack(spacing: 8) {
            ForEach(toasts.items) { t in
                Text(t.text)
                    .font(Theme.Font.secondary)
                    .foregroundStyle(t.tone.foreground)
                    .padding(.horizontal, 14).padding(.vertical, 10)
                    .background(t.tone.background, in: Capsule())
                    .shadow(color: .black.opacity(0.08), radius: 8, y: 2)
                    .transition(.move(edge: .bottom).combined(with: .opacity))
            }
        }
        .padding(Theme.Space.l)
        .animation(.spring(duration: 0.3), value: toasts.items)
        .allowsHitTesting(false)
    }
}
