#!/usr/bin/env bash
# Run one shard of the test suite.
#
#   scripts/test-shard.sh <shard> <total>      e.g. scripts/test-shard.sh 2 3
#
# Every test file under tests/ is listed in a fixed order and dealt round-robin
# to the shards, so the split is deterministic (the same file always lands in
# the same shard for a given <total>) and every file belongs to exactly one
# shard. The files are handed to `php artisan test`, which keeps phpunit.xml's
# environment and its exclusion of the `scale` group.
#
# CI runs the shards as a matrix (.github/workflows/deploy.yml); the whole
# suite on one runner took ~18 minutes, each shard a third of that. Locally
# `php artisan test` is still the plain way to run everything.
set -euo pipefail

shard="${1:?shard index (1-based)}"
total="${2:?total shards}"
if ! [[ "$shard" =~ ^[0-9]+$ && "$total" =~ ^[0-9]+$ ]] || (( shard < 1 || shard > total )); then
    echo "usage: $0 <shard 1..total> <total>" >&2
    exit 2
fi

cd "$(dirname "$0")/.."

mapfile -t files < <(find tests -type f -name '*Test.php' | LC_ALL=C sort)
mine=()
for i in "${!files[@]}"; do
    if (( i % total == shard - 1 )); then
        mine+=("${files[$i]}")
    fi
done

echo "Shard ${shard}/${total}: ${#mine[@]} of ${#files[@]} test files"
if (( ${#mine[@]} == 0 )); then
    echo "nothing to run"
    exit 0
fi

exec php artisan test "${mine[@]}"
