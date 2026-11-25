# Tailors Hub audit progress

### Android passkeys investigation — 25 November 2026, Codex (claim released; verification open)

- Rechecked the public Android and Apple well-known endpoints: both redirected to `/admin/` HTML. Inspected the server's single-origin ceremony configuration, challenge/user-verification options, Android fallback and locally built debug certificate. The release and Play signing fingerprints, deployment routing and library backup-state behavior remain unknown. Findings and a nonproduction-first proposal are in `ANDROID_PASSKEY_PROPOSAL.md`; no auth or server configuration was changed.
- The iPhone and Android test apps returned to sign-in after the simulators were restarted, so renewed signed-in side-by-side testing is pending.
- Owner approved the nonproduction proposal. Codex claims `backend/app/Services/Auth/Passkeys.php`, `backend/config/security.php`, `backend/config/applinks.php`, `backend/app/Http/Controllers/WellKnownController.php`, related backend tests, Android Gradle dependencies and Android profile/security passkey client files and tests. Production association/configuration and deployment remain outside this claim and require separate approval.
- Full Android Tailors and Hub unit runs each reported three Compose idling timeouts in `CalendarMonthParityTest` and `TailorPieceCounterTest`; all three passed in an isolated Tailors run with one worker. Root cause and full-suite stability remain unverified; lint was not reached in the failed combined run.
- Nonproduction implementation: Android Credential Manager registration and step-up, passkey listing/removal, and password/TOTP fallback; backend ceremony origins add only explicitly configured certificate fingerprints, with no Android certificates trusted by default. App-link output supports separately configured Hub and Tailors targets. Existing Apple association output is unchanged. The locked WebAuthn 5.3.9 source matches non-URL origins exactly and its ceremony includes verification, backup-bit and counter checks.
- Against a disposable PostgreSQL database, 15 passkey and association tests passed (114 assertions), including trusted/untrusted Android origins and continued web-origin acceptance. Both Android debug variants built; three provider/API tests and focused navigation tests passed. Real Credential Manager provider behavior, end-to-end enrollment/step-up, Apple device regression, production certificate fingerprints, deployed route behavior and shared-branch review remain open. No production config or account credentials were changed.
- The updated test emulator loaded the empty passkey list and Add control; no passkey was enrolled or removed. Tailors and Hub lint each report two errors in `PeopleAttendance.kt` and their flavor manifests; the merged Tailors debug manifest does set `MainActivity` exported to true. The running iPhone test build returned to sign-in, so the signed-in comparison is still open.
- `bundleTailorsRelease` produced `android/app/build/outputs/bundle/tailorsRelease/app-tailors-release.aab`; `jarsigner` reports it is unsigned. This is an unverified review artifact, not a Play-ready release. Upload signing, Play App Signing fingerprint, passkey associations, complete parity and release review remain open; nothing was uploaded or deployed.
- Apple shared-package tests passed on macOS (127 tests). The `BethanyKit` Xcode scheme has no test action, so this is not an iPhone-simulator test run.

### My Tasks task-row icons — 25 November 2026, Codex (claim released; verification open)

- Replaced the Android task-list hanger and filled shield with outlined garment and quality icons, closer to the signed-in iPhone reference. Both Android debug variants and focused production tests passed; visually inspected the loaded test account on the emulator. Other typography, navigation and accessibility differences remain open. Claim released.
- The TailorsMobile iPhone simulator build passed; the 127 Apple package tests were run on macOS, not the simulator.

### Task count accessibility — 25 November 2026, Codex (claim released; verification open)

- Added a “Draft count” accessibility label and value to the Android count editor. The focused tests passed, and the emulator accessibility tree shows the label and number. Signed-in comparison opened and cancelled both platforms' editors without saving. Full TalkBack/VoiceOver testing remains open. Claim released.
- A full Tailors unit-suite retry with one worker again produced three Compose idling timeouts in the calendar and count-editor tests (195 tests, 108 skipped); the affected tests pass in isolation. The underlying test-order/environment issue remains unknown.

### Android Updates presentation — 25 November 2026, Codex (claim released; verification open)

- Removed the duplicate Tailors page title and default subtitle, and styled the recognition action closer to iPhone; a nonzero must-read notice remains visible. The Hub flavor and feed actions are unchanged. Both Android debug variants and the focused route-render tests passed. Inspected both signed-in empty states; populated feed, must-read, posting, filtering, error and accessibility states remain unverified. Claim released.

### Android profile presentation — 25 November 2026, Codex (claim released; verification open)

- Aligned Tailors profile navigation labels, avatar, outlet placement and action hierarchy with the iPhone reference; removed the duplicate in-page title. Hub presentation and security operations were not changed.
- Both Android debug variants built and focused profile-identifier and shell tests passed. In the signed-in test emulator, verified the profile controls and opened employment and activity tabs without editing data. Pixel comparison, photo upload, security actions, populated activity, large text, accessibility and failure states remain unverified. Claim released.

