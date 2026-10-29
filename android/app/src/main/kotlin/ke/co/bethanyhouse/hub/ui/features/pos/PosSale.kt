package ke.co.bethanyhouse.hub.ui.features.pos

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.ChevronRight
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.History
import androidx.compose.material.icons.filled.Inventory2
import androidx.compose.material.icons.filled.KeyboardArrowUp
import androidx.compose.material.icons.filled.MoreHoriz
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.QrCodeScanner
import androidx.compose.material.icons.filled.Remove
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.ShoppingCart
import androidx.compose.material.icons.filled.Sync
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.VerticalDivider
import androidx.compose.material3.adaptive.currentWindowAdaptiveInfo
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.window.core.layout.WindowWidthSizeClass
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.money
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.BarcodeScannerSheet
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.RemoteImage
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

private sealed class SaleSheet {
    data object Payment : SaleSheet()
    data object History : SaleSheet()
    data object Returns : SaleSheet()
    data object Eod : SaleSheet()
    data object DaySummary : SaleSheet()
    data object Close : SaleSheet()
    data object Customer : SaleSheet()
    data class Receipt(val sale: PosSale) : SaleSheet()
}

/**
 * The sale screen (PosPage.tsx): catalogue on the left, cart on the right
 * from 840dp (`WindowWidthSizeClass.EXPANDED`); on the phone the catalogue
 * with a cart bar and a sheet for the cart and checkout.
 */
