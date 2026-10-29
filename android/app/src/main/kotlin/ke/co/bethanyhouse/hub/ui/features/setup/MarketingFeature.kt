package ke.co.bethanyhouse.hub.ui.features.setup

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ContentCopy
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
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.obj
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.AttachmentPicker
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.PickedFile
import ke.co.bethanyhouse.hub.ui.components.Pill
import ke.co.bethanyhouse.hub.ui.components.RemoteImage
import ke.co.bethanyhouse.hub.ui.components.RemoteJson
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.StatTile
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.SupportListRow
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.TileModifier
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.toastResult
import ke.co.bethanyhouse.hub.ui.hub
import ke.co.bethanyhouse.hub.ui.shell.FeatureRegistry
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive

/**
 * Marketing (seasons, campaigns), Home Front (homepage blocks, product
 * page sections — all `marketing/banners`) and Storefront Insights
 * (`analytics/overview?days=`). Reads need marketing.view (insights:
 * reports.customers); writes need marketing.manage.
 */
object MarketingFeature {
    fun register() {
        FeatureRegistry.register(Route.Seasons) { SeasonsScreen() }
        FeatureRegistry.register(Route.Campaigns) { CampaignsScreen() }
        FeatureRegistry.register(Route.HomeFront) { HomeFrontScreen() }
        FeatureRegistry.register(Route.HomeFrontProducts) { HomeFrontProductsScreen() }
        FeatureRegistry.register(Route.Insights) { InsightsScreen() }
    }
}

@Composable
private fun MarketingStats(s: JsonElement?) {
    Row(horizontalArrangement = Arrangement.spacedBy(Space.xs)) { Pill("${s["total"].int ?: 0} total"); Pill("${s["active"].int ?: 0} active", StatusTone.SUCCESS); Pill("${s["running"].int ?: 0} running now", StatusTone.INFO) }
}

// ── Seasons (GET /marketing/seasons → {data, stats}; POST/PUT/DELETE) ───────

@Composable
fun SeasonsScreen() {
    val env = LocalEnv.current
    var version by remember { mutableIntStateOf(0) }
    var editing by remember { mutableStateOf<Record?>(null) }
    var adding by remember { mutableStateOf(false) }
    val canManage = env.session.current.can("marketing.manage")
    Page("Seasons", subtitle = "Liturgical seasons that dress the storefront", refresh = { version++ }, actions = { if (canManage) Button(onClick = { adding = true }) { Text("New season") } }) {
        RemoteJson("/v1/admin/marketing/seasons", version = version, emptyTitle = "No seasons yet", isEmpty = { it["data"].records.isEmpty() }) { j, reload ->
            MarketingStats(j["stats"])
            j["data"].toRecords().forEach { r ->
                SupportListRow("${r.string("name") ?: ""} · ${r.string("key") ?: ""}", subtitle = "${r.string("tagline") ?: ""} · ${Day.format(r.string("starts_at"), "d MMM yyyy")} – ${Day.format(r.string("ends_at"), "d MMM yyyy")} · priority ${r["priority"].str ?: "0"}${r["promotion"]["name"].str?.let { " · campaign $it" } ?: ""}") {
                    Column(horizontalAlignment = Alignment.End) {
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.xs)) {
                            setupHexColor(r["theme"]["accent"].str)?.let { Box(Modifier.size(14.dp).background(it, CircleShape)) }
                            StatusBadge(if (r.bool("is_active") == true) "active" else "inactive")
                        }
                        if (canManage) Row(verticalAlignment = Alignment.CenterVertically) {
                            TextButton(onClick = { editing = r }) { Text("Edit") }
                            ConfirmButton("Delete season", "The storefront stops showing it.", destructive = true, action = { try { val x = env.client.delete("/v1/admin/marketing/seasons/${r.id}"); toastResult(env, x); reload() } catch (e: Exception) { env.toasts.error(e) } }) { Text("Delete") }
                        }
                    }
                }
            }
        }
    }
    if (adding) SeasonSheet(null, onDismiss = { adding = false }) { version++ }
    editing?.let { r -> SeasonSheet(r, onDismiss = { editing = null }) { version++ } }
}

