import SwiftUI
import BethanyKit

/// Reference data: countries, currencies, languages, tax rates and the
/// discount limits. The two currency rates are separate columns and the
/// server turns a rate change into a proposal (Phase 3C); the screen shows
/// the server's answer and never converts money itself.

// MARK: - Countries (GET /countries → {data, grouped, stats}; POST; PUT /{code}; PUT /{code}/toggle)

struct CountriesView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var version = 0
    @State private var editing: CountryDraft?
    @State private var filter = ""
    private var canEdit: Bool { env.session.permissions.canAny(["settings.edit", "setup.technical"]) }

    var body: some View {
        Page("Countries", subtitle: "Where we sell and ship") {
            HStack {
                TextField("Filter", text: $filter).textFieldStyle(.roundedBorder)
                if canEdit { Button { editing = CountryDraft() } label: { Label("Add country", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
            }
            RemoteJSON("/v1/admin/countries", version: version) { j, reload in
                let s = j["stats"] ?? .null
                HStack { Pill("\(s["total"]?.int ?? 0) countries", .neutral); Pill("\(s["active"]?.int ?? 0) active", .success); Pill("\(s["shipping_enabled"]?.int ?? 0) ship", .info) }
                let rows = (j["data"]?.records ?? []).filter { filter.isEmpty || ($0.string("name") ?? "").localizedCaseInsensitiveContains(filter) || ($0.string("code") ?? "").localizedCaseInsensitiveContains(filter) }
                ForEach(rows) { c in
                    SupportListRow("\(c.string("flag") ?? "") \(c.string("name") ?? "") (\(c.string("code") ?? ""))", subtitle: "\(c.string("region") ?? "") · \(c.string("default_currency_code") ?? "—") · +\(c.string("phone_code") ?? "")\(c["is_shipping_enabled"]?.bool == true ? " · ships (std \(c["standard_shipping_cost"]?.string ?? "—"), express \(c["express_shipping_cost"]?.string ?? "—"), free over \(c["free_shipping_threshold"]?.string ?? "—"), \(c["estimated_delivery_days"]?.string ?? "—") days)" : "")") {
                        HStack {
                            StatusBadge(c["is_active"]?.bool == true ? "active" : "inactive")
                            if canEdit {
                                Button("Edit") { editing = CountryDraft(record: c) }
                                AsyncButton { let r = try await env.session.client.put("/v1/admin/countries/\(c.string("code") ?? "")/toggle"); toastResult(env, r); reload() } label: { Image(systemName: "power") }
                            }
                        }
                    }
                }
            }
        }
        .sheet(item: $editing) { d in CountrySheet(draft: d) { version += 1 } }
    }
}

struct CountryDraft: Identifiable {
    var id: String?
    var f: [String: String] = [:]
    var active = true
    var shipping = false
    static let text: [(String, String)] = [("name", "Name"), ("native_name", "Native name"), ("phone_code", "Phone code"), ("flag", "Flag emoji"), ("region", "Region"), ("subregion", "Subregion"), ("default_currency_code", "Default currency"), ("free_shipping_threshold", "Free shipping over"), ("standard_shipping_cost", "Standard shipping cost"), ("express_shipping_cost", "Express shipping cost"), ("estimated_delivery_days", "Delivery days")]
    init() {}
    init(record c: Record) { id = c.string("code"); for (k, _) in Self.text { f[k] = c[k]?.string ?? "" }; active = c["is_active"]?.bool ?? true; shipping = c["is_shipping_enabled"]?.bool ?? false }
}

struct CountrySheet: View {
    @Environment(AppEnvironment.self) private var env
    @State var draft: CountryDraft
    @State private var code = ""
    let saved: () -> Void
    var body: some View {
        EditSheet(draft.id == nil ? "Add country" : "Edit \(draft.id ?? "")", canSave: !(draft.f["name"] ?? "").isEmpty && (draft.id != nil || code.count >= 2), save: {
            var pairs: [(String, JSONValue?)] = CountryDraft.text.map { ($0.0, (["free_shipping_threshold", "standard_shipping_cost", "express_shipping_cost", "estimated_delivery_days"].contains($0.0) ? J.typed(draft.f[$0.0] ?? "") : J.s(draft.f[$0.0])) as JSONValue?) }
            pairs.append(("is_active", .bool(draft.active))); pairs.append(("is_shipping_enabled", .bool(draft.shipping)))
            let r: APIClient.Response
            if let c = draft.id { r = try await env.session.client.put("/v1/admin/countries/\(c)", json: J.obj(pairs)) }
            else { pairs.append(("code", .string(code.uppercased()))); r = try await env.session.client.post("/v1/admin/countries", json: J.obj(pairs)) }
            toastResult(env, r); saved()
        }) { e in
            if draft.id == nil { Field("ISO code", $code, placeholder: "KE", error: e?.errors["code"]?.first) }
            ForEach(CountryDraft.text, id: \.0) { k in Field(k.1, Binding(get: { draft.f[k.0] ?? "" }, set: { draft.f[k.0] = $0 }), error: e?.errors[k.0]?.first) }
            ToggleRow("Active", $draft.active)
            ToggleRow("Shipping enabled", $draft.shipping)
        }
    }
}

// MARK: - Currencies (GET /currencies-management; PUT /{id} with rates → proposals)

struct CurrenciesView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var version = 0
    @State private var editing: CurrencyDraft?

    var body: some View {
        Page("Currencies", subtitle: "Pricing rate (what customers are quoted) and reporting rate (what reports convert at) are separate") {
            if env.session.can("settings.edit") { HStack { Spacer(); Button { editing = CurrencyDraft() } label: { Label("Add currency", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) } }
            RemoteJSON("/v1/admin/currencies-management", version: version) { j, reload in
                ForEach(j["data"]?.records ?? []) { c in
                    SectionCard {
                        HStack(alignment: .top) {
                            VStack(alignment: .leading, spacing: 3) {
                                HStack { Text("\(c.string("code") ?? "") · \(c.string("name") ?? "")").font(Theme.Font.sectionTitle); if c["is_default"]?.bool == true || c["is_base"]?.bool == true { Pill("base", .info) }; StatusBadge(c["is_active"]?.bool == true ? "active" : "inactive") }
                                Text("Pricing rate (base-relative): \(c["exchange_rate"]?.string ?? "—") · Reporting rate: \(c["reporting_rate_to_kes"]?.string.map { "KES \($0) per 1" } ?? "not set — reported apart")").font(Theme.Font.secondary).foregroundStyle(c["reporting_rate_to_kes"]?.isNull == false ? Theme.textSecondary : Theme.Palette.warning)
                                Text("Symbol \(c.string("symbol") ?? "") (\(c.string("symbol_position") ?? "before")) · \(c["decimal_places"]?.string ?? "2") decimals").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                            }
                            Spacer()
                            VStack {
                                if env.session.permissions.canAny(["settings.edit", "settings.financial_propose", "settings.pricing_rate_propose"]) { Button("Edit") { editing = CurrencyDraft(record: c) } }
                                if env.session.can("settings.edit") {
                                    AsyncButton("Toggle") { let r = try await env.session.client.put("/v1/admin/currencies-management/\(c.id)/toggle"); toastResult(env, r); reload() }
                                    if c["is_default"]?.bool != true { AsyncButton("Make default") { let r = try await env.session.client.put("/v1/admin/currencies-management/\(c.id)/set-default"); toastResult(env, r); reload() } }
                                    ConfirmButton("Delete currency", message: "Only a currency with no sales can go.", destructive: true, action: { do { let r = try await env.session.client.delete("/v1/admin/currencies-management/\(c.id)"); toastResult(env, r); reload() } catch { env.toasts.error(error) } }) { Text("Delete").foregroundStyle(Theme.Palette.danger) }
                                }
                            }
                        }
                    }
                }
            }
        }
        .sheet(item: $editing) { d in SetupCurrencySheet(draft: d) { version += 1 } }
    }
}

struct CurrencyDraft: Identifiable {
    var id: Int?
    var code = ""
    var name = ""
    var symbol = ""
    var storedRate: Double?
    /// The form speaks "KES per 1 unit", as the console does; the API keeps the inverse.
    var kesPerUnit = ""
    var reportingRate = ""
    var decimals = "2"
    var thousands = ","
    var decimalSep = "."
    var position = "before"
    var isDefault = false
    var active = true
    var effectiveFrom = ""
    init() {}
    init(record c: Record) {
        id = c.int("id"); code = c.string("code") ?? ""; name = c.string("name") ?? ""; symbol = c.string("symbol") ?? ""
        storedRate = c["exchange_rate"]?.double
        if let r = storedRate, r > 0 { kesPerUnit = String(format: "%.2f", (1 / r * 100).rounded() / 100) } else { kesPerUnit = "1" }
        reportingRate = c["reporting_rate_to_kes"]?.string ?? ""
        decimals = c["decimal_places"]?.string ?? "2"; thousands = c.string("thousand_separator") ?? ","; decimalSep = c.string("decimal_separator") ?? "."
        position = c.string("symbol_position") ?? "before"; isDefault = c["is_default"]?.bool ?? false; active = c["is_active"]?.bool ?? true
    }
    /// Mirrors CurrenciesPage.toPayload: an untouched figure sends the stored rate back exactly, so a 2-decimal round trip never reads as a rate change.
    var exchangeRatePayload: JSONValue {
        let typed = Double(kesPerUnit) ?? 0
        if let r = storedRate, r > 0, typed == (1 / r * 100).rounded() / 100 { return .number(r) }
        guard typed > 0 else { return .null }
        return .number((1 / typed * 1_000_000).rounded() / 1_000_000)
    }
}

struct SetupCurrencySheet: View {
    @Environment(AppEnvironment.self) private var env
    @State var draft: CurrencyDraft
    let saved: () -> Void
    var body: some View {
        EditSheet(draft.id == nil ? "Add currency" : "Edit \(draft.code)", canSave: !draft.code.isEmpty && !draft.name.isEmpty, save: {
            var pairs: [(String, JSONValue?)] = [
                ("code", .string(draft.code.uppercased())), ("name", .string(draft.name)), ("symbol", .string(draft.symbol)),
                ("exchange_rate", draft.exchangeRatePayload), ("reporting_rate_to_kes", J.typed(draft.reportingRate)),
                ("decimal_places", J.typed(draft.decimals)), ("thousand_separator", .string(draft.thousands)), ("decimal_separator", .string(draft.decimalSep)),
                ("symbol_position", .string(draft.position)), ("is_default", .bool(draft.isDefault)), ("is_active", .bool(draft.active)),
            ]
            let r: APIClient.Response
            if let id = draft.id {
                pairs.append(("effective_from", draft.effectiveFrom.isEmpty ? .null : .string(draft.effectiveFrom + "T00:00:00+03:00")))
                r = try await env.session.client.put("/v1/admin/currencies-management/\(id)", json: J.obj(pairs))
            } else { r = try await env.session.client.post("/v1/admin/currencies-management", json: J.obj(pairs)) }
            if let props = r.json?["proposals"]?.array, !props.isEmpty { env.toasts.show(r.json?.message ?? "Rate change proposed — it applies once signed", tone: .info) } else { toastResult(env, r) }
            saved()
        }) { e in
            HStack { Field("Code", $draft.code, error: e?.errors["code"]?.first); Field("Name", $draft.name, error: e?.errors["name"]?.first); Field("Symbol", $draft.symbol) }
            Text("Customer pricing").font(Theme.Font.sectionTitle)
            Field("KES per 1 unit (quotation rate)", $draft.kesPerUnit, error: e?.errors["exchange_rate"]?.first)
            Text("Stored base-relative (1/KES per unit, 6 decimals). A change is proposed for finance to sign.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            Text("Financial reporting").font(Theme.Font.sectionTitle)
            Field("KES per 1 unit for reports (blank = no reporting rate)", $draft.reportingRate, error: e?.errors["reporting_rate_to_kes"]?.first)
            Text("Reports convert at this rate; a change is proposed, dated, for the super admin to sign and never rewrites history.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            if draft.id != nil { Field("Effective from (yyyy-mm-dd, optional)", $draft.effectiveFrom) }
            HStack { Field("Decimals", $draft.decimals); Field("Thousands", $draft.thousands); Field("Decimal", $draft.decimalSep) }
            OptionPicker("Symbol position", $draft.position, options: [("before", "Before"), ("after", "After")])
            ToggleRow("Default", $draft.isDefault)
            ToggleRow("Active", $draft.active)
            Text("Saving asks you to confirm it is you.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
    }
}

// MARK: - Languages (GET /languages; POST/PUT/DELETE; toggle; set-default)

struct LanguagesView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var version = 0
    @State private var editing: LanguageDraft?
    private var canEdit: Bool { env.session.permissions.canAny(["settings.edit", "setup.technical"]) }
    var body: some View {
        Page("Languages", subtitle: "Storefront and console languages") {
            if canEdit { HStack { Spacer(); Button { editing = LanguageDraft() } label: { Label("Add language", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) } }
            RemoteJSON("/v1/admin/languages", version: version) { j, reload in
                ForEach(j["data"]?.records ?? []) { l in
                    SupportListRow("\(l.string("flag") ?? "") \(l.string("name") ?? "") · \(l.string("native_name") ?? "")", subtitle: "\(l.string("code") ?? "") · \(l.string("direction") ?? "ltr")") {
                        HStack {
                            if l["is_default"]?.bool == true { Pill("default", .info) }
                            StatusBadge(l["is_active"]?.bool == true ? "active" : "inactive")
                            if canEdit {
                                Button("Edit") { editing = LanguageDraft(record: l) }
                                AsyncButton { let r = try await env.session.client.put("/v1/admin/languages/\(l.id)/toggle"); toastResult(env, r); reload() } label: { Image(systemName: "power") }
                                if l["is_default"]?.bool != true { AsyncButton("Default") { let r = try await env.session.client.put("/v1/admin/languages/\(l.id)/set-default"); toastResult(env, r); reload() } }
                                AsyncButton(role: .destructive) { let r = try await env.session.client.delete("/v1/admin/languages/\(l.id)"); toastResult(env, r); reload() } label: { Image(systemName: "trash") }
                            }
                        }
                    }
                }
            }
        }
        .sheet(item: $editing) { d in LanguageSheet(draft: d) { version += 1 } }
    }
}

struct LanguageDraft: Identifiable {
    var id: Int?
    var code = ""; var name = ""; var native = ""; var direction = "ltr"; var flag = ""; var isDefault = false; var active = true
    init() {}
    init(record l: Record) { id = l.int("id"); code = l.string("code") ?? ""; name = l.string("name") ?? ""; native = l.string("native_name") ?? ""; direction = l.string("direction") ?? "ltr"; flag = l.string("flag") ?? ""; isDefault = l["is_default"]?.bool ?? false; active = l["is_active"]?.bool ?? true }
}

struct LanguageSheet: View {
    @Environment(AppEnvironment.self) private var env
    @State var draft: LanguageDraft
    let saved: () -> Void
    var body: some View {
        EditSheet(draft.id == nil ? "Add language" : "Edit language", canSave: !draft.code.isEmpty && !draft.name.isEmpty, save: {
            let body = J.obj([("code", .string(draft.code)), ("name", .string(draft.name)), ("native_name", .string(draft.native)), ("direction", .string(draft.direction)), ("flag", J.s(draft.flag)), ("is_default", .bool(draft.isDefault)), ("is_active", .bool(draft.active))])
            let r: APIClient.Response
            if let id = draft.id { r = try await env.session.client.put("/v1/admin/languages/\(id)", json: body) } else { r = try await env.session.client.post("/v1/admin/languages", json: body) }
            toastResult(env, r); saved()
        }) { e in
            HStack { Field("Code", $draft.code, placeholder: "sw", error: e?.errors["code"]?.first); Field("Flag", $draft.flag) }
            Field("Name", $draft.name, error: e?.errors["name"]?.first)
            Field("Native name", $draft.native)
            OptionPicker("Direction", $draft.direction, options: [("ltr", "Left to right"), ("rtl", "Right to left")])
            ToggleRow("Default", $draft.isDefault)
            ToggleRow("Active", $draft.active)
        }
    }
}

// MARK: - Tax rates (GET /tax-rates; POST/PUT/DELETE/toggle — step-up; PUT may return a proposal)

struct TaxRatesView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var version = 0
    @State private var editing: TaxDraft?
    var body: some View {
        Page("Tax rates", subtitle: "VAT and other rates applied to sales") {
            if env.session.can("settings.edit") { HStack { Spacer(); Button { editing = TaxDraft() } label: { Label("Add rate", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) } }
            RemoteJSON("/v1/admin/tax-rates", version: version) { j, reload in
                ForEach(j["data"]?.records ?? []) { t in
                    SupportListRow("\(t.string("name") ?? "") (\(t.string("code") ?? ""))", subtitle: "\(t["rate"]?.string ?? "")\(t.string("type") == "fixed" ? " fixed" : "%") · applies to \(t.string("applies_to") ?? "all") · \(t.string("country_code") ?? "all countries")") {
                        HStack {
                            if t["is_default"]?.bool == true { Pill("default", .info) }
                            StatusBadge(t["is_active"]?.bool == true ? "active" : "inactive")
                            if env.session.permissions.canAny(["settings.edit", "settings.financial_propose"]) {
                                Button("Edit") { editing = TaxDraft(record: t) }
                                AsyncButton { let r = try await env.session.client.put("/v1/admin/tax-rates/\(t.id)/toggle"); proposalToast(env, r); reload() } label: { Image(systemName: "power") }
                            }
                            if env.session.can("settings.edit") {
                                AsyncButton(role: .destructive) { let r = try await env.session.client.delete("/v1/admin/tax-rates/\(t.id)"); toastResult(env, r); reload() } label: { Image(systemName: "trash") }
                            }
                        }
                    }
                }
            }
        }
        .sheet(item: $editing) { d in TaxRateSheet(draft: d) { version += 1 } }
    }
}

/// Phase 3C: a write may answer with `proposal` (waits for a signature) instead of applying.
@MainActor
func proposalToast(_ env: AppEnvironment, _ r: APIClient.Response) {
    if let p = r.json?["proposal"], !p.isNull { env.toasts.show(r.json?.message ?? "Proposed — applies once signed", tone: .info) }
    else if let ps = r.json?["proposals"]?.array, !ps.isEmpty { env.toasts.show(r.json?.message ?? "Proposed — applies once signed", tone: .info) }
    else { toastResult(env, r) }
}

struct TaxDraft: Identifiable {
    var id: Int?
    var name = ""; var code = ""; var rate = ""; var type = "percentage"; var appliesTo = "all"; var country = ""; var isDefault = false; var active = true; var effectiveFrom = ""
    init() {}
    init(record t: Record) { id = t.int("id"); name = t.string("name") ?? ""; code = t.string("code") ?? ""; rate = t["rate"]?.string ?? ""; type = t.string("type") ?? "percentage"; appliesTo = t.string("applies_to") ?? "all"; country = t.string("country_code") ?? ""; isDefault = t["is_default"]?.bool ?? false; active = t["is_active"]?.bool ?? true }
}

struct TaxRateSheet: View {
    @Environment(AppEnvironment.self) private var env
    @State var draft: TaxDraft
    let saved: () -> Void
    var body: some View {
        EditSheet(draft.id == nil ? "Add tax rate" : "Edit tax rate", canSave: !draft.name.isEmpty && !draft.rate.isEmpty, save: {
            var pairs: [(String, JSONValue?)] = [("name", .string(draft.name)), ("code", .string(draft.code)), ("rate", J.typed(draft.rate)), ("type", .string(draft.type)), ("applies_to", .string(draft.appliesTo)), ("country_code", J.s(draft.country)), ("is_default", .bool(draft.isDefault)), ("is_active", .bool(draft.active))]
            let r: APIClient.Response
            if let id = draft.id { pairs.append(("effective_from", draft.effectiveFrom.isEmpty ? .null : .string(draft.effectiveFrom + "T00:00:00+03:00"))); r = try await env.session.client.put("/v1/admin/tax-rates/\(id)", json: J.obj(pairs)) }
            else { r = try await env.session.client.post("/v1/admin/tax-rates", json: J.obj(pairs)) }
            proposalToast(env, r); saved()
        }) { e in
            HStack { Field("Name", $draft.name, error: e?.errors["name"]?.first); Field("Code", $draft.code, error: e?.errors["code"]?.first) }
            HStack { Field("Rate", $draft.rate, error: e?.errors["rate"]?.first); OptionPicker("Type", $draft.type, options: [("percentage", "Percentage"), ("fixed", "Fixed")]) }
            OptionPicker("Applies to", $draft.appliesTo, options: [("all", "Everything"), ("products", "Products"), ("shipping", "Shipping")])
            Field("Country code (blank = all)", $draft.country)
            ToggleRow("Default", $draft.isDefault)
            ToggleRow("Active", $draft.active)
            if draft.id != nil { Field("Effective from (yyyy-mm-dd, optional)", $draft.effectiveFrom); Text("Rate, active and default changes are proposed for the super admin to sign.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
            Text("Saving asks you to confirm it is you.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
    }
}

// MARK: - Discount limits (GET/PUT /settings/discount-limits — owner only, step-up)

struct DiscountLimitsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var caps: [String: String] = [:]
    @State private var version = 0
    var body: some View {
        Page("Discount limits", subtitle: "The most each role may give as a discount; blank uses the default") {
            RemoteJSON("/v1/admin/settings/discount-limits", version: version) { j, _ in
                Text("Default limit: \(j["default_percent"]?.string ?? "—")%").font(Theme.Font.sectionTitle)
                let limits = j["limits"]?.records ?? []
                ForEach(limits) { l in
                    HStack {
                        VStack(alignment: .leading, spacing: 1) {
                            Text(l.string("display_name") ?? l.string("role") ?? "").font(Theme.Font.body)
                            Text("Effective \(l["effective_percent"]?.string ?? "")%\(l["updated_by"]?["name"]?.string.map { " · set by \($0)" } ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                        }
                        Spacer()
                        TextField("default", text: Binding(get: { caps[l.string("role") ?? ""] ?? l["cap_percent"]?.string ?? "" }, set: { caps[l.string("role") ?? ""] = $0 })).textFieldStyle(.roundedBorder).frame(width: 90)
                        Text("%").foregroundStyle(Theme.textSecondary)
                    }
                }
                AsyncButton("Save (asks you to confirm it's you)") {
                    let body = J.obj([("limits", .array(limits.map { l in
                        let role = l.string("role") ?? ""
                        let v = caps[role] ?? l["cap_percent"]?.string ?? ""
                        return J.obj([("role", .string(role)), ("cap_percent", J.typed(v))])
                    }))])
                    let r = try await env.session.client.put("/v1/admin/settings/discount-limits", json: body); toastResult(env, r); caps = [:]; version += 1
                }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
            }
        }
    }
}
