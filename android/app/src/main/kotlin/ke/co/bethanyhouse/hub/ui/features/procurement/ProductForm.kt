package ke.co.bethanyhouse.hub.ui.features.procurement

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
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
import androidx.compose.runtime.snapshots.SnapshotStateList
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toJson
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.DecimalField
import ke.co.bethanyhouse.hub.ui.components.ErrorState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.JRow
import ke.co.bethanyhouse.hub.ui.components.Loadable
import ke.co.bethanyhouse.hub.ui.components.LoadingSkeleton
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.RemoteImage
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.fmtMoney
import ke.co.bethanyhouse.hub.ui.components.fmtNumber
import ke.co.bethanyhouse.hub.ui.components.jrows
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonPrimitive

/**
 * The product form (ProductFormPage.tsx): one screen for a new product and
 * for editing. Writes go through POST/PUT /products; price and cost changes
 * on an existing product come back as proposals (Phase 3C) and the toast
 * says so. Images, variants and BOM live in ProductFormTabs.kt.
 */
internal data class PriceRow(val id: Long = System.nanoTime(), val rowId: Int?, val currency: String, val regular: String = "", val sale: String = "", val cost: String = "", val saleStart: String = "", val saleEnd: String = "")
internal data class Measurement(val id: Long = System.nanoTime(), val name: String = "", val unit: String = "", val required: Boolean = true)
internal data class ProductFeature(val id: Long = System.nanoTime(), val icon: String = "", val text: String = "")

private fun cleanStr(v: JsonElement?): String = if (v.isNull) "" else v.str ?: ""

internal class ProductFormModel {
    var product by mutableStateOf<JsonElement?>(null)
    var sku by mutableStateOf("")
    var skuManual by mutableStateOf(false)
    var slug by mutableStateOf("")
    var categoryId by mutableStateOf<Int?>(null)
    var type by mutableStateOf("simple")
    var status by mutableStateOf("draft")
    var featured by mutableStateOf(false)
    var producible by mutableStateOf(false)
    var brand by mutableStateOf("")
    var taxClass by mutableStateOf("standard")
    var lowStock by mutableStateOf("5")
    var weight by mutableStateOf(""); var length by mutableStateOf(""); var width by mutableStateOf(""); var height by mutableStateOf("")
    var name by mutableStateOf(""); var description by mutableStateOf(""); var shortDescription by mutableStateOf("")
    val prices: SnapshotStateList<PriceRow> = mutableStateListOf(PriceRow(rowId = null, currency = "KES"))
    var metaTitle by mutableStateOf(""); var metaDescription by mutableStateOf(""); var metaKeywords by mutableStateOf(""); var canonical by mutableStateOf(""); var ogTitle by mutableStateOf(""); var ogDescription by mutableStateOf("")
    val measurements: SnapshotStateList<Measurement> = mutableStateListOf()
    val features: SnapshotStateList<ProductFeature> = mutableStateListOf()
    val stageIds: SnapshotStateList<Int> = mutableStateListOf()
    val taxRateIds: SnapshotStateList<Int> = mutableStateListOf()

