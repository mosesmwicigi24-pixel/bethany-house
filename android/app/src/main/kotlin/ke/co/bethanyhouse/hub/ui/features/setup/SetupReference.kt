package ke.co.bethanyhouse.hub.ui.features.setup

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.PowerSettingsNew
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.arr
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.dbl
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.AsyncButton
import ke.co.bethanyhouse.hub.ui.components.AsyncTextButton
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Pill
import ke.co.bethanyhouse.hub.ui.components.RemoteJson
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.SupportListRow
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.message
import ke.co.bethanyhouse.hub.ui.components.toastResult
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonPrimitive
import java.util.Locale

/**
 * Reference data: countries, currencies, languages, tax rates and the
 * discount limits. The two currency rates are separate columns and the
 * server turns a rate change into a proposal (Phase 3C); the screen shows
 * the server's answer and never converts money itself.
 */

// ── Countries (GET /countries → {data, grouped, stats}; POST; PUT /{code}; PUT /{code}/toggle) ──

private val countryText = listOf("name" to "Name", "native_name" to "Native name", "phone_code" to "Phone code", "flag" to "Flag emoji", "region" to "Region", "subregion" to "Subregion", "default_currency_code" to "Default currency", "free_shipping_threshold" to "Free shipping over", "standard_shipping_cost" to "Standard shipping cost", "express_shipping_cost" to "Express shipping cost", "estimated_delivery_days" to "Delivery days")
private val countryNumbers = setOf("free_shipping_threshold", "standard_shipping_cost", "express_shipping_cost", "estimated_delivery_days")

@Composable
fun CountriesScreen() {
    val env = LocalEnv.current
    var version by remember { mutableIntStateOf(0) }
    var editing by remember { mutableStateOf<Record?>(null) }
    var adding by remember { mutableStateOf(false) }
    var filter by remember { mutableStateOf("") }
    val canEdit = env.setupPermissions.canAny(listOf("settings.edit", "setup.technical"))

    Page("Countries", subtitle = "Where we sell and ship", refresh = { version++ }, actions = { if (canEdit) Button(onClick = { adding = true }) { Text("Add country") } }) {
        ModuleSearchField(filter, { filter = it }, "Filter", onSubmit = {})
        RemoteJson("/v1/admin/countries", version = version) { j, reload ->
            val s = j["stats"]
            Row(horizontalArrangement = Arrangement.spacedBy(Space.xs)) { Pill("${s["total"].int ?: 0} countries"); Pill("${s["active"].int ?: 0} active", StatusTone.SUCCESS); Pill("${s["shipping_enabled"].int ?: 0} ship", StatusTone.INFO) }
            j["data"].toRecords().filter { filter.isEmpty() || (it.string("name") ?: "").contains(filter, true) || (it.string("code") ?: "").contains(filter, true) }.forEach { c ->
                val ships = if (c.bool("is_shipping_enabled") == true) " · ships (std ${c["standard_shipping_cost"].str ?: "—"}, express ${c["express_shipping_cost"].str ?: "—"}, free over ${c["free_shipping_threshold"].str ?: "—"}, ${c["estimated_delivery_days"].str ?: "—"} days)" else ""
                SupportListRow("${c.string("flag") ?: ""} ${c.string("name") ?: ""} (${c.string("code") ?: ""})", subtitle = "${c.string("region") ?: ""} · ${c.string("default_currency_code") ?: "—"} · +${c.string("phone_code") ?: ""}$ships") {
                    Column(horizontalAlignment = Alignment.End) {
                        StatusBadge(if (c.bool("is_active") == true) "active" else "inactive")
                        if (canEdit) Row(verticalAlignment = Alignment.CenterVertically) {
                            TextButton(onClick = { editing = c }) { Text("Edit") }
                            SetupIconAction(Icons.Default.PowerSettingsNew, "Toggle", tint = MaterialTheme.hub.textSecondary) { val r = env.client.put("/v1/admin/countries/${c.string("code") ?: ""}/toggle"); toastResult(env, r); reload() }
                        }
                    }
                }
            }
        }
    }
    if (adding) CountrySheet(null, onDismiss = { adding = false }) { version++ }
    editing?.let { c -> CountrySheet(c, onDismiss = { editing = null }) { version++ } }
}

