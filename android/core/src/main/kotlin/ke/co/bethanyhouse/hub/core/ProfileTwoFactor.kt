package ke.co.bethanyhouse.hub.core

/** The account's existing two-step endpoints. The server decides password, role and step-up requirements. */
class ProfileTwoFactor(private val client: ApiClient) {
    data class Setup(val secret: String, val qrUrl: String?)

    suspend fun begin(): Setup {
        val response = client.post("/v1/admin/auth/2fa/enable").json
        val secret = response["secret_key"].str?.takeIf { it.isNotBlank() }
            ?: throw ApiError.transport("Setup did not return a key. Please try again.")
        return Setup(secret, response["qr_code_url"].str)
    }

    suspend fun confirm(code: String): List<String> =
        codes(client.post("/v1/admin/auth/2fa/confirm", jsonOf("code" to code)).json)

    suspend fun disable(password: String) {
        client.post("/v1/admin/auth/2fa/disable", jsonOf("password" to password))
    }

    suspend fun replaceRecoveryCodes(): List<String> =
        codes(client.post("/v1/admin/auth/2fa/recovery-codes").json)

    private fun codes(response: kotlinx.serialization.json.JsonElement?): List<String> =
        response["recovery_codes"].records.mapNotNull { it.str?.takeIf(String::isNotBlank) }
}
