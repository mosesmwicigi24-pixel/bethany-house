package ke.co.bethanyhouse.hub.ui.features.setup

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ChevronLeft
import androidx.compose.material.icons.filled.ChevronRight
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
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
import androidx.compose.runtime.toMutableStateList
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.AsyncButton
import ke.co.bethanyhouse.hub.ui.components.AttachmentPicker
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.DayField
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.LoadingSkeleton
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Pill
import ke.co.bethanyhouse.hub.ui.components.RemoteImage
import ke.co.bethanyhouse.hub.ui.components.RemoteJson
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SupportListRow
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.toastResult
import ke.co.bethanyhouse.hub.ui.shell.FeatureRegistry
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement

/**
 * Setup: business, outlets, attendance rules, automations, holidays, roles,
 * users, reference data, commerce settings, production configuration,
 * activity logs, trash, database and downloads. Money-relevant settings
 * (rates, tax, settlement) go through the server's proposals exactly as the
 * console sends them; the screen only shows the answer.
 */
object SetupFeature {
    fun register() {
        FeatureRegistry.register(Route.SetupBusiness) { BusinessSettingsScreen() }
        FeatureRegistry.register(Route.Outlets) { OutletsScreen() }
        FeatureRegistry.register(Route.AttendanceRules) { AttendanceRulesScreen() }
        FeatureRegistry.register(Route.Automations) { AutomationsScreen() }
        FeatureRegistry.register(Route.Holidays) { HolidaysScreen() }
        FeatureRegistry.register(Route.Roles) { RolesScreen() }
        FeatureRegistry.register(Route.Users) { UsersScreen() }
        FeatureRegistry.register(Route.Countries) { CountriesScreen() }
        FeatureRegistry.register(Route.Currencies) { CurrenciesScreen() }
        FeatureRegistry.register(Route.Languages) { LanguagesScreen() }
        FeatureRegistry.register(Route.TaxRates) { TaxRatesScreen() }
        FeatureRegistry.register(Route.DiscountLimits) { DiscountLimitsScreen() }
        FeatureRegistry.register(Route.PaymentMethods) { PaymentMethodsScreen() }
        FeatureRegistry.register(Route.Shipping) { ShippingScreen() }
        FeatureRegistry.register(Route.ProductionStages) { ProductionStagesScreen() }
        FeatureRegistry.register(Route.AutoAssignees) { AutoAssigneesScreen() }
        FeatureRegistry.register(Route.ActivityLogs) { ActivityLogsScreen() }
        FeatureRegistry.register(Route.Trash) { TrashScreen() }
        FeatureRegistry.register(Route.Database) { DatabaseScreen() }
        FeatureRegistry.register(Route.Downloads) { DownloadApprovalsScreen() }
        FeatureRegistry.register(Route.MyDownloads) { MyDownloadsScreen() }
    }
}

// ── Business (GET/PUT /settings; POST /settings/logo — step-up) ──────────────

private val businessText = listOf("app_name" to "Business name", "app_tagline" to "Tagline", "app_email" to "Email", "app_phone" to "Phone", "app_address" to "Address", "app_city" to "City", "app_country" to "Country code", "app_timezone" to "Timezone", "default_currency" to "Default currency", "default_language" to "Default language", "order_prefix" to "Order prefix", "receipt_footer" to "Receipt footer", "low_stock_threshold" to "Low stock threshold")
private val businessBools = listOf("tax_inclusive" to "Prices include tax", "enable_guest_checkout" to "Guest checkout", "enable_reviews" to "Product reviews", "maintenance_mode" to "Maintenance mode")

