package ke.co.bethanyhouse.hub.ui.features.setup

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.toMutableStateList
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.long
import ke.co.bethanyhouse.hub.core.obj
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.AsyncButton
import ke.co.bethanyhouse.hub.ui.components.AsyncTextButton
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.DayField
import ke.co.bethanyhouse.hub.ui.components.DocumentButton
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.Page
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
import ke.co.bethanyhouse.hub.ui.components.message
import ke.co.bethanyhouse.hub.ui.components.prettyJson
import ke.co.bethanyhouse.hub.ui.components.saveDocument
import ke.co.bethanyhouse.hub.ui.components.shareFile
import ke.co.bethanyhouse.hub.ui.components.toastResult
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject

/**
 * Database management (super admin + settings.manage_database; every write
 * behind step-up): stats, backups, restore, schedule, storage, clear-by-date.
 * The full wipe is deliberately not offered here (docs/NATIVE_APPS_CHECKLIST.md).
 */
private enum class DbTab { BACKUPS, SCHEDULE, STORAGE, CLEAR }

@Composable
fun DatabaseScreen() {
    val env = LocalEnv.current
    var tab by remember { mutableStateOf(DbTab.BACKUPS) }
    var version by remember { mutableIntStateOf(0) }
    var restoring by remember { mutableStateOf<Record?>(null) }

    Page("Database", subtitle = "Backups, restore and housekeeping", refresh = { version++ }) {
        RemoteJson("/v1/admin/database/stats", version = version) { s, _ ->
            val dump = s["pg_dump_available"].bool == true
            TileGrid {
                StatTile("Size", s["database_size_human"].str ?: "—", modifier = TileModifier)
                StatTile("pg_dump", if (dump) "available" else "missing", tone = if (dump) StatusTone.SUCCESS else StatusTone.DANGER, modifier = TileModifier)
            }
            s["largest_tables"].records.takeIf { it.isNotEmpty() }?.let { tables ->
                SetupCaption("Largest: " + tables.take(5).joinToString(" · ") { "${it["table"].str ?: ""} ${it["size_human"].str ?: ""}" })
            }
        }
        SegmentedTabs(listOf(DbTab.BACKUPS to "Backups", DbTab.SCHEDULE to "Schedule", DbTab.STORAGE to "Storage", DbTab.CLEAR to "Clear by date"), tab) { tab = it }
        when (tab) {
            DbTab.BACKUPS -> {
                Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    AsyncButton("Back up to local", outlined = true) { val r = env.client.post("/v1/admin/database/backups", J.obj("disk" to J.s("local"))); toastResult(env, r); version++ }
                    AsyncButton("Back up to S3", outlined = true) { val r = env.client.post("/v1/admin/database/backups", J.obj("disk" to J.s("s3"))); toastResult(env, r); version++ }
                }
                RemoteJson("/v1/admin/database/backups", version = version, emptyTitle = "No backups yet", isEmpty = { it["data"].records.isEmpty() }) { j, reload ->
                    j["data"].toRecords().forEach { b ->
                        SupportListRow(b.string("filename") ?: "", subtitle = "${b.string("type")?.humanized ?: ""} · ${b.string("disk") ?: ""} · ${setupBytes(b["size_bytes"].long)} · by ${b.string("created_by_name") ?: b.string("triggered_by") ?: ""} · ${Day.dateTime(b.string("created_at"))}${b.string("error_message")?.let { " · $it" } ?: ""}") {
                            Column(horizontalAlignment = Alignment.End) {
                                StatusBadge(b.status)
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    if (b.status == "success") {
                                        DocumentButton("Get", "/v1/admin/database/backups/${b.id}/download", suggestedName = b.string("filename") ?: "backup.sql")
                                        TextButton(onClick = { restoring = b }) { Text("Restore…") }
                                    }
                                    ConfirmButton("Delete backup", "The file is removed from storage.", destructive = true, action = { try { val r = env.client.delete("/v1/admin/database/backups/${b.id}"); toastResult(env, r); reload() } catch (e: Exception) { env.toasts.error(e) } }) { Text("Delete") }
                                }
                            }
                        }
                    }
                }
            }
            DbTab.SCHEDULE -> BackupScheduleCard()
            DbTab.STORAGE -> StorageSettingsCard()
            DbTab.CLEAR -> ClearByDateCard()
        }
    }
    restoring?.let { b -> RestoreSheet(b, onDismiss = { restoring = null }) { version++ } }
}

