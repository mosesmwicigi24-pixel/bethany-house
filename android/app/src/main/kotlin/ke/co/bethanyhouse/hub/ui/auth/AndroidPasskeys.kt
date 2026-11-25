package ke.co.bethanyhouse.hub.ui.auth

import android.content.Context
import androidx.credentials.CreatePublicKeyCredentialRequest
import androidx.credentials.CreatePublicKeyCredentialResponse
import androidx.credentials.CredentialManager
import androidx.credentials.GetCredentialRequest
import androidx.credentials.GetPublicKeyCredentialOption
import androidx.credentials.PublicKeyCredential
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.jsonOf
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonObject

/** Native WebAuthn ceremonies; the server remains authoritative for identity and validation. */
internal interface PasskeyProvider {
    suspend fun create(options: String): String
    suspend fun get(options: String): String
}

private class DevicePasskeyProvider(private val context: Context) : PasskeyProvider {
    private val manager = CredentialManager.create(context)
    override suspend fun create(options: String): String =
        (manager.createCredential(context, CreatePublicKeyCredentialRequest(options)) as? CreatePublicKeyCredentialResponse)
            ?.registrationResponseJson ?: throw ApiError(0, "The device did not return a passkey.")
    override suspend fun get(options: String): String =
        (manager.getCredential(context, GetCredentialRequest(listOf(GetPublicKeyCredentialOption(options)))).credential as? PublicKeyCredential)
            ?.authenticationResponseJson ?: throw ApiError(0, "The device did not return a passkey.")
}

class AndroidPasskeys internal constructor(private val client: ApiClient, private val provider: PasskeyProvider) {
    constructor(context: Context, client: ApiClient) : this(client, DevicePasskeyProvider(context))

    suspend fun register(name: String) {
        val options = client.post("/v1/admin/profile/passkeys/options").json["options"] as? JsonObject
            ?: throw ApiError(0, "The server did not send passkey options.")
        val credential = parseCredential(provider.create(options.toString()))
        client.post("/v1/admin/profile/passkeys", jsonOf("name" to name, "credential" to credential))
    }

    suspend fun stepUp() {
        val options = client.post("/v1/admin/auth/step-up/passkey/options").json["options"] as? JsonObject
            ?: throw ApiError(0, "The server did not send a passkey challenge.")
        client.post("/v1/admin/auth/step-up/passkey", jsonOf("credential" to parseCredential(provider.get(options.toString()))))
    }

    private fun parseCredential(json: String): JsonObject = try {
        Json.parseToJsonElement(json).jsonObject
    } catch (e: IllegalArgumentException) {
        throw ApiError(0, "The device's passkey response could not be read.")
    }
}
