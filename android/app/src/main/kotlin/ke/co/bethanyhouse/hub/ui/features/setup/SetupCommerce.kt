package ke.co.bethanyhouse.hub.ui.features.setup

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.PowerSettingsNew
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.obj
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.KeyValueEditor
import ke.co.bethanyhouse.hub.ui.components.KeyValueRow
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Pill
import ke.co.bethanyhouse.hub.ui.components.RemoteJson
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.SupportListRow
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.listRecords
import ke.co.bethanyhouse.hub.ui.components.toastResult
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonPrimitive

/**
 * Payment methods (GET /payment-methods-management; writes behind step-up,
 * settlement changes become proposals) and shipping zones & methods.
 */

private fun csvCodes(text: String): List<String> = text.split(",").map { it.trim().uppercase() }.filter { it.isNotEmpty() }

@Composable
fun PaymentMethodsScreen() {
    val env = LocalEnv.current
    var version by remember { mutableIntStateOf(0) }
    var editing by remember { mutableStateOf<Record?>(null) }
    var adding by remember { mutableStateOf(false) }
    var configuring by remember { mutableStateOf<Record?>(null) }
    val s = env.session.current
    val canPropose = env.setupPermissions.canAny(listOf("settings.edit", "settings.financial_propose"))

    Page("Payment methods", subtitle = "How customers pay, and whether a payment settles at once or waits for approval", refresh = { version++ }, actions = {
        if (s.can("settings.edit")) Button(onClick = { adding = true }) { Text("Add method") }
    }) {
        RemoteJson("/v1/admin/payment-methods-management", version = version) { j, reload ->
            j["data"].records.map(::Record).forEach { m ->
                SupportListRow("${m.string("name") ?: ""} (${m.string("code") ?: ""})", subtitle = "${m.string("type")?.humanized ?: ""} · ${m.string("provider") ?: "no provider"} · ${m["supported_currencies"].records.mapNotNull { it.str }.joinToString(", ")} · ${if (m.bool("requires_approval") == true) "held for approval" else "settles at once"}") {
                    Column(horizontalAlignment = Alignment.End) {
                        Row(horizontalArrangement = Arrangement.spacedBy(Space.xs)) {
                            if (m.bool("is_default") == true) Pill("default", StatusTone.INFO)
                            StatusBadge(if (m.bool("is_active") == true) "active" else "inactive")
                        }
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            if (canPropose) TextButton(onClick = { editing = m }) { Text("Edit") }
                            if (s.can("settings.edit")) {
                                TextButton(onClick = { configuring = m }) { Text("Config") }
                                SetupIconAction(Icons.Default.PowerSettingsNew, "Toggle", tint = MaterialTheme.hub.textSecondary) { val r = env.client.put("/v1/admin/payment-methods-management/${m.id}/toggle"); toastResult(env, r); reload() }
                                SetupIconAction(description = "Delete") { val r = env.client.delete("/v1/admin/payment-methods-management/${m.id}"); toastResult(env, r); reload() }
                            }
                        }
                    }
                }
            }
        }
    }
    if (adding) PaymentMethodSheet(null, onDismiss = { adding = false }) { version++ }
    editing?.let { m -> PaymentMethodSheet(m, onDismiss = { editing = null }) { version++ } }
    configuring?.let { m -> PaymentConfigSheet(m, onDismiss = { configuring = null }) { version++ } }
}

