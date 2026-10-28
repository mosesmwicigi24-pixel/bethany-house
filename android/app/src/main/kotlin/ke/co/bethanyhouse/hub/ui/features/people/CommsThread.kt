package ke.co.bethanyhouse.hub.ui.features.people

import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Description
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.OpenInNew
import androidx.compose.material.icons.filled.Send
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.filled.Tag
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import ke.co.bethanyhouse.hub.AppEnvironment
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.RealtimeClient
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.AsyncTextButton
import ke.co.bethanyhouse.hub.ui.components.AttachmentPicker
import ke.co.bethanyhouse.hub.ui.components.Avatar
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.ErrorState
import ke.co.bethanyhouse.hub.ui.components.InfoSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.Loadable
import ke.co.bethanyhouse.hub.ui.components.LoadingSkeleton
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.PickedFile
import ke.co.bethanyhouse.hub.ui.components.Pill
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.uploadFile
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import java.util.UUID

/**
 * One conversation: messages (paged with `before`), realtime, the composer
 * with attachments, quick replies and entity tags, and per-message actions.
 */
internal class ThreadStore(private val env: AppEnvironment, private val scope: CoroutineScope, val channelId: Int) {
    val messages = mutableStateListOf<ChatMessage>()
    var reads by mutableStateOf<Map<Int, Int>>(emptyMap())       // user_id → last_read_message_id
        private set
    private var readerNames = mapOf<Int, String>()
    val typing = mutableStateListOf<String>()
    var onlineIds by mutableStateOf<Set<Int>>(emptySet())
        private set
    var hasMore by mutableStateOf(false)
        private set
    var state: Loadable<List<ChatMessage>> by mutableStateOf(Loadable.Idle)
        private set
    var replyTo by mutableStateOf<ChatMessage?>(null)
    var editing by mutableStateOf<ChatMessage?>(null)
    private val tokens = mutableListOf<Pair<String, UUID>>()
    private val typingTimers = mutableMapOf<String, Job>()
    private var lastWhisper = 0L
    private var poller: Job? = null

    val me: Int? get() = env.session.current.me?.id

    suspend fun load() {
        state = Loadable.Loading(messages.toList().takeIf { it.isNotEmpty() })
        try {
            val j = env.client.get("/v1/admin/channels/$channelId/messages", mapOf("per_page" to "50")).json
            messages.clear(); messages.addAll(j["messages"].records.map(::ChatMessage))
            hasMore = j["has_more"].bool ?: false
            applyReads(j["reads"])
            state = Loadable.Loaded(messages.toList())
            markRead()
            subscribe()
        } catch (e: ApiError) { state = Loadable.Failed(e, messages.toList().takeIf { it.isNotEmpty() }) }
        catch (e: Exception) { state = Loadable.Failed(ApiError.transport(e.message ?: "Error"), null) }
    }

    suspend fun loadOlder() {
        if (!hasMore) return
        val oldest = messages.firstOrNull()?.id ?: return
        val j = runCatching { env.client.get("/v1/admin/channels/$channelId/messages", mapOf("per_page" to "50", "before" to oldest.toString())).json }.getOrNull() ?: return
        messages.addAll(0, j["messages"].records.map(::ChatMessage))
        hasMore = j["has_more"].bool ?: false
        state = Loadable.Loaded(messages.toList())
    }

    private fun applyReads(v: JsonElement?) {
        val r = reads.toMutableMap(); val n = readerNames.toMutableMap()
        v.records.forEach { row -> row["user_id"].int?.let { uid -> r[uid] = row["last_read_message_id"].int ?: 0; n[uid] = row["name"].str ?: "" } }
        reads = r; readerNames = n
    }

    /** Read by everyone else in the conversation (the sender's tick). */
    fun readByAll(m: ChatMessage): Boolean { val others = reads.filterKeys { it != me }; return others.isNotEmpty() && others.values.all { it >= m.id } }
    fun readers(m: ChatMessage): List<String> = reads.filter { (uid, last) -> uid != me && last >= m.id }.keys.mapNotNull { readerNames[it] }.filter { it.isNotEmpty() }

