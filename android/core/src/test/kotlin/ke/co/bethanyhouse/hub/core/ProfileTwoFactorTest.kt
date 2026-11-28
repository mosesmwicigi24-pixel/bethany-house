package ke.co.bethanyhouse.hub.core

import kotlinx.coroutines.runBlocking
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.Assert.assertEquals
import org.junit.Assert.assertThrows
import org.junit.Test

class ProfileTwoFactorTest {
    @Test fun setupAndConfirmationUseTheExistingAccountEndpoints() = runBlocking {
        val server = MockWebServer(); server.start()
        try {
            val client = ApiClient(server.url("/").toString().trimEnd('/').toHubBaseUrl(), DeviceIdentity("0123456789abcdef0123", "Android", "1"))
            val security = ProfileTwoFactor(client)
            server.enqueue(MockResponse().setBody("""{"secret_key":"TESTSECRET","qr_code_url":"otpauth://test"}"""))
            server.enqueue(MockResponse().setBody("""{"recovery_codes":["first-code","second-code"]}"""))
            assertEquals("TESTSECRET", security.begin().secret)
            assertEquals(listOf("first-code", "second-code"), security.confirm("123456"))
            assertEquals("/api/v1/admin/auth/2fa/enable", server.takeRequest().path)
            val confirmation = server.takeRequest()
            assertEquals("/api/v1/admin/auth/2fa/confirm", confirmation.path)
            assertEquals("123456", HubJson.parse(confirmation.body.readUtf8())["code"].str)
        } finally { server.shutdown() }
    }

    @Test fun serverRejectionIsReportedAndNotRetried() = runBlocking {
        val server = MockWebServer(); server.start()
        try {
            val client = ApiClient(server.url("/").toString().trimEnd('/').toHubBaseUrl(), DeviceIdentity("0123456789abcdef0123", "Android", "1"))
            val security = ProfileTwoFactor(client)
            server.enqueue(MockResponse().setResponseCode(422).setBody("""{"message":"The verification code is invalid.","errors":{"code":["The verification code is invalid."]}}"""))
            val error = assertThrows(ApiError::class.java) { runBlocking { security.confirm("000000") } }
            assertEquals("The verification code is invalid.", error.field("code"))
            assertEquals(1, server.requestCount)
        } finally { server.shutdown() }
    }
    @Test fun disableRequiresTheUsersPasswordAndHonorsServerRefusal() = runBlocking {
        val server = MockWebServer(); server.start()
        try {
            val client = ApiClient(server.url("/").toString().trimEnd('/').toHubBaseUrl(), DeviceIdentity("0123456789abcdef0123", "Android", "1"))
            server.enqueue(MockResponse().setResponseCode(403).setBody("""{"message":"Two-step sign-in is required for your role."}"""))
            val error = assertThrows(ApiError::class.java) { runBlocking { ProfileTwoFactor(client).disable("test-password") } }
            assertEquals("Two-step sign-in is required for your role.", error.message)
            val request = server.takeRequest()
            assertEquals("/api/v1/admin/auth/2fa/disable", request.path)
            assertEquals("test-password", HubJson.parse(request.body.readUtf8())["password"].str)
            assertEquals(1, server.requestCount)
        } finally { server.shutdown() }
    }

    @Test fun recoveryCodesAreReturnedOnlyFromTheRecoveryEndpoint() = runBlocking {
        val server = MockWebServer(); server.start()
        try {
            val client = ApiClient(server.url("/").toString().trimEnd('/').toHubBaseUrl(), DeviceIdentity("0123456789abcdef0123", "Android", "1"))
            server.enqueue(MockResponse().setBody("""{"recovery_codes":["fresh-code"]}"""))
            assertEquals(listOf("fresh-code"), ProfileTwoFactor(client).replaceRecoveryCodes())
            assertEquals("/api/v1/admin/auth/2fa/recovery-codes", server.takeRequest().path)
        } finally { server.shutdown() }
    }
}
