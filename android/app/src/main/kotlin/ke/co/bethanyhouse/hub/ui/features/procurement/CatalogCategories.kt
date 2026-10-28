package ke.co.bethanyhouse.hub.ui.features.procurement

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowDownward
import androidx.compose.material.icons.filled.ArrowUpward
import androidx.compose.material.icons.filled.ExpandMore
import androidx.compose.material.icons.filled.ChevronRight
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material3.Button
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toJson
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.JRow
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.RemoteImage
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.jrows
import ke.co.bethanyhouse.hub.ui.components.rememberFilePicker
import ke.co.bethanyhouse.hub.ui.components.uploadFile
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonPrimitive

/**
 * Categories (CategoriesPage.tsx): the tree from GET /categories?tree=true,
 * create/edit (products.edit), delete (products.delete), subcategory,
 * move up/down among siblings (PUT /categories/reorder), image upload and
 * removal, active toggle.
 */
private class CategoryEdit(val existing: JsonElement?, val parentId: Int?)

@Composable
fun CategoriesScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val canEdit = env.session.current.can("products.edit")
    val canDelete = env.session.current.can("products.delete")
    val tree = remember { Resource(scope) { env.client.get("/v1/admin/categories", mapOf("tree" to "true")).json ?: JsonNull } }
    var search by remember { mutableStateOf("") }
    var editing by remember { mutableStateOf<CategoryEdit?>(null) }
    val expanded = remember { mutableStateListOf<Int>() }
    var imageTarget by remember { mutableStateOf<Int?>(null) }
    LaunchedEffect(tree) { tree.load() }

    val pickImage = rememberFilePicker(arrayOf("image/*")) { f ->
        val id = imageTarget ?: return@rememberFilePicker
        scope.launch { try { env.client.uploadFile("/v1/admin/categories/$id/image", f, field = "image"); env.toasts.show("Image set"); tree.load() } catch (e: Exception) { env.toasts.error(e) } }
    }

    fun flat(): List<Pair<Int, String>> {
        val out = mutableListOf<Pair<Int, String>>()
        fun walk(rows: List<JRow>, depth: Int) { rows.forEach { c -> c.int("id")?.let { out += it to ("  ".repeat(depth) + (c.str("name_en") ?: "")) }; walk(jrows(c["children"]), depth + 1) } }
        walk(jrows(tree.value["data"]), 0)
        return out
    }

    fun matches(c: JRow): Boolean {
        val q = search.trim().lowercase()
        if (q.isEmpty()) return true
        if ((c.str("name_en") ?: "").lowercase().contains(q) || (c.str("slug") ?: "").lowercase().contains(q)) return true
        return jrows(c["children"]).any { matches(it) }
    }

    suspend fun move(siblings: List<JRow>, from: Int, to: Int) {
        if (to !in siblings.indices) return
        val order = siblings.mapNotNull { it.int("id") }.toMutableList()
        val tmp = order[from]; order[from] = order[to]; order[to] = tmp
        val items = order.mapIndexed { i, id -> J.obj("id" to JsonPrimitive(id), "sort_order" to JsonPrimitive(i + 1)) }
        try { env.client.put("/v1/admin/categories/reorder", J.obj("categories" to toJson(items))); tree.load() } catch (e: Exception) { env.toasts.error(e) }
    }
    suspend fun toggle(id: Int) { try { val r = env.client.put("/v1/admin/categories/$id/toggle"); env.toasts.show(r.json["message"].str ?: "Updated"); tree.load() } catch (e: Exception) { env.toasts.error(e) } }
    suspend fun delete(id: Int) { try { val r = env.client.delete("/v1/admin/categories/$id"); env.toasts.show(r.json["message"].str ?: "Deleted"); tree.load() } catch (e: Exception) { env.toasts.error(e) } }
    suspend fun deleteImage(id: Int) { try { env.client.delete("/v1/admin/categories/$id/image"); env.toasts.show("Image removed"); tree.load() } catch (e: Exception) { env.toasts.error(e) } }

    @Composable
    fun node(c: JRow, siblings: List<JRow>, depth: Int) {
        val id = c.int("id") ?: return
        if (!matches(c)) return
        val children = jrows(c["children"])
        val index = siblings.indexOfFirst { it.int("id") == id }.coerceAtLeast(0)
        val open = id in expanded || search.isNotEmpty()
        var menu by remember(id) { mutableStateOf(false) }
        Column(Modifier.fillMaxWidth().padding(start = (depth * 16).dp)) {
            Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                if (children.isEmpty()) Box(Modifier.width(24.dp)) else IconButton(onClick = { if (id in expanded) expanded.remove(id) else expanded.add(id) }, modifier = Modifier.size(24.dp)) { Icon(if (open) Icons.Default.ExpandMore else Icons.Default.ChevronRight, contentDescription = if (open) "Collapse" else "Expand") }
                RemoteImage(c.str("image_url"), 36.dp)
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                        Text(c.str("name_en") ?: "", style = MaterialTheme.typography.bodyMedium)
                        if (c["is_active"].bool == false) StatusBadge("inactive", StatusTone.NEUTRAL)
                        if (c["featured"].bool == true) StatusBadge("featured", StatusTone.ACCENT)
                    }
                    Text("${c.str("slug") ?: ""} · ${c.int("products_count") ?: 0} products${if (c["show_in_menu"].bool == false) " · hidden from menu" else ""}${if (c["show_in_storefront"].bool == false) " · hidden from storefront" else ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                }
                if (canEdit) {
                    IconButton(enabled = index > 0, onClick = { scope.launch { move(siblings, index, index - 1) } }, modifier = Modifier.size(28.dp)) { Icon(Icons.Default.ArrowUpward, contentDescription = "Move up") }
                    IconButton(enabled = index < siblings.lastIndex, onClick = { scope.launch { move(siblings, index, index + 1) } }, modifier = Modifier.size(28.dp)) { Icon(Icons.Default.ArrowDownward, contentDescription = "Move down") }
                    Box {
                        IconButton(onClick = { menu = true }, modifier = Modifier.size(28.dp)) { Icon(Icons.Default.MoreVert, contentDescription = "More") }
                        DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                            DropdownMenuItem(text = { Text("Edit") }, onClick = { menu = false; editing = CategoryEdit(c.json, null) })
                            DropdownMenuItem(text = { Text("Add subcategory") }, onClick = { menu = false; editing = CategoryEdit(null, id) })
                            DropdownMenuItem(text = { Text("Set image…") }, onClick = { menu = false; imageTarget = id; pickImage() })
                            DropdownMenuItem(text = { Text(if (c["is_active"].bool == false) "Activate" else "Deactivate") }, onClick = { menu = false; scope.launch { toggle(id) } })
                            if (c.str("image_url") != null) DropdownMenuItem(text = { Text("Remove image") }, onClick = { menu = false; scope.launch { deleteImage(id) } })
                            if (canDelete) DropdownMenuItem(text = { Text("Delete", color = Palette.danger) }, onClick = { menu = false; scope.launch { delete(id) } })
                        }
                    }
                }
            }
            HorizontalDivider(color = MaterialTheme.hub.line)
            if (open) children.forEach { child -> node(child, children, depth + 1) }
        }
    }

    val stats = tree.value["stats"]
    Page("Categories", subtitle = if (tree.value == null) null else "${stats["total"].int ?: 0} categories · ${stats["active"].int ?: 0} active · ${stats["root"].int ?: 0} top level", refresh = { tree.reload() }) {
        ModuleSearchField(search, { search = it }, "Search", onSubmit = {})
        if (canEdit) Button(onClick = { editing = CategoryEdit(null, null) }) { Text("New category") }
        LoadableView(tree.state, retry = { tree.load() }, isEmpty = { it["data"].records.isEmpty() }, empty = { EmptyState("No categories yet", actionLabel = if (canEdit) "New category" else null) { editing = CategoryEdit(null, null) } }) { json ->
            SectionCard {
                val roots = jrows(json["data"])
                roots.forEach { c -> node(c, roots, 0) }
            }
        }
    }

    editing?.let { e -> CategoryEditSheet(e.existing, e.parentId, flat(), onDismiss = { editing = null }) { tree.load() } }
}

