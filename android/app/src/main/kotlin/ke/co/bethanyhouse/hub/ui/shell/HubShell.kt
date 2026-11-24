package ke.co.bethanyhouse.hub.ui.shell

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.Image
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.Logout
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.ContentCut
import androidx.compose.material.icons.filled.Menu
import androidx.compose.material.icons.filled.MoreHoriz
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.TextFields
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
import androidx.compose.material3.NavigationBarItemDefaults
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
import androidx.compose.runtime.setValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.material3.TextButton
import androidx.compose.material3.AlertDialog
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.draw.clip
import androidx.compose.ui.res.painterResource
import androidx.compose.foundation.shape.RoundedCornerShape
import ke.co.bethanyhouse.hub.R
import ke.co.bethanyhouse.hub.ui.LocalTailorsTextSize
import ke.co.bethanyhouse.hub.ui.TailorsTextSizeDialog
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.window.core.layout.WindowWidthSizeClass
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.OfflineStore
import ke.co.bethanyhouse.hub.core.AppFlavor
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
    if (!snapshot.mayUseApp) { WorkshopOnlyScreen(); return }
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
    val tabs = s.flavor.phoneTabs(s.permissions, s.home)
    var moreOpen by remember { mutableStateOf(false) }
    LaunchedEffect(nav.selection) { moreOpen = false }
    BackHandler(enabled = moreOpen && nav.path.isEmpty()) { moreOpen = false }
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
            topBar = { HubTopBar(showMenu = !(moreOpen && nav.path.isEmpty()) && !(s.flavor == AppFlavor.TAILORS && nav.current == Route.ProductionMyTasks), onMenu = { scope.launch { drawer.open() } }, titleOverride = if (moreOpen && nav.path.isEmpty()) "More" else null) },
            bottomBar = {
                if (nav.path.isEmpty() || s.flavor == AppFlavor.TAILORS) {
                  val bar: @Composable () -> Unit = { NavigationBar(containerColor = if (s.flavor == AppFlavor.TAILORS) Color.Transparent else MaterialTheme.hub.card, windowInsets = if (s.flavor == AppFlavor.TAILORS) WindowInsets(0, 0, 0, 0) else androidx.compose.material3.NavigationBarDefaults.windowInsets) {
                    val itemColors = if (s.flavor == AppFlavor.TAILORS) NavigationBarItemDefaults.colors(
                        selectedIconColor = MaterialTheme.colorScheme.onPrimaryContainer,
                        selectedTextColor = MaterialTheme.colorScheme.onPrimaryContainer,
                        indicatorColor = MaterialTheme.hub.canvas,
                        unselectedIconColor = MaterialTheme.hub.textSecondary,
                        unselectedTextColor = MaterialTheme.hub.textSecondary,
                    ) else NavigationBarItemDefaults.colors()
                    tabs.forEach { tab ->
                        val selected = !moreOpen && (if (nav.current is Route.Comms) tab.route is Route.Comms else nav.selection == tab.route)
                        NavigationBarItem(
                            selected = selected,
                            onClick = { moreOpen = false; nav.go(tab.route) },
                            icon = { NavIcon(tab, badge = if (tab.route == Route.Notifications) env.unreadNotifications else 0) },
                            label = { Text(tab.label, maxLines = 1, overflow = TextOverflow.Ellipsis) },
                            colors = itemColors,
                        )
                    }
                    NavigationBarItem(selected = moreOpen, onClick = { if (s.flavor == AppFlavor.TAILORS) { nav.path.clear(); moreOpen = true } else nav.drawerOpen = true }, icon = { Icon(Icons.Default.MoreHoriz, contentDescription = null) }, label = { Text("More") }, colors = itemColors)
                  } }
                  if (s.flavor == AppFlavor.TAILORS) {
                      Box(Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 16.dp, vertical = 6.dp)) {
                          Surface(shape = RoundedCornerShape(48.dp), color = MaterialTheme.hub.card, border = androidx.compose.foundation.BorderStroke(1.dp, MaterialTheme.hub.line)) { bar() }
                      }
                  } else bar()
                }
            },
        ) { padding ->
            if (s.flavor == AppFlavor.TAILORS && moreOpen && nav.path.isEmpty()) TailorsMore(tabs.map { it.route }.toSet(), open = { nav.push(it) }, modifier = Modifier.padding(padding))
            else CurrentScreen(Modifier.padding(padding))
        }
    }
}