### Android messages presentation — 25 November 2026, Codex (claim released; verification open)

- Tailors Android now groups channel rows in cards, displays contextual descriptions and the iPhone's empty-message wording, and shows a production-order header with an Open record action in contextual conversations. Hub flavor keeps its prior list and header. Existing message loading, read marking and sending code was not changed.
- Both Android debug builds and focused route/shell tests passed. Visually checked the signed-in test account's list and opened a test conversation; opening may have marked that test thread as read. No message was sent. Paired list screenshots and remaining differences are in `ANDROID_CHECKLIST.md`. Sending, attachments, unread transitions, failures, keyboard clearance, actual bottom navigation in a thread, large text, dark mode and TalkBack remain unverified. Claim released.

### Android calendar presentation — 25 November 2026, Codex (claim released; verification open)

- Added iPhone-style summary tiles, today outline and selected-date styling, monthly order count, compact order cards and a separate past-due list using existing schedule data. Android keeps 48dp date targets. Existing order resolution and permission checks remain in place.
- Both Android debug builds and focused production tests passed; a Compose test verifies selecting a day filters orders and Show month restores the list. On the signed-in test emulator, visually checked the month and past-due card without changing an order. Reference screenshots and open gaps are recorded in `ANDROID_CHECKLIST.md`. Different months, calendar-to-task return, larger text, dark mode, TalkBack and error/offline states remain unverified. Claim released.

### Android time-clock presentation — 25 November 2026, Codex (claim released; verification open)

- The Tailors Android clock uses a compact status-and-time card and a labeled Your time summary; long last-punch flags wrap. It no longer claims the tailor is at an outlet when the outlet has no saved coordinates. Existing clock, mock-location, permission and attendance eligibility checks are unchanged; Hub retains its existing clock design.
- Both Android debug builds, core ClockGate tests and Android kiosk tests passed. On the signed-in test emulator, visually checked the no-outlet-location state and wrapped last-punch flags without recording attendance. Screenshots and the differing iPhone location state are in `ANDROID_CHECKLIST.md`. iPhone source appears to show the same misleading “You are at…” hint when distance is unavailable; logged for iOS review without changing it. Clock-in/out, background trail, offline, other permission states, dark mode, large text and TalkBack remain unverified. Claim released.

### Android task-detail actions — 25 November 2026, Codex (claim released; verification open)

- Tailors task-detail counters now display a progress bar and an explicit Update count → draft → Save count flow. Draft changes make no request until saved, failed saves retain the draft, and queued counts display a waiting-to-sync label. Pending status actions disable conflicting Tailors controls, and blocked stages cannot start from the Tailors UI. Hub's immediate counter remains unchanged.
- Tailors and Hub debug builds passed; focused production, queue and draft-counter tests passed. On the signed-in Android test emulator, visually inspected the task card and opened/cancelled the editor without saving. Paired reference screenshots and remaining UI differences are recorded in `ANDROID_CHECKLIST.md`. Offline replay races, real server validation, batch controls, dark mode, large text and TalkBack remain unverified. No task record was changed. Claim released.

### My Tasks visual parity — 25 November 2026, Codex (claim released; verification open)

- Matched the task list's centered Tailors mark, greeting, workshop icon, segmented Active/History control, lane dots, task-row icons and chevrons. Tailors phone navigation now uses a floating card and remains visible on nested pages. Hub flavor keeps its existing navigation. The owner confirmed the simulator account is a test environment; no records were changed in this slice.
- Tailors and Hub debug APKs built, and focused Tailors production and shell tests passed, including bottom navigation in a conversation. Visually inspected the Android task list and recorded a paired reference and updated screenshot in `screenshots/my-tasks/`; see `ANDROID_CHECKLIST.md`. Font sizes, material icons and touch targets intentionally follow Android conventions and remain visibly different in places. Dark mode, largest text, TalkBack, tab restoration, nested-page keyboard behavior, and full device navigation remain unverified. Task detail, clock, calendar, messages and other pages have separate open gaps. This file claim is released.

### Android task queue ordering — 25 November 2026, Codex (verification open)

- Android now checks the current user's readable saved queue before sending a stage status or count. When that stage has saved work, the new change is saved for replay; an unavailable or unreadable queue blocks the update with an explanation. This aligns with the existing iPhone behavior and does not change server rules.
- Tailors debug build, focused production and queue tests, and full Tailors unit suite passed. The new queue test checks exact task scoping and unreadable storage. End-to-end replay with a simultaneous in-flight update, process restart, server refusal and emulator offline transitions remain unverified; deduplication and concurrent replay deserve separate review on both platforms before claiming strict ordering. No live task was changed.

