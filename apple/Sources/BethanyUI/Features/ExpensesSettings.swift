import SwiftUI
import BethanyKit

/// Expense categories and budgets (ExpenseCategoriesPage.tsx): both lists
/// read with expenses.view; create/update need expenses.budgets. There is
/// no delete endpoint — a category is deactivated instead.
struct ExpenseCategoriesView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var tab = "categories"
    @State private var categories: Resource<[JRow]>?
    @State private var budgets: Resource<[JRow]>?
    @State private var budgetType = "monthly"
    @State private var budgetYear = Calendar(identifier: .gregorian).component(.year, from: Date())
    @State private var editingCategory: JSONItem?
    @State private var newCategory = false
    @State private var editingBudget: JSONItem?
    @State private var newBudget = false

    var body: some View {
        Page("Expense categories & budgets") {
            SegmentedTabs(tabs: [("categories", "Categories"), ("budgets", "Budgets")], selection: $tab)
            if tab == "categories" { categoriesTab } else { budgetsTab }
        }
        .toolbar {
            ToolbarItemGroup(placement: .primaryAction) {
                if env.session.can("expenses.budgets") {
                    if tab == "categories" { Button { newCategory = true } label: { Label("New category", systemImage: "plus") } }
                    else { Button { newBudget = true } label: { Label("New budget", systemImage: "plus") } }
                }
            }
        }
        .task { if categories == nil { loadAll() } }
        .onChange(of: budgetType) { _, _ in loadBudgets() }
        .onChange(of: budgetYear) { _, _ in loadBudgets() }
        .refreshable { await categories?.reload(); await budgets?.reload() }
        .sheet(isPresented: $newCategory) { CategoryFormSheet(existing: nil, parents: flatCategories) { newCategory = false; loadAll() } }
        .sheet(item: $editingCategory) { c in CategoryFormSheet(existing: c.json, parents: flatCategories) { editingCategory = nil; loadAll() } }
        .sheet(isPresented: $newBudget) { BudgetFormSheet(existing: nil, categories: flatCategories) { newBudget = false; loadBudgets() } }
        .sheet(item: $editingBudget) { b in BudgetFormSheet(existing: b.json, categories: flatCategories) { editingBudget = nil; loadBudgets() } }
    }

    private var flatCategories: [(Int, String)] {
        var out: [(Int, String)] = []
        func walk(_ rows: [JRow], depth: Int) {
            for c in rows { if let id = c.int("id") { out.append((id, String(repeating: "  ", count: depth) + (c.str("name") ?? ""))) }; walk(jrows(c["children"]), depth: depth + 1) }
        }
        walk(categories?.value ?? [], depth: 0)
        return out
    }

    private func loadAll() {
        let c = env.session.client
        if categories == nil { categories = Resource { jrows(try await c.get("/v1/admin/expenses/categories").json?["categories"]) } }
        categories?.load()
        loadBudgets()
    }

    private func loadBudgets() {
        let c = env.session.client, t = budgetType, y = budgetYear
        budgets = Resource { jrows(try await c.get("/v1/admin/expenses/budgets", query: ["period_type": t, "period_year": String(y)]).json?["budgets"]) }
        budgets?.load()
    }

    @ViewBuilder
    private var categoriesTab: some View {
        if let categories {
            LoadableView(categories.state, retry: { categories.load() }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No categories yet", symbol: "tag") }) { rows in
                SectionCard {
                    ForEach(rows) { c in categoryRow(c, depth: 0) }
                }
                Text("Current-month spend and budget use are the server's (approved and paid expenses, KES).").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            }
        }
    }

    private func categoryRow(_ c: JRow, depth: Int) -> AnyView {
        AnyView(VStack(alignment: .leading, spacing: 0) {
            HStack(alignment: .top) {
                if let hex = c.str("color"), let color = Color(hexString: hex) { Circle().fill(color).frame(width: 10, height: 10).padding(.top, 6) }
                VStack(alignment: .leading, spacing: 2) {
                    HStack { Text(c.str("name") ?? "").font(Theme.Font.body); Text(c.str("code") ?? "").font(Theme.Font.mono).foregroundStyle(Theme.textTertiary); if c["is_active"]?.bool == false { StatusBadge("inactive", tone: .neutral) }; if c["is_tax_deductible"]?.bool == true { StatusBadge("tax deductible", tone: .info) } }
                    Text([c.str("description"), c.str("gl_code").map { "GL \($0)" }, c["requires_approval_above"].flatMap { $0.isNull ? nil : "approval above \(fmtMoney($0, currency: "KES"))" }].compactMap { $0 }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    HStack(spacing: Theme.Space.m) {
                        Text("This month: \(fmtMoney(c["current_month_spend"], currency: "KES"))")
                        if let m = c["budget_monthly"], !m.isNull { Text("Monthly budget \(fmtMoney(m, currency: "KES"))") }
                        if let p = c.dbl("budget_utilization_percent") { Text("\(fmtPct(p, decimals: 0)) used").foregroundStyle(p >= 100 ? Theme.Palette.danger : (p >= 80 ? Theme.Palette.warning : Theme.textSecondary)) }
                    }
                    .font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                }
                Spacer()
                if env.session.can("expenses.budgets") { Button("Edit") { editingCategory = JSONItem(json: c.json) }.font(Theme.Font.secondary) }
            }
            .padding(.leading, CGFloat(depth) * 20).padding(.vertical, 6)
            Divider()
            ForEach(jrows(c["children"])) { child in categoryRow(child, depth: depth + 1) }
        })
    }

    @ViewBuilder
    private var budgetsTab: some View {
        HStack {
            Picker("Period", selection: $budgetType) { Text("Monthly").tag("monthly"); Text("Quarterly").tag("quarterly"); Text("Annual").tag("annual") }.labelsHidden()
            Stepper("\(String(budgetYear))", value: $budgetYear, in: 2020...2100).frame(maxWidth: 160)
            Spacer()
        }
        .font(Theme.Font.secondary)
        if let budgets {
            LoadableView(budgets.state, retry: { budgets.load() }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No budgets for this period", symbol: "chart.bar") }) { rows in
                SectionCard {
                    ForEach(rows) { b in
                        HStack(alignment: .top) {
                            VStack(alignment: .leading, spacing: 2) {
                                Text("\(b["category"]?["name"]?.string ?? "Category") · \(periodLabel(b))").font(Theme.Font.body)
                                Text(b["outlet"]?["name"]?.string ?? "All outlets").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                HStack(spacing: Theme.Space.m) {
                                    Text("Budget \(fmtMoney(b["budgeted_amount"], currency: b.str("currency_code") ?? "KES"))")
                                    Text("Spent \(fmtMoney(b["actual_spend"], currency: b.str("comparison_currency") ?? "KES"))")
                                    Text("Variance \(fmtMoney(b["variance"], currency: b.str("comparison_currency") ?? "KES"))")
                                    if let p = b.dbl("utilization_percent") { Text(fmtPct(p, decimals: 0)).foregroundStyle(p >= 100 ? Theme.Palette.danger : (p >= 80 ? Theme.Palette.warning : Theme.textSecondary)) }
                                }
                                .font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                if let p = b.dbl("utilization_percent") { ProgressView(value: min(max(p, 0), 100), total: 100).tint(p >= 100 ? Theme.Palette.danger : Theme.Palette.brand) }
                            }
                            Spacer()
                            if env.session.can("expenses.budgets") { Button("Edit") { editingBudget = JSONItem(json: b.json) }.font(Theme.Font.secondary) }
                        }
                        .padding(.vertical, 6)
                        Divider()
                    }
                }
            }
        }
    }

    private func periodLabel(_ b: JRow) -> String {
        let n = b.int("period_number") ?? 1, y = b.int("period_year") ?? 0
        switch b.str("period_type") {
        case "monthly": return "\(Calendar.current.monthSymbols[max(0, min(11, n - 1))]) \(y)"
        case "quarterly": return "Q\(n) \(y)"
        default: return "\(y)"
        }
    }
}

