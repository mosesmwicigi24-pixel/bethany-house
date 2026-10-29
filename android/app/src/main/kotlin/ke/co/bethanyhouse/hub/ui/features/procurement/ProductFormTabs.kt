package ke.co.bethanyhouse.hub.ui.features.procurement

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowDownward
import androidx.compose.material.icons.filled.ArrowUpward
import androidx.compose.material.icons.filled.RemoveCircleOutline
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toJson
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.AttachmentPicker
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.DataTable
import ke.co.bethanyhouse.hub.ui.components.DecimalField
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.InfoSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.JRow
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.PickedFile
import ke.co.bethanyhouse.hub.ui.components.RemoteImage
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.TableColumn
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.fmtMoney
import ke.co.bethanyhouse.hub.ui.components.jpairs
import ke.co.bethanyhouse.hub.ui.components.jrows
import ke.co.bethanyhouse.hub.ui.components.rememberFilePicker
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive

// Product form tabs that act on a saved product: images and video,
// variants (with generate-from-attributes), and the bill of materials.

// ── Images & video ───────────────────────────────────────────────────────────

@Composable
internal fun ProductImagesTab(productId: Int, product: JsonElement, reload: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val canEdit = env.session.current.can("products.edit")
    val images = jrows(product["images"])
    var busy by remember { mutableStateOf(false) }
    var videoStatus by remember { mutableStateOf<String?>(null) }

    suspend fun put(path: String, msg: String) { try { env.client.put("/v1/admin/products/$productId/$path"); env.toasts.show(msg); reload() } catch (e: Exception) { env.toasts.error(e) } }
    suspend fun delete(path: String, msg: String) { try { env.client.delete("/v1/admin/products/$productId/$path"); env.toasts.show(msg); videoStatus = null; reload() } catch (e: Exception) { env.toasts.error(e) } }
    suspend fun upload(files: List<PickedFile>) {
        busy = true
        try {
            val parts = files.take(10).map { it.part("images[]") }
            env.client.upload("/v1/admin/products/$productId/images", parts)
            env.toasts.show("${parts.size} image(s) uploaded"); reload()
        } catch (e: Exception) { env.toasts.error(e) } finally { busy = false }
    }
    suspend fun move(index: Int, delta: Int) {
        val ids = images.mapNotNull { it.int("id") }.toMutableList()
        val to = index + delta
        if (index !in ids.indices || to !in ids.indices) return
        val tmp = ids[index]; ids[index] = ids[to]; ids[to] = tmp
        busy = true
        try {
            env.client.put("/v1/admin/products/$productId/images/reorder", J.obj("images" to toJson(ids.mapIndexed { i, id -> J.obj("id" to JsonPrimitive(id), "sort_order" to JsonPrimitive(i)) })))
            reload()
        } catch (e: Exception) { env.toasts.error(e) } finally { busy = false }
    }
    /** GET /products/{id}/video until video_status settles (null) or fails. */
    suspend fun pollVideo() {
        if ((videoStatus ?: product["video_status"].str) != "processing") return
        repeat(40) {
            delay(5000)
            val j = runCatching { env.client.get("/v1/admin/products/$productId/video", background = true).json }.getOrNull() ?: return
            val st = j["video_status"].str
            videoStatus = st
            if (st != "processing") { reload(); return }
        }
    }
    val pickVideo = rememberFilePicker(arrayOf("video/*")) { f ->
        busy = true
        scope.launch {
            try {
                val r = env.client.upload("/v1/admin/products/$productId/video", listOf(f.part("video")))
                videoStatus = r.json["video_status"].str ?: "processing"
                env.toasts.show(r.json["message"].str ?: "Video uploaded")
                busy = false
                pollVideo()
            } catch (e: Exception) { env.toasts.error(e) } finally { busy = false }
        }
    }
    LaunchedEffect(product["video_status"].str) { pollVideo() }

    SectionCard("Images") {
        if (images.isEmpty()) Text("No images yet.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textTertiary)
        images.forEachIndexed { index, img ->
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.m), verticalAlignment = Alignment.CenterVertically) {
                RemoteImage(img.str("thumbnail_url") ?: img.str("image_url"), 64.dp)
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                        Text(img.str("alt_text") ?: "Image ${index + 1}", style = MaterialTheme.typography.bodyMedium)
                        if (img["is_primary"].bool == true) StatusBadge("primary", StatusTone.SUCCESS)
                    }
                    Text("Order ${img.int("sort_order") ?: index}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                }
                if (canEdit) {
                    IconButton(enabled = index > 0 && !busy, onClick = { scope.launch { move(index, -1) } }) { Icon(Icons.Default.ArrowUpward, contentDescription = "Move up") }
                    IconButton(enabled = index < images.lastIndex && !busy, onClick = { scope.launch { move(index, 1) } }) { Icon(Icons.Default.ArrowDownward, contentDescription = "Move down") }
                }
            }
            if (canEdit) img.int("id")?.let { iid ->
                FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                    if (img["is_primary"].bool != true) OutlinedButton(enabled = !busy, onClick = { scope.launch { put("images/$iid/primary", "Primary image updated") } }) { Text("Make primary") }
                    ConfirmButton("Delete image", "The image is removed from the product.", destructive = true, action = { delete("images/$iid", "Image deleted") }) { Text("Delete") }
                }
            }
            HorizontalDivider(color = MaterialTheme.hub.line)
        }
        if (canEdit) AttachmentPicker(label = if (busy) "Uploading…" else "Add image (up to 10 per product)") { files -> if (!busy) scope.launch { upload(files) } }
    }
    SectionCard("Video") {
        val status = videoStatus ?: product["video_status"].str
        val url = product["video_url"].str
        if (!url.isNullOrEmpty() && status == null) Text(url, style = MaterialTheme.typography.labelSmall, maxLines = 1)
        if (status == "processing") Text("Converting for the web — it will appear here shortly.", style = MaterialTheme.typography.bodySmall, color = Palette.amber)
        if (status == "failed") Text("The last conversion failed. Upload the clip again.", style = MaterialTheme.typography.bodySmall, color = Palette.danger)
        if (url == null && status == null) Text("No clip. One short clip per product (hover-to-play on storefront cards); uploading again replaces it.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textTertiary)
        if (canEdit) FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
            OutlinedButton(enabled = !busy, onClick = { pickVideo() }) { Text("Upload video") }
            if (url != null || status != null) ConfirmButton("Remove video", "The clip is removed from the product.", destructive = true, enabled = !busy, action = { delete("video", "Video removed") }) { Text("Remove") }
        }
    }
}