    // Realtime (channel.{id}: .message.sent/.reaction.updated/.read.updated; presence.channel.{id}: client-typing)
    private suspend fun subscribe() {
        if (tokens.isNotEmpty()) return
        val rt = env.realtime
        if (rt == null) {
            poller = scope.launch { while (isActive) { delay(20_000); pollNew() } }
            return
        }
        val t1 = rt.subscribe("channel.$channelId") { e -> scope.launch { handle(e) } }
        val t2 = rt.subscribe("presence.channel.$channelId", presence = true) { e -> scope.launch { handlePresence(e) } }
        tokens += "channel.$channelId" to t1; tokens += "presence.channel.$channelId" to t2
    }

    private fun handle(event: RealtimeClient.Event) {
        val name = event.name.removePrefix(".")
        val p = event.payload ?: return
        when (name) {
            "message.sent" -> {
                val m = ChatMessage(p)
                if (messages.any { it.id == m.id }) return
                messages += m; state = Loadable.Loaded(messages.toList())
                if (m.userId != me) scope.launch { markRead() }
            }
            "reaction.updated" -> {
                val mid = p["message_id"].int ?: return
                val i = messages.indexOfFirst { it.id == mid }; val r = p["reactions"] ?: return
                if (i >= 0) { messages[i] = messages[i].with(mapOf("reactions" to r)); state = Loadable.Loaded(messages.toList()) }
            }
            "read.updated" -> {
                val uid = p["user_id"].int ?: return; val last = p["last_read_message_id"].int ?: return
                reads = reads + (uid to maxOf(reads[uid] ?: 0, last))
            }
        }
    }

    private fun handlePresence(event: RealtimeClient.Event) {
        when (event.name) {
            "pusher_internal:subscription_succeeded" -> onlineIds = event.payload["presence"]["ids"].records.mapNotNull { it.int }.toSet()
            "pusher_internal:member_added" -> event.payload["user_id"].int?.let { onlineIds = onlineIds + it }
            "pusher_internal:member_removed" -> event.payload["user_id"].int?.let { onlineIds = onlineIds - it }
            "client-typing" -> {
                val name = event.payload["name"].str ?: return
                if (event.payload["id"].int == me) return
                if (!typing.contains(name)) typing += name
                typingTimers[name]?.cancel()
                typingTimers[name] = scope.launch { delay(2_500); typing.remove(name) }
            }
        }
    }

    private suspend fun pollNew() {
        val j = runCatching { env.client.get("/v1/admin/channels/$channelId/messages", mapOf("per_page" to "50"), background = true).json }.getOrNull() ?: return
        var changed = false
        j["messages"].records.map(::ChatMessage).forEach { m -> if (messages.none { it.id == m.id }) { messages += m; changed = true } }
        applyReads(j["reads"])
        if (changed) state = Loadable.Loaded(messages.toList())
    }

    fun whisperTyping() {
        val now = System.currentTimeMillis()
        if (now - lastWhisper < 2_000) return
        val rt = env.realtime ?: return; val m = env.session.current.me ?: return
        lastWhisper = now
        rt.whisper("presence.channel.$channelId", "typing", jsonOf("id" to m.id, "name" to m.firstName))
    }

    fun stop() {
        poller?.cancel()
        typingTimers.values.forEach { it.cancel() }
        val rt = env.realtime ?: return
        val subs = tokens.toList(); tokens.clear()
        scope.launch { subs.forEach { (ch, t) -> rt.unsubscribe(ch, t) } }
    }

    // Actions
    suspend fun markRead() { runCatching { env.client.post("/v1/admin/channels/$channelId/read") } }