@Composable
private fun RailShell() {
    val env = LocalEnv.current
    val nav = env.navigator
    val s = env.session.current
    val tabs = s.flavor.phoneTabs(s.permissions, s.home)
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
                if (s.flavor == AppFlavor.HUB) IconButton(onClick = { nav.searchPresented = true }) { Icon(Icons.Default.Search, contentDescription = "Search", tint = Color.White) }
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
private fun HubTopBar(showMenu: Boolean, onMenu: () -> Unit, titleOverride: String? = null) {
    val env = LocalEnv.current
    val nav = env.navigator
    val route = nav.current
    val flavor = env.session.current.flavor
    val tailorHome = titleOverride == null && flavor == AppFlavor.TAILORS && route == Route.ProductionMyTasks && nav.path.isEmpty()
    val title = titleOverride ?: route?.let { NavCatalogue.item(it)?.label } ?: route?.let { NavCatalogue.item(it.parent ?: it)?.label } ?: flavor.appName
    TopAppBar(
        title = {
            if (tailorHome) Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically) {
                Image(painterResource(R.drawable.tailors_mark), contentDescription = null, modifier = Modifier.size(36.dp).clip(RoundedCornerShape(9.dp)))
                Spacer(Modifier.width(9.dp))
                Text("Tailors Hub", style = MaterialTheme.typography.titleMedium, maxLines = 1)
            } else Text(title, modifier = if (titleOverride != null) Modifier.fillMaxWidth() else Modifier, textAlign = if (titleOverride != null) TextAlign.Center else TextAlign.Start, maxLines = 1, overflow = TextOverflow.Ellipsis)
        },
        navigationIcon = {
            if (nav.path.isNotEmpty()) IconButton(onClick = { nav.pop() }) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back") }
            else if (showMenu) IconButton(onClick = onMenu) { Icon(Icons.Default.Menu, contentDescription = "Menu") }
        },
        actions = {
            // Search (orders, customers, products) and the notifications page are Bethany Hub's only.
            if (flavor == AppFlavor.HUB) {
                IconButton(onClick = { nav.searchPresented = true }) { Icon(Icons.Default.Search, contentDescription = "Search") }
                BadgedBox(badge = { if (env.unreadNotifications > 0) Badge { Text(env.unreadNotifications.toString()) } }) {
                    IconButton(onClick = { nav.go(Route.Notifications) }) { Icon(Icons.Default.Notifications, contentDescription = "Notifications") }
                }
            }
            if (env.pendingUnreadable) Text("saved changes unreadable", style = MaterialTheme.typography.labelSmall, color = Palette.danger, modifier = Modifier.padding(end = 8.dp))
            else if (env.pendingCount > 0) Text("${env.pendingCount} queued", style = MaterialTheme.typography.labelSmall, color = Palette.amber, modifier = Modifier.padding(end = 8.dp))
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
    val groups = s.flavor.groups(s.permissions)
    val dim = Color(0xFFB7C0D3)
    val textSize = LocalTailorsTextSize.current
    var showTextSize by remember { mutableStateOf(false) }
    if (showTextSize && textSize != null) TailorsTextSizeDialog(textSize) { showTextSize = false }
    // Changes saved offline wait in this person's own store on the device and
    // are sent the next time they sign in here; never under someone else.
    // Say so before signing out, so nobody leaves thinking their work is in.
    var unsentAtSignOut by remember { mutableIntStateOf(0) }
    fun signOut() = scope.launch {
        val waiting = try { env.offline?.pendingChecked()?.size ?: 0 } catch (e: OfflineStore.QueueUnreadable) { -1 }
        if (waiting != 0 && unsentAtSignOut == 0) unsentAtSignOut = waiting else env.session.signOut()
    }
    if (unsentAtSignOut != 0) AlertDialog(
        onDismissRequest = { unsentAtSignOut = 0 },
        title = { Text("Changes not sent yet") },
        text = {
            Text(
                if (unsentAtSignOut < 0) "Changes you made offline are saved on this device but could not be read, so it is not known what has reached the Hub. " +
                    "Signing out keeps them on this device. Ask a manager for help before signing out." else
                (if (unsentAtSignOut == 1) "1 change you made offline has" else "$unsentAtSignOut changes you made offline have") +
                    " not reached the Hub. They stay on this device and are sent the next time you sign in here. " +
                    "To send them now, wait for a connection before signing out.",
            )
        },
        confirmButton = { TextButton(onClick = { scope.launch { unsentAtSignOut = 0; env.session.signOut() } }) { Text("Sign out anyway") } },
        dismissButton = { TextButton(onClick = { unsentAtSignOut = 0 }) { Text("Stay signed in") } },
    )
    Column(Modifier.fillMaxHeight()) {
        Row(Modifier.padding(Space.l), verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.width(32.dp).padding(end = 0.dp).background(Palette.brand, MaterialTheme.shapes.extraSmall).padding(6.dp), contentAlignment = Alignment.Center) { Text(if (s.flavor == AppFlavor.TAILORS) "TH" else "BH", style = MaterialTheme.typography.labelSmall, color = Color.White) }
            Spacer(Modifier.width(Space.m))
            Column { Text(if (s.flavor == AppFlavor.TAILORS) "Tailors Hub" else "Bethany House", style = MaterialTheme.typography.titleSmall, color = Color.White); Text(s.me?.outlet?.name ?: "Hub", style = MaterialTheme.typography.labelSmall, color = dim) }
        }
        LazyColumn(Modifier.weight(1f)) {
            groups.forEach { group ->
                item { Text(group.label.uppercase(), style = MaterialTheme.typography.labelSmall, color = dim, modifier = Modifier.padding(start = Space.l, top = Space.m, bottom = 4.dp)) }
                items(group.items) { item -> SidebarRow(item, selected = nav.selection == item.route) { nav.go(item.route) } }
            }
            item { HorizontalDivider(color = Palette.navBorder, modifier = Modifier.padding(vertical = Space.s)) }
            items(s.flavor.userMenu) { item -> SidebarRow(item, selected = nav.selection == item.route) { nav.go(item.route) } }
            if (textSize != null) item {
                ListItem(
                    headlineContent = { Text("Text size", color = Color(0xFFE2E6EF)) },
                    leadingContent = { Icon(Icons.Default.TextFields, contentDescription = null, tint = dim) },
                    colors = androidx.compose.material3.ListItemDefaults.colors(containerColor = Color.Transparent),
                    modifier = Modifier.clickable { showTextSize = true },
                )
            }
            item {
                ListItem(
                    headlineContent = { Text("Sign out", color = Color(0xFFFCA5A5)) },
                    leadingContent = { Icon(Icons.AutoMirrored.Filled.Logout, contentDescription = null, tint = Color(0xFFFCA5A5)) },
                    colors = androidx.compose.material3.ListItemDefaults.colors(containerColor = Color.Transparent),
                    modifier = Modifier.clickable { signOut() },
                )
            }
        }
        Text("${s.me?.name ?: ""} · ${s.me?.roleNames?.joinToString() ?: ""}", style = MaterialTheme.typography.labelSmall, color = dim, maxLines = 2, modifier = Modifier.padding(Space.l))
    }
}

/** Signed in to Tailors Hub without production access. */
@Composable
private fun WorkshopOnlyScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    Box(Modifier.fillMaxSize().background(MaterialTheme.hub.canvas)) {
        ke.co.bethanyhouse.hub.ui.components.EmptyState(
            title = "Tailors Hub is for the workshop",
            message = "Your account has no production work. Sign in to Bethany Hub instead; it has everything your role opens.",
            icon = Icons.Default.ContentCut,
            actionLabel = "Sign out",
        ) { scope.launch { env.session.signOut() } }
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
