import SwiftUI
import BethanyKit

/// The register gate (RegisterModal.tsx, MorningCheckGate.tsx): pick an
/// outlet, open a till with its float, resume the one already open, and the
/// morning check for a till left open from an earlier day.
struct PosRootView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var session: PosSession?
    @State private var loading = true
    @State private var error: APIError?
    @State private var showOpen = false

    var body: some View {
        Group {
            if let session {
                if loading {
                    LoadingSkeleton()
                } else if let error, session.outlets.isEmpty {
                    ErrorState(error) { Task { await bootstrap() } }
                } else if session.outlets.isEmpty {
                    EmptyState("No outlet assigned", message: "Your account is not assigned to an outlet. Ask an administrator before using the till.", symbol: "storefront")
                } else if let stale = session.staleTill {
                    MorningCheckView(session: session, stale: stale)
                } else if session.hasOpenRegister {
                    PosSaleView(session: session)
                } else {
                    gate(session)
                }
            } else {
                LoadingSkeleton()
            }
        }
        .background(Theme.canvas)
        .navigationTitle("POS")
        .task { if session == nil { await bootstrap() } }
    }

    private func gate(_ session: PosSession) -> some View {
        VStack(spacing: Theme.Space.l) {
            Image(systemName: "cart").font(.system(size: 40)).foregroundStyle(Theme.Palette.brand)
            Text("Open your till").font(Theme.Font.display(22))
            Text("One open till per person. Pick the outlet you are working at and enter the float in the drawer.")
                .font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary).multilineTextAlignment(.center)
            Picker("Outlet", selection: Binding(get: { session.outlet?.id ?? 0 }, set: { id in
                session.outlet = session.outlets.first { $0.id == id }
                Task { await session.refreshRegister() }
            })) {
                ForEach(session.outlets) { Text($0.name).tag($0.id) }
            }
            .frame(maxWidth: 360)
            if session.ownerWatchesOnly {
                OwnerWatchBanner()
            } else if env.session.can("pos.open_register") {
                Button("Open register") { showOpen = true }.buttonStyle(.primary).frame(maxWidth: 360)
            } else {
                Text("You hold pos.access but not pos.open_register — an outlet manager opens the till.").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning)
            }
            if let r = session.register, r["status"]?.string == "counted" {
                Text("Your last till at this outlet is counted and awaits verification.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            }
        }
        .frame(maxWidth: 480)
        .padding(Theme.Space.xxl)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .sheet(isPresented: $showOpen) {
            RegisterOpenSheet(session: session) { Task { await session.refreshRegister() } }
        }
    }

    private func bootstrap() async {
        let s = session ?? PosSession(api: PosAPI(client: env.session.client), offline: env.offline, me: env.session.me)
        session = s
        loading = true
        defer { loading = false }
        do {
            s.outlets = try await s.api.outlets()
            error = nil
        } catch let e as APIError { error = e; return } catch { self.error = .transport(error.localizedDescription); return }
        let draftOutlet = await s.restoreDraft()
        // Auto-resume: the outlet where THIS person already has an open till wins (PosPage.tsx auto-bypass).
        var chosen = s.outlets.first { $0.id == draftOutlet }
        if chosen == nil {
            for o in s.outlets {
                if let st = try? await s.api.registerStatus(outletId: o.id), st["has_open_register"]?.bool == true || st["stale_till"]?.isNull == false {
                    chosen = o; s.registerStatus = st; break
                }
            }
        }
        s.outlet = chosen ?? s.outlets.first
        if s.registerStatus == nil { await s.refreshRegister() }
        s.checkoutConfig = try? await s.api.checkoutConfig()
    }
}

struct OwnerWatchBanner: View {
    var body: some View {
        Label("The owner's account watches the till but does not transact (owner.no_transact). A cashier or outlet manager rings sales; you may approve with your PIN.", systemImage: "eye")
            .font(Theme.Font.caption).foregroundStyle(Theme.Palette.amber)
            .padding(Theme.Space.m).frame(maxWidth: .infinity, alignment: .leading)
            .background(Theme.Palette.amberSoft, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
    }
}

// MARK: - Open (POST pos/register/open {outlet_id, opening_cash, notes?, opening_floats?})

struct RegisterOpenSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let session: PosSession
    let onOpened: () -> Void
    @State private var openingCash = ""
    @State private var notes = ""
    @State private var floats: [FloatRow] = []
    @State private var sellable: [String] = []
    @State private var busy = false
    @State private var error: APIError?

