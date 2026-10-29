package ke.co.bethanyhouse.hub.push

import android.app.PendingIntent
import android.content.Intent
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import ke.co.bethanyhouse.hub.AppEnvironment
import ke.co.bethanyhouse.hub.MainActivity
import ke.co.bethanyhouse.hub.R
import ke.co.bethanyhouse.hub.core.PushPayload
import kotlinx.coroutines.launch

/**
 * FCM entry point. A new registration token is sent to the Hub; a message
 * (FcmSender data map: title/body/url/icon) becomes a notification on the
 * `default` channel whose tap opens the route its `url` names.
 */
class HubMessagingService : FirebaseMessagingService() {
    override fun onNewToken(token: String) {
        val env = AppEnvironment.of(this)
        env.push.didReceive(token)
        env.scope.launch { env.push.register(env.session) }
    }

    override fun onMessageReceived(message: RemoteMessage) {
        val payload = PushPayload.from(message.data, message.notification?.title, message.notification?.body)
        val intent = Intent(this, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP
            putExtra(MainActivity.EXTRA_ROUTE_PATH, message.data["url"])
        }
        val pi = PendingIntent.getActivity(this, (System.currentTimeMillis() % Int.MAX_VALUE).toInt(), intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        val notification = NotificationCompat.Builder(this, "default")
            .setSmallIcon(R.drawable.ic_splash)
            .setContentTitle(payload.title ?: "Bethany House")
            .setContentText(payload.body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(payload.body))
            .setAutoCancel(true)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setContentIntent(pi)
            .setGroup(payload.icon ?: "bh")
            .build()
        try {
            NotificationManagerCompat.from(this).notify((System.currentTimeMillis() % Int.MAX_VALUE).toInt(), notification)
        } catch (_: SecurityException) {
            // POST_NOTIFICATIONS not granted: the in-app badge still updates via realtime.
        }
        AppEnvironment.of(this).let { env -> env.scope.launch { env.unreadNotifications += 1 } }
    }
}