@Composable
private fun CountrySheet(record: Record?, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    val code0 = record?.string("code")
    val f = remember { SetupForm(buildMap { if (record != null) countryText.forEach { (k, _) -> put(k, record[k].str ?: "") } }) }
    var code by remember { mutableStateOf("") }
    var active by remember { mutableStateOf(record?.bool("is_active") ?: true) }
    var shipping by remember { mutableStateOf(record?.bool("is_shipping_enabled") ?: false) }
    FormSheet(if (code0 == null) "Add country" else "Edit $code0", onDismiss, canSave = f["name"].isNotEmpty() && (code0 != null || code.length >= 2), save = {
        val pairs = countryText.map { (k, _) -> k to (if (k in countryNumbers) J.typed(f[k]) else J.s(f[k])) }.toMutableList<Pair<String, JsonElement?>>()
        pairs += "is_active" to J.b(active); pairs += "is_shipping_enabled" to J.b(shipping)
        val r = if (code0 != null) env.client.put("/v1/admin/countries/$code0", J.obj(*pairs.toTypedArray()))
        else { pairs += "code" to J.s(code.uppercase()); env.client.post("/v1/admin/countries", J.obj(*pairs.toTypedArray())) }
        toastResult(env, r); saved()
    }) { e ->
        if (code0 == null) Field("ISO code", code, { code = it }, placeholder = "KE", error = e?.field("code"))
        countryText.forEach { (k, label) -> Field(label, f[k], { f[k] = it }, error = e?.field(k)) }
        ToggleRow("Active", active, { active = it })
        ToggleRow("Shipping enabled", shipping, { shipping = it })
        Spacer(Modifier.height(Space.xs))
    }
}

// ── Currencies (GET /currencies-management; PUT /{id} with rates → proposals) ──

@Composable
fun CurrenciesScreen() {
    val env = LocalEnv.current
    var version by remember { mutableIntStateOf(0) }
    var editing by remember { mutableStateOf<Record?>(null) }
    var adding by remember { mutableStateOf(false) }
    val s = env.session.current
    val canPropose = env.setupPermissions.canAny(listOf("settings.edit", "settings.financial_propose", "settings.pricing_rate_propose"))

    Page("Currencies", subtitle = "Pricing rate (what customers are quoted) and reporting rate (what reports convert at) are separate", refresh = { version++ }, actions = {
        if (s.can("settings.edit")) Button(onClick = { adding = true }) { Text("Add currency") }
    }) {
        RemoteJson("/v1/admin/currencies-management", version = version) { j, reload ->
            j["data"].toRecords().forEach { c ->
                val hasReporting = !c["reporting_rate_to_kes"].isNull
                SectionCard {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.xs)) {
                        Text("${c.string("code") ?: ""} · ${c.string("name") ?: ""}", style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
                        if (c.bool("is_default") == true || c.bool("is_base") == true) Pill("base", StatusTone.INFO)
                        StatusBadge(if (c.bool("is_active") == true) "active" else "inactive")
                    }
                    SetupSecondary("Pricing rate (base-relative): ${c["exchange_rate"].str ?: "—"} · Reporting rate: ${c["reporting_rate_to_kes"].str?.let { "KES $it per 1" } ?: "not set — reported apart"}", if (hasReporting) MaterialTheme.hub.textSecondary else Palette.warning)
                    SetupCaption("Symbol ${c.string("symbol") ?: ""} (${c.string("symbol_position") ?: "before"}) · ${c["decimal_places"].str ?: "2"} decimals")
                    androidx.compose.foundation.layout.FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.xs), verticalArrangement = Arrangement.spacedBy(Space.xs)) {
                        if (canPropose) TextButton(onClick = { editing = c }) { Text("Edit") }
                        if (s.can("settings.edit")) {
                            AsyncTextButton("Toggle") { val r = env.client.put("/v1/admin/currencies-management/${c.id}/toggle"); toastResult(env, r); reload() }
                            if (c.bool("is_default") != true) AsyncTextButton("Make default") { val r = env.client.put("/v1/admin/currencies-management/${c.id}/set-default"); toastResult(env, r); reload() }
                            ConfirmButton("Delete currency", "Only a currency with no sales can go.", destructive = true, action = { try { val r = env.client.delete("/v1/admin/currencies-management/${c.id}"); toastResult(env, r); reload() } catch (e: Exception) { env.toasts.error(e) } }) { Text("Delete") }
                        }
                    }
                }
            }
        }
    }
    if (adding) CurrencySheet(null, onDismiss = { adding = false }) { version++ }
    editing?.let { c -> CurrencySheet(c, onDismiss = { editing = null }) { version++ } }
}

