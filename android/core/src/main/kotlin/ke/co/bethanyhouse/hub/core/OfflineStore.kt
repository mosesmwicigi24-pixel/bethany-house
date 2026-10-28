package ke.co.bethanyhouse.hub.core

import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.withContext
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement
import java.io.File
import java.time.Instant
import java.util.UUID

/**
 * Read cache and pending-operation queues (Phase 11), scoped per person.
 *
 * Policy (docs/NATIVE_APPS_AUDIT.md §5): money writes are never queued. What
 * may wait for a connection is exactly what the console already queues: one
 * clock punch, tailor task status and progress, to-do ticks, course progress,
 * form drafts. Everything the person has opened is cached for offline reading
 * and purged on sign-out. Files live in the app's private storage (encrypted
 * at rest by Android file-based encryption).
 */
class OfflineStore(context: Context, userId: Int) {
    private val root: File = File(context.filesDir, "hub/user-$userId").apply { mkdirs() }

    private fun fileFor(key: String) = File(root, key.replace('/', '_').replace('?', '_') + ".json")

    @Serializable private data class Envelope(val at: Long, val json: JsonElement)

    data class Cached<T>(val value: T, val at: Instant) {
        val ageSeconds: Long get() = Instant.now().epochSecond - at.epochSecond
    }

    suspend fun put(json: JsonElement, key: String) = withContext(Dispatchers.IO) {
        fileFor(key).writeText(HubJson.json.encodeToString(Envelope.serializer(), Envelope(Instant.now().epochSecond, json)))
    }

    suspend fun get(key: String): Cached<JsonElement>? = withContext(Dispatchers.IO) {
        val f = fileFor(key)
        if (!f.exists()) return@withContext null
        runCatching { HubJson.json.decodeFromString(Envelope.serializer(), f.readText()) }.getOrNull()?.let { Cached(it.json, Instant.ofEpochSecond(it.at)) }
    }

    suspend fun remove(key: String) = withContext(Dispatchers.IO) { fileFor(key).delete(); Unit }

    // ── Pending operations ───────────────────────────────────────────────────

    /** A queued write, replayed in order when the connection returns; `dedupeKey` collapses repeats. */
    @Serializable
    data class PendingOperation(
        val id: String = UUID.randomUUID().toString(),
        val kind: String,
        val method: String,
        val path: String,
        val body: JsonElement,
        val dedupeKey: String? = null,
        val recordedAt: Long = Instant.now().epochSecond,
    )

    private val queueFile get() = File(root, "pending-queue.json")
    private val _pendingCount = MutableStateFlow(0)
    val pendingCount: StateFlow<Int> = _pendingCount.asStateFlow()

    suspend fun pending(): List<PendingOperation> = withContext(Dispatchers.IO) {
        if (!queueFile.exists()) return@withContext emptyList()
        runCatching { HubJson.json.decodeFromString(kotlinx.serialization.builtins.ListSerializer(PendingOperation.serializer()), queueFile.readText()) }.getOrDefault(emptyList())
            .also { _pendingCount.value = it.size }
    }

    private suspend fun write(q: List<PendingOperation>) = withContext(Dispatchers.IO) {
        queueFile.writeText(HubJson.json.encodeToString(kotlinx.serialization.builtins.ListSerializer(PendingOperation.serializer()), q))
        _pendingCount.value = q.size
    }

    suspend fun enqueue(op: PendingOperation) {
        val q = pending().toMutableList()
        op.dedupeKey?.let { k -> q.removeAll { it.dedupeKey == k } }
        q += op
        write(q)
    }

    suspend fun dequeue(id: String) { write(pending().filterNot { it.id == id }) }

    data class ReplayResult(val sent: List<PendingOperation>, val refused: List<Pair<PendingOperation, ApiError>>)

    /** Replays the queue: a final 4xx drops the item (the server refused it); a transport failure stops and keeps the rest. */
    suspend fun replay(client: ApiClient): ReplayResult {
        val sent = mutableListOf<PendingOperation>()
        val refused = mutableListOf<Pair<PendingOperation, ApiError>>()
        for (op in pending()) {
            val method = runCatching { ApiClient.Method.valueOf(op.method) }.getOrNull()
            if (method == null) { dequeue(op.id); continue }
            try {
                client.send(ApiClient.Request(method, op.path, body = ApiClient.Body.Json(op.body)))
                dequeue(op.id); sent += op
            } catch (e: ApiError) {
                if (e.status in 400..499 && e.status != 401 && e.status != 423) { dequeue(op.id); refused += op to e } else break
            }
        }
        return ReplayResult(sent, refused)
    }

    suspend fun purge() = withContext(Dispatchers.IO) { root.deleteRecursively(); Unit }
}

/** Reachability as the app shows it (status pill, pending badge). */
class ConnectionMonitor(context: Context) {
    private val _isOnline = MutableStateFlow(true)
    val isOnline: StateFlow<Boolean> = _isOnline.asStateFlow()
    private val _isMetered = MutableStateFlow(false)
    val isMetered: StateFlow<Boolean> = _isMetered.asStateFlow()

    init {
        val cm = context.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
        _isOnline.value = cm.activeNetwork?.let { cm.getNetworkCapabilities(it)?.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) } ?: false
        cm.registerNetworkCallback(NetworkRequest.Builder().addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET).build(), object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) { _isOnline.value = true }
            override fun onLost(network: Network) { _isOnline.value = cm.activeNetwork != null && cm.activeNetwork != network }
            override fun onCapabilitiesChanged(network: Network, caps: NetworkCapabilities) {
                _isOnline.value = caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
                _isMetered.value = !caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_NOT_METERED)
            }
        })
    }
}
