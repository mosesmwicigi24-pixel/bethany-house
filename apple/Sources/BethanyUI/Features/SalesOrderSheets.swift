import SwiftUI
import BethanyKit

// Every sheet an order's actions open. Payload keys are the controllers'
// validation rules (OrderController, PaymentController,
// PaymentApprovalController, SalesDocumentRevisionController).

// MARK: Status — PUT /orders/{id}/status {status, tracking_number?, notes?}

struct OrderStatusSheet: View {
    @Environment(AppEnvironment.self) private var env
    let order: Record
    let onDone: () -> Void
    let onCancel: () -> Void
    @State private var status = ""
    @State private var tracking = ""
    @State private var notes = ""

    var body: some View {
        let next = Sales.nextStatuses(from: order.status)
        SheetFrame(title: "Update order status", submitLabel: "Update", canSubmit: !status.isEmpty, onCancel: onCancel, onSubmit: {
            var body: [String: JSONValue] = ["status": .string(status)]
            if !tracking.trimmingCharacters(in: .whitespaces).isEmpty { body["tracking_number"] = .string(tracking) }
            if !notes.trimmingCharacters(in: .whitespaces).isEmpty { body["notes"] = .string(notes) }
            _ = try await env.session.client.put("/v1/admin/orders/\(order.id)/status", json: .object(body))
            env.toasts.show("Status updated")
            onDone()
        }) {
            Text("From \((order.status ?? "").humanized) the order may move to:").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            Picker("New status", selection: $status) {
                Text("Choose…").tag("")
                ForEach(next, id: \.self) { Text($0.humanized).tag($0) }
            }
            if status == "shipped" { TextInput(label: "Tracking number (optional)", text: $tracking) }
            if status == "cancelled" { Text("Cancelling takes the order out of recognised sales; settled money stays until reversed or refunded.").font(Theme.Font.caption).foregroundStyle(Theme.Palette.danger) }
            if status == "completed", order.string("payment_status") != "paid" { Text("The order is not fully paid; the server may refuse Completed.").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning) }
            TextInput(label: "Note (optional)", text: $notes, multiline: true)
        }
        .onAppear { if status.isEmpty { status = next.first ?? "" } }
    }
}

// MARK: Record payment — POST /orders/{id}/payments, then POST /payments/{p}/upload-proof

/// Methods come from GET /v1/payment-methods?currency= (the sale picker, not
/// Setup); `requires_approval` is the server's classification. "Other" sends
/// method=other with custom_method_name. An M-PESA STK push is
/// POST /orders/{id}/payment {payment_method: mpesa, phone}; the payment
/// then settles when Safaricom answers (verify from the payment row).
struct RecordPaymentSheet: View {
    @Environment(AppEnvironment.self) private var env
    let order: Record
    let onDone: () -> Void
    let onCancel: () -> Void
    @State private var methods: [Record] = []
    @State private var method = ""
    @State private var customName = ""
    @State private var amount = ""
    @State private var reference = ""
    @State private var phone = ""
    @State private var taxInclusive = true
    @State private var notes = ""
    @State private var proof: SalesPickedFile?
    @State private var pushing = false

    private var currency: String { order.string("currency_code") ?? "KES" }
    private var selected: Record? { methods.first { $0.string("code") == method } }
    private var isOther: Bool { method == "__other__" }
    private var effectiveMethod: String { isOther ? "other" : method }
    private var requiresApproval: Bool { isOther ? true : (selected?["requires_approval"]?.bool ?? !["cash", "mpesa", "card", "card_paystack", "card_flutterwave", "paystack"].contains(effectiveMethod)) }
    private var referenceRequired: Bool { effectiveMethod == "bank_transfer" || isOther }
    private var canSubmit: Bool {
        Sales.amountJSON(amount) != nil && !method.isEmpty
            && (!referenceRequired || !reference.trimmingCharacters(in: .whitespaces).isEmpty)
            && (!isOther || !customName.trimmingCharacters(in: .whitespaces).isEmpty)
    }