@Composable
fun BusinessSettingsScreen() {
    val env = LocalEnv.current
    val f = remember { SetupForm() }
    val flags = remember { androidx.compose.runtime.mutableStateMapOf<String, Boolean>() }
    var loaded by remember { mutableStateOf(false) }
    var logoUrl by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<ApiError?>(null) }
    val canEdit = env.session.current.can("settings.edit")
    val scope = rememberCoroutineScope()

    LaunchedEffect(Unit) {
        if (loaded) return@LaunchedEffect
        try {
            val s = env.client.get("/v1/admin/settings").json["settings"]
            businessText.forEach { (k, _) -> f[k] = s[k].str ?: "" }
            businessBools.forEach { (k, _) -> flags[k] = s[k].bool ?: false }
            logoUrl = s["app_logo_url"].str
            loaded = true
        } catch (e: Exception) { env.toasts.error(e) }
    }

    Page("Business", subtitle = "Identity, defaults and storefront switches") {
        if (!loaded) { LoadingSkeleton(); return@Page }
        SectionCard("Logo") {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.l)) {
                logoUrl?.let { RemoteImage(it, size = 80.dp) }
                if (canEdit) AttachmentPicker("Upload logo", camera = false) { files ->
                    files.firstOrNull()?.let { file ->
                        scope.launch {
                            try { val r = env.client.upload("/v1/admin/settings/logo", listOf(file.part("logo"))); toastResult(env, r); logoUrl = r.json["url"].str } catch (e: Exception) { env.toasts.error(e) }
                        }
                    }
                }
            }
        }
        SectionCard("Details") {
            businessText.forEach { (k, label) -> Field(label, f[k], { f[k] = it }, error = error?.field(k), multiline = k == "receipt_footer" || k == "app_address", enabled = canEdit) }
        }
        SectionCard("Switches") {
            businessBools.forEach { (k, label) -> ToggleRow(label, flags[k] ?: false, { flags[k] = it }) }
        }
        if (canEdit) AsyncButton("Save (asks you to confirm it's you)", Modifier.fillMaxWidth()) {
            val pairs = businessText.map { (k, _) -> k to J.s(f[k]) }.toMutableList<Pair<String, JsonElement?>>()
            pairs += "low_stock_threshold" to J.typed(f["low_stock_threshold"])
            businessBools.forEach { (k, _) -> pairs += k to J.b(flags[k] ?: false) }
            try { val r = env.client.put("/v1/admin/settings", J.obj(*pairs.toTypedArray())); toastResult(env, r); error = null } catch (e: ApiError) { error = e; throw e }
        }
    }
}

// ── Attendance rules (GET/PUT /attendance/rules; PUT needs settings.edit) ───

private val attendanceNumbers = listOf("auto_clock_out_time" to "Auto clock-out at (HH:mm)", "auto_clock_out_after_hours" to "Auto clock-out after (hours)", "daily_limit_hours" to "Daily limit (hours)", "over_scheduled_minutes" to "Flag when over schedule by (min)", "under_scheduled_minutes" to "Flag when under schedule by (min)", "earliest_clock_in_minutes" to "Earliest clock-in before shift (min)", "trail_interval_minutes" to "Trail every (min)", "trail_distance_meters" to "Trail every (metres)", "leave_site_grace_minutes" to "Leaving site grace (min)", "leave_site_distance_meters" to "Leaving site distance (m)", "trail_retention_days" to "Keep trails (days)")
private val attendanceChoices = listOf("clock_in_without_shift", "location_mode", "leave_site_action")
private val attendanceFlags = listOf("remind_clock_in", "remind_clock_out")

