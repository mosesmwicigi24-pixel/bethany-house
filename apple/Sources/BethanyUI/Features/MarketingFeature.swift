import SwiftUI
import Charts
import BethanyKit

/// Marketing (seasons, campaigns), Home Front (homepage blocks, product
/// page sections — all `marketing/banners`) and Storefront Insights
/// (`analytics/overview?days=`). Reads need marketing.view (insights:
/// reports.customers); writes need marketing.manage.
@MainActor
public enum MarketingFeature {
    public static func register() {
        FeatureRegistry.register(.seasons) { _ in AnyView(SeasonsView()) }
        FeatureRegistry.register(.campaigns) { _ in AnyView(CampaignsView()) }
        FeatureRegistry.register(.homeFront) { _ in AnyView(HomeFrontView()) }
        FeatureRegistry.register(.homeFrontProducts) { _ in AnyView(HomeFrontProductsView()) }
        FeatureRegistry.register(.insights) { _ in AnyView(InsightsView()) }
    }
}

// MARK: - Seasons (GET /marketing/seasons → {data, stats}; POST/PUT/DELETE)

struct SeasonsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var version = 0
    @State private var editing: SeasonDraft?
    var body: some View {
        Page("Seasons", subtitle: "Liturgical seasons that dress the storefront") {
            if env.session.can("marketing.manage") { HStack { Spacer(); Button { editing = SeasonDraft() } label: { Label("New season", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) } }
            RemoteJSON("/v1/admin/marketing/seasons", version: version, emptyTitle: "No seasons yet", isEmpty: { ($0["data"]?.array ?? []).isEmpty }) { j, reload in
                let s = j["stats"] ?? .null
                HStack { Pill("\(s["total"]?.int ?? 0) total", .neutral); Pill("\(s["active"]?.int ?? 0) active", .success); Pill("\(s["running"]?.int ?? 0) running now", .info) }
                ForEach(j["data"]?.records ?? []) { r in
                    SupportListRow("\(r.string("name") ?? "") · \(r.string("key") ?? "")", subtitle: "\(r.string("tagline") ?? "") · \(Day.format(r.string("starts_at"), "d MMM yyyy")) – \(Day.format(r.string("ends_at"), "d MMM yyyy")) · priority \(r["priority"]?.string ?? "0")\(r["promotion"]?["name"]?.string.map { " · campaign \($0)" } ?? "")") {
                        HStack {
                            if let a = r["theme"]?["accent"]?.string, let hex = UInt32(a.dropFirst(), radix: 16) { Circle().fill(Color(hex: hex)).frame(width: 14, height: 14) }
                            StatusBadge(r["is_active"]?.bool == true ? "active" : "inactive")
                            if env.session.can("marketing.manage") {
                                Button("Edit") { editing = SeasonDraft(record: r) }
                                ConfirmButton("Delete season", message: "The storefront stops showing it.", destructive: true, action: { do { let x = try await env.session.client.delete("/v1/admin/marketing/seasons/\(r.id)"); toastResult(env, x); reload() } catch { env.toasts.error(error) } }) { Image(systemName: "trash") }
                            }
                        }
                    }
                }
            }
        }
        .sheet(item: $editing) { d in SeasonSheet(draft: d) { version += 1 } }
    }
}

struct SeasonDraft: Identifiable {
    var id: Int?
    var f: [String: String] = ["priority": "0", "sort_order": "0"]
    var theme: [String: String] = [:]
    var active = true
    var promotionId: Int?
    static let text: [(String, String)] = [("key", "Key (slug)"), ("name", "Name"), ("tagline", "Tagline"), ("scripture", "Scripture"), ("starts_at", "Starts (yyyy-mm-dd HH:mm)"), ("ends_at", "Ends"), ("priority", "Priority"), ("sort_order", "Sort order")]
    init() {}
    init(record r: Record) {
        id = r.int("id"); for (k, _) in Self.text { f[k] = r[k]?.string ?? "" }
        for (k, v) in r["theme"]?.object ?? [:] { theme[k] = v.string ?? "" }
        active = r["is_active"]?.bool ?? true; promotionId = r["promotion_id"]?.int
    }
}

