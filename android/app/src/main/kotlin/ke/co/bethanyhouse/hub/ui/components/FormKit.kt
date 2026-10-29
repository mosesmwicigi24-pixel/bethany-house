package ke.co.bethanyhouse.hub.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.AttachFile
import androidx.compose.material.icons.filled.ChevronRight
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.RemoveCircleOutline
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.rememberModalBottomSheetState
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.AppEnvironment
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.HubDates
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.dbl
import ke.co.bethanyhouse.hub.core.date
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import java.math.BigDecimal
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.util.Locale

// Shared form and list building blocks for every module (the Apple
// FeatureSupport / ModuleSupport / SharedUI, folded into one). Nothing
// here computes business data: a typed figure goes out as the person
// typed it and a displayed figure is the server's.

// ── JSON builders ────────────────────────────────────────────────────────────

object J {
    /** A string, or JSON null when blank. */
    fun s(v: String?): JsonElement = if (v.isNullOrEmpty()) JsonNull else JsonPrimitive(v)
    /** A string, or absent (null → key omitted by [obj]) when blank. */
    fun sOpt(v: String?): JsonElement? = if (v.isNullOrEmpty()) null else JsonPrimitive(v)
    fun i(v: Int?): JsonElement = v?.let { JsonPrimitive(it) } ?: JsonNull
    fun d(v: Double?): JsonElement = v?.let { JsonPrimitive(it) } ?: JsonNull
    fun b(v: Boolean): JsonElement = JsonPrimitive(v)
    fun ints(v: List<Int>): JsonElement = kotlinx.serialization.json.JsonArray(v.map { JsonPrimitive(it) })
    fun strs(v: List<String>): JsonElement = kotlinx.serialization.json.JsonArray(v.map { JsonPrimitive(it) })
    /** A number the person typed: sent as the digits they typed (the server validates). */
    fun typed(v: String): JsonElement = v.trim().let { if (it.isEmpty()) JsonNull else JsonPrimitive(it) }
    /** A decimal the person typed, as a JSON number (quantities, prices on a form), or null when blank/invalid. */
    fun decimal(text: String): JsonElement? = text.replace(",", "").trim().toBigDecimalOrNull()?.let { ke.co.bethanyhouse.hub.core.toJson(it) }
    /** Builds an object, leaving out null values. */
    fun obj(vararg pairs: Pair<String, JsonElement?>): JsonObject = JsonObject(pairs.mapNotNull { (k, v) -> v?.let { k to it } }.toMap())
    fun stringMap(pairs: List<Pair<String, String>>): JsonObject = JsonObject(pairs.filter { it.first.isNotBlank() }.associate { it.first.trim() to JsonPrimitive(it.second) })
}

fun String.toBigDecimalOrNull(): BigDecimal? = runCatching { BigDecimal(this) }.getOrNull()

/** `data` when wrapped, else the value itself as a list. */
val JsonElement?.listRecords: List<Record> get() = ((this["data"] as? kotlinx.serialization.json.JsonArray) ?: (this as? kotlinx.serialization.json.JsonArray))?.map(::Record) ?: emptyList()
val JsonElement?.message: String? get() = this["message"].str
/** Key/value rows of a `{k: v}` object, keys sorted. */
val JsonElement?.stringPairs: List<Pair<String, String>> get() = (this as? JsonObject)?.entries?.sortedBy { it.key }?.map { it.key to (it.value.str ?: "") } ?: emptyList()

/** "first_name last_name" from a user object, else `name`, else the string itself. */
fun personName(j: JsonElement?): String? {
    if (j == null || j is JsonNull) return null
    j["name"].str?.takeIf { it.isNotEmpty() }?.let { return it }
    val full = listOfNotNull(j["first_name"].str, j["last_name"].str).filter { it.isNotEmpty() }.joinToString(" ")
    return full.ifEmpty { j.str }
}

fun initials(name: String): String = name.split(" ").filter { it.isNotEmpty() }.take(2).mapNotNull { it.firstOrNull()?.uppercaseChar() }.joinToString("")

fun minutesText(m: Int?): String { m ?: return "—"; val h = m / 60; val r = m % 60; return if (r == 0) "${h}h" else "${h}h ${r.toString().padStart(2, '0')}m" }

/** A quantity exactly as the server sent it (int or decimal string), never re-rounded. */
fun quantityText(v: JsonElement?): String = v.str ?: "—"

