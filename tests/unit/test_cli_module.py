from __future__ import annotations

import subprocess
import sys


def test_python_module_cli_executes_main() -> None:
    result = subprocess.run(
        [sys.executable, "-m", "ilias2moodle.cli", "--version"],
        check=False,
        capture_output=True,
        text=True,
    )

    assert result.returncode == 0
    assert "ILIAS2Moodle 0.1.0" in result.stdout
