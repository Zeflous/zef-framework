# PHP SAST baseline

Security-oriented source-code analysis for the PHP code in this repository, run by
`.github/workflows/php-sast.yml`.

This document records **what is scanned, by what, with which pinned inputs, what
blocks a pull request, and what this gate explicitly is not.** It is the reference
for the scope and severity decisions, so those decisions are reviewable instead of
implicit in a workflow file.

---

## 1. What this gate is, and what it is not

| Gate | Question it answers |
|---|---|
| **PHP SAST (this document)** | Does the PHP source contain a security-relevant code pattern (injection, unsafe execution, unsafe deserialisation, SSRF, weak crypto, …)? |
| **CodeQL** (`.github/workflows`, default setup, `actions` language) | Are the **GitHub Actions workflows** themselves insecure or misconfigured? |
| **PHPStan** (`phpstan.neon.dist`, level max) | Is the PHP code **type-correct** and internally consistent? |
| **Deptrac** (`deptrac.yaml`) | Are the **architectural layer boundaries** respected? |
| **Gitleaks** (`secret-scan.yml`) | Is a **credential** committed in any file type? |
| **Dependency Review** (`dependency-review.yml`) | Does this pull request **introduce a vulnerable dependency**? |
| **Composer security audit** (`ci.yml`, `composer run audit`) | Does the **installed dependency set** carry a known advisory or an unaccepted abandoned package? |
| **Anchore SBOM** (`sbom.yml`) | What is in the **dependency inventory**? |
| **Infection** (Deep QA) | Are the **tests** strong enough to catch a mutation? |

This gate **does not replace, duplicate or weaken any of the above.** Concretely:

- It is **not** a CodeQL PHP analysis — no such thing exists here, because CodeQL
  supports no PHP language at all. Code scanning receives the SARIF this workflow
  uploads, under its own categories (`php-sast-source` — production at the `ERROR`
  floor, blocking; `php-sast-source-warning` — production at the `WARNING` floor,
  **blocking since 2026-09-24**; `php-sast-tests` — test and tooling code at the
  `WARNING` floor, **blocking since 2026-09-24**; `php-sast-tests-error` — test and
  tooling code at the `ERROR` floor, **blocking since 2026-09-24**). Those results
  must not be read as CodeQL output.
- It is **not** a type checker. PHPStan already covers correctness; Semgrep only
  looks for security-relevant patterns, and a Semgrep-clean tree says nothing
  about type correctness.
- It is **not** a secret scanner. Gitleaks already runs on every push and pull
  request.
- No existing step in `ci.yml`, `secret-scan.yml`, `sbom.yml`, `dependency-review.yml`,
  `phpbench.yml`, `pages.yml`, `release.yml`, `release-drafter.yml`, `auto-fix.yml`
  or `composer-lock.yml` was modified, removed or relaxed by this change.

---

## 2. Tool selection

### Chosen

**Semgrep Community Edition (OSS CLI), pinned to the `semgrep/semgrep` container
image at `1.177.0`** — executed with `semgrep scan` inside the pinned image, with
the **ruleset itself pinned to an immutable `semgrep/semgrep-rules` commit**.

### Alternatives considered

| Candidate | Verdict | Reasoning |
|---|---|---|
| **Semgrep CE CLI** (chosen) | **Adopted** | Purpose-built security rule engine with an extensive, curated, **PHP-specific** security ruleset (36 PHP security rules in the pinned tree), taint-style dataflow rules for injection classes, SARIF 2.1.0 output, real severity levels (`ERROR`/`WARNING`/`INFO`) with a `--severity` filter, an exit-code contract usable as a gate, and **no privileged credential required** to run. Runs entirely offline once the ruleset is fetched. |
| **`semgrep/semgrep-action`** (the official GitHub Action) | **Rejected** | The repository is **archived** (`archived: true`, last push 2024-04-09). Depending on an archived action for a security gate is a supply-chain liability. The CLI route adds no credential and is fully pinnable, so nothing is lost. |
| **PHP-native static security analysis** (e.g. taint analysis via the existing static-analysis ecosystem) | **Not adopted now** | It would add a second full static-analysis engine for overlapping PHP coverage, needs a project bootstrap for the ZEF source tree that does not exist yet, and the baseline is currently small enough that a dedicated engine cannot be justified. Revisit if first-party PHP source grows substantially and Semgrep's dataflow coverage proves insufficient. |
| **Custom Semgrep rules** | **Deferred, supported** | Worth writing for ZEF-specific security boundaries (module registration, security policy bootstrap). Deferred because at baseline there is not yet a second-party codebase to model, and hand-written rules carry their own false-negative risk. The pinned ruleset is a plain directory, so repository-local rules can be added later through the same `--config` mechanism. |
| **Adding Syft / a second SBOM tool** | **Rejected** | Anchore already produces the SBOM. Adding a tool to obtain a *primitive* that is orthogonal to source analysis would be duplication. |

### Licensing

The ruleset comes from `semgrep/semgrep-rules`, licensed under the **Semgrep Rules
License v1.0**: a non-exclusive, royalty-free, worldwide licence to use the rules
for **your own internal business purposes**; it does **not** permit distributing
the rules or making them available to others as a service. Running them inside
this repository's own CI is an internal use and is within the licence. The rules
are **not** vendored into the repository and are **not** redistributed — the
workflow downloads them from upstream at a pinned commit at run time. If this
repository ever exposes rules as a product or service, this decision must be
revisited.

---

## 3. Pinned analysis inputs

Every input is pinned and independently verifiable. **No value here comes from
memory**; each was resolved and cross-checked against its upstream source.

| Input | Pinned value | How it was verified |
|---|---|---|
| Semgrep engine | `semgrep/semgrep` image at `1.177.0`, digest `sha256:acaac22f…d81198` | Resolved through the Docker registry v2 manifest for the tag (anonymous pull token), read from the `Docker-Content-Digest` response header. `sha256:acaac22f…d81198` is the tag's digest, confirmed through two independent lookups. |
| Ruleset commit | `semgrep/semgrep-rules@40b8c63f75dc7c22c8a77482d73bfb864b146f7e` | Default branch (`develop`) head resolved through the GitHub API. |
| Ruleset tarball | `sha256:b7e483ab…ec4919` | The codeload tarball for that exact commit was downloaded **twice**; both downloads produced the identical digest, so the artefact is reproducible. The workflow re-checks this digest and **fails closed** if it does not match. |
| `actions/checkout` | `3d3c42e5…3ba90b1` (`v7.0.1`) | Annotated tag peeled through the GitHub API and cross-checked against the repository tag list. Same pin as the repository's existing workflows. |
| `github/codeql-action/upload-sarif` | `1c5b6756…e09fd` (`v4.38.1`) | Annotated tag peeled through the GitHub API, then the peeled commit cross-matched to its tag name in the tag list. |

All `uses:` in this workflow resolve to 40-character commit SHAs. No tag, branch
or `latest` reference is used as a security boundary.

**ZEF-local rules (additive, not a replacement).** `.github/semgrep/rules` is a
repository-owned rules directory passed to every scan as a fourth `--config`. It
exists because the pinned ruleset has a **measured false negative** on the
backslash-qualified call form: `pattern: unlink(...)` does not match `\unlink(...)`
nor `@\unlink(...)`, so four live call sites in `tests/` produced no finding at all
(§7.3). The pinned commit and its digest are unchanged; the local directory only
**adds** detection width, and no engine or ruleset pin moved.

**A second ZEF-local rule closes the whole *class* of that miss.** The qualified-call
false negative is not specific to `unlink`: the same call-form matrix measured **19**
functions covered by pinned rules that a backslash-qualified call hides. Rather than
add nineteen per-rule companions, `.github/semgrep/rules/php-lang-security/ban-qualified-global-call.yaml`
bans the qualified *writing style* for every function a pinned rule matches by name,
so the pinned rules can see code written in that style. The nineteen call sites were
then normalised to the bare form, which is equivalent at runtime inside a namespace
and is proved safe here (§7.4).

