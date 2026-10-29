package ke.co.bethanyhouse.hub.core

import android.annotation.SuppressLint
import android.content.Context
import android.location.Location
import android.os.Build
import android.os.Looper
import com.google.android.gms.location.CurrentLocationRequest
import com.google.android.gms.location.FusedLocationProviderClient
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.tasks.await
import kotlinx.coroutines.withTimeoutOrNull
import kotlinx.serialization.json.JsonElement
import java.time.Instant
import kotlin.math.atan2
import kotlin.math.cos
import kotlin.math.sin
import kotlin.math.sqrt

/**
 * One fix from the phone, with the mock flag the server records as
 * `mock_location` (docs/STORE_APP.md: Android reports when a fake-GPS app
 * supplied the fix).
 */
data class LocationFix(val latitude: Double, val longitude: Double, val accuracyMeters: Double?, val at: Instant, val mocked: Boolean) {
    /** Exactly what `TimeClockController::trail` validates. */
    fun trailPoint(): JsonElement = jsonOf(
        "latitude" to latitude, "longitude" to longitude, "accuracy" to accuracyMeters?.toInt(),
        "recorded_at" to HubDates.iso(at), "mocked" to mocked,
    )

    companion object {
        fun from(l: Location): LocationFix = LocationFix(
            latitude = l.latitude, longitude = l.longitude,
            accuracyMeters = if (l.hasAccuracy()) l.accuracy.toDouble() else null,
            at = Instant.ofEpochMilli(l.time),
            mocked = if (Build.VERSION.SDK_INT >= 31) l.isMock else @Suppress("DEPRECATION") l.isFromMockProvider,
        )
    }
}

/** The console's geofence rule (ClockPage.tsx): inside, near the edge, or outside. */
object Geofence {
    enum class Verdict { INSIDE, EDGE, OUTSIDE }
    private const val EARTH_RADIUS_M = 6_371_000.0

    fun distanceMeters(lat1: Double, lon1: Double, lat2: Double, lon2: Double): Double {
        val dLat = Math.toRadians(lat2 - lat1)
        val dLon = Math.toRadians(lon2 - lon1)
        val a = sin(dLat / 2) * sin(dLat / 2) + cos(Math.toRadians(lat1)) * cos(Math.toRadians(lat2)) * sin(dLon / 2) * sin(dLon / 2)
        return EARTH_RADIUS_M * 2 * atan2(sqrt(a), sqrt(1 - a))
    }

    /** No zone (radius null/0) = inside. The edge allowance is the fix accuracy, capped at the radius. */
    fun verdict(distance: Double, radius: Double?, accuracy: Double?): Verdict {
        if (radius == null || radius <= 0) return Verdict.INSIDE
        if (distance <= radius) return Verdict.INSIDE
        val allowance = minOf(accuracy ?: 0.0, radius)
        return if (distance <= radius + allowance) Verdict.EDGE else Verdict.OUTSIDE
    }
}

/** Fused location with the mock flag; continuous updates for the clock screen, one-shot for a punch. */
class LocationService(context: Context) {
    private val fused: FusedLocationProviderClient = LocationServices.getFusedLocationProviderClient(context.applicationContext)
    private val _latest = MutableStateFlow<LocationFix?>(null)
    val latest: StateFlow<LocationFix?> = _latest.asStateFlow()
    private var callback: LocationCallback? = null

    @SuppressLint("MissingPermission")
    suspend fun currentFix(timeoutMs: Long = 20_000): LocationFix? = withTimeoutOrNull(timeoutMs) {
        val req = CurrentLocationRequest.Builder().setPriority(Priority.PRIORITY_HIGH_ACCURACY).setMaxUpdateAgeMillis(5_000).build()
        fused.getCurrentLocation(req, null).await()?.let(LocationFix::from)?.also { _latest.value = it }
    }

    @SuppressLint("MissingPermission")
    fun startUpdating(intervalMs: Long = 3_000, minDistanceM: Float = 1f) {
        stopUpdating()
        val req = LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, intervalMs).setMinUpdateDistanceMeters(minDistanceM).build()
        val cb = object : LocationCallback() {
            override fun onLocationResult(result: LocationResult) { result.lastLocation?.let { _latest.value = LocationFix.from(it) } }
        }
        callback = cb
        fused.requestLocationUpdates(req, cb, Looper.getMainLooper())
    }

    fun stopUpdating() {
        callback?.let { fused.removeLocationUpdates(it) }
        callback = null
    }
}