// ── Variants ─────────────────────────────────────────────────────────────────

@Composable
internal fun ProductVariantsTab(productId: Int, product: JsonElement, currencies: List<String>, reload: () -> Unit) {
    val env = LocalEnv.current
    val canEdit = env.session.current.can("products.edit")
    val canCost = env.session.current.can("products.view_cost")
    var editing by remember { mutableStateOf<JsonElement?>(null) }
    var adding by remember { mutableStateOf(false) }
    var generating by remember { mutableStateOf(false) }
    val priceCurrencies = remember(product, currencies) {
        val codes = currencies.toMutableList()
        product["prices"].records.forEach { p -> p["currency_code"].str?.let { if (it !in codes) codes += it } }
        if ("KES" !in codes) codes.add(0, "KES")
        codes.toList()
    }

    SectionCard("Variants") {
        val rows = jrows(product["variants"])
        if (rows.isEmpty()) Text("No variants. Define attributes (Size, Colour…) and generate every combination, or add one by hand.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textTertiary)
        rows.forEach { v ->
            Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                    Text(v.str("variant_name") ?: "", style = MaterialTheme.typography.bodyMedium, modifier = Modifier.weight(1f, fill = false))
                    if (v["is_default"].bool == true) StatusBadge("default", StatusTone.INFO)
                    if (v["is_active"].bool == false) StatusBadge("inactive", StatusTone.NEUTRAL)
                }
                Text("${v.str("sku") ?: ""} · ${jpairs(v["attributes"]).joinToString(", ") { "${it.key}: ${it.value.str ?: ""}" }}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                Text(jrows(v["prices"]).joinToString(" · ") { p -> fmtMoney(p["regular_price"], p.str("currency_code") ?: "") + (p["sale_price"].takeIf { !it.isNull }?.let { " (sale ${fmtMoney(it, p.str("currency_code") ?: "")})" } ?: "") }, style = MaterialTheme.typography.labelSmall)
                if (canCost) jrows(v["prices"]).firstOrNull { it.str("currency_code") == "KES" }?.get("cost_price")?.takeIf { !it.isNull }?.let { Text("Cost ${fmtMoney(it, "KES")}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
                if (canEdit) v.int("id")?.let { vid ->
                    Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                        OutlinedButton(onClick = { editing = v.json }) { Text("Edit") }
                        ConfirmButton("Delete variant", "A variant that has been ordered cannot be deleted.", destructive = true, action = {
                            try { env.client.delete("/v1/admin/products/$productId/variants/$vid"); env.toasts.show("Variant deleted"); reload() } catch (e: Exception) { env.toasts.error(e) }
                        }) { Text("Delete") }
                    }
                }
            }
            HorizontalDivider(color = MaterialTheme.hub.line)
        }
        if (canEdit) FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
            OutlinedButton(onClick = { adding = true }) { Text("Add variant") }
            OutlinedButton(onClick = { generating = true }) { Text("Generate from attributes") }
        }
    }

    if (adding) ProductVariantSheet(productId, null, priceCurrencies, canCost, onDismiss = { adding = false }) { reload() }
    editing?.let { e -> ProductVariantSheet(productId, e, priceCurrencies, canCost, onDismiss = { editing = null }) { reload() } }
    if (generating) VariantGeneratorSheet(productId, product["sku"].str ?: "SKU", priceCurrencies, canCost, onDismiss = { generating = false }) { reload() }
}

