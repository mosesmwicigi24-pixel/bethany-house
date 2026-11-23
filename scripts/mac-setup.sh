#!/bin/sh
# One-time setup of a Mac for testing this repository, end to end:
#   1. tools: Xcode command-line tools, Homebrew, XcodeGen, Codex (ChatGPT's coding agent)
#   2. keeps this folder current with GitHub every 5 minutes (scripts/mac-sync.sh)
#   3. generates the Xcode projects and opens the iPhone/iPad one
#   4. starts Codex in this folder; it reads AGENTS.md, the same rules as CLAUDE.md
#
#   scripts/mac-setup.sh            follow the branch you are on now
#   scripts/mac-setup.sh <branch>   follow <branch>
#
# Safe to run again. Two steps stay yours because macOS and ChatGPT require a
# person: signing in to ChatGPT (Codex opens the browser the first time) and,
# for the ChatGPT desktop app, allowing it to work with Xcode and Terminal.
set -eu
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd "$REPO"
branch=${1:-$(git rev-parse --abbrev-ref HEAD)}
step() { printf '\n==> %s\n' "$*"; }

step "Xcode command-line tools"
if ! xcode-select -p >/dev/null 2>&1; then
    xcode-select --install || true
    echo "Finish the installer window, then run this script again."; exit 1
fi

step "Homebrew"
if ! command -v brew >/dev/null 2>&1; then
    for b in /opt/homebrew/bin/brew /usr/local/bin/brew; do [ -x "$b" ] && eval "$("$b" shellenv)"; done
fi
command -v brew >/dev/null 2>&1 || { echo "Install Homebrew from https://brew.sh, then run this script again."; exit 1; }

step "XcodeGen"
command -v xcodegen >/dev/null 2>&1 || brew install xcodegen

step "Codex (ChatGPT's coding agent)"
if ! command -v codex >/dev/null 2>&1; then
    brew install --cask codex || { command -v npm >/dev/null 2>&1 && npm install -g @openai/codex; }
fi
command -v codex >/dev/null 2>&1 || echo "Codex did not install; everything else continues. See https://github.com/openai/codex"

step "Follow origin/$branch"
git fetch --quiet origin "$branch"
scripts/mac-sync.sh "$branch"
scripts/mac-sync.sh --install "$branch"

step "Xcode projects"
apple/scripts/personal-device.sh

cat <<MSG

Done. This folder now follows origin/$branch every 5 minutes
(log: ~/Library/Logs/bethany-mac-sync.log; stop with scripts/mac-sync.sh --uninstall).

Yours to do once:
  - ChatGPT desktop app: Settings > Work with Apps > allow Xcode and Terminal.
  - Ask Codex to check, not to edit: edits on this Mac pause the sync until
    they are committed or discarded.
MSG

if command -v codex >/dev/null 2>&1 && [ -t 0 ]; then
    step "Starting Codex in $REPO (first time: sign in with your ChatGPT account)"
    exec codex
fi
