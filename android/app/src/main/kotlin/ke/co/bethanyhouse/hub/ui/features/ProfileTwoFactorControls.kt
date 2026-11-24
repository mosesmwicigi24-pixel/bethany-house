package ke.co.bethanyhouse.hub.ui.features

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
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
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.ProfileTwoFactor
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.auth.QrCode
import ke.co.bethanyhouse.hub.ui.components.FieldError
import kotlinx.coroutines.launch

@Composable
internal fun ProfileTwoFactorControls(enabled: Boolean) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var setup by remember { mutableStateOf(false) }
    var disable by remember { mutableStateOf(false) }
    var replace by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var codes by remember { mutableStateOf<List<String>?>(null) }
    var missingCodes by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<ApiError?>(null) }

    if (enabled) {
        OutlinedButton(onClick = { replace = true }) { Text("Replace recovery codes") }
        OutlinedButton(onClick = { disable = true }) { Text("Turn off two-step sign-in") }
    } else {
        OutlinedButton(onClick = { setup = true }) { Text("Turn on two-step sign-in") }
    }
    if (setup) TwoFactorSetupDialog(onDismiss = { setup = false }, onConfirmed = { received ->
        setup = false
        if (received.isEmpty()) missingCodes = true else codes = received
    })
    if (disable) TwoFactorDisableDialog(onDismiss = { disable = false })
    if (replace) AlertDialog(
        onDismissRequest = { if (!busy) { replace = false; error = null } },
        title = { Text("Replace recovery codes?") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                Text("Your current recovery codes will stop working. Save the new codes somewhere safe.")
                error?.let { Text(it.message, style = MaterialTheme.typography.bodySmall, color = Palette.danger) }
            }
        },
        confirmButton = {
            TextButton(enabled = !busy, onClick = {
                busy = true; error = null
                scope.launch {
                    try {
                        val received = ProfileTwoFactor(env.client).replaceRecoveryCodes()
                        replace = false
                        if (received.isEmpty()) missingCodes = true else codes = received
                        env.session.refreshMe(false)
                    } catch (e: ApiError) { error = e } finally { busy = false }
                }
            }) { Text(if (busy) "Replacing…" else "Replace codes") }
        },
        dismissButton = { TextButton(enabled = !busy, onClick = { replace = false; error = null }) { Text("Cancel") } },
    )
    codes?.let { received ->
        AlertDialog(
            onDismissRequest = {},
            title = { Text("Your recovery codes") },
            text = {
                Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                    Text("Save these somewhere safe. Each code works once, and they won't be shown again.")
                    SelectionContainer {
                        Column { received.forEach { Text(it, style = MaterialTheme.typography.bodyMedium) } }
                    }
                }
            },
            confirmButton = { TextButton(onClick = { codes = null }) { Text("I've saved them") } },
        )
    }
    if (missingCodes) AlertDialog(
        onDismissRequest = { missingCodes = false },
        title = { Text("Recovery codes unavailable") },
        text = { Text("The change was accepted, but the recovery codes could not be displayed. Ask your administrator for help securing your account.") },
        confirmButton = { TextButton(onClick = { missingCodes = false }) { Text("OK") } },
    )
}

@Composable
private fun TwoFactorSetupDialog(onDismiss: () -> Unit, onConfirmed: (List<String>) -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var setup by remember { mutableStateOf<ProfileTwoFactor.Setup?>(null) }
    var code by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<ApiError?>(null) }
    var busy by remember { mutableStateOf(false) }
    suspend fun begin() {
        busy = true; error = null
        try { setup = ProfileTwoFactor(env.client).begin() } catch (e: ApiError) { error = e } finally { busy = false }
    }
    LaunchedEffect(Unit) { begin() }
    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text("Turn on two-step sign-in") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                val current = setup
                if (current != null) {
                    Text("Add this account to your authenticator app, then enter its six-digit code.")
                    current.qrUrl?.let { QrCode(it, Modifier.size(160.dp)) }
                    Text("Setup key: ${current.secret}", style = MaterialTheme.typography.bodySmall)
                    OutlinedTextField(code, { code = it.filter { c -> c in '0'..'9' }.take(6) }, label = { Text("Authenticator code") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                    FieldError(error?.field("code"))
                } else if (busy) CircularProgressIndicator(Modifier.size(24.dp))
                else if (error != null) TextButton(onClick = { scope.launch { begin() } }) { Text("Try again") }
                error?.let { if (it.errors.isEmpty()) Text(it.message, style = MaterialTheme.typography.bodySmall, color = Palette.danger) }
            }
        },
        confirmButton = {
            TextButton(enabled = !busy && setup != null && code.length == 6, onClick = {
                busy = true; error = null
                scope.launch {
                    try {
                        val received = ProfileTwoFactor(env.client).confirm(code)
                        env.session.refreshMe(false); onConfirmed(received)
                    } catch (e: ApiError) { error = e } finally { busy = false }
                }
            }) { Text(if (busy) "Confirming…" else "Confirm") }
        },
        dismissButton = { TextButton(enabled = !busy, onClick = onDismiss) { Text("Cancel") } },
    )
}

@Composable
private fun TwoFactorDisableDialog(onDismiss: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var password by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<ApiError?>(null) }
    var busy by remember { mutableStateOf(false) }
    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text("Turn off two-step sign-in?") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                Text("Enter your password to confirm. Your role may require two-step sign-in.")
                OutlinedTextField(password, { password = it }, label = { Text("Your password") }, singleLine = true, visualTransformation = PasswordVisualTransformation())
                FieldError(error?.field("password"))
                error?.let { if (it.errors.isEmpty()) Text(it.message, style = MaterialTheme.typography.bodySmall, color = Palette.danger) }
            }
        },
        confirmButton = {
            TextButton(enabled = !busy && password.isNotEmpty(), onClick = {
                busy = true; error = null
                scope.launch {
                    try { ProfileTwoFactor(env.client).disable(password); env.session.refreshMe(false); env.toasts.show("Two-step sign-in turned off"); onDismiss() }
                    catch (e: ApiError) { error = e } finally { busy = false }
                }
            }) { Text(if (busy) "Turning off…" else "Turn off") }
        },
        dismissButton = { TextButton(enabled = !busy, onClick = onDismiss) { Text("Cancel") } },
    )
}
