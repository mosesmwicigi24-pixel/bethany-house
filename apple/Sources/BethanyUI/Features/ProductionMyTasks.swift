import SwiftUI
import BethanyKit

/// My Tasks (/production/my-tasks): the floor worker's workspace, ported
/// from TailorWorkspacePage. GET /v1/tailor/tasks?include_order_context=true
/// (open work plus finished stages on orders still moving) or
/// ?include_completed=true (history). Start / pause / done go to
/// PUT …/status, piece counts to POST …/progress (per batch on a batched
/// order), notes to POST …/note, and a manager may unlock a stage.
///
/// Offline: a status or progress write that fails with `APIError.isOffline`
/// is queued in `OfflineStore` (dedupe keys `task-<id>-status` and
/// `task-<id>-progress-<stage>`), the queued value stays on screen, and
/// `AppEnvironment.replayPending` sends it when the connection returns.
struct MyTasksView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var tasks: Resource<[Record]>?
    @State private var history = false
    @State private var pendingKeys: Set<String> = []
    @State private var noteFor: Record?
    @State private var completed: Record?

    var body: some View {
        Page("My Tasks", subtitle: env.session.me.map { "Your stages, \($0.name.split(separator: " ").first ?? "")" }) {
            HStack {
                Picker("", selection: $history) { Text("Active").tag(false); Text("History").tag(true) }.pickerStyle(.segmented).frame(maxWidth: 260)
                Spacer()
                if env.connection.pendingCount > 0 || !pendingKeys.isEmpty {
                    Label("\(max(env.connection.pendingCount, pendingKeys.count)) waiting to send", systemImage: "arrow.up.circle.dotted").font(Theme.Font.caption).foregroundStyle(Theme.Palette.amber)
                }
            }
            if let tasks {
                LoadableView(tasks.state, retry: { tasks.load() }, isEmpty: { $0.isEmpty }, empty: { EmptyState(history ? "No finished stages yet" : "Nothing on your bench", message: history ? nil : "When a stage is assigned to you it shows here.", symbol: "scissors") }) { rows in
                    ForEach(groups(rows)) { g in
                        SectionCard {
                            orderHeader(g)
                            ForEach(g.tasks) { t in
                                TaskCard(task: t, order: g.order, pending: pendingKeys.contains("task-\(t.id)-status") || pendingKeys.contains { $0.hasPrefix("task-\(t.id)-progress") },
                                         onAction: { action in await act(t, action) },
                                         onCount: { qty, batch in await count(t, qty: qty, batch: batch) },
                                         onNote: { noteFor = t })
                            }
                        }
                    }
                }
            }
        }
        .task {
            if tasks == nil { await load() }
            pendingKeys = Set((await env.offline?.pending() ?? []).compactMap(\.dedupeKey))
        }
        .onChange(of: history) { _, _ in Task { await load() } }
        .onChange(of: env.connection.isOnline) { _, online in if online { Task { await env.replayPending(); pendingKeys = Set((await env.offline?.pending() ?? []).compactMap(\.dedupeKey)); tasks?.load() } } }
        .refreshable { await tasks?.reload() }
        .sheet(item: $noteFor) { t in
            TextPromptSheet("Note on \(t["stage"]?["name"]?.string ?? "stage")", placeholder: "What the next bench should know", confirmLabel: "Post note") { text in
                do { _ = try await env.session.client.post("/v1/tailor/tasks/\(t.id)/note", json: .object(["note": .string(text)])); env.toasts.show("Note posted") } catch { env.toasts.error(error) }
            }
        }
        .sheet(item: $completed) { t in
            VStack(spacing: Theme.Space.l) {
                Image(systemName: "checkmark.seal.fill").font(.system(size: 48)).foregroundStyle(Theme.Palette.success)
                Text("\(t["stage"]?["name"]?.string ?? "Stage") done").font(Theme.Font.display(22))
                Text(t["production_order"]?["order_number"]?.string ?? "").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                Button("Back to my bench") { completed = nil }.buttonStyle(.primary)
            }
            .padding(Theme.Space.xxl).frame(minWidth: 320)
        }
    }

    private func load() async {
        let c = env.session.client, h = history, offline = env.offline
        let key = h ? "tailor-tasks-history" : "tailor-tasks-active"
        tasks = Resource {
            do {
                let j = try await c.get("/v1/tailor/tasks", query: h ? ["include_completed": "true"] : ["include_order_context": "true"]).json ?? .array([])
                await offline?.put(j, for: key)
                return (j.array ?? j["data"]?.array ?? []).map(Record.init(json:))
            } catch let e as APIError where e.isOffline {
                if let cached = await offline?.get(key) { return (cached.value.array ?? []).map(Record.init(json:)) }
                throw e
            }
        }
        await tasks?.reload()
    }

    // MARK: Grouping (one card per order, stages in sequence)

    struct OrderGroup: Identifiable {
        let id: Int
        let order: JSONValue
        let tasks: [Record]
    }

    private func groups(_ rows: [Record]) -> [OrderGroup] {
        var order: [Int] = []
        var byOrder: [Int: [Record]] = [:]
        for t in rows {
            let oid = t["production_order"]?["id"]?.int ?? t.int("production_order_id") ?? 0
            if byOrder[oid] == nil { order.append(oid) }
            byOrder[oid, default: []].append(t)
        }
        return order.map { oid in
            let ts = byOrder[oid]!.sorted { ($0["sequence"]?.int ?? 0) < ($1["sequence"]?.int ?? 0) }
            return OrderGroup(id: oid, order: ts.first?["production_order"] ?? .null, tasks: ts)
        }
    }

    private func orderHeader(_ g: OrderGroup) -> some View {
        let o = g.order
        let name = o["product"]?["translations"]?[0]?["name"]?.string ?? o["product_name"]?.string ?? "Garment"
        return VStack(alignment: .leading, spacing: 4) {
            HStack {
                Text(o["order_number"]?.string ?? "PRD").font(Theme.Font.sectionTitle)
                PriorityBadge(priority: o["priority"]?.string)
                StatusBadge(o["status"]?.string)
                Spacer()
                if let p = o["progress"]?["percent"]?.int { Text("\(p)%").font(Theme.Font.caption).monospacedDigit().foregroundStyle(Theme.textSecondary) }
            }
            HStack(spacing: Theme.Space.s) {
                if let img = o["product"]?["images"]?[0]?["image_url"]?.string, let url = URL(string: img) {
                    AsyncImage(url: url) { $0.resizable().scaledToFill() } placeholder: { Color.clear }.frame(width: 44, height: 44).clipShape(RoundedRectangle(cornerRadius: 8))
                }
                VStack(alignment: .leading, spacing: 2) {
                    Text("\(o["quantity"]?.int ?? 0) × \(name)").font(Theme.Font.body)
                    HStack(spacing: Theme.Space.s) {
                        if let c = o["customer_label"]?.string { Label(c, systemImage: "person").font(Theme.Font.caption) }
                        Label { DateText(o["due_date"]?.date, style: .date) } icon: { Image(systemName: "calendar") }.font(Theme.Font.caption)
                    }
                    .foregroundStyle(Theme.textSecondary)
                }
            }
            let specs = (o["specifications"]?.stringPairs ?? []) + (o["measurements"]?.stringPairs ?? [])
            if !specs.isEmpty {
                DisclosureGroup("Specs & measurements") {
                    ForEach(specs, id: \.0) { k, v in DetailRow(k.humanized, v) }
                }
                .font(Theme.Font.secondary)
            }
            if let n = o["notes"]?.string, !n.isEmpty { Text(n).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
        }
    }

    // MARK: Writes (queued when offline)

    private func act(_ t: Record, _ action: String) async {
        let path = "/v1/tailor/tasks/\(t.id)/status"
        let body: JSONValue = .object(["action": .string(action)])
        do {
            _ = try await env.session.client.put(path, json: body)
            if action == "complete" { completed = t } else { env.toasts.show(action == "pause" ? "Stage paused" : "Stage started") }
            haptic(action == "complete" ? .success : .light)
            tasks?.load()
        } catch let e as APIError where e.isOffline {
            await enqueue(kind: "task_status", method: "PUT", path: path, body: body, key: "task-\(t.id)-status")
        } catch { env.toasts.error(error) }
    }

    private func count(_ t: Record, qty: Int, batch: Int?) async {
        let path = "/v1/tailor/tasks/\(t.id)/progress"
        let body: JSONValue = .object(["quantity_done": .int(qty), "batch_id": .int(batch)])
        let stage = t["stage"]?["id"]?.int ?? t.int("production_stage_id") ?? 0
        let key = "task-\(t.id)-progress-\(stage)" + (batch.map { "-batch-\($0)" } ?? "")
        do {
            _ = try await env.session.client.post(path, json: body)
            haptic(.light)
            tasks?.load()
        } catch let e as APIError where e.isOffline {
            await enqueue(kind: "task_progress", method: "POST", path: path, body: body, key: key)
        } catch {
            env.toasts.error(error)
            tasks?.load()
        }
    }

    private func enqueue(kind: String, method: String, path: String, body: JSONValue, key: String) async {
        guard let offline = env.offline else { env.toasts.show("Offline and nothing could be saved on this device.", tone: .danger); return }
        await offline.enqueue(OfflineStore.PendingOperation(kind: kind, method: method, path: path, body: body, dedupeKey: key))
        env.connection.pendingCount = await offline.pending().count
        pendingKeys.insert(key)
        env.toasts.show("You're offline — saved on this device and sent when the connection returns.", tone: .warning)
    }

    private enum Haptic { case light, success }
    private func haptic(_ kind: Haptic) {
        #if os(iOS)
        switch kind {
        case .light: UIImpactFeedbackGenerator(style: .light).impactOccurred()
        case .success: UINotificationFeedbackGenerator().notificationOccurred(.success)
        }
        #endif
    }
}

