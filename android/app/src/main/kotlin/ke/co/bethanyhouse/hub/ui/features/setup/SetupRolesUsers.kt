package ke.co.bethanyhouse.hub.ui.features.setup

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ContentCopy
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material3.Button
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
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.AsyncButton
import ke.co.bethanyhouse.hub.ui.components.AsyncTextButton
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.InfoSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.OutletChoice
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.PagedList
import ke.co.bethanyhouse.hub.ui.components.Pill
import ke.co.bethanyhouse.hub.ui.components.RemoteJson
import ke.co.bethanyhouse.hub.ui.components.SecureFieldRow
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.StaffPicker
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.SupportListRow
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.toastResult
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement

/**
 * Roles (GET/POST/PUT/DELETE /roles, POST /roles/{id}/permissions|duplicate —
 * writes need roles.edit + step-up) and the permission matrix (GET /permissions).
 */
@Composable
fun RolesScreen() {
    val env = LocalEnv.current
    var version by remember { mutableIntStateOf(0) }
    var editing by remember { mutableStateOf<Record?>(null) }
    var adding by remember { mutableStateOf(false) }
    var matrixFor by remember { mutableStateOf<Record?>(null) }
    val canEdit = env.session.current.can("roles.edit")

    Page("Roles", subtitle = "Who may do what. Navigation follows permission; the server enforces every one.", refresh = { version++ }, actions = {
        if (canEdit) Button(onClick = { adding = true }) { Text("New role") }
    }) {
        RemoteJson("/v1/admin/roles", version = version) { j, reload ->
            j["data"].toRecords().forEach { r ->
                SectionCard {
                    Text("${r.string("display_name") ?: r.string("name") ?: ""} (${r.string("name") ?: ""})", style = MaterialTheme.typography.titleMedium)
                    SetupSecondary("${r.string("description") ?: ""} · ${r.int("users_count") ?: 0} people · ${r["permissions"].records.size} permissions · ${r.string("user_type") ?: ""}")
                    androidx.compose.foundation.layout.FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.xs), verticalArrangement = Arrangement.spacedBy(Space.xs)) {
                        if (r.bool("is_system") == true) Pill("system", StatusTone.INFO)
                        if (r.bool("is_active") == false) Pill("inactive")
                    }
                    androidx.compose.foundation.layout.FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.xs), verticalArrangement = Arrangement.spacedBy(Space.xs)) {
                        TextButton(onClick = { matrixFor = r }) { Text("Permissions") }
                        if (canEdit) {
                            TextButton(onClick = { editing = r }) { Text("Edit") }
                            SetupIconAction(Icons.Default.ContentCopy, "Duplicate", tint = MaterialTheme.hub.textSecondary) { val x = env.client.post("/v1/admin/roles/${r.id}/duplicate"); toastResult(env, x); reload() }
                            if (r.bool("is_system") != true) ConfirmButton("Delete role", "People holding it lose it.", destructive = true, action = {
                                try { val x = env.client.delete("/v1/admin/roles/${r.id}"); toastResult(env, x); reload() } catch (e: Exception) { env.toasts.error(e) }
                            }) { Text("Delete") }
                        }
                    }
                }
            }
        }
    }
    if (adding) RoleSheet(null, onDismiss = { adding = false }) { version++ }
    editing?.let { r -> RoleSheet(r, onDismiss = { editing = null }) { version++ } }
    matrixFor?.let { r -> PermissionMatrixSheet(r, onDismiss = { matrixFor = null }) { version++ } }
}

@Composable
private fun RoleSheet(record: Record?, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    var name by remember { mutableStateOf(record?.string("name") ?: "") }
    var displayName by remember { mutableStateOf(record?.string("display_name") ?: "") }
    var description by remember { mutableStateOf(record?.string("description") ?: "") }
    var userType by remember { mutableStateOf(record?.string("user_type") ?: "staff") }
    var active by remember { mutableStateOf(record?.bool("is_active") ?: true) }
    FormSheet(if (record == null) "New role" else "Edit role", onDismiss, canSave = displayName.isNotEmpty() && (record != null || name.isNotEmpty()), save = {
        val pairs = mutableListOf<Pair<String, JsonElement?>>("display_name" to J.s(displayName), "description" to J.s(description), "user_type" to J.s(userType), "is_active" to J.b(active))
        val r = if (record != null) env.client.put("/v1/admin/roles/${record.id}", J.obj(*pairs.toTypedArray()))
        else { pairs += "name" to J.s(name); env.client.post("/v1/admin/roles", J.obj(*pairs.toTypedArray())) }
        toastResult(env, r); saved()
    }) { e ->
        if (record == null) Field("Slug (e.g. floor_supervisor)", name, { name = it }, error = e?.field("name"))
        Field("Display name", displayName, { displayName = it }, error = e?.field("display_name"))
        Field("Description", description, { description = it }, multiline = true)
        OptionPicker("User type", userType, listOf("staff" to "Staff", "system" to "System", "customer" to "Customer"), { userType = it })
        ToggleRow("Active", active, { active = it })
        SetupStepUpNote()
        Spacer(Modifier.height(Space.xs))
    }
}