struct SeasonSheet: View {
    @Environment(AppEnvironment.self) private var env
    @State var draft: SeasonDraft
    let saved: () -> Void
    @State private var promotions: [Record] = []
    var body: some View {
        EditSheet(draft.id == nil ? "New season" : "Edit season", canSave: !(draft.f["name"] ?? "").isEmpty && !(draft.f["key"] ?? "").isEmpty, save: {
            var pairs: [(String, JSONValue?)] = SeasonDraft.text.map { ($0.0, (["priority", "sort_order"].contains($0.0) ? J.typed(draft.f[$0.0] ?? "") : J.s(draft.f[$0.0])) as JSONValue?) }
            pairs.append(("is_active", .bool(draft.active)))
            pairs.append(("promotion_id", J.i(draft.promotionId)))
            var th: [String: JSONValue] = [:]
            for (k, v) in draft.theme where !v.isEmpty { th[k] = .string(v) }
            pairs.append(("theme", th.isEmpty ? .null : .object(th)))
            let r: APIClient.Response
            if let id = draft.id { r = try await env.session.client.put("/v1/admin/marketing/seasons/\(id)", json: J.obj(pairs)) } else { r = try await env.session.client.post("/v1/admin/marketing/seasons", json: J.obj(pairs)) }
            toastResult(env, r, fallback: "Season saved"); saved()
        }) { e in
            ForEach(SeasonDraft.text, id: \.0) { k in Field(k.1, Binding(get: { draft.f[k.0] ?? "" }, set: { draft.f[k.0] = $0 }), error: e?.errors[k.0]?.first) }
            Text("Theme").font(Theme.Font.sectionTitle)
            ForEach([("accent", "Accent (#hex)"), ("accentInk", "Accent ink"), ("accentSoft", "Accent soft"), ("onAccent", "On accent"), ("liturgical", "Liturgical colour"), ("motif", "Motif")], id: \.0) { k in
                Field(k.1, Binding(get: { draft.theme[k.0] ?? "" }, set: { draft.theme[k.0] = $0 }), mono: k.0 != "motif")
            }
            Picker("Campaign", selection: $draft.promotionId) { Text("None").tag(Int?.none); ForEach(promotions) { p in Text(p.string("name") ?? "").tag(p.int("id")) } }
            ToggleRow("Active", $draft.active)
        }
        .task { promotions = (try? await env.session.client.json("/v1/admin/marketing/promotions"))?["data"]?.records ?? [] }
    }
}

// MARK: - Campaigns (GET /marketing/promotions; POST/PUT/DELETE)

struct CampaignsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var version = 0
    @State private var editing: CampaignDraft?
    var body: some View {
        Page("Campaigns", subtitle: "Blessed Friday and other storefront promotions") {
            if env.session.can("marketing.manage") { HStack { Spacer(); Button { editing = CampaignDraft() } label: { Label("New campaign", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) } }
            RemoteJSON("/v1/admin/marketing/promotions", version: version, emptyTitle: "No campaigns yet", isEmpty: { ($0["data"]?.array ?? []).isEmpty }) { j, reload in
                let s = j["stats"] ?? .null
                HStack { Pill("\(s["total"]?.int ?? 0) total", .neutral); Pill("\(s["active"]?.int ?? 0) active", .success); Pill("\(s["running"]?.int ?? 0) running now", .info) }
                ForEach(j["data"]?.records ?? []) { p in
                    SupportListRow(p.string("name") ?? "", subtitle: "\(p["discount_value"]?.string ?? "")\(p.string("discount_type") == "percentage" ? "% off" : " KES off") · \(Day.format(p.string("starts_at"), "d MMM yyyy")) – \(Day.format(p.string("ends_at"), "d MMM yyyy")) · used \(p["times_used"]?.int ?? 0)\(p["max_uses"]?.int.map { " of \($0)" } ?? "")\(p["is_exclusive"]?.bool == true ? " · exclusive" : "")") {
                        HStack {
                            StatusBadge(p["is_active"]?.bool == true ? "active" : "inactive")
                            if env.session.can("marketing.manage") {
                                Button("Edit") { editing = CampaignDraft(record: p) }
                                ConfirmButton("Delete campaign", message: "It stops applying to new orders.", destructive: true, action: { do { let x = try await env.session.client.delete("/v1/admin/marketing/promotions/\(p.id)"); toastResult(env, x); reload() } catch { env.toasts.error(error) } }) { Image(systemName: "trash") }
                            }
                        }
                    }
                }
            }
        }
        .sheet(item: $editing) { d in CampaignSheet(draft: d) { version += 1 } }
    }
}

