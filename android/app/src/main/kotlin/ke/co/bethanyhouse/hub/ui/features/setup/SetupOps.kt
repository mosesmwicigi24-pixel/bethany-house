package ke.co.bethanyhouse.hub.ui.features.setup

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowDownward
import androidx.compose.material.icons.filled.ArrowUpward
import androidx.compose.material3.Button
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
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
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.obj
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.AsyncButton
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.DocumentButton
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.InfoSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.KeyValueGrid
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.OutletChoice
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.PagedList
import ke.co.bethanyhouse.hub.ui.components.Pill
import ke.co.bethanyhouse.hub.ui.components.RemoteJson
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.StaffPicker
import ke.co.bethanyhouse.hub.ui.components.SupportListRow
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.listRecords
import ke.co.bethanyhouse.hub.ui.components.prettyJson
import ke.co.bethanyhouse.hub.ui.components.toastResult
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.serialization.json.JsonElement

// Production configuration (stages, auto-assignees), the activity log and the trash.

// ── Production stages (GET /product-stages (array); POST; PUT; DELETE; PUT /reorder {stages: [ids]}) ──

@Composable
fun ProductionStagesScreen() {
    val env = LocalEnv.current
    var version by remember { mutableIntStateOf(0) }
    var editing by remember { mutableStateOf<Record?>(null) }
    var adding by remember { mutableStateOf(false) }
    var order by remember { mutableStateOf<List<Record>>(emptyList()) }

    Page("Production stages", subtitle = "The pipeline every production order moves through, in order", refresh = { order = emptyList(); version++ }, actions = {
        Button(onClick = { adding = true }) { Text("New stage") }
    }) {
        RemoteJson("/v1/admin/product-stages", version = version) { j, reload ->
            val rows = order.ifEmpty { j.listRecords }
            rows.forEachIndexed { i, s ->
                Surface(shape = MaterialTheme.shapes.small, color = MaterialTheme.hub.card, modifier = Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(Space.m), verticalArrangement = Arrangement.spacedBy(Space.xs)) {
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                            Box(Modifier.size(12.dp).background(setupHexColor(s.string("color")) ?: Palette.neutral, CircleShape))
                            Column(Modifier.weight(1f)) {
                                Text("${i + 1}. ${s.string("name") ?: ""}", style = MaterialTheme.typography.bodyMedium)
                                s.string("description")?.takeIf { it.isNotEmpty() }?.let { SetupCaption(it, MaterialTheme.hub.textSecondary) }
                            }
                            if (s.bool("is_active") == false) Pill("inactive")
                        }
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            IconButton(enabled = i > 0, onClick = { order = rows.toMutableList().apply { add(i - 1, removeAt(i)) } }) { Icon(Icons.Default.ArrowUpward, contentDescription = "Move up") }
                            IconButton(enabled = i < rows.lastIndex, onClick = { order = rows.toMutableList().apply { add(i + 1, removeAt(i)) } }) { Icon(Icons.Default.ArrowDownward, contentDescription = "Move down") }
                            TextButton(onClick = { editing = s }) { Text("Edit") }
                            Spacer(Modifier.weight(1f))
                            ConfirmButton("Delete stage", "A stage in use by active tasks cannot be deleted.", destructive = true, action = {
                                try { val r = env.client.delete("/v1/admin/product-stages/${s.id}"); toastResult(env, r); order = emptyList(); reload() } catch (e: Exception) { env.toasts.error(e) }
                            }) { Text("Delete") }
                        }
                    }
                }
            }
            if (order.isNotEmpty()) Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                OutlinedButton(onClick = { order = emptyList() }) { Text("Discard order") }
                AsyncButton("Save order") {
                    val r = env.client.put("/v1/admin/product-stages/reorder", J.obj("stages" to J.ints(order.mapNotNull { it.int("id") })))
                    toastResult(env, r); order = emptyList(); reload()
                }
            }
        }
    }
    if (adding) StageSheet(null, onDismiss = { adding = false }) { order = emptyList(); version++ }
    editing?.let { s -> StageSheet(s, onDismiss = { editing = null }) { order = emptyList(); version++ } }
}