/** "KES per 1 unit" as the console shows it; the API keeps the inverse (base-relative). */
private fun kesPerUnitText(storedRate: Double?): String = if (storedRate != null && storedRate > 0) String.format(Locale.US, "%.2f", Math.round(1 / storedRate * 100) / 100.0) else "1"

/** Mirrors CurrenciesPage.toPayload: an untouched figure sends the stored rate back exactly, so a 2-decimal round trip never reads as a rate change. */
private fun exchangeRatePayload(storedRate: Double?, kesPerUnit: String): JsonElement {
    val typed = kesPerUnit.toDoubleOrNull() ?: 0.0
    if (storedRate != null && storedRate > 0 && typed == Math.round(1 / storedRate * 100) / 100.0) return JsonPrimitive(storedRate)
    if (typed <= 0) return JsonNull
    return JsonPrimitive(Math.round(1 / typed * 1_000_000) / 1_000_000.0)
}

@Composable
private fun CurrencySheet(record: Record?, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    val storedRate = record?.get("exchange_rate").dbl
    var code by remember { mutableStateOf(record?.string("code") ?: "") }
    var name by remember { mutableStateOf(record?.string("name") ?: "") }
    var symbol by remember { mutableStateOf(record?.string("symbol") ?: "") }
    var kesPerUnit by remember { mutableStateOf(if (record != null) kesPerUnitText(storedRate) else "") }
    var reportingRate by remember { mutableStateOf(record?.get("reporting_rate_to_kes").str ?: "") }
    var decimals by remember { mutableStateOf(record?.get("decimal_places").str ?: "2") }
    var thousands by remember { mutableStateOf(record?.string("thousand_separator") ?: ",") }
    var decimalSep by remember { mutableStateOf(record?.string("decimal_separator") ?: ".") }
    var position by remember { mutableStateOf(record?.string("symbol_position") ?: "before") }
    var isDefault by remember { mutableStateOf(record?.bool("is_default") ?: false) }
    var active by remember { mutableStateOf(record?.bool("is_active") ?: true) }
    var effectiveFrom by remember { mutableStateOf("") }

    FormSheet(if (record == null) "Add currency" else "Edit $code", onDismiss, canSave = code.isNotEmpty() && name.isNotEmpty(), save = {
        val pairs = mutableListOf<Pair<String, JsonElement?>>(
            "code" to JsonPrimitive(code.uppercase()), "name" to JsonPrimitive(name), "symbol" to JsonPrimitive(symbol),
            "exchange_rate" to exchangeRatePayload(storedRate, kesPerUnit), "reporting_rate_to_kes" to J.typed(reportingRate),
            "decimal_places" to J.typed(decimals), "thousand_separator" to JsonPrimitive(thousands), "decimal_separator" to JsonPrimitive(decimalSep),
            "symbol_position" to JsonPrimitive(position), "is_default" to J.b(isDefault), "is_active" to J.b(active),
        )
        val r = if (record != null) {
            pairs += "effective_from" to (if (effectiveFrom.isEmpty()) JsonNull else JsonPrimitive(effectiveFrom + "T00:00:00+03:00"))
            env.client.put("/v1/admin/currencies-management/${record.id}", J.obj(*pairs.toTypedArray()))
        } else env.client.post("/v1/admin/currencies-management", J.obj(*pairs.toTypedArray()))
        if (r.json["proposals"].arr?.isNotEmpty() == true) env.toasts.show(r.json.message ?: "Rate change proposed — it applies once signed", StatusTone.INFO) else toastResult(env, r)
        saved()
    }) { e ->
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Field("Code", code, { code = it }, Modifier.weight(1f), error = e?.field("code"))
            Field("Symbol", symbol, { symbol = it }, Modifier.weight(1f))
        }
        Field("Name", name, { name = it }, error = e?.field("name"))
        SetupHeading("Customer pricing")
        Field("KES per 1 unit (quotation rate)", kesPerUnit, { kesPerUnit = it }, error = e?.field("exchange_rate"))
        SetupCaption("Stored base-relative (1/KES per unit, 6 decimals). A change is proposed for finance to sign.")
        SetupHeading("Financial reporting")
        Field("KES per 1 unit for reports (blank = no reporting rate)", reportingRate, { reportingRate = it }, error = e?.field("reporting_rate_to_kes"))
        SetupCaption("Reports convert at this rate; a change is proposed, dated, for the super admin to sign and never rewrites history.")
        if (record != null) Field("Effective from (yyyy-mm-dd, optional)", effectiveFrom, { effectiveFrom = it })
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Field("Decimals", decimals, { decimals = it }, Modifier.weight(1f))
            Field("Thousands", thousands, { thousands = it }, Modifier.weight(1f))
            Field("Decimal", decimalSep, { decimalSep = it }, Modifier.weight(1f))
        }
        OptionPicker("Symbol position", position, listOf("before" to "Before", "after" to "After"), { position = it })
        ToggleRow("Default", isDefault, { isDefault = it })
        ToggleRow("Active", active, { active = it })
        SetupStepUpNote()
        Spacer(Modifier.height(Space.xs))
    }
}

