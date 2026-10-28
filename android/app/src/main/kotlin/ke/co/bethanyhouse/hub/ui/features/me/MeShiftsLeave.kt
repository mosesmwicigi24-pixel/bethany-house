package ke.co.bethanyhouse.hub.ui.features.me

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Schedule
import androidx.compose.material3.Button
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.HubDates
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.dbl
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.DateField
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.TileModifier
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import java.time.LocalDate

// ── My shifts (ShiftsPage.tsx): the next two weeks, open shifts to claim, swap requests ──

@Composable
internal fun MyShiftsScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val mine = remember { Resource(scope) { env.client.get("/v1/admin/me/shifts").json ?: JsonNull } }
    var swapping by remember { mutableStateOf<Record?>(null) }
    var note by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { mine.load() }

    suspend fun claim(s: Record) {
        busy = true
        try { val r = env.client.post("/v1/admin/me/shifts/${s.id}/claim"); env.toasts.show(r.json["message"].str ?: "Shift taken"); mine.reload() } catch (e: ApiError) { env.toasts.error(e) } finally { busy = false }
    }
    suspend fun requestSwap(s: Record) {
        val n = note.trim()
        val r = env.client.post("/v1/admin/me/shifts/${s.id}/swap", jsonOf("note" to n.ifEmpty { ke.co.bethanyhouse.hub.core.Omit }))
        env.toasts.show(r.json["message"].str ?: "Sent to the manager")
        swapping = null; note = ""
        mine.reload()
    }
    suspend fun withdraw(r: Record) {
        busy = true
        try { val res = env.client.delete("/v1/admin/me/swaps/${r.id}"); env.toasts.show(res.json["message"].str ?: "Withdrawn"); mine.reload() } catch (e: ApiError) { env.toasts.error(e) } finally { busy = false }
    }

    Page("My shifts", subtitle = "Next two weeks", refresh = { mine.reload() }) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) {
            OutlinedButton(onClick = { env.navigator.go(Route.Clock) }) { Icon(Icons.Default.Schedule, contentDescription = null, modifier = Modifier.width(16.dp)); Text(" Clock") }
        }
        LoadableView(mine.state, retry = { mine.load() }) { data ->
            val shifts = data["shifts"].toRecords()
            val open = data["open_shifts"].toRecords()
            val pending = data["pending_swaps"].toRecords()
            val askedIds = pending.mapNotNull { it["shift"]["id"].int }.toSet()
            if (shifts.isEmpty()) SectionCard { Text("No shifts published for you yet.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary, modifier = Modifier.fillMaxWidth()) }
            else MeListCard {
                shifts.forEach { s ->
                    ShiftRow(s) {
                        if (askedIds.contains(s.intId ?: -1)) StatusBadge("swap asked", StatusTone.WARNING)
                        else TextButton(onClick = { note = ""; swapping = s }) { Text("Swap", style = MaterialTheme.typography.labelSmall) }
                    }
                }
            }
            if (pending.isNotEmpty()) {
                MeSectionLabel("Waiting on the manager")
                MeListCard {
                    pending.forEach { r ->
                        MeListRow {
                            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                val sh = r["shift"]
                                Text("${if (!sh.isNull) "${MeFormat.day(MeFormat.date(sh["date"].str))} ${sh["starts_at"].str ?: ""}–${sh["ends_at"].str ?: ""}" else "Shift"} → ${r["to_user"]["name"].str ?: "anyone"}", style = MaterialTheme.typography.bodySmall)
                                r.string("note")?.let { Text("“$it”", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                            }
                            TextButton(onClick = { scope.launch { withdraw(r) } }, enabled = !busy) { Text("Withdraw", style = MaterialTheme.typography.labelSmall) }
                        }
                    }
                }
            }
            MeSectionLabel("Open shifts you can take")
            if (open.isEmpty()) Text("None right now.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
            else MeListCard {
                open.forEach { s -> ShiftRow(s) { Button(onClick = { scope.launch { claim(s) } }, enabled = !busy) { Text("Take it") } } }
            }
        }
    }
    swapping?.let { s ->
        MeSheet("Ask to swap", primary = "Send to manager", onDismiss = { swapping = null }, action = { requestSwap(s) }) {
            Text("${MeFormat.longDay(MeFormat.date(s.string("date")))} ${s.string("starts_at") ?: ""}–${s.string("ends_at") ?: ""} at ${s["outlet"]["name"].str ?: ""}. The manager decides who takes it; the shift stays yours until then.", style = MaterialTheme.typography.bodyMedium)
            Field("Why? (optional)", note, { note = it }, placeholder = "Clinic that morning")
        }
    }
}

@Composable
private fun ShiftRow(shift: Record, right: @Composable () -> Unit) {
    val date = MeFormat.date(shift.string("date"))
    val isToday = shift.string("date") == HubDates.dayString(HubDates.today())
    MeListRow {
        Column(
            Modifier.width(48.dp).background(if (isToday) Palette.brand else Palette.neutralSoft, MaterialTheme.shapes.small).padding(vertical = 4.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(MeFormat.weekday(date).uppercase(), fontSize = 9.sp, fontWeight = FontWeight.SemiBold, color = if (isToday) Color.White else Palette.neutral)
            Text(MeFormat.dayNumber(date), fontSize = 18.sp, fontWeight = FontWeight.Bold, color = if (isToday) Color.White else Palette.neutral)
        }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Row(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalAlignment = Alignment.CenterVertically) {
                Text("${shift.string("starts_at") ?: ""} – ${shift.string("ends_at") ?: ""}${if (shift.flag("overnight")) " (next day)" else ""}", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                Text("· ${MeFormat.hm(shift.int("minutes"))}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textTertiary)
            }
            Text(listOfNotNull(shift["outlet"]["name"].str, shift.string("label"), shift.string("notes")).joinToString(" · "), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        right()
    }
}

// ── My leave (LeavePage.tsx): balances, a request with a live preview, what happened to it ──

@Composable
internal fun MyLeaveScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val mine = remember { Resource(scope) { env.client.get("/v1/admin/me/leave").json ?: JsonNull } }
    var asking by remember { mutableStateOf(false) }
    var typeId by remember { mutableIntStateOf(0) }
    var startsOn by remember { mutableStateOf(HubDates.today()) }
    var endsOn by remember { mutableStateOf(HubDates.today()) }
    var reason by remember { mutableStateOf("") }
    var preview by remember { mutableStateOf<JsonElement?>(null) }
    LaunchedEffect(Unit) { mine.load() }
    val types = mine.value["types"].records

    suspend fun loadPreview() {
        if (typeId <= 0 || endsOn < startsOn) { preview = null; return }
        preview = try {
            env.client.get("/v1/admin/me/leave/preview", mapOf("leave_type_id" to typeId.toString(), "starts_on" to HubDates.dayString(startsOn), "ends_on" to HubDates.dayString(endsOn))).json
        } catch (e: ApiError) { env.toasts.error(e); null }
    }
    fun openForm() {
        if (typeId == 0) typeId = types.firstOrNull()["id"].int ?: 0
        startsOn = HubDates.today(); endsOn = HubDates.today(); reason = ""; preview = null
        asking = true
    }
    suspend fun submit() {
        val r = env.client.post("/v1/admin/me/leave", jsonOf(
            "leave_type_id" to typeId, "starts_on" to HubDates.dayString(startsOn), "ends_on" to HubDates.dayString(endsOn),
            "reason" to reason.trim().ifEmpty { ke.co.bethanyhouse.hub.core.Omit },
        ))
        env.toasts.show(r.json["message"].str ?: "Request sent")
        asking = false
        mine.reload()
    }
    suspend fun cancel(r: Record) {
        try { val res = env.client.delete("/v1/admin/me/leave/${r.id}"); env.toasts.show(res.json["message"].str ?: "Cancelled"); mine.reload() } catch (e: ApiError) { env.toasts.error(e) }
    }

    Page("My leave", subtitle = mine.value["year"].str, refresh = { mine.reload() }) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) { Button(onClick = { openForm() }) { Text("Request leave") } }
        LoadableView(mine.state, retry = { mine.load() }) { data ->
            val balances = data["balances"].records.filter { !it["available"].isNull }
            TileGrid(minWidth = 150.dp) {
                balances.forEach { b ->
                    Surface(shape = MaterialTheme.shapes.large, color = MaterialTheme.hub.card, modifier = TileModifier.border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.large)) {
                        Column(Modifier.padding(Space.m), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Text(b["type"]["name"].str?.uppercase() ?: "", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            Row(horizontalArrangement = Arrangement.spacedBy(2.dp), verticalAlignment = Alignment.Bottom) {
                                Text(b["available"].str ?: "—", style = MaterialTheme.typography.headlineSmall)
                                Text("/ ${entitlement(b)}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
                            }
                            Text("${b["used"].str ?: "0"} used${if ((b["requested"].dbl ?: 0.0) > 0) " · ${b["requested"].str ?: ""} asked" else ""}${if (b["type"]["accrual"].str == "monthly") " · earns monthly" else ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        }
                    }
                }
            }
            val approvers = data["approvers"].records.mapNotNull { it.str }
            Text("${if (approvers.isEmpty()) "Nobody is set to approve your leave yet; ask the owner to set who you report to." else "Decided by ${approvers.joinToString(" or ")}."} Days are working days; public holidays do not count.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
            MeSectionLabel("Requests")
            val requests = data["requests"].toRecords()
            if (requests.isEmpty()) Text("None yet.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
            else MeListCard { requests.forEach { r -> LeaveRow(r) { cancel(r) } } }
            TextButton(onClick = { env.navigator.go(Route.Clock) }, modifier = Modifier.fillMaxWidth()) { Text("Back to the clock", style = MaterialTheme.typography.labelSmall) }
        }
    }

    if (asking) {
        LaunchedEffect(typeId, startsOn, endsOn) { if (endsOn < startsOn) endsOn = startsOn else loadPreview() }
        val ok = typeId > 0 && !endsOn.isBefore(startsOn)
        val p = preview
        val enough = (p["enough"].bool ?: false) && (p["days"].dbl ?: 0.0) > 0
        MeSheet("Request leave", primary = "Send", onDismiss = { asking = false }, disabled = !ok || !enough, action = { submit() }) {
            SelectField("Type", typeId, types.mapNotNull { t -> t["id"].int?.let { it to "${t["name"].str ?: ""}${if (t["is_paid"].bool == false) " (unpaid)" else ""}" } }, { typeId = it ?: 0 }, Modifier.fillMaxWidth(), placeholder = "Choose…")
            Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                DateField("From", startsOn, { it?.let { d -> startsOn = d } }, Modifier.weight(1f), allowClear = false)
                DateField("To", endsOn, { it?.let { d -> endsOn = if (d.isBefore(startsOn)) startsOn else d } }, Modifier.weight(1f), allowClear = false)
            }
            Field("Reason (optional)", reason, { reason = it })
            if (p != null) {
                val days = p["days"].dbl ?: 0.0
                if (days <= 0) MeNotice("Those dates contain no working days.", StatusTone.DANGER)
                else {
                    val skipped = p["holidays_skipped"].records.mapNotNull { it.str }
                    val available = p["available"].str
                    MeNotice("${MeFormat.plural(days.toInt(), "day")}${if (skipped.isEmpty()) "" else " (skipping ${skipped.joinToString(", ")})"}${available?.let { " · $it available${if (p["enough"].bool == false) " — not enough" else ""}" } ?: ""}", if (enough) StatusTone.SUCCESS else StatusTone.DANGER)
                }
            }
        }
    }
}

/** "entitled + adjusted" as LeavePage prints it — leave days, not money. */
private fun entitlement(b: JsonElement): String {
    val total = (b["entitled"].dbl ?: 0.0) + (b["adjusted"].dbl ?: 0.0)
    return if (total == Math.rint(total)) total.toInt().toString() else total.toString()
}

private fun span(r: Record): String {
    val a = MeFormat.date(r.string("starts_on")); val b = MeFormat.date(r.string("ends_on"))
    return if (r.string("starts_on") == r.string("ends_on")) MeFormat.day(a) else "${MeFormat.day(a)} – ${MeFormat.day(b)}"
}

@Composable
private fun LeaveRow(r: Record, cancel: suspend () -> Unit) {
    val today = HubDates.dayString(HubDates.today())
    val cancellable = r.status == "pending" || (r.status == "approved" && (r.string("starts_on") ?: "") > today)
    MeListRow {
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Row(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(span(r), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                Text("· ${MeFormat.plural(r.int("days") ?: 0, "day")}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textTertiary)
            }
            Text(listOfNotNull(r["type"]["name"].str, r.string("reason")).joinToString(" · "), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, maxLines = 2, overflow = TextOverflow.Ellipsis)
            r.string("decision_note")?.let { n -> Text("“$n”${r.string("decided_by")?.let { " — $it" } ?: ""}", style = MaterialTheme.typography.labelSmall, fontStyle = FontStyle.Italic, color = MaterialTheme.hub.textSecondary) }
        }
        Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(4.dp)) {
            StatusBadge(r.status)
            if (cancellable) ConfirmButton("Cancel this request", "Your request for ${span(r)} will be cancelled.", destructive = true, action = cancel) { Text("Cancel", style = MaterialTheme.typography.labelSmall) }
        }
    }
}
