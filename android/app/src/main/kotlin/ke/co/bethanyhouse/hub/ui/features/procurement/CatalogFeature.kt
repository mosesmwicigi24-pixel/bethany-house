package ke.co.bethanyhouse.hub.ui.features.procurement

import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.DocumentButton
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.InfoSheet
import ke.co.bethanyhouse.hub.ui.components.Loadable
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.PickedFile
import ke.co.bethanyhouse.hub.ui.components.RemoteImage
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.rememberFilePicker
import ke.co.bethanyhouse.hub.ui.hub
import ke.co.bethanyhouse.hub.ui.shell.FeatureRegistry
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull

/**
 * Catalog: products list (search, filters, CSV bulk import), product form
 * (content, images & video, pricing per currency through proposals,
 * variants, features, SEO, measurements, production stages, BOM), publish,
 * archive; categories tree with CRUD, subcategories, reorder, image, toggle.
 * Endpoints as CatalogFeature.swift (ProductController, CategoryController,
 * BomController and react-admin/src/api/{products,categories,bom,proposals}.ts).
 */
object CatalogFeature {
    fun register() {
        FeatureRegistry.register(Route.Products) { ProductsListScreen() }
        FeatureRegistry.registerType<Route.Product> { r -> ProductFormScreen(if (r.new) null else r.id) }
        FeatureRegistry.register(Route.Categories) { CategoriesScreen() }
    }
}

internal object ProductVocab {
    val statuses = listOf("draft", "active", "inactive", "archived")
    val types: List<Pair<String, String>> = listOf("simple" to "Simple", "variable" to "Variable", "made_to_order" to "Made to order")
}

internal class ProductListItem(val json: JsonElement) {
    val id: Int get() = json["id"].int ?: 0
    val sku: String get() = json["sku"].str ?: ""
    val name: String get() = json["en_translation"]["name"].str ?: sku
    val status: String get() = json["status"].str ?: "draft"
    val type: String get() = json["product_type"].str ?: "simple"
    val category: String? get() = json["category"]["name_en"].str
    val image: String? get() = json["primary_image"]["image_url"].str
    val stock: Int get() = json["total_stock"].int ?: 0
    val lowThreshold: Int get() = json["low_stock_threshold"].int ?: 0
    val variants: Int get() = json["variants_count"].int ?: 0
    private val priceCurrency: String get() = json["base_price"]["currency_code"].str ?: "KES"
    val price: Money? get() = Money.from(json["base_price"]["regular_price"], priceCurrency)
    val salePrice: Money? get() = json["base_price"]["sale_price"].takeIf { !it.isNull }?.let { Money.from(it, priceCurrency) }
    val featured: Boolean get() = json["is_featured"].bool ?: false
    val producible: Boolean get() = json["is_producible"].bool ?: false
}

/** GET /products → `{data, meta: {current_page,last_page,total,from,to}, stats, brands, categories}`. */
private class ProductListModel(private val scope: CoroutineScope, private val client: ApiClient) {
    var items by mutableStateOf<List<ProductListItem>>(emptyList())
    var stats by mutableStateOf<JsonElement?>(null)
    var brands by mutableStateOf<List<String>>(emptyList())
    var categories by mutableStateOf<List<Pair<Int, String>>>(emptyList())
    var page by mutableStateOf(1)
    var lastPage by mutableStateOf(1)
    var total by mutableStateOf(0)
    var state by mutableStateOf<Loadable<List<ProductListItem>>>(Loadable.Idle)
    var query: Map<String, String?> = emptyMap()
    private var generation = 0

    fun load(page: Int = 1) { scope.launch { reload(page) } }

    suspend fun reload(page: Int = this.page) {
        val gen = ++generation
        this.page = page
        state = Loadable.Loading(items.takeIf { it.isNotEmpty() })
        try {
            val r = client.get("/v1/admin/products", query + mapOf("page" to page.toString(), "per_page" to "40"))
            if (gen != generation) return
            items = r.json["data"].records.map(::ProductListItem)
            lastPage = r.json["meta"]["last_page"].int ?: 1
            total = r.json["meta"]["total"].int ?: items.size
            stats = r.json["stats"]
            r.json["brands"].records.mapNotNull { it.str }.takeIf { it.isNotEmpty() }?.let { brands = it }
            r.json["categories"].records.mapNotNull { c -> c["id"].int?.let { it to (c["name_en"].str ?: "Category $it") } }.takeIf { it.isNotEmpty() }?.let { categories = it }
            state = Loadable.Loaded(items)
        } catch (e: ApiError) {
            if (gen == generation) state = Loadable.Failed(e, items.takeIf { it.isNotEmpty() })
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            if (gen == generation) state = Loadable.Failed(ApiError.transport(e.message ?: "Error"), items.takeIf { it.isNotEmpty() })
        }
    }
}

