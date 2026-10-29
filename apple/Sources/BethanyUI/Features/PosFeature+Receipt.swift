import SwiftUI
import BethanyKit
#if os(macOS)
import AppKit
import PDFKit
#else
import UIKit
#endif

/// The receipt after a sale (ReceiptModal.tsx): the Hub's figures for the
/// order, printed as the server's PDF when the person may read it
/// (GET /v1/admin/pdf/orders/{id}/receipt, orders.view) or as a native
/// rendering of the same payload otherwise; emailed via pos/sales/{id}/email-receipt.
struct ReceiptSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let session: PosSession
    let sale: PosSale
    @State private var email = ""
    @State private var busy = false

    private var needsApproval: Bool {
        sale.payments.contains { $0["requires_approval"]?.bool == true && ($0["approval_status"]?.string ?? "pending") != "approved" && $0["status"]?.string != "paid" }
    }

    var body: some View {
        PosSheetFrame(needsApproval ? "Invoice · awaiting approval" : "Receipt", subtitle: "\(sale.orderNumber) · \(session.outlet?.name ?? "")") {
            if needsApproval {
                Label("A payment on this sale is held for admin approval. Hand over an invoice; the receipt follows once it is approved.", systemImage: "clock.badge.exclamationmark")
                    .font(Theme.Font.secondary).foregroundStyle(Theme.Palette.amber)
            }
            ReceiptBody(sale: sale, outlet: session.outlet)
            HStack {
                TextField("Email the receipt to…", text: $email).textFieldStyle(.roundedBorder)
                    #if os(iOS)
                    .keyboardType(.emailAddress).textInputAutocapitalization(.never)
                    #endif
                Button("Send") { Task { await sendEmail() } }.disabled(!email.contains("@") || busy)
            }
        } actions: {
            Button { Task { await printReceipt() } } label: { Label("Print", systemImage: "printer") }.disabled(busy)
            Button("New sale") { dismiss() }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
        }
        .onAppear { if let e = sale.json["customer_email"]?.string, !e.contains("•") { email = e } }
    }

    private func sendEmail() async {
        busy = true; defer { busy = false }
        do { let r = try await session.api.emailReceipt(sale.id, email: email); env.toasts.show(r["message"]?.string ?? "Receipt sent") } catch { env.toasts.error(error) }
    }

    private func printReceipt() async {
        busy = true; defer { busy = false }
        if env.session.can("orders.view"), let r = try? await env.session.client.download("/v1/admin/pdf/orders/\(sale.id)/receipt"), r.contentType?.contains("pdf") == true {
            PrintService.printPDF(r.0, jobName: "Receipt \(sale.orderNumber)")
        } else {
            PrintService.printHTML(ReceiptHTML.render(sale: sale, outlet: session.outlet), jobName: "Receipt \(sale.orderNumber)")
        }
    }
}

