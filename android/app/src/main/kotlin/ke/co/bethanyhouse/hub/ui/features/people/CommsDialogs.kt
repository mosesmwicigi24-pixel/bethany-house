package ke.co.bethanyhouse.hub.ui.features.people

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Checkbox
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
import ke.co.bethanyhouse.hub.AppEnvironment
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.AsyncButton
import ke.co.bethanyhouse.hub.ui.components.AsyncTextButton
import ke.co.bethanyhouse.hub.ui.components.Avatar
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.InfoSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.Pill
import ke.co.bethanyhouse.hub.ui.components.RemoteJson
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SupportListRow
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.listRecords
import ke.co.bethanyhouse.hub.ui.components.toastResult
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement

/**
 * Sheets for Messages: new space (also from an audience), new DM, the
 * channel's members and settings, archive, dismiss for order threads, and
 * the quick-replies manager.
 */

internal data class StaffOption(val id: Int, val name: String, val outlet: String?)

/** Everyone a thing can be assigned to (`GET /users/assignable`, open to all staff). */
internal suspend fun fetchStaffOptions(env: AppEnvironment): List<StaffOption> =
    env.client.get("/v1/admin/users/assignable").json.listRecords.mapNotNull { r ->
        val id = r.int("id") ?: return@mapNotNull null
        val name = listOfNotNull(r.string("first_name"), r.string("last_name")).joinToString(" ")
        StaffOption(id, name.ifEmpty { r.string("name") ?: "#$id" }, r["outlet"]["name"].str)
    }

/** POST /channels {name, description, is_private, member_ids}; GET /channels/audiences (attendance.view_team) */
@Composable
internal fun NewSpaceSheet(store: CommsStore, onDismiss: () -> Unit, created: (Int) -> Unit) {
    val env = LocalEnv.current
    var name by remember { mutableStateOf("") }
    var desc by remember { mutableStateOf("") }
    var isPrivate by remember { mutableStateOf(false) }
    var members by remember { mutableStateOf<Set<Int>>(emptySet()) }
    var staff by remember { mutableStateOf<List<StaffOption>>(emptyList()) }
    var audiences by remember { mutableStateOf<List<JsonElement>>(emptyList()) }
    var filter by remember { mutableStateOf("") }
    var createdId by remember { mutableStateOf<Int?>(null) }
    LaunchedEffect(Unit) {
        staff = runCatching { fetchStaffOptions(env) }.getOrDefault(emptyList())
        if (env.session.current.can("attendance.view_team")) audiences = runCatching { env.client.get("/v1/admin/channels/audiences").json["audiences"].records }.getOrDefault(emptyList())
    }
    FormSheet("New space", onDismiss = { val id = createdId; if (id != null) created(id) else onDismiss() }, saveLabel = "Create", canSave = name.isNotBlank(), save = {
        val r = env.client.post("/v1/admin/channels", J.obj("name" to J.s(name), "description" to J.sOpt(desc), "is_private" to J.b(isPrivate), "member_ids" to J.ints(members.toList())))
        r.json["channel"]?.let { val ch = ChatChannel(it); store.upsert(ch); createdId = ch.id }
        env.toasts.show("Space created")
    }) { e ->
        Field("Name", name, { name = it }, placeholder = "Front counter", error = e?.field("name"))
        Field("Description", desc, { desc = it })
        ToggleRow("Private (invite only)", isPrivate, { isPrivate = it })
        if (audiences.isNotEmpty()) FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.xs), verticalArrangement = Arrangement.spacedBy(Space.xs)) {
            Text("Add everyone", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, modifier = Modifier.padding(top = 10.dp))
            audiences.forEach { a -> OutlinedButton(onClick = { members = members + a["people"].records.mapNotNull { it["id"].int } }) { Text(a["label"].str ?: "") } }
        }
        MemberChooser(staff, members, { members = it }, filter, { filter = it })
    }
}

