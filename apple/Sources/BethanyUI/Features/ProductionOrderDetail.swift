import SwiftUI
import BethanyKit

/// One production order (GET /v1/admin/production-orders/{id} → {order}).
/// Every action is shown only when the order's status allows it and the
/// person holds the permission the route checks.
struct ProductionOrderDetailView: View {
    @Environment(AppEnvironment.self) private var env
    let orderId: Int
    @State private var res: Resource<Record>?
    @State private var sheet: Sheet?
    @State private var showAudit = false

    enum Sheet: Identifiable {
        case edit, assign, materials, qc, rework, complete, batches, cancel
        var id: Int { hashValue }
    }

    var body: some View {
        Page("Production order") {
            if let res {
                LoadableView(res.state, retry: { res.load() }) { r in
                    let o = ProductionOrderRecord(r)
                    header(o)
                    actions(o)
                    tasks(o)
                    if !o.batches.isEmpty || canEdit(o) { batchesSection(o) }
                    if !o.allocations.isEmpty || r["material_requirements"]?.array?.isEmpty == false { materials(o) }
                    details(o)
                    ProductionMessagesSection(orderId: orderId)
                    if showAudit { ModuleAuditLog(path: "/v1/admin/production-orders/\(orderId)/audit-log") }
                    else { Button("Show audit log") { showAudit = true }.font(Theme.Font.secondary) }
                }
            }
        }
        .task { if res == nil { load() } }
        .refreshable { await res?.reload() }
        .sheet(item: $sheet) { s in
            if let r = res?.value {
                let o = ProductionOrderRecord(r)
                switch s {
                case .edit: EditProductionOrderSheet(order: o, onSaved: reload)
                case .assign: AssignTailorsSheet(order: o, onSaved: reload)
                case .materials: IssueMaterialsSheet(order: o, onSaved: reload)
                case .qc: QCSheet(order: o, onDone: reload)
                case .rework: ReworkSheet(order: o, onSaved: reload)
                case .complete: CompleteAndStockSheet(order: o, onDone: reload)
                case .batches: BatchesSheet(order: o, onSaved: reload)
                case .cancel: TextPromptSheet("Cancel \(o.number)", placeholder: "Reason (optional)", required: false, confirmLabel: "Cancel order", destructive: true) { reason in
                    await post("cancel", json: .object(["reason": .str(reason)]), success: "Order cancelled")
                }
                }
            }
        }
    }

    private func load() {
        let c = env.session.client, id = orderId
        res = Resource {
            let j = try await c.get("/v1/admin/production-orders/\(id)").json
            return Record(json: j?["order"] ?? j ?? .null)
        }
        res?.load()
    }

    private func reload() { res?.load() }

    private func canEdit(_ o: ProductionOrderRecord) -> Bool {
        env.session.can("production.raise_order") && ProductionStatus.editable.contains(o.status ?? "")
    }

    // MARK: Sections

