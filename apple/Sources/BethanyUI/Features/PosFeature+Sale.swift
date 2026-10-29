import SwiftUI
import BethanyKit

/// The sale screen (PosPage.tsx): catalogue on the left, cart on the right;
/// on the phone the catalogue with a cart bar and a sheet for checkout.
struct PosSaleView: View {
    @Environment(AppEnvironment.self) private var env
    let session: PosSession
    #if os(iOS)
    @Environment(\.horizontalSizeClass) private var hSize
    private var compact: Bool { hSize == .compact }
    #else
    private var compact: Bool { false }
    #endif

    @State private var products: Resource<[PosProduct]>?
    @State private var search = ""
    @State private var searchResults: [PosProduct]?
    @State private var searchTask: Task<Void, Never>?
    @State private var category: Int?
    @State private var variantPicker: PosProduct?
    @State private var showCart = false
    @State private var sheet: PosSheet?
    @State private var pricing = false

    enum PosSheet: Identifiable, Hashable {
        case payment, history, returns, eod, daySummary, close, customer
        case receipt(PosSale)
        var id: String { switch self { case .receipt(let s): return "receipt-\(s.id)"; default: return String(describing: self) } }
    }

    var body: some View {
        Group {
            if compact {
                catalogue
                    .safeAreaInset(edge: .bottom) { cartBar }
            } else {
                HStack(spacing: 0) {
                    catalogue
                    Divider()
                    CartPane(session: session, onCharge: { Task { await charge() } }, onSave: { Task { await saveOrder() } }, onCustomer: { sheet = .customer }, pricing: pricing)
                        .frame(width: 400)
                }
            }
        }
        .toolbar { toolbar }
        .task { await load() }
        .onChange(of: category) { _, _ in makeProducts() }
        .onChange(of: search) { _, q in
            searchTask?.cancel()
            guard q.count >= 2 else { searchResults = nil; return }
            searchTask = Task {
                try? await Task.sleep(for: .milliseconds(300))
                guard !Task.isCancelled, let outlet = session.outlet else { return }
                searchResults = try? await session.api.searchProducts(q, outletId: outlet.id, currency: session.currency)
            }
        }
        .sheet(item: $variantPicker) { p in VariantSheet(product: p) { v, mto in session.add(product: p, variant: v, production: mto); variantPicker = nil } }
        .sheet(isPresented: $showCart) {
            NavigationStack {
                CartPane(session: session, onCharge: { Task { await charge() } }, onSave: { Task { await saveOrder() } }, onCustomer: { sheet = .customer }, pricing: pricing)
                    .navigationTitle("Cart")
                    .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Done") { showCart = false } } }
            }
        }
        .sheet(item: $sheet) { s in sheetView(s) }
    }

    // MARK: Catalogue