@Composable
fun ProductsListScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val s = env.session.current
    val model = remember { ProductListModel(scope, env.client) }
    var search by remember { mutableStateOf("") }
    var status by remember { mutableStateOf("") }
    var type by remember { mutableStateOf("") }
    var categoryId by remember { mutableStateOf<Int?>(null) }
    var brand by remember { mutableStateOf("") }
    var featured by remember { mutableStateOf("") }
    var producible by remember { mutableStateOf("") }
    var importing by remember { mutableStateOf(false) }

    fun apply() {
        model.query = mapOf(
            "search" to search.ifEmpty { null }, "status" to status.ifEmpty { null }, "product_type" to type.ifEmpty { null },
            "category_id" to categoryId?.toString(), "brand" to brand.ifEmpty { null },
            "is_featured" to featured.ifEmpty { null }, "is_producible" to producible.ifEmpty { null },
        )
        model.load()
    }
    LaunchedEffect(status, type, categoryId, brand, featured, producible) { apply() }

    val st = model.stats
    val subtitle = if (st == null) null else "${st["total"].int ?: model.total} products · ${st["active"].int ?: 0} active · ${st["draft"].int ?: 0} draft · ${st["archived"].int ?: 0} archived"

    suspend fun archive(p: ProductListItem) { try { env.client.put("/v1/admin/products/${p.id}/archive"); env.toasts.show("Archived"); model.reload() } catch (e: Exception) { env.toasts.error(e) } }
    suspend fun delete(p: ProductListItem) { try { env.client.delete("/v1/admin/products/${p.id}"); env.toasts.show("Deleted"); model.reload() } catch (e: Exception) { env.toasts.error(e) } }

    Page("Products", subtitle = subtitle, refresh = { model.reload() }) {
        FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
            if (s.can("products.import")) OutlinedButton(onClick = { importing = true }) { Text("Bulk import") }
            if (s.can("products.create")) Button(onClick = { env.navigator.push(Route.Product(new = true)) }) { Text("New product") }
        }
        ModuleSearchField(search, { search = it }, "Search name or SKU", onSubmit = { apply() })
        OptionPicker("Status", status, ProductVocab.statuses.map { it to it.humanized }, { status = it }, allowNone = true)
        OptionPicker("Type", type, ProductVocab.types, { type = it }, allowNone = true)
        SelectField("Category", categoryId, model.categories, { categoryId = it }, Modifier.fillMaxWidth(), placeholder = "All categories")
        if (model.brands.isNotEmpty()) OptionPicker("Brand", brand, model.brands.map { it to it }, { brand = it }, allowNone = true)
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            OptionPicker("Featured", featured, listOf("true" to "Featured", "false" to "Not featured"), { featured = it }, Modifier.weight(1f), allowNone = true)
            OptionPicker("Producible", producible, listOf("true" to "Producible", "false" to "Not producible"), { producible = it }, Modifier.weight(1f), allowNone = true)
        }
        LoadableView(model.state, retry = { model.load(model.page) }, isEmpty = { it.isEmpty() }, empty = { EmptyState("No products match", "Change the filters, or add a product.") }) { items ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                items.forEach { p ->
                    var menu by remember(p.id) { mutableStateOf(false) }
                    Surface(shape = MaterialTheme.shapes.small, color = MaterialTheme.hub.card, modifier = Modifier.fillMaxWidth().border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.small).clickable { env.navigator.push(Route.Product(p.id)) }) {
                        Row(Modifier.padding(Space.m), horizontalArrangement = Arrangement.spacedBy(Space.m), verticalAlignment = Alignment.CenterVertically) {
                            RemoteImage(p.image, 52.dp)
                            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                                Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                                    Text(p.name, style = MaterialTheme.typography.bodyMedium, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false))
                                    StatusBadge(p.status)
                                    if (p.featured) StatusBadge("featured", StatusTone.ACCENT)
                                }
                                Text("${p.sku} · ${p.category ?: "No category"} · ${ProductVocab.types.firstOrNull { it.first == p.type }?.second ?: p.type.humanized}${if (p.variants > 0) " · ${p.variants} variants" else ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                    Text("Stock ${p.stock}", style = MaterialTheme.typography.labelSmall, color = if (p.stock <= 0) Palette.danger else if (p.stock <= p.lowThreshold) Palette.warning else MaterialTheme.hub.textSecondary)
                                    if (p.producible) Text("· producible", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
                                }
                            }
                            Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                MoneyText(p.price)
                                p.salePrice?.let { Text("sale ${it.formatted()}", style = MaterialTheme.typography.labelSmall, color = Palette.pink) }
                            }
                            if (s.can("products.edit") || s.can("products.delete")) Box {
                                IconButton(onClick = { menu = true }) { Icon(Icons.Default.MoreVert, contentDescription = "More") }
                                DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                                    DropdownMenuItem(text = { Text("Open") }, onClick = { menu = false; env.navigator.push(Route.Product(p.id)) })
                                    if (s.can("products.edit") && p.status != "archived") DropdownMenuItem(text = { Text("Archive") }, onClick = { menu = false; scope.launch { archive(p) } })
                                    if (s.can("products.delete")) DropdownMenuItem(text = { Text("Delete", color = Palette.danger) }, onClick = { menu = false; scope.launch { delete(p) } })
                                }
                            }
                        }
                    }
                }
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                    TextButton(enabled = model.page > 1, onClick = { model.load(model.page - 1) }) { Text("Previous") }
                    Text("Page ${model.page} of ${model.lastPage} · ${model.total} products", style = MaterialTheme.typography.labelSmall, modifier = Modifier.weight(1f))
                    TextButton(enabled = model.page < model.lastPage, onClick = { model.load(model.page + 1) }) { Text("Next") }
                }
            }
        }
    }

    if (importing) BulkImportSheet(onDismiss = { importing = false }) { model.load() }
}

