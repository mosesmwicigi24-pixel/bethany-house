package ke.co.bethanyhouse.hub.ui.features

import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.AccountCircle
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.AppFlavor
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.arr
import ke.co.bethanyhouse.hub.core.obj
import ke.co.bethanyhouse.hub.core.date
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.SessionPolicy
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.DetailRow
import ke.co.bethanyhouse.hub.ui.components.FieldError
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.features.people.PeopleSignedFileButton
import ke.co.bethanyhouse.hub.ui.features.me.PersonAvatar
import ke.co.bethanyhouse.hub.ui.features.me.jpegForUpload
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.hub
import ke.co.bethanyhouse.hub.ui.shell.FeatureRegistry
import kotlinx.coroutines.launch
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonElement

/**
 * Profile & security (/settings/profile): the person, password, sign-in safety.
 * Sessions and named devices are not shown in the apps (owner, 2026-11-24,
 * matching iOS); they stay on the console's Setup › Profile page.
 */
object ProfileFeature {
    fun register() { FeatureRegistry.register(Route.Profile) { ProfileScreen() } }
}

@Composable
fun ProfileScreen() {
    val env = LocalEnv.current
    val snapshot by env.session.snapshot.collectAsStateWithLifecycle()
    val me = snapshot.me ?: return
    var showPassword by remember { mutableStateOf(false) }
    var showEdit by remember { mutableStateOf(false) }
    var photoBusy by remember { mutableStateOf(false) }
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val photoPicker = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri ->
        if (uri != null && !photoBusy) {
            photoBusy = true
            scope.launch {
                try {
                    val bytes = withContext(Dispatchers.IO) {
                        val source = context.contentResolver.openInputStream(uri)?.use { it.readBytes() }
                        source?.let { jpegForUpload(it) }
                    }
                    if (bytes == null) env.toasts.show("Couldn't read this photo. Try another.", StatusTone.WARNING)
                    else if (bytes.size > 2 * 1024 * 1024) env.toasts.show("Choose a photo smaller than 2 MB.", StatusTone.WARNING)
                    else {
                        env.client.upload("/v1/admin/profile/avatar", listOf(ApiClient.Part.file("avatar", bytes, "avatar.jpg", "image/jpeg")))
                        env.session.refreshMe(false)
                        env.toasts.show("Photo uploaded. Refresh your profile if it does not appear.")
                    }
                } catch (e: Exception) { env.toasts.error(e) } finally { photoBusy = false }
            }
        }
    }
    var profileTab by remember { mutableStateOf(0) }
    // Opening the page re-reads the person, so the sign-in safety badges are current.
    LaunchedEffect(Unit) { env.session.refreshMe(false) }

    Page("My profile", refresh = { env.session.refreshMe(false) }) {
        if (env.flavor == AppFlavor.TAILORS) {
            SegmentedTabs(listOf(0 to "Profile", 1 to "Employment", 2 to "Activity"), profileTab) { profileTab = it }
        }
        when (profileTab) {
          1 -> OwnEmployment()
          2 -> OwnActivity()
          else -> {
        SectionCard {
            Row(verticalAlignment = Alignment.CenterVertically) {
                if (env.flavor == AppFlavor.TAILORS) PersonAvatar(me.name, me.avatarUrl, 56.dp)
                else Icon(Icons.Default.AccountCircle, contentDescription = null, tint = Palette.brand, modifier = Modifier.size(44.dp))
                Spacer(Modifier.width(Space.l))
                Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(me.name, style = MaterialTheme.typography.titleLarge)
                    Text(me.email, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                    if (env.flavor == AppFlavor.HUB) Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) { me.roleNames.forEach { StatusBadge(it, StatusTone.INFO) } }
                }
            }
            if (env.flavor == AppFlavor.TAILORS) {
                OutlinedButton(onClick = { showEdit = true }, modifier = Modifier.fillMaxWidth()) { Text("Edit details & preferences") }
                OutlinedButton(onClick = { photoPicker.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) }, enabled = !photoBusy, modifier = Modifier.fillMaxWidth()) { Text(if (photoBusy) "Uploading photo…" else "Change photo") }
            }
            me.outlet?.let { DetailRow("Outlet", it.name) }
            if (env.flavor == AppFlavor.HUB) {
                me.sessionPolicy?.let { p -> DetailRow("Session limits", "Idle ${p.idleMinutes} min (${if (p.onIdle == SessionPolicy.OnIdle.PIN_LOCK) "PIN lock" else "sign out"}), ${p.absoluteMinutes / 60} h absolute") }
                snapshot.tokenExpiresAt?.let { exp -> DetailRow("This sign-in renews until") { DateText(exp) } }
            }
        }

        SectionCard("Sign-in safety") {
            DetailRow("Two-step sign-in") { StatusBadge(if (me.twoFactorEnabled) "on" else if (me.twoFactorRequired) "required" else "off", if (me.twoFactorEnabled) StatusTone.SUCCESS else if (me.twoFactorRequired) StatusTone.WARNING else StatusTone.NEUTRAL) }
            if (me.twoFactorEnabled) me.recoveryCodesLeft?.let { DetailRow("Recovery codes left", it.toString()) }
            DetailRow("Terminal PIN") { StatusBadge(if (me.terminalPinSet) "set" else "not set", if (me.terminalPinSet) StatusTone.SUCCESS else StatusTone.NEUTRAL) }
            OutlinedButton(onClick = { showPassword = true }) { Text("Change password…") }
            Text("Two-step setup, passkeys and the terminal PIN are managed from Setup › Profile in the console.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
        }
          }
        }
    }

    if (showPassword) ChangePasswordDialog { showPassword = false }
    if (showEdit) EditOwnProfile { showEdit = false }
}

