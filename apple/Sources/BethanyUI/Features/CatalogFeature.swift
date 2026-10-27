import SwiftUI
import BethanyKit

/// Catalog: products list (search, filters, CSV bulk import), product form
/// (content, images & video, pricing per currency through proposals,
/// variants, features, SEO, measurements, production stages, BOM), publish,
/// archive; categories tree with CRUD, subcategories, reorder, image, toggle.
/// Endpoints verified against ProductController, CategoryController,
/// BomController and react-admin/src/api/{products,categories,bom,proposals}.ts.
@MainActor
enum CatalogFeature {
    static func register() {
        FeatureRegistry.register(.products) { _ in AnyView(ProductsListView()) }
        FeatureRegistry.register({ if case .product = $0 { return true }; return false }) { route in
            if case .product(let id, let new) = route { return AnyView(ProductFormView(id: new ? nil : id)) }
            return AnyView(EmptyView())
        }
        FeatureRegistry.register(.categories) { _ in AnyView(CategoriesView()) }
    }
}

enum ProductVocab {
    static let statuses = ["draft", "active", "inactive", "archived"]
    static let types: [(String, String)] = [("simple", "Simple"), ("variable", "Variable"), ("made_to_order", "Made to order")]
}

struct ProductListItem: Identifiable, Hashable {
    let json: JSONValue
    var id: Int { json["id"]?.int ?? 0 }
    var sku: String { json["sku"]?.string ?? "" }
    var name: String { json["en_translation"]?["name"]?.string ?? sku }
    var status: String { json["status"]?.string ?? "draft" }
    var type: String { json["product_type"]?.string ?? "simple" }
    var category: String? { json["category"]?["name_en"]?.string }
    var image: String? { json["primary_image"]?["image_url"]?.string }
    var stock: Int { json["total_stock"]?.int ?? 0 }
    var lowThreshold: Int { json["low_stock_threshold"]?.int ?? 0 }
    var variants: Int { json["variants_count"]?.int ?? 0 }
    var price: Money? { json["base_price"]?["regular_price"].flatMap { Money(json: $0, currency: json["base_price"]?["currency_code"]?.string ?? "KES") } }
    var salePrice: Money? { json["base_price"]?["sale_price"].flatMap { $0.isNull ? nil : Money(json: $0, currency: json["base_price"]?["currency_code"]?.string ?? "KES") } }
    var featured: Bool { json["is_featured"]?.bool ?? false }
    var producible: Bool { json["is_producible"]?.bool ?? false }
}

/// GET /products → `{data, meta: {current_page,last_page,total,from,to}, stats, brands, categories}`.
@MainActor
@Observable
final class ProductListModel {
    var items: [ProductListItem] = []
    var stats: JSONValue = .null
    var brands: [String] = []
    var categories: [(Int, String)] = []
    var page = 1
    var lastPage = 1
    var total = 0
    var state: Loadable<[ProductListItem]> = .idle
    var query: [String: String?] = [:]
    private let client: APIClient
    private var token = UUID()
    init(client: APIClient) { self.client = client }

    func load(page: Int = 1) async {
        let my = UUID(); token = my
        self.page = page
        state = .loading(previous: items.isEmpty ? nil : items)
        var q = query; q["page"] = String(page); q["per_page"] = "40"
        do {
            let r = try await client.get("/v1/admin/products", query: q)
            guard token == my else { return }
            items = (r.json?["data"]?.array ?? []).map(ProductListItem.init(json:))
            lastPage = r.json?["meta"]?["last_page"]?.int ?? 1
            total = r.json?["meta"]?["total"]?.int ?? items.count
            stats = r.json?["stats"] ?? .null
            brands = r.json?["brands"]?.array?.compactMap(\.string) ?? brands
            let cats = (r.json?["categories"]?.array ?? []).compactMap { c -> (Int, String)? in guard let id = c["id"]?.int else { return nil }; return (id, c["name_en"]?.string ?? "Category \(id)") }
            if !cats.isEmpty { categories = cats }
            state = .loaded(items)
        } catch let e as APIError { if token == my { state = .failed(e, previous: items.isEmpty ? nil : items) } }
        catch { if token == my { state = .failed(.transport(error.localizedDescription), previous: nil) } }
    }
}

