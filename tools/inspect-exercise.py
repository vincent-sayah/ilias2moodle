#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
from pathlib import Path

from ilias2moodle.ilias.exercise import parse_exercises


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Inspect native ILIAS Exercise export sets without extraction."
    )
    parser.add_argument("archive", type=Path)
    args = parser.parse_args()

    exercises = parse_exercises(args.archive)
    print(json.dumps(exercises, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
