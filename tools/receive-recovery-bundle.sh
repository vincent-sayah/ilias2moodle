#!/usr/bin/env bash
set -euo pipefail

MAX_BUNDLE_BYTES=536870912
MAX_PLAN_BYTES=2097152

die() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

bundles_root="${1:-}"
packages_root="${2:-}"

[[ -n "$bundles_root" ]] || die "bundles root argument is required"
[[ -d "$bundles_root" ]] || die "bundles root does not exist"
[[ ! -L "$bundles_root" ]] || die "bundles root must not be a symlink"
[[ -w "$bundles_root" ]] || die "bundles root is not writable"

if [[ -n "$packages_root" ]]; then
    [[ -d "$packages_root" ]] || die "packages root does not exist"
    [[ ! -L "$packages_root" ]] || die "packages root must not be a symlink"
fi

original_command="${SSH_ORIGINAL_COMMAND:-}"
IFS=' ' read -r action arg1 arg2 arg3 extra <<< "$original_command"

if [[ "$action" == "publish" ]]; then
    filename="${arg1:-}"
    expected_sha="${arg2:-}"
    expected_size="${arg3:-}"

    [[ -n "$filename" ]] || die "bundle filename is missing"
    [[ -n "$expected_sha" ]] || die "bundle sha256 is missing"
    [[ -n "$expected_size" ]] || die "bundle size is missing"
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

    exit 0
fi

if [[ "$action" == "list-pending" ]]; then
    [[ -n "$packages_root" ]] \
        || die "packages root is required for list-pending"
    [[ -z "${arg1:-}" && -z "${arg2:-}" && -z "${arg3:-}" && -z "${extra:-}" ]] \
        || die "unexpected list-pending arguments"

    script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    requests_root="$(dirname "$bundles_root")/requests"

    [[ -d "$requests_root" ]] \
        || die "recovery requests root does not exist"
    [[ ! -L "$requests_root" ]] \
        || die "recovery requests root must not be a symlink"

    python_bin="$(command -v python3 || true)"
    [[ -n "$python_bin" ]] \
        || die "python3 is required for list-pending"

    exec "$python_bin" \
        "${script_dir}/list-recovery-queue.py" \
        --packages-root "$packages_root" \
        --bundles-root "$bundles_root" \
        --requests-root "$requests_root"
fi

if [[ "$action" == "fetch-plan" ]]; then
    package_name="${arg1:-}"

    [[ -n "$packages_root" ]] \
        || die "packages root is required for fetch-plan"
    [[ -n "$package_name" ]] \
        || die "package name is missing"
    [[ -z "${arg2:-}" && -z "${arg3:-}" && -z "${extra:-}" ]] \
        || die "unexpected fetch-plan arguments"

    [[ "$package_name" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ ]] \
        || die "unsafe package name"

    packages_real="$(realpath "$packages_root")"
    package_dir="${packages_real}/${package_name}"

    [[ -d "$package_dir" ]] \
        || die "package does not exist"
    [[ ! -L "$package_dir" ]] \
        || die "package directory must not be a symlink"

    package_real="$(realpath "$package_dir")"
    [[ "$package_real" == "$packages_real/"* ]] \
        || die "package escaped packages root"

    plan="${package_real}/recovery-plan.json"

    [[ -f "$plan" ]] \
        || die "recovery-plan.json does not exist"
    [[ ! -L "$plan" ]] \
        || die "recovery-plan.json must not be a symlink"
    [[ -r "$plan" ]] \
        || die "recovery-plan.json is not readable"

    size="$(stat -c '%s' "$plan")"

    (( size > 0 )) \
        || die "recovery-plan.json is empty"
    (( size <= MAX_PLAN_BYTES )) \
        || die "recovery-plan.json exceeds 2 MiB safety limit"

    sha="$(sha256sum "$plan" | awk '{print $1}')"

    printf 'PLAN %s %s %s\n' \
        "$package_name" \
        "$sha" \
        "$size"

    cat "$plan"
    exit 0
fi

die "unsupported forced command"