private val seasonText = listOf("key" to "Key (slug)", "name" to "Name", "tagline" to "Tagline", "scripture" to "Scripture", "starts_at" to "Starts (yyyy-mm-dd HH:mm)", "ends_at" to "Ends", "priority" to "Priority", "sort_order" to "Sort order")
private val seasonTheme = listOf("accent" to "Accent (#hex)", "accentInk" to "Accent ink", "accentSoft" to "Accent soft", "onAccent" to "On accent", "liturgical" to "Liturgical colour", "motif" to "Motif")

@Composable
private fun SeasonSheet(record: Record?, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    val f = remember { SetupForm(buildMap { put("priority", "0"); put("sort_order", "0"); if (record != null) seasonText.forEach { (k, _) -> put(k, record[k].str ?: "") } }) }
    val theme = remember { SetupForm((record?.get("theme").obj ?: emptyMap()).mapValues { it.value.str ?: "" }) }
    var active by remember { mutableStateOf(record?.bool("is_active") ?: true) }
    var promotionId by remember { mutableStateOf(record?.get("promotion_id").int) }
    var promotions by remember { mutableStateOf<List<Record>>(emptyList()) }
    LaunchedEffect(Unit) { promotions = runCatching { env.client.get("/v1/admin/marketing/promotions").json["data"].toRecords() }.getOrDefault(emptyList()) }
    FormSheet(if (record == null) "New season" else "Edit season", onDismiss, canSave = f["name"].isNotEmpty() && f["key"].isNotEmpty(), save = {
        val pairs = seasonText.map { (k, _) -> k to (if (k == "priority" || k == "sort_order") J.typed(f[k]) else J.s(f[k])) }.toMutableList<Pair<String, JsonElement?>>()
        pairs += "is_active" to J.b(active); pairs += "promotion_id" to J.i(promotionId)
        val th = theme.keys().mapNotNull { k -> theme[k].takeIf { it.isNotEmpty() }?.let { k to JsonPrimitive(it) } }.toMap()
        pairs += "theme" to (if (th.isEmpty()) JsonNull else JsonObject(th))
        val body = J.obj(*pairs.toTypedArray())
        val r = if (record != null) env.client.put("/v1/admin/marketing/seasons/${record.id}", body) else env.client.post("/v1/admin/marketing/seasons", body)
        toastResult(env, r, "Season saved"); saved()
    }) { e ->
        seasonText.forEach { (k, label) -> Field(label, f[k], { f[k] = it }, error = e?.field(k)) }
        SetupHeading("Theme")
        seasonTheme.forEach { (k, label) -> Field(label, theme[k], { theme[k] = it }, mono = k != "motif") }
        SelectField("Campaign", promotionId, promotions.mapNotNull { p -> p.int("id")?.let { it to (p.string("name") ?: "") } }, { promotionId = it }, placeholder = "None")
        ToggleRow("Active", active, { active = it })
        Spacer(Modifier.height(Space.xs))
    }
}

// ── Campaigns (GET /marketing/promotions; POST/PUT/DELETE) ───────────────────

@Composable
fun CampaignsScreen() {
    val env = LocalEnv.current
    var version by remember { mutableIntStateOf(0) }
    var editing by remember { mutableStateOf<Record?>(null) }
    var adding by remember { mutableStateOf(false) }
    val canManage = env.session.current.can("marketing.manage")
    Page("Campaigns", subtitle = "Blessed Friday and other storefront promotions", refresh = { version++ }, actions = { if (canManage) Button(onClick = { adding = true }) { Text("New campaign") } }) {
        RemoteJson("/v1/admin/marketing/promotions", version = version, emptyTitle = "No campaigns yet", isEmpty = { it["data"].records.isEmpty() }) { j, reload ->
            MarketingStats(j["stats"])
            j["data"].toRecords().forEach { p ->
                val discount = "${p["discount_value"].str ?: ""}${if (p.string("discount_type") == "percentage") "% off" else " KES off"}"
                SupportListRow(p.string("name") ?: "", subtitle = "$discount · ${Day.format(p.string("starts_at"), "d MMM yyyy")} – ${Day.format(p.string("ends_at"), "d MMM yyyy")} · used ${p["times_used"].int ?: 0}${p["max_uses"].int?.let { " of $it" } ?: ""}${if (p.bool("is_exclusive") == true) " · exclusive" else ""}") {
                    Column(horizontalAlignment = Alignment.End) {
                        StatusBadge(if (p.bool("is_active") == true) "active" else "inactive")
                        if (canManage) Row(verticalAlignment = Alignment.CenterVertically) {
                            TextButton(onClick = { editing = p }) { Text("Edit") }
                            ConfirmButton("Delete campaign", "It stops applying to new orders.", destructive = true, action = { try { val x = env.client.delete("/v1/admin/marketing/promotions/${p.id}"); toastResult(env, x); reload() } catch (e: Exception) { env.toasts.error(e) } }) { Text("Delete") }
                        }
                    }
                }
            }
        }
    }
    if (adding) CampaignSheet(null, onDismiss = { adding = false }) { version++ }
    editing?.let { p -> CampaignSheet(p, onDismiss = { editing = null }) { version++ } }
}

