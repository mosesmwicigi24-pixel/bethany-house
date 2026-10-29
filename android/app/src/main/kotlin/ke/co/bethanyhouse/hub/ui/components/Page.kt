package ke.co.bethanyhouse.hub.ui.components

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch

/**
 * The page scaffold every screen uses: a title and subtitle, a scrolling
 * column with the phone gutter, a readable max width on tablets, and pull
 * to refresh when `refresh` is given.
 */
@Composable
fun Page(
    title: String,
    subtitle: String? = null,
    refresh: (suspend () -> Unit)? = null,
    actions: (@Composable () -> Unit)? = null,
    content: @Composable ColumnScope.() -> Unit,
) {
    var refreshing by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val body: @Composable () -> Unit = {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.TopCenter) {
            Column(
                Modifier.fillMaxWidth().widthIn(max = 1100.dp).verticalScroll(rememberScrollState()).padding(horizontal = Space.gutter, vertical = Space.l),
                verticalArrangement = Arrangement.spacedBy(Space.l),
            ) {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(title, style = MaterialTheme.typography.headlineMedium)
                        if (subtitle != null) Text(subtitle, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                    }
                    actions?.invoke()
                }
                content()
            }
        }
    }
    if (refresh == null) body() else PullToRefreshBox(
        isRefreshing = refreshing,
        onRefresh = { refreshing = true; scope.launch { try { refresh() } finally { refreshing = false } } },
    ) { body() }
}

/** Stat tiles that reflow by width. */
@Composable
fun TileGrid(minWidth: Dp = 160.dp, content: @Composable () -> Unit) {
    // A simple flow row keeps nesting inside the scrolling Page legal (LazyVerticalGrid would need a bounded height).
    androidx.compose.foundation.layout.FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.m), verticalArrangement = Arrangement.spacedBy(Space.m), modifier = Modifier.fillMaxWidth()) { content() }
}

val TileModifier: Modifier = Modifier.widthIn(min = 150.dp, max = 240.dp)