@Composable
private fun PaymentMethodSheet(record: Record?, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    var name by remember { mutableStateOf(record?.string("name") ?: "") }
    var code by remember { mutableStateOf(record?.string("code") ?: "") }
    var type by remember { mutableStateOf(record?.string("type") ?: "cash") }
    var provider by remember { mutableStateOf(record?.string("provider") ?: "") }
    var description by remember { mutableStateOf(record?.string("description") ?: "") }
    var active by remember { mutableStateOf(record?.bool("is_active") ?: true) }
    var isDefault by remember { mutableStateOf(record?.bool("is_default") ?: false) }
    var requiresApproval by remember { mutableStateOf(record?.bool("requires_approval") ?: false) }
    var sortOrder by remember { mutableStateOf(record?.get("sort_order").str ?: "0") }
    var currencies by remember { mutableStateOf(record?.get("supported_currencies").records.mapNotNull { it.str }.joinToString(", ")) }
    var effectiveFrom by remember { mutableStateOf("") }
    FormSheet(if (record == null) "Add payment method" else "Edit payment method", onDismiss, canSave = name.isNotEmpty() && code.isNotEmpty(), save = {
        val pairs = mutableListOf<Pair<String, JsonElement?>>("name" to JsonPrimitive(name), "code" to JsonPrimitive(code), "type" to JsonPrimitive(type), "provider" to J.s(provider), "description" to J.s(description), "is_active" to J.b(active), "is_default" to J.b(isDefault), "requires_approval" to J.b(requiresApproval), "sort_order" to J.typed(sortOrder), "supported_currencies" to J.strs(csvCodes(currencies)))
        val r = if (record != null) {
            pairs += "effective_from" to (if (effectiveFrom.isEmpty()) JsonNull else JsonPrimitive(effectiveFrom + "T00:00:00+03:00"))
            env.client.put("/v1/admin/payment-methods-management/${record.id}", J.obj(*pairs.toTypedArray()))
        } else env.client.post("/v1/admin/payment-methods-management", J.obj(*pairs.toTypedArray()))
        setupProposalToast(env, r); saved()
    }) { e ->
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Field("Name", name, { name = it }, Modifier.weight(1f), error = e?.field("name"))
            Field("Code", code, { code = it }, Modifier.weight(1f), error = e?.field("code"))
        }
        OptionPicker("Type", type, listOf("mobile_money" to "Mobile money", "card" to "Card", "cash" to "Cash", "bank_transfer" to "Bank transfer"), { type = it })
        Field("Provider", provider, { provider = it }, placeholder = "mpesa, pesapal…")
        Field("Description", description, { description = it })
        Field("Supported currencies (comma separated)", currencies, { currencies = it }, placeholder = "KES, USD")
        Field("Sort order", sortOrder, { sortOrder = it })
        ToggleRow("Active", active, { active = it })
        ToggleRow("Default", isDefault, { isDefault = it })
        ToggleRow("Hold payments for approval (settlement)", requiresApproval, { requiresApproval = it })
        if (record != null) {
            Field("Effective from (yyyy-mm-dd, optional)", effectiveFrom, { effectiveFrom = it })
            SetupCaption("A settlement change is proposed for the super admin to sign.")
        }
        SetupStepUpNote()
        Spacer(Modifier.height(Space.xs))
    }
}

/** PUT /payment-methods-management/{id}/config {configuration: {key: value}} */
@Composable
private fun PaymentConfigSheet(method: Record, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    var rows by remember {
        val cfg = method["configuration"].obj ?: method["config"].obj ?: emptyMap()
        mutableStateOf(cfg.keys.sorted().map { KeyValueRow(key = it, value = cfg[it].str ?: "") })
    }
    FormSheet("Configuration · ${method.string("name") ?: ""}", onDismiss, save = {
        val r = env.client.put("/v1/admin/payment-methods-management/${method.id}/config", J.obj("configuration" to J.stringMap(rows.map { it.key to it.value })))
        toastResult(env, r); saved()
    }) {
        SetupCaption("Provider keys and settings. Secrets are stored server-side; they come back masked.")
        KeyValueEditor("Keys", rows) { rows = it }
        Spacer(Modifier.height(Space.xs))
    }
}

// ── Shipping (GET /shipping/zones (array), /methods (array); zone countries; CRUD) ──