    var body: some View {
        PosSheetFrame("Open register", subtitle: session.outlet?.name) {
            LabeledContent("Float (\(session.outlet?.currencyCode ?? "KES"))") { AmountField("0.00", text: $openingCash) }
            FieldError(error?.errors["opening_cash"]?.first)
            ForEach($floats) { $row in
                LabeledContent("Float (\(row.code))") {
                    HStack {
                        AmountField("0.00", text: $row.amount)
                        Button { floats.removeAll { $0.id == row.id } } label: { Image(systemName: "minus.circle") }.buttonStyle(.plain)
                    }
                }
            }
            let remaining = sellable.filter { c in c != session.outlet?.currencyCode && !floats.contains { $0.code == c } }
            if !remaining.isEmpty {
                Menu("Add a float in another currency") { ForEach(remaining, id: \.self) { c in Button(c) { floats.append(FloatRow(code: c)) } } }.font(Theme.Font.secondary)
            }
            TextField("Notes (optional)", text: $notes, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4)
            if let e = error, e.errors.isEmpty { Text(e.message).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
        } actions: {
            Button("Cancel") { dismiss() }
            Button("Open register") { Task { await submit() } }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand).disabled(busy || amountJSON(openingCash) == nil)
        }
        .task { sellable = await loadSellableCurrencies(env.session.client) }
    }

    private func submit() async {
        guard let outlet = session.outlet, let cash = amountJSON(openingCash) else { return }
        busy = true; defer { busy = false }
        var fl: [String: JSONValue] = [:]
        for f in floats { if let a = amountJSON(f.amount) { fl[f.code] = a } }
        do {
            let r = try await session.api.openRegister(jsonObject([
                "outlet_id": .number(Double(outlet.id)), "opening_cash": cash,
                "notes": notes.isEmpty ? nil : .string(notes), "opening_floats": fl.isEmpty ? nil : .object(fl),
            ]))
            env.toasts.show(r["message"]?.string ?? "Register opened")
            onOpened(); dismiss()
        } catch let e as APIError { error = e } catch { self.error = .transport(error.localizedDescription) }
    }
}

struct FloatRow: Identifiable {
    let id = UUID()
    var code: String
    var amount = ""
}

/// GET /v1/settings/currencies (public): the codes the books can state in
/// shillings (`sellable`). Falls back to the outlet's own currency.
func loadSellableCurrencies(_ client: APIClient) async -> [String] {
    guard let rows = try? await client.get("/v1/settings/currencies").json?["data"]?.array else { return [] }
    return rows.compactMap { r in
        guard let code = r["code"]?.string else { return nil }
        if r["is_active"]?.bool == false { return nil }
        if r["sellable"]?.bool == false { return nil }
        return code.uppercased()
    }
}

// MARK: - Close / count (POST pos/register/close — TillService::submitCount, blind)

