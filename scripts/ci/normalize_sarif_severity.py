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

REPO_ROOT = Path(__file__).resolve().parents[2]


def repo_path(raw: str, what: str) -> Path:
    """Resolve a CLI-supplied path and refuse anything outside the repository.

    The SARIF files this tool rewrites are written by the Snyk CLI into the
    checkout root, so a mistyped or injected argument must never reach
    ``open()`` at an arbitrary location (code-scanning rule python/PT). The
    argument is validated lexically before any Path object is built, and
    validated again after resolution, so a symlink that lives in the repo but
    points outward is refused as well.

    This mirrors the guard used by the other CLI tools under ``scripts/``
    (``show_escapes.py``, ``mine_escapes.py``, ``ci/doctum_to_wiki.py``,
    ``mutation/gen_zone_tables.py``).
    """
    _refuse = f"ERROR: {what} must stay inside the repository: {raw}"
    if not raw:
        print(f"ERROR: {what} must not be empty.", file=sys.stderr)
        sys.exit(2)
    # Lexical gate, before any Path is constructed: the only valid shapes are
    # a relative path without parent-escape segments, or an absolute path that
    # already starts at the repository root. Everything else is refused here.
    normalized = raw.replace("\\", "/")
    if normalized.startswith("~") or ".." in normalized.split("/"):
        print(_refuse, file=sys.stderr)
        sys.exit(2)
    root_str = str(REPO_ROOT).rstrip("/")
    if normalized.startswith("/") and normalized != root_str and not normalized.startswith(root_str + "/"):
        print(_refuse, file=sys.stderr)
        sys.exit(2)
    # Resolution gate: resolve symlinks and re-verify containment at the real
    # location, so a link inside the repo but pointing outward is refused too.
    resolved = Path(normalized).resolve()
    if resolved != REPO_ROOT and REPO_ROOT not in resolved.parents:
        print(_refuse, file=sys.stderr)
        sys.exit(2)
    return resolved


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
        path = repo_path(name, "SARIF path")
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