### Android More and text size — 24 November 2026, Codex (verification open)

- Tailors phone More is a grouped page with the iPhone's Workshop, My workday, Time & pay, App settings and account sections. Existing permission-filtered routes and the offline sign-out warning are preserved. The signed-in Android emulator showed More and returned to it from Production Orders; the final divider and heading polish has built but has not been visually rechecked. Other roles, deep links, tablet, dark appearance and accessibility sizes remain open.
- Added a device-local text-size preference, device-setting switch and 9–36 reference slider. The emulator showed the dialog and live large-text resizing; the device setting was restored. Tailors debug build, focused Tailors and Hub shell/navigation tests and text-size persistence/range tests passed. Extreme-size screen usability and device restart remain open. The preview follows Android scaling and is not an exact iOS Dynamic Type mapping.
- Passkey audit: the public `https://hub.bethanyhouse.co.ke/.well-known/assetlinks.json` currently redirects to `/admin/` and returns HTML, not an Android association. The checked-in association config names one Android package, and the passkey validator accepts one configured/request origin. Release signing fingerprints, deployed origin configuration and the exact server changes needed for Android remain UNKNOWN — NEEDS VERIFICATION. No authentication or production configuration was changed.

### Android task disclosure preferences — 24 November 2026, Codex (verification open)

- Specifications and measurements expansion choices are stored locally per signed-in user, matching the iPhone behavior. Tailors debug build and focused production tests passed; account switching, persistence across restart and accessibility announcement need device verification.

### Android task detail parity — 24 November 2026, Codex (verification open)

- Reordered the Tailors task workspace to show the job identity and actionable stages first, followed by separate specification and measurement cards, bench note, stage overview, journey and conversation. Completed stages are summarized with status, recorded time and note action. Existing stage actions, endpoints and permission rules remain in place; Hub flavor layout is unchanged.
- Tailors debug build, focused production tests and the full Tailors unit suite passed. On the signed-in Android emulator, visually checked the job heading, active-stage controls, overview status colors and recorded timestamps without changing any records. History, QC notices, measurement disclosure interactions, conversation navigation, dark appearance, large text, and stage actions still require device verification. Disclosure preference persistence and native iPhone navigation presentation remain parity gaps.

### Android navigation appearance — 24 November 2026, Codex (done)

- Replaced the Tailors phone navigation's purple Material default with the app's orange selection and neutral indicator. The Hub flavor retains its existing colors. Tailors debug APK build and ShellRenderTest passed; visually checked the updated selected tab on the signed-in Android emulator. Dark mode and large text remain unverified.
- Signed-in My Tasks and Calendar loaded account data; the task detail was opened read-only. Full screen and behavior parity, including signed-in profile/security flows, remain open. No production records were changed.

### Android parity with iPhone — 24 November 2026, Codex (in progress)

- Android now exposes existing two-step setup, password-confirmed disable and recovery-code replacement with an explicit warning. Server authentication, role and step-up checks remain authoritative. Tailors APK build and mock-Hub tests for setup, confirmation, refusal and recovery requests passed; no live security setting was changed. Signed-in setup, QR scanning, step-up, recovery-code handling and screen accessibility remain unverified on an emulator. Android passkey registration remains open.
- Android now offers the existing own terminal-PIN flow with password confirmation and the same PIN guidance as iPhone. The Tailors APK build, PIN-rule tests and a mock-server test for request fields and server rejection passed. No live PIN was changed; the screen and successful update remain unverified on a signed-in emulator. Two-step setup and Android passkey registration remain open.
- Android Profile now has employment and activity tabs, personal-detail editing, masked identifiers, signed documents, profile editing and a photo picker. Existing server authentication and permission rules were not changed. Tailors APK build and focused identifier/task tests passed. The rebuilt app was installed and opened to sign-in; signed-in views, editing, document access and photo upload remain unverified. Showing a masked identifier does not establish deduction enrollment. Two-step setup, passkeys, terminal PIN management and wider parity remain open.
- Android Tailors Hub sign-in now shows the tailor name and scissors. My Tasks groups orders into iPhone-style status lanes, opens an order for its existing stage actions, and shows recorded start/completion timestamps in the theme color. Task mutations and offline code were not changed.
- Tailors debug APK built and ran on the Android emulator; the sign-in branding was visually checked. Focused ProductionParityTest passed, including lane classifications. Signed-in task list, task actions, timestamps and accessibility still need emulator verification using a suitable test account or fixture. My Tasks details, item conversations, profile, calendar order navigation and other screens still need parity work.
- Android task details now load the matching production-order conversation and recent messages using the existing Messages models, with a link to the full conversation. Tailors debug build and focused production tests passed. Signed-in conversation, navigation, empty and error states remain unverified on the emulator.
- Tailors task details now open measurements by default and show compact measurement tiles in the existing sheet order, with stage icons and recorded timestamps. Tailors debug build and focused production/identifier tests passed; signed-in layout, large text and stage interactions remain unverified.
- A horizontal stage journey now displays assigned stages in order with explicit status labels and subdued unfinished stages. Android build and focused tests passed; the journey has not yet been visually checked on a signed-in emulator.
- Tailors calendar order taps now resolve a unique exact order number, then open an assigned job in My Tasks or the permitted production-order view. The focused test covers exact, missing and ambiguous matches; Tailors APK and focused tests passed. Calendar-to-task, conversation return and permission behavior still need signed-in emulator testing.
- Android unit suites now use flavor-appropriate route and shell expectations: Hub passed 269 tests (108 skipped) and Tailors passed 184 tests (108 skipped) in separate runs. The skipped live-Hub tests and signed-in emulator checks remain open.
- Android clock now uses the same status gate as iPhone: a known status remains usable after an offline failure, but Hub errors block punching and overrides require a fresh status. ClockGate unit tests and the Tailors debug build passed; no live attendance records were changed. Emulator verification of offline and Hub-error states remains open.
- Auditing Android Tailors Hub against the iPhone experience; starting with the production calendar. Android offline queue files remain outside this work.
- Calendar: hides the stage breakdown in Tailors Hub, adds a past-due summary, date grid, selected-day filtering and Today/Next deadline shortcuts. Tailors debug APK built, installed and launched on the Android emulator; signed-in calendar appearance and interactions remain unverified. My Tasks, calendar order navigation, profile and other screens still require parity work and verification.

