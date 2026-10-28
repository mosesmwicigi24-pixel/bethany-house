package ke.co.bethanyhouse.hub.render

import android.content.Context
import ke.co.bethanyhouse.hub.AppEnvironment
import ke.co.bethanyhouse.hub.HubApplication
import ke.co.bethanyhouse.hub.core.SecureStore
import okhttp3.mockwebserver.Dispatcher
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import okhttp3.mockwebserver.RecordedRequest

/**
 * The real application, pointed at a local mock Hub and a plain store
 * (Robolectric has no Keystore), already holding a bearer token so the
 * session restores as a fixture super admin.
 */
class TestHubApp : HubApplication() {
    override fun createEnvironment(): AppEnvironment {
        val prefs = getSharedPreferences("bh_secure_test", Context.MODE_PRIVATE)
        prefs.edit().clear().putString("bh_admin_token", "test-token").commit()
        return AppEnvironment(this, hubUrl = MockHub.url, secureStore = SecureStore.unencryptedForTests(prefs))
    }
}

/** A Hub that answers every list endpoint with an empty page: the empty-and-zero case for every screen. */
object MockHub {
    val requests = java.util.concurrent.CopyOnWriteArrayList<String>()

    private val server: MockWebServer by lazy {
        MockWebServer().apply {
            dispatcher = object : Dispatcher() {
                override fun dispatch(request: RecordedRequest): MockResponse {
                    val path = request.path?.substringBefore('?') ?: ""
                    requests += "${request.method} $path"
                    val body = when {
                        path.endsWith("/auth/me") -> ME
                        else -> EMPTY_PAGE
                    }
                    return MockResponse().setResponseCode(200).setHeader("Content-Type", "application/json").setBody(body)
                }
            }
            start()
        }
    }

    val url: String get() = server.url("/").toString().trimEnd('/')

    private const val EMPTY_PAGE = """{"data":[],"meta":{"current_page":1,"last_page":1,"per_page":25,"total":0},"current_page":1,"last_page":1,"total":0}"""

    private const val ME = """{"user":{
        "id":1,"name":"Test Owner","email":"owner@example.com","user_type":"staff",
        "permissions":[],"roles":[{"id":1,"name":"super_admin","data_scope":"business"}],
        "is_owner":true,"money_view":"all","outlet":{"id":1,"name":"Westlands"},
        "terminal_pin_set":true,"two_factor_required":false,"two_factor_enabled":true,"recovery_codes_left":8
    }}"""
}
