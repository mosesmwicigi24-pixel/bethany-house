package ke.co.bethanyhouse.hub.ui.features.people

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Flag
import androidx.compose.material.icons.filled.Schedule
import androidx.compose.material3.Button
import androidx.compose.material3.Icon
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
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.google.android.gms.maps.model.CameraPosition
import com.google.android.gms.maps.model.LatLng
import com.google.maps.android.compose.Circle
import com.google.maps.android.compose.GoogleMap
import com.google.maps.android.compose.Marker
import com.google.maps.android.compose.MarkerState
import com.google.maps.android.compose.Polyline
import com.google.maps.android.compose.rememberCameraPositionState
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.dbl
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.Avatar
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.DayField
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.ErrorState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FieldError
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.InfoSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.KeyValueGrid
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.LoadingSkeleton
import ke.co.bethanyhouse.hub.ui.components.OutletChoice
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.PagedList
import ke.co.bethanyhouse.hub.ui.components.Pill
import ke.co.bethanyhouse.hub.ui.components.RemoteJson
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.StaffPicker
import ke.co.bethanyhouse.hub.ui.components.StatTile
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.SupportListRow
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.TileModifier
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.minutesText
import ke.co.bethanyhouse.hub.ui.components.toastResult
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull

/** Attendance (/settings/attendance): live board, flagged entries, the entries list, an entry's detail and the kiosks. */
@Composable
fun AttendanceScreen() {
    val env = LocalEnv.current
    var tab by remember { mutableStateOf("live") }
    var version by remember { mutableIntStateOf(0) }
    var openEntry by remember { mutableStateOf<Int?>(null) }
    val tabs = listOf("live" to "In now", "flagged" to "Flagged", "entries" to "Entries") + (if (env.session.current.can("attendance.manage")) listOf("kiosks" to "Kiosks") else emptyList())
    Page("Attendance", subtitle = "Time clock oversight") {
        SegmentedTabs(tabs, tab) { tab = it }
        when (tab) {
            "live" -> RemoteJson("/v1/admin/attendance/live", version = version, emptyTitle = "Nobody is clocked in", isEmpty = { it["data"].records.isEmpty() }) { j, _ ->
                TileGrid {
                    StatTile("Clocked in", j["count"].str ?: "0", modifier = TileModifier)
                    StatTile("On break", j["on_break"].str ?: "0", modifier = TileModifier)
                    StatTile("Flagged", j["flagged"].str ?: "0", tone = if ((j["flagged"].int ?: 0) > 0) StatusTone.WARNING else null, modifier = TileModifier)
                }
                j["data"].toRecords().forEach { e -> AttendanceEntryRow(e) { openEntry = e.int("id") } }
            }
            "flagged" -> RemoteJson("/v1/admin/attendance/flagged", version = version, emptyTitle = "No flagged entries", isEmpty = { it["data"].records.isEmpty() }) { j, _ ->
                j["data"].toRecords().forEach { e -> AttendanceEntryRow(e) { openEntry = e.int("id") } }
            }
            "entries" -> AttendanceEntriesList(version) { openEntry = it }
            "kiosks" -> KiosksPanel()
        }
    }
    openEntry?.let { id -> AttendanceEntrySheet(id, onDismiss = { openEntry = null }) { version += 1 } }
}

@Composable
private fun AttendanceEntryRow(entry: Record, onClick: () -> Unit) {
    val subtitle = "${entry["outlet"]["name"].str ?: "No outlet"} · in ${Day.dateTime(entry.string("clock_in_at"))}" + (entry.string("clock_out_at")?.let { " · out ${Day.time(it)}" } ?: "")
    SupportListRow(entry["user"]["name"].str ?: "—", subtitle, onClick = onClick) {
        Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(4.dp)) {
            FlowRow(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                StatusBadge(entry.status)
                if (entry["on_break"].bool == true) Pill("break", StatusTone.PINK)
                Pill(entry.string("clock_in_method") ?: "gps", StatusTone.NEUTRAL)
            }
            val flags = entry["flag_labels"].records.mapNotNull { it.str }
            if (flags.isNotEmpty()) Text(flags.joinToString(" · "), style = MaterialTheme.typography.labelSmall, color = Palette.warning, maxLines = 2)
            Text(minutesText(entry.int("worked_minutes") ?: entry.int("elapsed_minutes")), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
        }
    }
}

