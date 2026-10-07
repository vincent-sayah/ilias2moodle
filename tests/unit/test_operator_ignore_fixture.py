from __future__ import annotations

import json
import subprocess
import sys
from pathlib import Path


def _write_json(path: Path, data: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(data, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )


def test_operator_ignore_fixture_isolated_wiki_failure(
    tmp_path: Path,
) -> None:
    source = tmp_path / "source"
    output = tmp_path / "fixture"

    _write_json(
        source / "migration.json",
        {
            "schema_version": "1.0",
            "source": {
                "lms": "ILIAS",
                "version": "10.8",
            },
            "course": {
                "source_id": "282",
                "title": "Cours test",
                "metadata": {"obj_id": "827"},
                "items": [
                    {
                        "source_id": "500",
                        "type": "folder",
                        "title": "Activités",
                        "items": [
                            {
                                "source_id": "700",
                                "type": "itgr",
                                "title": "Informations",
                                "metadata": {
                                    "item_group_member_ref_ids": [
                                        "601",
                                        "600",
                                    ],
                                    "item_group_member_obj_ids": [
                                        "901",
                                        "900",
                                    ],
                                    "item_group_member_count": 2,
                                },
                                "items": [
                                    {
                                        "source_id": "601",
                                        "type": "content_page",
                                        "title": "Informations",
                                        "metadata": {"obj_id": "901"},
                                        "items": [],
                                    },
                                    {
                                        "source_id": "600",
                                        "type": "wiki",
                                        "title": "Wiki test",
                                        "metadata": {"obj_id": "900"},
                                        "items": [],
                                    },
                                ],
                            }
                        ],
                    }
                ],
            },
        },
    )
    _write_json(
        source / "package.json",
        {
            "course": {
                "source_id": "282",
                "title": "Cours test",
            }
        },
    )
    _write_json(
        source / "wikis" / "600" / "structure.json",
        {
            "schema_version": "1.0",
            "source": {
                "lms": "ILIAS",
                "ref_id": "600",
            },
            "history": {
                "migration_policy": "current_pages_only",
                "source_export_contains_history": False,
                "authors_migrated": False,
            },
            "unsupported_components": [],
            "pages": [
                {
                    "source_id": "1",
                    "title": "Accueil",
                    "content": {
                        "status": "ok",
                        "unsupported_components": [],
                        "blocks": [],
                    },
                }
            ],
            "start_page": {
                "source_id": "1",
                "title": "Accueil",
            },
        },
    )

    script = (
        Path(__file__).resolve().parents[2]
        / "tools"
        / "make-operator-ignore-fixture.py"
    )
    result = subprocess.run(
        [
            sys.executable,
            str(script),
            "--source-package",
            str(source),
            "--output",
            str(output),
            "--source-course-id",
            "9282",
        ],
        check=True,
        capture_output=True,
        text=True,
    )

    summary = json.loads(result.stdout)
    migration = json.loads(
        (output / "migration.json").read_text(encoding="utf-8")
    )
    wiki = json.loads(
        (
            output
            / "wikis"
            / "600"
            / "structure.json"
        ).read_text(encoding="utf-8")
    )
    source_wiki = json.loads(
        (
            source
            / "wikis"
            / "600"
            / "structure.json"
        ).read_text(encoding="utf-8")
    )

    assert migration["course"]["source_id"] == "9282"
    assert migration["course"]["title"].endswith(
        " [TEST IGNORE WIKI]"
    )
    assert (
        wiki["schema_version"]
        == "999-operator-ignore-test"
    )
    assert source_wiki["schema_version"] == "1.0"
    assert summary["wiki_ref_id"] == "600"
    assert summary["expected_failure"] == "WIKI_SCHEMA_UNSUPPORTED"
    assert summary["detached_item_groups"] == [
        {
            "item_group_ref_id": "700",
            "title": "Informations",
            "removed_members": [
                {
                    "source_ref_id": "600",
                    "source_obj_id": "900",
                }
            ],
            "remaining_member_ref_ids": ["601"],
        }
    ]

    item_group = migration["course"]["items"][0]["items"][0]
    metadata = item_group["metadata"]
    assert metadata["item_group_member_ref_ids"] == ["601"]
    assert metadata["item_group_member_obj_ids"] == ["901"]
    assert metadata["item_group_member_count"] == 1

    children = item_group["items"]
    assert any(
        child.get("source_id") == "600"
        and child.get("type") == "wiki"
        for child in children
    )

    assert (output / "operator-fixture.json").is_file()
