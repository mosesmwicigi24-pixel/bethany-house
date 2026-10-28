package ke.co.bethanyhouse.hub.ui.features

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.AccountCircle
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Computer
import androidx.compose.material.icons.filled.PhoneAndroid
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.SessionPolicy
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.DetailRow
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.FieldError
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.hub
import ke.co.bethanyhouse.hub.ui.shell.FeatureRegistry
import kotlinx.coroutines.launch

/** Profile & security (/settings/profile): the person, sessions, named devices, password, sign-in safety. */
object ProfileFeature {
    fun register() { FeatureRegistry.register(Route.Profile) { ProfileScreen() } }
}

@Composable
fun ProfileScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val snapshot by env.session.snapshot.collectAsStateWithLifecycle()
    val me = snapshot.me ?: return
    val sessions = remember { Resource(scope) { env.client.get("/v1/admin/profile/sessions").json["data"].toRecords() } }
    val devices = remember { Resource(scope) { val j = env.client.get("/v1/admin/profile/devices").json; (j["data"] ?: j).toRecords() } }
    var showPassword by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { sessions.load(); devices.load() }

    Page("My profile", refresh = { env.session.refreshMe(false); sessions.reload(); devices.reload() }) {
        SectionCard {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Default.AccountCircle, contentDescription = null, tint = Palette.brand, modifier = Modifier.size(44.dp))
                Spacer(Modifier.width(Space.l))
                Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(me.name, style = MaterialTheme.typography.titleLarge)
                    Text(me.email, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                    Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) { me.roleNames.forEach { StatusBadge(it, StatusTone.INFO) } }
                }
            }
            me.outlet?.let { DetailRow("Outlet", it.name) }
            me.sessionPolicy?.let { p -> DetailRow("Session limits", "Idle ${p.idleMinutes} min (${if (p.onIdle == SessionPolicy.OnIdle.PIN_LOCK) "PIN lock" else "sign out"}), ${p.absoluteMinutes / 60} h absolute") }
            snapshot.tokenExpiresAt?.let { exp -> DetailRow("This sign-in renews until") { DateText(exp) } }
        }

        SectionCard("Sign-in safety") {
            DetailRow("Two-step sign-in") { StatusBadge(if (me.twoFactorEnabled) "on" else if (me.twoFactorRequired) "required" else "off", if (me.twoFactorEnabled) StatusTone.SUCCESS else if (me.twoFactorRequired) StatusTone.WARNING else StatusTone.NEUTRAL) }
            if (me.twoFactorEnabled) me.recoveryCodesLeft?.let { DetailRow("Recovery codes left", it.toString()) }
            DetailRow("Terminal PIN") { StatusBadge(if (me.terminalPinSet) "set" else "not set", if (me.terminalPinSet) StatusTone.SUCCESS else StatusTone.NEUTRAL) }
            OutlinedButton(onClick = { showPassword = true }) { Text("Change password…") }
            Text("Two-step setup, passkeys and the terminal PIN are managed from Setup › Profile in the console until their native screens ship.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
        }

        SectionCard("Sessions") {
            LoadableView(sessions.state, retry = { sessions.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("No other sessions", icon = Icons.Default.Computer) }) { rows ->
                rows.forEach { s ->
                    val current = s.bool("is_current") == true
                    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(if (current) Icons.Default.CheckCircle else Icons.Default.Computer, contentDescription = null, tint = if (current) Palette.success else MaterialTheme.hub.textTertiary)
                        Spacer(Modifier.width(Space.m))
                        Column(Modifier.weight(1f)) {
                            Text(s.string("device") ?: s.string("agent") ?: "Session", style = MaterialTheme.typography.bodyMedium, maxLines = 1)
                            Text("${s.string("ip") ?: ""} · last used ${s.string("last_used") ?: "—"}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        }
                        if (!current) TextButton(onClick = { scope.launch { try { env.client.post("/v1/admin/profile/sessions/${s.id}/revoke"); env.toasts.show("Session revoked"); sessions.reload() } catch (e: ApiError) { env.toasts.error(e) } } }) { Text("Revoke") }
                    }
                }
                ConfirmButton("Revoke all other sessions", "Every other device will be signed out.", destructive = true, action = {
                    try { env.client.post("/v1/admin/profile/sessions/revoke-all"); env.toasts.show("Other sessions revoked"); sessions.reload() } catch (e: ApiError) { env.toasts.error(e) }
                }) { Text("Revoke all other sessions") }
            }
        }

        SectionCard("Named devices") {
            LoadableView(devices.state, retry = { devices.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("No named devices yet", icon = Icons.Default.PhoneAndroid) }) { rows ->
                rows.forEach { d ->
                    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Default.PhoneAndroid, contentDescription = null, tint = MaterialTheme.hub.textTertiary)
                        Spacer(Modifier.width(Space.m))
                        Column(Modifier.weight(1f)) {
                            Text(d.string("name") ?: d.string("label") ?: "Device", style = MaterialTheme.typography.bodyMedium)
                            Text(d.string("last_seen") ?: d.string("agent") ?: "", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        }
                        if (d.string("id") == env.device.id) StatusBadge("this device", StatusTone.SUCCESS)
                    }
                }
            }
        }
    }

    if (showPassword) ChangePasswordDialog { showPassword = false }
}

@Composable
private fun ChangePasswordDialog(dismiss: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var current by remember { mutableStateOf("") }
    var new by remember { mutableStateOf("") }
    var confirm by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<ApiError?>(null) }
    var busy by remember { mutableStateOf(false) }
    AlertDialog(
        onDismissRequest = dismiss,
        title = { Text("Change password") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                OutlinedTextField(value = current, onValueChange = { current = it }, label = { Text("Current password") }, singleLine = true, visualTransformation = PasswordVisualTransformation())
                FieldError(error?.field("current_password"))
                OutlinedTextField(value = new, onValueChange = { new = it }, label = { Text("New password") }, singleLine = true, visualTransformation = PasswordVisualTransformation())
                FieldError(error?.field("password"))
                OutlinedTextField(value = confirm, onValueChange = { confirm = it }, label = { Text("Confirm new password") }, singleLine = true, visualTransformation = PasswordVisualTransformation())
                error?.let { if (it.errors.isEmpty()) Text(it.message, style = MaterialTheme.typography.bodySmall, color = Palette.danger) }
            }
        },
        confirmButton = {
            TextButton(enabled = !busy && new.isNotEmpty() && new == confirm, onClick = {
                busy = true
                scope.launch {
                    try {
                        env.client.post("/v1/admin/profile/password", jsonOf("current_password" to current, "password" to new, "password_confirmation" to confirm))
                        env.toasts.show("Password changed"); dismiss()
                    } catch (e: ApiError) { error = e }
                    busy = false
                }
            }) { Text("Change") }
        },
        dismissButton = { TextButton(onClick = dismiss) { Text("Cancel") } },
    )
}
