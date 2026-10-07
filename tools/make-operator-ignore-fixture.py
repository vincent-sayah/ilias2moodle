#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import shutil
from pathlib import Path
from typing import Any


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description=(
            "Create a disposable operator-console fixture from an existing "
            "ILIAS2Moodle package. The fixture gets a new course source_id "
            "and one Wiki is deliberately made unsupported so the console "
            "can validate WAITING_DECISION -> Ignore -> COMPLETED_WITH_SKIPS."
        )
    )
    parser.add_argument(
        "--source-package",
        required=True,
        type=Path,
        help="Existing prepared package directory containing migration.json.",
    )
    parser.add_argument(
        "--output",
        required=True,
        type=Path,
        help="Destination directory for the disposable fixture.",
    )
    parser.add_argument(
        "--source-course-id",
        required=True,
        help="New synthetic source course ref_id, e.g. 9282.",
    )
    parser.add_argument(
        "--title-suffix",
        default=" [TEST IGNORE WIKI]",
        help="Suffix appended to the course title.",
    )
    return parser


def _walk_items(items: list[Any]):
    for item in items:
        if not isinstance(item, dict):
            continue
        yield item
        children = item.get("items", [])
        if isinstance(children, list):
            yield from _walk_items(children)



def _detach_wiki_from_item_groups(
    items: list[Any],
    wiki_ref: str,
) -> list[dict[str, Any]]:
    detached: list[dict[str, Any]] = []

    for item in _walk_items(items):
        if str(item.get("type", "")).strip() != "itgr":
            continue

        metadata = item.get("metadata")
        if not isinstance(metadata, dict):
            continue

        refs = metadata.get("item_group_member_ref_ids", [])
        objids = metadata.get("item_group_member_obj_ids", [])

        if not isinstance(refs, list) or not isinstance(objids, list):
            continue

        normalized_refs = [str(value) for value in refs]
        if wiki_ref not in normalized_refs:
            continue

        if len(refs) != len(objids):
            raise ValueError(
                "Item Group member refs/obj ids are inconsistent before "
                f"fixture isolation for ref_id {item.get('source_id')}"
            )

        kept_refs: list[Any] = []
        kept_objids: list[Any] = []
        removed: list[dict[str, str]] = []

        for member_ref, member_obj_id in zip(
            refs,
            objids,
            strict=True,
        ):
            if str(member_ref) == wiki_ref:
                removed.append(
                    {
                        "source_ref_id": str(member_ref),
                        "source_obj_id": str(member_obj_id),
                    }
                )
                continue

            kept_refs.append(member_ref)
            kept_objids.append(member_obj_id)

        if not kept_refs:
            raise ValueError(
                "Fixture isolation would leave Item Group "
                f"{item.get('source_id')} with no members"
            )

        metadata["item_group_member_ref_ids"] = kept_refs
        metadata["item_group_member_obj_ids"] = kept_objids
        metadata["item_group_member_count"] = len(kept_refs)
        metadata["operator_fixture"] = {
            "purpose": "detach_ignored_wiki_from_item_group",
            "wiki_ref_id": wiki_ref,
            "removed_members": removed,
        }

        detached.append(
            {
                "item_group_ref_id": str(item.get("source_id", "")),
                "title": str(item.get("title", "")),
                "removed_members": removed,
                "remaining_member_ref_ids": [
                    str(value) for value in kept_refs
                ],
            }
        )

    return detached


def _load_json(path: Path) -> dict[str, Any]:
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError(f"{path} must contain a JSON object")
    return data


