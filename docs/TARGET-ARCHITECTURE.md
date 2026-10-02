# ZEF Target Architecture — Config-Driven HMVC Platform

**Status:** target architecture / ADR baseline. This document is intentionally
prescriptive: it separates the current framework's strong hexagonal primitives
from the operating model required for a cohesive enterprise application platform.

## 1. Executive decision

ZEF SHALL evolve into a **configuration-driven modular monolith** with a clean
hexagonal kernel, **HMVC feature modules**, and versioned plug-and-play packages.
The unit of ownership is a module, not a directory of framework classes. A module
owns its presentation components, use cases, domain model, persistence mappings,
routes, policies, configuration schema, migrations, health checks, and tests.

This is not a microservice rewrite. Modules run in one deployable process and
communicate in-process by explicit contracts/events. They can later be extracted
only where a bounded context, data ownership, SLO, and operational cost justify it.

## 2. Current-state findings

| Finding | Evidence | Impact |
|---|---|---|
| Bootstrap manually imports and registers each module/plugin provider. | `src/Bootstrap.php` | Adding a module requires composition-root code changes; it is not plug-and-play. |
| Environment is read directly in the bootstrap. | `src/Bootstrap.php` | Configuration ownership is split and configuration is not fully typed/schema-driven. |
| Module registration currently covers services, aliases, and routes only. | `src/Adapters/Kernel/ModuleBootstrapper.php` | Migrations, middleware, commands, event subscriptions, policies, health checks and resources lack one lifecycle contract. |
| Composer maps multiple physical layers to one `Zef\\Framework\\` namespace. | `composer.json` / `docs/ARCHITECTURE.md` | Layer boundaries are conceptually clear but module ownership and public API boundaries are hard to infer from namespace alone. |

## 3. Target topology

```
                        ┌──────────── Platform Kernel ────────────┐
HTTP/CLI/Worker ───────▶│ bootstrap → config → catalogue → container│
                        │ security → tenancy → routing → telemetry │
                        └──────────────────┬───────────────────────┘
                                           │ module contract
 ┌─────────────────────────────────────────▼─────────────────────────────────────────┐
 │ Feature modules (HMVC): Identity · Catalog · Orders · Billing · Health · ...      │
 │  Presentation (HTTP/CLI) → Application (use cases) → Domain (model/ports)          │
 │                                      ↓                                               │
 │                          Infrastructure adapters per module                          │
 └─────────────────────────────────────────────────────────────────────────────────────┘
                                           │
                              shared kernel contracts only
                                           │
                  PDO · Redis · OTLP · S3 · Queue · external APIs
```

### Dependency rule

1. `Module/<Name>/Domain` depends only on PHP and `Zef\Contract`.
2. `Application` depends on its Domain plus published contracts from another module.
3. `Infrastructure` implements ports; it never becomes another module's repository.
4. `Presentation` adapts HTTP/CLI/messages and invokes module application services.
5. Cross-module calls use a published query contract or domain integration event; no
   direct access to another module's Infrastructure, controller, or database table.
6. The Kernel owns only lifecycle, configuration, routing, security baseline,
   tenancy context, observability, and cross-cutting ports.

## 4. Canonical repository layout

```
src/
  Kernel/                         # stable framework composition contracts
    Contract/ Config/ Module/ Runtime/ Security/ Observability/
  Modules/
    Catalog/
      module.php                  # declarative manifest (no arbitrary execution)
      config/schema.php           # typed schema + defaults; secrets are references
      Presentation/Http/          # controllers, request DTOs, presenters
      Presentation/Cli/
      Application/Command/ Query/ Handler/ Policy/
      Domain/ Model/ Event/ Port/
      Infrastructure/Persistence/ Messaging/ Cache/
      database/migrations/
      tests/{Unit,Integration,Contract}/
plugins/                          # independently installable packages only
  Vendor/Payments/
    zef-plugin.php
    src/ ...
config/
  app.php security.php telemetry.php queue.php database.php
  environments/{local,staging,production}.php
  modules.php                     # enabled modules/plugins + config overlays
```

`modules/` in the current tree is a transitional location. The target uses
`src/Modules` for first-party modules and Composer packages for third-party plugins.
This makes ownership, autoloading, test boundaries, and extraction candidates explicit.

## 5. Full configuration-driven boot

### 5.1 Deterministic source precedence

Configuration is immutable after boot and has this precedence, lowest to highest:

1. Kernel defaults.
2. Module/plugin manifest defaults.
3. `config/*.php` base application configuration.
4. Environment overlay selected by `ZEF_ENV`.
5. Approved secret-provider references (`%secret:name%`), resolved once at boot.
6. Whitelisted `ZEF_*__*` runtime overrides.

Every key must have an owner, schema, type, default/rationale, sensitivity tag,
and environment policy. Unknown keys fail boot in production. Secrets never appear
in compiled config, diagnostics, traces, or route/module inspection output.

### 5.2 Configuration contract

```php
return [
    'modules' => [
        'catalog' => ['enabled' => true, 'config' => ['read_model' => 'catalog']],
    ],
    'security' => ['csrf' => ['enabled' => true, 'secret' => '%secret:csrf%']],
    'tenancy' => ['resolver' => 'host', 'unknown_tenant' => 'deny'],
];
```

The loader validates the full graph before opening network listeners. Compiled
configuration is atomically written, checksum/versioned, permission-restricted,
and invalidated by source fingerprint plus schema version. Configuration changes
are deploy-time events; runtime mutation is forbidden except explicitly versioned
feature flags with audit logging.

