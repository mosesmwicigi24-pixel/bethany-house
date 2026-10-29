package ke.co.bethanyhouse.hub.ui.components

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.ui.draw.clip
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.core.date
import ke.co.bethanyhouse.hub.core.dbl
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject

// JSON-backed rows, a breakdown table and small charts for the Reports,
// Finance, Expenses and Catalog modules. A cell prints the server's figure
// with the currency the payload names; nothing is computed here.

/** One element of a JSON array, addressable by index. */
data class JRow(val id: Int, val json: JsonElement) {
    operator fun get(key: String): JsonElement? = json[key]
    fun str(key: String) = json[key].str
    fun int(key: String) = json[key].int
    fun dbl(key: String) = json[key].dbl
    fun money(key: String, currency: String? = null): Money? = Money.from(json[key], currency ?: json["currency"].str ?: json["currency_code"].str ?: "KES")
}

fun jrows(value: JsonElement?): List<JRow> = value.records.mapIndexed { i, e -> JRow(i, e) }

data class JPair(val key: String, val value: JsonElement)
fun jpairs(value: JsonElement?): List<JPair> = (value as? JsonObject)?.entries?.sortedBy { it.key }?.map { JPair(it.key, it.value) } ?: emptyList()

val JsonElement?.kes: Money? get() = Money.from(this, "KES")

class TableColumn(val title: String, val key: String, val kind: Kind = Kind.TEXT, val currencyKey: String? = null, val fixedCurrency: String? = null, val decimals: Int = 2, val width: Dp = 120.dp) {
    enum class Kind { TEXT, INT, NUMBER, MONEY, PCT, DATE, STATUS }
    val trailing: Boolean get() = kind != Kind.TEXT && kind != Kind.STATUS && kind != Kind.DATE
    fun text(row: JRow): String {
        val v = row[key]
        return when (kind) {
            Kind.TEXT -> v.str ?: "—"
            Kind.INT -> fmtInt(v)
            Kind.NUMBER -> fmtNumber(v.dbl, decimals)
            Kind.MONEY -> fmtMoney(v, fixedCurrency ?: currencyKey?.let { row.str(it) } ?: row.str("currency") ?: row.str("currency_code") ?: "KES")
            Kind.PCT -> fmtPct(v.dbl)
            Kind.DATE -> v.date?.let { formatDate(it, DateStyle.DATE) } ?: v.str ?: "—"
            Kind.STATUS -> (v.str ?: "—").humanized
        }
    }
}