@Composable
private fun CampaignSheet(record: Record?, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    var name by remember { mutableStateOf(record?.string("name") ?: "") }
    var description by remember { mutableStateOf(record?.string("description") ?: "") }
    var type by remember { mutableStateOf(record?.string("type") ?: "") }
    var discountType by remember { mutableStateOf(record?.string("discount_type") ?: "percentage") }
    var value by remember { mutableStateOf(record?.get("discount_value").str ?: "") }
    var starts by remember { mutableStateOf(record?.string("starts_at") ?: "") }
    var ends by remember { mutableStateOf(record?.string("ends_at") ?: "") }
    var priority by remember { mutableStateOf(record?.get("priority").str ?: "0") }
    var exclusive by remember { mutableStateOf(record?.bool("is_exclusive") ?: false) }
    var maxUses by remember { mutableStateOf(record?.get("max_uses").str ?: "") }
    var active by remember { mutableStateOf(record?.bool("is_active") ?: true) }
    FormSheet(if (record == null) "New campaign" else "Edit campaign", onDismiss, canSave = name.isNotEmpty() && value.isNotEmpty(), save = {
        val body = J.obj("name" to JsonPrimitive(name), "description" to J.s(description), "type" to J.s(type), "discount_type" to JsonPrimitive(discountType), "discount_value" to J.typed(value), "is_active" to J.b(active), "starts_at" to J.s(starts), "ends_at" to J.s(ends), "priority" to J.typed(priority), "is_exclusive" to J.b(exclusive), "max_uses" to J.typed(maxUses))
        val r = if (record != null) env.client.put("/v1/admin/marketing/promotions/${record.id}", body) else env.client.post("/v1/admin/marketing/promotions", body)
        toastResult(env, r, "Campaign saved"); saved()
    }) { e ->
        Field("Name", name, { name = it }, error = e?.field("name"))
        Field("Description", description, { description = it }, multiline = true)
        Field("Type", type, { type = it }, placeholder = "blessed_friday")
        OptionPicker("Discount", discountType, listOf("percentage" to "Percentage (%)", "fixed" to "Fixed (KES)"), { discountType = it })
        Field("Value", value, { value = it }, error = e?.field("discount_value"))
        Field("Starts (yyyy-mm-dd HH:mm)", starts, { starts = it }, error = e?.field("starts_at"))
        Field("Ends", ends, { ends = it }, error = e?.field("ends_at"))
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Field("Priority", priority, { priority = it }, Modifier.weight(1f))
            Field("Max uses (blank = unlimited)", maxUses, { maxUses = it }, Modifier.weight(1f))
        }
        ToggleRow("Exclusive (not combined with others)", exclusive, { exclusive = it })
        ToggleRow("Active", active, { active = it })
        Spacer(Modifier.height(Space.xs))
    }
}

// ── Home Front (banners by position; POST /banners/{id}/image multipart image+field) ──

private class HomeSlot(val key: String, val placement: String, val label: String, val hint: String)

private val homeSlots = listOf(
    HomeSlot("home_hero", "homepage", "Hero Slider", "Full-width rotating slides at the very top — order = slide 1, 2, 3…"),
    HomeSlot("home_promo", "homepage", "Promo Strips", "The two mid-page offer bands."),
    HomeSlot("shop_hero", "shop", "Shop Page Hero", "The banner across the top of the /shop page."),
    HomeSlot("home_category", "homepage", "Category Tiles", "The “Shop by category” cards."),
    HomeSlot("home_testimonial", "homepage", "Testimonials", "The “Loved at the altar” quotes."),
    HomeSlot("home_pillar", "homepage", "Why Bethany Pillars", "The four value pillars."),
    HomeSlot("home_newsletter", "homepage", "Newsletter", "The “Grace in every detail” sign-up block."),
)

