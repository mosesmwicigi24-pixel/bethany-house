package ke.co.bethanyhouse.hub.ui.features.me

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Geofence
import ke.co.bethanyhouse.hub.core.HubDates
import ke.co.bethanyhouse.hub.core.LocationFix
import ke.co.bethanyhouse.hub.core.OfflineStore
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.date
import ke.co.bethanyhouse.hub.core.dbl
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.obj
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toJson
import ke.co.bethanyhouse.hub.location.TrailService
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import java.time.Duration
import java.time.Instant
import kotlin.math.max
import kotlin.math.roundToInt

/** Where the phone's location stands, in words (ClockPage's states). */
private sealed class LocStatus {
    data object Idle : LocStatus()
    data object Asking : LocStatus()
    data object Denied : LocStatus()
    data object Locating : LocStatus()
    data object Fixed : LocStatus()
    data class Unavailable(val message: String) : LocStatus()
}

private fun hasForegroundLocation(context: Context) =
    ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
        ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED

private fun hasBackgroundLocation(context: Context) =
    Build.VERSION.SDK_INT < 29 || ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_BACKGROUND_LOCATION) == PackageManager.PERMISSION_GRANTED

/**
 * Clock in on the phone (ClockPage.tsx). One screen, one big button. The
 * device reports where it is and how sure it is; the server decides inside
 * or outside the outlet's zone and keeps the evidence. Every state is
 * explained in words (locating, location off, outside, offline). A fix a
 * fake-GPS app supplied is refused here, before anything is sent.
 *
 * A punch that cannot reach the server is kept in the offline queue
 * (`kind: "punch"`, one slot) with the phone's time as `recorded_at`; the
 * server stores that as the punch time and flags it `sent_late`.
 */