@Composable
internal fun PosSaleScreen(session: PosSession) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val expanded = currentWindowAdaptiveInfo().windowSizeClass.windowWidthSizeClass == WindowWidthSizeClass.EXPANDED
    val compact = !expanded

    var products by remember { mutableStateOf<Resource<List<PosProduct>>?>(null) }
    var search by remember { mutableStateOf("") }
    var searchResults by remember { mutableStateOf<List<PosProduct>?>(null) }
    var searchJob by remember { mutableStateOf<Job?>(null) }
    var category by remember { mutableStateOf<Int?>(null) }
    var variantPicker by remember { mutableStateOf<PosProduct?>(null) }
    var showCart by remember { mutableStateOf(false) }
    var showScanner by remember { mutableStateOf(false) }
    var sheet by remember { mutableStateOf<SaleSheet?>(null) }
    var pricing by remember { mutableStateOf(false) }

    fun makeProducts() {
        val outlet = session.outlet ?: return
        val cat = category
        products = Resource(scope) { session.api.products(outlet.id, cat, null) }.also { it.load() }
    }

    suspend fun handleTillRefusal(e: Exception) {
        if (e is ApiError && (e.reason == "TILL_FROM_EARLIER_DAY" || e.reason == "TILL_NOT_OPEN" || e.reason == "OWNER_DOES_NOT_TRANSACT")) session.refreshRegister()
    }

    /** Prices the cart with the Hub (create/update the pending order), then opens the payment sheet on the server's totals. */
    suspend fun charge() {
        if (session.cart.isEmpty() || session.ownerWatchesOnly) return
        pricing = true
        try { session.priceWithHub(); sheet = SaleSheet.Payment }
        catch (e: Exception) { env.toasts.error(e); handleTillRefusal(e) }
        finally { pricing = false }
    }

    /** "Save order": the pending order is kept for later (history drawer), the cart is cleared. */
    suspend fun saveOrder() {
        if (session.cart.isEmpty() || session.ownerWatchesOnly) return
        pricing = true
        try {
            val r = session.priceWithHub()
            env.toasts.show("Order ${r["order_number"].str ?: r["order"]["order_number"].str ?: ""} saved — find it in Sales history.")
            session.clearCart()
            showCart = false
        } catch (e: Exception) { env.toasts.error(e); handleTillRefusal(e) } finally { pricing = false }
    }

    /** A scanned code is a SKU lookup: one exact match goes straight into the cart, otherwise it fills the search. */
    suspend fun scanned(code: String) {
        val outlet = session.outlet ?: return
        val hits = runCatching { session.api.searchProducts(code, outlet.id, session.currency) }.getOrDefault(emptyList())
        val exact = hits.firstOrNull { p -> p.sku.equals(code, true) || p.variants.any { it.sku.equals(code, true) } }
        if (exact != null) {
            val v = exact.variants.firstOrNull { it.sku.equals(code, true) } ?: exact.defaultVariant
            if (v != null && v.sellable && !exact.isProducible && exact.variants.size <= 1) { session.add(exact, v, false); env.toasts.show("Added ${exact.name}") }
            else variantPicker = exact
        } else { search = code; searchResults = hits }
    }

    LaunchedEffect(Unit) {
        val outlet = session.outlet ?: return@LaunchedEffect
        if (products == null) makeProducts()
        session.checkoutConfig = session.checkoutConfig ?: runCatching { session.api.checkoutConfig() }.getOrNull()
        session.shippingMethods = runCatching { session.api.shippingMethods(session.currency) }.getOrDefault(emptyList())
        session.paymentMethods = runCatching { session.api.paymentMethods(session.currency) }.getOrDefault(emptyList())
        // An unpaid pending order from this session is resumed, never duplicated (PosPage.tsx auto-resume).
        if (session.cart.isEmpty() && session.pendingOrderId == null) {
            val open = runCatching { session.api.openPendingOrder(outlet.id) }.getOrNull()
            val order = open?.get("order")
            if (order != null) {
                session.restore(PosSale(order))
                env.toasts.show("Resumed unpaid order ${open["order_number"].str ?: ""}", StatusTone.INFO)
            }
        }
    }
    LaunchedEffect(category) { if (products != null) makeProducts() }
    LaunchedEffect(search) {
        searchJob?.cancel()
        if (search.length < 2) { searchResults = null; return@LaunchedEffect }
        searchJob = scope.launch {
            delay(300)
            val outlet = session.outlet ?: return@launch
            searchResults = runCatching { session.api.searchProducts(search, outlet.id, session.currency) }.getOrNull()
        }
    }

    val header: @Composable () -> Unit = {
        Row(Modifier.fillMaxWidth().padding(horizontal = Space.l, vertical = Space.s), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(session.outlet?.name ?: "", style = MaterialTheme.typography.titleMedium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text("Till #${session.registerId ?: "—"}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            }
            IconButton(onClick = { showScanner = true }) { Icon(Icons.Default.QrCodeScanner, contentDescription = "Scan a SKU") }
            TillMenu(session) { sheet = it }
        }
    }

    val catalogue: @Composable (Modifier) -> Unit = { modifier ->
        Column(modifier) {
            header()
            OutlinedTextField(
                value = search, onValueChange = { search = it }, placeholder = { Text("Search products or SKU") }, singleLine = true,
                leadingIcon = { Icon(Icons.Default.Search, contentDescription = null, tint = MaterialTheme.hub.textTertiary) },
                trailingIcon = { if (search.isNotEmpty()) IconButton(onClick = { search = "" }) { Icon(Icons.Default.Close, contentDescription = "Clear") } },
                modifier = Modifier.fillMaxWidth().padding(horizontal = Space.l, vertical = Space.s),
            )
            val all = products?.value
            if (!all.isNullOrEmpty() && search.isEmpty()) CategoryChips(all, category) { category = it }
            if (session.ownerWatchesOnly) Box(Modifier.padding(horizontal = Space.l)) { OwnerWatchBanner() }
            if (session.totalsStale && session.pendingOrderId != null) Row(Modifier.padding(horizontal = Space.l, vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Default.Sync, contentDescription = null, tint = Palette.amber, modifier = Modifier.size(14.dp))
                Spacer(Modifier.width(4.dp))
                Text("The cart changed since the Hub priced it — review again before charging.", style = MaterialTheme.typography.labelSmall, color = Palette.amber)
            }
            val res = products
            val onTap: (PosProduct) -> Unit = { p ->
                if (p.variants.size > 1 || p.isProducible) variantPicker = p
                else p.defaultVariant?.takeIf { it.sellable }?.let { session.add(p, it, false) }
            }
            Box(Modifier.weight(1f)) {
                if (res != null) {
                    val results = searchResults
                    if (results != null && search.isNotEmpty()) {
                        if (results.isEmpty()) EmptyState("Nothing matches “$search”", icon = Icons.Default.Search)
                        else ProductGrid(results, compact, session.ownerWatchesOnly, onTap)
                    } else {
                        LoadableView(res.state, retry = { res.load() }, isEmpty = { it.isEmpty() }, empty = {
                            EmptyState("No products at this outlet", "Products with stock or a price at ${session.outlet?.name ?: "this outlet"} show here.", Icons.Default.Inventory2)
                        }) { ProductGrid(it, compact, session.ownerWatchesOnly, onTap) }
                    }
                }
            }
        }
    }

    val cartPane: @Composable (Modifier) -> Unit = { modifier ->
        CartPane(session, modifier, onCharge = { scope.launch { charge() } }, onSave = { scope.launch { saveOrder() } }, onCustomer = { sheet = SaleSheet.Customer }, pricing = pricing)
    }

    if (compact) {
        Column(Modifier.fillMaxSize()) {
            catalogue(Modifier.weight(1f))
            CartBar(session) { showCart = true }
        }
    } else {
        Row(Modifier.fillMaxSize()) {
            catalogue(Modifier.weight(1f).fillMaxHeight())
            VerticalDivider(color = MaterialTheme.hub.line)
            cartPane(Modifier.width(400.dp).fillMaxHeight())
        }
    }

    variantPicker?.let { p -> VariantSheet(p, onDismiss = { variantPicker = null }) { v, mto -> session.add(p, v, mto); variantPicker = null } }
    if (showScanner) BarcodeScannerSheet(title = "Scan a SKU", onDismiss = { showScanner = false }) { code -> scope.launch { scanned(code) } }
    if (showCart) ModalBottomSheet(onDismissRequest = { showCart = false }, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(Modifier.fillMaxWidth().heightIn(min = 420.dp)) {
            Row(Modifier.fillMaxWidth().padding(horizontal = Space.l), verticalAlignment = Alignment.CenterVertically) {
                Text("Cart", style = MaterialTheme.typography.titleLarge, modifier = Modifier.weight(1f))
                TextButton(onClick = { showCart = false }) { Text("Done") }
            }
            cartPane(Modifier.fillMaxWidth().weight(1f, fill = false))
        }
    }

    when (val s = sheet) {
        null -> {}
        SaleSheet.Payment -> PaymentSheet(session, onDismiss = { sheet = null }) { sale -> sheet = SaleSheet.Receipt(sale) }
        SaleSheet.History -> SalesHistorySheet(session, onDismiss = { sheet = null }) { sale -> session.restore(sale); sheet = null; if (compact) showCart = true }
        SaleSheet.Returns -> ReturnsSheet(session, onDismiss = { sheet = null })
        SaleSheet.Eod -> {
            val outlet = session.outlet; val rid = session.registerId
            if (outlet != null && rid != null) UserEodSheet(session, outlet.id, outlet.name, rid, fixedDate = null, onDismiss = { sheet = null }) { scope.launch { session.refreshRegister() } }
            else sheet = null
        }
        SaleSheet.DaySummary -> session.outlet?.let { DailySummarySheet(session, it, onDismiss = { sheet = null }) } ?: run { sheet = null }
        SaleSheet.Close -> session.outlet?.let { outlet ->
            RegisterCloseSheet(session, outlet.id, session.register, session.eodSubmitted, onDismiss = { sheet = null },
                onCounted = { session.clearCart(); scope.launch { session.refreshRegister() } }, onRequestEod = { sheet = SaleSheet.Eod })
        } ?: run { sheet = null }
        SaleSheet.Customer -> CustomerSheet(session, onDismiss = { sheet = null })
        is SaleSheet.Receipt -> ReceiptSheet(session, s.sale, onDismiss = { sheet = null })
    }
}

@Composable
private fun TillMenu(session: PosSession, open: (SaleSheet) -> Unit) {
    val env = LocalEnv.current
    var menu by remember { mutableStateOf(false) }
    Box {
        IconButton(onClick = { menu = true }) { Icon(Icons.Default.MoreHoriz, contentDescription = "Till") }
        DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
            DropdownMenuItem(text = { Text("Sales history") }, leadingIcon = { Icon(Icons.Default.History, contentDescription = null) }, onClick = { menu = false; open(SaleSheet.History) })
            if (env.session.current.can("pos.returns")) DropdownMenuItem(text = { Text("Returns & refunds") }, onClick = { menu = false; open(SaleSheet.Returns) })
            DropdownMenuItem(text = { Text("End of Day report") }, onClick = { menu = false; open(SaleSheet.Eod) })
            DropdownMenuItem(text = { Text("Day summary") }, onClick = { menu = false; open(SaleSheet.DaySummary) })
            if (env.session.current.can("pos.close_register")) {
                HorizontalDivider()
                DropdownMenuItem(text = { Text("Count & close till") }, enabled = !session.ownerWatchesOnly, onClick = { menu = false; open(SaleSheet.Close) })
            }
        }
    }
}