**A ruleset overlay resolves a duplicated rule id.** The pinned ruleset declares the
id `tainted-exec` twice at two different severities, in two directories the workflow
loads. The workflow builds a small overlay from the *verified* tarball (its `sha256`
check is unchanged) and replaces exactly that one file with the in-tree merged
definition, so the id resolves at a single severity with the **union** of both pinned
pattern sets. The pin therefore stays authoritative and no detection is narrowed;
details and measurements in §7.4.

**The correctness family is loaded too (v2.34.0).** All four scan lanes additionally
pass `--config=/rules-overlay/php/lang/correctness` — the bug-detector sibling tree
inside the SAME pinned, digest-verified tarball (`empty-with-boolean-expression`,
severity WARNING), so the pinning surface did not grow by one byte. This is matrix
row 3 of `docs/security/stub-prescan.md`: the Semgrep correctness family is INCLUDED
for the production trees and the tests/ fixture corpus alike, mirroring what the
security family already does. Measured before enabling, with the pinned ruleset run
locally over the full targets: **0 findings over 652 production targets and 0 over
190 corpus targets** — the promotion to blocking shipped with an empty, hence fully
triaged, population. The four pre-existing lanes, their thresholds and their
suppressions are otherwise unchanged; only detection width increased.

One behaviour of the tool matters for maintaining this: Semgrep **namespaces rule
ids by configuration path**, so the local rule reports as
`github.semgrep.rules.php-lang-security.unlink-use-qualified` rather than as
`unlink-use-qualified`. A `#nosemgrep` marker, however, is matched against the
rule's **own** id, not against that namespaced `check_id` — measured in §7.3 with
the exact path form the workflow uses.

