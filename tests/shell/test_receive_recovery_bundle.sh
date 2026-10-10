#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
RECEIVER="${ROOT}/tools/receive-recovery-bundle.sh"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

bundles="${tmp}/bundles"
packages="${tmp}/packages"
requests="${tmp}/requests"
mkdir -p "$bundles" "$packages" "$requests"

grep -q 'command -v python3.11' "$RECEIVER"
grep -q 'sys.version_info >= (3, 11)' "$RECEIVER"

payload="${tmp}/payload.tar.gz"
printf 'recovery-payload\n' > "$payload"

sha="$(sha256sum "$payload" | awk '{print $1}')"
size="$(stat -c '%s' "$payload")"

SSH_ORIGINAL_COMMAND="publish course827.tar.gz ${sha} ${size}" \
    bash "$RECEIVER" "$bundles" "$packages" < "$payload" \
    > "${tmp}/success.out"

cmp -s "$payload" "${bundles}/course827.tar.gz"
grep -q '^PUBLISHED course827.tar.gz ' "${tmp}/success.out"

if SSH_ORIGINAL_COMMAND="publish ../escape.tar.gz ${sha} ${size}" \
    bash "$RECEIVER" "$bundles" "$packages" < "$payload" \
    > /dev/null 2>&1; then
    echo "Receiver accepted an unsafe filename" >&2
    exit 1
fi

badsha="$(printf '0%.0s' {1..64})"

if SSH_ORIGINAL_COMMAND="publish bad.tar.gz ${badsha} ${size}" \
    bash "$RECEIVER" "$bundles" "$packages" < "$payload" \
    > /dev/null 2>&1; then
    echo "Receiver accepted an invalid checksum" >&2
    exit 1
fi

if [[ -e "${bundles}/bad.tar.gz" ]]; then
    echo "Receiver kept a failed bundle" >&2
    exit 1
fi

mkdir -p "${packages}/course827_v18"
plan="${packages}/course827_v18/recovery-plan.json"
printf '%s' '{"schema_version":"1.0","requests":[]}' > "$plan"

plan_sha="$(sha256sum "$plan" | awk '{print $1}')"
plan_size="$(stat -c '%s' "$plan")"

SSH_ORIGINAL_COMMAND="fetch-plan course827_v18" \
    bash "$RECEIVER" "$bundles" "$packages" \
    > "${tmp}/plan.out"

expected_header="PLAN course827_v18 ${plan_sha} ${plan_size}"
actual_header="$(head -n 1 "${tmp}/plan.out")"
[[ "$actual_header" == "$expected_header" ]]

tail -n +2 "${tmp}/plan.out" > "${tmp}/plan.payload"
cmp -s "$plan" "${tmp}/plan.payload"

if SSH_ORIGINAL_COMMAND="fetch-plan ../escape" \
    bash "$RECEIVER" "$bundles" "$packages" \
    > /dev/null 2>&1; then
    echo "Receiver accepted an unsafe package name" >&2
    exit 1
fi

worker_package="course827_worker"
mkdir -p "${packages}/${worker_package}"

worker_plan="${packages}/${worker_package}/recovery-plan.json"
printf '%s' '{"schema_version":"1.0","recovery_required":true,"request_count":1,"requests":[{"type":"exercise_irss_collection"}],"unresolved_count":0,"unresolved":[]}' > "$worker_plan"

worker_sha="$(sha256sum "$worker_plan" | awk '{print $1}')"

cat > "${requests}/${worker_package}.json" <<EOF
{"schema_version":"1.0","package_name":"${worker_package}","zip_name":"course.zip","plan_sha256":"${worker_sha}","request_count":1}
EOF

SSH_ORIGINAL_COMMAND="list-pending" \
    bash "$RECEIVER" "$bundles" "$packages" \
    > "${tmp}/pending.json"

python3 - "${tmp}/pending.json" <<'PY'
import json
import sys

data = json.load(open(sys.argv[1], encoding="utf-8"))
assert data["job_count"] == 1
job = data["jobs"][0]
assert job["package_name"] == "course827_worker"
assert job["bundle_name"] == "course827_worker_recovery.tar.gz"
assert job["request_count"] == 1
PY

touch "${bundles}/${worker_package}_recovery.tar.gz"

SSH_ORIGINAL_COMMAND="list-pending" \
    bash "$RECEIVER" "$bundles" "$packages" \
    > "${tmp}/pending-after-bundle.json"

python3 - "${tmp}/pending-after-bundle.json" <<'PY'
import json
import sys

data = json.load(open(sys.argv[1], encoding="utf-8"))
assert data["job_count"] == 0
assert any(
    item.get("reason") == "bundle_already_present"
    for item in data["skipped"]
)
PY

echo "RECOVERY_RECEIVER_OK"
