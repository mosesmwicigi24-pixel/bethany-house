import SwiftUI
import BethanyKit

// Product form tabs that act on a saved product: images and video,
// variants (with generate-from-attributes), and the bill of materials.

// MARK: - Images & video

struct ProductImagesTab: View {
    @Environment(AppEnvironment.self) private var env
    let productId: Int
    let product: JSONValue
    let reload: () -> Void
    @State private var busy = false
    @State private var videoStatus: String?

    private var canEdit: Bool { env.session.can("products.edit") }
    private var images: [JRow] { jrows(product["images"]) }

    var body: some View {
        SectionCard("Images") {
            if images.isEmpty { Text("No images yet.").font(Theme.Font.secondary).foregroundStyle(Theme.textTertiary) }
            ForEach(Array(images.enumerated()), id: \.element.id) { index, img in
                HStack(spacing: Theme.Space.m) {
                    RemoteImage(url: img.str("thumbnail_url") ?? img.str("image_url"), size: 64)
                    VStack(alignment: .leading, spacing: 2) {
                        HStack { Text(img.str("alt_text") ?? "Image \(index + 1)").font(Theme.Font.body); if img["is_primary"]?.bool == true { StatusBadge("primary", tone: .success) } }
                        Text("Order \(img.int("sort_order") ?? index)").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    }
                    Spacer()
                    if canEdit, let iid = img.int("id") {
                        Button { Task { await move(index, -1) } } label: { Image(systemName: "arrow.up") }.disabled(index == 0 || busy)
                        Button { Task { await move(index, 1) } } label: { Image(systemName: "arrow.down") }.disabled(index >= images.count - 1 || busy)
                        if img["is_primary"]?.bool != true { Button("Make primary") { Task { await put("images/\(iid)/primary", "Primary image updated") } }.disabled(busy) }
                        ConfirmButton("Delete image", message: "The image is removed from the product.", destructive: true, action: { await delete("images/\(iid)", "Image deleted") }) { Image(systemName: "trash") }
                    }
                }
                .font(Theme.Font.secondary)
                .padding(.vertical, 4)
                Divider()
            }
            if canEdit {
                AttachmentPicker(label: busy ? "Uploading…" : "Add images (up to 10)", types: [.image], allowsMultiple: true) { files in Task { await upload(files) } }.disabled(busy).font(Theme.Font.secondary)
            }
        }
        SectionCard("Video") {
            let status = videoStatus ?? product["video_status"]?.string
            if let url = product["video_url"]?.string, !url.isEmpty, status == nil {
                HStack { Image(systemName: "play.rectangle").foregroundStyle(Theme.Palette.brand); Text(url).font(Theme.Font.caption).lineLimit(1); Spacer() }
            }
            if status == "processing" { Label("Converting for the web — it will appear here shortly.", systemImage: "clock").font(Theme.Font.secondary).foregroundStyle(Theme.Palette.amber) }
            if status == "failed" { Label("The last conversion failed. Upload the clip again.", systemImage: "exclamationmark.triangle").font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
            if product["video_url"]?.string == nil && status == nil { Text("No clip. One short clip per product (hover-to-play on storefront cards); uploading again replaces it.").font(Theme.Font.secondary).foregroundStyle(Theme.textTertiary) }
            if canEdit {
                HStack {
                    AttachmentPicker(label: "Upload video", types: [.movie, .mpeg4Movie, .quickTimeMovie], camera: false) { files in if let f = files.first { Task { await uploadVideo(f) } } }
                    if product["video_url"]?.string != nil || status != nil {
                        ConfirmButton("Remove video", message: "The clip is removed from the product.", destructive: true, action: { await delete("video", "Video removed") }) { Text("Remove") }
                    }
                }
                .font(Theme.Font.secondary).disabled(busy)
            }
        }
        .task(id: product["video_status"]?.string) { await pollVideo() }
    }

    private func upload(_ files: [UploadFile]) async {
        busy = true; defer { busy = false }
        let parts = files.prefix(10).map { $0.part(name: "images[]") }
        do { _ = try await env.session.client.send(APIClient.Request(.post, "/v1/admin/products/\(productId)/images", body: .multipart(Array(parts)))); env.toasts.show("\(parts.count) image(s) uploaded"); reload() } catch { env.toasts.error(error) }
    }

    private func move(_ index: Int, _ delta: Int) async {
        var ids = images.compactMap { $0.int("id") }
        let to = index + delta
        guard ids.indices.contains(index), ids.indices.contains(to) else { return }
        ids.swapAt(index, to)
        let body: JSONValue = .object(["images": .array(ids.enumerated().map { .object(["id": .number(Double($0.element)), "sort_order": .number(Double($0.offset))]) })])
        busy = true; defer { busy = false }
        do { _ = try await env.session.client.put("/v1/admin/products/\(productId)/images/reorder", json: body); reload() } catch { env.toasts.error(error) }
    }

    private func put(_ path: String, _ msg: String) async {
        do { _ = try await env.session.client.put("/v1/admin/products/\(productId)/\(path)"); env.toasts.show(msg); reload() } catch { env.toasts.error(error) }
    }
    private func delete(_ path: String, _ msg: String) async {
        do { _ = try await env.session.client.delete("/v1/admin/products/\(productId)/\(path)"); env.toasts.show(msg); videoStatus = nil; reload() } catch { env.toasts.error(error) }
    }

    private func uploadVideo(_ f: UploadFile) async {
        busy = true; defer { busy = false }
        do {
            let r = try await env.session.client.send(APIClient.Request(.post, "/v1/admin/products/\(productId)/video", body: .multipart([f.part(name: "video")])))
            videoStatus = r.json?["video_status"]?.string ?? "processing"
            env.toasts.show(r.json?["message"]?.string ?? "Video uploaded")
            await pollVideo()
        } catch { env.toasts.error(error) }
    }

    /// GET /products/{id}/video until video_status settles (null) or fails.
    private func pollVideo() async {
        guard (videoStatus ?? product["video_status"]?.string) == "processing" else { return }
        for _ in 0..<40 {
            try? await Task.sleep(for: .seconds(5))
            guard !Task.isCancelled, let j = try? await env.session.client.get("/v1/admin/products/\(productId)/video", background: true).json else { return }
            let s = j["video_status"]?.string
            videoStatus = s
            if s != "processing" { reload(); return }
        }
    }
}

// MARK: - Variants

struct ProductVariantsTab: View {
    @Environment(AppEnvironment.self) private var env
    let productId: Int
    let product: JSONValue
    let currencies: [String]
    let reload: () -> Void
    @State private var editing: JSONItem?
    @State private var adding = false
    @State private var generating = false

