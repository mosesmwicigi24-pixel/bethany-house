package ke.co.bethanyhouse.hub.ui.features.pos

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.RemoveCircleOutline
import androidx.compose.material.icons.filled.ShoppingCart
import androidx.compose.material.icons.filled.Storefront
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.runtime.toMutableStateList
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Omit
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.ErrorState
import ke.co.bethanyhouse.hub.ui.components.FieldError
import ke.co.bethanyhouse.hub.ui.components.LoadingSkeleton
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive

/**
 * The register gate (RegisterModal.tsx, MorningCheckGate.tsx): pick an
 * outlet, open a till with its float, resume the one already open, and the
 * morning check for a till left open from an earlier day.
 */
@Composable
internal fun PosRootScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val session = remember { PosSession(PosApi(env.client), env.offline, env.session.current.me, scope) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<ApiError?>(null) }
    var showOpen by remember { mutableStateOf(false) }

    suspend fun bootstrap() {
        loading = true
        try {
            try {
                session.outlets = session.api.outlets()
                error = null
            } catch (e: ApiError) { error = e; return } catch (e: Exception) { error = ApiError.transport(e.message ?: "Error"); return }
            val draftOutlet = session.restoreDraft()
            // Auto-resume: the outlet where THIS person already has an open till wins (PosPage.tsx auto-bypass).
            var chosen = session.outlets.firstOrNull { it.id == draftOutlet }
            if (chosen == null) {
                for (o in session.outlets) {
                    val st = runCatching { session.api.registerStatus(o.id) }.getOrNull() ?: continue
                    if (st["has_open_register"].bool == true || !st["stale_till"].isNull) { chosen = o; session.registerStatus = st; break }
                }
            }
            session.outlet = chosen ?: session.outlets.firstOrNull()
            if (session.registerStatus == null) session.refreshRegister()
            session.checkoutConfig = runCatching { session.api.checkoutConfig() }.getOrNull()
        } finally { loading = false }
    }

    LaunchedEffect(Unit) { bootstrap() }

    val stale = session.staleTill
    when {
        loading -> LoadingSkeleton()
        error != null && session.outlets.isEmpty() -> ErrorState(error!!) { scope.launch { bootstrap() } }
        session.outlets.isEmpty() -> EmptyState("No outlet assigned", "Your account is not assigned to an outlet. Ask an administrator before using the till.", Icons.Default.Storefront)
        stale != null -> MorningCheckView(session, stale)
        session.hasOpenRegister -> PosSaleScreen(session)
        else -> Box(Modifier.fillMaxSize().verticalScroll(rememberScrollState()), contentAlignment = Alignment.TopCenter) {
            Column(
                Modifier.widthIn(max = 480.dp).padding(Space.xxl),
                horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(Space.l),
            ) {
                Icon(Icons.Default.ShoppingCart, contentDescription = null, tint = Palette.brand, modifier = Modifier.size(40.dp))
                Text("Open your till", style = MaterialTheme.typography.headlineSmall)
                Text(
                    "One open till per person. Pick the outlet you are working at and enter the float in the drawer.",
                    style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary, textAlign = TextAlign.Center,
                )
                SelectField("Outlet", session.outlet?.id, session.outlets.map { it.id to it.name }, { id ->
                    session.outlet = session.outlets.firstOrNull { it.id == id }
                    scope.launch { session.refreshRegister() }
                }, Modifier.widthIn(max = 360.dp), placeholder = "Choose…")
                when {
                    session.ownerWatchesOnly -> OwnerWatchBanner()
                    env.session.current.can("pos.open_register") -> Button(onClick = { showOpen = true }, modifier = Modifier.widthIn(max = 360.dp).fillMaxWidth()) { Text("Open register") }
                    else -> Text("You hold pos.access but not pos.open_register — an outlet manager opens the till.", style = MaterialTheme.typography.labelSmall, color = Palette.warning, textAlign = TextAlign.Center)
                }
                if (session.register["status"].str == "counted") {
                    Text("Your last till at this outlet is counted and awaits verification.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary, textAlign = TextAlign.Center)
                }
            }
        }
    }
    if (showOpen) RegisterOpenSheet(session, onDismiss = { showOpen = false }) { scope.launch { session.refreshRegister() } }
}

@Composable
internal fun OwnerWatchBanner() {
    Row(
        Modifier.fillMaxWidth().background(Palette.amberSoft, MaterialTheme.shapes.small).padding(Space.m),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Default.Visibility, contentDescription = null, tint = Palette.amber, modifier = Modifier.size(16.dp))
        Spacer(Modifier.width(Space.s))
        Text(
            "The owner's account watches the till but does not transact (owner.no_transact). A cashier or outlet manager rings sales; you may approve with your PIN.",
            style = MaterialTheme.typography.labelSmall, color = Palette.amber,
        )
    }
}

