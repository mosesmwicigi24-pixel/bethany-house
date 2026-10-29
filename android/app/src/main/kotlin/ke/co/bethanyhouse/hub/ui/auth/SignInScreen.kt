package ke.co.bethanyhouse.hub.ui.auth

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
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
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.AuthService
import ke.co.bethanyhouse.hub.core.SessionStore
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.components.ErrorState
import ke.co.bethanyhouse.hub.ui.components.FieldError
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch

/**
 * Sign in → (two-step verify | two-step setup) → session. Mirrors the
 * console's Login/TwoFactorVerify Livewire pages against the same
 * `/v1/admin/auth` endpoints; nothing here is specific to the app.
 */
@Composable
fun SignInScreen() {
    val env = LocalEnv.current
    val snapshot by env.session.snapshot.collectAsStateWithLifecycle()
    Box(Modifier.fillMaxSize().background(MaterialTheme.hub.canvas).imePadding(), contentAlignment = Alignment.Center) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(Space.gutter),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(Space.xl),
        ) {
            Brand()
            val kioskEnded by env.kiosk.ended.collectAsStateWithLifecycle()
            kioskEnded?.let { reason ->
                Surface(shape = MaterialTheme.shapes.medium, color = Palette.warningSoft, modifier = Modifier.widthIn(max = 420.dp).fillMaxWidth()) {
                    Column(Modifier.padding(Space.l), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                        Text(reason, style = MaterialTheme.typography.bodySmall, color = Palette.warning)
                        TextButton(onClick = { env.kiosk.acknowledgeEnded() }) { Text("OK") }
                    }
                }
            }
            Surface(
                shape = MaterialTheme.shapes.large,
                color = MaterialTheme.hub.card,
                tonalElevation = 1.dp,
                shadowElevation = 2.dp,
                modifier = Modifier.widthIn(max = 420.dp).fillMaxWidth(),
            ) {
                Column(Modifier.padding(Space.xl), verticalArrangement = Arrangement.spacedBy(Space.l)) {
                    when (val s = snapshot.state) {
                        is SessionStore.State.TwoFactor -> TwoFactorStep()
                        is SessionStore.State.TwoFactorSetup -> TwoFactorSetupStep()
                        else -> CredentialsStep(signingIn = s == SessionStore.State.SigningIn, startupError = snapshot.startupError)
                    }
                }
            }
            Text("Bethany House Hub · ${env.config.hubUrl.removePrefix("https://")}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
        }
    }
}

@Composable
private fun Brand() {
    Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(Space.s)) {
        Box(Modifier.size(56.dp).background(Palette.brand, RoundedCornerShape(16.dp)), contentAlignment = Alignment.Center) {
            Text("BH", style = MaterialTheme.typography.titleLarge, color = androidx.compose.ui.graphics.Color.White)
        }
        Text("Bethany House", style = MaterialTheme.typography.headlineSmall)
        Text("Sign in to the Hub", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.hub.textSecondary)
    }
}