/** POST /database/backups/{id}/restore {confirm_phrase, take_safety_backup} */
@Composable
private fun RestoreSheet(backup: Record, onDismiss: () -> Unit, done: () -> Unit) {
    val env = LocalEnv.current
    var phrase by remember { mutableStateOf("") }
    var safety by remember { mutableStateOf(true) }
    FormSheet("Restore ${backup.string("filename") ?: ""}", onDismiss, saveLabel = "Restore", canSave = phrase.isNotEmpty(), destructive = true, save = {
        val r = env.client.post("/v1/admin/database/backups/${backup.id}/restore", J.obj("confirm_phrase" to J.s(phrase), "take_safety_backup" to J.b(safety)))
        toastResult(env, r); done()
    }) { e ->
        SetupSecondary("Everything written since this backup is lost. The server checks the confirmation phrase and asks you to confirm it is you.", Palette.danger)
        Field("Confirmation phrase", phrase, { phrase = it }, error = e?.field("confirm_phrase"))
        ToggleRow("Take a safety backup first", safety, { safety = it })
        Spacer(Modifier.height(Space.xs))
    }
}

/** GET/PUT /database/schedule */
@Composable
private fun BackupScheduleCard() {
    val env = LocalEnv.current
    var enabled by remember { mutableStateOf(false) }
    var frequency by remember { mutableStateOf("daily") }
    var runAt by remember { mutableStateOf("02:00") }
    var dow by remember { mutableStateOf("1") }
    var dom by remember { mutableStateOf("1") }
    var retain by remember { mutableStateOf("7") }
    var disk by remember { mutableStateOf("local") }
    var last by remember { mutableStateOf<JsonElement?>(null) }
    var loaded by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) {
        try {
            val s = env.client.get("/v1/admin/database/schedule").json["schedule"]
            if (!s.isNull) {
                enabled = s["is_enabled"].bool ?: false; frequency = s["frequency"].str ?: "daily"; runAt = (s["run_at"].str ?: "02:00").take(5)
                dow = s["day_of_week"].str ?: "1"; dom = s["day_of_month"].str ?: "1"; retain = s["retain_count"].str ?: "7"; disk = s["disk"].str ?: "local"; last = s
            }
        } catch (e: Exception) { env.toasts.error(e) }
        loaded = true
    }
    SectionCard("Scheduled backups") {
        if (!loaded) { CircularProgressIndicator(); return@SectionCard }
        ToggleRow("Enabled", enabled, { enabled = it })
        OptionPicker("Frequency", frequency, listOf("daily" to "Daily", "weekly" to "Weekly", "monthly" to "Monthly"), { frequency = it })
        Field("Run at (HH:mm)", runAt, { runAt = it })
        if (frequency == "weekly") OptionPicker("Day of week", dow, listOf("Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat").mapIndexed { i, d -> i.toString() to d }, { dow = it })
        if (frequency == "monthly") Field("Day of month", dom, { dom = it })
        Field("Keep (count)", retain, { retain = it })
        OptionPicker("Disk", disk, listOf("local" to "Local", "s3" to "S3"), { disk = it })
        last?.let { l -> l["last_run_status"].str?.let { st -> SetupCaption("Last run ${Day.dateTime(l["last_run_at"].str)}: $st${l["last_run_error"].str?.let { " — $it" } ?: ""}", if (st == "success") Palette.success else Palette.danger) } }
        AsyncButton("Save schedule") {
            val r = env.client.put("/v1/admin/database/schedule", J.obj("is_enabled" to J.b(enabled), "frequency" to J.s(frequency), "run_at" to J.s(runAt), "day_of_week" to (if (frequency == "weekly") J.typed(dow) else JsonNull), "day_of_month" to (if (frequency == "monthly") J.typed(dom) else JsonNull), "retain_count" to J.typed(retain), "disk" to J.s(disk)))
            toastResult(env, r)
        }
    }
}

