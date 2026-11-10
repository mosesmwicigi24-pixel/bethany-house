import SwiftUI
import BethanyKit

// The Approvals page's two queues beyond signatures, as on the console
// (react-admin/src/pages/approvals/ApprovalsPage.tsx: PurchaseReturnsPanel,
// PaymentApprovalsPanel). The Hub decides every outcome: maker ≠ checker,
// the order's next state and the money are the server's; these panels only
// list what waits and send the checker's decision.

// MARK: - Payment proofs (GET /payments/pending-approval; POST /payments/{id}/approve {notes?} | reject {notes})

struct PaymentProofApprovalsPanel: View {
    @Environment(AppEnvironment.self) private var env
    @State private var search = ""
    @State private var version = 0
    @State private var deciding: (payment: PendingPaymentProof, approve: Bool)?

    var body: some View {
        let canDecide = env.session.can("payments.approve_international")
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            TextField("Search order, customer…", text: $search).textFieldStyle(.roundedBorder).frame(maxWidth: 320)
            RemoteJSON("/v1/admin/payments/pending-approval", query: ["search": search.isEmpty ? nil : search, "per_page": "50"], version: version,
                       emptyTitle: "No payments awaiting approval", isEmpty: { PendingPaymentProof.rows($0).isEmpty }) { json, _ in
                LazyVStack(spacing: Theme.Space.s) {
                    ForEach(PendingPaymentProof.rows(json)) { p in row(p, canDecide: canDecide) }
                }
            }
        }
        .sheet(isPresented: Binding(get: { deciding != nil }, set: { if !$0 { deciding = nil } })) {
            if let d = deciding {
                DecisionSheet(
                    title: d.approve ? "Approve payment \(d.payment.paymentNumber)" : "Reject proof \(d.payment.paymentNumber)",
                    approve: d.approve,
                    noteLabel: d.approve ? "Approval notes" : "Reason for rejection (shown to staff)",
                    noteRequired: !d.approve,
                    warning: d.approve && !d.payment.hasProof ? "No proof has been uploaded. Approve only if you have confirmed the money another way." : nil
                ) { note in
                    // approve takes optional notes; reject requires them (PaymentApprovalController).
                    let action = d.approve ? "approve" : "reject"
                    let r = try await env.session.client.post("/v1/admin/payments/\(d.payment.id)/\(action)",
                                                              json: .object(note.isEmpty ? [:] : ["notes": .string(note)]))
                    env.toasts.show(r.json?["message"]?.string ?? (d.approve ? "Payment approved - order advanced" : "Payment proof rejected - staff notified"))
                    deciding = nil
                    version += 1
                }
            }
        }
    }

    private func row(_ p: PendingPaymentProof, canDecide: Bool) -> some View {
        SectionCard {
            VStack(alignment: .leading, spacing: Theme.Space.s) {
                HStack(spacing: Theme.Space.s) {
                    Button(p.orderNumber) { env.navigator.go(.order(id: p.orderId)) }.font(Theme.Font.mono).buttonStyle(.plain).foregroundStyle(Theme.Palette.brand)
                    if p.isInternational { Pill("International", .info) }
                    Pill(p.methodLabel)
                    if let since = p.createdAt { DateText(since, style: .relative).foregroundStyle(p.isUrgent ? Theme.Palette.danger : Theme.textSecondary) }
                    Spacer()
                }
                HStack(alignment: .firstTextBaseline, spacing: Theme.Space.m) {
                    MoneyText(p.amount, emphasis: true)
                    if let total = p.orderTotal { Text("of \(total.formatted()) order total").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                }
                Text(p.customerName).font(Theme.Font.secondary)
                let contact = [p.customerEmail, p.customerPhone, p.countryCode?.uppercased()].compactMap { $0 }.filter { !$0.isEmpty }
                if !contact.isEmpty { Text(contact.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                HStack(spacing: Theme.Space.m) {
                    if let issuer = p.proofIssuer {
                        SignedFileButton(label: "View proof", issuer: issuer, suggestedName: "proof-\(p.paymentNumber)")
                        if let at = p.proofUploadedAt { Text("Uploaded \(at.formatted(date: .abbreviated, time: .omitted))").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                    } else {
                        Label("No proof uploaded yet", systemImage: "exclamationmark.triangle").font(Theme.Font.caption).foregroundStyle(Theme.Palette.amber)
                    }
                    Spacer()
                    // Gated as the server gates the two actions; a reader sees the queue only.
                    if canDecide {
                        Button("Reject", role: .destructive) { deciding = (p, false) }
                        Button(p.hasProof ? "Approve" : "Approve anyway") { deciding = (p, true) }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                    }
                }
            }
        }
    }
}

// MARK: - Purchase returns (GET /purchase-returns?status=pending; POST /purchase-returns/{id}/approve {notes?} | reject {reason})

struct PurchaseReturnApprovalsPanel: View {
    @Environment(AppEnvironment.self) private var env
    @State private var version = 0
    @State private var deciding: (id: Int, number: String, approve: Bool)?

    var body: some View {
        let canDecide = env.session.can("procurement.approve")
        RemoteJSON("/v1/admin/purchase-returns", query: ["status": "pending", "per_page": "50"], version: version,
                   emptyTitle: "No purchase returns awaiting approval", isEmpty: { ($0["data"]?.array ?? []).isEmpty }) { json, _ in
            LazyVStack(spacing: Theme.Space.s) {
                ForEach((json["data"]?.array ?? []).compactMap { r -> (Int, JSONValue)? in r["id"]?.int.map { ($0, r) } }, id: \.0) { id, r in
                    row(id: id, r, canDecide: canDecide)
                }
            }
        }
        .sheet(isPresented: Binding(get: { deciding != nil }, set: { if !$0 { deciding = nil } })) {
            if let d = deciding {
                DecisionSheet(title: "\(d.approve ? "Approve" : "Reject") \(d.number)", approve: d.approve,
                              noteLabel: d.approve ? "Approval notes" : "Rejection reason", noteRequired: !d.approve, warning: nil) { note in
                    let body: [String: JSONValue] = d.approve ? (note.isEmpty ? [:] : ["notes": .string(note)]) : ["reason": .string(note)]
                    let action = d.approve ? "approve" : "reject"
                    let r = try await env.session.client.post("/v1/admin/purchase-returns/\(d.id)/\(action)", json: .object(body))
                    env.toasts.show(r.json?["message"]?.string ?? (d.approve ? "Return approved" : "Return rejected"))
                    deciding = nil
                    version += 1
                }
            }
        }
    }

    private func row(id: Int, _ r: JSONValue, canDecide: Bool) -> some View {
        let number = r["return_number"]?.string ?? "#\(id)"
        let by = [r["created_by_user"]?["first_name"]?.string, r["created_by_user"]?["last_name"]?.string].compactMap { $0 }.joined(separator: " ")
        return SectionCard {
            VStack(alignment: .leading, spacing: Theme.Space.s) {
                HStack(spacing: Theme.Space.s) {
                    Button(number) { env.navigator.go(.purchaseReturn(id: id)) }.font(Theme.Font.mono).buttonStyle(.plain).foregroundStyle(Theme.Palette.danger)
                    StatusBadge("pending")
                    Spacer()
                }
                HStack(spacing: Theme.Space.xs) {
                    Text(r["supplier"]?["name"]?.string ?? r["purchase_order"]?["po_number"]?.string ?? "-").font(Theme.Font.secondary).lineLimit(2)
                    if let po = r["purchase_order"]?["po_number"]?.string { Text("· \(po)").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                }
                HStack(spacing: Theme.Space.m) {
                    if !by.isEmpty { Text("By: \(by)") }
                    if let at = r["created_at"]?.date { DateText(at, style: .relative) }
                    if let n = r["items_count"]?.int { Text("\(n) item\(n == 1 ? "" : "s")") }
                }.font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                if let reason = r["reason"]?.string, !reason.isEmpty { Text(reason).font(Theme.Font.caption).italic().foregroundStyle(Theme.textSecondary).lineLimit(1) }
                if canDecide {
                    HStack {
                        Spacer()
                        Button("Reject", role: .destructive) { deciding = (id, number, false) }
                        Button("Approve") { deciding = (id, number, true) }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                    }
                }
            }
        }
    }
}

// MARK: - The checker's decision (the console's ActionModal)

struct DecisionSheet: View {
    @Environment(\.dismiss) private var dismiss
    let title: String
    let approve: Bool
    let noteLabel: String
    let noteRequired: Bool
    let warning: String?
    let send: (String) async throws -> Void
    @State private var note = ""

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.l) {
            Text(title).font(Theme.Font.sectionTitle)
            if let warning { Label(warning, systemImage: "exclamationmark.triangle").font(Theme.Font.caption).foregroundStyle(Theme.Palette.amber) }
            TextField(noteRequired ? "\(noteLabel) (required)" : "\(noteLabel) (optional)", text: $note, axis: .vertical)
                .textFieldStyle(.roundedBorder).lineLimit(3...6)
            HStack {
                Spacer()
                Button("Cancel") { dismiss() }
                AsyncButton(role: approve ? nil : .destructive, action: { try await send(note.trimmingCharacters(in: .whitespacesAndNewlines)) }) {
                    Text(approve ? "Approve" : "Reject")
                }
                .buttonStyle(.borderedProminent).tint(approve ? Theme.Palette.brand : Theme.Palette.danger)
                .disabled(noteRequired && note.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
            }
        }
        .padding(Theme.Space.xl).frame(minWidth: 380)
    }
}
