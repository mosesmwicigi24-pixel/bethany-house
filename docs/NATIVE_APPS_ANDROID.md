# Android apps — phone and Samsung tablet (native apps programme, Steps 6–7)

Code: `android/`. One Gradle project, two modules, one APK for both phone
and tablet (the shell adapts by window width class).

| Part | Path | What it is |
|---|---|---|
| `core` | `android/core` | Android library, no UI: `ApiClient` (OkHttp, the Step 2 contract), `SessionStore` (sign-in, 2FA, renewal, idle policy, PIN lock, step-up, held downloads), `SecureStore` (Keystore-encrypted prefs), device identity, permissions and the navigation catalogue (`NavCatalogue`, ported from the console sidebar and pinned by tests to the same slugs as the Apple kit), offline cache and pending queue, FCM registration, Reverb realtime over OkHttp WebSocket, fused location with mock detection and the geofence maths. |
| `app` | `android/app` | The application: `HubApplication`, `MainActivity` (single activity, deep links, push taps), theme (`ui/Theme.kt`), components, sign-in flow, security prompts, the phone shell (bottom bar + drawer), the tablet shells (rail at medium width, permanent sidebar at expanded width), `RouteScreen` + `FeatureRegistry`, the console bridge, and the feature screens under `ui/features/`. |
| Services | `app/.../push/HubMessagingService.kt`, `app/.../location/TrailService.kt` | FCM entry point (token → `push/subscribe-device`, message → notification → route) and the on-shift location trail as a foreground service. |
| Tests | `core/src/test`, `app/src/test` | Gates, home path, sidebar parity, contract shapes, money, dates, deep links, renewal timing (core); QR encoder, form kit, table cells, route registry, and Robolectric render tests of every route and of the three shells (app). |

## Build

Requires JDK 17 and an Android SDK (platform 35, build-tools 35). The
container this was written in had both, so unlike the Apple code the
Android code **compiles and its unit tests run here**; the GitHub Actions
workflow `.github/workflows/android.yml` repeats that on every pull request
touching `android/` and keeps an unsigned debug APK as an artifact.

```sh
cd android
echo "sdk.dir=$ANDROID_HOME" > local.properties    # git-ignored
./gradlew :core:testDebugUnitTest :app:testDebugUnitTest
./gradlew :app:assembleDebug                       # app/build/outputs/apk/debug/app-debug.apk
```

Configuration, in `local.properties`, `-P` properties or environment
variables (upper-cased), read by `app/build.gradle.kts`:

| Key | Purpose | Default |
|---|---|---|
| `hubUrl` | The Hub the app talks to | `https://hub.bethanyhouse.co.ke` |
| `reverbKey`, `reverbHost`, `reverbPort`, `reverbScheme` | Realtime (Reverb, Pusher protocol); realtime stays off without a key | `""`, hub host, 443, wss |
| `fcmProjectId`, `fcmAppId`, `fcmApiKey`, `fcmSenderId` | Firebase for push; initialised from these so no `google-services.json` is committed; push stays off without them | `""` |
| `mapsApiKey` | Google Maps for the trail map and outlet geofence; blank tiles without it | `""` |
| `releaseStoreFile`, `releaseStorePassword`, `releaseKeyAlias`, `releaseKeyPassword` | Release signing | unset → release build is unsigned |

Nothing secret is committed: `local.properties`, `*.jks`, `*.keystore` and
`google-services.json` are git-ignored. The release procedure is: owner
generates a keystore (`keytool -genkeypair -v -keystore hub-release.jks
-alias hub -keyalg RSA -keysize 4096 -validity 10000`), stores it outside
the repository, sets the four `release*` keys in `local.properties` (or CI
secrets), runs `./gradlew :app:bundleRelease`, and uploads the `.aab` to
Play Console with the same application id as the Expo shell
(`ke.co.bethanyhouse.hub`) so the listing carries over. Debug builds use
`ke.co.bethanyhouse.hub.debug` so both can be installed side by side.

## Server contract