private val storageKeys = listOf("backup_local_retain_count" to "Local copies to keep", "backup_s3_bucket" to "S3 bucket", "backup_s3_region" to "S3 region", "backup_s3_endpoint" to "S3 endpoint", "backup_s3_key" to "S3 key", "backup_s3_secret" to "S3 secret (masked)", "backup_s3_prefix" to "S3 prefix", "backup_s3_use_path_style" to "Path-style URLs (1/0)", "backup_notify_email" to "Notify email")

/** GET/PUT /database/storage-settings; POST /storage-settings/test {disk} */
@Composable
private fun StorageSettingsCard() {
    val env = LocalEnv.current
    val f = remember { SetupForm() }
    var loaded by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) {
        try { (env.client.get("/v1/admin/database/storage-settings").json["settings"].obj ?: emptyMap()).forEach { (k, v) -> f[k] = v.str ?: "" } } catch (e: Exception) { env.toasts.error(e) }
        loaded = true
    }
    SectionCard("Backup storage") {
        if (!loaded) { CircularProgressIndicator(); return@SectionCard }
        OptionPicker("Destination", f["backup_storage_disk"].ifEmpty { "local" }, listOf("local" to "Local disk", "s3" to "S3-compatible"), { f["backup_storage_disk"] = it })
        storageKeys.forEach { (k, label) -> Field(label, f[k], { f[k] = it }) }
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            AsyncButton("Save") { val r = env.client.put("/v1/admin/database/storage-settings", J.obj(*(storageKeys.map { it.first } + "backup_storage_disk").map { it to J.s(f[it]) }.toTypedArray())); toastResult(env, r) }
            AsyncButton("Test connection", outlined = true) {
                val r = env.client.post("/v1/admin/database/storage-settings/test", J.obj("disk" to J.s(f["backup_storage_disk"].ifEmpty { "local" })))
                env.toasts.show(r.json.message ?: "Tested", if (r.json["ok"].bool == false) StatusTone.DANGER else StatusTone.SUCCESS)
            }
        }
    }
}

