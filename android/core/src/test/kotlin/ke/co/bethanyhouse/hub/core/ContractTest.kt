package ke.co.bethanyhouse.hub.core

import java.math.BigDecimal
import java.time.Duration
import java.time.Instant
import java.time.ZonedDateTime
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertNotNull
import kotlin.test.assertNull
import kotlin.test.assertTrue

/** Pins the client to the shapes in docs/api/openapi.json. */
class ContractTest {
    private fun j(s: String) = HubJson.parse(s)

    @Test fun loginAnswersWithOneOfThreeShapes() {
        val session = AuthService.outcome(j("""{"message":"Login successful","token":"1|abc","user":{"id":7,"name":"Amina Njeri","email":"a@b.c","permissions":["orders.view"],"roles":[{"id":2,"name":"pos_clerk"}],"session_policy":{"idle_minutes":5,"absolute_minutes":720,"on_idle":"pin_lock"},"is_owner":false,"terminal_pin_set":true}}"""))
        assertTrue(session is AuthService.LoginOutcome.Session)
        session as AuthService.LoginOutcome.Session
        assertEquals("1|abc", session.token)
        assertEquals("Amina Njeri", session.me.name)
        assertEquals("Amina", session.me.firstName)
        assertEquals(listOf("pos_clerk"), session.me.roleNames)
        assertEquals(SessionPolicy(5, 720, SessionPolicy.OnIdle.PIN_LOCK), session.me.sessionPolicy)
        assertTrue(session.me.terminalPinSet)
        assertTrue(!session.me.isSuperAdmin)

        assertEquals(AuthService.LoginOutcome.TwoFactorRequired(7, "ch"), AuthService.outcome(j("""{"requires_2fa":true,"user_id":7,"challenge":"ch"}""")))
        assertEquals(AuthService.LoginOutcome.TwoFactorSetupRequired(7, "st"), AuthService.outcome(j("""{"requires_2fa_setup":true,"user_id":7,"setup_token":"st"}""")))
        assertFailsWith<ApiError> { AuthService.outcome(j("""{"message":"Invalid credentials"}""")) }
    }

    @Test fun superAdminComesFromRolesNotPermissions() {
        val me = Me(j("""{"id":1,"name":"Owner","email":"o@b.c","permissions":[],"roles":[{"name":"super_admin"}]}"""))
        assertTrue(me.isSuperAdmin)
        assertTrue(PermissionSet.of(me).can("payroll.run"))
    }

    /** The live `/auth/me` (verified against a running backend): first_name/last_name, no `name`. */
    @Test fun displayNameComesFromFirstAndLastNameAsTheBackendSendsIt() {
        val me = Me(j("""{"id":1,"first_name":"Super","last_name":"Admin","email":"o@b.c","permissions":[],"roles":[{"id":1,"name":"super_admin"}],"session_policy":{"idle_minutes":15,"absolute_minutes":480,"on_idle":"sign_out"}}"""))
        assertEquals("Super Admin", me.name)
        assertEquals("Super", me.firstName)
        assertEquals(true, me.isSuperAdmin)
        assertEquals("Only first", Me(j("""{"id":2,"first_name":"Only first","email":"x@y.z"}""")).name)
        assertEquals("Legacy Name", Me(j("""{"id":3,"name":"Legacy Name","email":"x@y.z"}""")).name)
    }

    @Test fun meRoundTripsThroughTheSecureStoreCache() {
        val original = Me(j("""{"id":1,"name":"Owner","email":"o@b.c","permissions":["a"],"roles":[],"extra_field":{"kept":true}}"""))
        val back = Me.decode(original.encode())!!
        assertEquals(original.raw, back.raw)
        assertEquals(true, back.raw["extra_field"]["kept"].bool, "fields the model does not name stay reachable")
    }

    @Test fun errorsNormaliseLikeTheConsole() {
        val e = ApiError.from(ApiClient.Response(422, emptyMap(), """{"message":"The given data was invalid.","errors":{"email":["The email field is required."]}}""".toByteArray()))
        assertTrue(e.isValidation)
        assertEquals(listOf("The email field is required."), e.errors["email"])
        assertEquals("The email field is required.", e.firstFieldError)

        val locked = ApiError.from(ApiClient.Response(423, emptyMap(), """{"message":"Locked","reason":"pin_locked"}""".toByteArray()))
        assertEquals("pin_locked", locked.reason)

        val stepUp = ApiError.from(ApiClient.Response(403, emptyMap(), """{"message":"Confirm","code":"step_up_required","method":"passkey","fallback":"password"}""".toByteArray()))
        assertEquals("step_up_required", stepUp.reason)
        val challenge = StepUpChallenge.from(stepUp.raw)
        assertEquals(StepUpChallenge.Method.PASSKEY, challenge.method)
        assertEquals(StepUpChallenge.Method.PASSWORD, challenge.fallback)

        val deleted = ApiError.from(ApiClient.Response(404, emptyMap(), """{"message":"Gone","reason":"deleted"}""".toByteArray()))
        assertTrue(deleted.isDeleted)

        val limited = ApiError.from(ApiClient.Response(429, emptyMap(), """{"message":"Slow down","retry_after_seconds":12}""".toByteArray()))
        assertEquals(12, limited.retryAfterSeconds)
    }