private val stageColours = listOf("#757d72" to "Slate", "#3b82f6" to "Blue", "#8b5cf6" to "Violet", "#ec4899" to "Pink", "#f59e0b" to "Amber", "#10b981" to "Green", "#ef4444" to "Red", "#06b6d4" to "Cyan")

@Composable
private fun StageSheet(record: Record?, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    var name by remember { mutableStateOf(record?.string("name") ?: "") }
    var description by remember { mutableStateOf(record?.string("description") ?: "") }
    var color by remember { mutableStateOf(record?.string("color") ?: "#3b82f6") }
    var active by remember { mutableStateOf(record?.bool("is_active") ?: true) }
    FormSheet(if (record == null) "New stage" else "Edit stage", onDismiss, canSave = name.isNotEmpty(), save = {
        val pairs = mutableListOf<Pair<String, JsonElement?>>("name" to J.s(name), "description" to J.s(description), "color" to J.s(color))
        val r = if (record != null) { pairs += "is_active" to J.b(active); env.client.put("/v1/admin/product-stages/${record.id}", J.obj(*pairs.toTypedArray())) } else env.client.post("/v1/admin/product-stages", J.obj(*pairs.toTypedArray()))
        toastResult(env, r); saved()
    }) { e ->
        Field("Name", name, { name = it }, error = e?.field("name"))
        Field("Description", description, { description = it }, multiline = true)
        OptionPicker("Colour", color, stageColours, { color = it })
        if (record != null) ToggleRow("Active", active, { active = it })
        Spacer(Modifier.height(Space.xs))
    }
}

// ── Auto-assignees (GET /production/auto-assignees (array); POST {user_id, role_in_order, outlet_id}; DELETE) ──

@Composable
fun AutoAssigneesScreen() {
    val env = LocalEnv.current
    var version by remember { mutableIntStateOf(0) }
    var adding by remember { mutableStateOf(false) }
    Page("Auto-assignees", subtitle = "People added to every new production order automatically", refresh = { version++ }, actions = {
        Button(onClick = { adding = true }) { Text("Add rule") }
    }) {
        RemoteJson("/v1/admin/production/auto-assignees", version = version, emptyTitle = "No rules yet", isEmpty = { it.listRecords.isEmpty() }) { j, reload ->
            j.listRecords.forEach { r ->
                SupportListRow("${r["user"]["first_name"].str ?: ""} ${r["user"]["last_name"].str ?: ""}", subtitle = "${r.string("role_in_order")?.humanized ?: "observer"} · ${r["outlet"]["name"].str ?: "every outlet"}") {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        if (r.bool("is_active") == false) Pill("inactive")
                        SetupIconAction(description = "Remove") { val x = env.client.delete("/v1/admin/production/auto-assignees/${r.id}"); toastResult(env, x); reload() }
                    }
                }
            }
        }
    }
    if (adding) {
        var user by remember { mutableStateOf<Int?>(null) }
        var role by remember { mutableStateOf("observer") }
        var outlet by remember { mutableStateOf<Int?>(null) }
        FormSheet("Auto-assignee rule", onDismiss = { adding = false }, canSave = user != null, save = {
            val r = env.client.post("/v1/admin/production/auto-assignees", J.obj("user_id" to J.i(user), "role_in_order" to J.s(role), "outlet_id" to J.i(outlet)))
            toastResult(env, r); version++
        }) { e ->
            StaffPicker("Person", user, { user = it })
            Field("Role in the order", role, { role = it }, placeholder = "observer, supervisor, qc…", error = e?.field("role_in_order"))
            OutletChoice("Only for outlet", outlet, { outlet = it }, allowAll = true)
            Spacer(Modifier.height(Space.xs))
        }
    }
}

// ── Activity logs (super admin): GET /activity-logs (paginated), /requests, /integrity, /export (step-up), /{id} ──

private enum class LogTab { CHANGES, REQUESTS, INTEGRITY }