    fun apply(p: JsonElement, currencies: List<String>) {
        product = p
        sku = p["sku"].str ?: ""; skuManual = true
        slug = p["slug"].str ?: ""; categoryId = p["category_id"].int
        type = p["product_type"].str ?: "simple"; status = p["status"].str ?: "draft"
        featured = p["is_featured"].bool ?: false; producible = p["is_producible"].bool ?: false
        brand = p["brand"].str ?: ""; taxClass = p["tax_class"].str ?: "standard"; lowStock = p["low_stock_threshold"].str ?: "5"
        weight = cleanStr(p["weight"]); length = cleanStr(p["length"]); width = cleanStr(p["width"]); height = cleanStr(p["height"])
        val translations = p["translations"].records
        val en = translations.firstOrNull { it["language_code"].str == "en" } ?: translations.firstOrNull()
        name = en["name"].str ?: ""; description = en["description"].str ?: ""; shortDescription = en["short_description"].str ?: ""
        val rows = p["prices"].records
        val codes = currencies.toMutableList()
        rows.forEach { r -> r["currency_code"].str?.let { if (it !in codes) codes += it } }
        if ("KES" !in codes) codes.add(0, "KES")
        prices.clear()
        prices += codes.map { code ->
            val r = rows.firstOrNull { it["currency_code"].str == code }
            PriceRow(rowId = r["id"].int, currency = code, regular = cleanStr(r["regular_price"]), sale = cleanStr(r["sale_price"]), cost = cleanStr(r["cost_price"]), saleStart = cleanStr(r["sale_start_date"]).take(10), saleEnd = cleanStr(r["sale_end_date"]).take(10))
        }
        val seos = p["seo"].records
        val seo = seos.firstOrNull { it["language_code"].str == "en" } ?: seos.firstOrNull()
        metaTitle = seo["meta_title"].str ?: ""; metaDescription = seo["meta_description"].str ?: ""; metaKeywords = seo["meta_keywords"].str ?: ""
        canonical = seo["canonical_url"].str ?: ""; ogTitle = seo["og_title"].str ?: ""; ogDescription = seo["og_description"].str ?: ""
        measurements.clear(); measurements += p["measurements"].records.map { Measurement(name = it["name"].str ?: "", unit = it["unit"].str ?: "", required = it["required"].bool ?: true) }
        features.clear(); features += p["features"].records.map { ProductFeature(icon = it["icon"].str ?: "", text = it["text"].str ?: "") }
        stageIds.clear(); stageIds += p["production_stage_ids"].records.mapNotNull { it.int }
        taxRateIds.clear(); taxRateIds += p["tax_rate_ids"].records.mapNotNull { it.int }
    }

    fun seedCurrencies(currencies: List<String>) {
        if (product != null) return
        val codes = currencies.toMutableList(); if ("KES" !in codes) codes.add(0, "KES")
        prices.clear(); prices += codes.map { PriceRow(rowId = null, currency = it) }
    }

    /** Mirrors the console's SKU helper: category code + name initials; the person may type over it. */
    fun autoSku(categoryName: String?) {
        if (skuManual || name.isEmpty()) return
        val cat = (categoryName ?: "").split(" ").firstOrNull()?.filter { it.isLetter() }?.take(3)?.uppercase() ?: ""
        val initials = name.split(" ").filter { it.isNotEmpty() }.take(3).mapNotNull { it.firstOrNull()?.uppercase() }.joinToString("")
        val stamp = ((System.currentTimeMillis() / 1000) % 10000).toString()
        sku = listOf(cat.ifEmpty { "GEN" }, initials.ifEmpty { "PRD" }, stamp).joinToString("-")
    }

    /** ProductCreatePayload; cost only rides on KES rows (withCostOnKesOnly). */
    fun payload(canCost: Boolean): JsonElement {
        val priceRows = prices.mapNotNull { r ->
            val reg = J.decimal(r.regular) ?: return@mapNotNull null
            J.obj(
                "currency_code" to JsonPrimitive(r.currency), "regular_price" to reg, "sale_price" to (J.decimal(r.sale) ?: JsonNull),
                "cost_price" to (if (canCost && r.currency == "KES") (J.decimal(r.cost) ?: JsonNull) else null),
                "sale_start_date" to J.s(r.saleStart), "sale_end_date" to J.s(r.saleEnd),
            )
        }
        return J.obj(
            "sku" to JsonPrimitive(sku.trim()), "product_type" to JsonPrimitive(type), "status" to JsonPrimitive(status),
            "is_featured" to JsonPrimitive(featured), "is_producible" to JsonPrimitive(producible), "tax_class" to JsonPrimitive(taxClass.ifEmpty { "standard" }),
            "low_stock_threshold" to JsonPrimitive(lowStock.toIntOrNull() ?: 5), "category_id" to (categoryId?.let { JsonPrimitive(it) } ?: JsonNull),
            "translations" to toJson(listOf(jsonOf("language_code" to "en", "name" to name, "description" to description, "short_description" to shortDescription))),
            "seo" to toJson(listOf(jsonOf("language_code" to "en", "meta_title" to metaTitle, "meta_description" to metaDescription, "meta_keywords" to metaKeywords, "canonical_url" to canonical.ifEmpty { null }, "og_title" to ogTitle, "og_description" to ogDescription))),
            "measurements" to toJson(measurements.filter { it.name.isNotEmpty() }.map { jsonOf("name" to it.name, "unit" to it.unit, "required" to it.required) }),
            "features" to toJson(features.filter { it.text.isNotBlank() }.map { jsonOf("icon" to it.icon, "text" to it.text) }),
            "production_stage_ids" to J.ints(stageIds.sorted()),
            "slug" to J.sOpt(slug), "brand" to J.sOpt(brand),
            "weight" to J.decimal(weight), "length" to J.decimal(length), "width" to J.decimal(width), "height" to J.decimal(height),
            "prices" to toJson(priceRows),
        )
    }
}

