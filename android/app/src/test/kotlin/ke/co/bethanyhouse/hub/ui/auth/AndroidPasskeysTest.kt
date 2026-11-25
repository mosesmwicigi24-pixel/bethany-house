package ke.co.bethanyhouse.hub.ui.auth

import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.DeviceIdentity
import ke.co.bethanyhouse.hub.core.toHubBaseUrl
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.Assert.*
import org.junit.Test
import java.util.concurrent.TimeUnit

class AndroidPasskeysTest {
    private val credential = """{"id":"credential-id","type":"public-key","response":{"clientDataJSON":"abc"}}"""

    @Test fun registrationPassesServerOptionsAndSubmitsCredential() = runBlocking {
        val server = MockWebServer(); server.start()
        try {
            server.enqueue(MockResponse().setBody("""{"options":{"challenge":"challenge","rp":{"id":"hub.test"}}}"""))
            server.enqueue(MockResponse().setResponseCode(201).setBody("{}"))
            val provider = object : PasskeyProvider {
                override suspend fun create(options: String): String { assertEquals("challenge", Json.parseToJsonElement(options).jsonObject["challenge"]?.toString()?.trim('"')); return credential }
                override suspend fun get(options: String): String = error("unexpected")
            }
            AndroidPasskeys(client(server), provider).register("Workshop phone")
            assertEquals("/api/v1/admin/profile/passkeys/options", server.takeRequest().path)
            val request = server.takeRequest()
            assertEquals("/api/v1/admin/profile/passkeys", request.path)
            val body = Json.parseToJsonElement(request.body.readUtf8()).jsonObject
            assertEquals("\"Workshop phone\"", body["name"].toString())
            assertEquals("\"credential-id\"", body["credential"]?.jsonObject?.get("id").toString())
        } finally { server.shutdown() }
    }

    @Test fun failedProviderDoesNotSubmitAssertion() = runBlocking {
        val server = MockWebServer(); server.start()
        try {
            server.enqueue(MockResponse().setBody("""{"options":{"challenge":"challenge"}}"""))
            val provider = object : PasskeyProvider {
                override suspend fun create(options: String): String = error("unexpected")
                override suspend fun get(options: String): String = throw IllegalStateException("cancelled")
            }
            try { AndroidPasskeys(client(server), provider).stepUp(); fail("Expected provider failure") }
            catch (e: IllegalStateException) { assertEquals("cancelled", e.message) }
            assertEquals("/api/v1/admin/auth/step-up/passkey/options", server.takeRequest().path)
            assertNull(server.takeRequest(100, TimeUnit.MILLISECONDS))
        } finally { server.shutdown() }
    }

    @Test fun successfulAssertionUsesTheServerChallengeAndPostsCredential() = runBlocking {
        val server = MockWebServer(); server.start()
        try {
            server.enqueue(MockResponse().setBody("""{"options":{"challenge":"assertion-challenge"}}"""))
            server.enqueue(MockResponse().setBody("{}"))
            val provider = object : PasskeyProvider {
                override suspend fun create(options: String): String = error("unexpected")
                override suspend fun get(options: String): String { assertTrue(options.contains("assertion-challenge")); return credential }
            }
            AndroidPasskeys(client(server), provider).stepUp()
            assertEquals("/api/v1/admin/auth/step-up/passkey/options", server.takeRequest().path)
            val request = server.takeRequest()
            assertEquals("/api/v1/admin/auth/step-up/passkey", request.path)
            assertEquals("\"credential-id\"", Json.parseToJsonElement(request.body.readUtf8()).jsonObject["credential"]?.jsonObject?.get("id").toString())
        } finally { server.shutdown() }
    }

    private fun client(server: MockWebServer) = ApiClient(server.url("/").toString().trimEnd('/').toHubBaseUrl(), DeviceIdentity("0123456789abcdef0123", "Android", "1"))
}