    suspend fun send(body: String) {
        val r = env.client.post("/v1/admin/channels/$channelId/messages", J.obj("body" to J.s(body), "reply_to_id" to J.i(replyTo?.id)))
        r.json["message"]?.let { ChatMessage(it) }?.let { msg -> if (messages.none { it.id == msg.id }) { messages += msg; state = Loadable.Loaded(messages.toList()) } }
        replyTo = null
    }

    suspend fun edit(m: ChatMessage, body: String) {
        val r = env.client.patch("/v1/admin/channels/$channelId/messages/${m.id}", J.obj("body" to J.s(body)))
        val i = messages.indexOfFirst { it.id == m.id }
        r.json["message"]?.let { if (i >= 0) { messages[i] = ChatMessage(it); state = Loadable.Loaded(messages.toList()) } }
        editing = null
    }

    suspend fun delete(m: ChatMessage) {
        env.client.delete("/v1/admin/channels/$channelId/messages/${m.id}")
        messages.removeAll { it.id == m.id }; state = Loadable.Loaded(messages.toList())
    }

    suspend fun react(m: ChatMessage, emoji: String) {
        val r = env.client.post("/v1/admin/channels/$channelId/messages/${m.id}/react", J.obj("emoji" to J.s(emoji)))
        val i = messages.indexOfFirst { it.id == m.id }
        r.json["reactions"]?.let { rx -> if (i >= 0) { messages[i] = messages[i].with(mapOf("reactions" to rx)); state = Loadable.Loaded(messages.toList()) } }
    }

    suspend fun forward(m: ChatMessage, toChannel: Int) {
        env.client.post("/v1/admin/channels/$channelId/messages/${m.id}/forward", J.obj("to_channel_id" to J.i(toChannel)))
    }

    suspend fun uploadAttachment(file: PickedFile): JsonElement? = env.client.uploadFile("/v1/admin/channels/attachments", file).json
}

// ── Thread screen ────────────────────────────────────────────────────────────

@Composable
internal fun ThreadScreen(channelId: Int) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val thread = remember(channelId) { ThreadStore(env, scope, channelId) }
    var channel by remember(channelId) { mutableStateOf<ChatChannel?>(null) }
    var channelError by remember(channelId) { mutableStateOf<ApiError?>(null) }
    var showDetails by remember { mutableStateOf(false) }
    var forwarding by remember { mutableStateOf<ChatMessage?>(null) }
    var actionsFor by remember { mutableStateOf<ChatMessage?>(null) }
    suspend fun loadChannel() {
        try { channel = env.client.get("/v1/admin/channels/$channelId").json["channel"]?.let(::ChatChannel); channelError = null }
        catch (e: ApiError) { channelError = e } catch (e: Exception) { channelError = ApiError.transport(e.message ?: "Error") }
    }
    LaunchedEffect(channelId) { loadChannel(); thread.load() }
    DisposableEffect(channelId) { onDispose { thread.stop() } }

    Column(Modifier.fillMaxSize().background(MaterialTheme.hub.canvas)) {
        val ch = channel
        Row(Modifier.fillMaxWidth().padding(horizontal = Space.gutter, vertical = Space.m), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            if (ch != null) Icon(ch.icon, contentDescription = null, tint = Palette.brandDeep, modifier = Modifier.size(20.dp))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(1.dp)) {
                Text(ch?.name ?: "Conversation", style = MaterialTheme.typography.titleMedium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                val d = ch?.description
                if (!d.isNullOrEmpty()) Text(d, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, maxLines = 1, overflow = TextOverflow.Ellipsis)
                else if (ch != null && !ch.isDM) Text("${ch.memberCount} members", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            }
            ch?.contextRoute?.let { r -> IconButton(onClick = { env.navigator.go(r) }) { Icon(Icons.Default.OpenInNew, contentDescription = "Open record") } }
            if (ch != null) IconButton(onClick = { showDetails = true }) { Icon(Icons.Default.Info, contentDescription = "Details") }
        }
        HorizontalDivider(color = MaterialTheme.hub.line)
        channelError?.let { if (ch == null) ErrorState(it, retry = { scope.launch { loadChannel() } }) }
        Box(Modifier.weight(1f).fillMaxWidth()) { MessagesList(thread, onActions = { actionsFor = it }) }
        if (thread.typing.isNotEmpty()) Text("${thread.typing.joinToString(", ")} ${if (thread.typing.size == 1) "is" else "are"} typing…", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary, modifier = Modifier.padding(horizontal = Space.gutter))
        HorizontalDivider(color = MaterialTheme.hub.line)
        Composer(thread)
    }
    actionsFor?.let { m -> MessageActionsSheet(thread, m, onDismiss = { actionsFor = null }, forward = { forwarding = m }) }
    forwarding?.let { m -> ForwardSheet(thread, m) { forwarding = null } }
    if (showDetails) channel?.let { ch -> ChannelDetailsSheet(ch, onDismiss = { showDetails = false }, onChanged = { scope.launch { loadChannel() } }, onRemoved = { showDetails = false; env.navigator.pop() }) }
}