    private var canEdit: Bool { env.session.can("products.edit") }
    private var canCost: Bool { env.session.can("products.view_cost") }

    var body: some View {
        SectionCard("Variants") {
            let rows = jrows(product["variants"])
            if rows.isEmpty { Text("No variants. Define attributes (Size, Colour…) and generate every combination, or add one by hand.").font(Theme.Font.secondary).foregroundStyle(Theme.textTertiary) }
            ForEach(rows) { v in
                HStack(alignment: .top) {
                    VStack(alignment: .leading, spacing: 2) {
                        HStack { Text(v.str("variant_name") ?? "").font(Theme.Font.body); if v["is_default"]?.bool == true { StatusBadge("default", tone: .info) }; if v["is_active"]?.bool == false { StatusBadge("inactive", tone: .neutral) } }
                        Text("\(v.str("sku") ?? "") · \(jpairs(v["attributes"]).map { "\($0.key): \($0.value.string ?? "")" }.joined(separator: ", "))").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        Text(jrows(v["prices"]).map { p in "\(fmtMoney(p["regular_price"], currency: p.str("currency_code") ?? "")) " + (p["sale_price"].flatMap { $0.isNull ? nil : "(sale \(fmtMoney($0, currency: p.str("currency_code") ?? "")))" } ?? "") }.joined(separator: " · ")).font(Theme.Font.caption)
                        if canCost, let kes = jrows(v["prices"]).first(where: { $0.str("currency_code") == "KES" }), let c = kes["cost_price"], !c.isNull { Text("Cost \(fmtMoney(c, currency: "KES"))").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                    }
                    Spacer()
                    if canEdit, let vid = v.int("id") {
                        Button("Edit") { editing = JSONItem(json: v.json) }
                        ConfirmButton("Delete variant", message: "A variant that has been ordered cannot be deleted.", destructive: true, action: { await delete(vid) }) { Image(systemName: "trash") }
                    }
                }
                .font(Theme.Font.secondary).padding(.vertical, 4)
                Divider()
            }
            if canEdit {
                HStack { Button("Add variant") { adding = true }; Button("Generate from attributes") { generating = true } }.font(Theme.Font.secondary)
            }
        }
        .sheet(isPresented: $adding) { ProductVariantSheet(productId: productId, existing: nil, currencies: priceCurrencies, canCost: canCost) { adding = false; reload() } }
        .sheet(item: $editing) { e in ProductVariantSheet(productId: productId, existing: e.json, currencies: priceCurrencies, canCost: canCost) { editing = nil; reload() } }
        .sheet(isPresented: $generating) { VariantGeneratorSheet(productId: productId, productSku: product["sku"]?.string ?? "SKU", currencies: priceCurrencies, canCost: canCost) { generating = false; reload() } }
    }

    private var priceCurrencies: [String] {
        var codes = currencies
        for p in product["prices"]?.array ?? [] { if let c = p["currency_code"]?.string, !codes.contains(c) { codes.append(c) } }
        if !codes.contains("KES") { codes.insert("KES", at: 0) }
        return codes
    }

    private func delete(_ vid: Int) async {
        do { _ = try await env.session.client.delete("/v1/admin/products/\(productId)/variants/\(vid)"); env.toasts.show("Variant deleted"); reload() } catch { env.toasts.error(error) }
    }
}

struct VariantPrice: Identifiable { let id = UUID(); let currency: String; var regular = ""; var sale = ""; var cost = "" }

func variantPricesJSON(_ prices: [VariantPrice], canCost: Bool) -> JSONValue {
    .array(prices.compactMap { p in
        guard let reg = jnum(p.regular) else { return nil }
        var o: [String: JSONValue] = ["currency_code": .string(p.currency), "regular_price": reg, "sale_price": jnum(p.sale) ?? .null]
        if canCost && p.currency == "KES" { o["cost_price"] = jnum(p.cost) ?? .null }
        return .object(o)
    })
}

struct ProductVariantSheet: View {
    @Environment(AppEnvironment.self) private var env
    let productId: Int
    let existing: JSONValue?
    let currencies: [String]
    let canCost: Bool
    let done: () -> Void
    @State private var sku = ""
    @State private var name = ""
    @State private var autoName = true
    @State private var attrs: [(String, String)] = [("Size", ""), ("Colour", "")]
    @State private var weight = ""
    @State private var isDefault = false
    @State private var isActive = true
    @State private var prices: [VariantPrice] = []
    @State private var error: APIError?
    @State private var busy = false

