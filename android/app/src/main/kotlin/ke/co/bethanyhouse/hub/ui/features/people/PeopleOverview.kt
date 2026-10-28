package ke.co.bethanyhouse.hub.ui.features.people

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.Avatar
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.KeyValueGrid
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Pill
import ke.co.bethanyhouse.hub.ui.components.RemoteJson
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.StatTile
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.TileModifier
import ke.co.bethanyhouse.hub.ui.components.toastResult
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.delay
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull

// ── Overview (GET /people/overview; refreshed every 30 s) ───────────────────

@Composable
fun PeopleOverviewScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val resource = remember { Resource(scope) { env.client.get("/v1/admin/people/overview", background = true).json ?: JsonNull } }
    LaunchedEffect(Unit) {
        resource.load()
        while (true) { delay(30_000); resource.reload() }
    }
    Page("People", subtitle = "Who is in, who is late, what is waiting", refresh = { resource.reload() }) {
        LoadableView(resource.state, retry = { resource.load() }) { o ->
            val now = o["now"]
            val late = now["late"].records.size
            val absent = now["absent"].records.size
            val flagged = o["week"]["flagged_punches"].int ?: 0
            TileGrid {
                StatTile("In now", now["in_count"].str ?: "0", hint = "${now["on_break"].int ?: 0} on break", modifier = TileModifier)
                StatTile("Late", "$late", tone = if (late > 0) StatusTone.WARNING else null, modifier = TileModifier)
                StatTile("Absent", "$absent", tone = if (absent > 0) StatusTone.DANGER else null, modifier = TileModifier)
                StatTile("On leave", "${now["on_leave"].records.size}", modifier = TileModifier)
                StatTile("Open shifts", o["week"]["open_shifts"].str ?: "0", hint = "this week", modifier = TileModifier)
                StatTile("Flagged punches", o["week"]["flagged_punches"].str ?: "0", hint = "this week", tone = if (flagged > 0) StatusTone.WARNING else null, modifier = TileModifier)
            }
            FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                OutlinedButton(onClick = { env.navigator.go(Route.PeopleRequests) }) { Text("Requests (${o["requests"]["count"].int ?: 0})") }
                OutlinedButton(onClick = { env.navigator.go(Route.Roster) }) { Text("Roster") }
                OutlinedButton(onClick = { env.navigator.go(Route.Attendance) }) { Text("Attendance") }
                if (env.session.current.can("attendance.view_team")) OutlinedButton(onClick = { env.navigator.go(Route.Timesheets) }) { Text("Timesheets") }
            }
            PeopleSection("Clocked in", now["in"].toRecords(), empty = "Nobody is clocked in right now") { r ->
                Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text("since ${Day.time(r.string("since"))}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                        if (r["on_break"].bool == true) Pill("on break", StatusTone.PINK)
                        if (r.string("left_site_at") != null) Pill("left site", StatusTone.WARNING)
                        r["flags"].records.mapNotNull { it.str }.forEach { Pill(it, StatusTone.WARNING) }
                    }
                }
            }
            PeopleSection("Late", now["late"].toRecords(), empty = null) { r ->
                Text("${r.int("minutes_late") ?: 0} min late · ${r.string("shift") ?: ""}", style = MaterialTheme.typography.labelSmall, color = Palette.warning)
            }
            PeopleSection("Absent", now["absent"].toRecords(), empty = null) { r ->
                Text(r.string("shift") ?: "", style = MaterialTheme.typography.labelSmall, color = Palette.danger)
            }
            PeopleSection("Expected later", now["expected"].toRecords(), empty = null) { r ->
                Text(r.string("shift") ?: "", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            }
            PeopleSection("On leave", now["on_leave"].toRecords(), empty = null) { r ->
                Text("${r.string("type") ?: "Leave"} · until ${Day.format(r.string("until"))}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            }
            val p = o["period"]
            if (!p.isNull) SectionCard("Pay period") {
                KeyValueGrid(listOf(
                    "Period" to "${Day.format(p["starts_on"].str)} – ${Day.format(p["ends_on"].str)}",
                    "Status" to (p["status"].str?.humanized ?: "—"),
                    "Days left" to (p["days_left"].str ?: "—"),
                    "Timesheets" to "${p["approved"].int ?: 0} approved · ${p["submitted"].int ?: 0} submitted · ${p["people"].int ?: 0} people",
                ))
            }
            val rules = o["rules"]
            if (!rules.isNull) Text(
                "Location: ${rules["location_mode"].str?.humanized ?: "—"} · Clock-in without a shift: ${rules["clock_in_without_shift"].str?.humanized ?: "—"}",
                style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary,
            )
        }
    }
}