@Composable
private fun MemberChooser(staff: List<StaffOption>, selected: Set<Int>, onChange: (Set<Int>) -> Unit, filter: String, onFilter: (String) -> Unit, exclude: Set<Int> = emptySet()) {
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text("Members (${selected.size})", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
        ModuleSearchField(filter, onFilter, "Filter people", onSubmit = {})
        Column(Modifier.heightIn(max = 220.dp).verticalScroll(rememberScrollState())) {
            staff.filter { !exclude.contains(it.id) && (filter.isEmpty() || it.name.contains(filter, ignoreCase = true)) }.forEach { s ->
                val on = selected.contains(s.id)
                Row(Modifier.fillMaxWidth().clickable { onChange(if (on) selected - s.id else selected + s.id) }, verticalAlignment = Alignment.CenterVertically) {
                    Checkbox(checked = on, onCheckedChange = { onChange(if (it) selected + s.id else selected - s.id) })
                    Text(s.name, style = MaterialTheme.typography.bodySmall)
                    s.outlet?.let { Text("  $it", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
                }
            }
        }
    }
}

/** POST /channels/dm {user_id} */
@Composable
internal fun NewDMSheet(store: CommsStore, onDismiss: () -> Unit, open: (Int) -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var staff by remember { mutableStateOf<List<StaffOption>>(emptyList()) }
    var filter by remember { mutableStateOf("") }
    LaunchedEffect(Unit) { staff = runCatching { fetchStaffOptions(env) }.getOrDefault(emptyList()) }
    InfoSheet("Message a colleague", onDismiss) {
        ModuleSearchField(filter, { filter = it }, "Find a person", onSubmit = {})
        staff.filter { it.id != env.session.current.me?.id && (filter.isEmpty() || it.name.contains(filter, ignoreCase = true)) }.forEach { s ->
            Row(Modifier.fillMaxWidth().clickable {
                scope.launch {
                    try {
                        val r = env.client.post("/v1/admin/channels/dm", J.obj("user_id" to J.i(s.id)))
                        r.json["channel"]?.let { val ch = ChatChannel(it); store.upsert(ch); open(ch.id) } ?: onDismiss()
                    } catch (e: Exception) { env.toasts.error(e) }
                }
            }.padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                Avatar(s.name, 28.dp)
                Text(s.name, modifier = Modifier.weight(1f))
                s.outlet?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
            }
        }
    }
}

// ── Details (GET /channels/{id}; PATCH; members; DELETE; dismiss) ────────────

@Composable
internal fun ChannelDetailsSheet(channel: ChatChannel, onDismiss: () -> Unit, onChanged: () -> Unit, onRemoved: () -> Unit) {
    val env = LocalEnv.current
    var detail by remember { mutableStateOf<ChatChannel?>(null) }
    var name by remember { mutableStateOf(channel.name) }
    var desc by remember { mutableStateOf(channel.description ?: "") }
    var adding by remember { mutableStateOf(false) }
    var version by remember { mutableIntStateOf(0) }
    LaunchedEffect(version) {
        runCatching { env.client.get("/v1/admin/channels/${channel.id}").json["channel"] }.getOrNull()?.let { val ch = ChatChannel(it); detail = ch; name = ch.name; desc = ch.description ?: "" }
    }
    val ch = detail ?: channel
    InfoSheet("Details", onDismiss) {
        if (ch.isDM) SectionCard { Text("Direct message with ${ch.name}", style = MaterialTheme.typography.bodySmall) }
        else {
            SectionCard("About") {
                Field("Name", name, { name = it })
                Field("Description", desc, { desc = it }, multiline = true)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                    AsyncButton("Save") {
                        val r = env.client.patch("/v1/admin/channels/${ch.id}", J.obj("name" to J.s(name), "description" to J.s(desc)))
                        r.json["channel"]?.let { detail = ChatChannel(it) }
                        env.toasts.show("Saved"); onChanged()
                    }
                    if (!ch.isContext) AsyncButton(if (ch.isPrivate) "Make public" else "Make private", outlined = true) {
                        val r = env.client.patch("/v1/admin/channels/${ch.id}", J.obj("is_private" to J.b(!ch.isPrivate)))
                        r.json["channel"]?.let { detail = ChatChannel(it) }
                        onChanged()
                    }
                }
            }
            SectionCard("Members (${ch.members.size})") {
                ch.members.forEach { m ->
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                        Avatar(m["name"].str ?: "?", 24.dp)
                        Text(m["name"].str ?: "", style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f))
                        if (m["role"].str == "admin") Pill("admin", StatusTone.INFO)
                        val uid = m["id"].int
                        if (uid != null && uid != env.session.current.me?.id) AsyncTextButton("Remove", color = Palette.danger) {
                            val r = env.client.delete("/v1/admin/channels/${ch.id}/members/$uid")
                            toastResult(env, r, "Removed"); version += 1; onChanged()
                        }
                    }
                }
                OutlinedButton(onClick = { adding = true }) { Text("Add members") }
            }
            if (ch.isContext) SectionCard("Thread") {
                ch.contextRoute?.let { r -> TextButton(onClick = { env.navigator.go(r) }) { Text("Open the record") } }
                AsyncButton("Hide from my list", outlined = true) {
                    env.client.post("/v1/admin/channels/${ch.id}/dismiss")
                    env.toasts.show("Hidden until the next message"); onRemoved()
                }
            } else ConfirmButton("Archive space", "The conversation is closed for everyone. Messages are kept.", destructive = true, action = {
                try { env.client.delete("/v1/admin/channels/${ch.id}"); env.toasts.show("Space archived"); onRemoved() } catch (e: Exception) { env.toasts.error(e) }
            }) { Text("Archive space") }
        }
    }
    if (adding) AddMembersSheet(ch, onDismiss = { adding = false }) { version += 1; onChanged() }
}