@Composable
private fun CategoryChips(all: List<PosProduct>, selected: Int?, onSelect: (Int?) -> Unit) {
    val cats = all.mapNotNull { p -> p.categoryId?.let { it to (p.categoryName ?: "") } }.distinctBy { it.first }.sortedBy { it.second }
    Row(Modifier.horizontalScroll(rememberScrollState()).padding(horizontal = Space.l).padding(bottom = Space.s), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
        Chip("All", selected == null) { onSelect(null) }
        cats.forEach { (id, name) -> Chip(name, selected == id) { onSelect(id) } }
    }
}

@Composable
internal fun Chip(label: String, on: Boolean, onClick: () -> Unit) {
    Surface(
        shape = CircleShape, color = if (on) Palette.brand else MaterialTheme.hub.card,
        modifier = Modifier.border(1.dp, if (on) Color.Transparent else MaterialTheme.hub.line, CircleShape).clickable(onClick = onClick),
    ) { Text(label, style = MaterialTheme.typography.bodySmall, color = if (on) Color.White else MaterialTheme.hub.text, modifier = Modifier.padding(horizontal = 12.dp, vertical = 7.dp)) }
}

@Composable
private fun ProductGrid(list: List<PosProduct>, compact: Boolean, disabled: Boolean, onTap: (PosProduct) -> Unit) {
    LazyVerticalGrid(
        columns = GridCells.Adaptive(if (compact) 150.dp else 170.dp),
        contentPadding = androidx.compose.foundation.layout.PaddingValues(Space.l),
        horizontalArrangement = Arrangement.spacedBy(Space.m), verticalArrangement = Arrangement.spacedBy(Space.m),
        modifier = Modifier.fillMaxSize(),
    ) {
        items(list, key = { it.id }) { p -> ProductTile(p, disabled) { onTap(p) } }
    }
}

