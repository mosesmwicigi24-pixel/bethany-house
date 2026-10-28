package ke.co.bethanyhouse.hub.ui.features.sales

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.filled.People
import androidx.compose.material.icons.filled.ShoppingBag
import androidx.compose.material3.Button
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.date
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.obj
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.DetailRow
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.ModuleRowCard
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.PagedList
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SecureFieldRow
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.StatTile
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.TileModifier
import ke.co.bethanyhouse.hub.ui.components.saveDocument
import ke.co.bethanyhouse.hub.ui.components.shareFile
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull

// ── Customers list (GET /admin/customers — CustomerController::index) ───────

/**
 * customers.view is the picker: identity and contact (masked by role).
 * Addresses, spend and credit figures come only with customers.insights —
 * the server strips them otherwise, so the rows show what they carry.
 */
@Composable
fun CustomersListScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var search by remember { mutableStateOf("") }
    var status by remember { mutableStateOf("") }
    var type by remember { mutableStateOf("") }
    var sort by remember { mutableStateOf("created_at:desc") }
    var form by remember { mutableStateOf<Pair<Boolean, Record?>?>(null) } // (open, editing)
    var exporting by remember { mutableStateOf(false) }
    val list = remember { PagedList(scope, env.client, "/v1/admin/customers", map = ::Record) }
    fun reload() {
        val parts = sort.split(":")
        list.query = mutableMapOf("search" to search.ifEmpty { null }, "status" to status.ifEmpty { null }, "type" to type.ifEmpty { null }, "sort_by" to parts.firstOrNull(), "sort_order" to (parts.getOrNull(1) ?: "desc"))
        list.load()
    }
    LaunchedEffect(status, type, sort) { reload() }

    Page("Customers", subtitle = if (list.total > 0) "${list.total} customers" else null, refresh = { list.reload() }) {
        Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
            ModuleSearchField(search, { search = it }, "Name, phone, email or customer no.", onSubmit = ::reload)
            Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                OptionPicker("Status", status, listOf("active" to "Active", "inactive" to "Inactive", "suspended" to "Suspended"), { status = it }, Modifier.weight(1f), allowNone = true)
                OptionPicker("Type", type, listOf("individual" to "Individual", "business" to "Business"), { type = it }, Modifier.weight(1f), allowNone = true)
            }
            OptionPicker("Sort", sort, listOf("created_at:desc" to "Newest first", "created_at:asc" to "Oldest first", "name:asc" to "Name A–Z", "name:desc" to "Name Z–A"), { if (it.isNotEmpty()) sort = it })
            FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                if (env.session.current.permissions.isSuperAdmin) OutlinedButton(onClick = { exporting = true }) { Text("Google Contacts CSV") }
                if (env.session.current.can("customers.create")) Button(onClick = { form = true to null }) { Text("New customer") }
            }
        }
        LoadableView(list.state, retry = { list.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("No customers match", icon = Icons.Default.People) }) { rows ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                rows.forEachIndexed { i, c ->
                    val id = c.intId
                    ModuleRowCard(onClick = { if (id != null) env.navigator.push(Route.Customer(id)) }) {
                        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                            Text(Sales.name(c.string("first_name"), c.string("last_name")) ?: "—", style = MaterialTheme.typography.bodyMedium, modifier = Modifier.weight(1f))
                            StatusBadge(c.status)
                        }
                        SalesCaption(listOfNotNull(c.string("customer_number"), c.string("phone"), c.string("email")).joinToString(" · "))
                        CustomerRowActions(c, onChanged = { list.load() }, onEdit = { form = true to c })
                    }
                    if (i == rows.lastIndex && list.hasMore) LaunchedEffect(rows.size) { list.loadMore() }
                }
                SalesLoadMore(list)
            }
        }
    }
    form?.let { (_, editing) -> CustomerFormSheet(editing, onDone = { list.load() }, onDismiss = { form = null }) }
    if (exporting) GoogleContactsExportSheet(onDismiss = { exporting = false })
}

