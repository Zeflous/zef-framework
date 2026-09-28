#!/usr/bin/env python3
"""
doctum_to_wiki.py — build a Markdown API *index* from a Doctum search index.

Why an index and not the documentation itself:

  A GitHub Wiki is a SEPARATE git repository (`<repo>.wiki.git`) holding Markdown
  pages. A Doctum build is static multi-page HTML plus asset trees (css/, js/,
  fonts/, doctum.js, opensearch.xml) joined by relative `.html` links. None of
  that survives: the Wiki renders Markdown, supplies its own CSS, runs no custom
  JavaScript and offers no channel for binary assets — so relative page links
  would simply break.

  What *does* survive is `doctum-search.json`, which is plain JSON. This script
  turns it into one Markdown page per namespace whose entries link OUT to the
  generated API reference on GitHub Pages. The Wiki therefore acts as a
  navigable index over the real documentation, never as a copy of it.

  `_generated.txt` records exactly which files were produced, so a publisher can
  refresh its own pages later without touching hand-written Wiki content.

Schema of doctum-search.json (verified against a real build):
  {"items": [ {"t": type_code, "n": name, "p": page, "d": description_or_null,
               "f": {"n": parent_namespace, "p": parent_page} | null} ]}
  type_code: C=class  I=interface  T=trait  N=namespace  M=method
  `f` is null for methods (t=M).

Usage:
  python3 doctum_to_wiki.py <doctum-search.json> <out_dir> [pages_api_base_url]
"""

from __future__ import annotations

import json
import re
import sys
from collections import defaultdict
from pathlib import Path

TYPE_LABEL = {"C": "Class", "I": "Interface", "T": "Trait", "N": "Namespace", "M": "Method"}
TYPE_ORDER = {"C": 0, "I": 1, "T": 2}
MANIFEST = "_generated.txt"

REPO_ROOT = Path(__file__).resolve().parents[2]


def repo_path(raw: str, what: str) -> Path:
    """Resolve a CLI-supplied path and refuse anything outside the repository.

    Both the search index this tool reads and the directory it writes live
    inside the checkout, so a mistyped or injected argument must never reach
    `read_text()`, `mkdir()` or `write_text()` at an arbitrary location
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
    # location, so a link inside the repo but points outward is refused too.
    resolved = Path(normalized).resolve()
    if resolved != REPO_ROOT and REPO_ROOT not in resolved.parents:
        print(_refuse, file=sys.stderr)
        sys.exit(2)
    return resolved


def slug(text: str) -> str:
    """A Wiki page name must be a safe, stable filename."""
    return re.sub(r"[^A-Za-z0-9._-]", "-", text).strip("-") or "root"


def main() -> int:
    if len(sys.argv) < 3:
        print(__doc__)
        return 2

    src, out = (
        repo_path(sys.argv[1], "Doctum search index"),
        repo_path(sys.argv[2], "output directory"),
    )
    base = sys.argv[3].rstrip("/") if len(sys.argv) > 3 else ""
    data = json.loads(src.read_text(encoding="utf-8"))
    items = data.get("items", [])

    if not items:
        print(
            "ERROR: the Doctum search index contains no items. Refusing to publish "
            "an empty index — check that `composer docs` ran and that "
            "doctum.php's Finder::in() still matches real source directories.",
            file=sys.stderr,
        )
        return 4

    # Group declared types by their containing namespace via `f.n`.
    members: dict[str, list[dict]] = defaultdict(list)
    for it in items:
        if it.get("t") in TYPE_ORDER and it.get("f"):
            members[it["f"]["n"]].append(it)

    namespaces = sorted({it["n"] for it in items if it.get("t") == "N"} | set(members))
    out.mkdir(parents=True, exist_ok=True)

    pages, total_types, total_methods, written = [], 0, 0, []
    for ns in namespaces:
        rows = sorted(members.get(ns, []), key=lambda m: (TYPE_ORDER.get(m["t"], 9), m["n"]))
        # Method entries (t=M) carry no `f`, so derive the owner namespace from the
        # name itself: "NS\\Cls::method" -> owner "NS\\Cls" -> namespace "NS".
        # A startswith() prefix test would over-count, because "Zef\\Framework\\Console"
        # is a prefix of "Zef\\Framework\\Console\\Generator\\...".
        methods = sum(
            1
            for it in items
            if it.get("t") == "M" and it["n"].rsplit("::", 1)[0].rsplit("\\", 1)[0] == ns
        )
        total_types += len(rows)
        total_methods += methods

        lines = [
            f"# `{ns}`",
            "",
            f"{len(rows)} declared type(s) · {methods} documented method(s).",
            "",
        ]
        if rows:
            lines += ["| Type | Name | Page |", "|---|---|---|"]
            for m in rows:
                short = m["n"].rsplit("\\", 1)[-1]
                url = f"{base}/{m['p']}" if base else m["p"]
                lines.append(f"| {TYPE_LABEL.get(m['t'], m['t'])} | `{short}` | [open]({url}) |")
        else:
            lines.append("_No declared types directly in this namespace._")
        lines += [
            "",
            "---",
            "",
            "*Index only — signatures, inheritance and source links live in the",
            "generated API reference on GitHub Pages, which a Wiki cannot host.*",
        ]
        page = slug(ns)
        (out / f"{page}.md").write_text("\n".join(lines) + "\n", encoding="utf-8")
        written.append(f"{page}.md")
        pages.append((ns, page, len(rows)))

    sidebar = ["# API index", "", f"{len(pages)} namespaces · {total_types} types", ""]
    for ns, page, n in pages:
        if not ns:
            continue
        depth = ns.count("\\")
        sidebar.append(f"{'  ' * depth}- [`{ns}`]({page}) — {n}")
    (out / "_Sidebar.md").write_text("\n".join(sidebar) + "\n", encoding="utf-8")
    written.append("_Sidebar.md")

    # Publish manifest: lets a publisher remove exactly its own previous output
    # and nothing else. Every name is a bare slug, so no path traversal is
    # possible by construction (slug() strips everything but [A-Za-z0-9._-]).
    written.append(MANIFEST)
    (out / MANIFEST).write_text("\n".join(sorted(written)) + "\n", encoding="utf-8")

    print(f"namespaces={len(pages)} types={total_types} methods={total_methods}")
    print(f"pages_written={len(written)} out_dir={out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