@Composable
private fun PeopleSection(title: String, rows: List<Record>, empty: String?, trailing: @Composable (Record) -> Unit) {
    if (rows.isEmpty() && empty == null) return
    SectionCard("$title (${rows.size})") {
        if (rows.isEmpty() && empty != null) Text(empty, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textTertiary)
        rows.forEach { r ->
            Row(Modifier.fillMaxWidth().padding(vertical = 2.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.m)) {
                Avatar(r["person"]["name"].str ?: "?")
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(r["person"]["name"].str ?: "—", style = MaterialTheme.typography.bodyMedium)
                    r.string("outlet")?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                }
                trailing(r)
            }
        }
    }
}

// ── Requests (GET /people/requests) ─────────────────────────────────────────

private data class FixDecision(val id: Int, val decision: String, val summary: String)

private fun kindLabel(k: String) = when (k) { "leave" -> "Leave"; "swap" -> "Shift swaps"; "advance" -> "Advances"; "fix" -> "Timesheet fixes"; else -> k.humanized }

@Composable
fun PeopleRequestsScreen() {
    val env = LocalEnv.current
    var deciding by remember { mutableStateOf<FixDecision?>(null) }
    var version by remember { mutableIntStateOf(0) }
    Page("Requests", subtitle = "Leave, swaps, advances and timesheet fixes waiting on a manager") {
        RemoteJson("/v1/admin/people/requests", version = version, emptyTitle = "Nothing is waiting", isEmpty = { it["data"].records.isEmpty() }) { json, _ ->
            val items = json["data"].toRecords()
            val counts = json["counts"]
            FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                listOf("leave", "swap", "advance", "fix").forEach { k -> Pill("${kindLabel(k)}: ${counts[k].int ?: 0}", StatusTone.INFO) }
            }
            listOf("fix", "leave", "swap", "advance").forEach { kind ->
                val rows = items.filter { it.string("kind") == kind }
                if (rows.isNotEmpty()) SectionCard(kindLabel(kind)) {
                    rows.forEach { r -> RequestRow(r) { deciding = it } }
                }
            }
        }
    }
    deciding?.let { d -> FixDecisionSheet(d, onDismiss = { deciding = null }) { deciding = null; version += 1 } }
}

@Composable
private fun RequestRow(r: Record, decide: (FixDecision) -> Unit) {
    val env = LocalEnv.current
    Column(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Avatar(r.string("person") ?: "?", 28.dp)
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(r.string("person") ?: "—", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                Text(r.string("summary") ?: "", style = MaterialTheme.typography.bodySmall)
                r.string("note")?.takeIf { it.isNotEmpty() }?.let { Text("“$it”", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
            }
            DateText(r.date("asked_at"), DateStyle.RELATIVE)
        }
        FlowRow(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s, Alignment.End)) {
            if (r.string("kind") == "fix") {
                if (env.session.current.can("attendance.manage")) {
                    OutlinedButton(onClick = { decide(FixDecision(r.int("id") ?: 0, "decline", r.string("summary") ?: "")) }) { Text("Decline") }
                    Button(onClick = { decide(FixDecision(r.int("id") ?: 0, "approve", r.string("summary") ?: "")) }) { Text("Mark corrected") }
                }
                TextButton(onClick = { env.navigator.go(Route.Attendance) }) { Text("Open attendance") }
            } else {
                Button(onClick = {
                    when (r.string("kind")) {
                        "leave" -> env.navigator.go(Route.Leave)
                        "swap" -> env.navigator.go(Route.Roster)
                        "advance" -> env.navigator.go(Route.Payroll)
                        else -> r.string("href")?.let { Route.fromConsolePath(it.removePrefix("/admin")) }?.let { env.navigator.go(it) }
                    }
                }) { Text("Open") }
            }
        }
    }
}

/** POST /people/requests/fixes/{id}/{approve|decline} {note} */
@Composable
private fun FixDecisionSheet(d: FixDecision, onDismiss: () -> Unit, done: () -> Unit) {
    val env = LocalEnv.current
    var note by remember { mutableStateOf("") }
    val approve = d.decision == "approve"
    FormSheet(if (approve) "Mark corrected" else "Decline fix request", onDismiss, saveLabel = if (approve) "Mark corrected" else "Decline", destructive = !approve, save = {
        val r = env.client.post("/v1/admin/people/requests/fixes/${d.id}/${d.decision}", J.obj("note" to J.sOpt(note)))
        toastResult(env, r)
        done()
    }) { e ->
        Text(d.summary, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
        Text(if (approve) "Correct the hours on the Attendance screen first; this tells the person it is done." else "The person is told their request was declined.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
        Field("Note to the person", note, { note = it }, error = e?.field("note"), multiline = true)
    }
}