/** GET /permissions → {data: [{group, permissions: [{id, name, display_name, description}]}]}; POST /roles/{id}/permissions {permissions: [ids]} */
@Composable
private fun PermissionMatrixSheet(role: Record, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    var groups by remember { mutableStateOf<List<Record>>(emptyList()) }
    val selected = remember { role["permissions"].records.mapNotNull { it["id"].int }.toMutableStateList() }
    var filter by remember { mutableStateOf("") }
    val canEdit = env.session.current.can("roles.edit")
    LaunchedEffect(Unit) { try { groups = env.client.get("/v1/admin/permissions").json["data"].toRecords() } catch (e: Exception) { env.toasts.error(e) } }

    InfoSheet("Permissions · ${role.string("display_name") ?: ""}", onDismiss) {
        SetupCaption("${selected.size} granted")
        ModuleSearchField(filter, { filter = it }, "Filter", onSubmit = {})
        groups.forEach { g ->
            val perms = g["permissions"].toRecords().filter { filter.isEmpty() || (it.string("name") ?: "").contains(filter, true) || (it.string("display_name") ?: "").contains(filter, true) }
            if (perms.isNotEmpty()) SectionCard(g.string("group") ?: "General") {
                if (canEdit) Row {
                    TextButton(onClick = { perms.mapNotNull { it.int("id") }.forEach { if (it !in selected) selected.add(it) } }) { Text("All") }
                    TextButton(onClick = { perms.mapNotNull { it.int("id") }.forEach { selected.remove(it) } }) { Text("None") }
                }
                perms.forEach { p ->
                    val id = p.int("id") ?: 0
                    SetupCheckRow(id in selected, enabled = canEdit, onChange = { if (it) { if (id !in selected) selected.add(id) } else selected.remove(id) }) {
                        Text(p.string("display_name") ?: p.string("name") ?: "", style = MaterialTheme.typography.bodySmall)
                        SetupCaption("${p.string("name") ?: ""}${p.string("description")?.let { " — $it" } ?: ""}")
                    }
                }
            }
        }
        if (canEdit) AsyncButton("Save permissions (asks you to confirm it's you)", Modifier.fillMaxWidth()) {
            val r = env.client.post("/v1/admin/roles/${role.id}/permissions", J.obj("permissions" to J.ints(selected.toList())))
            toastResult(env, r); saved(); onDismiss()
        }
        Spacer(Modifier.height(Space.xs))
    }
}

// ── Users (GET /users with filters; create/edit/status/reset/promote; sign-in security) ──

private val userBaseQuery = mapOf("exclude_type" to "customer", "sort_by" to "first_name", "sort_order" to "asc")

