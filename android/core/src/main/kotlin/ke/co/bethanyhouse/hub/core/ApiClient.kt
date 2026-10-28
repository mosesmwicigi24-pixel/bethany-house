package ke.co.bethanyhouse.hub.core

import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.serialization.json.JsonElement
import okhttp3.Call
import okhttp3.Callback
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.OkHttpClient
import okhttp3.Request as OkRequest
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response as OkResponse
import java.io.IOException
import java.util.concurrent.TimeUnit
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException

/**
 * The one HTTP client for the Hub API.
 *
 * Mirrors react-admin/src/api/client.ts and docs/api/README.md: bearer token,
 * `X-Device-Id`, `X-Background-Request` for polling, JSON in and out, and the
 * status codes every screen must understand (401 signed out, 423 PIN lock,
 * 403 step-up or download hold, 429 retry-after, 404 deleted vs not found).
 *
 * The client is stateless about the session: it asks a [SessionAuth] for the
 * token and reports auth events back to it, which lets the session store run
 * the PIN-unlock and step-up dialogs and retry the request.
 */
class ApiClient(
    val baseUrl: HttpUrl,
    val device: DeviceIdentity,
    private val http: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .writeTimeout(60, TimeUnit.SECONDS)
        .retryOnConnectionFailure(true)
        .build(),
) {
    @Volatile var auth: SessionAuth? = null

    val okHttp: OkHttpClient get() = http

    enum class Method { GET, POST, PUT, PATCH, DELETE }

    sealed class Body {
        data class Json(val value: JsonElement) : Body()
        data class Multipart(val parts: List<Part>) : Body()
    }

    data class Part(val name: String, val data: ByteArray? = null, val value: String? = null, val filename: String? = null, val mimeType: String? = null) {
        companion object {
            fun field(name: String, value: String) = Part(name, value = value)
            fun file(name: String, data: ByteArray, filename: String, mimeType: String) = Part(name, data = data, filename = filename, mimeType = mimeType)
        }
    }

    data class Request(
        val method: Method,
        /** Path relative to `/api`, e.g. "/v1/admin/auth/me". */
        val path: String,
        /** Repeated keys are allowed (e.g. "product_ids[]" twice). */
        val query: List<Pair<String, String>> = emptyList(),
        val body: Body? = null,
        /** Marks polling (no human touched the screen): does not count as activity. */
        val background: Boolean = false,
        val headers: Map<String, String> = emptyMap(),
    )

    class Response(val status: Int, val headers: Map<String, String>, val bytes: ByteArray) {
        val text: String by lazy { bytes.decodeToString() }
        val json: JsonElement? by lazy { HubJson.parseOrNull(text) }
        val contentType: String? get() = headers.entries.firstOrNull { it.key.equals("Content-Type", true) }?.value
        val filename: String?
            get() {
                val d = headers.entries.firstOrNull { it.key.equals("Content-Disposition", true) }?.value ?: return null
                val i = d.indexOf("filename=")
                if (i < 0) return null
                return d.substring(i + 9).trim('"', ';', ' ')
            }
    }

    /**
     * Sends the request, handling the session statuses once:
     * 423 pin_locked → wait for the PIN unlock, retry (up to 3);
     * 403 step_up_required → wait for step-up, retry (up to 2);
     * 401 → session ended.
     */
    suspend fun send(request: Request): Response {
        var pinRetries = 3
        var stepUpRetries = 2
        while (true) {
            val response = perform(request)
            val reason = response.json["reason"].str
            val code = response.json["code"].str
            when {
                response.status in 200..299 -> return response
                response.status == 401 -> {
                    auth?.sessionExpired()
                    throw ApiError.from(response)
                }
                response.status == 423 && reason == "pin_locked" && pinRetries > 0 -> {
                    pinRetries--
                    val a = auth ?: throw ApiError.from(response)
                    if (!a.awaitPinUnlock()) throw ApiError.from(response)
                }
                response.status == 403 && code == "step_up_required" && stepUpRetries > 0 -> {
                    stepUpRetries--
                    val a = auth ?: throw ApiError.from(response)
                    if (!a.awaitStepUp(StepUpChallenge.from(response.json))) throw ApiError.from(response)
                }
                response.status == 403 && code == "download_approval_required" -> {
                    auth?.downloadHeld(response.json)
                    throw ApiError.from(response)
                }
                else -> throw ApiError.from(response)
            }
        }
    }

    suspend fun get(path: String, query: List<Pair<String, String>> = emptyList(), background: Boolean = false): Response =
        send(Request(Method.GET, path, query = query, background = background))

    suspend fun get(path: String, query: Map<String, String?>, background: Boolean = false): Response =
        get(path, query.entries.mapNotNull { (k, v) -> v?.let { k to it } }, background)

    suspend fun post(path: String, json: JsonElement = jsonOf()): Response = send(Request(Method.POST, path, body = Body.Json(json)))
    suspend fun put(path: String, json: JsonElement = jsonOf()): Response = send(Request(Method.PUT, path, body = Body.Json(json)))
    suspend fun patch(path: String, json: JsonElement = jsonOf()): Response = send(Request(Method.PATCH, path, body = Body.Json(json)))
    suspend fun delete(path: String, json: JsonElement? = null): Response = send(Request(Method.DELETE, path, body = json?.let { Body.Json(it) }))
    suspend fun upload(path: String, parts: List<Part>, method: Method = Method.POST): Response = send(Request(method, path, body = Body.Multipart(parts)))

    /** A raw download (PDF, CSV) through the same session handling. */
    suspend fun download(path: String, query: List<Pair<String, String>> = emptyList()): Response =
        send(Request(Method.GET, path, query = query, headers = mapOf("Accept" to "*/*")))

    // ── Internals ────────────────────────────────────────────────────────────

    private suspend fun perform(request: Request): Response {
        val url = baseUrl.newBuilder()
            .addPathSegments(request.path.trimStart('/'))
            .apply { request.query.forEach { (k, v) -> addQueryParameter(k, v) } }
            .build()
        val builder = OkRequest.Builder().url(url)
            .header("Accept", "application/json")
            .header("User-Agent", device.userAgent)
            .header("X-Device-Id", device.id)
            .header("X-Device-Touch", "5")
            .header("X-Requested-With", "BethanyHub")
        if (request.background) builder.header("X-Background-Request", "1")
        auth?.bearerToken()?.let { builder.header("Authorization", "Bearer $it") }
        request.headers.forEach { (k, v) -> builder.header(k, v) }

        val body: RequestBody? = when (val b = request.body) {
            null -> if (request.method == Method.GET) null else "".toRequestBody(null)
            is Body.Json -> HubJson.json.encodeToString(JsonElement.serializer(), b.value).toRequestBody("application/json; charset=utf-8".toMediaType())
            is Body.Multipart -> MultipartBody.Builder().setType(MultipartBody.FORM).apply {
                b.parts.forEach { p ->
                    if (p.data != null) addFormDataPart(p.name, p.filename ?: "file", p.data.toRequestBody((p.mimeType ?: "application/octet-stream").toMediaType()))
                    else addFormDataPart(p.name, p.value ?: "")
                }
            }.build()
        }
        builder.method(request.method.name, body)

        val ok: OkResponse = try {
            http.newCall(builder.build()).await()
        } catch (e: IOException) {
            throw ApiError.transport(e.message ?: "Network error")
        }
        ok.use { r ->
            val headers = r.headers.names().associateWith { r.header(it) ?: "" }
            return Response(r.code, headers, r.body?.bytes() ?: ByteArray(0))
        }
    }
}

