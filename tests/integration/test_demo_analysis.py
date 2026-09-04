import json

from ilias2moodle.cli import main


def test_demo_analysis_generates_expected_files(tmp_path, monkeypatch) -> None:
    monkeypatch.setenv("ILIAS_MODE", "demo")
    output = tmp_path / "course-1234"

    result = main(["analyse", "--course", "1234", "--output", str(output), "--dry-run"])

    assert result == 0
    assert (output / "migration.json").exists()
    assert (output / "report.json").exists()
    assert (output / "report.html").exists()

    report = json.loads((output / "report.json").read_text(encoding="utf-8"))
    assert report["course"]["source_id"] == "1234"
    assert report["total_items"] == 5
