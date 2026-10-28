package ke.co.bethanyhouse.hub.ui.features.me

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Campaign
import androidx.compose.material.icons.filled.EventBusy
import androidx.compose.material.icons.filled.ExpandLess
import androidx.compose.material.icons.filled.ExpandMore
import androidx.compose.material.icons.filled.Star
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import coil.compose.SubcomposeAsyncImage
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.date
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.obj
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.DecimalField
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.InfoSheet
import ke.co.bethanyhouse.hub.ui.components.Loadable
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.listRecords
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull

/**
 * /updates — the feed (UpdatesPage.tsx): notices with a photo and a
 * must-read acknowledgement, shout-outs, birthday and anniversary posts,
 * events with an RSVP. Anyone may post a recognition; `updates.post`
 * (the feed's `can_post`) posts the rest and sees scheduled posts.
 */
@Composable
internal fun UpdatesScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var kind by remember { mutableStateOf("") }
    val rows = remember { mutableStateListOf<Record>() }
    var page by remember { mutableIntStateOf(1) }
    var hasMore by remember { mutableStateOf(false) }
    var meta by remember { mutableStateOf<JsonElement>(JsonNull) }
    var state by remember { mutableStateOf<Loadable<List<Record>>>(Loadable.Idle) }
    var compose by remember { mutableStateOf<String?>(null) }
    var showScheduled by remember { mutableStateOf(false) }
    val canPost = meta["can_post"].bool ?: env.session.current.can("updates.post")

    suspend fun load(reset: Boolean) {
        val next = if (reset) 1 else page + 1
        if (reset) state = Loadable.Loading(rows.toList().takeIf { it.isNotEmpty() })
        try {
            val json = env.client.get("/v1/admin/updates", mapOf("page" to next.toString(), "kind" to kind.ifEmpty { null })).json ?: JsonNull
            val fresh = json.listRecords
            if (reset) { rows.clear(); rows.addAll(fresh) } else { rows.removeAll { r -> fresh.any { it.id == r.id } }; rows.addAll(fresh) }
            page = next
            hasMore = json["has_more"].bool ?: false
            meta = json
            state = Loadable.Loaded(rows.toList())
        } catch (e: ApiError) {
            state = Loadable.Failed(e, rows.toList().takeIf { it.isNotEmpty() })
        } catch (e: Exception) {
            state = Loadable.Failed(ApiError.transport(e.message ?: "Error"), rows.toList().takeIf { it.isNotEmpty() })
        }
    }
    LaunchedEffect(kind) { load(true) }

    val pending = meta["must_read_pending"].int ?: 0
    val subtitle = if (pending > 0) "${MeFormat.plural(pending, "must-read")} waiting for you." else "What is happening at Bethany House."

    Page("Updates", subtitle = subtitle, refresh = { load(true) }) {
        FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.xs), modifier = Modifier.fillMaxWidth()) {
            OutlinedButton(onClick = { compose = "recognition" }) { Icon(Icons.Default.Star, contentDescription = null, modifier = Modifier.size(16.dp)); Text(" Recognise someone") }
            if (canPost) Button(onClick = { compose = "update" }) { Text("New post") }
            val n = meta["scheduled"].int ?: 0
            if (canPost && n > 0) TextButton(onClick = { showScheduled = true }) { Text("$n scheduled ›") }
        }
        SegmentedTabs(listOf("" to "All", "update" to "Updates", "recognition" to "Recognitions", "event" to "Events", "celebration" to "Celebrations"), kind) { kind = it }
        LoadableView(state, retry = { scope.launch { load(true) } }, isEmpty = { it.isEmpty() }, empty = { EmptyState("Nothing here yet", if (canPost) "Post the first update." else null, Icons.Default.Campaign) }) { posts ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.m)) {
                posts.forEach { p -> PostCard(p) { env.navigator.push(Route.Updates(p.intId)) } }
                if (hasMore) OutlinedButton(onClick = { scope.launch { load(false) } }, modifier = Modifier.fillMaxWidth()) { Text("Older posts") }
            }
        }
    }
    compose?.let { k -> ComposePostSheet(initialKind = k, canPost = canPost, onDismiss = { compose = null }, onPosted = { compose = null; scope.launch { load(true) } }) }
    if (showScheduled) ScheduledPostsSheet(onDismiss = { showScheduled = false }, onChanged = { scope.launch { load(true) } })
}

