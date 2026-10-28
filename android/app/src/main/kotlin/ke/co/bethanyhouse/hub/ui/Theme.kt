package ke.co.bethanyhouse.hub.ui

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.ColorScheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

/**
 * The Hub's visual identity on Android (Phase 10 design system), on Material 3.
 *
 * Tokens come from the console's tailwind palette so the five experiences
 * read as one product: brand orange `#F05423` for the primary action and
 * `#B23514` for brand text, warm greys for surfaces, deep navy for the
 * navigation chrome, and semantic ramps for status. Dark appearance is
 * derived here rather than inverted, so the brand stays orange on both.
 */
object Palette {
    val brand = Color(0xFFF05423)
    val brandDeep = Color(0xFFB23514)
    val brandTint = Color(0xFFF48364)
    val ink = Color(0xFF373A36)
    val nav = Color(0xFF0E1729)
    val navBorder = Color(0xFF1E2A44)
    val canvas = Color(0xFFF2F3F1)
    val field = Color(0xFFFAFBFA)
    val line = Color(0xFFE5E7E3)
    val textSecondary = Color(0xFF646B61)
    val textTertiary = Color(0xFF8A9086)

    val success = Color(0xFF15803D); val successSoft = Color(0xFFDCFCE7)
    val amber = Color(0xFFB45309); val amberSoft = Color(0xFFFEF3C7)
    val pink = Color(0xFFBE185D); val pinkSoft = Color(0xFFFCE7F3)
    val warning = Color(0xFFC2410C); val warningSoft = Color(0xFFFFEDD5)
    val danger = Color(0xFFB91C1C); val dangerSoft = Color(0xFFFEE2E2)
    val info = Color(0xFF1D4ED8); val infoSoft = Color(0xFFDBEAFE)
    val accent = Color(0xFF6D28D9); val accentSoft = Color(0xFFEDE9FE)
    val neutral = Color(0xFF4B5563); val neutralSoft = Color(0xFFF3F4F6)
}

/** Extra tokens Material's scheme has no slot for. */
data class HubColors(
    val canvas: Color, val card: Color, val field: Color, val line: Color,
    val text: Color, val textSecondary: Color, val textTertiary: Color,
)

val LocalHubColors = staticCompositionLocalOf {
    HubColors(Palette.canvas, Color.White, Palette.field, Palette.line, Palette.ink, Palette.textSecondary, Palette.textTertiary)
}

private val lightScheme: ColorScheme = lightColorScheme(
    primary = Palette.brand, onPrimary = Color.White,
    primaryContainer = Color(0xFFFFE4D6), onPrimaryContainer = Palette.brandDeep,
    secondary = Palette.brandDeep, onSecondary = Color.White,
    tertiary = Palette.accent,
    background = Palette.canvas, onBackground = Palette.ink,
    surface = Color.White, onSurface = Palette.ink,
    surfaceVariant = Palette.field, onSurfaceVariant = Palette.textSecondary,
    outline = Palette.line, outlineVariant = Palette.line,
    error = Palette.danger, onError = Color.White, errorContainer = Palette.dangerSoft, onErrorContainer = Palette.danger,
)

private val darkScheme: ColorScheme = darkColorScheme(
    primary = Palette.brand, onPrimary = Color.White,
    primaryContainer = Color(0xFF4A1E0E), onPrimaryContainer = Palette.brandTint,
    secondary = Palette.brandTint, onSecondary = Color.Black,
    tertiary = Color(0xFFC4B5FD),
    background = Color(0xFF121413), onBackground = Color(0xFFECEDEA),
    surface = Color(0xFF1C1F1D), onSurface = Color(0xFFECEDEA),
    surfaceVariant = Color(0xFF24282A), onSurfaceVariant = Color(0xFFA7ACA4),
    outline = Color(0xFF30353A), outlineVariant = Color(0xFF30353A),
    error = Color(0xFFF87171), onError = Color.Black, errorContainer = Color(0xFF3B1212), onErrorContainer = Color(0xFFFCA5A5),
)

private val darkHub = HubColors(Color(0xFF121413), Color(0xFF1C1F1D), Color(0xFF24282A), Color(0xFF30353A), Color(0xFFECEDEA), Color(0xFFA7ACA4), Color(0xFF7C827A))
private val lightHub = HubColors(Palette.canvas, Color.White, Palette.field, Palette.line, Palette.ink, Palette.textSecondary, Palette.textTertiary)

val HubShapes = Shapes(
    extraSmall = RoundedCornerShape(8.dp),
    small = RoundedCornerShape(12.dp),      // fields
    medium = RoundedCornerShape(14.dp),     // controls
    large = RoundedCornerShape(24.dp),      // cards
    extraLarge = RoundedCornerShape(28.dp), // sheets
)

