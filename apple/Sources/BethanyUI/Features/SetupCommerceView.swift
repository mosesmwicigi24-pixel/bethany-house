import SwiftUI
import BethanyKit

/// Payment methods (GET /payment-methods-management; writes behind step-up,
/// settlement changes become proposals) and shipping zones & methods.

struct PaymentMethodsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var version = 0
    @State private var editing: PaymentMethodDraft?
    @State private var configuring: Record?

    var body: some View {
        Page("Payment methods", subtitle: "How customers pay, and whether a payment settles at once or waits for approval") {
            if env.session.can("settings.edit") { HStack { Spacer(); Button { editing = PaymentMethodDraft() } label: { Label("Add method", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) } }
            RemoteJSON("/v1/admin/payment-methods-management", version: version) { j, reload in
                ForEach(j["data"]?.records ?? []) { m in
                    SupportListRow("\(m.string("name") ?? "") (\(m.string("code") ?? ""))", subtitle: "\(m.string("type")?.humanized ?? "") · \(m.string("provider") ?? "no provider") · \((m["supported_currencies"]?.array ?? []).compactMap(\.string).joined(separator: ", ")) · \(m["requires_approval"]?.bool == true ? "held for approval" : "settles at once")") {
                        HStack {
                            if m["is_default"]?.bool == true { Pill("default", .info) }
                            StatusBadge(m["is_active"]?.bool == true ? "active" : "inactive")
                            if env.session.permissions.canAny(["settings.edit", "settings.financial_propose"]) { Button("Edit") { editing = PaymentMethodDraft(record: m) } }
                            if env.session.can("settings.edit") {
                                Button("Config") { configuring = m }
                                AsyncButton { let r = try await env.session.client.put("/v1/admin/payment-methods-management/\(m.id)/toggle"); toastResult(env, r); reload() } label: { Image(systemName: "power") }
                                AsyncButton(role: .destructive) { let r = try await env.session.client.delete("/v1/admin/payment-methods-management/\(m.id)"); toastResult(env, r); reload() } label: { Image(systemName: "trash") }
                            }
                        }
                    }
                }
            }
        }
        .sheet(item: $editing) { d in PaymentMethodSheet(draft: d) { version += 1 } }
        .sheet(item: $configuring) { m in PaymentConfigSheet(method: m) { version += 1 } }
    }
}

struct PaymentMethodDraft: Identifiable {
    var id: Int?
    var name = ""; var code = ""; var type = "cash"; var provider = ""; var description = ""; var active = true; var isDefault = false; var requiresApproval = false; var sortOrder = "0"; var currencies = ""; var effectiveFrom = ""
    init() {}
    init(record m: Record) { id = m.int("id"); name = m.string("name") ?? ""; code = m.string("code") ?? ""; type = m.string("type") ?? "cash"; provider = m.string("provider") ?? ""; description = m.string("description") ?? ""; active = m["is_active"]?.bool ?? true; isDefault = m["is_default"]?.bool ?? false; requiresApproval = m["requires_approval"]?.bool ?? false; sortOrder = m["sort_order"]?.string ?? "0"; currencies = (m["supported_currencies"]?.array ?? []).compactMap(\.string).joined(separator: ", ") }
}