private val productSlots = listOf(
    HomeSlot("product_highlight", "", "Highlights", "Skimmable auto-advancing cards near the top — one claim + image each."),
    HomeSlot("product_poster", "", "Poster", "The editorial banner — headline + spec strip + image. One per product."),
    HomeSlot("product_feature", "", "Take a closer look", "Interactive feature cards, each with its own image."),
    HomeSlot("product_chapter", "", "Story chapters", "Cinematic editorial sections — eyebrow + headline + copy over an image."),
    HomeSlot("product_pillar", "", "Best place to buy", "The value cards below the story (icon + title + text)."),
)

/** What the banner sheet opens with: an existing block (or a copy of one), or an empty slot. */
private class BannerDraft(val record: Record?, val position: String, val placement: String, val sortOrder: Int, val copy: Boolean = false)

@Composable
fun HomeFrontScreen() {
    var version by remember { mutableIntStateOf(0) }
    var editing by remember { mutableStateOf<BannerDraft?>(null) }
    Page("Home page", subtitle = "Blocks on the storefront; empty slots keep the built-in content", refresh = { version++ }) {
        RemoteJson("/v1/admin/marketing/banners", version = version) { j, reload ->
            val all = j["data"].toRecords()
            homeSlots.forEach { slot -> BannerSlotCard(slot, all.filter { it.string("position") == slot.key }, slot.placement, reload) { editing = it } }
        }
    }
    editing?.let { d -> BannerSheet(d, homeSlots, onDismiss = { editing = null }) { version++ } }
}

/** Product pages: one banner set per product, keyed by placement `product:<slug>` (GET /products?per_page=300 for the picker). */
@Composable
fun HomeFrontProductsScreen() {
    val env = LocalEnv.current
    var products by remember { mutableStateOf<List<Record>>(emptyList()) }
    var slug by remember { mutableStateOf<String?>(null) }
    var version by remember { mutableIntStateOf(0) }
    var editing by remember { mutableStateOf<BannerDraft?>(null) }
    LaunchedEffect(Unit) { products = runCatching { env.client.get("/v1/admin/products", mapOf("per_page" to "300")).json["data"].toRecords() }.getOrDefault(emptyList()) }
    Page("Product pages", subtitle = "Editorial sections per product", refresh = { version++ }) {
        SelectField("Product", slug, products.mapNotNull { p -> p.string("slug")?.let { it to (p.string("name") ?: p["translations"].records.firstOrNull()["name"].str ?: p.string("sku") ?: "") } }, { slug = it }, placeholder = "Choose a product…")
        slug?.let { sl ->
            val placement = "product:$sl"
            RemoteJson("/v1/admin/marketing/banners", query = mapOf("placement" to placement), version = version) { j, reload ->
                val all = j["data"].toRecords()
                productSlots.forEach { slot -> BannerSlotCard(slot, all.filter { it.string("position") == slot.key }, placement, reload) { editing = it } }
            }
        }
    }
    editing?.let { d -> BannerSheet(d, productSlots, onDismiss = { editing = null }) { version++ } }
}

@Composable
private fun BannerSlotCard(slot: HomeSlot, banners: List<Record>, placement: String, reload: () -> Unit, edit: (BannerDraft) -> Unit) {
    val env = LocalEnv.current
    val canManage = env.session.current.can("marketing.manage")
    SectionCard(slot.label) {
        SetupCaption(slot.hint)
        banners.sortedBy { it.int("sort_order") ?: 0 }.forEach { b ->
            Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(Space.m)) {
                b.string("image_url")?.takeIf { it.isNotEmpty() }?.let { RemoteImage(it, size = 60.dp) }
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text("${b.int("sort_order") ?: 0}. ${b.string("title") ?: "(no title)"}", style = MaterialTheme.typography.bodyMedium)
                    b.string("subtitle")?.let { SetupCaption(it, MaterialTheme.hub.textSecondary) }
                    b.string("link_url")?.let { SetupCaption("${b.string("link_text") ?: "Link"} → $it") }
                    StatusBadge(if (b.bool("is_active") == true) "active" else "inactive")
                }
            }
            if (canManage) Row(verticalAlignment = Alignment.CenterVertically) {
                TextButton(onClick = { edit(BannerDraft(b, b.string("position") ?: slot.key, placement, b.int("sort_order") ?: 0)) }) { Text("Edit") }
                SetupIconAction(Icons.Default.ContentCopy, "Duplicate", tint = MaterialTheme.hub.textSecondary) { edit(BannerDraft(b, b.string("position") ?: slot.key, placement, banners.size + 1, copy = true)) }
                SetupIconAction(description = "Delete") { val r = env.client.delete("/v1/admin/marketing/banners/${b.id}"); toastResult(env, r); reload() }
            }
        }
        if (canManage) TextButton(onClick = { edit(BannerDraft(null, slot.key, placement, banners.size + 1)) }) { Text("Add") }
    }
}