// ── Product tile and variant picker ──────────────────────────────────────────

@Composable
private fun ProductTile(product: PosProduct, disabled: Boolean, onClick: () -> Unit) {
    val v = product.defaultVariant
    val enabled = !disabled && (v?.sellable == true || product.variants.size > 1)
    Surface(
        shape = MaterialTheme.shapes.medium, color = MaterialTheme.hub.card,
        modifier = Modifier.fillMaxWidth().border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.medium).clickable(enabled = enabled, onClick = onClick),
    ) {
        Column(Modifier.padding(Space.m), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Box(Modifier.fillMaxWidth().height(96.dp).background(MaterialTheme.hub.field, MaterialTheme.shapes.small), contentAlignment = Alignment.Center) {
                if (product.imageUrl != null) RemoteImage(product.imageUrl, size = 96.dp, modifier = Modifier.fillMaxWidth())
                else Icon(Icons.Default.Inventory2, contentDescription = null, tint = MaterialTheme.hub.textTertiary, modifier = Modifier.size(26.dp))
            }
            Text(product.name, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, maxLines = 2, overflow = TextOverflow.Ellipsis, color = if (enabled) MaterialTheme.hub.text else MaterialTheme.hub.textTertiary)
            Row(verticalAlignment = Alignment.CenterVertically) {
                if (v != null) {
                    if (v.sellable) MoneyText(v.money) else Text("No price in ${v.currency}", style = MaterialTheme.typography.labelSmall, color = Palette.warning)
                }
                Spacer(Modifier.weight(1f))
                if (product.variants.size > 1) Text("${product.variants.size} options", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
                else if (v != null) Text(
                    if (v.stock > 0) "${v.stock} in stock" else if (product.isProducible) "MTO" else "Out",
                    style = MaterialTheme.typography.labelSmall, color = if (v.stock > 0) MaterialTheme.hub.textTertiary else Palette.warning,
                )
            }
        }
    }
}

