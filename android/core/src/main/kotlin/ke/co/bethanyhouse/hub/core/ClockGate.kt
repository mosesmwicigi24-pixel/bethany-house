package ke.co.bethanyhouse.hub.core

/** A known status is usable after an offline failure; a Hub error needs a refresh. */
object ClockGate {
    fun statusUsable(hasStatus: Boolean, error: ApiError?): Boolean =
        hasStatus && (error == null || error.isOffline)

    /** A manager override must be judged online with a fresh status. */
    fun forceAllowed(hasStatus: Boolean, error: ApiError?): Boolean =
        hasStatus && error == null
}
