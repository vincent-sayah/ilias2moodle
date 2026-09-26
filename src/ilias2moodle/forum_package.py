from __future__ import annotations

import json
import shutil
import zipfile
from collections.abc import Iterable
from pathlib import Path, PurePosixPath
from typing import Any

from ilias2moodle.ilias.forum import parse_forums
from ilias2moodle.model import MigrationDocument, MigrationItem
from ilias2moodle.recovery_assets import load_forum_attachment_recovery


def _walk(
    items: Iterable[MigrationItem],
) -> Iterable[MigrationItem]:
    for item in items:
        yield item
        yield from _walk(item.items)


def _safe_archive_relative(path: str) -> PurePosixPath:
    candidate = PurePosixPath(path.lstrip("/"))
    if not candidate.parts or ".." in candidate.parts:
        raise ValueError(
            f"Chemin d'archive non sûr : {path}"
        )
    return candidate


def enrich_document_forums(
    document: MigrationDocument,
    archive_path: str | Path,
) -> dict[str, int]:
    forums = parse_forums(str(archive_path))
    by_object_id = {
        str(forum.get("source", {}).get("object_id", "")): forum
        for forum in forums
        if str(forum.get("source", {}).get("object_id", ""))
    }

    matched = 0
    missing = 0

    for item in _walk(document.course.items):
        if str(item.metadata.get("ilias_type", "")) != "frm":
            continue

        item.type = "forum"
        object_id = str(item.metadata.get("obj_id", ""))
        structure = by_object_id.get(object_id)

        if structure is None:
            item.metadata["forum_parse_error"] = (
                "Aucune structure Forum trouvée "
                f"pour obj_id={object_id}"
            )
            missing += 1
            continue

        structure["source"]["ref_id"] = item.source_id
        item.title = (
            str(structure.get("title", ""))
            or item.title
        )
        item.description = str(
            structure.get("description", "")
        )

        item.metadata.update(
            {
                "forum_schema_version": str(
                    structure.get("schema_version", "1.0")
                ),
                "forum_export_base": str(
                    structure.get("source", {}).get(
                        "export_base", ""
                    )
                ),
                "forum_thread_count": int(
                    structure.get("thread_count", 0)
                ),
                "forum_post_count": int(
                    structure.get("post_count", 0)
                ),
                "forum_attachment_count": int(
                    structure.get("attachment_count", 0)
                ),
                "forum_media_object_count": int(
                    structure.get("media_object_count", 0)
                ),
                "forum_source_author_count": len(
                    structure.get(
                        "source_author_ids",
                        [],
                    )
                ),
                "forum_phase7_contributions_deferred": True,
                "forum_structure": structure,
            }
        )
        matched += 1

    return {
        "detected": len(forums),
        "matched": matched,
        "missing": missing,
    }


