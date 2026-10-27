import SwiftUI
import BethanyKit

/// Categories (CategoriesPage.tsx): the tree from GET /categories?tree=true,
/// create/edit (products.edit), delete (products.delete), subcategory,
/// move up/down among siblings (PUT /categories/reorder), image upload and
/// removal, active toggle.
struct CategoriesView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var tree: Resource<JSONValue>?
    @State private var search = ""
    @State private var editing: CategoryEdit?
    @State private var expanded: Set<Int> = []

    struct CategoryEdit: Identifiable { let existing: JSONValue?; let parentId: Int?; var id: String { "\(existing?["id"]?.int ?? 0)-\(parentId ?? 0)" } }

    var body: some View {
        Page("Categories", subtitle: tree?.value.map { t in "\(t["stats"]?["total"]?.int ?? 0) categories · \(t["stats"]?["active"]?.int ?? 0) active · \(t["stats"]?["root"]?.int ?? 0) top level" }) {
            TextField("Search", text: $search).textFieldStyle(.roundedBorder).frame(maxWidth: 280)
            if let tree {
                LoadableView(tree.state, retry: { tree.load() }, isEmpty: { ($0["data"]?.array ?? []).isEmpty }, empty: { EmptyState("No categories yet", symbol: "square.stack.3d.up", actionLabel: env.session.can("products.edit") ? "New category" : nil) { editing = CategoryEdit(existing: nil, parentId: nil) } }) { json in
                    SectionCard {
                        let roots = jrows(json["data"])
                        ForEach(roots) { c in node(c, siblings: roots, depth: 0) }
                    }
                }
            }
        }
        .toolbar {
            ToolbarItemGroup(placement: .primaryAction) {
                if env.session.can("products.edit") { Button { editing = CategoryEdit(existing: nil, parentId: nil) } label: { Label("New category", systemImage: "plus") } }
            }
        }
        .task { if tree == nil { load() } }
        .refreshable { await tree?.reload() }
        .sheet(item: $editing) { e in CategoryEditSheet(existing: e.existing, parentId: e.parentId, parents: flat()) { editing = nil; load() } }
    }

    private func load() {
        let c = env.session.client
        if tree == nil { tree = Resource { try await c.get("/v1/admin/categories", query: ["tree": "true"]).json ?? .null } }
        tree?.load()
    }

    private func flat() -> [(Int, String)] {
        var out: [(Int, String)] = []
        func walk(_ rows: [JRow], depth: Int) { for c in rows { if let id = c.int("id") { out.append((id, String(repeating: "  ", count: depth) + (c.str("name_en") ?? ""))) }; walk(jrows(c["children"]), depth: depth + 1) } }
        walk(jrows(tree?.value?["data"]), depth: 0)
        return out
    }

    private func matches(_ c: JRow) -> Bool {
        let q = search.trimmingCharacters(in: .whitespaces).lowercased()
        if q.isEmpty { return true }
        if (c.str("name_en") ?? "").lowercased().contains(q) || (c.str("slug") ?? "").lowercased().contains(q) { return true }
        return jrows(c["children"]).contains { matches($0) }
    }

    private func node(_ c: JRow, siblings: [JRow], depth: Int) -> AnyView {
        guard matches(c), let id = c.int("id") else { return AnyView(EmptyView()) }
        let children = jrows(c["children"])
        let index = siblings.firstIndex { $0.int("id") == id } ?? 0
        let canEdit = env.session.can("products.edit")
        return AnyView(VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: Theme.Space.s) {
                if children.isEmpty {
                    Image(systemName: "circle.fill").font(.system(size: 5)).foregroundStyle(Theme.textTertiary).frame(width: 20)
                } else {
                    Button { if expanded.contains(id) { expanded.remove(id) } else { expanded.insert(id) } } label: { Image(systemName: expanded.contains(id) || !search.isEmpty ? "chevron.down" : "chevron.right").frame(width: 20) }.buttonStyle(.plain)
                }
                RemoteImage(url: c.str("image_url"), size: 36)
                VStack(alignment: .leading, spacing: 2) {
                    HStack { Text(c.str("name_en") ?? "").font(Theme.Font.body); if c["is_active"]?.bool == false { StatusBadge("inactive", tone: .neutral) }; if c["featured"]?.bool == true { StatusBadge("featured", tone: .accent) } }
                    Text("\(c.str("slug") ?? "") · \(c.int("products_count") ?? 0) products\(c["show_in_menu"]?.bool == false ? " · hidden from menu" : "")\(c["show_in_storefront"]?.bool == false ? " · hidden from storefront" : "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                }
                Spacer()
                if canEdit {
                    Button { Task { await move(id, siblings: siblings, from: index, to: index - 1) } } label: { Image(systemName: "arrow.up") }.disabled(index == 0).help("Move up")
                    Button { Task { await move(id, siblings: siblings, from: index, to: index + 1) } } label: { Image(systemName: "arrow.down") }.disabled(index >= siblings.count - 1).help("Move down")
                    AttachmentPicker(label: "Image", types: [.image]) { files in if let f = files.first { Task { await uploadImage(id, f) } } }
                        .labelStyle(.iconOnly).help("Set image")
                    Menu {
                        Button("Edit") { editing = CategoryEdit(existing: c.json, parentId: nil) }
                        Button("Add subcategory") { editing = CategoryEdit(existing: nil, parentId: id) }
                        Button(c["is_active"]?.bool == false ? "Activate" : "Deactivate") { Task { await toggle(id) } }
                        if c.str("image_url") != nil { Button("Remove image") { Task { await deleteImage(id) } } }
                        if env.session.can("products.delete") {
                            Button("Delete", role: .destructive) { Task { await delete(id) } }
                        }
                    } label: { Image(systemName: "ellipsis.circle") }
                }
            }
            .buttonStyle(.plain)
            .font(Theme.Font.secondary)
            .padding(.leading, CGFloat(depth) * 24).padding(.vertical, 6)
            Divider()
            if expanded.contains(id) || !search.isEmpty {
                ForEach(children) { child in node(child, siblings: children, depth: depth + 1) }
            }
        })
    }

    private func move(_ id: Int, siblings: [JRow], from: Int, to: Int) async {
        guard siblings.indices.contains(to) else { return }
        var order = siblings.compactMap { $0.int("id") }
        order.swapAt(from, to)
        let items: [JSONValue] = order.enumerated().map { .object(["id": .number(Double($0.element)), "sort_order": .number(Double($0.offset + 1))]) }
        do { _ = try await env.session.client.put("/v1/admin/categories/reorder", json: .object(["categories": .array(items)])); load() } catch { env.toasts.error(error) }
    }

    private func toggle(_ id: Int) async {
        do { let r = try await env.session.client.put("/v1/admin/categories/\(id)/toggle"); env.toasts.show(r.json?["message"]?.string ?? "Updated"); load() } catch { env.toasts.error(error) }
    }
    private func delete(_ id: Int) async {
        do { let r = try await env.session.client.delete("/v1/admin/categories/\(id)"); env.toasts.show(r.json?["message"]?.string ?? "Deleted"); load() } catch { env.toasts.error(error) }
    }
    private func uploadImage(_ id: Int, _ f: UploadFile) async {
        do { _ = try await env.session.client.send(APIClient.Request(.post, "/v1/admin/categories/\(id)/image", body: .multipart([f.part(name: "image")]))); env.toasts.show("Image set"); load() } catch { env.toasts.error(error) }
    }
    private func deleteImage(_ id: Int) async {
        do { _ = try await env.session.client.delete("/v1/admin/categories/\(id)/image"); env.toasts.show("Image removed"); load() } catch { env.toasts.error(error) }
    }
}