private suspend fun Call.await(): OkResponse = suspendCancellableCoroutine { cont ->
    enqueue(object : Callback {
        override fun onFailure(call: Call, e: IOException) { if (cont.isActive) cont.resumeWithException(e) }
        override fun onResponse(call: Call, response: OkResponse) { cont.resume(response) }
    })
    cont.invokeOnCancellation { runCatching { cancel() } }
}

/** What the session store must provide to the client, and what the client reports back. */
interface SessionAuth {
    suspend fun bearerToken(): String?
    suspend fun sessionExpired()
    /** Suspends until the person has unlocked with their terminal PIN; false if they signed out instead. */
    suspend fun awaitPinUnlock(): Boolean
    /** Suspends until identity is confirmed (password, TOTP or passkey); false if cancelled. */
    suspend fun awaitStepUp(challenge: StepUpChallenge): Boolean
    suspend fun downloadHeld(payload: JsonElement?)
}

data class StepUpChallenge(val method: Method, val fallback: Method?, val reason: String?) {
    enum class Method { PASSKEY, TOTP, PASSWORD;
        companion object { fun parse(s: String?): Method? = when (s) { "passkey" -> PASSKEY; "totp" -> TOTP; "password" -> PASSWORD; else -> null } }
    }
    companion object {
        fun from(json: JsonElement?) = StepUpChallenge(
            method = Method.parse(json["method"].str) ?: Method.PASSWORD,
            fallback = Method.parse(json["fallback"].str),
            reason = json["reason"].str,
        )
    }
}

/** Every failure a screen can show, normalised like the console's `{status, message, errors, reason}`. */
class ApiError(
    val status: Int,
    override val message: String,
    /** 422 field errors, keyed by field. */
    val errors: Map<String, List<String>> = emptyMap(),
    /** Machine-readable cause when the API gave one (`reason` or `code`). */
    val reason: String? = null,
    val retryAfterSeconds: Int? = null,
    val raw: JsonElement? = null,
) : Exception(message) {
    val isOffline get() = status == 0
    val isUnauthenticated get() = status == 401
    val isValidation get() = status == 422
    val isDeleted get() = status == 404 && reason == "deleted"
    val firstFieldError: String? get() = errors.values.firstOrNull()?.firstOrNull()
    fun field(name: String): String? = errors[name]?.firstOrNull()

    companion object {
        fun from(r: ApiClient.Response): ApiError {
            val j = r.json
            val errs = j["errors"].obj?.entries?.associate { (k, v) -> k to (v.arr?.mapNotNull { it.str } ?: listOfNotNull(v.str)) } ?: emptyMap()
            return ApiError(
                status = r.status,
                message = j["message"].str ?: defaultMessage(r.status),
                errors = errs,
                reason = j["reason"].str ?: j["code"].str,
                retryAfterSeconds = j["retry_after_seconds"].int,
                raw = j,
            )
        }

        fun transport(message: String) = ApiError(0, message, reason = "offline")

        private fun defaultMessage(status: Int) = when (status) {
            401 -> "Signed out"
            403 -> "Not permitted"
            404 -> "Not found"
            422 -> "Validation failed"
            423 -> "Locked"
            429 -> "Too many requests"
            in 500..599 -> "Server error"
            else -> "Request failed ($status)"
        }
    }
}

fun String.toHubBaseUrl(): HttpUrl = (trimEnd('/') + "/api/").toHttpUrl()