@Composable
private fun EditOwnProfile(onDismiss: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val profile = remember { Resource(scope) {
        val response = env.client.get("/v1/admin/profile").json
        if (response["user"].obj == null) throw ApiError.transport("Your profile could not be loaded. Please try again.")
        response ?: JsonNull
    } }
    LaunchedEffect(Unit) { profile.load() }
    val user = profile.value?.get("user")
    var first by remember(user) { mutableStateOf(user["first_name"].str.orEmpty()) }
    var last by remember(user) { mutableStateOf(user["last_name"].str.orEmpty()) }
    var phone by remember(user) { mutableStateOf(user["phone"].str.orEmpty()) }
    var language by remember(user) { mutableStateOf(user["preferred_language"].str.orEmpty()) }
    var currency by remember(user) { mutableStateOf(user["preferred_currency"].str.orEmpty()) }
    var languages by remember { mutableStateOf<List<Pair<String, String>>>(emptyList()) }
    var currencies by remember { mutableStateOf<List<Pair<String, String>>>(emptyList()) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<ApiError?>(null) }
    LaunchedEffect(Unit) {
        try {
            val j = env.client.get("/v1/admin/languages").json
            languages = (j.records.ifEmpty { j["data"].records }).mapNotNull { item -> item["code"].str?.let { it to (item["name"].str ?: it) } }
        } catch (_: ApiError) { languages = emptyList() }
        try {
            val j = env.client.get("/v1/admin/currencies-management").json
            currencies = (j.records.ifEmpty { j["data"].records }).mapNotNull { item -> item["code"].str?.let { it to (item["name"].str?.let { name -> "$it · $name" } ?: it) } }
        } catch (_: ApiError) { currencies = emptyList() }
    }
    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text("Details & preferences") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                LoadableView(profile.state, retry = { profile.load() }) { response ->
                    if (response["user"] == null) Text("Your details could not be loaded.", color = Palette.danger)
                    else {
                        OutlinedTextField(first, { first = it }, label = { Text("First name") }, singleLine = true)
                        FieldError(error?.field("first_name"))
                        OutlinedTextField(last, { last = it }, label = { Text("Last name") }, singleLine = true)
                        FieldError(error?.field("last_name"))
                        OutlinedTextField(phone, { phone = it }, label = { Text("Phone number") }, singleLine = true)
                        FieldError(error?.field("phone"))
                        Text("Email: ${response["user"]["email"].str.orEmpty()}", style = MaterialTheme.typography.bodySmall)
                        if (languages.isNotEmpty()) SelectField("Preferred language", language, languages, { if (it != null) language = it }, placeholder = language.ifBlank { "Select language" })
                        else Text("Language options are unavailable.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        if (currencies.isNotEmpty()) SelectField("Preferred currency", currency, currencies, { if (it != null) currency = it }, placeholder = currency.ifBlank { "Select currency" })
                        else Text("Currency options are unavailable.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        error?.let { if (it.errors.isEmpty()) Text(it.message, color = Palette.danger, style = MaterialTheme.typography.bodySmall) }
                    }
                }
            }
        },
        confirmButton = {
            TextButton(enabled = !busy && user != null && first.isNotBlank() && last.isNotBlank(), onClick = {
                busy = true
                scope.launch {
                    try {
                        val fields = mutableListOf<Pair<String, Any?>>("first_name" to first.trim(), "last_name" to last.trim(), "phone" to phone.trim())
                        if (languages.isNotEmpty() && language.isNotBlank()) fields.add("preferred_language" to language)
                        if (currencies.isNotEmpty() && currency.isNotBlank()) fields.add("preferred_currency" to currency)
                        env.client.put("/v1/admin/profile", jsonOf(*fields.toTypedArray()))
                        env.session.refreshMe(false); env.toasts.show("Profile updated"); onDismiss()
                    } catch (e: ApiError) { error = e } finally { busy = false }
                }
            }) { if (busy) CircularProgressIndicator(Modifier.size(18.dp)) else Text("Save") }
        },
        dismissButton = { TextButton(enabled = !busy, onClick = onDismiss) { Text("Cancel") } },
    )
}

