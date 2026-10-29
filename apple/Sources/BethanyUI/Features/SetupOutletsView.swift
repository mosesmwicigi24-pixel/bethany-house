import SwiftUI
import MapKit
import BethanyKit

/// Outlets (GET/POST/PUT/DELETE /outlets, GET /outlets/{id}/statistics):
/// the list, a form with the geofence drawn on a map, and the statistics card.
struct OutletsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var version = 0
    @State private var editing: OutletDraft?
    @State private var stats: IDRef?

    var body: some View {
        Page("Outlets", subtitle: "Stores, workshops and warehouses") {
            if env.session.can("outlets.create") {
                HStack { Spacer(); Button { editing = OutletDraft() } label: { Label("New outlet", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
            }
            RemoteJSON("/v1/admin/outlets", version: version, emptyTitle: "No outlets yet", isEmpty: { $0.listRecords.isEmpty }) { j, reload in
                ForEach(j.listRecords) { o in
                    SectionCard {
                        HStack(alignment: .top) {
                            VStack(alignment: .leading, spacing: 3) {
                                HStack { Text(o.string("name") ?? "").font(Theme.Font.sectionTitle); Pill(o.string("code") ?? "", .neutral); StatusBadge(o["is_active"]?.bool == true ? "active" : "inactive") }
                                Text("\(o.string("outlet_type")?.humanized ?? "") · \(o.string("sales_channel")?.humanized ?? "pos") channel · \([o.string("address_line1"), o.string("city"), o.string("country_code")].compactMap { $0 }.joined(separator: ", "))").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                                Text("\(o["users_count"]?.int ?? 0) people · geofence \(o["geofence_radius_meters"]?.int.map { "\($0) m" } ?? "not set")\(o["is_pickup_location"]?.bool == true ? " · pickup point" : "")").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                            }
                            Spacer()
                            VStack {
                                Button("Statistics") { stats = IDRef(o.int("id") ?? 0) }
                                if env.session.can("outlets.edit") { Button("Edit") { editing = OutletDraft(record: o) } }
                                if env.session.can("outlets.delete") {
                                    ConfirmButton("Delete outlet", message: "Only an outlet with no people, stock or sales can be deleted.", destructive: true, action: {
                                        do { let r = try await env.session.client.delete("/v1/admin/outlets/\(o.id)"); toastResult(env, r); reload() } catch { env.toasts.error(error) }
                                    }) { Text("Delete").foregroundStyle(Theme.Palette.danger) }
                                }
                            }
                        }
                    }
                }
            }
        }
        .sheet(item: $editing) { d in OutletSheet(draft: d) { version += 1 } }
        .sheet(item: $stats) { ref in OutletStatsSheet(id: ref.id) }
    }
}

struct OutletDraft: Identifiable {
    var id: Int?
    var f: [String: String] = ["outlet_type": "store", "sales_channel": "pos", "country_code": "KE"]
    var active = true
    var pickup = false
    var lat: Double?
    var lon: Double?
    var radius = ""
    static let text: [(String, String)] = [("code", "Code"), ("name", "Name"), ("email", "Email"), ("phone", "Phone"), ("address_line1", "Address line 1"), ("address_line2", "Address line 2"), ("city", "City"), ("state_province", "County / province"), ("postal_code", "Postal code"), ("country_code", "Country code")]
    init() {}
    init(record o: Record) {
        id = o.int("id")
        for (k, _) in Self.text { f[k] = o.string(k) ?? "" }
        f["outlet_type"] = o.string("outlet_type") ?? "store"
        f["sales_channel"] = o.string("sales_channel") ?? "pos"
        active = o["is_active"]?.bool ?? true
        pickup = o["is_pickup_location"]?.bool ?? false
        lat = o["latitude"]?.double; lon = o["longitude"]?.double
        radius = o["geofence_radius_meters"]?.string ?? ""
    }
}

/// POST/PUT /outlets — the geofence centre is set from the map's centre.
struct OutletSheet: View {
    @Environment(AppEnvironment.self) private var env
    @State var draft: OutletDraft
    let saved: () -> Void
    @State private var camera: MapCameraPosition
    @State private var centre: CLLocationCoordinate2D?

    init(draft: OutletDraft, saved: @escaping () -> Void) {
        _draft = State(initialValue: draft)
        self.saved = saved
        let c = CLLocationCoordinate2D(latitude: draft.lat ?? -1.2921, longitude: draft.lon ?? 36.8219)
        _camera = State(initialValue: .region(MKCoordinateRegion(center: c, span: MKCoordinateSpan(latitudeDelta: 0.01, longitudeDelta: 0.01))))
    }

    var body: some View {
        EditSheet(draft.id == nil ? "New outlet" : "Edit outlet", canSave: !(draft.f["name"] ?? "").isEmpty && !(draft.f["code"] ?? "").isEmpty, save: {
            var pairs: [(String, JSONValue?)] = OutletDraft.text.map { ($0.0, J.s(draft.f[$0.0]) as JSONValue?) }
            pairs.append(("outlet_type", J.s(draft.f["outlet_type"])))
            pairs.append(("sales_channel", J.s(draft.f["sales_channel"])))
            pairs.append(("is_active", .bool(draft.active)))
            pairs.append(("is_pickup_location", .bool(draft.pickup)))
            pairs.append(("latitude", J.d(draft.lat)))
            pairs.append(("longitude", J.d(draft.lon)))
            pairs.append(("geofence_radius_meters", J.typed(draft.radius)))
            let r: APIClient.Response
            if let id = draft.id { r = try await env.session.client.put("/v1/admin/outlets/\(id)", json: J.obj(pairs)) } else { r = try await env.session.client.post("/v1/admin/outlets", json: J.obj(pairs)) }
            toastResult(env, r); saved()
        }) { e in
            HStack { Field("Code", bind("code"), error: e?.errors["code"]?.first); Field("Name", bind("name"), error: e?.errors["name"]?.first) }
            HStack {
                OptionPicker("Type", bind("outlet_type"), options: [("store", "Store"), ("warehouse", "Warehouse"), ("outlet", "Outlet"), ("workshop", "Workshop")])
                OptionPicker("Sales channel", bind("sales_channel"), options: [("pos", "Till"), ("whatsapp", "Chat"), ("online", "Web")])
            }
            ForEach(OutletDraft.text.dropFirst(2), id: \.0) { k in Field(k.1, bind(k.0), error: e?.errors[k.0]?.first) }
            ToggleRow("Active", $draft.active)
            ToggleRow("Customers can collect here", $draft.pickup)
            Text("Geofence").font(Theme.Font.sectionTitle)
            Text("Clock-ins must happen inside this circle. Move the map so the pin sits on the outlet, then set the centre.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            Map(position: $camera) {
                if let lat = draft.lat, let lon = draft.lon {
                    let c = CLLocationCoordinate2D(latitude: lat, longitude: lon)
                    Annotation(draft.f["name"] ?? "Outlet", coordinate: c) { Image(systemName: "mappin.circle.fill").font(.title).foregroundStyle(Theme.Palette.brand) }
                    if let r = Double(draft.radius), r > 0 { MapCircle(center: c, radius: r).foregroundStyle(Theme.Palette.brand.opacity(0.15)).stroke(Theme.Palette.brand, lineWidth: 1) }
                }
            }
            .onMapCameraChange(frequency: .continuous) { ctx in centre = ctx.camera.centerCoordinate }
            .overlay { Image(systemName: "plus").foregroundStyle(Theme.Palette.danger).allowsHitTesting(false) }
            .frame(height: 240)
            .clipShape(RoundedRectangle(cornerRadius: Theme.Radius.field))
            HStack(alignment: .bottom) {
                Button("Set centre to map crosshair") { if let c = centre { draft.lat = c.latitude; draft.lon = c.longitude } }
                Field("Radius (m, 10–5000)", $draft.radius, error: e?.errors["geofence_radius_meters"]?.first)
            }
            Text(draft.lat.map { "\(String(format: "%.6f", $0)), \(String(format: "%.6f", draft.lon ?? 0))" } ?? "No location set").font(Theme.Font.mono).foregroundStyle(Theme.textSecondary)
            FieldError(e?.errors["latitude"]?.first ?? e?.errors["longitude"]?.first)
        }
    }
    private func bind(_ k: String) -> Binding<String> { Binding(get: { draft.f[k] ?? "" }, set: { draft.f[k] = $0 }) }
}

/// GET /outlets/{id}/statistics → {statistics: {...}} — shown as the server keys them.
struct OutletStatsSheet: View {
    @Environment(\.dismiss) private var dismiss
    let id: Int
    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.l) {
            HStack { Text("Outlet statistics").font(Theme.Font.sectionTitle); Spacer(); Button("Close") { dismiss() } }
            RemoteJSON("/v1/admin/outlets/\(id)/statistics") { j, _ in
                let s = j["statistics"]?.object ?? [:]
                ScrollView {
                    KeyValueGrid(pairs: s.keys.sorted().map { k in (k.humanized, s[k].map { v in v.money?.formatted() ?? v.string ?? prettyJSON(v) } ?? "—") })
                }
                Text("Figures are the server's; money fields are shown as sent.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            }
        }
        .padding(Theme.Space.xl)
        #if os(macOS)
        .frame(minWidth: 440, minHeight: 360)
        #endif
    }
}
