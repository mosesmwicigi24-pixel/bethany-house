# Android passkeys: investigation and proposed work

Status: investigation only; authentication and production configuration changes require the owner's approval. No implementation or deployment is approved by this document.

## Evidence (25 November 2026)

- Both `https://hub.bethanyhouse.co.ke/.well-known/assetlinks.json` and `https://hub.bethanyhouse.co.ke/.well-known/apple-app-site-association` redirected to `/admin/` and returned HTML on a read-only request. This is not a usable association response; the redirect's cause and actual production configuration have not been established.
- The backend's `WellKnownController` emits Android `common.get_login_creds` for one configured package and fingerprint list, and an Apple `webcredentials` entry. Its default Android package is `ke.co.bethanyhouse.hub`; the Tailors release application ID is `ke.co.bethanyhouse.tailors`, and its debug ID adds `.debug`.
- The locally built Tailors debug APK has SHA-256 certificate digest `6f43c97da070d1be76644d697e0daa9f34a980b5aa94b29697d2ae9f9f47550f`. Release/upload and Play App Signing certificate fingerprints and the deployed package configuration are **UNKNOWN — NEEDS VERIFICATION**. Do not add the debug certificate to production by assumption.
- `Passkeys` selects one configured/request origin for WebAuthn ceremonies, sets a five-minute challenge, requires user verification in creation and assertion options, uses a one-use cache pull and stores the returned credential record and counter. Tests exercise challenge rejection and a stale counter; exact backup-state validation and Android-origin support in the installed WebAuthn library remain **UNKNOWN — NEEDS VERIFICATION**.
- Android's step-up prompt currently selects password or TOTP fallback when the server offers a passkey. iPhone implements registration and authenticated step-up with native passkeys. The inspected server passkey routes are authenticated; passkey-only initial sign-in is not established by this audit.

## Proposed implementation, after approval

1. Trace the redirect through the hosting/proxy configuration and test both well-known responses in a nonproduction environment. Extend the Android association configuration/controller only as needed to express separately verified package/certificate pairs, preserving the Apple response and existing Hub association. Never accept arbitrary origins or fingerprints.
2. Verify the installed WebAuthn library's exact Android-origin validation semantics. If supported safely, add explicitly configured Android certificate-hash origins alongside the existing web origin; retain RP ID, required user verification, challenge lifecycle, account scoping and counter checks. Refuse malformed and untrusted origins.
3. Implement Android Credential Manager registration and authenticated step-up using existing API contracts, with cancellation, unsupported-provider and error states and existing fallback methods. Do not broaden initial sign-in or change authorization policy.
4. Test registration and assertion for approved and wrong origins, challenge reuse/expiry, account isolation, required verification, counter behavior and failure/cancellation; rerun web and Apple regression tests. Verify real credentials on a staging domain with a genuine signed Android build and the existing Apple flow.

Likely files include `backend/app/Services/Auth/Passkeys.php`, `backend/config/security.php`, `backend/config/applinks.php`, `backend/app/Http/Controllers/WellKnownController.php`, associated backend tests, Android Gradle dependencies, Android profile/security UI and new client tests. Hosting configuration is unidentified. No schema migration is currently proposed; need for configuration and deployment changes must be confirmed by the implementation audit.

Risks: accepting an unintended origin can weaken authentication; an incorrect association can break existing web or Apple passkeys; debug/release signing mismatches can prevent Android use. Preserve strict origin checking and existing fallback paths. Roll back by reverting the independently reviewed client/server changes and restoring the prior association/configuration, while retaining existing credential records. Production changes and deploy require the separate approvals in `AGENTS.md`.

## Local implementation notes

`PASSKEY_ANDROID_CERTIFICATES` is an empty-by-default, comma-separated list of SHA-256 signing certificate fingerprints (hex, optionally colon-separated). The server converts each explicitly configured fingerprint to an Android WebAuthn origin and rejects malformed entries. `ANDROID_TAILORS_PACKAGE` and `ANDROID_TAILORS_SHA256_FINGERPRINTS` configure a separate Digital Asset Links target without replacing the existing Hub target. The debug package has a `.debug` suffix, so a test association must use the matching test package and certificate; do not substitute a debug fingerprint for the Play App Signing fingerprint. These values have not been configured on production.
