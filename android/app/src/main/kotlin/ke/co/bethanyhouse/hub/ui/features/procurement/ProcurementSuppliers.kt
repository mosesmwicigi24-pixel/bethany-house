package ke.co.bethanyhouse.hub.ui.features.procurement

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Description
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.filled.StarBorder
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.dbl
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.money
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.DayField
import ke.co.bethanyhouse.hub.ui.components.DecimalField
import ke.co.bethanyhouse.hub.ui.components.DetailRow
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.InfoSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.ModuleRowCard
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.OptionPickerValues
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.PagedList
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.StatTile
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.TileModifier
import ke.co.bethanyhouse.hub.ui.components.listRecords
import ke.co.bethanyhouse.hub.ui.components.rememberFilePicker
import ke.co.bethanyhouse.hub.ui.components.saveDocument
import ke.co.bethanyhouse.hub.ui.components.shareFile
import ke.co.bethanyhouse.hub.ui.components.uploadFile
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonPrimitive
import java.util.Locale

// Suppliers (SupplierController): list, create/edit (procurement.create),
// detail with stats, recent orders, performance, rating, contact history,
// documents and export (step-up on the server).

// ── List (GET /v1/admin/suppliers → Laravel paginator) ───────────────────────

@Composable
fun SuppliersScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val list = remember { PagedList(scope, env.client, "/v1/admin/suppliers", map = ::Record, perPage = 20) }
    var search by remember { mutableStateOf("") }
    var status by remember { mutableStateOf("") }
    var creating by remember { mutableStateOf(false) }
    var editing by remember { mutableStateOf<Record?>(null) }
    var selected by remember { mutableStateOf<Record?>(null) }
    var exporting by remember { mutableStateOf(false) }

    fun apply() { list.query = mutableMapOf("search" to search.ifEmpty { null }, "status" to status.ifEmpty { null }); list.load() }
    LaunchedEffect(status) { apply() }

    /** GET …/suppliers/export answers JSON `{message, data}` (step-up gated); saved as a .json file to share. */
    suspend fun export() {
        exporting = true
        try {
            val r = env.client.get("/v1/admin/suppliers/export")
            val file = saveDocument(context, r, "suppliers-${Day.today()}.json")
            shareFile(context, file, "application/json")
            env.toasts.show("Export saved: ${file.name}")
        } catch (e: Exception) { env.toasts.error(e) } finally { exporting = false }
    }

    Page("Suppliers", subtitle = "Who the business buys from", refresh = { list.reload() }) {
        ModuleSearchField(search, { search = it }, "Name, email, phone or code", onSubmit = { apply() })
        OptionPickerValues("Status", status, listOf("active", "inactive", "suspended"), { status = it })
        FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
            OutlinedButton(enabled = !exporting, onClick = { scope.launch { export() } }) { if (exporting) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp) else Text("Export") }
            if (env.session.current.can("procurement.create")) Button(onClick = { creating = true }) { Text("New supplier") }
        }
        LoadableView(list.state, retry = { list.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("No suppliers match") }) { items ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                items.forEachIndexed { i, s ->
                    ModuleRowCard(onClick = { selected = s }) {
                        Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                            Text(s.string("name") ?: "", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                            StatusBadge(s.status)
                            s.string("type")?.let { StatusBadge(it, StatusTone.NEUTRAL) }
                        }
                        Text(listOfNotNull(s.string("company_code"), s.string("contact_person"), s.string("phone"), s.string("email")).joinToString(" · "), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, maxLines = 1)
                        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                            Text(s.string("city") ?: s.string("country") ?: "", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary, modifier = Modifier.weight(1f))
                            s["rating"].dbl?.takeIf { it > 0 }?.let { r ->
                                Icon(Icons.Default.Star, contentDescription = null, tint = Palette.amber, modifier = Modifier.size(14.dp))
                                Text(String.format(Locale.US, "%.1f", r), style = MaterialTheme.typography.labelSmall, color = Palette.amber)
                            }
                        }
                    }
                    if (i == items.lastIndex && list.hasMore) LaunchedEffect(items.size) { list.loadMore() }
                }
            }
        }
    }

    if (creating) SupplierFormSheet(null, onDismiss = { creating = false }) { list.load() }
    editing?.let { s -> SupplierFormSheet(s, onDismiss = { editing = null }) { list.load() } }
    selected?.let { s -> SupplierDetailSheet(s.id, onDismiss = { selected = null }, onEdit = { selected = null; editing = s }, onChanged = { list.load() }) }
}

