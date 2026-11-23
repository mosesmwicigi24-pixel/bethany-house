import XCTest
#if canImport(FoundationNetworking)
import FoundationNetworking
#endif
@testable import BethanyKit

/// A request racing a token renewal must not sign the person out (found by
/// the UI tour, 2026-11-23): renewal rotates the token and the server deletes
/// the old one at once (SessionRenewal::renew), so a request already sent with
/// the old token comes back 401 while the session is alive. The CI Hub's log
/// showed it: auth/renew at :31, people/requests/count at :32 → 401 → signed out.
final class APIClientRenewalTests: XCTestCase {
    /// The server: accepts only the token it issued last.
    final class StubHub: URLProtocol, @unchecked Sendable {
        nonisolated(unsafe) static var validToken = "new"
        nonisolated(unsafe) static var seen: [String] = []
        override class func canInit(with request: URLRequest) -> Bool { true }
        override class func canonicalRequest(for request: URLRequest) -> URLRequest { request }
        override func startLoading() {
            let auth = request.value(forHTTPHeaderField: "Authorization") ?? ""
            Self.seen.append(auth)
            let ok = auth == "Bearer \(Self.validToken)"
            let body = ok ? #"{"ok":true}"# : #"{"message":"Unauthenticated."}"#
            let response = HTTPURLResponse(url: request.url!, statusCode: ok ? 200 : 401, httpVersion: nil, headerFields: ["Content-Type": "application/json"])!
            client?.urlProtocol(self, didReceive: response, cacheStoragePolicy: .notAllowed)
            client?.urlProtocol(self, didLoad: Data(body.utf8))
            client?.urlProtocolDidFinishLoading(self)
        }
        override func stopLoading() {}
    }

    /// The session: hands out its token; renewal happens between the request
    /// being built and the answer arriving (`rotateAfterFirstRead`).
    actor Session: SessionAuth {
        var token: String?
        var rotateTo: String?
        private(set) var expired = 0
        init(token: String?, rotateTo: String? = nil) { self.token = token; self.rotateTo = rotateTo }
        func bearerToken() async -> String? {
            let t = token
            if let r = rotateTo { token = r; rotateTo = nil }   // renewed while this request is in flight
            return t
        }
        func sessionExpired() async { expired += 1; token = nil }
        func awaitPinUnlock() async -> Bool { false }
        func awaitStepUp(_ challenge: StepUpChallenge) async -> Bool { false }
        func downloadHeld(_ payload: JSONValue?) async {}
    }

    private func client(_ auth: Session) -> APIClient {
        let config = URLSessionConfiguration.ephemeral
        config.protocolClasses = [StubHub.self]
        let c = APIClient(baseURL: URL(string: "http://hub.test/api")!, device: DeviceIdentity(id: "test-device", platform: .macos, model: "test", appVersion: "1"), configuration: config)
        return c
    }

    override func setUp() {
        StubHub.validToken = "new"
        StubHub.seen = []
    }

    func testARequestSentWithTheTokenJustRenewedIsSentAgainNotSignedOut() async throws {
        let session = Session(token: "old", rotateTo: "new")
        let c = client(session)
        await c.attach(auth: session)
        let r = try await c.get("/v1/admin/people/requests/count")
        XCTAssertEqual(r.status, 200)
        XCTAssertEqual(StubHub.seen, ["Bearer old", "Bearer new"], "sent once with the old token, once with the renewed one")
        let expired = await session.expired
        XCTAssertEqual(expired, 0, "the person stays signed in")
    }

    func testA401OnTheCurrentTokenStillEndsTheSession() async {
        StubHub.validToken = "something-else"   // the session really ended server-side
        let session = Session(token: "current")
        let c = client(session)
        await c.attach(auth: session)
        do {
            _ = try await c.get("/v1/admin/dashboard")
            XCTFail("expected 401")
        } catch {
            XCTAssertEqual((error as? APIError)?.status, 401)
        }
        XCTAssertEqual(StubHub.seen, ["Bearer current"], "no retry: the token did not change")
        let expired = await session.expired
        XCTAssertEqual(expired, 1)
    }

    func testTheRetryHappensOnceAtMost() async {
        StubHub.validToken = "never"
        let session = Session(token: "old", rotateTo: "new")
        let c = client(session)
        await c.attach(auth: session)
        _ = try? await c.get("/v1/admin/dashboard")
        XCTAssertEqual(StubHub.seen, ["Bearer old", "Bearer new"])
        let expired = await session.expired
        XCTAssertEqual(expired, 1, "a 401 on the renewed token ends the session")
    }
}
