package ke.co.bethanyhouse.hub.core

import android.content.SharedPreferences
import kotlinx.coroutines.runBlocking
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import kotlin.test.AfterTest
import kotlin.test.BeforeTest
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertFalse
import kotlin.test.assertNull
import kotlin.test.assertTrue

/** The kiosk client against a mock Hub: its own token, the punch body, and what a 401 does. */
class KioskTest {
    private lateinit var server: MockWebServer
    private lateinit var kiosk: KioskStore
    private lateinit var api: KioskApi

    @BeforeTest fun setUp() {
        server = MockWebServer().apply { start() }
        kiosk = KioskStore(SecureStore.unencryptedForTests(MemoryPrefs()))
        api = KioskApi(server.url("/").toString().trimEnd('/').toHubBaseUrl(), DeviceIdentity("0123456789abcdef0123", "Galaxy Tab S9", "2.0.0+1"), kiosk)
    }

    @AfterTest fun tearDown() { server.shutdown() }

    private fun reply(code: Int, body: String) = server.enqueue(MockResponse().setResponseCode(code).setHeader("Content-Type", "application/json").setBody(body))

    @Test fun boardUsesTheKioskTokenAndDecodesThePeople() = runBlocking {
        kiosk.enter("9|kiosk-token")
        reply(200, """{"kiosk":{"id":4,"name":"Front door","outlet_id":1,"outlet":"Westlands"},"people":[
            {"id":7,"name":"Amina Njeri","initials":"AN","has_pin":true,"state":"in","since":"2026-10-29T08:02:00+03:00","elsewhere":null},
            {"id":8,"name":"Baraka Otieno","initials":"BO","has_pin":false,"state":"out","since":null,"elsewhere":null}
        ],"rules":{"clock_in_without_shift":"allow"},"server_time":"2026-10-29T09:00:00+03:00"}""")
        val board = api.board()
        val req = server.takeRequest()
        assertEquals("/api/v1/admin/kiosk/board", req.path)
        assertEquals("Bearer 9|kiosk-token", req.getHeader("Authorization"))
        assertEquals("Front door", board.kioskName)
        assertEquals("Westlands", board.outlet)
        assertEquals(1, board.inNow)
        assertEquals(listOf(true, false), board.people.map { it.hasPin })
    }

    @Test fun punchSendsWhatTheServerValidatesAndReturnsItsMessage() = runBlocking {
        kiosk.enter("9|kiosk-token")
        reply(201, """{"message":"Amina clocked in at 08:02.","entry_id":31,"at":"2026-10-29T08:02:00+03:00"}""")
        val msg = api.punch(7, "4821", clockIn = true, photoDataUrl = "data:image/jpeg;base64,AAAA")
        val body = HubJson.parse(server.takeRequest().body.readUtf8())
        assertEquals("Amina clocked in at 08:02.", msg)
        assertEquals(7, body["user_id"].int)
        assertEquals("4821", body["pin"].str)
        assertEquals("in", body["action"].str)
        assertEquals("data:image/jpeg;base64,AAAA", body["photo"].str)
    }

    @Test fun punchWithoutACameraLeavesThePhotoOut() = runBlocking {
        kiosk.enter("t")
        reply(200, """{"message":"Amina clocked out at 17:00. Worked 8 h 58 min."}""")
        api.punch(7, "4821", clockIn = false, photoDataUrl = null)
        val body = HubJson.parse(server.takeRequest().body.readUtf8())
        assertEquals("out", body["action"].str)
        assertFalse(body.obj!!.containsKey("photo"))
    }

    @Test fun aWrongPinIsTheServersMessageAndKeepsTheKiosk() = runBlocking {
        kiosk.enter("t")
        reply(422, """{"message":"Wrong PIN. 2 tries left."}""")
        val e = assertFailsWith<ApiError> { api.punch(7, "0000", clockIn = true, photoDataUrl = null) }
        assertEquals("Wrong PIN. 2 tries left.", e.message)
        assertTrue(kiosk.isKiosk)
    }

    @Test fun aLockedOutPinIsReportedNotWaitedOn() = runBlocking {
        kiosk.enter("t")
        reply(423, """{"message":"Too many wrong PINs. Try again in a few minutes."}""")
        val e = assertFailsWith<ApiError> { api.punch(7, "0000", clockIn = true, photoDataUrl = null) }
        assertEquals(423, e.status)
        assertTrue(kiosk.isKiosk)
    }

    @Test fun aRemovedOrExpiredKioskReturnsTheTabletToSetUp() = runBlocking {
        kiosk.enter("t")
        reply(401, """{"message":"Unauthenticated."}""")
        assertFailsWith<ApiError> { api.board() }
        assertFalse(kiosk.isKiosk)
        assertEquals(KioskApi.ENDED_ON_HUB, kiosk.ended.value)
        kiosk.acknowledgeEnded()
        assertNull(kiosk.ended.value)
    }

    @Test fun leavingKillsTheTokenOnTheHubAndForgetsIt() = runBlocking {
        kiosk.enter("t")
        reply(200, """{"message":"This tablet is no longer a kiosk."}""")
        api.leave()
        assertEquals("DELETE", server.takeRequest().method)
        assertFalse(kiosk.isKiosk)
        assertNull(kiosk.ended.value, "leaving on purpose shows no warning")
    }

    @Test fun theTokenSurvivesARestart() {
        val prefs = MemoryPrefs()
        KioskStore(SecureStore.unencryptedForTests(prefs)).enter("persisted")
        assertEquals("persisted", KioskStore(SecureStore.unencryptedForTests(prefs)).token.value)
    }
}

/** An in-memory SharedPreferences for JVM tests. */
private class MemoryPrefs : SharedPreferences {
    private val map = mutableMapOf<String, Any?>()
    override fun getAll(): MutableMap<String, *> = map
    override fun getString(key: String?, defValue: String?): String? = map[key] as String? ?: defValue
    override fun getStringSet(key: String?, defValues: MutableSet<String>?): MutableSet<String>? = defValues
    override fun getInt(key: String?, defValue: Int): Int = defValue
    override fun getLong(key: String?, defValue: Long): Long = defValue
    override fun getFloat(key: String?, defValue: Float): Float = defValue
    override fun getBoolean(key: String?, defValue: Boolean): Boolean = defValue
    override fun contains(key: String?): Boolean = map.containsKey(key)
    override fun registerOnSharedPreferenceChangeListener(listener: SharedPreferences.OnSharedPreferenceChangeListener?) {}
    override fun unregisterOnSharedPreferenceChangeListener(listener: SharedPreferences.OnSharedPreferenceChangeListener?) {}
    override fun edit(): SharedPreferences.Editor = object : SharedPreferences.Editor {
        override fun putString(key: String?, value: String?) = apply { map[key!!] = value }
        override fun putStringSet(key: String?, values: MutableSet<String>?) = this
        override fun putInt(key: String?, value: Int) = this
        override fun putLong(key: String?, value: Long) = this
        override fun putFloat(key: String?, value: Float) = this
        override fun putBoolean(key: String?, value: Boolean) = this
        override fun remove(key: String?) = apply { map.remove(key) }
        override fun clear() = apply { map.clear() }
        override fun commit() = true
        override fun apply() {}
    }
}
