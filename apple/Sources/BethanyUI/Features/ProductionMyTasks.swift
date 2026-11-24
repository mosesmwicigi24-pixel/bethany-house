import SwiftUI
import BethanyKit

/// The queue displays the Hub's assigned work. Piece counts are cumulative,
/// including per batch, and only a Hub response can advance an order to QC.
struct MyTasksView: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @Environment(\.dynamicTypeSize) private var typeSize
    @Environment(\.colorScheme) private var colorScheme
    @State private var tasks: Resource<[Record]>?
    @State private var history = false
    @State private var selectedID: Int?
    @State private var showsDetail = false
    @State private var pending: [OfflineStore.PendingOperation] = []
    @State private var syncing = false
    @State private var noteFor: Record?
    private let initialOrderID: Int?

    init(initialOrderID: Int? = nil) {
        self.initialOrderID = initialOrderID
        _selectedID = State(initialValue: initialOrderID)
        _showsDetail = State(initialValue: initialOrderID != nil)
    }

    var body: some View {
        GeometryReader { geometry in
            let wide = geometry.size.width >= (typeSize.isAccessibilitySize ? 760 : 580)
            if let tasks {
                LoadableView(tasks.state, retry: { tasks.load() }) { rows in
                    let orders = groups(rows)
                    if let initialOrderID, !orders.contains(where: { $0.id == initialOrderID }) {
                        EmptyState("This job is no longer on your task list", message: "Return to the calendar and refresh to see the latest work.", symbol: "scissors")
                    } else {
                        workspace(orders, wide: wide)
                    }
                }
            } else {
                ProgressView("Loading your work")
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .background(Theme.canvas)
        .navigationTitle("My Tasks")
        #if os(iOS)
        .navigationBarTitleDisplayMode(.inline)
        #endif
        .task(id: history) { await load() }
        .onChange(of: env.connection.isOnline) { _, online in
            if online { Task { await sync() } }
        }
        .onChange(of: env.connection.pendingCount) { _, _ in
            Task { await refreshPending() }
        }
        .sheet(item: $noteFor) { task in
            TextPromptSheet("Note on \(stageName(task))", placeholder: "What the next bench should know", confirmLabel: "Post note") { text in
                do {
                    _ = try await env.session.client.post("/v1/tailor/tasks/\(task.id)/note", json: .object(["note": .string(text)]))
                    env.toasts.show("Note posted")
                } catch { env.toasts.error(error) }
            }
        }
    }

    private func workspace(_ groups: [OrderGroup], wide: Bool) -> some View {
        let selection = groups.first { $0.id == selectedID } ?? (wide ? groups.first : nil)
        let detailVisible = showsDetail && selection != nil
        return HStack(spacing: 0) {
            queue(groups, selection: selection?.id)
                .frame(width: wide ? 215 : nil)
                .opacity(!wide && detailVisible ? 0 : 1)
                .allowsHitTesting(wide || !detailVisible)
                .accessibilityHidden(!wide && detailVisible)
                .overlay {
                    if !wide && detailVisible, let selection {
                        orderDetail(selection, back: true)
                            .id(selection.id)
                    }
                }
            if wide {
                Divider()
                if let selection {
                    orderDetail(selection, back: false).id(selection.id)
                } else {
                    EmptyState("Select an order", symbol: "scissors")
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                }
            }
        }
    }

    private func queue(_ groups: [OrderGroup], selection: Int?) -> some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 18) {
                HStack(alignment: .center, spacing: 8) {
                    Label("Workshop", systemImage: "scissors")
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(Theme.Palette.brandDeep)
                        .accessibilityHidden(true)
                    Spacer(minLength: 4)
                    if !pending.isEmpty {
                        Button {
                            Task { await sync() }
                        } label: {
                            Label("\(pending.count) pending", systemImage: "arrow.triangle.2.circlepath")
                                .font(.caption.weight(.medium))
                        }
                        .buttonStyle(.plain)
                        .foregroundStyle(Theme.text)
                        .disabled(!env.connection.isOnline || syncing)
                        .accessibilityHint("Send changes saved on this device")
                    } else if !env.connection.isOnline {
                        Label("Offline", systemImage: "wifi.slash").font(.caption)
                    }
                }
                .foregroundStyle(Theme.textSecondary)

                Picker("Task list", selection: $history) {
                    Text("Active").tag(false)
                    Text("History").tag(true)
                }
                .pickerStyle(.segmented)

                if groups.isEmpty {
                    EmptyState(history ? "No finished stages yet" : "Nothing on your bench",
                               message: history ? nil : "Assigned stages appear here.",
                               symbol: "scissors")
                } else {
                    ForEach(QueueLane.allCases) { lane in
                        let orders = groups.filter { $0.lane == lane }
                        if !orders.isEmpty {
                            VStack(alignment: .leading, spacing: 4) {
                                HStack(spacing: 7) {
                                    Circle()
                                        .fill(laneColor(lane))
                                        .frame(width: 6, height: 6)
                                        .accessibilityHidden(true)
                                    Text(lane.rawValue)
                                        .font(.caption.weight(.semibold))
                                        .foregroundStyle(Theme.textSecondary)
                                }
                                .accessibilityAddTraits(.isHeader)
                                ForEach(orders) { group in
                                    jobRow(group, selected: selection == group.id)
                                }
                            }
                        }
                    }
                }
            }
            .padding(Theme.Space.l)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(Theme.canvas)
        .refreshable { await refresh() }
    }

    private func jobRow(_ group: OrderGroup, selected: Bool) -> some View {
        Button {
            withAnimation(reduceMotion ? nil : .easeOut(duration: 0.16)) {
                selectedID = group.id
                showsDetail = true
            }
        } label: {
            HStack(spacing: 10) {
                Image(systemName: group.lane == .inspection ? "checkmark.shield" : "tshirt")
                    .font(.subheadline)
                    .foregroundStyle(laneColor(group.lane))
                    .frame(width: 30, height: 32)
                    .background(laneColor(group.lane).opacity(0.10), in: RoundedRectangle(cornerRadius: 8))
                VStack(alignment: .leading, spacing: 3) {
                    Text(group.name).font(.subheadline.weight(.semibold)).foregroundStyle(Theme.text)
                    Text(group.summary).font(.caption).foregroundStyle(Theme.textSecondary)
                }
                Spacer(minLength: 4)
                Image(systemName: "chevron.right").font(.caption2).foregroundStyle(Theme.textSecondary)
            }
            .frame(maxWidth: .infinity, minHeight: Theme.tapTarget, alignment: .leading)
            .padding(.horizontal, 8)
            .padding(.vertical, 5)
            .background(selected ? Theme.Palette.brand.opacity(0.10) : Theme.card, in: RoundedRectangle(cornerRadius: 9))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityAddTraits(selected ? .isSelected : [])
    }

    private func laneColor(_ lane: QueueLane) -> Color {
        switch lane {
        case .working: return colorScheme == .dark ? Theme.Palette.brandTint : Theme.Palette.brandDeep
        case .ready: return colorScheme == .dark ? Theme.Palette.brandTint : Theme.Palette.amber
        case .inspection: return colorScheme == .dark ? Color(hex: 0x91A9C7) : Theme.Palette.nav
        case .waiting, .finished: return Theme.textSecondary
        }
    }

    private func orderDetail(_ group: OrderGroup, back: Bool) -> some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 18) {
                VStack(alignment: .leading, spacing: 5) {
                    if back {
                        Button {
                            if initialOrderID != nil { dismiss() }
                            else { withAnimation(reduceMotion ? nil : .easeOut(duration: 0.16)) { showsDetail = false } }
                        } label: {
                            Label(initialOrderID == nil ? "Task list" : "Calendar", systemImage: "chevron.left")
                                .font(.subheadline.weight(.semibold))
                                .frame(minHeight: Theme.tapTarget)
                        }
                        .buttonStyle(.plain)
                        .foregroundStyle(Theme.Palette.brandDeep)
                    }
                    Text(group.name).font(.title2.weight(.semibold)).accessibilityAddTraits(.isHeader)
                    ViewThatFits(in: .horizontal) {
                        HStack(spacing: 8) { orderMetadata(group) }
                        VStack(alignment: .leading, spacing: 4) { orderMetadata(group) }
                    }
                    .font(.caption)
                    .foregroundStyle(Theme.textSecondary)
                }

                if let notice = group.notice {
                    Label(notice, systemImage: group.lane == .inspection ? "checkmark.shield" : "hourglass")
                        .font(.subheadline)
                        .padding(12)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .background(Theme.Palette.brand.opacity(0.08), in: RoundedRectangle(cornerRadius: 10))
                }

                ForEach(group.openStages) { task in
                    taskCard(task, order: group.order)
                }

                let specs = group.order["specifications"]?.stringPairs ?? []
                let measurements = MeasurementSheet.of(group.order["measurements"])
                if !specs.isEmpty || measurements.gender != nil || !measurements.body.isEmpty {
                    VStack(spacing: 0) {
                        Divider()
                        DisclosureGroup {
                            VStack(alignment: .leading, spacing: 12) {
                                WorkshopMeasurements(measurements: measurements)
                                if !specs.isEmpty {
                                    Text("Specifications").font(.caption.weight(.semibold))
                                        .foregroundStyle(Theme.textSecondary)
                                        .accessibilityAddTraits(.isHeader)
                                    ForEach(Array(specs.enumerated()), id: \.offset) { _, spec in
                                        DetailRow(spec.0.humanized, spec.1)
                                    }
                                }
                            }
                            .padding(.bottom, 12)
                        } label: {
                            HStack(spacing: 8) {
                                Label("Specs & measurements", systemImage: "ruler")
                                    .font(.subheadline.weight(.semibold))
                                    .foregroundStyle(Theme.text)
                                Spacer(minLength: 4)
                                if !measurements.body.isEmpty {
                                    Text("\(measurements.body.count)")
                                        .font(.caption.weight(.medium))
                                        .monospacedDigit()
                                        .foregroundStyle(Theme.textSecondary)
                                        .accessibilityLabel("\(measurements.body.count) measurements")
                                }
                            }
                            .frame(minHeight: Theme.tapTarget, alignment: .leading)
                        }
                        .tint(Theme.Palette.brandDeep)
                        Divider()
                    }
                }

                if let notes = group.order["notes"]?.string, !notes.isEmpty {
                    VStack(alignment: .leading, spacing: 5) {
                        Text("Bench note").font(.subheadline.weight(.semibold))
                        Text(notes).font(.subheadline).foregroundStyle(Theme.textSecondary)
                    }
                }

                if !group.tasks.isEmpty {
                    stageTimeline(group)
                }
            }
            .padding(Theme.Space.l)
            .frame(maxWidth: 620, alignment: .leading)
            .frame(maxWidth: .infinity, alignment: .topLeading)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(Theme.card)
        .refreshable { await refresh() }
    }

    private func stageTimeline(_ group: OrderGroup) -> some View {
        let complete = group.tasks.filter { $0.status == "completed" }.count
        return VStack(alignment: .leading, spacing: 0) {
            VStack(alignment: .leading, spacing: 6) {
                Label("Your stages", systemImage: "list.bullet.clipboard")
                    .font(.subheadline.weight(.semibold))
                    .accessibilityAddTraits(.isHeader)
                ProgressView(value: Double(complete), total: Double(group.tasks.count)) {
                    Text("\(complete) of \(group.tasks.count) complete")
                        .font(.caption).foregroundStyle(Theme.textSecondary)
                }
                .tint(complete == group.tasks.count ? Theme.Palette.success : Theme.Palette.brandDeep)
            }
            .padding(.bottom, 10)

            ForEach(group.tasks) { task in
                let status = task.status
                let finished = status == "completed"
                HStack(spacing: 10) {
                    ProductionStageIcon(stage: task["stage"], tint: stageTint(status), completed: finished)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(stageName(task)).font(.subheadline.weight(finished ? .regular : .semibold))
                        if !finished {
                            Text(stageLabel(status)).font(.caption).foregroundStyle(Theme.textSecondary)
                        }
                    }
                    .accessibilityElement(children: .ignore)
                    .accessibilityLabel(stageName(task))
                    .accessibilityValue(stageLabel(status))
                    Spacer(minLength: 0)
                    if !group.history, finished, ProductionStatus.acceptsFloorWork(group.order["status"]?.string) {
                        Button { noteFor = task } label: {
                            Image(systemName: "note.text")
                                .frame(width: Theme.tapTarget, height: Theme.tapTarget)
                                .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                        .foregroundStyle(Theme.textSecondary)
                        .accessibilityLabel("Add note to \(stageName(task))")
                    }
                }
                .padding(.vertical, 4)
                .frame(minHeight: 33)
                if task.id != group.tasks.last?.id {
                    Divider().padding(.leading, 38)
                }
            }
        }
        .padding(14)
        .background(Theme.canvas, in: RoundedRectangle(cornerRadius: 14))
    }

    private func stageTint(_ status: String?) -> Color {
        switch status {
        case "in_progress": return Theme.Palette.brandDeep
        case "paused": return Theme.Palette.warning
        case "failed": return Theme.Palette.danger
        default: return Theme.textSecondary
        }
    }

    @ViewBuilder
    private func orderMetadata(_ group: OrderGroup) -> some View {
        Text(group.order["order_number"]?.string ?? "Production order").monospacedDigit()
        if let date = group.order["due_date"]?.date {
            Label { DateText(date, style: .date) } icon: { Image(systemName: "calendar") }
        }
        if let customer = group.order["customer_label"]?.string, !customer.isEmpty {
            Label(customer, systemImage: "person")
        }
        if let priority = group.order["priority"]?.string, ["high", "urgent"].contains(priority) {
            PriorityBadge(priority: priority)
        }
    }

    private func taskCard(_ task: Record, order: JSONValue) -> some View {
        let changes = pending.filter { $0.path.hasPrefix("/v1/tailor/tasks/\(task.id)/") }
        return WorkshopStageCard(task: task, order: order, pending: changes,
                                 onAction: { await act(task, action: $0) },
                                 onCount: { await count(task, quantity: $0, batch: $1) },
                                 onNote: { noteFor = task },
                                 onUnlock: { await unlock(task) })
    }

    private func groups(_ rows: [Record]) -> [OrderGroup] {
        var sequence: [Int] = []
        var byOrder: [Int: [Record]] = [:]
        for task in rows {
            guard let id = task["production_order"]?["id"]?.int ?? task.int("production_order_id") else { continue }
            if byOrder[id] == nil { sequence.append(id) }
            byOrder[id, default: []].append(task)
        }
        return sequence.compactMap { id in
            guard let records = byOrder[id],
                  let order = records.first?["production_order"] else { return nil }
            let finished = records.filter { ["completed", "failed"].contains($0.status ?? "") }
            if history && finished.isEmpty { return nil }
            let visible = history ? finished : records
            return OrderGroup(id: id, order: order, tasks: visible.sorted { ($0.int("sequence") ?? 0) < ($1.int("sequence") ?? 0) }, history: history)
        }
        .sorted { $0.lane.sortOrder < $1.lane.sortOrder }
    }

    private func load() async {
        let client = env.session.client
        let history = history
        let offline = env.offline
        let key = history ? "tailor-tasks-history" : "tailor-tasks-active"
        let resource = Resource<[Record]> {
            let json: JSONValue
            do {
                json = try await client.get("/v1/tailor/tasks", query: history ? ["include_completed": "true"] : ["include_order_context": "true"]).json ?? .array([])
                await offline?.put(json, for: key)
            } catch let error as APIError where error.isOffline {
                guard let cached = await offline?.get(key) else { throw error }
                json = cached.value
            }
            return (json.array ?? json["data"]?.array ?? []).map(Record.init(json:))
        }
        tasks = resource
        await refreshPending()
        await resource.reload()
    }

    private func refreshPending() async {
        pending = await env.offline?.pending().filter { $0.kind == "task_progress" || $0.kind == "task_status" } ?? []
    }

    private func refresh() async {
        if env.connection.isOnline, !pending.isEmpty { await sync() }
        else { await tasks?.reload() }
    }

    private func sync() async {
        guard !syncing, env.connection.isOnline else { return }
        syncing = true
        defer { syncing = false }
        await env.replayPending()
        await tasks?.reload()
        await refreshPending()
    }

    private func act(_ task: Record, action: String) async {
        let path = "/v1/tailor/tasks/\(task.id)/status"
        let body = JSONValue.object(["action": .string(action)])
        guard let hasPending = await hasPendingChanges(for: task) else { return }
        if hasPending {
            _ = await enqueue(kind: "task_status", method: "PUT", path: path, body: body, key: "task-\(task.id)-status")
            return
        }
        do {
            _ = try await env.session.client.put(path, json: body)
            if action == "complete" { env.toasts.show("Stage complete") }
            await tasks?.reload()
        } catch let error as APIError where error.isOffline {
            _ = await enqueue(kind: "task_status", method: "PUT", path: path, body: body, key: "task-\(task.id)-status")
        } catch { env.toasts.error(error) }
    }

    private func count(_ task: Record, quantity: Int, batch: Int?) async -> Bool {
        let path = "/v1/tailor/tasks/\(task.id)/progress"
        let body = JSONValue.object(["quantity_done": .int(quantity), "batch_id": .int(batch)])
        let stage = task["stage"]?["id"]?.int ?? task.int("production_stage_id") ?? 0
        let key = "task-\(task.id)-progress-\(stage)" + (batch.map { "-batch-\($0)" } ?? "")
        guard let hasPending = await hasPendingChanges(for: task) else { return false }
        if hasPending {
            return await enqueue(kind: "task_progress", method: "POST", path: path, body: body, key: key)
        }
        do {
            let response = try await env.session.client.post(path, json: body)
            let status = response.json?["task"]?["production_order"]?["status"]?.string
            env.toasts.show(status == "qc_pending" ? "Count saved. Order is with Quality Control." : "Count saved")
            await tasks?.reload()
            return true
        } catch let error as APIError where error.isOffline {
            return await enqueue(kind: "task_progress", method: "POST", path: path, body: body, key: key)
        } catch {
            env.toasts.error(error)
            await tasks?.reload()
            return false
        }
    }

    private func hasPendingChanges(for task: Record) async -> Bool? {
        let prefix = "/v1/tailor/tasks/\(task.id)/"
        guard let offline = env.offlineStoreForCurrentUser() else {
            env.toasts.show("Can't check saved work on this device. Try again.", tone: .danger)
            return nil
        }
        do {
            return try await offline.pendingChecked().contains { $0.path.hasPrefix(prefix) }
        } catch {
            env.toasts.show("Can't read saved work on this device. Ask a manager for help before updating this task.", tone: .danger)
            return nil
        }
    }

    private func enqueue(kind: String, method: String, path: String, body: JSONValue, key: String) async -> Bool {
        let operation = OfflineStore.PendingOperation(kind: kind, method: method, path: path, body: body, dedupeKey: key)
        guard let offline = env.offline, await offline.enqueue(operation) else {
            env.toasts.show("This change could not be saved on this device. Try again.", tone: .danger)
            return false
        }
        await env.refreshPendingCount()
        await refreshPending()
        env.toasts.show("Saved on this device. Waiting to sync.", tone: .warning)
        if env.connection.isOnline { Task { await sync() } }
        return true
    }

    private func unlock(_ task: Record) async {
        do {
            _ = try await env.session.client.post("/v1/tailor/tasks/\(task.id)/unlock", json: .object(["allow": .bool(true)]))
            await tasks?.reload()
            env.toasts.show("Stage unlocked")
        } catch { env.toasts.error(error) }
    }
}

