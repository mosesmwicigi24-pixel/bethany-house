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
        prefs.edit().clear().putString("bh_admin_token", LiveHub.token ?: "test-token").commit()
        return AppEnvironment(this, hubUrl = LiveHub.url ?: MockHub.url, secureStore = SecureStore.unencryptedForTests(prefs))
    }
}

/**
 * A real backend for [LiveRenderTest], set with
 * `-PhubE2eUrl=http://127.0.0.1:8077 -PhubE2eToken=<sanctum token>`.
 * Unset (CI, normal runs) means the mock Hub below.
 */
object LiveHub {
    val url: String? = System.getProperty("hub.e2e.url")?.takeIf { it.isNotBlank() }
    val token: String? = System.getProperty("hub.e2e.token")?.takeIf { it.isNotBlank() }
}

/** A Hub that answers every list endpoint with an empty page: the empty-and-zero case for every screen. */
object MockHub {
    val requests = java.util.concurrent.CopyOnWriteArrayList<String>()
    /** Bodies of kiosk punches, in order. */
    val punches = java.util.concurrent.CopyOnWriteArrayList<String>()
    /** When set, every kiosk endpoint answers with this status (401: the kiosk was removed on the Hub). */
    @Volatile var kioskStatus: Int? = null
    /** "POST path body" of every order-payment write, in order. */
    val payments = java.util.concurrent.CopyOnWriteArrayList<String>()
    /** How verify-mpesa answers: 202 (asked Safaricom) or 422 (not confirmed). */
    @Volatile var mpesaVerifyStatus: Int = 202

    private val server: MockWebServer by lazy {
        MockWebServer().apply {
            dispatcher = object : Dispatcher() {
                override fun dispatch(request: RecordedRequest): MockResponse {
                    val path = request.path?.substringBefore('?') ?: ""
                    requests += "${request.method} $path"
                    if (request.method == "POST" && path.endsWith("/kiosk/punch")) punches += request.body.readUtf8()
                    if (request.method == "POST" && (path.endsWith("/verify-mpesa") || Regex("/orders/\\d+/payments$").containsMatchIn(path))) {
                        payments += "POST $path ${request.body.readUtf8()}"
                        if (path.endsWith("/verify-mpesa")) {
                            val code = mpesaVerifyStatus
                            val reply = if (code == 202) MPESA_ASKED else MPESA_NOT_CONFIRMED
                            return MockResponse().setResponseCode(code).setHeader("Content-Type", "application/json").setBody(reply)
                        }
                        return MockResponse().setResponseCode(201).setHeader("Content-Type", "application/json").setBody(PAYMENT_RECORDED)
                    }
                    val forced = kioskStatus
                    if (forced != null && path.contains("/kiosk/")) {
                        return MockResponse().setResponseCode(forced).setHeader("Content-Type", "application/json").setBody(UNAUTHENTICATED)
                    }
                    val body = when {
                        path.endsWith("/auth/me") -> ME
                        path.endsWith("/kiosk/board") -> KIOSK_BOARD
                        path.endsWith("/kiosk/punch") -> PUNCHED
                        request.method == "POST" && path.endsWith("/attendance/kiosks") -> KIOSK_CREATED
                        path.endsWith("/v1/admin/outlets") -> OUTLETS
                        path.endsWith("/v1/payment-methods") -> PAYMENT_METHODS
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

    private const val UNAUTHENTICATED = """{"message":"Unauthenticated."}"""

    private const val PAYMENT_METHODS = """{"data":[{"code":"mpesa","name":"M-Pesa","type":"mobile_money","is_active":true,"supported_currencies":[]},{"code":"cash","name":"Cash","type":"cash","is_active":true,"supported_currencies":[]}]}"""

    private const val MPESA_ASKED = """{"message":"Asked Safaricom to confirm receipt QHK7ABC123 — the payment will update here automatically when they answer (usually within a minute).","pending":true,"payment_status":"pending"}"""

    private const val MPESA_NOT_CONFIRMED = """{"message":"Could not confirm payment with Daraja. Please check the transaction code and try again."}"""

    private const val PAYMENT_RECORDED = """{"message":"Payment recorded","payment":{"id":77},"requires_approval":false}"""

    private const val OUTLETS = """{"data":[{"id":1,"name":"Westlands","code":"WST"}],"meta":{"current_page":1,"last_page":1,"total":1}}"""

    private const val KIOSK_CREATED = """{"message":"This tablet is now the kiosk at Westlands.","token":"5|new-kiosk-token","kiosk":{"id":4,"name":"Front door","outlet_id":1,"outlet":"Westlands"}}"""

    private const val PUNCHED ="""{"message":"Amina clocked in at 08:02.","entry_id":31}"""

    private const val KIOSK_BOARD = """{"kiosk":{"id":4,"name":"Front door","outlet_id":1,"outlet":"Westlands Store"},"people":[
        {"id":7,"name":"Amina Njeri","initials":"AN","has_pin":true,"state":"out","since":null,"elsewhere":null},
        {"id":8,"name":"Baraka Otieno","initials":"BO","has_pin":true,"state":"in","since":"2026-10-29T08:02:00+03:00","elsewhere":null}
    ],"rules":{"clock_in_without_shift":"allow"},"server_time":"2026-10-29T09:00:00+03:00"}"""

    private const val ME = """{"user":{
        "id":1,"first_name":"Test","last_name":"Owner","email":"owner@example.com","user_type":"system",
        "permissions":[],"roles":[{"id":1,"name":"super_admin","data_scope":"business"}],
        "is_owner":true,"money_view":{"range":"business","period":"any","label":"across your business"},"outlet":{"id":1,"name":"Westlands"},
        "terminal_pin_set":true,"two_factor_required":false,"two_factor_enabled":true,"recovery_codes_left":8
    }}"""
}
