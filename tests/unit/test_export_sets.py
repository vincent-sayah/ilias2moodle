from __future__ import annotations

import zipfile
from pathlib import Path

from ilias2moodle.ilias.export_sets import find_export_sets


def _write_aggregate_zip(path: Path) -> None:
    manifest = """<?xml version="1.0"?>
<Manifest MainEntity="crs" Title="Cours">
  <ExportSet Path="set_1/export__0__crs_100" Type="crs"/>
  <ExportSet Path="set_2/export__0__copa_200" Type="copa"/>
  <ExportSet Path="set_3/export__0__wiki_300" Type="wiki"/>
</Manifest>
"""
    with zipfile.ZipFile(path, "w") as archive:
        archive.writestr("/manifest.xml", manifest)


def _write_multiset_zip(path: Path) -> None:
    manifests = {
        "set_1/1700000000__0__crs_100/manifest.xml":
            '<Manifest MainEntity="crs" Title="Cours"/>',
        "set_2/1700000000__0__copa_200/manifest.xml":
            '<Manifest MainEntity="copa" Title="Page"/>',
        "set_3/1700000000__0__wiki_300/manifest.xml":
            '<Manifest MainEntity="wiki" Title="Wiki"/>',
        "set_4/1700000000__0__blog_400/manifest.xml":
            '<Manifest MainEntity="blog" Title="Blog"/>',
    }
    with zipfile.ZipFile(path, "w") as archive:
        for name, content in manifests.items():
            archive.writestr(name, content)


def test_find_export_sets_historical_aggregate_layout(tmp_path: Path) -> None:
    archive_path = tmp_path / "aggregate.zip"
    _write_aggregate_zip(archive_path)

    with zipfile.ZipFile(archive_path) as archive:
        assert find_export_sets(archive, "copa") == [
            {
                "object_id": "200",
                "path": "set_2/export__0__copa_200",
                "type": "copa",
            }
        ]
        assert find_export_sets(archive, "blog") == []


def test_find_export_sets_multiset_layout(tmp_path: Path) -> None:
    archive_path = tmp_path / "multiset.zip"
    _write_multiset_zip(archive_path)

    with zipfile.ZipFile(archive_path) as archive:
        assert find_export_sets(archive, "copa") == [
            {
                "object_id": "200",
                "path": "set_2/1700000000__0__copa_200",
                "type": "copa",
            }
        ]
        assert find_export_sets(archive, "wiki") == [
            {
                "object_id": "300",
                "path": "set_3/1700000000__0__wiki_300",
                "type": "wiki",
            }
        ]
        assert find_export_sets(archive, "blog") == [
            {
                "object_id": "400",
                "path": "set_4/1700000000__0__blog_400",
                "type": "blog",
            }
        ]
        assert find_export_sets(archive, "exc") == []
