from __future__ import annotations

import html
import json
from collections import Counter
from pathlib import Path
from typing import Any, Iterable

from ilias2moodle.model import MigrationDocument, MigrationItem

SUPPORTED_PHASE1_TYPES = {
    "folder",
    "file",
    "url",
    "page",
    "scorm",
    "learning_module",
    "test",
    "question_pool",
}


def _walk(items: Iterable[MigrationItem]) -> Iterable[MigrationItem]:
    for item in items:
        yield item
        yield from _walk(item.items)


def build_report(document: MigrationDocument) -> dict[str, Any]:
    all_items = list(_walk(document.course.items))
    counts = Counter(item.type for item in all_items)
    unsupported = [
        {"source_id": item.source_id, "type": item.type, "title": item.title}
        for item in all_items
        if item.type not in SUPPORTED_PHASE1_TYPES
    ]
    return {
        "schema_version": document.schema_version,
        "course": {
            "source_id": document.course.source_id,
            "title": document.course.title,
        },
        "total_items": len(all_items),
        "counts_by_type": dict(sorted(counts.items())),
        "unsupported_count": len(unsupported),
        "unsupported": unsupported,
    }


def write_reports(document: MigrationDocument, output_dir: Path) -> dict[str, Any]:
    output_dir.mkdir(parents=True, exist_ok=True)
    report = build_report(document)

    migration_path = output_dir / "migration.json"
    migration_path.write_text(
        json.dumps(document.to_dict(), ensure_ascii=False, indent=2), encoding="utf-8"
    )

    report_json_path = output_dir / "report.json"
    report_json_path.write_text(
        json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8"
    )

    rows = "".join(
        f"<tr><td>{html.escape(item_type)}</td><td>{count}</td></tr>"
        for item_type, count in report["counts_by_type"].items()
    )
    unsupported_rows = "".join(
        "<li>"
        f"{html.escape(item['type'])} — {html.escape(item['title'])} "
        f"({html.escape(item['source_id'])})"
        "</li>"
        for item in report["unsupported"]
    ) or "<li>Aucun</li>"

    report_html = f"""<!doctype html>
<html lang=\"fr\">
<head>
<meta charset=\"utf-8\">
<title>Rapport ILIAS2Moodle</title>
<style>
body {{ font-family: system-ui, sans-serif; max-width: 960px; margin: 40px auto; padding: 0 20px; }}
table {{ border-collapse: collapse; width: 100%; }}
th, td {{ border: 1px solid #ccc; padding: 8px; text-align: left; }}
</style>
</head>
<body>
<h1>Rapport ILIAS2Moodle</h1>
<p><strong>Cours :</strong> {html.escape(report['course']['title'])}</p>
<p><strong>Source ID :</strong> {html.escape(report['course']['source_id'])}</p>
<p><strong>Nombre total d’objets :</strong> {report['total_items']}</p>
<h2>Objets par type</h2>
<table><thead><tr><th>Type</th><th>Nombre</th></tr></thead><tbody>{rows}</tbody></table>
<h2>Objets non supportés</h2>
<ul>{unsupported_rows}</ul>
</body>
</html>
"""
    (output_dir / "report.html").write_text(report_html, encoding="utf-8")
    return report