/** GET /attendance/entries?outlet_id&user_id&status&from&to (paginated) */
@Composable
private fun AttendanceEntriesList(version: Int, open: (Int) -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var outlet by remember { mutableStateOf<Int?>(null) }
    var user by remember { mutableStateOf<Int?>(null) }
    var status by remember { mutableStateOf("") }
    var from by remember { mutableStateOf(Day.add(Day.today(), -7)) }
    var to by remember { mutableStateOf(Day.today()) }
    val list = remember { PagedList(scope, env.client, "/v1/admin/attendance/entries", map = ::Record) }
    suspend fun reload() {
        list.query = mutableMapOf("outlet_id" to outlet?.toString(), "user_id" to user?.toString(), "status" to status.ifEmpty { null }, "from" to from, "to" to to)
        list.reload()
    }
    LaunchedEffect(version) { reload() }
    Column(verticalArrangement = Arrangement.spacedBy(Space.m)) {
        OutletChoice("Outlet", outlet, { outlet = it }, allowAll = true)
        StaffPicker("Person", user, { user = it }, allowNone = true)
        PeopleChoice("Status", status, listOf("" to "Any", "active" to "Active", "completed" to "Completed", "flagged" to "Flagged"), { status = it })
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
            DayField("From", from, { from = it }, Modifier.weight(1f))
            DayField("To", to, { to = it }, Modifier.weight(1f))
        }
        Button(onClick = { scope.launch { reload() } }, modifier = Modifier.fillMaxWidth()) { Text("Apply") }
        LoadableView(list.state, retry = { list.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("No entries in this range", icon = Icons.Default.Schedule) }) { rows ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                rows.forEachIndexed { i, e ->
                    AttendanceEntryRow(e) { e.int("id")?.let(open) }
                    if (i == rows.lastIndex && list.hasMore) LaunchedEffect(rows.size) { list.loadMore() }
                }
                Text("${list.total} entries", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
            }
        }
    }
}

// ── Entry detail (GET /attendance/entries/{id}, /photo/{in|out}, /trail; PUT entries/{id}) ──