Built against `docs/api/openapi.json` (Step 2); headers, status handling
and money rules in `docs/api/README.md`. `ApiClient` sends the bearer
token, `X-Device-Id`, the app user agent and `Accept: application/json`;
`401` ends the session, `423 pin_locked` suspends the request until the PIN
overlay succeeds, `403 step_up_required` suspends it until the step-up
dialog succeeds, `403 download_approval_required` surfaces the hold.
Deep links (`bethanyhub://…`, `https://hub.bethanyhouse.co.ke/admin/…` via
App Links, push `url`s) map console paths to routes in
`Route.fromConsolePath` so every one opens the native screen when there is
one. App Links verify against the `assetlinks.json` the backend serves
once `ANDROID_SHA256_FINGERPRINTS` is set (docs/DEPLOY.md).

## How a screen gets to native

`RouteScreen` asks `FeatureRegistry` for a native screen. If none is
registered for the route, `ConsoleBridge` shows the same console page in a
`WebView`, signed in as the person (token handed over through the
console's own storage key, `window.BethanyNative` set so the console hides
its install banner). That keeps every capability reachable while modules
are ported; it is not the destination, and the bridge is labelled
"Console page" in the corner. Each module registers its routes in
`Features.registerAll()`.

## Phone and tablet

- **Phone (compact width):** the console's bottom tab bar (Home, Messages,
  Me, Production, Orders + More) with a back stack; "More" opens the full
  sidebar as a drawer. Pull to refresh on every page; forms are bottom
  sheets; the 16 dp gutter and single-column layouts throughout.
- **Tablet, medium width (Samsung Tab portrait, multi-window):** a
  navigation rail with the same tabs, the full sidebar as a drawer, search
  in the rail.
- **Tablet, expanded width (Samsung Tab landscape, DeX):** a permanent
  260 dp sidebar beside the content; pages cap their content at 1100 dp
  and stat tiles reflow. The POS lays out catalogue and cart side by side
  at this width. Samsung-specific behaviour (S Pen hover, DeX window
  resizing) works through the standard Compose input and `resizeableActivity`.

## Push, realtime, location, offline

- **Push:** FCM token → `PushRegistration` → `POST /admin/push/subscribe-device`
  (`platform: android`). The server's `FcmSender` needs the service-account
  JSON in its env (docs/DEPLOY.md). A tap opens the route named by `url`.
  Channels: `default` (high) and `trail` (low, the on-shift notification).
- **Realtime:** `RealtimeClient` subscribes the person's user channel on
  sign-in; `notification.pushed` bumps the badge and toasts; modules
  subscribe their own channels (messages).
- **Location:** `LocationService.currentFix()` for clock-in with the
  geofence verdict; a mocked fix (`Location.isMock`) is refused as the
  console refuses a simulated one. On shift, when the attendance rule asks,
  `TrailService` runs as a foreground service with a visible notification
  and posts the trail batch; it stops itself at clock-out, on `401`, or
  when the server answers `tracking: false`.
- **Offline:** read cache per person; pending queue with dedupe keys for
  what the console already queues; replay on foreground and on reconnect,
  refusals toasted, the queue count shown in the top bar.

## Session rules mirrored on the client

