#!/usr/bin/env bash
set -euo pipefail

MAX_BUNDLE_BYTES=536870912

die() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

bundles_root="${1:-}"
[[ -n "$bundles_root" ]] || die "bundles root argument is required"
[[ -d "$bundles_root" ]] || die "bundles root does not exist"
[[ ! -L "$bundles_root" ]] || die "bundles root must not be a symlink"
[[ -w "$bundles_root" ]] || die "bundles root is not writable"

original_command="${SSH_ORIGINAL_COMMAND:-}"

IFS=' ' read -r action filename expected_sha expected_size extra <<< "$original_command"

[[ "$action" == "publish" ]] || die "unsupported forced command"
[[ -n "${filename:-}" ]] || die "bundle filename is missing"
[[ -n "${expected_sha:-}" ]] || die "bundle sha256 is missing"
[[ -n "${expected_size:-}" ]] || die "bundle size is missing"
[[ -z "${extra:-}" ]] || die "unexpected forced command arguments"

[[ "$filename" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}\.(tar\.gz|tgz)$ ]] \
    || die "unsafe bundle filename"

[[ "$expected_sha" =~ ^[0-9a-fA-F]{64}$ ]] \
    || die "invalid bundle sha256"

[[ "$expected_size" =~ ^[0-9]+$ ]] \
    || die "invalid bundle size"

[[ "${#expected_size}" -le 9 ]] \
    || die "bundle size exceeds safety limit"

declared_size=$((10#$expected_size))

(( declared_size > 0 )) \
    || die "bundle size must be positive"

(( declared_size <= MAX_BUNDLE_BYTES )) \
    || die "bundle size exceeds 512 MiB safety limit"

umask 0027

tmp="$(mktemp "${bundles_root}/.incoming.${filename}.XXXXXX")"
cleanup() {
    rm -f -- "$tmp"
}
trap cleanup EXIT HUP INT TERM

head -c "$((declared_size + 1))" > "$tmp"

actual_size="$(stat -c '%s' "$tmp")"
[[ "$actual_size" == "$declared_size" ]] \
    || die "received bundle size does not match declaration"

actual_sha="$(sha256sum "$tmp" | awk '{print $1}')"
[[ "${actual_sha,,}" == "${expected_sha,,}" ]] \
    || die "received bundle sha256 does not match declaration"

chmod 0640 "$tmp"

target="${bundles_root}/${filename}"
mv -fT -- "$tmp" "$target"

trap - EXIT HUP INT TERM

printf 'PUBLISHED %s %s %s\n' \
    "$filename" \
    "${actual_sha,,}" \
    "$actual_size"
