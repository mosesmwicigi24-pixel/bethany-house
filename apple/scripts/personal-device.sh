#!/bin/sh
# Opens the iPhone/iPad app ready to install on your own device with a free
# Apple ID. See project.personal.yml for what differs from the store build.
set -e
cd "$(dirname "$0")/.."
command -v xcodegen >/dev/null 2>&1 || brew install xcodegen
xcodegen generate --spec project.personal.yml
cat <<'MSG'

Opening BethanyHubPersonal.xcodeproj. In Xcode:
  1. Top bar, left half: choose the scheme "HubMobile" (not HubMac).
  2. Top bar, right half: choose your iPhone or iPad.
  3. HubMobile target > Signing & Capabilities > Team: your Personal Team.
  4. Press Run. First time only, on the device: Settings > General >
     VPN & Device Management > your Apple ID > Trust.
MSG
open BethanyHubPersonal.xcodeproj
