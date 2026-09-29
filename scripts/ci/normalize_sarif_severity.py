#!/usr/bin/env python3
"""Normalize non-numeric ``security-severity`` values in Snyk SARIF files.

Why this exists
---------------
Snyk's Open Source (SCA) SARIF can emit a rule whose ``security-severity``
property is the literal string ``"undefined"``: license issues carry no CVSS
score, so Snyk has no number to write. GitHub Code Scanning rejects the entire
file with::

    could not convert rules: invalid security severity value, is not a number: undefined

which means the SCA findings never reach the Code Scanning tab even though the
scan itself succeeded.

This script rewrites any non-numeric ``security-severity`` to ``"0.0"`` so the
file is accepted. It touches ONLY the SARIF *evidence*; the workflow's
fail-closed verdict is computed from the scanner exit codes and is unaffected.

Usage::

    python3 scripts/ci/normalize_sarif_severity.py snyk-code.sarif snyk-sca.sarif

Missing files are skipped (a scanner that produced no SARIF is handled by the
verdict step, not here). Exit status is always 0 unless a file exists but is
not valid JSON, which is a real error worth failing on.
"""

from __future__ import annotations

import json
import os
import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]

# A SARIF filename the Snyk CLI writes into the checkout root: a bare name with
# no directory component and a strict character whitelist. Anything else is
# refused before a path is built.
_SAFE_SARIF_NAME = re.compile(r"^[A-Za-z0-9._-]+\.sarif$")


def sarif_path(raw: str) -> Path:
    """Resolve a SARIF filename to a path inside the repository root.

    The Snyk CLI writes its SARIF output into the checkout root, so this tool
    accepts a bare ``*.sarif`` filename only. ``os.path.basename`` strips any
    directory component (a sanitizer Snyk Code recognises) and the strict
    whitelist rejects everything that is not a plain SARIF filename, so a
    crafted argument can never influence the location that is written to.
    """
    name = os.path.basename(raw.replace("\\", "/"))
    if not _SAFE_SARIF_NAME.match(name):
        print(f"ERROR: SARIF path must be a bare *.sarif filename: {raw}", file=sys.stderr)
        sys.exit(2)
    return REPO_ROOT / name


def normalize(path: Path) -> int:
    """Rewrite non-numeric security-severity values in ``path``.

    Returns the number of values rewritten. Raises ``json.JSONDecodeError`` if
    the file is not valid JSON.
    """
    with path.open(encoding="utf-8") as handle:
        document = json.load(handle)

    fixed = 0
    for run in document.get("runs", []):
        driver = run.get("tool", {}).get("driver", {})
        for rule in driver.get("rules", []):
            properties = rule.get("properties")
            if not isinstance(properties, dict):
                continue
            severity = properties.get("security-severity")
            if severity is None:
                continue
            try:
                float(severity)
            except (TypeError, ValueError):
                properties["security-severity"] = "0.0"
                fixed += 1

    if fixed:
        with path.open("w", encoding="utf-8") as handle:
            json.dump(document, handle)

    return fixed


def main(argv: list[str]) -> int:
    if len(argv) < 2:
        print("usage: normalize_sarif_severity.py <file.sarif> [...]", file=sys.stderr)
        return 2

    for name in argv[1:]:
        path = sarif_path(name)
        if not path.exists():
            print(f"{name}: absent, skipped")
            continue
        try:
            fixed = normalize(path)
        except json.JSONDecodeError as error:
            print(f"{name}: not valid JSON: {error}", file=sys.stderr)
            return 1
        print(f"{name}: normalized {fixed} non-numeric security-severity value(s)")

    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))