import SwiftUI
import BethanyKit

// MARK: - Order items: list, price adjustment, line editing, send to production

/// The lines of an order. Figures are the stored ones (unit_price,
/// discount_amount, tax_amount, total_price); after an edit the server's
/// response is the only source of what they became.
///
/// - Adjust price: PATCH /orders/{id}/items/{i}/price {unit_price}
///   (orders.edit; only while payment_status is pending — the server's rule).
/// - Edit lines: PUT /orders/{id}/items {items[], reason?, confirm_paid_change?,
///   confirm_shipped_change?} (orders.edit_items). A 422 whose `reason` is
///   confirm_paid_change / confirm_shipped_change / reason_required is a
///   question, answered by re-sending with the flag or the reason.
/// - Send to production: POST /orders/{id}/items/{i}/production
///   (production.raise_order; money received; line is_producible; not yet raised).
struct OrderItemsSection: View {
    @Environment(AppEnvironment.self) private var env
    let order: Record
    let reload: () -> Void
    var onProduction: (Record) -> Void = { _ in }

    @State private var adjusting: Record?
    @State private var editing = false

    private var items: [Record] { (order["items"]?.array ?? []).map(Record.init(json:)) }
    private var currency: String { order.string("currency_code") ?? "KES" }
    private var dead: Bool { Sales.isDead(order.status) }
    private var paymentReceived: Bool { ["paid", "partial", "deposit"].contains(order.string("payment_status") ?? "") }

    var body: some View {
        SectionCard("Items") {
            if editing {
                OrderLineEditor(order: order, onDone: { editing = false; reload() }, onCancel: { editing = false })
            } else {
                ForEach(items) { item in itemRow(item) }
                if order["prices_include_tax"]?.bool == true {
                    Text("Prices include tax.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                }
                if let breakdown = order["tax_breakdown"]?.array, !breakdown.isEmpty {
                    ForEach(Array(breakdown.enumerated()), id: \.offset) { _, b in
                        DetailRow("\(b["label"]?.string ?? "Tax") @ \(b["rate"]?.string ?? "")%") { MoneyText(b["amount"].flatMap { Money(json: $0, currency: currency) }) }
                    }
                }
                if env.session.can("orders.edit_items"), !dead {
                    Button { editing = true } label: { Label("Edit lines", systemImage: "pencil") }.font(Theme.Font.secondary)
                }
            }
        }
        .sheet(item: $adjusting) { item in
            AdjustPriceSheet(order: order, item: item, onDone: { adjusting = nil; reload() }, onCancel: { adjusting = nil })
        }
    }

    private func itemRow(_ item: Record) -> some View {
        let canAdjust = env.session.can("orders.edit") && order.string("payment_status") == "pending" && !dead
        let canRaise = env.session.can("production.raise_order") && paymentReceived && !dead
            && item["is_producible"]?.bool == true && (item["production_order_id"]?.isNull ?? true)
        return VStack(alignment: .leading, spacing: 4) {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 2) {
                    Text(item.string("product_name") ?? "Item").font(Theme.Font.body)
                    HStack(spacing: 6) {
                        if let v = item.string("variant_name"), !v.isEmpty { Text(v) }
                        if let sku = item.string("sku") { Text(sku).font(Theme.Font.mono) }
                    }
                    .font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    HStack(spacing: 6) {
                        Text("\(item.string("quantity") ?? "1") × \(item.money("unit_price", currency: currency)?.formatted() ?? "—")")
                        if item["price_adjusted"]?.bool == true, let orig = item.money("original_price", currency: currency) {
                            Text("was \(orig.formatted())").strikethrough().foregroundStyle(Theme.textTertiary)
                        }
                        if let d = item.money("discount_amount", currency: currency), !d.isZero { Text("− \(d.formatted()) discount").foregroundStyle(Theme.Palette.pink) }
                        if let rate = item["tax_rate"]?.string { Text("tax \(rate)%").foregroundStyle(Theme.textTertiary) }
                    }
                    .font(Theme.Font.caption)
                    if let poId = item.int("production_order_id") {
                        Button { env.navigator.push(.productionOrder(id: poId)) } label: { Label("In production", systemImage: "hammer") }.font(Theme.Font.caption)
                    }
                }
                Spacer()
                MoneyText(item.money("total_price", currency: currency) ?? item.money("subtotal", currency: currency))
            }
            HStack(spacing: Theme.Space.m) {
                if canAdjust { Button("Adjust price") { adjusting = item } }
                if canRaise { Button("MTO · Send to production") { onProduction(item) } }
            }
            .font(Theme.Font.caption)
            Divider()
        }
    }
}

