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
                                "source_id": "600",
                                "type": "wiki",
                                "title": "Wiki test",
                                "items": [],
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
    assert (output / "operator-fixture.json").is_file()
