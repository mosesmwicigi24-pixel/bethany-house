# Bethany House Hub — Android

Native Android phone and Samsung tablet app for the Hub (native apps
programme, Steps 6–7). Kotlin, Jetpack Compose, Material 3. The Laravel
backend is the single source of truth; the app never computes money.

- `core/` — Android library: API client, session (Sanctum token, renewal,
  idle policy, PIN lock, step-up), permissions and navigation catalogue,
  offline cache and pending queue, push registration, Reverb realtime,
  location. Unit-tested (`./gradlew :core:testDebugUnitTest`).
- `app/` — the application: theme, components, sign-in, phone shell
  (bottom bar) and tablet shell (rail / permanent sidebar), the route
  registry and every ported module under `ui/features/`.

Build: `ANDROID_HOME=<sdk> ./gradlew :app:assembleDebug`. Configuration
(Hub URL, Reverb, Firebase, Maps key, release keystore) comes from
`local.properties` or environment variables; nothing secret is committed.
See `docs/NATIVE_APPS_ANDROID.md` for the full build, configuration and
release procedure, and the per-screen status.