@Composable
private fun AttendanceEntrySheet(entryId: Int, onDismiss: () -> Unit, changed: () -> Unit) {
    val env = LocalEnv.current
    var entry by remember { mutableStateOf<Record?>(null) }
    var error by remember { mutableStateOf<ApiError?>(null) }
    var editing by remember { mutableStateOf(false) }
    var showTrail by remember { mutableStateOf(false) }
    var version by remember { mutableIntStateOf(0) }
    LaunchedEffect(version) {
        try { entry = env.client.get("/v1/admin/attendance/entries/$entryId").json["entry"]?.let(::Record); error = null }
        catch (e: ApiError) { error = e } catch (e: Exception) { error = ApiError.transport(e.message ?: "Error") }
    }
    InfoSheet("Entry #$entryId", onDismiss) {
        val e = entry
        when {
            e != null -> {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    Avatar(e["user"]["name"].str ?: "?")
                    Text(e["user"]["name"].str ?: "—", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                    StatusBadge(e.status)
                }
                KeyValueGrid(listOf(
                    "Outlet" to (e["outlet"]["name"].str ?: "—"),
                    "Clock in" to Day.dateTime(e.string("clock_in_at")),
                    "Clock out" to (e.string("clock_out_at")?.let { Day.dateTime(it) } ?: "—"),
                    "Method" to (e.string("clock_in_method")?.humanized ?: "—"),
                    "Worked" to minutesText(e.int("worked_minutes")),
                    "Breaks" to minutesText(e.int("total_break_minutes")),
                    "Distance at clock-in" to (e.int("clock_in_distance_meters")?.let { "$it m" } ?: "—"),
                    "Accuracy" to (e.int("clock_in_accuracy_meters")?.let { "±$it m" } ?: "—"),
                    "Device" to (e.string("device_info") ?: "—"),
                    "Left site" to (e.string("left_site_at")?.let { Day.dateTime(it) } ?: "—"),
                    "Trail points" to (e.int("trail_points")?.toString() ?: "0"),
                    "Overridden by" to (e.string("overridden_by") ?: "—"),
                    "Corrected by" to (e.string("corrected_by") ?: "—"),
                ))
                val flags = e["flag_labels"].records.mapNotNull { it.str }
                if (flags.isNotEmpty()) SectionCard("Why a manager should look") {
                    flags.forEach { Row(verticalAlignment = Alignment.CenterVertically) { Icon(Icons.Default.Flag, contentDescription = null, tint = Palette.warning, modifier = Modifier.padding(end = 6.dp)); Text(it, style = MaterialTheme.typography.bodySmall, color = Palette.warning) } }
                }
                e.string("flagged_reason")?.let { Text(it, style = MaterialTheme.typography.bodySmall) }
                e.string("notes")?.takeIf { it.isNotEmpty() }?.let { SectionCard("Notes") { Text(it, style = MaterialTheme.typography.bodySmall) } }
                val breaks = e["breaks"].records
                if (breaks.isNotEmpty()) SectionCard("Breaks") {
                    breaks.forEach { b -> Text("${Day.time(b["started_at"].str)} – ${b["ended_at"].str?.let { Day.time(it) } ?: "open"}", style = MaterialTheme.typography.bodySmall) }
                }
                if (e["photos"]["in"].bool == true || e["photos"]["out"].bool == true) SectionCard("Kiosk photos") {
                    Row(horizontalArrangement = Arrangement.spacedBy(Space.m)) {
                        if (e["photos"]["in"].bool == true) Column(Modifier.weight(1f)) { Text("In", style = MaterialTheme.typography.labelSmall); PeopleSignedImage("/api/v1/admin/attendance/entries/$entryId/photo/in", 180.dp) }
                        if (e["photos"]["out"].bool == true) Column(Modifier.weight(1f)) { Text("Out", style = MaterialTheme.typography.labelSmall); PeopleSignedImage("/api/v1/admin/attendance/entries/$entryId/photo/out", 180.dp) }
                    }
                }
                if (showTrail) TrailMap(entryId) else OutlinedButton(onClick = { showTrail = true }) { Text("Show location trail") }
                if (env.session.current.can("attendance.manage")) Button(onClick = { editing = true }, modifier = Modifier.fillMaxWidth()) { Text("Correct entry…") }
            }
            error != null -> ErrorState(error!!, retry = { version += 1 })
            else -> LoadingSkeleton()
        }
    }
    if (editing) entry?.let { e -> AttendanceEntryEditSheet(e, onDismiss = { editing = false }) { version += 1; changed() } }
}