/**
 * Status (PUT /customers/{id}/status), invite (POST …/invite-to-portal),
 * edit (PUT /customers/{id}), delete (DELETE /customers/{id}).
 */
@Composable
private fun CustomerRowActions(customer: Record, onChanged: () -> Unit, onEdit: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val s = env.session.current
    val canInvite = s.can("customers.invite") && customer["is_portal_user"].bool != true && !(customer.string("email") ?: "").isEmpty()
    var menu by remember { mutableStateOf(false) }
    var confirmDelete by remember { mutableStateOf(false) }
    if (!(s.can("customers.edit") || s.can("customers.delete") || canInvite)) return
    Box {
        IconButton(onClick = { menu = true }) { Icon(Icons.Default.MoreVert, contentDescription = "Actions") }
        DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
            if (s.can("customers.edit")) {
                DropdownMenuItem(text = { Text("Edit") }, onClick = { menu = false; onEdit() })
                listOf("active", "inactive", "suspended").forEach { st ->
                    DropdownMenuItem(text = { Text("Set ${st.humanized}") }, enabled = customer.status != st, onClick = {
                        menu = false
                        scope.launch { try { env.client.put("/v1/admin/customers/${customer.id}/status", jsonOf("status" to st)); env.toasts.show("Status updated"); onChanged() } catch (e: Exception) { env.toasts.error(e) } }
                    })
                }
            }
            if (canInvite) DropdownMenuItem(text = { Text("Invite to portal") }, onClick = {
                menu = false
                scope.launch { try { val r = env.client.post("/v1/admin/customers/${customer.id}/invite-to-portal"); env.toasts.show(r.json["message"].str ?: "Portal invite sent") } catch (e: Exception) { env.toasts.error(e) } }
            })
            if (s.can("customers.delete")) {
                HorizontalDivider()
                DropdownMenuItem(text = { Text("Delete…", color = Palette.danger) }, onClick = { menu = false; confirmDelete = true })
            }
        }
    }
    if (confirmDelete) androidx.compose.material3.AlertDialog(
        onDismissRequest = { confirmDelete = false },
        title = { Text("Delete customer") },
        text = { Text("The customer record is removed (recoverable from Trash by the owner).") },
        confirmButton = { androidx.compose.material3.TextButton(onClick = {
            confirmDelete = false
            scope.launch { try { env.client.delete("/v1/admin/customers/${customer.id}"); env.toasts.show("Customer deleted"); onChanged() } catch (e: Exception) { env.toasts.error(e) } }
        }) { Text("Delete", color = Palette.danger) } },
        dismissButton = { androidx.compose.material3.TextButton(onClick = { confirmDelete = false }) { Text("Cancel") } },
    )
}

// ── Customer form (POST /customers | PUT /customers/{id}) ───────────────────