@Composable
private fun CategoryEditSheet(existing: JsonElement?, parentId: Int?, parents: List<Pair<Int, String>>, onDismiss: () -> Unit, done: () -> Unit) {
    val env = LocalEnv.current
    var nameEn by remember { mutableStateOf(existing["name_en"].str ?: "") }
    var nameSw by remember { mutableStateOf(existing["name_sw"].str ?: "") }
    var nameFr by remember { mutableStateOf(existing["name_fr"].str ?: "") }
    var namePt by remember { mutableStateOf(existing["name_pt"].str ?: "") }
    var descEn by remember { mutableStateOf(existing["description_en"].str ?: "") }
    var slug by remember { mutableStateOf(existing["slug"].str ?: "") }
    var parent by remember { mutableStateOf(if (existing != null) existing["parent_id"].int else parentId) }
    var icon by remember { mutableStateOf(existing["icon"].str ?: "") }
    var color by remember { mutableStateOf(existing["color"].str ?: "") }
    var active by remember { mutableStateOf(existing["is_active"].bool ?: true) }
    var inMenu by remember { mutableStateOf(existing["show_in_menu"].bool ?: true) }
    var inStorefront by remember { mutableStateOf(existing["show_in_storefront"].bool ?: true) }
    var featured by remember { mutableStateOf(existing["featured"].bool ?: false) }
    var sortOrder by remember { mutableStateOf(existing["sort_order"].str ?: "") }
    var metaTitle by remember { mutableStateOf(existing["meta_title"].str ?: "") }
    var metaDescription by remember { mutableStateOf(existing["meta_description"].str ?: "") }
    var metaKeywords by remember { mutableStateOf(existing["meta_keywords"].str ?: "") }
    val existingId = existing["id"].int

    FormSheet(if (existing == null) (if (parentId == null) "New category" else "New subcategory") else "Edit category", onDismiss, saveLabel = if (existing == null) "Create" else "Save", canSave = nameEn.isNotBlank(), save = {
        val body = J.obj(
            "name_en" to JsonPrimitive(nameEn.trim()), "is_active" to JsonPrimitive(active), "show_in_menu" to JsonPrimitive(inMenu), "show_in_storefront" to JsonPrimitive(inStorefront), "featured" to JsonPrimitive(featured),
            "name_sw" to J.sOpt(nameSw), "name_fr" to J.sOpt(nameFr), "name_pt" to J.sOpt(namePt), "description_en" to J.sOpt(descEn),
            "slug" to J.sOpt(slug), "parent_id" to (parent?.let { JsonPrimitive(it) } ?: JsonNull),
            "icon" to J.sOpt(icon), "color" to J.sOpt(color), "sort_order" to sortOrder.toIntOrNull()?.let { JsonPrimitive(it) },
            "meta_title" to J.sOpt(metaTitle), "meta_description" to J.sOpt(metaDescription), "meta_keywords" to J.sOpt(metaKeywords),
        )
        if (existingId != null) env.client.put("/v1/admin/categories/$existingId", body) else env.client.post("/v1/admin/categories", body)
        env.toasts.show("Category saved")
        done()
    }) { error ->
        Field("Name (English) *", nameEn, { nameEn = it }, error = error?.field("name_en"))
        Field("Swahili", nameSw, { nameSw = it })
        Field("French", nameFr, { nameFr = it })
        Field("Portuguese", namePt, { namePt = it })
        Field("Description (English)", descEn, { descEn = it }, multiline = true)
        Field("Slug", slug, { slug = it }, placeholder = "Auto from the name", error = error?.field("slug"))
        SelectField("Parent", parent, parents.filter { it.first != existingId }, { parent = it }, Modifier.fillMaxWidth(), placeholder = "None (top level)")
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Field("Icon", icon, { icon = it }, Modifier.weight(1f))
            Field("Colour", color, { color = it }, Modifier.weight(1f), placeholder = "#hex")
            Field("Sort order", sortOrder, { sortOrder = it.filter { c -> c.isDigit() } }, Modifier.weight(1f), keyboard = androidx.compose.ui.text.input.KeyboardType.Number)
        }
        ToggleRow("Active", active, { active = it })
        ToggleRow("In menu", inMenu, { inMenu = it })
        ToggleRow("On storefront", inStorefront, { inStorefront = it })
        ToggleRow("Featured", featured, { featured = it })
        Field("Meta title", metaTitle, { metaTitle = it })
        Field("Meta description", metaDescription, { metaDescription = it }, multiline = true)
        Field("Meta keywords", metaKeywords, { metaKeywords = it })
    }
}