fun fmtNumber(d: Double?, decimals: Int = 0): String { d ?: return "—"; return java.text.DecimalFormat(if (decimals == 0) "#,##0" else "#,##0." + "0".repeat(decimals), java.text.DecimalFormatSymbols(Locale.US)).format(d) }
fun fmtPct(d: Double?, decimals: Int = 1): String = d?.let { String.format(Locale.US, "%.${decimals}f%%", it) } ?: "—"
fun fmtInt(j: JsonElement?): String = j.int?.let { fmtNumber(it.toDouble()) } ?: j.dbl?.let { fmtNumber(it) } ?: "—"
fun fmtMoney(j: JsonElement?, currency: String = "KES"): String = ke.co.bethanyhouse.hub.core.Money.from(j, currency)?.formatted() ?: "—"

// ── Dates (Nairobi wall-clock, yyyy-MM-dd on the wire) ───────────────────────

object Day {
    private val fmt = DateTimeFormatter.ofPattern("yyyy-MM-dd")
    fun today(): String = HubDates.today().format(fmt)
    fun parse(s: String?): LocalDate? = s?.take(10)?.let { runCatching { LocalDate.parse(it, fmt) }.getOrNull() }
    fun string(d: LocalDate): String = d.format(fmt)
    fun add(s: String, days: Long): String = parse(s)?.plusDays(days)?.let(::string) ?: s
    fun monday(s: String): String = parse(s)?.let { string(it.minusDays((it.dayOfWeek.value - 1).toLong())) } ?: s
    fun firstOfMonth(s: String): String = s.take(7) + "-01"
    fun lastOfMonth(s: String): String = parse(firstOfMonth(s))?.let { string(it.withDayOfMonth(it.lengthOfMonth())) } ?: s
    fun format(s: String?, pattern: String = "EEE d MMM"): String {
        s ?: return "—"
        val instant = HubDates.parse(s) ?: return s
        return instant.atZone(HubDates.nairobi).format(DateTimeFormatter.ofPattern(pattern, Locale.UK))
    }
    fun time(s: String?) = format(s, "HH:mm")
    fun dateTime(s: String?) = format(s, "d MMM yyyy, HH:mm")
    fun year(s: String): Int = s.take(4).toIntOrNull() ?: HubDates.today().year
    fun thisYear(): Int = HubDates.today().year
}

// ── Remote JSON ──────────────────────────────────────────────────────────────

/** Fetches a JSON document, re-fetching when `path`, `query` or `version` change. `content` gets the value and a reload. */
@Composable
fun RemoteJson(
    path: String,
    query: Map<String, String?> = emptyMap(),
    version: Int = 0,
    emptyTitle: String = "Nothing here yet",
    isEmpty: (JsonElement) -> Boolean = { false },
    content: @Composable (JsonElement, reload: () -> Unit) -> Unit,
) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val q = query.entries.mapNotNull { (k, v) -> v?.let { k to it } }
    val resource = remember(path, q) { Resource(scope) { env.client.get(path, q).json ?: JsonNull } }
    LaunchedEffect(resource, version) { resource.reload() }
    LoadableView(resource.state, retry = { resource.load() }, isEmpty = isEmpty, empty = { EmptyState(emptyTitle) }) { json -> content(json) { resource.load() } }
}

// ── Buttons ──────────────────────────────────────────────────────────────────

/** Runs a suspend action, shows progress, reports failures as toasts. */
@Composable
fun AsyncButton(label: String, modifier: Modifier = Modifier, enabled: Boolean = true, destructive: Boolean = false, outlined: Boolean = false, action: suspend () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    val onClick = { busy = true; scope.launch { try { action() } catch (e: Exception) { env.toasts.error(e) } finally { busy = false } }; Unit }
    val inner: @Composable () -> Unit = { if (busy) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp) else Text(label) }
    if (outlined) OutlinedButton(onClick = onClick, enabled = enabled && !busy, modifier = modifier) { inner() }
    else Button(onClick = onClick, enabled = enabled && !busy, modifier = modifier, colors = if (destructive) ButtonDefaults.buttonColors(containerColor = Palette.danger) else ButtonDefaults.buttonColors()) { inner() }
}