### Profile and employment redesign — 24 November 2026, Codex (verification open)

- Profile polish: aligned profile action widths, compacted the passkey empty state, styled security actions, clarified the personal-details heading and placed the activity empty state in a card. TailorsMobile simulator build succeeded and the app was launched. Signed-in visual and interaction checks remain open.
- Follow-up: profile editing now blocks saving when the profile cannot load and offers retry; missing emergency contact has an add/edit prompt, and recent activity shows date and time. TailorsMobile simulator build passed. The app launched to sign-in; the follow-up has not been checked in signed-in screens. Deduction enrollment, photo upload and security interactions remain unverified.
- Apple profile groups the existing sign-in controls, removes sessions and named devices from the page, adds a photo-library picker, and displays only populated employment fields and identifiers. Activity remains one tab with the latest 20 actions. Existing server security and permission rules remain in place.
- TailorsMobile simulator build and HubMac build passed. The iPhone 17 Pro simulator launched to sign-in; signed-in screens, photo upload, security actions and employment states have not been verified. Deduction enrollment is not established by the presence of an identifier; the current display only hides missing identifiers, so enrollment-based filtering remains open.

### Cleaner My Tasks and stage colors — 24 November 2026, Codex

- Refined My Tasks spacing and white cards; stage icons use distinct theme colors when active, unfinished stages are subdued, and completed status is green. Recorded dates and times are orange. The stage journey uses the same icon colors. Existing stage actions and status labels remain.
- TailorsMobile iPhone simulator build passed, including the green status and orange timestamp change. Visually inspected the task list with fictional data. A separate timestamp fixture verified the stage accessibility values show a known completion date/time and first-start date/time in Nairobi; absent timestamps are not invented. Stage colors and dark appearance still need visual inspection in the simulator.

### Cleaner task and calendar surfaces — 24 November 2026, Codex

- Applied the More screen's lighter grouped-card treatment to My Tasks detail, its stage list and journey, task rows, and Calendar summary tiles. Stage names use lighter weight and more vertical spacing; work actions, measurements, statuses and message behavior remain unchanged.
- TailorsMobile iPhone simulator build passed. Using fictional data, visually inspected the task-detail header and cards; accessibility inspection showed the stage rows and actions. Simulator control disconnected before the stage card and calendar could be visually inspected. Dark mode, large text, iPad and the remaining app pages have not been reviewed for this visual treatment.

### Stage spacing and text size — 24 November 2026, Codex

- Added spacing and hierarchy to task stage rows. The status line shows the Hub's completion time for completed stages or the first start time for in-progress/paused stages, when available, using Nairobi time; no timestamp is fabricated.
- Added Tailors Hub Text size settings in iPhone More and the iPad sidebar. A 9–36 pt sample slider maps to iOS Dynamic Type sizes, with a device-setting option. The sample uses the exact selected point size; individual app text styles scale differently. The choice is stored on the device.
- TailorsMobile iPhone simulator build passed. With fictional data, opened the settings, moved the slider to 9 and 36, saw the preview and app text scale without navigating away, and restored the device setting. The sample order did not include timestamps, so the timestamp display remains unverified in the simulator. iPad, dark mode and other screens at large text sizes remain unverified.