**A normalization step sits between the scans and the upload (issue #61).** Two
measured behaviours of the pinned engine made every code-scanning alert this
workflow ever published defective:

* **Container-absolute paths.** The scans run in a container with the workspace
  mounted at `/src`, and Semgrep writes the target path it was given into every
  `artifactLocation.uri` (`/src/src/…`, `/src/tests/…`). Code scanning resolves
  uris against the repository root, where those paths do not exist, so every
  alert pointed at a file that is not in the repository: the link 404'd, and an
  alert that cannot be attributed to a real location can never be recognised as
  fixed.
* **Suppressed findings are still results.** A `# nosemgrep` marker neutralises
  the exit code of `--error` — that is what keeps the blocking gate green — but
  the finding is still written to the SARIF, carrying
  `suppressions: [{"kind": "inSource"}]`. Code scanning does not treat that field
  as a disposition: it opens an alert for every such result anyway. Measured on
  `main` @ `c938edd` and reproduced locally with the same pinned inputs: the four
  categories together uploaded **61 results, all 61 carrying an accepted
  suppression** (the entire §7 register), and the alert population was exactly
  that set — 31 open, 30 dismissed to unblock PR #58 — feeding the
  `code_scanning` ruleset (`alerts_threshold: all`) a permanent, un-actionable
  count on every pull request.

The step rewrites each SARIF into the `*.published.sarif` copy the upload steps
consume: every uri carrying the `/src` container prefix becomes
repository-relative (value-wide: result locations and run-level artifacts
alike), and results with a non-empty `suppressions` array are dropped. The
published population is therefore **exactly the population the exit-code gate
acts on** — undispositioned findings. The suppressed population stays governed
by the inline markers and the §7 register, which remain the in-tree,
line-accurate audit trail; they are no longer duplicated into code scanning as
open alerts. The scan invocations, the pinned ruleset, the severity floors and
the blocking thresholds are unchanged: no rule is disabled, no path is excluded,
no finding is left unexamined.

---

## 4. Scope

| Path set | Analysed | Effect |
|---|---|---|
| `src/**/*.php` (production / runtime source), `ERROR` severity | yes | **Blocking** — the job fails |
| `src/**/*.php` (production / runtime source), `WARNING` severity | yes | **Blocking** — the job fails (promoted 2026-09-24) |
| `tests/**/*.php`, `tools/**/*.php`, `scripts/**/*.php`, `WARNING` severity | yes | **Blocking** — the job fails (promoted 2026-09-24) |
| `tests/**/*.php`, `tools/**/*.php`, `scripts/**/*.php`, `ERROR` severity | yes | **Blocking** — the job fails (coverage hole closed 2026-09-24) |

**Why test and tooling code is scanned *and* blocks.** The alternative ("it is not
production, so skip it") is not accepted here: test and CI helper code runs with
repository credentials and on developer machines, so a dangerous pattern there has
real impact, and excluding whole trees is exactly how a scanner becomes theatre.
Leaving the tree analysed but silenced fails the other way too — it produces
alerts nobody is obliged to act on.

That tree was report-only at first for a *sequencing* reason, not a policy one: it
carried 30 un-triaged findings, and a first-run red gate teaches reviewers to
bypass the gate. The pass shipped report-only, its population was triaged and
registered (§7.2), and only then was `--error` added — the same sequence used for
the production `WARNING` floor in §5. Neither tree was made green by *ignoring* a
finding: both were made green by *dispositioning* it in writing.

**Why `src/` blocks.** The baseline `ERROR`-severity scan of `src/**` on `main`
produced **zero** findings (section 6), so the blocking threshold introduces no
false-positive friction today and guarantees the gate cannot silently degrade. The
`WARNING` floor over the same tree produced three findings, and those were triaged
and registered before the `WARNING` floor was itself made blocking — a blocking floor
is only defensible once its population is dispositioned, otherwise the gate colours
red for reasons nobody has decided about.

**Excluded paths** are listed explicitly in the committed `.semgrepignore` (`.git/`,
`vendor/`, `build/`, `dist/`, `node_modules/`, tool caches). Note the interaction
this file has with the tool: committing it **replaces** Semgrep's built-in default
ignore list, so `tests/` — which the default list would silently skip — is only
analysed because the file lists exclusions explicitly and does not list `tests/`.
That behaviour was verified empirically, not assumed.

---

## 5. Severity and blocking policy

| Severity | Policy |
|---|---|
| `ERROR` in `src/**` | **Blocks the pull request.** Semgrep is invoked with `--severity ERROR --error`; any `ERROR` finding makes the job fail. Error-level PHP rules in the pinned ruleset cover the injection classes where a false negative is most damaging (code injection via dynamic evaluation, command execution, SSRF). |
| `WARNING`, in `src/**` | **Blocks the pull request** (promoted 2026-09-24). A dedicated pass (*Scan production PHP source at WARNING severity*) scans production source at `--severity WARNING --error`, so warning-level rules — including the taint rules whose confidence metadata is `MEDIUM CONFIDENCE` — are looked for in production code *and* can fail the job. Before that pass existed, `src/**` was evaluated at `ERROR` only, so a whole severity band was invisible in production *by construction*: a zero count under `ERROR` never meant "no warning-level match". The pass shipped report-only for one change (PR #41) so its findings could be triaged first; that triage is in §7, and promotion followed it. |
| `WARNING`, in `tests/**`, `scripts/**`, `tools/**` | **Blocks the pull request** (promoted 2026-09-24). A dedicated pass (*Scan tests and tooling PHP*) scans these trees at `--severity WARNING --error`. All 30 findings it produced were triaged and registered in §7.2 **before** the promotion; §4 explains why the ordering matters. |
| `ERROR`, in `tests/**`, `scripts/**`, `tools/**` | **Blocks the pull request** (added 2026-09-24). A dedicated pass (*Scan tests and tooling PHP at ERROR severity*) scans these trees at `--severity ERROR --error`. Until then this combination was evaluated by **no pass at all**, so an `ERROR`-severity rule whose subject appeared only in test or tooling code was structurally invisible to the gate. The four findings it reports were triaged and registered in §7.5 **before** the pass was added. |
| `INFO` | Not reported (`--severity WARNING` floors the reporting scan). |

**Policy shape.** The gate fails on high-severity findings only, and never because
a scan *ran*. Crucially, the gate is **not** made green by ignoring findings: no
rule is disabled, there is no blanket path exclusion, and there is no `|| true` on
the blocking scan step. There **are** sixty-five in-source `#nosemgrep`
suppressions — five on `eval()`, fifty-three on `unlink()`, six on `exec()`, one on
`unserialize()`; they are accepted, justified and registered in sections 7.1 to 7.5.
This document previously stated that none existed — that was wrong, and is corrected
there.

**Four-pass policy — every floor is blocking as of 2026-09-24 (owner decision).**
The `WARNING` floor over `src/**` is evaluated in its own pass and supplies
`--error`, exactly as the `ERROR` floor does; the `WARNING` floor over `tests/`,
`scripts/` and `tools/` supplies `--error` as well, and so now does the `ERROR`
floor over those same trees. **There is no report-only surface left.** What
differs between the four passes is only *what is scanned*, never *whether a
finding blocks*.

The promotion was **sequenced, not bundled**. The pass was introduced report-only
(PR #41) so that its findings would be *visible* without turning an untriaged
population into a red gate, and promotion followed only once the three findings it
revealed had been dispositioned in writing (§7). An untriaged red gate is how
reviewers learn to bypass a gate, so the ordering matters more than the one-line
change.

The change is deliberately visible in the diff and carries its own rationale. The
`ERROR` step is byte-identical to before, no rule was disabled, no path was
excluded, and no finding was left unexamined: detection width increased *and*
blocking width increased; nothing was relaxed.

---

## 6. Baseline findings

Re-measured on `main` @ `87e2ba94e21b4f22b911ab5e359dad4f03c39416` (2026-09-24) with
the pinned ruleset and engine, by reproducing this workflow's exact invocation
locally rather than by copying a previous figure. The numbers published in this
section earlier (a single file per tree, 0 findings everywhere) described an early
three-file baseline and were stale by roughly two orders of magnitude in file
count; they are corrected here.

Pinned ruleset inventory: **36** rule files under `php/lang/security` (23
top-level + 10 in `injection/` + 3 in `audit/`). Rules actually evaluated: **20**
under the `ERROR` floor, **17** under the `WARNING` floor — the 16 pinned
WARNING-severity rules plus the one ZEF-local rule added on 2026-09-24 (§3).

The `WARNING`-floor figure including the local rule is **17**, confirmed by the
engine itself (`Ran 17 rules on 90 files`). The figures below were captured before
the local rule existed, so the *pinned-ruleset* columns remain the comparison
baseline; the local rule adds findings only under its own id, and the one addition
is recorded in §7.3.

| Pass | Targets | Findings | Blocking |
|---|---|---|---|
| `src/**`, `ERROR` floor | 360 files | **0** | yes (`--error`) |
| `src/**`, `WARNING` floor | 360 files | **3** | yes (`--error`, promoted 2026-09-24) |
| `tests/**` + `scripts/**`, `WARNING` floor | 97 files | 30 | yes (`--error`, promoted 2026-09-24) |
| `tests/**` + `scripts/**`, `WARNING` floor **after** the §7.2 triage | 97 files | 30 registered (exit `0`) | yes (`--error`) |

**Re-measured once more on `main` @ `5bc88c3` (2026-09-24), which is the figure that
supersedes the two rows above.** After the whole-class qualified-call fix (§7.4) and
the normalisation of the nineteen call sites, all three blocking passes report **zero
findings and exit `0`**: `src/` at the `ERROR` floor (360 files), `src/` at the
`WARNING` floor (360 files), and `tests/`+`scripts/`+`tools/` at the `WARNING` floor
(97 files). The `src/` `WARNING` row's "3 findings" no longer holds: those three calls
were rewritten out of the qualified form, so the sibling `unlink-use-qualified` rule now
matches nothing on this tree and the pinned `unlink-use` rule takes over their
disposition (§7.4). Nothing was deleted to make this true — the detection width went
**up** (a rule that flagged 0 calls of this class now flags the class), and each of the
three blocked passes was re-verified with `--error`, plus a negative control proving the
suppression markers still carry the exit code.

Re-measured again on `main` @ `2ed883e` after the promotion: the same three
`WARNING` findings, all `php.lang.security.unlink-use`, on best-effort cleanup of a
temporary file this code named itself on the failure path of an atomic `rename()`:
`src/Adapters/Http/UploadedFile.php:136`, `src/Adapters/Router/RouteCache.php:48`,
`src/Application/Container/Autowiring/AutowireAotCompiler.php:104`. (The line
numbers move from the PR #41 figures because the register comments added in §7 sit
above each call.)

Those three were **triaged before the pass was promoted**, not after: they are
accepted suppressions, registered and justified in §7, with the argument for
suppression-over-rewrite measured in §7.1. The `ERROR` floor remains 0 findings.

`tests/**`, `scripts/**` and `tools/**` were **still report-only at this
measurement**. They were promoted to blocking later the same day, after their own
population had been dispositioned in writing; that re-measurement and its register
are in §7.2.

No baseline file and no rule-level suppression was created for this change.
Nothing is hidden to make the gate green, and — the other direction — nothing that
was visible before became invisible: the blocking `ERROR` pass is the same
invocation it was before this change.

**Detection was proven, not assumed.** Composing rules that find nothing proves
nothing, so detection was validated against a **synthetic, out-of-repository
fixture** containing six deliberately unsafe constructs (through the shell and
dynamic-evaluation constructs, cookie-driven deserialisation, request-controlled
file access, request-controlled redirect). The pinned ruleset reported **7 findings
across 5 distinct rules** and the exit code moved from `0` to `1` with `--error`.
No insecure code was ever placed in `src/` — the fixture lived outside the
repository and was never committed.

**Exit-code contract (measured, not assumed).** `0` = clean; `1` = findings present
**and** `--error` supplied; `2` = internal error; `7` = configuration could not be
loaded (e.g. a pinned ruleset failed to fetch). The last one matters: a broken
pin turns the job red instead of producing a silent zero-finding pass.

**Runtime.** ~2 s locally for the three baseline files; ~8 s for a full multi-config
invocation including registry fetch. On a runner, the dominant cost is fetching the
pinned ruleset and the engine image.

---

## 7. Suppression policy

**Registered suppressions: 88**, counted from the marker directives themselves —
marker directives **on call lines only**, i.e. occurrences of
`grep -rn 'nosemgrep:' --include=*.php .` whose line is not comment-only
(the raw count including prose mentions is 89 on the tree carrying this revision;
exactly one occurrence, the explanatory prose above `TinkerSession.php:70`, is
comment-only and is not a marker). This revision recounts the whole table because
the previous register (85) had gone stale again: the LocalStorage symlink
containment fix (PR #149) landed two markers without promoting the count, and
the outbox-lease branch (PR #178) registers one more below. The two newest
markers — the first ever for `ban-qualified-global-call` — come from
`tests/Unit/kernel-mid-shadow-functions.php` (issue #90 round 4):

| Rule id on the marker | Count |
|---|---|
| `php.lang.security.unlink-use` | 70 |
| `exec-use` | 7 |
| `unlink-use` (ZEF-local scope) | 4 |
| `php.lang.security.eval-use` | 2 |
| `eval-use` | 2 |
| `ban-qualified-global-call` (ZEF-local scope) | 2 |
| `php.lang.security.unserialize-use` | 1 |
| **Total** | **88** |

That is **74 `unlink` + 7 `exec` + 4 `eval` + 1 `unserialize` + 2 recursion-forced
qualified `header` delegations**. The four newest
`unlink-use` markers live in `tests/Unit/MiddlewareMutationDebtTest.php` (issue
#90 round 1): each unlinks a `tempnam()` fixture the test itself created for
asserting on `error_log` output — no request input reaches the path. The two
`ban-qualified-global-call` markers live in
`tests/Unit/kernel-mid-shadow-functions.php` (issue #90 round 4) and are the first
suppressions of that rule: the namespace shadow `Zef\Framework\header()` exists
precisely so ResponseEmitter's unqualified SAPI calls can be observed by tests, and
its own delegating calls **must** stay backslash-qualified — the bare form inside
the shadow would recurse into itself. Unlike the nineteen call sites the rule
normalised to the bare form (safe because no same-named function exists in their
namespaces), a rewrite is impossible here; the arguments are pass-through values
already produced by the code under test, never request input. The count is of
marker directives on call lines: an explanatory comment that merely *mentions* a
marker, such as the prose line above `TinkerSession.php:70`, is not one. One earlier
accounting in this document reached 47 by adding four to a 43 that was itself
derived rather than counted; the table above is the count, and it is reproducible
with the command shown. The 61 in the previous revision had gone stale in the other
direction — it predated the `ConfigCompiler` marker registered with v2.21.0 (row 25)
by one; the v2.23.0 branch adds rows 48–49 on top. Five of the `unlink-use` entries
are in production source (§7.1, row 25, row 49); the single `unserialize-use` entry
(row 48) is production source as well; the rest are in test and tooling code (§7.2,
§7.4); the
four qualified-call entries formerly registered under `unlink-use-qualified` are
**superseded** — their call sites were normalised to the bare form and now carry
`unlink-use` markers (§7.4). This section previously stated that nothing was
suppressed; that was wrong, and the register below is the correction. Under rule 4,
the accepted list is reviewable here as a whole rather than scattered across source
files.

The three `unlink-use` entries were added when the `WARNING` pass over production
source was promoted to blocking, and the order matters: the pass first reported
these three (`report-only`, PR #41), they were then triaged, and only then did the
pass become merge-blocking. The suppression is what made a *green* blocking gate
possible without ignoring a finding.

| # | Rule | Location | Reason | Lifetime |
|---|---|---|---|---|
| 1 | `php.lang.security.eval-use` | `src/Adapters/Runtime/TinkerSession.php:68-70` | The `bin/zef tinker` REPL evaluates developer-typed code **by design**. Its caller (`bin/zef`, `zef_tinker()`) refuses to start when `ZEF_ENV=production` unless `--force` is passed, so the input is neither remote nor untrusted; the class itself performs no I/O. Removing `eval()` would remove the feature. | **Permanent** — the feature *is* `eval` |
| 2 | `php.lang.security.eval-use` | `src/Application/Container/Autowiring/AutowireAotCompiler.php:167` | `evalFactory()` evaluates **code this compiler generated itself** one step earlier (`generateFactory()` returns a `static fn` expression assembled from `var_export`'d scalars, a `\`-prefixed class name, and integer dependency placeholders — no caller-supplied payload reaches the string). It is the compile-time pattern Symfony's DI container dump uses, and the resulting closure is what makes reflection-free cold start possible. The suppression is **load-bearing**: the CI invocation over `src/**` exits `1` ("2 findings (2 blocking)") without it, measured on the same ruleset. | **Permanent** — the pattern is the feature |
| 3 | `php.lang.security.unlink-use` | `src/Adapters/Http/UploadedFile.php:136` | Best-effort cleanup of `$targetPath . '.zef-tmp-' . bin2hex(random_bytes(8))` — a name this method generated itself at line 66. No request input reaches the argument, and the call runs only on the failure path (`if (!$success)`), after the atomic `rename()` to the caller's destination has already failed; it can therefore only remove a partially written temp file of this method's own making. | **Permanent** — no rewrite can satisfy the rule (§7.1) |
| 4 | `php.lang.security.unlink-use` | `src/Adapters/Router/RouteCache.php:48` | Same shape: cleanup of `$path . '.' . bin2hex(random_bytes(6)) . '.tmp'` (line 39), a self-named temp sibling of the cache file, on the branch where `rename($tmp, $path)` has already returned false. Not reachable with request-controlled input. | **Permanent** — no rewrite can satisfy the rule (§7.1) |
| 5 | `php.lang.security.unlink-use` | `src/Application/Container/Autowiring/AutowireAotCompiler.php:104` | Same shape: cleanup of `$path . '.tmp.' . getmypid()` (line 95), a self-named temp sibling of the AOT export, on the branch where `rename($tmp, $path)` has already returned false. Reached from the compile-time CLI path, never from a request. | **Permanent** — no rewrite can satisfy the rule (§7.1) |
| 48 | `php.lang.security.unserialize-use` | `src/Infrastructure/Config/RadixTreeCache.php:124` | The blob is a cache file this class itself wrote through the atomic `rename()` + `0600` contract (`write()`, line 148); the path is operator-configured exactly like `ConfigCompiler`'s export target, never request input. `allowed_classes` is closed over exactly `ConfigRadixTree` and `ConfigRadixNode` — both `final readonly` data carriers with **no magic methods** (no `__wakeup`, `__destruct`, `__toString`, `__call`), so the object-injection gadget chain the rule guards against cannot start. The worst a tampered file achieves is a wrong-but-typed tree that still has to survive the version stamp, the SHA-256 value fingerprint and the `instanceof ConfigRadixTree` check (lines 131–137), and a corrupt payload is a soft miss (`@unserialize` + `catch (\Throwable)` → rebuild). The rule matches on argument shape (`pattern: unserialize(...)` minus a literal first argument), never on provenance — the same measured false-positive mechanism as §7.1/§7.5. Issue #60 P2 specifies the stdlib `serialize()`/`unserialize()` envelope precisely because it is OPcache-neutral; the only unflagged call form is `unserialize("literal")`, which cannot read a file. | **Permanent** — the bounded deserializer is the feature (issue #60 P2) |
| 49 | `php.lang.security.unlink-use` | `src/Infrastructure/Config/RadixTreeCache.php:176` | Same shape as rows 3–5 and 25: best-effort cleanup of `'.' . $basename . '.' . bin2hex(random_bytes(6)) . '.tmp'` (line 165) — a name this method generated itself — on the branch where `rename($tmp, $cacheFile)` has already returned false. No request input reaches the argument. Added with v2.23.0 (issue #60 P2). | **Permanent** — no rewrite can satisfy the rule (§7.1) |

Rows 48–49 were added when the v2.23.0 radix cache (issue #60 P2) tripped the
promoted `WARNING` floor on its first CI run: the gate reported exactly one
undispositioned finding — `unserialize-use` on the `RadixTreeCache` read path — and
the fix followed the registered sequence, not a gate change: provenance traced,
blast radius bounded (`allowed_classes` closed over two `final readonly` classes
with no magic methods), justification written, marker placed on the call line,
entries added to this register. No rule was disabled and no path excluded; the
same invocation now exits `0` with the finding still recorded in the emitted SARIF.

All sixty-five suppressions are `inSource` and scoped to a single line. None hides
a rule-class-wide exclusion, and none removes an evaluation that would otherwise be
counted.

That claim is **measured, not asserted**. Running the promoted invocation against
the tree with the suppressions in place exits `0` while the emitted SARIF still
contains every suppressed result — the marker neutralises the *exit code*, not the
*finding*. Strip the markers on a copy and the same invocation exits `1` with the
same results. So a suppression buys a green gate only by leaving the finding
recorded — which is why the register exists, and why every entry above carries its
reason inline at the call site.

**Where the suppressed findings live since the issue #61 fix.** Until 2026-09-25
those results were also uploaded to code scanning, where they opened alerts that
duplicated this register while feeding the `code_scanning` ruleset a permanent
population of un-actionable alerts (31 open at the measurement, none of them
pointing at a real file — see §3). The upload path now drops results with an
accepted suppression and publishes only undispositioned findings, with
repository-relative paths. The register and the inline markers are the audit
trail; code scanning shows only what a reviewer can still act on. The three
`unlink-use` entries are **not** a change of behaviour —
they document calls that already existed and were already flagged; what changed is
that they are now recorded and reviewable in one place.

### 7.1 Why the three `unlink-use` findings are suppressed rather than fixed

Suppression was chosen over a rewrite on measurement, not preference. The rule is
`pattern: unlink(...)` minus `pattern-not: unlink("...",...)` — it matches **every**
`unlink()` whose argument is not a bare string literal, irrespective of provenance.
Removing the `@` or the enclosing `if` changes nothing.

Measured against the pinned ruleset, on a synthetic probe **outside the repository**
(no insecure code was ever placed in `src/`):

| Call form | Matched by `unlink-use`? |
|---|---|
| `unlink('/tmp/known-file')` — literal | no |
| `unlink($tmp)` | **yes** |
| `unlink(realpath($tmp))` — hardened | **yes** |
| `unlink(dirname($tmp) . '/' . basename($tmp))` — hardened | **yes** |
| `unlink((string) $tmp)` | **yes** |
| `unlink(sprintf('%s', $tmp))` | **yes** |

So a "fix" that adds path hardening would still be flagged, and the only forms that
clear the rule are worse: inline a literal path (wrong — the name is computed per
call), or express the removal as something other than `unlink()`. The calls are also
not reachable with attacker-controlled input, and the rule's own metadata rates them
`confidence: LOW` / `likelihood: LOW`.

The honest characterisation is therefore: **a false positive on a low-risk cleanup
path, accepted in writing, with the suppression recorded in the register below and
the marker left at the call site.** Nothing was hidden to obtain a green gate, and
the rule stays enabled for every other `unlink()` in the tree.

### 7.2 The thirty `unlink-use` findings in test code

Added when the `tests/`, `scripts/` and `tools/` pass was promoted to blocking.
Same problem class as §7.1, same conclusion, but **dispositioned individually
rather than in bulk** — "30 findings, all in tests, suppress them all" would be a
blanket decision dressed up as a triage. Each call site was read, its argument's
provenance was traced, and the whole tree was searched for request-derived input.

The measurement that matters: **0 of the 34 `unlink(` call sites in `tests/` can
have a request superglobal in scope.** Every argument traces to `tempnam()`,
`sys_get_temp_dir()`, a `bin2hex(random_bytes())`-suffixed name the test itself
computed, or an entry returned by `scandir()`/`glob()` over a directory the test
created. The rule — `pattern: unlink(...)` minus `pattern-not: unlink("...",...)` —
matches on *argument shape*, never on provenance, so it cannot distinguish these
from a genuinely request-driven delete; §7.1's measurement of that limitation
applies unchanged.

| # | File | Call sites | What it removes |
|---|---|---|---|
| 6 | `tests/Unit/EdgeMatrixF10ContainerTest.php` | 57, 79 | temp AOT file from `sys_get_temp_dir() . '/zef-aot-' . bin2hex(...)` |
| 7 | `tests/Unit/EdgeMatrixF6MixedTest.php` | 360 | `tempnam()` used as an `error_log` sink, after `file_get_contents()` |
| 8 | `tests/Unit/EdgeMatrixF7AotRadixTest.php` | 98, 120, 151, 175, 190, 200 | temp AOT fixtures in `finally` teardown |
| 9 | `tests/Unit/EdgeMatrixHttpTest.php` | 368, 390, 988 | `tempnam()` passed to `Stream`/`StreamFactory`, closed first |
| 10 | `tests/Unit/EdgeMatrixMakerGeneratorsTest.php` | 447 | a dirent from `scandir()` over the test's own temp dir |
| 11 | `tests/Unit/EdgeMatrixMakerTest.php` | 597 | same shape, in `rmRecursive()` teardown |
| 12 | `tests/Unit/EdgeMatrixRouterKernelTest.php` | 335, 353, 379 | `tempnam()` file converted to a dir; `glob()` over the test's own dir |
| 13 | `tests/Unit/EdgeMatrixRuntimeTest.php` | 341, 367, 668 | `tempnam()` `error_log` sink, after restoring `error_log` |
| 14 | `tests/Unit/HttpDeepTest.php` | 240, 251 | `tempnam()` target and its `-moved` sibling |
| 15 | `tests/Unit/HttpTest.php` | 66, 67 | `tempnam()` target and the `(string)`-cast temp path |
| 16 | `tests/Unit/KernelEdgeTest.php` | 454 | `tempnam()` `error_log` sink |
| 17 | `tests/Unit/ModulesPluginsTest.php` | 347 | `tempnam()` `error_log` sink |
| 18 | `tests/Unit/RuntimeEdgeTest.php` | 248 | `tempnam()` `error_log` sink |
| 19 | `tests/V2100EnterpriseSuite.php` | 450 | temp route-cache path from the suite's own `tempnam()` |
| 20 | `tests/V290AutowireSuite.php` | 300, 301 | `tempnam()` malformed-AOT fixture and the AOT export path |
| 21 | `unlink-use-qualified` (ZEF-local) | `tests/Unit/EdgeMatrixF10KernelTest.php:436`, `tests/Unit/EdgeMatrixF8ObsInfraTest.php:566`, `tests/Unit/MutationDeepHttpTest.php:81`, `tests/Unit/ObservabilityTest.php:666` | Four backslash-qualified `@\unlink()` teardowns that the pinned rule never matched — a ruleset **false negative**, closed on 2026-09-24 by a ZEF-local rule. Detail, measurement and negative control in §7.3. | **Permanent** — no rewrite can satisfy the rule (§7.1) |
| 22 | `tests/Unit/OpenApiAdaptersTest.php` | 87, 110, 111 | spec/Postman artefacts the CLI test itself named: `sys_get_temp_dir() . '/zef-openapi-test-' . uniqid('', true)` suffixes, deleted after `file_get_contents()` assertions pass |
| 23 | `tests/Unit/OpenApiInfectionSweepTest.php` | 910, 940, 958, 974, 992, 993 | spec/Postman artefacts and the suite's own `openapi.json`/`'0'` dirents in `finally` teardown, over a directory the test created via `mkdir()` |
| 24 | `tests/Unit/RuntimeSoakVariantsTest.php` | 160 | OTLP soak sink from `tempnam(sys_get_temp_dir(), 'zef-otlp-sink-')`, removed in `finally` teardown after the sink-file assertions |
| 50 | `tests/Unit/EventSourcingOutboxLeaseTest.php` | 441 | shared-sqlite `tempnam(sys_get_temp_dir(), 'zef-outbox-lease-')` database file, removed in `finally` teardown after the cross-connection claim-disjointness assertions |

**Lifetime: permanent** — §7.1 applies unchanged, no rewrite satisfies the rule.
Every entry is `inSource`, single-line, and carries its reason inline. Since the
issue #61 fix these findings are no longer re-published as code-scanning alerts
(§3); the inline markers and this register are the record.

Re-measured on the OpenAPI 3.1 branch (v2.20.0): the promotion of the
`tests/`/`scripts/`/`tools/` pass caught **9 new `php.lang.security.unlink-use`
sites** introduced with the package's own tests (entries 22–23). Same disposition
path as the original population: each call site was read, the argument traces to
a name the test itself computed (`sys_get_temp_dir()` + `uniqid()` suffix, or a
dirent of a directory the test created) — no request superglobal can be in scope
in a PHPUnit process — and each now carries its own inline marker. The gate was
reproduced locally with semgrep 1.177.0 and the pinned ruleset before pushing:
0 findings across all four scans.

Re-measured on the soak-variants branch (issue #37): the new OTLP soak test
introduced **1 new `php.lang.security.unlink-use` site** (entry 24) — the
`tempnam()` file-sink removed in `finally` teardown after the batch-export
assertions. Same disposition path: the argument traces to `tempnam()` under
`sys_get_temp_dir()` with a test-chosen prefix; no request superglobal can be in
scope in a PHPUnit process. The four scans were reproduced locally with the
pinned semgrep 1.177.0 + ruleset before pushing: 0 findings.

Re-measured on the outbox-lease branch (PR #178): the new shared-sqlite claim
test introduced **1 new `php.lang.security.unlink-use` site** (entry 50) — the
`tempnam()` database file removed in `finally` teardown after the
cross-connection claim-disjointness assertions. Same disposition path: the
argument traces to `tempnam()` under `sys_get_temp_dir()` with a test-chosen
prefix; no request superglobal can be in scope in a PHPUnit process.

### 7.4 The whole class of the qualified-call miss — closed, and the register after it

§7.3 closed the miss for `unlink` alone. A measured call-form matrix showed that was
**one instance of a class**: nineteen functions covered by pinned rules were hidden by
the same backslash-qualified writing style (`assert`, `exec`, `system`, `passthru`,
`popen`, `shell_exec`, `pcntl_exec`, `proc_open`, `unlink`, `eval`, `unserialize`,
`phpinfo`, `extract`, `trigger_error`, `ldap_bind`, `openssl_decrypt`,
`mb_ereg_replace`, `base_convert`, `header`, plus the weak-hash trio). The `@` operator
is transparent in every case measured — only the leading backslash hides the call.

**The fix is one rule, not nineteen.** `ban-qualified-global-call` (severity
`WARNING`) bans the qualified writing style for those functions, so the pinned rules
match the calls they were written to match. The nineteen real call sites were then
normalised to the bare form: **11** `\assert()`, **4** `\exec()` and **4** `@\unlink()`,
across six files in `tests/` — `tests/` already carried suppressions for the same
calls, so this swapped a workaround for the plain form rather than introducing a new
class of change.

**Bare form is equivalent and proved so.** Inside a namespace, PHP falls back to the
global function when no function of that name exists in the namespace; the repository
declares no such shadowing function and no `use function` import of those names
(measured), so the fallback is unconditional here. The touched suites were executed:
**129 tests, 527 assertions, OK**.

**Why a regex and not an AST pattern.** The AST route was measured and is impossible:
`pattern: $F(...)` with a `metavariable-regex` on `$F` matched **zero** qualified calls,
and the same capture without the backslash matched only bare calls — Semgrep's AST
matcher does not match a backslash-qualified call at all. This is the same technique
(and the same limitation) as the §7.3 arm.

**The text-reach cost is contained, not ignored.** `pattern-regex` is evaluated on
text, so `pattern-not-regex` arms for block comments and quoted strings cancel matches
that fall inside them — measured working. There is deliberately **no line-comment arm**:
a regex cannot distinguish `//` inside a quoted string from a real comment, and an arm
of that shape was measured to cancel a **real** call that merely followed a string
containing `//`. A hidden real call is a false negative, the worse direction for this
rule, so the bounded residual is: a line comment *mentioning* a qualified call is
reported. Same accepted limitation as §7.3.

**Register after this change: 43 entries.**

| # | Rule | Location | Reason | Lifetime |
|---|---|---|---|---|
| 22 | `php.lang.security.exec-use` | `tests/ZefCliContractTest.php:31, 45, 58` | `exec()` of a command this test builds from `escapeshellarg($php)` and the path to `bin/zef` — a fixed binary, no test- or request-controlled fragment. The normalisation to bare form is what made the pinned `ERROR` rule see these calls; without the marker the tests pass fails. | **Permanent** — the call is the test's subject |
| 23 | `php.lang.security.exec-use` | `tests/ZefCliDispatchTest.php:124` | Same shape: the suite's own helper runs `bin/zef` with `escapeshellarg`-quoted arguments. | **Permanent** — the call is the test's subject |
| 24 | `php.lang.security.unlink-use` | `tests/Unit/EdgeMatrixF10KernelTest.php:436`, `tests/Unit/EdgeMatrixF8ObsInfraTest.php:566`, `tests/Unit/MutationDeepHttpTest.php:81`, `tests/Unit/ObservabilityTest.php:666` | The four §7.3 sites after normalisation. Previously registered under the ZEF-local `unlink-use-qualified` id; now bare, so the **pinned** rule flags them and its id is the correct one. Those four §7.3 entries are **superseded by these**. | **Permanent** — §7.1 applies unchanged |

The ZEF-local `unlink-use-qualified` rule stays loaded even though it now matches
nothing on this tree. It is **kept rather than deleted** so that no rule is removed as
part of a change whose purpose was to widen detection; `ban-qualified-global-call`
covers the same class, so the duplication is inert (zero matches) rather than
load-bearing.

**Ruleset overlay — the duplicated `tainted-exec` id.** The pinned ruleset declares
that id twice at different severities, and Semgrep neither deduplicates an id across
config paths nor lets a later definition override an earlier one. Measured with the
pinned engine on a fixture outside the repository: with no `--severity` filter the same
sink line reports **twice**, once at `ERROR` and once at `WARNING`; with a filter, only
the copy matching that severity is reported. The two copies are also **not supersets**
— the `ERROR` copy alone adds the sink `pcntl_exec`, while the `WARNING` copy alone adds
the source `file_get_contents('php://input')`, the sinks `expect_popen`/backticks, and
the sanitizer `escapeshellcmd`. Picking either would therefore **narrow** detection.
The workflow consequently composes a derived copy of the *verified* tarball (its
`sha256` check is unchanged and still runs first) in which the **canonical** file is
replaced by the in-tree union definition and the divergent copy is **removed**:

| Path in the ruleset | Before | After |
|---|---|---|
| `php/lang/security/tainted-exec.yaml` | `ERROR`, 1 result | `ERROR`, 1 result — replaced by the in-tree union definition |
| `php/lang/security/injection/tainted-exec.yaml` | `WARNING`, 1 result | **removed from the composed tree** — 0 results |

This is a gate **strengthening** and is recorded as such: one id, one definition, one
severity, union pattern set. Measured on a tainted-`exec()` fixture outside the
repository, the sink span reported **three** results before (canonical `ERROR`,
injection `WARNING`, and the separate `exec-use` rule) and **two** after (one
`tainted-exec` at `ERROR`, plus that separate rule) — the ambiguity is gone and no
coverage was lost. Residual: `exec-use` still reports the same `exec()` sink under its
own independently pinned id, which is a distinct rule reporting a real finding rather
than an ambiguous duplicate. The composed tree is asserted to declare `tainted-exec`
exactly once, so neither a leftover copy nor a silently skipped overlay can pass.

The overlay rule file lives at `.github/semgrep/overlay/tainted-exec.yaml`, deliberately
**outside** `.github/semgrep/rules/` — that directory is itself a config input, so a rule
file inside it would be loaded directly *and* through the composed tree, reintroducing
the duplication the overlay exists to remove.

**Negative control.** The markers are load-bearing, not decorative. On a copy of the six
files with every `// nosemgrep:` marker stripped, the same CI-equivalent invocation
reports **4 active `unlink-use` findings at the `WARNING` floor and 4 active `exec-use`
findings at the `ERROR` floor**, exiting `1`; with the markers in place the same pass
exits `0`. Each invocation asserts that files were actually scanned, because an earlier
probe of this control returned zero findings simply because nothing had been scanned.

**Measured coverage gap, deliberately not closed here.** Enumerating the `ERROR` floor
over `tests/`, `scripts/` and `tools/` — a combination **no workflow pass evaluates** —
reports four findings that are pre-existing and un-triaged:
`tests/SelfTestBridgeTest.php:71` (`exec-use`), `tests/Unit/EdgeMatrixF10ContainerTest.php:116`
(`eval-use`), `tests/V2110RadixTreeSuite.php:390` (`eval-use`), `scripts/lint.php:52`
(`exec-use`). They were **left un-suppressed on purpose** at that point in time, for the
reason recorded above. **Closed 2026-09-24 — see §7.5.**

#### Former coverage gap — now closed

That gap was the last measured blind spot in this workflow and it is closed: an
`ERROR`-floor pass over `tests/`, `scripts/` and `tools/` now runs and blocks (§4, §5).
The four findings were triaged first and each one was **read, traced and dispositioned**
before the pass existed, because the whole point of recording the gap rather than
suppressing it was that a suppression on a finding no gate evaluates hides the finding
instead of dispositioning it. The register is §7.5.

#### Former false negative — now closed

This section previously recorded four call sites as a **known false negative** that
was deliberately left unpatched, on the correct reasoning that a suppression marker
on a call the rule does not flag is decoration rather than evidence. That standing
condition changed on 2026-09-24: the gap was closed by a ZEF-local rule instead of
being carried in a checklist, because leaving a *false negative* open is the more
dangerous half of a security gate to leave open. The four sites are now detected,
suppressed narrowly and registered in **§7.3**.

### 7.3 The four qualified-call findings — a rule false negative, closed

Section 7.2 formerly carried these four call sites as a known false negative, left
unpatched. Owner decision on 2026-09-24 closed it with a ZEF-local rule
(`.github/semgrep/rules/php-lang-security/unlink-use-qualified.yaml`), because an
open false negative is the more consequential half of a security gate to leave open.

**The gap, measured** against the pinned ruleset (semgrep 1.177.0, commit
`40b8c63f`) on a probe **outside this repository** — no insecure code was ever placed
in `src/`:

| Call form | Pinned rule | ZEF-local rule |
|---|---|---|
| `unlink($p)` | flagged | — |
| `@unlink($p)` | flagged | — |
| `unlink(realpath($p))` | flagged | — |
| `\unlink($p)` | **missed** | flagged |
| `@\unlink($p)` | **missed** | flagged |
| `@\unlink(realpath($p))` | **missed** | flagged |
| `unlink('/tmp/lit')`, `\unlink('/tmp/lit')` | — | not flagged (literal exemption) |
| `$o->unlink($p)`, `Foo::unlink($p)`, `\Zef\unlink($p)` | — | not flagged (method / qualified call) |

**Why a regex arm rather than a second AST pattern.** A qualified-only AST arm
matched nothing at all, inside or outside `pattern-either`
(`pattern: '\unlink(...)'` → 0 findings; `pattern: '\unlink($FILE, ...)'` → 0
findings). Adding the qualified arm *alongside* the unqualified one inside
`pattern-either` did match — but then double-reported every unqualified span,
because a second configuration path re-declares the same rule id and Semgrep does
not deduplicate a rule id across configurations (measured: 4 duplicate spans). The
local rule therefore uses `pattern-regex` under **its own id**, so it never competes
with the upstream definition.

**Cost of the regex form (measured, accepted).** `pattern-regex` is evaluated
against source *text*, not the AST, so it also matches the construct inside a line
comment, a block comment and a string literal. `pattern-not-regex` cannot cancel
that: a not-regex range that merely contains the match does not remove it
(measured). This is accepted because the arm runs under `--severity WARNING
--error`, so any match is a reviewable finding carrying its own rule id, and the
current tree contains exactly the four intended occurrences.

| # | Rule | Location | Reason | Lifetime |
|---|---|---|---|---|
| 21 | `unlink-use-qualified` | `tests/Unit/EdgeMatrixF10KernelTest.php:436` | Teardown of `$sink`, computed by the test itself at line 248 (`$buildDir . '/f10_otlp_sink_' . \uniqid('', true) . '.jsonl'`), in a `finally` block. No request input reaches the argument. | **Permanent** |
| 22 | `unlink-use-qualified` | `tests/Unit/EdgeMatrixF8ObsInfraTest.php:566` | Same teardown shape; `$sink` is computed by `runOtlpServerSession()` as `$buildDir . '/f8_otlp_sink_' . \uniqid('', true) . '.jsonl'`. | **Permanent** |
| 23 | `unlink-use-qualified` | `tests/Unit/MutationDeepHttpTest.php:81` | `$this->tmpFile` is this test's own `\tempnam(\sys_get_temp_dir(), 'zefmut')` (line 62), used as a simulated upload source; the call is additionally guarded by `\is_file()`. | **Permanent** |
| 24 | `unlink-use-qualified` | `tests/Unit/ObservabilityTest.php:666` | Same teardown shape; `$sink` from `runOtlpServerSession()` (`$buildDir . '/otlp_sink_' . \uniqid('', true) . '.jsonl'`). | **Permanent** |
| 25 | `php.lang.security.unlink-use` | `src/Application/Config/ConfigCompiler.php:58` | Best-effort cleanup of `'.' . $basename . '.' . bin2hex(random_bytes(6)) . '.tmp'` — a name this method generated itself at line 48. No request input reaches the argument, and the call runs only on the failure path (`if (!@rename($tmp, $targetFile))`), after the atomic `rename()` of the compiled config has already failed. Added with the v2.21.0 Configuration System; re-measured per §7.2 (58 markers). | **Permanent** |
| 26 | `php.lang.security.unlink-use` | `tests/Unit/ConfigV2SourcesLoaderTest.php:41` | Teardown of the test's own `sys_get_temp_dir() . '/zef-configv2-' . uniqid()` workspace; `$file` comes from `glob()` over that directory. Same shape as rows 21–24. v2.21.0. | **Permanent** |
| 27 | `php.lang.security.unlink-use` | `tests/Unit/ConfigV2MutationSweepTest.php:49` | Same teardown shape as row 26 (`zef-configv2sweep-` workspace). v2.21.0. | **Permanent** |
| 28 | `php.lang.security.unlink-use` | `tests/Unit/ConfigV2ApplicationTest.php:38` | Same teardown shape as row 26 (`zef-configv2app-` workspace, plus a nested secrets dir). v2.21.0. | **Permanent** |

**Verified behaviour** — CI-equivalent invocation, config path in the exact form the
workflow uses (`/src/.github/semgrep/rules`), `--severity WARNING --error`:

| Condition | Findings | Exit code |
|---|---|---|
| Markers present, `tests/` | 0 | **0** |
| Markers stripped on a copy outside the repository, same four files | **4** | **1** |
| `src/` (all three trees), markers present | 0 | **0** |

The negative control is the load-bearing half: it shows the new arm can actually
fail the job. The marker neutralises the exit code, not the finding.

If a finding must be suppressed in future, the rules are:

1. Never suppress at rule level, and never exclude a whole scanned tree to silence
   findings — that converts a gate into a formality.
2. Suppress at the narrowest possible **line or block**, inline, with the reason in
   the suppression comment.
3. Every suppression states: the rule, the location, the reason, and whether it is
   temporary (with a review date) or permanent.
4. Suppressions are recorded in this document, so the accepted list is reviewable
   as a whole rather than scattered across source files.
5. A suppression that is not justified in writing is not accepted.

---

### 7.5 The four `ERROR`-floor findings over test and tooling code — the coverage hole, closed

Added when the *Scan tests and tooling PHP at ERROR severity* pass was introduced.
Unlike §7.2 and §7.4 this was not a promotion of an existing report-only pass: the
combination had **never been evaluated by any pass**, so the findings below were
invisible by construction. The order therefore had to be reversed — the population was
triaged first and the blocking pass was added on top of a dispositioned set, because a
suppression written for a finding no gate evaluates would have hidden it rather than
dispositioned it.

**All four are false positives on the same measured mechanism.** Both rules match on
*argument shape*, never on provenance: `exec-use` is `metavariable-regex:
exec|passthru|proc_open|popen|shell_exec|system|pcntl_exec` minus
`$FUNC('...', ...)`, so **any** `exec()` with a non-literal first argument matches, and
`eval-use` is `pattern: eval(...)` minus `eval('...')`. Both rule files' own metadata
rates them `confidence: LOW` / `likelihood: LOW`.

| # | Rule | Location | Reason | Lifetime |
|---|---|---|---|---|
| 44 | `php.lang.security.exec-use` | `tests/SelfTestBridgeTest.php:71` | The test's stated subject is invoking `bin/zef` as a subprocess. `$cmd` is assembled on line 70 from `escapeshellarg(\PHP_BINARY)`, `escapeshellarg(__DIR__ . '/../bin/zef')` and `escapeshellarg($arg)`, where `$arg` is the class's own suite key (`'--self-test=' . $key` from the data provider). Every fragment is shell-quoted, two are fixed paths to a repository binary, and no request-derived value reaches it. | **Permanent** — the call is the test's subject |
| 45 | `php.lang.security.exec-use` | `scripts/lint.php:52` | The tool's stated subject is running `php -l` over the repository. `$php` is `\PHP_BINARY` (the running interpreter's own path) and `$file` is a relative path yielded by the `RecursiveIteratorIterator` walk defined above, wrapped in `escapeshellarg()`; `$root` is `dirname(__DIR__)`. No request-derived value reaches the command. | **Permanent** — the call is the tool's subject |
| 46 | `php.lang.security.eval-use` | `tests/Unit/EdgeMatrixF10ContainerTest.php:116` | `$code` is `$result->factoryCode[F10NullableDefaultConsumer::class]`, a factory expression produced one step earlier by `AutowireCompilerPass::process()` in this same test. The assertion is that the compiler's own output is callable — evaluating it **is** the assertion. | **Permanent** — evaluating the compiler's own output is the assertion |
| 47 | `php.lang.security.eval-use` | `tests/V2110RadixTreeSuite.php:390` | `$encoded` is built on line 389 as `'return ' . var_export($payload, true) . ';'` where `$payload = $tree->exportArray()` and `$tree = $this->sampleTree()` — both this suite's own fixture. The test round-trips its own AOT export; no external string is evaluated. | **Permanent** — evaluating the suite's own fixture is the assertion |

Each marker is `inSource`, single-line, names the rule and states its reason inline;
none is a path or rule-class exclusion. Since the issue #61 fix these findings are
registered here rather than duplicated into code scanning (§3). Two of the four (`#46`, `#47`) sit
on the same statement shape as the `eval-use` entries already accepted in §7.1/§7.2 —
evaluating code the codebase itself just produced — so this is an existing, reviewed
category rather than a new one.

**Verified behaviour** — CI-equivalent invocation, same config paths and pinned inputs,
`--severity ERROR --error`, over `tests/` + `scripts/`:

| Condition | Findings | Exit code |
|---|---|---|
| Markers present, `tests/` + `scripts/` | 0 | **0** |
| Markers stripped on a copy outside the repository | **4** | **1** |
| `src/` at the `ERROR` floor, markers present | 0 | **0** |
| `src/` at the `WARNING` floor, markers present | 0 | **0** |
| `tests/` + `scripts/` at the `WARNING` floor, markers present | 0 | **0** |

The second row is the load-bearing one: it shows the new pass can actually fail the job.
The marker neutralises the *exit code*, not the *finding*.

**Register total now stands at 48** (§7). §7.4's "Register after this change: 43
entries" remains as the figure for that change; the four entries above are additive to
it, and the four entries §7.4 added are themselves counted in the 48.

---

## 8. Trigger policy

| Event | Reason |
|---|---|
| `pull_request` (targeting `main`) | Findings must be caught **before** merge. Pull requests from forks are analysed normally: the workflow uses no repository secret and no write-capable token in the analysis path, and it does not use `pull_request_target`. |
| `push` (to `main`) | Catches findings that entered through a direct push or a bypassed check, and keeps the `main` branch's code-scanning state current. |
| `workflow_dispatch` | Manual re-run, used when triaging or after changing the pinned ruleset. |

`pull_request_target` is deliberately **not** used. It would run with a
write-capable token in a context a pull-request author can influence; there is no
security justification for that here.

---

## 9. Permissions, credentials and code scanning

| Item | Value | Justification |
|---|---|---|
| Workflow-level `permissions` | `contents: read` | Baseline for the whole workflow. The analysis only reads the checked-out tree. |
| Job `permissions` | `contents: read`, `security-events: write` | `security-events: write` is required **only** by `upload-sarif`, to publish SARIF into code scanning. The job cannot write to the repository. |
| Repository secret | **None** | Semgrep CE needs no token to run against local source, and this workflow does not log in to the Semgrep platform, so no `SEMGREP_APP_TOKEN` is configured or required. |
| Network access | Outbound to `codeload.github.com` (pinned ruleset) and the container registry (pinned engine image) | No source code is uploaded to any Semgrep service. Telemetry is disabled (`--metrics=off`) and version checks are disabled (`--disable-version-check`). |
| Artefacts | Four SARIF files in the runner temp directory (production-`ERROR`, production-`WARNING`, tooling-`WARNING`, tooling-`ERROR`), rewritten into normalized `*.published.sarif` copies and published to code scanning under four categories | The workspace is not archived and uploaded, and no credential is written to an artefact. |
| Fork behaviour | Analysis runs; the upload is the only privileged step, and a fork pull request receives a read-only token, so `security-events: write` is not granted | Upload is `continue-on-error` so a token-scope limitation cannot turn an otherwise clean analysis red. |

**Publication normalization (issue #61).** Between the scans and the uploads, one
step rewrites each SARIF into the `*.published.sarif` copy that is actually
uploaded: container-absolute `/src/…` uris become repository-relative, and
results carrying an accepted inline suppression are dropped (measured root cause
and mechanics in §3). Published alerts therefore map to real files, can be
auto-closed when their location disappears from a later analysis, and reflect
exactly the undispositioned population; the §7 register governs the suppressed
one. A failure of this step publishes nothing rather than re-publishing
un-normalized paths, and fails the job loudly instead — the one place where a
publication-side failure is allowed to colour the job red, because its silent
failure mode is precisely the alert population that blocked PR #58.

**Known weakness (accepted, owner decision).** The SARIF **upload** steps are
marked `continue-on-error: true`. The analysis itself still fails the job on a
blocking finding — but a failure *of the upload* (for example if code scanning is
unavailable, or on a fork pull request where `security-events: write` is not
granted) will not turn the run red. The trade-off is deliberate: it keeps the
security decision on the PHP analysis, where the merge-blocking evidence lives,
instead of on the availability of the code-scanning backend. The consequence is
that SARIF publication is best-effort — **the job log and exit code, not code
scanning, are the source of truth.** If the owner would rather have publication
itself be a hard gate, that is a one-line change (drop `continue-on-error`), and
it should be paired with confirming that code scanning accepts third-party SARIF
on this repository.

---

## 10. Local reproduction

```bash
# engine: any Docker host; ruleset: the pinned commit
RULES_SHA=40b8c63f75dc7c22c8a77482d73bfb864b146f7e
curl -sSL --fail -o rules.tar.gz \
  "https://codeload.github.com/semgrep/semgrep-rules/tar.gz/$RULES_SHA"
echo "b7e483abf001c405a3e908251ff66cb198a26702aff5fe4c5f0c4b2fffec4919  rules.tar.gz" \
  | sha256sum --check --strict
mkdir -p rules && tar -xzf rules.tar.gz -C rules --strip-components=1

# same invocation the workflow uses
docker run --rm -v "$PWD:/src:ro" -v "$PWD/rules:/rules:ro" \
  semgrep/semgrep@sha256:acaac22ffc7b7cc5926de0751b223bce0b2491c33d18422fa72f632c78d81198 \
  semgrep scan --config=/rules/php/lang/security \
    --metrics=off --disable-version-check --severity ERROR --error /src/src
```

A Docker-less local equivalent uses the same version from PyPI
(`pip install semgrep==1.177.0`) with `--config <extracted>/php/lang/security`.

---

## 11. Maintenance

- **Engine and ruleset upgrades are deliberate pull requests**, never automatic.
  An upgrade updates the pinned digest/commit in the workflow, re-runs the local
  reproduction, and records the new baseline in section 6 of this document.
- **When moving the pin, re-test the suppression markers.** A new ruleset version
  may rename a rule id (which silently un-suppresses every marker) or extend a rule
  to a construct it previously missed. §7.2's recorded false negative on the
  fully-qualified `\unlink()` form was a concrete instance of the latter; it is now
  closed by a local rule (§7.3), so a pin move is the moment to check whether the
  upstream rule has *also* fixed it — if it has, the local rule becomes redundant
  and should be removed rather than left to double-report.
- **Never replace the pin with a tag or `latest`.** `latest` as a security
  boundary means the analysis changes without review.
- **Revisit this decision** if first-party PHP source grows enough that a
  ZEF-specific ruleset becomes worthwhile (section 2), or if the Semgrep Rules
  Licence stops covering this repository's use.