@Composable
private fun VariantSheet(product: PosProduct, onDismiss: () -> Unit, pick: (PosVariant, Boolean) -> Unit) {
    var madeToOrder by remember { mutableStateOf(false) }
    PosSheet(product.name, subtitle = product.sku, onDismiss = onDismiss, actions = { TextButton(onClick = onDismiss) { Text("Cancel") } }) {
        if (product.isProducible) ToggleRow("Made to order (raises a production order at checkout)", madeToOrder, { madeToOrder = it })
        product.variants.forEach { v ->
            val enabled = v.sellable && (v.stock > 0 || madeToOrder)
            Surface(shape = MaterialTheme.shapes.small, color = MaterialTheme.hub.card, modifier = Modifier.fillMaxWidth().border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.small).clickable(enabled = enabled) { pick(v, madeToOrder) }) {
                Row(Modifier.padding(Space.m), verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Text(v.name.ifEmpty { "Default" }, style = MaterialTheme.typography.bodyMedium, color = if (enabled) MaterialTheme.hub.text else MaterialTheme.hub.textTertiary)
                        Text("${v.sku} · ${v.stock} in stock", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    }
                    if (v.sellable) MoneyText(v.money) else Text("No price in ${v.currency}", style = MaterialTheme.typography.labelSmall, color = Palette.warning)
                }
            }
        }
    }
}

// ── Phone cart bar ───────────────────────────────────────────────────────────

