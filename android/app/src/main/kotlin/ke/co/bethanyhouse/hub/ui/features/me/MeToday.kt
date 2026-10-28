package ke.co.bethanyhouse.hub.ui.features.me

import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ChevronRight
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.date
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.obj
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import java.time.Instant

/**
 * Me › Today (MePage.tsx): the phone's home for a staff member. Today
 * first — the clock card — then everything that is theirs. A manager with
 * `attendance.view_team` also sees the team now and the requests waiting.
 */
@Composable
internal fun MeTodayScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val c = env.client
    val teamView = env.session.current.can("attendance.view_team")
    val status = remember { Resource(scope) { c.get("/v1/admin/time-clock/status", background = true).json ?: JsonNull } }
    val leave = remember { Resource(scope) { c.get("/v1/admin/me/leave", background = true).json ?: JsonNull } }
    val feed = remember { Resource(scope) { c.get("/v1/admin/updates", listOf("page" to "1"), background = true).json ?: JsonNull } }
    val forms = remember { Resource(scope) { c.get("/v1/admin/forms/today", background = true).json ?: JsonNull } }
    val signing = remember { Resource(scope) { c.get("/v1/admin/me/sign", background = true).json ?: JsonNull } }
    val courses = remember { Resource(scope) { c.get("/v1/admin/courses", background = true).json ?: JsonNull } }
    val todos = remember { Resource(scope) { c.get("/v1/admin/todos", mapOf("scope" to "mine", "status" to "open"), background = true).json ?: JsonNull } }
    val overview = remember { if (teamView) Resource(scope) { c.get("/v1/admin/people/overview", background = true).json ?: JsonNull } else null }
    val all = remember { listOfNotNull(status, leave, feed, forms, signing, courses, todos, overview) }
    suspend fun loadAll() = coroutineScope { all.forEach { r -> launch { r.reload() } } }
    LaunchedEffect(Unit) { loadAll() }

    val me = env.session.current.me
    val firstName = me?.raw["first_name"].str ?: me?.firstName?.takeIf { it.isNotEmpty() } ?: "there"

    Page("Hi $firstName", subtitle = MeFormat.longDay(Instant.now()), refresh = { loadAll() }) {
        ClockCard(status.value)
        feed.value?.let { UpdatesCard(it) }
        MeListCard { todayLinks(status.value, leave.value, forms.value, signing.value, courses.value, todos.value).forEach { MeLinkRow(it.label, it.hint, it.route) } }
        if (teamView) overview?.value?.let { TeamCard(it) }
    }
}

// ── Clock card ───────────────────────────────────────────────────────────────

