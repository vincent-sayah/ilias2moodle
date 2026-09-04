#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

PYTHON_BIN=""
for candidate in python3.13 python3.12 python3.11; do
  if command -v "$candidate" >/dev/null 2>&1; then
    if "$candidate" -c 'import sys; raise SystemExit(0 if sys.version_info >= (3, 11) else 1)' >/dev/null 2>&1; then
      PYTHON_BIN="$candidate"
      break
    fi
  fi
done

if [[ -z "$PYTHON_BIN" ]]; then
  echo "ERREUR : ILIAS2Moodle nécessite Python 3.11 ou supérieur."
  echo
  echo "Versions détectées :"
  for candidate in python3 python3.13 python3.12 python3.11; do
    if command -v "$candidate" >/dev/null 2>&1; then
      printf '  %-10s -> ' "$candidate"
      "$candidate" --version 2>&1 || true
    fi
  done
  echo
  echo "Sur RHEL/AlmaLinux 8 ou 9, installez Python 3.11 en parallèle du Python système :"
  echo "  dnf install -y python3.11 python3.11-pip"
  echo
  echo "Ne remplacez pas /usr/bin/python3 et ne modifiez pas le Python système."
  exit 3
fi

echo "ILIAS2Moodle : utilisation de $($PYTHON_BIN --version 2>&1) via $(command -v "$PYTHON_BIN")"
cd "$ROOT_DIR"
PYTHONPATH="$ROOT_DIR/src${PYTHONPATH:+:$PYTHONPATH}" exec "$PYTHON_BIN" -m ilias2moodle "$@"