@Composable
private fun OwnEmployment() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val file = remember { Resource(scope) {
        val response = env.client.get("/v1/admin/me/file").json
        if (response["person"].obj == null) throw ApiError.transport("Your employment details could not be loaded. Please try again.")
        response ?: JsonNull
    } }
    var editing by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { file.load() }
    LoadableView(file.state, retry = { file.load() }) { data ->
        val person = data["person"]
        val profile = person["profile"]
        SectionCard("Employment") {
            listOf("job_title" to "Job title", "department" to "Department", "employment_type" to "Type", "hire_date" to "Since").forEach { (key, label) ->
                profile[key].str?.takeIf { it.isNotBlank() }?.let { DetailRow(label, it.replace('_', ' ')) }
            }
            person["reports_to"]["name"].str?.takeIf { it.isNotBlank() }?.let { DetailRow("Reports to", it) }
            Text("For corrections, contact the person who keeps employee files.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
        }
        SectionCard("Personal details") {
            profile["date_of_birth"].str?.takeIf { it.isNotBlank() }?.let { DetailRow("Date of birth", it) }
            val contact = listOfNotNull(profile["emergency_contact_name"].str, profile["emergency_contact_relationship"].str, profile["emergency_contact_phone"].str).filter { it.isNotBlank() }.joinToString(" · ")
            if (contact.isNotBlank()) DetailRow("Emergency contact", contact)
            else Text("No emergency contact on file.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
            OutlinedButton(onClick = { editing = true }) { Text("Edit personal details") }
        }
        if (editing) {
            EditOwnEmployment(
                profile["date_of_birth"].str.orEmpty(), profile["emergency_contact_name"].str.orEmpty(),
                profile["emergency_contact_phone"].str.orEmpty(), profile["emergency_contact_relationship"].str.orEmpty(),
                onDismiss = { editing = false }, onSaved = { editing = false; file.load() },
            )
        }
        val identifiers = ownIdentifiers(person)
        if (identifiers.isNotEmpty()) SectionCard("Identifiers") {
            identifiers.forEach { (label, value) -> DetailRow(label, value) }
            Text("Identifiers are masked here.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
        }
        SectionCard("My documents") {
            val documents = person["documents"].records
            if (documents.isEmpty()) Text("No documents on file.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
            documents.forEach { document ->
                val id = document["id"].int
                val name = document["original_name"].str ?: document["name"].str ?: "Document"
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text(name, modifier = Modifier.weight(1f), style = MaterialTheme.typography.bodyMedium)
                    if (id != null) PeopleSignedFileButton("Open", "/api/v1/admin/me/file/documents/$id", name)
                }
            }
        }
    }
}

/** Show only identifiers actually returned in the masked own-file response. */
internal fun ownIdentifiers(person: JsonElement?): List<Pair<String, String>> =
    listOf("national_id_no" to "National ID", "kra_pin" to "KRA PIN", "nssf_no" to "NSSF", "shif_no" to "SHIF (SHA)", "mpesa_phone" to "M-Pesa phone", "bank_account_no" to "Bank account")
        .mapNotNull { (key, label) -> person["masked"][key].str?.takeIf { it.isNotBlank() }?.let { label to it } }

@Composable
private fun EditOwnEmployment(initialDob: String, initialName: String, initialPhone: String, initialRelationship: String, onDismiss: () -> Unit, onSaved: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var dob by remember { mutableStateOf(initialDob) }
    var name by remember { mutableStateOf(initialName) }
    var phone by remember { mutableStateOf(initialPhone) }
    var relationship by remember { mutableStateOf(initialRelationship) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<ApiError?>(null) }
    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text("Personal details") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                OutlinedTextField(value = dob, onValueChange = { dob = it }, label = { Text("Date of birth (YYYY-MM-DD)") }, singleLine = true)
                FieldError(error?.field("date_of_birth"))
                OutlinedTextField(value = name, onValueChange = { name = it }, label = { Text("Emergency contact name") }, singleLine = true)
                FieldError(error?.field("emergency_contact_name"))
                OutlinedTextField(value = phone, onValueChange = { phone = it }, label = { Text("Their phone") }, singleLine = true)
                FieldError(error?.field("emergency_contact_phone"))
                OutlinedTextField(value = relationship, onValueChange = { relationship = it }, label = { Text("Relationship") }, singleLine = true)
                FieldError(error?.field("emergency_contact_relationship"))
                error?.let { if (it.errors.isEmpty()) Text(it.message, color = Palette.danger, style = MaterialTheme.typography.bodySmall) }
            }
        },
        confirmButton = {
            TextButton(enabled = !busy, onClick = {
                busy = true
                scope.launch {
                    try {
                        env.client.put("/v1/admin/me/file", jsonOf("date_of_birth" to dob.trim().ifBlank { null }, "emergency_contact_name" to name.trim().ifBlank { null }, "emergency_contact_phone" to phone.trim().ifBlank { null }, "emergency_contact_relationship" to relationship.trim().ifBlank { null }))
                        env.toasts.show("Personal details saved"); onSaved()
                    } catch (e: ApiError) { error = e } finally { busy = false }
                }
            }) { Text(if (busy) "Saving…" else "Save") }
        },
        dismissButton = { TextButton(enabled = !busy, onClick = onDismiss) { Text("Cancel") } },
    )
}

@Composable
private fun OwnActivity() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val activity = remember { Resource(scope) {
        val response = env.client.get("/v1/admin/profile/activity", mapOf("per_page" to "20")).json
        if (response["data"].arr == null) throw ApiError.transport("Your activity could not be loaded. Please try again.")
        response ?: JsonNull
    } }
    LaunchedEffect(Unit) { activity.load() }
    LoadableView(activity.state, retry = { activity.load() }) { data ->
        SectionCard("Recent activity") {
            Text("Your latest 20 account actions.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            val rows = data["data"].records
            if (rows.isEmpty()) Text("No activity recorded yet.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
            rows.forEach { row ->
                Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(row["description"].str?.takeIf { it.isNotBlank() } ?: row["action"].str?.replace('_', ' ') ?: "Activity", style = MaterialTheme.typography.bodyMedium)
                    row["created_at"].date?.let { DateText(it, DateStyle.DATE_TIME) }
                }
            }
        }
    }
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
