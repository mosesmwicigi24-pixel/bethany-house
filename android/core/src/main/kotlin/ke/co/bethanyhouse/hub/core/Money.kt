package ke.co.bethanyhouse.hub.core

import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.doubleOrNull
import kotlinx.serialization.json.longOrNull
import java.math.BigDecimal
import java.text.DecimalFormat
import java.text.DecimalFormatSymbols
import java.util.Locale

/**
 * A monetary amount as the server computed it.
 *
 * Clients never sum, convert or round money (CLAUDE.md §6, docs/api/README.md).
 * [Money] exists so a figure can be displayed exactly as received: decimal
 * strings are parsed as [BigDecimal], never as a float, and the currency rides
 * along so a KES and a USD figure can never be added by accident (there is
 * deliberately no `plus`).
 */
data class Money(val amount: BigDecimal, val currency: String = "KES") {
    val isZero: Boolean get() = amount.signum() == 0
    val isNegative: Boolean get() = amount.signum() < 0

    /** "KES 1,234.50" — the currency code first, as every Hub document prints it. */
    fun formatted(decimals: Int = 2): String {
        val symbols = DecimalFormatSymbols(Locale.US)
        val f = DecimalFormat("#,##0.${"0".repeat(decimals)}", symbols)
        return "$currency ${f.format(amount.setScale(decimals, java.math.RoundingMode.HALF_UP))}"
    }

    companion object {
        /** From a JSON field: "1234.50", 1234.5 or 1234. */
        fun from(json: JsonElement?, currency: String = "KES"): Money? {
            val p = json as? JsonPrimitive ?: return null
            if (p is JsonNull) return null
            if (p.isString) {
                val text = p.content.replace(",", "").trim()
                return runCatching { Money(BigDecimal(text), currency) }.getOrNull()
            }
            p.longOrNull?.let { return Money(BigDecimal.valueOf(it), currency) }
            // The server sent a JSON number; go through its decimal text so
            // binary noise never reaches the screen.
            p.doubleOrNull?.let { return Money(BigDecimal(String.format(Locale.US, "%.6f", it)).stripTrailingZeros().let { b -> if (b.scale() < 0) b.setScale(0) else b }, currency) }
            return null
        }

        fun parse(text: String, currency: String = "KES"): Money? =
            runCatching { Money(BigDecimal(text.replace(",", "").trim()), currency) }.getOrNull()
    }
}

/**
 * The server's shape for a list of rows in several currencies
 * (`App\Support\MoneyByCurrency`): a KES total at each row's own reporting
 * rate, the native figures beside it, and the unconvertible ones named.
 * Never a sum across currencies.
 */
data class MoneyByCurrency(
    val kesTotal: Money?,
    val byCurrency: List<Line>,
    val unconverted: List<JsonElement>,
) {
    data class Line(val currency: String, val native: Money?, val kes: Money?, val raw: JsonElement)

    companion object {
        fun from(json: JsonElement?): MoneyByCurrency? {
            val o = json.obj ?: return null
            val lines = o["by_currency"].records.map { row ->
                val code = row["currency"].str ?: row["code"].str ?: row["currency_code"].str ?: "?"
                Line(
                    currency = code,
                    native = Money.from(row["native"] ?: row["total"] ?: row["amount"], code),
                    kes = Money.from(row["kes"] ?: row["kes_total"] ?: row["amount_kes"], "KES"),
                    raw = row,
                )
            }
            return MoneyByCurrency(
                kesTotal = Money.from(o["kes_total"], "KES"),
                byCurrency = lines,
                unconverted = o["unconverted"].records,
            )
        }
    }
}
