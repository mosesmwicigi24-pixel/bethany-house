package ke.co.bethanyhouse.hub

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import com.google.firebase.FirebaseApp
import com.google.firebase.FirebaseOptions
import com.google.firebase.messaging.FirebaseMessaging
import kotlinx.coroutines.launch

/** Bethany House Hub for Android (native apps programme, Steps 6–7). */
class HubApplication : Application() {
    lateinit var env: AppEnvironment
        private set

    override fun onCreate() {
        super.onCreate()
        env = AppEnvironment(this)
        createChannels()
        initFirebase()
        env.observeLifecycle()
    }

    private fun createChannels() {
        val nm = getSystemService(NotificationManager::class.java)
        nm.createNotificationChannel(NotificationChannel("default", "Hub notifications", NotificationManager.IMPORTANCE_HIGH).apply {
            description = "Approvals, orders, messages and everything the Hub tells you about"
        })
        nm.createNotificationChannel(NotificationChannel("trail", "On-shift location", NotificationManager.IMPORTANCE_LOW).apply {
            description = "Shown while your position is recorded between clock-in and clock-out"
        })
    }

    /**
     * Firebase is initialised from build config rather than google-services.json
     * so no credential file is committed. Without the four values push is off;
     * registration simply never happens and the server's FCM sender stays idle.
     */
    private fun initFirebase() {
        if (BuildConfig.FCM_PROJECT_ID.isBlank() || BuildConfig.FCM_APP_ID.isBlank() || BuildConfig.FCM_API_KEY.isBlank()) return
        val options = FirebaseOptions.Builder()
            .setProjectId(BuildConfig.FCM_PROJECT_ID)
            .setApplicationId(BuildConfig.FCM_APP_ID)
            .setApiKey(BuildConfig.FCM_API_KEY)
            .setGcmSenderId(BuildConfig.FCM_SENDER_ID)
            .build()
        FirebaseApp.initializeApp(this, options)
        FirebaseMessaging.getInstance().isAutoInitEnabled = true
        FirebaseMessaging.getInstance().token.addOnSuccessListener { token ->
            env.push.didReceive(token)
            env.scope.launch { env.push.register(env.session) }
        }
    }
}