@Composable
private fun MessagesList(thread: ThreadStore, onActions: (ChatMessage) -> Unit) {
    val scroll = rememberScrollState()
    val scope = rememberCoroutineScope()
    val lastId = thread.messages.lastOrNull()?.id
    LaunchedEffect(lastId) { if (lastId != null) scroll.animateScrollTo(scroll.maxValue) }
    Column(Modifier.fillMaxSize().verticalScroll(scroll).padding(horizontal = Space.gutter, vertical = Space.m), verticalArrangement = Arrangement.spacedBy(Space.s)) {
        if (thread.hasMore) TextButton(onClick = { scope.launch { thread.loadOlder() } }, modifier = Modifier.fillMaxWidth()) { Text("Load earlier messages") }
        val st = thread.state
        if (st is Loadable.Failed && st.previous == null) ErrorState(st.error, retry = { scope.launch { thread.load() } })
        else if (st is Loadable.Idle || (st is Loadable.Loading && st.previous == null)) LoadingSkeleton(4)
        thread.messages.forEach { m -> MessageRow(thread, m) { onActions(m) } }
    }
}

private val quickEmoji = listOf("👍", "❤️", "🙏", "😂", "🎉", "✅")

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun MessageRow(thread: ThreadStore, message: ChatMessage, onLongPress: () -> Unit) {
    val env = LocalEnv.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val mine = message.userId == thread.me
    val p = message.parsed
    Row(Modifier.fillMaxWidth().combinedClickable(onClick = {}, onLongClick = onLongPress), horizontalArrangement = if (mine) Arrangement.End else Arrangement.Start, verticalAlignment = Alignment.Top) {
        if (!mine) { Avatar(message.userName, 28.dp); Spacer(Modifier.width(Space.s)) }
        Column(Modifier.widthIn(max = 320.dp), horizontalAlignment = if (mine) Alignment.End else Alignment.Start, verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Text(message.userName, style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold)
                DateText(message.createdAt, DateStyle.TIME)
                if (message.editedAt != null) Text("edited", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
            }
            message.replyTo?.let { r ->
                Text("↩ ${r["user_name"].str ?: ""}: ${r["body"].str ?: ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.background(Palette.neutralSoft, MaterialTheme.shapes.extraSmall).padding(6.dp))
            }
            if (message.type == "system") Text(p.text, style = MaterialTheme.typography.labelSmall, fontStyle = FontStyle.Italic, color = MaterialTheme.hub.textTertiary)
            else if (p.text.isNotEmpty()) SelectionContainer {
                Text(p.text, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.background(if (mine) Palette.brand.copy(alpha = 0.14f) else MaterialTheme.hub.card, MaterialTheme.shapes.medium).padding(horizontal = 12.dp, vertical = 8.dp))
            }
            p.images.forEach { (_, url) -> PeopleSignedImage(url, 220.dp) }
            p.files.forEach { (name, url) ->
                AsyncTextButton(name) { peoplePresentBytes(context, peopleFetchSigned(env, url), name) }
            }
            message.linkedEntities.forEach { e -> EntityChip(e, message.entityPreviews["${e["type"].str ?: ""}:${e["id"].int ?: 0}"]) }
            val rx = message.reactions.filter { it.value.isNotEmpty() }.toSortedMap()
            if (rx.isNotEmpty()) FlowRow(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                rx.forEach { (emoji, users) ->
                    val on = users.contains(thread.me ?: -1)
                    Text("$emoji ${users.size}", style = MaterialTheme.typography.labelSmall, color = if (on) Palette.brand else Palette.neutral,
                        modifier = Modifier.border(1.dp, if (on) Palette.brand else MaterialTheme.hub.line, CircleShape).clickable { scope.launch { try { thread.react(message, emoji) } catch (e: Exception) { env.toasts.error(e) } } }.padding(horizontal = 8.dp, vertical = 3.dp))
                }
            }
            if (mine && message.type != "system") {
                val readers = thread.readers(message); val all = thread.readByAll(message)
                Text(if (all) "Read" else if (readers.isEmpty()) "Sent" else "Read by ${readers.joinToString(", ")}", fontSize = 10.sp, color = if (all) Palette.success else MaterialTheme.hub.textTertiary)
            }
        }
    }
}

/** Long-press actions: reply, react, forward, edit and delete (own messages). */
@Composable
private fun MessageActionsSheet(thread: ThreadStore, message: ChatMessage, onDismiss: () -> Unit, forward: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val mine = message.userId == thread.me
    InfoSheet("Message", onDismiss) {
        Text(message.parsed.text, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary, maxLines = 3, overflow = TextOverflow.Ellipsis)
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            quickEmoji.forEach { e -> Text(e, fontSize = 22.sp, modifier = Modifier.clickable { scope.launch { try { thread.react(message, e) } catch (x: Exception) { env.toasts.error(x) } }; onDismiss() }.padding(4.dp)) }
        }
        TextButton(onClick = { thread.replyTo = message; onDismiss() }) { Text("Reply") }
        TextButton(onClick = { forward(); onDismiss() }) { Text("Forward") }
        if (mine && message.type != "system") {
            TextButton(onClick = { thread.editing = message; onDismiss() }) { Text("Edit") }
            AsyncTextButton("Delete", color = Palette.danger) { thread.delete(message); onDismiss() }
        }
    }
}