    private func header(_ o: ProductionOrderRecord) -> some View {
        SectionCard {
            HStack(alignment: .top, spacing: Theme.Space.m) {
                if let img = o.image, let url = URL(string: img) {
                    AsyncImage(url: url) { $0.resizable().scaledToFill() } placeholder: { Color.clear }
                        .frame(width: 72, height: 72).clipShape(RoundedRectangle(cornerRadius: Theme.Radius.field))
                }
                VStack(alignment: .leading, spacing: 4) {
                    HStack { Text(o.number).font(Theme.Font.display(20)); StatusBadge(o.status); PriorityBadge(priority: o.priority); if o.isOverdue { StatusBadge("overdue", tone: .danger) } }
                    Text("\(o.quantity) × \(o.productName)").font(Theme.Font.body)
                    if let sku = o.productSku { Text("SKU \(sku)").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                    if let c = o.customerLabel {
                        Text("For \(c)").font(Theme.Font.secondary)
                        if let contact = o.r.string("customer_contact") { Text(contact).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                    } else { Text("For stock").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary) }
                }
                Spacer()
                VStack(alignment: .trailing, spacing: 2) {
                    Text("\(o.percent)%").font(Theme.Font.stat).monospacedDigit()
                    if let p = o.r["progress"], let f = p["finished"]?.int, let s = p["stages"]?.int { Text("\(f)/\(s) stages").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                }
            }
            ProgressView(value: Double(o.percent), total: 100).tint(Theme.Palette.brand)
            HStack {
                DetailRow("Due") { DateText(o.dueDate, style: .date) }
                DetailRow("Started") { DateText(o.r.date("started_at"), style: .date) }
            }
        }
    }

    @ViewBuilder
    private func actions(_ o: ProductionOrderRecord) -> some View {
        let s = env.session
        let status = o.status ?? ""
        let floor = ProductionStatus.acceptsFloorWork(status)
        HStack(spacing: Theme.Space.s) {
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: Theme.Space.s) {
                    if status == "draft", s.can("production.confirm_order") {
                        ConfirmButton("Confirm order", message: "Tasks are created from the product's stages and serials are reserved for \(o.quantity) pieces.", action: { await confirm(force: false) }) { Label("Confirm", systemImage: "checkmark.seal") }
                            .buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                    }
                    if floor, s.can("production.manage_assignees") {
                        Button { sheet = .assign } label: { Label("Assign", systemImage: "person.2") }
                        Button { sheet = .materials } label: { Label("Issue materials", systemImage: "cube") }.disabled(o.allocations.isEmpty)
                        ConfirmButton("Add missing tasks", message: "Seeds a task for every active stage this order lacks. Existing tasks are kept.", action: { await post("regenerate-tasks", success: nil) }) { Label("Regenerate tasks", systemImage: "arrow.clockwise") }
                    }
                    if status == "qc_pending", s.can("production.submit_qc") {
                        Button { sheet = .qc } label: { Label("Quality check", systemImage: "checkmark.shield") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                    }
                    if status == "qc_failed", s.can("production.submit_qc") {
                        Button { sheet = .rework } label: { Label("Send back for rework", systemImage: "arrow.uturn.backward") }.buttonStyle(.borderedProminent).tint(Theme.Palette.warning)
                    }
                    if status == "qc_passed", s.can("production.approve_qc") {
                        Button { sheet = .complete } label: { Label("Complete & stock", systemImage: "shippingbox") }.buttonStyle(.borderedProminent).tint(Theme.Palette.success)
                    }
                    if canEdit(o) {
                        Button { sheet = .edit } label: { Label("Edit", systemImage: "pencil") }
                        Button { sheet = .batches } label: { Label("Batches", systemImage: "square.grid.2x2") }
                    }
                    if ProductionStatus.cancellable.contains(status), s.can("production.confirm_order") {
                        Button(role: .destructive) { sheet = .cancel } label: { Label("Cancel", systemImage: "xmark.circle") }
                    }
                    if status != "completed", s.can("production.delete_order") {
                        ConfirmButton("Delete permanently", message: "Removes the order and its tasks, batches and allocations; serials are voided and the linked sales line unlinked. This cannot be undone.", destructive: true, action: { await forceDelete() }) { Label("Delete", systemImage: "trash") }
                    }
                    DocumentButton("PDF", path: "/v1/admin/pdf/production-orders/\(orderId)", suggestedName: "\(o.number).pdf")
                }
            }
        }
        .buttonStyle(.bordered)
    }

    private func tasks(_ o: ProductionOrderRecord) -> some View {
        SectionCard("Stages") {
            if o.tasks.isEmpty { Text(o.status == "draft" ? "Tasks are created when the order is confirmed." : "No tasks on this order.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary) }
            ForEach(Array(o.tasks.enumerated()), id: \.offset) { _, t in
                ProductionTaskRow(task: t, order: o, onChanged: reload)
                Divider()
            }
        }
    }

    private func batchesSection(_ o: ProductionOrderRecord) -> some View {
        SectionCard("Colourway batches") {
            if o.batches.isEmpty { Text("Not batched — the whole order counts as one.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary) }
            ForEach(Array(o.batches.enumerated()), id: \.offset) { _, b in
                BatchRow(orderId: orderId, batch: b, canEdit: canEdit(o), onChanged: reload)
            }
        }
    }

    private func materials(_ o: ProductionOrderRecord) -> some View {
        SectionCard("Materials") {
            let reqs = o.r["material_requirements"]?.array ?? []
            ForEach(Array(reqs.enumerated()), id: \.offset) { _, m in
                HStack {
                    VStack(alignment: .leading) {
                        Text(m["material_name"]?.string ?? "Material").font(Theme.Font.body)
                        Text("Needs \(quantityText(m["required"])) \(m["unit"]?.string ?? "") · available \(quantityText(m["available"]))").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    }
                    Spacer()
                    if m["is_short"]?.bool == true { StatusBadge("short \(quantityText(m["shortage"]))", tone: .danger) }
                }
            }
            if !o.allocations.isEmpty {
                Divider()
                ForEach(Array(o.allocations.enumerated()), id: \.offset) { _, a in
                    HStack {
                        Text(a["material"]?["name"]?.string ?? "Material").font(Theme.Font.secondary)
                        Spacer()
                        Text("issued \(quantityText(a["quantity_allocated"])) / \(quantityText(a["quantity_required"])) \(a["material"]?["unit_of_measure"]?.string ?? "")").font(Theme.Font.caption).monospacedDigit()
                        if env.session.canViewCost, let c = a["material"]?["unit_cost"]?.money { Text("@ \(c.formatted())").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                    }
                }
            }
        }
    }

    private func details(_ o: ProductionOrderRecord) -> some View {
        SectionCard("Details") {
            DetailRow("Outlet", o.r["outlet"]?["name"]?.string)
            DetailRow("Raised by", Names.person(o.r["created_by"]))
            DetailRow("Created") { DateText(o.r.date("created_at")) }
            DetailRow("Fitting") { DateText(o.r.date("fitting_date"), style: .date) }
            DetailRow("Collection") { DateText(o.r.date("collection_date"), style: .date) }
            if let n = o.r.string("notes"), !n.isEmpty { DetailRow("Notes", n) }
            if let sale = o.r["customer_order"]?["order_number"]?.string, let sid = o.r.int("customer_order_id") {
                HStack { Text("Sales order").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary); Spacer(); Button(sale) { env.navigator.push(.order(id: sid)) } }
            }
            ForEach([("Measurements", "measurements"), ("Specifications", "specifications"), ("Preferences", "customer_preferences")], id: \.0) { title, key in
                let pairs = o.r[key]?.stringPairs ?? []
                if !pairs.isEmpty {
                    Text(title).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).textCase(.uppercase).padding(.top, 4)
                    ForEach(pairs, id: \.0) { k, v in DetailRow(k.humanized, v) }
                }
            }
        }
    }

    // MARK: Actions

    private func post(_ action: String, json: JSONValue = .object([:]), success: String?) async {
        do {
            let r = try await env.session.client.post("/v1/admin/production-orders/\(orderId)/\(action)", json: json)
            env.toasts.show(success ?? r.json?["message"]?.string ?? "Done")
            reload()
        } catch { env.toasts.error(error) }
    }

    private func confirm(force: Bool) async {
        do {
            _ = try await env.session.client.post("/v1/admin/production-orders/\(orderId)/confirm", json: force ? .object(["force": .bool(true)]) : .object([:]))
            env.toasts.show("Order confirmed")
            reload()
        } catch let e as APIError where e.reason == "pending_payment_approval" && !force && env.session.me?.isSuperAdmin == true {
            env.toasts.show("\(e.message) Forcing past the payment hold…", tone: .warning)
            await confirm(force: true)
        } catch { env.toasts.error(error) }
    }

    private func forceDelete() async {
        do {
            _ = try await env.session.client.delete("/v1/admin/production-orders/\(orderId)/force")
            env.toasts.show("Production order deleted")
            env.navigator.go(.productionOrders)
        } catch { env.toasts.error(error) }
    }
}

// MARK: - Task row (stage actions through /v1/tailor/tasks/{id}/status, unlock)

struct ProductionTaskRow: View {
    @Environment(AppEnvironment.self) private var env
    let task: JSONValue
    let order: ProductionOrderRecord
    let onChanged: () -> Void

    var body: some View {
        let status = task["status"]?.string ?? "pending"
        let assignee = Names.person(task["assigned_to_user"] ?? task["assigned_to"])
        let done = task["quantity_done"]?.int ?? 0
        HStack(alignment: .top) {
            VStack(alignment: .leading, spacing: 3) {
                HStack {
                    if let seq = task["sequence"]?.int { Text("\(seq).").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                    Text(task["stage"]?["name"]?.string ?? "Stage").font(Theme.Font.body.weight(.semibold))
                    StatusBadge(status)
                    if task["concurrent_allowed"]?.bool == true { StatusBadge("unlocked", tone: .accent) }
                }
                Text(assignee.map { "Assigned to \($0)" } ?? "Unassigned").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                Text("\(done) of \(order.quantity) pieces").font(Theme.Font.caption).monospacedDigit().foregroundStyle(Theme.textSecondary)
            }
            Spacer()
            if env.session.can("production.manage_assignees"), ProductionStatus.acceptsFloorWork(order.status) {
                Menu {
                    if status == "pending" || status == "paused" { Button("Start") { Task { await act("start") } } }
                    if status == "in_progress" { Button("Pause") { Task { await act("pause") } } }
                    if status != "completed" { Button("Mark complete") { Task { await act("complete") } } }
                    if task["started_at"]?.isNull != false, task["concurrent_allowed"]?.bool != true {
                        Button("Unlock to run in parallel") { Task { await unlock(true) } }
                    } else if task["concurrent_allowed"]?.bool == true, task["started_at"]?.isNull != false {
                        Button("Re-lock") { Task { await unlock(false) } }
                    }
                } label: { Image(systemName: "ellipsis.circle") }
            }
        }
        .padding(.vertical, 4)
    }

    private func act(_ action: String) async {
        guard let id = task["id"]?.int else { return }
        do {
            _ = try await env.session.client.put("/v1/tailor/tasks/\(id)/status", json: .object(["action": .string(action)]))
            env.toasts.show("Stage \(action == "complete" ? "completed" : action == "pause" ? "paused" : "started")")
            onChanged()
        } catch { env.toasts.error(error) }
    }

    private func unlock(_ allow: Bool) async {
        guard let id = task["id"]?.int else { return }
        do {
            _ = try await env.session.client.post("/v1/tailor/tasks/\(id)/unlock", json: .object(["allow": .bool(allow)]))
            env.toasts.show(allow ? "Stage unlocked — it may run alongside earlier stages" : "Stage re-locked")
            onChanged()
        } catch { env.toasts.error(error) }
    }
}

// MARK: - Batch row with reference photos (multipart POST …/batches/{b}/images)

struct BatchRow: View {
    @Environment(AppEnvironment.self) private var env
    let orderId: Int
    let batch: JSONValue
    let canEdit: Bool
    let onChanged: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Text(batch["label"]?.string ?? "Batch").font(Theme.Font.body.weight(.semibold))
                Text("× \(batch["quantity"]?.int ?? 0)").font(Theme.Font.secondary).monospacedDigit()
                Spacer()
                if canEdit { PhotoAttachButton { data, name in await upload(data, name) }.font(Theme.Font.secondary) }
            }
            let attrs = batch["attributes"]?.stringPairs ?? []
            if !attrs.isEmpty { Text(attrs.map { "\($0.0): \($0.1)" }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
            let images = batch["images"]?.array?.compactMap(\.string) ?? []
            if !images.isEmpty {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: Theme.Space.s) {
                        ForEach(images, id: \.self) { u in
                            AsyncImage(url: URL(string: u)) { $0.resizable().scaledToFill() } placeholder: { Theme.line }
                                .frame(width: 64, height: 64).clipShape(RoundedRectangle(cornerRadius: 8))
                                .contextMenu { if canEdit { Button("Remove photo", role: .destructive) { Task { await remove(u) } } } }
                        }
                    }
                }
            }
        }
        .padding(.vertical, 4)
    }

    private func upload(_ data: Data, _ name: String) async {
        guard let bid = batch["id"]?.int else { return }
        do {
            _ = try await env.session.client.uploadFile("/v1/admin/production-orders/\(orderId)/batches/\(bid)/images", file: data, field: "image", filename: name)
            env.toasts.show("Photo added")
            onChanged()
        } catch { env.toasts.error(error) }
    }

    private func remove(_ url: String) async {
        guard let bid = batch["id"]?.int else { return }
        do {
            _ = try await env.session.client.delete("/v1/admin/production-orders/\(orderId)/batches/\(bid)/images", json: .object(["url": .string(url)]))
            onChanged()
        } catch { env.toasts.error(error) }
    }
}

// MARK: - Messages thread (GET/POST …/messages)

struct ProductionMessagesSection: View {
    @Environment(AppEnvironment.self) private var env
    let orderId: Int
    @State private var res: Resource<[Record]>?
    @State private var text = ""
    @State private var type = "message"

    var body: some View {
        SectionCard("Thread") {
            if let res {
                LoadableView(res.state, retry: { res.load() }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No messages yet", symbol: "bubble.left") }) { msgs in
                    ForEach(msgs) { m in
                        HStack(alignment: .top, spacing: Theme.Space.s) {
                            Text(m["user"]?["initials"]?.string ?? "?").font(Theme.Font.caption).frame(width: 28, height: 28).background(Theme.Palette.neutralSoft, in: Circle())
                            VStack(alignment: .leading, spacing: 2) {
                                HStack { Text(Names.person(m["user"]) ?? "").font(Theme.Font.caption.weight(.semibold)); if m.string("type") == "note" { StatusBadge("note", tone: .amber) }; Spacer(); DateText(m.date("created_at"), style: .relative) }
                                Text(m.string("body") ?? "").font(Theme.Font.secondary)
                            }
                        }
                        .padding(.vertical, 3)
                    }
                }
            }
            HStack {
                Picker("", selection: $type) { Text("Message").tag("message"); Text("Note").tag("note") }.frame(maxWidth: 130)
                TextField("Write to the people on this order…", text: $text).textFieldStyle(.roundedBorder).onSubmit { Task { await send() } }
                Button("Send") { Task { await send() } }.disabled(text.trimmingCharacters(in: .whitespaces).isEmpty)
            }
        }
        .task {
            if res == nil {
                let c = env.session.client, id = orderId
                res = Resource { try (await c.get("/v1/admin/production-orders/\(id)/messages").json?["messages"]?.decode([Record].self)) ?? [] }
                res?.load()
            }
        }
    }

    private func send() async {
        let body = text.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !body.isEmpty else { return }
        do {
            _ = try await env.session.client.post("/v1/admin/production-orders/\(orderId)/messages", json: .object(["body": .string(body), "type": .string(type)]))
            text = ""
            res?.load()
        } catch { env.toasts.error(error) }
    }
}