extension Color {
    init?(hexString: String) {
        var s = hexString.trimmingCharacters(in: .whitespaces)
        if s.hasPrefix("#") { s.removeFirst() }
        guard s.count == 6, let v = UInt32(s, radix: 16) else { return nil }
        self.init(hex: v)
    }
}

struct CategoryFormSheet: View {
    @Environment(AppEnvironment.self) private var env
    let existing: JSONValue?
    let parents: [(Int, String)]
    let done: () -> Void
    @State private var name = ""
    @State private var code = ""
    @State private var description = ""
    @State private var parentId: Int? = nil
    @State private var color = ""
    @State private var icon = ""
    @State private var approvalAbove = ""
    @State private var budgetMonthly = ""
    @State private var budgetAnnual = ""
    @State private var taxDeductible = false
    @State private var active = true
    @State private var gl = ""
    @State private var error: APIError?
    @State private var busy = false

    var body: some View {
        BHFormSheet(existing == nil ? "New category" : "Edit category", primary: existing == nil ? "Create" : "Save", busy: busy, disabled: name.isEmpty || (existing == nil && code.isEmpty), action: save) {
            FieldBox("Name *", error: error?.errors["name"]?.first) { TextField("", text: $name).textFieldStyle(.roundedBorder) }
            if existing == nil {
                FieldBox("Code *", error: error?.errors["code"]?.first) { TextField("e.g. RENT", text: $code).textFieldStyle(.roundedBorder) }
                FieldBox("Parent category") { Picker("", selection: $parentId) { Text("None (top level)").tag(Int?.none); ForEach(parents, id: \.0) { Text($0.1).tag(Int?.some($0.0)) } }.labelsHidden() }
            }
            FieldBox("Description") { TextField("", text: $description).textFieldStyle(.roundedBorder) }
            HStack {
                FieldBox("Colour (#hex)", error: error?.errors["color"]?.first) { TextField("#6366f1", text: $color).textFieldStyle(.roundedBorder) }
                FieldBox("GL code") { TextField("", text: $gl).textFieldStyle(.roundedBorder) }
            }
            FieldBox("Requires approval above (KES)", error: error?.errors["requires_approval_above"]?.first) { DecimalField("Blank = category default", text: $approvalAbove) }
            HStack {
                FieldBox("Monthly budget (KES)") { DecimalField("", text: $budgetMonthly) }
                FieldBox("Annual budget (KES)") { DecimalField("", text: $budgetAnnual) }
            }
            Toggle("Tax deductible", isOn: $taxDeductible)
            if existing != nil { Toggle("Active", isOn: $active) }
            if let e = error, e.errors.isEmpty { Text(e.message).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
        }
        .onAppear {
            guard let e = existing else { return }
            name = e["name"]?.string ?? ""; code = e["code"]?.string ?? ""; description = e["description"]?.string ?? ""
            color = e["color"]?.string ?? ""; gl = e["gl_code"]?.string ?? ""
            approvalAbove = e["requires_approval_above"].flatMap { $0.isNull ? nil : $0.string } ?? ""
            budgetMonthly = e["budget_monthly"].flatMap { $0.isNull ? nil : $0.string } ?? ""
            budgetAnnual = e["budget_annual"].flatMap { $0.isNull ? nil : $0.string } ?? ""
            taxDeductible = e["is_tax_deductible"]?.bool ?? false; active = e["is_active"]?.bool ?? true
        }
    }

    private func save() async {
        busy = true; defer { busy = false }
        error = nil
        var body: [String: JSONValue] = ["name": .string(name), "is_tax_deductible": .bool(taxDeductible)]
        body.put("description", jstr(description)); body.put("color", jstr(color)); body.put("gl_code", jstr(gl))
        body.put("requires_approval_above", jnum(approvalAbove)); body.put("budget_monthly", jnum(budgetMonthly)); body.put("budget_annual", jnum(budgetAnnual))
        do {
            if let e = existing, let id = e["id"]?.int {
                body["is_active"] = .bool(active)
                _ = try await env.session.client.put("/v1/admin/expenses/categories/\(id)", json: .object(body))
            } else {
                body["code"] = .string(code); body.put("parent_id", parentId.map { .number(Double($0)) }); body.put("icon", jstr(icon))
                _ = try await env.session.client.post("/v1/admin/expenses/categories", json: .object(body))
            }
            env.toasts.show("Category saved"); done()
        } catch let e as APIError { error = e } catch { env.toasts.error(error) }
    }
}

struct BudgetFormSheet: View {
    @Environment(AppEnvironment.self) private var env
    let existing: JSONValue?
    let categories: [(Int, String)]
    let done: () -> Void
    @State private var categoryId: Int? = nil
    @State private var outletId: Int? = nil
    @State private var outlets: [(Int, String)] = []
    @State private var periodType = "monthly"
    @State private var year = Calendar(identifier: .gregorian).component(.year, from: Date())
    @State private var number = Calendar(identifier: .gregorian).component(.month, from: Date())
    @State private var amount = ""
    @State private var currency = "KES"
    @State private var notes = ""
    @State private var error: APIError?
    @State private var busy = false

