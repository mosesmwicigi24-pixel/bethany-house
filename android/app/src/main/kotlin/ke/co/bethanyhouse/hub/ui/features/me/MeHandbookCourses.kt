package ke.co.bethanyhouse.hub.ui.features.me

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.ChevronRight
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.MenuBook
import androidx.compose.material.icons.filled.RadioButtonChecked
import androidx.compose.material.icons.filled.RadioButtonUnchecked
import androidx.compose.material.icons.filled.Remove
import androidx.compose.material.icons.filled.School
import androidx.compose.material3.Button
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.date
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.Loadable
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.listRecords
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import java.util.UUID

// ── Handbook (HandbookPage.tsx): searchable, grouped by category; updates.post writes ──

@Composable
internal fun HandbookScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var query by remember { mutableStateOf("") }
    var editing by remember { mutableStateOf(false) }
    val list = remember { Resource(scope) { env.client.get("/v1/admin/handbook", mapOf("q" to query.trim().ifEmpty { null })).json ?: JsonNull } }
    LaunchedEffect(query) { if (list.state != Loadable.Idle) delay(300); list.reload() }

    Page("Handbook", subtitle = "How we do things at Bethany House.", refresh = { list.reload() }) {
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
            ModuleSearchField(query, { query = it }, "Search policies and how-tos…", onSubmit = { list.load() }, modifier = Modifier.weight(1f))
            if (list.value["can_edit"].bool == true) Button(onClick = { editing = true }) { Text("New article") }
        }
        LoadableView(list.state, retry = { list.load() }, isEmpty = { it.listRecords.isEmpty() }, empty = { EmptyState("Nothing here yet", icon = Icons.Default.MenuBook) }) { d ->
            val groups = d.listRecords.groupBy { it.string("category") ?: "General" }
            groups.keys.sorted().forEach { cat ->
                MeSectionLabel(cat)
                MeListCard {
                    groups[cat].orEmpty().forEach { a ->
                        MeListRow(onClick = { env.navigator.push(Route.Handbook(a.intId)) }) {
                            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                                    Text(a.string("title") ?: "", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                                    if (a["published_at"].isNull) StatusBadge("draft", StatusTone.NEUTRAL)
                                }
                                Text(a.string("excerpt") ?: "", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            }
                            if (a.flag("must_read")) {
                                val acked = !a["acknowledged_at"].isNull
                                StatusBadge(if (acked) "Read" else "Must read", if (acked) StatusTone.SUCCESS else StatusTone.DANGER)
                            }
                            Icon(Icons.Default.ChevronRight, contentDescription = null, tint = MaterialTheme.hub.textTertiary, modifier = Modifier.size(16.dp))
                        }
                    }
                }
            }
        }
    }
    if (editing) ArticleEditSheet(article = null, onDismiss = { editing = false }, onSaved = { editing = false; list.load() })
}

@Composable
internal fun ArticleScreen(id: Int) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val c = env.client
    val article = remember { Resource(scope) { Record(c.get("/v1/admin/handbook/$id").json["article"] ?: JsonNull) } }
    var canEdit by remember { mutableStateOf(false) }
    var editing by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) {
        article.load()
        canEdit = runCatching { c.get("/v1/admin/handbook", background = true).json["can_edit"].bool }.getOrNull() ?: env.session.current.can("updates.post")
    }

    suspend fun acknowledge(postId: Int) {
        busy = true
        try { val r = c.post("/v1/admin/updates/$postId/acknowledge"); env.toasts.show(r.json["message"].str ?: "Acknowledged"); article.reload() } catch (e: ApiError) { env.toasts.error(e) } finally { busy = false }
    }
    suspend fun remove() {
        try { val r = c.delete("/v1/admin/handbook/$id"); env.toasts.show(r.json["message"].str ?: "Removed"); env.navigator.go(Route.Handbook()) } catch (e: ApiError) { env.toasts.error(e) }
    }

    Page(article.value?.string("title") ?: "Article", refresh = { article.reload() }) {
        LoadableView(article.state, retry = { article.load() }) { a ->
            SectionCard {
                Text("${a.string("category") ?: "General"}${a["outlet"]["name"].str?.let { " · $it" } ?: ""} · updated ${MeFormat.dayYear(a.date("updated_at"))}${if (a["published_at"].isNull) " · draft" else ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                SelectionContainer { Text(a.string("body") ?: "", style = MaterialTheme.typography.bodyMedium) }
                val postId = a.int("post_id")
                if (a.flag("must_read") && postId != null) {
                    val at = a.date("acknowledged_at")
                    if (at != null) MeNotice("You acknowledged this on ${MeFormat.day(at)}.", StatusTone.SUCCESS)
                    else Button(onClick = { scope.launch { acknowledge(postId) } }, enabled = !busy, modifier = Modifier.fillMaxWidth()) { Text("I have read and understood this") }
                }
                if (canEdit) {
                    HorizontalDivider(color = MaterialTheme.hub.line)
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s, Alignment.End)) {
                        ConfirmButton("Remove this article", "It is taken out of the handbook for everyone.", destructive = true, action = { remove() }) { Text("Remove") }
                        OutlinedButton(onClick = { editing = true }) { Text("Edit") }
                    }
                }
            }
        }
    }
    if (editing) ArticleEditSheet(article = article.value, onDismiss = { editing = false }, onSaved = { editing = false; article.load() })
}