    private var catalogue: some View {
        VStack(spacing: 0) {
            HStack(spacing: Theme.Space.s) {
                HStack {
                    Image(systemName: "magnifyingglass").foregroundStyle(Theme.textTertiary)
                    TextField("Search products or SKU", text: $search).textFieldStyle(.plain)
                    if !search.isEmpty { Button { search = "" } label: { Image(systemName: "xmark.circle.fill").foregroundStyle(Theme.textTertiary) }.buttonStyle(.plain) }
                }
                .padding(.horizontal, 12).frame(height: 38)
                .background(Theme.field, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
                .overlay(RoundedRectangle(cornerRadius: Theme.Radius.field).stroke(Theme.line))
            }
            .padding(Theme.Space.l)
            if let all = products?.value, !all.isEmpty, search.isEmpty {
                categoryChips(all)
            }
            if session.ownerWatchesOnly { OwnerWatchBanner().padding(.horizontal, Theme.Space.l) }
            if session.totalsStale, session.pendingOrderId != nil {
                Label("The cart changed since the Hub priced it — review again before charging.", systemImage: "arrow.triangle.2.circlepath")
                    .font(Theme.Font.caption).foregroundStyle(Theme.Palette.amber).padding(.horizontal, Theme.Space.l).padding(.top, 4)
            }
            ScrollView {
                if let products {
                    if let results = searchResults, !search.isEmpty {
                        if results.isEmpty { EmptyState("Nothing matches “\(search)”", symbol: "magnifyingglass") } else { grid(results) }
                    } else {
                        LoadableView(products.state, retry: { products.load() }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No products at this outlet", message: "Products with stock or a price at \(session.outlet?.name ?? "this outlet") show here.", symbol: "shippingbox") }) { grid($0) }
                    }
                }
            }
            .refreshable { await products?.reload() }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }

    private func categoryChips(_ all: [PosProduct]) -> some View {
        let cats = Dictionary(grouping: all.compactMap { p in p.categoryId.map { ($0, p.categoryName ?? "") } }, by: { $0.0 }).compactMap { k, v in v.first.map { (k, $0.1) } }.sorted { $0.1 < $1.1 }
        return ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: Theme.Space.s) {
                chip("All", selected: category == nil) { category = nil }
                ForEach(cats, id: \.0) { c in chip(c.1, selected: category == c.0) { category = c.0 } }
            }
            .padding(.horizontal, Theme.Space.l).padding(.bottom, Theme.Space.s)
        }
    }

    private func chip(_ label: String, selected: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(label).font(Theme.Font.caption).padding(.horizontal, 12).padding(.vertical, 6)
                .background(selected ? Theme.Palette.brand : Theme.card, in: Capsule())
                .foregroundStyle(selected ? .white : Theme.text)
                .overlay(Capsule().stroke(selected ? Color.clear : Theme.line))
        }
        .buttonStyle(.plain)
    }

    private func grid(_ list: [PosProduct]) -> some View {
        LazyVGrid(columns: [GridItem(.adaptive(minimum: compact ? 150 : 170), spacing: Theme.Space.m)], spacing: Theme.Space.m) {
            ForEach(list) { p in
                ProductTile(product: p, disabled: session.ownerWatchesOnly) {
                    if p.variants.count > 1 || p.isProducible { variantPicker = p }
                    else if let v = p.defaultVariant, v.sellable { session.add(product: p, variant: v, production: false) }
                }
            }
        }
        .padding(Theme.Space.l)
    }

    // MARK: Phone cart bar

    private var cartBar: some View {
        Button { showCart = true } label: {
            HStack {
                Image(systemName: "cart.fill")
                Text(session.cart.isEmpty ? "Cart is empty" : "\(session.cart.reduce(0) { $0 + $1.quantity }) item\(session.cart.count == 1 ? "" : "s")")
                Spacer()
                if let total = session.pendingOrder?["total_amount"].flatMap({ Money(json: $0, currency: session.currency) }), !session.totalsStale {
                    Text(total.formatted()).monospacedDigit()
                } else {
                    Text("Review to price").font(Theme.Font.caption).opacity(0.85)
                }
                Image(systemName: "chevron.up")
            }
            .font(.system(size: 15, weight: .semibold)).foregroundStyle(.white)
            .padding(.horizontal, 18).frame(minHeight: 52)
            .background(Theme.Palette.nav, in: RoundedRectangle(cornerRadius: Theme.Radius.control))
            .padding(.horizontal, Theme.Space.gutter).padding(.bottom, Theme.Space.s)
        }
        .buttonStyle(.plain)
    }

    // MARK: Toolbar

    @ToolbarContentBuilder
    private var toolbar: some ToolbarContent {
        ToolbarItemGroup(placement: .primaryAction) {
            Menu {
                Text("\(session.outlet?.name ?? "") · Till #\(session.registerId.map(String.init) ?? "—")")
                Divider()
                Button { sheet = .history } label: { Label("Sales history", systemImage: "clock.arrow.circlepath") }
                if env.session.can("pos.returns") { Button { sheet = .returns } label: { Label("Returns & refunds", systemImage: "arrow.uturn.backward") } }
                Button { sheet = .eod } label: { Label("End of Day report", systemImage: "moon.stars") }
                Button { sheet = .daySummary } label: { Label("Day summary", systemImage: "chart.bar") }
                Divider()
                if env.session.can("pos.close_register") {
                    Button { sheet = .close } label: { Label("Count & close till", systemImage: "lock") }.disabled(session.ownerWatchesOnly)
                }
            } label: { Label("Till", systemImage: "tray.2") }
        }
    }

