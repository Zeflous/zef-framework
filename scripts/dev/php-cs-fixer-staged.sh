#!/usr/bin/env bash
#
# LOCAL-ONLY helper: run PHP-CS-Fixer over STAGED PHP files only.
# ---------------------------------------------------------------------------
# Why this exists:
#   "php-cs-fixer check" with no arguments walks the whole Finder tree
#   (src + tests). Before a commit you only care about the files you actually
#   staged, so this helper narrows the scope to those files.
#
# Cache:
#   Uses the local cache (--using-cache=yes) and leaves the cache file at its
#   default location (.php-cs-fixer.cache, already git-ignored) so unchanged
#   files are not re-checked. CI deliberately does the opposite
#   (--using-cache=no, full tree) - CI must stay deterministic and must not
#   depend on a cache that only exists on someone's machine. This helper was
#   previously referenced by composer.json while the file itself was absent;
#   it now exists on main, so `composer format:check:staged` runs instead of
#   failing with "No such file or directory" (exit 127).
#
# Scope:
#   --path-mode=intersection means "intersection of the Finder paths from the
#   repository config AND the paths given here", so the repository's own
#   configuration (.php-cs-fixer.dist.php, @PSR12) remains the authority and
#   this helper cannot widen the checked set to files outside src/ and tests/.
#
# PHP-CS-Fixer remains the AUTHORITY for formatting. This script never writes -
# it only reports, so "fix locally" stays an explicit, separate action.
#
# Exit codes follow PHP-CS-Fixer: 8 = files need fixing, 0 = compliant.

set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
cd "$repo_root"

if [[ ! -x vendor/bin/php-cs-fixer ]]; then
  echo "php-cs-fixer (staged): vendor/bin/php-cs-fixer not found. Run 'composer install'." >&2
  exit 1
fi

# Staged PHP files: Added/Copied/Modified/Renamed, and still present on disk
# (a file staged for deletion must not be handed to the fixer).
staged_files=()
while IFS= read -r file; do
  [[ -n "$file" ]] || continue
  [[ -f "$file" ]] || continue
  staged_files+=("$file")
done < <(git diff --cached --name-only --diff-filter=ACMR -- '*.php')

if [[ "${#staged_files[@]}" -eq 0 ]]; then
  echo "php-cs-fixer (staged): no staged PHP files; nothing to check."
  exit 0
fi

echo "php-cs-fixer (staged): checking ${#staged_files[@]} staged PHP file(s) with the local cache."

# The '--' terminator keeps file names from being parsed as options.
vendor/bin/php-cs-fixer check \
  --diff \
  --path-mode=intersection \
  --using-cache=yes \
  -- "${staged_files[@]}"
