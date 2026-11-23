import SwiftUI
import BethanyKit

// Where a production job's materials come from (#573; console
// components/production/MaterialSourcing.tsx). Nothing here names a supplier
// or a price: who we buy from is procurement's to see.
//
//   MaterialSourcingFields   the per-material choice, inside "Send to production"
//   MaterialSourcingPanel    the same on a production job, with where each stands
//   MovePurchaseLineSheet    procurement moves a job's line to another supplier

struct MaterialSourcingFields: View {
    let needs: [MaterialSourcing.Need]
    @Binding var choices: [Int: MaterialSourcing.Choice]

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.s) {
            ForEach(needs) { m in row(m) }
        }
    }

    private func row(_ m: MaterialSourcing.Need) -> some View {
        let choice = choices[m.materialId]
        let mode = choice?.mode ?? .buy
        let unit = m.unit ?? ""
        let enough = m.free >= m.required
        let err = MaterialSourcing.problem(m, choice)
        return VStack(alignment: .leading, spacing: 6) {
            HStack(alignment: .firstTextBaseline) {
                Text(m.name).font(Theme.Font.body.weight(.semibold)).lineLimit(1)
                Spacer()
                Text("\(MaterialSourcing.qty(m.required)) \(unit)").font(Theme.Font.body.weight(.semibold)).monospacedDigit()
            }
            Text(m.free > 0 ? "\(MaterialSourcing.qty(m.free)) \(unit) free in store\(enough ? "" : " · not enough")" : "None free in store")
                .font(Theme.Font.caption).foregroundStyle(enough ? Theme.Palette.success : Theme.Palette.amber)
            Picker("Where \(m.name) comes from", selection: Binding(
                get: { mode },
                set: { new in
                    var c = choices[m.materialId] ?? .init(mode: .buy)
                    if new == .split && c.store.isEmpty { c.store = MaterialSourcing.splitStart(m) }
                    c.mode = new
                    choices[m.materialId] = c
                })) {
                ForEach(MaterialSourcing.Mode.allCases.filter { $0 != .split || MaterialSourcing.canSplit(m) }, id: \.self) { Text($0.label).tag($0) }
            }
            .pickerStyle(.segmented).labelsHidden()
            if mode == .split {
                HStack {
                    VStack(alignment: .leading, spacing: 2) {
                        Text("From store (\(unit))").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        TextField("0", text: Binding(get: { choices[m.materialId]?.store ?? "" }, set: { choices[m.materialId]?.store = $0 }))
                            .textFieldStyle(.roundedBorder).monospacedDigit()
                            #if os(iOS)
                            .keyboardType(.decimalPad)
                            #endif
                    }
                    VStack(alignment: .leading, spacing: 2) {
                        Text("To buy (\(unit))").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        Text(MaterialSourcing.qty(MaterialSourcing.split(m, choice).buy)).font(Theme.Font.body.weight(.semibold)).monospacedDigit()
                    }
                }
            }
            if let err {
                Text(err).font(Theme.Font.caption.weight(.medium)).foregroundStyle(Theme.Palette.danger)
            } else {
                Text(MaterialSourcing.outcome(m, choice)).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            }
        }
        .padding(Theme.Space.m)
        .overlay(RoundedRectangle(cornerRadius: 12).stroke(Theme.line, lineWidth: 1))
    }
}

/// GET/POST production-orders/{id}/material-sourcing. Editable by
/// production.raise_order or production.manage_assignees while the job is open.
struct MaterialSourcingPanel: View {
    @Environment(AppEnvironment.self) private var env
    let orderId: Int
    let closed: Bool
    @State private var job: MaterialSourcing.Job?
    @State private var editing = false
    @State private var choices: [Int: MaterialSourcing.Choice] = [:]
    @State private var saving = false

    private var canEdit: Bool { env.session.permissions.canAny(["production.raise_order", "production.manage_assignees"]) }