    @ViewBuilder
    private func sheetView(_ s: PosSheet) -> some View {
        switch s {
        case .payment:
            PaymentSheet(session: session) { sale in sheet = .receipt(sale) }
        case .history:
            SalesHistorySheet(session: session) { sale in session.restore(sale: sale); sheet = nil; if compact { showCart = true } }
        case .returns:
            ReturnsSheet(session: session)
        case .eod:
            if let outlet = session.outlet, let rid = session.registerId {
                UserEodSheet(session: session, outletId: outlet.id, outletName: outlet.name, registerId: rid, fixedDate: nil) { Task { await session.refreshRegister() } }
            }
        case .daySummary:
            if let outlet = session.outlet { DailySummarySheet(session: session, outlet: outlet) }
        case .close:
            if let outlet = session.outlet {
                RegisterCloseSheet(session: session, outletId: outlet.id, register: session.register, eodSubmitted: session.eodSubmitted,
                                   onCounted: { session.clearCart(); Task { await session.refreshRegister() } }, onRequestEod: { sheet = .eod })
            }
        case .customer:
            CustomerSheet(session: session)
        case .receipt(let sale):
            ReceiptSheet(session: session, sale: sale)
        }
    }

    // MARK: Actions

    private func load() async {
        guard let outlet = session.outlet else { return }
        if products == nil { makeProducts() }
        session.checkoutConfig = session.checkoutConfig ?? (try? await session.api.checkoutConfig())
        session.shippingMethods = (try? await session.api.shippingMethods(currency: session.currency)) ?? []
        session.paymentMethods = (try? await session.api.paymentMethods(currency: session.currency)) ?? []
        // An unpaid pending order from this session is resumed, never duplicated (PosPage.tsx auto-resume).
        if session.cart.isEmpty, session.pendingOrderId == nil, let open = try? await session.api.openPendingOrder(outletId: outlet.id), let order = open?["order"] {
            session.restore(sale: PosSale(json: order))
            env.toasts.show("Resumed unpaid order \(open?["order_number"]?.string ?? "")", tone: .info)
        }
    }

    private func makeProducts() {
        guard let outlet = session.outlet else { return }
        let api = session.api
        let cat = category
        products = Resource { try await api.products(outletId: outlet.id, categoryId: cat, currency: nil) }
        products?.load()
    }

    /// Prices the cart with the Hub (create/update the pending order), then opens the payment sheet on the server's totals.
    private func charge() async {
        guard !session.cart.isEmpty, !session.ownerWatchesOnly else { return }
        pricing = true; defer { pricing = false }
        do {
            _ = try await session.priceWithHub()
            sheet = .payment
        } catch { env.toasts.error(error); await handleTillRefusal(error) }
    }

    /// "Save order": the pending order is kept for later (history drawer), the cart is cleared.
    private func saveOrder() async {
        guard !session.cart.isEmpty, !session.ownerWatchesOnly else { return }
        pricing = true; defer { pricing = false }
        do {
            let r = try await session.priceWithHub()
            env.toasts.show("Order \(r["order_number"]?.string ?? r["order"]?["order_number"]?.string ?? "") saved — find it in Sales history.")
            session.clearCart()
            showCart = false
        } catch { env.toasts.error(error); await handleTillRefusal(error) }
    }

    private func handleTillRefusal(_ error: Error) async {
        if let e = error as? APIError, e.reason == "TILL_FROM_EARLIER_DAY" || e.reason == "TILL_NOT_OPEN" || e.reason == "OWNER_DOES_NOT_TRANSACT" {
            await session.refreshRegister()
        }
    }
}

// MARK: - Product tile and variant picker