// ── Shared sheet chrome and fields ───────────────────────────────────────────

/** The POS modal: title, subtitle, a scrolling body and a row of actions. */
@Composable
internal fun PosSheet(
    title: String,
    subtitle: String? = null,
    onDismiss: () -> Unit,
    actions: (@Composable () -> Unit)? = null,
    content: @Composable ColumnScope.() -> Unit,
) {
    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = Space.l).padding(bottom = Space.xl)) {
            Text(title, style = MaterialTheme.typography.titleLarge)
            if (subtitle != null) Text(subtitle, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
            Spacer(Modifier.height(Space.m))
            Column(Modifier.weight(1f, fill = false).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(Space.m)) { content() }
            if (actions != null) {
                Spacer(Modifier.height(Space.l))
                FlowRow(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s, Alignment.End), verticalArrangement = Arrangement.spacedBy(Space.s)) { actions() }
            }
        }
    }
}

/** A right-aligned decimal field; what is typed is what is sent. */
@Composable
internal fun AmountField(placeholder: String, text: String, onChange: (String) -> Unit, modifier: Modifier = Modifier, label: String? = null, enabled: Boolean = true) {
    OutlinedTextField(
        value = text, onValueChange = { onChange(it.filter { c -> c.isDigit() || c == '.' || c == ',' || c == '-' }) },
        placeholder = { Text(placeholder) }, label = label?.let { { Text(it) } }, singleLine = true, enabled = enabled,
        textStyle = TextStyle(textAlign = TextAlign.End, fontFamily = FontFamily.Monospace),
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
        modifier = modifier.widthIn(max = 200.dp),
    )
}

@Composable
internal fun LabelledRow(label: String, content: @Composable () -> Unit) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Text(label, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary, modifier = Modifier.weight(1f))
        content()
    }
}

@Composable
internal fun CaptionText(text: String, color: androidx.compose.ui.graphics.Color = MaterialTheme.hub.textTertiary) {
    Text(text, style = MaterialTheme.typography.labelSmall, color = color)
}

@Composable
internal fun ErrorText(e: ApiError?) {
    if (e != null && e.errors.isEmpty()) Text(e.message, style = MaterialTheme.typography.bodySmall, color = Palette.danger)
}

/**
 * GET /v1/settings/currencies (public): the codes the books can state in
 * shillings (`sellable`). Falls back to the outlet's own currency.
 */
internal suspend fun loadSellableCurrencies(client: ApiClient): List<String> {
    val rows = runCatching { client.get("/v1/settings/currencies").json["data"].records }.getOrNull() ?: return emptyList()
    return rows.mapNotNull { r ->
        val code = r["code"].str ?: return@mapNotNull null
        if (r["is_active"].bool == false) return@mapNotNull null
        if (r["sellable"].bool == false) return@mapNotNull null
        code.uppercase()
    }
}

// ── Open (POST pos/register/open {outlet_id, opening_cash, notes?, opening_floats?}) ──

private data class FloatRow(val code: String, val amount: String = "")

@Composable
internal fun RegisterOpenSheet(session: PosSession, onDismiss: () -> Unit, onOpened: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var openingCash by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    val floats = remember { mutableListOf<FloatRow>().toMutableStateList() }
    var sellable by remember { mutableStateOf<List<String>>(emptyList()) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<ApiError?>(null) }
    var addMenu by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { sellable = loadSellableCurrencies(env.client) }

    suspend fun submit() {
        val outlet = session.outlet ?: return
        val cash = amountJson(openingCash) ?: return
        busy = true
        try {
            val fl = floats.mapNotNull { f -> amountJson(f.amount)?.let { f.code to it } }.toMap()
            val r = session.api.openRegister(jsonOf(
                "outlet_id" to outlet.id, "opening_cash" to cash,
                "notes" to (notes.ifEmpty { null } ?: Omit), "opening_floats" to (if (fl.isEmpty()) Omit else JsonObject(fl)),
            ))
            env.toasts.show(r["message"].str ?: "Register opened")
            onOpened(); onDismiss()
        } catch (e: ApiError) { error = e } catch (e: Exception) { error = ApiError.transport(e.message ?: "Error") } finally { busy = false }
    }

    PosSheet("Open register", subtitle = session.outlet?.name, onDismiss = onDismiss, actions = {
        TextButton(onClick = onDismiss) { Text("Cancel") }
        Button(onClick = { scope.launch { submit() } }, enabled = !busy && amountJson(openingCash) != null) {
            if (busy) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp) else Text("Open register")
        }
    }) {
        LabelledRow("Float (${session.outlet?.currencyCode ?: "KES"})") { AmountField("0.00", openingCash, { openingCash = it }) }
        FieldError(error?.field("opening_cash"))
        floats.forEachIndexed { i, row ->
            LabelledRow("Float (${row.code})") {
                AmountField("0.00", row.amount, { v -> floats[i] = row.copy(amount = v) })
                IconButton(onClick = { floats.removeAt(i) }) { Icon(Icons.Default.RemoveCircleOutline, contentDescription = "Remove") }
            }
        }
        val remaining = sellable.filter { c -> c != session.outlet?.currencyCode && floats.none { it.code == c } }
        if (remaining.isNotEmpty()) Box {
            TextButton(onClick = { addMenu = true }) { Text("Add a float in another currency") }
            DropdownMenu(expanded = addMenu, onDismissRequest = { addMenu = false }) {
                remaining.forEach { c -> DropdownMenuItem(text = { Text(c) }, onClick = { floats += FloatRow(c); addMenu = false }) }
            }
        }
        OutlinedTextField(value = notes, onValueChange = { notes = it }, label = { Text("Notes (optional)") }, minLines = 2, modifier = Modifier.fillMaxWidth())
        ErrorText(error)
    }
}