struct ProductsListView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var model: ProductListModel?
    @State private var search = ""
    @State private var status = ""
    @State private var type = ""
    @State private var categoryId: Int? = nil
    @State private var brand = ""
    @State private var featured = ""
    @State private var producible = ""
    @State private var importing = false

    var body: some View {
        Page("Products", subtitle: model.map { m in "\(m.stats["total"]?.int ?? m.total) products · \(m.stats["active"]?.int ?? 0) active · \(m.stats["draft"]?.int ?? 0) draft · \(m.stats["archived"]?.int ?? 0) archived" }) {
            if let model {
                filters(model)
                LoadableView(model.state, retry: { Task { await model.load() } }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No products match", message: "Change the filters, or add a product.", symbol: "shippingbox") }) { items in
                    LazyVStack(spacing: Theme.Space.s) {
                        ForEach(items) { p in productRow(p) }
                    }
                    HStack {
                        Button("Previous") { Task { await model.load(page: model.page - 1) } }.disabled(model.page <= 1)
                        Text("Page \(model.page) of \(model.lastPage) · \(model.total) products").font(Theme.Font.caption).monospacedDigit()
                        Button("Next") { Task { await model.load(page: model.page + 1) } }.disabled(model.page >= model.lastPage)
                    }
                    .font(Theme.Font.secondary)
                }
            }
        }
        .toolbar {
            ToolbarItemGroup(placement: .primaryAction) {
                if env.session.can("products.import") { Button { importing = true } label: { Label("Bulk import", systemImage: "square.and.arrow.down.on.square") } }
                if env.session.can("products.create") { Button { env.navigator.push(.product(new: true)) } label: { Label("New product", systemImage: "plus") } }
            }
        }
        .task {
            if model == nil { let m = ProductListModel(client: env.session.client); model = m; await m.load() }
        }
        .onChange(of: filterKey) { _, _ in apply() }
        .refreshable { await model?.load(page: model?.page ?? 1) }
        .sheet(isPresented: $importing) { BulkImportSheet { importing = false; Task { await model?.load() } } }
    }

    private var filterKey: String { "\(search)|\(status)|\(type)|\(categoryId ?? 0)|\(brand)|\(featured)|\(producible)" }

    private func apply() {
        guard let model else { return }
        model.query = [
            "search": search.isEmpty ? nil : search, "status": status.isEmpty ? nil : status, "product_type": type.isEmpty ? nil : type,
            "category_id": categoryId.map(String.init), "brand": brand.isEmpty ? nil : brand,
            "is_featured": featured.isEmpty ? nil : featured, "is_producible": producible.isEmpty ? nil : producible,
        ]
        Task { await model.load() }
    }

    private func filters(_ model: ProductListModel) -> some View {
        VStack(alignment: .leading, spacing: Theme.Space.s) {
            HStack(spacing: Theme.Space.s) {
                TextField("Search name or SKU", text: $search).textFieldStyle(.roundedBorder).frame(maxWidth: 260)
                Picker("Status", selection: $status) { Text("All statuses").tag(""); ForEach(ProductVocab.statuses, id: \.self) { Text($0.humanized).tag($0) } }.labelsHidden()
                Picker("Type", selection: $type) { Text("All types").tag(""); ForEach(ProductVocab.types, id: \.0) { Text($0.1).tag($0.0) } }.labelsHidden()
                Picker("Category", selection: $categoryId) { Text("All categories").tag(Int?.none); ForEach(model.categories, id: \.0) { Text($0.1).tag(Int?.some($0.0)) } }.labelsHidden()
            }
            HStack(spacing: Theme.Space.s) {
                if !model.brands.isEmpty { Picker("Brand", selection: $brand) { Text("All brands").tag(""); ForEach(model.brands, id: \.self) { Text($0).tag($0) } }.labelsHidden() }
                Picker("Featured", selection: $featured) { Text("Featured: any").tag(""); Text("Featured").tag("true"); Text("Not featured").tag("false") }.labelsHidden()
                Picker("Producible", selection: $producible) { Text("Producible: any").tag(""); Text("Producible").tag("true"); Text("Not producible").tag("false") }.labelsHidden()
                Spacer()
            }
        }
        .font(Theme.Font.secondary)
    }

    private func productRow(_ p: ProductListItem) -> some View {
        Button { env.navigator.push(.product(id: p.id)) } label: {
            HStack(spacing: Theme.Space.m) {
                RemoteImage(url: p.image, size: 52)
                VStack(alignment: .leading, spacing: 3) {
                    HStack { Text(p.name).font(Theme.Font.body).lineLimit(1); StatusBadge(p.status); if p.featured { StatusBadge("featured", tone: .accent) } }
                    Text("\(p.sku) · \(p.category ?? "No category") · \(ProductVocab.types.first { $0.0 == p.type }?.1 ?? p.type.humanized)\(p.variants > 0 ? " · \(p.variants) variants" : "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).lineLimit(1)
                    HStack(spacing: 6) {
                        Text("Stock \(p.stock)").font(Theme.Font.caption).foregroundStyle(p.stock <= 0 ? Theme.Palette.danger : (p.stock <= p.lowThreshold ? Theme.Palette.warning : Theme.textSecondary))
                        if p.producible { Text("· producible").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                    }
                }
                Spacer()
                VStack(alignment: .trailing, spacing: 2) {
                    MoneyText(p.price)
                    if let s = p.salePrice { Text("sale \(s.formatted())").font(Theme.Font.caption).foregroundStyle(Theme.Palette.pink) }
                }
            }
            .padding(Theme.Space.m)
            .background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .contextMenu {
            Button("Open") { env.navigator.push(.product(id: p.id)) }
            if env.session.can("products.edit") && p.status != "archived" { Button("Archive") { Task { await archive(p) } } }
            if env.session.can("products.delete") { Button("Delete", role: .destructive) { Task { await delete(p) } } }
        }
    }

    private func archive(_ p: ProductListItem) async {
        do { _ = try await env.session.client.put("/v1/admin/products/\(p.id)/archive"); env.toasts.show("Archived"); await model?.load(page: model?.page ?? 1) } catch { env.toasts.error(error) }
    }
    private func delete(_ p: ProductListItem) async {
        do { _ = try await env.session.client.delete("/v1/admin/products/\(p.id)"); env.toasts.show("Deleted"); await model?.load(page: model?.page ?? 1) } catch { env.toasts.error(error) }
    }
}

/// CSV bulk import: download the template (GET /products/export-template),
/// upload a CSV (POST /products/bulk-import, `file`) → `{message, imported, errors}`.
struct BulkImportSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let done: () -> Void
    @State private var file: UploadFile?
    @State private var busy = false
    @State private var result: JSONValue?

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.l) {
            Text("Bulk import products").font(Theme.Font.sectionTitle)
            Text("1. Download the CSV template. 2. Fill one product per row (sku, name_en, description_en, short_description_en, category_id, product_type, status, price_kes, price_usd, cost_kes, brand, weight, is_featured, is_producible, low_stock_threshold). 3. Upload it.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            HStack {
                DocumentButton("Download template", path: "/v1/admin/products/export-template", suggestedName: "products-import-template.csv")
                AttachmentPicker(label: file?.name ?? "Choose CSV…", types: [.commaSeparatedText, .plainText], camera: false) { file = $0.first }
            }
            .font(Theme.Font.secondary)
            if let result {
                VStack(alignment: .leading, spacing: 4) {
                    Text("\(result["imported"]?.int ?? 0) product(s) imported").font(Theme.Font.secondary.weight(.semibold)).foregroundStyle((result["imported"]?.int ?? 0) > 0 ? Theme.Palette.success : Theme.Palette.warning)
                    ForEach(Array((result["errors"]?.array ?? []).enumerated()), id: \.offset) { _, e in Text(e.string ?? "").font(Theme.Font.caption).foregroundStyle(Theme.Palette.danger) }
                }
            }
            HStack {
                Spacer()
                Button("Close") { dismiss(); if result != nil { done() } }
                Button { Task { await upload() } } label: { if busy { ProgressView().controlSize(.small) } else { Text("Import") } }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand).disabled(file == nil || busy)
            }
        }
        .padding(Theme.Space.xl).frame(minWidth: 440, idealWidth: 560, minHeight: 300)
    }

    private func upload() async {
        guard let file else { return }
        busy = true; defer { busy = false }
        do {
            let r = try await env.session.client.send(APIClient.Request(.post, "/v1/admin/products/bulk-import", body: .multipart([file.part(name: "file")])))
            result = r.json
            env.toasts.show(r.json?["message"]?.string ?? "Import finished")
        } catch { env.toasts.error(error) }
    }
}
