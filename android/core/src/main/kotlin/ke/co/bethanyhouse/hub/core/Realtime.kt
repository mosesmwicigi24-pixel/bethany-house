package ke.co.bethanyhouse.hub.core

import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.serialization.json.JsonElement
import okhttp3.Request
import okhttp3.Response
import okhttp3.WebSocket
import okhttp3.WebSocketListener
import java.util.UUID
import java.util.concurrent.ConcurrentHashMap

/**
 * Reverb (Pusher protocol) over an OkHttp WebSocket: the realtime side of
 * messages, comments and notifications (react-admin/src/lib/echo.ts).
 *
 * Channels: `user.{id}` (`.notification.pushed`), `thread.{model}.{id}`
 * (`.comment.posted`), `channel.{id}` (`.message.sent`, `.reaction.updated`,
 * `.read.updated`), `presence.channel.{id}` (typing). Private and presence
 * channels are signed by `POST /api/broadcasting/auth` with the bearer token.
 */
class RealtimeClient(private val config: Configuration, private val client: ApiClient) {
    data class Configuration(val appKey: String, val host: String, val port: Int = 443, val scheme: String = "wss")
    data class Event(val channel: String?, val name: String, val payload: JsonElement?)
    fun interface Handler { fun on(event: Event) }

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var socket: WebSocket? = null
    @Volatile private var socketId: String? = null
    @Volatile private var running = false
    private var reconnectDelayMs = 1_000L
    private val subscriptions = ConcurrentHashMap<String, MutableMap<UUID, Handler>>()
    private val subscribeLock = Mutex()

    fun connect() {
        if (running) return
        running = true
        open()
    }

    fun disconnect() {
        running = false
        socket?.close(1000, "bye")
        socket = null
        socketId = null
    }

    /** Subscribes (private channels get `private-` on the wire). Returns a token to unsubscribe. */
    suspend fun subscribe(channel: String, presence: Boolean = false, handler: Handler): UUID {
        val wire = if (presence) "presence-$channel" else if (channel.startsWith("private-")) channel else "private-$channel"
        val id = UUID.randomUUID()
        val fresh = subscribeLock.withLock {
            val map = subscriptions.getOrPut(wire) { mutableMapOf() }
            val wasEmpty = map.isEmpty()
            map[id] = handler
            wasEmpty
        }
        if (fresh) sendSubscribe(wire)
        return id
    }

    suspend fun unsubscribe(channel: String, token: UUID) {
        subscriptions.keys.filter { it.endsWith(channel) }.forEach { key ->
            val empty = subscribeLock.withLock {
                subscriptions[key]?.remove(token)
                subscriptions[key]?.isEmpty() == true
            }
            if (empty) {
                subscriptions.remove(key)
                send(jsonOf("event" to "pusher:unsubscribe", "data" to jsonOf("channel" to key)))
            }
        }
    }

    /** Client event on a presence channel (typing indicators). */
    fun whisper(channel: String, event: String, data: JsonElement) {
        send(jsonOf("event" to "client-$event", "channel" to "presence-$channel", "data" to data))
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private fun open() {
        val url = "${config.scheme}://${config.host}:${config.port}/app/${config.appKey}?protocol=7&client=bethany-hub-android&version=1.0"
        socket = client.okHttp.newWebSocket(Request.Builder().url(url).build(), object : WebSocketListener() {
            override fun onMessage(webSocket: WebSocket, text: String) { scope.launch { handle(text) } }
            override fun onClosed(webSocket: WebSocket, code: Int, reason: String) { reconnect() }
            override fun onFailure(webSocket: WebSocket, t: Throwable, response: Response?) { reconnect() }
        })
    }

    private fun reconnect() {
        socketId = null
        if (!running) return
        scope.launch {
            delay(reconnectDelayMs)
            reconnectDelayMs = minOf(reconnectDelayMs * 2, 30_000)
            if (running) open()
        }
    }

    private suspend fun handle(text: String) {
        val msg = HubJson.parseOrNull(text) ?: return
        val name = msg["event"].str ?: ""
        val channel = msg["channel"].str
        var payload = msg["data"]
        payload.str?.let { s -> HubJson.parseOrNull(s)?.let { if (it.obj != null || it.arr != null) payload = it } }
        when (name) {
            "pusher:connection_established" -> {
                socketId = payload["socket_id"].str
                reconnectDelayMs = 1_000
                subscriptions.keys.forEach { sendSubscribe(it) }
            }
            "pusher:ping" -> send(jsonOf("event" to "pusher:pong", "data" to jsonOf()))
            else -> channel?.let { ch -> subscriptions[ch]?.values?.toList()?.forEach { it.on(Event(ch, name, payload)) } }
        }
    }

    private suspend fun sendSubscribe(wire: String) {
        val sid = socketId ?: return
        val data = mutableMapOf<String, Any?>("channel" to wire)
        try {
            val r = client.send(ApiClient.Request(ApiClient.Method.POST, "/broadcasting/auth", body = ApiClient.Body.Json(jsonOf("socket_id" to sid, "channel_name" to wire))))
            r.json["auth"].str?.let { data["auth"] = it }
            r.json["channel_data"].str?.let { data["channel_data"] = it }
        } catch (_: ApiError) {
            return
        }
        send(jsonOf("event" to "pusher:subscribe", "data" to toJson(data)))
    }

    private fun send(obj: JsonElement) {
        socket?.send(HubJson.json.encodeToString(JsonElement.serializer(), obj))
    }
}