struct CategoryEditSheet: View {
    @Environment(AppEnvironment.self) private var env
    let existing: JSONValue?
    let parentId: Int?
    let parents: [(Int, String)]
    let done: () -> Void
    @State private var nameEn = ""
    @State private var nameSw = ""
    @State private var nameFr = ""
    @State private var namePt = ""
    @State private var descEn = ""
    @State private var slug = ""
    @State private var parent: Int? = nil
    @State private var icon = ""
    @State private var color = ""
    @State private var active = true
    @State private var inMenu = true
    @State private var inStorefront = true
    @State private var featured = false
    @State private var sortOrder = ""
    @State private var metaTitle = ""
    @State private var metaDescription = ""
    @State private var metaKeywords = ""
    @State private var error: APIError?
    @State private var busy = false

    var body: some View {
        BHFormSheet(existing == nil ? (parentId == nil ? "New category" : "New subcategory") : "Edit category", primary: existing == nil ? "Create" : "Save", busy: busy, disabled: nameEn.trimmingCharacters(in: .whitespaces).isEmpty, action: save) {
            FieldBox("Name (English) *", error: error?.errors["name_en"]?.first) { TextField("", text: $nameEn).textFieldStyle(.roundedBorder) }
            HStack {
                FieldBox("Swahili") { TextField("", text: $nameSw).textFieldStyle(.roundedBorder) }
                FieldBox("French") { TextField("", text: $nameFr).textFieldStyle(.roundedBorder) }
                FieldBox("Portuguese") { TextField("", text: $namePt).textFieldStyle(.roundedBorder) }
            }
            FieldBox("Description (English)") { TextField("", text: $descEn, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
            HStack {
                FieldBox("Slug", error: error?.errors["slug"]?.first) { TextField("Auto from the name", text: $slug).textFieldStyle(.roundedBorder) }
                FieldBox("Parent") { Picker("", selection: $parent) { Text("None (top level)").tag(Int?.none); ForEach(parents.filter { $0.0 != existing?["id"]?.int }, id: \.0) { Text($0.1).tag(Int?.some($0.0)) } }.labelsHidden() }
            }
            HStack {
                FieldBox("Icon") { TextField("", text: $icon).textFieldStyle(.roundedBorder) }
                FieldBox("Colour") { TextField("#hex", text: $color).textFieldStyle(.roundedBorder) }
                FieldBox("Sort order") { TextField("", text: $sortOrder).textFieldStyle(.roundedBorder) }
            }
            HStack { Toggle("Active", isOn: $active); Toggle("In menu", isOn: $inMenu); Toggle("On storefront", isOn: $inStorefront); Toggle("Featured", isOn: $featured) }.font(Theme.Font.secondary)
            FieldBox("Meta title") { TextField("", text: $metaTitle).textFieldStyle(.roundedBorder) }
            FieldBox("Meta description") { TextField("", text: $metaDescription, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...3) }
            FieldBox("Meta keywords") { TextField("", text: $metaKeywords).textFieldStyle(.roundedBorder) }
            if let e = error, e.errors.isEmpty { Text(e.message).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
        }
        .onAppear {
            parent = parentId
            guard let e = existing else { return }
            nameEn = e["name_en"]?.string ?? ""; nameSw = e["name_sw"]?.string ?? ""; nameFr = e["name_fr"]?.string ?? ""; namePt = e["name_pt"]?.string ?? ""
            descEn = e["description_en"]?.string ?? ""; slug = e["slug"]?.string ?? ""; parent = e["parent_id"]?.int
            icon = e["icon"]?.string ?? ""; color = e["color"]?.string ?? ""; sortOrder = e["sort_order"]?.string ?? ""
            active = e["is_active"]?.bool ?? true; inMenu = e["show_in_menu"]?.bool ?? true; inStorefront = e["show_in_storefront"]?.bool ?? true; featured = e["featured"]?.bool ?? false
            metaTitle = e["meta_title"]?.string ?? ""; metaDescription = e["meta_description"]?.string ?? ""; metaKeywords = e["meta_keywords"]?.string ?? ""
        }
    }

    private func save() async {
        busy = true; defer { busy = false }
        error = nil
        var body: [String: JSONValue] = ["name_en": .string(nameEn.trimmingCharacters(in: .whitespaces)), "is_active": .bool(active), "show_in_menu": .bool(inMenu), "show_in_storefront": .bool(inStorefront), "featured": .bool(featured)]
        body.put("name_sw", jstr(nameSw)); body.put("name_fr", jstr(nameFr)); body.put("name_pt", jstr(namePt)); body.put("description_en", jstr(descEn))
        body.put("slug", jstr(slug)); body["parent_id"] = parent.map { .number(Double($0)) } ?? .null
        body.put("icon", jstr(icon)); body.put("color", jstr(color)); body.put("sort_order", Int(sortOrder).map { .number(Double($0)) })
        body.put("meta_title", jstr(metaTitle)); body.put("meta_description", jstr(metaDescription)); body.put("meta_keywords", jstr(metaKeywords))
        do {
            if let e = existing, let id = e["id"]?.int { _ = try await env.session.client.put("/v1/admin/categories/\(id)", json: .object(body)) }
            else { _ = try await env.session.client.post("/v1/admin/categories", json: .object(body)) }
            env.toasts.show("Category saved"); done()
        } catch let e as APIError { error = e } catch { env.toasts.error(error) }
    }
}