    var body: some View {
        BHFormSheet(existing == nil ? "New budget" : "Edit budget", primary: existing == nil ? "Create" : "Save", busy: busy, disabled: decimal(amount) == nil || (existing == nil && categoryId == nil), action: save) {
            if existing == nil {
                FieldBox("Category *", error: error?.errors["category_id"]?.first) { Picker("", selection: $categoryId) { Text("Choose…").tag(Int?.none); ForEach(categories, id: \.0) { Text($0.1).tag(Int?.some($0.0)) } }.labelsHidden() }
                if outlets.count > 1 { FieldBox("Outlet") { Picker("", selection: $outletId) { Text("All outlets").tag(Int?.none); ForEach(outlets, id: \.0) { Text($0.1).tag(Int?.some($0.0)) } }.labelsHidden() } }
                HStack {
                    FieldBox("Period") { Picker("", selection: $periodType) { Text("Monthly").tag("monthly"); Text("Quarterly").tag("quarterly"); Text("Annual").tag("annual") }.labelsHidden() }
                    FieldBox("Year") { Stepper("\(String(year))", value: $year, in: 2020...2100) }
                    if periodType != "annual" { FieldBox(periodType == "monthly" ? "Month" : "Quarter") { Stepper("\(number)", value: $number, in: 1...(periodType == "monthly" ? 12 : 4)) } }
                }
                FieldBox("Currency") { Picker("", selection: $currency) { Text("KES").tag("KES"); Text("USD").tag("USD") }.labelsHidden() }
            } else {
                Text("\(existing?["category"]?["name"]?.string ?? "") · \(existing?["period_type"]?.string?.humanized ?? "") \(existing?["period_number"]?.int ?? 0)/\(existing?["period_year"]?.int ?? 0)").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            }
            FieldBox("Budgeted amount *", error: error?.errors["budgeted_amount"]?.first) { DecimalField("", text: $amount) }
            FieldBox("Notes") { TextField("", text: $notes).textFieldStyle(.roundedBorder) }
            if let e = error, e.errors.isEmpty { Text(e.message).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
        }
        .onAppear { if let e = existing { amount = e["budgeted_amount"]?.string ?? ""; notes = e["notes"]?.string ?? "" } }
        .task { if existing == nil { outlets = await outletOptions(env.session.client, reports: false) } }
    }

    private func save() async {
        busy = true; defer { busy = false }
        error = nil
        var body: [String: JSONValue] = [:]
        body.put("budgeted_amount", jnum(amount)); body.put("notes", jstr(notes))
        do {
            if let e = existing, let id = e["id"]?.int {
                _ = try await env.session.client.put("/v1/admin/expenses/budgets/\(id)", json: .object(body))
            } else {
                body.put("category_id", categoryId.map { .number(Double($0)) }); body.put("outlet_id", outletId.map { .number(Double($0)) })
                body["period_type"] = .string(periodType); body["period_year"] = .number(Double(year)); body["period_number"] = .number(Double(periodType == "annual" ? 1 : number)); body["currency_code"] = .string(currency)
                _ = try await env.session.client.post("/v1/admin/expenses/budgets", json: .object(body))
            }
            env.toasts.show("Budget saved"); done()
        } catch let e as APIError { error = e } catch { env.toasts.error(error) }
    }
}

// MARK: - Analytics (GET /expenses/summary)

struct ExpenseAnalyticsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var preset: DatePreset = .this_month
    @State private var customStart = Date()
    @State private var customEnd = Date()
    @State private var outletId: Int? = nil
    @State private var outlets: [(Int, String)] = []