private enum QueueLane: String, CaseIterable, Identifiable {
    case working = "In progress"
    case ready = "Ready to start"
    case waiting = "Waiting"
    case inspection = "With Quality Control"
    case finished = "Finished stages"

    var id: Self { self }
    var sortOrder: Int { Self.allCases.firstIndex(of: self) ?? 0 }
}

private struct OrderGroup: Identifiable {
    let id: Int
    let order: JSONValue
    let tasks: [Record]
    let history: Bool

    var name: String { order["product"]?["translations"]?[0]?["name"]?.string ?? order["product_name"]?.string ?? "Garment" }
    private var status: String { order["status"]?.string ?? "" }
    var openStages: [Record] {
        guard !history, ProductionStatus.acceptsFloorWork(status) else { return [] }
        return tasks.filter { ["pending", "in_progress", "paused"].contains($0.status ?? "") }
    }

    var lane: QueueLane {
        if history { return .finished }
        if status == "qc_pending" || status == "qc_passed" { return .inspection }
        if openStages.contains(where: { $0.status == "in_progress" || $0.status == "paused" }) { return .working }
        if openStages.contains(where: { $0["blocked_by_stage"]?.string == nil }) { return .ready }
        return .waiting
    }

    var summary: String {
        if let notice { return notice }
        if let stage = openStages.first(where: { $0.status == "in_progress" || $0.status == "paused" }) ?? openStages.first {
            if let blocker = stage["blocked_by_stage"]?.string { return "Waiting on \(blocker)" }
            return "\(stageName(stage)) · \(stageLabel(stage.status))"
        }
        return "\(tasks.count) \(tasks.count == 1 ? "stage" : "stages")"
    }

