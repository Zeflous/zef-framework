#!/usr/bin/env python3
"""Tampilkan escape mutants (file:line, mutator, diff) secara kompak.

Usage: python3 scripts/show_escapes.py <infection-log> [substr-file] [--max N]
"""
import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[1]


def repo_path(raw: str, what: str) -> Path:
    """Resolve a CLI-supplied path and refuse anything outside the repository.

    The log this tool reads is produced inside the checkout, so a mistyped or
    injected argument must never point `open()` at arbitrary files outside it
    (code-scanning rule python/PT). The argument is validated lexically
    before any Path object is built, and validated again after resolution, so
    a symlink that lives in the repo but points outward is refused as well.
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
    # location, so a link inside the repo that points outward is refused too.
    resolved = Path(normalized).resolve()
    if resolved != REPO_ROOT and REPO_ROOT not in resolved.parents:
        print(_refuse, file=sys.stderr)
        sys.exit(2)
    return resolved


def parse_args(argv: list[str]) -> tuple[str, str, int] | None:
    """Split argv into (log, substr, maxn); None means usage error."""
    if len(argv) < 2:
        return None
    log = repo_path(argv[1], "infection log")
    substr = argv[2] if len(argv) > 2 and not argv[2].startswith("--") else ""
    maxn = 10000
    if "--max" in argv:
        maxn = int(argv[argv.index("--max") + 1])
    return log, substr, maxn


def render_entry(index: int, entry: str, path: str, line: str, mut: str) -> None:
    """Render one escaped-mutant entry."""
    short = "/".join(path.split("/")[-3:])
    print(f"#{index} {short}:{line} [{mut}]")
    diff = []
    for ln in entry.splitlines()[1:]:
        s = ln.strip()
        if s.startswith(("@@", "-", "+")):
            diff.append(s[:160])
    for d in diff[:14]:
        print(f"   {d}")
    print()


def main() -> int:
    parsed = parse_args(sys.argv)
    if parsed is None:
        print("usage: show_escapes.py <log> [substr] [--max N]", file=sys.stderr)
        return 2
    log, substr, maxn = parsed
    text = open(log, encoding="utf-8", errors="replace").read()
    m = re.search(r"^Escaped mutants:\s*=+\s*(.*)", text, flags=re.M | re.S)
    if not m:
        print("(tidak ada escape)", file=sys.stderr)
        return 0
    # The block runs to the end of the log; the next section header ("Killed
    # mutants:" etc.) ends the escaped block. Cut it here instead of encoding
    # the boundary as a lookahead in the regex above.
    block = m.group(1)
    boundary = re.search(r"^\w[\w ]* mutants?:", block, flags=re.M)
    if boundary is not None:
        block = block[: boundary.start()]
    entries = re.split(r"\n(?=\d+\) )", block)
    n = 0
    for e in entries:
        hm = re.match(r"\d+\) (.+?):(\d+)\s+\[M\] (\S+)", e)
        if hm is None or (substr and substr not in hm.group(1)):
            continue
        n += 1
        if n > maxn:
            print(f"... (dipotong, > {maxn})")
            break
        render_entry(n, e, hm.group(1), hm.group(2), hm.group(3))
    if n == 0:
        print("(tidak ada escape cocok)", file=sys.stderr)
    return 0


if __name__ == "__main__":
    sys.exit(main())