private const val TAB_CONTENT = "content"

@Composable
fun ProductFormScreen(id: Int?) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val s = env.session.current
    val model = remember(id) { ProductFormModel() }
    var state by remember(id) { mutableStateOf<Loadable<JsonElement>>(Loadable.Idle) }
    var tab by remember(id) { mutableStateOf(TAB_CONTENT) }
    var categories by remember { mutableStateOf<List<Pair<Int, String>>>(emptyList()) }
    var currencies by remember { mutableStateOf<List<String>>(emptyList()) }
    var stages by remember { mutableStateOf<List<JRow>>(emptyList()) }
    var taxRates by remember { mutableStateOf<List<JRow>>(emptyList()) }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<ApiError?>(null) }

    val isEditing = id != null
    val canSave = if (isEditing) s.can("products.edit") else s.can("products.create")
    val canCost = s.can("products.view_cost")
    val canEditCost = s.can("products.edit_cost")
    val categoryName = model.categoryId?.let { cid -> categories.firstOrNull { it.first == cid }?.second }

    fun load() {
        val pid = id ?: return
        state = Loadable.Loading(state.current)
        scope.launch {
            try {
                val j = env.client.get("/v1/admin/products/$pid").json ?: JsonNull
                model.apply(j["product"] ?: j, currencies)
                state = Loadable.Loaded(j)
            } catch (e: ApiError) { state = Loadable.Failed(e, state.current) } catch (e: Exception) { state = Loadable.Failed(ApiError.transport(e.message ?: "Error"), state.current) }
        }
    }

    LaunchedEffect(id) {
        val c = env.client
        runCatching { c.get("/v1/admin/categories", background = true) }.getOrNull()?.let { r -> categories = r.json["data"].records.mapNotNull { cat -> cat["id"].int?.let { it to (cat["name_en"].str ?: "Category $it") } } }
        runCatching { c.get("/v1/admin/currencies-management", background = true) }.getOrNull()?.let { r -> currencies = r.json["data"].records.filter { it["is_active"].bool ?: true }.mapNotNull { it["code"].str } }
        runCatching { c.get("/v1/admin/product-stages", background = true) }.getOrNull()?.let { r -> stages = jrows(if (r.json is kotlinx.serialization.json.JsonArray) r.json else r.json["data"]) }
        runCatching { c.get("/v1/admin/tax-rates", background = true) }.getOrNull()?.let { r -> taxRates = jrows(r.json["data"]) }
        model.seedCurrencies(currencies)
        if (isEditing) load()
    }

    suspend fun save() {
        saving = true
        error = null
        try {
            val body = model.payload(canCost)
            val r: ApiClient.Response = if (id != null) env.client.put("/v1/admin/products/$id", body) else env.client.post("/v1/admin/products", body)
            val pid = r.json["product"]["id"].int ?: id
            if (pid != null && taxRates.isNotEmpty()) runCatching { env.client.post("/v1/admin/products/$pid/tax-rates", jsonOf("tax_rate_ids" to model.taxRateIds.sorted())) }
            val waiting = r.json["proposals"].records.filter { it["status"].str != "applied" }
            env.toasts.show(r.json["message"].str ?: (if (isEditing) "Product saved" else "Product created"), if (waiting.isEmpty()) StatusTone.SUCCESS else StatusTone.INFO)
            if (!isEditing && pid != null) env.navigator.go(Route.Product(pid)) else load()
        } catch (e: ApiError) { error = e; env.toasts.error(e) } catch (e: Exception) { env.toasts.error(e) } finally { saving = false }
    }

    suspend fun put(action: String) {
        val pid = id ?: return
        try { val r = env.client.put("/v1/admin/products/$pid/$action"); env.toasts.show(r.json["message"].str ?: "Done"); load() } catch (e: Exception) { env.toasts.error(e) }
    }

    val productStatus = model.product["status"].str
    Page(if (isEditing) model.name.ifEmpty { "Product" } else "New product", subtitle = if (isEditing) "${model.sku} · ${model.status.humanized}" else null, refresh = if (isEditing) suspend { load() } else null) {
        val st = state
        if (isEditing && st is Loadable.Loading && st.previous == null) LoadingSkeleton()
        else if (isEditing && st is Loadable.Failed && st.previous == null) ErrorState(st.error, retry = { load() })
        else if (isEditing && st is Loadable.Idle) LoadingSkeleton()
        else {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                model.product?.let { p -> RemoteImage(p["images"].records.firstOrNull()["image_url"].str, 44.dp); StatusBadge(p["status"].str) }
                Spacer(Modifier.weight(1f))
            }
            FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                if (isEditing && s.can("products.edit")) {
                    if (productStatus != "active") OutlinedButton(onClick = { scope.launch { put("publish") } }) { Text("Publish") }
                    if (productStatus != "archived") ConfirmButton("Archive product", "The product leaves the storefront and the till; it stays on record.", destructive = true, action = { put("archive") }) { Text("Archive") }
                }
                if (canSave) Button(enabled = !saving && model.name.isNotEmpty() && model.sku.isNotEmpty(), onClick = { scope.launch { save() } }) { if (saving) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp) else Text(if (isEditing) "Save" else "Create product") }
                else if (canEditCost) Text("You hold cost only: propose a KES cost on the Pricing tab.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            }
            val tabList = buildList {
                add("content" to "Content"); add("media" to "Images"); add("pricing" to "Pricing"); add("variants" to "Variants"); add("features" to "Features"); add("seo" to "SEO")
                if (model.producible) { add("measurements" to "Measurements"); add("stages" to "Production Stages") }
                if (s.can("production.view_bom")) add("bom" to "Bill of Materials")
            }
            SegmentedTabs(tabList, tab) { tab = it }
            val needSave: @Composable () -> Unit = { Text("Save the product first, then add this.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary) }
            when (tab) {
                "media" -> if (id != null) ProductImagesTab(id, model.product ?: JsonNull) { load() } else needSave()
                "pricing" -> PricingTab(model, error, canCost, canSave, isEditing && canEditCost && !s.can("products.edit")) { load() }
                "variants" -> if (id != null) ProductVariantsTab(id, model.product ?: JsonNull, currencies) { load() } else needSave()
                "features" -> FeaturesTab(model)
                "seo" -> SeoTab(model, error)
                "measurements" -> MeasurementsTab(model)
                "stages" -> StagesTab(model, stages)
                "bom" -> if (id != null) BomTab(id, model.product ?: JsonNull) else needSave()
                else -> ContentTab(model, error, categories, taxRates, isEditing, categoryName)
            }
            error?.let { e -> if (e.errors.isEmpty()) Text(e.message, style = MaterialTheme.typography.bodySmall, color = Palette.danger) }
        }
    }
}

