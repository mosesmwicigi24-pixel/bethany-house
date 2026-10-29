package ke.co.bethanyhouse.hub.ui.shell

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Icon
import androidx.compose.material3.ListItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.NavCatalogue
import ke.co.bethanyhouse.hub.core.NavItem
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.delay

/**
 * Global search (the console's ⌘K palette): pages by name, then records via
 * `GET /v1/admin/search?q=` (orders, customers, products, people) when the
 * query is three characters or more.
 */
@Composable
fun CommandPalette(dismiss: () -> Unit) {
    val env = LocalEnv.current
    var query by remember { mutableStateOf("") }
    var hits by remember { mutableStateOf<List<Hit>>(emptyList()) }
    val focus = remember { FocusRequester() }
    val s = env.session.current
    val pages = remember(query) {
        val q = query.trim().lowercase()
        if (q.isEmpty()) emptyList() else NavCatalogue.allItems.filter { it.gate.allows(s.permissions) && it.label.lowercase().contains(q) }.take(8)
    }

    LaunchedEffect(query) {
        if (query.trim().length < 3) { hits = emptyList(); return@LaunchedEffect }
        delay(250)
        hits = try {
            env.client.get("/v1/admin/search", listOf("q" to query.trim())).json.let { j ->
                buildList {
                    j["orders"].records.forEach { r -> r["id"].int?.let { add(Hit("Order #${r["order_number"].str ?: it}", r["customer_name"].str ?: r["status"].str, Route.Order(it))) } }
                    j["customers"].records.forEach { r -> r["id"].int?.let { add(Hit(r["name"].str ?: "Customer", r["phone"].str, Route.Customer(it))) } }
                    j["products"].records.forEach { r -> r["id"].int?.let { add(Hit(r["name"].str ?: "Product", r["sku"].str, Route.Product(it))) } }
                    j["users"].records.forEach { r -> r["id"].int?.let { add(Hit(r["name"].str ?: "Person", r["email"].str, Route.People(it))) } }
                }
            }
        } catch (_: Exception) { emptyList() }
    }
    LaunchedEffect(Unit) { focus.requestFocus() }

    ModalBottomSheet(onDismissRequest = dismiss) {
        Column(Modifier.padding(horizontal = Space.l).heightIn(min = 320.dp)) {
            OutlinedTextField(value = query, onValueChange = { query = it }, placeholder = { Text("Search pages, orders, customers, products, people") }, singleLine = true, modifier = Modifier.fillMaxWidth().focusRequester(focus))
            LazyColumn(Modifier.padding(top = Space.m)) {
                if (pages.isNotEmpty()) item { Text("PAGES", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                items(pages) { p: NavItem -> ListItem(headlineContent = { Text(p.label) }, leadingContent = { Icon(iconFor(p.icon), contentDescription = null) }, modifier = Modifier.clickable { env.navigator.go(p.route); dismiss() }) }
                if (hits.isNotEmpty()) item { Text("RECORDS", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                items(hits) { h -> ListItem(headlineContent = { Text(h.title) }, supportingContent = { h.subtitle?.let { Text(it) } }, modifier = Modifier.clickable { env.navigator.go(h.route); dismiss() }) }
            }
        }
    }
}

private data class Hit(val title: String, val subtitle: String?, val route: Route)
