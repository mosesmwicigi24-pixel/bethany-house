# Tailors Hub — the workshop app

Owner decision, 2026-11-23: tailors get their own app, not the whole Hub.
**Tailors Hub** is built from the same code as **Bethany Hub** on iPhone/iPad
and Android; the only difference is which screens it may show.

## Who can use it

Workshop staff: anyone with `production.worker`, `production.view`,
`production.submit_qc` or `production.approve_qc` (tailors, QC inspectors,
production managers). Anyone else who signs in is told "Tailors Hub is for
the workshop" and offered Sign out. The owner (super admin) can open it to test.

## What it shows

Owner, 2026-11-23 (scope review of commit 4eb0ecfd): Tailors Hub is the
workshop app **for everyone who uses it**, production managers included. A
person's wider Hub permissions never turn it into the full Hub.

| Area | Screens |
|---|---|
| Workshop | My Tasks, Production Orders (+ an order, assignments), Work In Progress, Quality Control, Calendar |
| Own workday | Clock in, My shifts, My leave, My hours, My payslips, My advances, Updates, Training, Checklists, Handbook, To sign, To-dos, Colleagues |
| Staff | Messages |
| Account | My profile, Sign out |

Each screen still needs its own permission (a tailor without QC rights sees
no Quality Control). Home: a tailor lands on My Tasks, a manager on
Production Orders, an inspector on Quality Control.

Not in Tailors Hub: anything that sells, buys, counts stock or reports
money; the Bill of Materials; search; the notifications page; the Today
summary; and **People administration, employee files and team payroll**
(Overview, Requests, Attendance, Checklist management, Roster, Timesheets,
team Leave, Employee files, Payroll). Managers use Bethany Hub for those,
whatever their permissions.
A link, push tap or button that leads to one of those shows "This page is
in Bethany Hub", never the website version.

**Hiding is not the control.** The server checks every permission on every
request whatever the app shows; Tailors Hub only narrows what is offered.

## Where the rule lives (one list, both platforms)

- Apple: `apple/Sources/BethanyKit/AppFlavor.swift` (`TailorsHub.allows`),
  tests `apple/Tests/BethanyKitTests/AppFlavorTests.swift`.
- Android: `android/core/.../core/AppFlavor.kt`, tests `AppFlavorTest.kt`.

Menus, phone tabs, search and the route guard (`RouteView` / `RouteScreen`)
all read it. Change both files and both tests together.

## Builds

| | Bethany Hub | Tailors Hub |
|---|---|---|
| iPhone/iPad | scheme `HubMobile`, `ke.co.bethanyhouse.hub` | scheme `TailorsMobile`, `ke.co.bethanyhouse.tailors` |
| Free Apple ID (`apple/scripts/personal-device.sh`) | `…hub.test` | `…tailors.test` |
| Android | flavour `hub`, `ke.co.bethanyhouse.hub` | flavour `tailors`, `ke.co.bethanyhouse.tailors` |
| Link scheme | `bethanyhub://`, Hub web links | `tailorshub://` only |

Android: `./gradlew :app:assembleTailorsDebug` →
`app/build/outputs/apk/tailors/debug/app-tailors-debug.apk`. APK publishing
in CI stays paused until the owner says "publish the APK".

## Not yet

- **Push notifications.** The server sends to one app identity
  (`APNS_BUNDLE_ID`; the FCM credentials belong to the Hub's app id) and
  drops other apps' devices, so Tailors Hub does not register for push.
  Needs a server change (a topic per app on each device subscription).
- **Its own icon.** It uses the Hub's launcher icon on Android and the
  default icon on Apple until there is Tailors Hub artwork.
- **Store listings.** Separate App Store / Play Store records would be needed
  to publish it; nothing is submitted.