@Composable
private fun ContentTab(m: ProductFormModel, error: ApiError?, categories: List<Pair<Int, String>>, taxRates: List<JRow>, isEditing: Boolean, categoryName: String?) {
    SectionCard("Content") {
        Field("Name *", m.name, { m.name = it; if (!isEditing) m.autoSku(categoryName) }, error = error?.field("translations.0.name"))
        Field("SKU *", m.sku, { m.sku = it; m.skuManual = true }, error = error?.field("sku"), mono = true)
        Field("Slug", m.slug, { m.slug = it }, placeholder = "Auto from the name")
        SelectField("Category", m.categoryId, categories, { m.categoryId = it; if (!isEditing) m.autoSku(categories.firstOrNull { c -> c.first == it }?.second) }, Modifier.fillMaxWidth(), placeholder = "None")
        error?.field("category_id")?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = Palette.danger) }
        OptionPicker("Type", m.type, ProductVocab.types, { if (it.isNotEmpty()) m.type = it })
        OptionPicker("Status", m.status, ProductVocab.statuses.map { it to it.humanized }, { if (it.isNotEmpty()) m.status = it })
        ToggleRow("Featured", m.featured, { m.featured = it })
        ToggleRow("Producible (made in-house)", m.producible, { m.producible = it })
        Field("Brand", m.brand, { m.brand = it })
        Field("Tax class", m.taxClass, { m.taxClass = it }, placeholder = "standard")
        Field("Low-stock threshold", m.lowStock, { m.lowStock = it.filter { c -> c.isDigit() } }, placeholder = "5", keyboard = androidx.compose.ui.text.input.KeyboardType.Number)
        Field("Short description", m.shortDescription, { m.shortDescription = it }, multiline = true)
        Field("Description *", m.description, { m.description = it }, multiline = true, error = error?.field("translations.0.description"))
        ProcCaption("Stored as HTML; the console's rich editor writes the same field.")
        Text("Physical", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            DecimalField("Weight (kg)", m.weight, { m.weight = it }, Modifier.weight(1f))
            DecimalField("Length (cm)", m.length, { m.length = it }, Modifier.weight(1f))
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            DecimalField("Width (cm)", m.width, { m.width = it }, Modifier.weight(1f))
            DecimalField("Height (cm)", m.height, { m.height = it }, Modifier.weight(1f))
        }
        if (taxRates.isNotEmpty()) {
            Text("Tax rates", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
            taxRates.forEach { t ->
                val tid = t.int("id") ?: return@forEach
                ToggleRow("${t.str("name") ?: ""} (${fmtNumber(t.dbl("rate"), 2)}%)", tid in m.taxRateIds, { on -> if (on) { if (tid !in m.taxRateIds) m.taxRateIds += tid } else m.taxRateIds.remove(tid) })
            }
        }
    }
}

