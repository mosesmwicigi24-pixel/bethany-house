package ke.co.bethanyhouse.hub.core

/** Changes only the signed-in person's PIN; the server verifies the password and policy. */
class TerminalPinClient(private val client: ApiClient) {
    suspend fun set(pin: String, confirmation: String, password: String) {
        client.put("/v1/admin/profile/terminal-pin", jsonOf(
            "pin" to pin,
            "pin_confirmation" to confirmation,
            "current_password" to password,
        ))
    }
}
