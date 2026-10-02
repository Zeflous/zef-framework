# Security Policy

This document states how a security problem in **ZEF Framework** (`Zeflous/zef-framework`)
is reported, what is in scope, and what the repository already enforces on every
change. It describes the project as it is; where a rule is aspirational rather than
enforced, it is marked as such.

> **Scope of this policy.** ZEF Framework is a **PHP library/application skeleton**.
> The maintainers do not operate a hosted service on your behalf. A vulnerability in
> *this repository* is a defect in the code, the build, or the CI supply chain — not
> an incident in a deployment you run. If you believe a deployment of ZEF is
> compromised, the operator of that deployment is the correct first contact.

## Supported versions

Security fixes are developed on `main` and released from there. There is no
long-term-support branch.

| Version line | Supported with security fixes |
|:--|:--|
| `main` — documented release **v2.35.0** | :white_check_mark: Yes |
| 2.29.x | :warning: Best effort — the fix lands on `main` first |
| 2.28.x and older | :x: No |

If you depend on an older line, the fix is available by upgrading. Version history
is recorded in [`docs/CHANGELOG-v2.35.0.md`](docs/CHANGELOG-v2.35.0.md) and the
earlier `docs/CHANGELOG-*.md` files.

## Reporting a vulnerability

**Report privately. Do not open a public issue, and do not open a pull request that
contains the exploit.**

This repository is configured for **GitHub private vulnerability reporting**. That is
the only reporting channel: no security email address is published for this project,
and none should be inferred.

1. Open the repository's **Security** tab and choose **Report a vulnerability**
   ([direct link](https://github.com/Zeflous/zef-framework/security/advisories/new)).
   This creates a private advisory visible only to you and the maintainers.
2. Include the details listed below.
3. The maintainers triage the report **in that advisory**. Discussion, remediation
   and the eventual disclosure all happen there.

### What to include

A report that can be reproduced is resolved far faster than one that cannot.

- The **affected revision** — a commit SHA, tag, or branch. `main` moves; a report
  without a revision cannot be mapped to code.
- The **entry point** — a route, CLI command, middleware, or class + method.
- A **minimal reproduction** — the smallest request, configuration, or code path
  that demonstrates the problem, with the exact commands you ran.
- The **impact** you believe it has, and the **preconditions** (authentication
  required? specific configuration? a hostile dependency or peer?).
- Any **suggested fix or mitigation**, if you have one.

Do not include live credentials, tokens, or private keys in a report. If a secret
must be shown to demonstrate the issue, show a redacted or throwaway value and say
what it stands for.

### Response targets

The project is maintained on a best-effort basis. The targets below describe good
faith, not a contractual SLA:

| Stage | Target |
|:--|:--|
| Acknowledge the report | within **3 business days** |
| Initial triage and severity assessment | within **10 business days** |
| Status update while a fix is in progress | at least every **14 days** |
| Fix for a confirmed **critical** issue | as soon as a reviewed change can be gated and released |

If a report is declined, the reason is recorded in the advisory. A declined report
is still read and answered.

## Scope

**In scope** — defects in this repository:

- `src/`, `app/`, `modules/`, `plugins/`, `autoload/` — framework and application code.
- Security-relevant runtime behaviour: request handling, URI/host parsing, session
  and cookie handling, CSRF and origin checks, rate limiting, output/error encoding,
  telemetry redaction, and anything that crosses a trust boundary.
- **Build and CI supply chain** — workflow definitions and pinned analysis inputs in
  `.github/`, including whether a gate can be bypassed, whether a job holds more
  authority than it needs, or whether a pinned ruleset can be substituted.
- Dependency declarations in `composer.json`, and configuration that ships to users.

**Out of scope**:

- Vulnerabilities in **third-party dependencies**. Report those to the upstream
  project; this repository consumes them. A defect in how ZEF *uses* a dependency is
  in scope.
- Findings that require the attacker to already hold local filesystem access,
  ability to edit the deployment, or control of the process environment.
- Missing hardening that is not a defect in this code — e.g. TLS termination,
  reverse-proxy limits, or network policy, which belong to the operator.
- Automated scanner output with no demonstrated impact. If a rule fires without an
  exploitable path, say so; unactionable reports are answered and closed.
- Denial of service by unbounded resource consumption with no amplification step.
- The demo application's lack of production-grade authentication: the sample routes
  (including `/toko*`) are **demonstrations**, not a security boundary, and are not
  intended to be deployed as an authenticated service.

## What this repository already enforces

These controls are declared in-tree and can be reviewed rather than trusted. The
workflow files are the reference; this section only points at them.

- **Static analysis (SAST).** `.github/workflows/php-sast.yml` runs Semgrep over the
  PHP sources and publishes SARIF to code scanning. It requires **no repository
  secret and no personal access token**, uses the `pull_request` trigger only, and
  grants no job write authority beyond `security-events: write`. Its pinned ruleset
  is **verified by sha256 before use**, and ZEF-local rules in `.github/semgrep/rules`
  close a measured false negative of the pinned set. Scope, severity policy and the
  known limits of this gate are documented in [`docs/security/php-sast.md`](docs/security/php-sast.md).
- **Cloud-side quality gate (SonarCloud).** `.github/workflows/sonarcloud.yml` runs
  SonarCloud analysis over `src/` and `tests/`, blocks on the server-side quality-gate
  verdict, and decorates pull requests once the ALM binding is configured. It requires
  the repository secret `SONAR_TOKEN` and skips visibly (notice + green, never silent)
  while that secret is absent. Setup, scope and the coverage bridge are documented in
  [`docs/security/sonarcloud.md`](docs/security/sonarcloud.md).
- **Code scanning and dependency review.** CodeQL analysis and a dependency-review
  gate run per pull request; a dependency SBOM workflow is present.
- **Secret scanning.** A `gitleaks` workflow scans for committed credentials, and
  contributors are required to confirm that no secret value appears in a diff, its
  description, or CI logs (`.github/pull_request_template.md`).
- **Protected `main`.** Merges into `main` are gated by required checks; the enforced
  set is recorded in [`docs/GOVERNANCE.md`](docs/GOVERNANCE.md).
- **Telemetry redaction.** Sensitive keys are filtered before telemetry is emitted;
  see the redaction notes in [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).
- **Credentials are never committed.** Runtime configuration is expressed as
  environment variables — **by name only**. No token, password or key value belongs
  in the repository, its documentation, its logs, or a pull request body.

## Disclosure

Confirmed issues are fixed on `main` and, where the report came through a private
advisory, published as a **GitHub Security Advisory** once a fix is available. The
report is credited unless you ask to remain anonymous. Please allow the fix to be
released before publishing your own write-up.

There is **no bug bounty** and no monetary reward for reports.