// ── Create / edit (POST, PUT …/suppliers[/{id}]) ─────────────────────────────

private val supplierTypes = listOf("manufacturer", "wholesaler", "distributor", "other")
private val supplierTextFields: List<Pair<String, String>> = listOf(
    "company_code" to "Company code", "contact_person" to "Contact person", "email" to "Email", "phone" to "Phone", "alternate_phone" to "Alternate phone", "website" to "Website",
    "address_line_1" to "Address line 1", "address_line_2" to "Address line 2", "city" to "City", "state" to "State / county", "country" to "Country", "postal_code" to "Postal code",
    "tax_number" to "Tax number", "registration_number" to "Registration number", "payment_terms" to "Payment terms", "credit_limit" to "Credit limit",
    "bank_name" to "Bank", "bank_account_name" to "Account name", "bank_account_number" to "Account number", "bank_swift_code" to "SWIFT",
)

@Composable
private fun SupplierFormSheet(supplier: Record?, onDismiss: () -> Unit, onSaved: () -> Unit) {
    val env = LocalEnv.current
    val f = remember {
        mutableStateMapOf<String, String>().apply {
            put("name", supplier?.string("name") ?: ""); put("type", supplier?.string("type") ?: "wholesaler"); put("currency", supplier?.string("currency") ?: "KES")
            put("status", supplier?.status ?: "active"); put("notes", supplier?.string("notes") ?: "")
            supplierTextFields.forEach { (k, _) -> put(k, supplier?.get(k).str ?: "") }
        }
    }

    FormSheet(if (supplier == null) "New supplier" else "Edit ${supplier.string("name") ?: ""}", onDismiss, canSave = (f["name"] ?: "").isNotEmpty(), save = {
        val pairs = f.entries.mapNotNull { (k, v) ->
            when {
                k == "credit_limit" -> J.decimal(v)?.let { k to it }
                k == "company_code" && supplier == null && v.isEmpty() -> null
                k in setOf("name", "type", "status", "currency") -> k to JsonPrimitive(v)
                else -> k to J.s(v)
            }
        }
        val body = J.obj(*pairs.toTypedArray())
        if (supplier != null) env.client.put("/v1/admin/suppliers/${supplier.id}", body) else env.client.post("/v1/admin/suppliers", body)
        env.toasts.show("Supplier saved")
        onSaved()
    }) { error ->
        Field("Name", f["name"] ?: "", { f["name"] = it }, error = error?.field("name"))
        OptionPickerValues("Type", f["type"] ?: "", supplierTypes, { if (it.isNotEmpty()) f["type"] = it })
        OptionPicker("Currency", f["currency"] ?: "KES", listOf("KES" to "KES", "USD" to "USD"), { if (it.isNotEmpty()) f["currency"] = it })
        OptionPickerValues("Status", f["status"] ?: "active", listOf("active", "inactive", "suspended"), { if (it.isNotEmpty()) f["status"] = it })
        supplierTextFields.forEach { (key, label) ->
            if (key == "credit_limit") DecimalField(label, f[key] ?: "", { f[key] = it }, error = error?.field(key))
            else Field(label, f[key] ?: "", { f[key] = it }, error = error?.field(key))
        }
        Field("Notes", f["notes"] ?: "", { f["notes"] = it }, multiline = true)
    }
}

// ── Detail (GET …/suppliers/{id} → {supplier, stats, recent_orders}; performance, contacts, documents, rating) ──

