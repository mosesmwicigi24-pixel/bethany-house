package ke.co.bethanyhouse.hub.core

/** Mirrors the server's terminal PIN guidance; the server remains authoritative. */
object TerminalPinRule {
    fun problem(pin: String): String? = when {
        pin.length !in 4..6 || pin.any { it !in '0'..'9' } -> "The PIN must be 4 to 6 digits."
        pin.all { it == pin.first() } -> "The PIN cannot be one digit repeated."
        "01234567890123".contains(pin) || "98765432109876".contains(pin) -> "The PIN cannot be a run of consecutive digits."
        else -> null
    }
}
