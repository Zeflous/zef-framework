# SonarCloud gate

Cloud-side quality analysis for the PHP code in this repository, run by
`.github/workflows/sonarcloud.yml` and configured by `sonar-project.properties`
at the repository root.

This document records **what is analysed, by what, which verdict blocks a pull
request, what this gate explicitly is not, and the one-time setup it needs.** It
is the reference for the scope and policy decisions, so those decisions are
reviewable instead of implicit in a workflow file — same contract as
`docs/security/php-sast.md` for the Semgrep gate.

---

## 1. Position in the pipeline

The quality pipeline has two independent lanes, and a pull request must satisfy
both:

```
[ pull request opened ]
        |
        +---> CI lane (ci.yml): PHPStan max level, Deptrac, mutation testing,
        |     coverage gate, style, platform smoke. Proves the code WORKS and
        |     the architecture stays clean — locally, deterministically.
        |
        +---> Security/Review lane:
              - php-sast.yml  : Semgrep (pinned engine + ruleset, SARIF to
                                code scanning) — see docs/security/php-sast.md
              - snyk-security.yml : SCA / IaC (SARIF to code scanning)
              - sonarcloud.yml: SonarCloud — bugs, vulnerabilities, code
                                smells, duplications, security hotspots,
                                new-code conditions, with pull-request
                                decoration comments.
```

The lanes are deliberately redundant: Semgrep, Snyk Code, CodeQL and SonarCloud
use different engines with different blind spots. A finding any one of them
reports is actionable; a finding none of them reports is the only kind that
ships silently.

## 2. What this gate is

- **Cloud-side analysis** of `src/` (production) and `tests/` (test code —
  analysed on purpose: it runs with repository credentials on CI and on
  developer machines).
- **Blocking on the SonarCloud quality-gate verdict**: `sonar.qualitygate.wait=true`
  makes the scanner wait for the server-side computation; a red gate fails the
  job. The default "Sonar way" gate applies (new-code reliability, security,
  maintainability, duplications).
- **Pull-request decoration**: once the GitHub ALM binding is configured
  (section 3, step 4), SonarCloud posts inline comments and a quality summary
  on every pull request it analyses.
- **Zero secret until configured**: while `SONAR_TOKEN` does not exist the job
  is green **with a visible notice** (workflow step summary + `::notice::`),
  never red and never silently green.

## 3. Setup checklist (one-time, ~5 minutes)

The workflow ships fully wired; only the SonarCloud account side is manual:

1. Sign in at <https://sonarcloud.io> with the GitHub account that owns this
   repository (GitHub login → SonarCloud organization is created for the
   account on first use).
2. **Create project manually**: "Create project → Manually", pick the
   organization, set the project key to `mbetixz_zef-framework` (or note the
   key SonarCloud assigns and align `sonar-project.properties` in the same
   change). Choose **public project** — analysis of public projects is free.
3. **Disable Automatic Analysis**: Project Administration → Analysis Method →
   disable "Automatic Analysis". CI-based analysis (this workflow) and
   automatic analysis conflict; the scanner fails with an explicit error while
   both are enabled.
4. **Bind GitHub (ALM integration)**: Administration → ALM Integrations →
   GitHub → install the SonarCloud GitHub App on this repository. This enables
   pull-request decoration and branch links in the SonarCloud UI.
5. **Create a token** (My Account → Security → Generate Token, type "Global /
   User Token") and save it as the repository secret `SONAR_TOKEN`
   (Settings → Secrets and variables → Actions). The name is fixed by
   `.github/workflows/sonarcloud.yml`.
6. **Verify**: Actions tab → *SonarCloud* workflow → *Run workflow* (manual
   dispatch is enabled for exactly this). The first run analyses `main` and
   must end green.
7. **Promote to a required check** (recommended after the first green run):
   branch protection on `main`, add the required status-check context
   **`SonarCloud Scan`** — the job name is load-bearing and pinned by the
   workflow file. Until this step the gate informs but does not block merges.

## 4. Analysis inputs and supply chain

| Input | Value | Pinned how |
| --- | --- | --- |
| Scanner action | `SonarSource/sonarqube-scan-action` v8.2.2 | full commit SHA `ba9859eae8dd6bd29e412f25ddbbef3d032000f4` (repository policy: every action pinned to a full-length SHA) |
| Scanner engine | downloaded by the action from the pinned action's logic | version is a property of the pinned action commit; upgrades are deliberate pull requests |
| Analysis scope | `src/`, `tests/` | `sonar-project.properties`, reviewable in-tree |
| Quality gate | server-side "Sonar way" (new code) | SonarCloud project settings |

There is no local engine to pin because the analysis runs server-side; the
reviewable, immutable input is the action commit plus the properties file.
Engine upgrades happen by re-pinning the action SHA in a reviewed pull request,
mirroring how the Semgrep engine digest is upgraded in `php-sast.yml`.

## 5. What this gate explicitly is not

- **Not a replacement for PHPStan or Deptrac.** Those run at maximum locally
  pinned levels and block earlier, with repo-specific rules SonarCloud does not
  know (layer contracts, mutation score floors). SonarCloud adds an independent
  second opinion, not a substitute.
- **Not a SAST replacement.** Semgrep (pinned ruleset, fail-closed verification,
  `docs/security/php-sast.md`) stays the merge-blocking pattern-matching gate.
  SonarCloud security hotspots complement it with taint-flow heuristics that
  require human triage.
- **Not a coverage gate.** Coverage remains the CI lane's job (Xdebug driver,
  90% floor). See section 6 for the current bridge.
- **Not fork-authenticated.** Fork pull requests are skipped with a neutral
  check (secrets are withheld there anyway — same guard as `snyk-security.yml`).

## 6. Coverage bridge (v1) and follow-up

The main CI job produces PHPUnit coverage but does not export it; this lane
therefore sets `sonar.coverage.exclusions=**/*` so the default gate's
coverage-on-new-code condition does not fail on absent data. Bugs, code smells,
duplications, security hotspots and new-code reliability conditions remain
fully enforced. The deliberate follow-up is an artifact hand-off (CI uploads the
coverage XML; this workflow downloads it and points
`sonar.php.coverage.reportPaths` at it), after which the exclusion is removed
and the coverage condition becomes real. Until then the exclusion is the honest
statement of what this gate measures.

## 7. Failure modes and their meaning

| Symptom | Meaning |
| --- | --- |
| Red job, "quality gate failed" | SonarCloud verdict was red — triage the findings via the PR decoration or the project dashboard; fix the code or adjust the gate in SonarCloud (a reviewed decision, not a workflow edit) |
| Red job, "you are running CI analysis while Automatic Analysis is enabled" | section 3 step 3 was skipped |
| Red job, "project not found" / 401 | `sonar.projectKey` / `sonar.organization` / `SONAR_TOKEN` mismatch — section 3 |
| Green job with the skip notice | `SONAR_TOKEN` not set yet — section 3 step 5 |
| Skipped (neutral) check on a PR | fork pull request or `dependabot[bot]` author — by design |