/**
 * POST/PUT /marketing/banners, then POST /banners/{id}/image {image, field}.
 * Rich style keys the form does not edit are preserved, as the console does.
 */
@Composable
private fun BannerSheet(draft: BannerDraft, slots: List<HomeSlot>, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    val b = draft.record
    val styles = b?.get("styles").obj ?: emptyMap()
    val isProduct = draft.placement.startsWith("product:")
    val isNew = b == null || draft.copy
    var position by remember { mutableStateOf(draft.position) }
    var sortOrder by remember { mutableStateOf(draft.sortOrder.toString()) }
    var title by remember { mutableStateOf(b?.string("title") ?: "") }
    var subtitle by remember { mutableStateOf(b?.string("subtitle") ?: "") }
    var linkUrl by remember { mutableStateOf(b?.string("link_url") ?: "") }
    var linkText by remember { mutableStateOf(b?.string("link_text") ?: "") }
    var eyebrow by remember { mutableStateOf(styles["eyebrow"].str ?: styles["icon"].str ?: "") }
    var theme by remember { mutableStateOf(styles["theme"].str ?: "") }
    var active by remember { mutableStateOf(b?.bool("is_active") ?: true) }
    var openNew by remember { mutableStateOf(b?.bool("open_in_new_tab") ?: false) }
    var image by remember { mutableStateOf<PickedFile?>(null) }
    FormSheet(if (isNew) "Add block" else "Edit block", onDismiss, save = {
        val st = styles.toMutableMap()
        fun put(k: String, v: String) { J.sOpt(v)?.let { st[k] = it } ?: st.remove(k) }
        if (isProduct) { put("eyebrow", eyebrow); put("icon", eyebrow) } else { put("eyebrow", eyebrow); put("theme", theme) }
        val body = J.obj("position" to JsonPrimitive(position), "placement" to JsonPrimitive(draft.placement), "sort_order" to J.typed(sortOrder), "title" to J.s(title), "subtitle" to J.s(subtitle),
            "link_url" to (if (isProduct) null else J.s(linkUrl)), "link_text" to (if (isProduct) null else J.s(linkText)), "is_active" to J.b(active), "open_in_new_tab" to (if (isProduct) null else J.b(openNew)), "styles" to JsonObject(st))
        val r = if (!isNew) env.client.put("/v1/admin/marketing/banners/${b!!.id}", body) else env.client.post("/v1/admin/marketing/banners", body)
        val id = r.json["data"]["id"].int
        image?.let { img -> if (id != null) env.client.upload("/v1/admin/marketing/banners/$id/image", listOf(img.part("image"), ApiClient.Part.field("field", "image_url"))) }
        env.toasts.show("Block saved"); saved()
    }) { e ->
        OptionPicker("Slot", position, slots.map { it.key to it.label }, { position = it })
        Field("Position (order, 1 = first)", sortOrder, { sortOrder = it }, error = e?.field("sort_order"))
        Field(if (position == "product_pillar") "Icon (emoji)" else "Eyebrow", eyebrow, { eyebrow = it })
        Field("Headline / title", title, { title = it }, error = e?.field("title"))
        Field("Text / subtitle", subtitle, { subtitle = it }, multiline = true)
        if (!isProduct) {
            Field("Link URL", linkUrl, { linkUrl = it }); Field("Link text", linkText, { linkText = it }); Field("Theme", theme, { theme = it })
            ToggleRow("Open link in a new tab", openNew, { openNew = it })
        }
        AttachmentPicker(image?.name ?: "Choose image (jpeg/png/webp ≤5MB)", camera = false) { files -> files.firstOrNull()?.let { image = it } }
        ToggleRow("Active", active, { active = it })
        Spacer(Modifier.height(Space.xs))
    }
}

