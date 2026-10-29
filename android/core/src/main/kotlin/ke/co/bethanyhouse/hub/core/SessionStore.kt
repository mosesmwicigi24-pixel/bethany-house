package ke.co.bethanyhouse.hub.core

import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.serialization.json.JsonElement
import java.time.Duration
import java.time.Instant

/**
 * The signed-in session, shared by every screen (react-admin's auth.store.ts
 * and security.store.ts in one place).
 *
 * Owns the bearer token (secure store), the person and their effective
 * permissions, the session policy and its idle clock, token renewal, and the
 * PIN-lock and step-up dialogs the API client waits on. Everything the app
 * shows is derived from `me`; nothing here computes business data.
 */
class SessionStore(
    val client: ApiClient,
    private val store: SecureStore,
    private val scope: CoroutineScope = CoroutineScope(SupervisorJob() + Dispatchers.Main.immediate),
) : SessionAuth {

    sealed class State {
        data object Starting : State()
        data object SignedOut : State()
        data object SigningIn : State()
        data class TwoFactor(val userId: Int, val challenge: String) : State()
        data class TwoFactorSetup(val userId: Int, val setupToken: String) : State()
        data object SignedIn : State()
    }

    data class Snapshot(
        val state: State = State.Starting,
        val me: Me? = null,
        val permissions: PermissionSet = PermissionSet.NONE,
        val tokenExpiresAt: Instant? = null,
        val sessionStartedAt: Instant? = null,
        val startupError: ApiError? = null,
        val pinLocked: Boolean = false,
        val stepUp: StepUpChallenge? = null,
        val heldDownload: JsonElement? = null,
        val idleLockedOut: Boolean = false,
    ) {
        val home: Route get() = HomePath.resolve(permissions)
        fun can(permission: String) = permissions.can(permission)
        fun allows(gate: NavGate) = gate.allows(permissions)
    }

    private val _snapshot = MutableStateFlow(Snapshot())
    val snapshot: StateFlow<Snapshot> = _snapshot.asStateFlow()
    val current: Snapshot get() = _snapshot.value

    val auth = AuthService(client)
    var pushRegistrar: PushRegistration? = null

    @Volatile private var token: String? = store.get(TOKEN)
    private val pinWaiters = mutableListOf<CompletableDeferred<Boolean>>()
    private val stepUpWaiters = mutableListOf<CompletableDeferred<Boolean>>()
    private val waitersLock = Mutex()
    @Volatile private var lastActivity: Instant = Instant.now()
    private var renewalJob: Job? = null
    private var idleJob: Job? = null

    init { client.auth = this }

    // ── Start ────────────────────────────────────────────────────────────────

    /**
     * Restores the session: a cached `me` renders at once (offline too), then
     * `/me` confirms it. Only 401/403 from `/me` clear the session; an
     * unreachable API keeps the cached session and reports the startup problem.
     */
    suspend fun start() {
        if (token == null) { _snapshot.update { it.copy(state = State.SignedOut) }; return }
        store.get(ME_CACHE)?.let(Me::decode)?.let { cached -> apply(cached); _snapshot.update { it.copy(state = State.SignedIn) } }
        try {
            val fresh = auth.me()
            apply(fresh)
            _snapshot.update { it.copy(state = State.SignedIn, startupError = null) }
            scheduleRenewal()
            startIdleClock()
            pushRegistrar?.register(this)
        } catch (e: ApiError) {
            if (e.status == 401 || e.status == 403) clearSession()
            else _snapshot.update { it.copy(startupError = e, state = if (it.me == null) State.SignedOut else it.state) }
        }
    }

    // ── Sign-in ──────────────────────────────────────────────────────────────

    suspend fun signIn(email: String, password: String, remember: Boolean) {
        _snapshot.update { it.copy(state = State.SigningIn) }
        try {
            handle(auth.login(email, password, remember))
        } catch (e: Exception) {
            _snapshot.update { it.copy(state = State.SignedOut) }
            throw e
        }
    }

    suspend fun verifyTwoFactor(code: String?, recoveryCode: String?) {
        val s = current.state as? State.TwoFactor ?: return
        handle(auth.verifyTwoFactor(s.userId, s.challenge, code, recoveryCode))
    }

    suspend fun beginTwoFactorSetup(): AuthService.TwoFactorSetup {
        val s = current.state as? State.TwoFactorSetup ?: throw ApiError(0, "No setup in progress")
        return auth.beginTwoFactorSetup(s.userId, s.setupToken)
    }

    suspend fun confirmTwoFactorSetup(code: String): List<String> {
        val s = current.state as? State.TwoFactorSetup ?: throw ApiError(0, "No setup in progress")
        val r = auth.confirmTwoFactorSetup(s.userId, s.setupToken, code)
        handle(r.outcome)
        return r.recoveryCodes
    }

    private suspend fun handle(outcome: AuthService.LoginOutcome) {
        when (outcome) {
            is AuthService.LoginOutcome.TwoFactorRequired -> _snapshot.update { it.copy(state = State.TwoFactor(outcome.userId, outcome.challenge)) }
            is AuthService.LoginOutcome.TwoFactorSetupRequired -> _snapshot.update { it.copy(state = State.TwoFactorSetup(outcome.userId, outcome.setupToken)) }
            is AuthService.LoginOutcome.Session -> {
                token = outcome.token
                store.set(TOKEN, outcome.token)
                apply(outcome.me)
                _snapshot.update { it.copy(state = State.SignedIn, startupError = null, tokenExpiresAt = null, sessionStartedAt = Instant.now()) }
                scheduleRenewal()
                startIdleClock()
                pushRegistrar?.register(this)
            }
        }
    }

    fun cancelSignIn() { _snapshot.update { it.copy(state = State.SignedOut) } }

    // ── Session ──────────────────────────────────────────────────────────────

    suspend fun signOut() {
        pushRegistrar?.unregister(this)
        runCatching { auth.logout() }
        clearSession()
    }

    private suspend fun clearSession() {
        renewalJob?.cancel(); idleJob?.cancel()
        token = null
        store.remove(TOKEN); store.remove(ME_CACHE)
        _snapshot.value = Snapshot(state = State.SignedOut)
        waitersLock.withLock {
            pinWaiters.forEach { it.complete(false) }; pinWaiters.clear()
            stepUpWaiters.forEach { it.complete(false) }; stepUpWaiters.clear()
        }
    }

    suspend fun refreshMe(background: Boolean = true) {
        runCatching { auth.me(background) }.getOrNull()?.let(::apply)
    }

    private fun apply(me: Me) {
        store.set(ME_CACHE, me.encode())
        _snapshot.update { it.copy(me = me, permissions = PermissionSet.of(me)) }
    }

    // ── Activity, idle, renewal ──────────────────────────────────────────────

    /** Called by the app on every user interaction. */
    fun touch() { lastActivity = Instant.now() }

    /** Mirrors SessionPolicy on the client so the lock appears when the server would apply it. */
    private fun startIdleClock() {
        idleJob?.cancel()
        val policy = current.me?.sessionPolicy ?: return
        idleJob = scope.launch {
            while (isActive) {
                delay(15_000)
                val idleMinutes = Duration.between(lastActivity, Instant.now()).toMillis() / 60_000.0
                if (idleMinutes > policy.idleMinutes) {
                    if (policy.onIdle == SessionPolicy.OnIdle.PIN_LOCK) _snapshot.update { it.copy(pinLocked = true) }
                    else _snapshot.update { it.copy(idleLockedOut = true) }
                }
            }
        }
    }

    /**
     * Renew before expiry: at 75% of the remaining life, and on foreground
     * when less than a day remains. The server caps the new expiry at the
     * role's absolute limit, so this never extends a session the policy would end.
     */
    private fun scheduleRenewal() {
        renewalJob?.cancel()
        renewalJob = scope.launch {
            delay(5_000)
            while (isActive) {
                renewNow()
                delay(renewalDelaySeconds(current.tokenExpiresAt, Instant.now()) * 1000)
            }
        }
    }

    suspend fun renewNow() {
        if (current.state != State.SignedIn || current.pinLocked) return
        try {
            val r = auth.renew()
            token = r.token
            store.set(TOKEN, r.token)
            _snapshot.update { it.copy(tokenExpiresAt = r.expiresAt, sessionStartedAt = r.sessionStartedAt ?: it.sessionStartedAt) }
        } catch (e: ApiError) {
            if (e.status == 409) renewalJob?.cancel()  // not renewable: keep using it until it ends
        }
    }

    /** Foreground hook: confirm the session and renew when it is close. */
    suspend fun didBecomeActive() {
        touch()
        _snapshot.update { it.copy(idleLockedOut = false) }
        if (current.state != State.SignedIn) return
        refreshMe(background = true)
        val exp = current.tokenExpiresAt
        if (exp != null && Duration.between(Instant.now(), exp).toHours() < 24) renewNow()
    }

    // ── PIN lock & step-up (SessionAuth) ─────────────────────────────────────

    override suspend fun bearerToken(): String? = token
    override suspend fun sessionExpired() { clearSession() }

    override suspend fun awaitPinUnlock(): Boolean {
        val d = CompletableDeferred<Boolean>()
        waitersLock.withLock { pinWaiters += d }
        _snapshot.update { it.copy(pinLocked = true) }
        return d.await()
    }

    override suspend fun awaitStepUp(challenge: StepUpChallenge): Boolean {
        val d = CompletableDeferred<Boolean>()
        waitersLock.withLock { stepUpWaiters += d }
        _snapshot.update { it.copy(stepUp = challenge) }
        return d.await()
    }

    override suspend fun downloadHeld(payload: JsonElement?) { _snapshot.update { it.copy(heldDownload = payload) } }
    fun clearHeldDownload() { _snapshot.update { it.copy(heldDownload = null) } }

    /** Terminal PIN entered on the lock overlay. */
    suspend fun unlock(pin: String) {
        auth.unlockWithPin(pin)
        _snapshot.update { it.copy(pinLocked = false) }
        touch()
        waitersLock.withLock { pinWaiters.forEach { it.complete(true) }; pinWaiters.clear() }
    }

    suspend fun abandonLock() { signOut() }

    suspend fun stepUp(password: String?, code: String?) {
        auth.stepUp(password, code)
        stepUpSucceeded()
    }

    suspend fun stepUpSucceeded() {
        _snapshot.update { it.copy(stepUp = null) }
        waitersLock.withLock { stepUpWaiters.forEach { it.complete(true) }; stepUpWaiters.clear() }
    }

    suspend fun cancelStepUp() {
        _snapshot.update { it.copy(stepUp = null) }
        waitersLock.withLock { stepUpWaiters.forEach { it.complete(false) }; stepUpWaiters.clear() }
    }

    companion object {
        private const val TOKEN = "bh_admin_token"
        private const val ME_CACHE = "bh_me_cache"

        /** Seconds until the next renewal attempt. */
        fun renewalDelaySeconds(expiresAt: Instant?, now: Instant): Long {
            expiresAt ?: return 6 * 3600
            val remaining = Duration.between(now, expiresAt).seconds
            if (remaining <= 0) return 60
            return maxOf(60L, minOf((remaining * 0.75).toLong(), 12 * 3600L))
        }
    }
}
