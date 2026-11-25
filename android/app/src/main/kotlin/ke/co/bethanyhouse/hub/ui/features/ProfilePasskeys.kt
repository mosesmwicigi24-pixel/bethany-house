package ke.co.bethanyhouse.hub.ui.features

import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.arr
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.auth.AndroidPasskeys
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement

@Composable
internal fun ProfilePasskeys() {
    val env = LocalEnv.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var rows by remember { mutableStateOf<List<JsonElement>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var adding by remember { mutableStateOf(false) }
    var removing by remember { mutableStateOf<JsonElement?>(null) }
    var name by remember { mutableStateOf("") }
    fun load() { scope.launch {
        try { rows = env.client.get("/v1/admin/profile/passkeys").json["data"].arr ?: throw IllegalStateException("The passkey list could not be read."); error = null }
        catch (e: CancellationException) { throw e }
        catch (e: Exception) { error = e.message ?: "Could not load passkeys." }
    } }
    LaunchedEffect(Unit) { load() }
    Text("Passkeys")
    Text("Use a passkey to confirm it's you. Passkeys added here or in the console work in both.")
    if (rows == null && error == null) CircularProgressIndicator()
    if (rows?.isEmpty() == true) Text("No passkeys added yet.")
    rows?.forEach { row ->
        Row(Modifier.fillMaxWidth()) {
            Text(row["name"].str ?: "Passkey", Modifier.weight(1f))
            TextButton(onClick = { removing = row }, enabled = !busy && row["id"].int != null) { Text("Remove") }
        }
    }
    error?.let { Text(it); TextButton(onClick = { load() }) { Text("Retry") } }
    OutlinedButton(onClick = { name = ""; adding = true }, enabled = !busy) { Text("Add a passkey…") }
    if (adding) AlertDialog(onDismissRequest = { if (!busy) adding = false }, title = { Text("Add a passkey") },
        text = { OutlinedTextField(name, { name = it.take(80) }, label = { Text("Passkey name") }, singleLine = true) },
        confirmButton = { TextButton(enabled = !busy && name.isNotBlank(), onClick = {
            busy = true; error = null
            scope.launch {
                try { AndroidPasskeys(context, env.client).register(name.trim()); adding = false; load() }
                catch (e: CancellationException) { throw e }
                catch (e: Exception) { error = e.message ?: "Passkey could not be added."; adding = false }
                finally { busy = false }
            }
        }) { Text("Add") } }, dismissButton = { TextButton(enabled = !busy, onClick = { adding = false }) { Text("Cancel") } })
    removing?.let { row -> AlertDialog(onDismissRequest = { if (!busy) removing = null }, title = { Text("Remove passkey?") },
        text = { Text("Remove ${row["name"].str ?: "this passkey"}? You can add it again later.") },
        confirmButton = { TextButton(enabled = !busy, onClick = {
            busy = true; error = null
            scope.launch {
                try { env.client.delete("/v1/admin/profile/passkeys/${row["id"].int}"); removing = null; load() }
                catch (e: CancellationException) { throw e }
                catch (e: Exception) { error = e.message ?: "Passkey could not be removed."; removing = null }
                finally { busy = false }
            }
        }) { Text("Remove") } }, dismissButton = { TextButton(enabled = !busy, onClick = { removing = null }) { Text("Cancel") } }) }
}