/** `#[#ORD-1234](entity:order:1234)` → a chip that opens the record. */
@Composable
private fun EntityChip(entity: JsonElement, preview: JsonElement?) {
    val env = LocalEnv.current
    val type = entity["type"].str ?: ""
    val id = entity["id"].int ?: 0
    val route: Route? = when (type) { "order" -> Route.Order(id); "production_order" -> Route.ProductionOrder(id); else -> null }
    Row(
        Modifier.background(MaterialTheme.hub.card, MaterialTheme.shapes.small).border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.small).clickable(enabled = route != null) { route?.let { env.navigator.go(it) } }.padding(horizontal = 10.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Icon(if (type == "order") Icons.Default.Tag else Icons.Default.Description, contentDescription = null, modifier = Modifier.size(14.dp))
        Column(verticalArrangement = Arrangement.spacedBy(1.dp)) {
            Text(preview["label"].str ?: entity["label"].str ?: "#$id", style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold)
            if (preview != null) Text(listOfNotNull(preview["subtitle"].str, preview["meta"].str).joinToString(" · "), fontSize = 10.sp, color = MaterialTheme.hub.textSecondary, maxLines = 1)
        }
        preview["status"].str?.let { StatusBadge(it) }
        if (preview["is_overdue"].bool == true) Pill("overdue", StatusTone.DANGER)
    }
}