@Composable
private fun CartBar(session: PosSession, onOpen: () -> Unit) {
    val total = session.pendingOrder["total_amount"]?.let { Money.from(it, session.currency) }?.takeIf { !session.totalsStale }
    Surface(
        shape = MaterialTheme.shapes.medium, color = Palette.nav,
        modifier = Modifier.fillMaxWidth().padding(horizontal = Space.gutter).padding(bottom = Space.s).clickable(onClick = onOpen),
    ) {
        Row(Modifier.padding(horizontal = 18.dp, vertical = 14.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Icon(Icons.Default.ShoppingCart, contentDescription = null, tint = Color.White, modifier = Modifier.size(18.dp))
            Text(
                if (session.cart.isEmpty()) "Cart is empty" else "${session.itemCount} item${if (session.cart.size == 1) "" else "s"}",
                style = MaterialTheme.typography.labelLarge, color = Color.White, modifier = Modifier.weight(1f),
            )
            if (total != null) Text(total.formatted(), style = MaterialTheme.typography.labelLarge, color = Color.White)
            else Text("Review to price", style = MaterialTheme.typography.labelSmall, color = Color.White.copy(alpha = 0.85f))
            Icon(Icons.Default.KeyboardArrowUp, contentDescription = null, tint = Color.White)
        }
    }
}

// ── Cart pane ────────────────────────────────────────────────────────────────

@Composable
private fun CartPane(session: PosSession, modifier: Modifier, onCharge: () -> Unit, onSave: () -> Unit, onCustomer: () -> Unit, pricing: Boolean) {
    var editing by remember { mutableStateOf<CartLine?>(null) }
    Column(modifier.background(MaterialTheme.hub.canvas)) {
        Row(Modifier.fillMaxWidth().clickable(onClick = onCustomer).padding(Space.m), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Icon(Icons.Default.Person, contentDescription = null, tint = Palette.brand)
            Column(Modifier.weight(1f)) {
                Text(session.customer?.displayName ?: "Walk-in customer", style = MaterialTheme.typography.bodyMedium)
                session.customer?.phone?.takeIf { it.isNotEmpty() }?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
            }
            Icon(Icons.Default.ChevronRight, contentDescription = null, tint = MaterialTheme.hub.textTertiary)
        }
        HorizontalDivider(color = MaterialTheme.hub.line)
        Column(Modifier.weight(1f, fill = false).verticalScroll(rememberScrollState()).padding(Space.m), verticalArrangement = Arrangement.spacedBy(Space.s)) {
            session.cart.forEach { line ->
                CartRow(
                    line,
                    onInc = { session.update(line.copy(quantity = line.quantity + 1)) },
                    onDec = { if (line.quantity > 1) session.update(line.copy(quantity = line.quantity - 1)) else session.remove(line) },
                    onEdit = { editing = line },
                    onRemove = { session.remove(line) },
                )
            }
            if (session.cart.isEmpty()) Text("Tap a product to add it.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textTertiary)
            Text("DISCOUNT, SHIPPING & NOTE", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, modifier = Modifier.padding(top = Space.s))
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                DiscountTypeTabs(session.cartDiscountType) { session.cartDiscountType = it; session.cartChanged() }
                if (session.cartDiscountType != "none") AmountField("0", session.cartDiscountValue, { v ->
                    session.cartDiscountValue = clampPercent(v, session.cartDiscountType, session.discountCap); session.cartChanged()
                }, Modifier.width(110.dp))
            }
            val cap = session.discountCap
            if (cap != null && session.cartDiscountType != "none") CaptionText("Up to ${formatCap(cap)}% — the Hub refuses more.")
            if (session.shippingMethods.isNotEmpty()) {
                val options = session.shippingMethods.mapNotNull { m ->
                    val id = m["id"].int ?: return@mapNotNull null
                    val fee = m["fee"]?.let { Money.from(it, m["fee_currency"].str ?: session.currency) }
                    val suffix = if (m["cost_type"].str == "free") " · free" else fee?.let { " · ${it.formatted()}" } ?: " · no rate"
                    id to ((m["name"].str ?: "Method") + suffix)
                }
                SelectField("Shipping", session.shippingMethodId, options, { session.shippingMethodId = it; session.cartChanged() }, Modifier.fillMaxWidth(), placeholder = "Collected in store")
            }
            OutlinedTextField(value = session.note, onValueChange = { session.note = it; session.cartChanged() }, label = { Text("Order note") }, modifier = Modifier.fillMaxWidth(), maxLines = 3)
        }
        HorizontalDivider(color = MaterialTheme.hub.line)
        CartTotals(session, onCharge, onSave, pricing)
    }
    editing?.let { line -> LineEditorSheet(line, session.discountCap, onDismiss = { editing = null }) { session.update(it); editing = null } }
}

private fun clampPercent(text: String, type: String, cap: Double?): String {
    if (type != "percent" || cap == null) return text
    val v = text.toDoubleOrNull() ?: return text
    return if (v > cap) formatCap(cap) else text
}

@Composable
internal fun DiscountTypeTabs(value: String, onChange: (String) -> Unit) {
    val options = listOf("none" to "None", "percent" to "%", "flat" to "Flat")
    SingleChoiceSegmentedButtonRow(Modifier.widthIn(max = 220.dp)) {
        options.forEachIndexed { i, (v, label) ->
            SegmentedButton(selected = value == v, onClick = { onChange(v) }, shape = SegmentedButtonDefaults.itemShape(i, options.size)) { Text(label) }
        }
    }
}

/** The Hub's figures for this cart (pending order reply). Nothing is added here. */
@Composable
private fun CartTotals(session: PosSession, onCharge: () -> Unit, onSave: () -> Unit, pricing: Boolean) {
    Column(Modifier.fillMaxWidth().background(MaterialTheme.hub.card).padding(Space.l), verticalArrangement = Arrangement.spacedBy(Space.s)) {
        val order = session.pendingOrder["order"]
        val cur = session.currency
        if (order != null && !session.totalsStale) {
            TotalRow("Subtotal", order["subtotal"]?.let { Money.from(it, cur) })
            order["discount_amount"].money?.takeIf { !it.isZero }?.let { TotalRow("Discount", Money.from(order["discount_amount"], cur)) }
            TotalRow(if (order["prices_include_tax"].bool == true) "Tax (included)" else "Tax", order["tax_amount"]?.let { Money.from(it, cur) })
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text("Total", style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
                MoneyText(Money.from(order["total"], cur), emphasis = true)
            }
            order["amount_paid"].money?.takeIf { !it.isZero }?.let {
                TotalRow("Paid so far", Money.from(order["amount_paid"], cur))
                TotalRow("Outstanding", Money.from(order["outstanding"], cur))
            }
            CaptionText("Priced by the Hub · ${session.pendingOrder["order_number"].str ?: ""}")
        } else if (session.cart.isNotEmpty()) {
            Text(
                if (session.totalsStale) "Cart changed — review to price it again." else "Totals are computed by the Hub when you review the cart.",
                style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary,
            )
        }
        if (session.ownerWatchesOnly) OwnerWatchBanner()
        else Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
            TextButton(onClick = { session.clearCart() }, enabled = session.cart.isNotEmpty()) { Text("Clear") }
            OutlinedButton(onClick = onSave, enabled = session.cart.isNotEmpty() && !pricing) { Text("Save order") }
            Button(onClick = onCharge, enabled = session.cart.isNotEmpty() && !pricing, modifier = Modifier.weight(1f)) {
                if (pricing) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp, color = Color.White)
                else Text(if (session.pendingOrderId != null && !session.totalsStale) "Charge" else "Review & charge", maxLines = 1)
            }
        }
    }
}