struct ProductTile: View {
    let product: PosProduct
    let disabled: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            VStack(alignment: .leading, spacing: 6) {
                ZStack {
                    RoundedRectangle(cornerRadius: Theme.Radius.field).fill(Theme.field)
                    if let u = product.imageURL, let url = URL(string: u) {
                        AsyncImage(url: url) { img in img.resizable().scaledToFill() } placeholder: { Image(systemName: "photo").foregroundStyle(Theme.textTertiary) }
                            .clipShape(RoundedRectangle(cornerRadius: Theme.Radius.field))
                    } else {
                        Image(systemName: "shippingbox").font(.system(size: 26)).foregroundStyle(Theme.textTertiary)
                    }
                }
                .frame(height: 96)
                Text(product.name).font(Theme.Font.secondary).fontWeight(.semibold).lineLimit(2).multilineTextAlignment(.leading)
                HStack {
                    if let v = product.defaultVariant {
                        if v.sellable { MoneyText(v.money) } else { Text("No price in \(v.currency)").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning) }
                    }
                    Spacer()
                    if product.variants.count > 1 { Text("\(product.variants.count) options").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                    else if let v = product.defaultVariant { Text(v.stock > 0 ? "\(v.stock) in stock" : (product.isProducible ? "MTO" : "Out")).font(Theme.Font.caption).foregroundStyle(v.stock > 0 ? Theme.textTertiary : Theme.Palette.warning) }
                }
            }
            .padding(Theme.Space.m).frame(maxWidth: .infinity, alignment: .leading)
            .background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.control))
            .overlay(RoundedRectangle(cornerRadius: Theme.Radius.control).stroke(Theme.line))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(disabled || (product.defaultVariant?.sellable != true && product.variants.count <= 1))
        .accessibilityLabel(product.name)
    }
}

struct VariantSheet: View {
    @Environment(\.dismiss) private var dismiss
    let product: PosProduct
    let pick: (PosVariant, Bool) -> Void
    @State private var madeToOrder = false

    var body: some View {
        PosSheetFrame(product.name, subtitle: product.sku) {
            if product.isProducible {
                Toggle("Made to order (raises a production order at checkout)", isOn: $madeToOrder).toggleStyle(.switch).font(Theme.Font.secondary)
            }
            ForEach(product.variants) { v in
                Button { pick(v, madeToOrder) } label: {
                    HStack {
                        VStack(alignment: .leading, spacing: 2) {
                            Text(v.name.isEmpty ? "Default" : v.name).font(Theme.Font.body)
                            Text("\(v.sku) · \(v.stock) in stock").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        }
                        Spacer()
                        if v.sellable { MoneyText(v.money) } else { Text("No price in \(v.currency)").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning) }
                    }
                    .padding(Theme.Space.m).background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.field)).contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .disabled(!v.sellable || (v.stock <= 0 && !madeToOrder))
            }
        } actions: { Button("Cancel") { dismiss() } }
    }
}

// MARK: - Cart pane

struct CartPane: View {
    @Environment(AppEnvironment.self) private var env
    let session: PosSession
    let onCharge: () -> Void
    let onSave: () -> Void
    let onCustomer: () -> Void
    let pricing: Bool
    @State private var editing: CartLine?