// ── Close / count (POST pos/register/close — TillService::submitCount, blind) ──

private val KES_NOTES = listOf(1000, 500, 200, 100, 50, 40, 20, 10, 5, 1)

@Composable
internal fun RegisterCloseSheet(
    session: PosSession,
    /** The till being counted: the open register (or the stale one in the morning check). */
    outletId: Int,
    register: JsonElement?,
    eodSubmitted: Boolean,
    onDismiss: () -> Unit,
    onCounted: () -> Unit,
    onRequestEod: () -> Unit,
) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var closingCash by remember { mutableStateOf("") }
    var useDenominations by remember { mutableStateOf(false) }
    val denoms = remember { mutableStateMapOf<Int, String>() }
    val counts = remember { mutableStateMapOf<String, String>() }
    var notes by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<ApiError?>(null) }

    val home = register["currency_code"].str ?: "KES"
    val foreign = register["balances"].records.filter { it["is_home"].bool != true }
    val byDenomination = useDenominations && home == "KES"

    val complete = run {
        val homeOk = if (byDenomination) denoms.values.any { (it.toIntOrNull() ?: 0) > 0 } else amountJson(closingCash) != null
        val foreignOk = foreign.all { amountJson(counts[it["currency_code"].str ?: ""] ?: "") != null }
        homeOk && foreignOk
    }

    suspend fun submit() {
        busy = true
        try {
            val body = mutableMapOf<String, Any?>("outlet_id" to outletId, "notes" to (notes.ifEmpty { null } ?: Omit))
            if (byDenomination) {
                val dc = denoms.entries.mapNotNull { (d, q) -> q.toIntOrNull()?.takeIf { it > 0 }?.let { d.toString() to JsonPrimitive(it) } }.toMap()
                body["denomination_count"] = JsonObject(dc)
                body["closing_cash"] = 0   // derived server-side from the denominations
            } else {
                body["closing_cash"] = amountJson(closingCash) ?: Omit
            }
            if (foreign.isNotEmpty()) body["counts"] = JsonArray(foreign.mapNotNull { b ->
                val code = b["currency_code"].str ?: return@mapNotNull null
                val a = amountJson(counts[code] ?: "") ?: return@mapNotNull null
                jsonOf("currency_code" to code, "counted_cash" to a)
            })
            val r = session.api.closeRegister(jsonOf(*body.entries.map { it.key to it.value }.toTypedArray()))
            env.toasts.show(r["message"].str ?: "Count submitted")
            onCounted(); onDismiss()
        } catch (e: ApiError) {
            if (e.raw["requires_eod"].bool == true) { onDismiss(); onRequestEod() } else error = e
        } catch (e: Exception) { error = ApiError.transport(e.message ?: "Error") } finally { busy = false }
    }

    PosSheet("Count your till", subtitle = "A blind count: the expected figure and any variance are shown to your outlet manager when they verify it.", onDismiss = onDismiss, actions = {
        TextButton(onClick = onDismiss) { Text("Cancel") }
        Button(onClick = { scope.launch { submit() } }, enabled = !busy && eodSubmitted && complete) {
            if (busy) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp) else Text("Submit count")
        }
    }) {
        if (!eodSubmitted) Column(Modifier.fillMaxWidth().background(Palette.warningSoft, MaterialTheme.shapes.small).padding(Space.m), verticalArrangement = Arrangement.spacedBy(Space.s)) {
            Text("Submit your End of Day report before counting.", style = MaterialTheme.typography.bodySmall, color = Palette.warning)
            OutlinedButton(onClick = onRequestEod) { Text("Complete EoD report first") }
        }
        if (home == "KES") ToggleRow("Count by note and coin", useDenominations, { useDenominations = it })
        if (byDenomination) {
            TileGrid(minWidth = 120.dp) {
                KES_NOTES.forEach { d ->
                    OutlinedTextField(
                        value = denoms[d] ?: "", onValueChange = { denoms[d] = it.filter { c -> c.isDigit() } }, label = { Text("$d") }, singleLine = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.width(96.dp),
                    )
                }
            }
            CaptionText("The Hub adds the notes up; the count is what you typed here.")
        } else {
            LabelledRow("Counted cash ($home)") { AmountField("0.00", closingCash, { closingCash = it }) }
            FieldError(error?.field("closing_cash"))
        }
        foreign.forEach { b ->
            val code = b["currency_code"].str ?: "?"
            LabelledRow("Counted $code") { AmountField("0.00", counts[code] ?: "", { counts[code] = it }) }
        }
        if (foreign.isNotEmpty()) CaptionText("Each currency is counted apart; nothing is added across currencies.")
        FieldError(error?.field("counts"))
        OutlinedTextField(value = notes, onValueChange = { notes = it }, label = { Text("Closing notes (optional)") }, minLines = 2, modifier = Modifier.fillMaxWidth())
        ErrorText(error)
    }
}