/**
 * CSV bulk import: download the template (GET /products/export-template),
 * upload a CSV (POST /products/bulk-import, `file`) → `{message, imported, errors}`.
 */
@Composable
private fun BulkImportSheet(onDismiss: () -> Unit, done: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var file by remember { mutableStateOf<PickedFile?>(null) }
    var busy by remember { mutableStateOf(false) }
    var result by remember { mutableStateOf<JsonElement?>(null) }
    val pickCsv = rememberFilePicker(arrayOf("text/csv", "text/comma-separated-values", "application/vnd.ms-excel")) { file = it }

    InfoSheet("Bulk import products", onDismiss = { onDismiss(); if (result != null) done() }) {
        Text("1. Download the CSV template. 2. Fill one product per row (sku, name_en, description_en, short_description_en, category_id, product_type, status, price_kes, price_usd, cost_kes, brand, weight, is_featured, is_producible, low_stock_threshold). 3. Upload it.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
            DocumentButton("Download template", "/v1/admin/products/export-template", suggestedName = "products-import-template.csv")
            OutlinedButton(onClick = { pickCsv() }) { Text(file?.name ?: "Choose CSV…") }
        }
        result?.let { r ->
            val imported = r["imported"].int ?: 0
            Text("$imported product(s) imported", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = if (imported > 0) Palette.success else Palette.warning)
            r["errors"].records.forEach { e -> Text(e.str ?: "", style = MaterialTheme.typography.labelSmall, color = Palette.danger) }
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) {
            TextButton(onClick = { onDismiss(); if (result != null) done() }) { Text("Close") }
            Spacer(Modifier.size(Space.s))
            Button(enabled = file != null && !busy, onClick = {
                val f = file ?: return@Button
                busy = true
                scope.launch {
                    try {
                        val r = env.client.upload("/v1/admin/products/bulk-import", listOf(f.part("file")))
                        result = r.json
                        env.toasts.show(r.json["message"].str ?: "Import finished")
                    } catch (e: Exception) { env.toasts.error(e) } finally { busy = false }
                }
            }) { if (busy) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp) else Text("Import") }
        }
    }
}