### Stage journey overview — 24 November 2026, Codex

- Added a horizontal, connected stage journey card below Your stages. It uses the existing assigned-stage sequence, stage icons and explicit statuses; the detailed stage rows and their actions remain available. The connecting line is neutral and does not imply that intermediate stages are complete.
- TailorsMobile iPhone simulator build passed. The simulator window changed during sign-in, so the card's visual layout, horizontal scrolling, accessibility sizes and dark appearance remain unverified. No live data was changed.

### Task stage rows — 24 November 2026, Codex

- Stage rows now use the existing stage-specific icons, a name and explicit status, with a completion tick at the far end. Pending stages say “Not done”; paused, failed and in-progress states retain their accurate labels. Existing add-note actions are preserved.
- TailorsMobile iPhone simulator build passed. With fictional data, accessibility inspection confirmed four Done stages and one In progress stage and their note actions. Simulator control disconnected when attempting to scroll for a visual inspection; pending, dark mode, larger text and the final card appearance remain unverified.

### Tailors Hub header — 24 November 2026, Codex

- Replaced the two-line My Tasks heading on the Tailors Hub task list with the app's existing scissors-and-thread icon and a larger Tailors Hub name. The system navigation title remains My Tasks for back navigation; Bethany Hub retains its My Tasks heading.
- TailorsMobile simulator build passed; visually checked the header and task list on iPhone 17 Pro with fictional Hub data. Dark mode, larger accessibility text and iPad remain unverified.

### Task detail redesign — in progress, 24 November 2026, Codex

- Replaced the compact-width task overlay with navigation-stack detail, preserving the wide split and the Calendar sheet entry; added a product navigation title, selectable order reference, scoped customer label where present, and labeled due date. Separated specifications and measurements into expandable sections, with locally stored per-account disclosure preferences. Made measurement icons consistent, styled the note quietly, and presented the assigned stages as a vertical timeline. Existing stage actions and job conversation remain.
- TailorsMobile iPhone 17 Pro simulator build succeeded. With a fictional local Hub, visually checked the in-progress shirt, separate expanded sections and system Back to My Tasks. No live data was changed.
- **Still open:** measurement units remain UNKNOWN — NEEDS VERIFICATION and are not invented; order and fitting dates are absent from the existing task payload. Verify per-user disclosure persistence, empty/offline/error states, completed and sparse orders, Calendar-to-detail return, VoiceOver, Dynamic Type, dark mode, iPad/Mac and tab-bar clearance. Relative due-date indicators, full before/after screenshot set and the requested broader simulator matrix remain unfinished. Do not describe this redesign as fully verified.

### Personalized My Tasks header — 24 November 2026, Codex

- Added Tailors Hub branding above the My Tasks navigation title and a greeting using the signed-in account name above the workshop list. Offline and pending-sync controls remain in place.
- TailorsMobile iPhone 17 Pro simulator build passed. The header, sample account greeting and populated task list were visually inspected using a fictional local Hub. No live Hub data was changed. Dark mode, larger text, iPad and missing-name states remain unverified in the simulator.

### My Tasks workspace — 24 November 2026, Codex

- Removed Calendar's open-work-by-stage section. My Tasks now opens specifications and measurements by default, groups the bench note in a themed card, and previews up to three recent messages from the existing membership-scoped production channel. Opening the preview uses the existing full Messages conversation; previews do not mark messages read. Missing or dismissed channels are not created or rejoined automatically.
- TailorsMobile simulator build succeeded. With a fictional local Hub on iPhone 17 Pro, verified the expanded measurement sheet, bench note, recent message, and opening the same conversation. No live Hub data was changed. The screenshot inspection showed the top of the workspace; the conversation was also checked via accessibility navigation.
- Calendar removal was checked in source but not visually rechecked after the simulator control disconnected. Empty/dismissed channel, error/retry, dark mode, accessibility sizes, iPad and live Hub behavior remain unverified. No Android offline files or Hub permission logic changed.

### Calendar and My Tasks navigation — 24 November 2026, Codex

- Calendar now opens the same My Tasks job view when an order is in the signed-in worker's task list. A Calendar back control returns to the calendar. Other orders retain the existing production-order route; no Hub endpoints or permission gates changed.
- The TailorsMobile simulator build succeeded. On iPhone 17 Pro (iOS 27.2), using a fictional local Hub, the assigned shirt opened from Calendar with its stitching progress, measurements and stages, and returned to Calendar. No live Hub data was changed.
- Unassigned-order fallback, assignment changes between requests, network failures, iPad layout, accessibility sizes and live Hub behavior remain unverified in the simulator.

### Production-order layout — 24 November 2026