// ── Morning check (TillGate: a till left open from an earlier day) ───────────

@Composable
internal fun MorningCheckView(session: PosSession, stale: JsonElement) {
    val scope = rememberCoroutineScope()
    var showReport by remember { mutableStateOf(false) }
    var showCount by remember { mutableStateOf(false) }
    /** GET pos/tills/{id}: the stale till's own currency and balances, so the count asks for each. */
    var till by remember { mutableStateOf<JsonElement?>(null) }
    val tradingDay = stale["trading_day"].str ?: "an earlier day"
    val reportDone = stale["eod_submitted"].bool ?: false

    LaunchedEffect(stale) {
        stale["id"].int?.let { id -> till = runCatching { session.api.client.get("${PosApi.BASE}/tills/$id").json["till"] }.getOrNull() }
    }

    Box(Modifier.fillMaxSize().verticalScroll(rememberScrollState()), contentAlignment = Alignment.TopCenter) {
        Column(Modifier.widthIn(max = 520.dp).padding(Space.xxl), verticalArrangement = Arrangement.spacedBy(Space.l)) {
            Text("Close your till from $tradingDay", style = MaterialTheme.typography.headlineSmall)
            Text(
                "A till of yours at ${stale["outlet_name"].str ?: "another outlet"} was left open. The Hub refuses every sale and payment until it is reported on and counted (TILL_FROM_EARLIER_DAY).",
                style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary,
            )
            MorningStep(1, "Write the End of Day report for $tradingDay", done = reportDone, active = !reportDone, hideAction = session.ownerWatchesOnly) { showReport = true }
            MorningStep(2, "Count the drawer", done = false, active = reportDone, hideAction = session.ownerWatchesOnly) { showCount = true }
            if (session.ownerWatchesOnly) OwnerWatchBanner()
        }
    }

    val oid = stale["outlet_id"].int
    val rid = stale["id"].int
    if (showReport && oid != null && rid != null) {
        UserEodSheet(session, outletId = oid, outletName = stale["outlet_name"].str ?: "", registerId = rid, fixedDate = stale["trading_day"].str, onDismiss = { showReport = false }) {
            scope.launch { session.refreshRegister() }
        }
    }
    if (showCount && oid != null) {
        RegisterCloseSheet(session, outletId = oid, register = till ?: stale, eodSubmitted = reportDone, onDismiss = { showCount = false },
            onCounted = { scope.launch { session.refreshRegister() } }, onRequestEod = { showReport = true })
    }
}

@Composable
private fun MorningStep(n: Int, title: String, done: Boolean, active: Boolean, hideAction: Boolean, action: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().background(MaterialTheme.hub.card, MaterialTheme.shapes.small)
            .border(1.dp, if (active) Palette.brand else MaterialTheme.hub.line, MaterialTheme.shapes.small).padding(Space.m),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.m),
    ) {
        if (done) Icon(Icons.Default.CheckCircle, contentDescription = null, tint = Palette.success, modifier = Modifier.size(22.dp))
        else Text("$n", style = MaterialTheme.typography.titleMedium, color = if (active) Palette.brand else MaterialTheme.hub.textTertiary)
        Text(title, style = MaterialTheme.typography.bodyMedium, textDecoration = if (done) TextDecoration.LineThrough else null, modifier = Modifier.weight(1f))
        if (active && !done && !hideAction) OutlinedButton(onClick = action) { Text("Do it") }
    }
}
