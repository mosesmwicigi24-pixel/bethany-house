package ke.co.bethanyhouse.hub.core

import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.NonCancellable
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.withContext
import kotlinx.serialization.Serializable
import kotlinx.serialization.builtins.ListSerializer
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
class OfflineStore internal constructor(private val root: File) {
    constructor(context: Context, userId: Int) : this(File(context.filesDir, "hub/user-$userId"))

    init { root.mkdirs() }

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
    private val queueSerializer = ListSerializer(PendingOperation.serializer())
    private val _pendingCount = MutableStateFlow(0)
    val pendingCount: StateFlow<Int> = _pendingCount.asStateFlow()
    /** The saved queue could not be read: `pendingCount` is not known. */
    private val _pendingUnreadable = MutableStateFlow(false)
    val pendingUnreadable: StateFlow<Boolean> = _pendingUnreadable.asStateFlow()

    /** The stored queue exists but cannot be read. It is never treated as empty. */
    class QueueUnreadable(cause: Throwable) : Exception("Changes saved on this device could not be read.", cause)

    // Guards the queue file, `inFlight` and `delivered`. Plain blocking lock:
    // the file is small and every public call runs on Dispatchers.IO.
    private val guard = Any()
    /** Operations being sent right now; a second sender gets `AlreadySending`. */
    private val inFlight = mutableSetOf<String>()
    /**
     * Sent and accepted (or finally refused), but the queue file could not be
     * rewritten. Hidden from `pendingChecked()` and never sent again while the
     * app runs; the next successful write clears it from disk.
     */
    private val delivered = mutableSetOf<String>()

    /** The queue as best known. An unreadable queue reads as empty here: use `pendingChecked()` where "nothing waiting" must be true. */
    suspend fun pending(): List<PendingOperation> = try { pendingChecked() } catch (e: QueueUnreadable) { emptyList() }

    /** The queue, minus anything already delivered. Throws [QueueUnreadable] rather than answer "empty" for a file it cannot read. */
    suspend fun pendingChecked(): List<PendingOperation> = withContext(Dispatchers.IO) {
        synchronized(guard) { readLocked().filterNot { it.id in delivered } }
    }

    private fun readLocked(): List<PendingOperation> {
        val q = try {
            if (!queueFile.exists()) emptyList() else HubJson.json.decodeFromString(queueSerializer, queueFile.readText())
        } catch (e: Exception) {
            _pendingUnreadable.value = true
            throw QueueUnreadable(e)
        }
        _pendingUnreadable.value = false
        _pendingCount.value = q.count { it.id !in delivered }
        return q
    }

    /** Writes through a temporary file, dropping anything delivered; once that write succeeds, nothing delivered remains on disk. */
    private fun storeLocked(q: List<PendingOperation>) {
        val kept = q.filterNot { it.id in delivered }
        root.mkdirs()
        val tmp = File(root, "pending-queue.json.tmp")
        tmp.writeText(HubJson.json.encodeToString(queueSerializer, kept))
        if (!tmp.renameTo(queueFile)) { tmp.delete(); throw java.io.IOException("Could not replace the saved queue.") }
        delivered.clear()
        _pendingCount.value = kept.size
    }

    /** True only once the new queue is stored. An unreadable queue is never replaced, since it may hold work still owed to the Hub. */
    suspend fun enqueue(op: PendingOperation): Boolean = withContext(Dispatchers.IO) {
        synchronized(guard) {
            try {
                val q = readLocked().toMutableList()
                op.dedupeKey?.let { k -> q.removeAll { it.dedupeKey == k } }
                q += op
                storeLocked(q)
                true
            } catch (e: Exception) { false }
        }
    }

    /** Removes an operation. False when the queue could not be read or rewritten; it then stays on disk. */
    suspend fun dequeue(id: String): Boolean = withContext(Dispatchers.IO) { synchronized(guard) { dequeueLocked(id) } }

    private fun dequeueLocked(id: String): Boolean = try {
        val q = readLocked()
        if (q.any { it.id == id }) storeLocked(q.filterNot { it.id == id })
        true
    } catch (e: Exception) { false }

    // ── Sending: one path for replay and for a screen's "Send now" ─────────────

    sealed interface SendOutcome {
        /** Accepted by the server and removed from the queue. */
        data object Sent : SendOutcome
        /** Accepted, but the queue file could not be updated. Not sent again while the app runs. */
        data object SentButNotCleared : SendOutcome
        /** The server refused it for good ([isFinalRefusal]); removed. */
        data class Refused(val status: Int, val message: String) : SendOutcome
        /** Not sent (offline, temporary error, session ended); kept for retry. */
        data object KeptForRetry : SendOutcome
        /** Another sender has this operation in flight. */
        data object AlreadySending : SendOutcome
        /** No longer queued (sent, replaced by a newer value, or never queued). */
        data object NotQueued : SendOutcome
        /** The stored queue could not be read. */
        data object Unreadable : SendOutcome
    }

