package ke.co.bethanyhouse.hub.ui.auth

import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Fingerprint
import androidx.compose.material.icons.filled.Key
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.Schedule
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.StepUpChallenge
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch

/**
 * The PIN lock (423 pin_locked), the step-up dialog (403 step_up_required),
 * the idle sign-out notice, the held-download notice and the biometric
 * re-lock. Mounted once by the shell over everything else.
 */
@Composable
fun SecurityPrompts() {
    val env = LocalEnv.current
    val snapshot by env.session.snapshot.collectAsStateWithLifecycle()
    when {
        snapshot.pinLocked -> PinLockOverlay()
        snapshot.idleLockedOut -> IdleLockedOutOverlay()
    }
    snapshot.stepUp?.let { StepUpDialog(it) }
    snapshot.heldDownload?.let { held ->
        AlertDialog(
            onDismissRequest = { env.session.clearHeldDownload() },
            title = { Text("This download needs approval") },
            text = { Text(held["message"].str ?: "An approver has to release this file before it can be downloaded. You'll be told when it is.") },
            confirmButton = { TextButton(onClick = { env.session.clearHeldDownload() }) { Text("OK") } },
        )
    }
    if (env.biometrics.locked) BiometricLockOverlay()
}

@Composable
private fun Scrim(content: @Composable () -> Unit) {
    Box(Modifier.fillMaxSize().background(Color.Black.copy(alpha = 0.55f)), contentAlignment = Alignment.Center) {
        Surface(shape = MaterialTheme.shapes.extraLarge, color = MaterialTheme.hub.card, shadowElevation = 12.dp, modifier = Modifier.padding(Space.xl).widthIn(max = 380.dp)) {
            Column(Modifier.padding(Space.xxl), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(Space.l)) { content() }
        }
    }
}

@Composable
private fun PinLockOverlay() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var pin by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    fun unlock() {
        if (busy || pin.length < 4) return
        busy = true; error = null
        scope.launch {
            try { env.session.unlock(pin); pin = "" } catch (e: ApiError) { error = e.message } catch (e: Exception) { error = e.message }
            busy = false
        }
    }
    Scrim {
        Icon(Icons.Default.Lock, contentDescription = null, tint = Palette.brand, modifier = Modifier.size(32.dp))
        Text("Session locked", style = MaterialTheme.typography.titleLarge)
        Text("Enter your terminal PIN to continue, ${env.session.current.me?.firstName ?: ""}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary, textAlign = TextAlign.Center)
        OutlinedTextField(
            value = pin, onValueChange = { pin = it.filter(Char::isDigit).take(8) }, label = { Text("PIN") }, singleLine = true,
            visualTransformation = PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword, imeAction = ImeAction.Done),
            keyboardActions = KeyboardActions(onDone = { unlock() }),
            textStyle = MaterialTheme.typography.bodyLarge.copy(fontFamily = FontFamily.Monospace),
            modifier = Modifier.widthIn(max = 180.dp),
        )
        error?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = Palette.danger) }
        Button(onClick = { unlock() }, enabled = !busy && pin.length >= 4, modifier = Modifier.fillMaxWidth()) {
            if (busy) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp, color = Color.White) else Text("Unlock")
        }
        TextButton(onClick = { scope.launch { env.session.abandonLock() } }) { Text("Sign out instead") }
    }
}

@Composable
private fun IdleLockedOutOverlay() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    Scrim {
        Icon(Icons.Default.Schedule, contentDescription = null, tint = Palette.amber, modifier = Modifier.size(32.dp))
        Text("You've been away a while", style = MaterialTheme.typography.titleLarge)
        Text("For your account's safety the session may have ended. Continue to check.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary, textAlign = TextAlign.Center)
        Button(onClick = { scope.launch { env.session.didBecomeActive() } }, modifier = Modifier.fillMaxWidth()) { Text("Continue") }
    }
}