    var notice: String? {
        if history { return nil }
        switch status {
        case "qc_pending": return "Awaiting inspection · No action needed"
        case "qc_passed": return "Inspection passed · No action needed"
        case "qc_failed": return "Inspection needs follow-up from a manager"
        default: return openStages.isEmpty ? "Your stages are finished" : nil
        }
    }
}

private func stageName(_ task: Record) -> String { task["stage"]?["name"]?.string ?? "Stage" }

private func stageLabel(_ status: String?) -> String {
    switch status {
    case "pending": return "Ready to start"
    case "in_progress": return "In progress"
    case "paused": return "Paused"
    case "completed": return "Complete"
    case "failed": return "Failed"
    default: return status?.humanized ?? "Stage"
    }
}

private struct WorkshopStageCard: View {
    @Environment(AppEnvironment.self) private var env
    let task: Record
    let order: JSONValue
    let pending: [OfflineStore.PendingOperation]
    let onAction: (String) async -> Void
    let onCount: (Int, Int?) async -> Bool
    let onNote: () -> Void
    let onUnlock: () async -> Void
    @State private var busy = false

    private var status: String { task.status ?? "pending" }
    private var blocker: String? { task["blocked_by_stage"]?.string }
    private var quantity: Int { max(1, order["quantity"]?.int ?? 1) }
    private var awaitingStatus: Bool { pending.contains { $0.kind == "task_status" } }

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 8) {
                ProductionStageIcon(stage: task["stage"], tint: Theme.Palette.brandDeep)
                Text(stageName(task)).font(.subheadline.weight(.semibold))
                Spacer(minLength: 8)
                Text(blocker == nil ? stageLabel(status) : "Waiting")
                    .font(.caption).foregroundStyle(Theme.textSecondary)
            }
            if let instruction = task["stage"]?["description"]?.string, !instruction.isEmpty {
                Text(instruction).font(.caption).foregroundStyle(Theme.textSecondary)
            }
            if let blocker {
                Label("Waiting on \(blocker)", systemImage: "lock").font(.caption)
                    .foregroundStyle(Theme.textSecondary)
            }
            if awaitingStatus {
                Label("Stage update waiting to sync", systemImage: "arrow.triangle.2.circlepath").font(.caption)
            }
            counters
            actions
        }
        .padding(12)
        .background(Theme.canvas, in: RoundedRectangle(cornerRadius: 12))
        .overlay(RoundedRectangle(cornerRadius: 12).strokeBorder(Theme.line))
        .disabled(busy)
    }

    @ViewBuilder
    private var counters: some View {
        let batches = order["batches"]?.array ?? []
        if batches.isEmpty {
            counter("Pieces completed", quantity: quantity, confirmed: task["quantity_done"]?.int ?? 0, batch: nil)
        } else {
            ForEach(batches.compactMap { batch in batch["id"]?.int.map { (id: $0, row: batch) } }, id: \.id) { batch in
                let progress = task["batch_progress"]?.array ?? []
                let done = progress.first { $0["production_order_batch_id"]?.int == batch.id }?["quantity_done"]?.int ?? 0
                counter(batch.row["label"]?.string ?? "Batch", quantity: batch.row["quantity"]?.int ?? quantity, confirmed: done, batch: batch.id)
            }
        }
    }

    private func counter(_ label: String, quantity: Int, confirmed: Int, batch: Int?) -> some View {
        let queued = pending.last {
            $0.kind == "task_progress" && $0.body["batch_id"]?.int == batch
        }?.body["quantity_done"]?.int
        let completing = pending.contains { $0.kind == "task_status" && $0.body["action"]?.string == "complete" }
        return WorkshopPieceCounter(label: label, confirmed: confirmed, limit: quantity, queued: queued, blocked: completing) { value in
            guard !busy else { return false }
            busy = true
            defer { busy = false }
            return await onCount(value, batch)
        }
    }

    private var actions: some View {
        ViewThatFits(in: .horizontal) {
            HStack(spacing: 8) { actionButtons }
            VStack(alignment: .leading, spacing: 4) { actionButtons }
        }
        .controlSize(.small)
        .font(.caption)
        .tint(Theme.Palette.brandDeep)
    }

    @ViewBuilder
    private var actionButtons: some View {
        if status == "pending" || status == "paused" {
            Button(status == "paused" ? "Resume" : "Start") { run { await onAction("start") } }
                .buttonStyle(WorkshopActionStyle(prominent: false))
                .disabled(blocker != nil || awaitingStatus)
        }
        if status == "in_progress" {
            Button("Pause") { run { await onAction("pause") } }
                .buttonStyle(WorkshopActionStyle(prominent: false))
                .disabled(awaitingStatus)
        }
        if status == "in_progress" || status == "paused" {
            ConfirmButton("Mark \(stageName(task)) done", message: "This marks the stage complete for the whole order.", action: {
                guard !busy else { return }
                busy = true
                defer { busy = false }
                await onAction("complete")
            }) { Text("Mark done") }
                .buttonStyle(WorkshopActionStyle(prominent: false))
                .disabled(awaitingStatus)
        }
        Menu {
            Button("Add note", action: onNote)
            if env.session.can("production.manage_assignees"), task["started_at"]?.isNull != false, task["concurrent_allowed"]?.bool != true {
                Button("Unlock stage") { run(onUnlock) }
            }
        } label: {
            Label("Stage actions", systemImage: "ellipsis.circle").labelStyle(.iconOnly)
        }
        .fixedSize()
    }

    private func run(_ operation: @escaping () async -> Void) {
        guard !busy else { return }
        busy = true
        Task {
            defer { busy = false }
            await operation()
        }
    }
}