@Composable
fun UsersScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var search by remember { mutableStateOf("") }
    var status by remember { mutableStateOf("") }
    var role by remember { mutableStateOf("") }
    var roles by remember { mutableStateOf<List<Record>>(emptyList()) }
    var editing by remember { mutableStateOf<Record?>(null) }
    var adding by remember { mutableStateOf(false) }
    var security by remember { mutableStateOf<Record?>(null) }
    var promoting by remember { mutableStateOf<Record?>(null) }
    val list = remember { PagedList(scope, env.client, "/v1/admin/users", userBaseQuery.toMutableMap<String, String?>(), map = ::Record) }
    val s = env.session.current
    fun apply() { list.query = (userBaseQuery + mapOf("search" to search.ifEmpty { null }, "status" to status.ifEmpty { null }, "role" to role.ifEmpty { null })).toMutableMap(); list.load() }
    LaunchedEffect(Unit) {
        apply()
        roles = runCatching { env.client.get("/v1/admin/roles").json["data"].toRecords() }.getOrDefault(emptyList())
    }
    suspend fun setStatus(u: Record, st: String) { try { val r = env.client.put("/v1/admin/users/${u.id}/status", J.obj("status" to J.s(st))); toastResult(env, r); list.reload() } catch (e: Exception) { env.toasts.error(e) } }

    Page("Users", subtitle = "Accounts, roles, outlets and sign-in security", refresh = { list.reload() }, actions = {
        if (s.can("users.create")) Button(onClick = { adding = true }) { Text("New user") }
    }) {
        ModuleSearchField(search, { search = it }, "Search name, email or phone", onSubmit = ::apply)
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
            OptionPicker("Status", status, listOf("" to "Any", "active" to "Active", "inactive" to "Inactive", "suspended" to "Suspended"), { status = it }, Modifier.weight(1f), allowNone = true)
            OptionPicker("Role", role, listOf("" to "Any role") + roles.map { (it.string("name") ?: "") to (it.string("display_name") ?: "") }, { role = it }, Modifier.weight(1f), allowNone = true)
        }
        OutlinedButton(onClick = ::apply) { Text("Apply") }
        LoadableView(list.state, retry = { list.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("No users match") }) { rows ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                rows.forEachIndexed { i, u ->
                    var menu by remember(u.id) { mutableStateOf(false) }
                    var statusMenu by remember(u.id) { mutableStateOf(false) }
                    val roleNames = u["roles"].records.mapNotNull { it["display_name"].str ?: it["name"].str }.joinToString(", ")
                    SupportListRow(u.string("name") ?: "${u.string("first_name") ?: ""} ${u.string("last_name") ?: ""}", subtitle = "${u.string("email") ?: ""} · $roleNames · ${u["outlet"]["name"].str ?: "no outlet"} · last sign-in ${u.string("last_login_at")?.let(Day::dateTime) ?: "never"}") {
                        Column(horizontalAlignment = Alignment.End) {
                            StatusBadge(u.status)
                            if (u["locked_at"].str != null) Pill("locked", StatusTone.DANGER)
                            if (u.bool("two_factor_enabled") == true) Pill("2FA", StatusTone.SUCCESS) else if (u.bool("must_setup_2fa") == true) Pill("2FA pending", StatusTone.WARNING)
                        }
                        Box {
                            IconButton(onClick = { menu = true }) { Icon(Icons.Default.MoreVert, contentDescription = "Actions") }
                            DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                                if (s.can("users.edit")) {
                                    DropdownMenuItem(text = { Text("Edit") }, onClick = { menu = false; editing = u })
                                    DropdownMenuItem(text = { Text("Status…") }, onClick = { menu = false; statusMenu = true })
                                    DropdownMenuItem(text = { Text("Send password reset") }, onClick = { menu = false; scope.launch { try { val r = env.client.post("/v1/admin/users/${u.id}/reset-password"); toastResult(env, r) } catch (e: Exception) { env.toasts.error(e) } } })
                                    if (u.string("user_type") == "customer") DropdownMenuItem(text = { Text("Promote to staff") }, onClick = { menu = false; promoting = u })
                                }
                                DropdownMenuItem(text = { Text("Sign-in security") }, onClick = { menu = false; security = u })
                                if (s.can("users.delete")) DropdownMenuItem(text = { Text("Delete", color = Palette.danger) }, onClick = { menu = false; scope.launch { try { val r = env.client.delete("/v1/admin/users/${u.id}"); toastResult(env, r); list.reload() } catch (e: Exception) { env.toasts.error(e) } } })
                            }
                            DropdownMenu(expanded = statusMenu, onDismissRequest = { statusMenu = false }) {
                                listOf("active", "inactive", "suspended").forEach { st -> DropdownMenuItem(text = { Text(st.humanized) }, onClick = { statusMenu = false; scope.launch { setStatus(u, st) } }) }
                            }
                        }
                    }
                    if (i == rows.lastIndex && list.hasMore) LaunchedEffect(rows.size) { list.loadMore() }
                }
                SetupCaption("${list.total} users")
            }
        }
    }
    if (adding) UserSheet(null, roles, onDismiss = { adding = false }) { list.load() }
    editing?.let { u -> UserSheet(u, roles, onDismiss = { editing = null }) { list.load() } }
    security?.let { u -> UserSecuritySheet(u) { security = null } }
    promoting?.let { u -> PromoteSheet(u, roles, onDismiss = { promoting = null }) { list.load() } }
}