/** New or edit (HandbookController::validateArticle: title, body, category, outlet_id, must_read, publish). */
@Composable
private fun ArticleEditSheet(article: Record?, onDismiss: () -> Unit, onSaved: () -> Unit) {
    val env = LocalEnv.current
    var title by remember { mutableStateOf(article?.string("title") ?: "") }
    var body by remember { mutableStateOf(article?.string("body") ?: "") }
    var category by remember { mutableStateOf(article?.string("category") ?: "") }
    var outletId by remember { mutableStateOf(article?.get("outlet")["id"].int) }
    var mustRead by remember { mutableStateOf(article?.flag("must_read") ?: false) }
    var publish by remember { mutableStateOf(if (article == null) true else !article["published_at"].isNull) }
    val outlets = remember { MeOutletChoices(env) }
    LaunchedEffect(Unit) { outlets.load() }

    suspend fun save() {
        val json = jsonOf(
            "title" to title.trim(), "body" to body,
            "category" to category.trim().ifEmpty { null },
            "outlet_id" to outletId, "must_read" to mustRead, "publish" to publish,
        )
        val r = if (article != null) env.client.put("/v1/admin/handbook/${article.id}", json) else env.client.post("/v1/admin/handbook", json)
        env.toasts.show(r.json["message"].str ?: "Saved")
        onSaved()
    }

    MeSheet(if (article == null) "New article" else "Edit article", primary = if (publish) "Publish" else "Save draft", onDismiss = onDismiss, disabled = title.isBlank() || body.isBlank(), action = { save() }) {
        Field("Title", title, { title = it })
        Field("Category", category, { category = it }, placeholder = "Till, Sales, Workshop, HR…")
        MeOutletPicker(outlets, outletId) { outletId = it }
        OutlinedTextField(value = body, onValueChange = { body = it }, label = { Text("Text") }, minLines = 10, modifier = Modifier.fillMaxWidth(), textStyle = MaterialTheme.typography.bodyMedium.copy(fontFamily = FontFamily.Monospace))
        ToggleRow("Must read (goes on the feed; everyone presses \"I have read this\")", mustRead, { mustRead = it })
        ToggleRow("Published", publish, { publish = it })
    }
}

// ── Training (CoursesPage.tsx): the list with my progress, lessons, the quiz; updates.post writes ──

@Composable
internal fun CoursesScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var editing by remember { mutableStateOf(false) }
    val list = remember { Resource(scope) { env.client.get("/v1/admin/courses").json ?: JsonNull } }
    LaunchedEffect(Unit) { list.load() }
    val subtitle = list.value?.let { d -> val n = d["to_do"].int ?: 0; if (n > 0) "${MeFormat.plural(n, "course")} to complete." else "Short courses with a quiz at the end." }

    Page("Training", subtitle = subtitle, refresh = { list.reload() }) {
        if (list.value["can_edit"].bool == true) Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) { Button(onClick = { editing = true }) { Text("New course") } }
        LoadableView(list.state, retry = { list.load() }, isEmpty = { it.listRecords.isEmpty() }, empty = { EmptyState("No courses yet", icon = Icons.Default.School) }) { d ->
            d.listRecords.forEach { c -> CourseRow(c) { env.navigator.push(Route.Courses(c.intId)) } }
        }
    }
    if (editing) CourseEditSheet(courseId = null, onDismiss = { editing = false }, onSaved = { editing = false; list.load() })
}

