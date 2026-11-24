package ke.co.bethanyhouse.hub.core

import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.async
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import org.junit.After
import org.junit.Before
import org.junit.Test
import java.io.File
import java.nio.file.Files
import java.util.Collections
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertFalse
import kotlin.test.assertTrue

/** The offline queue's sending rules, matching apple/Tests/BethanyKitTests/OfflineStoreTests.swift. */
class OfflineStoreTest {
    private lateinit var dir: File
    private lateinit var store: OfflineStore
    private val queueFile get() = File(dir, "pending-queue.json")

    @Before fun setUp() { dir = Files.createTempDirectory("offline").toFile(); store = OfflineStore(dir) }
    @After fun tearDown() { dir.deleteRecursively() }

    private fun op(kind: String = "punch", key: String? = null, at: String = "2026-11-24T09:54:54Z") = OfflineStore.PendingOperation(
        kind = kind, method = "POST", path = "/v1/me/attendance/clock-in",
        body = buildJsonObject { put("recorded_at", JsonPrimitive(at)) }, dedupeKey = key,
    )

    /** Records every call; fails with [failWith] when set. */
    private class Recorder(var failWith: Exception? = null) {
        val sent: MutableList<OfflineStore.PendingOperation> = Collections.synchronizedList(mutableListOf())
        val transport: suspend (OfflineStore.PendingOperation) -> Unit = { o -> failWith?.let { throw it }; sent += o }
    }

    @Test fun `an offline punch is sent once with its original time`() = runBlocking {
        val punch = op(key = "punch")
        assertTrue(store.enqueue(punch))
        val r = Recorder()
        val first = store.replay(r.transport)
        val second = store.replay(r.transport)
        assertEquals(listOf(punch.id), first.sent.map { it.id })
        assertTrue(second.sent.isEmpty())
        assertEquals(1, r.sent.size)
        assertEquals(punch.body, r.sent.single().body)
        assertEquals(punch.recordedAt, r.sent.single().recordedAt)
        assertTrue(store.pendingChecked().isEmpty())
        assertEquals(0, store.pendingCount.value)
    }

    @Test fun `replay sends in order and stops at the first temporary failure`() = runBlocking {
        val a = op("a"); val b = op("b")
        store.enqueue(a); store.enqueue(b)
        val r = Recorder(ApiError(500, "Server error"))
        val result = store.replay(r.transport)
        assertTrue(result.sent.isEmpty() && result.refused.isEmpty())
        assertEquals(listOf(a.id, b.id), store.pendingChecked().map { it.id })
    }

    @Test fun `only a final refusal drops an operation`() = runBlocking {
        for (status in listOf(0, 401, 408, 423, 429, 500, 503)) {
            val o = op(key = "punch"); store.enqueue(o)
            assertEquals(OfflineStore.SendOutcome.KeptForRetry, store.send(o.id, Recorder(ApiError(status, "no")).transport), "status $status")
            assertEquals(listOf(o.id), store.pendingChecked().map { it.id }, "status $status kept")
        }
        for (status in listOf(400, 403, 404, 409, 422)) {
            val o = op(key = "punch"); store.enqueue(o)
            assertEquals(OfflineStore.SendOutcome.Refused(status, "no"), store.send(o.id, Recorder(ApiError(status, "no")).transport))
            assertTrue(store.pendingChecked().isEmpty(), "status $status dropped")
        }
    }

    @Test fun `a non-API failure keeps the operation`() = runBlocking {
        val o = op(); store.enqueue(o)
        assertEquals(OfflineStore.SendOutcome.KeptForRetry, store.send(o.id, Recorder(java.io.IOException("reset")).transport))
        assertEquals(1, store.pendingChecked().size)
    }

    @Test fun `replay and Send now never send the same operation twice`() = runBlocking {
        val o = op(key = "punch"); store.enqueue(o)
        val started = CompletableDeferred<Unit>(); val gate = CompletableDeferred<Unit>()
        var calls = 0
        val slow: suspend (OfflineStore.PendingOperation) -> Unit = { calls++; started.complete(Unit); gate.await() }
        val replay = async(kotlinx.coroutines.Dispatchers.Default) { store.replay(slow) }
        started.await()
        // "Send now" while the replay has it in flight: told so, nothing sent.
        val sendNow = Recorder()
        val outcome = store.send(o.id, sendNow.transport)
        gate.complete(Unit)
        assertEquals(OfflineStore.SendOutcome.AlreadySending, outcome)
        assertEquals(listOf(o.id), replay.await().sent.map { it.id })
        assertEquals(OfflineStore.SendOutcome.NotQueued, store.send(o.id, sendNow.transport))
        assertEquals(1, calls)
        assertTrue(sendNow.sent.isEmpty())
    }

    @Test fun `a sent operation whose removal failed is not sent again`() = runBlocking {
        val o = op(key = "punch"); store.enqueue(o)
        val saved = queueFile.readText()
        // Accepted by the server, then the queue file becomes unreadable/unwritable.
        val breaking: suspend (OfflineStore.PendingOperation) -> Unit = { queueFile.delete(); File(queueFile, "x").apply { parentFile.mkdirs(); writeText("") } }
        assertEquals(OfflineStore.SendOutcome.SentButNotCleared, store.send(o.id, breaking))
        // The file comes back still holding the sent punch.
        queueFile.deleteRecursively(); queueFile.writeText(saved)
        val r = Recorder()
        assertEquals(OfflineStore.SendOutcome.NotQueued, store.send(o.id, r.transport))
        assertTrue(store.replay(r.transport).sent.isEmpty())
        assertTrue(store.pendingChecked().isEmpty())
        assertTrue(r.sent.isEmpty())
        // The next successful write drops it from disk.
        val next = op("todo"); assertTrue(store.enqueue(next))
        assertFalse(queueFile.readText().contains(o.id))
        assertTrue(queueFile.readText().contains(next.id))
    }

    @Test fun `an unreadable queue is never treated as empty or overwritten`() = runBlocking {
        queueFile.writeText("{ not a queue")
        assertFailsWith<OfflineStore.QueueUnreadable> { store.pendingChecked() }
        assertTrue(store.pendingUnreadable.value)
        assertTrue(store.pending().isEmpty())
        assertTrue(store.replay(Recorder().transport).unreadable)
        assertFalse(store.enqueue(op()))
        assertEquals(OfflineStore.SendOutcome.Unreadable, store.send("any", Recorder().transport))
        assertEquals("{ not a queue", queueFile.readText())
    }

    @Test fun `a newer punch replaces the waiting one`() = runBlocking {
        val first = op(key = "punch"); val second = op(key = "punch", at = "2026-11-24T10:00:00Z")
        store.enqueue(first); store.enqueue(second)
        assertEquals(listOf(second.id), store.pendingChecked().map { it.id })
        assertEquals(OfflineStore.SendOutcome.NotQueued, store.send(first.id, Recorder().transport))
    }

    @Test fun `final refusals match the iOS rule`() {
        assertTrue(listOf(400, 403, 404, 409, 422).all(OfflineStore::isFinalRefusal))
        assertFalse(listOf(0, 401, 408, 423, 429, 500, 503).any(OfflineStore::isFinalRefusal))
    }
}