internal data class VariantPrice(val id: Long = System.nanoTime(), val currency: String, val regular: String = "", val sale: String = "", val cost: String = "")
internal data class AttrPair(val id: Long = System.nanoTime(), val key: String, val value: String)

internal fun variantPricesJson(prices: List<VariantPrice>, canCost: Boolean): JsonElement = toJson(prices.mapNotNull { p ->
    val reg = J.decimal(p.regular) ?: return@mapNotNull null
    J.obj("currency_code" to JsonPrimitive(p.currency), "regular_price" to reg, "sale_price" to (J.decimal(p.sale) ?: JsonNull), "cost_price" to (if (canCost && p.currency == "KES") (J.decimal(p.cost) ?: JsonNull) else null))
})

@Composable
private fun VariantPriceRows(prices: androidx.compose.runtime.snapshots.SnapshotStateList<VariantPrice>, canCost: Boolean) {
    prices.forEachIndexed { i, p ->
        Text(p.currency, style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold)
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            DecimalField("Regular", p.regular, { v -> prices[i] = p.copy(regular = v) }, Modifier.weight(1f))
            DecimalField("Sale", p.sale, { v -> prices[i] = p.copy(sale = v) }, Modifier.weight(1f))
            if (canCost && p.currency == "KES") DecimalField("Cost", p.cost, { v -> prices[i] = p.copy(cost = v) }, Modifier.weight(1f))
        }
    }
}