/// PATCH /orders/{id}/items/{i}/price {unit_price} — upwards only, no payments yet.
struct AdjustPriceSheet: View {
    @Environment(AppEnvironment.self) private var env
    let order: Record
    let item: Record
    let onDone: () -> Void
    let onCancel: () -> Void
    @State private var price = ""
    var body: some View {
        SheetFrame(title: "Adjust unit price", submitLabel: "Update price", canSubmit: Sales.amountJSON(price) != nil, onCancel: onCancel, onSubmit: {
            guard let p = Sales.amountJSON(price) else { return }
            _ = try await env.session.client.patch("/v1/admin/orders/\(order.id)/items/\(item.id)/price", json: .object(["unit_price": p]))
            env.toasts.show("Price updated")
            onDone()
        }) {
            DetailRow("Line", "\(item.string("product_name") ?? "") × \(item.string("quantity") ?? "")")
            DetailRow("Current unit price") { MoneyText(item.money("unit_price", currency: order.string("currency_code"))) }
            Text("The catalogue price is kept as original_price for the audit trail. Only while no payment has been recorded.").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            SalesLabeledField(label: "New unit price (\(order.string("currency_code") ?? "KES"))") { TextField("0.00", text: $price).textFieldStyle(.roundedBorder) }
        }
        .onAppear { if price.isEmpty { price = item["unit_price"]?.string ?? "" } }
    }
}

// MARK: Line editor — PUT /orders/{id}/items

/// Staged, never optimistic: nothing is sent until Update, and no figure is
/// computed here. Existing lines keep their `id`; new lines carry
/// product_id + product_variant_id; a removed line is simply omitted.
struct OrderLineEditor: View {
    @Environment(AppEnvironment.self) private var env
    let order: Record
    let onDone: () -> Void
    let onCancel: () -> Void

    struct Line: Identifiable {
        let id = UUID()
        var recordId: String?
        var productId: String?
        var variantId: String?
        var name: String
        var sku: String?
        var quantity: String
        var unitPrice: String
        var discount: String
        var locked: Bool   // already in production: quantity cannot change
    }

    @State private var lines: [Line] = []
    @State private var reason = ""
    @State private var adding = false
    @State private var busy = false
    @State private var question: (reason: String, message: String)?
    @State private var confirmed: (paid: Bool, shipped: Bool) = (false, false)
    @State private var error: String?