/** "Confirm it's you": TOTP or password as the server asked, with its fallback. Passkeys are not yet wired on Android (see checklist). */
@Composable
private fun StepUpDialog(challenge: StepUpChallenge) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var method by remember(challenge) { mutableStateOf(if (challenge.method == StepUpChallenge.Method.PASSKEY) (challenge.fallback ?: StepUpChallenge.Method.PASSWORD) else challenge.method) }
    var secret by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    fun submit() {
        if (busy) return
        busy = true; error = null
        scope.launch {
            try { env.session.stepUp(if (method == StepUpChallenge.Method.PASSWORD) secret else null, if (method == StepUpChallenge.Method.TOTP) secret else null) }
            catch (e: ApiError) { error = e.message } catch (e: Exception) { error = e.message }
            busy = false
        }
    }
    AlertDialog(
        onDismissRequest = { scope.launch { env.session.cancelStepUp() } },
        icon = { Icon(Icons.Default.Key, contentDescription = null, tint = Palette.brand) },
        title = { Text("Confirm it's you") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(Space.m)) {
                Text(challenge.reason ?: "This action needs you to confirm your identity.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                if (challenge.method == StepUpChallenge.Method.PASSKEY) Text("Passkeys aren't available in this app yet; use the fallback below.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
                OutlinedTextField(
                    value = secret, onValueChange = { secret = it }, singleLine = true,
                    label = { Text(if (method == StepUpChallenge.Method.TOTP) "Authenticator code" else "Password") },
                    visualTransformation = if (method == StepUpChallenge.Method.PASSWORD) PasswordVisualTransformation() else androidx.compose.ui.text.input.VisualTransformation.None,
                    keyboardOptions = KeyboardOptions(keyboardType = if (method == StepUpChallenge.Method.TOTP) KeyboardType.NumberPassword else KeyboardType.Password, imeAction = ImeAction.Done),
                    keyboardActions = KeyboardActions(onDone = { submit() }),
                )
                error?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = Palette.danger) }
                val fb = challenge.fallback
                if (fb != null && fb != method && fb != StepUpChallenge.Method.PASSKEY) TextButton(onClick = { method = fb; secret = "" }) { Text("Use ${if (fb == StepUpChallenge.Method.PASSWORD) "password" else "authenticator code"} instead") }
            }
        },
        confirmButton = { TextButton(enabled = !busy && secret.isNotEmpty(), onClick = { submit() }) { Text("Confirm") } },
        dismissButton = { TextButton(onClick = { scope.launch { env.session.cancelStepUp() } }) { Text("Cancel") } },
    )
}

/** Device boundary after the background grace; the server session is untouched. */
@Composable
private fun BiometricLockOverlay() {
    val env = LocalEnv.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var failed by remember { mutableStateOf(false) }
    fun prompt() {
        val activity = context as? FragmentActivity ?: run { env.biometrics.unlocked(); return }
        val manager = BiometricManager.from(context)
        val allowed = BiometricManager.Authenticators.BIOMETRIC_WEAK or BiometricManager.Authenticators.DEVICE_CREDENTIAL
        if (manager.canAuthenticate(allowed) != BiometricManager.BIOMETRIC_SUCCESS) { env.biometrics.unlocked(); return }
        val info = BiometricPrompt.PromptInfo.Builder().setTitle("Unlock the Hub").setSubtitle("Bethany House").setAllowedAuthenticators(allowed).build()
        BiometricPrompt(activity, ContextCompat.getMainExecutor(context), object : BiometricPrompt.AuthenticationCallback() {
            override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) { env.biometrics.unlocked() }
            override fun onAuthenticationError(errorCode: Int, errString: CharSequence) { failed = true }
        }).authenticate(info)
    }
    LaunchedEffect(Unit) { prompt() }
    Scrim {
        Icon(Icons.Default.Fingerprint, contentDescription = null, tint = Palette.brand, modifier = Modifier.size(40.dp))
        Text("Unlock the Hub", style = MaterialTheme.typography.titleLarge)
        if (failed) Button(onClick = { failed = false; prompt() }, modifier = Modifier.fillMaxWidth()) { Text("Try again") }
        TextButton(onClick = { scope.launch { env.session.signOut(); env.biometrics.unlocked() } }) { Text("Sign out") }
    }
}
