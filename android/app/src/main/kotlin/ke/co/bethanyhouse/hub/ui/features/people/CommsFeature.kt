package ke.co.bethanyhouse.hub.ui.features.people

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Build
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.Forum
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.ShoppingBag
import androidx.compose.material.icons.filled.Tag
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.AppEnvironment
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.HubDates
import ke.co.bethanyhouse.hub.core.RealtimeClient
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
import ke.co.bethanyhouse.hub.ui.components.Avatar
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Loadable
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.hub
import ke.co.bethanyhouse.hub.ui.shell.FeatureRegistry
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import java.time.Instant
import java.util.UUID

/**
 * Messages (Phase 3 channels): spaces, DMs and order/production threads.
 * The phone stacks the list and a thread through the navigator
 * (`Route.Comms(channelId)`); the AI assist rail stays on the console bridge.
 */
object CommsFeature {
    fun register() {
        FeatureRegistry.register({ it is Route.Comms }) { r -> CommsScreen((r as Route.Comms).channelId) }
    }
}

@Composable
fun CommsScreen(channelId: Int?) {
    if (channelId == null) ChannelListScreen() else ThreadScreen(channelId)
}

// ── Models ───────────────────────────────────────────────────────────────────

internal data class ChatChannel(val json: JsonElement) {
    val id: Int get() = json["id"].int ?: 0
    val type: String get() = json["type"].str ?: "space"
    val name: String get() = json["name"].str ?: "Conversation"
    val description: String? get() = json["description"].str
    val isPrivate: Boolean get() = json["is_private"].bool ?: false
    val contextType: String? get() = json["context_type"].str
    val contextId: Int? get() = json["context_id"].int
    val unread: Int get() = json["unread_count"].int ?: 0
    val lastActivity: Instant? get() = json["last_activity_at"].date
    val lastMessage: JsonElement? get() = json["last_message"]?.takeIf { !it.isNull }
    val members: List<JsonElement> get() = json["members"].records
    val memberCount: Int get() = json["members"].int ?: members.size
    val isDM: Boolean get() = type == "dm"
    val isContext: Boolean get() = contextType != null
    val icon: ImageVector get() = if (isDM) Icons.Default.Person else if (isContext) (if (contextType == "order") Icons.Default.ShoppingBag else Icons.Default.Build) else if (isPrivate) Icons.Default.Lock else Icons.Default.Tag
    val contextRoute: Route? get() {
        val cid = contextId ?: return null
        return when (contextType) { "order" -> Route.Order(cid); "production_order" -> Route.ProductionOrder(cid); else -> null }
    }
    fun with(edits: Map<String, JsonElement>): ChatChannel = ChatChannel(JsonObject((json.obj ?: JsonObject(emptyMap())) + edits))
}

internal data class ChatMessage(val json: JsonElement) {
    val id: Int get() = json["id"].int ?: 0
    val channelId: Int get() = json["channel_id"].int ?: 0
    val body: String get() = json["body"].str ?: ""
    val type: String get() = json["type"].str ?: "text"
    val userId: Int? get() = json["user"]["id"].int
    val userName: String get() = json["user"]["name"].str ?: "System"
    val createdAt: Instant? get() = json["created_at"].date
    val editedAt: Instant? get() = json["edited_at"].date
    val replyTo: JsonElement? get() = json["reply_to"]?.takeIf { !it.isNull }
    val reactions: Map<String, List<Int>> get() = json["reactions"].obj?.entries?.associate { (k, v) -> k to v.records.mapNotNull { it.int } } ?: emptyMap()
    val linkedEntities: List<JsonElement> get() = json["linked_entities"].records
    val entityPreviews: JsonElement? get() = json["entity_previews"]
    fun with(edits: Map<String, JsonElement>): ChatMessage = ChatMessage(JsonObject((json.obj ?: JsonObject(emptyMap())) + edits))

    /** The console's `parseBody`: images `![n](u)`, files `[📎 n](u)` / `[n](http…)`, entity tokens `#[label](entity:type:id)` lifted out of the text. */
    data class Parsed(val text: String, val images: List<Pair<String, String>>, val files: List<Pair<String, String>>)
    val parsed: Parsed get() = parseBody(body)