@Composable
private fun ProductVariantSheet(productId: Int, existing: JsonElement?, currencies: List<String>, canCost: Boolean, onDismiss: () -> Unit, done: () -> Unit) {
    val env = LocalEnv.current
    var sku by remember { mutableStateOf(existing["sku"].str ?: "") }
    var name by remember { mutableStateOf(existing["variant_name"].str ?: "") }
    var autoName by remember { mutableStateOf(existing == null) }
    val attrs = remember {
        mutableStateListOf<AttrPair>().apply {
            if (existing == null) { add(AttrPair(key = "Size", value = "")); add(AttrPair(key = "Colour", value = "")) }
            else jpairs(existing["attributes"]).forEach { add(AttrPair(key = it.key, value = it.value.str ?: "")) }
        }
    }
    var weight by remember { mutableStateOf(existing["weight"].takeIf { !it.isNull }?.str ?: "") }
    var isDefault by remember { mutableStateOf(existing["is_default"].bool ?: false) }
    var isActive by remember { mutableStateOf(existing["is_active"].bool ?: true) }
    val prices = remember {
        mutableStateListOf<VariantPrice>().apply {
            currencies.forEach { c ->
                val p = existing["prices"].records.firstOrNull { it["currency_code"].str == c }
                add(VariantPrice(currency = c, regular = p["regular_price"].str ?: "", sale = p["sale_price"].takeIf { !it.isNull }?.str ?: "", cost = p["cost_price"].takeIf { !it.isNull }?.str ?: ""))
            }
        }
    }

    FormSheet(if (existing == null) "New variant" else "Edit variant", onDismiss, saveLabel = if (existing == null) "Create" else "Save", canSave = sku.isNotEmpty() && (autoName || name.isNotEmpty()), save = {
        val body = J.obj(
            "sku" to JsonPrimitive(sku), "is_default" to JsonPrimitive(isDefault), "is_active" to JsonPrimitive(isActive), "prices" to variantPricesJson(prices, canCost),
            "attributes" to JsonObject(attrs.filter { it.key.isNotEmpty() }.associate { it.key to JsonPrimitive(it.value) }),
            "weight" to J.decimal(weight),
            "auto_name" to (if (existing == null && autoName) JsonPrimitive(true) else null),
            "variant_name" to (if (existing == null && autoName) null else J.sOpt(name)),
        )
        val vid = existing["id"].int
        val r: ApiClient.Response = if (existing != null && vid != null) env.client.put("/v1/admin/products/$productId/variants/$vid", body) else env.client.post("/v1/admin/products/$productId/variants", body)
        val waiting = r.json["proposals"].records.filter { it["status"].str != "applied" }
        env.toasts.show(r.json["message"].str ?: "Variant saved", if (waiting.isEmpty()) StatusTone.SUCCESS else StatusTone.INFO)
        done()
    }) { error ->
        Field("SKU *", sku, { sku = it }, error = error?.field("sku"), mono = true)
        if (existing == null) ToggleRow("Compose the name from product + attributes (server)", autoName, { autoName = it })
        if (!autoName || existing != null) Field("Variant name", name, { name = it }, error = error?.field("variant_name"))
        Text("Attributes", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
        attrs.forEachIndexed { i, a ->
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                Field("Attribute", a.key, { v -> attrs[i] = a.copy(key = v) }, Modifier.weight(1f))
                Field("Value", a.value, { v -> attrs[i] = a.copy(value = v) }, Modifier.weight(1f))
                IconButton(onClick = { attrs.removeAll { it.id == a.id } }) { Icon(Icons.Default.RemoveCircleOutline, contentDescription = "Remove", tint = Palette.danger) }
            }
        }
        TextButton(onClick = { attrs += AttrPair(key = "", value = "") }) { Text("Add attribute") }
        DecimalField("Weight (kg)", weight, { weight = it })
        ToggleRow("Default", isDefault, { isDefault = it })
        ToggleRow("Active", isActive, { isActive = it })
        Text("Prices", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
        VariantPriceRows(prices, canCost)
        if (existing != null) ProcCaption("Price and cost changes are proposals (Phase 3C): the live price stays until signed when the change is past your band.")
    }
}

/**
 * Define attribute groups, generate every combination, price them, then
 * create each through POST /variants with auto_name (ProductVariant::composeName).
 */
private class Combo(val id: Long = System.nanoTime(), var sku: String, val attrs: List<Pair<String, String>>, val prices: androidx.compose.runtime.snapshots.SnapshotStateList<VariantPrice>)

@Composable
private fun VariantGeneratorSheet(productId: Int, productSku: String, currencies: List<String>, canCost: Boolean, onDismiss: () -> Unit, done: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val groups = remember { mutableStateListOf(AttrPair(key = "Size", value = ""), AttrPair(key = "Colour", value = "")) }
    var step by remember { mutableStateOf(0) }
    val combos = remember { mutableStateListOf<Combo>() }
    val skus = remember { mutableStateMapOf<Long, String>() }
    var progress by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }

    fun generate() {
        val cleaned = groups.map { g -> g.key.trim() to g.value.split(",").map { it.trim() }.filter { it.isNotEmpty() } }.filter { it.first.isNotEmpty() && it.second.isNotEmpty() }
        if (cleaned.isEmpty()) { env.toasts.show("Add at least one attribute value to generate variants.", StatusTone.WARNING); return }
        var result: List<List<Pair<String, String>>> = listOf(emptyList())
        cleaned.forEach { (name, values) -> result = result.flatMap { prefix -> values.map { prefix + (name to it) } } }
        combos.clear(); skus.clear()
        result.forEach { attrs ->
            val suffix = attrs.joinToString("-") { it.second.uppercase().filter { c -> c.isLetterOrDigit() }.take(4) }
            val c = Combo(sku = "$productSku-$suffix", attrs = attrs, prices = mutableStateListOf<VariantPrice>().apply { currencies.forEach { add(VariantPrice(currency = it)) } })
            combos += c; skus[c.id] = c.sku
        }
        step = 1
    }

    suspend fun create() {
        busy = true
        var made = 0
        val snapshot = combos.toList()
        try {
            for ((i, c) in snapshot.withIndex()) {
                progress = "Creating ${i + 1} of ${snapshot.size}…"
                val body = J.obj(
                    "sku" to JsonPrimitive(skus[c.id] ?: c.sku), "auto_name" to JsonPrimitive(true), "is_default" to JsonPrimitive(false), "is_active" to JsonPrimitive(true),
                    "prices" to variantPricesJson(c.prices, canCost), "attributes" to JsonObject(c.attrs.associate { it.first to JsonPrimitive(it.second) }),
                )
                try { env.client.post("/v1/admin/products/$productId/variants", body); made += 1 }
                catch (e: Exception) {
                    env.toasts.error(e)
                    progress = "Stopped at ${skus[c.id] ?: c.sku}: fix it and continue."
                    repeat(made) { combos.removeAt(0) }
                    return
                }
            }
            env.toasts.show("$made variant(s) created")
            done(); onDismiss()
        } finally { busy = false }
    }

    InfoSheet("Generate variants", onDismiss) {
        if (step == 0) {
            Text("Values comma-separated. Every combination becomes a variant; SKUs are derived from the product SKU and can be edited next.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
            groups.forEachIndexed { i, g ->
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                    Field("Attribute", g.key, { v -> groups[i] = g.copy(key = v) }, Modifier.weight(0.8f))
                    Field("Values", g.value, { v -> groups[i] = g.copy(value = v) }, Modifier.weight(1.2f), placeholder = "S, M, L")
                    IconButton(onClick = { groups.removeAll { it.id == g.id } }) { Icon(Icons.Default.RemoveCircleOutline, contentDescription = "Remove", tint = Palette.danger) }
                }
            }
            TextButton(onClick = { groups += AttrPair(key = "", value = "") }) { Text("Add attribute") }
        } else {
            if (progress.isNotEmpty()) Text(progress, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            combos.forEach { c ->
                Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Space.xs)) {
                    Text(c.attrs.joinToString(" · ") { "${it.first}: ${it.second}" }, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
                    Field("SKU", skus[c.id] ?: c.sku, { skus[c.id] = it }, mono = true)
                    VariantPriceRows(c.prices, canCost)
                }
                HorizontalDivider(color = MaterialTheme.hub.line)
            }
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End, verticalAlignment = Alignment.CenterVertically) {
            TextButton(enabled = !busy, onClick = onDismiss) { Text("Cancel") }
            Spacer(Modifier.size(Space.s))
            Button(enabled = !busy && (step == 0 || combos.isNotEmpty()), onClick = { if (step == 0) generate() else scope.launch { create() } }) {
                if (busy) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp) else Text(if (step == 0) "Next: prices" else "Create ${combos.size} variants")
            }
        }
    }
}