    var body: some View {
        SheetFrame(title: "Record payment", submitLabel: requiresApproval ? "Submit for approval" : "Record", canSubmit: canSubmit, onCancel: onCancel, onSubmit: {
            guard let amt = Sales.amountJSON(amount) else { return }
            var body: [String: JSONValue] = ["method": .string(effectiveMethod), "amount": amt, "tax_inclusive": .bool(taxInclusive)]
            if !reference.trimmingCharacters(in: .whitespaces).isEmpty { body["reference"] = .string(reference) }
            if !phone.trimmingCharacters(in: .whitespaces).isEmpty { body["phone"] = .string(phone) }
            if !notes.trimmingCharacters(in: .whitespaces).isEmpty { body["notes"] = .string(notes) }
            if isOther { body["custom_method_name"] = .string(customName) }
            let r = try await env.session.client.post("/v1/admin/orders/\(order.id)/payments", json: .object(body))
            if let proof, let pid = r.json?["payment"]?["id"]?.string {
                _ = try await env.session.client.send(.init(.post, "/v1/admin/payments/\(pid)/upload-proof", body: .multipart([proof.part(named: "proof")])))
            }
            let needs = r.json?["requires_approval"]?.bool ?? requiresApproval
            env.toasts.show(needs ? "Payment submitted — awaiting approval before it takes effect" : "Payment recorded")
            onDone()
        }) {
            DetailRow("Order total") { MoneyText(order.money("total_amount")) }
            DetailRow("Payment status", order.string("payment_status")?.humanized)
            if methods.isEmpty {
                Text("No payment method is set up for \(currency). Set them up in Setup › Payment Methods.").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning)
            }
            Picker("Method", selection: $method) {
                ForEach(methods) { m in Text(m.string("name") ?? m.string("code") ?? "").tag(m.string("code") ?? "") }
                Text("Other").tag("__other__")
            }
            if isOther { TextInput(label: "Method name (e.g. Cheque, Wire transfer)", text: $customName) }
            SalesLabeledField(label: "Amount (\(currency))") { TextField("0.00", text: $amount).textFieldStyle(.roundedBorder) }
            if effectiveMethod == "mpesa" {
                TextInput(label: "Customer phone", text: $phone)
                HStack {
                    Button {
                        pushing = true
                        Task {
                            defer { pushing = false }
                            do {
                                _ = try await env.session.client.post("/v1/admin/orders/\(order.id)/payment", json: .object(["payment_method": .string("mpesa"), "phone": .string(phone)]))
                                env.toasts.show("STK push sent — the payment settles when the customer confirms; verify it from the payment row if it stays pending", tone: .info)
                                onDone()
                            } catch { env.toasts.error(error) }
                        }
                    } label: { Label("Send STK push", systemImage: "iphone.radiowaves.left.and.right") }
                    .disabled(pushing || phone.trimmingCharacters(in: .whitespaces).isEmpty)
                    Text("or record a payment already received with its receipt below").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                }
            }
            TextInput(label: referenceRequired ? "Reference (required)" : "Reference / receipt", text: $reference)
            Toggle("Amount includes tax", isOn: $taxInclusive)
            TextInput(label: "Notes", text: $notes, multiline: true)
            if requiresApproval {
                VStack(alignment: .leading, spacing: 4) {
                    Text("This method needs an approver's signature; attach proof (PDF or image, max 10 MB).").font(Theme.Font.caption).foregroundStyle(Theme.Palette.amber)
                    HStack {
                        SalesFilePickButton(label: proof == nil ? "Attach proof" : "Replace proof") { proof = $0.first }
                        if let proof { Text(proof.filename).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                    }
                }
            }
        }
        .task {
            do {
                let r = try await env.session.client.get("/v1/payment-methods", query: ["currency": currency])
                methods = r.json?.salesRecords.filter { m in
                    guard m["is_active"]?.bool != false else { return false }
                    let supported = m["supported_currencies"]?.array?.compactMap(\.string) ?? []
                    return supported.isEmpty || supported.contains(currency)
                } ?? []
                if method.isEmpty { method = methods.first?.string("code") ?? "" }
            } catch { env.toasts.error(error) }
        }
    }
}

/// POST /payments/{id}/upload-proof (multipart `proof`) — payments.upload_proof.
struct ProofUploadButton: View {
    @Environment(AppEnvironment.self) private var env
    let paymentId: String
    let onDone: () -> Void
    @State private var busy = false
    var body: some View {
        SalesFilePickButton(label: busy ? "Uploading…" : "Upload proof") { files in
            guard let f = files.first else { return }
            busy = true
            Task {
                defer { busy = false }
                do {
                    _ = try await env.session.client.send(.init(.post, "/v1/admin/payments/\(paymentId)/upload-proof", body: .multipart([f.part(named: "proof")])))
                    env.toasts.show("Proof uploaded — awaiting review")
                    onDone()
                } catch { env.toasts.error(error) }
            }
        }
        .disabled(busy)
    }
}