/// The receipt lines as the server returned them.
struct ReceiptBody: View {
    let sale: PosSale
    let outlet: PosOutlet?

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            Group {
                Text(outlet?.name ?? sale.json["outlet_name"]?.string ?? "Bethany House").font(Theme.Font.sectionTitle)
                Text("\(sale.orderNumber) · \(sale.json["cashier_name"]?.string ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                DateText(sale.createdAt)
                if let c = sale.customerName { Text("Customer: \(c)").font(Theme.Font.secondary) }
            }
            Divider()
            ForEach(Array(sale.items.enumerated()), id: \.offset) { _, i in
                let variant = i["variant_name"]?.string ?? ""
                let title = variant.isEmpty ? (i["product_name"]?.string ?? "Item") : "\(i["product_name"]?.string ?? "Item") · \(variant)"
                let unit = Money(json: i["unit_price"] ?? .null, currency: sale.currency)?.formatted() ?? "—"
                HStack(alignment: .top) {
                    VStack(alignment: .leading, spacing: 1) {
                        Text(title).font(Theme.Font.secondary)
                        HStack(spacing: 4) {
                            Text("\(i["quantity"]?.int ?? 0) × \(unit)").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                            if let d = Money(json: i["discount_amount"] ?? .null, currency: sale.currency), !d.isZero { Text("less \(d.formatted())").font(Theme.Font.caption).foregroundStyle(Theme.Palette.pink) }
                            if i["is_production"]?.bool == true { StatusBadge("made to order", tone: .accent) }
                        }
                    }
                    Spacer()
                    MoneyText(Money(json: i["subtotal"] ?? .null, currency: sale.currency))
                }
            }
            Divider()
            line("Subtotal", sale.money("subtotal"))
            if let d = sale.money("discount_amount"), !d.isZero { line("Discount", d) }
            line(sale.json["prices_include_tax"]?.bool == true ? "Tax (included)" : "Tax", sale.money("tax_amount"))
            HStack { Text("Total").font(Theme.Font.sectionTitle); Spacer(); MoneyText(sale.total, emphasis: true) }
            if let paid = sale.money("amount_paid"), let out = sale.money("outstanding"), !out.isZero {
                line("Paid", paid); line("Outstanding", out)
            }
            if !sale.payments.isEmpty {
                Divider()
                ForEach(Array(sale.payments.enumerated()), id: \.offset) { _, p in
                    HStack {
                        Text((p["payment_method"]?.string ?? "payment").humanized).font(Theme.Font.secondary)
                        if let r = p["reference"]?.string, !r.isEmpty { Text(r).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                        if p["requires_approval"]?.bool == true { StatusBadge(p["approval_status"]?.string ?? "pending") } else { StatusBadge(p["status"]?.string) }
                        Spacer()
                        MoneyText(Money(json: p["amount"] ?? .null, currency: p["currency_code"]?.string ?? sale.currency))
                    }
                    if let cr = p["cash_received"], !cr.isNull { line("Cash received", Money(json: cr, currency: sale.currency)) }
                    if let cg = p["change_given"], !cg.isNull { line("Change", Money(json: cg, currency: sale.currency)) }
                }
            }
            if !sale.pendingReversals.isEmpty {
                Text("Void / refund requests are pending on this sale.").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning)
            }
        }
        .padding(Theme.Space.l).background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
    }

    private func line(_ label: String, _ m: Money?) -> some View {
        HStack { Text(label).font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary); Spacer(); MoneyText(m) }
    }
}

/// Plain HTML of the server's sale payload for printing when the PDF is not
/// available to this person. Figures are copied, never recomputed.
enum ReceiptHTML {
    static func render(sale: PosSale, outlet: PosOutlet?) -> String {
        func esc(_ s: String) -> String { s.replacingOccurrences(of: "&", with: "&amp;").replacingOccurrences(of: "<", with: "&lt;") }
        func m(_ v: JSONValue?, _ cur: String? = nil) -> String { v.flatMap { Money(json: $0, currency: cur ?? sale.currency) }?.formatted() ?? "—" }
        var h = "<html><body style='font-family:-apple-system,Helvetica;font-size:12px;width:280px'>"
        h += "<h3 style='margin:0'>\(esc(outlet?.name ?? "Bethany House"))</h3>"
        if let a = outlet?.raw["address"]?.string { h += "<div>\(esc(a))</div>" }
        h += "<div>\(esc(sale.orderNumber)) · \(esc(sale.json["created_at"]?.string ?? ""))</div>"
        if let c = sale.json["cashier_name"]?.string { h += "<div>Cashier: \(esc(c))</div>" }
        if let c = sale.customerName { h += "<div>Customer: \(esc(c))</div>" }
        h += "<hr><table width='100%'>"
        for i in sale.items {
            h += "<tr><td>\(esc(i["product_name"]?.string ?? ""))<br><small>\(i["quantity"]?.int ?? 0) × \(m(i["unit_price"]))</small></td><td align='right'>\(m(i["subtotal"]))</td></tr>"
        }
        h += "</table><hr><table width='100%'>"
        h += "<tr><td>Subtotal</td><td align='right'>\(m(sale.json["subtotal"]))</td></tr>"
        if let d = sale.money("discount_amount"), !d.isZero { h += "<tr><td>Discount</td><td align='right'>\(d.formatted())</td></tr>" }
        h += "<tr><td>Tax</td><td align='right'>\(m(sale.json["tax_amount"]))</td></tr>"
        h += "<tr><td><b>Total</b></td><td align='right'><b>\(m(sale.json["total"]))</b></td></tr>"
        for p in sale.payments {
            h += "<tr><td>\(esc((p["payment_method"]?.string ?? "").humanized)) \(esc(p["reference"]?.string ?? ""))</td><td align='right'>\(m(p["amount"], p["currency_code"]?.string))</td></tr>"
            if let cr = p["cash_received"], !cr.isNull { h += "<tr><td>Cash received</td><td align='right'>\(m(cr))</td></tr>" }
            if let cg = p["change_given"], !cg.isNull { h += "<tr><td>Change</td><td align='right'>\(m(cg))</td></tr>" }
        }
        if let out = sale.money("outstanding"), !out.isZero { h += "<tr><td>Outstanding</td><td align='right'>\(out.formatted())</td></tr>" }
        h += "</table><hr><div style='text-align:center'>Thank you</div></body></html>"
        return h
    }
}