/** PUT /attendance/entries/{id} {clock_in_at, clock_out_at, status, notes} */
@Composable
private fun AttendanceEntryEditSheet(entry: Record, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    val inIso = entry.string("clock_in_at")
    var inDay by remember { mutableStateOf(inIso?.let { Day.format(it, "yyyy-MM-dd") }?.takeIf { it != "—" } ?: Day.today()) }
    var inTime by remember { mutableStateOf(inIso?.let { Day.time(it) }?.takeIf { it != "—" } ?: "08:00") }
    var hasOut by remember { mutableStateOf(entry.string("clock_out_at") != null) }
    val outIso = entry.string("clock_out_at")
    var outDay by remember { mutableStateOf(outIso?.let { Day.format(it, "yyyy-MM-dd") }?.takeIf { it != "—" } ?: Day.today()) }
    var outTime by remember { mutableStateOf(outIso?.let { Day.time(it) }?.takeIf { it != "—" } ?: "17:00") }
    var status by remember { mutableStateOf(entry.status ?: "completed") }
    var notes by remember { mutableStateOf(entry.string("notes") ?: "") }
    val inValue = peopleIsoDateTime(inDay, inTime)
    val outValue = peopleIsoDateTime(outDay, outTime)
    FormSheet("Correct entry #${entry.id}", onDismiss, canSave = inValue != null && (!hasOut || outValue != null), save = {
        val r = env.client.put("/v1/admin/attendance/entries/${entry.id}", J.obj(
            "clock_in_at" to J.s(inValue),
            "clock_out_at" to (if (hasOut) J.s(outValue) else JsonNull),
            "status" to J.s(status),
            "notes" to J.s(notes),
        ))
        toastResult(env, r, "Time entry updated")
        saved()
    }) { e ->
        Text("Times are Nairobi wall-clock.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
            DayField("Clock in", inDay, { inDay = it }, Modifier.weight(1f))
            Field("Time (HH:mm)", inTime, { inTime = it }, Modifier.weight(1f), error = e?.field("clock_in_at"))
        }
        ToggleRow("Clocked out", hasOut, { hasOut = it })
        if (hasOut) Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
            DayField("Clock out", outDay, { outDay = it }, Modifier.weight(1f))
            Field("Time (HH:mm)", outTime, { outTime = it }, Modifier.weight(1f))
        }
        FieldError(e?.field("clock_out_at"))
        PeopleChoice("Status", status, listOf("active" to "Active", "completed" to "Completed", "flagged" to "Flagged"), { status = it })
        Field("Notes", notes, { notes = it }, error = e?.field("notes"), multiline = true)
    }
}

// ── Trail map (GET /attendance/entries/{id}/trail) ───────────────────────────

private fun latLng(v: JsonElement?): LatLng? {
    val la = v["latitude"].dbl ?: return null
    val lo = v["longitude"].dbl ?: return null
    return LatLng(la, lo)
}