## 6. HMVC model

Each module may dispatch an internal subrequest only through `ModuleDispatcher`.
Subrequests are **not** arbitrary recursive HTTP calls: they inherit correlation,
tenant, locale, auth principal and cancellation context; have max depth 8; have a
strict timeout/budget; and cannot re-run global ingress security middleware.

Use HMVC for composition (dashboard widgets, page fragments, internal view models),
not for command orchestration. State-changing work stays in application commands.
The preferred module-to-module read path is a typed query service; render fragments
only when presentation composition is genuinely needed.

```
Outer request → global middleware → module route → controller
                                      └→ ModuleDispatcher::render(CatalogWidget)
                                           → child module presentation pipeline
                                           → response fragment/view model
```

Rules: child requests are read-only by default, cannot alter parent response headers
except declared fragment metadata, propagate only allowlisted attributes, and expose
child spans/metrics. A cycle (`A → B → A`) fails with `ModuleDispatchCycleException`.

## 7. Plug-and-play module and plugin contract

Every module provides an immutable manifest validated before activation:

```php
return [
  'id' => 'vendor.payments', 'version' => '1.0.0',
  'requires' => ['zef' => '^3.0', 'module:identity' => '^2.1'],
  'provides' => ['payment.gateway'],
  'config_schema' => 'Vendor\\Payments\\ConfigSchema',
  'provider' => 'Vendor\\Payments\\PaymentsModule',
  'capabilities' => ['routes', 'commands', 'migrations', 'events'],
];
```

`ModuleInterface` has phases: `define()` (pure metadata), `register()` (container
definitions), `boot()` (validated wiring only), `start()` (workers/listeners), and
`stop()` (bounded graceful shutdown). The catalogue topologically sorts dependencies,
rejects duplicate IDs/capabilities/cycles, verifies compatibility and signatures,
then records the enabled module set in telemetry and health output.

Plugins are Composer packages with an `extra.zef-plugin` manifest. Production
allows only allowlisted/signed packages, pinned lockfile versions, capability
approval, and no arbitrary code during config discovery. Enable/disable is a
deployment operation, not an admin HTTP endpoint.

## 8. Enterprise cross-cutting architecture

| Concern | Mandatory design |
|---|---|
| Security | Global trusted-proxy/host/body-limit/authentication precede routing. Module routes declare authn/authz, rate-limit class, CSRF mode, tenant mode and OpenAPI operation. Production config fails closed for missing required secrets. |
| Tenancy | `TenantContext` is request/job/message scoped; tenant is resolved once at ingress and attached to every query/queue/event. Cross-tenant access requires an explicit privileged policy and audit event. |
| Data | A module owns schema/tables and migrations. Transactional outbox is the default integration boundary. Read/write splitting, if introduced, pins all transaction reads to primary. |
| Async | Job/message envelopes carry module ID, schema version, tenant, correlation, causation, idempotency key and deadline. Consumers are at-least-once and idempotent; DLQ is observable and replay is authorized. |
| Observability | Every ingress/module/subrequest/job creates correlated spans. Logs are structured/redacted; metrics include module, tenant class (never tenant ID if high cardinality), outcome and latency. |
| Resilience | Explicit timeouts, cancellation, bulkheads and retry budgets per adapter. No unbounded body/config/job/event traversal; no retry of non-idempotent external side effect without outbox/idempotency. |

## 9. Migration plan

### Phase 0 — guardrails (no public behavior change)
* Publish Kernel contracts, module manifest schema, catalogue validator and architecture tests.
* Add `module:list --json`, `module:validate`, configuration provenance report with secrets masked.
* Enforce deptrac rules: no cross-module Infrastructure dependency; no `getenv()` outside config adapters.

### Phase 1 — configuration unification
* Move current `Bootstrap` environment reads and provider list into config/module catalogue.
* Adapt existing ConfigProvider classes through a legacy manifest adapter.
* Add compiled-config provenance and fail-closed production unknown-key rules.

### Phase 2 — first-party HMVC modules
* Migrate Core, Health and Toko into canonical module layout without changing routes.
* Introduce `ModuleDispatcher` read-only child dispatch and cycle/depth/budget tests.
* Split module-owned migrations and health indicators.

### Phase 3 — plugin platform
* Release package manifest/compatibility policy, capability allowlist and lifecycle hooks.
* Convert Toko into the reference external plugin; add install/disable/rollback contract tests.

### Phase 4 — enterprise operations
* Add tenancy context enforcement, outbox operational dashboards, SLO-based health/readiness,
  signed plugin provenance, and disaster-recovery runbooks.

Each phase ships behind compatibility adapters; no big-bang namespace rewrite. Remove
legacy bootstrap/module providers only after two supported releases and migration tooling.

## 10. Architecture fitness functions and release gates

Required CI evidence: full unit/integration/contract tests; mutation and static
analysis ratchets; module graph validation; configuration schema/secret leak tests;
plugin compatibility tests; route/OpenAPI parity; migration upgrade/downgrade tests;
tenant isolation tests; queue/outbox duplicate and DLQ tests; performance budgets for
boot, route dispatch and worker memory. Production promotion additionally requires
SBOM/dependency scan, signed artifact, configuration approval, backup/restore drill,
and incident/DLQ/key-rotation runbooks.

## 11. Decisions explicitly deferred

GraphQL, WebSocket, gRPC, ORM, external brokers, workflow/saga engine, and full ABAC
remain adapters/capabilities—not Kernel assumptions. Add each only after its module
contract, threat model, operational SLO, and failure semantics are accepted.
