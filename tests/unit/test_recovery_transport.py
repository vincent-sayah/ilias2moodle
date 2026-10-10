from __future__ import annotations

import hashlib
import subprocess
import tarfile
from pathlib import Path

import pytest

from ilias2moodle.recovery_transport import (
    build_recovery_bundle,
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
        stdout,
        stderr,
        check: bool,
    ) -> subprocess.CompletedProcess[bytes]:
        captured["command"] = command
        captured["payload"] = stdin.read()
        captured["stdout"] = stdout
        captured["stderr"] = stderr
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