- Reordered the order page to show its summary, work instructions and measurements, then compact stages; removed its PDF action and Details section at the owner's request. The removed Details section previously displayed fitting/collection dates and a sales-order link; those are no longer shown on this page.
- Added restrained color to the shared measurement icons. The iPhone simulator build succeeded and a fictional order's summary, measurement grid and section sequence were checked. Dark appearance and large text remain unverified.

### Production calendar — 24 November 2026, Codex

- Restyled open-stage workload rows with relevant existing production icons and task-count badges. Checked four fictional stage types on iPhone 17 Pro; an empty `by_stage` response still hides the section.
- Reordered the production-order page so its status and actions come first, followed by stages, work instructions (notes, measurements, specifications and preferences), batches/materials, administrative details and thread. Verified the section sequence with a fictional order in the simulator. No Hub data or permission logic changed.
- Refined calendar styling with quieter summary tiles, a today outline, selected-day contrast for light and dark appearance, a deadline legend and piece counts where provided. iPhone simulator build and sample date selection checked; dark appearance and larger text remain unverified.
- Redesigned `ProductionBoards.swift` with a selectable month grid, due-order agenda, past-due summary and open-stage workload. Existing schedule data is scoped by the server. Tapping a card uses the permission-scoped production-order search and exact order number to resolve its ID; if lookup fails the app reports it.
- On an iPhone 17 Pro simulator with a fictional Hub, selecting 24 November filtered the agenda to one order; tapping the sample card opened the matching order and Back returned to the calendar. No live data was changed.
- Open: the schedule response does not include fitting or collection dates. Those dates appear in order details when available, but are not shown as calendar events. Simulator coverage does not establish live Hub parity or all permissions, failure and accessibility states.

### Tailors Hub app icon — 23 November 2026

- Added a terracotta scissors-and-thread icon to the Apple TailorsMobile app icon catalog. The iPhone simulator build succeeded and the installed icon was inspected on the iPhone 17 Pro home screen. App Store distribution remains separate and unverified.

### In progress — 23 November 2026, Codex

- Refining the Apple production-order Details and Thread UI in `ProductionOrderDetail.swift`, reviewing the time-clock UI in `MeFeatureClock.swift` against the Hub, and auditing the Apple More pages one by one; preserving existing Hub APIs and permissions. Claude has claimed Android offline files separately.
- Local slice: order header separates status badges from the order number; details use labelled rows; the thread composer uses a separate type selector and guards repeated sends. Clock shows an error and retry when status cannot load, and avoids presenting absent summary data as zero. iPhone simulator build succeeded and 121 Swift tests passed. The finished layouts, thread sending/failure and clock actions still need simulator verification. The full More-page audit remains in progress.

### iPhone simulator review — 23 November 2026

- Reviewed commit `571a6dc4` on iPhone 17 Pro simulator, iOS 27.2, against the live Hub. The review used read-only navigation and no task, attendance or employee data was submitted.
- Xcode `TailorsMobile` Debug simulator build succeeded. Its sole warning was: `warning: Metadata extraction skipped, no AppIntents.framework dependency found`.
- The signed-in account showed My Tasks, Calendar, Messages and Clock in, plus More. More showed workday links and no People section. The account's assigned role was not independently verified; the menu and access test reflect its observed permissions.
- A task showed a Men badge before nine measurements, in the same order as the previously supplied screenshot. Independent comparison against the Hub's original measurement sheet remains unverified.
- The custom-scheme link `tailorshub://hub/admin/people/5` displayed `You don't have access to this page`. An actual HTTPS universal link was not tested.
- HR/payroll account navigation and employee-file access remain unverified pending an appropriate sign-in. Screenshots of the task, measurements, More and access-denied screens were saved locally under `/private/tmp/tailors-review-571a6dc4-screens/`; they contain live data and are not committed.

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

### Offline queue P0 fixes (23 November 2026, later)