struct CampaignDraft: Identifiable {
    var id: Int?
    var name = ""; var description = ""; var type = ""; var discountType = "percentage"; var value = ""; var starts = ""; var ends = ""; var priority = "0"; var exclusive = false; var maxUses = ""; var active = true
    init() {}
    init(record p: Record) { id = p.int("id"); name = p.string("name") ?? ""; description = p.string("description") ?? ""; type = p.string("type") ?? ""; discountType = p.string("discount_type") ?? "percentage"; value = p["discount_value"]?.string ?? ""; starts = p.string("starts_at") ?? ""; ends = p.string("ends_at") ?? ""; priority = p["priority"]?.string ?? "0"; exclusive = p["is_exclusive"]?.bool ?? false; maxUses = p["max_uses"]?.string ?? ""; active = p["is_active"]?.bool ?? true }
}

struct CampaignSheet: View {
    @Environment(AppEnvironment.self) private var env
    @State var draft: CampaignDraft
    let saved: () -> Void
    var body: some View {
        EditSheet(draft.id == nil ? "New campaign" : "Edit campaign", canSave: !draft.name.isEmpty && !draft.value.isEmpty, save: {
            let body = J.obj([("name", .string(draft.name)), ("description", J.s(draft.description)), ("type", J.s(draft.type)), ("discount_type", .string(draft.discountType)), ("discount_value", J.typed(draft.value)), ("is_active", .bool(draft.active)), ("starts_at", J.s(draft.starts)), ("ends_at", J.s(draft.ends)), ("priority", J.typed(draft.priority)), ("is_exclusive", .bool(draft.exclusive)), ("max_uses", J.typed(draft.maxUses))])
            let r: APIClient.Response
            if let id = draft.id { r = try await env.session.client.put("/v1/admin/marketing/promotions/\(id)", json: body) } else { r = try await env.session.client.post("/v1/admin/marketing/promotions", json: body) }
            toastResult(env, r, fallback: "Campaign saved"); saved()
        }) { e in
            Field("Name", $draft.name, error: e?.errors["name"]?.first)
            Field("Description", $draft.description, multiline: true)
            Field("Type", $draft.type, placeholder: "blessed_friday")
            HStack { OptionPicker("Discount", $draft.discountType, options: [("percentage", "Percentage (%)"), ("fixed", "Fixed (KES)")]); Field("Value", $draft.value, error: e?.errors["discount_value"]?.first) }
            HStack { Field("Starts (yyyy-mm-dd HH:mm)", $draft.starts, error: e?.errors["starts_at"]?.first); Field("Ends", $draft.ends, error: e?.errors["ends_at"]?.first) }
            HStack { Field("Priority", $draft.priority); Field("Max uses (blank = unlimited)", $draft.maxUses) }
            ToggleRow("Exclusive (not combined with others)", $draft.exclusive)
            ToggleRow("Active", $draft.active)
        }
    }
}

// MARK: - Home Front (banners by position; POST /banners/{id}/image multipart image+field)

struct HomeSlot: Identifiable { let key: String; let placement: String; let label: String; let hint: String; var id: String { key } }

let homeSlots: [HomeSlot] = [
    HomeSlot(key: "home_hero", placement: "homepage", label: "Hero Slider", hint: "Full-width rotating slides at the very top — order = slide 1, 2, 3…"),
    HomeSlot(key: "home_promo", placement: "homepage", label: "Promo Strips", hint: "The two mid-page offer bands."),
    HomeSlot(key: "shop_hero", placement: "shop", label: "Shop Page Hero", hint: "The banner across the top of the /shop page."),
    HomeSlot(key: "home_category", placement: "homepage", label: "Category Tiles", hint: "The “Shop by category” cards."),
    HomeSlot(key: "home_testimonial", placement: "homepage", label: "Testimonials", hint: "The “Loved at the altar” quotes."),
    HomeSlot(key: "home_pillar", placement: "homepage", label: "Why Bethany Pillars", hint: "The four value pillars."),
    HomeSlot(key: "home_newsletter", placement: "homepage", label: "Newsletter", hint: "The “Grace in every detail” sign-up block."),
]