    private var currency: String { order.string("currency_code") ?? "KES" }

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            Text("Send the lines you want to end up with; subtotal, tax, total, payment status and stock are recomputed by the server.").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            ForEach($lines) { $line in
                HStack(alignment: .top, spacing: Theme.Space.s) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(line.name).font(Theme.Font.body)
                        if let sku = line.sku { Text(sku).font(Theme.Font.mono).foregroundStyle(Theme.textTertiary) }
                        if line.locked { Text("In production — quantity locked").font(Theme.Font.caption).foregroundStyle(Theme.Palette.amber) }
                    }
                    Spacer()
                    SalesLabeledField(label: "Qty") { TextField("1", text: $line.quantity).textFieldStyle(.roundedBorder).frame(width: 60).disabled(line.locked) }
                    SalesLabeledField(label: "Unit price") { TextField("", text: $line.unitPrice).textFieldStyle(.roundedBorder).frame(width: 110) }
                    SalesLabeledField(label: "Discount") { TextField("0", text: $line.discount).textFieldStyle(.roundedBorder).frame(width: 90) }
                    Button(role: .destructive) { lines.removeAll { $0.id == line.id } } label: { Image(systemName: "trash") }.buttonStyle(.plain).foregroundStyle(Theme.Palette.danger).disabled(line.locked)
                }
                Divider()
            }
            if lines.isEmpty { Text("An order needs at least one line.").font(Theme.Font.caption).foregroundStyle(Theme.Palette.danger) }
            Button { adding = true } label: { Label("Add item", systemImage: "plus") }.font(Theme.Font.secondary)
            if let cap = env.session.me?.discountCapPercent {
                Text("Your discount limit is \(cap.formatted())% of a line; the server enforces it (and measures a lowered unit price as well).").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            }
            TextInput(label: "Reason (required once money or goods have moved)", text: $reason)
            if let q = question {
                VStack(alignment: .leading, spacing: 4) {
                    Text(q.message).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.amber)
                    HStack {
                        Button("Go back") { question = nil }
                        Button(q.reason == "reason_required" ? "Send with reason" : "Yes, apply the change") { Task { await submit(answering: q.reason) } }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                            .disabled(q.reason == "reason_required" && reason.trimmingCharacters(in: .whitespaces).isEmpty)
                    }
                }
                .padding(Theme.Space.m).background(Theme.Palette.amberSoft, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
            }
            if let error { Text(error).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
            HStack {
                Spacer()
                Button("Cancel", action: onCancel)
                Button("Update lines") { Task { await submit(answering: nil) } }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand).disabled(busy || lines.isEmpty)
            }
        }
        .onAppear { if lines.isEmpty { lines = (order["items"]?.array ?? []).map(Record.init(json:)).map(Line.init(record:)) } }
        .sheet(isPresented: $adding) {
            ProductPickerSheet(currency: currency, onPicked: { line in lines.append(line); adding = false }, onCancel: { adding = false })
        }
    }

    private func submit(answering: String?) async {
        if answering == "confirm_paid_change" { confirmed.paid = true }
        if answering == "confirm_shipped_change" { confirmed.shipped = true }
        question = nil
        error = nil
        busy = true
        defer { busy = false }
        var body: [String: JSONValue] = ["items": .array(lines.map { l in
            var o: [String: JSONValue] = ["quantity": .number(Double(Int(l.quantity.trimmingCharacters(in: .whitespaces)) ?? 1))]
            if let rid = l.recordId { o["id"] = .string(rid) } else {
                if let p = l.productId { o["product_id"] = .string(p) }
                o["product_variant_id"] = l.variantId.map { JSONValue.string($0) } ?? JSONValue.null
            }
            if let up = Sales.amountJSON(l.unitPrice) { o["unit_price"] = up }
            if let d = Sales.amountJSON(l.discount) { o["discount_amount"] = d }
            return .object(o)
        })]
        if !reason.trimmingCharacters(in: .whitespaces).isEmpty { body["reason"] = .string(reason.trimmingCharacters(in: .whitespaces)) }
        if confirmed.paid { body["confirm_paid_change"] = .bool(true) }
        if confirmed.shipped { body["confirm_shipped_change"] = .bool(true) }
        do {
            let r = try await env.session.client.put("/v1/admin/orders/\(order.id)/items", json: .object(body))
            let j = r.json
            var msg = j?["message"]?.string ?? "Lines updated"
            if let over = j?["overpaid"].flatMap({ Money(json: $0, currency: currency) }), !over.isZero { msg += " — overpaid by \(over.formatted())" }
            env.toasts.show(msg)
            onDone()
        } catch let e as APIError {
            if ["confirm_paid_change", "confirm_shipped_change", "reason_required"].contains(e.reason ?? "") {
                question = (reason: e.reason ?? "", message: e.message)
            } else { error = e.firstFieldError ?? e.message }
        } catch { self.error = error.localizedDescription }
    }
}

extension OrderLineEditor.Line {
    init(record r: Record) {
        self.init(recordId: r.id, productId: r["product_id"]?.string, variantId: r["product_variant_id"]?.string ?? r["variant_id"]?.string,
                  name: [r.string("product_name"), r.string("variant_name")].compactMap { $0 }.filter { !$0.isEmpty }.joined(separator: " · "),
                  sku: r.string("sku"), quantity: r["quantity"]?.string ?? "1", unitPrice: r["unit_price"]?.string ?? "",
                  discount: r["discount_amount"]?.string ?? "0", locked: r["production_order_id"]?.isNull == false)
    }
}