private val userText = listOf("first_name", "last_name", "email", "phone", "user_type", "status")

/** POST /users, PUT /users/{id}. Roles go in `role_ids`; the server allows only a super admin to change them. */
@Composable
private fun UserSheet(record: Record?, roles: List<Record>, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    val isSuper = env.session.current.me?.isSuperAdmin == true
    val f = remember { SetupForm(buildMap { put("user_type", "staff"); put("status", "active"); if (record != null) userText.forEach { k -> put(k, record.string(k) ?: "") } }) }
    val roleIds = remember { (record?.get("roles").records.mapNotNull { it["id"].int }).toMutableStateList() }
    var outletId by remember { mutableStateOf(record?.get("outlet")["id"].int) }
    var reportsTo by remember { mutableStateOf(record?.get("reports_to").int) }
    var must2fa by remember { mutableStateOf(record?.bool("must_setup_2fa") ?: false) }
    FormSheet(if (record == null) "New user" else "Edit user", onDismiss, canSave = f["first_name"].isNotEmpty() && f["email"].isNotEmpty(), save = {
        val pairs = userText.map { k -> k to J.s(f[k]) }.toMutableList<Pair<String, JsonElement?>>()
        pairs += "outlet_id" to J.i(outletId); pairs += "reports_to" to J.i(reportsTo); pairs += "must_setup_2fa" to J.b(must2fa)
        if (isSuper || record == null) pairs += "role_ids" to J.ints(roleIds.toList())
        if (f["password"].isNotEmpty()) { pairs += "password" to J.s(f["password"]); pairs += "password_confirmation" to J.s(f["password_confirmation"]) }
        val body = J.obj(*pairs.toTypedArray())
        val r = if (record != null) env.client.put("/v1/admin/users/${record.id}", body) else env.client.post("/v1/admin/users", body)
        toastResult(env, r); saved()
    }) { e ->
        Field("First name", f["first_name"], { f["first_name"] = it }, error = e?.field("first_name"))
        Field("Last name", f["last_name"], { f["last_name"] = it }, error = e?.field("last_name"))
        Field("Email", f["email"], { f["email"] = it }, error = e?.field("email"))
        Field("Phone", f["phone"], { f["phone"] = it }, error = e?.field("phone"))
        OptionPicker("Type", f["user_type"], listOf("staff" to "Staff", "system" to "System"), { f["user_type"] = it })
        OptionPicker("Status", f["status"], listOf("active" to "Active", "inactive" to "Inactive") + (if (record == null) emptyList() else listOf("suspended" to "Suspended")), { f["status"] = it })
        OutletChoice("Outlet", outletId, { outletId = it }, allowAll = true)
        StaffPicker("Reports to", reportsTo, { reportsTo = it }, allowNone = true)
        ToggleRow("Must set up two-step sign-in", must2fa, { must2fa = it })
        SecureFieldRow(if (record == null) "Password" else "New password (optional)", f["password"], { f["password"] = it }, error = e?.field("password"))
        SecureFieldRow("Confirm password", f["password_confirmation"], { f["password_confirmation"] = it })
        SetupHeading("Roles")
        if (!isSuper) SetupCaption("Only a super administrator changes who holds which role.")
        roles.forEach { r ->
            val id = r.int("id") ?: 0
            SetupCheckRow(id in roleIds, enabled = isSuper || record == null, onChange = { if (it) { if (id !in roleIds) roleIds.add(id) } else roleIds.remove(id) }) {
                Text("${r.string("display_name") ?: ""} (${r.string("name") ?: ""})", style = MaterialTheme.typography.bodySmall)
            }
        }
        Spacer(Modifier.height(Space.xs))
    }
}

/** POST /users/{id}/promote-to-staff {role_ids, outlet_id} */
@Composable
private fun PromoteSheet(user: Record, roles: List<Record>, onDismiss: () -> Unit, done: () -> Unit) {
    val env = LocalEnv.current
    val roleIds = remember { mutableListOf<Int>().toMutableStateList() }
    var outlet by remember { mutableStateOf<Int?>(null) }
    FormSheet("Promote ${user.string("name") ?: ""} to staff", onDismiss, saveLabel = "Promote", save = {
        val r = env.client.post("/v1/admin/users/${user.id}/promote-to-staff", J.obj("role_ids" to J.ints(roleIds.toList()), "outlet_id" to J.i(outlet)))
        toastResult(env, r); done()
    }) {
        OutletChoice("Outlet", outlet, { outlet = it }, allowAll = true)
        roles.forEach { r ->
            val id = r.int("id") ?: 0
            SetupCheckRow(id in roleIds, onChange = { if (it) { if (id !in roleIds) roleIds.add(id) } else roleIds.remove(id) }) { Text(r.string("display_name") ?: "", style = MaterialTheme.typography.bodySmall) }
        }
        Spacer(Modifier.height(Space.xs))
    }
}