    companion object {
        private val imageRe = Regex("""!\[([^\]]*)\]\(([^)]+)\)""")
        private val fileRe = Regex("""\[📎\s*([^\]]+)\]\(([^)]+)\)""")
        private val linkRe = Regex("""\[([^\]]+)\]\((https?://[^)]+)\)""")
        private val entityRe = Regex("""#\[([^\]]+)\]\(entity:[^)]+\)""")
        fun parseBody(body: String): Parsed {
            var text = body
            val images = mutableListOf<Pair<String, String>>(); val files = mutableListOf<Pair<String, String>>()
            text = imageRe.replace(text) { m -> images += m.groupValues[1] to m.groupValues[2]; "" }
            text = fileRe.replace(text) { m -> files += m.groupValues[1] to m.groupValues[2]; "" }
            text = linkRe.replace(text) { m -> files += m.groupValues[1] to m.groupValues[2]; "" }
            text = entityRe.replace(text, "")
            return Parsed(text.trim(), images, files)
        }
    }
}

// ── Store (GET /channels; realtime unread + last message) ───────────────────

internal class CommsStore(private val env: AppEnvironment, private val scope: CoroutineScope) {
    val channels = mutableStateListOf<ChatChannel>()
    var state: Loadable<List<ChatChannel>> by mutableStateOf(Loadable.Idle)
        private set
    private val subscriptions = mutableMapOf<Int, UUID>()

    val spaces: List<ChatChannel> get() = channels.filter { !it.isDM && !it.isContext }
    val dms: List<ChatChannel> get() = channels.filter { it.isDM }
    val threads: List<ChatChannel> get() = channels.filter { it.isContext }

    suspend fun load() {
        state = Loadable.Loading(channels.toList().takeIf { it.isNotEmpty() })
        try {
            val j = env.client.get("/v1/admin/channels").json
            val list = j["channels"].records.map(::ChatChannel).sortedByDescending { it.lastActivity ?: Instant.EPOCH }
            channels.clear(); channels.addAll(list)
            state = Loadable.Loaded(channels.toList())
            watchAll()
        } catch (e: ApiError) { state = Loadable.Failed(e, channels.toList().takeIf { it.isNotEmpty() }) }
        catch (e: Exception) { state = Loadable.Failed(ApiError.transport(e.message ?: "Error"), channels.toList().takeIf { it.isNotEmpty() }) }
    }

    /** One subscription per listed channel keeps unread counts and the list's last message live. */
    private suspend fun watchAll() {
        val rt = env.realtime ?: return
        for (ch in channels.toList()) {
            if (subscriptions.containsKey(ch.id)) continue
            val id = ch.id
            subscriptions[id] = rt.subscribe("channel.$id") { event: RealtimeClient.Event ->
                if (event.name == ".message.sent" || event.name == "message.sent") event.payload?.let { p -> scope.launch { messageArrived(id, p) } }
            }
        }
    }

    private fun messageArrived(channelId: Int, payload: JsonElement) {
        val i = channels.indexOfFirst { it.id == channelId }
        if (i < 0) return
        val m = ChatMessage(payload)
        val mine = m.userId == env.session.current.me?.id
        val unread = if (mine) 0 else channels[i].unread + 1
        channels[i] = channels[i].with(mapOf(
            "unread_count" to JsonPrimitive(unread),
            "last_activity_at" to JsonPrimitive(m.json["created_at"].str ?: HubDates.iso(Instant.now())),
            "last_message" to jsonOf("id" to m.id, "body" to m.body, "user_name" to m.userName, "created_at" to (m.json["created_at"].str ?: "")),
        ))
        val sorted = channels.sortedByDescending { it.lastActivity ?: Instant.EPOCH }
        channels.clear(); channels.addAll(sorted)
        state = Loadable.Loaded(channels.toList())
    }

    fun upsert(ch: ChatChannel) {
        val i = channels.indexOfFirst { it.id == ch.id }
        if (i >= 0) channels[i] = ch else channels.add(0, ch)
        state = Loadable.Loaded(channels.toList())
        scope.launch { watchAll() }
    }

    fun remove(id: Int) { channels.removeAll { it.id == id }; state = Loadable.Loaded(channels.toList()) }

    fun stop() {
        val rt = env.realtime ?: return
        val subs = subscriptions.toMap(); subscriptions.clear()
        scope.launch { subs.forEach { (id, token) -> rt.unsubscribe("channel.$id", token) } }
    }
}

// ── Channel list ─────────────────────────────────────────────────────────────

