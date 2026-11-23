#!/bin/sh
# Keeps a Mac's clone of this repository current with GitHub, so every change
# can be run and checked locally (Xcode, ChatGPT/Codex, or anything else that
# reads the folder), and regenerates the Xcode projects when apple/ changes.
#
#   scripts/mac-sync.sh                      sync once, following main
#   scripts/mac-sync.sh <branch>             sync once, following <branch>
#   scripts/mac-sync.sh --install [branch]   sync every 5 minutes (launchd)
#   scripts/mac-sync.sh --uninstall          stop syncing
#
# It only ever fast-forwards. If you have edited tracked files, or the branch
# cannot fast-forward, it changes nothing and says so in the log:
#   ~/Library/Logs/bethany-mac-sync.log
set -eu

REPO=$(cd "$(dirname "$0")/.." && pwd)
LABEL=ke.co.bethanyhouse.mac-sync
PLIST="$HOME/Library/LaunchAgents/$LABEL.plist"
LOG="$HOME/Library/Logs/bethany-mac-sync.log"
log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $*"; }

case "${1:-}" in
--install)
    branch=${2:-main}
    mkdir -p "$HOME/Library/LaunchAgents" "$HOME/Library/Logs"
    cat > "$PLIST" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key><string>$LABEL</string>
    <key>ProgramArguments</key>
    <array><string>$REPO/scripts/mac-sync.sh</string><string>$branch</string></array>
    <key>StartInterval</key><integer>300</integer>
    <key>RunAtLoad</key><true/>
    <key>StandardOutPath</key><string>$LOG</string>
    <key>StandardErrorPath</key><string>$LOG</string>
    <key>EnvironmentVariables</key>
    <dict><key>PATH</key><string>/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin</string></dict>
</dict>
</plist>
PLIST
    launchctl bootout "gui/$(id -u)" "$PLIST" 2>/dev/null || true
    launchctl bootstrap "gui/$(id -u)" "$PLIST"
    echo "Syncing $REPO with origin/$branch every 5 minutes. Log: $LOG"
    exit 0 ;;
--uninstall)
    launchctl bootout "gui/$(id -u)" "$PLIST" 2>/dev/null || true
    rm -f "$PLIST"
    echo "Stopped syncing."
    exit 0 ;;
esac

branch=${1:-main}
cd "$REPO"

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    log "skipped: tracked files have local edits (git status). Commit, stash or discard them to resume."
    exit 0
fi

git fetch --quiet origin "$branch"
before=$(git rev-parse HEAD)
if [ "$(git rev-parse --abbrev-ref HEAD)" != "$branch" ]; then
    if git show-ref --verify --quiet "refs/heads/$branch"; then
        git checkout --quiet "$branch"
    else
        git checkout --quiet -b "$branch" "origin/$branch"
    fi
fi

if ! git merge --ff-only --quiet "origin/$branch" 2>/dev/null; then
    log "skipped: local $branch has commits that are not on GitHub; it cannot fast-forward."
    exit 0
fi
after=$(git rev-parse HEAD)

if [ "$before" != "$after" ]; then
    log "updated $branch: $(git log -1 --format='%h %s')"
fi
if [ "$before" != "$after" ] || [ ! -d apple/BethanyHubPersonal.xcodeproj ]; then
    if git diff --quiet "$before" "$after" -- apple && [ -d apple/BethanyHubPersonal.xcodeproj ]; then
        :
    else
        apple/scripts/personal-device.sh --no-open && log "regenerated the Xcode projects"
    fi
fi
