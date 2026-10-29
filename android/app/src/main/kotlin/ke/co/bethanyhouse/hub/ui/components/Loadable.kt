package ke.co.bethanyhouse.hub.ui.components

import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshots.SnapshotStateList
import androidx.compose.runtime.mutableStateListOf
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Paginated
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement

/** The state of anything fetched from the Hub, so every screen shows the same skeleton, empty and error states. */
sealed class Loadable<out T> {
    data object Idle : Loadable<Nothing>()
    data class Loading<T>(val previous: T?) : Loadable<T>()
    data class Loaded<T>(val value: T) : Loadable<T>()
    data class Failed<T>(val error: ApiError, val previous: T?) : Loadable<T>()

    val current: T?
        get() = when (this) {
            is Loaded -> value
            is Loading -> previous
            is Failed -> previous
            Idle -> null
        }
    val isLoading: Boolean get() = this is Loading
    val failure: ApiError? get() = (this as? Failed)?.error
}

/** Runs a fetch into a [Loadable], keeping the previous value on screen while reloading. */
class Resource<T>(private val scope: CoroutineScope, private val fetch: suspend () -> T) {
    var state: Loadable<T> by mutableStateOf(Loadable.Idle)
        private set
    private var job: Job? = null
    val value: T? get() = state.current

    fun load() { job?.cancel(); job = scope.launch { run() } }
    suspend fun reload() = run()
    fun set(v: T) { state = Loadable.Loaded(v) }

    private suspend fun run() {
        state = Loadable.Loading(state.current)
        try {
            state = Loadable.Loaded(fetch())
        } catch (e: ApiError) {
            state = Loadable.Failed(e, state.current)
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            state = Loadable.Failed(ApiError.transport(e.message ?: "Error"), state.current)
        }
    }
}

/** A paginated list from a Laravel paginator: loads page 1, appends on demand. */
class PagedList<T>(
    private val scope: CoroutineScope,
    private val client: ApiClient,
    private val path: String,
    var query: MutableMap<String, String?> = mutableMapOf(),
    private val map: (JsonElement) -> T,
    var perPage: Int = 25,
) {
    val items: SnapshotStateList<T> = mutableStateListOf()
    var state: Loadable<List<T>> by mutableStateOf(Loadable.Idle)
        private set
    var total: Int by mutableStateOf(0)
        private set
    var isLoadingMore: Boolean by mutableStateOf(false)
        private set
    private var nextPage: Int? = null
    private var generation = 0
    val hasMore: Boolean get() = nextPage != null

    fun load() { scope.launch { reload() } }

    suspend fun reload() {
        val gen = ++generation
        state = Loadable.Loading(items.toList().takeIf { it.isNotEmpty() })
        try {
            val page = fetchPage(1)
            if (gen != generation) return
            items.clear(); items.addAll(page.data)
            total = page.total; nextPage = page.nextPage
            state = Loadable.Loaded(items.toList())
        } catch (e: ApiError) {
            if (gen != generation) return
            state = Loadable.Failed(e, items.toList().takeIf { it.isNotEmpty() })
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            if (gen != generation) return
            state = Loadable.Failed(ApiError.transport(e.message ?: "Error"), items.toList().takeIf { it.isNotEmpty() })
        }
    }

    fun loadMore() {
        val page = nextPage ?: return
        if (isLoadingMore) return
        isLoadingMore = true
        scope.launch {
            try {
                val p = fetchPage(page)
                items.addAll(p.data); nextPage = p.nextPage
                state = Loadable.Loaded(items.toList())
            } catch (_: ApiError) {
            } finally { isLoadingMore = false }
        }
    }

    private suspend fun fetchPage(page: Int): Paginated<T> {
        val q = query.entries.mapNotNull { (k, v) -> v?.let { k to it } } + listOf("page" to page.toString(), "per_page" to perPage.toString())
        return Paginated.from(client.get(path, q).json, map)
    }

    fun remove(predicate: (T) -> Boolean) { items.removeAll(predicate); state = Loadable.Loaded(items.toList()) }
    fun replace(index: Int, item: T) { if (index in items.indices) { items[index] = item; state = Loadable.Loaded(items.toList()) } }
}