/// Admin catalogue search (GET /products?search=&per_page=20, products.view),
/// then GET /products/{id} for a variable product's variants. The unit price
/// is left to the server unless the person types one.
struct ProductPickerSheet: View {
    @Environment(AppEnvironment.self) private var env
    let currency: String
    let onPicked: (OrderLineEditor.Line) -> Void
    let onCancel: () -> Void
    @State private var search = ""
    @State private var results: [Record] = []
    @State private var picked: Record?
    @State private var variants: [Record] = []
    @State private var variantId = ""
    @State private var quantity = "1"
    @State private var price = ""

    private var needsVariant: Bool { (picked?.int("variants_count") ?? 0) > 0 }
    private var ready: Bool { picked != nil && (!needsVariant || !variantId.isEmpty) && (Int(quantity) ?? 0) > 0 }

    var body: some View {
        SheetFrame(title: "Add item", submitLabel: "Add line", canSubmit: ready, onCancel: onCancel, onSubmit: {
            guard let p = picked else { return }
            let v = variants.first { $0.id == variantId }
            onPicked(OrderLineEditor.Line(recordId: nil, productId: p.id, variantId: v?.id,
                                          name: [p["en_translation"]?["name"]?.string ?? p.string("sku") ?? "Product", v?.string("variant_name")].compactMap { $0 }.joined(separator: " · "),
                                          sku: v?.string("sku") ?? p.string("sku"), quantity: quantity, unitPrice: price, discount: "0", locked: false))
        }) {
            SalesSearchField(text: $search, placeholder: "Search the catalogue") { Task { await find() } }
            ForEach(results) { p in
                Button { Task { await pick(p) } } label: {
                    HStack {
                        VStack(alignment: .leading) {
                            Text(p["en_translation"]?["name"]?.string ?? p.string("sku") ?? "").font(Theme.Font.body)
                            Text("\(p.string("sku") ?? "")\((p.int("variants_count") ?? 0) > 0 ? " · \(p.int("variants_count") ?? 0) variants" : "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        }
                        Spacer()
                        if let bp = p["base_price"], let reg = (bp["sale_price"] ?? bp["regular_price"]).flatMap({ Money(json: $0, currency: "KES") }) {
                            Text(reg.formatted()).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                        }
                        if picked?.id == p.id { Image(systemName: "checkmark.circle.fill").foregroundStyle(Theme.Palette.brand) }
                    }
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
            }
            if needsVariant {
                Picker("Variant", selection: $variantId) {
                    Text("Choose a variant").tag("")
                    ForEach(variants) { v in Text("\(v.string("variant_name") ?? "") · \(v.string("sku") ?? "")").tag(v.id) }
                }
            }
            HStack {
                SalesLabeledField(label: "Quantity") { TextField("1", text: $quantity).textFieldStyle(.roundedBorder).frame(width: 80) }
                SalesLabeledField(label: "Unit price (\(currency), blank = catalogue)") { TextField("", text: $price).textFieldStyle(.roundedBorder) }
            }
        }
    }

    private func find() async {
        let term = search.trimmingCharacters(in: .whitespaces)
        guard term.count >= 2 else { results = []; return }
        do {
            let r = try await env.session.client.get("/v1/admin/products", query: ["search": term, "per_page": "20"])
            results = r.json?.salesRecords ?? []
        } catch { env.toasts.error(error) }
    }

    private func pick(_ p: Record) async {
        picked = p
        variantId = ""
        variants = []
        guard (p.int("variants_count") ?? 0) > 0 else { return }
        do {
            let r = try await env.session.client.get("/v1/admin/products/\(p.id)")
            variants = (r.json?["product"]?["variants"]?.array ?? []).map(Record.init(json:)).filter { $0["is_active"]?.bool != false }
        } catch { env.toasts.error(error) }
    }
}