@Composable
internal fun TotalRow(label: String, money: Money?, strong: Boolean = false) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Text(label, style = if (strong) MaterialTheme.typography.bodyMedium else MaterialTheme.typography.bodySmall, fontWeight = if (strong) FontWeight.SemiBold else FontWeight.Normal,
            color = if (strong) MaterialTheme.hub.text else MaterialTheme.hub.textSecondary, modifier = Modifier.weight(1f))
        MoneyText(money, emphasis = strong)
    }
}

@Composable
private fun CartRow(line: CartLine, onInc: () -> Unit, onDec: () -> Unit, onEdit: () -> Unit, onRemove: () -> Unit) {
    Surface(shape = MaterialTheme.shapes.small, color = MaterialTheme.hub.card, modifier = Modifier.fillMaxWidth().border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.small)) {
        Row(Modifier.padding(Space.s), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(line.title, style = MaterialTheme.typography.bodyMedium, maxLines = 2, overflow = TextOverflow.Ellipsis)
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    MoneyText(line.unitMoney)
                    Text("each", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
                    if (line.discountType != "none") StatusBadge("-${line.discountValue}${if (line.discountType == "percent") "%" else ""}", StatusTone.PINK)
                    if (line.isProduction) StatusBadge("made to order", StatusTone.ACCENT)
                }
            }
            IconButton(onClick = onDec, modifier = Modifier.size(32.dp)) { Icon(if (line.quantity > 1) Icons.Default.Remove else Icons.Default.Delete, contentDescription = "Less", tint = Palette.brandDeep, modifier = Modifier.size(18.dp)) }
            Text("${line.quantity}", style = MaterialTheme.typography.bodyMedium, modifier = Modifier.widthIn(min = 24.dp), textAlign = androidx.compose.ui.text.style.TextAlign.Center)
            IconButton(onClick = onInc, modifier = Modifier.size(32.dp)) { Icon(Icons.Default.Add, contentDescription = "More", tint = Palette.brandDeep, modifier = Modifier.size(18.dp)) }
            IconButton(onClick = onEdit, modifier = Modifier.size(32.dp)) { Icon(Icons.Default.MoreHoriz, contentDescription = "Edit line", tint = Palette.brandDeep, modifier = Modifier.size(18.dp)) }
            IconButton(onClick = onRemove, modifier = Modifier.size(32.dp)) { Icon(Icons.Default.Close, contentDescription = "Remove", tint = MaterialTheme.hub.textTertiary, modifier = Modifier.size(16.dp)) }
        }
    }
}

