package ke.co.bethanyhouse.hub.ui.features.procurement

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.RemoveCircleOutline
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.components.DecimalField
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.serialization.json.JsonElement
import java.math.BigDecimal
import java.time.Instant

// Shared vocabulary for the procurement module (ProcurementFeature.swift).
// Money on a purchase order is what the business agreed to pay the supplier,
// in the order's currency; every figure shown is the server's. A unit price
// is typed by a person, never derived here.

internal object POStatus {
    val all = listOf("draft", "pending_approval", "approved", "ordered", "partially_received", "received", "cancelled")
    val editable = setOf("draft", "pending_approval", "approved")
    val receivable = setOf("approved", "ordered", "partially_received")
    val returnable = setOf("ordered", "partially_received", "received")
}

/** Typed reads over a purchase order (PurchaseOrderController index/show). */
internal class PurchaseOrderRecord(val r: Record) {
    val id: Int get() = r.int("id") ?: 0
    val number: String get() = r.string("po_number") ?: "PO-$id"
    val status: String? get() = r.status
    val currency: String get() = r.string("currency_code") ?: r.string("currency") ?: "KES"
    val supplierName: String get() = r["supplier"]["name"].str ?: "Supplier"
    val total: Money? get() = r.money("total_amount", currency) ?: r.money("total", currency)
    val subtotal: Money? get() = r.money("subtotal", currency)
    val tax: Money? get() = r.money("tax_amount", currency) ?: r.money("tax", currency)
    val shipping: Money? get() = r.money("shipping_amount", currency) ?: r.money("shipping_cost", currency)
    val items: List<JsonElement> get() = r["items"].records
    val revision: Int get() = r.int("revision") ?: 0
    val expected: Instant? get() = r.date("expected_delivery_date")
}

/** The person's typed decimal, or null when blank/invalid. Used only to decide whether a form may be sent. */
internal fun procDecimal(text: String): BigDecimal? = text.replace(",", "").trim().takeIf { it.isNotEmpty() }?.let { runCatching { BigDecimal(it) }.getOrNull() }

/** A quantity difference printed without binary noise (quantities, never money). */
internal fun procQty(d: BigDecimal): String = d.stripTrailingZeros().let { if (it.scale() < 0) it.setScale(0) else it }.toPlainString()

internal fun procQtyOf(j: JsonElement?): BigDecimal = j.str?.let { procDecimal(it) } ?: BigDecimal.ZERO

/** The title of a purchase-order line: product (+ variant), material, or description. */
internal fun poLineTitle(i: JsonElement?): String {
    val p = i["product"]
    if (!p.isNull) {
        val name = p["translations"][0]["name"].str ?: p["name"].str ?: p["sku"].str
        return listOfNotNull(name, i["variant"]["variant_name"].str).joinToString(" · ")
    }
    val m = i["material"]
    if (!m.isNull) return m["name"].str ?: "Material"
    return i["description"].str ?: "Line"
}

/** A line editor row: title, quantity and unit price typed by the person, a remove button. */
@Composable
internal fun ProcLineEditor(title: String, note: String? = null, qty: String, onQty: (String) -> Unit, price: String, onPrice: (String) -> Unit, priceLabel: String = "Unit price", onRemove: () -> Unit) {
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Space.xs)) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(title, style = MaterialTheme.typography.bodyMedium)
                if (note != null) Text(note, style = MaterialTheme.typography.labelSmall, color = Palette.amber)
            }
            IconButton(onClick = onRemove) { Icon(Icons.Default.RemoveCircleOutline, contentDescription = "Remove", tint = Palette.danger) }
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            DecimalField("Qty", qty, onQty, Modifier.weight(1f))
            DecimalField(priceLabel, price, onPrice, Modifier.weight(1.4f))
        }
    }
}

@Composable
internal fun ProcCaption(text: String, color: androidx.compose.ui.graphics.Color? = null) {
    Text(text, style = MaterialTheme.typography.labelSmall, color = color ?: MaterialTheme.hub.textTertiary)
}