/** GET /database/clearable-tables; POST /clear-preview {tables, before_date}; POST /clear {tables, before_date, confirm: true, skip_auto_backup} */
@Composable
private fun ClearByDateCard() {
    val env = LocalEnv.current
    var tables by remember { mutableStateOf<List<Record>>(emptyList()) }
    val chosen = remember { mutableListOf<String>().toMutableStateList() }
    var before by remember { mutableStateOf(Day.add(Day.today(), -365)) }
    var preview by remember { mutableStateOf<JsonElement?>(null) }
    var skipBackup by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { tables = runCatching { env.client.get("/v1/admin/database/clearable-tables").json["data"].toRecords() }.getOrDefault(emptyList()) }
    SectionCard("Clear transactions before a date") {
        SetupSecondary("Removes ledger and business rows older than the date from the chosen tables, after a safety backup. The server asks you to confirm it is you.", Palette.danger)
        DayField("Before", before, { before = it })
        tables.forEach { t ->
            val key = t.string("key") ?: ""
            SetupCheckRow(key in chosen, onChange = { if (it) { if (key !in chosen) chosen.add(key) } else chosen.remove(key) }) {
                Text("${t.string("label") ?: key} · ${t.string("group") ?: ""} · ${t.int("row_count") ?: 0} rows", style = MaterialTheme.typography.bodySmall)
            }
        }
        AsyncButton("Preview", outlined = true, enabled = chosen.isNotEmpty()) { preview = env.client.post("/v1/admin/database/clear-preview", J.obj("tables" to J.strs(chosen.toList()), "before_date" to J.s(before))).json }
        preview?.let { p ->
            SetupCaption("${p["total_rows"].int ?: 0} rows would go: " + (p["preview"].obj ?: emptyMap()).map { "${it.key} ${it.value.int ?: 0}" }.sorted().joinToString(", "), MaterialTheme.hub.textSecondary)
        }
        ToggleRow("Skip the automatic safety backup", skipBackup, { skipBackup = it })
        ConfirmButton("Clear now", "Rows older than $before are deleted from ${chosen.size} table group(s). This cannot be undone without the backup.", destructive = true, enabled = preview != null, action = {
            try {
                val r = env.client.post("/v1/admin/database/clear", J.obj("tables" to J.strs(chosen.toList()), "before_date" to J.s(before), "confirm" to J.b(true), "skip_auto_backup" to J.b(skipBackup)))
                env.toasts.show("${r.json.message ?: "Cleared"} — ${r.json["total_deleted"].int ?: 0} rows"); preview = null
            } catch (e: Exception) { env.toasts.error(e) }
        }) { Text("Clear now") }
    }
}

// ── Downloads ────────────────────────────────────────────────────────────────

private enum class DlTab { WAITING, ALL, APPROVERS }

private fun requesterName(r: Record): String {
    val u = r["user"]
    if (u.isNull) return "user #${r.int("user_id") ?: 0}"
    return "${u["first_name"].str ?: ""} ${u["last_name"].str ?: ""}"
}