private struct WorkshopPieceCounter: View {
    let label: String
    let confirmed: Int
    let limit: Int
    let queued: Int?
    let blocked: Bool
    let onSave: (Int) async -> Bool
    @State private var draft: Int?
    @State private var saving = false

    private var current: Int { queued ?? confirmed }

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(alignment: .firstTextBaseline) {
                Text(label).font(.caption).foregroundStyle(Theme.textSecondary)
                Spacer()
                Text("\(current) of \(limit)").font(.caption.weight(.semibold)).monospacedDigit()
            }
            ProgressView(value: Double(min(max(current, 0), limit)), total: Double(max(1, limit)))
                .tint(queued == nil ? Theme.Palette.brand : Theme.Palette.amber)
                .accessibilityLabel(queued == nil ? "Confirmed piece count" : "Piece count on this device")
            if queued != nil {
                Label("Saved here · Waiting to sync", systemImage: "arrow.triangle.2.circlepath")
                    .font(.caption)
                    .foregroundStyle(Theme.textSecondary)
            }
            if let draft {
                HStack(spacing: 4) {
                    stepButton("minus", label: "Remove one piece", enabled: draft > 0) { self.draft = draft - 1 }
                    Text(draft, format: .number).font(.headline).monospacedDigit()
                        .frame(minWidth: 28)
                        .accessibilityLabel("Draft count")
                        .accessibilityValue(String(draft))
                    stepButton("plus", label: "Add one piece", enabled: draft < limit) { self.draft = draft + 1 }
                    Spacer(minLength: 4)
                    Button("All \(limit)") { self.draft = limit }
                        .font(.caption.weight(.medium))
                        .frame(minHeight: Theme.tapTarget)
                        .disabled(draft >= limit)
                }
                HStack(spacing: 12) {
                    Spacer(minLength: 4)
                    Button("Cancel") { self.draft = nil }
                        .buttonStyle(.plain)
                        .frame(minHeight: Theme.tapTarget)
                        .keyboardShortcut(.cancelAction)
                    Button {
                        save(draft)
                    } label: {
                        if saving { ProgressView().controlSize(.small) }
                        else { Text("Save count") }
                    }
                    .buttonStyle(WorkshopActionStyle())
                    .disabled(draft == current || blocked)
                    .keyboardShortcut(.defaultAction)
                }
            } else {
                HStack {
                    Spacer()
                    Button("Update count") { draft = min(max(current, 0), limit) }
                        .buttonStyle(WorkshopActionStyle())
                        .disabled(blocked)
                }
            }
        }
        .disabled(saving)
    }

    private func stepButton(_ icon: String, label: String, enabled: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: icon)
                .font(.subheadline.weight(.semibold))
                .frame(width: 30, height: 30)
                .background(Theme.card, in: RoundedRectangle(cornerRadius: 7))
                .overlay(RoundedRectangle(cornerRadius: 7).strokeBorder(Theme.line))
                .frame(width: Theme.tapTarget, height: Theme.tapTarget)
                .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
        .accessibilityLabel(label)
    }

    private func save(_ value: Int) {
        guard !saving else { return }
        saving = true
        Task {
            defer { saving = false }
            if await onSave(value) { draft = nil }
        }
    }
}

private struct WorkshopActionStyle: ButtonStyle {
    @Environment(\.isEnabled) private var enabled
    @Environment(\.colorScheme) private var colorScheme
    var prominent = true

    func makeBody(configuration: Configuration) -> some View {
        let accent = colorScheme == .dark ? Theme.Palette.brandTint : Theme.Palette.brandDeep
        let onAccent: Color = colorScheme == .dark ? .black : .white
        configuration.label
            .font(.caption.weight(prominent ? .semibold : .medium))
            .foregroundStyle(prominent ? onAccent : Theme.text)
            .padding(.horizontal, 10)
            .padding(.vertical, prominent ? 7 : 5)
            .background(prominent ? accent : Theme.card, in: RoundedRectangle(cornerRadius: 7))
            .overlay(RoundedRectangle(cornerRadius: 7).strokeBorder(prominent ? .clear : Theme.line))
            .opacity(!enabled ? 0.45 : configuration.isPressed ? 0.8 : 1)
            .frame(minHeight: Theme.tapTarget)
            .contentShape(Rectangle())
    }
}
