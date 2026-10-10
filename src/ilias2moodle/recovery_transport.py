from __future__ import annotations

import hashlib
import json
import re
import shutil
import subprocess
import tarfile
from pathlib import Path
from typing import Any

_MAX_BUNDLE_BYTES = 536_870_912
_BUNDLE_NAME_RE = re.compile(
    r"^[A-Za-z0-9][A-Za-z0-9._-]{0,127}\.(?:tar\.gz|tgz)$"
)
_USER_RE = re.compile(r"^[A-Za-z0-9._-]+$")
_HOST_RE = re.compile(r"^[A-Za-z0-9_.:\-]+$")
_PACKAGE_NAME_RE = re.compile(
    r"^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$"
)
_MAX_PLAN_BYTES = 2_097_152


def _validate_bundle_name(value: str) -> str:
    candidate = value.strip()
    if not _BUNDLE_NAME_RE.fullmatch(candidate):
        raise ValueError(
            "Nom de bundle invalide. Utiliser un nom simple en .tar.gz ou .tgz."
        )
    return candidate


def _validate_package_name(value: str) -> str:
    candidate = value.strip()
    if not _PACKAGE_NAME_RE.fullmatch(candidate):
        raise ValueError(
            "Nom de package recovery invalide."
        )
    return candidate


def _validate_source_tree(source: Path) -> None:
    if not source.is_dir():
        raise FileNotFoundError(
            f"Répertoire recovery introuvable : {source}"
        )

    for path in source.rglob("*"):
        if path.is_symlink():
            raise ValueError(
                f"Lien symbolique interdit dans le recovery : {path}"
            )
        if not path.is_dir() and not path.is_file():
            raise ValueError(
                f"Entrée recovery non régulière interdite : {path}"
            )


def build_recovery_bundle(
    source: Path,
    bundle: Path,
) -> dict[str, Any]:
    """Create a gzip tar bundle from one validated recovery directory."""

    source = source.resolve()
    bundle = bundle.expanduser().resolve()

    _validate_source_tree(source)
    _validate_bundle_name(bundle.name)

    if bundle == source or source in bundle.parents:
        raise ValueError(
            "Le bundle de sortie ne doit pas être créé dans le recovery source."
        )

    bundle.parent.mkdir(parents=True, exist_ok=True)

    if bundle.exists():
        if bundle.is_dir() or bundle.is_symlink():
            raise ValueError(
                f"Chemin bundle de sortie invalide : {bundle}"
            )
        bundle.unlink()

    with tarfile.open(bundle, mode="w:gz") as archive:
        archive.add(
            source,
            arcname=source.name,
            recursive=True,
        )

    size = bundle.stat().st_size
    if size <= 0 or size > _MAX_BUNDLE_BYTES:
        bundle.unlink(missing_ok=True)
        raise ValueError(
            "Bundle recovery vide ou supérieur à la limite de 512 MiB."
        )

    sha256 = hashlib.sha256()
    with bundle.open("rb") as handle:
        for chunk in iter(
            lambda: handle.read(1024 * 1024),
            b"",
        ):
            sha256.update(chunk)

    return {
        "bundle": str(bundle),
        "name": bundle.name,
        "size": size,
        "sha256": sha256.hexdigest(),
    }