/** Download approvals (owner + delegates; the server decides): the queue (approve with step-up, deny with a note), the ledger, approvers. */
@Composable
fun DownloadApprovalsScreen() {
    val env = LocalEnv.current
    var tab by remember { mutableStateOf(DlTab.WAITING) }
    var version by remember { mutableIntStateOf(0) }
    var category by remember { mutableStateOf("") }
    var denying by remember { mutableStateOf<Record?>(null) }

    Page("Download approvals", subtitle = "Every file a staff member takes passes the gate", refresh = { version++ }) {
        RemoteJson("/v1/admin/downloads/capabilities", version = version) { caps, _ ->
            if (caps["can_approve"].bool != true) {
                EmptyState("You are not a download approver", "The owner and the managers he delegates to decide these.")
            } else {
                val enforcing = caps["enforcing"].bool == true
                Row(horizontalArrangement = Arrangement.spacedBy(Space.xs)) { Pill(if (enforcing) "gate enforcing" else "recording only", if (enforcing) StatusTone.SUCCESS else StatusTone.WARNING); Pill("${caps["pending"].int ?: 0} waiting", StatusTone.AMBER) }
                SegmentedTabs(listOf(DlTab.WAITING to "Waiting", DlTab.ALL to "Ledger") + (if (caps["is_owner"].bool == true) listOf(DlTab.APPROVERS to "Approvers") else emptyList()), tab) { tab = it }
                when (tab) {
                    DlTab.WAITING -> RemoteJson("/v1/admin/downloads/requests", query = mapOf("status" to "pending"), version = version, emptyTitle = "Nothing waiting", isEmpty = { it["data"].records.isEmpty() }) { j, reload ->
                        j["data"].toRecords().forEach { r ->
                            SectionCard {
                                Row(verticalAlignment = Alignment.Top) {
                                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                                        Text(r.string("label") ?: r.string("path") ?: "", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                                        SetupCaption("${requesterName(r)} · ${r.string("category")?.humanized ?: ""} · ${r.string("method") ?: ""} ${r.string("path") ?: ""}", MaterialTheme.hub.textSecondary)
                                        r.string("reason")?.let { SetupSecondary("“$it”") }
                                        if (!r["payload"].isNull) Text(prettyJson(r["payload"]), style = MaterialTheme.typography.labelSmall.copy(fontFamily = FontFamily.Monospace), color = MaterialTheme.hub.textTertiary, maxLines = 6)
                                    }
                                    Column(horizontalAlignment = Alignment.End) { StatusBadge(r.status); DateText(r.date("created_at"), DateStyle.RELATIVE) }
                                }
                                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s, Alignment.End)) {
                                    TextButton(onClick = { denying = r }) { Text("Decline") }
                                    ConfirmButton("Approve download", "The person gets a single-use link to exactly this file. You will be asked to confirm it is you.", action = {
                                        try { val x = env.client.post("/v1/admin/downloads/requests/${r.string("uuid") ?: ""}/approve", J.obj()); toastResult(env, x); reload() } catch (e: Exception) { env.toasts.error(e) }
                                    }) { Text("Approve") }
                                }
                            }
                        }
                    }
                    DlTab.ALL -> {
                        OptionPicker("Kind", category, listOf("" to "Every kind", "gated" to "Needs approval", "never_attach" to "Database backups", "exempt" to "Invoices, quotations, receipts", "bulk_contacts" to "Bulk contacts"), { category = it }, allowNone = true)
                        RemoteJson("/v1/admin/downloads/all", query = mapOf("category" to category.ifEmpty { null }), version = version, emptyTitle = "No downloads recorded", isEmpty = { it["data"].records.isEmpty() }) { j, _ ->
                            j["data"].toRecords().forEach { r ->
                                val decider = r["decider"]
                                val decided = if (decider.isNull) "" else " · ${if (r.status == "denied") "declined" else "approved"} by ${decider["first_name"].str ?: ""} ${decider["last_name"].str ?: ""}"
                                SupportListRow(r.string("label") ?: "", subtitle = "${requesterName(r)} · ${r.string("category")?.humanized ?: ""} · ${r.string("downloaded_at")?.let { "taken ${Day.dateTime(it)}" } ?: "not taken"}$decided${r["file_bytes"].long?.let { " · ${setupBytes(it)}" } ?: ""}") {
                                    Column(horizontalAlignment = Alignment.End) {
                                        StatusBadge(r.status)
                                        if (r.string("archive_path") != null) DocumentButton("Archive", "/v1/admin/downloads/${r.string("uuid") ?: ""}/archive", suggestedName = r.string("file_name") ?: "download")
                                    }
                                }
                            }
                            SetupCaption("Page ${j["current_page"].int ?: 1} of ${j["last_page"].int ?: 1} · ${j["total"].int ?: 0}")
                        }
                    }
                    DlTab.APPROVERS -> ApproversPanel()
                }
            }
        }
    }
    denying?.let { r -> DenySheet(r, onDismiss = { denying = null }) { version++ } }
}

/** POST /downloads/requests/{uuid}/deny {note} */
@Composable
private fun DenySheet(request: Record, onDismiss: () -> Unit, done: () -> Unit) {
    val env = LocalEnv.current
    var note by remember { mutableStateOf("") }
    FormSheet("Decline download", onDismiss, saveLabel = "Decline", canSave = note.isNotEmpty(), destructive = true, save = {
        val r = env.client.post("/v1/admin/downloads/requests/${request.string("uuid") ?: ""}/deny", J.obj("note" to J.s(note)))
        toastResult(env, r); done()
    }) { e ->
        SetupSecondary(request.string("label") ?: "")
        Field("Why (the person sees this)", note, { note = it }, error = e?.field("note"), multiline = true)
        Spacer(Modifier.height(Space.xs))
    }
}

