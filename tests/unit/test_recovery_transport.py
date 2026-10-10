from __future__ import annotations

import hashlib
import json
import subprocess
import tarfile
from pathlib import Path

import pytest

from ilias2moodle.recovery_transport import (
    build_recovery_bundle,
    fetch_recovery_plan,
    list_pending_recovery_jobs,
    publish_recovery_bundle,
)


def test_build_recovery_bundle_preserves_recovery_root(
    tmp_path: Path,
) -> None:
    source = tmp_path / "course827_recovery"
    collection = source / "collection"
    collection.mkdir(parents=True)
    (collection / "manifest.json").write_text(
        '{"ok": true}\n',
        encoding="utf-8",
    )

    bundle = tmp_path / "course827_recovery.tar.gz"
    result = build_recovery_bundle(
        source,
        bundle,
    )

    assert result["bundle"] == str(bundle.resolve())
    assert result["name"] == bundle.name
    assert result["size"] > 0
    assert len(result["sha256"]) == 64

    with tarfile.open(bundle, "r:gz") as archive:
        names = archive.getnames()

    assert (
        "course827_recovery/collection/manifest.json"
        in names
    )


def test_build_recovery_bundle_rejects_symlink(
    tmp_path: Path,
) -> None:
    source = tmp_path / "recovery"
    source.mkdir()
    target = tmp_path / "outside.txt"
    target.write_text("outside", encoding="utf-8")
    (source / "link").symlink_to(target)

    with pytest.raises(
        ValueError,
        match="Lien symbolique interdit",
    ):
        build_recovery_bundle(
            source,
            tmp_path / "recovery.tar.gz",
        )