@Composable
fun AsyncTextButton(label: String, enabled: Boolean = true, color: Color = MaterialTheme.colorScheme.primary, action: suspend () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    TextButton(enabled = enabled && !busy, onClick = { busy = true; scope.launch { try { action() } catch (e: Exception) { env.toasts.error(e) } finally { busy = false } } }) {
        if (busy) CircularProgressIndicator(Modifier.size(14.dp), strokeWidth = 2.dp) else Text(label, color = color)
    }
}

/** Toasts the server's `message` (or a fallback) after a write. */
fun toastResult(env: AppEnvironment, response: ApiClient.Response, fallback: String = "Saved") { env.toasts.show(response.json.message ?: fallback) }

// ── Sheets ───────────────────────────────────────────────────────────────────

/**
 * A modal form: title, scrolling body, Cancel / primary action, busy state and
 * the server's field errors handed to the body. `save` throws ApiError to keep
 * the sheet open with errors; returning normally dismisses it.
 */
@Composable
fun FormSheet(
    title: String,
    onDismiss: () -> Unit,
    saveLabel: String = "Save",
    canSave: Boolean = true,
    destructive: Boolean = false,
    save: suspend () -> Unit,
    content: @Composable ColumnScope.(ApiError?) -> Unit,
) {
    val scope = rememberCoroutineScope()
    var error by remember { mutableStateOf<ApiError?>(null) }
    var busy by remember { mutableStateOf(false) }
    val state = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = state) {
        Column(Modifier.fillMaxWidth().padding(horizontal = Space.l).padding(bottom = Space.xl)) {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text(title, style = MaterialTheme.typography.titleLarge, modifier = Modifier.weight(1f))
                TextButton(onClick = onDismiss, enabled = !busy) { Text("Cancel") }
            }
            Column(Modifier.weight(1f, fill = false).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(Space.m)) {
                content(error)
                error?.let { e -> if (e.errors.isEmpty()) Text(e.message, style = MaterialTheme.typography.bodySmall, color = Palette.danger) }
            }
            Spacer(Modifier.height(Space.l))
            Button(
                onClick = { busy = true; error = null; scope.launch { try { save(); onDismiss() } catch (e: ApiError) { error = e } catch (e: Exception) { error = ApiError.transport(e.message ?: "Error") } finally { busy = false } } },
                enabled = !busy && canSave, modifier = Modifier.fillMaxWidth(),
                colors = if (destructive) ButtonDefaults.buttonColors(containerColor = Palette.danger) else ButtonDefaults.buttonColors(),
            ) { if (busy) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp, color = Color.White) else Text(saveLabel) }
        }
    }
}

/** A sheet that asks for one line of text (a reason, a note) before an action. */
@Composable
fun TextPromptSheet(title: String, onDismiss: () -> Unit, placeholder: String = "Reason", required: Boolean = true, confirmLabel: String = "Confirm", destructive: Boolean = false, action: suspend (String) -> Unit) {
    var text by remember { mutableStateOf("") }
    FormSheet(title, onDismiss, saveLabel = confirmLabel, canSave = !required || text.isNotBlank(), destructive = destructive, save = { action(text.trim()) }) {
        OutlinedTextField(value = text, onValueChange = { text = it }, label = { Text(placeholder) }, minLines = 3, modifier = Modifier.fillMaxWidth())
    }
}

/** A read-only sheet (details, pickers) with a title and a close button. */
@Composable
fun InfoSheet(title: String, onDismiss: () -> Unit, content: @Composable ColumnScope.() -> Unit) {
    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = Space.l).padding(bottom = Space.xl).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(Space.m)) {
            Text(title, style = MaterialTheme.typography.titleLarge)
            content()
        }
    }
}

// ── Fields ───────────────────────────────────────────────────────────────────

@Composable
fun Field(label: String, value: String, onChange: (String) -> Unit, modifier: Modifier = Modifier, placeholder: String = "", error: String? = null, multiline: Boolean = false, mono: Boolean = false, keyboard: KeyboardType = KeyboardType.Text, enabled: Boolean = true) {
    Column(modifier.fillMaxWidth()) {
        OutlinedTextField(
            value = value, onValueChange = onChange, label = { Text(label) }, placeholder = { if (placeholder.isNotEmpty()) Text(placeholder) },
            singleLine = !multiline, minLines = if (multiline) 3 else 1, isError = error != null, enabled = enabled, modifier = Modifier.fillMaxWidth(),
            textStyle = if (mono) MaterialTheme.typography.bodyMedium.copy(fontFamily = FontFamily.Monospace) else MaterialTheme.typography.bodyMedium,
            keyboardOptions = KeyboardOptions(keyboardType = keyboard, imeAction = if (multiline) ImeAction.Default else ImeAction.Next),
        )
        FieldError(error)
    }
}