/** GET /downloads/approvers; POST {user_id} (step-up); DELETE /approvers/{userId} (step-up) */
@Composable
private fun ApproversPanel() {
    val env = LocalEnv.current
    var version by remember { mutableIntStateOf(0) }
    var adding by remember { mutableStateOf<Int?>(null) }
    RemoteJson("/v1/admin/downloads/approvers", version = version) { j, reload ->
        val o = j["owner"]
        SectionCard("Owner") { SetupSecondary(if (o.isNull) "—" else "${o["first_name"].str ?: ""} ${o["last_name"].str ?: ""} · ${o["email"].str ?: ""}") }
        SectionCard("Delegates") {
            j["delegates"].toRecords().forEach { d ->
                Row(verticalAlignment = Alignment.CenterVertically) {
                    SetupSecondary("${d["user"]["first_name"].str ?: ""} ${d["user"]["last_name"].str ?: ""} · ${d["user"]["email"].str ?: ""}")
                    Spacer(Modifier.weight(1f))
                    SetupIconAction(description = "Remove delegate") { val r = env.client.delete("/v1/admin/downloads/approvers/${d["user"]["id"].int ?: 0}"); toastResult(env, r); reload() }
                }
            }
            StaffPicker("Add a delegate", adding, { adding = it })
            AsyncButton("Add (step-up)", enabled = adding != null) { val r = env.client.post("/v1/admin/downloads/approvers", J.obj("user_id" to J.i(adding))); toastResult(env, r); adding = null; reload() }
        }
    }
}

/**
 * My downloads (GET /downloads/mine): cancel a waiting request; take an approved one
 * (POST /requests/{uuid}/token, then replay the approved request with the token header).
 */
@Composable
fun MyDownloadsScreen() {
    val env = LocalEnv.current
    val context = LocalContext.current
    var version by remember { mutableIntStateOf(0) }

    suspend fun take(r: Record) {
        val t = env.client.post("/v1/admin/downloads/requests/${r.string("uuid") ?: ""}/token").json
        val token = t["token"].str; val header = t["header"].str; var path = t["path"].str
        if (token == null || header == null || path == null) throw ApiError(0, "No download link was issued.")
        if (path.startsWith("/api")) path = path.drop(4)
        val method = (t["method"].str ?: "GET").uppercase()
        val payload = t["payload"]
        val request = if (method == "GET")
            ApiClient.Request(ApiClient.Method.GET, path, query = (payload.obj ?: emptyMap()).mapNotNull { (k, v) -> v.str?.let { k to it } }, headers = mapOf(header to token, "Accept" to "*/*"))
        else ApiClient.Request(ApiClient.Method.POST, path, body = ApiClient.Body.Json(payload ?: JsonObject(emptyMap())), headers = mapOf(header to token, "Accept" to "*/*"))
        val res = env.client.send(request)
        val file = saveDocument(context, res, r.string("file_name") ?: r.string("export_id") ?: "download")
        shareFile(context, file, res.contentType ?: "application/octet-stream")
        version++
    }

    Page("My downloads", subtitle = "Files you asked for and what happened to them", refresh = { version++ }) {
        RemoteJson("/v1/admin/downloads/mine", version = version, emptyTitle = "You have not asked for any downloads", isEmpty = { it["data"].records.isEmpty() }) { j, reload ->
            j["data"].toRecords().forEach { r ->
                SupportListRow(r.string("label") ?: "", subtitle = "${Day.dateTime(r.string("created_at"))} · ${r.string("category")?.humanized ?: ""}${r.string("decision_note")?.let { " · $it" } ?: ""}${r.string("downloaded_at")?.let { " · taken ${Day.dateTime(it)}" } ?: ""}") {
                    Column(horizontalAlignment = Alignment.End) {
                        StatusBadge(r.status)
                        if (r.status == "pending") AsyncTextButton("Cancel") { val x = env.client.post("/v1/admin/downloads/requests/${r.string("uuid") ?: ""}/cancel"); toastResult(env, x); reload() }
                        if (r.status == "approved") AsyncButton("Download") { take(r) }
                    }
                }
            }
        }
    }
}
