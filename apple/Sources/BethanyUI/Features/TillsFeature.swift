import SwiftUI
import BethanyKit

/// Tills (TillsPage.tsx → TillController): the till lifecycle's back office.
/// Verify & finalize (pos.till_verify, verifier ≠ operator), reconcile against
/// the payments ledger (pos.reconcile), record a correction (pos.till_correction).
/// Every figure is the server's, per currency (TillPresenter); a finalized
/// till's money is locked and a change is a `till_corrections` row.
@MainActor
enum TillsFeature {
    static func register() {
        FeatureRegistry.register(.tills) { _ in AnyView(TillsListView()) }
    }
}

struct TillRow: Identifiable, Decodable, Sendable, Hashable {
    let record: Record
    var id: String { record.id }
    init(from decoder: Decoder) throws { record = try Record(from: decoder) }
    var json: JSONValue { record.json }
    var stage: String? { json["stage"]?.string }
    var home: String { json["currency_code"]?.string ?? "KES" }
    var balances: [JSONValue] { json["balances"]?.array ?? [] }
    var blind: Bool { json["blind"]?.bool ?? false }
}

struct TillsListView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var list: PagedList<TillRow>?
    @State private var tab: Tab = .all
    @State private var selected: TillRow?

    enum Tab: String, CaseIterable, Identifiable {
        case all, awaiting, open, toReconcile, mismatch, discrepancy, finalized
        var id: String { rawValue }
        var label: String {
            switch self {
            case .all: return "All"; case .awaiting: return "Awaiting verification"; case .open: return "Open"
            case .toReconcile: return "To reconcile"; case .mismatch: return "Mismatch"; case .discrepancy: return "Discrepancies held"; case .finalized: return "Finalized"
            }
        }
        /// TillsPage.tsx TAB_PARAMS → TillController::index filters.
        var query: [String: String?] {
            switch self {
            case .all: return [:]
            case .awaiting: return ["stage": "awaiting_verification"]
            case .open: return ["stage": "open"]
            case .toReconcile: return ["reconciliation": "none"]
            case .mismatch: return ["reconciliation": "mismatch"]
            case .discrepancy: return ["discrepancy": "awaiting_approval"]
            case .finalized: return ["stage": "finalized"]
            }
        }
    }

    private var tabs: [Tab] {
        let s = env.session
        let backOffice = s.can("pos.till_verify") || s.can("pos.reconcile") || s.can("pos.till_correction")
        var t: [Tab] = [.all]
        if backOffice { t.append(.awaiting) }
        t.append(.open)
        if s.can("pos.reconcile") { t.append(contentsOf: [.toReconcile, .mismatch]) }
        if backOffice { t.append(.discrepancy) }
        t.append(.finalized)
        return t
    }

    var body: some View {
        Page("Tills", subtitle: "Blind to the operator until verified; each currency counted apart") {
            Picker("", selection: $tab) { ForEach(tabs) { Text($0.label).tag($0) } }.pickerStyle(.segmented)
            if let list {
                LoadableView(list.state, retry: { Task { await list.load() } }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No tills here", symbol: "tray.2") }) { rows in
                    LazyVStack(spacing: Theme.Space.s) {
                        ForEach(rows) { t in
                            Button { selected = t } label: { tillRow(t) }.buttonStyle(.plain)
                                .onAppear { if t.id == rows.last?.id { Task { await list.loadMore() } } }
                        }
                    }
                }
            }
        }
        .task {
            if list == nil {
                let s = env.session
                tab = s.can("pos.till_verify") ? .awaiting : (s.can("pos.reconcile") ? .toReconcile : .all)
                list = PagedList(client: s.client, path: "\(PosAPI.base)/tills", query: tab.query)
                await list?.load()
            }
        }
        .onChange(of: tab) { _, t in list?.query = t.query; Task { await list?.load() } }
        .refreshable { await list?.load() }
        .sheet(item: $selected) { t in TillDetailView(id: t.record.int("id") ?? 0) { Task { await list?.load() } } }
    }

    private func tillRow(_ t: TillRow) -> some View {
        let j = t.json
        return HStack(alignment: .top) {
            VStack(alignment: .leading, spacing: 3) {
                HStack {
                    Text(j["register_name"]?.string ?? j["register_number"]?.string ?? "Till #\(j["id"]?.int ?? 0)").font(Theme.Font.body)
                    StatusBadge(t.stage)
                    if j["legacy"]?.bool == true { StatusBadge("legacy", tone: .neutral) }
                    if j["left_open_overnight"]?.bool == true { StatusBadge("left open overnight", tone: .warning) }
                }
                Text("\(j["outlet_name"]?.string ?? "") · \(j["opened_by"]?.string ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                DateText(j["opened_at"]?.date)
            }
            Spacer()
            VStack(alignment: .trailing, spacing: 2) {
                ForEach(Array(t.balances.enumerated()), id: \.offset) { _, b in
                    let code = b["currency_code"]?.string ?? t.home
                    HStack(spacing: 6) {
                        Text(code).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                        if let c = b["counted_cash"], !c.isNull { MoneyText(Money(json: c, currency: code)) } else { Text("open").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                        VarianceText(b["variance"], code: code, cls: b["variance_class"]?.string)
                    }
                }
                if t.blind { Text("blind").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
            }
        }
        .padding(Theme.Space.m).frame(maxWidth: .infinity).background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.field)).contentShape(Rectangle())
    }
}