/** A decimal text field (money, quantities): what is typed is what is sent. */
@Composable
fun DecimalField(label: String, value: String, onChange: (String) -> Unit, modifier: Modifier = Modifier, error: String? = null, enabled: Boolean = true) =
    Field(label, value, { onChange(it.filter { c -> c.isDigit() || c == '.' || c == '-' }) }, modifier, error = error, keyboard = KeyboardType.Decimal, enabled = enabled)

@Composable
fun SecureFieldRow(label: String, value: String, onChange: (String) -> Unit, error: String? = null) {
    Column(Modifier.fillMaxWidth()) {
        OutlinedTextField(value = value, onValueChange = onChange, label = { Text(label) }, singleLine = true, visualTransformation = PasswordVisualTransformation(), isError = error != null, modifier = Modifier.fillMaxWidth(), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password))
        FieldError(error)
    }
}

/** A select of (wire value, label) options bound to a String; "" means none. */
@Composable
fun OptionPicker(label: String, value: String, options: List<Pair<String, String>>, onChange: (String) -> Unit, modifier: Modifier = Modifier, error: String? = null, allowNone: Boolean = false) {
    Column(modifier.fillMaxWidth()) {
        SelectField(label, value.ifEmpty { null }, options, { onChange(it ?: "") }, Modifier.fillMaxWidth(), placeholder = if (allowNone) "—" else "Choose…")
        FieldError(error)
    }
}

@Composable
fun OptionPickerValues(label: String, value: String, values: List<String>, onChange: (String) -> Unit, modifier: Modifier = Modifier, error: String? = null) =
    OptionPicker(label, value, values.map { it to it.humanized }, onChange, modifier, error)

@Composable
fun ToggleRow(label: String, on: Boolean, onChange: (Boolean) -> Unit, hint: String? = null) {
    Row(Modifier.fillMaxWidth().clickable { onChange(!on) }.padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(label, style = MaterialTheme.typography.bodyMedium)
            if (hint != null) Text(hint, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
        }
        Switch(checked = on, onCheckedChange = onChange)
    }
}

/** "yyyy-MM-dd" text bound to a date picker in Nairobi time. */
@Composable
fun DayField(label: String, day: String, onChange: (String) -> Unit, modifier: Modifier = Modifier, allowClear: Boolean = false) =
    DateField(label, Day.parse(day), { onChange(it?.let(Day::string) ?: "") }, modifier, allowClear)

/** A labelled slot with its 422 message underneath. */
@Composable
fun FieldBox(label: String, error: String? = null, content: @Composable ColumnScope.() -> Unit) {
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(label.uppercase(), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
        content()
        FieldError(error)
    }
}

/** Horizontal pill tabs. */
@Composable
fun <T> SegmentedTabs(tabs: List<Pair<T, String>>, selection: T, onSelect: (T) -> Unit) {
    Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(Space.xs)) {
        tabs.forEach { (v, label) ->
            val on = v == selection
            Surface(
                shape = CircleShape, color = if (on) Palette.brandDeep else MaterialTheme.hub.card,
                modifier = Modifier.border(1.dp, if (on) Color.Transparent else MaterialTheme.hub.line, CircleShape).clickable { onSelect(v) },
            ) { Text(label, style = MaterialTheme.typography.bodySmall, fontWeight = if (on) FontWeight.SemiBold else FontWeight.Normal, color = if (on) Color.White else MaterialTheme.hub.text, modifier = Modifier.padding(horizontal = 12.dp, vertical = 6.dp)) }
        }
    }
}

@Composable
fun ModuleSearchField(text: String, onChange: (String) -> Unit, placeholder: String, onSubmit: () -> Unit, modifier: Modifier = Modifier) {
    OutlinedTextField(
        value = text, onValueChange = onChange, placeholder = { Text(placeholder) }, singleLine = true, modifier = modifier.fillMaxWidth(),
        leadingIcon = { Icon(Icons.Default.Search, contentDescription = null, tint = MaterialTheme.hub.textTertiary) },
        trailingIcon = { if (text.isNotEmpty()) IconButton(onClick = { onChange(""); onSubmit() }) { Icon(Icons.Default.Close, contentDescription = "Clear") } },
        keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
        keyboardActions = androidx.compose.foundation.text.KeyboardActions(onSearch = { onSubmit() }),
    )
}

