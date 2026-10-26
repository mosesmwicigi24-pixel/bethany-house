# Store app — Play Store and App Store submission

People PR 8. The app lives in `mobile/` (Expo). It is a native shell around
the Hub console: the console is the product, the app is the phone's wrapper.
Nothing in the app stores or computes business data.

## What the app adds over the installed web app

| | Web app (PWA) | Store app |
|---|---|---|
| Clock-in location | Browser geolocation | Phone location service; Android reports when a fake-GPS app supplied the fix (`mock_location` flag on the punch) |
| Push | Web Push (iOS needs the home-screen install) | Expo push, works on both platforms |
| Named device | Random key in browser storage; lost on a cache clear | Id kept in the phone's secure store per install |
| Unlock | Password / passkey step-up | Face ID / fingerprint after 5 min in the background, then the Hub's own step-up as before |
| Updates | Instant, from the Hub | Instant, from the Hub (the shell rarely changes) |

Location is read only while the clock screen is open. Background location is
blocked in the Android manifest and not requested on iOS.

## Accounts you need (owner)

1. **Google Play Console** developer account (one-off fee). Create the app
   "Bethany House Hub", package `ke.co.bethanyhouse.hub`.
2. **Apple Developer Program** (yearly). App Store Connect app, bundle id
   `ke.co.bethanyhouse.hub`.
3. **Expo account** (free) for EAS Build; `eas init` writes the project id
   into `mobile/app.json`.

## Steps

```sh
cd mobile && npm install
npm i -g eas-cli && eas login && eas init
# Android: upload a Play service-account JSON as mobile/google-play-service-account.json
# iOS: fill appleId / ascAppId / appleTeamId in eas.json
eas build --platform all --profile production
eas submit --platform android --profile production   # internal track first
eas submit --platform ios --profile production       # then TestFlight
```

Then in the stores:

- **Privacy policy URL**: `https://hub.bethanyhouse.co.ke/admin/privacy`
  (shipped with this PR, public, no login).
- **Data safety (Play) / App privacy (Apple)**: location (precise, used for
  app functionality, not shared, collected only in use); name, email,
  phone, work records (app functionality, account-linked). No ads, no
  tracking, no third-party analytics.
- **Permission justification**: foreground location only, "to confirm the
  staff member is at the shop when clocking in". No background location.
- **Review notes**: a staff-only app; give the reviewer a test login on a
  user whose outlet geofence covers the reviewer's address, or set that
  outlet's radius wide for the review window.
- **Listing**: screenshots from `react-admin` phone pages (clock, shifts,
  leave, payslips); short description "Clock in, shifts, leave and payslips
  for Bethany House staff".

## Versioning

`eas.json` has `appVersionSource: remote` with `autoIncrement` on the
production profile, so build numbers increase on their own. Bump
`expo.version` in `app.json` for a user-visible version.

## Verifying a build

1. Install the internal-track / TestFlight build.
2. Sign in; the device appears under Setup › Devices with the app's user
   agent `BethanyHub/<version>`.
3. Open Clock in; the fix arrives from the phone's location service. With a
   fake-GPS app on (Android), the punch is flagged `mock location` in
   Attendance.
4. Approve a leave request from the Hub for that user; the phone gets a
   push.
5. Background the app for five minutes and return: biometric prompt.

## Not in this PR

- Offline screens of their own. The console's own offline handling (kept
  punch, pending badge) applies inside the app.
- App Store / Play Store accounts and the first submission: those are the
  owner's to open; the package is ready.
