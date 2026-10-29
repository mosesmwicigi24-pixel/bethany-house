package ke.co.bethanyhouse.hub.ui.features.me

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.CropSquare
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.HubJson
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.date
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.obj
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.TileModifier
import ke.co.bethanyhouse.hub.ui.components.listRecords
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive

/**
 * /forms — a staff member's checklists and reports (FormsPage.tsx): what is
 * due today at my outlet, done or missed, and the report forms to send any
 * time. Filling one is a sheet: ticks, numbers, text, photos, a signature.
 */
@Composable
internal fun FormsScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val c = env.client
    val today = remember { Resource(scope) { c.get("/v1/admin/forms/today").json ?: JsonNull } }
    val mine = remember { Resource(scope) { c.get("/v1/admin/forms/mine", background = true).json.listRecords } }
    var filling by remember { mutableStateOf<Record?>(null) }
    LaunchedEffect(Unit) { today.load(); mine.load() }

    Page("Checklists & forms", subtitle = today.value["date"].str?.let { MeFormat.longDay(MeFormat.date(it)) }, refresh = { today.reload(); mine.reload() }) {
        if (today.value["can_manage"].bool == true) Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) {
            TextButton(onClick = { env.navigator.go(Route.FormsManage) }) { Text("Manager view ›") }
        }
        LoadableView(today.state, retry = { today.load() }) { d ->
            val rows = d.listRecords
            val checks = rows.filter { it["template"]["kind"].str == "checklist" }
            val reports = rows.filter { it["template"]["kind"].str == "report" }
            MeSectionLabel("Today's checklists")
            if (checks.isEmpty()) Text("No checklist due today.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
            checks.forEach { r -> ChecklistRow(r) { filling = r } }
            MeSectionLabel("Report something")
            if (reports.isEmpty()) Text("No report forms for your outlet.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
            TileGrid(minWidth = 160.dp) {
                reports.forEach { r ->
                    Surface(shape = MaterialTheme.shapes.large, color = MaterialTheme.hub.card, modifier = TileModifier.border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.large).clickable { filling = r }) {
                        Column(Modifier.padding(Space.l), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Text(r["template"]["name"].str ?: "", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                            val n = r["reports_today"].int ?: 0
                            Text("${r["outlet"]["name"].str ?: ""}${if (n > 0) " · $n today" else ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        }
                    }
                }
            }
        }
        val mineRows = mine.value
        if (!mineRows.isNullOrEmpty()) {
            MeSectionLabel("Yours lately")
            MeListCard {
                mineRows.take(8).forEach { s ->
                    MeListRow {
                        Text(s["template"]["name"].str ?: "", style = MaterialTheme.typography.bodySmall)
                        Text("· ${s.string("for_date") ?: ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary, modifier = Modifier.weight(1f))
                        val skipped = s.int("skipped_required") ?: 0
                        StatusBadge("${stateLabel(s.status)}${if (skipped > 0) " · $skipped skipped" else ""}", when (s.status) { "late" -> StatusTone.WARNING; "missed" -> StatusTone.DANGER; else -> StatusTone.SUCCESS })
                    }
                }
            }
        }
    }
    filling?.let { row -> FillFormSheet(row, onDismiss = { filling = null }, onDone = { filling = null; today.load(); mine.load() }) }
}

private fun stateLabel(s: String?): String = when (s) { "due" -> "Due"; "overdue" -> "Overdue"; "submitted" -> "Done"; "late" -> "Done late"; "missed" -> "Missed"; else -> "" }

@Composable
private fun ChecklistRow(r: Record, open: () -> Unit) {
    val state = r.string("state") ?: ""
    val done = state == "submitted" || state == "late"
    val bad = state == "missed" || state == "overdue"
    val sub = r["submission"].obj
    val skipped = sub["skipped_required"].int ?: 0
    val detail = buildString {
        append(r["outlet"]["name"].str ?: "")
        r["due_at"].date?.let { append(" · due ${MeFormat.time(it)}") }
        if (sub != null) append(" · ${if (state == "late") "done late" else "done"} by ${sub["by"].str ?: "—"} at ${MeFormat.time(sub["at"].date)}")
        else if (state == "missed") append(" · missed — do it now") else if (state == "overdue") append(" · overdue")
        if (skipped > 0) append(" · $skipped skipped")
    }
    Surface(
        shape = MaterialTheme.shapes.large,
        color = if (done) Palette.successSoft.copy(alpha = 0.4f) else if (bad) Palette.dangerSoft.copy(alpha = 0.3f) else MaterialTheme.hub.card,
        modifier = Modifier.fillMaxWidth().border(1.dp, if (done) Palette.success.copy(alpha = 0.3f) else if (bad) Palette.danger.copy(alpha = 0.3f) else MaterialTheme.hub.line, MaterialTheme.shapes.large).clickable(enabled = !done, onClick = open),
    ) {
        Row(Modifier.padding(Space.l), horizontalArrangement = Arrangement.spacedBy(Space.m), verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(36.dp).background(if (done) Palette.success else Palette.neutralSoft, CircleShape), contentAlignment = Alignment.Center) {
                Icon(if (done) Icons.Default.Check else Icons.Default.CropSquare, contentDescription = null, tint = if (done) Color.White else MaterialTheme.hub.textSecondary, modifier = Modifier.size(18.dp))
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(r["template"]["name"].str ?: "", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                Text(detail, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            }
            if (!done) Text("Fill in ›", style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold, color = Palette.brandDeep)
        }
    }
}

// ── The form renderer (FormTemplate::ITEM_TYPES: check, text, number, photo; requires_signature) ──

/**
 * Posts `multipart: template_id, outlet_id, answers (JSON), notes?,
 * signature (PNG data URL)?, photo_<key> files` exactly as FormsController::submit validates.
 */
@Composable
private fun FillFormSheet(row: Record, onDismiss: () -> Unit, onDone: () -> Unit) {
    val env = LocalEnv.current
    val checks = remember { mutableStateMapOf<String, Boolean>() }
    val texts = remember { mutableStateMapOf<String, String>() }
    val photos = remember { mutableStateMapOf<String, MePickedPhoto>() }
    var notes by remember { mutableStateOf("") }
    var signature by remember { mutableStateOf<String?>(null) }
    val template = row["template"]
    val items = template["items"].records
    val requiresSignature = template["requires_signature"].bool ?: false
    val isReport = template["kind"].str == "report"
    val missing = items.count { i ->
        val key = i["key"].str
        if (i["required"].bool != true || key == null) false else when (i["type"].str) {
            "photo" -> photos[key] == null
            "check" -> checks[key] != true
            else -> (texts[key] ?: "").isBlank()
        }
    }

    suspend fun submit() {
        val templateId = template["id"].int ?: throw ApiError(422, "This form has no template.")
        val outletId = row["outlet"]["id"].int ?: throw ApiError(422, "This form has no outlet.")
        val answers = mutableMapOf<String, JsonElement>()
        items.forEach { i ->
            val key = i["key"].str ?: return@forEach
            when (i["type"].str) {
                "check" -> answers[key] = JsonPrimitive(checks[key] ?: false)
                "photo" -> {}
                else -> texts[key]?.takeIf { it.isNotEmpty() }?.let { answers[key] = JsonPrimitive(it) }
            }
        }
        val parts = mutableListOf(
            ApiClient.Part.field("template_id", templateId.toString()),
            ApiClient.Part.field("outlet_id", outletId.toString()),
            ApiClient.Part.field("answers", HubJson.json.encodeToString(JsonElement.serializer(), JsonObject(answers))),
        )
        notes.trim().takeIf { it.isNotEmpty() }?.let { parts += ApiClient.Part.field("notes", it) }
        signature?.let { parts += ApiClient.Part.field("signature", it) }
        photos.forEach { (key, p) -> parts += ApiClient.Part.file("photo_$key", p.data, p.filename, p.mimeType) }
        val r = env.client.upload("/v1/admin/forms/submissions", parts)
        env.toasts.show(r.json["message"].str ?: if (isReport) "Sent" else "Done")
        onDone()
    }

    MeSheet(template["name"].str ?: "Form", primary = if (isReport) "Send" else "Done", onDismiss = onDismiss, disabled = requiresSignature && signature == null, action = { submit() }) {
        Text("${row["outlet"]["name"].str ?: ""}${row["due_at"].date?.let { " · due ${MeFormat.time(it)}" } ?: ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
        items.forEach { i ->
            val key = i["key"].str ?: return@forEach
            val required = i["required"].bool ?: false
            val label = i["label"].str ?: key
            Column(Modifier.fillMaxWidth().border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.small).padding(Space.m), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                when (i["type"].str) {
                    "check" -> Row(Modifier.fillMaxWidth().clickable { checks[key] = !(checks[key] ?: false) }, verticalAlignment = Alignment.CenterVertically) {
                        ItemLabel(label, required, Modifier.weight(1f))
                        Switch(checked = checks[key] ?: false, onCheckedChange = { checks[key] = it })
                    }
                    "number" -> { ItemLabel(label, required); Field("", texts[key] ?: "", { texts[key] = it }, keyboard = KeyboardType.Decimal) }
                    "photo" -> { ItemLabel(label, required); MePhotoPickerField("Take or choose a photo", photos[key]) { p -> if (p == null) photos.remove(key) else photos[key] = p } }
                    else -> { ItemLabel(label, required); OutlinedTextField(value = texts[key] ?: "", onValueChange = { texts[key] = it }, minLines = 3, modifier = Modifier.fillMaxWidth()) }
                }
            }
        }
        MeFieldLabel("Notes (optional)") { OutlinedTextField(value = notes, onValueChange = { notes = it }, minLines = 2, modifier = Modifier.fillMaxWidth()) }
        if (requiresSignature) Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
            ItemLabel("Signature", true)
            SignaturePad(signature, { signature = it })
        }
        Text(
            if (missing > 0) "${MeFormat.plural(missing, "required item")} blank — your manager will see that." else "All required items done.",
            style = MaterialTheme.typography.labelSmall, color = if (missing > 0) Palette.warning else Palette.success,
        )
    }
}

@Composable
private fun ItemLabel(label: String, required: Boolean, modifier: Modifier = Modifier) {
    Row(modifier, horizontalArrangement = Arrangement.spacedBy(2.dp)) {
        Text(label, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Medium)
        if (required) Text("*", color = Palette.danger)
    }
}