// ── Insights (GET /analytics/overview?days=) ─────────────────────────────────

@Composable
fun InsightsScreen() {
    var days by remember { mutableStateOf("30") }
    Page("Storefront insights", subtitle = "Visitors and buyers by country and device — no IP is ever stored") {
        OptionPicker("Range", days, listOf("7" to "Last 7 days", "30" to "Last 30 days", "90" to "Last 90 days", "365" to "Last year"), { days = it })
        RemoteJson("/v1/admin/analytics/overview", query = mapOf("days" to days)) { j, _ ->
            val t = j["totals"]
            TileGrid {
                StatTile("Visits", t["visits"].str ?: "0", modifier = TileModifier)
                StatTile("Online orders", t["orders"].str ?: "0", modifier = TileModifier)
                StatTile("Countries", t["countries"].str ?: "0", modifier = TileModifier)
                StatTile("Mobile share", "${t["mobile_share"].str ?: "0"}%", modifier = TileModifier)
            }
            val markets = j["markets"].toRecords()
            SectionCard("Visits by country") {
                val top = markets.take(12)
                val max = top.maxOfOrNull { it.int("visits") ?: 0 }?.takeIf { it > 0 } ?: 1
                if (top.isEmpty()) SetupCaption("No visits in this range.")
                top.forEach { m ->
                    val visits = m.int("visits") ?: 0
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                        Text(setupCountryName(m.string("country_code")), style = MaterialTheme.typography.bodySmall, modifier = Modifier.width(96.dp), maxLines = 1)
                        Box(Modifier.weight(1f).height(14.dp)) { Box(Modifier.fillMaxWidth(visits.toFloat() / max).height(14.dp).background(Palette.brand, MaterialTheme.shapes.extraSmall)) }
                        Text(visits.toString(), style = MaterialTheme.typography.labelSmall.copy(fontFamily = FontFamily.Monospace), color = MaterialTheme.hub.textSecondary)
                    }
                }
            }
            SectionCard("Markets") {
                MarketRow("Country", "Visits", "Orders", "Revenue", "Conversion", header = true)
                markets.forEach { m ->
                    MarketRow(setupCountryName(m.string("country_code")), m["visits"].str ?: "0", m["orders"].str ?: "0", m["revenue"].str ?: "0", if (!m["conversion_pct"].isNull) "${m["conversion_pct"].str ?: ""}%" else "n/a")
                }
                SetupCaption("Revenue is the server's figure in its reporting currency; conversion is n/a where no visits were recorded.")
            }
            SectionCard("Devices") { j["devices"].toRecords().forEach { d -> Row { Text(d.string("device_type")?.humanized ?: "—", style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f)); Text(d["visits"].str ?: "0", style = MaterialTheme.typography.bodySmall.copy(fontFamily = FontFamily.Monospace)) } } }
            SectionCard("Operating systems") { j["os"].toRecords().forEach { d -> Row { Text(d.string("os") ?: "—", style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f)); Text(d["visits"].str ?: "0", style = MaterialTheme.typography.bodySmall.copy(fontFamily = FontFamily.Monospace)) } } }
        }
    }
}

@Composable
private fun MarketRow(country: String, visits: String, orders: String, revenue: String, conversion: String, header: Boolean = false) {
    val style = if (header) MaterialTheme.typography.labelSmall else MaterialTheme.typography.bodySmall.copy(fontFamily = FontFamily.Monospace)
    val color = if (header) MaterialTheme.hub.textSecondary else MaterialTheme.hub.text
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
        Text(country, style = if (header) style else MaterialTheme.typography.bodySmall, color = color, modifier = Modifier.weight(1.6f), maxLines = 1)
        Text(visits, style = style, color = color, modifier = Modifier.weight(0.8f))
        Text(orders, style = style, color = color, modifier = Modifier.weight(0.8f))
        Text(revenue, style = style, color = color, modifier = Modifier.weight(1.2f), maxLines = 1)
        Text(conversion, style = style, color = color, modifier = Modifier.weight(0.9f))
    }
}
