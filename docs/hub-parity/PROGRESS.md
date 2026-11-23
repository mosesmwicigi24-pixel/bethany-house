# Tailors Hub audit progress

## 23 November 2026

- Inspected the user-provided brief, repository governance, existing working tree, prior native checklist and audit backlog, route allowlist and registrations.
- The native checklist is dated 29 October and predates later simulator work. Its device verification status requires reconciliation with current evidence.
- Preserved the pre-existing dirty working tree, including entitlement and generated plist changes.
- Created an initial workflow inventory. All rows remain partial or divergent until the requested end-to-end checks are complete.
- Traced the offline queue and clock resend paths. An unreadable queue is silently represented as empty. Sign-out retains the queue, but its guard can incorrectly report that no changes are pending. Task and clock paths can also fail to recognize pending work. This requires a full design and regression checks.
- Corrected manual clock resend so temporary HTTP errors and authentication expiry retain a queued punch. Existing queue refusal classification is reused. An end-to-end resend test remains outstanding.
- Previous conversation evidence includes selected fictional iPhone/iPad flows and 96 Swift tests; those observations do not establish whole-app parity.

### Offline retry verification

- iOS simulator target built successfully with the clock resend classification change.
- Swift suite passed 96 tests, including existing temporary-versus-final refusal classification tests.
- The simulator has not yet reproduced an offline punch followed by HTTP 500 or 401. This path remains unverified end to end.
- Added a throwing queue read for paths that must distinguish unreadable saved work from an empty queue. Task changes and new clock punches now stop with a visible message when that check fails. The sign-out guard, pending badge and general replay still use the older non-throwing read and remain open.
- The simulator initially exposed a missing offline store immediately after sign-in. Task and clock actions now resolve the signed-in user's store on demand; the underlying session-change observation and its effects on realtime and other offline features still require investigation.
- On an iPhone 18 Pro Max simulator, a fictional damaged queue blocked a task count update, displayed an actionable error and retained the count draft. After restoring the local test fixture, a separate fictional Hub accepted one count update from 4 to 5 and the app showed “Count saved.” This does not verify the live Hub.
- Manual punch resend and automatic queue replay each send independently. Concurrent execution can therefore issue the same pending request twice. The server checks whether a user is already clocked in, but the inspected controller does not establish request idempotency or prove concurrent safety. Investigate and test before relying on this path.
- Queue deletion errors are currently suppressed, so a reported successful send may remain queued and be retried. This remains open.
- After the latest changes, the shared Mac app target built successfully with code signing disabled, and the Swift suite passed 96 tests. A Mac runtime walkthrough and the remaining iPad and iPhone scenarios have not been completed.
