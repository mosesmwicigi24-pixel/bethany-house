package ke.co.bethanyhouse.hub.ui.shell

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.Logout
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.Menu
import androidx.compose.material.icons.filled.MoreHoriz
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.DrawerValue
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.ListItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalDrawerSheet
import androidx.compose.material3.ModalNavigationDrawer
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationRail
import androidx.compose.material3.NavigationRailItem
import androidx.compose.material3.PermanentDrawerSheet
import androidx.compose.material3.PermanentNavigationDrawer
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.material3.adaptive.currentWindowAdaptiveInfo
import androidx.compose.material3.rememberDrawerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.window.core.layout.WindowWidthSizeClass
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.NavCatalogue
import ke.co.bethanyhouse.hub.core.NavItem
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.auth.SecurityPrompts
import ke.co.bethanyhouse.hub.ui.components.ToastHost
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch

/**
 * Phone: the console's bottom tab bar (Home, Messages, Me, Production,
 * Orders + More) with a back stack. Tablet (Samsung Tab, Step 7): a
 * navigation rail at medium width and a permanent sidebar at expanded
 * width, with the detail beside it, so the tablet is a workstation rather
 * than a large phone. The same [RouteScreen] renders in all three.
 */
@Composable
fun HubShell() {
    val env = LocalEnv.current
    val nav = env.navigator
    val snapshot by env.session.snapshot.collectAsStateWithLifecycle()
    val width = currentWindowAdaptiveInfo().windowSizeClass.windowWidthSizeClass
    LaunchedEffect(snapshot.home) { if (nav.selection == null) nav.selection = snapshot.home }
    LaunchedEffect(Unit) { env.unreadNotifications = 0 }

    BackHandler(enabled = nav.path.isNotEmpty() || nav.searchPresented || nav.drawerOpen) {
        when {
            nav.searchPresented -> nav.searchPresented = false
            nav.drawerOpen -> nav.drawerOpen = false
            else -> nav.pop()
        }
    }

    Box(Modifier.fillMaxSize()) {
        when (width) {
            WindowWidthSizeClass.COMPACT -> PhoneShell()
            WindowWidthSizeClass.MEDIUM -> RailShell()
            else -> SidebarShell()
        }
        Box(Modifier.align(Alignment.BottomCenter).padding(bottom = 72.dp)) { ToastHost(env.toasts) }
        if (nav.searchPresented) CommandPalette { nav.searchPresented = false }
        SecurityPrompts()
    }
}

@Composable
private fun CurrentScreen(modifier: Modifier = Modifier) {
    val env = LocalEnv.current
    val route = env.navigator.current ?: env.session.current.home
    Box(modifier.fillMaxSize().background(MaterialTheme.hub.canvas)) {
        // Keyed so a different route gets fresh state while the same route keeps it.
        androidx.compose.runtime.key(route) { RouteScreen(route) }
    }
}

@Composable
private fun PhoneShell() {
    val env = LocalEnv.current
    val nav = env.navigator
    val s = env.session.current
    val tabs = NavCatalogue.phoneTabs(s.permissions, s.home)
    val drawer = rememberDrawerState(DrawerValue.Closed)
    val scope = rememberCoroutineScope()
    LaunchedEffect(nav.drawerOpen) { if (nav.drawerOpen) drawer.open() else drawer.close() }
    LaunchedEffect(drawer.isClosed) { if (drawer.isClosed) nav.drawerOpen = false }

    ModalNavigationDrawer(
        drawerState = drawer,
        gesturesEnabled = nav.path.isEmpty(),
        drawerContent = { ModalDrawerSheet(drawerContainerColor = Palette.nav) { SidebarContent() } },
    ) {
        Scaffold(
            containerColor = MaterialTheme.hub.canvas,
            topBar = { HubTopBar(showMenu = true, onMenu = { scope.launch { drawer.open() } }) },
            bottomBar = {
                if (nav.path.isEmpty()) NavigationBar(containerColor = MaterialTheme.hub.card) {
                    tabs.forEach { tab ->
                        val selected = nav.selection == tab.route
                        NavigationBarItem(
                            selected = selected,
                            onClick = { nav.go(tab.route) },
                            icon = { NavIcon(tab, badge = if (tab.route == Route.Notifications) env.unreadNotifications else 0) },
                            label = { Text(tab.label, maxLines = 1, overflow = TextOverflow.Ellipsis) },
                        )
                    }
                    NavigationBarItem(selected = false, onClick = { nav.drawerOpen = true }, icon = { Icon(Icons.Default.MoreHoriz, contentDescription = null) }, label = { Text("More") })
                }
            },
        ) { padding -> CurrentScreen(Modifier.padding(padding)) }
    }
}