@Composable
private fun CustomerFormSheet(editing: Record?, onDone: () -> Unit, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var firstName by remember { mutableStateOf(editing?.string("first_name") ?: "") }
    var lastName by remember { mutableStateOf(editing?.string("last_name") ?: "") }
    var email by remember { mutableStateOf(editing?.string("email")?.takeIf { !Sales.isMasked(it) } ?: "") }
    var phone by remember { mutableStateOf(editing?.string("phone")?.takeIf { !Sales.isMasked(it) } ?: "") }
    var type by remember { mutableStateOf(editing?.string("customer_type") ?: "individual") }
    var company by remember { mutableStateOf(editing?.string("company") ?: "") }
    var taxNumber by remember { mutableStateOf(editing?.string("tax_id") ?: "") }
    var language by remember { mutableStateOf(editing?.string("preferred_language") ?: "en") }
    var currency by remember { mutableStateOf(editing?.string("preferred_currency") ?: "KES") }
    var notes by remember { mutableStateOf(editing?.string("notes") ?: "") }
    val canSubmit = firstName.isNotBlank() && lastName.isNotBlank() && (email.isNotBlank() || phone.isNotBlank()) && (type != "business" || company.isNotBlank())

    FormSheet(if (editing == null) "New customer" else "Edit customer", onDismiss, saveLabel = "Save", canSave = canSubmit, save = {
        val body = mutableMapOf<String, JsonElement>(
            "first_name" to Sales.optionalString(firstName), "last_name" to Sales.optionalString(lastName),
            "type" to kotlinx.serialization.json.JsonPrimitive(type), "preferred_language" to kotlinx.serialization.json.JsonPrimitive(language), "preferred_currency" to kotlinx.serialization.json.JsonPrimitive(currency),
            "company_name" to Sales.optionalString(company), "tax_number" to Sales.optionalString(taxNumber), "notes" to Sales.optionalString(notes),
        )
        if (email.isNotBlank()) body["email"] = kotlinx.serialization.json.JsonPrimitive(email.trim())
        if (phone.isNotBlank()) body["phone"] = kotlinx.serialization.json.JsonPrimitive(phone.trim())
        val json = kotlinx.serialization.json.JsonObject(body)
        if (editing != null) env.client.put("/v1/admin/customers/${editing.id}", json) else env.client.post("/v1/admin/customers", json)
        env.toasts.show(if (editing == null) "Customer created" else "Customer updated")
        onDone()
    }) { error ->
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Field("First name", firstName, { firstName = it }, Modifier.weight(1f), error = error?.field("first_name"))
            Field("Last name", lastName, { lastName = it }, Modifier.weight(1f), error = error?.field("last_name"))
        }
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Field("Phone", phone, { phone = it }, Modifier.weight(1f), error = error?.field("phone"))
            Field("Email", email, { email = it }, Modifier.weight(1f), error = error?.field("email"))
        }
        SalesCaption("Email or phone is required. Without an email the customer is a walk-in record without portal access; you can invite them later.", MaterialTheme.hub.textTertiary)
        SegmentedTabs(listOf("individual" to "Individual", "business" to "Business"), type) { type = it }
        if (type == "business") Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Field("Company (required)", company, { company = it }, Modifier.weight(1f), error = error?.field("company_name"))
            Field("Tax number", taxNumber, { taxNumber = it }, Modifier.weight(1f), error = error?.field("tax_number"))
        }
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            OptionPicker("Language", language, listOf("en" to "English", "fr" to "French", "pt" to "Portuguese"), { if (it.isNotEmpty()) language = it }, Modifier.weight(1f))
            OptionPicker("Currency", currency, listOf("KES" to "KES", "USD" to "USD"), { if (it.isNotEmpty()) currency = it }, Modifier.weight(1f))
        }
        Field("Notes", notes, { notes = it }, multiline = true)
        SalesFieldErrors(error)
    }
}

// ── Google Contacts export (POST /customers/export/google-contacts {password, code}) ─

/**
 * The owner account only: password and two-step code typed each time; the
 * server answers a CSV (and refuses everyone else with 403).
 */
@Composable
private fun GoogleContactsExportSheet(onDismiss: () -> Unit) {
    val env = LocalEnv.current
    val context = LocalContext.current
    var password by remember { mutableStateOf("") }
    var code by remember { mutableStateOf("") }
    FormSheet("Export for Google Contacts", onDismiss, saveLabel = "Download CSV", canSave = password.isNotEmpty() && code.trim().length >= 6, save = {
        val r = env.client.send(ApiClient.Request(ApiClient.Method.POST, "/v1/admin/customers/export/google-contacts",
            body = ApiClient.Body.Json(jsonOf("password" to password, "code" to code.trim())), headers = mapOf("Accept" to "*/*")))
        val contacts = r.headers.entries.firstOrNull { it.key.equals("X-Contacts", ignoreCase = true) }?.value ?: "?"
        val file = saveDocument(context, r, "bethany-house-google-contacts.csv")
        shareFile(context, file, r.contentType ?: "text/csv")
        env.toasts.show("$contacts contacts exported")
    }) { error ->
        SalesSecondary("A CSV in Google's own import layout: one row per phone number with the best name the hub holds. Owner only; the server checks the password and two-step code every time.")
        SecureFieldRow("Password", password, { password = it }, error = error?.field("password"))
        Field("Two-step code", code, { code = it }, error = error?.field("code"))
        SalesFieldErrors(error)
    }
}