@Composable
private fun ClockCard(s: JsonElement?) {
    val env = LocalEnv.current
    val active = s["active_entry"].obj
    val shift = s["today_shift"].obj
    val onBreak = active["on_break"].bool ?: false
    Surface(
        shape = MaterialTheme.shapes.large,
        color = if (active != null) Palette.successSoft else MaterialTheme.hub.card,
        modifier = Modifier.fillMaxWidth().border(1.dp, if (active != null) Palette.success.copy(alpha = 0.3f) else MaterialTheme.hub.line, MaterialTheme.shapes.large).clickable { env.navigator.go(Route.Clock) },
    ) {
        Column(Modifier.padding(Space.l), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text("TODAY", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
            if (active != null) {
                Text("Clocked in since ${MeFormat.time(active["clock_in_at"].date)}${if (onBreak) " · on break" else ""}", style = MaterialTheme.typography.titleLarge)
                Text("${MeFormat.hm(active["elapsed_minutes"].int)} so far · tap to clock out", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            } else {
                Text("Not clocked in", style = MaterialTheme.typography.titleLarge)
                Text(shift?.let { "Shift ${it["starts_at"].str ?: ""}–${it["ends_at"].str ?: ""} · tap to clock in" } ?: "Tap to clock in", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            }
        }
    }
}

// ── Updates card ─────────────────────────────────────────────────────────────

@Composable
private fun UpdatesCard(f: JsonElement) {
    val env = LocalEnv.current
    val posts = f["data"].records
    val mustRead = f["must_read_pending"].int ?: 0
    val unread = posts.count { it["me"]["read_at"].isNull }
    Surface(
        shape = MaterialTheme.shapes.large,
        color = if (mustRead > 0) Palette.dangerSoft.copy(alpha = 0.4f) else MaterialTheme.hub.card,
        modifier = Modifier.fillMaxWidth().border(1.dp, if (mustRead > 0) Palette.danger.copy(alpha = 0.3f) else MaterialTheme.hub.line, MaterialTheme.shapes.large).clickable { env.navigator.go(Route.Updates()) },
    ) {
        Column(Modifier.padding(Space.l), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("Updates", style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
                if (mustRead > 0) Text("${MeFormat.plural(mustRead, "must-read")} waiting", style = MaterialTheme.typography.labelSmall, color = Palette.danger)
                else Text(if (unread > 0) "$unread new" else "all read", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
                Icon(Icons.Default.ChevronRight, contentDescription = null, tint = MaterialTheme.hub.textTertiary, modifier = Modifier.size(16.dp))
            }
            posts.take(3).forEach { p ->
                val isUnread = p["me"]["read_at"].isNull
                Text(postLine(p), style = MaterialTheme.typography.bodySmall, fontWeight = if (isUnread) FontWeight.Medium else FontWeight.Normal, color = if (isUnread) MaterialTheme.hub.text else MaterialTheme.hub.textSecondary, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
            if (posts.isEmpty()) Text("Nothing posted yet.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
        }
    }
}

private fun postLine(p: JsonElement): String {
    val title = p["title"].str ?: ""
    return when (p["kind"].str) {
        "recognition" -> "⭐ ${p["author"]["name"].str ?: ""} recognised ${p["recipient"]["name"].str ?: ""}"
        "celebration" -> "🎉 $title"
        "event" -> "📅 $title"
        else -> "📣 $title"
    }
}

// ── Links ────────────────────────────────────────────────────────────────────

private class LinkItem(val label: String, val hint: String?, val route: Route)

private fun todayLinks(s: JsonElement?, leave: JsonElement?, forms: JsonElement?, signing: JsonElement?, courses: JsonElement?, todos: JsonElement?): List<LinkItem> {
    val shift = s["today_shift"].obj
    val balances = leave["balances"].records
    val annual = balances.firstOrNull { it["type"]["code"].str == "annual" } ?: balances.firstOrNull { !it["available"].isNull }
    val formsRows = forms["data"].records
    val formsDue = formsRows.count { it["template"]["kind"].str == "checklist" && (it["state"].str ?: "") !in listOf("submitted", "late") }
    val pendingSign = signing["pending"].int ?: 0
    val items = mutableListOf(
        LinkItem("My shifts", shift?.let { "today ${it["starts_at"].str ?: ""}–${it["ends_at"].str ?: ""}" } ?: (if (s == null) null else "no shift today"), Route.MyShifts),
        LinkItem("My leave", annual["available"].int?.let { "$it days left" }, Route.MyLeave),
        LinkItem("My hours", s?.let { "${MeFormat.hm(it["week_worked_minutes"].int)} this week" }, Route.MyTimesheet),
        LinkItem("My pay", "payslips", Route.MyPayslips),
        LinkItem("To-dos", todos?.let { t -> val overdue = t["overdue"].int ?: 0; val open = t["open"].int ?: 0; if (overdue > 0) "$overdue overdue" else if (open > 0) "$open open" else "none open" }, Route.Todos),
        LinkItem("Colleagues", "the directory", Route.Colleagues),
        LinkItem("Checklists & forms", if (formsDue > 0) "$formsDue to do" else if (forms == null) null else "all done", Route.Forms),
        LinkItem("Handbook", "policies, how-tos", Route.Handbook()),
        LinkItem("Training", courses?.let { c -> val n = c["to_do"].int ?: 0; if (n > 0) "$n to complete" else "all done" }, Route.Courses()),
    )
    if (pendingSign > 0) items += LinkItem("To sign", "$pendingSign waiting", Route.Sign())
    items += LinkItem("Advances & loans", null, Route.MyAdvances)
    items += LinkItem("My file", "contact, documents, identifiers", Route.Profile)
    return items
}

// ── Team now (OverviewPage.tsx TeamNow, compact) ─────────────────────────────

@Composable
private fun TeamCard(o: JsonElement) {
    val env = LocalEnv.current
    val now = o["now"]
    val requests = o["requests"]["count"].int ?: 0
    SectionCard {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("Your team now", style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
            TextButton(onClick = { env.navigator.go(Route.PeopleOverview) }) { Text("Overview ›") }
        }
        TeamGroup("In now", StatusTone.SUCCESS, now["in"].records) { x ->
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(x["person"]["name"].str ?: "", style = MaterialTheme.typography.bodySmall, maxLines = 1)
                Text("· since ${MeFormat.time(x["since"].date)}${x["outlet"].str?.let { " · $it" } ?: ""}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textTertiary, maxLines = 1)
                if (x["on_break"].bool == true) StatusBadge("break", StatusTone.NEUTRAL)
                val left = x["left_site_at"].date
                if (left != null) StatusBadge("away since ${MeFormat.time(left)}", StatusTone.WARNING)
                else { val n = x["flags"].records.size; if (n > 0) StatusBadge(MeFormat.plural(n, "note"), StatusTone.WARNING) }
            }
        }
        TeamGroup("Late", StatusTone.DANGER, now["late"].records) { x ->
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text("${x["person"]["name"].str ?: ""} · shift ${x["shift"].str ?: ""}", style = MaterialTheme.typography.bodySmall, maxLines = 1, modifier = Modifier.weight(1f))
                Text("${x["minutes_late"].int ?: 0} min", style = MaterialTheme.typography.labelSmall, color = Palette.danger)
            }
        }
        TeamGroup("Did not show", StatusTone.DANGER, now["absent"].records) { x -> Text("${x["person"]["name"].str ?: ""} · shift ${x["shift"].str ?: ""}", style = MaterialTheme.typography.bodySmall, maxLines = 1) }
        TeamGroup("Due later", StatusTone.NEUTRAL, now["expected"].records) { x -> Text("${x["person"]["name"].str ?: ""} · ${x["shift"].str ?: ""}", style = MaterialTheme.typography.bodySmall, maxLines = 1) }
        TeamGroup("On leave", StatusTone.INFO, now["on_leave"].records) { x -> Text("${x["person"]["name"].str ?: ""} · ${x["type"].str ?: "leave"} until ${x["until"].str ?: ""}", style = MaterialTheme.typography.bodySmall, maxLines = 1) }
        val total = (now["in_count"].int ?: 0) + now["late"].records.size + now["absent"].records.size + now["expected"].records.size + now["on_leave"].records.size
        if (total == 0) Text("Nobody is clocked in and nothing is rostered today.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
        if (requests > 0) MeNotice("${MeFormat.plural(requests, "request")} waiting on you ›", StatusTone.WARNING, Modifier.clickable { env.navigator.go(Route.PeopleRequests) })
    }
}

@Composable
private fun TeamGroup(title: String, tone: StatusTone, rows: List<JsonElement>, row: @Composable (JsonElement) -> Unit) {
    if (rows.isEmpty()) return
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text("${title.uppercase()} · ${rows.size}", style = MaterialTheme.typography.labelSmall, color = tone.foreground)
        rows.forEach { x ->
            Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                PersonAvatar(x["person"]["name"].str, x["person"]["avatar_url"].str, size = 24.dp)
                row(x)
            }
        }
    }
}