    var body: some View {
        BHFormSheet(existing == nil ? "New variant" : "Edit variant", primary: existing == nil ? "Create" : "Save", busy: busy, disabled: sku.isEmpty || (!autoName && name.isEmpty), action: save) {
            FieldBox("SKU *", error: error?.errors["sku"]?.first) { TextField("", text: $sku).textFieldStyle(.roundedBorder) }
            if existing == nil { Toggle("Compose the name from product + attributes (server)", isOn: $autoName).font(Theme.Font.secondary) }
            if !autoName || existing != nil { FieldBox("Variant name", error: error?.errors["variant_name"]?.first) { TextField("", text: $name).textFieldStyle(.roundedBorder) } }
            Text("Attributes").font(Theme.Font.secondary.weight(.semibold))
            ForEach(attrs.indices, id: \.self) { i in
                HStack {
                    TextField("Attribute", text: Binding(get: { attrs[i].0 }, set: { attrs[i].0 = $0 })).textFieldStyle(.roundedBorder).frame(width: 120)
                    TextField("Value", text: Binding(get: { attrs[i].1 }, set: { attrs[i].1 = $0 })).textFieldStyle(.roundedBorder)
                    Button { attrs.remove(at: i) } label: { Image(systemName: "minus.circle") }.buttonStyle(.plain).foregroundStyle(Theme.Palette.danger)
                }
            }
            Button { attrs.append(("", "")) } label: { Label("Add attribute", systemImage: "plus") }.font(Theme.Font.secondary)
            HStack { FieldBox("Weight (kg)") { DecimalField("", text: $weight) }; Toggle("Default", isOn: $isDefault); Toggle("Active", isOn: $isActive) }.font(Theme.Font.secondary)
            Text("Prices").font(Theme.Font.secondary.weight(.semibold))
            ForEach($prices) { $p in
                HStack {
                    Text(p.currency).frame(width: 44, alignment: .leading)
                    DecimalField("Regular", text: $p.regular); DecimalField("Sale", text: $p.sale)
                    if canCost && p.currency == "KES" { DecimalField("Cost", text: $p.cost) }
                }
                .font(Theme.Font.secondary)
            }
            if existing != nil { Text("Price and cost changes are proposals (Phase 3C): the live price stays until signed when the change is past your band.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
            if let e = error, e.errors.isEmpty { Text(e.message).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
        }
        .onAppear {
            prices = currencies.map { VariantPrice(currency: $0) }
            guard let v = existing else { return }
            sku = v["sku"]?.string ?? ""; name = v["variant_name"]?.string ?? ""; autoName = false
            attrs = jpairs(v["attributes"]).map { ($0.key, $0.value.string ?? "") }
            weight = v["weight"].flatMap { $0.isNull ? nil : $0.string } ?? ""; isDefault = v["is_default"]?.bool ?? false; isActive = v["is_active"]?.bool ?? true
            for p in v["prices"]?.array ?? [] {
                if let i = prices.firstIndex(where: { $0.currency == p["currency_code"]?.string }) {
                    prices[i].regular = p["regular_price"]?.string ?? ""; prices[i].sale = p["sale_price"].flatMap { $0.isNull ? nil : $0.string } ?? ""; prices[i].cost = p["cost_price"].flatMap { $0.isNull ? nil : $0.string } ?? ""
                }
            }
        }
    }

    private func save() async {
        busy = true; defer { busy = false }
        error = nil
        var body: [String: JSONValue] = ["sku": .string(sku), "is_default": .bool(isDefault), "is_active": .bool(isActive), "prices": variantPricesJSON(prices, canCost: canCost)]
        body["attributes"] = .object(Dictionary(uniqueKeysWithValues: attrs.filter { !$0.0.isEmpty }.map { ($0.0, JSONValue.string($0.1)) }))
        body.put("weight", jnum(weight))
        if existing == nil && autoName { body["auto_name"] = .bool(true) } else { body.put("variant_name", jstr(name)) }
        do {
            let r: APIClient.Response
            if let v = existing, let vid = v["id"]?.int { r = try await env.session.client.put("/v1/admin/products/\(productId)/variants/\(vid)", json: .object(body)) }
            else { r = try await env.session.client.post("/v1/admin/products/\(productId)/variants", json: .object(body)) }
            let waiting = (r.json?["proposals"]?.array ?? []).filter { $0["status"]?.string != "applied" }
            env.toasts.show(r.json?["message"]?.string ?? "Variant saved", tone: waiting.isEmpty ? .success : .info)
            done()
        } catch let e as APIError { error = e } catch { env.toasts.error(error) }
    }
}

/// Define attribute groups, generate every combination, price them, then
/// create each through POST /variants with auto_name (ProductVariant::composeName).
struct VariantGeneratorSheet: View {
    @Environment(AppEnvironment.self) private var env
    let productId: Int
    let productSku: String
    let currencies: [String]
    let canCost: Bool
    let done: () -> Void
    @State private var groups: [(String, String)] = [("Size", ""), ("Colour", "")]
    @State private var step = 0
    @State private var combos: [Combo] = []
    @State private var busy = false
    @State private var progress = ""

    struct Combo: Identifiable { let id = UUID(); var sku: String; let attrs: [(String, String)]; var prices: [VariantPrice] }

    var body: some View {
        BHFormSheet("Generate variants", primary: step == 0 ? "Next: prices" : "Create \(combos.count) variants", busy: busy, disabled: step == 0 ? false : combos.isEmpty, action: { if step == 0 { generate() } else { await create() } }) {
            if step == 0 {
                Text("Values comma-separated. Every combination becomes a variant; SKUs are derived from the product SKU and can be edited next.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                ForEach(groups.indices, id: \.self) { i in
                    HStack {
                        TextField("Attribute", text: Binding(get: { groups[i].0 }, set: { groups[i].0 = $0 })).textFieldStyle(.roundedBorder).frame(width: 120)
                        TextField("S, M, L", text: Binding(get: { groups[i].1 }, set: { groups[i].1 = $0 })).textFieldStyle(.roundedBorder)
                        Button { groups.remove(at: i) } label: { Image(systemName: "minus.circle") }.buttonStyle(.plain).foregroundStyle(Theme.Palette.danger)
                    }
                }
                Button { groups.append(("", "")) } label: { Label("Add attribute", systemImage: "plus") }.font(Theme.Font.secondary)
            } else {
                if !progress.isEmpty { Text(progress).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                ForEach($combos) { $c in
                    VStack(alignment: .leading, spacing: 4) {
                        HStack { Text(c.attrs.map { "\($0.0): \($0.1)" }.joined(separator: " · ")).font(Theme.Font.secondary.weight(.semibold)); Spacer(); TextField("SKU", text: $c.sku).textFieldStyle(.roundedBorder).frame(width: 160) }
                        ForEach($c.prices) { $p in
                            HStack { Text(p.currency).frame(width: 44, alignment: .leading); DecimalField("Regular", text: $p.regular); DecimalField("Sale", text: $p.sale); if canCost && p.currency == "KES" { DecimalField("Cost", text: $p.cost) } }.font(Theme.Font.secondary)
                        }
                    }
                    Divider()
                }
            }
        }
    }

    private func generate() {
        let cleaned = groups.map { (g: (String, String)) -> (String, [String]) in (g.0.trimmingCharacters(in: .whitespaces), g.1.split(separator: ",").map { $0.trimmingCharacters(in: .whitespaces) }.filter { !$0.isEmpty }) }.filter { !$0.0.isEmpty && !$0.1.isEmpty }
        guard !cleaned.isEmpty else { env.toasts.show("Add at least one attribute value to generate variants.", tone: .warning); return }
        var result: [[(String, String)]] = [[]]
        for (name, values) in cleaned { result = result.flatMap { prefix in values.map { prefix + [(name, $0)] } } }
        combos = result.map { attrs in
            let suffix = attrs.map { $0.1.uppercased().filter { $0.isLetter || $0.isNumber }.prefix(4) }.joined(separator: "-")
            return Combo(sku: "\(productSku)-\(suffix)", attrs: attrs, prices: currencies.map { VariantPrice(currency: $0) })
        }
        step = 1
    }

    private func create() async {
        busy = true; defer { busy = false }
        var made = 0
        for (i, c) in combos.enumerated() {
            progress = "Creating \(i + 1) of \(combos.count)…"
            var body: [String: JSONValue] = ["sku": .string(c.sku), "auto_name": .bool(true), "is_default": .bool(false), "is_active": .bool(true), "prices": variantPricesJSON(c.prices, canCost: canCost)]
            body["attributes"] = .object(Dictionary(uniqueKeysWithValues: c.attrs.map { ($0.0, JSONValue.string($0.1)) }))
            do { _ = try await env.session.client.post("/v1/admin/products/\(productId)/variants", json: .object(body)); made += 1 }
            catch { env.toasts.error(error); progress = "Stopped at \(c.sku): fix it and continue."; combos.removeFirst(made); return }
        }
        env.toasts.show("\(made) variant(s) created")
        done()
    }
}

// MARK: - Bill of materials (production.view_bom to read, bom.edit to write)

struct BomTab: View {
    @Environment(AppEnvironment.self) private var env
    let productId: Int
    let product: JSONValue
    @State private var list: Resource<JSONValue>?
    @State private var editing: JSONItem?
    @State private var adding = false
    @State private var feasibilityQty = "1"
    @State private var feasibility: [Int: JSONValue] = [:]

    private var canEdit: Bool { env.session.can("bom.edit") }

    var body: some View {
        SectionCard("Bill of materials") {
            if let list {
                LoadableView(list.state, retry: { list.load() }, isEmpty: { ($0["data"]?.array ?? []).isEmpty }, empty: { EmptyState("No BOM yet", message: canEdit ? "Add the first version: materials and quantities per piece." : "Procurement defines what this product is made of.", symbol: "list.bullet.rectangle", actionLabel: canEdit ? "New BOM version" : nil) { adding = true } }) { json in
                    ForEach(jrows(json["data"])) { bom in bomCard(bom) }
                }
            }
            if canEdit { Button("New BOM version") { adding = true }.font(Theme.Font.secondary) }
        }
        .task { if list == nil { load() } }
        .sheet(isPresented: $adding) { ProductBomEditSheet(productId: productId, existing: nil, variants: jrows(product["variants"])) { adding = false; load() } }
        .sheet(item: $editing) { e in ProductBomEditSheet(productId: productId, existing: e.json, variants: jrows(product["variants"])) { editing = nil; load() } }
    }

    private func load() {
        let c = env.session.client, pid = productId
        if list == nil { list = Resource { try await c.get("/v1/admin/products/\(pid)/bom").json ?? .null } }
        list?.load()
    }

    @ViewBuilder
    private func bomCard(_ bom: JRow) -> some View {
        let bid = bom.int("id") ?? 0
        VStack(alignment: .leading, spacing: Theme.Space.s) {
            HStack {
                Text("Version \(bom.int("version") ?? 0)").font(Theme.Font.secondary.weight(.semibold))
                if bom["is_active"]?.bool == true { StatusBadge("active", tone: .success) }
                if let v = bom["variant"], !v.isNull { Text(v["variant_name"]?.string ?? "").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                Spacer()
                Text("Total \(fmtMoney(bom["total_cost"], currency: "KES")) · \(bom.int("items_count") ?? jrows(bom["items"]).count) items").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            }
            if let n = bom.str("notes"), !n.isEmpty { Text(n).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
            DataTable(columns: [TableColumn("Material", "material_name"), TableColumn("Code", "material_code"), TableColumn("Qty", "quantity", .number(3)), TableColumn("Unit", "unit_of_measure"), TableColumn("Unit cost", "cost_per_unit", .money, fixedCurrency: "KES"), TableColumn("Line cost", "line_cost", .money, fixedCurrency: "KES")],
                      rows: jrows(bom["items"]).map { JRow(id: $0.id, json: flattenItem($0.json)) })
            HStack(spacing: Theme.Space.s) {
                HStack(spacing: 4) { Text("Can we make").font(Theme.Font.caption); DecimalField("1", text: $feasibilityQty).frame(width: 60); Button("Check") { Task { await check(bid) } }.font(Theme.Font.caption) }
                if let f = feasibility[bid] {
                    Text(f["summary"]?.string ?? (f["feasible"]?.bool == true ? "Feasible" : "Short of materials")).font(Theme.Font.caption).foregroundStyle(f["feasible"]?.bool == true ? Theme.Palette.success : Theme.Palette.danger)
                }
                Spacer()
                if canEdit {
                    if bom["is_active"]?.bool != true { Button("Activate") { Task { await activate(bid) } } }
                    Button("Edit") { editing = JSONItem(json: bom.json) }
                    ConfirmButton("Delete BOM version", message: "This version is removed.", destructive: true, action: { await delete(bid) }) { Image(systemName: "trash") }
                }
            }
            .font(Theme.Font.secondary)
            if let f = feasibility[bid], let short = f["shortfalls"]?.array, !short.isEmpty {
                DataTable(columns: [TableColumn("Material", "material_name"), TableColumn("Required", "required", .number(2)), TableColumn("Available", "available", .number(2)), TableColumn("Short", "shortfall", .number(2)), TableColumn("UoM", "uom")], rows: jrows(f["shortfalls"]))
            }
        }
        .padding(Theme.Space.m).background(Theme.field, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
    }

    private func flattenItem(_ j: JSONValue) -> JSONValue {
        var o = j.object ?? [:]
        o["material_name"] = j["material"]?["name"] ?? .null
        o["material_code"] = j["material"]?["code"] ?? .null
        o["cost_per_unit"] = j["material"]?["cost_per_unit"] ?? .null
        return .object(o)
    }

    private func check(_ bid: Int) async {
        if let j = try? await env.session.client.get("/v1/admin/products/\(productId)/bom/\(bid)/feasibility", query: ["quantity": feasibilityQty]).json { feasibility[bid] = j }
    }
    private func activate(_ bid: Int) async {
        do { _ = try await env.session.client.put("/v1/admin/products/\(productId)/bom/\(bid)/activate"); env.toasts.show("BOM activated"); load() } catch { env.toasts.error(error) }
    }
    private func delete(_ bid: Int) async {
        do { _ = try await env.session.client.delete("/v1/admin/products/\(productId)/bom/\(bid)"); env.toasts.show("BOM deleted"); load() } catch { env.toasts.error(error) }
    }
}

struct ProductBomEditSheet: View {
    @Environment(AppEnvironment.self) private var env
    let productId: Int
    let existing: JSONValue?
    let variants: [JRow]
    let done: () -> Void
    @State private var notes = ""
    @State private var variantId: Int? = nil
    @State private var items: [Item] = []
    @State private var search = ""
    @State private var results: [JRow] = []
    @State private var error: APIError?
    @State private var busy = false

    struct Item: Identifiable { let id = UUID(); let materialId: Int; let name: String; var quantity: String; var uom: String; var notes: String }

    var body: some View {
        BHFormSheet(existing == nil ? "New BOM version" : "Edit BOM version \(existing?["version"]?.int ?? 0)", primary: "Save", busy: busy, disabled: items.isEmpty || items.contains { decimal($0.quantity) == nil }, action: save) {
            if existing == nil && !variants.isEmpty {
                FieldBox("For a variant (optional)") { Picker("", selection: $variantId) { Text("Whole product").tag(Int?.none); ForEach(variants) { v in if let id = v.int("id") { Text(v.str("variant_name") ?? "").tag(Int?.some(id)) } } }.labelsHidden() }
            }
            FieldBox("Notes") { TextField("", text: $notes).textFieldStyle(.roundedBorder) }
            Text("Materials per piece").font(Theme.Font.secondary.weight(.semibold))
            ForEach($items) { $it in
                HStack { Text(it.name).font(Theme.Font.secondary).frame(minWidth: 140, alignment: .leading); DecimalField("Qty", text: $it.quantity).frame(width: 80); TextField("UoM", text: $it.uom).textFieldStyle(.roundedBorder).frame(width: 70); TextField("Note", text: $it.notes).textFieldStyle(.roundedBorder); Button { items.removeAll { $0.id == it.id } } label: { Image(systemName: "minus.circle") }.buttonStyle(.plain).foregroundStyle(Theme.Palette.danger) }
            }
            FieldBox("Add a material (search raw materials)") { TextField("Name or code", text: $search).textFieldStyle(.roundedBorder) }
            ForEach(results) { m in
                Button { add(m) } label: { HStack { Text(m.str("name") ?? ""); Text(m.str("code") ?? "").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary); Spacer(); Text(m.str("unit_of_measure") ?? "").font(Theme.Font.caption) } }.buttonStyle(.plain).font(Theme.Font.secondary)
            }
            if let e = error { Text(e.firstFieldError ?? e.message).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
        }
        .onAppear {
            guard let b = existing else { return }
            notes = b["notes"]?.string ?? ""
            items = (b["items"]?.array ?? []).compactMap { i in
                guard let mid = i["material_id"]?.int else { return nil }
                return Item(materialId: mid, name: i["material"]?["name"]?.string ?? "Material \(mid)", quantity: i["quantity"]?.string ?? "", uom: i["unit_of_measure"]?.string ?? "", notes: i["notes"]?.string ?? "")
            }
        }
        .onChange(of: search) { _, q in Task { await find(q) } }
    }

    private func add(_ m: JRow) {
        guard let id = m.int("id") else { return }
        items.append(Item(materialId: id, name: m.str("name") ?? "Material", quantity: "1", uom: m.str("unit_of_measure") ?? "", notes: ""))
        search = ""; results = []
    }

    private func find(_ q: String) async {
        guard q.count >= 2 else { results = []; return }
        if let r = try? await env.session.client.get("/v1/admin/inventory/materials", query: ["search": q, "per_page": "30"], background: true) { results = jrows(r.json?["data"]) }
    }

    private func save() async {
        busy = true; defer { busy = false }
        error = nil
        var body: [String: JSONValue] = ["items": .array(items.map { .object(["material_id": .number(Double($0.materialId)), "quantity": jnum($0.quantity) ?? .number(0), "unit_of_measure": .string($0.uom), "notes": jstr($0.notes) ?? .null]) })]
        body.put("notes", jstr(notes))
        do {
            if let b = existing, let bid = b["id"]?.int { _ = try await env.session.client.put("/v1/admin/products/\(productId)/bom/\(bid)", json: .object(body)) }
            else { body.put("product_variant_id", variantId.map { .number(Double($0)) }); _ = try await env.session.client.post("/v1/admin/products/\(productId)/bom", json: .object(body)) }
            env.toasts.show("BOM saved"); done()
        } catch let e as APIError { error = e } catch { env.toasts.error(error) }
    }
}