    @Test fun paginatedDecodesEveryShapeTheApiUses() {
        val laravel = Paginated.from(j("""{"data":[{"id":3,"status":"paid","total":"1250.00"}],"current_page":2,"last_page":5,"per_page":20,"total":97}"""), ::Record)
        assertEquals("3", laravel.data.first().id)
        assertEquals("paid", laravel.data.first().status)
        assertEquals(3, laravel.nextPage)
        assertEquals(97, laravel.total)
        assertEquals(Money(BigDecimal("1250.00"), "KES"), laravel.data.first().money("total"))

        val meta = Paginated.from(j("""{"data":[{"id":1}],"meta":{"current_page":1,"last_page":1,"total":1}}"""), ::Record)
        assertEquals(1, meta.total)
        val nested = Paginated.from(j("""{"data":{"data":[{"id":1},{"id":2}],"current_page":1,"last_page":3,"per_page":2,"total":6}}"""), ::Record)
        assertEquals(2, nested.data.size); assertEquals(2, nested.nextPage)
        val bare = Paginated.from(j("""[{"id":9}]"""), ::Record)
        assertEquals(1, bare.data.size); assertNull(bare.nextPage)
    }

    @Test fun moneyIsNeverAFloat() {
        assertEquals(BigDecimal("0.10"), Money.from(j("\"0.10\""))?.amount)
        assertEquals(0, BigDecimal("0.1").compareTo(Money.from(j("0.1"))!!.amount))
        assertEquals(BigDecimal("1234.50"), Money.from(j("\"1,234.50\""))?.amount)
        assertNull(Money.from(j("\"n/a\"")))
        assertEquals("USD 1,234.50", Money(BigDecimal("1234.5"), "USD").formatted())
        val mixed = MoneyByCurrency.from(j("""{"kes_total":"128000.00","by_currency":[{"currency":"USD","native":"1000.00","kes":"128000.00"}],"unconverted":[{"currency":"ZMW"}]}"""))!!
        assertEquals(BigDecimal("128000.00"), mixed.kesTotal?.amount)
        assertEquals("USD", mixed.byCurrency.first().native?.currency)
        assertEquals(1, mixed.unconverted.size)
    }

    @Test fun nairobiWallClockIsNotShiftedToUtc() {
        val d = assertNotNull(HubDates.parse("2026-10-27 09:30:00"))
        assertEquals(9, ZonedDateTime.ofInstant(d, HubDates.nairobi).hour)
        assertEquals("2026-10-27", HubDates.dayString(d))
        assertNotNull(HubDates.parse("2026-10-27T09:30:00+03:00"))
        assertNotNull(HubDates.parse("2026-10-27T06:30:00.000000Z"))
        assertNotNull(HubDates.parse("2026-10-27"))
    }

    @Test fun renewalIsScheduledInsideTheTokenLife() {
        val now = Instant.now()
        assertEquals(6 * 3600L, SessionStore.renewalDelaySeconds(now.plus(Duration.ofHours(8)), now))
        assertEquals(12 * 3600L, SessionStore.renewalDelaySeconds(now.plus(Duration.ofDays(7)), now))
        assertEquals(60L, SessionStore.renewalDelaySeconds(now.plusSeconds(30), now))
        assertEquals(6 * 3600L, SessionStore.renewalDelaySeconds(null, now))
    }

    @Test fun geofenceMathsMatchesTheServer() {
        assertEquals(0.0, Geofence.distanceMeters(-1.2921, 36.8219, -1.2921, 36.8219), 0.001)
        val oneDegreeLat = Geofence.distanceMeters(0.0, 0.0, 1.0, 0.0)
        assertEquals(111_195.0, oneDegreeLat, 50.0)
        assertEquals(Geofence.Verdict.INSIDE, Geofence.verdict(40.0, 50.0, 10.0))
        assertEquals(Geofence.Verdict.EDGE, Geofence.verdict(55.0, 50.0, 10.0))
        assertEquals(Geofence.Verdict.OUTSIDE, Geofence.verdict(70.0, 50.0, 10.0))
        assertEquals(Geofence.Verdict.INSIDE, Geofence.verdict(9999.0, null, 10.0), "no zone = inside")
        assertEquals(Geofence.Verdict.OUTSIDE, Geofence.verdict(120.0, 50.0, 500.0), "allowance capped at the radius")
    }
}