Branch brought up to date with `main` (#578–#582, #553 merged today). Conflicts
were the earlier Tailors Hub code arriving twice (once squashed); the newer
branch side was kept and the menu test follows `main`'s "Me" → "My Workday"
rename.

- **One sending path.** `OfflineStore.send(_:using:)` is now the only way a
  queued operation is sent. Automatic replay and the clock screen's resend
  both use it, and an operation already in flight is never sent a second time
  (`.alreadySending`). A replay that meets an operation in flight stops there,
  so nothing queued after it reaches the server first.
- **Accepted but not cleared.** `dequeue` reports failure instead of
  swallowing it. An operation the server accepted whose removal could not be
  written is held in memory as delivered: hidden from the queue, never resent
  while the app runs, and dropped from disk at the next successful write.
  Replay reports these (`notCleared`) and the app says they will not be sent
  again.
- **Unreadable is never empty.** Replay reports an unreadable queue
  (`unreadable`) and sends nothing. The sign-out guard uses the checked read
  and warns "Saved changes can't be read" instead of signing out silently. The
  pending badge is set only from the checked read (`refreshPendingCount`), and
  `ConnectionMonitor.pendingUnreadable` flags an unreadable queue (shown in the
  Mac sidebar).
- **Tests.** Five new `OfflineStoreTests`: two senders at once send a punch
  once; replay keeps order behind an operation in flight; temporary failure
  keeps and final refusal removes; accepted-but-uncleared is not resent and
  leaves disk at the next write; an unreadable queue replays nothing and is
  reported. All 8 offline-store tests pass on Linux (Swift 6.0.3, APIClient
  stubbed). Mutation check: removing the in-flight guard fails the two
  concurrency tests (the punch is sent twice); removing the delivered filter
  fails the uncleared test.
- **Not verified here:** the SwiftUI changes (clock screen, sign-out alert,
  Mac badge) compile only in Apple CI; no simulator or device run.

Still open:
- Restart durability: an accepted-but-uncleared operation survives on disk
  only until the next successful write; if the app is killed first, it can be
  resent after restart. The server side (punch state check, absolute progress
  counts) limits the effect, but request idempotency is not established
  server-side — UNKNOWN — NEEDS VERIFICATION.
- Android has its own offline store and sign-out guard; none of these fixes is
  ported there yet.
- ~~Tailors Hub scope~~ decided by the owner (2026-11-23): the workshop app
  for everyone; the own-workday screens stay (advances, checklists, handbook,
  to sign, to-dos, colleagues added); People administration, employee files
  and team payroll removed from Tailors Hub on both platforms, whatever the
  person's permissions. `AppFlavorTests` / `AppFlavorTest` pin a manager with
  HR and payroll rights. Today (`.me`) is left out: not in the owner's list.
  Tailor and manager experiences still need a run on a device.

### People administration restored (owner, 2026-11-23, later)

The owner approved restoring People administration, employee files and team
payroll to Tailors Hub for those whose existing Hub permissions open them.

- `TailorsHub.allows` (Swift and Kotlin) includes People overview, requests,
  attendance, checklist management, roster, timesheets, leave, employee files
  (list and detail) and payroll again. Each keeps its catalogue gate
  (`attendance.view_team`, `forms.manage`, `hr.view`, `payroll.view`). No
  server permission, outlet scope, step-up or audit code changed.
- **Detail gate (found by Codex):** Android's route guard looked up the exact
  route, so one employee's file (`People(5)`, not a menu item) had no gate and
  only the server stood between a direct link and the page. Both platforms now
  resolve a screen's gate with `NavCatalogue.gate`: the item's own gate, else
  its list's (`Route.parent`). Apple already inherited the parent gate in
  `RouteView`; the rule moved into BethanyKit (`Route.parent`) so it is shared
  and tested, and `Navigator.parent` forwards to it.
- **Tests (hand-written expectations):** an HR manager sees all nine People
  pages; a team lead without `forms.manage`/`hr.view`/`payroll.view` sees six;
  a tailor without management rights sees none; `/people/5` parses to an
  employee file whose gate is `hr.view` and refuses a tailor and a team lead.
  Swift `AppFlavorTests` 7/7 (Linux, Swift 6.0.3); Kotlin `AppFlavorTest` 8/8,
  core suite 37/37; both Android APKs build. Mutation check on both
  platforms: reverting to the exact-route lookup fails the direct-link test.
- Offline reliability changes (`OfflineStore.send`, checked reads) untouched.
- **Not verified:** the Apple screen layer compiles only in Apple CI; no
  simulator or device run from the cloud. A run on the owner's Mac (TailorsMobile
  in the iPhone simulator) is still to do: the Mac is not connected to this
  session.

### Offline clock-in restored — 24 November 2026, Claude

Codex reported, and the owner chose that Claude fix, before #588 merges.

- **Regression (from 6d2d4455):** `canPunch` required the clock status to
  load without error, so a failed refresh with no signal greyed out Clock in
  even with the last status and a valid location on screen; the tap never
  reached the offline queue.
- **Second defect, same path:** the offline branch of `punch()` saved to
  `env.offline`, which can still be unset just after sign-in, turning a saved
  punch into an error. It now uses the store `punch()` resolved at its start.
- **Rule:** `ClockGate` (BethanyKit). Clock in/out is available with the last
  status on screen when it is fresh or failed only for being offline; a Hub
  error or no status still blocks. A forced (override) punch still needs a
  fresh status: it is never queued.
- **Tests:** `ClockGateTests` (5) and `OfflineStoreTests` (9, including a
  punch made offline: kept while offline, sent exactly once on reconnect with
  its original `recorded_at`). 14 pass on Linux (Swift 6.0.3, APIClient
  stubbed). Putting the strict rule back fails the offline-gate test.