@Composable
private fun CourseRow(c: Record, open: () -> Unit) {
    val me = c["me"]
    val passed = me["passed"].bool ?: false
    val required = c.flag("required")
    val lessons = c.int("lessons_count") ?: 0
    val lessonsDone = me["lessons_done"].int ?: 0
    Surface(
        shape = MaterialTheme.shapes.large,
        color = if (passed) Palette.successSoft.copy(alpha = 0.4f) else MaterialTheme.hub.card,
        modifier = Modifier.fillMaxWidth().border(1.dp, if (passed) Palette.success.copy(alpha = 0.3f) else if (required) Palette.danger.copy(alpha = 0.3f) else MaterialTheme.hub.line, MaterialTheme.shapes.large).clickable(onClick = open),
    ) {
        Row(Modifier.padding(Space.l), horizontalArrangement = Arrangement.spacedBy(Space.m), verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(40.dp).background(if (passed) Palette.success else Palette.neutralSoft, CircleShape), contentAlignment = Alignment.Center) {
                Icon(if (passed) Icons.Default.Check else Icons.Default.School, contentDescription = null, tint = if (passed) Color.White else MaterialTheme.hub.textSecondary, modifier = Modifier.size(20.dp))
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text(c.string("title") ?: "", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                    if (c["published_at"].isNull) StatusBadge("draft", StatusTone.NEUTRAL)
                }
                val progress = if (passed) " · passed ${me["best_score"].str ?: ""}%" else if (lessonsDone > 0) " · $lessonsDone/$lessons read" else ""
                Text("${MeFormat.plural(lessons, "lesson")} · ${MeFormat.plural(c.int("questions_count") ?: 0, "question")} · pass ${c.int("pass_mark") ?: 0}%$progress", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            }
            if (required && !passed) Text("REQUIRED", style = MaterialTheme.typography.labelSmall, color = Color.White, modifier = Modifier.background(Palette.danger, CircleShape).padding(horizontal = 8.dp, vertical = 3.dp))
        }
    }
}