@Composable
private fun PricingTab(m: ProductFormModel, error: ApiError?, canCost: Boolean, canSave: Boolean, showCostProposal: Boolean, changed: () -> Unit) {
    SectionCard("Pricing per currency") {
        Text("Customer pricing (currencies.exchange_rate), never the reporting rate. A price or cost change on an existing product is a proposal: within the maker's band it applies at once, otherwise it waits in Approvals and the live price stays.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
        m.prices.forEachIndexed { i, row ->
            Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Space.xs)) {
                Text(row.currency, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    DecimalField("Regular price", row.regular, { v -> m.prices[i] = row.copy(regular = v) }, Modifier.weight(1f), error = if (i == 0) error?.field("prices.0.regular_price") else null)
                    DecimalField("Sale price", row.sale, { v -> m.prices[i] = row.copy(sale = v) }, Modifier.weight(1f))
                }
                if (canCost && row.currency == "KES") DecimalField("Cost (KES)", row.cost, { v -> m.prices[i] = row.copy(cost = v) }, enabled = canSave)
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    Field("Sale starts (YYYY-MM-DD)", row.saleStart, { v -> m.prices[i] = row.copy(saleStart = v) }, Modifier.weight(1f))
                    Field("Sale ends", row.saleEnd, { v -> m.prices[i] = row.copy(saleEnd = v) }, Modifier.weight(1f))
                }
            }
            HorizontalDivider(color = MaterialTheme.hub.line)
        }
        if (!canCost) ProcCaption("Cost is need-to-know (products.view_cost); it is not shown here and is left untouched on save.")
        if (showCostProposal) m.product?.let { p -> CostProposalPanel(p, changed) }
    }
}

@Composable
private fun FeaturesTab(m: ProductFormModel) {
    SectionCard("Storefront features (icon + short line)") {
        m.features.forEachIndexed { i, f ->
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                Field("Icon (emoji)", f.icon, { v -> m.features[i] = f.copy(icon = v) }, Modifier.weight(0.6f))
                Field("Feature", f.text, { v -> m.features[i] = f.copy(text = v) }, Modifier.weight(1.4f), placeholder = "e.g. Hand-finished seams")
                IconButton(onClick = { m.features.removeAll { it.id == f.id } }) { Icon(Icons.Default.RemoveCircleOutline, contentDescription = "Remove", tint = Palette.danger) }
            }
        }
        TextButton(onClick = { m.features += ProductFeature() }) { Text("Add feature") }
    }
}

@Composable
private fun SeoTab(m: ProductFormModel, error: ApiError?) {
    SectionCard("SEO (English)") {
        Field("Meta title", m.metaTitle, { m.metaTitle = it })
        Field("Meta description", m.metaDescription, { m.metaDescription = it }, multiline = true)
        Field("Meta keywords", m.metaKeywords, { m.metaKeywords = it }, placeholder = "comma, separated")
        Field("Canonical URL", m.canonical, { m.canonical = it }, placeholder = "https://…", error = error?.field("seo.0.canonical_url"))
        Field("Open Graph title", m.ogTitle, { m.ogTitle = it })
        Field("Open Graph description", m.ogDescription, { m.ogDescription = it }, multiline = true)
    }
}