@Composable
fun ShippingScreen() {
    val env = LocalEnv.current
    var version by remember { mutableIntStateOf(0) }
    var zone by remember { mutableStateOf<Record?>(null) }
    var addingZone by remember { mutableStateOf(false) }
    var method by remember { mutableStateOf<Record?>(null) }
    var addingMethod by remember { mutableStateOf<Int?>(null) } // first zone id as the default
    var addingMethodOpen by remember { mutableStateOf(false) }
    val canEdit = env.setupPermissions.canAny(listOf("settings.edit", "setup.technical"))

    Page("Shipping", subtitle = "Zones group countries; methods price delivery per zone", refresh = { version++ }) {
        RemoteJson("/v1/admin/shipping/zones", version = version) { zj, reload ->
            val zones = zj.listRecords
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                SetupHeading("Zones"); Spacer(Modifier.weight(1f))
                if (canEdit) TextButton(onClick = { addingZone = true }) { Text("New zone") }
            }
            zones.forEach { z ->
                SupportListRow(z.string("name") ?: "", subtitle = "${z.string("description") ?: ""} · ${z["countries"].records.mapNotNull { it.str }.joinToString(", ")}") {
                    Column(horizontalAlignment = Alignment.End) {
                        StatusBadge(if (z.bool("is_active") == false) "inactive" else "active")
                        if (canEdit) Row(verticalAlignment = Alignment.CenterVertically) {
                            TextButton(onClick = { zone = z }) { Text("Edit") }
                            SetupIconAction(description = "Delete") { val r = env.client.delete("/v1/admin/shipping/zones/${z.id}"); toastResult(env, r); reload() }
                        }
                    }
                }
            }
            RemoteJson("/v1/admin/shipping/methods", version = version) { mj, reloadM ->
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    SetupHeading("Methods"); Spacer(Modifier.weight(1f))
                    if (canEdit) TextButton(onClick = { addingMethod = zones.firstOrNull()?.int("id"); addingMethodOpen = true }) { Text("New method") }
                }
                mj.listRecords.forEach { m ->
                    val zoneName = m.string("zone_name") ?: zones.firstOrNull { it.int("id") == m.int("shipping_zone_id") }?.string("name") ?: "zone ${m.int("shipping_zone_id") ?: 0}"
                    val costType = m.string("cost_type")
                    val cost = "${costType?.humanized ?: ""} ${if (costType == "free") "" else (m["flat_rate"].str ?: "")}${if (costType == "percentage") "%" else ""}"
                    SupportListRow("${m.string("name") ?: ""} · $zoneName", subtitle = "$cost${m["min_order_amount"].str?.let { " · min order $it" } ?: ""} · ${m.string("delivery_time") ?: ""}") {
                        Column(horizontalAlignment = Alignment.End) {
                            StatusBadge(if (m.bool("is_active") == true) "active" else "inactive")
                            if (canEdit) Row(verticalAlignment = Alignment.CenterVertically) {
                                TextButton(onClick = { method = m }) { Text("Edit") }
                                SetupIconAction(description = "Delete") { val r = env.client.delete("/v1/admin/shipping/methods/${m.id}"); toastResult(env, r); reloadM() }
                            }
                        }
                    }
                }
            }
        }
    }
    if (addingZone) ZoneSheet(null, onDismiss = { addingZone = false }) { version++ }
    zone?.let { z -> ZoneSheet(z, onDismiss = { zone = null }) { version++ } }
    if (addingMethodOpen) MethodSheet(null, addingMethod, onDismiss = { addingMethodOpen = false }) { version++ }
    method?.let { m -> MethodSheet(m, m.int("shipping_zone_id"), onDismiss = { method = null }) { version++ } }
}