/** POST /channels/{id}/members {user_id} — one call per person, as the console does. */
@Composable
private fun AddMembersSheet(channel: ChatChannel, onDismiss: () -> Unit, done: () -> Unit) {
    val env = LocalEnv.current
    var staff by remember { mutableStateOf<List<StaffOption>>(emptyList()) }
    var selected by remember { mutableStateOf<Set<Int>>(emptySet()) }
    var filter by remember { mutableStateOf("") }
    LaunchedEffect(Unit) { staff = runCatching { fetchStaffOptions(env) }.getOrDefault(emptyList()) }
    FormSheet("Add members", onDismiss, saveLabel = "Add ${selected.size}", canSave = selected.isNotEmpty(), save = {
        selected.forEach { uid -> env.client.post("/v1/admin/channels/${channel.id}/members", J.obj("user_id" to J.i(uid))) }
        env.toasts.show("Added ${selected.size}")
        done()
    }) { _ ->
        MemberChooser(staff, selected, { selected = it }, filter, { filter = it }, exclude = channel.members.mapNotNull { it["id"].int }.toSet())
    }
}

// ── Quick replies (GET/POST/PATCH/DELETE /channels/quick-replies) ───────────

@Composable
internal fun QuickRepliesSheet(onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var version by remember { mutableIntStateOf(0) }
    var editingId by remember { mutableStateOf<Int?>(null) }
    var title by remember { mutableStateOf("") }
    var body by remember { mutableStateOf("") }
    InfoSheet("Quick replies", onDismiss) {
        Text("Saved messages everyone can insert. {order}, {customer} and {name} are filled in when used.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
        RemoteJson("/v1/admin/channels/quick-replies", version = version, emptyTitle = "None yet", isEmpty = { it["quick_replies"].records.isEmpty() }) { j, reload ->
            j["quick_replies"].toRecords().forEach { q ->
                SupportListRow(q.string("title") ?: "", q.string("body")) {
                    Column(horizontalAlignment = Alignment.End) {
                        TextButton(onClick = { editingId = q.int("id"); title = q.string("title") ?: ""; body = q.string("body") ?: "" }) { Text("Edit") }
                        AsyncTextButton("Delete", color = Palette.danger) { val r = env.client.delete("/v1/admin/channels/quick-replies/${q.id}"); toastResult(env, r, "Deleted"); reload() }
                    }
                }
            }
        }
        Text(if (editingId == null) "New quick reply" else "Edit quick reply", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
        Field("Title", title, { title = it })
        Field("Message", body, { body = it }, multiline = true)
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
            if (editingId != null) TextButton(onClick = { editingId = null; title = ""; body = "" }) { Text("Cancel edit") }
            AsyncButton(if (editingId == null) "Save" else "Update", enabled = title.isNotEmpty() && body.isNotEmpty()) {
                val payload = J.obj("title" to J.s(title), "body" to J.s(body))
                val id = editingId
                if (id != null) env.client.patch("/v1/admin/channels/quick-replies/$id", payload) else env.client.post("/v1/admin/channels/quick-replies", payload)
                editingId = null; title = ""; body = ""; version += 1
            }
        }
    }
}
