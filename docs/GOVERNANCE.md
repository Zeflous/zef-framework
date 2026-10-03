# Governance: merge gates, ratchets and credential policy

This document records the **enforced** repository governance as measured from the
GitHub API and the workflow files — not as intended. Where an enforcement rule and
its configuration disagree, the disagreement is written down here rather than
smoothed over.

> **STALE SNAPSHOT (audit v25, 2026-10-03).** The measurements below were taken
> against the protection API on `main` @ `e33bf155732a52401f8290255dfa1329f19177d8`
> (re-verified 2026-10-01, after the v2.34.1 merge). `main` has advanced since
> (latest audited head `249717fb`, after the #366 merge); re-verify the API
> readings — the required-context union in particular — before treating any
> setting as current.

## 1. Branch protection on `main`

| Setting | Value | Consequence |
|---|---|---|
| Ruleset `main` required contexts | **6** (id `23878357`, `enforcement: active`) | Stale since its creation (2026-09-23): still lists `Analyze (actions)`, which the classic list replaced two days later (see N2). Additive only — it gates nothing the classic list does not already gate. |
| Classic BP required contexts | **12** | The grown, current list — the number the v2.34.0 changelog counts ("required checks 11 → 12"). |
| **Effective required (union)** | **13** | What a pull request must actually satisfy before merge. The canonical per-context table is §1.1; the drift history is N3. |
| `strict` | `true` | The branch must be **up to date with `main`** — a PR that falls behind must update its branch. A `behind` PR is therefore expected behaviour, not a broken PR |
| `enforce_admins` | `true` | No bypass, including for the owner |
| `dismiss_stale_reviews` | `true` | Pushing after review re-requires review |
| `require_code_owner_reviews` | `false` (measured 2026-10-01) | Off in both mechanisms — the contradictory setting N1 recorded on 2026-09-24 was turned down since, without a note here until now. See N1. |
| `required_approving_review_count` | **`0`** | ⚠️ See N1 below |
| `required_linear_history` | `false` | Merge commits are permitted |
| `allow_force_pushes` / `allow_deletions` | `false` | History on `main` is append-only through the API |
| `required_conversation_resolution` | `true` | All review threads must be resolved before merge (present in both mechanisms) |

### 1.1 The canonical gate table

Thirteen contexts gate every merge — the union of the classic list (12) and
the ruleset list (6, of which 5 overlap and 1 is the stale `Analyze
(actions)`). "Enforced by" records which mechanism requires the context;
"Reports on" records the events where the context actually publishes, as
measured on PR #274 and on the `main` push after it.

| # | Context | Enforced by | Gate domain | Reports on |
|---|---|---|---|---|
| 1 | `PHP lint, audit, static analysis and style` | both | lint · self-test 501/501 · PHPUnit 3808 · PHPStan (max + strict-rules) · deptrac · cs-fixer · phpcs · coverage ≥ 90% · rector dry-run · zone mutation ratchet · release docs/cadence ratchets | PR + push |
| 2 | `dependency-review` | both | supply-chain diff of the lockfile | PR only |
| 3 | `gitleaks` | both | whole-diff secret scan | PR + push |
| 4 | `PHPBench` | both | performance budget against the committed baseline | PR + push |
| 5 | `Build API documentation` | both | Doctum API-docs build | PR only |
| 6 | `Analyze (actions)` | ruleset only | CodeQL `actions`-language analysis | PR + push |
| 7 | `CodeQL` | classic only | the GHAS context proving the `actions`-language scan ran on the PR head (not a PHP content gate — CodeQL has no PHP support) | PR only |
| 8 | `PHP SAST (Semgrep)` | classic only | blocking `ERROR`-severity Semgrep over `src/**` (+ tests/tooling lanes) | PR + push |
| 9 | `SonarCloud Scan` | classic only | bugs/quality gate on the main SonarCloud project | PR + push |
| 10 | `snyk` | classic only | Snyk dependency & license security | PR + push |
| 11 | `code/snyk (Zeflous)` | classic only | Snyk code analysis through the Zeflous app | PR only |
| 12 | `Platform smoke (windows-latest)` | classic only | Windows platform parity (graceful Redis-skip profile, no `ext-redis`) | PR + push |
| 13 | `Stub pre-scan (tests fixtures)` | classic only | quarantined SonarCloud scan of `tests/**` + fail-closed fixture-count ratchet (222 files) | PR + push |

Four contexts — `dependency-review`, `Build API documentation`, `CodeQL`,
`code/snyk (Zeflous)` — report **only on pull requests**. A direct push to
`main` (which `strict` + `enforce_admins` already forbids in practice) can
never satisfy them; the merged pull request carries the evidence. This is
why the check-run list on a fresh `main` HEAD appears to be "missing" four
required names: they are PR-scoped by design, not skipped.

The ruleset additionally enforces rule types the classic list has no
equivalent for, all measured active on `main`:

| Ruleset rule | Parameters (measured) | Measured effect |
|---|---|---|
| `code_scanning` | CodeQL + Semgrep OSS, all alert severities | would block a PR whose code-scanning results carry alerts; on green PRs its effect is indistinguishable from a backstop |
| `code_quality` | all severities | same posture — backstop behind #9 |
| `code_coverage` | minimum 90%, max drop 5% | requires a coverage report posted to the Checks API to take effect; no dedicated coverage check-run exists today, so the 90% floor is enforced by the `assert-coverage.php` step inside context #1 and this rule stays dormant |

### N3 — the two protection mechanisms drifted apart (open finding)

The ruleset `main` was created 2026-09-23 by copying that era's six classic
contexts, `Analyze (actions)` included. Every correction and promotion since
then landed in the **classic** list only: `Analyze (actions)` → `CodeQL`
plus `PHP SAST (Semgrep)` (N2, 2026-09-24), later `SonarCloud Scan`, `snyk`,
`code/snyk (Zeflous)`, `Platform smoke (windows-latest)`, and finally
`Stub pre-scan (tests fixtures)` with v2.34.0 — while the ruleset's context
list was never touched. The measured consequences:

- The union is **13**, not 12 (the changelog's count of the classic list) and
  not 7 (the number this file carried before this correction — it was last
  re-verified 2026-09-24, before the zero-debt gates grew the classic list).
- The stale ruleset copy is **additive only**: its one non-overlapping item,
  `Analyze (actions)`, publishes green on PR heads today, so it costs one
  extra green check and blocks nothing.
- Every future promotion must add its context to **both** mechanisms — or
  explicitly record the asymmetry in §1.1 — otherwise the drift regrows.

Closing options (**owner decision** — deliberately not executed by this
documentation change):

- **A. Re-sync the ruleset** to the classic twelve (`Analyze (actions)` out,
  the seven classic-only contexts in): dual enforcement stays, the union
  drops to 12, and both APIs agree again.
- **B. Consolidate on the ruleset** (turn classic protection off): one
  mechanism, the modern API — conversation resolution and thread resolution
  are already ruleset parameters (`required_review_thread_resolution`), but
  the classic UI's required-context list disappears and the migration must
  be verified gate-by-gate against §1.1 first.

Until one is chosen, §1.1 is the single authoritative gate table, and both
mechanisms stay untouched.

### N1 — code-owner rule turned down by a config change (updated 2026-10-01)

The 2026-09-24 measurement recorded `require_code_owner_reviews: true`
together with `required_approving_review_count: 0` — a contradictory
configuration: GitHub only applies the code-owner requirement when at least
one approving review is required, so the rule could not be satisfied *or*
blocked, and at that time the repository also had **no `.github/CODEOWNERS`
file at all**. As measured now, both mechanisms carry
`require_code_owner_reviews: false`, so nothing is contradictory anymore —
the setting was turned down at some point after 2026-09-24 without a note
here until this correction. `.github/CODEOWNERS` exists. Raising
`required_approving_review_count` to `>= 1` remains an **owner decision**:
it changes the merge flow for every pull request.

### N2 — the PHP SAST gate is now a required context (closed 2026-09-24)

As measured, neither CodeQL nor the PHP SAST job was among the required contexts, so a
security regression detected by either did not block a merge. That is resolved for the
gate that actually analyses the **PHP production source**: `PHP SAST (Semgrep)` is now a
required context, and that job decides its outcome on the exit code of the blocking
`ERROR`-severity scan over `src/**` — never on code-scanning availability, which is
`continue-on-error` by design.

CodeQL is still **not** a content gate for PHP here, because CodeQL does not support PHP
at all; requiring it adds no PHP coverage. It is required as the context that proves the
`actions`-language analysis ran (see §1), which is why `Analyze (actions)` was replaced by
`CodeQL` rather than simply deleted.

Correction note (2026-10-01): that replacement was applied to the **classic**
context list only — the ruleset copy still carries `Analyze (actions)` today
(see N3). Also, as of this re-verification `Analyze (actions)` *does* publish
on pull-request heads (it is green on PR #273 and PR #274), so the "never
publishes" rationale that motivated the swap no longer describes the
platform behaviour; the context is kept because it is harmless and required
by the stale ruleset copy.

## 2. Quality ratchets

A ratchet converts "this should be better" into "this must not get worse". Each one below
is enforced, cheap, and cannot be relaxed without a visible edit.

### 2.1 Mutation score — per zone

- **Enforced aggregate gate:** `composer mutation:ci` → `--min-msi=85 --min-covered-msi=90`
  over every first-party source directory (~9.4k mutants). It runs in
  `mutation.yml` (release tags and `workflow_dispatch`), **not** on the push/PR
  path — see 2.5 — and `release.yml` waits for it before publishing.
- **Enforced per-zone ratchet:** `composer mutation:zones` →
  `scripts/ci/assert-zone-coverage.php --floor=95`, run in `ci.yml` as the
  `Zone mutation ratchet` step.
- The ratchet reads committed evidence: `docs/mutation/baseline.tsv` (frozen floor per
  zone) against `docs/mutation/zones.tsv` (current measurement), for exactly the zones listed
  in `scripts/f16_zones.tsv`. It re-runs **no** mutation suite, so the pipeline cost does not
  double. It fails if a zone is missing from either table, if a row claims `OK` below the
  floor, if a `DEBT`/`UNKNOWN` row carries no reason, if `evidence` is empty, or if **any zone
  has regressed below its frozen baseline** — that last check is the point of the gate.
- **The baseline only ratchets up.** Raising it is a deliberate, reviewable edit; lowering a
  measured score below it fails the build. Closing a zone is: write tests, re-measure, promote
  the row to `OK`.
- Format, campaign tooling and pitfalls: `docs/mutation/README.md`.

### 2.2 PHPStan baseline

`phpstan-baseline.neon` currently suppresses **455** findings. PHPStan runs at level max and
passes *because* those 455 are held in the baseline.

Rule: **the baseline may not grow without an owner decision.** A pull request that adds
entries must state, in its description, which findings it adds and why they cannot be fixed
in the same change. Any reduction is welcome and requires no approval. Removing the baseline
outright is a multi-release campaign, not a single step, and would block the pipeline until
finished.

### 2.3 Every workflow job declares a timeout

Every job in `.github/workflows/` declares `timeout-minutes`. This is a ratchet on
*pipeline liveness*: without it, a stuck step holds a required status check open
indefinitely and — because a hung run's `updated_at` freezes at job start — a hang is
indistinguishable from a slow run from the outside. With it, the job fails closed and
releases the check. New jobs must declare one.

### 2.4 The release gate waits for a terminal CI state

`release.yml` must not read a CI `conclusion` before the run is `completed`. Doing so
converts a legitimate race into a spurious failure: pushing a tag while `ci.yml` for the
same SHA is still `in_progress` yields an empty conclusion, which a naive gate reads as
"failed". The gate polls `status` to a terminal value, bounded by a deadline so a genuinely
stuck pipeline still fails closed. See `references/cicd-gitlab-github.md` in the ZEF skill
package for the full rule.

### 2.5 The mutation suite runs at release, the ratchet runs on every push

The aggregate Infection gate was relocated out of the push/PR path into
`mutation.yml`, triggered by `v*.*.*` tags and `workflow_dispatch`. It was
**relocated, not weakened**:

- **Same threshold and scope.** `composer mutation:ci` → `--min-msi=85 /
  --min-covered-msi=90` over the same first-party directories. No number moved.
- **Same blocking power.** `release.yml` includes `mutation.yml` in the list of
  workflows it waits for (see 2.4), so a release fails unless the suite passes.
  A gate that is not waited on is not a gate — the workflow alone would prove
  nothing.
- **Same evidence.** `build/infection.log` and `build/infection-summary.log` are
  uploaded as the `mutation-evidence` artifact even when the gate fails, so a red
  verdict is diagnosable without re-running the suite.
- **The cheap ratchet stays.** `composer mutation:zones` still runs in `ci.yml` on
  every push and PR. It re-runs no suite — it reads `docs/mutation/` — so the
  zone-regression signal still lands minutes after the push, while the
  hour-long suite is what moved.

Why: the mutation score is a property of the **committed source tree**, not of the
change under review, so on the PR path it could not change any merge decision it
was not already making — while consuming ~55 of `ci.yml`'s ~66 minutes on every
push and holding the required check `PHP lint, audit, static analysis and style`
open for roughly an hour per merge. The trade is explicit and reversible: one
workflow file and one line in `release.yml`.

### 2.6 Release cadence — the tag ships with the bump, the changelog ships with the tag

Eleven minors (v2.18.0 → v2.27.0) shipped as `docs/CHANGELOG-v*.md` files with
no tag and no GitHub release (audit issue #88), while the Release-Drafter
computed patch-only drafts from file-matching labels that never fired. A
release that exists only as a changelog file cannot be installed, attested or
diffed — the tag is the release record, and every "documented release"
reference in README/SECURITY keyed off it drifted three to nine minors behind
`ZefVersion`. The policy going forward:

- **A PR that bumps `ZefVersion::VERSION` cuts the tag in the same merge.**
  The changelog file lands first (or in the same PR); the tag `v{VERSION}` is
  pushed as part of landing the bump. A tag-only release without assets is
  still better than a silent minor — the full `release.yml` artifact pipeline
  (tarball, checksum, provenance attestation) stays reserved for milestone
  releases and can be dispatched against any tag afterwards.
- **The gate is `scripts/ci/assert-release-cadence.php`** and runs in `ci.yml`
  on every push, PR and schedule. Fail-closed: every `vX.Y.Z` tag with major
  ≥ 2 must carry `docs/CHANGELOG-vX.Y.Z.md`, and the newest tag may never run
  ahead of `ZefVersion::VERSION`. One-directional on purpose: a changelog
  without a tag is a legitimate pre-release state (the bump PR is in flight).
  Both failure modes were proven against negative controls before the gate
  was trusted (see the script header).
- **The drafter computes MINOR by default.** `.github/release-drafter.yml`
  resolves `patch` only when the `patch` label is applied explicitly (hotfix)
  and `major` on the `breaking` label — conventional-commit titles
  (`feat:`, `fix:`, `docs:`, `chore:`, and `!:` / `BREAKING CHANGE`) feed the
  autolabeler so the category sections and the resolver have a signal. The
  draft remains advisory: publishing goes through `release.yml`, never
  through the draft's publish button.

### 2.7 Platform smoke cells are advisory until proven

`ci.yml` runs two platform cells beyond the canonical ubuntu/PHP-8.4 gate
(restore of the matrix collapsed in PR #22 — audit issue #92):
`Platform smoke (windows-latest)` and `Platform smoke (PHP 8.5)`. They are
deliberately **static-named jobs, not a matrix strategy**: GitHub Actions
suffixes matrix values onto job names, which breaks the exact match against
the required status-check context. The windows-latest cell has since been **promoted to a required context —
via the classic protection list** (measured 2026-10-01: it gates PR #273 and
PR #274). That promotion never reached this paragraph, and never reached the
ruleset's context list either — the exact drift class N3 documents. The
PHP 8.5 cell remains deliberately **not required** — an advisory cell must
prove itself stable on main (two consecutive green runs on pushes, no
flakes) before promotion, at which point the context name should be added to
**both** mechanisms (the lesson of N3) and the "advisory" label removed
here. A gate that blocks merges while it is itself unproven trades one
flake class for another.

The per-platform Redis profile is part of the design: Windows runners have no
service containers, and loading `ext-redis` there without a server turns the
suite into hard errors (phpredis throws in `setUp`, so the availability guard
never returns false) — so the Windows cell omits the extension entirely and
takes the graceful skip profile, while the 8.5 cell runs the full Redis wire
profile like the canonical cell. `composer why-not php 8.5.0` was verified
empty before the 8.5 cell landed (no locked package constrains it).

The Windows cell landed with its phpunit step deliberately non-blocking: the
first runs measured 26 platform-debt failures in recently-landed features
(make:app DX paths, restrictive file modes, LocalStorage failure-mapping
fixtures, CRLF-sensitive expectations), inventoried and tracked in issue #110.
Flip `continue-on-error` off as that debt burns down, then promote the cell.

**On the Kilo review bot**: it has repeatedly failed with output-limit errors
on large PRs (observed twice during #83 and once during #84). It is not a
required context and never blocked a merge — a red Kilo check with an
infra-flake signature (output truncation, timeout, runner-side error) is not
a code verdict. Do not "fix" a phantom: check the log signature first.

## 3. Credential policy

### 3.1 Declared by name only

The automation runs with a single GitHub credential, injected at runtime and referred to by
name (`GITHUB_TOKEN`). Its **value** must never appear in any repository artifact — not in
source, workflows, `.git/config`, documentation, logs, reports, PR descriptions, or CI logs.

Presence is checked with a masked probe (`scripts/check_env_vars.py`), which prints only
`PRESENT` / `ABSENT` and never a value.

### 3.2 Never persist a runtime credential into repository state

A token reaches shell steps but **not** the agent's own tool-call environment. Writing it
into a git remote URL persists plaintext in `.git/config`. Push with an inline credential URL
or a credential helper that reads the environment **at run time**:

```bash
git config --local credential.helper '!f() { echo username=x-access-token; echo password=$GITHUB_TOKEN; }; f'
```

The stored value is the *expression*, evaluated per invocation, so `.git/config` holds no
secret. After any credential touch, verify: `git config --local --list` shows no value, and a
workspace-wide scan finds no occurrence.

### 3.3 Least-privilege scope

The token in use carries a wide OAuth scope set (including `admin:org`,
`admin:repo_hook`, `admin:ssh_signing_key`, `delete:packages`, `workflow`, `repo`).
The automation needs **`repo`** and, only if it must write workflow files, **`workflow`**.
Narrowing the scope is an **account-level owner action** — it cannot be performed from
inside the repository.

## 4. Supply chain

- Actions are pinned to a commit SHA with the human-readable version in a trailing comment;
  Dependabot (`.github/dependabot.yml`) rewrites the SHA and keeps the comment in sync.
- Dependabot covers `composer` and `github-actions`, weekly, grouped, capped at 5 open PRs per
  ecosystem. Runtime dependencies are deliberately **not** grouped: a production dependency
  bump deserves its own review and its own `dependency-review` run.
- SBOM and provenance generation run in `sbom.yml` and `release.yml`.

## 5. Bot-managed release state

The draft release `v2.17.1` is **managed by Release Drafter** (`release-drafter.yml`): its body
is regenerated from merged pull requests, and it is published through `release.yml` when the
owner pushes the tag. A draft whose body already lists merged PRs is the mechanism working as
designed. Do not delete release drafts on the assumption that they are stale.