// ── Bill of materials (production.view_bom to read, bom.edit to write) ───────

@Composable
internal fun BomTab(productId: Int, product: JsonElement) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val canEdit = env.session.current.can("bom.edit")
    val list = remember(productId) { Resource(scope) { env.client.get("/v1/admin/products/$productId/bom").json ?: JsonNull } }
    var editing by remember { mutableStateOf<JsonElement?>(null) }
    var adding by remember { mutableStateOf(false) }
    var feasibilityQty by remember { mutableStateOf("1") }
    val feasibility = remember { mutableStateMapOf<Int, JsonElement>() }
    LaunchedEffect(list) { list.load() }

    SectionCard("Bill of materials") {
        LoadableView(list.state, retry = { list.load() }, isEmpty = { it["data"].records.isEmpty() }, empty = { EmptyState("No BOM yet", if (canEdit) "Add the first version: materials and quantities per piece." else "Procurement defines what this product is made of.", actionLabel = if (canEdit) "New BOM version" else null) { adding = true } }) { json ->
            jrows(json["data"]).forEach { bom ->
                val bid = bom.int("id") ?: 0
                Column(Modifier.fillMaxWidth().background(MaterialTheme.hub.field, MaterialTheme.shapes.small).padding(Space.m), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                    Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                        Text("Version ${bom.int("version") ?: 0}", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
                        if (bom["is_active"].bool == true) StatusBadge("active", StatusTone.SUCCESS)
                        bom["variant"].takeIf { !it.isNull }?.let { Text(it["variant_name"].str ?: "", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                    }
                    Text("Total ${fmtMoney(bom["total_cost"], "KES")} · ${bom.int("items_count") ?: jrows(bom["items"]).size} items", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    bom.str("notes")?.takeIf { it.isNotEmpty() }?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                    DataTable(
                        listOf(TableColumn("Material", "material_name"), TableColumn("Code", "material_code"), TableColumn("Qty", "quantity", TableColumn.Kind.NUMBER, decimals = 3), TableColumn("Unit", "unit_of_measure"), TableColumn("Unit cost", "cost_per_unit", TableColumn.Kind.MONEY, fixedCurrency = "KES"), TableColumn("Line cost", "line_cost", TableColumn.Kind.MONEY, fixedCurrency = "KES")),
                        jrows(bom["items"]).map { JRow(it.id, flattenBomItem(it.json)) },
                    )
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                        DecimalField("Can we make", feasibilityQty, { feasibilityQty = it }, Modifier.weight(1f))
                        OutlinedButton(onClick = { scope.launch { runCatching { env.client.get("/v1/admin/products/$productId/bom/$bid/feasibility", mapOf("quantity" to feasibilityQty)).json }.getOrNull()?.let { feasibility[bid] = it } } }) { Text("Check") }
                    }
                    feasibility[bid]?.let { f ->
                        Text(f["summary"].str ?: (if (f["feasible"].bool == true) "Feasible" else "Short of materials"), style = MaterialTheme.typography.labelSmall, color = if (f["feasible"].bool == true) Palette.success else Palette.danger)
                        val short = jrows(f["shortfalls"])
                        if (short.isNotEmpty()) DataTable(listOf(TableColumn("Material", "material_name"), TableColumn("Required", "required", TableColumn.Kind.NUMBER), TableColumn("Available", "available", TableColumn.Kind.NUMBER), TableColumn("Short", "shortfall", TableColumn.Kind.NUMBER), TableColumn("UoM", "uom")), short)
                    }
                    if (canEdit) FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                        if (bom["is_active"].bool != true) OutlinedButton(onClick = { scope.launch { try { env.client.put("/v1/admin/products/$productId/bom/$bid/activate"); env.toasts.show("BOM activated"); list.load() } catch (e: Exception) { env.toasts.error(e) } } }) { Text("Activate") }
                        OutlinedButton(onClick = { editing = bom.json }) { Text("Edit") }
                        ConfirmButton("Delete BOM version", "This version is removed.", destructive = true, action = {
                            try { env.client.delete("/v1/admin/products/$productId/bom/$bid"); env.toasts.show("BOM deleted"); list.load() } catch (e: Exception) { env.toasts.error(e) }
                        }) { Text("Delete") }
                    }
                }
            }
        }
        if (canEdit) OutlinedButton(onClick = { adding = true }) { Text("New BOM version") }
    }

    if (adding) ProductBomEditSheet(productId, null, jrows(product["variants"]), onDismiss = { adding = false }) { list.load() }
    editing?.let { e -> ProductBomEditSheet(productId, e, jrows(product["variants"]), onDismiss = { editing = null }) { list.load() } }
}