// ── Customer detail (GET /customers/{id} → {customer, stats}) ───────────────

@Composable
fun CustomerDetailScreen(customerId: Int) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val insights = env.session.current.can("customers.insights")
    val resource = remember(customerId) { Resource(scope) { env.client.get("/v1/admin/customers/$customerId").json ?: JsonNull } }
    val orders = remember(customerId) { if (insights) PagedList(scope, env.client, "/v1/admin/customers/$customerId/orders", map = ::Record) else null }
    LaunchedEffect(resource) { resource.load(); orders?.load() }
    var editing by remember { mutableStateOf(false) }
    val current = Record(resource.value["customer"] ?: JsonNull)

    Page(Sales.name(current.string("first_name"), current.string("last_name")) ?: "Customer", refresh = { resource.reload(); orders?.reload() }) {
        LoadableView(resource.state, retry = { resource.load() }) { json ->
            val c = Record(json["customer"] ?: JsonNull)
            SectionCard {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top) {
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                            SalesMono(c.string("customer_number") ?: "")
                            StatusBadge(c.status)
                            StatusBadge(c.string("customer_type"), StatusTone.NEUTRAL)
                            if (c["is_portal_user"].bool == true) StatusBadge("portal", StatusTone.INFO)
                        }
                        c.string("company")?.takeIf { it.isNotEmpty() }?.let { Text(it, style = MaterialTheme.typography.bodyMedium) }
                        Row(horizontalArrangement = Arrangement.spacedBy(Space.m), verticalAlignment = Alignment.CenterVertically) {
                            SalesCaption("Prefers ${c.string("preferred_currency") ?: "KES"} · ${(c.string("preferred_language") ?: "en").uppercase()}", MaterialTheme.hub.textTertiary)
                            DateText(c.date("created_at"), DateStyle.DATE)
                        }
                    }
                    CustomerRowActions(c, onChanged = { resource.load() }, onEdit = { editing = true })
                }
            }
            val stats = json["stats"]
            if (stats.obj != null) SectionCard("Purchase history") {
                TileGrid(minWidth = 150.dp) {
                    StatTile("Orders", stats["total_orders"].str ?: "0", hint = "${stats["recognised_orders"].str ?: "0"} recognised", modifier = TileModifier)
                    StatTile("Total spent", Money.from(stats["total_spent"], "KES")?.formatted() ?: "—", hint = "server figure", modifier = TileModifier)
                    StatTile("Average order", Money.from(stats["average_order_value"], "KES")?.formatted() ?: "—", modifier = TileModifier)
                    StatTile("Online / POS", "${stats["online_orders"].str ?: "0"} / ${stats["pos_orders"].str ?: "0"}", modifier = TileModifier)
                    StatTile("Cancelled", stats["cancelled_orders"].str ?: "0", tone = if ((stats["cancelled_orders"].int ?: 0) > 0) StatusTone.DANGER else null, modifier = TileModifier)
                }
                stats["last_order_date"].date?.let { d -> Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) { SalesCaption("Last order"); DateText(d) } }
            }
            SectionCard("Contact") {
                SalesContactLine("Phone", c.string("phone"), "phone", customerId, null)
                SalesContactLine("Email", c.string("email"), "email", customerId, null)
                c.string("tax_id")?.takeIf { it.isNotEmpty() }?.let { DetailRow("Tax ID", it) }
                if (!c["loyalty_points"].isNull) DetailRow("Loyalty points", c["loyalty_points"].str)
                c.money("credit_limit", c.string("preferred_currency") ?: "KES")?.takeIf { !it.isZero }?.let { cl -> DetailRow("Credit limit") { MoneyText(cl) } }
            }
            val addresses = c["addresses"].toRecords()
            SectionCard("Addresses") {
                if (addresses.isEmpty()) SalesSecondary(if (insights) "No addresses on file." else "Addresses need customers.insights.")
                addresses.forEach { a ->
                    Column(Modifier.padding(vertical = 2.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                            Text(a.string("name") ?: "Address", style = MaterialTheme.typography.bodyMedium)
                            if (a["is_default"].bool == true) StatusBadge("default", StatusTone.INFO)
                        }
                        SalesSecondary(listOf("address_line_1", "address_line_2", "city", "state", "postal_code", "country").mapNotNull { a.string(it) }.filter { it.isNotEmpty() }.joinToString(", "), MaterialTheme.hub.text)
                        a.string("phone")?.let { SalesCaption(it) }
                    }
                }
            }
            if (orders != null) SectionCard("Orders") {
                LoadableView(orders.state, retry = { orders.load() }, isEmpty = { it.isEmpty() }, empty = { SalesSecondary("No orders yet") }) { rows ->
                    rows.forEachIndexed { i, o ->
                        val id = o.intId
                        Row(Modifier.fillMaxWidth().clickable { if (id != null) env.navigator.push(Route.Order(id)) }.padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            SalesMono(o.string("order_number") ?: "")
                            StatusBadge(o.status)
                            StatusBadge(o.string("payment_status"))
                            Spacer(Modifier.weight(1f))
                            Column(horizontalAlignment = Alignment.End) { MoneyText(o.money("total_amount")); DateText(o.date("created_at"), DateStyle.DATE) }
                        }
                        SalesDivider()
                        if (i == rows.lastIndex && orders.hasMore) LaunchedEffect(rows.size) { orders.loadMore() }
                    }
                    SalesLoadMore(orders)
                }
            }
            c.string("notes")?.takeIf { it.isNotEmpty() }?.let { n -> SectionCard("Notes") { Text(n, style = MaterialTheme.typography.bodyMedium) } }
        }
    }
    if (editing) CustomerFormSheet(current, onDone = { resource.load() }, onDismiss = { editing = false })
}

