// swift-tools-version: 5.9
//
// Bethany House Hub — shared Apple code (native apps programme, Step 3).
//
//   BethanyKit  networking, session, permissions, navigation catalogue,
//               offline store, push and realtime. No UI. Mirrors the
//               backend contract in docs/api/openapi.json.
//   BethanyUI   design system and the feature screens, shared by the macOS,
//               iPhone and iPad apps. Each app target (Apps/) composes these
//               with its own navigation shell.
//
// The app targets are generated from project.yml with XcodeGen:
//   brew install xcodegen && cd apple && xcodegen generate && open BethanyHub.xcodeproj
//
import PackageDescription

let package = Package(
    name: "BethanyHub",
    defaultLocalization: "en",
    platforms: [
        .macOS(.v14),
        .iOS(.v17),
    ],
    products: [
        .library(name: "BethanyKit", targets: ["BethanyKit"]),
        .library(name: "BethanyUI", targets: ["BethanyUI"]),
    ],
    targets: [
        .target(
            name: "BethanyKit",
            path: "Sources/BethanyKit",
            swiftSettings: [.enableUpcomingFeature("StrictConcurrency")]
        ),
        .target(
            name: "BethanyUI",
            dependencies: ["BethanyKit"],
            path: "Sources/BethanyUI",
            resources: [.process("Resources")]
        ),
        .testTarget(
            name: "BethanyKitTests",
            dependencies: ["BethanyKit"],
            path: "Tests/BethanyKitTests"
        ),
    ]
)
