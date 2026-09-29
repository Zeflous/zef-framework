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
import sys
from pathlib import Path


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
        path = Path(name)
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