def publish_recovery_bundle(
    bundle: Path,
    *,
    host: str,
    user: str,
    identity_file: Path,
    known_hosts_file: Path,
    port: int = 22,
    remote_name: str | None = None,
    ssh_executable: str | None = None,
) -> dict[str, Any]:
    """Push a recovery bundle through a forced-command SSH key.

    The remote public key must force tools/receive-recovery-bundle.sh.
    No remote path or shell command is supplied by the caller.
    """

    bundle = bundle.expanduser().resolve()
    if not bundle.is_file() or bundle.is_symlink():
        raise FileNotFoundError(
            f"Bundle recovery introuvable : {bundle}"
        )

    size = bundle.stat().st_size
    if size <= 0 or size > _MAX_BUNDLE_BYTES:
        raise ValueError(
            "Bundle recovery vide ou supérieur à la limite de 512 MiB."
        )

    remote_name = _validate_bundle_name(
        remote_name or bundle.name
    )

    host = host.strip()
    user = user.strip()

    if not _HOST_RE.fullmatch(host):
        raise ValueError("Hôte SSH invalide.")
    if not _USER_RE.fullmatch(user):
        raise ValueError("Utilisateur SSH invalide.")
    if port < 1 or port > 65535:
        raise ValueError("Port SSH invalide.")

    identity_file = identity_file.expanduser().resolve()
    known_hosts_file = known_hosts_file.expanduser().resolve()

    if not identity_file.is_file():
        raise FileNotFoundError(
            f"Clé privée SSH introuvable : {identity_file}"
        )
    if not known_hosts_file.is_file():
        raise FileNotFoundError(
            f"known_hosts SSH introuvable : {known_hosts_file}"
        )
    if known_hosts_file.stat().st_size <= 0:
        raise ValueError("known_hosts SSH est vide.")

    ssh = (
        ssh_executable
        or shutil.which("ssh")
    )
    if not ssh:
        raise FileNotFoundError(
            "Exécutable ssh introuvable dans PATH."
        )

    sha256 = hashlib.sha256()
    with bundle.open("rb") as handle:
        for chunk in iter(
            lambda: handle.read(1024 * 1024),
            b"",
        ):
            sha256.update(chunk)
    digest = sha256.hexdigest()

    original_command = (
        f"publish {remote_name} {digest} {size}"
    )

    command = [
        ssh,
        "-T",
        "-p",
        str(port),
        "-i",
        str(identity_file),
        "-o",
        "BatchMode=yes",
        "-o",
        "IdentitiesOnly=yes",
        "-o",
        "StrictHostKeyChecking=yes",
        "-o",
        f"UserKnownHostsFile={known_hosts_file}",
        f"{user}@{host}",
        original_command,
    ]

    with bundle.open("rb") as handle:
        completed = subprocess.run(
            command,
            stdin=handle,
            capture_output=True,
            check=False,
        )

    stdout = completed.stdout.decode(
        "utf-8",
        errors="replace",
    ).strip()
    stderr = completed.stderr.decode(
        "utf-8",
        errors="replace",
    ).strip()

    if completed.returncode != 0:
        raise RuntimeError(
            "Publication SSH du bundle en échec "
            f"(exit={completed.returncode}) : {stderr or stdout}"
        )

    return {
        "published": True,
        "bundle": str(bundle),
        "remote_name": remote_name,
        "size": size,
        "sha256": digest,
        "host": host,
        "user": user,
        "port": port,
        "receiver_stdout": stdout,
        "receiver_stderr": stderr,
        "command": command,
    }