    private var window: ReportWindow { ReportWindow(preset: preset, customStart: customStart, customEnd: customEnd, outletId: outletId) }

    var body: some View {
        Page("Expense analytics", subtitle: "\(window.description) · \(outletId.flatMap { id in outlets.first { $0.0 == id }?.1 } ?? "All outlets")") {
            HStack {
                Picker("Period", selection: $preset) { ForEach(DatePreset.allCases) { Text($0.label).tag($0) } }.labelsHidden().frame(maxWidth: 200)
                if outlets.count > 1 { Picker("Outlet", selection: $outletId) { Text("All outlets").tag(Int?.none); ForEach(outlets, id: \.0) { Text($0.1).tag(Int?.some($0.0)) } }.labelsHidden() }
                Spacer()
            }
            if preset == .custom {
                HStack { DatePicker("From", selection: $customStart, displayedComponents: .date); DatePicker("To", selection: $customEnd, displayedComponents: .date) }.environment(\.timeZone, HubDates.nairobi).font(Theme.Font.secondary)
            }
            ReportData("/v1/admin/expenses/summary", query: window.dateParams()) { json in
                let t = json["totals"]
                TileGrid(minWidth: 170) {
                    StatTile("Spend (approved + paid)", value: fmtMoney(t?["total_amount"], currency: "KES"), hint: "\(t?["total_count"]?.int ?? 0) expenses · KES at the recorded rate")
                    StatTile("Paid out", value: fmtMoney(t?["paid_amount"], currency: "KES"))
                    StatTile("Approved, unpaid", value: fmtMoney(t?["approved_unpaid_amount"], currency: "KES"))
                    StatTile("Average", value: fmtMoney(t?["avg_amount"], currency: "KES"))
                    StatTile("Pending approval", value: fmtMoney(json["pending"]?["total"], currency: "KES"), hint: "\(json["pending"]?["count"]?.int ?? 0) waiting — not in the spend above", tone: (json["pending"]?["count"]?.int ?? 0) > 0 ? .amber : nil)
                }
                SectionCard("By category") {
                    BarsChart(points: chartPoints(json["by_category"], label: "name", y: "total"))
                    DataTable(columns: [TableColumn("Category", "name"), TableColumn("Code", "code"), TableColumn("Count", "count", .int), TableColumn("Total", "total", .money, fixedCurrency: "KES")], rows: jrows(json["by_category"]))
                }
                SectionCard("Monthly trend (last 12 months, all statuses that count as spend)") {
                    LinesChart(points: chartPoints(json["monthly_trend"], label: "month", y: "total", series: "Spend"))
                }
                SectionCard("By payment method") {
                    DataTable(columns: [TableColumn("Method", "payment_method", .status), TableColumn("Count", "count", .int), TableColumn("Total", "total", .money, fixedCurrency: "KES")], rows: jrows(json["by_payment_method"]))
                }
                HStack { ShareTextButton(label: "Share summary", text: shareText(json)); Spacer() }.font(Theme.Font.secondary)
            }
        }
        .task { outlets = await outletOptions(env.session.client, reports: false) }
    }

    private func shareText(_ json: JSONValue) -> String {
        let t = json["totals"]
        var lines = ["Bethany House — expenses \(window.description)",
                     "Spend (approved + paid): \(fmtMoney(t?["total_amount"], currency: "KES")) across \(t?["total_count"]?.int ?? 0) expenses",
                     "Paid out: \(fmtMoney(t?["paid_amount"], currency: "KES")) · Approved unpaid: \(fmtMoney(t?["approved_unpaid_amount"], currency: "KES"))",
                     "Pending approval (not included): \(fmtMoney(json["pending"]?["total"], currency: "KES"))", "By category:"]
        for r in jrows(json["by_category"]) { lines.append("  \(r.str("name") ?? ""): \(fmtMoney(r["total"], currency: "KES")) (\(r.int("count") ?? 0))") }
        lines.append("Figures: server summary, KES at each expense's recorded rate.")
        return lines.joined(separator: "\n")
    }
}