- **Not verified here:** the screen itself. On the PR head, on a simulator:
  network off → Clock in saves "waiting to be sent"; network on → sent once,
  the Hub shows the original time.

### Offline clock-in simulator check for #588 — 24 November 2026, Codex

TailorsMobile Debug built from `94227319` in a clean snapshot and ran on an
iPhone 17 Pro simulator with iOS 27.2 and a fictional, local Hub.

1. Opened Time clock online. It showed “Not clocked in,” a location fix at
   the fixture workshop, and an enabled Clock in button.
2. Shut off the fixture's HTTP listener and confirmed the connection was
   refused. The simulator's network interface stayed up.
3. Tapped Clock in. The app showed that the punch was saved and “waiting to
   be sent.” The on-disk queue contained one punch, with `recorded_at`
   `2026-11-24T09:54:54.326Z`. The fixture had received zero punches.
4. Restored the listener and foregrounded the app. Automatic replay sent one
   clock-in POST. The fixture received it at `09:55:46.955Z`, recorded the
   original timestamp, and the app's on-disk queue cleared.
5. The open screen still showed the pending banner after automatic replay.
   Tapping Send now refreshed the screen to “Clocked in at 12:54” Nairobi
   time. The fixture's total stayed at one POST.

This verifies saving during a Hub transport outage, automatic foreground
replay, and timestamp preservation. The simulator gestures did not establish
a failed status refresh before the tap. The five `ClockGateTests` and nine
`OfflineStoreTests` passed on the same head, including the failed-refresh gate
and replay timestamp cases. Airplane-mode reachability changes and production
attendance processing were outside this fixture run. Dark appearance, larger
text, iPad, empty, and offline job-detail states remain open.

### Android offline queue — 24 November 2026, Claude (done)

Ported the iOS queue fixes to Android, same rules on both platforms:

- **One sending path.** `OfflineStore.send(id, transport)` is used by the
  automatic replay and by the clock's Send now; an operation already in
  flight answers `AlreadySending` instead of being posted twice.
- **No resend after a failed rewrite.** Accepted but not cleared from disk
  (`SentButNotCleared`) is hidden and never sent again while the app runs; the
  next successful write drops it. Writes go through a temporary file.
- **Unreadable is not empty.** `pendingChecked()` throws `QueueUnreadable`;
  replay reports it, enqueue refuses to overwrite it, the top bar says "saved
  changes unreadable", and sign-out warns instead of reading 0.
- **Only a final 4xx drops work.** Before, the clock deleted a saved punch on
  any non-offline error, a 500 included. Now 401, 408, 423, 429 and 5xx keep
  it (`isFinalRefusal`, same list as iOS).
- A failed enqueue (clock punch, task update) now says it was not saved.

Verified here: `OfflineStoreTest` 9/9, each case mutation-checked (in-flight
guard, delivered set, unreadable-as-empty, old refusal list); core 46/46;
app `testHubDebugUnitTest` 265/265 (one run failed `KioskSetUpTest` on
timing; it passed alone on this tree and on the base, and on a full re-run);
`assembleHubDebug` and `assembleTailorsDebug` build.
**Not verified:** on a phone or emulator. Server idempotency for a replayed
punch remains UNKNOWN — NEEDS VERIFICATION.

### Profile: sessions and named devices removed on Android — 24 November 2026, Claude

Owner decision (2026-11-24): the apps' own profile page no longer shows
Sessions or Named devices, matching Codex's iOS change. People manage them on
the console's Setup › Profile page; the Android note now says so. Setup ›
Users (a manager's per-person sessions) is unchanged. The page re-reads the
person on open so the sign-in safety badges are current (and the route render
test still sees a Hub request). App tests 265/265; both flavours build.
Not verified on a device.

### Punch duplicates: server checked, Android guard — 24 November 2026, Claude

- **Server (verified in code):** a replayed punch cannot create a second
  shift. `TimeClockController::clockIn` refuses with 422 when the person is
  already clocked in, and the partial unique index
  `time_entries_one_active_per_user` (one `active` row per user) rejects a
  racing second insert. A replayed clock-out after one that landed gets 404.
  Both are final refusals, so the app drops the duplicate; the person sees a
  red "could not be recorded" toast, which is harmless but reads as an error.
  A racing insert that hits the index is not caught in the controller, so it
  would surface as a 5xx (kept, then refused with 422 on the next replay).
  Not exercised against a live database here.
- **Android:** a second punch while one is saved is now refused ("Send the
  saved punch before recording another"), as on iOS. Before, a second offline
  tap replaced the saved clock-in with a later one and lost the true time. The
  pending badge refreshes after an offline punch. App tests 265/265, core
  46/46, both flavours build. No UI test: the clock needs a location fix the
  render harness cannot fake. Not verified on a device.
