import XCTest
@testable import BethanyKit

/// Sign-in safety. The passkey options are the real answer of a local Hub on
/// a scratch database (2026-11-10, POST profile/passkeys/options after
/// step-up); the PIN rule is App\Services\Auth\TerminalPin::problem.
final class SecurityModelsTests: XCTestCase {
    private func json(_ s: String) -> JSONValue { try! HubJSON.decoder.decode(JSONValue.self, from: Data(s.utf8)) }

    func testThePasskeyOptionsTheServerIssuesAreRead() throws {
        let o = try XCTUnwrap(PasskeyCreationOptions(json: json(#"{"options":{"challenge":"XnSLjoskAIASDmzWBJQKFEbTn12nse_Dgw6zpuSH7cA","timeout":300000,"rp":{"id":"127.0.0.1","name":"Bethany House Hub"},"user":{"id":"ZGIwZTkzN2QtNWZlYy00NjgwLWJiMzctOGFmMzk0ZGRlNTI0","name":"mwicigi@icloud.com","displayName":"Super Admin"},"pubKeyCredParams":[{"type":"public-key","alg":-7},{"type":"public-key","alg":-257}],"authenticatorSelection":{"requireResidentKey":null,"authenticatorAttachment":null,"userVerification":"required","residentKey":"preferred"},"attestation":"none","excludeCredentials":[]}}"#)))
        XCTAssertEqual(o.relyingParty, "127.0.0.1", "the relying party is the server's, not guessed")
        XCTAssertEqual(o.challenge.count, 32)
        XCTAssertEqual(String(data: o.userId, encoding: .utf8), "db0e937d-5fec-4680-bb37-8af394dde524", "the user handle is the user's uuid")
        XCTAssertEqual(o.userName, "mwicigi@icloud.com")
        XCTAssertNil(PasskeyCreationOptions(json: json(#"{"options":{}}"#)))
    }

    func testTheNewCredentialIsSentAsTheConsoleSendsIt() {
        let j = PasskeyCreationOptions.credentialJSON(credentialID: Data([0xfb, 0xff, 0x01]), clientDataJSON: Data("{}".utf8), attestationObject: Data([0xa0]))
        XCTAssertEqual(j["id"]?.string, "-_8B", "base64url, no padding")
        XCTAssertEqual(j["rawId"]?.string, "-_8B")
        XCTAssertEqual(j["type"]?.string, "public-key")
        XCTAssertEqual(j["response"]?["clientDataJSON"]?.string, "e30")
        XCTAssertEqual(j["response"]?["attestationObject"]?.string, "oA")
        XCTAssertNil(j["response"]?["signature"], "a registration carries no assertion fields")
        XCTAssertEqual(Data(base64URL: "-_8B"), Data([0xfb, 0xff, 0x01]))
    }

    func testThePinRuleIsTheServers() {
        XCTAssertNil(TerminalPinRule.problem("2580"))
        XCTAssertNil(TerminalPinRule.problem("918273"))
        XCTAssertEqual(TerminalPinRule.problem("123"), "The PIN must be 4 to 6 digits.")
        XCTAssertEqual(TerminalPinRule.problem("1234567"), "The PIN must be 4 to 6 digits.")
        XCTAssertEqual(TerminalPinRule.problem("12a4"), "The PIN must be 4 to 6 digits.")
        XCTAssertEqual(TerminalPinRule.problem("٢٥٨٠"), "The PIN must be 4 to 6 digits.", "Arabic-Indic digits are not \\d in PHP's default regex")
        XCTAssertEqual(TerminalPinRule.problem("7777"), "The PIN cannot be one digit repeated.")
        XCTAssertEqual(TerminalPinRule.problem("3456"), "The PIN cannot be a run of consecutive digits.")
        XCTAssertEqual(TerminalPinRule.problem("9012"), "The PIN cannot be a run of consecutive digits.", "the server's run wraps 9 to 0")
        XCTAssertEqual(TerminalPinRule.problem("6543"), "The PIN cannot be a run of consecutive digits.")
    }

    func testTwoFactorSetupAndCodes() {
        let s = TwoFactor.setup(json(#"{"secret_key":"JBSWY3DPEHPK3PXP","qr_code_url":"otpauth://totp/Hub:a@b.c?secret=JBSWY3DPEHPK3PXP","message":"Scan"}"#))
        XCTAssertEqual(s?.secret, "JBSWY3DPEHPK3PXP")
        XCTAssertNil(TwoFactor.setup(json(#"{"message":"x"}"#)))
        XCTAssertTrue(TwoFactor.isCode(" 123456 "))
        XCTAssertFalse(TwoFactor.isCode("12345"))
        XCTAssertFalse(TwoFactor.isCode("12345a"))
        XCTAssertEqual(TwoFactor.recoveryCodes(json(#"{"recovery_codes":["a1b2-c3d4","e5f6-g7h8"]}"#)), ["a1b2-c3d4", "e5f6-g7h8"])
        XCTAssertEqual(PasskeyRow.rows(json(#"{"data":[]}"#)), [], "the scratch Hub's answer")
    }
}