@Composable
internal fun CourseScreen(id: Int) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val c = env.client
    val course = remember { Resource(scope) { Record(c.get("/v1/admin/courses/$id").json["course"] ?: JsonNull) } }
    var step by remember { mutableIntStateOf(0) }
    val answers = remember { mutableStateMapOf<Int, Int>() }
    var result by remember { mutableStateOf<JsonElement?>(null) }
    var canEdit by remember { mutableStateOf(false) }
    var editing by remember { mutableStateOf(false) }
    var showProgress by remember { mutableStateOf(false) }
    val progress = remember { Resource(scope) { c.get("/v1/admin/courses/$id/progress").json ?: JsonNull } }
    var busy by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) {
        course.load()
        canEdit = runCatching { c.get("/v1/admin/courses", background = true).json["can_edit"].bool }.getOrNull() ?: env.session.current.can("updates.post")
    }

    suspend fun markDone(lesson: Record) {
        busy = true
        try { c.post("/v1/admin/courses/$id/lessons/${lesson.id}/done"); course.reload() } catch (e: ApiError) { env.toasts.error(e) } finally { busy = false }
    }
    /** `answers` keyed by question id → chosen option index (CoursesController::quiz). */
    suspend fun submitQuiz() {
        busy = true
        try {
            val obj = JsonObject(answers.entries.associate { (k, v) -> k.toString() to JsonPrimitive(v) })
            result = c.post("/v1/admin/courses/$id/quiz", jsonOf("answers" to obj)).json
            course.reload()
        } catch (e: ApiError) { env.toasts.error(e) } finally { busy = false }
    }
    suspend fun remove() {
        try { val r = c.delete("/v1/admin/courses/$id"); env.toasts.show(r.json["message"].str ?: "Removed"); env.navigator.go(Route.Courses()) } catch (e: ApiError) { env.toasts.error(e) }
    }

    Page(course.value?.string("title") ?: "Course", refresh = { course.reload() }) {
        LoadableView(course.state, retry = { course.load() }) { cr ->
            val lessons = cr.records("lessons")
            val questions = cr.records("questions")
            val atQuiz = step >= lessons.size
            val allRead = lessons.all { it.flag("done") }
            val passed = cr["me"]["passed"].bool ?: false
            SectionCard {
                Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                    lessons.forEachIndexed { i, l -> Chip("${i + 1}", selected = i == step && !atQuiz, done = l.flag("done")) { step = i } }
                    if (questions.isNotEmpty()) Chip("Quiz", selected = atQuiz, done = passed) { step = lessons.size }
                    if (passed) Text("Passed · ${cr["me"]["best_score"].str ?: ""}%", style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold, color = Palette.success)
                }
                if (!atQuiz && step in lessons.indices) {
                    val lesson = lessons[step]
                    Text(lesson.string("title") ?: "", style = MaterialTheme.typography.titleMedium)
                    SelectionContainer { Text(lesson.string("body") ?: "", style = MaterialTheme.typography.bodyMedium) }
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s, Alignment.End)) {
                        if (!lesson.flag("done")) OutlinedButton(onClick = { scope.launch { markDone(lesson) } }, enabled = !busy) { Text("Mark as read") }
                        Button(onClick = {
                            if (!lesson.flag("done")) scope.launch { markDone(lesson) }
                            if (step + 1 < lessons.size || questions.isNotEmpty()) step += 1 else env.navigator.go(Route.Courses())
                        }) { Text(if (step + 1 < lessons.size) "Next lesson" else if (questions.isEmpty()) "Finish" else "To the quiz") }
                    }
                }
                if (atQuiz && questions.isNotEmpty()) {
                    if (!allRead) MeNotice("Read every lesson first (${lessons.count { it.flag("done") }} of ${lessons.size} read).", StatusTone.WARNING)
                    if (passed) {
                        MeNotice("You passed this course with ${cr["me"]["best_score"].str ?: ""}% on ${MeFormat.day(cr["me"]["completed_at"].date)}.", StatusTone.SUCCESS)
                    } else {
                        questions.forEachIndexed { i, q -> QuizQuestion(i, q, answers, result, canEdit) }
                        result?.let { r -> MeNotice(r["message"].str ?: "", if (r["passed"].bool == true) StatusTone.SUCCESS else StatusTone.DANGER) }
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) {
                            Button(onClick = { scope.launch { submitQuiz() } }, enabled = allRead && !busy && answers.size >= questions.size) { Text("Check my answers") }
                        }
                    }
                }
            }
            if (canEdit) SectionCard {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                    TextButton(onClick = { showProgress = !showProgress; if (showProgress) progress.load() }) { Text(if (showProgress) "Hide" else "Who has done it") }
                    Spacer(Modifier.weight(1f))
                    ConfirmButton("Remove this course", "Completions on files stay.", destructive = true, action = { remove() }) { Text("Remove") }
                    OutlinedButton(onClick = { editing = true }) { Text("Edit") }
                }
                if (showProgress) LoadableView(progress.state, retry = { progress.load() }) { p ->
                    Text("${p["passed"].int ?: 0} of ${p["audience"].int ?: 0} passed", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    MeListCard {
                        p.listRecords.forEach { r ->
                            MeListRow {
                                Text(r.string("name") ?: "", style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f))
                                val passedRow = r.flag("passed")
                                Text(
                                    if (passedRow) "passed ${r["best_score"].str ?: ""}%" else if (r["started_at"].isNull) "not started" else "${r.int("lessons_done") ?: 0} read · ${MeFormat.plural(r.int("attempts") ?: 0, "attempt")}",
                                    style = MaterialTheme.typography.labelSmall, color = if (passedRow) Palette.success else MaterialTheme.hub.textSecondary,
                                )
                            }
                        }
                    }
                }
            }
        }
    }
    if (editing) CourseEditSheet(courseId = id, onDismiss = { editing = false }, onSaved = { editing = false; course.load() })
}

@Composable
private fun Chip(text: String, selected: Boolean, done: Boolean, onClick: () -> Unit) {
    Surface(
        shape = CircleShape,
        color = if (selected) Palette.brandDeep else if (done) Palette.successSoft else MaterialTheme.hub.card,
        modifier = Modifier.border(1.dp, if (selected || done) Color.Transparent else MaterialTheme.hub.line, CircleShape).clickable(onClick = onClick),
    ) {
        Text(text, style = MaterialTheme.typography.labelSmall, color = if (selected) Color.White else if (done) Palette.success else MaterialTheme.hub.textSecondary, modifier = Modifier.padding(horizontal = 10.dp, vertical = 4.dp))
    }
}