let productSlots: [HomeSlot] = [
    HomeSlot(key: "product_highlight", placement: "", label: "Highlights", hint: "Skimmable auto-advancing cards near the top — one claim + image each."),
    HomeSlot(key: "product_poster", placement: "", label: "Poster", hint: "The editorial banner — headline + spec strip + image. One per product."),
    HomeSlot(key: "product_feature", placement: "", label: "Take a closer look", hint: "Interactive feature cards, each with its own image."),
    HomeSlot(key: "product_chapter", placement: "", label: "Story chapters", hint: "Cinematic editorial sections — eyebrow + headline + copy over an image."),
    HomeSlot(key: "product_pillar", placement: "", label: "Best place to buy", hint: "The value cards below the story (icon + title + text)."),
]

struct HomeFrontView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var version = 0
    @State private var editing: BannerDraft?
    var body: some View {
        Page("Home page", subtitle: "Blocks on the storefront; empty slots keep the built-in content") {
            RemoteJSON("/v1/admin/marketing/banners", version: version) { j, reload in
                let all = j["data"]?.records ?? []
                ForEach(homeSlots) { slot in
                    BannerSlotCard(slot: slot, banners: all.filter { $0.string("position") == slot.key }, placement: slot.placement, reload: reload) { editing = $0 }
                }
            }
        }
        .sheet(item: $editing) { d in BannerSheet(draft: d, slots: homeSlots) { version += 1 } }
    }
}

/// Product pages: one banner set per product, keyed by placement `product:<slug>` (GET /products?per_page=300 for the picker).
struct HomeFrontProductsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var products: [Record] = []
    @State private var slug: String?
    @State private var version = 0
    @State private var editing: BannerDraft?
    var body: some View {
        Page("Product pages", subtitle: "Editorial sections per product") {
            Picker("Product", selection: $slug) {
                Text("Choose a product…").tag(String?.none)
                ForEach(products) { p in Text(p.string("name") ?? p["translations"]?.array?.first?["name"]?.string ?? p.string("sku") ?? "").tag(p.string("slug")) }
            }
            if let slug {
                let placement = "product:\(slug)"
                RemoteJSON("/v1/admin/marketing/banners", query: ["placement": placement], version: version) { j, reload in
                    let all = j["data"]?.records ?? []
                    ForEach(productSlots) { slot in
                        BannerSlotCard(slot: slot, banners: all.filter { $0.string("position") == slot.key }, placement: placement, reload: reload) { editing = $0 }
                    }
                }
            }
        }
        .task { products = (try? await env.session.client.json("/v1/admin/products", query: ["per_page": "300"]))?["data"]?.records ?? [] }
        .sheet(item: $editing) { d in BannerSheet(draft: d, slots: productSlots) { version += 1 } }
    }
}