Same as the Apple apps: idle clock per role (PIN lock or sign-out at the
server's moment), renewal at 75 % of remaining life and on foreground when
under a day remains (never past the absolute limit), biometric re-lock
after five minutes in the background (`BiometricPrompt`, device boundary
only), held downloads surfaced, step-up by TOTP or password. Passkey
step-up is not yet wired on Android (the server offers a fallback, which
the dialog uses); see the checklist.

## Verification status

| Check | Status |
|---|---|
| Kotlin sources written against the merged contract | DONE |
| `:core` compiled and unit tests green | DONE (in the authoring container) |
| `:app` compiled and unit tests green | DONE (in the authoring container) |
| `assembleDebug` APK | see PR CI (`Android` workflow) |
| Every route has a native screen except the documented bridged ones | DONE — `RegistryTest` |
| Every native route renders as a signed-in super admin against empty Hub responses, draws, and calls the Hub | DONE — `RouteRenderTest`, 121 routes, Robolectric (JVM Android runtime) |
| Phone bottom bar, tablet rail, tablet permanent sidebar render at their widths; deep link opens a detail with a back stack | DONE — `ShellRenderTest` |
| Every native route against a running backend with seeded records renders without an error state | DONE — `LiveRenderTest`, 108 routes, local Laravel + Postgres scratch database (2026-10-28); not in CI |
| Money on screen matches an independent computation from the seeded records | DONE for the dashboard and payment transactions (see "Live render check") |
| Run on a device or emulator | UNVERIFIED — the authoring container has no KVM, so no emulator |
| Signed release build, Play Console | NOT POSSIBLE here — owner's keystore and Play account |
| App Links verification | DONE server side; 404 until `ANDROID_SHA256_FINGERPRINTS` set |

## Clock-in kiosk

A manager with `attendance.manage` opens People › Attendance › Kiosks on
the tablet and chooses **Make this tablet a kiosk** (with step-up). The Hub
mints a kiosk-only token. The app keeps it in its own secure-store key
(`bh_kiosk_token`), signs the manager out and opens the board. The token
is never shown or shared.

- **Board:** the outlet's team, in or out, refreshed every 30 s, in large
  tiles for a 10–12" screen.
- **Punch:** tap your name, type your terminal PIN, and the front camera
  takes one 480 px JPEG. The server checks the PIN with its lock-out,
  applies the shift gate and keeps the photo.
- **Device:** the activity is pinned with screen pinning and the screen
  stays on. Android asks once to confirm the pin when the kiosk starts; a
  tablet enrolled as a managed (device-owner) device pins without asking.
- **Ending:** "Leave kiosk mode" removes the token on the Hub. A kiosk
  removed on the Hub, or past its lifetime (`KIOSK_TOKEN_DAYS`, default 90,
  from #519), returns the tablet to the sign-in screen with a note saying
  why.

## Live render check

`LiveRenderTest` renders every native route against a real backend instead
of the mock, and fails any screen that shows an error state. It is skipped
unless `-PhubE2eUrl` and `-PhubE2eToken` are given, so CI does not run it.

```sh
cd backend
export DB_CONNECTION=pgsql DB_DATABASE=bh_e2e DB_USERNAME=... DB_PASSWORD=...
php artisan migrate:fresh --force && php artisan permission:sync
php artisan db:seed --class=CountriesSeeder --force
php ../android/tools/live-hub-fixture.php       # refuses any database not ending in _e2e
php artisan serve --port=8077 &
cd ../android
./gradlew :app:testDebugUnitTest --tests '*LiveRenderTest*' \
  -PhubE2eUrl=http://127.0.0.1:8077 -PhubE2eToken="$(cat /tmp/hub-e2e-token.txt)"
```

The fixture seeds one outlet, three products priced in KES and USD with
stock in, low and out, a customer, a paid KES till sale (4,500), a
confirmed USD web order (120) with a USD 50 card payment, a pending KES
order (2,500), and a staff member with leave, a shift and a time entry.
On the 2026-10-28 run the screens showed, and an independent computation
from those records agrees:

| Figure | Screen | Independent computation |
|---|---|---|
| Today's sales KES 19,860 | Dashboard | 4,500 + 120 × 128 (reporting rate); the pending order excluded |
| Collected today KES 10,900 | Dashboard, Payment transactions | 4,500 + 50 × 128 |
| Outstanding KES 8,960 | (difference) | USD 70 owed × 128 |
| USD 120.00 total, USD 50 paid | Order detail | the order's own currency, unconverted |

The USD figures convert at 128 KES/USD (reporting), not 100 (pricing).

## Per-screen status (Android phone / tablet)

Codes: **N** native screen registered; **B** console bridge; **—** not
offered. "Verified" (run on a device) has not happened for any row.

Last updated: 2026-10-28 (Step 6).

| Screen | Phone | Tablet |
|---|---|---|
| Sign-in, 2FA, forced 2FA setup (QR), password reset | N | N |
| Session policy mirror (idle, PIN lock, renewal), held downloads | N | N |
| Step-up (password, TOTP) | N | N |
| Step-up by passkey | — (server fallback used) | — |
| Biometric re-lock | N | N |
| Navigation by permission, home path, search palette | N (bottom bar + drawer) | N (rail / permanent sidebar) |
| Deep links (bethanyhub://, App Links), push tap-to-route | N | N |
| Reverb realtime (user channel → badge, toast) | N | N |
| Offline read cache, pending queue, replay | N | N |
| Dashboard, Approvals, Notifications | N | N |
| Profile (sessions, devices, password) | N (2FA/passkey/PIN self-management: B) | same |
| Me: Today, updates, checklists, handbook, training, to sign, to-dos, colleagues, clock (geofence, trail service), shifts, leave, hours, payslips, advances | N | N |
| Messages (spaces, DMs, realtime, attachments, reactions, quick replies) | N (AI assist rail: B) | same |
| People: overview, requests, attendance (trail map, kiosks), roster, timesheets, leave, employee files, payroll, checklist templates | N | N |
| Catalog: products, product form (images, pricing via proposals, variants, SEO, stages, BOM), bulk import, categories | N | N |
| POS (register, sale, split tender, M-PESA/Paystack, receipt print/share, history, voids/returns via PIN, cashier EoD) | N | N (catalogue and cart side by side) |
| Tills (verify/finalize, reconcile, corrections, per currency), EoD reports and settings | N | N |
| Sales: order lists, pending queue, order detail, quotations, invoices, interest carts, shipments, customers, balances | N (handoff: B) | same |
| Production: my tasks (offline queue), orders, detail, WIP, QC, calendar, BOM | N | N |
| Inventory: stock, serials (camera scan), adjustments, transfers, materials, low stock | N | N |
| Procurement: POs (detail, receive, return, payments), suppliers, GRN, returns | N | N |
| Expenses (list, detail, approvals, receipt capture, bulk), imprest, categories and budgets, analytics | N | N |
| Finance: payment transactions (refund, void/reassign requests, export) | N | N |
| Reports (11 pages, tabs, drill-down, PDF, CSV, schedules, signals) | N (print: open PDF in the system viewer) | same |
| Setup: business, outlets (geofence map), attendance rules, automations, holidays, roles matrix, users and sign-in security, countries, currencies (pricing vs reporting rate), languages, taxes, discount limits, payment methods, shipping, stages, auto-assignees, activity logs, trash, database, downloads | N (database wipe deliberately omitted) | same |
| Marketing: seasons, campaigns, home front, product pages, insights | N | N |
| Clock-in kiosk (team board, terminal PIN, front-camera photo, screen pinning; set up from Attendance › Kiosks) | N (works, but meant for a tablet) | N |

## Findings surfaced by the Android port

The Apple findings F1–F7 (docs/NATIVE_APPS_APPLE.md) apply unchanged; the
Android screens take the same handling. Android-specific:

| # | Finding | Handling |
|---|---|---|
| A1 | Passkey step-up is not wired on Android (Credential Manager integration needs the Digital Asset Links passkey association and a device to test). | The step-up dialog uses the server's fallback (TOTP or password). |
| A2 | Android has no direct print for a remote PDF. | Report and document PDFs are saved and opened in the system viewer, which offers print and share; POS receipts print through `PrintManager`. |
| A3 | The serial scanner reads one code per camera session (ML Kit single result). | Reconcile reopens the scanner per code and keeps a typed-entry row. |
| A4 | Maps (trail map, outlet geofence) need `mapsApiKey`. | Without it tiles are blank and the screen says so; positions and radius still save. |
| A5 | `/auth/me` sends `first_name` and `last_name`, not `name`; the clients read `name`. Found by the live render check. Android showed a blank name everywhere; the Apple `Me` parser rejects the payload, which fails sign-in. | Fixed in both: display name built from first and last name as the console does, `name` kept as a fallback; a contract test now uses the real shape. |

## Deliberately not ported

Same as the Apple list, except kiosk mode, which is native on Android (see "Clock-in kiosk"):
`POST database/wipe`, the Comms AI assist rail and per-message translate,
the order handoff page, mention autocomplete, drag-and-drop reorder.