// ── Languages (GET /languages; POST/PUT/DELETE; toggle; set-default) ──────────

@Composable
fun LanguagesScreen() {
    val env = LocalEnv.current
    var version by remember { mutableIntStateOf(0) }
    var editing by remember { mutableStateOf<Record?>(null) }
    var adding by remember { mutableStateOf(false) }
    val canEdit = env.setupPermissions.canAny(listOf("settings.edit", "setup.technical"))
    Page("Languages", subtitle = "Storefront and console languages", refresh = { version++ }, actions = { if (canEdit) Button(onClick = { adding = true }) { Text("Add language") } }) {
        RemoteJson("/v1/admin/languages", version = version) { j, reload ->
            j["data"].toRecords().forEach { l ->
                SupportListRow("${l.string("flag") ?: ""} ${l.string("name") ?: ""} · ${l.string("native_name") ?: ""}", subtitle = "${l.string("code") ?: ""} · ${l.string("direction") ?: "ltr"}") {
                    Column(horizontalAlignment = Alignment.End) {
                        Row(horizontalArrangement = Arrangement.spacedBy(Space.xs)) {
                            if (l.bool("is_default") == true) Pill("default", StatusTone.INFO)
                            StatusBadge(if (l.bool("is_active") == true) "active" else "inactive")
                        }
                        if (canEdit) Row(verticalAlignment = Alignment.CenterVertically) {
                            TextButton(onClick = { editing = l }) { Text("Edit") }
                            SetupIconAction(Icons.Default.PowerSettingsNew, "Toggle", tint = MaterialTheme.hub.textSecondary) { val r = env.client.put("/v1/admin/languages/${l.id}/toggle"); toastResult(env, r); reload() }
                            if (l.bool("is_default") != true) AsyncTextButton("Default") { val r = env.client.put("/v1/admin/languages/${l.id}/set-default"); toastResult(env, r); reload() }
                            SetupIconAction(description = "Delete") { val r = env.client.delete("/v1/admin/languages/${l.id}"); toastResult(env, r); reload() }
                        }
                    }
                }
            }
        }
    }
    if (adding) LanguageSheet(null, onDismiss = { adding = false }) { version++ }
    editing?.let { l -> LanguageSheet(l, onDismiss = { editing = null }) { version++ } }
}