struct BannerSlotCard: View {
    @Environment(AppEnvironment.self) private var env
    let slot: HomeSlot
    let banners: [Record]
    let placement: String
    let reload: () -> Void
    let edit: (BannerDraft) -> Void
    var body: some View {
        SectionCard(slot.label) {
            Text(slot.hint).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            ForEach(banners.sorted { ($0.int("sort_order") ?? 0) < ($1.int("sort_order") ?? 0) }) { b in
                HStack(alignment: .top, spacing: Theme.Space.m) {
                    if let img = b.string("image_url"), !img.isEmpty { SignedImage(source: img, maxHeight: 60).frame(width: 90) }
                    VStack(alignment: .leading, spacing: 2) {
                        Text("\(b.int("sort_order") ?? 0). \(b.string("title") ?? "(no title)")").font(Theme.Font.body)
                        if let s = b.string("subtitle") { Text(s).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).lineLimit(2) }
                        if let l = b.string("link_url") { Text("\(b.string("link_text") ?? "Link") → \(l)").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                    }
                    Spacer()
                    StatusBadge(b["is_active"]?.bool == true ? "active" : "inactive")
                    if env.session.can("marketing.manage") {
                        Button("Edit") { edit(BannerDraft(record: b, placement: placement)) }
                        Button { var d = BannerDraft(record: b, placement: placement); d.id = nil; d.sortOrder = String(banners.count + 1); edit(d) } label: { Image(systemName: "doc.on.doc") }
                        AsyncButton(role: .destructive) { let r = try await env.session.client.delete("/v1/admin/marketing/banners/\(b.id)"); toastResult(env, r); reload() } label: { Image(systemName: "trash") }
                    }
                }
            }
            if env.session.can("marketing.manage") {
                Button { edit(BannerDraft(position: slot.key, placement: placement, sortOrder: banners.count + 1)) } label: { Label("Add", systemImage: "plus") }
            }
        }
    }
}

struct BannerDraft: Identifiable {
    var id: Int?
    var position: String
    var placement: String
    var sortOrder: String
    var title = ""; var subtitle = ""; var linkUrl = ""; var linkText = ""; var eyebrow = ""; var theme = ""; var active = true; var openNew = false
    var styles: JSONValue = .object([:])
    var image: LocalFile?
    init(position: String, placement: String, sortOrder: Int) { self.position = position; self.placement = placement; self.sortOrder = String(sortOrder) }
    init(record b: Record, placement: String) {
        id = b.int("id"); position = b.string("position") ?? ""; self.placement = b.string("placement") ?? placement; sortOrder = b["sort_order"]?.string ?? "0"
        title = b.string("title") ?? ""; subtitle = b.string("subtitle") ?? ""; linkUrl = b.string("link_url") ?? ""; linkText = b.string("link_text") ?? ""
        styles = b["styles"] ?? .object([:]); eyebrow = styles["eyebrow"]?.string ?? styles["icon"]?.string ?? ""; theme = styles["theme"]?.string ?? ""
        active = b["is_active"]?.bool ?? true; openNew = b["open_in_new_tab"]?.bool ?? false
    }
}

/// POST/PUT /marketing/banners, then POST /banners/{id}/image {image, field}.
/// Rich style keys the form does not edit are preserved, as the console does.
struct BannerSheet: View {
    @Environment(AppEnvironment.self) private var env
    @State var draft: BannerDraft
    let slots: [HomeSlot]
    let saved: () -> Void
    var body: some View {
        EditSheet(draft.id == nil ? "Add block" : "Edit block", save: {
            var st = draft.styles.object ?? [:]
            let isProduct = draft.placement.hasPrefix("product:")
            if isProduct { st["eyebrow"] = J.sOpt(draft.eyebrow); st["icon"] = J.sOpt(draft.eyebrow) } else { st["eyebrow"] = J.sOpt(draft.eyebrow); st["theme"] = J.sOpt(draft.theme) }
            let body = J.obj([("position", .string(draft.position)), ("placement", .string(draft.placement)), ("sort_order", J.typed(draft.sortOrder)), ("title", J.s(draft.title)), ("subtitle", J.s(draft.subtitle)),
                ("link_url", isProduct ? nil : J.s(draft.linkUrl)), ("link_text", isProduct ? nil : J.s(draft.linkText)), ("is_active", .bool(draft.active)), ("open_in_new_tab", isProduct ? nil : .bool(draft.openNew)), ("styles", .object(st))])
            let r: APIClient.Response
            if let id = draft.id { r = try await env.session.client.put("/v1/admin/marketing/banners/\(id)", json: body) } else { r = try await env.session.client.post("/v1/admin/marketing/banners", json: body) }
            if let img = draft.image, let id = r.json?["data"]?["id"]?.int {
                _ = try await env.session.client.upload("/v1/admin/marketing/banners/\(id)/image", parts: [img.part(named: "image"), APIClient.MultipartPart(name: "field", value: "image_url")])
            }
            env.toasts.show("Block saved"); saved()
        }) { e in
            OptionPicker("Slot", $draft.position, options: slots.map { ($0.key, $0.label) })
            Field("Position (order, 1 = first)", $draft.sortOrder, error: e?.errors["sort_order"]?.first)
            Field(draft.position == "product_pillar" ? "Icon (emoji)" : "Eyebrow", $draft.eyebrow)
            Field("Headline / title", $draft.title, error: e?.errors["title"]?.first)
            Field("Text / subtitle", $draft.subtitle, multiline: true)
            if !draft.placement.hasPrefix("product:") {
                Field("Link URL", $draft.linkUrl); Field("Link text", $draft.linkText); Field("Theme", $draft.theme)
                ToggleRow("Open link in a new tab", $draft.openNew)
            }
            HStack { AttachFileButton(label: draft.image?.name ?? "Choose image (jpeg/png/webp ≤5MB)") { draft.image = $0 } }
            ToggleRow("Active", $draft.active)
        }
    }
}