@Composable
fun AttendanceRulesScreen() {
    val env = LocalEnv.current
    val f = remember { SetupForm() }
    val flags = remember { androidx.compose.runtime.mutableStateMapOf<String, Boolean>() }
    val days = remember { androidx.compose.runtime.mutableStateListOf<Int>() }
    var loaded by remember { mutableStateOf(false) }
    val canEdit = env.session.current.can("settings.edit")

    LaunchedEffect(Unit) {
        if (loaded) return@LaunchedEffect
        try {
            val r = env.client.get("/v1/admin/attendance/rules").json["rules"]
            attendanceNumbers.forEach { (k, _) -> f[k] = r[k].str ?: "" }
            attendanceChoices.forEach { k -> f[k] = r[k].str ?: "" }
            attendanceFlags.forEach { k -> flags[k] = r[k].bool ?: false }
            days.clear(); days.addAll(r["working_days"].records.mapNotNull { it.int })
            loaded = true
        } catch (e: Exception) { env.toasts.error(e) }
    }

    Page("Attendance rules", subtitle = "How the time clock behaves for everyone") {
        if (!loaded) { LoadingSkeleton(); return@Page }
        SectionCard("Working days") {
            androidx.compose.foundation.layout.FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.xs), verticalArrangement = Arrangement.spacedBy(Space.xs)) {
                listOf("Mo", "Tu", "We", "Th", "Fr", "Sa", "Su").forEachIndexed { i, label ->
                    val d = i + 1
                    val on = d in days
                    OutlinedButton(onClick = { if (on) days.remove(d) else days.add(d) }, colors = ButtonDefaults.outlinedButtonColors(contentColor = if (on) Palette.brand else Palette.neutral)) { Text(label) }
                }
            }
        }
        SectionCard("Clocking") {
            OptionPicker("Clock in without a rostered shift", f["clock_in_without_shift"], listOf("allow" to "Allow", "flag" to "Allow and flag", "refuse" to "Refuse"), { f["clock_in_without_shift"] = it })
            ToggleRow("Remind to clock in", flags["remind_clock_in"] ?: false, { flags["remind_clock_in"] = it })
            ToggleRow("Remind to clock out", flags["remind_clock_out"] ?: false, { flags["remind_clock_out"] = it })
        }
        SectionCard("Location") {
            OptionPicker("Location mode", f["location_mode"], listOf("off" to "Off", "punch" to "At clock in/out", "trail" to "Trail while on shift"), { f["location_mode"] = it })
            OptionPicker("When someone leaves the site", f["leave_site_action"], listOf("none" to "Nothing", "flag" to "Flag the entry", "clock_out" to "Clock them out"), { f["leave_site_action"] = it })
        }
        SectionCard("Limits") {
            attendanceNumbers.forEach { (k, label) -> Field(label, f[k], { f[k] = it }) }
        }
        if (canEdit) AsyncButton("Save rules", Modifier.fillMaxWidth()) {
            val pairs = attendanceNumbers.map { (k, _) -> k to J.typed(f[k]) }.toMutableList<Pair<String, JsonElement?>>()
            pairs += "auto_clock_out_time" to J.s(f["auto_clock_out_time"])
            attendanceChoices.forEach { k -> pairs += k to J.s(f[k]) }
            attendanceFlags.forEach { k -> pairs += k to J.b(flags[k] ?: false) }
            pairs += "working_days" to J.ints(days.sorted())
            val r = env.client.put("/v1/admin/attendance/rules", J.obj(*pairs.toTypedArray())); toastResult(env, r)
        }
    }
}

// ── Automations (GET /attendance/automations; PUT /automations/{key}; PUT /recognition-points) ──

@Composable
fun AutomationsScreen() {
    val env = LocalEnv.current
    var version by remember { mutableIntStateOf(0) }
    var pointKes by remember { mutableStateOf("") }
    val canEdit = env.session.current.can("settings.edit")

    Page("Automations", subtitle = "The alerts the hub sends managers, and the value of a recognition point", refresh = { version++ }) {
        RemoteJson("/v1/admin/attendance/automations", version = version) { j, reload ->
            val people = j["people"].toRecords()
            j["automations"].toRecords().forEach { a -> AutomationCard(a, people, canEdit, reload) }
            SectionCard("Recognition points") {
                SetupCaption("A point given on the Updates feed is worth this much in KES on the person's file.")
                Field("KES per point", pointKes, { pointKes = it }, placeholder = j["recognition_point_kes"].str ?: "")
                if (canEdit) AsyncButton("Save", enabled = pointKes.isNotEmpty()) {
                    val r = env.client.put("/v1/admin/attendance/recognition-points", J.obj("kes" to J.typed(pointKes))); toastResult(env, r); reload()
                }
            }
        }
    }
}

@Composable
private fun AutomationCard(a: Record, people: List<Record>, canEdit: Boolean, reload: () -> Unit) {
    val env = LocalEnv.current
    val key = a.string("key") ?: ""
    var enabled by remember(key) { mutableStateOf(a.bool("enabled") ?: false) }
    var threshold by remember(key) { mutableStateOf(a["threshold"].str ?: "") }
    var recipients by remember(key) { mutableStateOf(a.string("recipients") ?: "managers") }
    val extra = remember(key) { a["extra_user_ids"].records.mapNotNull { it.int }.toMutableStateList() }
    var menu by remember { mutableStateOf(false) }

    SectionCard(a.string("label") ?: key) {
        SetupSecondary(a.string("text") ?: "")
        ToggleRow("Enabled", enabled, { enabled = it })
        a.string("unit")?.let { unit -> Field("Threshold ($unit, ${a.int("min") ?: 0}–${a.int("max") ?: 0})", threshold, { threshold = it }) }
        OptionPicker("Send to", recipients, listOf("managers" to "Outlet managers", "hr" to "HR", "owner" to "Owner", "finance" to "Finance"), { recipients = it })
        androidx.compose.foundation.layout.Box {
            OutlinedButton(onClick = { menu = true }) { Text("Also: ${extra.size} people") }
            DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                people.forEach { p ->
                    val id = p.int("id") ?: 0
                    DropdownMenuItem(text = { Text((if (id in extra) "✓ " else "") + (p.string("name") ?: "")) }, onClick = { if (id in extra) extra.remove(id) else extra.add(id) })
                }
            }
        }
        if (a.bool("has_recipients") == false) SetupCaption("Nobody would receive this yet.", Palette.warning)
        if (canEdit) AsyncButton("Save") {
            val r = env.client.put("/v1/admin/attendance/automations/$key", J.obj("enabled" to J.b(enabled), "threshold" to J.typed(threshold), "recipients" to J.s(recipients), "extra_user_ids" to J.ints(extra.toList())))
            toastResult(env, r); reload()
        }
    }
}