@Composable
fun ActivityLogsScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var tab by remember { mutableStateOf(LogTab.CHANGES) }
    var search by remember { mutableStateOf("") }
    var action by remember { mutableStateOf("") }
    var from by remember { mutableStateOf("") }
    var to by remember { mutableStateOf("") }
    var open by remember { mutableStateOf<Record?>(null) }
    val list = remember { PagedList(scope, env.client, "/v1/admin/activity-logs", map = ::Record) }
    val requests = remember { PagedList(scope, env.client, "/v1/admin/activity-logs/requests", map = ::Record) }
    fun filters(): Map<String, String?> = mapOf("search" to search.ifEmpty { null }, "action" to action.ifEmpty { null }, "start_date" to from.ifEmpty { null }, "end_date" to to.ifEmpty { null })
    fun apply() { list.query = filters().toMutableMap(); list.load() }
    LaunchedEffect(Unit) { apply(); requests.load() }

    Page("Activity logs", subtitle = "The permanent record of every change and every staff API call", refresh = { list.reload(); requests.reload() }) {
        SegmentedTabs(listOf(LogTab.CHANGES to "Changes", LogTab.REQUESTS to "Requests", LogTab.INTEGRITY to "Integrity"), tab) { tab = it }
        DocumentButton("Export (step-up)", "/v1/admin/activity-logs/export", filters(), "activity-logs.json")
        when (tab) {
            LogTab.CHANGES -> {
                ModuleSearchField(search, { search = it }, "Search description, action or person", onSubmit = ::apply)
                Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    Field("Action", action, { action = it }, Modifier.weight(1f))
                    Field("From (yyyy-mm-dd)", from, { from = it }, Modifier.weight(1f))
                }
                Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                    Field("To", to, { to = it }, Modifier.weight(1f))
                    OutlinedButton(onClick = ::apply) { Text("Apply") }
                }
                LoadableView(list.state, retry = { list.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("No entries") }) { rows ->
                    Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                        rows.forEachIndexed { i, l ->
                            val subject = l.string("subject_type")?.let { " · ${it.substringAfterLast('\\')} #${l.int("subject_id") ?: 0}" } ?: ""
                            SupportListRow(l.string("description") ?: l.string("action") ?: "", subtitle = "${l.string("user_name") ?: "System"} · ${l.string("action") ?: ""}$subject · ${l.string("ip_address") ?: ""}", onClick = { open = l }) { DateText(l.date("created_at")) }
                            if (i == rows.lastIndex && list.hasMore) LaunchedEffect(rows.size) { list.loadMore() }
                        }
                        SetupCaption("${list.total} entries")
                    }
                }
            }
            LogTab.REQUESTS -> LoadableView(requests.state, retry = { requests.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("No requests logged") }) { rows ->
                Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                    rows.forEachIndexed { i, r ->
                        SupportListRow("${r.string("method") ?: ""} ${r.string("path") ?: ""}", subtitle = "${r.string("user_name") ?: "—"} · ${r.int("status") ?: 0} · ${r.int("duration_ms") ?: 0} ms · ${r.int("rows_returned")?.let { "$it rows · " } ?: ""}${r.string("ip_address") ?: ""}") { DateText(r.date("occurred_at")) }
                        if (i == rows.lastIndex && requests.hasMore) LaunchedEffect(rows.size) { requests.loadMore() }
                    }
                }
            }
            LogTab.INTEGRITY -> RemoteJson("/v1/admin/activity-logs/integrity") { j, _ ->
                val c = j["last_check"]
                if (!c.isNull) SectionCard("Last verification") {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                        Pill(if (c["ok"].bool == true) "ok" else "problem", if (c["ok"].bool == true) StatusTone.SUCCESS else StatusTone.DANGER)
                        SetupSecondary(Day.dateTime(c["checked_at"].str))
                    }
                    Text(prettyJson(c["details"]), style = MaterialTheme.typography.bodySmall.copy(fontFamily = FontFamily.Monospace), color = MaterialTheme.hub.textSecondary)
                }
                (j["tables"].obj ?: emptyMap()).keys.sorted().forEach { t ->
                    val info = j["tables"][t]
                    SectionCard(t) {
                        val seal = info["last_seal"]
                        if (!seal.isNull) KeyValueGrid(listOf("Sealed to id" to (seal["last_id"].str ?: "—"), "Rows" to (seal["row_count"].str ?: "—"), "Hash" to (seal["hash"].str ?: "—"), "Sealed at" to Day.dateTime(seal["sealed_at"].str), "Unsealed rows" to (info["unsealed_rows"].str ?: "0")))
                        else SetupSecondary("Never sealed · ${info["unsealed_rows"].int ?: 0} rows")
                    }
                }
            }
        }
    }
    open?.let { l ->
        InfoSheet(l.string("description") ?: "Entry", onDismiss = { open = null }) {
            KeyValueGrid(listOf("By" to "${l.string("user_name") ?: "System"} ${l.string("user_email") ?: ""}", "Action" to (l.string("action") ?: ""), "Subject" to "${l.string("subject_type") ?: ""} #${l.int("subject_id") ?: 0}", "IP" to (l.string("ip_address") ?: "—"), "Request" to (l.string("request_id") ?: "—"), "When" to Day.dateTime(l.string("created_at"))))
            androidx.compose.foundation.text.selection.SelectionContainer { Text(l["properties"].str ?: prettyJson(l["properties"]), style = MaterialTheme.typography.bodySmall.copy(fontFamily = FontFamily.Monospace)) }
        }
    }
}