def fetch_recovery_plan(
    *,
    package_name: str,
    output: Path,
    host: str,
    user: str,
    identity_file: Path,
    known_hosts_file: Path,
    port: int = 22,
    ssh_executable: str | None = None,
) -> dict[str, Any]:
    """Fetch one recovery-plan.json through the forced-command SSH key."""

    package_name = _validate_package_name(
        package_name
    )

    host = host.strip()
    user = user.strip()

    if not _HOST_RE.fullmatch(host):
        raise ValueError("Hôte SSH invalide.")
    if not _USER_RE.fullmatch(user):
        raise ValueError("Utilisateur SSH invalide.")
    if port < 1 or port > 65535:
        raise ValueError("Port SSH invalide.")

    identity_file = identity_file.expanduser().resolve()
    known_hosts_file = known_hosts_file.expanduser().resolve()

    if not identity_file.is_file():
        raise FileNotFoundError(
            f"Clé privée SSH introuvable : {identity_file}"
        )
    if not known_hosts_file.is_file():
        raise FileNotFoundError(
            f"known_hosts SSH introuvable : {known_hosts_file}"
        )
    if known_hosts_file.stat().st_size <= 0:
        raise ValueError("known_hosts SSH est vide.")

    ssh = ssh_executable or shutil.which("ssh")
    if not ssh:
        raise FileNotFoundError(
            "Exécutable ssh introuvable dans PATH."
        )

    command = [
        ssh,
        "-T",
        "-p",
        str(port),
        "-i",
        str(identity_file),
        "-o",
        "BatchMode=yes",
        "-o",
        "IdentitiesOnly=yes",
        "-o",
        "StrictHostKeyChecking=yes",
        "-o",
        f"UserKnownHostsFile={known_hosts_file}",
        f"{user}@{host}",
        f"fetch-plan {package_name}",
    ]

    completed = subprocess.run(
        command,
        capture_output=True,
        check=False,
    )

    stderr = completed.stderr.decode(
        "utf-8",
        errors="replace",
    ).strip()

    if completed.returncode != 0:
        stdout_text = completed.stdout.decode(
            "utf-8",
            errors="replace",
        ).strip()
        raise RuntimeError(
            "Récupération SSH du recovery-plan en échec "
            f"(exit={completed.returncode}) : "
            f"{stderr or stdout_text}"
        )

    header, separator, payload = completed.stdout.partition(
        b"\n"
    )
    if not separator:
        raise ValueError(
            "Réponse recovery-plan sans en-tête valide."
        )

    try:
        header_text = header.decode("ascii")
    except UnicodeDecodeError as exc:
        raise ValueError(
            "En-tête recovery-plan non ASCII."
        ) from exc

    parts = header_text.split(" ")
    if len(parts) != 4 or parts[0] != "PLAN":
        raise ValueError(
            "En-tête recovery-plan invalide."
        )

    received_package = parts[1]
    expected_sha = parts[2].lower()
    expected_size_text = parts[3]

    if received_package != package_name:
        raise ValueError(
            "Le serveur a retourné un package inattendu."
        )
    if not re.fullmatch(
        r"[0-9a-f]{64}",
        expected_sha,
    ):
        raise ValueError(
            "SHA-256 recovery-plan invalide."
        )
    if not expected_size_text.isdigit():
        raise ValueError(
            "Taille recovery-plan invalide."
        )

    expected_size = int(expected_size_text)
    if (
        expected_size <= 0
        or expected_size > _MAX_PLAN_BYTES
    ):
        raise ValueError(
            "Taille recovery-plan hors limite."
        )
    if len(payload) != expected_size:
        raise ValueError(
            "Taille recovery-plan reçue incorrecte."
        )

    digest = hashlib.sha256(payload).hexdigest()
    if digest != expected_sha:
        raise ValueError(
            "SHA-256 recovery-plan reçu incorrect."
        )

    try:
        decoded = payload.decode("utf-8")
        plan = json.loads(decoded)
    except (UnicodeDecodeError, ValueError) as exc:
        raise ValueError(
            "Recovery-plan reçu invalide."
        ) from exc

    if not isinstance(plan, dict):
        raise ValueError(
            "Recovery-plan reçu doit être un objet JSON."
        )
    if str(plan.get("schema_version", "")) != "1.0":
        raise ValueError(
            "Recovery-plan reçu utilise un schéma non supporté."
        )

    output = output.expanduser().resolve()
    output.parent.mkdir(
        parents=True,
        exist_ok=True,
    )

    tmp = output.with_name(
        f".{output.name}.tmp"
    )
    try:
        tmp.write_bytes(payload)
        tmp.replace(output)
    finally:
        tmp.unlink(missing_ok=True)

    return {
        "fetched": True,
        "package_name": package_name,
        "plan": str(output),
        "size": expected_size,
        "sha256": digest,
        "host": host,
        "user": user,
        "port": port,
        "receiver_stderr": stderr,
        "command": command,
    }



