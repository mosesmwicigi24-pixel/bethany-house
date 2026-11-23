import XCTest
@testable import BethanyKit

/// Messages: attention, notices, "Handled" and Assist. Fixtures are real
/// responses from a local Hub on a scratch database (2026-11-10): Wanjiru
/// messaged the admin; the admin dismissed the notice, then marked it handled.
final class ChannelAttentionTests: XCTestCase {
    private func json(_ s: String) -> JSONValue { try! HubJSON.decoder.decode(JSONValue.self, from: Data(s.utf8)) }

    private lazy var waiting = json(#"{"channels":[{"channel_id":1,"type":"dm","name":"Wanjiru Kamau","context_type":null,"muted":false,"unread_count":1,"pending_count":1,"pending_since":"2026-11-10T12:03:23+03:00","level":"waiting","notice":{"message_id":1,"body":"Can you approve the cope order today?","mentions_me":false,"created_at":"2026-11-10T12:03:23+03:00","count":1,"user":{"id":2,"name":"Wanjiru Kamau","initials":"WK"}}}],"totals":{"unread":1,"pending":1,"warn":0,"escalated":0,"notices":1},"thresholds":{"warn_minutes":60,"escalate_minutes":240},"server_time":"2026-11-10T12:03:25+03:00"}"#)

    func testAMessageWaitingOnMeFloatsAsANoticeAndOffersHandled() throws {
        let a = ChannelAttention(json: waiting)
        XCTAssertEqual(a.pending, 1)
        XCTAssertEqual(a.notices, 1)
        XCTAssertTrue(a.waitsOnMe(1))
        XCTAssertFalse(a.waitsOnMe(99))
        let n = try XCTUnwrap(a.withNotices.first?.notice)
        XCTAssertEqual(n.messageId, 1)
        XCTAssertEqual(n.from, "Wanjiru Kamau")
        XCTAssertEqual(n.body, "Can you approve the cope order today?")
        XCTAssertEqual(a.item(1)?.level, "waiting")
    }

    func testDismissingTheNoticeLeavesItUnreadAndWaiting() {
        // After notice-dismiss {message_id: 1}: notice null, still unread and pending.
        let a = ChannelAttention(json: json(#"{"channels":[{"channel_id":1,"type":"dm","name":"Wanjiru Kamau","unread_count":1,"pending_count":1,"level":"waiting","notice":null}],"totals":{"unread":1,"pending":1,"warn":0,"escalated":0,"notices":0}}"#))
        XCTAssertTrue(a.withNotices.isEmpty)
        XCTAssertTrue(a.waitsOnMe(1), "a dismissed notice still waits on me")
        XCTAssertEqual(a.unread, 1)
    }

    func testHandledClearsWhatWaitsAndAnEmptyAnswerIsEmpty() {
        // After acknowledge: the server's own answer.
        let a = ChannelAttention(json: json(#"{"channels":[],"totals":{"unread":0,"pending":0,"warn":0,"escalated":0,"notices":0}}"#))
        XCTAssertFalse(a.waitsOnMe(1))
        XCTAssertEqual(a.pending, 0)
        XCTAssertTrue(ChannelAttention.empty.items.isEmpty)
    }

    func testAssistSendsOnlyWhatEachActionNeeds() {
        XCTAssertEqual(ChannelAssist.body(.summarise, language: "en", draft: "x", audience: nil), json(#"{"action":"summarise","language":"en"}"#))
        XCTAssertEqual(ChannelAssist.body(.translate, language: "sw", draft: "Asante", audience: "team"), json(#"{"action":"translate","language":"sw","text":"Asante"}"#))
        XCTAssertEqual(ChannelAssist.body(.draft, language: "fr", draft: "", audience: "customer"), json(#"{"action":"draft","language":"en","audience":"customer"}"#))
        XCTAssertFalse(ChannelAssist.canRun(.translate, draft: "  "))
        XCTAssertTrue(ChannelAssist.canRun(.summarise, draft: ""))
    }

    func testAssistAnswersReadTextAndOptions() {
        let r = ChannelAssist.answer(json(#"{"action":"replies","text":"","options":["Yes, today","Tomorrow morning","Which order?"]}"#))
        XCTAssertEqual(r.options.count, 3)
        XCTAssertEqual(ChannelAssist.answer(json(#"{"action":"summarise","text":"Wanjiru asks for approval."}"#)).text, "Wanjiru asks for approval.")
    }
}
