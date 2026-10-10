#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
RECEIVER="${ROOT}/tools/receive-recovery-bundle.sh"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

payload="${tmp}/payload.tar.gz"
printf 'recovery-payload\n' > "$payload"

sha="$(sha256sum "$payload" | awk '{print $1}')"
size="$(stat -c '%s' "$payload")"

SSH_ORIGINAL_COMMAND="publish course827.tar.gz ${sha} ${size}" \
    bash "$RECEIVER" "$tmp" < "$payload" \
    > "${tmp}/success.out"

cmp -s "$payload" "${tmp}/course827.tar.gz"
grep -q '^PUBLISHED course827.tar.gz ' "${tmp}/success.out"

if SSH_ORIGINAL_COMMAND="publish ../escape.tar.gz ${sha} ${size}" \
    bash "$RECEIVER" "$tmp" < "$payload" \
    > /dev/null 2>&1; then
    echo "Receiver accepted an unsafe filename" >&2
    exit 1
fi

badsha="$(printf '0%.0s' {1..64})"

if SSH_ORIGINAL_COMMAND="publish bad.tar.gz ${badsha} ${size}" \
    bash "$RECEIVER" "$tmp" < "$payload" \
    > /dev/null 2>&1; then
    echo "Receiver accepted an invalid checksum" >&2
    exit 1
fi

if [[ -e "${tmp}/bad.tar.gz" ]]; then
    echo "Receiver kept a failed bundle" >&2
    exit 1
fi

echo "RECOVERY_RECEIVER_OK"