// MARK: - Insights (GET /analytics/overview?days=)

struct InsightsView: View {
    @State private var days = "30"
    var body: some View {
        Page("Storefront insights", subtitle: "Visitors and buyers by country and device — no IP is ever stored") {
            OptionPicker("Range", $days, options: [("7", "Last 7 days"), ("30", "Last 30 days"), ("90", "Last 90 days"), ("365", "Last year")])
            RemoteJSON("/v1/admin/analytics/overview", query: ["days": days]) { j, _ in
                let t = j["totals"] ?? .null
                TileGrid(minWidth: 150) {
                    StatTile("Visits", value: t["visits"]?.string ?? "0")
                    StatTile("Online orders", value: t["orders"]?.string ?? "0")
                    StatTile("Countries", value: t["countries"]?.string ?? "0")
                    StatTile("Mobile share", value: "\(t["mobile_share"]?.string ?? "0")%")
                }
                let markets = j["markets"]?.records ?? []
                SectionCard("Visits by country") {
                    Chart(markets.prefix(12)) { m in
                        BarMark(x: .value("Visits", m.int("visits") ?? 0), y: .value("Country", countryName(m.string("country_code"))))
                            .foregroundStyle(Theme.Palette.brand)
                    }
                    .frame(height: CGFloat(max(120, min(12, markets.count) * 26)))
                }
                SectionCard("Markets") {
                    Grid(alignment: .leading, horizontalSpacing: 12, verticalSpacing: 6) {
                        GridRow { Text("Country"); Text("Visits"); Text("Orders"); Text("Revenue"); Text("Conversion") }.font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        ForEach(markets) { m in
                            GridRow {
                                Text(countryName(m.string("country_code"))).font(Theme.Font.secondary)
                                Text(m["visits"]?.string ?? "0").monospacedDigit()
                                Text(m["orders"]?.string ?? "0").monospacedDigit()
                                Text(m["revenue"]?.string ?? "0").monospacedDigit()
                                Text(m["conversion_pct"]?.isNull == false ? "\(m["conversion_pct"]?.string ?? "")%" : "n/a").monospacedDigit()
                            }
                            .font(Theme.Font.secondary)
                        }
                    }
                    Text("Revenue is the server's figure in its reporting currency; conversion is n/a where no visits were recorded.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                }
                HStack(alignment: .top, spacing: Theme.Space.m) {
                    SectionCard("Devices") { ForEach(j["devices"]?.records ?? []) { d in HStack { Text(d.string("device_type")?.humanized ?? "—"); Spacer(); Text(d["visits"]?.string ?? "0").monospacedDigit() }.font(Theme.Font.secondary) } }
                    SectionCard("Operating systems") { ForEach(j["os"]?.records ?? []) { d in HStack { Text(d.string("os") ?? "—"); Spacer(); Text(d["visits"]?.string ?? "0").monospacedDigit() }.font(Theme.Font.secondary) } }
                }
            }
        }
    }

    private func countryName(_ code: String?) -> String {
        guard let code else { return "Unknown" }
        return Locale(identifier: "en_KE").localizedString(forRegionCode: code) ?? code
    }
}
