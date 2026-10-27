import SwiftUI
import BethanyKit

/// Signals (IntelligenceDashboard.tsx): churn risk, reorder suggestions with
/// the auto-reorder action, smart tasks, budget warnings, material shortages
/// with a preflight check, tailor workload. Each feed keeps its own module
/// gate on top of reports.signals; a refused feed shows as such.
struct SignalsReportView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var preflightProduct = ""
    @State private var preflightQty = "1"
    @State private var preflight: JSONValue?

    var body: some View {
        ReportShell(page: .signals, subtitle: "As of now · intelligence feeds", dated: false) { _ in
            if env.session.can("inventory.view") {
                SignalFeed(title: "Reorder suggestions", path: "/v1/admin/intelligence/reorder-suggestions", key: "suggestions") { r in
                    HStack {
                        RemoteImage(url: r.str("product_image"), size: 36)
                        VStack(alignment: .leading, spacing: 2) {
                            HStack { Text(r.str("product_name") ?? "").font(Theme.Font.body); StatusBadge(r.str("severity"), tone: r.str("severity") == "out_of_stock" ? .danger : .warning) }
                            Text("\(r.str("sku") ?? "")\(r["variant"]?["name"]?.string.map { " · \($0)" } ?? "") · \(r["outlet"]?["name"]?.string ?? "Warehouse") · available \(r.int("quantity_available") ?? 0) / reorder point \(r.int("reorder_point") ?? 0) · suggest \(r.int("reorder_quantity") ?? 0)").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        }
                        Spacer()
                        if env.session.can("procurement.create"), let iid = r.int("inventory_item_id") {
                            ConfirmButton("Draft a purchase order", message: "A draft PO is created for this item with its active supplier.", action: { await autoReorder(iid) }) { Text("Auto-reorder") }
                        }
                    }
                    .font(Theme.Font.secondary)
                }
                SignalFeed(title: "Material shortages (open production orders)", path: "/v1/admin/intelligence/material-shortages", key: "shortages") { r in
                    HStack { VStack(alignment: .leading, spacing: 2) { HStack { Text(r.str("material_name") ?? "").font(Theme.Font.body); StatusBadge(r.str("severity"), tone: r.str("severity") == "out_of_stock" ? .danger : .warning) }; Text("needed \(fmtNumber(r.dbl("total_needed"), decimals: 2)) \(r.str("unit") ?? "") across \(r.int("orders_needing") ?? 0) orders · available \(fmtNumber(r.dbl("available"), decimals: 2)) · short \(fmtNumber(r.dbl("shortfall"), decimals: 2))").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }; Spacer() }.font(Theme.Font.secondary)
                }
                SectionCard("Preflight: can we make N of a product?") {
                    HStack { TextField("Product id", text: $preflightProduct).textFieldStyle(.roundedBorder).frame(width: 120); DecimalField("Qty", text: $preflightQty).frame(width: 80); Button("Check") { Task { await runPreflight() } }.disabled(Int(preflightProduct) == nil) }
                    if let p = preflight {
                        let rows = jrows(p["shortages"])
                        if rows.isEmpty { Label("No shortages for this quantity.", systemImage: "checkmark.circle").foregroundStyle(Theme.Palette.success).font(Theme.Font.secondary) }
                        else { AutoTable(rows: rows) }
                    }
                }
            }
            if env.session.can("intelligence.view") {
                SignalFeed(title: "Churn risk", path: "/v1/admin/intelligence/churn-risk", key: "customers", query: ["limit": "20"]) { r in
                    HStack { VStack(alignment: .leading, spacing: 2) { HStack { Text(r.str("name") ?? "").font(Theme.Font.body); StatusBadge(r.str("risk_level"), tone: r.str("risk_level") == "high" ? .danger : .warning) }; Text("\(r.str("phone") ?? r.str("email") ?? "") · \(r.int("total_orders") ?? 0) orders · last \(r.int("days_since_last") ?? 0)d ago, usual \(r.int("avg_interval_days") ?? 0)d · overdue \(r.int("overdue_by_days") ?? 0)d").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }; Spacer(); Text(fmtMoney(r["lifetime_value"], currency: "KES")).font(Theme.Font.secondary.weight(.semibold)); if let id = r.int("customer_id") { Button("Open") { env.navigator.go(.customer(id: id)) } } }.font(Theme.Font.secondary)
                }
            }
            if env.session.can("expenses.view") {
                SignalFeed(title: "Budget warnings", path: "/v1/admin/intelligence/budget-warnings", key: "warnings") { r in
                    VStack(alignment: .leading, spacing: 2) {
                        HStack { Text("\(r.str("category_name") ?? "") · \(r.str("outlet_name") ?? "")").font(Theme.Font.body); StatusBadge(r.str("severity"), tone: r.str("severity") == "exceeded" ? .danger : .warning); Spacer(); Text(fmtPct(r.dbl("utilization_percent"), decimals: 0)).font(Theme.Font.secondary.weight(.semibold)) }
                        Text("\(r.str("period") ?? "") · budget \(fmtMoney(r["budgeted_amount"], currency: "KES")) · spent \(fmtMoney(r["actual_spend"], currency: "KES")) · remaining \(fmtMoney(r["remaining"], currency: "KES"))").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        ProgressView(value: min(max(r.dbl("utilization_percent") ?? 0, 0), 100), total: 100).tint(r.str("severity") == "exceeded" ? Theme.Palette.danger : Theme.Palette.warning)
                    }
                }
            }
            if env.session.can("production.manage_assignees") {
                SignalFeed(title: "Tailor workload", path: "/v1/admin/intelligence/tailor-workload", key: "tailors") { r in
                    HStack { VStack(alignment: .leading, spacing: 2) { HStack { Text(r.str("name") ?? "").font(Theme.Font.body); StatusBadge(r.str("recommendation"), tone: ["available": StatusTone.success, "light": .info, "moderate": .amber, "heavy": .danger][r.str("recommendation") ?? ""] ?? .neutral) }; Text("\(r.int("active_tasks") ?? 0) active · \(r.int("overdue_tasks") ?? 0) overdue · \(fmtNumber(r.dbl("avg_hours_per_task"), decimals: 1)) h/task · completion \(fmtPct(r.dbl("completion_rate"), decimals: 0))").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }; Spacer(); Text("score \(fmtNumber(r.dbl("workload_score"), decimals: 1))").font(Theme.Font.caption) }.font(Theme.Font.secondary)
                }
            }
            SignalFeed(title: "Smart tasks (your open production tasks, prioritised)", path: "/v1/admin/intelligence/smart-tasks", key: nil) { r in
                HStack {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(r["productionOrder"]?["product"]?["translations"]?[0]?["name"]?.string ?? r.str("title") ?? r.str("name") ?? "Task #\(r.str("id") ?? "")").font(Theme.Font.body)
                        Text([r.str("status")?.humanized, r.str("stage_name"), r.str("priority"), r.str("due_date").map { "due \(String($0.prefix(10)))" }].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    }
                    Spacer()
                    if let id = r["productionOrder"]?["id"]?.int ?? r.int("production_order_id") { Button("Open order") { env.navigator.go(.productionOrder(id: id)) } }
                }
                .font(Theme.Font.secondary)
            }
        }
    }

    private func autoReorder(_ itemId: Int) async {
        do { let r = try await env.session.client.post("/v1/admin/intelligence/auto-reorder/\(itemId)"); env.toasts.show(r.json?["message"]?.string ?? "Draft PO created"); if let id = r.json?["purchase_order"]?["id"]?.int { env.navigator.go(.purchaseOrder(id: id)) } } catch { env.toasts.error(error) }
    }

    private func runPreflight() async {
        guard let pid = Int(preflightProduct) else { return }
        do { preflight = try await env.session.client.post("/v1/admin/intelligence/material-shortages/preflight", json: .object(["product_id": .number(Double(pid)), "quantity": .number(Double(Int(preflightQty) ?? 1))])).json } catch { env.toasts.error(error) }
    }
}

/// One intelligence feed: `{key: [...]}` (or a bare array), rendered per row.
struct SignalFeed<Row: View>: View {
    @Environment(AppEnvironment.self) private var env
    let title: String
    let path: String
    let key: String?
    var query: [String: String?] = [:]
    @ViewBuilder let row: (JRow) -> Row
    @State private var resource: Resource<[JRow]>?

    var body: some View {
        SectionCard(title) {
            if let resource {
                LoadableView(resource.state, retry: { resource.load() }, isEmpty: { $0.isEmpty }, empty: { EmptyState("Nothing to flag", symbol: "checkmark.circle") }) { rows in
                    ForEach(rows) { r in row(r).padding(.vertical, 4); Divider() }
                }
            }
        }
        .task {
            if resource == nil {
                let c = env.session.client, p = path, k = key, q = query
                resource = Resource { let j = try await c.get(p, query: q).json; return jrows(k.flatMap { j?[$0] } ?? j) }
                resource?.load()
            }
        }
    }
}