// ── Composer (POST /messages {body, reply_to_id}; attachments via POST /channels/attachments) ──

private data class PendingUpload(val file: PickedFile, val uploaded: JsonElement?)

@Composable
private fun Composer(thread: ThreadStore) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var text by remember { mutableStateOf("") }
    var pending by remember { mutableStateOf<List<PendingUpload>>(emptyList()) }
    var uploading by remember { mutableStateOf(false) }
    var sending by remember { mutableStateOf(false) }
    var showQuick by remember { mutableStateOf(false) }
    var showEntities by remember { mutableStateOf(false) }
    val editing = thread.editing
    LaunchedEffect(editing?.id) { if (editing != null) text = editing.body }

    suspend fun attach(f: PickedFile) {
        pending = pending + PendingUpload(f, null)
        uploading = true
        try { val att = thread.uploadAttachment(f); pending = pending.map { if (it.file === f) it.copy(uploaded = att) else it } }
        catch (e: Exception) { env.toasts.error(e); pending = pending.filterNot { it.file === f } }
        finally { uploading = false }
    }
    suspend fun send() {
        sending = true
        try {
            val e = thread.editing
            if (e != null) { thread.edit(e, text); text = ""; return }
            val md = pending.mapNotNull { p ->
                val a = p.uploaded ?: return@mapNotNull null
                val url = a["url"].str ?: return@mapNotNull null
                val name = a["name"].str ?: p.file.name
                if (a["is_image"].bool == true) "![$name]($url)" else "[📎 $name]($url)"
            }.joinToString("\n")
            val body = listOf(text.trim(), md).filter { it.isNotEmpty() }.joinToString("\n")
            thread.send(body.ifEmpty { " " })
            text = ""; pending = emptyList()
        } catch (e: Exception) { env.toasts.error(e) } finally { sending = false }
    }

    Column(Modifier.fillMaxWidth().padding(horizontal = Space.gutter, vertical = Space.s), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        thread.replyTo?.let { r ->
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("Replying to ${r.userName}: ${r.parsed.text}", style = MaterialTheme.typography.labelSmall, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                IconButton(onClick = { thread.replyTo = null }) { Icon(Icons.Default.Close, contentDescription = "Cancel reply") }
            }
        }
        if (editing != null) Row(verticalAlignment = Alignment.CenterVertically) {
            Text("Editing message", style = MaterialTheme.typography.labelSmall, modifier = Modifier.weight(1f))
            IconButton(onClick = { thread.editing = null; text = "" }) { Icon(Icons.Default.Close, contentDescription = "Cancel edit") }
        }
        if (pending.isNotEmpty()) FlowRow(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            pending.forEach { p ->
                Row(Modifier.background(Palette.neutralSoft, CircleShape).padding(horizontal = 8.dp, vertical = 4.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    if (p.uploaded == null) CircularProgressIndicator(Modifier.size(12.dp), strokeWidth = 2.dp) else Text("✓", style = MaterialTheme.typography.labelSmall)
                    Text(p.file.name, style = MaterialTheme.typography.labelSmall, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.widthIn(max = 140.dp))
                    Icon(Icons.Default.Close, contentDescription = "Remove", modifier = Modifier.size(14.dp).clickable { pending = pending.filterNot { it === p } })
                }
            }
        }
        Row(verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
            if (pending.size < 10) AttachmentPicker("") { files -> files.forEach { f -> scope.launch { attach(f) } } }
            IconButton(onClick = { showQuick = true }) { Icon(Icons.Default.Star, contentDescription = "Quick replies") }
            IconButton(onClick = { showEntities = true }) { Icon(Icons.Default.Tag, contentDescription = "Tag an order or product") }
            OutlinedTextField(value = text, onValueChange = { text = it; thread.whisperTyping() }, placeholder = { Text("Message…") }, maxLines = 6, modifier = Modifier.weight(1f))
            val canSend = !sending && !uploading && (text.isNotBlank() || pending.isNotEmpty())
            IconButton(onClick = { scope.launch { send() } }, enabled = canSend) {
                if (sending) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp) else Icon(Icons.Default.Send, contentDescription = "Send", tint = if (canSend) Palette.brand else MaterialTheme.hub.textTertiary)
            }
        }
    }
    if (showQuick) QuickReplyPicker(onDismiss = { showQuick = false }) { text = (if (text.isEmpty()) "" else "$text ") + it; showQuick = false }
    if (showEntities) EntityPicker(onDismiss = { showEntities = false }) { text = (if (text.isEmpty()) "" else "$text ") + it + " "; showEntities = false }
}