@Composable
private fun RailShell() {
    val env = LocalEnv.current
    val nav = env.navigator
    val s = env.session.current
    val tabs = NavCatalogue.phoneTabs(s.permissions, s.home)
    val drawer = rememberDrawerState(DrawerValue.Closed)
    val scope = rememberCoroutineScope()
    LaunchedEffect(nav.drawerOpen) { if (nav.drawerOpen) drawer.open() else drawer.close() }
    LaunchedEffect(drawer.isClosed) { if (drawer.isClosed) nav.drawerOpen = false }
    ModalNavigationDrawer(drawerState = drawer, drawerContent = { ModalDrawerSheet(drawerContainerColor = Palette.nav) { SidebarContent() } }) {
        Row(Modifier.fillMaxSize()) {
            NavigationRail(containerColor = Palette.nav, contentColor = Color.White) {
                IconButton(onClick = { scope.launch { drawer.open() } }) { Icon(Icons.Default.Menu, contentDescription = "All pages", tint = Color.White) }
                Spacer(Modifier.padding(4.dp))
                tabs.forEach { tab ->
                    NavigationRailItem(
                        selected = nav.selection == tab.route,
                        onClick = { nav.go(tab.route) },
                        icon = { NavIcon(tab, badge = if (tab.route == Route.Notifications) env.unreadNotifications else 0) },
                        label = { Text(tab.label, maxLines = 1) },
                        colors = androidx.compose.material3.NavigationRailItemDefaults.colors(selectedIconColor = Palette.nav, selectedTextColor = Color.White, unselectedIconColor = Color(0xFFB7C0D3), unselectedTextColor = Color(0xFFB7C0D3), indicatorColor = Palette.brand),
                    )
                }
                Spacer(Modifier.weight(1f))
                IconButton(onClick = { nav.searchPresented = true }) { Icon(Icons.Default.Search, contentDescription = "Search", tint = Color.White) }
            }
            Scaffold(containerColor = MaterialTheme.hub.canvas, topBar = { HubTopBar(showMenu = false, onMenu = {}) }) { padding -> CurrentScreen(Modifier.padding(padding)) }
        }
    }
}

@Composable
private fun SidebarShell() {
    PermanentNavigationDrawer(drawerContent = { PermanentDrawerSheet(Modifier.width(260.dp), drawerContainerColor = Palette.nav) { SidebarContent() } }) {
        Scaffold(containerColor = MaterialTheme.hub.canvas, topBar = { HubTopBar(showMenu = false, onMenu = {}) }) { padding -> CurrentScreen(Modifier.padding(padding)) }
    }
}

@Composable
private fun HubTopBar(showMenu: Boolean, onMenu: () -> Unit) {
    val env = LocalEnv.current
    val nav = env.navigator
    val route = nav.current
    val title = route?.let { NavCatalogue.item(it)?.label } ?: route?.let { NavCatalogue.item(it.parent ?: it)?.label } ?: "Hub"
    TopAppBar(
        title = { Text(title, maxLines = 1, overflow = TextOverflow.Ellipsis) },
        navigationIcon = {
            if (nav.path.isNotEmpty()) IconButton(onClick = { nav.pop() }) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back") }
            else if (showMenu) IconButton(onClick = onMenu) { Icon(Icons.Default.Menu, contentDescription = "Menu") }
        },
        actions = {
            IconButton(onClick = { nav.searchPresented = true }) { Icon(Icons.Default.Search, contentDescription = "Search") }
            BadgedBox(badge = { if (env.unreadNotifications > 0) Badge { Text(env.unreadNotifications.toString()) } }) {
                IconButton(onClick = { nav.go(Route.Notifications) }) { Icon(Icons.Default.Notifications, contentDescription = "Notifications") }
            }
            if (env.pendingCount > 0) Text("${env.pendingCount} queued", style = MaterialTheme.typography.labelSmall, color = Palette.amber, modifier = Modifier.padding(end = 8.dp))
        },
        colors = TopAppBarDefaults.topAppBarColors(containerColor = MaterialTheme.hub.canvas),
    )
}