    var body: some View {
        Group {
            if let job, job.status != "none" {
                SectionCard("Where the materials come from") {
                    HStack {
                        StatusBadge(job.statusLabel, tone: tone(job.status))
                        Spacer()
                        if canEdit && !closed && !editing && !job.outstanding.isEmpty {
                            Button(job.status == "unsourced" ? "Choose sources" : "Change") { choices = job.currentChoices; editing = true }
                        }
                    }
                    if editing {
                        MaterialSourcingFields(needs: job.outstanding, choices: $choices)
                        HStack {
                            Spacer()
                            Button("Cancel") { editing = false }
                            Button(saving ? "Saving…" : "Save") { Task { await save(job) } }
                                .buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                                .disabled(saving || MaterialSourcing.hasProblem(job.outstanding, choices))
                        }
                    } else {
                        ForEach(job.materials) { m in
                            HStack(alignment: .top) {
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(m.name).font(Theme.Font.body)
                                    Text("\(MaterialSourcing.qty(m.required)) \(m.unit ?? "") needed").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).monospacedDigit()
                                }
                                Spacer()
                                VStack(alignment: .trailing, spacing: 3) {
                                    if m.issued > 0 { StatusBadge("\(MaterialSourcing.qty(m.issued)) \(m.unit ?? "") issued", tone: .neutral) }
                                    if m.reserved > 0 { StatusBadge("\(MaterialSourcing.qty(m.reserved)) \(m.unit ?? "") reserved in store", tone: .success) }
                                    if m.toBuy > 0 { StatusBadge("\(MaterialSourcing.qty(m.toBuy)) \(m.unit ?? "") being bought", tone: .amber) }
                                    if m.unsourced > 0 { StatusBadge("\(MaterialSourcing.qty(m.unsourced)) \(m.unit ?? "") not yet sourced", tone: .danger) }
                                }
                            }
                            Divider()
                        }
                        if job.status == "waiting" {
                            Text("When procurement receives a delivery, the material is held for this job automatically.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                        }
                    }
                }
            }
        }
        .task { await load() }
    }

    private func tone(_ status: String) -> StatusTone {
        switch status { case "unsourced": return .danger; case "waiting": return .amber; case "ready": return .success; default: return .neutral }
    }

    private func load() async {
        do { job = MaterialSourcing.job(try await env.session.client.get("/v1/admin/production-orders/\(orderId)/material-sourcing").json ?? .null) }
        catch { job = nil }
    }

    private func save(_ job: MaterialSourcing.Job) async {
        saving = true
        defer { saving = false }
        do {
            let r = try await env.session.client.post("/v1/admin/production-orders/\(orderId)/material-sourcing", json: .object(["materials": MaterialSourcing.payload(job.outstanding, choices)]))
            env.toasts.show("Materials updated")
            self.job = MaterialSourcing.job(r.json ?? .null)
            editing = false
        } catch { env.toasts.error(error) }
    }
}

/// POST purchase-orders/{id}/items/{itemId}/move {supplier_id} — procurement.create, draft only.
/// The line joins that supplier's open draft (one is started if there is none).
struct MovePurchaseLineSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let poId: Int
    let currentSupplierId: Int?
    let line: JSONValue
    let onMoved: (Int) -> Void
    @State private var suppliers: [Record] = []
    @State private var to: Int?
    @State private var error: APIError?

    var body: some View {
        ModuleFormSheet("Move to another supplier", canSubmit: to != nil, error: error, submit: submit) {
            VStack(alignment: .leading, spacing: 2) {
                Text(line["description"]?.string ?? "Line").font(Theme.Font.body.weight(.semibold))
                Text("\(quantityText(line["quantity"])) · Job \(line["production_order"]?["order_number"]?.string ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            }
            ModuleField("New supplier", error: error?.errors["supplier_id"]?.first) {
                Picker("New supplier", selection: $to) {
                    Text(suppliers.isEmpty ? "Loading…" : "Choose a supplier").tag(Int?.none)
                    ForEach(suppliers.filter { $0.int("id") != currentSupplierId }) { s in Text(s.string("name") ?? "").tag(s.int("id")) }
                }
            }
            Text("The line joins that supplier's open draft purchase order (one is started if there is none). This is only possible while the order is a draft.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
        .task { suppliers = (try? await env.session.client.get("/v1/admin/suppliers", query: ["per_page": "200"]).json?["data"]?.array?.map(Record.init(json:))) ?? [] }
    }

    private func submit() async {
        guard let to, let itemId = line["id"]?.int else { return }
        do {
            let r = try await env.session.client.post("/v1/admin/purchase-orders/\(poId)/items/\(itemId)/move", json: .object(["supplier_id": .number(Double(to))]))
            env.toasts.show(r.json?["message"]?.string ?? "Line moved")
            dismiss()
            if let id = r.json?["purchase_order"]?["id"]?.int { onMoved(id) }
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}
