package ke.co.bethanyhouse.hub.location

import android.annotation.SuppressLint
import android.app.Notification
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import android.os.Looper
import androidx.core.app.NotificationCompat
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import ke.co.bethanyhouse.hub.AppEnvironment
import ke.co.bethanyhouse.hub.MainActivity
import ke.co.bethanyhouse.hub.R
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.LocationFix
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.jsonOf
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import java.util.concurrent.CopyOnWriteArrayList

/**
 * Location on shift (People C1, docs/STORE_APP.md): between clock-in and
 * clock-out, when the attendance rule says *trail while clocked in*, a
 * foreground service with a visible notification records a position every
 * few minutes and posts the batch to `POST /v1/admin/time-clock/trail`.
 * Off shift nothing runs. The server keeps the trail for the retention
 * period and decides "left the site". It stops itself on 401 or when the
 * server answers `tracking: false`.
 */
class TrailService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Default)
    private val points = CopyOnWriteArrayList<JsonElement>()
    private var callback: LocationCallback? = null

    override fun onBind(intent: Intent?): IBinder? = null

    @SuppressLint("MissingPermission")
    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) { stopSelf(); return START_NOT_STICKY }
        val intervalMin = intent?.getIntExtra(EXTRA_INTERVAL_MIN, 5) ?: 5
        val distanceM = intent?.getIntExtra(EXTRA_DISTANCE_M, 100) ?: 100

        val notification = buildNotification()
        if (Build.VERSION.SDK_INT >= 29) startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION)
        else startForeground(NOTIFICATION_ID, notification)

        val fused = LocationServices.getFusedLocationProviderClient(this)
        val req = LocationRequest.Builder(Priority.PRIORITY_BALANCED_POWER_ACCURACY, intervalMin * 60_000L)
            .setMinUpdateDistanceMeters(maxOf(10, distanceM).toFloat())
            .build()
        val cb = object : LocationCallback() {
            override fun onLocationResult(result: LocationResult) {
                result.lastLocation?.let { points += LocationFix.from(it).trailPoint() }
            }
        }
        callback = cb
        fused.requestLocationUpdates(req, cb, Looper.getMainLooper())

        scope.launch {
            while (isActive) {
                delay(intervalMin * 60_000L)
                flush()
            }
        }
        return START_STICKY
    }

    private suspend fun flush() {
        if (points.isEmpty()) return
        val batch = points.takeLast(200)
        val env = AppEnvironment.of(this)
        try {
            val r = env.client.post("/v1/admin/time-clock/trail", jsonOf("points" to batch))
            points.removeAll(batch.toSet())
            if (r.json["tracking"].bool == false) stopSelf()
        } catch (e: ApiError) {
            if (e.status == 401 || (e.status in 400..499 && e.status != 429)) stopSelf()
            // Offline: keep the points (capped at 200) for the next flush.
            while (points.size > 200) points.removeAt(0)
        }
    }

    override fun onDestroy() {
        callback?.let { LocationServices.getFusedLocationProviderClient(this).removeLocationUpdates(it) }
        scope.cancel()
        super.onDestroy()
    }

    private fun buildNotification(): Notification {
        val pi = PendingIntent.getActivity(this, 0, Intent(this, MainActivity::class.java), PendingIntent.FLAG_IMMUTABLE)
        return NotificationCompat.Builder(this, "trail")
            .setSmallIcon(R.drawable.ic_splash)
            .setContentTitle("Clocked in at Bethany House")
            .setContentText("Your position is recorded every few minutes until you clock out.")
            .setOngoing(true)
            .setContentIntent(pi)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .build()
    }

    companion object {
        const val NOTIFICATION_ID = 4101
        const val ACTION_STOP = "ke.co.bethanyhouse.hub.trail.STOP"
        const val EXTRA_INTERVAL_MIN = "interval_min"
        const val EXTRA_DISTANCE_M = "distance_m"

        fun start(context: Context, intervalMinutes: Int, distanceMeters: Int) {
            val i = Intent(context, TrailService::class.java).putExtra(EXTRA_INTERVAL_MIN, intervalMinutes).putExtra(EXTRA_DISTANCE_M, distanceMeters)
            context.startForegroundService(i)
        }

        fun stop(context: Context) {
            context.startService(Intent(context, TrailService::class.java).setAction(ACTION_STOP))
        }
    }
}