def test_publish_recovery_bundle_uses_strict_ssh(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    bundle = tmp_path / "course827_recovery.tar.gz"
    payload = b"recovery-payload"
    bundle.write_bytes(payload)

    identity = tmp_path / "id_ed25519"
    identity.write_text("private", encoding="utf-8")

    known_hosts = tmp_path / "known_hosts"
    known_hosts.write_text(
        "192.168.56.54 ssh-ed25519 AAAATEST\n",
        encoding="utf-8",
    )

    captured: dict[str, object] = {}

    def fake_run(
        command: list[str],
        *,
        stdin,
        capture_output: bool,
        check: bool,
    ) -> subprocess.CompletedProcess[bytes]:
        captured["command"] = command
        captured["payload"] = stdin.read()
        captured["capture_output"] = capture_output
        captured["check"] = check
        return subprocess.CompletedProcess(
            command,
            0,
            stdout=b"PUBLISHED ok\n",
            stderr=b"",
        )

    monkeypatch.setattr(
        "ilias2moodle.recovery_transport.subprocess.run",
        fake_run,
    )

    result = publish_recovery_bundle(
        bundle,
        host="192.168.56.54",
        user="ilias2moodlepush",
        identity_file=identity,
        known_hosts_file=known_hosts,
        ssh_executable="/usr/bin/ssh",
    )

    digest = hashlib.sha256(payload).hexdigest()
    command = captured["command"]

    assert isinstance(command, list)
    assert "BatchMode=yes" in command
    assert "IdentitiesOnly=yes" in command
    assert "StrictHostKeyChecking=yes" in command
    assert (
        f"UserKnownHostsFile={known_hosts.resolve()}"
        in command
    )
    assert command[-2] == (
        "ilias2moodlepush@192.168.56.54"
    )
    assert command[-1] == (
        "publish course827_recovery.tar.gz "
        f"{digest} {len(payload)}"
    )
    assert captured["payload"] == payload
    assert result["published"] is True
    assert result["sha256"] == digest


@pytest.mark.parametrize(
    "name",
    [
        "../recovery.tar.gz",
        "/tmp/recovery.tar.gz",
        "recovery.zip",
        "recovery name.tar.gz",
    ],
)
def test_publish_recovery_bundle_rejects_unsafe_name(
    tmp_path: Path,
    name: str,
) -> None:
    bundle = tmp_path / "valid.tar.gz"
    bundle.write_bytes(b"payload")
    identity = tmp_path / "id_ed25519"
    identity.write_text("private", encoding="utf-8")
    known_hosts = tmp_path / "known_hosts"
    known_hosts.write_text("host key\n", encoding="utf-8")

    with pytest.raises(ValueError, match="Nom de bundle invalide"):
        publish_recovery_bundle(
            bundle,
            host="moodle.local",
            user="ilias2moodlepush",
            identity_file=identity,
            known_hosts_file=known_hosts,
            remote_name=name,
            ssh_executable="/usr/bin/ssh",
        )



def test_fetch_recovery_plan_validates_and_writes_atomically(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    identity = tmp_path / "id_ed25519"
    identity.write_text("private", encoding="utf-8")
    known_hosts = tmp_path / "known_hosts"
    known_hosts.write_text(
        "192.168.56.54 ssh-ed25519 AAAATEST\n",
        encoding="utf-8",
    )

    payload = (
        b'{"schema_version":"1.0","recovery_required":true,'
        b'"request_count":0,"requests":[]}'
    )
    digest = hashlib.sha256(payload).hexdigest()
    response = (
        f"PLAN course827_v18 {digest} {len(payload)}\n"
    ).encode("ascii") + payload

    captured: dict[str, object] = {}

    def fake_run(
        command: list[str],
        *,
        capture_output: bool,
        check: bool,
    ) -> subprocess.CompletedProcess[bytes]:
        captured["command"] = command
        captured["capture_output"] = capture_output
        captured["check"] = check
        return subprocess.CompletedProcess(
            command,
            0,
            stdout=response,
            stderr=b"",
        )

    monkeypatch.setattr(
        "ilias2moodle.recovery_transport.subprocess.run",
        fake_run,
    )

    output = tmp_path / "plan.json"
    result = fetch_recovery_plan(
        package_name="course827_v18",
        output=output,
        host="192.168.56.54",
        user="ilias2moodlepush",
        identity_file=identity,
        known_hosts_file=known_hosts,
        ssh_executable="/usr/bin/ssh",
    )

    assert output.read_bytes() == payload
    assert result["fetched"] is True
    assert result["sha256"] == digest
    assert captured["command"][-1] == (
        "fetch-plan course827_v18"
    )
    assert "StrictHostKeyChecking=yes" in captured["command"]


def test_fetch_recovery_plan_rejects_bad_digest(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    identity = tmp_path / "id_ed25519"
    identity.write_text("private", encoding="utf-8")
    known_hosts = tmp_path / "known_hosts"
    known_hosts.write_text("host key\n", encoding="utf-8")

    payload = b'{"schema_version":"1.0","requests":[]}'
    response = (
        f"PLAN course827_v18 {'0' * 64} {len(payload)}\n"
    ).encode("ascii") + payload

    def fake_run(
        command: list[str],
        *,
        capture_output: bool,
        check: bool,
    ) -> subprocess.CompletedProcess[bytes]:
        return subprocess.CompletedProcess(
            command,
            0,
            stdout=response,
            stderr=b"",
        )

    monkeypatch.setattr(
        "ilias2moodle.recovery_transport.subprocess.run",
        fake_run,
    )

    with pytest.raises(
        ValueError,
        match="SHA-256 recovery-plan reçu incorrect",
    ):
        fetch_recovery_plan(
            package_name="course827_v18",
            output=tmp_path / "plan.json",
            host="moodle.local",
            user="ilias2moodlepush",
            identity_file=identity,
            known_hosts_file=known_hosts,
            ssh_executable="/usr/bin/ssh",
        )



def test_list_pending_recovery_jobs_validates_response(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    identity = tmp_path / "id_ed25519"
    identity.write_text("private", encoding="utf-8")
    known_hosts = tmp_path / "known_hosts"
    known_hosts.write_text("host key\n", encoding="utf-8")

    digest = "a" * 64
    response = json.dumps({
        "schema_version": "1.0",
        "job_count": 1,
        "jobs": [
            {
                "package_name": "course827_worker",
                "bundle_name": "course827_worker_recovery.tar.gz",
                "plan_sha256": digest,
                "plan_size": 1234,
                "request_count": 2,
            }
        ],
        "skipped_count": 0,
        "skipped": [],
    }).encode("utf-8")

    def fake_run(
        command: list[str],
        *,
        capture_output: bool,
        check: bool,
    ) -> subprocess.CompletedProcess[bytes]:
        assert command[-1] == "list-pending"
        assert capture_output is True
        assert check is False
        return subprocess.CompletedProcess(
            command,
            0,
            stdout=response,
            stderr=b"",
        )

    monkeypatch.setattr(
        "ilias2moodle.recovery_transport.subprocess.run",
        fake_run,
    )

    result = list_pending_recovery_jobs(
        host="moodle.local",
        user="ilias2moodlepush",
        identity_file=identity,
        known_hosts_file=known_hosts,
        ssh_executable="/usr/bin/ssh",
    )

    assert result["job_count"] == 1
    assert result["jobs"][0]["package_name"] == (
        "course827_worker"
    )
    assert result["jobs"][0]["plan_sha256"] == digest
