// Bethany House Hub — Android (native apps programme, Steps 6–7).
//
//   :core  networking, session, permissions, navigation catalogue, offline,
//          push, realtime. No UI. Mirrors docs/api/openapi.json and the
//          Apple BethanyKit one to one.
//   :app   Jetpack Compose: design system, shells (phone bottom bar, tablet
//          navigation rail/drawer), sign-in, security prompts, feature screens.
pluginManagement {
    repositories {
        google()
        // Google's mirror of Maven Central first: it is not rate-limited the way
        // repo.maven.apache.org is from CI egress; Central itself stays as fallback.
        maven { url = uri("https://maven-central.storage-download.googleapis.com/maven2/") }
        mavenCentral()
        gradlePluginPortal()
    }
}

dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()
        // Google's mirror of Maven Central first: it is not rate-limited the way
        // repo.maven.apache.org is from CI egress; Central itself stays as fallback.
        maven { url = uri("https://maven-central.storage-download.googleapis.com/maven2/") }
        mavenCentral()
    }
}

rootProject.name = "BethanyHub"
include(":core", ":app")