@Composable
private fun ChannelListScreen() {
    val env = ke.co.bethanyhouse.hub.LocalEnv.current
    val scope = rememberCoroutineScope()
    val store = remember { CommsStore(env, scope) }
    var query by remember { mutableStateOf("") }
    var hits by remember { mutableStateOf<List<JsonElement>>(emptyList()) }
    var searching by remember { mutableStateOf(false) }
    var searched by remember { mutableStateOf(false) }
    var menu by remember { mutableStateOf(false) }
    var newSpace by remember { mutableStateOf(false) }
    var newDM by remember { mutableStateOf(false) }
    var quickReplies by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { store.load() }
    DisposableEffect(Unit) { onDispose { store.stop() } }

    suspend fun search() {
        if (query.length < 2) return
        searching = true
        try { hits = env.client.get("/v1/admin/channels/search", mapOf("q" to query)).json["hits"].records; searched = true }
        catch (e: Exception) { env.toasts.error(e) } finally { searching = false }
    }
    fun open(id: Int) = env.navigator.push(Route.Comms(id))

    Page("Messages", refresh = { store.load() }, actions = {
        Box {
            IconButton(onClick = { menu = true }) { Icon(Icons.Default.Edit, contentDescription = "New") }
            DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                DropdownMenuItem(text = { Text("New space") }, onClick = { menu = false; newSpace = true })
                DropdownMenuItem(text = { Text("New direct message") }, onClick = { menu = false; newDM = true })
                DropdownMenuItem(text = { Text("Quick replies") }, onClick = { menu = false; quickReplies = true })
            }
        }
    }) {
        ModuleSearchField(query, { query = it; if (it.isEmpty()) { hits = emptyList(); searched = false } }, "Search messages", onSubmit = { scope.launch { search() } })
        if (query.isNotEmpty()) {
            if (searching) androidx.compose.material3.CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp)
            else if (!searched) Text("Press search to look through messages", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
            else if (hits.isEmpty()) Text("No messages match", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
            hits.forEach { h ->
                Column(Modifier.fillMaxWidth().clickable { h["channel_id"].int?.let { query = ""; open(it) } }.padding(vertical = Space.s), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(store.channels.firstOrNull { it.id == h["channel_id"].int }?.name ?: "Conversation", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
                    Text(h["snippet"].str ?: "", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, maxLines = 2, overflow = TextOverflow.Ellipsis)
                    DateText(h["created_at"].date, DateStyle.RELATIVE)
                }
            }
        } else LoadableView(store.state, retry = { scope.launch { store.load() } }, isEmpty = { it.isEmpty() }, empty = { EmptyState("No conversations yet", "Start a space or message a colleague.", Icons.Default.Forum) }) { _ ->
            ChannelSection("Spaces", store.spaces, ::open)
            ChannelSection("Direct messages", store.dms, ::open)
            ChannelSection("Order & production threads", store.threads, ::open)
        }
    }
    if (newSpace) NewSpaceSheet(store, onDismiss = { newSpace = false }) { newSpace = false; open(it) }
    if (newDM) NewDMSheet(store, onDismiss = { newDM = false }) { newDM = false; open(it) }
    if (quickReplies) QuickRepliesSheet { quickReplies = false }
}

@Composable
private fun ChannelSection(title: String, list: List<ChatChannel>, open: (Int) -> Unit) {
    if (list.isEmpty()) return
    Text(title.uppercase(), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
    list.forEach { ch -> ChannelRow(ch, Modifier.clickable { open(ch.id) }) }
}

@Composable
internal fun ChannelRow(channel: ChatChannel, modifier: Modifier = Modifier) {
    Row(modifier.fillMaxWidth().padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
        if (channel.isDM) Avatar(channel.name, 30.dp)
        else Box(Modifier.size(30.dp).background(Palette.neutralSoft, MaterialTheme.shapes.extraSmall), contentAlignment = Alignment.Center) { Icon(channel.icon, contentDescription = null, tint = Palette.neutral, modifier = Modifier.size(16.dp)) }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(channel.name, style = MaterialTheme.typography.bodyMedium, fontWeight = if (channel.unread > 0) FontWeight.SemiBold else FontWeight.Normal, maxLines = 1, overflow = TextOverflow.Ellipsis)
            channel.lastMessage?.let { lm ->
                Text((lm["user_name"].str?.let { "$it: " } ?: "") + ChatMessage.parseBody(lm["body"].str ?: "").text, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
        }
        Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
            DateText(channel.lastActivity, DateStyle.RELATIVE)
            if (channel.unread > 0) Text("${channel.unread}", style = MaterialTheme.typography.labelSmall, color = Color.White, modifier = Modifier.background(Palette.brand, CircleShape).padding(horizontal = 6.dp, vertical = 2.dp))
        }
    }
}
