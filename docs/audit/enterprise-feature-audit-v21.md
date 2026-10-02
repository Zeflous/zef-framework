# Enterprise feature audit — v21

**Date:** 2026-10-02  
**Scope:** the nine areas requested in `docs/ROADMAP.md`; static code/contract
review plus targeted PHPUnit, self-test, PHPStan and Composer verification.
**Method:** Hexagonal boundary review (TOGAF application/data/technology), threat
review against OWASP input/resource-exhaustion controls, and ISO 27001 / COBIT
evidence review. Status is **VERIFIED** only where source and an automated check
were inspected; roadmap-only claims are **PLANNED**.

## Executive result

The framework has strong port/adapter separation, unusually broad automated
coverage, and mature primitives across router, security, async/CQRS, telemetry,
database transactions, queueing, HTTP, and validation. It is **not** feature
complete against its enterprise roadmap: the open integrations and data/platform
capabilities below must not be advertised as delivered. One DoS defect was
verified and remediated in this audit: recursive message payload pre-validation
could exhaust the PHP/Xdebug call stack before the JSON depth guard ran. The
serializer now uses bounded iterative traversal and has deep, cyclic, and wide
payload regression tests.

## Findings and disposition

| Area | Verified controls | Open issue / impact | Priority and disposition |
|---|---|---|---|
| Configuration | Source aggregation, schema validation, typed accessor, secrets ports, compiled config, metrics and cache are present. | Secret-provider availability and compiled-config freshness are deployment controls; no hosted secret-manager adapter is delivered. A stale secret/config can cause unsafe runtime policy. | P1: document deployment ownership; add Vault/KMS adapters only when an operational target is selected. |
| Router | Radix matching, constraints/ReDoS guard, 405, cache, host, locale, binding, content negotiation and route middleware execution are present. | No GraphQL/WebSocket/gRPC transport adapter. These are separate ingress planes, not a router bug. | P2: roadmap-managed; define protocol/auth/backpressure contracts before implementation. |
| Security | Auth/authz ports, rate limits, replay, CSRF, CORS, trusted host/proxy, headers, body limits, client-IP resolution, AES-GCM and rotation exist. | OAuth2/SAML/WebAuthn, ABAC/RLS, malware scanning and privacy-retention controls remain planned. Missing controls can make regulated or federated deployments non-compliant. | P0 for deployments requiring federation/regulatory scope: do not claim support; use a vetted identity provider at the edge. |
| CQRS & event-driven | Command/query buses, idempotency, event store, snapshots, projections, outbox and retry primitives are present. | Broker adapters, saga orchestration, event migration/version tooling and exactly-once/order guarantees are not delivered. The prior recursive serializer was a worker-availability DoS; **fixed**. | P0 fixed; P1: use outbox + idempotent consumers and explicitly document at-least-once semantics. |
| Observability | Trace context, spans, OTLP, metrics, structured logging/redaction, Prometheus and health endpoints exist. | No APM vendor adapters, sampling/baggage, slow-query/N+1 tooling, alerting or incident integrations. This raises MTTR under high traffic. | P1: set SLOs, alert rules and trace sampling policy outside the library before production scale-out. |
| Database | Query builder, migration locking/rollback, nested transactions/savepoints, repositories and UoW hooks are present. | Multi-connection, replica routing, pooling, sharding, ORM/specification/identity map and batch operations remain planned. Single-connection assumptions become an availability bottleneck. | P1: introduce a connection-selection port with read-consistency policy; do not add transparent splitting without transaction pinning. |
| Job queue | In-process/PDO/Redis Streams queues, retries, DLQ, idempotency, scheduling, priorities, cluster lease and operational CLI exist. | SQS/Beanstalk, chaining/batching, job rate limits and dashboard are absent. Redis is optional in the current dev environment. | P1: capacity-test PDO/Redis drivers and configure worker concurrency, retry and DLQ runbooks. |
| HTTP & API | PSR-7/15/17, API versions, pagination, sort/filter whitelist, ETag, Problem Details, OpenAPI generation/gating and Postman export exist. | HATEOAS/JSON:API/HAL, sparse fields, embedding, advanced Vary and SDK generation are planned. | P2: preserve current explicit representation contracts; add generated SDK only with semver/breaking-change gate. |
| Validation & input | Field rules, form requests, async rules, localized messages, header/URI/route validation and regex hardening exist. | HTML purifier and general sanitization filters are intentionally absent. Blind sanitization is not a substitute for output encoding. | P1: use context-specific output encoding; introduce a vetted purifier only for explicit rich-HTML use cases. |

## Remediation implemented in this audit

### Finding: recursive payload safety check — **P0 availability**

`JsonMessageSerializer` walked nested arrays recursively before invoking
`json_encode(..., JSON_THROW_ON_ERROR)`. A nested attacker-controlled command or
message could therefore hit the PHP stack limit (and terminate a long-lived
RoadRunner worker) rather than receive the expected controlled JSON-depth error.
The validator now uses an explicit work stack, rejects resources/objects, and
caps depth, pending entries, and processed array nodes. The pending-entry check
is material: a node-count cap applied only after popping does not prevent a
single very wide input from first allocating an enormous work queue.

**Tests:** deep payload retains the `JsonException` contract; cyclic and wide
payloads fail deterministically with `InvalidArgumentException`.

## Compliance and governance assessment

* **ISO 27001:** the codebase demonstrates preventive controls (input limits,
  authz, encryption, logging/redaction) and detective controls (health,
  metrics, CI gates). Statement-of-applicability evidence for identity
  federation, retention, incident response and hosted-secret operations remains
  an application/deployment responsibility, not framework evidence.
* **COBIT:** quality ratchets, static analysis, mutation evidence, dependency
  policy and governance documentation support BAI03/BAI06/DSS05. Product owners
  must assign accountable owners and measurable SLO/RTO/RPO targets for the
  PLANNED rows above.
* **Supply chain:** `composer install` requires `ext-redis` because it is listed
  in `require-dev`; the audit environment did not provide it. CI should test a
  no-Redis profile using `--ignore-platform-req=ext-redis` only where Redis tests
  are explicitly skipped, while production uses a locked, extension-complete
  image.

## Release gate recommendation

Before labelling an application built on ZEF as enterprise-ready, require: (1)
full PHPUnit on a non-root CI user, (2) PHPStan/format/deptrac/security gates,
(3) load tests for the selected queue/database adapter, (4) threat-model signoff
for identity and tenant isolation, and (5) tested backup, key-rotation, DLQ and
incident runbooks. These are deployment controls and cannot be proven solely by
the framework library test suite.
