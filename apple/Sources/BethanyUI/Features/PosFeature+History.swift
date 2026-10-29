import SwiftUI
import BethanyKit

/// Sales history (SalesHistoryDrawer.tsx): today's sales by this cashier,
/// restore an unpaid order, request a void (Phase 4B: a request, voided only
/// when an approver signs — on this till with their PIN or from the inbox).
struct SalesHistorySheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let session: PosSession
    let onRestore: (PosSale) -> Void
    @State private var date = Date()
    @State private var search = ""
    @State private var sales: Resource<[PosSale]>?
    @State private var selected: PosSale?

    var body: some View {
        PosSheetFrame("Sales history", subtitle: session.outlet?.name) {
            HStack {
                DatePicker("", selection: $date, displayedComponents: .date).labelsHidden()
                TextField("Order number or customer", text: $search).textFieldStyle(.roundedBorder)
            }
            if let sales {
                LoadableView(sales.state, retry: { sales.load() }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No sales on this day", symbol: "clock.arrow.circlepath") }) { list in
                    let shown = search.isEmpty ? list : list.filter { $0.orderNumber.localizedCaseInsensitiveContains(search) || ($0.customerName ?? "").localizedCaseInsensitiveContains(search) }
                    ForEach(shown) { s in
                        Button { selected = s } label: { SaleRow(sale: s) }.buttonStyle(.plain)
                    }
                    Text("Each sale is shown in its own currency; the drawer adds nothing across currencies.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                }
            }
        } actions: { Button("Close") { dismiss() } }
        .task { make() }
        .onChange(of: date) { _, _ in make() }
        .sheet(item: $selected) { s in
            SaleDetailSheet(session: session, sale: s, onRestore: { onRestore($0) }, onChanged: { sales?.load() })
        }
    }

    private func make() {
        guard let outlet = session.outlet else { return }
        let api = session.api, day = HubDates.dayString(date)
        sales = Resource { try await api.sales(outletId: outlet.id, query: ["date": day, "my_orders_only": "1"]) }
        sales?.load()
    }
}

struct SaleRow: View {
    let sale: PosSale
    var body: some View {
        HStack {
            VStack(alignment: .leading, spacing: 2) {
                HStack { Text(sale.orderNumber).font(Theme.Font.body); StatusBadge(sale.isRestorable ? "awaiting payment" : sale.status, tone: sale.isRestorable ? .amber : nil) }
                Text("\(sale.customerName ?? "Walk-in") · \(sale.json["payment_method"]?.string?.humanized ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                DateText(sale.createdAt, style: .time)
            }
            Spacer()
            VStack(alignment: .trailing) {
                MoneyText(sale.total)
                if !sale.pendingReversals.isEmpty { StatusBadge("reversal pending", tone: .warning) }
            }
        }
        .padding(Theme.Space.m).background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.field)).contentShape(Rectangle())
    }
}