/** Per-line discount (within the person's cap), made-to-order notes and measurements. */
@Composable
private fun LineEditorSheet(initial: CartLine, cap: Double?, onDismiss: () -> Unit, save: (CartLine) -> Unit) {
    var line by remember { mutableStateOf(initial) }
    val missingRequired = line.isProduction && line.measurementFields.any { it.required && (line.measurementValues[it.name] ?: "").isBlank() }
    PosSheet(line.title, subtitle = line.sku, onDismiss = onDismiss, actions = {
        TextButton(onClick = onDismiss) { Text("Cancel") }
        Button(onClick = { save(line) }, enabled = !missingRequired) { Text("Save") }
    }) {
        LabelledRow("Quantity") {
            IconButton(onClick = { if (line.quantity > 1) line = line.copy(quantity = line.quantity - 1) }) { Icon(Icons.Default.Remove, contentDescription = "Less") }
            Text("${line.quantity}", style = MaterialTheme.typography.bodyMedium, modifier = Modifier.widthIn(min = 28.dp), textAlign = androidx.compose.ui.text.style.TextAlign.Center)
            IconButton(onClick = { if (line.quantity < 9999) line = line.copy(quantity = line.quantity + 1) }) { Icon(Icons.Default.Add, contentDescription = "More") }
        }
        LabelledRow("Discount") { DiscountTypeTabs(line.discountType) { line = line.copy(discountType = it) } }
        if (line.discountType != "none") {
            LabelledRow(if (line.discountType == "percent") "Percent" else "Amount (${line.currency})") {
                AmountField("0", line.discountValue, { v -> line = line.copy(discountValue = clampPercent(v, line.discountType, cap)) })
            }
            if (cap != null) CaptionText("Your limit is ${formatCap(cap)}% of the line — a super admin can give more. The Hub enforces it.")
        }
        ToggleRow("Made to order", line.isProduction, { line = line.copy(isProduction = it) })
        if (line.isProduction) {
            if (line.measurementFields.isEmpty()) {
                OutlinedTextField(value = line.productionNotes, onValueChange = { line = line.copy(productionNotes = it) }, label = { Text("Measurements, colours, preferences…") }, minLines = 3, modifier = Modifier.fillMaxWidth())
            } else {
                line.measurementFields.forEach { f ->
                    OutlinedTextField(
                        value = line.measurementValues[f.name] ?: "", onValueChange = { v -> line = line.copy(measurementValues = line.measurementValues + (f.name to v)) },
                        label = { Text(f.name + (f.unit?.let { " ($it)" } ?: "") + (if (f.required) " *" else "")) }, singleLine = true, modifier = Modifier.fillMaxWidth(),
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Text),
                    )
                }
                OutlinedTextField(value = line.productionNotes, onValueChange = { line = line.copy(productionNotes = it) }, label = { Text("Production notes") }, minLines = 2, modifier = Modifier.fillMaxWidth())
            }
        }
    }
}