    /** Sends one queued operation, never twice at once. Replay and the clock's "Send now" both come here. */
    suspend fun send(id: String, transport: suspend (PendingOperation) -> Unit): SendOutcome {
        val op = withContext(Dispatchers.IO) {
            synchronized(guard) {
                if (id in inFlight) return@withContext SendOutcome.AlreadySending
                val q = try { readLocked() } catch (e: QueueUnreadable) { return@withContext SendOutcome.Unreadable }
                val op = q.firstOrNull { it.id == id && it.id !in delivered } ?: return@withContext SendOutcome.NotQueued
                inFlight += id
                op
            }
        }
        if (op is SendOutcome) return op
        op as PendingOperation
        try {
            try {
                transport(op)
            } catch (e: ApiError) {
                if (!isFinalRefusal(e.status)) return SendOutcome.KeptForRetry
                withContext(NonCancellable + Dispatchers.IO) { synchronized(guard) { if (!dequeueLocked(id)) markDeliveredLocked(id) } }
                return SendOutcome.Refused(e.status, e.message)
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                return SendOutcome.KeptForRetry
            }
            return withContext(NonCancellable + Dispatchers.IO) {
                synchronized(guard) {
                    if (dequeueLocked(id)) SendOutcome.Sent
                    else {
                        markDeliveredLocked(id)
                        SendOutcome.SentButNotCleared
                    }
                }
            }
        } finally {
            synchronized(guard) { inFlight -= id }
        }
    }

    private fun markDeliveredLocked(id: String) {
        delivered += id
        _pendingCount.value = (_pendingCount.value - 1).coerceAtLeast(0)
    }

    data class ReplayResult(
        val sent: List<PendingOperation> = emptyList(),
        val refused: List<Pair<PendingOperation, ApiError>> = emptyList(),
        /** Accepted by the server but still in the stored queue file. */
        val notCleared: List<PendingOperation> = emptyList(),
        /** The stored queue could not be read: nothing was sent, nothing lost. */
        val unreadable: Boolean = false,
    )

    suspend fun replay(client: ApiClient): ReplayResult = replay(transport(client))

    /**
     * Replays the queue in order through [send]. A final 4xx drops the item (the
     * caller reports it as refused); anything else stops and keeps the rest. An
     * unreadable queue is reported, never treated as empty.
     */
    suspend fun replay(transport: suspend (PendingOperation) -> Unit): ReplayResult {
        val sent = mutableListOf<PendingOperation>()
        val refused = mutableListOf<Pair<PendingOperation, ApiError>>()
        val notCleared = mutableListOf<PendingOperation>()
        fun result(unreadable: Boolean = false) = ReplayResult(sent, refused, notCleared, unreadable)
        val queue = try { pendingChecked() } catch (e: QueueUnreadable) { return result(unreadable = true) }
        for (op in queue) {
            when (val outcome = send(op.id, transport)) {
                SendOutcome.Sent -> sent += op
                SendOutcome.SentButNotCleared -> { sent += op; notCleared += op }
                is SendOutcome.Refused -> refused += op to ApiError(outcome.status, outcome.message)
                SendOutcome.NotQueued -> continue
                // Another sender has this one: stop, so nothing after it reaches the server first.
                SendOutcome.AlreadySending, SendOutcome.KeptForRetry -> return result()
                SendOutcome.Unreadable -> return result(unreadable = true)
            }
        }
        return result()
    }

    companion object {
        /** The server said no for good. 401 (session), 408, 423 (PIN lock) and 429 are worth retrying. */
        fun isFinalRefusal(status: Int) = status in 400..499 && status !in setOf(401, 408, 423, 429)

        /** The Hub client as a transport. An unknown method can never be sent, so it is a final refusal. */
        fun transport(client: ApiClient): suspend (PendingOperation) -> Unit = { op ->
            val method = runCatching { ApiClient.Method.valueOf(op.method) }.getOrNull()
                ?: throw ApiError(400, "This saved change cannot be sent (unknown method ${op.method}).")
            client.send(ApiClient.Request(method, op.path, body = ApiClient.Body.Json(op.body)))
        }
    }

    suspend fun purge() = withContext(Dispatchers.IO) {
        synchronized(guard) { delivered.clear(); root.deleteRecursively() }
        Unit
    }
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