// MARK: Verify — POST /orders/{id}/payments/{p}/verify-mpesa | verify-paystack

struct VerifyMpesaSheet: View {
    @Environment(AppEnvironment.self) private var env
    let order: Record
    let payment: Record
    let onDone: () -> Void
    let onCancel: () -> Void
    @State private var code = ""
    var body: some View {
        SheetFrame(title: "Verify with Daraja", submitLabel: "Verify", onCancel: onCancel, onSubmit: {
            let body: JSONValue = code.trimmingCharacters(in: .whitespaces).isEmpty ? .object([:]) : .object(["transaction_code": .string(code.trimmingCharacters(in: .whitespaces))])
            let r = try await env.session.client.post("/v1/admin/orders/\(order.id)/payments/\(payment.id)/verify-mpesa", json: body)
            let pending = r.status == 202 || r.json?["pending"]?.bool == true
            env.toasts.show(r.json?["message"]?.string ?? (pending ? "Safaricom was asked; the payment updates when they answer" : "Payment confirmed"), tone: pending ? .info : .success)
            onDone()
        }) {
            Text("Leave the code empty to query the STK push; enter the M-PESA receipt code for a payment made offline.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            TextInput(label: "M-PESA receipt code (optional)", text: $code)
        }
    }
}

struct VerifyPaystackSheet: View {
    @Environment(AppEnvironment.self) private var env
    let order: Record
    let payment: Record
    let onDone: () -> Void
    let onCancel: () -> Void
    @State private var reference = ""
    var body: some View {
        SheetFrame(title: "Verify with Paystack", submitLabel: "Verify", canSubmit: !reference.trimmingCharacters(in: .whitespaces).isEmpty, onCancel: onCancel, onSubmit: {
            let r = try await env.session.client.post("/v1/admin/orders/\(order.id)/payments/\(payment.id)/verify-paystack", json: .object(["reference": .string(reference.trimmingCharacters(in: .whitespaces))]))
            env.toasts.show(r.json?["message"]?.string ?? "Paystack payment confirmed")
            onDone()
        }) {
            TextInput(label: "Paystack reference", text: $reference)
        }
        .onAppear { if reference.isEmpty { reference = payment.string("provider_reference") ?? "" } }
    }
}

// MARK: Reverse — POST /orders/{id}/payments[/{p}]/reverse {reason, restore_status}

struct ReversePaymentSheet: View {
    @Environment(AppEnvironment.self) private var env
    let order: Record
    let payment: Record?
    let onDone: () -> Void
    let onCancel: () -> Void
    @State private var reason = ""
    @State private var restore = true
    var body: some View {
        SheetFrame(title: payment == nil ? "Reverse every settled payment" : "Reverse payment \(payment?.string("payment_number") ?? "")", submitLabel: env.session.permissions.isSuperAdmin ? "Reverse now" : "Ask finance to reverse", canSubmit: reason.trimmingCharacters(in: .whitespaces).count >= 3, destructive: true, onCancel: onCancel, onSubmit: {
            let path = payment.map { "/v1/admin/orders/\(order.id)/payments/\($0.id)/reverse" } ?? "/v1/admin/orders/\(order.id)/payments/reverse"
            let r = try await env.session.client.post(path, json: .object(["reason": .string(reason.trimmingCharacters(in: .whitespaces)), "restore_status": .bool(restore)]))
            env.toasts.show(r.json?["message"]?.string ?? (r.status == 202 ? "Reversal requested — finance signs it" : "Payment reversed"), tone: r.status == 202 ? .info : .success)
            onDone()
        }) {
            if let payment { DetailRow("Amount") { MoneyText(payment.money("amount", currency: payment.string("currency_code") ?? order.string("currency_code"))) } }
            Text("Nothing is deleted: the payment is voided with the reason; stock and the receipt follow. The super admin executes at once; everyone else raises a payment_void request finance signs.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            TextInput(label: "Why was this payment a mistake?", text: $reason, multiline: true)
            Toggle("Restore the order status the payment had advanced", isOn: $restore)
        }
    }
}

// MARK: Correct payment details — PUT /orders/{id}/payments/{p}/revise (super admin)

struct RevisePaymentSheet: View {
    @Environment(AppEnvironment.self) private var env
    let order: Record
    let payment: Record
    let onDone: () -> Void
    let onCancel: () -> Void
    @State private var reason = ""
    @State private var reference = ""
    @State private var phone = ""
    @State private var method = ""
    @State private var paidAt: Date?
    @State private var methods: [Record] = []
    var body: some View {
        SheetFrame(title: "Correct payment details", submitLabel: "Save correction", canSubmit: reason.trimmingCharacters(in: .whitespaces).count >= 5, onCancel: onCancel, onSubmit: {
            var body: [String: JSONValue] = ["reason": .string(reason.trimmingCharacters(in: .whitespaces))]
            body["provider_reference"] = Sales.optionalString(reference)
            body["phone_number"] = Sales.optionalString(phone)
            if !method.isEmpty { body["payment_method"] = .string(method) }
            if let paidAt { body["paid_at"] = .string(HubDates.iso8601.string(from: paidAt)) }
            _ = try await env.session.client.put("/v1/admin/orders/\(order.id)/payments/\(payment.id)/revise", json: .object(body))
            env.toasts.show("Payment details corrected")
            onDone()
        }) {
            Text("The amount of a recorded payment is never edited here: void it and record it again. The previous details are kept as a revision.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            TextInput(label: "Reason (required)", text: $reason, multiline: true)
            TextInput(label: "Provider reference", text: $reference)
            TextInput(label: "Phone number", text: $phone)
            Picker("Method", selection: $method) {
                Text("Keep \((payment.string("payment_method") ?? "").humanized)").tag("")
                ForEach(methods) { m in Text(m.string("name") ?? "").tag(m.string("code") ?? "") }
            }
            SalesOptionalDateField(label: "Paid at", date: $paidAt)
        }
        .onAppear {
            reference = payment.string("provider_reference") ?? ""
            phone = payment.string("phone_number") ?? ""
            paidAt = payment.date("paid_at")
        }
        .task {
            if let r = try? await env.session.client.get("/v1/payment-methods", query: ["currency": order.string("currency_code")]) { methods = r.json?.salesRecords ?? [] }
        }
    }
}

// MARK: Refund — POST /orders/{id}/refund {amount, reason, refund_shipping}

struct RefundSheet: View {
    @Environment(AppEnvironment.self) private var env
    let order: Record
    let onDone: () -> Void
    let onCancel: () -> Void
    @State private var amount = ""
    @State private var reason = ""
    @State private var refundShipping = false
    var body: some View {
        SheetFrame(title: "Refund \(order.string("order_number") ?? "")", submitLabel: "Refund", canSubmit: Sales.amountJSON(amount) != nil && !reason.trimmingCharacters(in: .whitespaces).isEmpty, destructive: true, onCancel: onCancel, onSubmit: {
            guard let amt = Sales.amountJSON(amount) else { return }
            let r = try await env.session.client.post("/v1/admin/orders/\(order.id)/refund", json: .object(["amount": amt, "reason": .string(reason), "refund_shipping": .bool(refundShipping)]))
            env.toasts.show(r.json?["message"]?.string ?? "Refund recorded")
            onDone()
        }) {
            Text("A refund moves cash, revenue, tax, stock and COGS; the server refuses more than was collected.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            SalesLabeledField(label: "Amount (\(order.string("currency_code") ?? "KES"))") { TextField("0.00", text: $amount).textFieldStyle(.roundedBorder) }
            TextInput(label: "Reason", text: $reason, multiline: true)
            Toggle("Refund the shipping fee as well", isOn: $refundShipping)
        }
    }
}

// MARK: Void — POST /orders/{id}/void {reason}

struct VoidOrderSheet: View {
    @Environment(AppEnvironment.self) private var env
    let order: Record
    let onDone: () -> Void
    let onCancel: () -> Void
    @State private var reason = ""
    var body: some View {
        SheetFrame(title: "Void \(order.string("order_number") ?? "order")", submitLabel: "Void order", canSubmit: !reason.trimmingCharacters(in: .whitespaces).isEmpty, destructive: true, onCancel: onCancel, onSubmit: {
            let r = try await env.session.client.post("/v1/admin/orders/\(order.id)/void", json: .object(["reason": .string(reason)]))
            env.toasts.show(r.json?["message"]?.string ?? "Order voided")
            onDone()
        }) {
            Text("For test orders, duplicates or fraud: stock is returned and pending payments voided. Use Refund for a completed order.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            TextInput(label: "Reason (required)", text: $reason, multiline: true)
        }
    }
}

// MARK: Deposit — POST /orders/{id}/set-deposit {deposit_amount, balance_due_date}

struct DepositSheet: View {
    @Environment(AppEnvironment.self) private var env
    let order: Record
    let onDone: () -> Void
    let onCancel: () -> Void
    @State private var amount = ""
    @State private var dueDate: Date?
    var body: some View {
        SheetFrame(title: "Deposit terms", submitLabel: "Save terms", canSubmit: Sales.amountJSON(amount) != nil, onCancel: onCancel, onSubmit: {
            guard let amt = Sales.amountJSON(amount) else { return }
            var body: [String: JSONValue] = ["deposit_amount": amt]
            if let dueDate { body["balance_due_date"] = .string(HubDates.dayString(dueDate)) }
            let r = try await env.session.client.post("/v1/admin/orders/\(order.id)/set-deposit", json: .object(body))
            if let p = r.json?["proposal"], !p.isNull, p["status"]?.string != "applied" {
                env.toasts.show(r.json?["message"]?.string ?? "Credit past your band — waiting for finance to sign", tone: .info)
            } else { env.toasts.show("Deposit terms saved") }
            onDone()
        }) {
            DetailRow("Order total") { MoneyText(order.money("total_amount")) }
            Text("Credit past the maker's band waits for finance (a proposal); the terms stay until signed.").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            SalesLabeledField(label: "Minimum deposit (\(order.string("currency_code") ?? "KES"))") { TextField("0.00", text: $amount).textFieldStyle(.roundedBorder) }
            SalesOptionalDateField(label: "Balance due by", date: $dueDate)
        }
        .onAppear {
            if amount.isEmpty, let d = order["deposit_amount"], !d.isNull { amount = d.string ?? "" }
            if dueDate == nil { dueDate = order.date("balance_due_date") }
        }
    }
}

// MARK: Shipping fee — PATCH /orders/{id}/shipping-fee {amount, note?, shipping_method_id?}

struct ShippingFeeSheet: View {
    @Environment(AppEnvironment.self) private var env
    let order: Record
    let onDone: () -> Void
    let onCancel: () -> Void
    @State private var methods: [Record] = []
    @State private var methodId = ""
    @State private var amount = ""
    @State private var note = ""
    var body: some View {
        let chosen = methods.first { $0.id == methodId }
        let fee = chosen?["fee"]
        SheetFrame(title: "Shipping fee", submitLabel: "Save fee", canSubmit: chosen != nil ? (fee != nil && !(fee?.isNull ?? true)) : Sales.amountJSON(amount) != nil, onCancel: onCancel, onSubmit: {
            var body: [String: JSONValue] = [:]
            if let chosen, let fee, !fee.isNull {
                body["amount"] = fee
                body["shipping_method_id"] = .string(chosen.id)
            } else if let amt = Sales.amountJSON(amount) { body["amount"] = amt } else { return }
            if !note.trimmingCharacters(in: .whitespaces).isEmpty { body["note"] = .string(note) }
            let r = try await env.session.client.patch("/v1/admin/orders/\(order.id)/shipping-fee", json: .object(body))
            env.toasts.show(r.json?["message"]?.string ?? "Shipping fee saved")
            onDone()
        }) {
            DetailRow("Current fee") { MoneyText(order.money("shipping_amount")) }
            if let paid = order.string("payment_status"), paid != "pending", !env.session.can("orders.reduce_shipping_fee") {
                Text("Money has been collected on this order; lowering the fee needs orders.reduce_shipping_fee.").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning)
            }
            Picker("Shipping method", selection: $methodId) {
                Text("Type an amount").tag("")
                ForEach(methods) { m in
                    Text("\(m.string("name") ?? "") — \(m["fee"].flatMap { Money(json: $0, currency: m.string("fee_currency") ?? order.string("currency_code") ?? "KES") }?.formatted() ?? "no pricing rate")").tag(m.id)
                }
            }
            if chosen == nil {
                SalesLabeledField(label: "Fee (\(order.string("currency_code") ?? "KES"))") { TextField("0.00", text: $amount).textFieldStyle(.roundedBorder) }
            } else if fee?.isNull ?? true {
                Text("This method has no fee in the order's currency (no pricing rate).").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning)
            }
            TextInput(label: "Note", text: $note)
        }
        .task {
            if let r = try? await env.session.client.get("/v1/admin/orders/shipping-methods", query: ["currency": order.string("currency_code")]) { methods = r.json?.salesRecords ?? [] }
        }
    }
}

// MARK: Country & currency — POST /orders/{id}/update-currency {country_code}

struct CurrencySheet: View {
    @Environment(AppEnvironment.self) private var env
    let order: Record
    let onDone: () -> Void
    let onCancel: () -> Void
    @State private var countries: [Record] = []
    @State private var code = ""
    var body: some View {
        SheetFrame(title: "Country & currency", submitLabel: "Apply", canSubmit: code.count == 2, onCancel: onCancel, onSubmit: {
            let r = try await env.session.client.post("/v1/admin/orders/\(order.id)/update-currency", json: .object(["country_code": .string(code)]))
            env.toasts.show(r.json?["changed"]?.bool == true ? "Currency set to \(r.json?["currency_code"]?.string ?? "")" : "Currency unchanged (\(r.json?["currency_code"]?.string ?? ""))")
            onDone()
        }) {
            DetailRow("Current", "\(order.string("customer_country_code") ?? "—") · \(order.string("currency_code") ?? "")")
            Text("Only while no payment has started. The currency follows the country's default when it is sellable (active with a reporting rate); prices are re-quoted by the server.").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            Picker("Country", selection: $code) {
                Text("Choose…").tag("")
                ForEach(countries) { c in Text("\(c.string("name") ?? "") (\(c.string("default_currency_code") ?? "—"))").tag(c.string("code") ?? "") }
            }
        }
        .task {
            if let r = try? await env.session.client.get("/v1/admin/countries") { countries = r.json?.salesRecords.filter { $0["is_active"]?.bool != false } ?? [] }
            if code.isEmpty { code = order.string("customer_country_code") ?? "" }
        }
    }
}

// MARK: Attach customer — POST /orders/{id}/attach-customer {customer_id} | {new_customer}

struct AttachCustomerSheet: View {
    @Environment(AppEnvironment.self) private var env
    let order: Record
    let onDone: () -> Void
    let onCancel: () -> Void
    @State private var existing = true
    @State private var search = ""
    @State private var results: [Record] = []
    @State private var picked: Record?
    @State private var firstName = ""
    @State private var lastName = ""
    @State private var phone = ""
    @State private var email = ""
    @State private var company = ""