struct SaleDetailSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let session: PosSession
    @State var sale: PosSale
    let onRestore: (PosSale) -> Void
    let onChanged: () -> Void
    @State private var askingVoid = false
    @State private var voidReason = ""
    @State private var busy = false
    @State private var pin: PinRequest?

    var body: some View {
        PosSheetFrame(sale.orderNumber, subtitle: sale.createdAt.map { DateFormatterCache.dateTime.string(from: $0) }) {
            ForEach(Array(sale.pendingReversals.enumerated()), id: \.offset) { _, p in
                VStack(alignment: .leading, spacing: 4) {
                    HStack {
                        Text(p["kind"]?.string == "void" ? "Void requested" : "Refund requested").font(Theme.Font.secondary).fontWeight(.semibold)
                        if let a = p["amount"], !a.isNull { MoneyText(Money(json: a, currency: p["currency_code"]?.string ?? sale.currency)) }
                    }
                    let askedBy = p["requested_by"]?.string ?? ""
                    Text("Waiting for \(PinRequest.bandLabel(p["awaiting"]?.string))\(askedBy.isEmpty ? "" : " · asked by " + askedBy). Nothing has changed on the sale yet.").font(Theme.Font.caption).foregroundStyle(Theme.Palette.amber)
                    if let aid = p["approval_id"]?.int {
                        Button("Approver here? Enter their PIN") { pin = PinRequest(approvalId: aid, title: "\(p["kind"]?.string == "void" ? "Void" : "Refund on") \(sale.orderNumber)", awaiting: p["awaiting"]?.string) }
                            .buttonStyle(.bordered)
                    }
                }
                .padding(Theme.Space.m).frame(maxWidth: .infinity, alignment: .leading).background(Theme.Palette.warningSoft, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
            }
            if sale.isRestorable {
                Text("No payment recorded. Restore it to the cart to adjust it or take payment — the same order is paid, not a second one.").font(Theme.Font.caption).foregroundStyle(Theme.Palette.brandDeep)
            }
            ReceiptBody(sale: sale, outlet: session.outlet)
            if askingVoid {
                TextField("Reason for the void (required)", text: $voidReason, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4)
                HStack {
                    Button("Cancel") { askingVoid = false; voidReason = "" }
                    Button(busy ? "Requesting…" : "Request void") { Task { await requestVoid() } }.buttonStyle(.borderedProminent).tint(Theme.Palette.danger)
                        .disabled(voidReason.trimmingCharacters(in: .whitespaces).count < 3 || busy)
                }
            }
        } actions: {
            if sale.isRestorable && !session.ownerWatchesOnly { Button("Restore to cart") { onRestore(sale); dismiss() } }
            if env.session.can("pos.void"), !session.ownerWatchesOnly, sale.status != "voided", !sale.pendingReversals.contains(where: { $0["kind"]?.string == "void" }), !askingVoid {
                Button("Request void") { askingVoid = true }
            }
            Button("Close") { dismiss() }
        }
        .sheet(item: $pin) { req in
            TillApprovalPinSheet(session: session, request: req) { _ in Task { await reload() } }
        }
    }

    private func requestVoid() async {
        busy = true; defer { busy = false }
        do {
            let r = try await session.api.requestVoid(sale.id, reason: voidReason.trimmingCharacters(in: .whitespaces))
            env.toasts.show(r["message"]?.string ?? "Void requested", tone: .amber)
            askingVoid = false
            await reload()
            if let aid = r["approval"]?["id"]?.int {
                pin = PinRequest(approvalId: aid, title: "Void \(sale.orderNumber)", awaiting: r["approval"]?["awaiting"]?["permission"]?.string)
            }
        } catch { env.toasts.error(error) }
    }

    private func reload() async {
        if let fresh = try? await session.api.sale(sale.id) { sale = fresh }
        onChanged()
    }
}

enum DateFormatterCache {
    static let dateTime: DateFormatter = {
        let f = DateFormatter(); f.timeZone = HubDates.nairobi; f.locale = Locale(identifier: "en_KE"); f.dateStyle = .medium; f.timeStyle = .short; return f
    }()
}

// MARK: - Approver PIN (PosTillApprovalController: approvers, pin-sign)

struct PinRequest: Identifiable, Hashable {
    let approvalId: Int
    let title: String
    let awaiting: String?
    var id: Int { approvalId }

    /// Who a band is waiting on, in the words the till uses (TillApprovalPinModal.tsx).
    static func bandLabel(_ permission: String?) -> String {
        switch permission {
        case "pos.approve_reversal": return "an outlet manager"
        case "approvals.finance_sign": return "finance"
        case "approvals.super_sign": return "the owner"
        default: return "an approver"
        }
    }
}