struct PaymentMethodSheet: View {
    @Environment(AppEnvironment.self) private var env
    @State var draft: PaymentMethodDraft
    let saved: () -> Void
    var body: some View {
        EditSheet(draft.id == nil ? "Add payment method" : "Edit payment method", canSave: !draft.name.isEmpty && !draft.code.isEmpty, save: {
            var pairs: [(String, JSONValue?)] = [("name", .string(draft.name)), ("code", .string(draft.code)), ("type", .string(draft.type)), ("provider", J.s(draft.provider)), ("description", J.s(draft.description)), ("is_active", .bool(draft.active)), ("is_default", .bool(draft.isDefault)), ("requires_approval", .bool(draft.requiresApproval)), ("sort_order", J.typed(draft.sortOrder)),
                ("supported_currencies", J.strs(draft.currencies.split(separator: ",").map { $0.trimmingCharacters(in: .whitespaces).uppercased() }.filter { !$0.isEmpty }))]
            let r: APIClient.Response
            if let id = draft.id { pairs.append(("effective_from", draft.effectiveFrom.isEmpty ? .null : .string(draft.effectiveFrom + "T00:00:00+03:00"))); r = try await env.session.client.put("/v1/admin/payment-methods-management/\(id)", json: J.obj(pairs)) }
            else { r = try await env.session.client.post("/v1/admin/payment-methods-management", json: J.obj(pairs)) }
            proposalToast(env, r); saved()
        }) { e in
            HStack { Field("Name", $draft.name, error: e?.errors["name"]?.first); Field("Code", $draft.code, error: e?.errors["code"]?.first) }
            OptionPicker("Type", $draft.type, options: [("mobile_money", "Mobile money"), ("card", "Card"), ("cash", "Cash"), ("bank_transfer", "Bank transfer")])
            Field("Provider", $draft.provider, placeholder: "mpesa, pesapal…")
            Field("Description", $draft.description)
            Field("Supported currencies (comma separated)", $draft.currencies, placeholder: "KES, USD")
            Field("Sort order", $draft.sortOrder)
            ToggleRow("Active", $draft.active)
            ToggleRow("Default", $draft.isDefault)
            ToggleRow("Hold payments for approval (settlement)", $draft.requiresApproval)
            if draft.id != nil { Field("Effective from (yyyy-mm-dd, optional)", $draft.effectiveFrom); Text("A settlement change is proposed for the super admin to sign.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
            Text("Saving asks you to confirm it is you.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
    }
}

/// PUT /payment-methods-management/{id}/config {configuration: {key: value}}
struct PaymentConfigSheet: View {
    @Environment(AppEnvironment.self) private var env
    let method: Record
    let saved: () -> Void
    @State private var pairs: [(String, String)] = []
    var body: some View {
        EditSheet("Configuration · \(method.string("name") ?? "")", save: {
            var o: [String: JSONValue] = [:]
            for (k, v) in pairs where !k.isEmpty { o[k] = .string(v) }
            let r = try await env.session.client.put("/v1/admin/payment-methods-management/\(method.id)/config", json: J.obj([("configuration", .object(o))]))
            toastResult(env, r); saved()
        }) { _ in
            Text("Provider keys and settings. Secrets are stored server-side; they come back masked.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            ForEach(pairs.indices, id: \.self) { i in
                HStack {
                    TextField("key", text: Binding(get: { pairs[i].0 }, set: { pairs[i].0 = $0 })).textFieldStyle(.roundedBorder).font(Theme.Font.mono)
                    TextField("value", text: Binding(get: { pairs[i].1 }, set: { pairs[i].1 = $0 })).textFieldStyle(.roundedBorder)
                    Button(role: .destructive) { pairs.remove(at: i) } label: { Image(systemName: "minus.circle") }
                }
            }
            Button { pairs.append(("", "")) } label: { Label("Add key", systemImage: "plus") }
        }
        .task {
            let cfg = method["configuration"]?.object ?? method["config"]?.object ?? [:]
            pairs = cfg.keys.sorted().map { ($0, cfg[$0]?.string ?? "") }
        }
    }
}

// MARK: - Shipping (GET /shipping/zones (array), /methods (array); zone countries; CRUD)

struct ShippingView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var version = 0
    @State private var zone: ZoneDraft?
    @State private var method: MethodDraft?
    private var canEdit: Bool { env.session.permissions.canAny(["settings.edit", "setup.technical"]) }

    var body: some View {
        Page("Shipping", subtitle: "Zones group countries; methods price delivery per zone") {
            RemoteJSON("/v1/admin/shipping/zones", version: version) { zj, reload in
                let zones = zj.listRecords
                HStack { Text("Zones").font(Theme.Font.sectionTitle); Spacer(); if canEdit { Button { zone = ZoneDraft() } label: { Label("New zone", systemImage: "plus") } } }
                ForEach(zones) { z in
                    SupportListRow(z.string("name") ?? "", subtitle: "\(z.string("description") ?? "") · \((z["countries"]?.array ?? []).compactMap(\.string).joined(separator: ", "))") {
                        HStack {
                            StatusBadge(z["is_active"]?.bool == false ? "inactive" : "active")
                            if canEdit {
                                Button("Edit") { zone = ZoneDraft(record: z) }
                                AsyncButton(role: .destructive) { let r = try await env.session.client.delete("/v1/admin/shipping/zones/\(z.id)"); toastResult(env, r); reload() } label: { Image(systemName: "trash") }
                            }
                        }
                    }
                }
                RemoteJSON("/v1/admin/shipping/methods", version: version) { mj, reloadM in
                    HStack { Text("Methods").font(Theme.Font.sectionTitle); Spacer(); if canEdit { Button { method = MethodDraft(zoneId: zones.first?.int("id")) } label: { Label("New method", systemImage: "plus") } } }
                    ForEach(mj.listRecords) { m in
                        SupportListRow("\(m.string("name") ?? "") · \(m.string("zone_name") ?? zones.first { $0.int("id") == m.int("shipping_zone_id") }?.string("name") ?? "zone \(m.int("shipping_zone_id") ?? 0)")", subtitle: "\(m.string("cost_type")?.humanized ?? "") \(m.string("cost_type") == "free" ? "" : (m["flat_rate"]?.string ?? ""))\(m.string("cost_type") == "percentage" ? "%" : "")\(m["min_order_amount"]?.string.map { " · min order \($0)" } ?? "") · \(m.string("delivery_time") ?? "")") {
                            HStack {
                                StatusBadge(m["is_active"]?.bool == true ? "active" : "inactive")
                                if canEdit {
                                    Button("Edit") { method = MethodDraft(record: m) }
                                    AsyncButton(role: .destructive) { let r = try await env.session.client.delete("/v1/admin/shipping/methods/\(m.id)"); toastResult(env, r); reloadM() } label: { Image(systemName: "trash") }
                                }
                            }
                        }
                    }
                }
            }
        }
        .sheet(item: $zone) { d in ZoneSheet(draft: d) { version += 1 } }
        .sheet(item: $method) { d in MethodSheet(draft: d) { version += 1 } }
    }
}

struct ZoneDraft: Identifiable {
    var id: Int?; var name = ""; var description = ""; var countries = ""
    init() {}
    init(record z: Record) { id = z.int("id"); name = z.string("name") ?? ""; description = z.string("description") ?? ""; countries = (z["countries"]?.array ?? []).compactMap(\.string).joined(separator: ", ") }
}

/// POST/PUT /shipping/zones {name, description, countries: [codes]}
struct ZoneSheet: View {
    @Environment(AppEnvironment.self) private var env
    @State var draft: ZoneDraft
    let saved: () -> Void
    var body: some View {
        EditSheet(draft.id == nil ? "New zone" : "Edit zone", canSave: !draft.name.isEmpty, save: {
            let body = J.obj([("name", .string(draft.name)), ("description", J.s(draft.description)), ("countries", J.strs(draft.countries.split(separator: ",").map { $0.trimmingCharacters(in: .whitespaces).uppercased() }.filter { !$0.isEmpty }))])
            let r: APIClient.Response
            if let id = draft.id { r = try await env.session.client.put("/v1/admin/shipping/zones/\(id)", json: body) } else { r = try await env.session.client.post("/v1/admin/shipping/zones", json: body) }
            toastResult(env, r); saved()
        }) { e in
            Field("Name", $draft.name, error: e?.errors["name"]?.first)
            Field("Description", $draft.description)
            Field("Country codes (comma separated)", $draft.countries, placeholder: "KE, UG, TZ", error: e?.errors["countries"]?.first)
        }
    }
}

struct MethodDraft: Identifiable {
    var id: Int?; var zoneId: Int?; var name = ""; var description = ""; var delivery = ""; var costType = "flat_rate"; var rate = "0"; var minOrder = ""; var active = true; var sort = "0"
    init(zoneId: Int?) { self.zoneId = zoneId }
    init(record m: Record) { id = m.int("id"); zoneId = m.int("shipping_zone_id"); name = m.string("name") ?? ""; description = m.string("description") ?? ""; delivery = m.string("delivery_time") ?? ""; costType = m.string("cost_type") ?? "flat_rate"; rate = m["flat_rate"]?.string ?? "0"; minOrder = m["min_order_amount"]?.string ?? ""; active = m["is_active"]?.bool ?? true; sort = m["sort_order"]?.string ?? "0" }
}

/// POST/PUT /shipping/methods {zone_id, name, description, delivery_time, cost_type, flat_rate, min_order_amount, is_active, sort_order}
struct MethodSheet: View {
    @Environment(AppEnvironment.self) private var env
    @State var draft: MethodDraft
    let saved: () -> Void
    @State private var zones: [Record] = []
    var body: some View {
        EditSheet(draft.id == nil ? "New shipping method" : "Edit shipping method", canSave: !draft.name.isEmpty && (draft.id != nil || draft.zoneId != nil), save: {
            var pairs: [(String, JSONValue?)] = [("name", .string(draft.name)), ("description", J.s(draft.description)), ("delivery_time", J.s(draft.delivery)), ("cost_type", .string(draft.costType)), ("flat_rate", J.typed(draft.rate)), ("min_order_amount", J.typed(draft.minOrder)), ("is_active", .bool(draft.active)), ("sort_order", J.typed(draft.sort))]
            let r: APIClient.Response
            if let id = draft.id { r = try await env.session.client.put("/v1/admin/shipping/methods/\(id)", json: J.obj(pairs)) }
            else { pairs.append(("zone_id", J.i(draft.zoneId))); r = try await env.session.client.post("/v1/admin/shipping/methods", json: J.obj(pairs)) }
            toastResult(env, r); saved()
        }) { e in
            if draft.id == nil {
                Picker("Zone", selection: $draft.zoneId) { ForEach(zones) { z in Text(z.string("name") ?? "").tag(z.int("id")) } }
            }
            Field("Name", $draft.name, error: e?.errors["name"]?.first)
            Field("Description", $draft.description)
            Field("Delivery time", $draft.delivery, placeholder: "2–5 business days")
            OptionPicker("Cost", $draft.costType, options: [("flat_rate", "Flat rate"), ("free", "Free"), ("percentage", "Percentage of order")])
            if draft.costType != "free" { Field(draft.costType == "percentage" ? "Percent" : "Flat rate (KES)", $draft.rate, error: e?.errors["flat_rate"]?.first) }
            Field("Minimum order amount (optional)", $draft.minOrder)
            Field("Sort order", $draft.sort)
            ToggleRow("Active", $draft.active)
        }
        .task { zones = (try? await env.session.client.json("/v1/admin/shipping/zones"))?.listRecords ?? [] }
    }
}