@Composable
private fun TrailMap(entryId: Int) {
    val env = LocalEnv.current
    var trail by remember { mutableStateOf<JsonElement?>(null) }
    var error by remember { mutableStateOf<ApiError?>(null) }
    var version by remember { mutableIntStateOf(0) }
    LaunchedEffect(version) {
        try { trail = env.client.get("/v1/admin/attendance/entries/$entryId/trail").json ?: JsonNull; error = null }
        catch (e: ApiError) { error = e } catch (e: Exception) { error = ApiError.transport(e.message ?: "Error") }
    }
    SectionCard("Location trail") {
        val t = trail
        when {
            t != null -> {
                val points = t["points"].records.mapNotNull { latLng(it) }
                val inPt = latLng(t["clock_in"]); val outPt = latLng(t["clock_out"]); val site = latLng(t["outlet"])
                val all = points + listOfNotNull(inPt, outPt, site)
                if (all.isEmpty()) Text("No location was recorded for this entry.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                else {
                    val center = LatLng(all.map { it.latitude }.average(), all.map { it.longitude }.average())
                    val camera = rememberCameraPositionState { position = CameraPosition.fromLatLngZoom(center, 15f) }
                    GoogleMap(modifier = Modifier.fillMaxWidth().height(300.dp).clip(MaterialTheme.shapes.small), cameraPositionState = camera) {
                        val radius = t["outlet"]["radius"].dbl
                        if (site != null && radius != null) {
                            Circle(center = site, radius = radius, fillColor = Palette.brand.copy(alpha = 0.15f), strokeColor = Palette.brand, strokeWidth = 2f)
                            Marker(state = MarkerState(site), title = t["outlet"]["name"].str ?: "Outlet")
                        }
                        if (points.size > 1) Polyline(points = points, color = Palette.info, width = 6f)
                        t["points"].records.forEach { p -> if (p["mocked"].bool == true) latLng(p)?.let { Marker(state = MarkerState(it), title = "Mock location") } }
                        inPt?.let { Marker(state = MarkerState(it), title = "In ${Day.time(t["clock_in"]["at"].str)}") }
                        outPt?.let { Marker(state = MarkerState(it), title = "Out ${Day.time(t["clock_out"]["at"].str)}") }
                    }
                    Text("${points.size} points · kept ${t["retention_days"].int ?: 0} days" + (t["left_site_at"].str?.let { " · left site ${Day.time(it)}" } ?: ""), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
                    Text("Map tiles need a Google Maps key (the mapsApiKey build property); without one the map shows blank tiles, the pins and trail still draw.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
                }
            }
            error != null -> ErrorState(error!!, retry = { version += 1 })
            else -> LoadingSkeleton(2)
        }
    }
}

// ── Kiosks (GET/POST/DELETE /attendance/kiosks; POST needs step-up) ─────────

@Composable
private fun KiosksPanel() {
    val env = LocalEnv.current
    var adding by remember { mutableStateOf(false) }
    Column(verticalArrangement = Arrangement.spacedBy(Space.m)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("Clock-in kiosks", style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
            Button(onClick = { adding = true }) { Text("Make this tablet a kiosk") }
        }
        Text("A kiosk is a tablet at the door: staff tap their name and type their terminal PIN to clock in or out. It reaches only the clock-in board, and lasts until it is removed here or reaches its time limit.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
        RemoteJson("/v1/admin/attendance/kiosks", emptyTitle = "No kiosks set up", isEmpty = { it["data"].records.isEmpty() }) { j, reload ->
            j["data"].toRecords().forEach { k ->
                val expires = k.string("expires_at")?.let { " · works until ${Day.format(it, "d MMM yyyy")}" } ?: ""
                SupportListRow(k.string("name") ?: "Kiosk", "${k.string("outlet") ?: "—"} · set up by ${k.string("set_up_by") ?: "—"} · last used ${k.string("last_used_at")?.let { Day.dateTime(it) } ?: "never"}$expires") {
                    ConfirmButton("Remove kiosk", "The tablet goes back to the sign-in screen next time it checks in.", destructive = true, action = {
                        try { val r = env.client.delete("/v1/admin/attendance/kiosks/${k.id}"); toastResult(env, r); reload() } catch (e: Exception) { env.toasts.error(e) }
                    }) { Icon(Icons.Default.Delete, contentDescription = "Remove") }
                }
            }
        }
    }
    if (adding) KioskSetupSheet(onDismiss = { adding = false })
}

/**
 * Makes THIS device the kiosk, as the console does: the Hub mints the
 * kiosk-only token (step-up), the app keeps it under its own key, the
 * manager is signed out of this device and the clock-in board opens. The
 * token is never shown or shared.
 */
@Composable
private fun KioskSetupSheet(onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var outlet by remember { mutableStateOf<Int?>(null) }
    var name by remember { mutableStateOf("") }
    FormSheet("Make this tablet a kiosk", onDismiss, saveLabel = "Make this tablet a kiosk", canSave = outlet != null && name.isNotBlank(), save = {
        val r = env.client.post("/v1/admin/attendance/kiosks", J.obj("outlet_id" to J.i(outlet), "name" to J.s(name.trim())))
        val token = r.json["token"].str ?: throw ke.co.bethanyhouse.hub.core.ApiError(0, "The Hub did not return a kiosk token.")
        env.kiosk.enter(token)
        env.session.signOut()
    }) { e ->
        OutletChoice("Outlet", outlet, { outlet = it })
        Field("Tablet name", name, { name = it }, placeholder = "Front door", error = e?.field("name"))
        Text("You will be signed out of this device and the clock-in board opens. Staff need a terminal PIN (Profile › Security) to punch. You will be asked to confirm it is you.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
    }
}