struct TillApprovalPinSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let session: PosSession
    let request: PinRequest
    let onDecided: (String) -> Void
    @State private var approvers: Resource<[JSONValue]>?
    @State private var approverId: Int?
    @State private var pin = ""
    @State private var reject = false
    @State private var reason = ""
    @State private var waitingOn: String?
    @State private var note: String?
    @State private var error: String?
    @State private var busy = false

    var body: some View {
        PosSheetFrame("Approver at the till", subtitle: request.title) {
            Text("Waiting for \(PinRequest.bandLabel(waitingOn ?? request.awaiting)). They enter their own PIN here — never yours.")
                .font(Theme.Font.caption).foregroundStyle(Theme.Palette.amber).padding(Theme.Space.s).background(Theme.Palette.amberSoft, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
            if let note { Text(note).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.success) }
            if let approvers {
                LoadableView(approvers.state, retry: { approvers.load() }, isEmpty: { $0.isEmpty }, empty: { EmptyState("Nobody at this outlet can sign this now", message: "An approver can sign from their Approvals inbox instead.", symbol: "person.slash") }) { list in
                    Picker("Approver", selection: Binding(get: { approverId ?? 0 }, set: { approverId = $0 == 0 ? nil : $0 })) {
                        Text("Choose…").tag(0)
                        ForEach(Array(list.enumerated()), id: \.offset) { _, a in
                            Text("\(a["name"]?.string ?? "")\(a["pin_set"]?.bool == false ? " (no PIN set)" : "")").tag(a["id"]?.int ?? 0)
                        }
                    }
                }
            }
            Picker("", selection: $reject) { Text("Approve").tag(false); Text("Reject").tag(true) }.pickerStyle(.segmented).frame(maxWidth: 240)
            if reject { TextField("Reason (required)", text: $reason, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
            SecureField("Approver's terminal PIN", text: $pin).textFieldStyle(.roundedBorder).frame(maxWidth: 200)
                #if os(iOS)
                .keyboardType(.numberPad)
                #endif
            if let error { Text(error).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
        } actions: {
            Button("Leave for the inbox") { dismiss() }
            Button(busy ? "Signing…" : (reject ? "Reject" : "Sign")) { Task { await sign() } }
                .buttonStyle(.borderedProminent).tint(reject ? Theme.Palette.danger : Theme.Palette.brand).disabled(!canSubmit)
        }
        .task {
            if approvers == nil {
                let api = session.api, id = request.approvalId
                approvers = Resource { try await api.approvers(id) }
                approvers?.load()
            }
        }
    }

    private var canSubmit: Bool {
        approverId != nil && pin.count >= 4 && pin.count <= 6 && pin.allSatisfy(\.isNumber) && (!reject || reason.trimmingCharacters(in: .whitespaces).count >= 3) && !busy
    }

    private func sign() async {
        guard let approverId else { return }
        busy = true; defer { busy = false }
        do {
            let r = try await session.api.pinSign(request.approvalId, jsonObject([
                "approver_id": .number(Double(approverId)), "pin": .string(pin),
                "decision": .string(reject ? "reject" : "approve"), "reason": reject ? .string(reason) : nil,
            ]))
            pin = ""; error = nil
            let status = r["request"]?["status"]?.string ?? ""
            if status == "pending" {
                self.approverId = nil
                waitingOn = r["request"]?["awaiting"]?["permission"]?.string
                note = r["message"]?.string
                approvers?.load()
                return
            }
            env.toasts.show(r["message"]?.string ?? "Signed")
            onDecided(status)
            dismiss()
        } catch let e as APIError { pin = ""; error = e.message } catch { pin = ""; self.error = error.localizedDescription }
    }
}

// MARK: - Returns (POST pos/returns → a refund REQUEST, signed with a PIN)

struct ReturnsSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let session: PosSession
    @State private var query = ""
    @State private var results: [PosSale] = []
    @State private var searchTask: Task<Void, Never>?
    @State private var sale: PosSale?
    @State private var quantities: [Int: Int] = [:]
    @State private var reason = Self.reasons[0]
    @State private var customReason = ""
    @State private var refundMethod = "cash"
    @State private var busy = false
    @State private var pin: PinRequest?

    static let reasons = ["Defective / damaged product", "Wrong item received", "Customer changed mind", "Size / fit issue", "Duplicate purchase", "Other"]
    static let refundMethods = [("cash", "Cash"), ("mpesa", "M-Pesa"), ("store_credit", "Store credit"), ("card", "Card")]

    var body: some View {
        PosSheetFrame("Returns & refunds", subtitle: "A refund is a request; an approver signs it before anything is refunded.") {
            if let sale {
                HStack { Text(sale.orderNumber).font(Theme.Font.sectionTitle); StatusBadge(sale.status); Spacer(); MoneyText(sale.total); Button("Change") { self.sale = nil } }
                ForEach(Array(sale.items.enumerated()), id: \.offset) { _, i in
                    let itemId = i["id"]?.int ?? 0
                    let sold = i["quantity"]?.int ?? 0
                    HStack {
                        VStack(alignment: .leading, spacing: 1) {
                            Text(i["product_name"]?.string ?? "Item").font(Theme.Font.secondary)
                            Text("\(sold) sold · \(Money(json: i["unit_price"] ?? .null, currency: sale.currency)?.formatted() ?? "") each").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        }
                        Spacer()
                        Stepper("Return \(quantities[itemId] ?? 0)", value: Binding(get: { quantities[itemId] ?? 0 }, set: { quantities[itemId] = $0 }), in: 0...sold).frame(maxWidth: 180)
                    }
                }
                Picker("Reason", selection: $reason) { ForEach(Self.reasons, id: \.self) { Text($0) } }
                if reason == "Other" { TextField("Describe the reason", text: $customReason).textFieldStyle(.roundedBorder) }
                Picker("Refund by", selection: $refundMethod) { ForEach(Self.refundMethods, id: \.0) { Text($0.1).tag($0.0) } }
                Text("The refund amount is computed by the Hub from the lines returned and shown with the approval request.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            } else {
                TextField("Order number, name or phone (3+ characters)", text: $query).textFieldStyle(.roundedBorder)
                ForEach(results) { s in Button { sale = s; quantities = [:] } label: { SaleRow(sale: s) }.buttonStyle(.plain) }
            }
        } actions: {
            Button("Close") { dismiss() }
            if sale != nil {
                Button(busy ? "Requesting…" : "Request refund") { Task { await submit() } }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                    .disabled(busy || session.ownerWatchesOnly || !quantities.values.contains { $0 > 0 } || (reason == "Other" && customReason.isEmpty))
            }
        }
        .onChange(of: query) { _, q in
            searchTask?.cancel()
            guard q.count >= 3, let outlet = session.outlet else { results = []; return }
            searchTask = Task {
                try? await Task.sleep(for: .milliseconds(350))
                guard !Task.isCancelled else { return }
                results = (try? await session.api.sales(outletId: outlet.id, query: ["search": q])) ?? []
            }
        }
        .sheet(item: $pin) { req in TillApprovalPinSheet(session: session, request: req) { _ in dismiss() } }
    }

    private func submit() async {
        guard let sale else { return }
        busy = true; defer { busy = false }
        let items: [JSONValue] = sale.items.compactMap { i in
            guard let id = i["id"]?.int, let q = quantities[id], q > 0 else { return nil }
            return jsonObject(["variant_id": i["variant_id"].map { $0.isNull ? .null : $0 } ?? .null, "quantity": .number(Double(q))])
        }
        do {
            let r = try await session.api.processReturn(jsonObject([
                "original_order_id": .number(Double(sale.id)), "items": .array(items),
                "reason": .string(reason == "Other" ? customReason : reason), "refund_method": .string(refundMethod),
            ]))
            env.toasts.show(r["message"]?.string ?? "Refund requested", tone: .amber)
            let amount = r["refund_amount"].flatMap { Money(json: $0, currency: sale.currency) }?.formatted() ?? ""
            if let aid = r["approval"]?["id"]?.int {
                pin = PinRequest(approvalId: aid, title: "Refund on \(sale.orderNumber) · \(amount)", awaiting: r["approval"]?["awaiting"]?["permission"]?.string)
            }
        } catch { env.toasts.error(error) }
    }
}