struct VarianceText: View {
    let value: JSONValue?
    let code: String
    let cls: String?
    init(_ value: JSONValue?, code: String, cls: String?) { self.value = value; self.code = code; self.cls = cls }
    var body: some View {
        if let v = value, !v.isNull, let m = Money(json: v, currency: code) {
            Text(m.isZero ? "balanced" : (m.isNegative ? "short \(m.formatted())" : "over \(m.formatted())"))
                .font(Theme.Font.caption).foregroundStyle(m.isZero ? Theme.Palette.success : Theme.Palette.danger)
        }
    }
}

// MARK: - Detail (GET pos/tills/{id})

struct TillDetailView: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let id: Int
    let onChanged: () -> Void
    @State private var till: Resource<JSONValue>?
    @State private var varianceReason = ""
    @State private var verifyNotes = ""
    @State private var recNotes = ""
    @State private var corrReason = ""
    @State private var corrCurrency = ""
    @State private var corrActual = ""
    @State private var corrExpected = ""
    @State private var closingNotes = ""
    @State private var busy = false

    private var api: APIClient { env.session.client }

    var body: some View {
        PosSheetFrame("Till #\(id)") {
            if let till {
                LoadableView(till.state, retry: { till.load() }) { t in content(t) }
            }
        } actions: { Button("Close") { dismiss() } }
        .task {
            if till == nil {
                let c = api, tid = id
                till = Resource { try await c.get("\(PosAPI.base)/tills/\(tid)").json?["till"] ?? .null }
                till?.load()
            }
        }
        .onChange(of: till?.value?["closing_notes"]?.string) { _, n in closingNotes = n ?? "" }
    }

    @ViewBuilder
    private func content(_ t: JSONValue) -> some View {
        let home = t["currency_code"]?.string ?? "KES"
        let blind = t["blind"]?.bool ?? false
        let balances = t["balances"]?.array ?? []
        let multi = balances.count > 1
        let isOperator = t["opened_by_id"]?.int == env.session.me?.id
        HStack {
            Text(t["register_name"]?.string ?? t["register_number"]?.string ?? "").font(Theme.Font.sectionTitle)
            StatusBadge(t["stage"]?.string)
            if t["left_open_overnight"]?.bool == true { StatusBadge("left open overnight", tone: .warning) }
            Spacer()
        }
        DetailRow("Outlet", t["outlet_name"]?.string)
        DetailRow("Operator", t["opened_by"]?.string)
        DetailRow("Opened") { DateText(t["opened_at"]?.date) }
        if let c = t["closed_at"]?.date { DetailRow("Counted") { DateText(c) } }
        if let f = t["finalized_at"]?.date { DetailRow("Finalized by \(t["verified_by"]?.string ?? "")") { DateText(f) } }
        DetailRow("Transactions", t["transaction_count"]?.string)
        if blind {
            Label("Blind count: the expected figure and any variance are shown once your outlet manager has finalized this till.", systemImage: "eye.slash")
                .font(Theme.Font.caption).foregroundStyle(Theme.Palette.amber)
        }

        ForEach(Array(balances.enumerated()), id: \.offset) { _, b in
            let code = b["currency_code"]?.string ?? home
            SectionCard(multi ? "\(code)\(b["is_home"]?.bool == true ? " (till's own)" : "")" : "Drawer (\(code))") {
                money("Opening float", b["opening_cash"], code)
                money("Count", b["counted_cash"], code)
                if !blind {
                    if let br = b["breakdown"] {
                        money("+ Cash sales", br["cash_sales"], code)
                        if let ci = br["cash_in"]?.money, !ci.isZero { money("+ Cash in", br["cash_in"], code) }
                        money("− Cash refunds", br["refunds"], code)
                        money("− Voids", br["voids"], code)
                        money("− Paid-outs", br["paid_outs"], code)
                    }
                    money("Expected cash", b["expected_cash_at_count"].flatMap { $0.isNull ? nil : $0 } ?? b["breakdown"]?["expected"] ?? b["expected_cash"], code, strong: true)
                    HStack { Text("Variance").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary); Spacer(); VarianceText(b["variance"], code: code, cls: b["variance_class"]?.string) }
                    if let mm = b["expected_basis_mismatch"], !mm.isNull, let m = Money(json: mm, currency: code), !m.isZero {
                        Text("Legacy register: the ledger figure differs from the old running column by \(m.formatted()).").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning)
                    }
                    money("Sales", b["total_sales"], code); money("Cash sales", b["total_cash_sales"], code); money("Card", b["total_card_sales"], code); money("M-Pesa", b["total_mpesa_sales"], code); money("Refunds", b["total_refunds"], code)
                }
            }
        }
        if !blind, let k = t["kes_summary"], multi {
            SectionCard("Whole till in shillings (\(k["basis"]?.string ?? "reporting rate"))") {
                money("Expected", k["expected"], "KES"); money("Counted", k["counted"], "KES")
                HStack { Text("Variance").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary); Spacer(); VarianceText(k["variance"], code: "KES", cls: nil) }
                if let u = k["unconverted"]?.array, !u.isEmpty { Text("Not converted: \(u.compactMap(\.string).joined(separator: ", "))").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning) }
            }
        }
        if let n = t["notes"]?.string, !n.isEmpty { DetailRow("Opening notes", n) }
        if let r = t["variance_reason"]?.string, !r.isEmpty { DetailRow("Variance reason", r) }
        if let d = t["discrepancies"]?.array, !d.isEmpty {
            SectionCard("Discrepancies") {
                ForEach(Array(d.enumerated()), id: \.offset) { _, x in
                    let code = x["currency_code"]?.string ?? home
                    HStack {
                        VStack(alignment: .leading, spacing: 2) {
                            HStack { Text("\(x["direction"]?.string?.capitalizedFirst ?? "") by").font(Theme.Font.secondary); MoneyText(Money(json: x["amount"] ?? .null, currency: code)); StatusBadge(x["status"]?.string) }
                            Text(x["reason"]?.string ?? "").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        }
                        Spacer()
                        if let k = x["amount_kes"], !k.isNull, code != "KES" { Text("= \(Money(json: k, currency: "KES")?.formatted() ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                    }
                }
            }
        }
        operatorNotes(t, isOperator: isOperator)
        finalizeSection(t, isOperator: isOperator)
        reconcileSection(t, home: home)
        correctionSection(t, balances: balances, home: home)
    }

    private func money(_ label: String, _ v: JSONValue?, _ code: String, strong: Bool = false) -> some View {
        HStack {
            Text(label).font(strong ? Theme.Font.body : Theme.Font.secondary).fontWeight(strong ? .semibold : .regular).foregroundStyle(strong ? Theme.text : Theme.textSecondary)
            Spacer()
            MoneyText(v.flatMap { $0.isNull ? nil : Money(json: $0, currency: code) }, emphasis: strong)
        }
    }

    @ViewBuilder
    private func operatorNotes(_ t: JSONValue, isOperator: Bool) -> some View {
        if isOperator, t["finalized_at"]?.isNull != false, t["status"]?.string != "closed" {
            SectionCard("Your closing notes") {
                TextField("Notes (before the till is finalized)", text: $closingNotes, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...5)
                Button("Save notes") { Task { await patchNotes() } }.disabled(busy)
            }
        } else if let n = t["closing_notes"]?.string, !n.isEmpty { DetailRow("Closing notes", n) }
    }

    @ViewBuilder
    private func finalizeSection(_ t: JSONValue, isOperator: Bool) -> some View {
        if env.session.can("pos.till_verify"), t["stage"]?.string == "awaiting_verification" {
            let hasVariance = (t["balances"]?.array ?? []).contains { b in (b["variance"].flatMap { Money(json: $0) }?.isZero ?? true) == false }
            SectionCard("Verify & finalize") {
                if isOperator {
                    Text("You counted this till; someone else verifies it (maker ≠ checker).").font(Theme.Font.secondary).foregroundStyle(Theme.Palette.warning)
                } else {
                    if hasVariance { TextField("Why is the till over or short? (required)", text: $varianceReason, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
                    TextField("Verification notes (optional)", text: $verifyNotes, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(1...3)
                    Text("Finalizing locks the till's money. A variance above the logged limit (at the reporting rate) is held for approval.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                    ConfirmButton("Finalize till", message: "The till's figures are locked after this; finance can only add a linked correction.", action: { await finalize(hasVariance) }) { Text("Verify & finalize") }
                        .buttonStyle(.borderedProminent).tint(Theme.Palette.brand).disabled(busy || (hasVariance && varianceReason.trimmingCharacters(in: .whitespaces).count < 3))
                }
            }
        }
    }

    @ViewBuilder
    private func reconcileSection(_ t: JSONValue, home: String) -> some View {
        let recs = t["reconciliations"]?.array ?? t["latest_reconciliations"]?.array ?? []
        if !recs.isEmpty {
            SectionCard("Reconciliations against the payments ledger") {
                ForEach(Array(recs.enumerated()), id: \.offset) { _, r in
                    let code = r["currency_code"]?.string ?? home
                    VStack(alignment: .leading, spacing: 2) {
                        HStack { Text(code).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary); StatusBadge(r["status"]?.string); if r["flagged_for_finance"]?.bool == true { StatusBadge("flagged for finance", tone: .danger) }; Spacer(); DateText(r["created_at"]?.date, style: .relative) }
                        money("Till cash sales", r["till_cash_sales"], code)
                        money("Payments ledger", r["payments_cash"], code)
                        money("Difference", r["difference"], code)
                        if let n = r["missing_from_till_count"]?.int, n > 0 { money("Cash that reached no till (\(n))", r["missing_from_till_amount"], code) }
                        if let by = r["reconciled_by"]?.string { Text("By \(by)").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                        if let n = r["notes"]?.string, !n.isEmpty { Text(n).font(Theme.Font.caption) }
                    }
                    .padding(.vertical, 4)
                }
            }
        }
        if env.session.can("pos.reconcile"), t["stage"]?.string == "finalized" {
            let excluded = [t["opened_by_id"]?.int, t["verified_by_id"]?.int].contains(env.session.me?.id)
            SectionCard("Reconcile") {
                if excluded {
                    Text("You counted or verified this till; reconciliation is independent.").font(Theme.Font.secondary).foregroundStyle(Theme.Palette.warning)
                } else {
                    TextField("Notes (optional)", text: $recNotes, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(1...3)
                    Button(busy ? "Reconciling…" : "Reconcile against payments") { Task { await reconcile() } }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand).disabled(busy)
                }
            }
        }
    }

    @ViewBuilder
    private func correctionSection(_ t: JSONValue, balances: [JSONValue], home: String) -> some View {
        if let cs = t["corrections"]?.array, !cs.isEmpty {
            SectionCard("Corrections") {
                ForEach(Array(cs.enumerated()), id: \.offset) { _, c in
                    let code = c["currency_code"]?.string ?? home
                    VStack(alignment: .leading, spacing: 2) {
                        HStack { Text(code).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary); StatusBadge(c["status"]?.string); Spacer(); DateText(c["created_at"]?.date, style: .relative) }
                        Text(c["reason"]?.string ?? "").font(Theme.Font.secondary)
                        HStack { Text("Count").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary); MoneyText(c["original_actual_cash"].flatMap { Money(json: $0, currency: code) }); Text("→"); MoneyText(c["corrected_actual_cash"].flatMap { Money(json: $0, currency: code) }) }
                        HStack { Text("Variance").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary); MoneyText(c["original_variance"].flatMap { Money(json: $0, currency: code) }); Text("→"); MoneyText(c["corrected_variance"].flatMap { Money(json: $0, currency: code) }) }
                        if let by = c["opened_by"]?.string { Text("By \(by)").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                    }
                    .padding(.vertical, 4)
                }
            }
        }
        if env.session.can("pos.till_correction"), t["stage"]?.string == "finalized" {
            SectionCard("Record a correction") {
                Text("The original stays as it was counted; the correction is a linked record.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                if balances.count > 1 {
                    Picker("Currency", selection: $corrCurrency) { Text("Till's own (\(home))").tag(""); ForEach(balances.compactMap { $0["currency_code"]?.string }.filter { $0 != home }, id: \.self) { Text($0).tag($0) } }
                }
                TextField("Reason (at least 10 characters)", text: $corrReason, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4)
                LabeledContent("Corrected count") { AmountField("leave blank to keep", text: $corrActual) }
                LabeledContent("Corrected expected") { AmountField("leave blank to keep", text: $corrExpected) }
                Button(busy ? "Recording…" : "Record correction") { Task { await correct() } }.buttonStyle(.bordered).disabled(busy || corrReason.trimmingCharacters(in: .whitespaces).count < 10)
            }
        }
    }

    // MARK: Writes

    private func run(_ work: () async throws -> JSONValue, success: String) async {
        busy = true; defer { busy = false }
        do {
            let r = try await work()
            env.toasts.show(r["message"]?.string ?? success)
            await till?.reload()
            onChanged()
        } catch { env.toasts.error(error) }
    }

    private func patchNotes() async {
        await run({ try await api.patch("\(PosAPI.base)/tills/\(id)", json: .object(["notes": .string(closingNotes)])).json ?? .null }, success: "Notes saved")
    }

    private func finalize(_ hasVariance: Bool) async {
        await run({ try await api.post("\(PosAPI.base)/tills/\(id)/finalize", json: jsonObject([
            "variance_reason": hasVariance ? .string(varianceReason.trimmingCharacters(in: .whitespaces)) : (varianceReason.isEmpty ? nil : .string(varianceReason)),
            "notes": verifyNotes.isEmpty ? nil : .string(verifyNotes),
        ])).json ?? .null }, success: "Till verified and finalized")
    }

    private func reconcile() async {
        await run({ try await api.post("\(PosAPI.base)/tills/\(id)/reconcile", json: jsonObject(["notes": recNotes.isEmpty ? nil : .string(recNotes)])).json ?? .null }, success: "Reconciled")
        recNotes = ""
    }

    private func correct() async {
        await run({ try await api.post("\(PosAPI.base)/tills/\(id)/corrections", json: jsonObject([
            "reason": .string(corrReason.trimmingCharacters(in: .whitespaces)),
            "currency_code": corrCurrency.isEmpty ? nil : .string(corrCurrency),
            "corrected_actual_cash": amountJSON(corrActual),
            "corrected_expected_cash": amountJSON(corrExpected),
        ])).json ?? .null }, success: "Correction recorded")
        corrReason = ""; corrActual = ""; corrExpected = ""
    }
}