struct RegisterCloseSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let session: PosSession
    /// The till being counted: the open register (or the stale one in the morning check).
    let outletId: Int
    let register: JSONValue?
    let eodSubmitted: Bool
    let onCounted: () -> Void
    let onRequestEod: () -> Void
    @State private var closingCash = ""
    @State private var useDenominations = false
    @State private var denoms: [Int: String] = [:]
    @State private var counts: [String: String] = [:]
    @State private var notes = ""
    @State private var busy = false
    @State private var error: APIError?

    private static let kesNotes = [1000, 500, 200, 100, 50, 40, 20, 10, 5, 1]
    private var home: String { register?["currency_code"]?.string ?? "KES" }
    private var foreign: [JSONValue] { (register?["balances"]?.array ?? []).filter { $0["is_home"]?.bool != true } }

    var body: some View {
        PosSheetFrame("Count your till", subtitle: "A blind count: the expected figure and any variance are shown to your outlet manager when they verify it.") {
            if !eodSubmitted {
                VStack(alignment: .leading, spacing: Theme.Space.s) {
                    Label("Submit your End of Day report before counting.", systemImage: "moon.stars").font(Theme.Font.secondary).foregroundStyle(Theme.Palette.warning)
                    Button("Complete EoD report first") { onRequestEod() }.buttonStyle(.bordered)
                }
                .padding(Theme.Space.m).background(Theme.Palette.warningSoft, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
            }
            if home == "KES" {
                Toggle("Count by note and coin", isOn: $useDenominations).toggleStyle(.switch).font(Theme.Font.secondary)
            }
            if useDenominations && home == "KES" {
                TileGrid(minWidth: 120) {
                    ForEach(Self.kesNotes, id: \.self) { d in
                        LabeledContent("\(d)") {
                            TextField("0", text: Binding(get: { denoms[d] ?? "" }, set: { denoms[d] = $0 })).textFieldStyle(.roundedBorder).frame(width: 64)
                                #if os(iOS)
                                .keyboardType(.numberPad)
                                #endif
                        }
                    }
                }
                Text("The Hub adds the notes up; the count is what you typed here.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            } else {
                LabeledContent("Counted cash (\(home))") { AmountField("0.00", text: $closingCash) }
                FieldError(error?.errors["closing_cash"]?.first)
            }
            ForEach(Array(foreign.enumerated()), id: \.offset) { _, b in
                let code = b["currency_code"]?.string ?? "?"
                LabeledContent("Counted \(code)") { AmountField("0.00", text: Binding(get: { counts[code] ?? "" }, set: { counts[code] = $0 })) }
            }
            if !foreign.isEmpty { Text("Each currency is counted apart; nothing is added across currencies.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
            FieldError(error?.errors["counts"]?.first)
            TextField("Closing notes (optional)", text: $notes, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4)
            if let e = error, e.errors.isEmpty { Text(e.message).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
        } actions: {
            Button("Cancel") { dismiss() }
            Button("Submit count") { Task { await submit() } }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand).disabled(busy || !eodSubmitted || !complete)
        }
    }

    private var complete: Bool {
        let homeOK = useDenominations && home == "KES" ? denoms.values.contains { Int($0) ?? 0 > 0 } : amountJSON(closingCash) != nil
        let foreignOK = foreign.allSatisfy { amountJSON(counts[$0["currency_code"]?.string ?? ""] ?? "") != nil }
        return homeOK && foreignOK
    }

    private func submit() async {
        busy = true; defer { busy = false }
        var body: [String: JSONValue?] = ["outlet_id": .number(Double(outletId)), "notes": notes.isEmpty ? nil : .string(notes)]
        if useDenominations && home == "KES" {
            var dc: [String: JSONValue] = [:]
            for (d, q) in denoms { if let n = Int(q), n > 0 { dc[String(d)] = .number(Double(n)) } }
            body["denomination_count"] = .object(dc)
            body["closing_cash"] = .number(0)   // derived server-side from the denominations
        } else {
            body["closing_cash"] = amountJSON(closingCash)
        }
        if !foreign.isEmpty {
            body["counts"] = .array(foreign.compactMap { b in
                guard let code = b["currency_code"]?.string, let a = amountJSON(counts[code] ?? "") else { return nil }
                return .object(["currency_code": .string(code), "counted_cash": a])
            })
        }
        do {
            let r = try await session.api.closeRegister(jsonObject(body))
            env.toasts.show(r["message"]?.string ?? "Count submitted")
            onCounted(); dismiss()
        } catch let e as APIError {
            if e.raw?["requires_eod"]?.bool == true { dismiss(); onRequestEod() } else { error = e }
        } catch { self.error = .transport(error.localizedDescription) }
    }
}

// MARK: - Morning check (TillGate: a till left open from an earlier day)

struct MorningCheckView: View {
    let session: PosSession
    let stale: JSONValue
    @State private var showReport = false
    @State private var showCount = false
    /// GET pos/tills/{id}: the stale till's own currency and balances, so the count asks for each.
    @State private var till: JSONValue?

    private var tradingDay: String { stale["trading_day"]?.string ?? "an earlier day" }
    private var reportDone: Bool { stale["eod_submitted"]?.bool ?? false }

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.l) {
            Text("Close your till from \(tradingDay)").font(Theme.Font.display(22))
            Text("A till of yours at \(stale["outlet_name"]?.string ?? "another outlet") was left open. The Hub refuses every sale and payment until it is reported on and counted (TILL_FROM_EARLIER_DAY).")
                .font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            step(1, "Write the End of Day report for \(tradingDay)", done: reportDone, active: !reportDone) { showReport = true }
            step(2, "Count the drawer", done: false, active: reportDone) { showCount = true }
            if session.ownerWatchesOnly { OwnerWatchBanner() }
        }
        .frame(maxWidth: 520).padding(Theme.Space.xxl).frame(maxWidth: .infinity, maxHeight: .infinity)
        .task {
            if let id = stale["id"]?.int { till = try? await session.api.client.get("\(PosAPI.base)/tills/\(id)").json?["till"] }
        }
        .sheet(isPresented: $showReport) {
            if let oid = stale["outlet_id"]?.int, let rid = stale["id"]?.int {
                UserEodSheet(session: session, outletId: oid, outletName: stale["outlet_name"]?.string ?? "", registerId: rid, fixedDate: stale["trading_day"]?.string) {
                    Task { await session.refreshRegister() }
                }
            }
        }
        .sheet(isPresented: $showCount) {
            if let oid = stale["outlet_id"]?.int {
                RegisterCloseSheet(session: session, outletId: oid, register: till ?? stale, eodSubmitted: reportDone, onCounted: { Task { await session.refreshRegister() } }, onRequestEod: { showReport = true })
            }
        }
    }

    private func step(_ n: Int, _ title: String, done: Bool, active: Bool, action: @escaping () -> Void) -> some View {
        HStack(spacing: Theme.Space.m) {
            Image(systemName: done ? "checkmark.circle.fill" : "\(n).circle").foregroundStyle(done ? Theme.Palette.success : (active ? Theme.Palette.brand : Theme.textTertiary))
            Text(title).font(Theme.Font.body).strikethrough(done)
            Spacer()
            if active && !done && !session.ownerWatchesOnly { Button("Do it") { action() }.buttonStyle(.bordered).tint(Theme.Palette.brand) }
        }
        .padding(Theme.Space.m)
        .background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.field).stroke(active ? Theme.Palette.brand : Theme.line))
    }
}