@Composable
private fun QuizQuestion(i: Int, q: Record, answers: MutableMap<Int, Int>, result: JsonElement?, canEdit: Boolean) {
    val qid = q.int("id") ?: i
    val wrong = result["wrong"].records.mapNotNull { it.int }.contains(qid)
    Column(
        Modifier.fillMaxWidth().background(if (wrong) Palette.dangerSoft.copy(alpha = 0.3f) else Color.Transparent, MaterialTheme.shapes.small).border(1.dp, if (wrong) Palette.danger.copy(alpha = 0.4f) else MaterialTheme.hub.line, MaterialTheme.shapes.small).padding(Space.m),
        verticalArrangement = Arrangement.spacedBy(Space.s),
    ) {
        Text("${i + 1}. ${q.string("question") ?: ""}", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Medium)
        q.strings("options").forEachIndexed { oi, o ->
            val chosen = answers[qid] == oi
            Row(Modifier.fillMaxWidth().clickable { answers[qid] = oi }, horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                Icon(if (chosen) Icons.Default.RadioButtonChecked else Icons.Default.RadioButtonUnchecked, contentDescription = null, tint = if (chosen) Palette.brand else MaterialTheme.hub.textTertiary, modifier = Modifier.size(20.dp))
                Text("$o${if (canEdit && q.int("answer") == oi) " ✓" else ""}", style = MaterialTheme.typography.bodySmall)
            }
        }
    }
}

/**
 * New or edit (CoursesController::validateCourse): title, description, outlet_id,
 * required, pass_mark, publish, lessons[{title, body}], questions[{question, options[], answer}].
 */
private data class EditLesson(val id: String = UUID.randomUUID().toString(), val title: String = "", val body: String = "")
private data class EditQuestion(val id: String = UUID.randomUUID().toString(), val question: String = "", val options: List<String> = listOf("", ""), val answer: Int = 0)