    var body: some View {
        VStack(spacing: 0) {
            Button(action: onCustomer) {
                HStack {
                    Image(systemName: "person.crop.circle").foregroundStyle(Theme.Palette.brand)
                    VStack(alignment: .leading, spacing: 1) {
                        Text(session.customer?.displayName ?? "Walk-in customer").font(Theme.Font.body)
                        if let c = session.customer, !c.phone.isEmpty { Text(c.phone).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                    }
                    Spacer()
                    Image(systemName: "chevron.right").foregroundStyle(Theme.textTertiary)
                }
                .padding(Theme.Space.m).contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            Divider()
            List {
                ForEach(session.cart) { line in
                    CartRow(line: line, onInc: { var l = line; l.quantity += 1; session.update(l) }, onDec: { var l = line; if l.quantity > 1 { l.quantity -= 1; session.update(l) } else { session.remove(line) } }, onEdit: { editing = line })
                        .listRowBackground(Theme.card)
                        .swipeActions { Button("Remove", role: .destructive) { session.remove(line) } }
                }
                if session.cart.isEmpty {
                    Text("Tap a product to add it.").font(Theme.Font.secondary).foregroundStyle(Theme.textTertiary).listRowBackground(Color.clear)
                }
                Section {
                    HStack {
                        Picker("Cart discount", selection: Binding(get: { session.cartDiscountType }, set: { session.cartDiscountType = $0; session.cartChanged() })) {
                            Text("None").tag("none"); Text("%").tag("percent"); Text("Flat").tag("flat")
                        }
                        .pickerStyle(.segmented).frame(maxWidth: 200)
                        if session.cartDiscountType != "none" {
                            AmountField("0", text: Binding(get: { session.cartDiscountValue }, set: { session.cartDiscountValue = clampPercent($0, type: session.cartDiscountType); session.cartChanged() }))
                        }
                    }
                    if let cap = session.discountCap, session.cartDiscountType != "none" { Text("Up to \(formatCap(cap))% — the Hub refuses more.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                    if !session.shippingMethods.isEmpty {
                        Picker("Shipping", selection: Binding(get: { session.shippingMethodId ?? 0 }, set: { session.shippingMethodId = $0 == 0 ? nil : $0; session.cartChanged() })) {
                            Text("Collected in store").tag(0)
                            ForEach(Array(session.shippingMethods.enumerated()), id: \.offset) { _, m in
                                let fee = m["fee"].flatMap { Money(json: $0, currency: m["fee_currency"]?.string ?? session.currency) }
                                Text("\(m["name"]?.string ?? "Method")\(m["cost_type"]?.string == "free" ? " · free" : fee.map { " · \($0.formatted())" } ?? " · no rate")").tag(m["id"]?.int ?? 0)
                            }
                        }
                    }
                    TextField("Order note", text: Binding(get: { session.note }, set: { session.note = $0; session.cartChanged() }), axis: .vertical).lineLimit(1...3)
                } header: { Text("Discount, shipping & note").font(Theme.Font.caption) }
                .listRowBackground(Theme.card)
            }
            .scrollContentBackground(.hidden)
            .background(Theme.canvas)
            Divider()
            totals
        }
        .background(Theme.canvas)
        .sheet(item: $editing) { line in LineEditorSheet(line: line, cap: session.discountCap) { session.update($0) } }
    }

    /// The Hub's figures for this cart (pending order reply). Nothing is added here.
    private var totals: some View {
        VStack(spacing: Theme.Space.s) {
            if let order = session.pendingOrder?["order"], !session.totalsStale {
                let cur = session.currency
                row("Subtotal", order["subtotal"], cur)
                if let d = order["discount_amount"]?.money, !d.isZero { row("Discount", order["discount_amount"], cur) }
                row(order["prices_include_tax"]?.bool == true ? "Tax (included)" : "Tax", order["tax_amount"], cur)
                HStack { Text("Total").font(Theme.Font.sectionTitle); Spacer(); MoneyText(Money(json: order["total"] ?? .null, currency: cur), emphasis: true) }
                if let paid = order["amount_paid"]?.money, !paid.isZero {
                    row("Paid so far", order["amount_paid"], cur)
                    row("Outstanding", order["outstanding"], cur)
                }
                Text("Priced by the Hub · \(session.pendingOrder?["order_number"]?.string ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            } else if !session.cart.isEmpty {
                Text(session.totalsStale ? "Cart changed — review to price it again." : "Totals are computed by the Hub when you review the cart.").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            }
            if session.ownerWatchesOnly {
                OwnerWatchBanner()
            } else {
                HStack(spacing: Theme.Space.s) {
                    Button("Clear") { session.clearCart() }.disabled(session.cart.isEmpty)
                    Button("Save order", action: onSave).disabled(session.cart.isEmpty || pricing)
                    Button { onCharge() } label: {
                        if pricing { ProgressView().controlSize(.small) } else { Text(session.pendingOrderId != nil && !session.totalsStale ? "Charge" : "Review & charge") }
                    }
                    .buttonStyle(.primary).disabled(session.cart.isEmpty || pricing)
                }
            }
        }
        .padding(Theme.Space.l)
        .background(Theme.card)
    }

    private func row(_ label: String, _ v: JSONValue?, _ cur: String) -> some View {
        HStack { Text(label).font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary); Spacer(); MoneyText(v.flatMap { Money(json: $0, currency: cur) }) }
    }

    private func clampPercent(_ text: String, type: String) -> String {
        guard type == "percent", let cap = session.discountCap, let v = Double(text), v > cap else { return text }
        return formatCap(cap)
    }
}

func formatCap(_ cap: Double) -> String { cap == cap.rounded() ? String(Int(cap)) : String(cap) }

struct CartRow: View {
    let line: CartLine
    let onInc: () -> Void
    let onDec: () -> Void
    let onEdit: () -> Void
    var body: some View {
        HStack(alignment: .top, spacing: Theme.Space.m) {
            VStack(alignment: .leading, spacing: 2) {
                Text(line.title).font(Theme.Font.body).lineLimit(2)
                HStack(spacing: 6) {
                    MoneyText(line.unitMoney)
                    Text("each").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                    if line.discountType != "none" { StatusBadge("-\(line.discountValue)\(line.discountType == "percent" ? "%" : "")", tone: .pink) }
                    if line.isProduction { StatusBadge("made to order", tone: .accent) }
                }
            }
            Spacer()
            HStack(spacing: 4) {
                Button(action: onDec) { Image(systemName: line.quantity > 1 ? "minus.circle" : "trash") }.buttonStyle(.plain)
                Text("\(line.quantity)").font(Theme.Font.body).monospacedDigit().frame(minWidth: 24)
                Button(action: onInc) { Image(systemName: "plus.circle") }.buttonStyle(.plain)
                Button(action: onEdit) { Image(systemName: "ellipsis.circle") }.buttonStyle(.plain).padding(.leading, 4)
            }
            .foregroundStyle(Theme.Palette.brandDeep)
        }
        .padding(.vertical, 4)
        .contentShape(Rectangle())
    }
}

/// Per-line discount (within the person's cap), made-to-order notes and measurements.
struct LineEditorSheet: View {
    @Environment(\.dismiss) private var dismiss
    @State var line: CartLine
    let cap: Double?
    let save: (CartLine) -> Void

    var body: some View {
        PosSheetFrame(line.title, subtitle: line.sku) {
            LabeledContent("Quantity") { Stepper("\(line.quantity)", value: $line.quantity, in: 1...9999).frame(maxWidth: 160) }
            LabeledContent("Discount") {
                Picker("", selection: $line.discountType) { Text("None").tag("none"); Text("%").tag("percent"); Text("Flat").tag("flat") }.pickerStyle(.segmented).frame(maxWidth: 200)
            }
            if line.discountType != "none" {
                LabeledContent(line.discountType == "percent" ? "Percent" : "Amount (\(line.currency))") {
                    AmountField("0", text: Binding(get: { line.discountValue }, set: { v in
                        if line.discountType == "percent", let cap, let d = Double(v), d > cap { line.discountValue = formatCap(cap) } else { line.discountValue = v }
                    }))
                }
                if let cap { Text("Your limit is \(formatCap(cap))% of the line — a super admin can give more. The Hub enforces it.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
            }
            Toggle("Made to order", isOn: $line.isProduction).toggleStyle(.switch).font(Theme.Font.secondary)
            if line.isProduction {
                if line.measurementFields.isEmpty {
                    TextField("Measurements, colours, preferences…", text: $line.productionNotes, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(3...6)
                } else {
                    ForEach(line.measurementFields, id: \.name) { f in
                        LabeledContent("\(f.name)\(f.unit.map { " (\($0))" } ?? "")\(f.required ? " *" : "")") {
                            TextField("", text: Binding(get: { line.measurementValues[f.name] ?? "" }, set: { line.measurementValues[f.name] = $0 })).textFieldStyle(.roundedBorder).frame(maxWidth: 160)
                        }
                    }
                    TextField("Production notes", text: $line.productionNotes, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4)
                }
            }
        } actions: {
            Button("Cancel") { dismiss() }
            Button("Save") { save(line); dismiss() }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                .disabled(line.isProduction && line.measurementFields.contains { $0.required && (line.measurementValues[$0.name] ?? "").trimmingCharacters(in: .whitespaces).isEmpty })
        }
    }
}
