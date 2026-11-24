package ke.co.bethanyhouse.hub.ui

import android.content.Context
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlin.math.roundToInt

/** Device-local display preference; a null reference follows Android's font setting. */
class TailorsTextSize internal constructor(private val context: Context) {
    private val preferences = context.getSharedPreferences("tailors_text_size", Context.MODE_PRIVATE)
    var useDevice by mutableStateOf(preferences.getBoolean("use_device", true))
        private set
    var reference by mutableIntStateOf(preferences.getInt("reference", 17).coerceIn(9, 36))
        private set

    fun followDevice(enabled: Boolean) {
        useDevice = enabled
        preferences.edit().putBoolean("use_device", enabled).apply()
    }

    fun chooseReference(value: Int) {
        reference = value.coerceIn(9, 36)
        useDevice = false
        preferences.edit().putInt("reference", reference).putBoolean("use_device", false).apply()
    }
}

val LocalTailorsTextSize = staticCompositionLocalOf<TailorsTextSize?> { null }

@Composable
fun rememberTailorsTextSize(): TailorsTextSize {
    val context = LocalContext.current.applicationContext
    return remember(context) { TailorsTextSize(context) }
}

@Composable
fun TailorsTextSizeDialog(settings: TailorsTextSize, onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        containerColor = MaterialTheme.hub.card,
        title = { Text("Text size") },
        text = {
            Column(Modifier.heightIn(max = 420.dp).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text("Choose a comfortable size for text throughout Tailors Hub.")
                Text("Your workshop, at a glance.", style = MaterialTheme.typography.bodyLarge)
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                    Text("Use device setting", modifier = Modifier.weight(1f))
                    Switch(checked = settings.useDevice, onCheckedChange = settings::followDevice)
                }
                Slider(
                    value = settings.reference.toFloat(),
                    onValueChange = { settings.chooseReference(it.roundToInt()) },
                    valueRange = 9f..36f,
                    steps = 26,
                    colors = SliderDefaults.colors(thumbColor = Palette.brandDeep, activeTrackColor = Palette.brandDeep, inactiveTrackColor = MaterialTheme.hub.line),
                    modifier = Modifier.semantics {
                        contentDescription = "Text size reference"
                        stateDescription = if (settings.useDevice) "Device setting" else "${settings.reference} point sample"
                    },
                )
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                    Text("9", fontSize = 12.sp)
                    Text(if (settings.useDevice) "Device setting" else "${settings.reference} pt sample", fontSize = 12.sp)
                    Text("36", fontSize = 12.sp)
                }
                Text("App text scales from this sample. Use device setting follows Android's accessibility setting.", style = MaterialTheme.typography.bodySmall)
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text("Done") } },
    )
}