def list_pending_recovery_jobs(
    *,
    host: str,
    user: str,
    identity_file: Path,
    known_hosts_file: Path,
    port: int = 22,
    ssh_executable: str | None = None,
) -> dict[str, Any]:
    """List queued recovery jobs through the forced-command SSH key."""

    host = host.strip()
    user = user.strip()

    if not _HOST_RE.fullmatch(host):
        raise ValueError("Hôte SSH invalide.")
    if not _USER_RE.fullmatch(user):
        raise ValueError("Utilisateur SSH invalide.")
    if port < 1 or port > 65535:
        raise ValueError("Port SSH invalide.")

    identity_file = identity_file.expanduser().resolve()
    known_hosts_file = known_hosts_file.expanduser().resolve()

    if not identity_file.is_file():
        raise FileNotFoundError(
            f"Clé privée SSH introuvable : {identity_file}"
        )
    if not known_hosts_file.is_file():
        raise FileNotFoundError(
            f"known_hosts SSH introuvable : {known_hosts_file}"
        )
    if known_hosts_file.stat().st_size <= 0:
        raise ValueError("known_hosts SSH est vide.")

    ssh = ssh_executable or shutil.which("ssh")
    if not ssh:
        raise FileNotFoundError(
            "Exécutable ssh introuvable dans PATH."
        )

    command = [
        ssh,
        "-T",
        "-p",
        str(port),
        "-i",
        str(identity_file),
        "-o",
        "BatchMode=yes",
        "-o",
        "IdentitiesOnly=yes",
        "-o",
        "StrictHostKeyChecking=yes",
        "-o",
        f"UserKnownHostsFile={known_hosts_file}",
        f"{user}@{host}",
        "list-pending",
    ]

    completed = subprocess.run(
        command,
        capture_output=True,
        check=False,
    )

    stdout = completed.stdout.decode(
        "utf-8",
        errors="replace",
    ).strip()
    stderr = completed.stderr.decode(
        "utf-8",
        errors="replace",
    ).strip()

    if completed.returncode != 0:
        raise RuntimeError(
            "Liste SSH des recoveries en attente en échec "
            f"(exit={completed.returncode}) : "
            f"{stderr or stdout}"
        )

    try:
        data = json.loads(stdout)
    except json.JSONDecodeError as exc:
        raise ValueError(
            "Réponse list-pending invalide."
        ) from exc

    if not isinstance(data, dict):
        raise ValueError(
            "Réponse list-pending doit être un objet JSON."
        )
    if str(data.get("schema_version", "")) != "1.0":
        raise ValueError(
            "Schéma list-pending non supporté."
        )

    jobs = data.get("jobs")
    if not isinstance(jobs, list):
        raise ValueError(
            "Réponse list-pending jobs invalide."
        )

    validated: list[dict[str, Any]] = []

    for index, job in enumerate(jobs, start=1):
        if not isinstance(job, dict):
            raise ValueError(
                f"Job pending #{index} invalide."
            )

        package_name = _validate_package_name(
            str(job.get("package_name", ""))
        )
        bundle_name = _validate_bundle_name(
            str(job.get("bundle_name", ""))
        )
        plan_sha256 = str(
            job.get("plan_sha256", "")
        ).lower()

        if not re.fullmatch(
            r"[0-9a-f]{64}",
            plan_sha256,
        ):
            raise ValueError(
                f"SHA-256 pending #{index} invalide."
            )

        request_count = int(
            job.get("request_count", 0)
        )
        plan_size = int(
            job.get("plan_size", 0)
        )

        if request_count <= 0:
            raise ValueError(
                f"request_count pending #{index} invalide."
            )
        if (
            plan_size <= 0
            or plan_size > _MAX_PLAN_BYTES
        ):
            raise ValueError(
                f"plan_size pending #{index} invalide."
            )

        validated.append({
            "package_name": package_name,
            "bundle_name": bundle_name,
            "plan_sha256": plan_sha256,
            "plan_size": plan_size,
            "request_count": request_count,
        })

    data["jobs"] = validated
    data["job_count"] = len(validated)
    data["receiver_stderr"] = stderr
    data["command"] = command
    return data
