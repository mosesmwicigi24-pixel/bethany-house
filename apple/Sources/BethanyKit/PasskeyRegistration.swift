import Foundation
import AuthenticationServices
#if os(macOS)
import AppKit
#else
import UIKit
#endif

/// Adds a platform passkey (Touch ID / Face ID) to the signed-in person,
/// against `POST profile/passkeys/options` then `POST profile/passkeys
/// {name, credential}` (PasskeyController; both behind step-up on the
/// server, which the client's step-up prompt answers and resends). The
/// relying party is what the server names; the app's `webcredentials`
/// associated domain makes it the Hub's.
@MainActor
public final class PasskeyRegistration: NSObject, ASAuthorizationControllerDelegate, ASAuthorizationControllerPresentationContextProviding {
    private let client: APIClient
    private var continuation: CheckedContinuation<ASAuthorizationPlatformPublicKeyCredentialRegistration, Error>?

    public init(client: APIClient) { self.client = client }

    /// Returns the server's message ("Passkey added." or similar).
    public func register(name: String) async throws -> String {
        let r = try await client.post("/v1/admin/profile/passkeys/options")
        guard let options = PasskeyCreationOptions(json: r.json ?? .null) else {
            throw APIError(status: 0, message: "The server did not send passkey options.")
        }
        let provider = ASAuthorizationPlatformPublicKeyCredentialProvider(relyingPartyIdentifier: options.relyingParty)
        let request = provider.createCredentialRegistrationRequest(challenge: options.challenge, name: options.userName, userID: options.userId)
        request.userVerificationPreference = .required

        let created: ASAuthorizationPlatformPublicKeyCredentialRegistration = try await withCheckedThrowingContinuation { c in
            continuation = c
            let controller = ASAuthorizationController(authorizationRequests: [request])
            controller.delegate = self
            controller.presentationContextProvider = self
            controller.performRequests()
        }
        guard let attestation = created.rawAttestationObject else {
            throw APIError(status: 0, message: "The device did not return the new passkey.")
        }
        let credential = PasskeyCreationOptions.credentialJSON(credentialID: created.credentialID, clientDataJSON: created.rawClientDataJSON, attestationObject: attestation)
        let done = try await client.post("/v1/admin/profile/passkeys", json: .object(["name": .string(name), "credential": credential]))
        return done.json?["message"]?.string ?? "Passkey added."
    }

    public func authorizationController(controller: ASAuthorizationController, didCompleteWithAuthorization authorization: ASAuthorization) {
        if let c = authorization.credential as? ASAuthorizationPlatformPublicKeyCredentialRegistration {
            continuation?.resume(returning: c)
        } else {
            continuation?.resume(throwing: APIError(status: 0, message: "Unexpected credential"))
        }
        continuation = nil
    }

    public func authorizationController(controller: ASAuthorizationController, didCompleteWithError error: Error) {
        // The console's sentence for a cancelled or timed-out prompt.
        if (error as? ASAuthorizationError)?.code == .canceled {
            continuation?.resume(throwing: APIError(status: 0, message: "The passkey was not confirmed — cancelled or timed out."))
        } else {
            continuation?.resume(throwing: error)
        }
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
