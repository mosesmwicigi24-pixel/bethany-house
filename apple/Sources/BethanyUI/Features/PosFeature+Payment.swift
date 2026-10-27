import SwiftUI
import BethanyKit
import UniformTypeIdentifiers

/// Payment against the pending order (PaymentModal.tsx → POST
/// pos/pending-order/{id}/pay). Split tender across the configured methods,
/// M-PESA STK push with Daraja verification, Paystack, cash with change, bank
/// or other with a reference and proof. Every figure shown is the Hub's;
/// the only client arithmetic is the cash-change hint, labelled as such.
struct PaymentSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    @Environment(\.openURL) private var openURL
    let session: PosSession
    let onComplete: (PosSale) -> Void

    struct Tender: Identifiable, Hashable {
        let id = UUID()
        var method: String
        var amount: String
        var reference = ""
        var cashReceived = ""
    }

    @State private var tenders: [Tender] = []
    @State private var method: String = ""
    @State private var amount = ""
    @State private var reference = ""
    @State private var cashReceived = ""
    @State private var split = false
    @State private var deposit = false
    @State private var depositAmount = ""
    @State private var proof: (name: String, data: Data, type: String)?
    @State private var showProofPicker = false
    @State private var busy = false
    @State private var error: APIError?
    // M-PESA
    @State private var phone = ""
    @State private var stkState: StkState = .idle
    @State private var stkCode = ""
    @State private var pollTask: Task<Void, Never>?
    // Paystack
    @State private var email = ""
    @State private var payURL: URL?

    enum StkState: Equatable { case idle, pushing, waiting, confirmed, failed(String) }

    private var order: JSONValue? { session.pendingOrder?["order"] }
    private var orderId: Int? { session.pendingOrderId }
    private var currency: String { session.currency }
    private var outstanding: Money? { (order?["outstanding"] ?? session.pendingOrder?["total_amount"]).flatMap { Money(json: $0, currency: currency) } }
    private var methods: [JSONValue] {
        var list = session.paymentMethods.filter { $0["is_active"]?.bool != false }
        if !list.contains(where: { $0["code"]?.string == "__other__" }) {
            list.append(.object(["code": .string("__other__"), "name": .string("Other"), "type": .string("other"), "requires_approval": .bool(true)]))
        }
        return list
    }
    private var selected: JSONValue? { methods.first { $0["code"]?.string == method } }
    private var kind: MethodKind { MethodKind(selected) }

    enum MethodKind {
        case cash, mpesa, paystack, card, bank, other
        init(_ m: JSONValue?) {
            let code = m?["code"]?.string ?? ""
            let type = m?["type"]?.string ?? ""
            let provider = m?["provider"]?.string ?? ""
            if code == "cash" || type == "cash" { self = .cash }
            else if code == "mpesa" || provider == "safaricom" { self = .mpesa }
            else if code == "paystack" || code == "card_paystack" || provider == "paystack" { self = .paystack }
            else if type == "card" || code.contains("card") || code.contains("flutterwave") { self = .card }
            else if type == "bank_transfer" || code == "bank_transfer" { self = .bank }
            else { self = .other }
        }
    }

    var body: some View {
        PosSheetFrame("Take payment", subtitle: "\(session.pendingOrder?["order_number"]?.string ?? "") · \(session.customer?.displayName ?? "Walk-in")") {
            summary
            if session.ownerWatchesOnly { OwnerWatchBanner() }
            if methods.count <= 1 {
                Label("No payment methods are configured for \(currency). Set them up under Setup › Payment Methods.", systemImage: "exclamationmark.triangle").font(Theme.Font.secondary).foregroundStyle(Theme.Palette.warning)
            }
            HStack {
                Toggle("Split tender", isOn: $split).toggleStyle(.switch)
                Toggle("Deposit / part payment", isOn: $deposit).toggleStyle(.switch)
            }
            .font(Theme.Font.secondary)
            if deposit {
                LabeledContent("Deposit amount (\(currency))") { AmountField("0.00", text: $depositAmount) }
                Text("The Hub records the rest as outstanding (receivable), never as revenue collected.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            }
            if split, !tenders.isEmpty {
                ForEach(tenders) { t in
                    HStack {
                        Text(methodName(t.method)).font(Theme.Font.secondary)
                        Text(t.amount).monospacedDigit()
                        if !t.reference.isEmpty { Text(t.reference).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                        Spacer()
                        Button { tenders.removeAll { $0.id == t.id } } label: { Image(systemName: "minus.circle") }.buttonStyle(.plain)
                    }
                    .padding(Theme.Space.s).background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
                }
                Text("The Hub checks the tenders against the outstanding balance when they are posted.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            }
            methodPicker
            methodPanel
            if let e = error { Text(e.firstFieldError ?? e.message).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
        } actions: {
            Button("Cancel") { pollTask?.cancel(); dismiss() }
            if split {
                Button("Add tender") { addTender() }.disabled(amountJSON(amount) == nil || method.isEmpty)
                Button("Post \(tenders.count) tender\(tenders.count == 1 ? "" : "s")") { Task { await post(tenders) } }
                    .buttonStyle(.borderedProminent).tint(Theme.Palette.brand).disabled(tenders.isEmpty || busy || session.ownerWatchesOnly)
            } else if kind == .mpesa || kind == .paystack {
                EmptyView()
            } else {
                Button(busy ? "Posting…" : "Record payment") { Task { await post([Tender(method: method, amount: amount, reference: reference, cashReceived: cashReceived)]) } }
                    .buttonStyle(.borderedProminent).tint(Theme.Palette.brand).disabled(amountJSON(amount) == nil || method.isEmpty || busy || session.ownerWatchesOnly)
            }
        }
        .onAppear {
            if method.isEmpty { method = (methods.first { $0["is_default"]?.bool == true } ?? methods.first)?["code"]?.string ?? "cash" }
            if amount.isEmpty, let o = outstanding { amount = plainAmount(o) }
            if phone.isEmpty, let p = session.customer?.phone, !p.contains("•") { phone = p }
            if email.isEmpty, let e = session.customer?.email, !e.contains("•") { email = e }
        }
        .onChange(of: deposit) { _, on in if on, depositAmount.isEmpty { depositAmount = amount } }
        .onDisappear { pollTask?.cancel() }
        .fileImporter(isPresented: $showProofPicker, allowedContentTypes: [.pdf, .jpeg, .png, .webP, .image]) { result in
            if case .success(let url) = result {
                let ok = url.startAccessingSecurityScopedResource()
                defer { if ok { url.stopAccessingSecurityScopedResource() } }
                if let data = try? Data(contentsOf: url) {
                    proof = (url.lastPathComponent, data, UTType(filenameExtension: url.pathExtension)?.preferredMIMEType ?? "application/octet-stream")
                }
            }
        }
    }

    private var summary: some View {
        VStack(alignment: .leading, spacing: 4) {
            HStack { Text("Total").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary); Spacer(); MoneyText(order?["total"].flatMap { Money(json: $0, currency: currency) }) }
            if let paid = order?["amount_paid"]?.money, !paid.isZero {
                HStack { Text("Paid so far").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary); Spacer(); MoneyText(order?["amount_paid"].flatMap { Money(json: $0, currency: currency) }) }
            }
            HStack { Text("Outstanding").font(Theme.Font.sectionTitle); Spacer(); MoneyText(outstanding, emphasis: true) }
        }
        .padding(Theme.Space.m).background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
    }

    private var methodPicker: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: Theme.Space.s) {
                ForEach(Array(methods.enumerated()), id: \.offset) { _, m in
                    let code = m["code"]?.string ?? ""
                    Button { method = code; stkState = .idle; error = nil } label: {
                        Text(m["name"]?.string ?? code).font(Theme.Font.secondary).padding(.horizontal, 12).padding(.vertical, 8)
                            .background(method == code ? Theme.Palette.brand : Theme.card, in: Capsule())
                            .foregroundStyle(method == code ? .white : Theme.text)
                            .overlay(Capsule().stroke(method == code ? Color.clear : Theme.line))
                    }
                    .buttonStyle(.plain)
                }
            }
        }
    }

    @ViewBuilder
    private var methodPanel: some View {
        LabeledContent("Amount (\(currency))") { AmountField("0.00", text: $amount) }
        switch kind {
        case .cash:
            LabeledContent("Cash received") { AmountField("0.00", text: $cashReceived) }
            if let hint = changeHint { Text("Change: \(hint.formatted()) (indicative — the Hub records the final figure on the receipt)").font(Theme.Font.caption).foregroundStyle(Theme.Palette.success) }
        case .mpesa:
            mpesaPanel
        case .paystack:
            paystackPanel
        case .card:
            TextField("Card / terminal reference", text: $reference).textFieldStyle(.roundedBorder)
        case .bank, .other:
            TextField(kind == .bank ? "Transfer reference" : "Reference / method name", text: $reference).textFieldStyle(.roundedBorder)
            HStack {
                Button(proof == nil ? "Attach proof of payment" : "Replace proof") { showProofPicker = true }
                if let p = proof { Text(p.name).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary); Button { proof = nil } label: { Image(systemName: "xmark.circle") }.buttonStyle(.plain) }
            }
            if selected?["requires_approval"]?.bool ?? true {
                Text("This method is held for admin approval; the sale shows an invoice until it is approved.").font(Theme.Font.caption).foregroundStyle(Theme.Palette.amber)
            }
        }
    }

    // MARK: M-PESA (POST /v1/admin/orders/{id}/payment {payment_method: mpesa, phone}; poll GET /v1/admin/orders/{id})

    @ViewBuilder
    private var mpesaPanel: some View {
        if currency != "KES" {
            Text("M-Pesa takes shillings; this order is in \(currency). Use card or bank transfer.").font(Theme.Font.secondary).foregroundStyle(Theme.Palette.warning)
        } else {
            switch stkState {
            case .idle, .failed:
                LabeledContent("Customer phone") { TextField("07…", text: $phone).textFieldStyle(.roundedBorder).frame(maxWidth: 200) }
                if case .failed(let m) = stkState { Text(m).font(Theme.Font.caption).foregroundStyle(Theme.Palette.danger) }
                Button("Send STK push") { Task { await stkPush() } }.buttonStyle(.borderedProminent).tint(Theme.Palette.success).disabled(phone.trimmingCharacters(in: .whitespaces).count < 9 || busy || session.ownerWatchesOnly)
            case .pushing:
                ProgressView("Sending to the phone…")
            case .waiting:
                ProgressView("Waiting for the customer to enter their PIN… (up to 3 minutes)")
                Button("Cancel push") { pollTask?.cancel(); stkState = .idle }
            case .confirmed:
                Label("M-Pesa payment confirmed", systemImage: "checkmark.circle.fill").foregroundStyle(Theme.Palette.success)
            }
            Divider()
            Text("Or confirm a payment the customer already made").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            HStack {
                TextField("M-Pesa code (e.g. QJL3ABC7DE)", text: $stkCode).textFieldStyle(.roundedBorder)
                Button("Verify via Daraja") { Task { await verifyMpesa() } }.disabled(stkCode.trimmingCharacters(in: .whitespaces).isEmpty || busy)
            }
            Button("Record manually with this code") { Task { await post([Tender(method: method, amount: amount, reference: stkCode)]) } }
                .disabled(stkCode.trimmingCharacters(in: .whitespaces).isEmpty || amountJSON(amount) == nil || busy || session.ownerWatchesOnly)
        }
    }

    private func stkPush() async {
        guard let orderId else { return }
        stkState = .pushing; error = nil
        do {
            _ = try await session.api.initiatePayment(orderId: orderId, .object(["payment_method": .string("mpesa"), "phone": .string(phone.trimmingCharacters(in: .whitespaces))]))
            stkState = .waiting
            startPolling(every: 10, times: 18, accept: ["paid", "partial"]) { stkState = .failed("No confirmation within 3 minutes. Verify the code the customer shows, or record it manually.") }
        } catch let e as APIError { stkState = .failed(e.message) } catch { stkState = .failed(error.localizedDescription) }
    }

    private func verifyMpesa() async {
        guard let orderId else { return }
        busy = true; defer { busy = false }
        do {
            let o = try await session.api.order(orderId)
            guard let pending = (o["payments"]?.array ?? []).first(where: { $0["status"]?.string == "pending" && $0["payment_method"]?.string == "mpesa" }), let pid = pending["id"]?.int else {
                error = APIError(status: 0, message: "No pending M-Pesa payment on this order to verify — send an STK push first, or record the code manually.")
                return
            }
            let r = try await session.api.verifyMpesa(orderId: orderId, paymentId: pid, code: stkCode.trimmingCharacters(in: .whitespaces))
            if r["pending"]?.bool == true {
                env.toasts.show(r["message"]?.string ?? "Asked Safaricom to confirm; it will settle automatically.", tone: .info)
                startPolling(every: 10, times: 18, accept: ["paid", "partial"]) { }
            } else {
                pollTask?.cancel(); stkState = .confirmed
                await completeFromServer()
            }
        } catch let e as APIError { error = e } catch { self.error = .transport(error.localizedDescription) }
    }

    // MARK: Paystack (POST payment {payment_method: card_paystack, email} → authorization_url)

    @ViewBuilder
    private var paystackPanel: some View {
        LabeledContent("Customer email") { TextField("name@example.com", text: $email).textFieldStyle(.roundedBorder).frame(maxWidth: 240) }
        if let payURL {
            Text("Payment page opened. Waiting for Paystack to confirm…").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            Button("Open the payment page again") { openURL(payURL) }
        } else {
            Button("Start card payment") { Task { await paystack() } }.buttonStyle(.borderedProminent).tint(Theme.Palette.info).disabled(!email.contains("@") || busy || session.ownerWatchesOnly)
        }
        Divider()
        TextField("Or record a card reference manually", text: $reference).textFieldStyle(.roundedBorder)
        Button("Record manually") { Task { await post([Tender(method: method, amount: amount, reference: reference)]) } }.disabled(reference.isEmpty || amountJSON(amount) == nil || busy || session.ownerWatchesOnly)
    }

    private func paystack() async {
        guard let orderId else { return }
        busy = true; defer { busy = false }
        do {
            let r = try await session.api.initiatePayment(orderId: orderId, .object(["payment_method": .string("card_paystack"), "email": .string(email.trimmingCharacters(in: .whitespaces))]))
            if let u = r["authorization_url"]?.string, let url = URL(string: u) {
                payURL = url
                openURL(url)
                startPolling(every: 5, times: 24, accept: ["paid"]) { payURL = nil; env.toasts.show("Paystack has not confirmed yet — try again or record the reference manually.", tone: .warning) }
            }
        } catch let e as APIError { error = e } catch { self.error = .transport(error.localizedDescription) }
    }

    private func startPolling(every seconds: Int, times: Int, accept: [String], onTimeout: @escaping @MainActor () -> Void) {
        guard let orderId else { return }
        pollTask?.cancel()
        pollTask = Task {
            for _ in 0..<times {
                try? await Task.sleep(for: .seconds(seconds))
                if Task.isCancelled { return }
                if let o = try? await session.api.order(orderId), let st = o["payment_status"]?.string, accept.contains(st) {
                    stkState = .confirmed
                    await completeFromServer()
                    return
                }
            }
            onTimeout()
        }
    }

    /// The gateway settled server-side: read the sale back and show its receipt.
    private func completeFromServer() async {
        guard let orderId else { return }
        if let sale = try? await session.api.sale(orderId) {
            env.toasts.show("Payment confirmed — sale complete")
            session.clearCart()
            onComplete(sale)
        }
    }

    // MARK: Posting tenders (POST pos/pending-order/{id}/pay)

    private func addTender() {
        tenders.append(Tender(method: method, amount: amount, reference: reference, cashReceived: cashReceived))
        amount = ""; reference = ""; cashReceived = ""
    }

    private func post(_ list: [Tender]) async {
        guard let orderId, !list.isEmpty else { return }
        busy = true; error = nil
        defer { busy = false }
        let rows: [[String: JSONValue?]] = list.map { t in [
            "method": .string(t.method == "__other__" ? "other" : t.method),
            "amount": amountJSON(t.amount),
            "reference": t.reference.isEmpty ? nil : .string(t.reference),
            "cash_received": amountJSON(t.cashReceived),
        ] }
        do {
            let reply: JSONValue
            if let proof {
                var parts: [APIClient.MultipartPart] = []
                for (i, r) in rows.enumerated() {
                    for (k, v) in r { if let v, let s = v.string { parts.append(.init(name: "payments[\(i)][\(k)]", value: s)) } }
                }
                if deposit, let d = amountJSON(depositAmount)?.string { parts.append(.init(name: "is_deposit", value: "true")); parts.append(.init(name: "deposit_amount", value: d)) }
                parts.append(.init(name: "proof_of_payment", data: proof.data, filename: proof.name, mimeType: proof.type))
                reply = try await session.api.recordPay(orderId, multipart: parts)
            } else {
                reply = try await session.api.recordPay(orderId, jsonObject([
                    "payments": .array(rows.map(jsonObject)),
                    "is_deposit": deposit ? .bool(true) : nil,
                    "deposit_amount": deposit ? amountJSON(depositAmount) : nil,
                ]))
            }
            pollTask?.cancel()
            let sale = PosSale(json: reply["order"] ?? .null)
            if reply["needs_approval"]?.bool == true { env.toasts.show("Payment submitted — awaiting admin approval", tone: .amber) }
            else { env.toasts.show(reply["message"]?.string ?? "Payment recorded") }
            session.clearCart()
            onComplete(sale)
        } catch let e as APIError {
            error = e
            if e.reason == "TILL_FROM_EARLIER_DAY" || e.reason == "TILL_NOT_OPEN" { await session.refreshRegister(); dismiss() }
        } catch { self.error = .transport(error.localizedDescription) }
    }

    /// Cash tendered minus the Hub's outstanding figure — a hint only, never persisted.
    private var changeHint: Money? {
        guard let o = outstanding, let c = amountJSON(cashReceived)?.string, let tendered = Decimal(string: c, locale: Locale(identifier: "en_US_POSIX")), tendered > o.amount else { return nil }
        return Money(amount: tendered - o.amount, currency: o.currency)
    }

    private func methodName(_ code: String) -> String { methods.first { $0["code"]?.string == code }?["name"]?.string ?? code.humanized }
}

/// "1234.50": a server figure as a plain decimal string, to prefill an amount field.
func plainAmount(_ m: Money) -> String {
    let f = NumberFormatter()
    f.numberStyle = .decimal
    f.usesGroupingSeparator = false
    f.minimumFractionDigits = 2
    f.maximumFractionDigits = 2
    f.locale = Locale(identifier: "en_US_POSIX")
    return f.string(from: m.amount as NSDecimalNumber) ?? "\(m.amount)"
}