// MARK: - Shared sheet chrome and fields

struct PosSheetFrame<Content: View, Actions: View>: View {
    let title: String
    let subtitle: String?
    let content: Content
    let actions: Actions
    init(_ title: String, subtitle: String? = nil, @ViewBuilder content: () -> Content, @ViewBuilder actions: () -> Actions) {
        self.title = title; self.subtitle = subtitle; self.content = content(); self.actions = actions()
    }
    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            VStack(alignment: .leading, spacing: 2) {
                Text(title).font(Theme.Font.sectionTitle)
                if let subtitle { Text(subtitle).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
            }
            .padding(Theme.Space.xl)
            Divider()
            ScrollView { VStack(alignment: .leading, spacing: Theme.Space.m) { content }.padding(Theme.Space.xl) }
            Divider()
            HStack { Spacer(); actions }.padding(Theme.Space.l)
        }
        .frame(minWidth: 380, idealWidth: 520, minHeight: 320)
        #if os(macOS)
        .frame(maxHeight: 720)
        #endif
        .background(Theme.canvas)
    }
}

struct AmountField: View {
    let placeholder: String
    @Binding var text: String
    init(_ placeholder: String, text: Binding<String>) { self.placeholder = placeholder; _text = text }
    var body: some View {
        TextField(placeholder, text: $text)
            .textFieldStyle(.roundedBorder).multilineTextAlignment(.trailing).monospacedDigit().frame(maxWidth: 180)
            #if os(iOS)
            .keyboardType(.decimalPad)
            #endif
    }
}