/** Another person's sign-in (UserSecurityController): sessions, devices, 2FA reset (step-up), PIN reset, unlock. */
@Composable
private fun UserSecuritySheet(user: Record, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var version by remember { mutableIntStateOf(0) }
    var reason by remember { mutableStateOf("") }
    InfoSheet("Sign-in security · ${user.string("name") ?: ""}", onDismiss) {
        SectionCard("Account") {
            if (user["locked_at"].str != null) {
                SetupSecondary("Locked${user.string("lock_reason")?.let { ": $it" } ?: ""}", Palette.danger)
                AsyncButton("Unlock", outlined = true) { val r = env.client.post("/v1/admin/users/${user.id}/unlock"); toastResult(env, r) }
            } else SetupSecondary("Not locked")
            AsyncButton("Reset terminal PIN", outlined = true) { val r = env.client.post("/v1/admin/users/${user.id}/terminal-pin/reset"); toastResult(env, r) }
            Field("Reason for 2FA reset", reason, { reason = it })
            ConfirmButton("Reset two-step sign-in", "They set it up again at their next sign-in. You will be asked to confirm it is you.", destructive = true, enabled = reason.isNotEmpty(), action = {
                try { val r = env.client.post("/v1/admin/users/${user.id}/2fa/reset", J.obj("reason" to J.s(reason))); toastResult(env, r) } catch (e: Exception) { env.toasts.error(e) }
            }) { Text("Reset 2FA") }
        }
        RemoteJson("/v1/admin/users/${user.id}/sessions", version = version) { j, reload ->
            SectionCard("Sessions") {
                val rows = j["data"].toRecords()
                if (rows.isEmpty()) SetupCaption("No sessions")
                rows.forEach { se ->
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            SetupSecondary(se["device"]["label"].str ?: se.string("agent") ?: "Session", MaterialTheme.hub.text)
                            SetupCaption("${se.string("ip") ?: ""} · last used ${Day.dateTime(se.string("last_used"))}${if (se.bool("locked") == true) " · PIN-locked" else ""}")
                        }
                        AsyncTextButton("Revoke") { val r = env.client.post("/v1/admin/users/${user.id}/sessions/${se.id}/revoke"); toastResult(env, r); reload() }
                    }
                }
                if (rows.isNotEmpty()) AsyncButton("Revoke all", destructive = true) { val r = env.client.post("/v1/admin/users/${user.id}/sessions/revoke-all"); toastResult(env, r); reload() }
            }
        }
        RemoteJson("/v1/admin/users/${user.id}/devices", version = version) { j, reload ->
            SectionCard("Named devices") {
                val rows = j["data"].toRecords()
                if (rows.isEmpty()) SetupCaption("No devices")
                rows.forEach { d -> DeviceRow(user.id, d, reload) }
            }
        }
        Spacer(Modifier.height(Space.xs))
    }
}

@Composable
private fun DeviceRow(userId: String, device: Record, reload: () -> Unit) {
    val env = LocalEnv.current
    var name by remember(device.id) { mutableStateOf(device.string("name") ?: "") }
    Column(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        SetupSecondary(device.string("label") ?: "Device", MaterialTheme.hub.text)
        SetupCaption("${device.string("facts") ?: ""} · ${device.int("active_sessions") ?: 0} sessions · last seen ${Day.dateTime(device.string("last_seen"))}")
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.xs)) {
            Field("Rename", name, { name = it }, Modifier.weight(1f))
            AsyncTextButton("Name", enabled = name.isNotEmpty()) { val r = env.client.put("/v1/admin/users/$userId/devices/${device.id}", J.obj("name" to J.s(name))); toastResult(env, r); reload() }
            SetupIconAction(description = "Forget device") { val r = env.client.delete("/v1/admin/users/$userId/devices/${device.id}"); toastResult(env, r); reload() }
        }
    }
}