// ── Outstanding balances (GET /admin/pos/outstanding-balances — receivables.view) ─

/**
 * Receivables: orders with money still owed. `amount_paid` and `balance`
 * are the server's figures per row; rows in several currencies are listed
 * in their own currency and never summed here.
 */
@Composable
fun OutstandingBalancesScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var search by remember { mutableStateOf("") }
    val list = remember { PagedList(scope, env.client, "/v1/admin/pos/outstanding-balances", map = ::Record) }
    fun reload() { list.query = mutableMapOf("search" to search.ifEmpty { null }); list.load() }
    LaunchedEffect(Unit) { reload() }

    Page("Outstanding Balances", subtitle = if (list.total > 0) "${list.total} orders with a balance" else null, refresh = { list.reload() }) {
        ModuleSearchField(search, { search = it }, "Order no., customer, phone", onSubmit = ::reload)
        LoadableView(list.state, retry = { list.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("Nothing outstanding", "Every order has been settled.", Icons.Default.CheckCircle) }) { rows ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                rows.forEachIndexed { i, r ->
                    val id = r.intId
                    val cc = r.string("currency_code")
                    ModuleRowCard(onClick = { if (id != null) env.navigator.push(Route.Order(id)) }) {
                        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            SalesMono(r.string("order_number") ?: "")
                            StatusBadge(r.string("payment_status"))
                            Spacer(Modifier.weight(1f))
                            Column(horizontalAlignment = Alignment.End) { SalesCaption("Balance"); MoneyText(r.money("balance", cc), emphasis = true) }
                        }
                        Text(r.string("customer_name") ?: "Walk-in", style = MaterialTheme.typography.bodyMedium)
                        SalesCaption(listOfNotNull(r.string("customer_phone"), r.string("outlet_name")).joinToString(" · "))
                        SalesCaption("Total ${r.money("total_amount", cc)?.formatted() ?: "—"} · paid ${r.money("amount_paid", cc)?.formatted() ?: "—"}")
                        r.string("balance_due_date")?.let { SalesCaption("due ${it.take(10)}", Palette.amber) }
                    }
                    if (i == rows.lastIndex && list.hasMore) LaunchedEffect(rows.size) { list.loadMore() }
                }
                SalesLoadMore(list)
            }
        }
    }
}