/** A caption stating the basis of a figure (reporting rate, payment date…). */
@Composable
fun BasisCaption(text: String?) {
    if (text.isNullOrEmpty()) return
    Row(verticalAlignment = Alignment.CenterVertically) {
        Icon(Icons.Default.Info, contentDescription = null, tint = MaterialTheme.hub.textTertiary, modifier = Modifier.size(14.dp))
        Spacer(Modifier.width(4.dp))
        Text(text, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
    }
}

@Composable
fun Pill(text: String, tone: StatusTone = StatusTone.NEUTRAL) = StatusBadge(text, tone)

@Composable
fun PriorityBadge(priority: String?) = StatusBadge(priority ?: "normal", when (priority ?: "") { "urgent" -> StatusTone.DANGER; "high" -> StatusTone.WARNING; "low" -> StatusTone.NEUTRAL; else -> StatusTone.INFO })

@Composable
fun Avatar(name: String, size: androidx.compose.ui.unit.Dp = 32.dp) {
    Box(Modifier.size(size).background(Palette.brandDeep, CircleShape), contentAlignment = Alignment.Center) {
        Text(initials(name), color = Color.White, style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
fun KeyValueGrid(pairs: List<Pair<String, String?>>) { Column { pairs.forEach { (k, v) -> DetailRow(k, v) } } }

/** A plain card row with title, subtitle and trailing content. */
@Composable
fun SupportListRow(title: String, subtitle: String? = null, onClick: (() -> Unit)? = null, trailing: @Composable () -> Unit = {}) {
    Surface(shape = MaterialTheme.shapes.small, color = MaterialTheme.hub.card, modifier = Modifier.fillMaxWidth().border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.small).then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier)) {
        Row(Modifier.padding(Space.m), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.m)) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(title, style = MaterialTheme.typography.bodyMedium, maxLines = 2, overflow = TextOverflow.Ellipsis)
                if (!subtitle.isNullOrEmpty()) Text(subtitle, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, maxLines = 2, overflow = TextOverflow.Ellipsis)
            }
            trailing()
        }
    }
}

/** A tappable card row for list screens. */
@Composable
fun ModuleRowCard(onClick: () -> Unit, content: @Composable ColumnScope.() -> Unit) {
    Surface(shape = MaterialTheme.shapes.small, color = MaterialTheme.hub.card, modifier = Modifier.fillMaxWidth().border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.small).clickable(onClick = onClick)) {
        Row(Modifier.padding(Space.m), verticalAlignment = Alignment.Top) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) { content() }
            Icon(Icons.Default.ChevronRight, contentDescription = null, tint = MaterialTheme.hub.textTertiary, modifier = Modifier.padding(top = 2.dp).size(18.dp))
        }
    }
}

@Composable
fun StatsRow(tiles: List<Triple<String, String, StatusTone?>>) {
    TileGrid { tiles.forEach { (l, v, t) -> StatTile(l, v, tone = t, modifier = TileModifier) } }
}

// ── Key/value editor ─────────────────────────────────────────────────────────

data class KeyValueRow(val id: Long = System.nanoTime(), val key: String = "", val value: String = "")

@Composable
fun KeyValueEditor(title: String, rows: List<KeyValueRow>, onChange: (List<KeyValueRow>) -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
        Text(title.uppercase(), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
        rows.forEach { row ->
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                OutlinedTextField(value = row.key, onValueChange = { k -> onChange(rows.map { if (it.id == row.id) it.copy(key = k) else it }) }, label = { Text("Field") }, singleLine = true, modifier = Modifier.weight(1f))
                OutlinedTextField(value = row.value, onValueChange = { v -> onChange(rows.map { if (it.id == row.id) it.copy(value = v) else it }) }, label = { Text("Value") }, singleLine = true, modifier = Modifier.weight(1f))
                IconButton(onClick = { onChange(rows.filterNot { it.id == row.id }) }) { Icon(Icons.Default.RemoveCircleOutline, contentDescription = "Remove", tint = Palette.danger) }
            }
        }
        TextButton(onClick = { onChange(rows + KeyValueRow()) }) { Icon(Icons.Default.Add, contentDescription = null, modifier = Modifier.size(16.dp)); Text(" Add row") }
    }
}

// ── Lookups ──────────────────────────────────────────────────────────────────