internal object PostKind {
    val label = mapOf("update" to "Update", "recognition" to "Recognition", "celebration" to "Celebration", "event" to "Event")
    val icon = mapOf("update" to "📣", "recognition" to "⭐", "celebration" to "🎉", "event" to "📅")
    fun headline(p: Record): String =
        if (p.string("kind") == "recognition") "${p["author"]["name"].str ?: "Someone"} recognised ${p["recipient"]["name"].str ?: ""}"
        else p.string("title") ?: label[p.string("kind") ?: ""] ?: "Post"
}

@Composable
private fun PostCard(post: Record, open: () -> Unit) {
    val kind = post.string("kind") ?: "update"
    val unread = post["me"]["read_at"].isNull
    val needsAck = post.flag("must_read") && post["me"]["acknowledged_at"].isNull && !post.flag("is_author")
    val person = if (kind == "celebration") post["recipient"] else post["author"]
    Surface(
        shape = MaterialTheme.shapes.large, color = MaterialTheme.hub.card,
        modifier = Modifier.fillMaxWidth().border(if (needsAck) 2.dp else 1.dp, if (needsAck) Palette.danger.copy(alpha = 0.4f) else MaterialTheme.hub.line, MaterialTheme.shapes.large).clickable(onClick = open),
    ) {
        Column(Modifier.padding(Space.l), verticalArrangement = Arrangement.spacedBy(Space.s)) {
            Row(horizontalArrangement = Arrangement.spacedBy(Space.m), verticalAlignment = Alignment.CenterVertically) {
                PersonAvatar(person["name"].str, person["avatar_url"].str)
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Row(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text(person["name"].str ?: "Bethany House", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        if (kind == "recognition") post["recipient"]["name"].str?.let { r ->
                            Text("recognised", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                            Text(r, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        }
                    }
                    Text("${PostKind.icon[kind] ?: ""} ${PostKind.label[kind] ?: kind} · ${MeFormat.dayTime(post.date("published_at"))}${post["outlet"]["name"].str?.let { " · $it" } ?: ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
                }
                if (needsAck) Text("MUST READ", style = MaterialTheme.typography.labelSmall, color = Color.White, modifier = Modifier.background(Palette.danger, CircleShape).padding(horizontal = 8.dp, vertical = 3.dp))
                else if (unread) Box(Modifier.size(10.dp).background(Palette.brand, CircleShape))
            }
            post.string("title")?.let { Text(it, style = MaterialTheme.typography.titleMedium) }
            post.string("body")?.let { Text(it, style = MaterialTheme.typography.bodySmall, maxLines = 4, overflow = TextOverflow.Ellipsis) }
            val pts = post.int("points") ?: 0
            if (pts > 0) Text("+${MeFormat.plural(pts, "point")}", style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold, color = Palette.amber)
            if (kind == "event") {
                val going = post.int("rsvp_yes_count") ?: 0
                Text("📅 ${MeFormat.dayTime(post.date("event_at"))}${post.string("event_place")?.let { " · $it" } ?: ""}${post["me"]["rsvp"].str?.let { " · you said $it" } ?: ""}${if (going > 0) " · $going going" else ""}", style = MaterialTheme.typography.bodySmall)
            }
            Text("${if (post.flag("has_photo")) "📷 photo · " else ""}${MeFormat.plural(post.int("comments_count") ?: 0, "comment")}${if (post.flag("can_manage")) " · ${post.int("reads_count") ?: 0} read" else ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
        }
    }
}

// ── One post: photo, RSVP, acknowledge, readers, comments ────────────────────

@Composable
internal fun PostDetailScreen(id: Int) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val c = env.client
    val post = remember { Resource(scope) { Record(c.get("/v1/admin/updates/$id").json["post"] ?: JsonNull) } }
    var photoUrl by remember { mutableStateOf<String?>(null) }
    var photoFailed by remember { mutableStateOf(false) }
    var comment by remember { mutableStateOf("") }
    var showReaders by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var markedRead by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) { post.reload() }
    LaunchedEffect(post.value?.id) {
        val p = post.value ?: return@LaunchedEffect
        if (!markedRead && p["me"]["read_at"].isNull) {
            markedRead = true
            runCatching { c.post("/v1/admin/updates/$id/read") }
        }
        if (p.flag("has_photo") && photoUrl == null) {
            try { photoUrl = c.get("/v1/admin/updates/$id/photo").json["url"].str; if (photoUrl == null) photoFailed = true } catch (e: ApiError) { photoFailed = true }
        }
    }

    suspend fun call(work: suspend () -> String?) {
        busy = true
        try { work()?.let { env.toasts.show(it) }; post.reload() } catch (e: Exception) { env.toasts.error(e) } finally { busy = false }
    }
    suspend fun acknowledge() = call { c.post("/v1/admin/updates/$id/acknowledge").json["message"].str ?: "Acknowledged" }
    suspend fun rsvp(answer: String) = call { c.post("/v1/admin/updates/$id/rsvp", jsonOf("answer" to answer)).json["message"].str ?: "Saved" }
    suspend fun send() {
        val body = comment.trim()
        if (body.isEmpty()) return
        call { c.post("/v1/admin/updates/$id/comments", jsonOf("body" to body)); comment = ""; null }
    }
    suspend fun removeComment(cm: Record) = call { c.delete("/v1/admin/updates/$id/comments/${cm.id}"); null }
    suspend fun remove() {
        try {
            val r = c.delete("/v1/admin/updates/$id")
            env.toasts.show(r.json["message"].str ?: "Removed")
            env.navigator.go(Route.Updates())
        } catch (e: Exception) { env.toasts.error(e) }
    }

    Page(post.value?.let(PostKind::headline) ?: "Post", refresh = { post.reload() }) {
        LoadableView(post.state, retry = { post.load() }) { p ->
            val kind = p.string("kind") ?: "update"
            val person = if (kind == "celebration") p["recipient"] else p["author"]
            SectionCard {
                Row(horizontalArrangement = Arrangement.spacedBy(Space.m), verticalAlignment = Alignment.CenterVertically) {
                    PersonAvatar(person["name"].str, person["avatar_url"].str)
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Text(person["name"].str ?: "Bethany House", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                        val whenText = if (p.date("published_at") != null) MeFormat.dayTime(p.date("published_at")) else "scheduled ${MeFormat.dayTime(p.date("publish_at"))}"
                        Text("${PostKind.icon[kind] ?: ""} ${PostKind.label[kind] ?: kind} · $whenText${p["outlet"]["name"].str?.let { " · $it" } ?: " · everyone"}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
                    }
                    if (p.flag("can_manage")) ConfirmButton("Remove this post", "It disappears from everyone's feed.", destructive = true, action = { remove() }) { Text("Remove", style = MaterialTheme.typography.labelSmall) }
                }
                if (p.flag("has_photo")) {
                    val url = photoUrl
                    if (url != null) SubcomposeAsyncImage(model = url, contentDescription = null, contentScale = ContentScale.Fit, modifier = Modifier.fillMaxWidth().heightIn(max = 380.dp).clip(MaterialTheme.shapes.small), loading = { Box(Modifier.fillMaxWidth().heightIn(min = 120.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() } })
                    else if (photoFailed) Text("The photo could not be loaded.", style = MaterialTheme.typography.labelSmall, color = Palette.danger)
                    else Box(Modifier.fillMaxWidth().heightIn(min = 120.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
                }
                p.string("body")?.let { Text(it, style = MaterialTheme.typography.bodyMedium) }
                val pts = p.int("points") ?: 0
                if (pts > 0) Text("+${MeFormat.plural(pts, "point")}", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = Palette.amber)
                if (kind == "event") EventBox(p, busy) { a -> scope.launch { rsvp(a) } }
                if (p.flag("must_read") && !p.flag("is_author")) {
                    val at = p["me"]["acknowledged_at"].date
                    if (at != null) MeNotice("You acknowledged this on ${MeFormat.dayTime(at)}.", StatusTone.SUCCESS)
                    else Button(onClick = { scope.launch { acknowledge() } }, enabled = !busy, modifier = Modifier.fillMaxWidth()) { Text("I have read this") }
                }
                p["readers"].obj?.let { readers -> ReadersBox(p, readers, showReaders) { showReaders = !showReaders } }
            }
            val comments = p.records("comments")
            SectionCard(MeFormat.plural(comments.size, "comment")) {
                comments.forEach { cm ->
                    Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.Top) {
                        PersonAvatar(cm["user"]["name"].str, cm["user"]["avatar_url"].str, size = 28.dp)
                        Column(Modifier.weight(1f).background(MaterialTheme.hub.field, MaterialTheme.shapes.small).padding(Space.s), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Row(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalAlignment = Alignment.CenterVertically) {
                                Text(cm["user"]["name"].str ?: "", style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold)
                                Text("· ${MeFormat.dayTime(cm.date("at"))}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
                                if (cm.flag("mine") || p.flag("can_manage")) Text("remove", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary, modifier = Modifier.clickable(enabled = !busy) { scope.launch { removeComment(cm) } })
                            }
                            Text(cm.string("body") ?: "", style = MaterialTheme.typography.bodySmall)
                        }
                    }
                }
                if (p.flag("comments_enabled")) {
                    Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                        OutlinedTextField(value = comment, onValueChange = { comment = it }, placeholder = { Text("Comment as ${env.session.current.me?.raw["first_name"].str ?: "you"}…") }, modifier = Modifier.weight(1f), maxLines = 4)
                        Button(onClick = { scope.launch { send() } }, enabled = !busy && comment.isNotBlank()) { Text("Post") }
                    }
                } else Text("Comments are off on this post.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
            }
        }
    }
}

@Composable
private fun EventBox(p: Record, busy: Boolean, rsvp: (String) -> Unit) {
    Column(Modifier.fillMaxWidth().background(MaterialTheme.hub.field, MaterialTheme.shapes.small).padding(Space.m), verticalArrangement = Arrangement.spacedBy(Space.s)) {
        Text("📅 ${MeFormat.dayTime(p.date("event_at"))}${p.string("event_place")?.let { " · $it" } ?: ""}", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Medium)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.xs)) {
            listOf("yes" to "I'll be there", "maybe" to "Maybe", "no" to "Can't make it").forEach { (a, label) ->
                val mine = p["me"]["rsvp"].str == a
                Surface(shape = CircleShape, color = if (mine) Palette.brandDeep else MaterialTheme.hub.card, modifier = Modifier.border(1.dp, if (mine) Color.Transparent else MaterialTheme.hub.line, CircleShape).clickable(enabled = !busy) { rsvp(a) }) {
                    Text(label, style = MaterialTheme.typography.labelSmall, color = if (mine) Color.White else MaterialTheme.hub.text, modifier = Modifier.padding(horizontal = 12.dp, vertical = 6.dp))
                }
            }
        }
        val n = p.int("rsvp_yes_count") ?: 0
        if (n > 0) Text("$n going", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
    }
}

@Composable
private fun ReadersBox(p: Record, readers: JsonElement, open: Boolean, toggle: () -> Unit) {
    val mustRead = p.flag("must_read")
    val read = readers["read"].records; val acked = readers["acknowledged"].records; val pending = readers["pending"].records
    val audience = readers["audience"].int ?: 0
    val rsvp = readers["rsvp"]
    val done = if (mustRead) acked else read
    val notYet = if (mustRead) read.filter { it["acknowledged_at"].isNull } + pending else pending
    Column(Modifier.fillMaxWidth().border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.small).clickable(onClick = toggle).padding(Space.m), verticalArrangement = Arrangement.spacedBy(Space.s)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("${done.size} of $audience ${if (mustRead) "acknowledged" else "read"}${if (p.string("kind") == "event") " · ${rsvp["yes"].int ?: 0} going, ${rsvp["maybe"].int ?: 0} maybe, ${rsvp["no"].int ?: 0} not" else ""}", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.Medium, modifier = Modifier.weight(1f))
            Icon(if (open) Icons.Default.ExpandLess else Icons.Default.ExpandMore, contentDescription = null, modifier = Modifier.size(16.dp))
        }
        if (open) Row(horizontalArrangement = Arrangement.spacedBy(Space.l), verticalAlignment = Alignment.Top) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(if (mustRead) "Acknowledged" else "Read", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                done.forEach { r -> Text("${r["name"].str ?: ""}${r["rsvp"].str?.let { " · $it" } ?: ""}", style = MaterialTheme.typography.labelSmall) }
                if (done.isEmpty()) Text("Nobody yet", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(if (mustRead) "Not yet" else "Not opened", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                notYet.forEach { r -> Text("${r["name"].str ?: ""}${if (mustRead && !r["read_at"].isNull) " · opened" else ""}", style = MaterialTheme.typography.labelSmall) }
            }
        }
    }
}

// ── Compose (multipart, as updatesApi.create builds it: booleans as "1"/"0", photo file) ──

@Composable
private fun ComposePostSheet(initialKind: String, canPost: Boolean, onDismiss: () -> Unit, onPosted: () -> Unit) {
    val env = LocalEnv.current
    var kind by remember { mutableStateOf(initialKind) }
    var title by remember { mutableStateOf("") }
    var body by remember { mutableStateOf("") }
    var mustRead by remember { mutableStateOf(false) }
    var commentsEnabled by remember { mutableStateOf(true) }
    var outletId by remember { mutableStateOf<Int?>(null) }
    var schedule by remember { mutableStateOf(false) }
    var publishAt by remember { mutableStateOf(MeFormat.nowLocal().plusHours(1)) }
    var eventAt by remember { mutableStateOf(MeFormat.nowLocal().plusDays(1)) }
    var eventPlace by remember { mutableStateOf("") }
    var recipientId by remember { mutableStateOf<Int?>(null) }
    var points by remember { mutableStateOf("") }
    var photo by remember { mutableStateOf<MePickedPhoto?>(null) }
    var people by remember { mutableStateOf<List<Record>>(emptyList()) }
    val outlets = remember { MeOutletChoices(env) }
    LaunchedEffect(Unit) {
        outlets.load()
        try { people = env.client.get("/v1/admin/updates/people").json.listRecords } catch (e: ApiError) { env.toasts.error(e) }
    }
    val ok = if (kind == "recognition") recipientId != null && body.isNotBlank() else title.isNotBlank()

    suspend fun save() {
        val parts = mutableListOf(ApiClient.Part.field("kind", kind))
        fun add(name: String, value: String?) { if (!value.isNullOrEmpty()) parts += ApiClient.Part.field(name, value) }
        if (kind == "recognition") {
            add("recipient_id", recipientId?.toString())
            add("body", body.trim())
            if (canPost) points.trim().toIntOrNull()?.let { add("points", it.toString()) }
        } else {
            add("title", title.trim())
            add("body", body.trim())
            add("outlet_id", outletId?.toString())
            parts += ApiClient.Part.field("must_read", if (kind == "update" && mustRead) "1" else "0")
            parts += ApiClient.Part.field("comments_enabled", if (commentsEnabled) "1" else "0")
            if (schedule) add("publish_at", MeFormat.dateTimeString(publishAt))
            if (kind == "event") { add("event_at", MeFormat.dateTimeString(eventAt)); add("event_place", eventPlace.trim()) }
            photo?.let { parts += ApiClient.Part.file("photo", it.data, it.filename, it.mimeType) }
        }
        val r = env.client.upload("/v1/admin/updates", parts)
        env.toasts.show(r.json["message"].str ?: "Posted")
        onPosted()
    }

    MeSheet(
        title = when (kind) { "recognition" -> "Recognise someone"; "event" -> "New event"; else -> "New update" },
        primary = if (schedule && kind != "recognition") "Schedule" else "Post",
        onDismiss = onDismiss, disabled = !ok, action = { save() },
    ) {
        if (canPost) SegmentedTabs(listOf("update" to "📣 Update", "event" to "📅 Event", "recognition" to "⭐ Recognition"), kind) { kind = it }
        if (kind == "recognition") {
            SelectField("Who", recipientId, people.mapNotNull { p -> p.intId?.let { it to (p.string("name") ?: "") } }, { recipientId = it }, Modifier.fillMaxWidth(), placeholder = "Choose a colleague…")
            Field("What they did", body, { body = it }, placeholder = "Stayed late to finish the wedding order…", multiline = true)
            if (canPost) DecimalField("Points (optional; payroll turns them into a bonus only when a point has a value)", points, { points = it.filter { c -> c.isDigit() } })
        } else {
            Field("Title", title, { title = it })
            Field("Details", body, { body = it }, multiline = true)
            if (kind == "event") {
                MeDateTimeField("When", eventAt) { eventAt = it }
                Field("Where", eventPlace, { eventPlace = it })
            }
            MeFieldLabel("Photo (optional, up to 5 MB)") { MePhotoPickerField("Choose photo", photo) { photo = it } }
            MeOutletPicker(outlets, outletId) { outletId = it }
            if (kind == "update") ToggleRow("Must read (everyone presses \"I have read this\")", mustRead, { mustRead = it })
            ToggleRow("Allow comments", commentsEnabled, { commentsEnabled = it })
            ToggleRow("Schedule", schedule, { schedule = it })
            if (schedule) MeDateTimeField("Goes out", publishAt) { publishAt = it }
        }
    }
}

// ── Scheduled posts (updates.post): post now or remove ───────────────────────

@Composable
private fun ScheduledPostsSheet(onDismiss: () -> Unit, onChanged: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val list = remember { Resource(scope) { env.client.get("/v1/admin/updates/scheduled").json.listRecords } }
    LaunchedEffect(Unit) { list.load() }

    suspend fun postNow(p: Record) {
        try { env.client.put("/v1/admin/updates/${p.id}", jsonOf("publish_at" to null)); env.toasts.show("Posted."); list.load(); onChanged() } catch (e: Exception) { env.toasts.error(e) }
    }
    suspend fun remove(p: Record) {
        try { val r = env.client.delete("/v1/admin/updates/${p.id}"); env.toasts.show(r.json["message"].str ?: "Removed"); list.load(); onChanged() } catch (e: Exception) { env.toasts.error(e) }
    }

    InfoSheet("Scheduled posts", onDismiss) {
        LoadableView(list.state, retry = { list.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("Nothing scheduled", icon = Icons.Default.EventBusy) }) { rows ->
            MeListCard {
                rows.forEach { p ->
                    MeListRow {
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Text(p.string("title") ?: PostKind.label[p.string("kind") ?: ""] ?: "Post", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                            Text("Goes out ${MeFormat.dayTime(p.date("publish_at"))}${p["outlet"]["name"].str?.let { " · $it" } ?: ""}${if (p.flag("must_read")) " · must read" else ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        }
                        TextButton(onClick = { scope.launch { postNow(p) } }) { Text("Post now") }
                        TextButton(onClick = { scope.launch { remove(p) } }) { Text("Remove", color = Palette.danger) }
                    }
                }
            }
        }
    }
}
