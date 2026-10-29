package ke.co.bethanyhouse.hub.core

import android.content.Context
import android.content.SharedPreferences
import android.os.Build
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import java.util.UUID

/**
 * Keystore-backed secret storage (Phase 12): the bearer token, the cached
 * `me`, the device id and the FCM token never touch plain SharedPreferences
 * or files. The master key lives in the Android Keystore; values are AES-GCM.
 */
class SecureStore private constructor(private val prefs: SharedPreferences) {
    constructor(context: Context, name: String = "bh_secure") : this(encrypted(context, name))

    fun get(account: String): String? = prefs.getString(account, null)
    fun set(account: String, value: String) { prefs.edit().putString(account, value).apply() }
    fun remove(account: String) { prefs.edit().remove(account).apply() }

    companion object {
        private fun encrypted(context: Context, name: String): SharedPreferences {
            val key = MasterKey.Builder(context.applicationContext)
                .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
                .build()
            return EncryptedSharedPreferences.create(
                context.applicationContext,
                name,
                key,
                EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
                EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
            )
        }

        /**
         * Plain SharedPreferences, for JVM tests only (Robolectric has no
         * Android Keystore). Never used by the app: the only production
         * constructor is the Keystore-backed one above.
         */
        @androidx.annotation.VisibleForTesting
        fun unencryptedForTests(prefs: SharedPreferences): SecureStore = SecureStore(prefs)
    }
}

/**
 * The stable per-install identity the Hub keys named devices on. Sent as
 * `X-Device-Id` on every request (App\Services\Auth\Devices) and stored with
 * the push registration. Kept in the secure store so it survives app updates.
 */
data class DeviceIdentity(val id: String, val model: String, val appVersion: String) {
    val platform: String get() = "android"
    val userAgent: String get() = "BethanyHub/$appVersion (android; $model)"

    companion object {
        private const val ACCOUNT = "bh_device_id"
        private val valid = Regex("^[A-Za-z0-9-]{16,64}$")

        fun current(store: SecureStore, appVersion: String): DeviceIdentity {
            val existing = store.get(ACCOUNT)
            val id = if (existing != null && valid.matches(existing)) existing else UUID.randomUUID().toString().lowercase().also { store.set(ACCOUNT, it) }
            val model = listOf(Build.MANUFACTURER, Build.MODEL).filter { it.isNotBlank() }.joinToString(" ").trim().ifEmpty { "Android" }
            return DeviceIdentity(id, model, appVersion)
        }
    }
}
