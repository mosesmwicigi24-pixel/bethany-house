package ke.co.bethanyhouse.hub.ui.features.production

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import ke.co.bethanyhouse.hub.core.OfflineStore
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.JsonObject
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import java.io.File

@RunWith(RobolectricTestRunner::class)
@Config(application = android.app.Application::class)
class TaskQueueParityTest {
    @Test fun `saved changes are scoped to the exact task and unreadable work is not empty`() = runBlocking {
        val context = ApplicationProvider.getApplicationContext<Context>()
        val userId = 987654
        val store = OfflineStore(context, userId)
        store.purge()
        try {
            assertTrue(store.enqueue(OfflineStore.PendingOperation(kind = "task_progress", method = "POST", path = "/v1/tailor/tasks/12/progress", body = JsonObject(emptyMap()))))
            assertTrue(taskHasPendingChanges(store, "12"))
            assertFalse(taskHasPendingChanges(store, "1"))
            assertFalse(taskHasPendingChanges(store, "13"))
            File(context.filesDir, "hub/user-$userId/pending-queue.json").writeText("not valid json")
            try {
                taskHasPendingChanges(store, "12")
                fail("An unreadable queue must not be treated as empty")
            } catch (_: OfflineStore.QueueUnreadable) { }
        } finally { store.purge() }
    }
}