@Composable
internal fun ClockScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val c = env.client
    val status = remember { Resource(scope) { c.get("/v1/admin/time-clock/status").json ?: JsonNull } }
    val shifts = remember { Resource(scope) { c.get("/v1/admin/me/shifts", background = true).json ?: JsonNull } }
    val leave = remember { Resource(scope) { c.get("/v1/admin/me/leave", background = true).json ?: JsonNull } }
    var locStatus by remember { mutableStateOf<LocStatus>(LocStatus.Idle) }
    var fix by remember { mutableStateOf<LocationFix?>(null) }
    val latest by env.location.latest.collectAsStateWithLifecycle()
    var chosenOutletId by remember { mutableStateOf<Int?>(null) }
    var pending by remember { mutableStateOf<OfflineStore.PendingOperation?>(null) }
    var busy by remember { mutableStateOf(false) }
    var askReason by remember { mutableStateOf(false) }
    var reason by remember { mutableStateOf("") }
    var serverAsksOverride by remember { mutableStateOf(false) }
    val isOnline by env.connection.isOnline.collectAsStateWithLifecycle()
    var now by remember { mutableStateOf(Instant.now()) }
    LaunchedEffect(Unit) { while (true) { delay(1000); now = Instant.now() } }

    val canOverride = env.session.current.can("attendance.manage") || env.session.current.can("outlets.all_access")
    val data = status.value
    val active = data["active_entry"].obj
    val outlets = data["outlets"].records
    val rules = data["rules"]

    fun distance(f: LocationFix, o: JsonElement): Double? {
        val lat = o["latitude"].dbl ?: return null; val lng = o["longitude"].dbl ?: return null
        return Geofence.distanceMeters(f.latitude, f.longitude, lat, lng)
    }
    /** Which outlet: the active entry's, else the chosen, else the nearest, else the only one. */
    val outlet: JsonElement? = run {
        val activeOutletId = active["outlet"]["id"].int
        if (active != null && activeOutletId != null) return@run outlets.firstOrNull { it["id"].int == activeOutletId }
        chosenOutletId?.let { id -> outlets.firstOrNull { it["id"].int == id } }?.let { return@run it }
        if (outlets.isEmpty()) return@run null
        val f = fix
        if (f != null) {
            val nearest = outlets.filter { it["latitude"].dbl != null && it["longitude"].dbl != null }.minByOrNull { distance(f, it) ?: Double.POSITIVE_INFINITY }
            if (nearest != null) return@run nearest
        }
        outlets[0]
    }
    val distanceMeters: Double? = fix?.let { f -> outlet?.let { distance(f, it) } }
    val radius: Double? = outlet["geofence_radius_meters"].dbl
    val verdict: Geofence.Verdict = distanceMeters?.let { Geofence.verdict(it, radius, fix?.accuracyMeters) } ?: Geofence.verdict(0.0, null, null)
    val insideOrEdge = distanceMeters == null || verdict != Geofence.Verdict.OUTSIDE
    val mocked = fix?.mocked == true

    // ── Location: permission at the moment it is needed, then updates while the screen is open ──
    suspend fun locate() {
        locStatus = LocStatus.Locating
        val f = env.location.currentFix()
        if (f == null) { if (fix == null) locStatus = LocStatus.Unavailable("Could not get a location fix. Step outside or near a window and try again.") }
        else { fix = f; locStatus = LocStatus.Fixed }
    }
    val permissionLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { granted ->
        if (granted.values.any { it }) { env.location.startUpdating(); scope.launch { locate() } } else locStatus = LocStatus.Denied
    }
    fun startLocation() {
        if (hasForegroundLocation(context)) { env.location.startUpdating(); scope.launch { locate() } }
        else { locStatus = LocStatus.Asking; permissionLauncher.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION)) }
    }
    LaunchedEffect(latest) {
        val l = latest ?: return@LaunchedEffect
        if (Duration.between(l.at, Instant.now()).abs().toMinutes() < 2 && hasForegroundLocation(context)) { fix = l; locStatus = LocStatus.Fixed }
    }
    DisposableEffect(Unit) { onDispose { env.location.stopUpdating() } }

    // ── Pending punch ───────────────────────────────────────────────────────
    suspend fun loadPending() { pending = env.offline?.pending()?.firstOrNull { it.kind == "punch" } }
    /** Sends the kept punch; a refusal drops it (the server said no), a transport failure keeps it for next time. */
    suspend fun sendPending() {
        val p = pending ?: return
        val store = env.offline ?: return
        val label = if (p.path.endsWith("clock-in")) "clock-in" else "clock-out"
        try {
            c.send(ApiClient.Request(ApiClient.Method.POST, p.path, body = ApiClient.Body.Json(p.body)))
            store.dequeue(p.id)
            pending = null
            env.toasts.show("Your $label from ${MeFormat.whenAt(Instant.ofEpochSecond(p.recordedAt))} has been sent.")
            status.reload()
        } catch (e: ApiError) {
            if (!e.isOffline) {
                store.dequeue(p.id)
                pending = null
                env.toasts.show("The saved punch could not be recorded: ${e.message}", StatusTone.DANGER)
            }
        }
    }
    LaunchedEffect(Unit) {
        status.load(); shifts.load(); leave.load()
        loadPending()
        startLocation()
        if (pending != null && isOnline) sendPending()
    }
    LaunchedEffect(isOnline) { if (isOnline && pending != null) sendPending() }

    // ── Location on shift (rules.location_mode == "trail") ──────────────────
    val locationMode = rules["location_mode"].str
    val activeNow = active != null
    fun startTrail() {
        TrailService.start(context, rules["trail_interval_minutes"].int ?: 5, (rules["trail_distance_meters"].dbl ?: 100.0).roundToInt())
    }
    val backgroundLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { startTrail() }
    LaunchedEffect(activeNow, locationMode, data == null) {
        if (data == null) return@LaunchedEffect
        if (activeNow && locationMode == "trail") {
            if (!hasForegroundLocation(context)) return@LaunchedEffect
            if (hasBackgroundLocation(context)) startTrail() else backgroundLauncher.launch(Manifest.permission.ACCESS_BACKGROUND_LOCATION)
        } else TrailService.stop(context)
    }

    // ── Punching (fields as ClockPage.tsx sends them) ───────────────────────
    val elapsedMinutes: Int = run {
        val inAt = active["clock_in_at"].date ?: return@run 0
        max(0, Duration.between(inAt, now).toMinutes().toInt() - (active["total_break_minutes"].int ?: 0))
    }
    suspend fun punch(force: Boolean = false) {
        val o = outlet ?: return
        val outletId = o["id"].int ?: return
        busy = true
        try {
            val f = env.location.currentFix(10_000) ?: fix
            if (f == null) { env.toasts.show("Waiting for your location. Try again in a moment.", StatusTone.WARNING); return }
            fix = f
            if (f.mocked) { env.toasts.show("This location comes from a mock-location app. Turn it off and try again.", StatusTone.DANGER); return }
            val body = mutableMapOf<String, Any?>(
                "latitude" to f.latitude, "longitude" to f.longitude, "accuracy" to f.accuracyMeters,
                "recorded_at" to HubDates.iso(Instant.now()), "mock_location" to f.mocked,
            )
            val path: String
            if (active != null) path = "/v1/admin/time-clock/clock-out" else {
                path = "/v1/admin/time-clock/clock-in"
                body["outlet_id"] = outletId
                body["app_version"] = "app-${env.device.platform}-${env.device.appVersion}"
                if (force) { body["force"] = true; body["reason"] = reason.trim() }
            }
            val json = toJson(body)
            try {
                val r = c.post(path, json)
                env.toasts.show(r.json["message"].str ?: if (active != null) "Clocked out" else "Clocked in")
                askReason = false; reason = ""; serverAsksOverride = false
                status.reload()
            } catch (e: ApiError) {
                val store = env.offline
                if (e.isOffline && !force && store != null) {
                    val op = OfflineStore.PendingOperation(kind = "punch", method = "POST", path = path, body = json, dedupeKey = "punch")
                    store.enqueue(op)
                    pending = op
                    env.toasts.show(if (active != null) "No signal. Your clock-out is saved and will be sent when you are back online." else "No signal. Your clock-in is saved and will be sent when you are back online.", StatusTone.INFO)
                } else {
                    if (e.raw["requires_override"].bool == true) serverAsksOverride = true
                    env.toasts.error(e)
                }
            }
        } finally { busy = false }
    }
    suspend fun breakToggle(start: Boolean) {
        busy = true
        try {
            val r = c.post("/v1/admin/time-clock/break/${if (start) "start" else "end"}")
            env.toasts.show(r.json["message"].str ?: if (start) "Break started" else "Break ended")
            status.reload()
        } catch (e: ApiError) { env.toasts.error(e) } finally { busy = false }
    }

    val canPunch = fix != null && outlet != null && !busy && !mocked && (active != null || insideOrEdge)
    val showForce = active == null && fix != null && outlet != null && canOverride && !mocked && (serverAsksOverride || !insideOrEdge)

    Page("Time clock", subtitle = MeFormat.longDay(Instant.now()), refresh = { status.reload() }) {
        // Words for every state
        val (bannerText, bannerTone) = when (val ls = locStatus) {
            LocStatus.Denied -> "Location is off for this app" to StatusTone.DANGER
            is LocStatus.Unavailable -> "Location unavailable" to StatusTone.DANGER
            LocStatus.Idle, LocStatus.Asking, LocStatus.Locating -> "Finding you…" to StatusTone.NEUTRAL
            LocStatus.Fixed -> {
                val f = fix; val o = outlet; val d = distanceMeters
                when {
                    f == null -> "Finding you…" to StatusTone.NEUTRAL
                    f.mocked -> "Mock location detected" to StatusTone.DANGER
                    o == null -> "No outlet to clock into" to StatusTone.DANGER
                    d == null -> "${o["name"].str ?: "Outlet"} has no location set" to StatusTone.WARNING
                    verdict == Geofence.Verdict.INSIDE -> "${d.roundToInt()} m from ${o["name"].str ?: "the outlet"}" to StatusTone.SUCCESS
                    verdict == Geofence.Verdict.EDGE -> "${d.roundToInt()} m away, phone ±${f.accuracyMeters?.roundToInt() ?: 0} m" to StatusTone.WARNING
                    else -> "${d.roundToInt()} m away, outside the ${radius?.roundToInt() ?: 0} m zone" to StatusTone.DANGER
                }
            }
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) { StatusBadge(bannerText, bannerTone) }
        if (outlets.size > 1 && active == null) {
            SelectField("Outlet", outlet["id"].int, outlets.mapNotNull { o -> o["id"].int?.let { it to (o["name"].str ?: "Outlet") } }, { chosenOutletId = it }, Modifier.fillMaxWidth(), placeholder = "Nearest")
        }
        when (val ls = locStatus) {
            LocStatus.Denied -> MeNotice("Clocking in needs your location, only at the moment you press the button. Allow location for Bethany House Hub in Settings, then try again.", StatusTone.DANGER, Modifier.clickable { startLocation() })
            is LocStatus.Unavailable -> Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                MeNotice(ls.message, StatusTone.WARNING, Modifier.weight(1f))
                TextButton(onClick = { startLocation() }) { Text("Try again") }
            }
            else -> {}
        }
        if (mocked) MeNotice("Your phone reports a mock location (a fake-GPS app). Turn it off and try again; a punch cannot be sent from a simulated position.", StatusTone.DANGER)
        pending?.let { p ->
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                MeNotice("A ${if (p.path.endsWith("clock-in")) "clock-in" else "clock-out"} from ${MeFormat.whenAt(Instant.ofEpochSecond(p.recordedAt))} is waiting to be sent.", StatusTone.INFO, Modifier.weight(1f))
                TextButton(onClick = { scope.launch { sendPending() } }, enabled = isOnline) { Text("Send now") }
            }
        }

        // The clock and the button
        SectionCard {
            Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(Space.m)) {
                Text(MeFormat.time(now), fontSize = 48.sp, fontWeight = FontWeight.Black, lineHeight = 52.sp)
                val stateLine = when {
                    data == null -> "Loading…"
                    active == null -> "Not clocked in"
                    active["on_break"].bool == true -> "On break · clocked in at ${MeFormat.time(active["clock_in_at"].date)}"
                    else -> "Clocked in at ${MeFormat.time(active["clock_in_at"].date)} · ${MeFormat.clockMinutes(elapsedMinutes)}"
                }
                Text(stateLine, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                val hint = when {
                    fix == null -> "waiting for location"
                    active != null -> outlet["name"].str ?: ""
                    verdict == Geofence.Verdict.INSIDE -> "you are at ${outlet["name"].str ?: "the outlet"}"
                    verdict == Geofence.Verdict.EDGE -> "near the zone · will be noted"
                    else -> "outside the zone"
                }
                Box(
                    Modifier.size(176.dp).alpha(if (canPunch) 1f else 0.4f).shadow(14.dp, CircleShape).background(if (active != null) Palette.ink else Palette.brand, CircleShape)
                        .clickable(enabled = canPunch) { scope.launch { punch() } },
                    contentAlignment = Alignment.Center,
                ) {
                    if (busy) CircularProgressIndicator(color = Color.White)
                    else Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(4.dp), modifier = Modifier.padding(horizontal = 16.dp)) {
                        Text(if (active != null) "CLOCK OUT" else "CLOCK IN", fontSize = 20.sp, fontWeight = FontWeight.Black, color = Color.White, letterSpacing = 1.sp)
                        Text(hint, style = MaterialTheme.typography.labelSmall, color = Color.White.copy(alpha = 0.9f), textAlign = TextAlign.Center)
                    }
                }
            }
            if (active != null) Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Center) {
                if (active["on_break"].bool == true) OutlinedButton(onClick = { scope.launch { breakToggle(false) } }, enabled = !busy) { Text("End break") }
                else OutlinedButton(onClick = { scope.launch { breakToggle(true) } }, enabled = !busy) { Text("Start break") }
            }
            if (showForce && !askReason) TextButton(onClick = { askReason = true }, modifier = Modifier.fillMaxWidth()) { Text("Clock in anyway (manager override)", style = MaterialTheme.typography.labelSmall) }
            if (showForce && askReason) Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                Text("Why are you clocking in from outside the zone? This is written to the audit trail.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                OutlinedTextField(value = reason, onValueChange = { reason = it }, placeholder = { Text("e.g. delivering to a client first") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s, Alignment.End)) {
                    TextButton(onClick = { askReason = false; reason = "" }) { Text("Cancel") }
                    Button(onClick = { scope.launch { punch(force = true) } }, enabled = reason.trim().length >= 3 && !busy) { Text("Clock in with override") }
                }
            }
            fix?.let { f ->
                Text("Phone accuracy ±${f.accuracyMeters?.roundToInt() ?: 0} m${radius?.let { " · zone ${it.roundToInt()} m" } ?: ""}. Location is read only while this screen is open.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth())
            }
        }

        // Today, this week, last punch
        SummaryCard(data, shifts.value, leave.value, active, elapsedMinutes)

        val todayEntries = data["today_entries"].records
        if (todayEntries.isNotEmpty()) {
            MeSectionLabel("Today's punches")
            MeListCard {
                todayEntries.forEach { e ->
                    MeListRow {
                        Text("${MeFormat.time(e["clock_in_at"].date)} – ${e["clock_out_at"].date?.let(MeFormat::time) ?: "…"}", style = MaterialTheme.typography.bodyMedium, modifier = Modifier.weight(1f))
                        FlagChips(e)
                        Text(if (e["status"].str == "active") MeFormat.clockMinutes(elapsedMinutes) else MeFormat.clockMinutes(e["worked_minutes"].int), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                    }
                }
            }
        }
    }
}

