package ke.co.bethanyhouse.hub.ui.features.setup

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
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
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.unit.dp
import com.google.android.gms.maps.model.CameraPosition
import com.google.android.gms.maps.model.LatLng
import com.google.maps.android.compose.Circle
import com.google.maps.android.compose.GoogleMap
import com.google.maps.android.compose.MapUiSettings
import com.google.maps.android.compose.Marker
import com.google.maps.android.compose.rememberCameraPositionState
import com.google.maps.android.compose.rememberMarkerState
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.dbl
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.money
import ke.co.bethanyhouse.hub.core.obj
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FieldError
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.InfoSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.KeyValueGrid
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Pill
import ke.co.bethanyhouse.hub.ui.components.RemoteJson
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.listRecords
import ke.co.bethanyhouse.hub.ui.components.prettyJson
import ke.co.bethanyhouse.hub.ui.components.toastResult
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.serialization.json.JsonElement
import java.util.Locale

/**
 * Outlets (GET/POST/PUT/DELETE /outlets, GET /outlets/{id}/statistics):
 * the list, a form with the geofence drawn on a map, and the statistics card.
 */
@Composable
fun OutletsScreen() {
    val env = LocalEnv.current
    var version by remember { mutableIntStateOf(0) }
    var editing by remember { mutableStateOf<Record?>(null) }
    var adding by remember { mutableStateOf(false) }
    var stats by remember { mutableStateOf<Record?>(null) }
    val s = env.session.current

    Page("Outlets", subtitle = "Stores, workshops and warehouses", refresh = { version++ }, actions = {
        if (s.can("outlets.create")) Button(onClick = { adding = true }) { Text("New outlet") }
    }) {
        RemoteJson("/v1/admin/outlets", version = version, emptyTitle = "No outlets yet", isEmpty = { it.listRecords.isEmpty() }) { j, reload ->
            j.listRecords.forEach { o ->
                SectionCard {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                        Text(o.string("name") ?: "", style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
                        Pill(o.string("code") ?: "")
                        StatusBadge(if (o.bool("is_active") == true) "active" else "inactive")
                    }
                    SetupSecondary("${o.string("outlet_type")?.humanized ?: ""} · ${o.string("sales_channel")?.humanized ?: "pos"} channel · ${listOfNotNull(o.string("address_line1"), o.string("city"), o.string("country_code")).joinToString(", ")}")
                    SetupCaption("${o.int("users_count") ?: 0} people · geofence ${o.int("geofence_radius_meters")?.let { "$it m" } ?: "not set"}${if (o.bool("is_pickup_location") == true) " · pickup point" else ""}")
                    androidx.compose.foundation.layout.FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.xs)) {
                        TextButton(onClick = { stats = o }) { Text("Statistics") }
                        if (s.can("outlets.edit")) TextButton(onClick = { editing = o }) { Text("Edit") }
                        if (s.can("outlets.delete")) ConfirmButton("Delete outlet", "Only an outlet with no people, stock or sales can be deleted.", destructive = true, action = {
                            try { val r = env.client.delete("/v1/admin/outlets/${o.id}"); toastResult(env, r); reload() } catch (e: Exception) { env.toasts.error(e) }
                        }) { Text("Delete") }
                    }
                }
            }
        }
    }
    if (adding) OutletSheet(null, onDismiss = { adding = false }) { version++ }
    editing?.let { o -> OutletSheet(o, onDismiss = { editing = null }) { version++ } }
    stats?.let { o -> OutletStatsSheet(o.id) { stats = null } }
}

private val outletText = listOf("code" to "Code", "name" to "Name", "email" to "Email", "phone" to "Phone", "address_line1" to "Address line 1", "address_line2" to "Address line 2", "city" to "City", "state_province" to "County / province", "postal_code" to "Postal code", "country_code" to "Country code")