/// Native printing: a PDF through the system print panel, or HTML markup
/// through a text print formatter. No web view. Called from UI actions, so on the main thread.
enum PrintService {
    static func printPDF(_ data: Data, jobName: String) {
        #if os(macOS)
        guard let doc = PDFDocument(data: data) else { return }
        let info = NSPrintInfo.shared
        info.jobDisposition = .spool
        if let op = doc.printOperation(for: info, scalingMode: .pageScaleToFit, autoRotate: true) {
            op.jobTitle = jobName
            op.showsPrintPanel = true
            op.run()
        }
        #else
        let controller = UIPrintInteractionController.shared
        let info = UIPrintInfo.printInfo()
        info.jobName = jobName
        info.outputType = .general
        controller.printInfo = info
        controller.printingItem = data
        _ = controller.present(animated: true, completionHandler: nil)
        #endif
    }

    static func printHTML(_ html: String, jobName: String) {
        #if os(macOS)
        guard let data = html.data(using: .utf8),
              let text = try? NSAttributedString(data: data, options: [.documentType: NSAttributedString.DocumentType.html, .characterEncoding: String.Encoding.utf8.rawValue], documentAttributes: nil) else { return }
        let view = NSTextView(frame: NSRect(x: 0, y: 0, width: 300, height: 800))
        view.textStorage?.setAttributedString(text)
        view.sizeToFit()
        let info = NSPrintInfo.shared
        let op = NSPrintOperation(view: view, printInfo: info)
        op.jobTitle = jobName
        op.showsPrintPanel = true
        op.run()
        #else
        let controller = UIPrintInteractionController.shared
        let info = UIPrintInfo.printInfo()
        info.jobName = jobName
        info.outputType = .general
        controller.printInfo = info
        controller.printFormatter = UIMarkupTextPrintFormatter(markupText: html)
        _ = controller.present(animated: true, completionHandler: nil)
        #endif
    }
}

// MARK: - Customer (pos/customers/search, quick create, reveal)