@Composable
private fun LanguageSheet(record: Record?, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    var code by remember { mutableStateOf(record?.string("code") ?: "") }
    var name by remember { mutableStateOf(record?.string("name") ?: "") }
    var native by remember { mutableStateOf(record?.string("native_name") ?: "") }
    var direction by remember { mutableStateOf(record?.string("direction") ?: "ltr") }
    var flag by remember { mutableStateOf(record?.string("flag") ?: "") }
    var isDefault by remember { mutableStateOf(record?.bool("is_default") ?: false) }
    var active by remember { mutableStateOf(record?.bool("is_active") ?: true) }
    FormSheet(if (record == null) "Add language" else "Edit language", onDismiss, canSave = code.isNotEmpty() && name.isNotEmpty(), save = {
        val body = J.obj("code" to JsonPrimitive(code), "name" to JsonPrimitive(name), "native_name" to JsonPrimitive(native), "direction" to JsonPrimitive(direction), "flag" to J.s(flag), "is_default" to J.b(isDefault), "is_active" to J.b(active))
        val r = if (record != null) env.client.put("/v1/admin/languages/${record.id}", body) else env.client.post("/v1/admin/languages", body)
        toastResult(env, r); saved()
    }) { e ->
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Field("Code", code, { code = it }, Modifier.weight(1f), placeholder = "sw", error = e?.field("code"))
            Field("Flag", flag, { flag = it }, Modifier.weight(1f))
        }
        Field("Name", name, { name = it }, error = e?.field("name"))
        Field("Native name", native, { native = it })
        OptionPicker("Direction", direction, listOf("ltr" to "Left to right", "rtl" to "Right to left"), { direction = it })
        ToggleRow("Default", isDefault, { isDefault = it })
        ToggleRow("Active", active, { active = it })
        Spacer(Modifier.height(Space.xs))
    }
}

// ── Tax rates (GET /tax-rates; POST/PUT/DELETE/toggle — step-up; PUT may return a proposal) ──

@Composable
fun TaxRatesScreen() {
    val env = LocalEnv.current
    var version by remember { mutableIntStateOf(0) }
    var editing by remember { mutableStateOf<Record?>(null) }
    var adding by remember { mutableStateOf(false) }
    val s = env.session.current
    val canPropose = env.setupPermissions.canAny(listOf("settings.edit", "settings.financial_propose"))
    Page("Tax rates", subtitle = "VAT and other rates applied to sales", refresh = { version++ }, actions = { if (s.can("settings.edit")) Button(onClick = { adding = true }) { Text("Add rate") } }) {
        RemoteJson("/v1/admin/tax-rates", version = version) { j, reload ->
            j["data"].toRecords().forEach { t ->
                SupportListRow("${t.string("name") ?: ""} (${t.string("code") ?: ""})", subtitle = "${t["rate"].str ?: ""}${if (t.string("type") == "fixed") " fixed" else "%"} · applies to ${t.string("applies_to") ?: "all"} · ${t.string("country_code") ?: "all countries"}") {
                    Column(horizontalAlignment = Alignment.End) {
                        Row(horizontalArrangement = Arrangement.spacedBy(Space.xs)) {
                            if (t.bool("is_default") == true) Pill("default", StatusTone.INFO)
                            StatusBadge(if (t.bool("is_active") == true) "active" else "inactive")
                        }
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            if (canPropose) {
                                TextButton(onClick = { editing = t }) { Text("Edit") }
                                SetupIconAction(Icons.Default.PowerSettingsNew, "Toggle", tint = MaterialTheme.hub.textSecondary) { val r = env.client.put("/v1/admin/tax-rates/${t.id}/toggle"); setupProposalToast(env, r); reload() }
                            }
                            if (s.can("settings.edit")) SetupIconAction(description = "Delete") { val r = env.client.delete("/v1/admin/tax-rates/${t.id}"); toastResult(env, r); reload() }
                        }
                    }
                }
            }
        }
    }
    if (adding) TaxRateSheet(null, onDismiss = { adding = false }) { version++ }
    editing?.let { t -> TaxRateSheet(t, onDismiss = { editing = null }) { version++ } }
}