// ── Holidays (GET /holidays?year; POST/PUT/DELETE need hr.edit) ─────────────

@Composable
fun HolidaysScreen() {
    val env = LocalEnv.current
    var year by remember { mutableIntStateOf(Day.thisYear()) }
    var version by remember { mutableIntStateOf(0) }
    var editing by remember { mutableStateOf<Record?>(null) }
    var adding by remember { mutableStateOf(false) }
    val canEdit = env.session.current.can("hr.edit")

    Page("Public holidays", subtitle = "Days the roster and leave count as holidays", refresh = { version++ }) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            IconButton(onClick = { year-- }) { Icon(Icons.Default.ChevronLeft, contentDescription = "Previous year") }
            Text(year.toString(), style = MaterialTheme.typography.titleMedium)
            IconButton(onClick = { year++ }) { Icon(Icons.Default.ChevronRight, contentDescription = "Next year") }
            Spacer(Modifier.weight(1f))
            if (canEdit) Button(onClick = { adding = true }) { Text("Add holiday") }
        }
        RemoteJson("/v1/admin/holidays", query = mapOf("year" to year.toString()), version = version, emptyTitle = "No holidays recorded for $year", isEmpty = { it["data"].records.isEmpty() }) { j, reload ->
            j["data"].toRecords().forEach { h ->
                val confirmed = h.bool("is_confirmed") == true
                SupportListRow(h.string("name") ?: "", subtitle = "${Day.format(h.string("date"), "EEEE d MMMM")} · ${h.string("country") ?: ""}${h.string("notes")?.let { " · $it" } ?: ""}") {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Pill(if (confirmed) "confirmed" else "expected", if (confirmed) StatusTone.SUCCESS else StatusTone.AMBER)
                        if (canEdit) {
                            TextButton(onClick = { editing = h }) { Text("Edit") }
                            SetupIconAction(description = "Delete") { val r = env.client.delete("/v1/admin/holidays/${h.id}"); toastResult(env, r); reload() }
                        }
                    }
                }
            }
        }
    }
    if (adding) HolidaySheet(null, onDismiss = { adding = false }) { version++ }
    editing?.let { h -> HolidaySheet(h, onDismiss = { editing = null }) { version++ } }
}

@Composable
private fun HolidaySheet(record: Record?, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    var date by remember { mutableStateOf(record?.string("date")?.take(10) ?: Day.today()) }
    var name by remember { mutableStateOf(record?.string("name") ?: "") }
    var confirmed by remember { mutableStateOf(record?.bool("is_confirmed") ?: true) }
    var notes by remember { mutableStateOf(record?.string("notes") ?: "") }
    FormSheet(if (record == null) "Add holiday" else "Edit holiday", onDismiss, canSave = name.isNotEmpty(), save = {
        val body = J.obj("date" to J.s(date), "name" to J.s(name), "is_confirmed" to J.b(confirmed), "notes" to J.s(notes))
        val r = if (record != null) env.client.put("/v1/admin/holidays/${record.id}", body) else env.client.post("/v1/admin/holidays", body)
        toastResult(env, r); saved()
    }) { e ->
        DayField("Date", date, { date = it })
        Field("Name", name, { name = it }, error = e?.field("name") ?: e?.field("date"))
        ToggleRow("Confirmed (gazetted)", confirmed, { confirmed = it })
        Field("Notes", notes, { notes = it })
        Spacer(Modifier.height(Space.xs))
    }
}