private fun flattenBomItem(j: JsonElement): JsonElement {
    val o = (j as? JsonObject)?.toMutableMap() ?: mutableMapOf()
    o["material_name"] = j["material"]["name"] ?: JsonNull
    o["material_code"] = j["material"]["code"] ?: JsonNull
    o["cost_per_unit"] = j["material"]["cost_per_unit"] ?: JsonNull
    return JsonObject(o)
}

private data class BomItem(val id: Long = System.nanoTime(), val materialId: Int, val name: String, val quantity: String, val uom: String, val notes: String)

@Composable
private fun ProductBomEditSheet(productId: Int, existing: JsonElement?, variants: List<JRow>, onDismiss: () -> Unit, done: () -> Unit) {
    val env = LocalEnv.current
    var notes by remember { mutableStateOf(existing["notes"].str ?: "") }
    var variantId by remember { mutableStateOf<Int?>(null) }
    val items = remember {
        mutableStateListOf<BomItem>().apply {
            existing["items"].records.forEach { i ->
                val mid = i["material_id"].int ?: return@forEach
                add(BomItem(materialId = mid, name = i["material"]["name"].str ?: "Material $mid", quantity = i["quantity"].str ?: "", uom = i["unit_of_measure"].str ?: "", notes = i["notes"].str ?: ""))
            }
        }
    }
    var search by remember { mutableStateOf("") }
    var results by remember { mutableStateOf<List<JRow>>(emptyList()) }
    LaunchedEffect(search) {
        val q = search
        if (q.length < 2) { results = emptyList(); return@LaunchedEffect }
        delay(300)
        if (!isActive) return@LaunchedEffect
        runCatching { env.client.get("/v1/admin/inventory/materials", mapOf("search" to q, "per_page" to "30"), background = true) }.getOrNull()?.let { results = jrows(it.json["data"]) }
    }

    FormSheet(if (existing == null) "New BOM version" else "Edit BOM version ${existing["version"].int ?: 0}", onDismiss, canSave = items.isNotEmpty() && items.none { procDecimal(it.quantity) == null }, save = {
        val body = J.obj(
            "items" to toJson(items.map { J.obj("material_id" to JsonPrimitive(it.materialId), "quantity" to (J.decimal(it.quantity) ?: JsonPrimitive(0)), "unit_of_measure" to JsonPrimitive(it.uom), "notes" to J.s(it.notes)) }),
            "notes" to J.sOpt(notes),
            "product_variant_id" to (if (existing == null) variantId?.let { JsonPrimitive(it) } else null),
        )
        val bid = existing["id"].int
        if (existing != null && bid != null) env.client.put("/v1/admin/products/$productId/bom/$bid", body) else env.client.post("/v1/admin/products/$productId/bom", body)
        env.toasts.show("BOM saved")
        done()
    }) { _ ->
        if (existing == null && variants.isNotEmpty()) SelectField("For a variant (optional)", variantId, variants.mapNotNull { v -> v.int("id")?.let { it to (v.str("variant_name") ?: "") } }, { variantId = it }, Modifier.fillMaxWidth(), placeholder = "Whole product")
        Field("Notes", notes, { notes = it })
        Text("Materials per piece", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
        items.forEachIndexed { i, it ->
            Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Space.xs)) {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text(it.name, style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f))
                    IconButton(onClick = { items.removeAll { r -> r.id == it.id } }) { Icon(Icons.Default.RemoveCircleOutline, contentDescription = "Remove", tint = Palette.danger) }
                }
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    DecimalField("Qty", it.quantity, { v -> items[i] = it.copy(quantity = v) }, Modifier.weight(1f))
                    Field("UoM", it.uom, { v -> items[i] = it.copy(uom = v) }, Modifier.weight(1f))
                }
                Field("Note", it.notes, { v -> items[i] = it.copy(notes = v) })
            }
        }
        Field("Add a material (search raw materials)", search, { search = it }, placeholder = "Name or code")
        results.forEach { m ->
            Row(Modifier.fillMaxWidth().clickable {
                m.int("id")?.let { id -> items += BomItem(materialId = id, name = m.str("name") ?: "Material", quantity = "1", uom = m.str("unit_of_measure") ?: "", notes = ""); search = ""; results = emptyList() }
            }.padding(vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                Text(m.str("name") ?: "", style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f))
                Text(m.str("code") ?: "", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                Text(m.str("unit_of_measure") ?: "", style = MaterialTheme.typography.labelSmall)
            }
        }
    }
}