// MARK: - One stage on the bench

struct TaskCard: View {
    @Environment(AppEnvironment.self) private var env
    let task: Record
    let order: JSONValue
    let pending: Bool
    let onAction: (String) async -> Void
    let onCount: (Int, Int?) async -> Void
    let onNote: () -> Void
    @State private var busy = false

    private var status: String { task.status ?? "pending" }
    private var blocked: String? { task["blocked_by_stage"]?.string }
    private var orderOpen: Bool { ProductionStatus.acceptsFloorWork(order["status"]?.string) }
    private var quantity: Int { max(1, order["quantity"]?.int ?? 1) }

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.s) {
            HStack {
                Text(task["stage"]?["name"]?.string ?? "Stage").font(Theme.Font.body.weight(.semibold))
                StatusBadge(status)
                if pending { StatusBadge("waiting to send", tone: .amber) }
                if task["concurrent_allowed"]?.bool == true { StatusBadge("unlocked", tone: .accent) }
                Spacer()
                if let h = task["estimated_hours"]?.string, h != "0" { Text("\(h) h").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
            }
            if let d = task["stage"]?["description"]?.string, !d.isEmpty { Text(d).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
            if let blocked, task["started_at"]?.isNull != false {
                Label("Waiting on \(blocked)", systemImage: "lock").font(Theme.Font.caption).foregroundStyle(Theme.Palette.amber)
            }
            if status != "completed", orderOpen { counters }
            if status == "completed" { Text("Done — \(task["quantity_done"]?.int ?? 0) pieces").font(Theme.Font.caption).foregroundStyle(Theme.Palette.success) }
            if orderOpen { actions }
            else if let s = order["status"]?.string, ["qc_pending", "qc_failed"].contains(s) { Text(s == "qc_pending" ? "With Quality Control — counts are locked." : "Failed QC — a manager decides what happens next.").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
        }
        .padding(.vertical, 6)
        .disabled(busy)
    }

    @ViewBuilder
    private var counters: some View {
        let batches = order["batches"]?.array ?? []
        if batches.isEmpty {
            Counter(label: "Pieces through me", value: task["quantity_done"]?.int ?? 0, max: quantity) { v in await run { await onCount(v, nil) } }
        } else {
            ForEach(Array(batches.enumerated()), id: \.offset) { _, b in
                let bid = b["id"]?.int ?? 0
                let done = (task["batch_progress"]?.array ?? []).first { $0["production_order_batch_id"]?.int == bid }?["quantity_done"]?.int ?? 0
                Counter(label: b["label"]?.string ?? "Batch", value: done, max: b["quantity"]?.int ?? quantity) { v in await run { await onCount(v, bid) } }
            }
        }
    }

    private var actions: some View {
        HStack(spacing: Theme.Space.s) {
            if status == "pending" || status == "paused" {
                Button { Task { await run { await onAction("start") } } } label: { Label("Start", systemImage: "play.fill") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
            }
            if status == "in_progress" {
                Button { Task { await run { await onAction("pause") } } } label: { Label("Pause", systemImage: "pause.fill") }.buttonStyle(.bordered)
            }
            if status == "in_progress" || status == "paused" {
                ConfirmButton("Mark \(task["stage"]?["name"]?.string ?? "stage") done", message: "Tells the next bench this stage is finished for the whole order.", action: { await run { await onAction("complete") } }) { Label("Done", systemImage: "checkmark") }
                    .buttonStyle(.borderedProminent).tint(Theme.Palette.success)
            }
            Button { onNote() } label: { Image(systemName: "note.text") }.buttonStyle(.bordered).accessibilityLabel("Add note")
            if env.session.can("production.manage_assignees"), task["started_at"]?.isNull != false, task["concurrent_allowed"]?.bool != true {
                Button { Task { await unlock() } } label: { Image(systemName: "lock.open") }.buttonStyle(.bordered).accessibilityLabel("Unlock stage")
            }
            Spacer()
        }
        .controlSize(.large)
    }

    private func run(_ op: () async -> Void) async { busy = true; await op(); busy = false }

    private func unlock() async {
        do {
            _ = try await env.session.client.post("/v1/tailor/tasks/\(task.id)/unlock", json: .object(["allow": .bool(true)]))
            env.toasts.show("Stage unlocked")
        } catch { env.toasts.error(error) }
    }
}

/// A big-button piece counter: the absolute cumulative count is sent, and
/// the server validates it against the pipeline.
struct Counter: View {
    let label: String
    let value: Int
    let max: Int
    let onChange: (Int) async -> Void
    @State private var shown: Int?

    var body: some View {
        let current = shown ?? value
        HStack {
            VStack(alignment: .leading, spacing: 2) {
                Text(label).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                Text("\(current) / \(max)").font(Theme.Font.display(20)).monospacedDigit()
            }
            Spacer()
            Button { set(current - 1) } label: { Image(systemName: "minus") }.disabled(current <= 0)
            Button { set(current + 1) } label: { Image(systemName: "plus") }.disabled(current >= max)
            Button("All") { set(max) }.disabled(current >= max)
        }
        .buttonStyle(.bordered).controlSize(.large)
        .onChange(of: value) { _, _ in shown = nil }
    }

    private func set(_ v: Int) {
        let clamped = Swift.max(0, Swift.min(max, v))
        shown = clamped
        Task { await onChange(clamped) }
    }
}