@Composable
private fun CourseEditSheet(courseId: Int?, onDismiss: () -> Unit, onSaved: () -> Unit) {
    val env = LocalEnv.current
    var title by remember { mutableStateOf("") }
    var description by remember { mutableStateOf("") }
    var outletId by remember { mutableStateOf<Int?>(null) }
    var required by remember { mutableStateOf(false) }
    var passMark by remember { mutableIntStateOf(80) }
    var publish by remember { mutableStateOf(true) }
    var lessons by remember { mutableStateOf(listOf(EditLesson())) }
    var questions by remember { mutableStateOf(listOf<EditQuestion>()) }
    val outlets = remember { MeOutletChoices(env) }
    var loaded by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) {
        outlets.load()
        if (courseId != null && !loaded) {
            try {
                val cr = Record(env.client.get("/v1/admin/courses/$courseId").json["course"] ?: JsonNull)
                title = cr.string("title") ?: ""; description = cr.string("description") ?: ""; outletId = cr["outlet"]["id"].int
                required = cr.flag("required"); passMark = cr.int("pass_mark") ?: 80; publish = !cr["published_at"].isNull
                lessons = cr.records("lessons").map { EditLesson(title = it.string("title") ?: "", body = it.string("body") ?: "") }.ifEmpty { listOf(EditLesson()) }
                questions = cr.records("questions").map { EditQuestion(question = it.string("question") ?: "", options = it.strings("options"), answer = it.int("answer") ?: 0) }
            } catch (e: ApiError) { env.toasts.error(e) }
        }
        loaded = true
    }
    val ok = title.isNotBlank() && lessons.isNotEmpty()
        && lessons.all { it.title.isNotBlank() && it.body.isNotBlank() }
        && questions.all { q -> q.question.isNotBlank() && q.options.size >= 2 && q.options.all { it.isNotBlank() } }

    suspend fun save() {
        val json = jsonOf(
            "title" to title.trim(),
            "description" to description.trim().ifEmpty { null },
            "outlet_id" to outletId, "required" to required, "pass_mark" to passMark, "publish" to publish,
            "lessons" to lessons.map { jsonOf("title" to it.title, "body" to it.body) },
            "questions" to questions.map { jsonOf("question" to it.question, "options" to it.options, "answer" to it.answer) },
        )
        val r = if (courseId != null) env.client.put("/v1/admin/courses/$courseId", json) else env.client.post("/v1/admin/courses", json)
        env.toasts.show(r.json["message"].str ?: "Saved")
        onSaved()
    }

    MeSheet(if (courseId == null) "New course" else "Edit course", primary = if (publish) "Publish" else "Save draft", onDismiss = onDismiss, disabled = !ok || !loaded, action = { save() }) {
        Field("Title", title, { title = it })
        Field("What it is for", description, { description = it }, multiline = true)
        MeOutletPicker(outlets, outletId) { outletId = it }
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Text("Pass mark $passMark%", style = MaterialTheme.typography.bodyMedium, modifier = Modifier.weight(1f))
            IconButton(onClick = { passMark = maxOf(1, passMark - 5) }) { Icon(Icons.Default.Remove, contentDescription = "Lower pass mark") }
            IconButton(onClick = { passMark = minOf(100, passMark + 5) }) { Icon(Icons.Default.Add, contentDescription = "Raise pass mark") }
        }
        ToggleRow("Required (waits on the Me page until passed)", required, { required = it })
        ToggleRow("Published", publish, { publish = it })
        Text("Lessons", style = MaterialTheme.typography.titleMedium)
        lessons.forEach { l ->
            Column(Modifier.fillMaxWidth().border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.small).padding(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    OutlinedTextField(value = l.title, onValueChange = { v -> lessons = lessons.map { if (it.id == l.id) it.copy(title = v) else it } }, placeholder = { Text("Lesson title") }, singleLine = true, modifier = Modifier.weight(1f))
                    IconButton(onClick = { lessons = lessons.filterNot { it.id == l.id } }) { Icon(Icons.Default.Close, contentDescription = "Remove lesson", tint = MaterialTheme.hub.textTertiary) }
                }
                OutlinedTextField(value = l.body, onValueChange = { v -> lessons = lessons.map { if (it.id == l.id) it.copy(body = v) else it } }, minLines = 4, modifier = Modifier.fillMaxWidth())
            }
        }
        OutlinedButton(onClick = { lessons = lessons + EditLesson() }) { Text("+ Lesson") }
        Text("Quiz", style = MaterialTheme.typography.titleMedium)
        questions.forEach { q ->
            Column(Modifier.fillMaxWidth().border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.small).padding(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    OutlinedTextField(value = q.question, onValueChange = { v -> questions = questions.map { if (it.id == q.id) it.copy(question = v) else it } }, placeholder = { Text("Question") }, singleLine = true, modifier = Modifier.weight(1f))
                    IconButton(onClick = { questions = questions.filterNot { it.id == q.id } }) { Icon(Icons.Default.Close, contentDescription = "Remove question", tint = MaterialTheme.hub.textTertiary) }
                }
                q.options.forEachIndexed { oi, o ->
                    Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                        IconButton(onClick = { questions = questions.map { if (it.id == q.id) it.copy(answer = oi) else it } }) {
                            Icon(if (q.answer == oi) Icons.Default.RadioButtonChecked else Icons.Default.RadioButtonUnchecked, contentDescription = "The right answer", tint = if (q.answer == oi) Palette.brand else MaterialTheme.hub.textTertiary)
                        }
                        OutlinedTextField(value = o, onValueChange = { v -> questions = questions.map { if (it.id == q.id) it.copy(options = it.options.mapIndexed { j, x -> if (j == oi) v else x }) else it } }, placeholder = { Text("Option ${oi + 1}") }, singleLine = true, modifier = Modifier.weight(1f))
                        if (q.options.size > 2) IconButton(onClick = { questions = questions.map { if (it.id == q.id) { val opts = it.options.filterIndexed { j, _ -> j != oi }; it.copy(options = opts, answer = minOf(it.answer, opts.size - 1)) } else it } }) { Icon(Icons.Default.Close, contentDescription = "Remove option", tint = MaterialTheme.hub.textTertiary) }
                    }
                }
                if (q.options.size < 6) TextButton(onClick = { questions = questions.map { if (it.id == q.id) it.copy(options = it.options + "") else it } }) { Text("+ option") }
            }
        }
        OutlinedButton(onClick = { questions = questions + EditQuestion() }) { Text("+ Question") }
        Text("Tick the circle beside the right answer.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
    }
}
