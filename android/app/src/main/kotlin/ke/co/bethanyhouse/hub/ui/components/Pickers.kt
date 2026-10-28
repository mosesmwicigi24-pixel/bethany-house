package ke.co.bethanyhouse.hub.ui.components

import android.content.Context
import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material3.DatePicker
import androidx.compose.material3.DatePickerDialog
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.rememberDatePickerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.HubDates
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneOffset

/** A date field in Nairobi wall-clock terms (YYYY-MM-DD on the wire). */
@Composable
fun DateField(label: String, value: LocalDate?, onChange: (LocalDate?) -> Unit, modifier: Modifier = Modifier, allowClear: Boolean = true) {
    var open by remember { mutableStateOf(false) }
    OutlinedButton(onClick = { open = true }, modifier = modifier) { Text(if (value == null) label else "$label: ${HubDates.dayString(value)}") }
    if (open) {
        val state = rememberDatePickerState(initialSelectedDateMillis = value?.atStartOfDay(ZoneOffset.UTC)?.toInstant()?.toEpochMilli())
        DatePickerDialog(
            onDismissRequest = { open = false },
            confirmButton = { TextButton(onClick = { open = false; onChange(state.selectedDateMillis?.let { Instant.ofEpochMilli(it).atZone(ZoneOffset.UTC).toLocalDate() }) }) { Text("OK") } },
            dismissButton = { Row { if (allowClear) TextButton(onClick = { open = false; onChange(null) }) { Text("Clear") }; TextButton(onClick = { open = false }) { Text("Cancel") } } },
        ) { DatePicker(state = state) }
    }
}

/** A select backed by (wire value, label) options. */
@Composable
fun <T> SelectField(label: String, value: T?, options: List<Pair<T, String>>, onChange: (T?) -> Unit, modifier: Modifier = Modifier, placeholder: String = "Any") {
    var expanded by remember { mutableStateOf(false) }
    ExposedDropdownMenuBox(expanded = expanded, onExpandedChange = { expanded = it }, modifier = modifier) {
        OutlinedTextField(
            value = options.firstOrNull { it.first == value }?.second ?: placeholder, onValueChange = {}, readOnly = true, label = { Text(label) }, singleLine = true,
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded) }, modifier = Modifier.menuAnchor().fillMaxWidth(),
        )
        DropdownMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
            DropdownMenuItem(text = { Text(placeholder) }, onClick = { onChange(null); expanded = false })
            options.forEach { (v, l) -> DropdownMenuItem(text = { Text(l) }, onClick = { onChange(v); expanded = false }) }
        }
    }
}

/** Picked file as multipart part (images from the gallery or camera, PDFs). */
data class PickedFile(val name: String, val bytes: ByteArray, val mimeType: String) {
    fun part(field: String) = ApiClient.Part.file(field, bytes, name, mimeType)
}

fun readPicked(context: Context, uri: Uri): PickedFile? {
    val resolver = context.contentResolver
    val bytes = resolver.openInputStream(uri)?.use { it.readBytes() } ?: return null
    val mime = resolver.getType(uri) ?: "application/octet-stream"
    var name = uri.lastPathSegment ?: "file"
    resolver.query(uri, arrayOf(android.provider.OpenableColumns.DISPLAY_NAME), null, null, null)?.use { c -> if (c.moveToFirst()) name = c.getString(0) ?: name }
    return PickedFile(name, bytes, mime)
}

/** Returns a launcher for picking an image or document; `onPicked` receives the bytes ready to upload. */
@Composable
fun rememberFilePicker(mimeTypes: Array<String> = arrayOf("image/*", "application/pdf"), onPicked: (PickedFile) -> Unit): () -> Unit {
    val context = LocalContext.current
    val launcher = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri -> uri?.let { readPicked(context, it) }?.let(onPicked) }
    return { launcher.launch(mimeTypes) }
}

/** Camera capture for receipts and QC photos; returns a JPEG ready to upload. */
@Composable
fun rememberPhotoCapture(onCaptured: (PickedFile) -> Unit): () -> Unit {
    val context = LocalContext.current
    var target by remember { mutableStateOf<Uri?>(null) }
    val launcher = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { ok ->
        val uri = target
        if (ok && uri != null) readPicked(context, uri)?.let { onCaptured(it.copy(name = "photo-${System.currentTimeMillis()}.jpg", mimeType = "image/jpeg")) }
    }
    return {
        val dir = java.io.File(context.cacheDir, "documents").apply { mkdirs() }
        val f = java.io.File(dir, "capture-${System.currentTimeMillis()}.jpg")
        val uri = androidx.core.content.FileProvider.getUriForFile(context, context.packageName + ".files", f)
        target = uri
        launcher.launch(uri)
    }
}