/** GET /channels/quick-replies → {quick_replies: [{id, title, body}]} */
@Composable
private fun QuickReplyPicker(onDismiss: () -> Unit, pick: (String) -> Unit) {
    val env = LocalEnv.current
    var items by remember { mutableStateOf<List<Record>>(emptyList()) }
    LaunchedEffect(Unit) { items = runCatching { env.client.get("/v1/admin/channels/quick-replies").json["quick_replies"].toRecords() }.getOrDefault(emptyList()) }
    InfoSheet("Quick replies", onDismiss) {
        if (items.isEmpty()) Text("None saved yet", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
        items.forEach { q ->
            Column(Modifier.fillMaxWidth().clickable { pick(q.string("body") ?: "") }.padding(vertical = 6.dp), verticalArrangement = Arrangement.spacedBy(1.dp)) {
                Text(q.string("title") ?: "", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
                Text(q.string("body") ?: "", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, maxLines = 2, overflow = TextOverflow.Ellipsis)
            }
        }
    }
}

/** GET /channels/entity-search?q → {results: [{type, id, label, subtitle, status, meta}]}; inserts `#[label](entity:type:id)`. */
@Composable
private fun EntityPicker(onDismiss: () -> Unit, pick: (String) -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var q by remember { mutableStateOf("") }
    var results by remember { mutableStateOf<List<JsonElement>>(emptyList()) }
    InfoSheet("Tag an order, customer or product", onDismiss) {
        ModuleSearchField(q, { q = it }, "Order number, customer or product", onSubmit = {
            scope.launch { try { results = env.client.get("/v1/admin/channels/entity-search", mapOf("q" to q)).json["results"].records } catch (e: Exception) { env.toasts.error(e) } }
        })
        results.forEach { r ->
            Row(Modifier.fillMaxWidth().clickable { pick("#[${r["label"].str ?: ""}](entity:${r["type"].str ?: ""}:${r["id"].int ?: 0})") }.padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                Column(Modifier.weight(1f)) {
                    Text(r["label"].str ?: "", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
                    Text(r["subtitle"].str ?: "", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                }
                StatusBadge(r["status"].str)
            }
        }
    }
}

/** POST /channels/{id}/messages/{msg}/forward {to_channel_id} */
@Composable
private fun ForwardSheet(thread: ThreadStore, message: ChatMessage, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var channels by remember { mutableStateOf<List<ChatChannel>>(emptyList()) }
    LaunchedEffect(Unit) { channels = runCatching { env.client.get("/v1/admin/channels").json["channels"].records.map(::ChatChannel) }.getOrDefault(emptyList()) }
    InfoSheet("Forward to", onDismiss) {
        Text(message.parsed.text, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, maxLines = 3, overflow = TextOverflow.Ellipsis)
        val scope = rememberCoroutineScope()
        channels.filter { it.id != message.channelId }.forEach { ch ->
            ChannelRow(ch, Modifier.clickable { scope.launch { try { thread.forward(message, ch.id); env.toasts.show("Forwarded to ${ch.name}"); onDismiss() } catch (e: Exception) { env.toasts.error(e) } } })
        }
    }
}