/** POST/PUT /shipping/zones {name, description, countries: [codes]} */
@Composable
private fun ZoneSheet(record: Record?, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    var name by remember { mutableStateOf(record?.string("name") ?: "") }
    var description by remember { mutableStateOf(record?.string("description") ?: "") }
    var countries by remember { mutableStateOf(record?.get("countries").records.mapNotNull { it.str }.joinToString(", ")) }
    FormSheet(if (record == null) "New zone" else "Edit zone", onDismiss, canSave = name.isNotEmpty(), save = {
        val body = J.obj("name" to JsonPrimitive(name), "description" to J.s(description), "countries" to J.strs(csvCodes(countries)))
        val r = if (record != null) env.client.put("/v1/admin/shipping/zones/${record.id}", body) else env.client.post("/v1/admin/shipping/zones", body)
        toastResult(env, r); saved()
    }) { e ->
        Field("Name", name, { name = it }, error = e?.field("name"))
        Field("Description", description, { description = it })
        Field("Country codes (comma separated)", countries, { countries = it }, placeholder = "KE, UG, TZ", error = e?.field("countries"))
        Spacer(Modifier.height(Space.xs))
    }
}

/** POST/PUT /shipping/methods {zone_id, name, description, delivery_time, cost_type, flat_rate, min_order_amount, is_active, sort_order} */
@Composable
private fun MethodSheet(record: Record?, initialZone: Int?, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    var zoneId by remember { mutableStateOf(initialZone) }
    var zones by remember { mutableStateOf<List<Record>>(emptyList()) }
    var name by remember { mutableStateOf(record?.string("name") ?: "") }
    var description by remember { mutableStateOf(record?.string("description") ?: "") }
    var delivery by remember { mutableStateOf(record?.string("delivery_time") ?: "") }
    var costType by remember { mutableStateOf(record?.string("cost_type") ?: "flat_rate") }
    var rate by remember { mutableStateOf(record?.get("flat_rate").str ?: "0") }
    var minOrder by remember { mutableStateOf(record?.get("min_order_amount").str ?: "") }
    var active by remember { mutableStateOf(record?.bool("is_active") ?: true) }
    var sort by remember { mutableStateOf(record?.get("sort_order").str ?: "0") }
    LaunchedEffect(Unit) { if (record == null) zones = runCatching { env.client.get("/v1/admin/shipping/zones").json.listRecords }.getOrDefault(emptyList()) }
    FormSheet(if (record == null) "New shipping method" else "Edit shipping method", onDismiss, canSave = name.isNotEmpty() && (record != null || zoneId != null), save = {
        val pairs = mutableListOf<Pair<String, JsonElement?>>("name" to JsonPrimitive(name), "description" to J.s(description), "delivery_time" to J.s(delivery), "cost_type" to JsonPrimitive(costType), "flat_rate" to J.typed(rate), "min_order_amount" to J.typed(minOrder), "is_active" to J.b(active), "sort_order" to J.typed(sort))
        val r = if (record != null) env.client.put("/v1/admin/shipping/methods/${record.id}", J.obj(*pairs.toTypedArray()))
        else { pairs += "zone_id" to J.i(zoneId); env.client.post("/v1/admin/shipping/methods", J.obj(*pairs.toTypedArray())) }
        toastResult(env, r); saved()
    }) { e ->
        if (record == null) SelectField("Zone", zoneId, zones.mapNotNull { z -> z.int("id")?.let { it to (z.string("name") ?: "") } }, { zoneId = it }, placeholder = "Choose…")
        Field("Name", name, { name = it }, error = e?.field("name"))
        Field("Description", description, { description = it })
        Field("Delivery time", delivery, { delivery = it }, placeholder = "2–5 business days")
        OptionPicker("Cost", costType, listOf("flat_rate" to "Flat rate", "free" to "Free", "percentage" to "Percentage of order"), { costType = it })
        if (costType != "free") Field(if (costType == "percentage") "Percent" else "Flat rate (KES)", rate, { rate = it }, error = e?.field("flat_rate"))
        Field("Minimum order amount (optional)", minOrder, { minOrder = it })
        Field("Sort order", sort, { sort = it })
        ToggleRow("Active", active, { active = it })
        Spacer(Modifier.height(Space.xs))
    }
}