def extract_forum_assets(
    document: MigrationDocument,
    archive_path: str | Path,
    output_dir: Path,
    attachment_recovery: str | Path | None = None,
) -> dict[str, Any]:
    output_dir.mkdir(parents=True, exist_ok=True)

    managed_root = output_dir / "forums"
    if managed_root.exists():
        shutil.rmtree(managed_root)

    stats = {
        "forum_structures": 0,
        "forum_attachment_files": 0,
        "forum_attachment_files_recovered": 0,
        "forum_media_files": 0,
    }
    missing: list[dict[str, str]] = []

    with zipfile.ZipFile(str(archive_path)) as archive:
        members = {
            name.lstrip("/"): name
            for name in archive.namelist()
        }

        def copy_member(
            source_path: str,
            destination_relative: PurePosixPath,
        ) -> bool:
            canonical = _safe_archive_relative(
                source_path
            ).as_posix()

            actual_name = members.get(canonical)
            if actual_name is None:
                return False

            destination = output_dir.joinpath(
                *destination_relative.parts
            )
            destination.parent.mkdir(
                parents=True,
                exist_ok=True,
            )

            with archive.open(actual_name) as src:
                with destination.open("wb") as dst:
                    shutil.copyfileobj(src, dst)
            return True

        for item in _walk(document.course.items):
            if item.type != "forum":
                continue

            structure = item.metadata.get(
                "forum_structure"
            )
            if not isinstance(structure, dict):
                missing.append(
                    {
                        "source_id": item.source_id,
                        "kind": "forum_structure",
                        "source_path": str(
                            item.metadata.get(
                                "forum_export_base",
                                "",
                            )
                        ),
                    }
                )
                continue

            forum_root = PurePosixPath(
                "forums",
                item.source_id,
            )

            for thread in structure.get("threads", []):
                if not isinstance(thread, dict):
                    continue

                for post in thread.get("posts", []):
                    if not isinstance(post, dict):
                        continue

                    post_id = str(
                        post.get("source_id", "")
                    ) or "unknown"

                    for index, attachment in enumerate(
                        post.get("attachments", []),
                        start=1,
                    ):
                        if not isinstance(
                            attachment,
                            dict,
                        ):
                            continue

                        source_path = str(
                            attachment.get(
                                "archive_path",
                                "",
                            )
                        )
                        if not source_path:
                            continue

                        filename = (
                            Path(
                                str(
                                    attachment.get(
                                        "filename",
                                        "",
                                    )
                                )
                                or source_path
                            ).name
                            or f"attachment-{index}"
                        )

                        destination = PurePosixPath(
                            *forum_root.parts,
                            "attachments",
                            post_id,
                            filename,
                        )

                        copied = copy_member(
                            source_path,
                            destination,
                        )
                        recovered = False
                        recovery_error = None

                        if not copied and attachment_recovery is not None:
                            forum_obj_id = str(
                                structure.get("source", {}).get(
                                    "object_id",
                                    "",
                                )
                            )
                            recovery, recovery_error = (
                                load_forum_attachment_recovery(
                                    attachment_recovery,
                                    forum_obj_id,
                                    post_id,
                                    filename,
                                )
                            )
                            if recovery is not None:
                                destination_path = output_dir.joinpath(
                                    *destination.parts
                                )
                                destination_path.parent.mkdir(
                                    parents=True,
                                    exist_ok=True,
                                )
                                shutil.copy2(
                                    recovery["path"],
                                    destination_path,
                                )
                                copied = True
                                recovered = True

                        if copied:
                            attachment[
                                "migration_path"
                            ] = destination.as_posix()
                            if recovered:
                                attachment["recovery_status"] = "RECOVERED"
                                stats[
                                    "forum_attachment_files_recovered"
                                ] += 1
                            stats[
                                "forum_attachment_files"
                            ] += 1
                        else:
                            missing.append(
                                {
                                    "source_id":
                                        item.source_id,
                                    "post_id": post_id,
                                    "kind": recovery_error
                                        or "forum_attachment",
                                    "source_path":
                                        source_path,
                                }
                            )

                    for index, media in enumerate(
                        post.get("media_objects", []),
                        start=1,
                    ):
                        if not isinstance(media, dict):
                            continue

                        source_path = str(
                            media.get(
                                "archive_path",
                                "",
                            )
                        )
                        if not source_path:
                            continue

                        filename = (
                            Path(
                                str(
                                    media.get(
                                        "filename",
                                        "",
                                    )
                                )
                                or source_path
                            ).name
                            or f"media-{index}"
                        )

                        destination = PurePosixPath(
                            *forum_root.parts,
                            "media",
                            post_id,
                            filename,
                        )

                        if copy_member(
                            source_path,
                            destination,
                        ):
                            media[
                                "migration_path"
                            ] = destination.as_posix()
                            stats[
                                "forum_media_files"
                            ] += 1
                        else:
                            missing.append(
                                {
                                    "source_id":
                                        item.source_id,
                                    "post_id": post_id,
                                    "kind":
                                        "forum_media",
                                    "source_path":
                                        source_path,
                                }
                            )

            structure_path = PurePosixPath(
                *forum_root.parts,
                "structure.json",
            )
            destination = output_dir.joinpath(
                *structure_path.parts
            )
            destination.parent.mkdir(
                parents=True,
                exist_ok=True,
            )
            destination.write_text(
                json.dumps(
                    structure,
                    ensure_ascii=False,
                    indent=2,
                ),
                encoding="utf-8",
            )

            item.metadata[
                "migration_structure_path"
            ] = structure_path.as_posix()

            item.metadata[
                "migration_forum_attachment_file_count"
            ] = sum(
                1
                for thread in structure.get(
                    "threads",
                    [],
                )
                if isinstance(thread, dict)
                for post in thread.get(
                    "posts",
                    [],
                )
                if isinstance(post, dict)
                for attachment in post.get(
                    "attachments",
                    [],
                )
                if isinstance(attachment, dict)
                and attachment.get("migration_path")
            )

            item.metadata[
                "migration_forum_media_file_count"
            ] = sum(
                1
                for thread in structure.get(
                    "threads",
                    [],
                )
                if isinstance(thread, dict)
                for post in thread.get(
                    "posts",
                    [],
                )
                if isinstance(post, dict)
                for media in post.get(
                    "media_objects",
                    [],
                )
                if isinstance(media, dict)
                and media.get("migration_path")
            )

            item.metadata.pop("forum_structure", None)
            stats["forum_structures"] += 1

    return {
        "managed_directory": "forums",
        "extracted": stats,
        "missing": missing,
    }
