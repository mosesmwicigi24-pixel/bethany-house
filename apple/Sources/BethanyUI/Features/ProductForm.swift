import SwiftUI
import BethanyKit

/// The product form (ProductFormPage.tsx): one screen for a new product and
/// for editing. Writes go through POST/PUT /products; price and cost changes
/// on an existing product come back as proposals (Phase 3C) and the toast
/// says so. Images, variants and BOM live in ProductFormTabs.swift.
@MainActor
@Observable
final class ProductFormModel {
    struct PriceRow: Identifiable { let id = UUID(); var rowId: Int?; var currency: String; var regular = ""; var sale = ""; var cost = ""; var saleStart = ""; var saleEnd = "" }
    struct Measurement: Identifiable { let id = UUID(); var name = ""; var unit = ""; var required = true }
    struct Feature: Identifiable { let id = UUID(); var icon = ""; var text = "" }

    var product: JSONValue?
    var sku = ""
    var skuManual = false
    var slug = ""
    var categoryId: Int? = nil
    var type = "simple"
    var status = "draft"
    var featured = false
    var producible = false
    var brand = ""
    var taxClass = "standard"
    var lowStock = "5"
    var weight = ""; var length = ""; var width = ""; var height = ""
    var name = ""; var description = ""; var shortDescription = ""
    var prices: [PriceRow] = [PriceRow(rowId: nil, currency: "KES")]
    var metaTitle = ""; var metaDescription = ""; var metaKeywords = ""; var canonical = ""; var ogTitle = ""; var ogDescription = ""
    var measurements: [Measurement] = []
    var features: [Feature] = []
    var stageIds: Set<Int> = []
    var taxRateIds: Set<Int> = []

    func apply(_ p: JSONValue, currencies: [String]) {
        product = p
        sku = p["sku"]?.string ?? ""; skuManual = true
        slug = p["slug"]?.string ?? ""; categoryId = p["category_id"]?.int
        type = p["product_type"]?.string ?? "simple"; status = p["status"]?.string ?? "draft"
        featured = p["is_featured"]?.bool ?? false; producible = p["is_producible"]?.bool ?? false
        brand = p["brand"]?.string ?? ""; taxClass = p["tax_class"]?.string ?? "standard"; lowStock = p["low_stock_threshold"]?.string ?? "5"
        weight = p["weight"].flatMap { $0.isNull ? nil : $0.string } ?? ""; length = p["length"].flatMap { $0.isNull ? nil : $0.string } ?? ""
        width = p["width"].flatMap { $0.isNull ? nil : $0.string } ?? ""; height = p["height"].flatMap { $0.isNull ? nil : $0.string } ?? ""
        let en = (p["translations"]?.array ?? []).first { $0["language_code"]?.string == "en" } ?? p["translations"]?.array?.first
        name = en?["name"]?.string ?? ""; description = en?["description"]?.string ?? ""; shortDescription = en?["short_description"]?.string ?? ""
        let rows = p["prices"]?.array ?? []
        var codes = currencies
        for r in rows { if let c = r["currency_code"]?.string, !codes.contains(c) { codes.append(c) } }
        if !codes.contains("KES") { codes.insert("KES", at: 0) }
        prices = codes.map { code in
            let r = rows.first { $0["currency_code"]?.string == code }
            let clean: (JSONValue?) -> String = { v in v.flatMap { $0.isNull ? nil : $0.string } ?? "" }
            return PriceRow(rowId: r?["id"]?.int, currency: code, regular: clean(r?["regular_price"]), sale: clean(r?["sale_price"]), cost: clean(r?["cost_price"]), saleStart: String(clean(r?["sale_start_date"]).prefix(10)), saleEnd: String(clean(r?["sale_end_date"]).prefix(10)))
        }
        let seo = (p["seo"]?.array ?? []).first { $0["language_code"]?.string == "en" } ?? p["seo"]?.array?.first
        metaTitle = seo?["meta_title"]?.string ?? ""; metaDescription = seo?["meta_description"]?.string ?? ""; metaKeywords = seo?["meta_keywords"]?.string ?? ""
        canonical = seo?["canonical_url"]?.string ?? ""; ogTitle = seo?["og_title"]?.string ?? ""; ogDescription = seo?["og_description"]?.string ?? ""
        measurements = (p["measurements"]?.array ?? []).map { Measurement(name: $0["name"]?.string ?? "", unit: $0["unit"]?.string ?? "", required: $0["required"]?.bool ?? true) }
        features = (p["features"]?.array ?? []).map { Feature(icon: $0["icon"]?.string ?? "", text: $0["text"]?.string ?? "") }
        stageIds = Set((p["production_stage_ids"]?.array ?? []).compactMap(\.int))
        taxRateIds = Set((p["tax_rate_ids"]?.array ?? []).compactMap(\.int))
    }

