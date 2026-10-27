import XCTest
@testable import BethanyKit

/// Pins the client to the shapes in docs/api/openapi.json.
final class ContractTests: XCTestCase {
    private func json(_ s: String) -> JSONValue {
        try! HubJSON.decoder.decode(JSONValue.self, from: Data(s.utf8))
    }

    func testLoginAnswersWithOneOfThreeShapes() throws {
        let session = try AuthService.outcome(from: json(#"{"message":"Login successful","token":"1|abc","user":{"id":7,"name":"Amina","email":"a@b.c","permissions":["orders.view"],"roles":[{"id":2,"name":"pos_clerk"}],"session_policy":{"idle_minutes":5,"absolute_minutes":720,"on_idle":"pin_lock"},"is_owner":false,"terminal_pin_set":true}}"#))
        guard case .session(let token, let me) = session else { return XCTFail("expected a session") }
        XCTAssertEqual(token, "1|abc")
        XCTAssertEqual(me.name, "Amina")
        XCTAssertEqual(me.roleNames, ["pos_clerk"])
        XCTAssertEqual(me.sessionPolicy, SessionPolicy(idleMinutes: 5, absoluteMinutes: 720, onIdle: .pinLock))
        XCTAssertTrue(me.terminalPinSet)
        XCTAssertFalse(me.isSuperAdmin)

        let twoFactor = try AuthService.outcome(from: json(#"{"requires_2fa":true,"user_id":7,"challenge":"ch"}"#))
        XCTAssertEqual(twoFactor, .twoFactorRequired(userId: 7, challenge: "ch"))

        let setup = try AuthService.outcome(from: json(#"{"requires_2fa_setup":true,"user_id":7,"setup_token":"st"}"#))
        XCTAssertEqual(setup, .twoFactorSetupRequired(userId: 7, setupToken: "st"))

        XCTAssertThrowsError(try AuthService.outcome(from: json(#"{"message":"Invalid credentials"}"#)))
    }

    func testSuperAdminComesFromRolesNotPermissions() throws {
        let me = try Me(json: json(#"{"id":1,"name":"Owner","email":"o@b.c","permissions":[],"roles":[{"name":"super_admin"}]}"#))
        XCTAssertTrue(me.isSuperAdmin)
        XCTAssertTrue(PermissionSet(me: me).can("payroll.run"))
    }

    func testMeRoundTripsThroughTheKeychainCache() throws {
        let original = try Me(json: json(#"{"id":1,"name":"Owner","email":"o@b.c","permissions":["a"],"roles":[],"extra_field":{"kept":true}}"#))
        let data = try HubJSON.encoder.encode(original)
        let back = try HubJSON.decoder.decode(Me.self, from: data)
        XCTAssertEqual(back, original)
        XCTAssertEqual(back.raw["extra_field"]?["kept"]?.bool, true, "fields the model does not name stay reachable")
    }

    func testErrorsNormaliseLikeTheConsole() {
        let r = APIClient.Response(status: 422, headers: [:], data: Data(#"{"message":"The given data was invalid.","errors":{"email":["The email field is required."]}}"#.utf8))
        let e = APIError(r)
        XCTAssertTrue(e.isValidation)
        XCTAssertEqual(e.errors["email"], ["The email field is required."])
        XCTAssertEqual(e.firstFieldError, "The email field is required.")

        let locked = APIError(APIClient.Response(status: 423, headers: [:], data: Data(#"{"message":"Locked","reason":"pin_locked"}"#.utf8)))
        XCTAssertEqual(locked.reason, "pin_locked")

        let stepUp = APIError(APIClient.Response(status: 403, headers: [:], data: Data(#"{"message":"Confirm","code":"step_up_required","method":"passkey","fallback":"password"}"#.utf8)))
        XCTAssertEqual(stepUp.reason, "step_up_required")
        let challenge = StepUpChallenge(json: stepUp.raw)
        XCTAssertEqual(challenge.method, .passkey)
        XCTAssertEqual(challenge.fallback, .password)

        let deleted = APIError(APIClient.Response(status: 404, headers: [:], data: Data(#"{"message":"Gone","reason":"deleted","deleted_at":"2026-10-01 10:00:00"}"#.utf8)))
        XCTAssertTrue(deleted.isDeleted)

        let limited = APIError(APIClient.Response(status: 429, headers: [:], data: Data(#"{"message":"Slow down","retry_after_seconds":12,"error":"rate_limited"}"#.utf8)))
        XCTAssertEqual(limited.retryAfterSeconds, 12)
    }

    func testPaginatedDecodesLaravelsShape() throws {
        let page = try HubJSON.decoder.decode(Paginated<Record>.self, from: Data(#"{"data":[{"id":3,"status":"paid","total":"1250.00"}],"current_page":2,"last_page":5,"per_page":20,"total":97}"#.utf8))
        XCTAssertEqual(page.data.first?.id, "3")
        XCTAssertEqual(page.data.first?.status, "paid")
        XCTAssertEqual(page.nextPage, 3)
        XCTAssertEqual(page.total, 97)
        XCTAssertEqual(page.data.first?.money("total"), Money(amount: Decimal(string: "1250.00")!, currency: "KES"))
    }

    func testMoneyIsNeverAFloat() {
        XCTAssertEqual(Money(json: .string("0.10"))?.amount, Decimal(string: "0.10"))
        XCTAssertEqual(Money(json: .number(0.1))?.amount, Decimal(string: "0.1"))
        XCTAssertEqual(Money(json: .string("1,234.50"))?.amount, Decimal(string: "1234.50"))
        XCTAssertNil(Money(json: .string("n/a")))
        XCTAssertEqual(Money(amount: 1234.5, currency: "USD").formatted(), "USD 1,234.50")
        let mixed = MoneyByCurrency(json: json(#"{"kes_total":"128000.00","by_currency":[{"currency":"USD","native":"1000.00","kes":"128000.00"}],"unconverted":[{"currency":"ZMW"}]}"#))
        XCTAssertEqual(mixed?.kesTotal?.amount, Decimal(string: "128000.00"))
        XCTAssertEqual(mixed?.byCurrency.first?.native?.currency, "USD")
        XCTAssertEqual(mixed?.unconverted.count, 1)
    }

    func testNairobiWallClockIsNotShiftedToUTC() throws {
        let d = try XCTUnwrap(HubDates.parse("2026-10-27 09:30:00"))
        var cal = Calendar(identifier: .gregorian)
        cal.timeZone = HubDates.nairobi
        XCTAssertEqual(cal.component(.hour, from: d), 9)
        XCTAssertEqual(HubDates.dayString(d), "2026-10-27")
        XCTAssertNotNil(HubDates.parse("2026-10-27T09:30:00+03:00"))
        XCTAssertNotNil(HubDates.parse("2026-10-27"))
    }

    func testConsolePathsRoundTrip() {
        for item in NavCatalogue.allItems {
            XCTAssertEqual(Route.from(consolePath: item.route.consolePath), item.route, item.label)
            XCTAssertEqual(Route.from(consolePath: "/admin" + item.route.consolePath), item.route, "with the /admin prefix")
        }
        XCTAssertEqual(Route.from(consolePath: "/sales/orders/42"), .order(id: 42))
        XCTAssertEqual(Route.from(consolePath: "/admin/me/leave?tab=x"), .myLeave)
        XCTAssertEqual(Route.from(consolePath: "/reports/financial"), .report(.finance))
        XCTAssertEqual(Route.from(consolePath: "/intelligence"), .report(.signals))
        XCTAssertEqual(Route.from(consolePath: "/catalogue/products/new"), .product(new: true))
        XCTAssertNil(Route.from(consolePath: "/nowhere"))
    }

    func testRenewalIsScheduledInsideTheTokenLife() {
        let now = Date()
        XCTAssertEqual(SessionStore.renewalDelay(expiresAt: now.addingTimeInterval(8 * 3600), now: now), 6 * 3600)
        XCTAssertEqual(SessionStore.renewalDelay(expiresAt: now.addingTimeInterval(7 * 86_400), now: now), 12 * 3600, "never longer than half a day between checks")
        XCTAssertEqual(SessionStore.renewalDelay(expiresAt: now.addingTimeInterval(30), now: now), 60)
        XCTAssertEqual(SessionStore.renewalDelay(expiresAt: nil, now: now), 6 * 3600)
    }
}
