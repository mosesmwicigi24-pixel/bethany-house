# Bethany House Hub — store app

The Play Store / App Store app (People PR 8). An Expo (React Native) shell
around the Hub console at `https://hub.bethanyhouse.co.ke/admin/`. All data
and every screen come from the Hub; the app adds the phone's own location
service (with Android's fake-GPS detection), push through Expo, a stable
device id, and biometric unlock.

See `docs/STORE_APP.md` for the submission steps.

## Run on your phone (development)

```sh
cd mobile
npm install
npx expo start
```

Scan the QR code with Expo Go. Push tokens need a real device and an EAS
project id (`extra.eas.projectId` in `app.json`).

## Build for the stores

```sh
npm i -g eas-cli && eas login
eas init                        # writes the project id into app.json
eas build --platform android --profile production
eas build --platform ios --profile production
eas submit --platform android --profile production
eas submit --platform ios --profile production
```

## How the page and the app talk

`bridge.ts` here and `react-admin/src/lib/native.ts` in the console are the
two halves of one contract. The app injects `window.BethanyNative` before the
page loads; the page posts `{type: ...}` messages; the app answers with
`bh:native` events. Change both together.
