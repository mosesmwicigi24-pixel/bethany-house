import Foundation
import AuthenticationServices
#if os(macOS)
import AppKit
#else
import UIKit
#endif

/// Step-up with a platform passkey (Touch ID / Face ID), against
/// `POST /admin/auth/step-up/passkey/options` then `POST /admin/auth/step-up/passkey`
/// (app/Services/Auth/Passkeys.php, WebAuthn). The relying party is the Hub
/// host; the apple-app-site-association `webcredentials` entry (Step 2)
/// lets the app use passkeys registered in the console and vice versa.
@MainActor
public final class PasskeyStepUp: NSObject, ASAuthorizationControllerDelegate, ASAuthorizationControllerPresentationContextProviding {
    private let client: APIClient
    private var continuation: CheckedContinuation<ASAuthorizationPlatformPublicKeyCredentialAssertion, Error>?

    public init(client: APIClient) {
        self.client = client
    }

    public func perform() async throws {
        let options = try await client.post("/v1/admin/auth/step-up/passkey/options")
        guard let json = options.json,
              let challengeB64 = json["challenge"]?.string ?? json["publicKey"]?["challenge"]?.string,
              let challenge = Data(base64URL: challengeB64) else {
            throw APIError(status: 0, message: "The server did not send a passkey challenge.")
        }
        let rpId = json["rpId"]?.string ?? json["publicKey"]?["rpId"]?.string ?? client.baseURL.host ?? "hub.bethanyhouse.co.ke"
        let provider = ASAuthorizationPlatformPublicKeyCredentialProvider(relyingPartyIdentifier: rpId)
        let request = provider.createCredentialAssertionRequest(challenge: challenge)
        let allowed = (json["allowCredentials"]?.array ?? json["publicKey"]?["allowCredentials"]?.array ?? []).compactMap { c -> ASAuthorizationPlatformPublicKeyCredentialDescriptor? in
            guard let id = c["id"]?.string, let data = Data(base64URL: id) else { return nil }
            return ASAuthorizationPlatformPublicKeyCredentialDescriptor(credentialID: data)
        }
        request.allowedCredentials = allowed

        let assertion: ASAuthorizationPlatformPublicKeyCredentialAssertion = try await withCheckedThrowingContinuation { c in
            continuation = c
            let controller = ASAuthorizationController(authorizationRequests: [request])
            controller.delegate = self
            controller.presentationContextProvider = self
            controller.performRequests()
        }

        let body: JSONValue = .object([
            "id": .string(assertion.credentialID.base64URL),
            "rawId": .string(assertion.credentialID.base64URL),
            "type": .string("public-key"),
            "response": .object([
                "clientDataJSON": .string(assertion.rawClientDataJSON.base64URL),
                "authenticatorData": .string(assertion.rawAuthenticatorData.base64URL),
                "signature": .string(assertion.signature.base64URL),
                "userHandle": .string(assertion.userID.base64URL),
            ]),
        ])
        _ = try await client.post("/v1/admin/auth/step-up/passkey", json: body)
    }

    public func authorizationController(controller: ASAuthorizationController, didCompleteWithAuthorization authorization: ASAuthorization) {
        if let a = authorization.credential as? ASAuthorizationPlatformPublicKeyCredentialAssertion {
            continuation?.resume(returning: a)
        } else {
            continuation?.resume(throwing: APIError(status: 0, message: "Unexpected credential"))
        }
        continuation = nil
    }

    public func authorizationController(controller: ASAuthorizationController, didCompleteWithError error: Error) {
        continuation?.resume(throwing: error)
        continuation = nil
    }

    public func presentationAnchor(for controller: ASAuthorizationController) -> ASPresentationAnchor {
        #if os(macOS)
        return NSApplication.shared.keyWindow ?? NSApplication.shared.windows.first ?? ASPresentationAnchor()
        #else
        return UIApplication.shared.connectedScenes.compactMap { ($0 as? UIWindowScene)?.keyWindow }.first ?? ASPresentationAnchor()
        #endif
    }
}

public extension Data {
    init?(base64URL: String) {
        var s = base64URL.replacingOccurrences(of: "-", with: "+").replacingOccurrences(of: "_", with: "/")
        while s.count % 4 != 0 { s += "=" }
        self.init(base64Encoded: s)
    }

    var base64URL: String {
        base64EncodedString().replacingOccurrences(of: "+", with: "-").replacingOccurrences(of: "/", with: "_").replacingOccurrences(of: "=", with: "")
    }
}