// ── Trash (GET /trash summary; /trash/{model}?search (data + meta); restore; restore-all; force delete; empty) ──

private val trashModels = listOf("products" to "Products", "categories" to "Categories", "users" to "Users", "customers" to "Customers")

@Composable
fun TrashScreen() {
    val env = LocalEnv.current
    var model by remember { mutableStateOf("products") }
    var search by remember { mutableStateOf("") }
    var applied by remember { mutableStateOf("") }
    var version by remember { mutableIntStateOf(0) }
    val singular = model.dropLast(1)

    Page("Trash", subtitle = "Deleted records that can still be restored", refresh = { version++ }) {
        RemoteJson("/v1/admin/trash", version = version) { s, _ ->
            SegmentedTabs(trashModels.map { (k, label) -> k to "$label (${s["summary"][k].int ?: 0})" }, model) { model = it }
        }
        ModuleSearchField(search, { search = it }, "Search", onSubmit = { applied = search })
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            ConfirmButton("Restore all", "Every deleted $singular comes back.", action = { try { val r = env.client.post("/v1/admin/trash/$model/restore-all"); toastResult(env, r); version++ } catch (e: Exception) { env.toasts.error(e) } }) { Text("Restore all") }
            ConfirmButton("Empty trash", "Every deleted $singular is gone for good.", destructive = true, action = { try { val r = env.client.delete("/v1/admin/trash/$model/empty"); toastResult(env, r); version++ } catch (e: Exception) { env.toasts.error(e) } }) { Text("Empty") }
        }
        RemoteJson("/v1/admin/trash/$model", query = mapOf("search" to applied.ifEmpty { null }, "per_page" to "50"), version = version, emptyTitle = "Nothing in the trash", isEmpty = { it["data"].records.isEmpty() }) { j, reload ->
            j["data"].toRecords().forEach { item ->
                SupportListRow(trashName(model, item), subtitle = "${trashMeta(model, item)} · deleted ${Day.dateTime(item.string("deleted_at"))}") {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        AsyncButton("Restore", outlined = true) { val r = env.client.post("/v1/admin/trash/$model/${item.id}/restore"); toastResult(env, r); reload(); version++ }
                        SetupIconAction(description = "Delete forever") { val r = env.client.delete("/v1/admin/trash/$model/${item.id}"); toastResult(env, r); reload(); version++ }
                    }
                }
            }
            SetupCaption("${j["meta"]["total"].int ?: 0} in the trash")
        }
    }
}

private fun trashName(model: String, i: Record): String = when (model) {
    "products" -> i["translations"].records.firstOrNull()["name"].str ?: i.string("sku") ?: "Product"
    "categories" -> i.string("name_en") ?: i.string("slug") ?: "Category"
    else -> "${i.string("first_name") ?: ""} ${i.string("last_name") ?: ""}"
}

private fun trashMeta(model: String, i: Record): String = when (model) {
    "products" -> "SKU ${i.string("sku") ?: ""} · ${i.string("product_type") ?: ""}"
    "categories" -> "Slug ${i.string("slug") ?: ""}"
    "customers" -> "${i.string("email") ?: ""} · ${i.string("customer_number") ?: ""}"
    else -> i.string("email") ?: ""
}