struct CustomerSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let session: PosSession
    @State private var query = ""
    @State private var results: [JSONValue] = []
    @State private var searching = false
    @State private var searchTask: Task<Void, Never>?
    @State private var draft = PosCustomer(id: -1)
    @State private var mode: Mode = .search
    @State private var revealReason = "payment_follow_up"
    @State private var revealed: [String: String] = [:]

    enum Mode: String, CaseIterable { case search = "Find", create = "New customer", walkIn = "Walk-in name" }
    static let reasons: [(String, String)] = [("payment_follow_up", "Payment follow-up"), ("delivery", "Delivery"), ("customer_callback", "Calling the customer back"), ("order_issue", "A problem with the order")]

    var body: some View {
        PosSheetFrame("Customer", subtitle: "Contacts are masked at the till; a reveal is audited.") {
            if let c = session.customer {
                HStack {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(c.displayName).font(Theme.Font.body)
                        Text([revealed["phone"] ?? c.phone, revealed["email"] ?? c.email].filter { !$0.isEmpty }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    }
                    Spacer()
                    if c.id > 0 {
                        Menu("Reveal…") {
                            Picker("Reason", selection: $revealReason) { ForEach(Self.reasons, id: \.0) { Text($0.1).tag($0.0) } }
                            Button("Phone") { Task { await reveal("phone") } }
                            Button("Email") { Task { await reveal("email") } }
                        }
                    }
                    Button("Remove") { session.customer = nil; revealed = [:]; session.cartChanged() }
                }
                .padding(Theme.Space.m).background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
            }
            Picker("", selection: $mode) { ForEach(Mode.allCases, id: \.self) { Text($0.rawValue) } }.pickerStyle(.segmented)
            switch mode {
            case .search:
                TextField("Name or phone (3+ characters)", text: $query).textFieldStyle(.roundedBorder)
                if searching { ProgressView().controlSize(.small) }
                ForEach(Array(results.enumerated()), id: \.offset) { _, r in
                    Button {
                        let parts = (r["name"]?.string ?? "").split(separator: " ", maxSplits: 1).map(String.init)
                        session.customer = PosCustomer(id: r["id"]?.int ?? 0, firstName: parts.first ?? "", lastName: parts.count > 1 ? parts[1] : "", phone: r["phone"]?.string ?? "", email: r["email"]?.string ?? "")
                        revealed = [:]; session.cartChanged(); dismiss()
                    } label: {
                        HStack { Text(r["name"]?.string ?? "Customer"); Spacer(); Text(r["phone"]?.string ?? "").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                            .padding(Theme.Space.s).contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                }
            case .create:
                TextField("First name *", text: $draft.firstName).textFieldStyle(.roundedBorder)
                TextField("Last name", text: $draft.lastName).textFieldStyle(.roundedBorder)
                TextField("Phone *", text: $draft.phone).textFieldStyle(.roundedBorder)
                TextField("Email", text: $draft.email).textFieldStyle(.roundedBorder)
                TextField("Organisation (a parish, a school, a bank)", text: $draft.company).textFieldStyle(.roundedBorder)
                Button("Attach new customer") { draft.id = -1; session.customer = draft; session.cartChanged(); dismiss() }
                    .buttonStyle(.borderedProminent).tint(Theme.Palette.brand).disabled(draft.firstName.isEmpty || draft.phone.isEmpty)
            case .walkIn:
                TextField("First name", text: $draft.firstName).textFieldStyle(.roundedBorder)
                TextField("Last name", text: $draft.lastName).textFieldStyle(.roundedBorder)
                TextField("Phone (optional)", text: $draft.phone).textFieldStyle(.roundedBorder)
                Button("Use for this sale") { draft.id = 0; session.customer = draft; session.cartChanged(); dismiss() }.buttonStyle(.bordered).disabled(draft.firstName.isEmpty)
            }
            LabeledContent("Customer country (2 letters, for currency)") {
                TextField("KE", text: Binding(get: { session.customerCountryCode }, set: { session.customerCountryCode = String($0.prefix(2)).uppercased(); session.cartChanged() })).textFieldStyle(.roundedBorder).frame(width: 64)
            }
        } actions: { Button("Done") { dismiss() } }
        .onChange(of: query) { _, q in
            searchTask?.cancel()
            guard q.count >= 3 else { results = []; return }
            searchTask = Task {
                try? await Task.sleep(for: .milliseconds(350))
                guard !Task.isCancelled else { return }
                searching = true
                results = (try? await session.api.searchCustomers(q)) ?? []
                searching = false
            }
        }
    }

    private func reveal(_ field: String) async {
        guard let c = session.customer, c.id > 0 else { return }
        do {
            let r = try await session.api.reveal(customerId: c.id, field: field, reason: revealReason, orderId: session.pendingOrderId)
            if let v = r["value"]?.string { revealed[field] = v; env.toasts.show("\(field.capitalizedFirst) revealed — this is audited", tone: .info) }
        } catch { env.toasts.error(error) }
    }
}
