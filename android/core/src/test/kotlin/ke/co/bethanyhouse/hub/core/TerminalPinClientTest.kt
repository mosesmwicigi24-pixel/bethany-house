package ke.co.bethanyhouse.hub.core

import kotlinx.coroutines.runBlocking
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.Assert.assertEquals
import org.junit.Assert.assertThrows
import org.junit.Test

class TerminalPinClientTest {
    @Test fun sendsOwnPinAndPasswordToServerAndPreservesRejection() = runBlocking {
        val server = MockWebServer()
        server.start()
        try {
            val client = ApiClient(server.url("/").toString().trimEnd('/').toHubBaseUrl(), DeviceIdentity("0123456789abcdef0123", "Android", "1"))
            val pins = TerminalPinClient(client)
            server.enqueue(MockResponse().setResponseCode(422).setHeader("Content-Type", "application/json").setBody("""{"message":"Your password is not right.","errors":{"current_password":["Your password is not right."]}}"""))
            val failure = assertThrows(ApiError::class.java) { runBlocking { pins.set("4821", "4821", "bad-password") } }
            assertEquals("Your password is not right.", failure.field("current_password"))
            val request = server.takeRequest()
            assertEquals("PUT", request.method)
            assertEquals("/api/v1/admin/profile/terminal-pin", request.path)
            val body = HubJson.parse(request.body.readUtf8())
            assertEquals("4821", body["pin"].str)
            assertEquals("4821", body["pin_confirmation"].str)
            assertEquals("bad-password", body["current_password"].str)
            assertEquals(1, server.requestCount)
        } finally { server.shutdown() }
    }
}