    private var canSubmit: Bool { existing ? picked != nil : !firstName.trimmingCharacters(in: .whitespaces).isEmpty && !phone.trimmingCharacters(in: .whitespaces).isEmpty }

    var body: some View {
        SheetFrame(title: "Add customer details", submitLabel: "Attach", canSubmit: canSubmit, onCancel: onCancel, onSubmit: {
            let body: JSONValue
            if existing, let picked { body = .object(["customer_id": .string(picked.id)]) }
            else {
                var nc: [String: JSONValue] = ["first_name": .string(firstName), "phone": .string(phone)]
                if !lastName.isEmpty { nc["last_name"] = .string(lastName) }
                if !email.isEmpty { nc["email"] = .string(email) }
                if !company.trimmingCharacters(in: .whitespaces).isEmpty { nc["company"] = .string(company) }
                body = .object(["new_customer": .object(nc)])
            }
            _ = try await env.session.client.post("/v1/admin/orders/\(order.id)/attach-customer", json: body)
            env.toasts.show("Customer attached")
            onDone()
        }) {
            Text("Captured once, then frozen — the server keeps the first details a sale gets.").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            Picker("", selection: $existing) { Text("Existing customer").tag(true); Text("New customer").tag(false) }.pickerStyle(.segmented)
            if existing {
                SalesSearchField(text: $search, placeholder: "Name, phone or email (3+ characters)") { Task { await find() } }
                ForEach(results) { c in
                    Button {
                        picked = c
                    } label: {
                        HStack {
                            VStack(alignment: .leading) {
                                Text(Sales.name(first: c.string("first_name"), last: c.string("last_name")) ?? c.string("customer_number") ?? "").font(Theme.Font.body)
                                Text([c.string("customer_number"), c.string("phone"), c.string("email")].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                            }
                            Spacer()
                            if picked?.id == c.id { Image(systemName: "checkmark.circle.fill").foregroundStyle(Theme.Palette.brand) }
                        }
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                }
            } else {
                TextInput(label: "First name", text: $firstName)
                TextInput(label: "Last name", text: $lastName)
                TextInput(label: "Phone (required)", text: $phone)
                TextInput(label: "Email", text: $email)
                TextInput(label: "Company", text: $company)
            }
        }
    }

    private func find() async {
        let term = search.trimmingCharacters(in: .whitespaces)
        guard term.count >= 3 else { results = []; return }
        do {
            let r = try await env.session.client.get("/v1/admin/customers", query: ["search": term, "per_page": "8"])
            results = r.json?.salesRecords ?? []
        } catch { env.toasts.error(error) }
    }
}

// MARK: Note — POST /orders/{id}/notes {note, is_internal}

struct AddNoteSheet: View {
    @Environment(AppEnvironment.self) private var env
    let order: Record
    let onDone: () -> Void
    let onCancel: () -> Void
    @State private var note = ""
    @State private var internalNote = true
    var body: some View {
        SheetFrame(title: "Add note", submitLabel: "Add", canSubmit: !note.trimmingCharacters(in: .whitespaces).isEmpty, onCancel: onCancel, onSubmit: {
            _ = try await env.session.client.post("/v1/admin/orders/\(order.id)/notes", json: .object(["note": .string(note), "is_internal": .bool(internalNote)]))
            env.toasts.show("Note added")
            onDone()
        }) {
            TextInput(label: "Note", text: $note, multiline: true)
            Toggle("Internal (staff only)", isOn: $internalNote)
        }
    }
}

// MARK: Send to production — POST /orders/{id}/items/{i}/production

struct RaiseProductionSheet: View {
    @Environment(AppEnvironment.self) private var env
    let order: Record
    let item: Record
    let onDone: () -> Void
    let onCancel: () -> Void
    @State private var color = ""
    @State private var notes = ""
    @State private var dueDate: Date?
    @State private var measurements: [(String, String)] = [("", "")]
    var body: some View {
        SheetFrame(title: "Send to production", submitLabel: "Raise production order", onCancel: onCancel, onSubmit: {
            var body: [String: JSONValue] = [:]
            if !color.trimmingCharacters(in: .whitespaces).isEmpty { body["color"] = .string(color) }
            if !notes.trimmingCharacters(in: .whitespaces).isEmpty { body["notes"] = .string(notes) }
            if let dueDate { body["due_date"] = .string(HubDates.dayString(dueDate)) }
            let m = measurements.filter { !$0.0.trimmingCharacters(in: .whitespaces).isEmpty }
            if !m.isEmpty { body["measurements"] = .object(Dictionary(uniqueKeysWithValues: m.map { ($0.0.trimmingCharacters(in: .whitespaces), JSONValue.string($0.1)) })) }
            let r = try await env.session.client.post("/v1/admin/orders/\(order.id)/items/\(item.id)/production", json: .object(body))
            env.toasts.show("Production order \(r.json?["production_order_number"]?.string ?? "") raised")
            onDone()
        }) {
            DetailRow("Line", "\(item.string("product_name") ?? "") × \(item.string("quantity") ?? "")")
            TextInput(label: "Colour", text: $color)
            SalesOptionalDateField(label: "Due date", date: $dueDate)
            SalesLabeledField(label: "Measurements") {
                ForEach(measurements.indices, id: \.self) { i in
                    HStack {
                        TextField("e.g. chest", text: Binding(get: { measurements[i].0 }, set: { measurements[i].0 = $0 })).textFieldStyle(.roundedBorder)
                        TextField("value", text: Binding(get: { measurements[i].1 }, set: { measurements[i].1 = $0 })).textFieldStyle(.roundedBorder)
                    }
                }
                Button("Add measurement") { measurements.append(("", "")) }.font(Theme.Font.secondary)
            }
            TextInput(label: "Notes for the workshop", text: $notes, multiline: true)
        }
    }
}