@Composable
private fun CredentialsStep(signingIn: Boolean, startupError: ApiError?) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var remember by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<ApiError?>(null) }
    var forgot by remember { mutableStateOf(false) }

    fun submit() {
        if (signingIn || email.isBlank() || password.isEmpty()) return
        error = null
        scope.launch {
            try { env.session.signIn(email.trim(), password, remember) } catch (e: ApiError) { error = e } catch (e: Exception) { error = ApiError.transport(e.message ?: "Could not sign in") }
        }
    }

    if (startupError != null && startupError.isOffline) {
        Text("Can't reach Bethany House. Check the connection; anything you opened before will still be here when you sign in.", style = MaterialTheme.typography.bodySmall, color = Palette.warning)
    }
    OutlinedTextField(
        value = email, onValueChange = { email = it }, label = { Text("Email") }, singleLine = true, modifier = Modifier.fillMaxWidth(),
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email, imeAction = ImeAction.Next),
        isError = error?.field("email") != null,
    )
    FieldError(error?.field("email"))
    OutlinedTextField(
        value = password, onValueChange = { password = it }, label = { Text("Password") }, singleLine = true, modifier = Modifier.fillMaxWidth(),
        visualTransformation = PasswordVisualTransformation(),
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = ImeAction.Done),
        keyboardActions = KeyboardActions(onDone = { submit() }),
        isError = error?.field("password") != null,
    )
    FieldError(error?.field("password"))
    Row(verticalAlignment = Alignment.CenterVertically) {
        Checkbox(checked = remember, onCheckedChange = { remember = it })
        Text("Keep me signed in on this device", style = MaterialTheme.typography.bodySmall)
    }
    error?.let { e ->
        if (e.errors.isEmpty()) Text(
            if (e.status == 429) "Too many attempts. Try again in ${e.retryAfterSeconds ?: 60} seconds." else e.message,
            style = MaterialTheme.typography.bodySmall, color = Palette.danger,
        )
    }
    Button(onClick = { submit() }, enabled = !signingIn && email.isNotBlank() && password.isNotEmpty(), modifier = Modifier.fillMaxWidth().height(48.dp)) {
        if (signingIn) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp, color = androidx.compose.ui.graphics.Color.White) else Text("Sign in")
    }
    Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) { TextButton(onClick = { forgot = true }) { Text("Forgot password?") } }

    if (forgot) ForgotPasswordDialog(initialEmail = email) { forgot = false }
}

@Composable
private fun ForgotPasswordDialog(initialEmail: String, dismiss: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var email by remember { mutableStateOf(initialEmail) }
    var message by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    AlertDialog(
        onDismissRequest = dismiss,
        title = { Text("Reset your password") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(Space.m)) {
                OutlinedTextField(value = email, onValueChange = { email = it }, label = { Text("Email") }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email))
                message?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary) }
            }
        },
        confirmButton = {
            TextButton(enabled = !busy && email.isNotBlank(), onClick = {
                busy = true
                scope.launch {
                    message = try { env.session.auth.forgotPassword(email.trim()) } catch (e: ApiError) { e.message } catch (e: Exception) { e.message ?: "Could not send" }
                    busy = false
                }
            }) { Text("Send reset link") }
        },
        dismissButton = { TextButton(onClick = dismiss) { Text(if (message != null) "Done" else "Cancel") } },
    )
}

@Composable
private fun TwoFactorStep() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var code by remember { mutableStateOf("") }
    var recovery by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<ApiError?>(null) }
    var busy by remember { mutableStateOf(false) }

    fun submit() {
        if (busy || code.length < 6) return
        busy = true; error = null
        scope.launch {
            try { env.session.verifyTwoFactor(if (recovery) null else code, if (recovery) code else null) } catch (e: ApiError) { error = e } catch (e: Exception) { error = ApiError.transport(e.message ?: "") }
            busy = false
        }
    }

    Text("Two-step sign-in", style = MaterialTheme.typography.titleLarge)
    Text(if (recovery) "Enter one of your recovery codes. Each works once." else "Enter the 6-digit code from your authenticator app.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
    OutlinedTextField(
        value = code, onValueChange = { code = it.trim() }, label = { Text(if (recovery) "Recovery code" else "Code") }, singleLine = true, modifier = Modifier.fillMaxWidth(),
        keyboardOptions = KeyboardOptions(keyboardType = if (recovery) KeyboardType.Ascii else KeyboardType.NumberPassword, imeAction = ImeAction.Done),
        keyboardActions = KeyboardActions(onDone = { submit() }),
        textStyle = MaterialTheme.typography.bodyLarge.copy(fontFamily = FontFamily.Monospace),
    )
    error?.let { Text(it.message, style = MaterialTheme.typography.bodySmall, color = Palette.danger) }
    Button(onClick = { submit() }, enabled = !busy && code.length >= 6, modifier = Modifier.fillMaxWidth().height(48.dp)) {
        if (busy) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp, color = androidx.compose.ui.graphics.Color.White) else Text("Verify")
    }
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
        TextButton(onClick = { recovery = !recovery; code = "" }) { Text(if (recovery) "Use authenticator code" else "Use a recovery code") }
        TextButton(onClick = { env.session.cancelSignIn() }) { Text("Back") }
    }
}