/** A breakdown table from JSON rows; scrolls sideways on narrow screens. */
@Composable
fun DataTable(columns: List<TableColumn>, rows: List<JRow>, onTap: ((JRow) -> Unit)? = null, emptyText: String = "Nothing in this period.") {
    if (rows.isEmpty()) { Text(emptyText, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textTertiary, modifier = Modifier.padding(vertical = Space.s)); return }
    Column(Modifier.horizontalScroll(rememberScrollState())) {
        Row(Modifier.padding(vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(Space.l)) {
            columns.forEach { c -> Text(c.title.uppercase(), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, textAlign = if (c.trailing) TextAlign.End else TextAlign.Start, modifier = Modifier.width(c.width)) }
        }
        HorizontalDivider(color = MaterialTheme.hub.line)
        rows.forEach { row ->
            Row(Modifier.then(if (onTap != null) Modifier.clickable { onTap(row) } else Modifier).padding(vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(Space.l), verticalAlignment = Alignment.CenterVertically) {
                columns.forEach { c ->
                    Box(Modifier.width(c.width), contentAlignment = if (c.trailing) Alignment.CenterEnd else Alignment.CenterStart) {
                        when (c.kind) {
                            TableColumn.Kind.STATUS -> StatusBadge(row.str(c.key))
                            TableColumn.Kind.MONEY -> { val t = c.text(row); Text(t, style = MaterialTheme.typography.bodySmall.copy(fontFamily = FontFamily.Monospace), color = if (t.contains("-")) Palette.danger else MaterialTheme.hub.text, maxLines = 1) }
                            else -> Text(c.text(row), style = MaterialTheme.typography.bodySmall, maxLines = 2, overflow = TextOverflow.Ellipsis)
                        }
                    }
                }
            }
        }
    }
}

// ── Charts ───────────────────────────────────────────────────────────────────

data class ChartPoint(val id: Int, val label: String, val value: Double, val series: String = "")

fun chartPoints(value: JsonElement?, label: String, y: String, series: String = ""): List<ChartPoint> =
    jrows(value).mapNotNull { r -> r.dbl(y)?.let { ChartPoint(r.id, r.str(label) ?: r.id.toString(), it, series) } }

private val seriesColors = listOf(Palette.brand, Palette.info, Palette.success, Palette.accent, Palette.amber)

/** A simple bar chart; the axis caption names the currency. */
@Composable
fun BarsChart(points: List<ChartPoint>, currency: String? = "KES", height: Dp = 180.dp) {
    if (points.isEmpty()) { Text("No data to chart.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary); return }
    val max = points.maxOf { it.value }.takeIf { it > 0 } ?: 1.0
    val line = MaterialTheme.hub.line
    Column {
        currency?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
        Canvas(Modifier.fillMaxWidth().height(height)) {
            val n = points.size
            val gap = 4.dp.toPx()
            val w = (size.width - gap * (n - 1)) / n
            drawLine(line, Offset(0f, size.height), Offset(size.width, size.height), 1f)
            points.forEachIndexed { i, p ->
                val h = (p.value / max * (size.height - 8.dp.toPx())).toFloat().coerceAtLeast(0f)
                drawRect(Palette.brand, Offset(i * (w + gap), size.height - h), Size(w, h))
            }
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            val step = maxOf(1, points.size / 6)
            points.filterIndexed { i, _ -> i % step == 0 }.forEach { Text(it.label.take(8), fontSize = 9.sp, color = MaterialTheme.hub.textTertiary) }
        }
    }
}

/** One or more lines over an ordered x axis (dates or buckets). */
@Composable
fun LinesChart(points: List<ChartPoint>, currency: String? = "KES", height: Dp = 180.dp) {
    if (points.isEmpty()) { Text("No data to chart.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary); return }
    val series = points.groupBy { it.series }
    val labels = points.map { it.label }.distinct()
    val max = points.maxOf { it.value }.takeIf { it > 0 } ?: 1.0
    val min = minOf(0.0, points.minOf { it.value })
    val line = MaterialTheme.hub.line
    Column {
        Row(horizontalArrangement = Arrangement.spacedBy(Space.m)) {
            currency?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
            series.keys.filter { it.isNotEmpty() }.forEachIndexed { i, s -> Text("● $s", style = MaterialTheme.typography.labelSmall, color = seriesColors[i % seriesColors.size]) }
        }
        Canvas(Modifier.fillMaxWidth().height(height)) {
            drawLine(line, Offset(0f, size.height), Offset(size.width, size.height), 1f)
            val range = (max - min).takeIf { it > 0 } ?: 1.0
            series.values.forEachIndexed { si, pts ->
                val color = seriesColors[si % seriesColors.size]
                val path = Path()
                pts.forEachIndexed { i, p ->
                    val xi = labels.indexOf(p.label).coerceAtLeast(0)
                    val x = if (labels.size <= 1) size.width / 2 else xi * size.width / (labels.size - 1)
                    val y = (size.height - ((p.value - min) / range * (size.height - 8.dp.toPx()))).toFloat()
                    if (i == 0) path.moveTo(x, y) else path.lineTo(x, y)
                    drawCircle(color, 3.dp.toPx(), Offset(x, y))
                }
                drawPath(path, color, style = Stroke(width = 2.dp.toPx()))
            }
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            val step = maxOf(1, labels.size / 6)
            labels.filterIndexed { i, _ -> i % step == 0 }.forEach { Text(it.take(8), fontSize = 9.sp, color = MaterialTheme.hub.textTertiary) }
        }
    }
}

/** A remote image (product, category, receipt) with a placeholder; `signed` sources go through the API's link issuer. */
@Composable
fun RemoteImage(url: String?, size: Dp = 56.dp, modifier: Modifier = Modifier) {
    val env = ke.co.bethanyhouse.hub.LocalEnv.current
    val resolved = url?.let { if (it.startsWith("/")) env.config.hubUrl.trimEnd('/') + it else it }
    Box(modifier.width(size).height(size).background(MaterialTheme.hub.line, MaterialTheme.shapes.small).clip(MaterialTheme.shapes.small), contentAlignment = Alignment.Center) {
        if (resolved == null) Text("—", color = MaterialTheme.hub.textTertiary)
        else coil.compose.AsyncImage(model = coil.request.ImageRequest.Builder(androidx.compose.ui.platform.LocalContext.current).data(resolved).crossfade(true).build(), contentDescription = null, contentScale = androidx.compose.ui.layout.ContentScale.Crop, modifier = Modifier.fillMaxWidth().height(size))
    }
}
