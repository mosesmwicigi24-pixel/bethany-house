# Apple apps — macOS, iPhone, iPad (native apps programme, Steps 3–5)

Code: `apple/`. One Swift package, two app targets.

| Part | Path | What it is |
|---|---|---|
| `BethanyKit` | `apple/Sources/BethanyKit` | Networking (`APIClient`), session (`SessionStore`: sign-in, 2FA, renewal, PIN lock, step-up), Keychain, device identity, permissions and the navigation catalogue (`NavCatalogue`, ported from the console sidebar), offline store and pending queue, APNs registration, Reverb realtime, passkey step-up. No UI. |
| `BethanyUI` | `apple/Sources/BethanyUI` | Design system (`Theme`), shared components, sign-in flow, security prompts, the macOS shell (`MacShell`), command palette, `RouteView` + `FeatureRegistry`, and the feature screens under `Features/`. |
| `HubMac` | `apple/Apps/HubMac` | macOS 14+ app target. |
| `HubMobile` | `apple/Apps/HubMobile` | iOS/iPadOS 17+ universal app target (Steps 4–5). |
| Tests | `apple/Tests/BethanyKitTests` | Gates, home path, sidebar parity, contract shapes, money, dates, deep links, renewal timing. |

## Build

Requires a Mac with Xcode 15.4 or newer. Nothing in `apple/` can be compiled
in the Linux container this programme was written in; see "Verification
status" below before trusting any claim about a build.

```sh
brew install xcodegen
cd apple
swift test                          # BethanyKit unit tests (macOS host)
xcodegen generate                   # writes BethanyHub.xcodeproj (git-ignored)
open BethanyHub.xcodeproj           # select HubMac or HubMobile, set your team, run
```

Signing: set `DEVELOPMENT_TEAM` in Xcode or export it before `xcodegen`.
Bundle id `ke.co.bethanyhouse.hub` is the same as the Expo shell's so the
existing App Store Connect record carries over.

## Server contract

The apps are built against `docs/api/openapi.json` (Step 2). Headers, status
handling and money rules are in `docs/api/README.md`; the Swift mirror is
`APIClient`, `APIError`, `Money`. Deep links map console paths to routes in
`Route.from(consolePath:)` so push `url`s, universal links and pasted Hub
links all open the native screen.

Environment overrides for a staging build: `HUB_URL`, `REVERB_KEY`,
`REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` in the scheme's environment.

## How a screen gets to native

`RouteView` asks `FeatureRegistry` for a native screen. If none is
registered for the route, `ConsoleBridgeView` shows the same console page
signed in as the person. That keeps every capability reachable from day one
while modules are ported; it is not the destination. Each ported module
registers its routes in `Features.registerAll()` and ticks its rows in
`docs/NATIVE_APPS_CHECKLIST.md`.

## Push

APNs token → `PushRegistration` → `POST /admin/push/subscribe-device`
(`platform: macos|ios|ipados`). The server's `ApnsSender` needs the `.p8`
key in its env (docs/DEPLOY.md). A tap opens `PushPayload.route`.

## Session rules mirrored on the client

- Idle clock per role (`SessionPolicy` from `/me`): a clerk sees the PIN
  lock, others the sign-out notice, at the same moment the server would.
- Token renewal: `POST /admin/auth/renew` at 75% of remaining life and on
  foreground when under a day remains; never past the absolute limit.
- Biometric re-lock after five minutes in the background (device boundary only).
- Offline: read cache per person; queued writes only for what the console
  already queues (punch, task progress, to-dos, course progress, form drafts).

## Verification status

| Check | Status |
|---|---|
| Swift sources written against the merged contract | DONE |
| Compiled on macOS / iOS | UNVERIFIED — no Xcode in the authoring environment |
| `swift test` | UNVERIFIED — same |
| Signed build, TestFlight | NOT POSSIBLE here — owner's Mac and Apple Developer account |