/** Loads a reference list once (outlets, users, products, suppliers, materials) for a picker. */
class Lookup(private val fetch: suspend () -> List<Record>) {
    var options: List<Record> by mutableStateOf(emptyList())
        private set
    var loaded: Boolean by mutableStateOf(false)
        private set
    suspend fun load() { if (loaded) return; options = runCatching { fetch() }.getOrDefault(emptyList()); loaded = true }
    fun label(id: Int?): String? = id?.let { i -> options.firstOrNull { it.intId == i }?.let(::title) }
    fun pairs(): List<Pair<Int, String>> = options.mapNotNull { r -> r.intId?.let { it to title(r) } }

    companion object {
        fun title(r: Record): String = r["en_translation"]["name"].str ?: r.string("name") ?: personName(r.json) ?: r.string("sku") ?: "#${r.id}"
        fun outlets(c: ApiClient) = Lookup { c.get("/v1/admin/outlets").json.listRecords }
        fun assignable(c: ApiClient) = Lookup { c.get("/v1/admin/users/assignable").json.listRecords }
        fun products(c: ApiClient, producibleOnly: Boolean = false) = Lookup { c.get("/v1/admin/products", mapOf("per_page" to "500", "is_producible" to if (producibleOnly) "1" else null)).json.listRecords }
        fun suppliers(c: ApiClient) = Lookup { c.get("/v1/admin/suppliers", mapOf("per_page" to "200", "status" to "active")).json.listRecords }
        fun materials(c: ApiClient) = Lookup { c.get("/v1/admin/inventory/materials", mapOf("per_page" to "200", "is_active" to "1")).json.listRecords }
        fun customers(c: ApiClient) = Lookup { c.get("/v1/admin/customers", mapOf("per_page" to "200")).json.listRecords }
    }
}

@Composable
fun LookupPicker(label: String, lookup: Lookup, selection: Int?, onChange: (Int?) -> Unit, modifier: Modifier = Modifier, allowNone: Boolean = true, error: String? = null) {
    LaunchedEffect(lookup) { lookup.load() }
    Column(modifier.fillMaxWidth()) {
        SelectField(label, selection, lookup.pairs(), onChange, Modifier.fillMaxWidth(), placeholder = if (allowNone) "—" else "Choose…")
        FieldError(error)
    }
}

/** Everyone a thing can be assigned to; `allowNone` offers "Nobody (open)". */
@Composable
fun StaffPicker(label: String, selection: Int?, onChange: (Int?) -> Unit, modifier: Modifier = Modifier, allowNone: Boolean = false) {
    val env = LocalEnv.current
    val lookup = remember { Lookup.assignable(env.client) }
    LaunchedEffect(Unit) { lookup.load() }
    val pairs = lookup.options.mapNotNull { r -> r.intId?.let { id -> id to (Lookup.title(r) + (r["outlet"]["name"].str?.let { " · $it" } ?: "")) } }
    SelectField(label, selection, pairs, onChange, modifier.fillMaxWidth(), placeholder = if (allowNone) "Nobody (open)" else "Choose…")
}

/** Outlets; defaults to the person's own outlet unless `allowAll`. */
@Composable
fun OutletChoice(label: String, selection: Int?, onChange: (Int?) -> Unit, modifier: Modifier = Modifier, allowAll: Boolean = false) {
    val env = LocalEnv.current
    val lookup = remember { Lookup.outlets(env.client) }
    LaunchedEffect(Unit) {
        lookup.load()
        if (selection == null && !allowAll) onChange(env.session.current.me?.outlet?.id ?: lookup.options.firstOrNull()?.intId)
    }
    SelectField(label, selection, lookup.pairs(), onChange, modifier.fillMaxWidth(), placeholder = if (allowAll) "All outlets" else "Choose…")
}

/** Outlet pairs for filters: the reports list (any report page) or POS outlets. */
suspend fun outletOptions(client: ApiClient, reports: Boolean): List<Pair<Int, String>> {
    val r = runCatching { client.get(if (reports) "/v1/admin/reports/outlets" else "/v1/admin/outlets", background = true) }.getOrNull() ?: return emptyList()
    return r.json.listRecords.mapNotNull { o -> o.intId?.let { it to (o.string("name") ?: "Outlet $it") } }
}

// ── Audit log, revisions ─────────────────────────────────────────────────────