/** The full catalogue, grouped, as the console sidebar (Sidebar.tsx). */
@Composable
private fun SidebarContent() {
    val env = LocalEnv.current
    val nav = env.navigator
    val s = env.session.current
    val scope = rememberCoroutineScope()
    val groups = NavCatalogue.groups.mapNotNull { it.visible(s.permissions) }
    val dim = Color(0xFFB7C0D3)
    Column(Modifier.fillMaxHeight()) {
        Row(Modifier.padding(Space.l), verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.width(32.dp).padding(end = 0.dp).background(Palette.brand, MaterialTheme.shapes.extraSmall).padding(6.dp), contentAlignment = Alignment.Center) { Text("BH", style = MaterialTheme.typography.labelSmall, color = Color.White) }
            Spacer(Modifier.width(Space.m))
            Column { Text("Bethany House", style = MaterialTheme.typography.titleSmall, color = Color.White); Text(s.me?.outlet?.name ?: "Hub", style = MaterialTheme.typography.labelSmall, color = dim) }
        }
        LazyColumn(Modifier.weight(1f)) {
            groups.forEach { group ->
                item { Text(group.label.uppercase(), style = MaterialTheme.typography.labelSmall, color = dim, modifier = Modifier.padding(start = Space.l, top = Space.m, bottom = 4.dp)) }
                items(group.items) { item -> SidebarRow(item, selected = nav.selection == item.route) { nav.go(item.route) } }
            }
            item { HorizontalDivider(color = Palette.navBorder, modifier = Modifier.padding(vertical = Space.s)) }
            items(NavCatalogue.userMenu) { item -> SidebarRow(item, selected = nav.selection == item.route) { nav.go(item.route) } }
            item {
                ListItem(
                    headlineContent = { Text("Sign out", color = Color(0xFFFCA5A5)) },
                    leadingContent = { Icon(Icons.AutoMirrored.Filled.Logout, contentDescription = null, tint = Color(0xFFFCA5A5)) },
                    colors = androidx.compose.material3.ListItemDefaults.colors(containerColor = Color.Transparent),
                    modifier = Modifier.clickable { scope.launch { env.session.signOut() } },
                )
            }
        }
        Text("${s.me?.name ?: ""} · ${s.me?.roleNames?.joinToString() ?: ""}", style = MaterialTheme.typography.labelSmall, color = dim, maxLines = 2, modifier = Modifier.padding(Space.l))
    }
}

@Composable
private fun SidebarRow(item: NavItem, selected: Boolean, onClick: () -> Unit) {
    Surface(
        color = if (selected) Palette.brand.copy(alpha = 0.18f) else Color.Transparent,
        shape = MaterialTheme.shapes.small,
        modifier = Modifier.fillMaxWidth().padding(horizontal = Space.s, vertical = 1.dp).clickable(onClick = onClick),
    ) {
        Row(Modifier.padding(horizontal = Space.m, vertical = 9.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(iconFor(item.icon), contentDescription = null, tint = if (selected) Palette.brandTint else Color(0xFFB7C0D3), modifier = Modifier.width(20.dp))
            Spacer(Modifier.width(Space.m))
            Text(item.label, style = MaterialTheme.typography.bodyMedium, color = if (selected) Color.White else Color(0xFFE2E6EF), maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
    }
}

@Composable
private fun NavIcon(item: NavItem, badge: Int) {
    BadgedBox(badge = { if (badge > 0) Badge { Text(badge.toString()) } }) { Icon(iconFor(item.icon), contentDescription = item.label) }
}