@Composable
private fun SummaryCard(data: JsonElement?, mine: JsonElement?, leave: JsonElement?, active: JsonElement?, elapsedMinutes: Int) {
    val env = LocalEnv.current
    val today = mine["today"].obj
    val next = mine["next"].obj
    val balances = leave["balances"].records
    val annual = balances.firstOrNull { it["type"]["code"].str == "annual" } ?: balances.firstOrNull { !it["available"].isNull }
    val rules = data["rules"]
    val last = data["last_entry"].obj
    val todayMinutes = (data["today_worked_minutes"].int ?: 0) + (if (active != null) max(0, elapsedMinutes - (active["elapsed_minutes"].int ?: 0)) else 0)
    MeListCard {
        SummaryRow("Today's shift") {
            Text(
                today?.let { "${it["starts_at"].str ?: ""} – ${it["ends_at"].str ?: ""}${it["label"].str?.let { l -> " · $l" } ?: ""}" }
                    ?: next?.let { "Next: ${MeFormat.weekday(MeFormat.date(it["date"].str))} ${it["starts_at"].str ?: ""}" }
                    ?: "None rostered",
                style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, textAlign = TextAlign.End, modifier = Modifier.clickable { env.navigator.go(Route.MyShifts) },
            )
        }
        SummaryRow("Today") { Text(MeFormat.clockMinutes(todayMinutes), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold) }
        SummaryRow("Leave balance") { Text(annual["available"].int?.let { "$it days" } ?: "—", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, modifier = Modifier.clickable { env.navigator.go(Route.MyLeave) }) }
        SummaryRow("This week") { Text(MeFormat.clockMinutes(data["week_worked_minutes"].int ?: 0), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, modifier = Modifier.clickable { env.navigator.go(Route.MyTimesheet) }) }
        SummaryRow("Last punch") {
            if (last != null) Row(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalAlignment = Alignment.CenterVertically) {
                Text("${MeFormat.whenAt(last["clock_out_at"].date ?: last["clock_in_at"].date)} · ${if (!last["clock_out_at"].isNull) "out" else "in"}", style = MaterialTheme.typography.bodySmall)
                if (last["clock_in_method"].str == "override") StatusBadge("override", StatusTone.WARNING)
                FlagChips(last)
            } else Text("—", style = MaterialTheme.typography.bodySmall)
        }
        data["today_shift"].obj?.let { shift ->
            SummaryRow("Today's shift") { Text("${shift["starts_at"].str ?: ""}–${shift["ends_at"].str ?: ""}${shift["outlet"].str?.let { " · $it" } ?: ""}", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold) }
        }
        if (rules["location_mode"].str == "trail") MeListRow { Text(trailWords(rules), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
        rules["auto_clock_out_time"].str?.let { auto ->
            MeListRow { Text("A shift still open at $auto is closed automatically and shown to your manager.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
        }
    }
}

private fun trailWords(rules: JsonElement?): String {
    var s = "While you are clocked in the app records your position every few minutes; it stops when you clock out."
    val action = rules["leave_site_action"].str
    if (action != null && action != "none") s += " Leaving the site for more than ${rules["leave_site_grace_minutes"].int ?: 0} minutes is ${if (action == "clock_out") "clocked out" else "noted for your manager"}."
    return s
}

@Composable
private fun SummaryRow(label: String, value: @Composable () -> Unit) {
    MeListRow {
        Text(label, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
        Spacer(Modifier.width(Space.m).weight(1f))
        value()
    }
}

/** Flags the server attached to a punch, as short words (ClockPage FLAG_SHORT). */
@Composable
internal fun FlagChips(entry: JsonElement?) {
    val short = mapOf("accuracy_low" to "imprecise", "edge" to "near the edge", "sent_late" to "sent late", "mock_location" to "mock location", "auto_clock_out" to "auto closed")
    val flags = entry["flags"].records.mapNotNull { it.str }
    if (flags.isNotEmpty()) Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) { flags.forEach { StatusBadge(short[it] ?: it, StatusTone.WARNING) } }
}