@Composable
private fun TwoFactorSetupStep() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    var setup by remember { mutableStateOf<AuthService.TwoFactorSetup?>(null) }
    var code by remember { mutableStateOf("") }
    var recoveryCodes by remember { mutableStateOf<List<String>>(emptyList()) }
    var error by remember { mutableStateOf<ApiError?>(null) }
    var busy by remember { mutableStateOf(false) }

    suspend fun begin() {
        error = null
        try { setup = env.session.beginTwoFactorSetup() } catch (e: ApiError) { error = e } catch (e: Exception) { error = ApiError.transport(e.message ?: "") }
    }
    LaunchedEffect(Unit) { begin() }

    fun confirm() {
        if (busy || code.length != 6) return
        busy = true; error = null
        scope.launch {
            try { recoveryCodes = env.session.confirmTwoFactorSetup(code) } catch (e: ApiError) { error = e } catch (e: Exception) { error = ApiError.transport(e.message ?: "") }
            busy = false
        }
    }

    Text("Set up two-step sign-in", style = MaterialTheme.typography.titleLarge)
    Text("Your role requires it. Add the Hub to an authenticator app, then enter the code it shows.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
    val s = setup
    when {
        s != null -> {
            s.otpauthUrl?.let { url -> Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) { QrCode(url, Modifier.size(180.dp)) } }
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text(s.secret, style = MaterialTheme.typography.bodyMedium.copy(fontFamily = FontFamily.Monospace), modifier = Modifier.weight(1f))
                TextButton(onClick = { copy(context, "Secret", s.secret) }) { Text("Copy") }
            }
            OutlinedTextField(
                value = code, onValueChange = { code = it.filter(Char::isDigit).take(6) }, label = { Text("Code") }, singleLine = true, modifier = Modifier.fillMaxWidth(),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword, imeAction = ImeAction.Done),
                keyboardActions = KeyboardActions(onDone = { confirm() }),
                textStyle = MaterialTheme.typography.bodyLarge.copy(fontFamily = FontFamily.Monospace),
            )
            error?.let { Text(it.message, style = MaterialTheme.typography.bodySmall, color = Palette.danger) }
            Button(onClick = { confirm() }, enabled = !busy && code.length == 6, modifier = Modifier.fillMaxWidth().height(48.dp)) {
                if (busy) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp, color = androidx.compose.ui.graphics.Color.White) else Text("Turn on and sign in")
            }
        }
        error != null -> ErrorState(error!!) { scope.launch { begin() } }
        else -> Box(Modifier.fillMaxWidth().padding(Space.l), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
    }
    TextButton(onClick = { env.session.cancelSignIn() }) { Text("Back") }

    if (recoveryCodes.isNotEmpty()) AlertDialog(
        onDismissRequest = {},
        title = { Text("Your recovery codes") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                Text("Keep these somewhere safe. Each one signs you in once if you lose your authenticator.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                Column(Modifier.fillMaxWidth().background(MaterialTheme.hub.field, MaterialTheme.shapes.small).padding(Space.m)) {
                    recoveryCodes.forEach { Text(it, style = MaterialTheme.typography.bodyMedium.copy(fontFamily = FontFamily.Monospace)) }
                }
            }
        },
        confirmButton = { TextButton(onClick = { recoveryCodes = emptyList() }) { Text("I've saved them") } },
        dismissButton = { TextButton(onClick = { copy(context, "Recovery codes", recoveryCodes.joinToString("\n")) }) { Text("Copy all") } },
    )
}

private fun copy(context: Context, label: String, text: String) {
    (context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager).setPrimaryClip(ClipData.newPlainText(label, text))
}