val HubTypography = Typography(
    displaySmall = TextStyle(fontFamily = FontFamily.SansSerif, fontWeight = FontWeight.Bold, fontSize = 28.sp, lineHeight = 34.sp),
    headlineMedium = TextStyle(fontFamily = FontFamily.SansSerif, fontWeight = FontWeight.Bold, fontSize = 26.sp, lineHeight = 32.sp),
    headlineSmall = TextStyle(fontFamily = FontFamily.SansSerif, fontWeight = FontWeight.Bold, fontSize = 22.sp, lineHeight = 28.sp),
    titleLarge = TextStyle(fontFamily = FontFamily.SansSerif, fontWeight = FontWeight.SemiBold, fontSize = 20.sp, lineHeight = 26.sp),
    titleMedium = TextStyle(fontFamily = FontFamily.SansSerif, fontWeight = FontWeight.SemiBold, fontSize = 17.sp, lineHeight = 22.sp),
    titleSmall = TextStyle(fontFamily = FontFamily.SansSerif, fontWeight = FontWeight.SemiBold, fontSize = 15.sp, lineHeight = 20.sp),
    bodyLarge = TextStyle(fontFamily = FontFamily.SansSerif, fontSize = 16.sp, lineHeight = 22.sp),
    bodyMedium = TextStyle(fontFamily = FontFamily.SansSerif, fontSize = 15.sp, lineHeight = 21.sp),
    bodySmall = TextStyle(fontFamily = FontFamily.SansSerif, fontSize = 13.sp, lineHeight = 18.sp),
    labelLarge = TextStyle(fontFamily = FontFamily.SansSerif, fontWeight = FontWeight.SemiBold, fontSize = 15.sp),
    labelMedium = TextStyle(fontFamily = FontFamily.SansSerif, fontWeight = FontWeight.Medium, fontSize = 12.sp),
    labelSmall = TextStyle(fontFamily = FontFamily.SansSerif, fontWeight = FontWeight.Medium, fontSize = 11.sp, lineHeight = 14.sp),
)

object Space {
    val xs = 4.dp; val s = 8.dp; val m = 12.dp; val l = 16.dp; val xl = 24.dp; val xxl = 32.dp
    /** Side gutter for phone layouts. */
    val gutter = 16.dp
}

@Composable
fun HubTheme(darkTheme: Boolean = isSystemInDarkTheme(), content: @Composable () -> Unit) {
    androidx.compose.runtime.CompositionLocalProvider(LocalHubColors provides if (darkTheme) darkHub else lightHub) {
        MaterialTheme(colorScheme = if (darkTheme) darkScheme else lightScheme, shapes = HubShapes, typography = HubTypography, content = content)
    }
}

val MaterialTheme.hub: HubColors @Composable get() = LocalHubColors.current

/** The status families every module's badge resolves to (console `.badge-*`). */
enum class StatusTone(val foreground: Color, val background: Color) {
    SUCCESS(Palette.success, Palette.successSoft),
    AMBER(Palette.amber, Palette.amberSoft),
    PINK(Palette.pink, Palette.pinkSoft),
    WARNING(Palette.warning, Palette.warningSoft),
    DANGER(Palette.danger, Palette.dangerSoft),
    INFO(Palette.info, Palette.infoSoft),
    ACCENT(Palette.accent, Palette.accentSoft),
    NEUTRAL(Palette.neutral, Palette.neutralSoft);

    companion object {
        /** One mapping for the status strings the API uses across modules. */
        fun forStatus(status: String?): StatusTone = when (status?.lowercase() ?: "") {
            "paid", "completed", "approved", "delivered", "active", "published", "received", "done", "matched", "signed", "applied", "settled", "verified", "finalized", "closed" -> SUCCESS
            "pending", "pending_approval", "processing", "submitted", "requested", "open", "draft", "ordered", "scheduled", "in_transit", "sent", "counted" -> AMBER
            "in_progress", "partial", "deposit", "partially_received", "changes_requested", "on_hold", "sent_back", "held" -> PINK
            "unpaid", "pending_review", "flagged", "late", "missed", "expired", "returned", "short", "over", "mismatch", "awaiting_approval" -> WARNING
            "cancelled", "canceled", "rejected", "declined", "voided", "refunded", "failed", "deleted", "denied", "blocked", "withdrawn", "skipped" -> DANGER
            "confirmed", "shipped", "dispatched", "accepted", "issued", "locked" -> INFO
            else -> NEUTRAL
        }
    }
}