/** `GET <path>` → `{logs: [{label, description, actor_name, created_at}]}`. */
@Composable
fun ModuleAuditLog(path: String) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val logs = remember(path) { Resource(scope) { env.client.get(path).json["logs"].listRecords } }
    LaunchedEffect(logs) { logs.load() }
    SectionCard("Audit log") {
        LoadableView(logs.state, retry = { logs.load() }, isEmpty = { it.isEmpty() }, empty = { Text("No activity recorded", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textTertiary) }) { rows ->
            rows.forEach { l ->
                Column(Modifier.padding(vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Row(Modifier.fillMaxWidth()) {
                        Text(l.string("label") ?: l.string("event")?.humanized ?: "Event", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                        DateText(l.date("created_at"))
                    }
                    l.string("description")?.takeIf { it.isNotEmpty() }?.let { Text(it, style = MaterialTheme.typography.bodySmall) }
                    Text(l.string("actor_name") ?: "System", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                }
                HorizontalDivider(color = MaterialTheme.hub.line)
            }
        }
    }
}

/** Document revisions (`{current_revision, revisions: [{revision, reason, changes, by, created_at}]}`). */
@Composable
fun RevisionsSection(path: String) {
    RemoteJson(path) { json, _ ->
        SectionCard("Revisions") {
            val revs = json["revisions"].records
            Text("Current: Rev ${json["current_revision"].int ?: 0}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
            if (revs.isEmpty()) Text("Issued as first written.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textTertiary)
            revs.forEach { r ->
                Column(Modifier.padding(vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Row(Modifier.fillMaxWidth()) {
                        Text("Rev ${r["revision"].int ?: 0}", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                        DateText(r["created_at"].date)
                    }
                    Text(r["reason"].str ?: "", style = MaterialTheme.typography.bodySmall)
                    r["changes"].records.forEach { c -> Text("• ${c.str ?: ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                    r["by"].str?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
                }
            }
        }
    }
}

// ── Attachments ──────────────────────────────────────────────────────────────

/** One button offering a file, the gallery or the camera; hands back files ready to upload. */
@Composable
fun AttachmentPicker(label: String = "Attach", images: Boolean = true, camera: Boolean = true, onPick: (List<PickedFile>) -> Unit) {
    var menu by remember { mutableStateOf(false) }
    val pickFile = rememberFilePicker(if (images) arrayOf("image/*", "application/pdf") else arrayOf("*/*")) { onPick(listOf(it)) }
    val capture = rememberPhotoCapture { onPick(listOf(it)) }
    Box {
        OutlinedButton(onClick = { menu = true }) { Icon(Icons.Default.AttachFile, contentDescription = null, modifier = Modifier.size(16.dp)); Text(" $label") }
        DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
            DropdownMenuItem(text = { Text("Choose a file…") }, onClick = { menu = false; pickFile() })
            if (images && camera) DropdownMenuItem(text = { Text("Take a photo…") }, onClick = { menu = false; capture() })
        }
    }
}

/** One-file multipart upload with optional text fields. */
suspend fun ApiClient.uploadFile(path: String, file: PickedFile, field: String = "file", fields: Map<String, String> = emptyMap(), method: ApiClient.Method = ApiClient.Method.POST): ApiClient.Response =
    upload(path, listOf(file.part(field)) + fields.map { (k, v) -> ApiClient.Part.field(k, v) }, method)

/** Shares text with the system share sheet. */
fun shareText(context: android.content.Context, text: String, title: String = "Share") {
    val i = android.content.Intent(android.content.Intent.ACTION_SEND).setType("text/plain").putExtra(android.content.Intent.EXTRA_TEXT, text)
    context.startActivity(android.content.Intent.createChooser(i, title).addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK))
}

@Composable
fun ShareTextButton(label: String, text: String) {
    val context = LocalContext.current
    OutlinedButton(onClick = { shareText(context, text, label) }) { Text(label) }
}

/** Whether the viewer may see unit cost / COGS figures (products.view_cost). */
val AppEnvironment.canViewCost: Boolean get() = session.current.can("products.view_cost")

/** Pretty-prints JSON for read-only inspection. */
fun prettyJson(v: JsonElement?): String = v?.let { kotlinx.serialization.json.Json { prettyPrint = true }.encodeToString(JsonElement.serializer(), it) } ?: ""
fun parseJsonObject(text: String): JsonObject? = ke.co.bethanyhouse.hub.core.HubJson.parseOrNull(text) as? JsonObject
