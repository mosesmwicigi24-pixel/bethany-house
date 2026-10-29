import SwiftUI
import BethanyKit

// The production order's action sheets. Payload keys are exactly those
// ProductionController validates (update, assign, issueMaterials,
// qualityCheck, rework, complete, saveBatches).

// MARK: - Edit (PUT /production-orders/{id})

struct EditProductionOrderSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let order: ProductionOrderRecord
    let onSaved: () -> Void
    @State private var quantity: String
    @State private var priority: String
    @State private var dueDate: Date
    @State private var fitting: Date?
    @State private var collection: Date?
    @State private var notes: String
    @State private var measurements: [KeyValueRow]
    @State private var error: APIError?

    init(order: ProductionOrderRecord, onSaved: @escaping () -> Void) {
        self.order = order
        self.onSaved = onSaved
        _quantity = State(initialValue: String(order.quantity))
        _priority = State(initialValue: order.priority ?? "normal")
        _dueDate = State(initialValue: order.dueDate ?? Date())
        _fitting = State(initialValue: order.r.date("fitting_date"))
        _collection = State(initialValue: order.r.date("collection_date"))
        _notes = State(initialValue: order.r.string("notes") ?? "")
        _measurements = State(initialValue: (order.r["measurements"]?.stringPairs ?? []).map { KeyValueRow(key: $0.0, value: $0.1) })
    }

    /// Quantity is structural: free while draft; a reduction only with
    /// production.delete_order afterwards (ProductionController::update).
    private var quantityEditable: Bool { order.status == "draft" || env.session.can("production.delete_order") }

    var body: some View {
        ModuleFormSheet("Edit \(order.number)", error: error, submit: submit) {
            HStack {
                ModuleField("Quantity", error: error?.errors["quantity"]?.first) { TextField("", text: $quantity).textFieldStyle(.roundedBorder).disabled(!quantityEditable) }
                ModuleField("Priority") { Picker("", selection: $priority) { ForEach(ProductionStatus.priorities, id: \.self) { Text($0.humanized).tag($0) } } }
            }
            if !quantityEditable { Text("Quantity can only change while the order is a draft.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
            ModuleField("Due date") { DatePicker("", selection: $dueDate, displayedComponents: .date).labelsHidden() }
            ProductionOptionalDate(label: "Fitting date", date: $fitting)
            ProductionOptionalDate(label: "Collection date", date: $collection)
            KeyValueEditor(title: "Measurements", rows: $measurements)
            ModuleField("Notes") { TextField("Notes", text: $notes, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...5) }
        }
    }

    private func submit() async {
        var body: [String: JSONValue] = [
            "priority": .string(priority), "due_date": .string(apiDay(dueDate)), "notes": .str(notes),
            "fitting_date": fitting.map { .string(apiDay($0)) } ?? .null,
            "collection_date": collection.map { .string(apiDay($0)) } ?? .null,
            "measurements": .stringMap(measurements.map { ($0.key, $0.value) }),
        ]
        if quantityEditable, let q = Int(quantity), q > 0, q != order.quantity { body["quantity"] = .int(q) }
        do {
            _ = try await env.session.client.put("/v1/admin/production-orders/\(order.id)", json: .object(body))
            env.toasts.show("Order updated"); onSaved(); dismiss()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}

struct ProductionOptionalDate: View {
    let label: String
    @Binding var date: Date?
    var body: some View {
        ModuleField(label) {
            HStack {
                Toggle("", isOn: Binding(get: { date != nil }, set: { date = $0 ? (date ?? Date()) : nil })).labelsHidden()
                if let d = date {
                    DatePicker("", selection: Binding(get: { d }, set: { date = $0 }), displayedComponents: .date).labelsHidden()
                } else { Text("Not set").font(Theme.Font.secondary).foregroundStyle(Theme.textTertiary) }
            }
        }
    }
}

// MARK: - Assign (POST …/assign {assignments: [{task_id, tailor_id, estimated_hours}]})

struct AssignTailorsSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let order: ProductionOrderRecord
    let onSaved: () -> Void
    @State private var people: Lookup?
    @State private var chosen: [Int: Int?] = [:]
    @State private var hours: [Int: String] = [:]
    @State private var error: APIError?

    var body: some View {
        ModuleFormSheet("Assign stages — \(order.number)", submitLabel: "Save assignments", canSubmit: chosen.values.contains { $0 != nil }, error: error, submit: submit) {
            if let people {
                ForEach(Array(order.tasks.enumerated()), id: \.offset) { _, t in
                    let tid = t["id"]?.int ?? 0
                    VStack(alignment: .leading, spacing: 4) {
                        HStack { Text(t["stage"]?["name"]?.string ?? "Stage").font(Theme.Font.body.weight(.semibold)); StatusBadge(t["status"]?.string) }
                        HStack {
                            LookupPicker(label: "Person", lookup: people, selection: Binding(
                                get: { chosen[tid] ?? (t["assigned_to"]?.int ?? t["assigned_to"]?["id"]?.int) },
                                set: { chosen[tid] = $0 }))
                            TextField("Hours", text: Binding(get: { hours[tid] ?? (t["estimated_hours"]?.string ?? "") }, set: { hours[tid] = $0 })).textFieldStyle(.roundedBorder).frame(width: 80)
                        }
                    }
                    Divider()
                }
                Text("Handing a stage to someone keeps its progress. Only changed rows are sent.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            }
        }
        .task { if people == nil { people = Lookup.assignable(env.session.client) } }
    }

    private func submit() async {
        let rows: [JSONValue] = chosen.compactMap { tid, who in
            guard let who else { return nil }
            var row: [String: JSONValue] = ["task_id": .int(tid), "tailor_id": .int(who)]
            if let h = hours[tid], let v = JSONValue.decimal(h) { row["estimated_hours"] = v }
            return .object(row)
        }
        do {
            _ = try await env.session.client.post("/v1/admin/production-orders/\(order.id)/assign", json: .object(["assignments": .array(rows)]))
            env.toasts.show("Assignments saved"); onSaved(); dismiss()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}

// MARK: - Issue materials (POST …/materials {allocations: [{allocation_id, quantity}]})

struct IssueMaterialsSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let order: ProductionOrderRecord
    let onSaved: () -> Void
    @State private var qty: [Int: String] = [:]
    @State private var error: APIError?

    var body: some View {
        ModuleFormSheet("Issue materials — \(order.number)", submitLabel: "Issue", canSubmit: qty.values.contains { (Double($0) ?? 0) > 0 }, error: error, submit: submit) {
            ForEach(Array(order.allocations.enumerated()), id: \.offset) { _, a in
                let aid = a["id"]?.int ?? 0
                HStack {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(a["material"]?["name"]?.string ?? "Material").font(Theme.Font.body)
                        Text("required \(quantityText(a["quantity_required"])) · issued \(quantityText(a["quantity_allocated"])) \(a["material"]?["unit_of_measure"]?.string ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    }
                    Spacer()
                    TextField("Qty", text: Binding(get: { qty[aid] ?? "" }, set: { qty[aid] = $0 })).textFieldStyle(.roundedBorder).frame(width: 90)
                }
            }
            Text("Stock is deducted from material inventory as issued; a shortage is refused by the server with the available figure.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
    }

    private func submit() async {
        let rows: [JSONValue] = qty.compactMap { id, text in
            guard let v = JSONValue.decimal(text), (v.double ?? 0) > 0 else { return nil }
            return .object(["allocation_id": .int(id), "quantity": v])
        }
        do {
            _ = try await env.session.client.post("/v1/admin/production-orders/\(order.id)/materials", json: .object(["allocations": .array(rows)]))
            env.toasts.show("Materials issued"); onSaved(); dismiss()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}

// MARK: - QC (POST …/qc {passed, passed_quantity, failed_quantity, defect_types, notes})

struct QCSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let order: ProductionOrderRecord
    let onDone: () -> Void
    @State private var passed = true
    @State private var passedQty: String
    @State private var failedQty = "0"
    @State private var defects: Set<String> = []
    @State private var notes = ""
    @State private var error: APIError?

    static let defectTypes = ["stitching", "measurement", "fabric", "finishing", "embroidery", "colour", "other"]

    init(order: ProductionOrderRecord, onDone: @escaping () -> Void) {
        self.order = order
        self.onDone = onDone
        _passedQty = State(initialValue: String(order.quantity))
    }

    var body: some View {
        ModuleFormSheet("Quality check — \(order.number)", submitLabel: passed ? "Record pass" : "Record fail", error: error, submit: submit) {
            Picker("Result", selection: $passed) { Text("Passed").tag(true); Text("Failed").tag(false) }.pickerStyle(.segmented)
            HStack {
                ModuleField("Passed pieces", error: error?.errors["passed_quantity"]?.first) { TextField("", text: $passedQty).textFieldStyle(.roundedBorder) }
                ModuleField("Failed pieces", error: error?.errors["failed_quantity"]?.first) { TextField("", text: $failedQty).textFieldStyle(.roundedBorder) }
            }
            if !passed {
                ModuleField("Defects") {
                    TileGrid(minWidth: 120) {
                        ForEach(Self.defectTypes, id: \.self) { d in
                            Toggle(d.humanized, isOn: Binding(get: { defects.contains(d) }, set: { if $0 { defects.insert(d) } else { defects.remove(d) } })).toggleStyle(.button)
                        }
                    }
                }
            }
            ModuleField("Notes") { TextField("What the inspector found", text: $notes, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...5) }
            Text("Whoever worked on this order cannot record its QC — the server refuses a maker's own check. Reference photos belong on the order's batches.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
    }

    private func submit() async {
        let body: JSONValue = .object([
            "passed": .bool(passed), "passed_quantity": .int(Int(passedQty) ?? 0), "failed_quantity": .int(Int(failedQty) ?? 0),
            "defect_types": .array(defects.sorted().map { JSONValue.string($0) }), "notes": .str(notes),
        ])
        do {
            _ = try await env.session.client.post("/v1/admin/production-orders/\(order.id)/qc", json: body)
            env.toasts.show(passed ? "QC passed" : "QC failed — recorded on the order", tone: passed ? .success : .warning); onDone(); dismiss()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}

// MARK: - Rework (POST …/rework {reason, stages: [{task_id, pieces?, assigned_to?}]})

struct ReworkSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let order: ProductionOrderRecord
    let onSaved: () -> Void
    @State private var reason = ""
    @State private var picked: Set<Int> = []
    @State private var pieces: [Int: String] = [:]
    @State private var assignee: [Int: Int?] = [:]
    @State private var people: Lookup?
    @State private var error: APIError?

    var body: some View {
        ModuleFormSheet("Send back for rework — \(order.number)", submitLabel: "Send back", canSubmit: !picked.isEmpty && !reason.trimmingCharacters(in: .whitespaces).isEmpty, error: error, submit: submit) {
            ModuleField("Reason", error: error?.errors["reason"]?.first) { TextField("What must be redone", text: $reason, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(2...4) }
            if let people {
                ForEach(Array(order.tasks.enumerated()), id: \.offset) { _, t in
                    let tid = t["id"]?.int ?? 0
                    VStack(alignment: .leading, spacing: 4) {
                        Toggle(t["stage"]?["name"]?.string ?? "Stage", isOn: Binding(get: { picked.contains(tid) }, set: { if $0 { picked.insert(tid) } else { picked.remove(tid) } }))
                        if picked.contains(tid) {
                            HStack {
                                TextField("Pieces (blank = whole stage)", text: Binding(get: { pieces[tid] ?? "" }, set: { pieces[tid] = $0 })).textFieldStyle(.roundedBorder).frame(maxWidth: 200)
                                LookupPicker(label: "Reassign to", lookup: people, selection: Binding(get: { assignee[tid] ?? nil }, set: { assignee[tid] = $0 }))
                            }
                        }
                    }
                }
            }
        }
        .task { if people == nil { people = Lookup.assignable(env.session.client) } }
    }

    private func submit() async {
        let stages: [JSONValue] = picked.sorted().map { tid in
            var row: [String: JSONValue] = ["task_id": .int(tid)]
            if let p = pieces[tid], let n = Int(p), n > 0 { row["pieces"] = .int(n) }
            if let a = assignee[tid], let a { row["assigned_to"] = .int(a) }
            return .object(row)
        }
        do {
            _ = try await env.session.client.post("/v1/admin/production-orders/\(order.id)/rework", json: .object(["reason": .string(reason.trimmingCharacters(in: .whitespaces)), "stages": .array(stages)]))
            env.toasts.show("Sent back for rework — the tailors have been told"); onSaved(); dismiss()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}

// MARK: - Complete & stock (POST …/complete {outlet_id?, final_quantity?})

struct CompleteAndStockSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let order: ProductionOrderRecord
    let onDone: () -> Void
    @State private var outlets: Lookup?
    @State private var outletId: Int?
    @State private var finalQty: String
    @State private var error: APIError?

    init(order: ProductionOrderRecord, onDone: @escaping () -> Void) {
        self.order = order
        self.onDone = onDone
        _finalQty = State(initialValue: String(order.quantity))
        _outletId = State(initialValue: order.r.int("outlet_id"))
    }

    var body: some View {
        ModuleFormSheet("Complete & stock — \(order.number)", submitLabel: "Complete", canSubmit: (Int(finalQty) ?? 0) > 0, error: error, submit: submit) {
            if let outlets { ModuleField("Stock into outlet", error: error?.errors["outlet_id"]?.first) { LookupPicker(label: "Outlet", lookup: outlets, selection: $outletId) } }
            ModuleField("Final quantity", error: error?.errors["final_quantity"]?.first) { TextField("", text: $finalQty).textFieldStyle(.roundedBorder) }
            Text(order.isCustomerJob ? "A customer's garment is held for them and leaves stock when collected." : "Finished goods enter stock once; the server refuses a second completion.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
        .task { if outlets == nil { outlets = Lookup.outlets(env.session.client) } }
    }

    private func submit() async {
        do {
            let r = try await env.session.client.post("/v1/admin/production-orders/\(order.id)/complete", json: .object(["outlet_id": .int(outletId), "final_quantity": .int(Int(finalQty) ?? order.quantity)]))
            env.toasts.show(r.json?["message"]?.string ?? "Completed and stocked"); onDone(); dismiss()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}

// MARK: - Batches (PUT …/batches {batches: [{label, quantity, attributes}]})

struct BatchesSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let order: ProductionOrderRecord
    let onSaved: () -> Void
    @State private var rows: [BatchDraft]
    @State private var error: APIError?

    struct BatchDraft: Identifiable { let id = UUID(); var label: String; var quantity: String; var attrs: String }

    init(order: ProductionOrderRecord, onSaved: @escaping () -> Void) {
        self.order = order
        self.onSaved = onSaved
        _rows = State(initialValue: order.batches.map { b in
            BatchDraft(label: b["label"]?.string ?? "", quantity: b["quantity"]?.string ?? "", attrs: (b["attributes"]?.stringPairs ?? []).map { "\($0.0)=\($0.1)" }.joined(separator: ", "))
        })
    }

    private var sum: Int { rows.reduce(0) { $0 + (Int($1.quantity) ?? 0) } }
    private var ok: Bool { rows.isEmpty || (sum == order.quantity && rows.allSatisfy { !$0.label.trimmingCharacters(in: .whitespaces).isEmpty && (Int($0.quantity) ?? 0) > 0 }) }

    var body: some View {
        ModuleFormSheet("Colourway batches — \(order.number)", canSubmit: ok, error: error, submit: submit) {
            Text("Quantities must add up to exactly \(order.quantity). Batches can only be redefined before any pieces are counted; an empty set removes batching.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            ForEach($rows) { $row in
                HStack {
                    TextField("Label", text: $row.label).textFieldStyle(.roundedBorder)
                    TextField("Qty", text: $row.quantity).textFieldStyle(.roundedBorder).frame(width: 70)
                    TextField("trim=blue, size=M", text: $row.attrs).textFieldStyle(.roundedBorder)
                    Button { rows.removeAll { $0.id == row.id } } label: { Image(systemName: "minus.circle") }.buttonStyle(.plain).foregroundStyle(Theme.Palette.danger)
                }
            }
            Button { rows.append(BatchDraft(label: "", quantity: "", attrs: "")) } label: { Label("Add batch", systemImage: "plus") }
            Text("Total \(sum) of \(order.quantity)").font(Theme.Font.secondary).foregroundStyle(sum == order.quantity || rows.isEmpty ? Theme.Palette.success : Theme.Palette.danger)
        }
    }

    private func submit() async {
        let batches: [JSONValue] = rows.map { r in
            let pairs = r.attrs.split(separator: ",").compactMap { part -> (String, String)? in
                let kv = part.split(separator: "=", maxSplits: 1).map { $0.trimmingCharacters(in: .whitespaces) }
                return kv.count == 2 ? (kv[0], kv[1]) : nil
            }
            return .object(["label": .string(r.label.trimmingCharacters(in: .whitespaces)), "quantity": .int(Int(r.quantity) ?? 0), "attributes": pairs.isEmpty ? .null : .stringMap(pairs)])
        }
        do {
            _ = try await env.session.client.put("/v1/admin/production-orders/\(order.id)/batches", json: .object(["batches": .array(batches)]))
            env.toasts.show("Batches saved"); onSaved(); dismiss()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}
