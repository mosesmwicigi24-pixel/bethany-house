import SwiftUI
import BethanyKit

// MARK: - Handbook (HandbookPage.tsx): searchable, grouped by category; updates.post writes

struct HandbookView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var query = ""
    @State private var list: Resource<JSONValue>?
    @State private var editing = false

    var body: some View {
        Page("Handbook", subtitle: "How we do things at Bethany House.") {
            HStack {
                TextField("Search policies and how-tos…", text: $query).textFieldStyle(.roundedBorder)
                if list?.value?["can_edit"]?.bool == true { Button("New article") { editing = true }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
            }
            if let list {
                LoadableView(list.state, retry: { list.load() }, isEmpty: { $0.records.isEmpty }, empty: { EmptyState("Nothing here yet", symbol: "book") }) { d in
                    let groups = Dictionary(grouping: d.records) { $0.string("category") ?? "General" }
                    ForEach(groups.keys.sorted(), id: \.self) { cat in
                        Text(cat.uppercased()).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                        ListCard {
                            ForEach(groups[cat] ?? []) { a in
                                Button { env.navigator.push(.handbook(id: a.int("id"))) } label: {
                                    ListRow {
                                        HStack(spacing: Theme.Space.m) {
                                            VStack(alignment: .leading, spacing: 2) {
                                                HStack(spacing: 6) {
                                                    Text(a.string("title") ?? "").font(Theme.Font.body.weight(.semibold))
                                                    if a["published_at"]?.isNull ?? true { StatusBadge("draft", tone: .neutral) }
                                                }
                                                Text(a.string("excerpt") ?? "").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).lineLimit(1)
                                            }
                                            Spacer()
                                            if a.flag("must_read") {
                                                let acked = !(a["acknowledged_at"]?.isNull ?? true)
                                                StatusBadge(acked ? "Read" : "Must read", tone: acked ? .success : .danger)
                                            }
                                            Image(systemName: "chevron.right").font(.system(size: 11, weight: .semibold)).foregroundStyle(Theme.textTertiary)
                                        }
                                    }
                                    .contentShape(Rectangle())
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    }
                }
            }
        }
        .task { if list == nil { await load() } }
        .onChange(of: query) { _, _ in Task { try? await Task.sleep(for: .milliseconds(300)); await load() } }
        .refreshable { await load() }
        .sheet(isPresented: $editing) { ArticleEditSheet(article: nil) { editing = false; Task { await load() } } }
    }

    private func load() async {
        let c = env.session.client, q = query.trimmingCharacters(in: .whitespaces)
        list = Resource { try await c.get("/v1/admin/handbook", query: ["q": q.isEmpty ? nil : q]).json ?? .null }
        await list?.reload()
    }
}

struct ArticleView: View {
    @Environment(AppEnvironment.self) private var env
    let id: Int
    @State private var article: Resource<Record>?
    @State private var canEdit = false
    @State private var editing = false
    @State private var busy = false

    var body: some View {
        Page(article?.value?.string("title") ?? "Article") {
            if let article {
                LoadableView(article.state, retry: { article.load() }) { a in
                    SectionCard {
                        Text("\(a.string("category") ?? "General")\(a["outlet"]?["name"]?.string.map { " · \($0)" } ?? "") · updated \(MeFormat.dayYear(a.date("updated_at")))\(a["published_at"]?.isNull ?? true ? " · draft" : "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        Text(a.string("body") ?? "").font(Theme.Font.body).lineSpacing(3).textSelection(.enabled)
                        if a.flag("must_read"), let postId = a.int("post_id") {
                            if let at = a.date("acknowledged_at") {
                                NoticeText("You acknowledged this on \(MeFormat.day(at)).", tone: .success)
                            } else {
                                Button("I have read and understood this") { Task { await acknowledge(postId) } }.buttonStyle(.primary).disabled(busy)
                            }
                        }
                        if canEdit {
                            Divider()
                            HStack {
                                Spacer()
                                ConfirmButton("Remove this article", message: "It is taken out of the handbook for everyone.", destructive: true, action: { await remove() }) { Text("Remove").foregroundStyle(Theme.Palette.danger) }
                                Button("Edit") { editing = true }.buttonStyle(.bordered)
                            }
                        }
                    }
                }
            }
        }
        .task {
            if article == nil {
                let c = env.session.client, articleId = id
                article = Resource {
                    let j = try await c.get("/v1/admin/handbook/\(articleId)").json
                    return Record(json: j?["article"] ?? .null)
                }
                article?.load()
                canEdit = (try? await c.get("/v1/admin/handbook", background: true).json?["can_edit"]?.bool) ?? env.session.can("updates.post")
            }
        }
        .refreshable { await article?.reload() }
        .sheet(isPresented: $editing) { ArticleEditSheet(article: article?.value) { editing = false; article?.load() } }
    }

    private func acknowledge(_ postId: Int) async {
        busy = true; defer { busy = false }
        do {
            let r = try await env.session.client.post("/v1/admin/updates/\(postId)/acknowledge")
            env.toasts.show(r.json?["message"]?.string ?? "Acknowledged")
            await article?.reload()
        } catch { env.toasts.error(error) }
    }

    private func remove() async {
        do {
            let r = try await env.session.client.delete("/v1/admin/handbook/\(id)")
            env.toasts.show(r.json?["message"]?.string ?? "Removed")
            env.navigator.go(.handbook())
        } catch { env.toasts.error(error) }
    }
}

/// New or edit (HandbookController::validateArticle: title, body, category, outlet_id, must_read, publish).
struct ArticleEditSheet: View {
    @Environment(AppEnvironment.self) private var env
    let article: Record?
    let onSaved: () -> Void
    @State private var title = ""
    @State private var body_ = ""
    @State private var category = ""
    @State private var outletId: Int?
    @State private var mustRead = false
    @State private var publish = true
    @State private var outlets = OutletChoices()
    @State private var busy = false

    var body: some View {
        MeSheet(article == nil ? "New article" : "Edit article", primary: publish ? "Publish" : "Save draft", disabled: title.trimmingCharacters(in: .whitespaces).isEmpty || body_.trimmingCharacters(in: .whitespaces).isEmpty, busy: busy, action: { await save() }) {
            FieldLabel("Title") { TextField("", text: $title).textFieldStyle(.roundedBorder) }
            FieldLabel("Category") { TextField("Till, Sales, Workshop, HR…", text: $category).textFieldStyle(.roundedBorder) }
            OutletPicker(choices: outlets, outletId: $outletId)
            FieldLabel("Text") { TextEditor(text: $body_).font(Theme.Font.mono).frame(minHeight: 220).overlay(RoundedRectangle(cornerRadius: 6).stroke(Theme.line)) }
            Toggle("Must read (goes on the feed; everyone presses \"I have read this\")", isOn: $mustRead)
            Toggle("Published", isOn: $publish)
        }
        .task {
            if let a = article {
                title = a.string("title") ?? ""; body_ = a.string("body") ?? ""; category = a.string("category") ?? ""
                outletId = a["outlet"]?["id"]?.int; mustRead = a.flag("must_read"); publish = !(a["published_at"]?.isNull ?? true)
            }
            await outlets.load(env)
        }
    }

    private func save() async {
        busy = true; defer { busy = false }
        let body: JSONValue = .object([
            "title": .string(title.trimmingCharacters(in: .whitespacesAndNewlines)),
            "body": .string(body_),
            "category": category.trimmingCharacters(in: .whitespaces).isEmpty ? .null : .string(category.trimmingCharacters(in: .whitespaces)),
            "outlet_id": outletId.map { .number(Double($0)) } ?? .null,
            "must_read": .bool(mustRead),
            "publish": .bool(publish),
        ])
        do {
            let r: APIClient.Response
            if let a = article { r = try await env.session.client.put("/v1/admin/handbook/\(a.id)", json: body) }
            else { r = try await env.session.client.post("/v1/admin/handbook", json: body) }
            env.toasts.show(r.json?["message"]?.string ?? "Saved")
            onSaved()
        } catch { env.toasts.error(error) }
    }
}

// MARK: - Training (CoursesPage.tsx): the list with my progress, lessons, the quiz; updates.post writes

struct CoursesView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var list: Resource<JSONValue>?
    @State private var editing = false

    var body: some View {
        Page("Training", subtitle: subtitle) {
            if list?.value?["can_edit"]?.bool == true {
                HStack { Spacer(); Button("New course") { editing = true }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
            }
            if let list {
                LoadableView(list.state, retry: { list.load() }, isEmpty: { $0.records.isEmpty }, empty: { EmptyState("No courses yet", symbol: "graduationcap") }) { d in
                    ForEach(d.records) { c in courseRow(c) }
                }
            }
        }
        .task {
            if list == nil {
                let c = env.session.client
                list = Resource { try await c.get("/v1/admin/courses").json ?? .null }
                list?.load()
            }
        }
        .refreshable { await list?.reload() }
        .sheet(isPresented: $editing) { CourseEditSheet(courseId: nil) { editing = false; list?.load() } }
    }

    private var subtitle: String? {
        guard let d = list?.value else { return nil }
        let n = d["to_do"]?.int ?? 0
        return n > 0 ? "\(MeFormat.plural(n, "course")) to complete." : "Short courses with a quiz at the end."
    }

    private func courseRow(_ c: Record) -> some View {
        let me = c["me"]
        let passed = me?["passed"]?.bool ?? false
        let required = c.flag("required")
        let lessons = c.int("lessons_count") ?? 0
        return Button { env.navigator.push(.courses(id: c.int("id"))) } label: {
            HStack(spacing: Theme.Space.m) {
                ZStack {
                    Circle().fill(passed ? Theme.Palette.success : Theme.Palette.neutralSoft)
                    Image(systemName: passed ? "checkmark" : "graduationcap").foregroundStyle(passed ? .white : Theme.textSecondary)
                }
                .frame(width: 40, height: 40)
                VStack(alignment: .leading, spacing: 2) {
                    HStack(spacing: 6) {
                        Text(c.string("title") ?? "").font(Theme.Font.body.weight(.semibold))
                        if c["published_at"]?.isNull ?? true { StatusBadge("draft", tone: .neutral) }
                    }
                    Text("\(MeFormat.plural(lessons, "lesson")) · \(MeFormat.plural(c.int("questions_count") ?? 0, "question")) · pass \(c.int("pass_mark") ?? 0)%\(passed ? " · passed \(me?["best_score"]?.string ?? "")%" : (me?["lessons_done"]?.int ?? 0) > 0 ? " · \(me?["lessons_done"]?.int ?? 0)/\(lessons) read" : "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                }
                Spacer()
                if required && !passed { Text("REQUIRED").font(Theme.Font.caption).foregroundStyle(.white).padding(.horizontal, 8).padding(.vertical, 3).background(Theme.Palette.danger, in: Capsule()) }
            }
            .padding(Theme.Space.l).frame(maxWidth: .infinity, alignment: .leading)
            .background(passed ? Theme.Palette.successSoft.opacity(0.4) : Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).stroke(passed ? Theme.Palette.success.opacity(0.3) : required ? Theme.Palette.danger.opacity(0.3) : Theme.line, lineWidth: 1))
            .foregroundStyle(Theme.text).contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

struct CourseView: View {
    @Environment(AppEnvironment.self) private var env
    let id: Int
    @State private var course: Resource<Record>?
    @State private var step = 0
    @State private var answers: [Int: Int] = [:]
    @State private var result: JSONValue?
    @State private var canEdit = false
    @State private var editing = false
    @State private var showProgress = false
    @State private var progress: Resource<JSONValue>?
    @State private var busy = false

    var body: some View {
        Page(course?.value?.string("title") ?? "Course") {
            if let course {
                LoadableView(course.state, retry: { course.load() }) { c in content(c) }
            }
        }
        .task {
            if course == nil {
                let c = env.session.client, courseId = id
                course = Resource {
                    let j = try await c.get("/v1/admin/courses/\(courseId)").json
                    return Record(json: j?["course"] ?? .null)
                }
                course?.load()
                canEdit = (try? await c.get("/v1/admin/courses", background: true).json?["can_edit"]?.bool) ?? env.session.can("updates.post")
            }
        }
        .refreshable { await course?.reload() }
        .sheet(isPresented: $editing) { CourseEditSheet(courseId: id) { editing = false; course?.load() } }
    }

    @ViewBuilder
    private func content(_ c: Record) -> some View {
        let lessons = c.records("lessons")
        let questions = c.records("questions")
        let atQuiz = step >= lessons.count
        let allRead = lessons.allSatisfy { $0.flag("done") }
        let passed = c["me"]?["passed"]?.bool ?? false
        SectionCard {
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 6) {
                    ForEach(Array(lessons.enumerated()), id: \.offset) { i, l in
                        chip("\(i + 1)", selected: i == step && !atQuiz, done: l.flag("done")) { step = i }
                    }
                    if !questions.isEmpty { chip("Quiz", selected: atQuiz, done: passed) { step = lessons.count } }
                    if passed { Text("Passed · \(c["me"]?["best_score"]?.string ?? "")%").font(Theme.Font.caption.weight(.semibold)).foregroundStyle(Theme.Palette.success) }
                }
            }
            if !atQuiz, lessons.indices.contains(step) {
                let lesson = lessons[step]
                Text(lesson.string("title") ?? "").font(Theme.Font.sectionTitle)
                Text(lesson.string("body") ?? "").font(Theme.Font.body).lineSpacing(3).textSelection(.enabled)
                HStack {
                    Spacer()
                    if !lesson.flag("done") { Button("Mark as read") { Task { await markDone(lesson) } }.buttonStyle(.bordered).disabled(busy) }
                    Button(step + 1 < lessons.count ? "Next lesson" : questions.isEmpty ? "Finish" : "To the quiz") {
                        if !lesson.flag("done") { Task { await markDone(lesson) } }
                        if step + 1 < lessons.count || !questions.isEmpty { step += 1 } else { env.navigator.go(.courses()) }
                    }
                    .buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                }
            }
            if atQuiz && !questions.isEmpty {
                if !allRead { NoticeText("Read every lesson first (\(lessons.filter { $0.flag("done") }.count) of \(lessons.count) read).", tone: .warning) }
                if passed {
                    NoticeText("You passed this course with \(c["me"]?["best_score"]?.string ?? "")% on \(MeFormat.day(c["me"]?["completed_at"]?.date)).", tone: .success)
                } else {
                    ForEach(Array(questions.enumerated()), id: \.offset) { i, q in quizQuestion(i, q) }
                    if let result {
                        NoticeText(result["message"]?.string ?? "", tone: result["passed"]?.bool == true ? .success : .danger)
                    }
                    HStack {
                        Spacer()
                        Button("Check my answers") { Task { await submitQuiz() } }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                            .disabled(!allRead || busy || answers.count < questions.count)
                    }
                }
            }
        }
        if canEdit {
            SectionCard {
                HStack {
                    Button(showProgress ? "Hide" : "Who has done it") { showProgress.toggle(); if showProgress { loadProgress() } }
                    Spacer()
                    ConfirmButton("Remove this course", message: "Completions on files stay.", destructive: true, action: { await remove() }) { Text("Remove").foregroundStyle(Theme.Palette.danger) }
                    Button("Edit") { editing = true }.buttonStyle(.bordered)
                }
                if showProgress, let progress {
                    LoadableView(progress.state, retry: { progress.load() }) { p in
                        Text("\(p["passed"]?.int ?? 0) of \(p["audience"]?.int ?? 0) passed").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        ListCard {
                            ForEach(p.records) { r in
                                ListRow {
                                    HStack {
                                        Text(r.string("name") ?? "").font(Theme.Font.secondary)
                                        Spacer()
                                        let passedRow = r.flag("passed")
                                        Text(passedRow ? "passed \(r["best_score"]?.string ?? "")%" : (r["started_at"]?.isNull ?? true) ? "not started" : "\(r.int("lessons_done") ?? 0) read · \(MeFormat.plural(r.int("attempts") ?? 0, "attempt"))")
                                            .font(Theme.Font.caption).foregroundStyle(passedRow ? Theme.Palette.success : Theme.textSecondary)
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    private func chip(_ text: String, selected: Bool, done: Bool, action: @escaping () -> Void) -> some View {
        Button(text, action: action)
            .font(Theme.Font.caption)
            .foregroundStyle(selected ? .white : done ? Theme.Palette.success : Theme.textSecondary)
            .padding(.horizontal, 10).padding(.vertical, 4)
            .background(selected ? Theme.Palette.brandDeep : done ? Theme.Palette.successSoft : Theme.card, in: Capsule())
            .overlay(Capsule().stroke(selected || done ? Color.clear : Theme.line, lineWidth: 1))
            .buttonStyle(.plain)
    }

    private func quizQuestion(_ i: Int, _ q: Record) -> some View {
        let qid = q.int("id") ?? i
        let wrong = result?["wrong"]?.array?.compactMap(\.int).contains(qid) ?? false
        return VStack(alignment: .leading, spacing: Theme.Space.s) {
            Text("\(i + 1). \(q.string("question") ?? "")").font(Theme.Font.body.weight(.medium))
            ForEach(Array(q.strings("options").enumerated()), id: \.offset) { oi, o in
                Button { answers[qid] = oi } label: {
                    HStack(spacing: Theme.Space.s) {
                        Image(systemName: answers[qid] == oi ? "largecircle.fill.circle" : "circle").foregroundStyle(answers[qid] == oi ? Theme.Palette.brand : Theme.textTertiary)
                        Text("\(o)\(canEdit && q.int("answer") == oi ? " ✓" : "")").font(Theme.Font.secondary)
                        Spacer()
                    }
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
            }
        }
        .padding(Theme.Space.m).frame(maxWidth: .infinity, alignment: .leading)
        .background(wrong ? Theme.Palette.dangerSoft.opacity(0.3) : Color.clear, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.field).stroke(wrong ? Theme.Palette.danger.opacity(0.4) : Theme.line, lineWidth: 1))
    }

    private func markDone(_ lesson: Record) async {
        busy = true; defer { busy = false }
        do { _ = try await env.session.client.post("/v1/admin/courses/\(id)/lessons/\(lesson.id)/done"); await course?.reload() } catch { env.toasts.error(error) }
    }

    /// `answers` keyed by question id → chosen option index (CoursesController::quiz).
    private func submitQuiz() async {
        busy = true; defer { busy = false }
        var obj: [String: JSONValue] = [:]
        for (k, v) in answers { obj[String(k)] = .number(Double(v)) }
        do {
            result = try await env.session.client.post("/v1/admin/courses/\(id)/quiz", json: .object(["answers": .object(obj)])).json
            await course?.reload()
        } catch { env.toasts.error(error) }
    }

    private func loadProgress() {
        let c = env.session.client, courseId = id
        progress = Resource { try await c.get("/v1/admin/courses/\(courseId)/progress").json ?? .null }
        progress?.load()
    }

    private func remove() async {
        do {
            let r = try await env.session.client.delete("/v1/admin/courses/\(id)")
            env.toasts.show(r.json?["message"]?.string ?? "Removed")
            env.navigator.go(.courses())
        } catch { env.toasts.error(error) }
    }
}

/// New or edit (CoursesController::validateCourse): title, description, outlet_id,
/// required, pass_mark, publish, lessons[{title, body}], questions[{question, options[], answer}].
struct CourseEditSheet: View {
    @Environment(AppEnvironment.self) private var env
    let courseId: Int?
    let onSaved: () -> Void
    struct Lesson: Identifiable { let id = UUID(); var title = ""; var body = "" }
    struct Question: Identifiable { let id = UUID(); var question = ""; var options = ["", ""]; var answer = 0 }
    @State private var title = ""
    @State private var description = ""
    @State private var outletId: Int?
    @State private var required = false
    @State private var passMark = 80
    @State private var publish = true
    @State private var lessons: [Lesson] = [Lesson()]
    @State private var questions: [Question] = []
    @State private var outlets = OutletChoices()
    @State private var loaded = false
    @State private var busy = false

    private var ok: Bool {
        !title.trimmingCharacters(in: .whitespaces).isEmpty && !lessons.isEmpty
            && lessons.allSatisfy { !$0.title.trimmingCharacters(in: .whitespaces).isEmpty && !$0.body.trimmingCharacters(in: .whitespaces).isEmpty }
            && questions.allSatisfy { !$0.question.trimmingCharacters(in: .whitespaces).isEmpty && $0.options.count >= 2 && $0.options.allSatisfy { !$0.trimmingCharacters(in: .whitespaces).isEmpty } }
    }

    var body: some View {
        MeSheet(courseId == nil ? "New course" : "Edit course", primary: publish ? "Publish" : "Save draft", disabled: !ok || !loaded, busy: busy, action: { await save() }) {
            FieldLabel("Title") { TextField("", text: $title).textFieldStyle(.roundedBorder) }
            FieldLabel("What it is for") { TextField("", text: $description, axis: .vertical).lineLimit(2...4).textFieldStyle(.roundedBorder) }
            OutletPicker(choices: outlets, outletId: $outletId)
            Stepper("Pass mark \(passMark)%", value: $passMark, in: 1...100, step: 5)
            Toggle("Required (waits on the Me page until passed)", isOn: $required)
            Toggle("Published", isOn: $publish)
            Text("Lessons").font(Theme.Font.sectionTitle)
            ForEach($lessons) { $l in
                VStack(alignment: .leading, spacing: 4) {
                    HStack {
                        TextField("Lesson title", text: $l.title).textFieldStyle(.roundedBorder)
                        Button { lessons.removeAll { $0.id == l.id } } label: { Image(systemName: "xmark") }.buttonStyle(.plain).foregroundStyle(Theme.textTertiary).accessibilityLabel("Remove lesson")
                    }
                    TextEditor(text: $l.body).font(Theme.Font.secondary).frame(minHeight: 90).overlay(RoundedRectangle(cornerRadius: 6).stroke(Theme.line))
                }
                .padding(Theme.Space.s).overlay(RoundedRectangle(cornerRadius: Theme.Radius.field).stroke(Theme.line))
            }
            Button("+ Lesson") { lessons.append(Lesson()) }.buttonStyle(.bordered)
            Text("Quiz").font(Theme.Font.sectionTitle)
            ForEach($questions) { $q in
                VStack(alignment: .leading, spacing: 4) {
                    HStack {
                        TextField("Question", text: $q.question).textFieldStyle(.roundedBorder)
                        Button { questions.removeAll { $0.id == q.id } } label: { Image(systemName: "xmark") }.buttonStyle(.plain).foregroundStyle(Theme.textTertiary).accessibilityLabel("Remove question")
                    }
                    ForEach(q.options.indices, id: \.self) { oi in
                        HStack(spacing: Theme.Space.s) {
                            Button { q.answer = oi } label: { Image(systemName: q.answer == oi ? "largecircle.fill.circle" : "circle").foregroundStyle(q.answer == oi ? Theme.Palette.brand : Theme.textTertiary) }.buttonStyle(.plain).accessibilityLabel("The right answer")
                            TextField("Option \(oi + 1)", text: $q.options[oi]).textFieldStyle(.roundedBorder)
                            if q.options.count > 2 {
                                Button { q.options.remove(at: oi); q.answer = min(q.answer, q.options.count - 1) } label: { Image(systemName: "xmark") }.buttonStyle(.plain).foregroundStyle(Theme.textTertiary)
                            }
                        }
                    }
                    if q.options.count < 6 { Button("+ option") { q.options.append("") }.font(Theme.Font.caption) }
                }
                .padding(Theme.Space.s).overlay(RoundedRectangle(cornerRadius: Theme.Radius.field).stroke(Theme.line))
            }
            Button("+ Question") { questions.append(Question()) }.buttonStyle(.bordered)
            Text("Tick the circle beside the right answer.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
        .task {
            await outlets.load(env)
            if let courseId, !loaded {
                if let j = try? await env.session.client.get("/v1/admin/courses/\(courseId)").json?["course"] {
                    let c = Record(json: j)
                    title = c.string("title") ?? ""; description = c.string("description") ?? ""; outletId = c["outlet"]?["id"]?.int
                    required = c.flag("required"); passMark = c.int("pass_mark") ?? 80; publish = !(c["published_at"]?.isNull ?? true)
                    lessons = c.records("lessons").map { Lesson(title: $0.string("title") ?? "", body: $0.string("body") ?? "") }
                    questions = c.records("questions").map { Question(question: $0.string("question") ?? "", options: $0.strings("options"), answer: $0.int("answer") ?? 0) }
                    if lessons.isEmpty { lessons = [Lesson()] }
                }
            }
            loaded = true
        }
    }

    private func save() async {
        busy = true; defer { busy = false }
        let body: JSONValue = .object([
            "title": .string(title.trimmingCharacters(in: .whitespacesAndNewlines)),
            "description": description.trimmingCharacters(in: .whitespaces).isEmpty ? .null : .string(description),
            "outlet_id": outletId.map { .number(Double($0)) } ?? .null,
            "required": .bool(required),
            "pass_mark": .number(Double(passMark)),
            "publish": .bool(publish),
            "lessons": .array(lessons.map { .object(["title": .string($0.title), "body": .string($0.body)]) }),
            "questions": .array(questions.map { .object(["question": .string($0.question), "options": .array($0.options.map(JSONValue.string)), "answer": .number(Double($0.answer))]) }),
        ])
        do {
            let r: APIClient.Response
            if let courseId { r = try await env.session.client.put("/v1/admin/courses/\(courseId)", json: body) }
            else { r = try await env.session.client.post("/v1/admin/courses", json: body) }
            env.toasts.show(r.json?["message"]?.string ?? "Saved")
            onSaved()
        } catch { env.toasts.error(error) }
    }
}