    func seedCurrencies(_ currencies: [String]) {
        guard product == nil else { return }
        var codes = currencies; if !codes.contains("KES") { codes.insert("KES", at: 0) }
        prices = codes.map { PriceRow(rowId: nil, currency: $0) }
    }

    /// Mirrors the console's SKU helper: category code + name initials; the person may type over it.
    func autoSku(categoryName: String?) {
        guard !skuManual, !name.isEmpty else { return }
        let cat = (categoryName ?? "").components(separatedBy: " ").first.map { String($0.filter(\.isLetter).prefix(3)).uppercased() }
        let initials = name.split(separator: " ").prefix(3).compactMap { $0.first.map { String($0).uppercased() } }.joined()
        let stamp = String(Int(Date().timeIntervalSince1970) % 10000)
        sku = [cat?.isEmpty == false ? cat! : "GEN", initials.isEmpty ? "PRD" : initials, stamp].joined(separator: "-")
    }

    /// ProductCreatePayload; cost only rides on KES rows (withCostOnKesOnly).
    func payload(canCost: Bool) -> JSONValue {
        var o: [String: JSONValue] = [
            "sku": .string(sku.trimmingCharacters(in: .whitespaces)), "product_type": .string(type), "status": .string(status),
            "is_featured": .bool(featured), "is_producible": .bool(producible), "tax_class": .string(taxClass.isEmpty ? "standard" : taxClass),
            "low_stock_threshold": .number(Double(Int(lowStock) ?? 5)), "category_id": categoryId.map { .number(Double($0)) } ?? .null,
            "translations": .array([.object(["language_code": .string("en"), "name": .string(name), "description": .string(description), "short_description": .string(shortDescription)])]),
            "seo": .array([.object(["language_code": .string("en"), "meta_title": .string(metaTitle), "meta_description": .string(metaDescription), "meta_keywords": .string(metaKeywords), "canonical_url": canonical.isEmpty ? .null : .string(canonical), "og_title": .string(ogTitle), "og_description": .string(ogDescription)])]),
            "measurements": .array(measurements.filter { !$0.name.isEmpty }.map { .object(["name": .string($0.name), "unit": .string($0.unit), "required": .bool($0.required)]) }),
            "features": .array(features.filter { !$0.text.trimmingCharacters(in: .whitespaces).isEmpty }.map { .object(["icon": .string($0.icon), "text": .string($0.text)]) }),
            "production_stage_ids": .array(stageIds.sorted().map { .number(Double($0)) }),
        ]
        o.put("slug", jstr(slug)); o.put("brand", jstr(brand))
        o.put("weight", jnum(weight)); o.put("length", jnum(length)); o.put("width", jnum(width)); o.put("height", jnum(height))
        o["prices"] = .array(prices.compactMap { r in
            guard let reg = jnum(r.regular) else { return nil }
            var p: [String: JSONValue] = ["currency_code": .string(r.currency), "regular_price": reg, "sale_price": jnum(r.sale) ?? .null]
            if canCost && r.currency == "KES" { p["cost_price"] = jnum(r.cost) ?? .null }
            p["sale_start_date"] = jstr(r.saleStart) ?? .null; p["sale_end_date"] = jstr(r.saleEnd) ?? .null
            return .object(p)
        })
        return .object(o)
    }
}

struct ProductFormView: View {
    @Environment(AppEnvironment.self) private var env
    let id: Int?
    @State private var model = ProductFormModel()
    @State private var state: Loadable<JSONValue> = .idle
    @State private var tab = "content"
    @State private var categories: [(Int, String)] = []
    @State private var currencies: [String] = []
    @State private var stages: [JRow] = []
    @State private var taxRates: [JRow] = []
    @State private var saving = false
    @State private var error: APIError?