@Composable
private fun MeasurementsTab(m: ProductFormModel) {
    SectionCard("Measurements taken for a made-to-order piece") {
        m.measurements.forEachIndexed { i, mm ->
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                Field("Name", mm.name, { v -> m.measurements[i] = mm.copy(name = v) }, Modifier.weight(1.4f), placeholder = "e.g. Chest")
                Field("Unit", mm.unit, { v -> m.measurements[i] = mm.copy(unit = v) }, Modifier.weight(0.7f))
                IconButton(onClick = { m.measurements.removeAll { it.id == mm.id } }) { Icon(Icons.Default.RemoveCircleOutline, contentDescription = "Remove", tint = Palette.danger) }
            }
            ToggleRow("Required", mm.required, { v -> m.measurements[i] = mm.copy(required = v) })
        }
        TextButton(onClick = { m.measurements += Measurement() }) { Text("Add measurement") }
    }
}

@Composable
private fun StagesTab(m: ProductFormModel, stages: List<JRow>) {
    SectionCard("Production stages this product passes through") {
        Text("Pick the stages this product actually needs; its production orders carry only those, in catalogue order (Setup → Production Stages). None selected means every active stage.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
        stages.forEach { st ->
            val sid = st.int("id") ?: return@forEach
            ToggleRow((st.str("name") ?: "Stage $sid") + (if (st["active"].bool == false) " (inactive)" else ""), sid in m.stageIds, { on -> if (on) { if (sid !in m.stageIds) m.stageIds += sid } else m.stageIds.remove(sid) })
        }
    }
}

/**
 * products.edit_cost without products.edit: the one write is a KES cost per
 * price row, proposed (POST /proposals, event product_cost_change) — within
 * 5% of the cost 24 hours ago it applies at once, else finance signs.
 */
private class CostRow(val id: Int, val label: String, val cost: JsonElement?)

@Composable
private fun CostProposalPanel(product: JsonElement, changed: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val values = remember { mutableStateMapOf<Int, String>() }
    var busy by remember { mutableStateOf(false) }
    val rows = remember(product) {
        buildList {
            product["prices"].records.filter { it["currency_code"].str == "KES" }.forEach { p -> p["id"].int?.let { add(CostRow(it, "Product", p["cost_price"])) } }
            product["variants"].records.forEach { v -> v["prices"].records.filter { it["currency_code"].str == "KES" }.forEach { p -> p["id"].int?.let { add(CostRow(it, v["variant_name"].str ?: "Variant", p["cost_price"])) } } }
        }
    }

    suspend fun propose(rowId: Int) {
        busy = true
        try {
            val r = env.client.post("/v1/admin/proposals", J.obj("event" to JsonPrimitive("product_cost_change"), "subject_id" to JsonPrimitive(rowId), "changes" to J.obj("cost_price" to (J.decimal(values[rowId] ?: "") ?: JsonNull))))
            val applied = r.json["proposal"]["status"].str == "applied" || r.json["proposal"].isNull
            env.toasts.show(r.json["message"].str ?: "Proposed", if (applied) StatusTone.SUCCESS else StatusTone.INFO)
            values.remove(rowId)
            changed()
        } catch (e: Exception) { env.toasts.error(e) } finally { busy = false }
    }

    Column(Modifier.fillMaxWidth().background(MaterialTheme.hub.field, MaterialTheme.shapes.small).padding(Space.m), verticalArrangement = Arrangement.spacedBy(Space.s)) {
        Text("Cost (KES)", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
        Text("Within 5% of the cost 24 hours ago it applies at once; more needs approval from finance (and the super admin above 25%).", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
        if (rows.isEmpty()) ProcCaption("This product has no KES price row yet, so it has nowhere to keep a cost.")
        rows.forEach { r ->
            Text("${r.label} — now ${if (r.cost.isNull) "no cost" else fmtMoney(r.cost, "KES")}", style = MaterialTheme.typography.bodySmall)
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                DecimalField("New cost", values[r.id] ?: "", { values[r.id] = it }, Modifier.weight(1f))
                OutlinedButton(enabled = !busy && procDecimal(values[r.id] ?: "") != null, onClick = { scope.launch { propose(r.id) } }) { Text("Propose") }
            }
        }
    }
}
