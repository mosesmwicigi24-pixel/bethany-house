package ke.co.bethanyhouse.hub.ui.shell

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowForwardIos
import androidx.compose.material.icons.automirrored.filled.Logout
import androidx.compose.material.icons.filled.TextFields
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.OfflineStore
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.ui.LocalTailorsTextSize
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.TailorsTextSizeDialog
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch

@Composable
internal fun TailorsMore(tabRoutes: Set<Route>, open: (Route) -> Unit, modifier: Modifier = Modifier) {
    val env = LocalEnv.current
    val session = env.session.current
    val scope = rememberCoroutineScope()
    val settings = LocalTailorsTextSize.current
    var showSettings by remember { mutableStateOf(false) }
    var unsent by remember { mutableIntStateOf(0) }
    if (showSettings && settings != null) TailorsTextSizeDialog(settings) { showSettings = false }
    if (unsent != 0) AlertDialog(
        onDismissRequest = { unsent = 0 },
        title = { Text("Changes not sent yet") },
        text = { Text(if (unsent < 0) "Saved changes could not be read. They stay on this device. Ask a manager for help before signing out." else "$unsent saved ${if (unsent == 1) "change has" else "changes have"} not reached the Hub. They stay on this device and will be sent the next time you sign in here.") },
        confirmButton = { TextButton(onClick = { scope.launch { unsent = 0; env.session.signOut() } }) { Text("Sign out anyway") } },
        dismissButton = { TextButton(onClick = { unsent = 0 }) { Text("Stay signed in") } },
    )
    val groups = session.flavor.groups(session.permissions)
    val ordered = groups.filter { it.label == "Production" } + groups.filter { it.label != "Production" }
    val personal = setOf(Route.MyShifts, Route.MyLeave, Route.MyTimesheet, Route.MyPayslips, Route.MyAdvances)
    Column(modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = Space.gutter, vertical = Space.l), verticalArrangement = Arrangement.spacedBy(20.dp)) {
        ordered.forEach { group ->
            val items = group.items.filter { it.route !in tabRoutes }
            val sections = if (group.label == "Me") listOf("My workday" to items.filter { it.route !in personal }, "Time & pay" to items.filter { it.route in personal })
                else listOf(group.label to items)
            sections.filter { it.second.isNotEmpty() }.forEach { (label, rows) ->
                MoreSection(label) { rows.forEachIndexed { index, item ->
                    if (index > 0) HorizontalDivider(Modifier.padding(start = 56.dp, end = 18.dp), color = MaterialTheme.hub.line)
                    MoreRow(item.label, iconFor(item.icon)) { open(item.route) }
                } }
            }
        }
        if (settings != null) MoreSection("App settings") { MoreRow("Text size", Icons.Default.TextFields) { showSettings = true } }
        MoreSection(session.me?.name ?: "Account") {
            session.flavor.userMenu.forEach { item -> MoreRow(item.label, iconFor(item.icon)) { open(item.route) } }
            if (session.flavor.userMenu.isNotEmpty()) HorizontalDivider(Modifier.padding(start = 56.dp, end = 18.dp), color = MaterialTheme.hub.line)
            MoreRow("Sign out", Icons.AutoMirrored.Filled.Logout) {
                scope.launch {
                    val waiting = try { env.offline?.pendingChecked()?.size ?: 0 } catch (e: OfflineStore.QueueUnreadable) { -1 }
                    if (waiting != 0) unsent = waiting else env.session.signOut()
                }
            }
        }
    }
}

@Composable
private fun MoreSection(title: String, content: @Composable ColumnScope.() -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(title, style = MaterialTheme.typography.titleSmall, color = MaterialTheme.hub.textSecondary, modifier = Modifier.padding(horizontal = 12.dp))
        Surface(shape = MaterialTheme.shapes.large, color = MaterialTheme.hub.card, modifier = Modifier.fillMaxWidth()) {
            Column(content = content)
        }
    }
}

@Composable
private fun MoreRow(label: String, icon: ImageVector, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clickable(onClick = onClick).defaultMinSize(minHeight = 56.dp).padding(horizontal = 18.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(16.dp)) {
        Icon(icon, contentDescription = null, tint = Palette.brandDeep, modifier = Modifier.size(22.dp))
        Text(label, modifier = Modifier.weight(1f), style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.hub.text)
        Icon(Icons.AutoMirrored.Filled.ArrowForwardIos, contentDescription = null, tint = MaterialTheme.hub.textTertiary, modifier = Modifier.size(16.dp))
    }
}
