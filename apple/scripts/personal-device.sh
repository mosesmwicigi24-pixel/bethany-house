#!/bin/sh
# Opens the iPhone/iPad app ready to install on your own device with a free
# Apple ID. See project.personal.yml for what differs from the store build.
#   apple/scripts/personal-device.sh             generate both projects, open the personal one
#   apple/scripts/personal-device.sh --no-open   generate only (scripts/mac-sync.sh uses this)
#
# The signing team is taken from DEVELOPMENT_TEAM if set, otherwise from the
# "Apple Development" certificate in your keychain (created the first time
# you sign in to Xcode), so regenerating does not lose your Team choice.
set -e
cd "$(dirname "$0")/.."
command -v xcodegen >/dev/null 2>&1 || brew install xcodegen

if [ -z "${DEVELOPMENT_TEAM:-}" ]; then
    DEVELOPMENT_TEAM=$(security find-certificate -c "Apple Development" -p 2>/dev/null \
        | openssl x509 -noout -subject 2>/dev/null \
        | sed -n 's/.*OU *= *\([A-Z0-9]\{10\}\).*/\1/p' | head -n 1)
fi
export DEVELOPMENT_TEAM

xcodegen generate --quiet --spec project.yml
xcodegen generate --quiet --spec project.personal.yml

[ "${1:-}" = "--no-open" ] && exit 0

if [ -n "$DEVELOPMENT_TEAM" ]; then
    team="Team: already set to your Personal Team ($DEVELOPMENT_TEAM)."
else
    team="Team: choose your Personal Team (HubMobile target > Signing & Capabilities)."
fi
cat <<MSG

Opening BethanyHubPersonal.xcodeproj. In Xcode:
  1. Menu bar: Product > Scheme > HubMobile (not HubMac).
  2. Top bar: choose your iPhone or iPad.
  3. $team
  4. Press Run. First time only, on the device: Settings > General >
     VPN & Device Management > your Apple ID > Trust.
MSG
open BethanyHubPersonal.xcodeproj