    private var isEditing: Bool { id != nil }
    private var canSave: Bool { isEditing ? env.session.can("products.edit") : env.session.can("products.create") }
    private var canCost: Bool { env.session.can("products.view_cost") }
    private var canEditCost: Bool { env.session.can("products.edit_cost") }

    var body: some View {
        Page(isEditing ? (model.name.isEmpty ? "Product" : model.name) : "New product", subtitle: isEditing ? "\(model.sku) · \(model.status.humanized)" : nil) {
            if isEditing, case .loading(nil) = state { LoadingSkeleton() }
            else if isEditing, case .failed(let e, nil) = state { ErrorState(e, retry: { load() }) }
            else {
                header
                tabs
                switch tab {
                case "media": if let pid = id { ProductImagesTab(productId: pid, product: model.product ?? .null, reload: { load() }) } else { needSave }
                case "pricing": pricingTab
                case "variants": if let pid = id { ProductVariantsTab(productId: pid, product: model.product ?? .null, currencies: currencies, reload: { load() }) } else { needSave }
                case "features": featuresTab
                case "seo": seoTab
                case "measurements": measurementsTab
                case "stages": stagesTab
                case "bom": if let pid = id { BomTab(productId: pid, product: model.product ?? .null) } else { needSave }
                default: contentTab
                }
                if let e = error, e.errors.isEmpty { Text(e.message).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
            }
        }
        .task { await bootstrap() }
    }

    private var needSave: some View { Text("Save the product first, then add this.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary) }

    private var header: some View {
        HStack(spacing: Theme.Space.s) {
            if let p = model.product { RemoteImage(url: p["images"]?.array?.first?["image_url"]?.string, size: 44); StatusBadge(p["status"]?.string) }
            Spacer()
            if isEditing && env.session.can("products.edit") {
                if model.product?["status"]?.string != "active" { Button("Publish") { Task { await put("publish") } } }
                if model.product?["status"]?.string != "archived" { ConfirmButton("Archive product", message: "The product leaves the storefront and the till; it stays on record.", destructive: true, action: { await put("archive") }) { Text("Archive") } }
            }
            if canSave {
                Button { Task { await save() } } label: { if saving { ProgressView().controlSize(.small) } else { Text(isEditing ? "Save" : "Create product") } }
                    .buttonStyle(.borderedProminent).tint(Theme.Palette.brand).disabled(saving || model.name.isEmpty || model.sku.isEmpty)
            } else if canEditCost {
                Text("You hold cost only: propose a KES cost on the Pricing tab.").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            }
        }
        .font(Theme.Font.secondary)
    }

    private var tabList: [(String, String)] {
        var list: [(String, String)] = [("content", "Content"), ("media", "Images"), ("pricing", "Pricing"), ("variants", "Variants"), ("features", "Features"), ("seo", "SEO")]
        if model.producible { list.append(("measurements", "Measurements")); list.append(("stages", "Production Stages")) }
        if env.session.can("production.view_bom") { list.append(("bom", "Bill of Materials")) }
        return list
    }

    private var tabs: some View { SegmentedTabs(tabs: tabList, selection: $tab) }

    // MARK: Tabs

    private var contentTab: some View {
        @Bindable var m = model
        return SectionCard("Content") {
            FieldBox("Name *", error: error?.errors["translations.0.name"]?.first) { TextField("", text: $m.name).textFieldStyle(.roundedBorder).onChange(of: m.name) { _, _ in if !isEditing { m.autoSku(categoryName: categoryName) } } }
            HStack(alignment: .top) {
                FieldBox("SKU *", error: error?.errors["sku"]?.first) { TextField("", text: $m.sku).textFieldStyle(.roundedBorder).onChange(of: m.sku) { _, _ in m.skuManual = true } }
                FieldBox("Slug") { TextField("Auto from the name", text: $m.slug).textFieldStyle(.roundedBorder) }
            }
            HStack(alignment: .top) {
                FieldBox("Category", error: error?.errors["category_id"]?.first) { Picker("", selection: $m.categoryId) { Text("None").tag(Int?.none); ForEach(categories, id: \.0) { Text($0.1).tag(Int?.some($0.0)) } }.labelsHidden().onChange(of: m.categoryId) { _, _ in if !isEditing { m.autoSku(categoryName: categoryName) } } }
                FieldBox("Type") { Picker("", selection: $m.type) { ForEach(ProductVocab.types, id: \.0) { Text($0.1).tag($0.0) } }.labelsHidden() }
                FieldBox("Status") { Picker("", selection: $m.status) { ForEach(ProductVocab.statuses, id: \.self) { Text($0.humanized).tag($0) } }.labelsHidden() }
            }
            HStack { Toggle("Featured", isOn: $m.featured); Toggle("Producible (made in-house)", isOn: $m.producible) }.font(Theme.Font.secondary)
            HStack(alignment: .top) {
                FieldBox("Brand") { TextField("", text: $m.brand).textFieldStyle(.roundedBorder) }
                FieldBox("Tax class") { TextField("standard", text: $m.taxClass).textFieldStyle(.roundedBorder) }
                FieldBox("Low-stock threshold") { TextField("5", text: $m.lowStock).textFieldStyle(.roundedBorder) }
            }
            FieldBox("Short description") { TextField("", text: $m.shortDescription, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
            FieldBox("Description * (stored as HTML; the console's rich editor writes the same field)", error: error?.errors["translations.0.description"]?.first) {
                TextEditor(text: $m.description).font(Theme.Font.body).frame(minHeight: 140).overlay(RoundedRectangle(cornerRadius: 6).stroke(Theme.line))
            }
            Text("Physical").font(Theme.Font.secondary.weight(.semibold))
            HStack {
                FieldBox("Weight (kg)") { DecimalField("", text: $m.weight) }
                FieldBox("Length (cm)") { DecimalField("", text: $m.length) }
                FieldBox("Width (cm)") { DecimalField("", text: $m.width) }
                FieldBox("Height (cm)") { DecimalField("", text: $m.height) }
            }
            if !taxRates.isEmpty {
                Text("Tax rates").font(Theme.Font.secondary.weight(.semibold))
                ForEach(taxRates) { t in
                    if let tid = t.int("id") {
                        Toggle("\(t.str("name") ?? "") (\(fmtNumber(t.dbl("rate"), decimals: 2))%)", isOn: Binding(get: { m.taxRateIds.contains(tid) }, set: { on in if on { m.taxRateIds.insert(tid) } else { m.taxRateIds.remove(tid) } })).font(Theme.Font.secondary)
                    }
                }
            }
        }
    }

    private var categoryName: String? { model.categoryId.flatMap { id in categories.first { $0.0 == id }?.1 } }

    private var pricingTab: some View {
        @Bindable var m = model
        return SectionCard("Pricing per currency") {
            Text("Customer pricing (currencies.exchange_rate), never the reporting rate. A price or cost change on an existing product is a proposal: within the maker's band it applies at once, otherwise it waits in Approvals and the live price stays.").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            ForEach($m.prices) { $row in
                VStack(alignment: .leading, spacing: 6) {
                    Text(row.currency).font(Theme.Font.secondary.weight(.semibold))
                    HStack(alignment: .top) {
                        FieldBox("Regular price", error: error?.errors["prices.0.regular_price"]?.first) { DecimalField("", text: $row.regular) }
                        FieldBox("Sale price") { DecimalField("", text: $row.sale) }
                        if canCost && row.currency == "KES" { FieldBox("Cost (KES)") { DecimalField("", text: $row.cost).disabled(!canSave) } }
                    }
                    HStack {
                        FieldBox("Sale starts (YYYY-MM-DD)") { TextField("", text: $row.saleStart).textFieldStyle(.roundedBorder) }
                        FieldBox("Sale ends") { TextField("", text: $row.saleEnd).textFieldStyle(.roundedBorder) }
                    }
                }
                .padding(.vertical, 4)
                Divider()
            }
            if !canCost { Text("Cost is need-to-know (products.view_cost); it is not shown here and is left untouched on save.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
            if isEditing && canEditCost && !env.session.can("products.edit"), let p = model.product { CostProposalPanel(product: p) { load() } }
        }
    }

    private var featuresTab: some View {
        @Bindable var m = model
        return SectionCard("Storefront features (icon + short line)") {
            ForEach($m.features) { $f in
                HStack { TextField("Icon (emoji)", text: $f.icon).textFieldStyle(.roundedBorder).frame(width: 90); TextField("e.g. Hand-finished seams", text: $f.text).textFieldStyle(.roundedBorder); Button { m.features.removeAll { $0.id == f.id } } label: { Image(systemName: "minus.circle") }.buttonStyle(.plain).foregroundStyle(Theme.Palette.danger) }
            }
            Button { m.features.append(.init()) } label: { Label("Add feature", systemImage: "plus") }.font(Theme.Font.secondary)
        }
    }

    private var seoTab: some View {
        @Bindable var m = model
        return SectionCard("SEO (English)") {
            FieldBox("Meta title") { TextField("", text: $m.metaTitle).textFieldStyle(.roundedBorder) }
            FieldBox("Meta description") { TextField("", text: $m.metaDescription, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
            FieldBox("Meta keywords") { TextField("comma, separated", text: $m.metaKeywords).textFieldStyle(.roundedBorder) }
            FieldBox("Canonical URL", error: error?.errors["seo.0.canonical_url"]?.first) { TextField("https://…", text: $m.canonical).textFieldStyle(.roundedBorder) }
            FieldBox("Open Graph title") { TextField("", text: $m.ogTitle).textFieldStyle(.roundedBorder) }
            FieldBox("Open Graph description") { TextField("", text: $m.ogDescription, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
        }
    }

    private var measurementsTab: some View {
        @Bindable var m = model
        return SectionCard("Measurements taken for a made-to-order piece") {
            ForEach($m.measurements) { $mm in
                HStack { TextField("Name (e.g. Chest)", text: $mm.name).textFieldStyle(.roundedBorder); TextField("Unit", text: $mm.unit).textFieldStyle(.roundedBorder).frame(width: 80); Toggle("Required", isOn: $mm.required).font(Theme.Font.caption); Button { m.measurements.removeAll { $0.id == mm.id } } label: { Image(systemName: "minus.circle") }.buttonStyle(.plain).foregroundStyle(Theme.Palette.danger) }
            }
            Button { m.measurements.append(.init()) } label: { Label("Add measurement", systemImage: "plus") }.font(Theme.Font.secondary)
        }
    }

    private var stagesTab: some View {
        @Bindable var m = model
        return SectionCard("Production stages this product passes through") {
            Text("Pick the stages this product actually needs; its production orders carry only those, in catalogue order (Setup → Production Stages). None selected means every active stage.").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            ForEach(stages) { s in
                if let sid = s.int("id") {
                    Toggle(isOn: Binding(get: { m.stageIds.contains(sid) }, set: { on in if on { m.stageIds.insert(sid) } else { m.stageIds.remove(sid) } })) {
                        HStack { Text(s.str("name") ?? "Stage \(sid)"); if s["active"]?.bool == false { StatusBadge("inactive", tone: .neutral) } }
                    }
                    .font(Theme.Font.secondary)
                }
            }
        }
    }

    // MARK: Loading & saving

    private func bootstrap() async {
        let c = env.session.client
        async let cats = c.get("/v1/admin/categories", background: true)
        async let curs = c.get("/v1/admin/currencies-management", background: true)
        async let stg = c.get("/v1/admin/product-stages", background: true)
        async let tax = c.get("/v1/admin/tax-rates", background: true)
        if let r = try? await cats { categories = (r.json?["data"]?.array ?? []).compactMap { c in c["id"]?.int.map { ($0, c["name_en"]?.string ?? "Category \($0)") } } }
        if let r = try? await curs { currencies = (r.json?["data"]?.array ?? []).filter { $0["is_active"]?.bool ?? true }.compactMap { $0["code"]?.string } }
        if let r = try? await stg { stages = jrows(r.json?.array != nil ? r.json : r.json?["data"]) }
        if let r = try? await tax { taxRates = jrows(r.json?["data"]) }
        model.seedCurrencies(currencies)
        if isEditing { load() }
    }

    private func load() {
        guard let id else { return }
        state = .loading(previous: state.value)
        Task {
            do {
                let j = try await env.session.client.get("/v1/admin/products/\(id)").json ?? .null
                model.apply(j["product"] ?? j, currencies: currencies)
                state = .loaded(j)
            } catch let e as APIError { state = .failed(e, previous: state.value) } catch { state = .failed(.transport(error.localizedDescription), previous: state.value) }
        }
    }

    private func save() async {
        saving = true; defer { saving = false }
        error = nil
        let body = model.payload(canCost: canCost)
        do {
            let r: APIClient.Response
            if let id { r = try await env.session.client.put("/v1/admin/products/\(id)", json: body) }
            else { r = try await env.session.client.post("/v1/admin/products", json: body) }
            let pid = r.json?["product"]?["id"]?.int ?? id
            if let pid, !taxRates.isEmpty {
                _ = try? await env.session.client.post("/v1/admin/products/\(pid)/tax-rates", json: .object(["tax_rate_ids": .array(model.taxRateIds.sorted().map { .number(Double($0)) })]))
            }
            let waiting = (r.json?["proposals"]?.array ?? []).filter { $0["status"]?.string != "applied" }
            env.toasts.show(r.json?["message"]?.string ?? (isEditing ? "Product saved" : "Product created"), tone: waiting.isEmpty ? .success : .info)
            if !isEditing, let pid { env.navigator.go(.product(id: pid)) } else { load() }
        } catch let e as APIError { error = e; env.toasts.error(e) } catch { env.toasts.error(error) }
    }

    private func put(_ action: String) async {
        guard let id else { return }
        do { let r = try await env.session.client.put("/v1/admin/products/\(id)/\(action)"); env.toasts.show(r.json?["message"]?.string ?? "Done"); load() } catch { env.toasts.error(error) }
    }
}

/// products.edit_cost without products.edit: the one write is a KES cost per
/// price row, proposed (POST /proposals, event product_cost_change) — within
/// 5% of the cost 24 hours ago it applies at once, else finance signs.
struct CostProposalPanel: View {
    @Environment(AppEnvironment.self) private var env
    let product: JSONValue
    let changed: () -> Void
    @State private var values: [Int: String] = [:]
    @State private var busy = false

    struct CostRow: Identifiable { let id: Int; let label: String; let cost: JSONValue? }

    private var rows: [CostRow] {
        var out: [CostRow] = []
        for p in product["prices"]?.array ?? [] where p["currency_code"]?.string == "KES" { if let id = p["id"]?.int { out.append(CostRow(id: id, label: "Product", cost: p["cost_price"])) } }
        for v in product["variants"]?.array ?? [] {
            for p in v["prices"]?.array ?? [] where p["currency_code"]?.string == "KES" { if let id = p["id"]?.int { out.append(CostRow(id: id, label: v["variant_name"]?.string ?? "Variant", cost: p["cost_price"])) } }
        }
        return out
    }

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.s) {
            Text("Cost (KES)").font(Theme.Font.secondary.weight(.semibold))
            Text("Within 5% of the cost 24 hours ago it applies at once; more needs approval from finance (and the super admin above 25%).").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            if rows.isEmpty { Text("This product has no KES price row yet, so it has nowhere to keep a cost.").font(Theme.Font.caption) }
            ForEach(rows) { r in
                HStack {
                    Text("\(r.label) — now \(r.cost.flatMap { $0.isNull ? nil : fmtMoney($0, currency: "KES") } ?? "no cost")").font(Theme.Font.secondary).frame(minWidth: 180, alignment: .leading)
                    DecimalField("New cost", text: Binding(get: { values[r.id] ?? "" }, set: { values[r.id] = $0 })).frame(maxWidth: 140)
                    Button("Propose") { Task { await propose(r.id) } }.disabled(busy || decimal(values[r.id] ?? "") == nil)
                }
            }
        }
        .padding(Theme.Space.m).background(Theme.field, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
    }

    private func propose(_ rowId: Int) async {
        busy = true; defer { busy = false }
        do {
            let r = try await env.session.client.post("/v1/admin/proposals", json: .object(["event": .string("product_cost_change"), "subject_id": .number(Double(rowId)), "changes": .object(["cost_price": jnum(values[rowId] ?? "") ?? .null])]))
            let applied = r.json?["proposal"]?["status"]?.string == "applied" || r.json?["proposal"]?.isNull == true
            env.toasts.show(r.json?["message"]?.string ?? "Proposed", tone: applied ? .success : .info)
            values[rowId] = nil
            changed()
        } catch { env.toasts.error(error) }
    }
}