@Composable
private fun SupplierDetailSheet(supplierId: String, onDismiss: () -> Unit, onEdit: () -> Unit, onChanged: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val canEdit = env.session.current.can("procurement.create")
    val res = remember(supplierId) { Resource(scope) { env.client.get("/v1/admin/suppliers/$supplierId").json ?: JsonNull } }
    val perf = remember(supplierId) { Resource(scope) { env.client.get("/v1/admin/suppliers/$supplierId/performance").json ?: JsonNull } }
    val contacts = remember(supplierId) { Resource(scope) { env.client.get("/v1/admin/suppliers/$supplierId/contacts").json.listRecords } }
    val documents = remember(supplierId) { Resource(scope) { env.client.get("/v1/admin/suppliers/$supplierId/documents").json.listRecords } }
    var rating by remember { mutableStateOf(0.0) }
    var review by remember { mutableStateOf("") }
    var addingContact by remember { mutableStateOf(false) }
    var docType by remember { mutableStateOf("other") }
    var uploading by remember { mutableStateOf(false) }
    val pickDocument = rememberFilePicker(arrayOf("application/pdf", "image/*", "*/*")) { file ->
        uploading = true
        scope.launch {
            try {
                env.client.uploadFile("/v1/admin/suppliers/$supplierId/documents", file, field = "document", fields = mapOf("document_type" to docType))
                env.toasts.show("Document uploaded"); documents.load()
            } catch (e: Exception) { env.toasts.error(e) } finally { uploading = false }
        }
    }
    fun loadAll() { res.load(); perf.load(); contacts.load(); documents.load() }
    LaunchedEffect(supplierId) { loadAll() }

    InfoSheet("Supplier", onDismiss) {
        if (canEdit) OutlinedButton(onClick = onEdit) { Text("Edit") }
        LoadableView(res.state, retry = { res.load() }) { j ->
            val s = j["supplier"]
            val st = j["stats"]
            Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                Text(s["name"].str ?: "", style = MaterialTheme.typography.titleLarge)
                StatusBadge(s["status"].str)
            }
            Text(listOfNotNull(s["company_code"].str, s["type"].str?.humanized, s["contact_person"].str, s["phone"].str, s["email"].str).joinToString(" · "), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
            TileGrid {
                StatTile("Orders", st["total_orders"].str ?: "0", modifier = TileModifier)
                StatTile("Open", st["pending_orders"].str ?: "0", modifier = TileModifier)
                val unconverted = st["unconverted_orders"].int ?: 0
                StatTile("Bought (KES)", st["total_value"].money?.formatted() ?: "—", hint = if (unconverted > 0) "$unconverted order(s) without a reporting rate left out" else "at the reporting rate", modifier = TileModifier)
            }
            DetailRow("Payment terms", s["payment_terms"].str)
            DetailRow("Currency", s["currency"].str)
            DetailRow("Address", listOfNotNull(s["address_line_1"].str, s["city"].str, s["country"].str).joinToString(", "))
            DetailRow("Last order", st["last_order_date"].str)

            if (canEdit) {
                Text("RATING", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                Row(verticalAlignment = Alignment.CenterVertically) {
                    val shown = if (rating > 0) rating else (s["rating"].dbl ?: 0.0)
                    (1..5).forEach { i -> IconButton(onClick = { rating = i.toDouble() }, modifier = Modifier.size(32.dp)) { Icon(if (i <= shown) Icons.Default.Star else Icons.Default.StarBorder, contentDescription = "$i", tint = Palette.amber) } }
                }
                Field("Review (optional)", review, { review = it })
                OutlinedButton(enabled = rating > 0, onClick = {
                    scope.launch {
                        try {
                            env.client.put("/v1/admin/suppliers/$supplierId/rating", jsonOf("rating" to rating, "review" to review.ifEmpty { null }))
                            env.toasts.show("Rating saved"); rating = 0.0; review = ""; loadAll(); onChanged()
                        } catch (e: Exception) { env.toasts.error(e) }
                    }
                }) { Text("Save rating") }
            }

            Text("Performance", style = MaterialTheme.typography.titleMedium)
            LoadableView(perf.state, retry = { perf.load() }) { p ->
                TileGrid {
                    StatTile("On time", p["on_time_deliveries"].str ?: "0", tone = StatusTone.SUCCESS, modifier = TileModifier)
                    StatTile("Late", p["late_deliveries"].str ?: "0", tone = if ((p["late_deliveries"].int ?: 0) > 0) StatusTone.DANGER else null, modifier = TileModifier)
                    StatTile("Avg variance (days)", p["avg_delivery_variance"].str ?: "—", modifier = TileModifier)
                    StatTile("Received", p["total_received"].str ?: "0", modifier = TileModifier)
                    StatTile("Rejected", p["total_rejected"].str ?: "0", modifier = TileModifier)
                    StatTile("Quality rate", p["quality_rate"]?.let { "${it.str ?: "—"}%" } ?: "—", modifier = TileModifier)
                }
            }

            Text("Recent orders", style = MaterialTheme.typography.titleMedium)
            val orders = j["recent_orders"].records
            if (orders.isEmpty()) Text("No purchase orders yet.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textTertiary)
            orders.forEach { o ->
                val po = PurchaseOrderRecord(Record(o))
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                    TextButton(onClick = { onDismiss(); env.navigator.push(Route.PurchaseOrder(po.id)) }) { Text(po.number) }
                    StatusBadge(po.status)
                    Spacer(Modifier.weight(1f))
                    MoneyText(po.total)
                }
            }

            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text("Contact history", style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
                if (canEdit) TextButton(onClick = { addingContact = true }) { Text("Add") }
            }
            LoadableView(contacts.state, retry = { contacts.load() }, isEmpty = { it.isEmpty() }, empty = { Text("No contact recorded", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textTertiary) }) { rows ->
                rows.forEach { c ->
                    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                            Text(c.string("subject") ?: "", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                            StatusBadge(c.string("contact_type"), StatusTone.NEUTRAL)
                            Text(c.string("contact_date") ?: "", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        }
                        Text(c.string("notes") ?: "", style = MaterialTheme.typography.bodySmall)
                    }
                    HorizontalDivider(color = MaterialTheme.hub.line)
                }
            }

            Text("Documents", style = MaterialTheme.typography.titleMedium)
            if (canEdit) {
                OptionPickerValues("Document type", docType, listOf("contract", "certificate", "tax_document", "other"), { if (it.isNotEmpty()) docType = it })
                OutlinedButton(enabled = !uploading, onClick = { pickDocument() }) { if (uploading) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp) else Text("Upload document") }
            }
            LoadableView(documents.state, retry = { documents.load() }, isEmpty = { it.isEmpty() }, empty = { Text("No documents", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textTertiary) }) { rows ->
                rows.forEach { d ->
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Default.Description, contentDescription = null, tint = MaterialTheme.hub.textTertiary, modifier = Modifier.size(18.dp))
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Text(d.string("file_name") ?: d.string("name") ?: "Document", style = MaterialTheme.typography.bodyMedium)
                            Text(listOfNotNull(d.string("document_type")?.humanized, d.string("description")).joinToString(" · "), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        }
                        DateText(d.date("created_at"), DateStyle.DATE)
                    }
                }
                // Supplier document rows carry no download URL (SupplierController::documents, audit F7): listed, not opened.
                ProcCaption("Documents are listed only; the server gives no link to open them.")
            }
        }
    }

    if (addingContact) AddContactSheet(supplierId, onDismiss = { addingContact = false }) { contacts.load() }
}

/** POST …/suppliers/{id}/contacts {contact_type, subject, notes, contact_date} */
@Composable
private fun AddContactSheet(supplierId: String, onDismiss: () -> Unit, onSaved: () -> Unit) {
    val env = LocalEnv.current
    var type by remember { mutableStateOf("phone") }
    var subject by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    var date by remember { mutableStateOf(Day.today()) }

    FormSheet("Record a contact", onDismiss, canSave = subject.isNotEmpty() && notes.isNotEmpty(), save = {
        env.client.post("/v1/admin/suppliers/$supplierId/contacts", jsonOf("contact_type" to type, "subject" to subject, "notes" to notes, "contact_date" to date))
        env.toasts.show("Contact recorded")
        onSaved()
    }) { error ->
        OptionPickerValues("Type", type, listOf("email", "phone", "meeting", "other"), { if (it.isNotEmpty()) type = it })
        Field("Subject", subject, { subject = it }, error = error?.field("subject"))
        Field("Notes", notes, { notes = it }, placeholder = "What was discussed", multiline = true, error = error?.field("notes"))
        DayField("Date", date, { if (it.isNotEmpty()) date = it })
    }
}