def _write_json(path: Path, data: dict[str, Any]) -> None:
    path.write_text(
        json.dumps(data, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )


def create_fixture(
    source_package: Path,
    output: Path,
    source_course_id: str,
    title_suffix: str,
) -> dict[str, Any]:
    source_package = source_package.resolve()
    output = output.resolve()
    migration_path = source_package / "migration.json"

    if output == source_package or output.is_relative_to(source_package):
        raise ValueError(
            "output must be outside the source package directory"
        )

    if not migration_path.is_file():
        raise FileNotFoundError(
            f"migration.json not found in source package: {source_package}"
        )

    if output.exists():
        raise FileExistsError(
            f"output already exists; remove it explicitly before reuse: {output}"
        )

    document = _load_json(migration_path)
    course = document.get("course")
    if not isinstance(course, dict):
        raise ValueError("migration.json has no valid course object")

    old_source_id = str(course.get("source_id", "")).strip()
    if not old_source_id:
        raise ValueError("migration.json course.source_id is empty")

    new_source_id = str(source_course_id).strip()
    if (
        not new_source_id.isdigit()
        or int(new_source_id) <= 0
        or new_source_id == old_source_id
    ):
        raise ValueError(
            "synthetic source course id must be a positive numeric id "
            "different from the original source_id"
        )

    title = str(course.get("title", "")).strip()
    items = course.get("items", [])
    if not isinstance(items, list):
        raise ValueError("migration.json course.items must be a list")

    wiki_refs = [
        str(item.get("source_id", "")).strip()
        for item in _walk_items(items)
        if str(item.get("type", "")).strip() == "wiki"
        and str(item.get("source_id", "")).strip()
    ]
    if not wiki_refs:
        raise ValueError("source package contains no Wiki object")

    wiki_ref = wiki_refs[0]
    source_wiki = source_package / "wikis" / wiki_ref / "structure.json"
    if not source_wiki.is_file():
        raise FileNotFoundError(
            f"Wiki structure.json not found for ref_id {wiki_ref}: {source_wiki}"
        )

    shutil.copytree(source_package, output)

    fixture_migration = output / "migration.json"
    document = _load_json(fixture_migration)
    course = document["course"]
    course["source_id"] = new_source_id
    course["title"] = f"{title}{title_suffix}"

    fixture_items = course.get("items", [])
    if not isinstance(fixture_items, list):
        raise ValueError("fixture migration course.items must be a list")

    detached_item_groups = _detach_wiki_from_item_groups(
        fixture_items,
        wiki_ref,
    )

    metadata = course.get("metadata")
    if isinstance(metadata, dict):
        metadata["operator_fixture"] = {
            "purpose": "ignore_wiki_validation",
            "original_source_course_id": old_source_id,
            "synthetic_source_course_id": new_source_id,
            "wiki_ref_id": wiki_ref,
            "detached_item_groups": detached_item_groups,
        }

    _write_json(fixture_migration, document)

    package_path = output / "package.json"
    if package_path.is_file():
        package = _load_json(package_path)
        package_course = package.get("course")
        if isinstance(package_course, dict):
            package_course["source_id"] = new_source_id
            package_course["title"] = course["title"]
        package["operator_fixture"] = {
            "purpose": "ignore_wiki_validation",
            "original_source_course_id": old_source_id,
            "synthetic_source_course_id": new_source_id,
            "wiki_ref_id": wiki_ref,
            "detached_item_groups": detached_item_groups,
        }
        _write_json(package_path, package)

    fixture_wiki = output / "wikis" / wiki_ref / "structure.json"
    wiki = _load_json(fixture_wiki)
    original_schema = str(wiki.get("schema_version", ""))
    wiki["schema_version"] = "999-operator-ignore-test"
    wiki["operator_fixture"] = {
        "purpose": "force_WIKI_SCHEMA_UNSUPPORTED",
        "original_schema_version": original_schema,
    }
    _write_json(fixture_wiki, wiki)

    summary = {
        "source_package": str(source_package),
        "output": str(output.resolve()),
        "original_source_course_id": old_source_id,
        "synthetic_source_course_id": new_source_id,
        "title": course["title"],
        "wiki_ref_id": wiki_ref,
        "detached_item_groups": detached_item_groups,
        "wiki_structure": str(fixture_wiki.resolve()),
        "expected_failure": "WIKI_SCHEMA_UNSUPPORTED",
        "migration_json": str(fixture_migration.resolve()),
    }

    _write_json(output / "operator-fixture.json", summary)
    return summary


def main() -> int:
    args = _parser().parse_args()
    summary = create_fixture(
        args.source_package,
        args.output,
        args.source_course_id,
        args.title_suffix,
    )
    print(json.dumps(summary, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