/** POST/PUT /outlets — the geofence centre is the pin: drag it, or tap the map, then save. */
@Composable
private fun OutletSheet(record: Record?, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    val f = remember {
        SetupForm(buildMap {
            put("outlet_type", record?.string("outlet_type") ?: "store"); put("sales_channel", record?.string("sales_channel") ?: "pos"); put("country_code", "KE")
            if (record != null) outletText.forEach { (k, _) -> put(k, record.string(k) ?: "") }
        })
    }
    var active by remember { mutableStateOf(record?.bool("is_active") ?: true) }
    var pickup by remember { mutableStateOf(record?.bool("is_pickup_location") ?: false) }
    var lat by remember { mutableStateOf(record?.get("latitude").dbl) }
    var lon by remember { mutableStateOf(record?.get("longitude").dbl) }
    var radius by remember { mutableStateOf(record?.get("geofence_radius_meters").str ?: "") }
    val start = LatLng(lat ?: -1.2921, lon ?: 36.8219)
    val camera = rememberCameraPositionState { position = CameraPosition.fromLatLngZoom(start, 15f) }
    val marker = rememberMarkerState(position = start)
    // A drag ends with the marker's new position; that becomes the geofence centre.
    LaunchedEffect(marker.position) { if (lat != null || marker.position != start) { lat = marker.position.latitude; lon = marker.position.longitude } }

    FormSheet(if (record == null) "New outlet" else "Edit outlet", onDismiss, canSave = f["name"].isNotEmpty() && f["code"].isNotEmpty(), save = {
        val pairs = outletText.map { (k, _) -> k to J.s(f[k]) }.toMutableList<Pair<String, JsonElement?>>()
        pairs += "outlet_type" to J.s(f["outlet_type"]); pairs += "sales_channel" to J.s(f["sales_channel"])
        pairs += "is_active" to J.b(active); pairs += "is_pickup_location" to J.b(pickup)
        pairs += "latitude" to J.d(lat); pairs += "longitude" to J.d(lon); pairs += "geofence_radius_meters" to J.typed(radius)
        val body = J.obj(*pairs.toTypedArray())
        val r = if (record != null) env.client.put("/v1/admin/outlets/${record.id}", body) else env.client.post("/v1/admin/outlets", body)
        toastResult(env, r); saved()
    }) { e ->
        Field("Code", f["code"], { f["code"] = it }, error = e?.field("code"))
        Field("Name", f["name"], { f["name"] = it }, error = e?.field("name"))
        OptionPicker("Type", f["outlet_type"], listOf("store" to "Store", "warehouse" to "Warehouse", "outlet" to "Outlet", "workshop" to "Workshop"), { f["outlet_type"] = it })
        OptionPicker("Sales channel", f["sales_channel"], listOf("pos" to "Till", "whatsapp" to "Chat", "online" to "Web"), { f["sales_channel"] = it })
        outletText.drop(2).forEach { (k, label) -> Field(label, f[k], { f[k] = it }, error = e?.field(k)) }
        ToggleRow("Active", active, { active = it })
        ToggleRow("Customers can collect here", pickup, { pickup = it })
        SetupHeading("Geofence")
        SetupCaption("Clock-ins must happen inside this circle. Drag the pin onto the outlet (or tap the map), then set the radius.")
        GoogleMap(
            modifier = Modifier.fillMaxWidth().height(240.dp).clip(MaterialTheme.shapes.medium),
            cameraPositionState = camera,
            uiSettings = MapUiSettings(zoomControlsEnabled = false),
            onMapClick = { p -> marker.position = p; lat = p.latitude; lon = p.longitude },
        ) {
            Marker(state = marker, draggable = true, title = f["name"].ifEmpty { "Outlet" })
            val r = radius.toDoubleOrNull()
            if (lat != null && lon != null && r != null && r > 0) Circle(center = LatLng(lat!!, lon!!), radius = r, fillColor = Palette.brand.copy(alpha = 0.15f), strokeColor = Palette.brand, strokeWidth = 2f)
        }
        SetupCaption("Map tiles need a Google Maps API key in local.properties (mapsApiKey); without one the map is blank but the pin and figures still work.")
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
            OutlinedButton(onClick = { val c = camera.position.target; marker.position = c; lat = c.latitude; lon = c.longitude }) { Text("Centre on map view") }
            Field("Radius (m, 10–5000)", radius, { radius = it }, Modifier.weight(1f), error = e?.field("geofence_radius_meters"))
        }
        Text(lat?.let { String.format(Locale.US, "%.6f, %.6f", it, lon ?: 0.0) } ?: "No location set", style = MaterialTheme.typography.bodySmall.copy(fontFamily = FontFamily.Monospace), color = MaterialTheme.hub.textSecondary)
        FieldError(e?.field("latitude") ?: e?.field("longitude"))
        Spacer(Modifier.height(Space.xs))
    }
}

/** GET /outlets/{id}/statistics → {statistics: {...}} — shown as the server keys them. */
@Composable
private fun OutletStatsSheet(id: String, onDismiss: () -> Unit) {
    InfoSheet("Outlet statistics", onDismiss) {
        RemoteJson("/v1/admin/outlets/$id/statistics") { j, _ ->
            val s = j["statistics"].obj ?: emptyMap()
            Column {
                KeyValueGrid(s.keys.sorted().map { k -> k.humanized to (s[k]?.let { v -> v.money?.formatted() ?: v.str ?: prettyJson(v) } ?: "—") })
                SetupCaption("Figures are the server's; money fields are shown as sent.")
            }
        }
    }
}