@Composable
private fun TaxRateSheet(record: Record?, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    var name by remember { mutableStateOf(record?.string("name") ?: "") }
    var code by remember { mutableStateOf(record?.string("code") ?: "") }
    var rate by remember { mutableStateOf(record?.get("rate").str ?: "") }
    var type by remember { mutableStateOf(record?.string("type") ?: "percentage") }
    var appliesTo by remember { mutableStateOf(record?.string("applies_to") ?: "all") }
    var country by remember { mutableStateOf(record?.string("country_code") ?: "") }
    var isDefault by remember { mutableStateOf(record?.bool("is_default") ?: false) }
    var active by remember { mutableStateOf(record?.bool("is_active") ?: true) }
    var effectiveFrom by remember { mutableStateOf("") }
    FormSheet(if (record == null) "Add tax rate" else "Edit tax rate", onDismiss, canSave = name.isNotEmpty() && rate.isNotEmpty(), save = {
        val pairs = mutableListOf<Pair<String, JsonElement?>>("name" to JsonPrimitive(name), "code" to JsonPrimitive(code), "rate" to J.typed(rate), "type" to JsonPrimitive(type), "applies_to" to JsonPrimitive(appliesTo), "country_code" to J.s(country), "is_default" to J.b(isDefault), "is_active" to J.b(active))
        val r = if (record != null) {
            pairs += "effective_from" to (if (effectiveFrom.isEmpty()) JsonNull else JsonPrimitive(effectiveFrom + "T00:00:00+03:00"))
            env.client.put("/v1/admin/tax-rates/${record.id}", J.obj(*pairs.toTypedArray()))
        } else env.client.post("/v1/admin/tax-rates", J.obj(*pairs.toTypedArray()))
        setupProposalToast(env, r); saved()
    }) { e ->
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Field("Name", name, { name = it }, Modifier.weight(1f), error = e?.field("name"))
            Field("Code", code, { code = it }, Modifier.weight(1f), error = e?.field("code"))
        }
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Field("Rate", rate, { rate = it }, Modifier.weight(1f), error = e?.field("rate"))
            OptionPicker("Type", type, listOf("percentage" to "Percentage", "fixed" to "Fixed"), { type = it }, Modifier.weight(1f))
        }
        OptionPicker("Applies to", appliesTo, listOf("all" to "Everything", "products" to "Products", "shipping" to "Shipping"), { appliesTo = it })
        Field("Country code (blank = all)", country, { country = it })
        ToggleRow("Default", isDefault, { isDefault = it })
        ToggleRow("Active", active, { active = it })
        if (record != null) {
            Field("Effective from (yyyy-mm-dd, optional)", effectiveFrom, { effectiveFrom = it })
            SetupCaption("Rate, active and default changes are proposed for the super admin to sign.")
        }
        SetupStepUpNote()
        Spacer(Modifier.height(Space.xs))
    }
}

// ── Discount limits (GET/PUT /settings/discount-limits — owner only, step-up) ──

@Composable
fun DiscountLimitsScreen() {
    val env = LocalEnv.current
    val caps = remember { androidx.compose.runtime.mutableStateMapOf<String, String>() }
    var version by remember { mutableIntStateOf(0) }
    Page("Discount limits", subtitle = "The most each role may give as a discount; blank uses the default", refresh = { version++ }) {
        RemoteJson("/v1/admin/settings/discount-limits", version = version) { j, _ ->
            SetupHeading("Default limit: ${j["default_percent"].str ?: "—"}%")
            val limits = j["limits"].toRecords()
            limits.forEach { l ->
                val role = l.string("role") ?: ""
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    Column(Modifier.weight(1f)) {
                        Text(l.string("display_name") ?: role, style = MaterialTheme.typography.bodyMedium)
                        SetupCaption("Effective ${l["effective_percent"].str ?: ""}%${l["updated_by"]["name"].str?.let { " · set by $it" } ?: ""}")
                    }
                    Field("%", caps[role] ?: l["cap_percent"].str ?: "", { caps[role] = it }, Modifier.width(96.dp), placeholder = "default")
                }
            }
            AsyncButton("Save (asks you to confirm it's you)", Modifier.fillMaxWidth()) {
                val body = J.obj("limits" to kotlinx.serialization.json.JsonArray(limits.map { l ->
                    val role = l.string("role") ?: ""
                    J.obj("role" to J.s(role), "cap_percent" to J.typed(caps[role] ?: l["cap_percent"].str ?: ""))
                }))
                val r = env.client.put("/v1/admin/settings/discount-limits", body); toastResult(env, r); caps.clear(); version++
            }
        }
    }
}
