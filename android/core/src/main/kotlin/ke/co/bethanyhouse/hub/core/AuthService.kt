package ke.co.bethanyhouse.hub.core

import kotlinx.serialization.json.JsonElement
import java.time.Instant

/** The sign-in and session endpoints (openapi.json, tag `auth`). */
class AuthService(val client: ApiClient) {

    /** `POST /admin/auth/login` answers with one of three shapes. */
    sealed class LoginOutcome {
        data class Session(val token: String, val me: Me) : LoginOutcome()
        data class TwoFactorRequired(val userId: Int, val challenge: String) : LoginOutcome()
        data class TwoFactorSetupRequired(val userId: Int, val setupToken: String) : LoginOutcome()
    }

    suspend fun login(email: String, password: String, remember: Boolean): LoginOutcome =
        outcome(client.post("/v1/admin/auth/login", jsonOf("email" to email, "password" to password, "remember_me" to remember)).json)

    /** Second step: a TOTP code, or a recovery code (each works once). */
    suspend fun verifyTwoFactor(userId: Int, challenge: String, code: String?, recoveryCode: String?): LoginOutcome =
        outcome(client.post("/v1/admin/auth/2fa/verify", jsonOf(
            "user_id" to userId, "challenge" to challenge,
            "code" to (code ?: Omit), "recovery_code" to (recoveryCode ?: Omit),
        )).json)

    data class TwoFactorSetup(val secret: String, val otpauthUrl: String?, val raw: JsonElement?)

    suspend fun beginTwoFactorSetup(userId: Int, setupToken: String): TwoFactorSetup {
        val j = client.post("/v1/admin/auth/2fa/setup", jsonOf("user_id" to userId, "setup_token" to setupToken)).json
        return TwoFactorSetup(j["secret"].str ?: "", j["otpauth_url"].str ?: j["qr_url"].str, j)
    }

    data class TwoFactorSetupResult(val outcome: LoginOutcome, val recoveryCodes: List<String>)

    suspend fun confirmTwoFactorSetup(userId: Int, setupToken: String, code: String): TwoFactorSetupResult {
        val j = client.post("/v1/admin/auth/2fa/setup/confirm", jsonOf("user_id" to userId, "setup_token" to setupToken, "code" to code)).json
        return TwoFactorSetupResult(outcome(j), j["recovery_codes"].records.mapNotNull { it.str })
    }

    suspend fun me(background: Boolean = false): Me {
        val j = client.get("/v1/admin/auth/me", background = background).json
        return Me(j["user"] ?: throw ApiError(0, "Unexpected /me response"))
    }

    suspend fun logout() { client.post("/v1/admin/auth/logout") }

    data class Renewal(val token: String, val expiresAt: Instant, val sessionStartedAt: Instant?, val policy: SessionPolicy?)

    /** `POST /admin/auth/renew` — a replacement token for the same session; 409 = not renewable. */
    suspend fun renew(): Renewal {
        val j = client.post("/v1/admin/auth/renew").json
        return Renewal(
            token = j["token"].str ?: throw ApiError(0, "Unexpected renew response"),
            expiresAt = j["expires_at"].date ?: throw ApiError(0, "Unexpected renew response"),
            sessionStartedAt = j["session_started_at"].date,
            policy = SessionPolicy.from(j["session_policy"]),
        )
    }

    suspend fun unlockWithPin(pin: String) { client.post("/v1/admin/auth/pin/unlock", jsonOf("pin" to pin)) }

    suspend fun stepUp(password: String?, code: String?) {
        client.post("/v1/admin/auth/step-up", jsonOf("password" to (password ?: Omit), "code" to (code ?: Omit)))
    }

    suspend fun forgotPassword(email: String): String =
        client.post("/v1/admin/auth/forgot-password", jsonOf("email" to email)).json["message"].str
            ?: "If that address exists, a reset link is on its way."

    companion object {
        fun outcome(json: JsonElement?): LoginOutcome {
            json ?: throw ApiError(0, "Empty sign-in response")
            if (json["requires_2fa"].bool == true) {
                return LoginOutcome.TwoFactorRequired(json["user_id"].int ?: 0, json["challenge"].str ?: throw ApiError(0, "Missing challenge"))
            }
            if (json["requires_2fa_setup"].bool == true) {
                return LoginOutcome.TwoFactorSetupRequired(json["user_id"].int ?: 0, json["setup_token"].str ?: throw ApiError(0, "Missing setup token"))
            }
            val token = json["token"].str ?: throw ApiError(0, json["message"].str ?: "Unexpected sign-in response")
            return LoginOutcome.Session(token, Me(json["user"] ?: throw ApiError(0, "Unexpected sign-in response")))
        }
    }
}
