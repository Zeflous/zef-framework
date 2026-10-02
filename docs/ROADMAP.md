# 🚀 ZEF Framework — Roadmap Fitur

> Dokumen ini adalah roadmap asli yang menjadi dasar pemetaan modular pada refactor hexagonal.
> Setiap butir di bawah kini memiliki lokasi fisik yang jelas di pohon direktori
> (lihat `docs/ARCHITECTURE.md` untuk pemetaan namespace → layer).

---

## 🏗️ 1. FONDASI FRAMEWORK

### Arsitektur Hexagonal (Ports & Adapters)
- [x] Core domain isolation → `src/Domain`
- [x] PSR interfaces sebagai ports → `src/Compat/Psr` + `src/Domain/**`
- [x] HTTP, CLI, RoadRunner sebagai inbound adapters → `src/Adapters/{Http,Kernel,Runtime}` + `bin/`
- [x] Async runtime fiber-native (v2.26.0: `FiberScheduler` pump FIFO + timer monotonic via `MonotonicClockInterface`/`SleeperInterface` (deterministik di test), `await/awaitAll/suspend/sleep/timeout`, `FiberChannel` bounded FIFO (invarian park satu sisi, splice cancellation), `Semaphore` + `WaitGroup` fail-fast, `CoroutineLocal` WeakMap, `CancellationTokenSource`/token view, deteksi deadlock + surfacing `UnobservedTaskException` — zona mutasi `app-runtime-async` MSI 96.64%)
- [x] Database, Cache, Queue sebagai outbound adapters → `src/Infrastructure/{Cache,Database,EventSourcing,Job,Message,Security,Storage,OpenApi}` + `src/Application/{CQRS,Job,Message}`
- [x] Dependency injection melalui Container PSR-11 → `src/Application/Container`
- [x] Request/Response handling melalui PSR-7 → `src/Adapters/Http`

  #### Target Enterprise:
  - [ ] `GraphQL adapter`
  - [ ] `WebSocket adapter`
  - [ ] `gRPC adapter`
  - [x] `Event sourcing adapter` (v2.19.0: EventStore port + InMemory/PDO adapters, `AggregateRoot`, snapshot policy, `Projector` + checkpoints, transactional outbox + relay dead-letter)
  - [x] `OpenAPI documentation` (v2.20.0: generator spesifikasi 3.1 dari route table + validation engine + PHP 8.4 Attributes, serializer JSON/YAML dependency-free, `SpecHandler` ETag/304 + Swagger UI dev-only, CLI `openapi:generate`, export Postman v2.1, validator struktural; runtime validation middleware & security enforcement menyusul) — **runtime gate terkirim v2.33.0** (`OpenApiGateMiddleware`, lihat `docs/OPENAPI-GATE-PARITY.md`)
  - [x] `Configuration System v2` (v2.21.0: multi-source `config/*.php` + overlay env `ZEF_*__KEY` + secrets provider `%secret:name%`, skema fail-fast collect-all (tipe/required/default/enum/min/max/pattern + strict unknown-key), accessor bertipe `string()/int()/float()/bool()/array()/enum()`, export config terkompilasi atomik + `CompiledConfigSource` untuk boot produksi, singleton `Config::class` di container; v2.23.0 issue #60: port observability `ConfigMetricsInterface` untuk pipeline secrets + `MeterConfigMetrics` over `MeterInterface`, cache index radix `RadixTreeCache` (serialize OPcache-neutral, invalidasi ZefVersion+fingerprint, atomic 0600), memo `PatternQueryCache` untuk pattern query berulang, dan jalur migrasi skema `ConfigSchema::$version` + `ConfigMigrator` ladder ascending)
  - [x] `OpenAPI runtime validation` (v2.33.0: `OpenApiGateMiddleware` PSR-15 — matriks paritas 12 batas `docs/OPENAPI-GATE-PARITY.md`; path-template mirror semantik Router (pass-through 404, 405+Allow, 400 konstrain), parameter query/header/cookie dengan coercion string-transport, body JSON ketat dengan JSON Pointer, security requirement 401/403 via identitas terverifikasi + bukti kehadiran, respons opt-in fail-closed 500, boot fail-closed reuse `OpenApiSpecValidator`/`OpenApiSecurityValidator`)
  - [ ] `OpenAPI client SDK generation` — generator SDK klien dari spesifikasi
  - [ ] `OpenAPI breaking-change detection` — diff spesifikasi otomatis untuk evolusi API
  - [ ] `Social auth adapters (Google, Facebook, GitHub)`
  - [ ] `SMS/Email service adapters`
  - [x] `CDN/storage adapters (S3, GCS, Local)` — **v2.30.0**: port `ObjectStorageInterface` + adapter `LocalStorage` (filesystem, tulis atomik) & `S3CompatibleStorage` (SigV4 in-house, tanpa SDK — AWS/MinIO/Ceph/R2/GCS via endpoint XML-API); broker transports tetap terbuka (baris RabbitMQ/SQS/Kafka di bawah)
  - [x] `v2.8.0`: Conditional-GET adapter (ETag/If-None-Match/If-Modified-Since) → `src/Adapters/Http/ETagMiddleware`
### Container System (PSR-11) — `src/Application/Container` + `src/Domain/Container`
- [x] Auto-wiring dengan reflection
- [x] Singleton, Request-scoped, Transient lifecycles
- [x] Circular dependency detection
- [x] Service definition validation
- [x] Module-based registration
- [x] Lazy loading support
- [x] Request scope isolation
- [x] Runtime implicit lifetime-capture guard (singleton subtree yang menarik service REQUEST/TRANSIENT via `$ctx->get()` di body factory fail-fast saat resolusi — melengkapi pass compile-time `assertSingletonClosure` untuk dep terdeklarasi)
- [x] Architecture policy enforcement

  #### Target Enterprise:
  - [x] `Tagged services untuk grouping` (v2.8.0: `TaggedServiceLocator`, resolusi via tag `ServiceDefinition`)
  - [x] `Contextual binding` (v2.10.0: `Container::when()->needs()->give()`, registry-rewrite pre-freeze)
  - [x] `Service decoration chain` (v2.10.0: `Container::decorate()`, wrapper definitions + `@inner:*`)
  - [x] `Service middleware/interceptors` (onion ber-priority di sekeliling KONSTRUKSI service — cache-miss path, konsisten dengan event resolving/resolved: priority lebih tinggi = terluar, short-circuit mengganti instance namun tetap melewati resolved listeners + per-lifetime caching, pipeline disegel saat `validateAndFreeze()` via `Container::addServiceMiddleware()`)
  - [x] `Container compilation untuk performa` (`ContainerCompiler` ✔ + autowiring AOT v2.9.0 ✔)
  - [x] `Service provider dengan deferred loading` (v2.10.0: `ServiceProviderInterface` + `DeferrableProviderInterface` + `bootProviders()`)
  - [x] `Container events (resolving, resolved)` (v2.10.0: `onResolving()` / `onResolved()` dengan instance replacement)
  - [x] `RadixTree namespace container` (v2.11.0: `NamespaceRadixTree` + `NamespaceScopePolicy` + `RadixTreeCompilerPass` — hybrid flat O(1) + trie O(K), `getByPrefix()`, namespace fallback, scope policy `internal`/`module` tanpa tagging manual, AOT `exportArray`)

### Router System — `src/Adapters/Router`
- [x] O(log n) route matching (Radix Tree)
- [x] Dynamic parameters dengan constraints
- [x] Built-in constraints (int, uuid, slug, hex, bool)
- [x] Custom regex constraints dengan ReDoS protection
- [x] Method-based routing (GET, POST, etc.)
- [x] 405 Method Not Allowed detection
- [x] Route priority system
- [x] Module-scoped routes

  #### Target Enterprise:
  - [x] `Route groups dengan prefixes` (v2.10.0: `Router::group()`, prefix/name/middleware/priority bersarang)
  - [ ] `Subdomain routing untuk multi-tenancy`
  - [x] `API versioning (URL, header, query)` (v2.10.0: `ApiVersionNegotiator`, prioritas path > header > query > default)
  - [ ] `Route model binding otomatis`
  - [x] `Route caching & compilation` (v2.10.0: `exportRoutes()`/`fromCompiledArray()`/`RouteCache`, file murni atomik)
  - [ ] `Localization routing (/{locale}/...)`
  - [ ] `Content negotiation routing`
  - [x] `Route naming & reverse routing` (v2.8.0: `RouteDefinition.name`, `Router::patternFor()`, `UrlGenerator`)
  - [x] `Route middleware assignment` (v2.10.0: metadata `middleware` per-route via groups, terekspor di `getRoutes()`)
  - [x] `Fallback routes & custom 404` (v2.10.0: `Router::fallback()` + `matchOrFallback()`, 405 tetap dijaga)

---

## 🛡️ 2. SECURITY FRAMEWORK

### Security Dasar — `src/{Domain,Application,Infrastructure,Adapters}/Security`
- [x] Authentication boundaries (layer isolation)
- [x] Authorization policies (scope-based) → `AllowScopeAuthorizationPolicy`
- [x] Replay protection (idempotency keys) → `BoundedInMemoryReplayProtector`
- [x] Rate limiting (in-memory, APCu, Redis) → `InMemoryRateLimiter`, `ApcuRateLimiter`, `RedisRateLimiter`
- [x] Rate limiting tiered (v2.25.0: sliding window counter + token bucket cost-aware via `CacheClockInterface`, `RateLimitRule` + `RateLimitVerdict` (most restrictive wins), `TieredRateLimiter` kunci komposit `name>identity`, `RateLimitMiddleware` PSR-15: header draft IETF `RateLimit-*` + legacy `X-RateLimit-*`, rantai identitas identity > API key > IP sadar-proxy, fail-open/closed, wiring env `ZEF_SECURITY_RATE_LIMIT_TIERS/_ALGORITHM/_FAIL_OPEN` fail-fast)
- [x] Credential management → `StaticCredentialProvider`, `CredentialHandle`
- [x] Security context propagation → `SecurityContext`, `CorrelationPropagator`
- [x] CSRF protection dengan token rotation → `CsrfTokenManager`
- [x] CORS dengan origin validation & preflight handling → `app/Middleware/CorsMiddleware`
- [x] Security headers (HSTS, CSP, X-Frame-Options, X-Content-Type-Options) → `app/Middleware/SecurityHeadersMiddleware`
- [x] Trusted host/proxy validation → `TrustedHostValidator`, `TrustedProxyMatcher`
- [x] Header injection prevention → `InvalidHeaderException`, `HeaderValidator`
- [x] Request body size limits → `RequestBodyPolicy`, `LimitedInputStream`
- [x] Client IP resolution (rightmost-untrusted XFF) → `ClientAddressResolver`

#### Authentication / Authorization / Firewall / Encryption / Audit (Target Enterprise)
- [x] MFA/2FA TOTP (v2.8.0: `Totp` RFC 6238 + `Base32` RFC 4648, vektor resmi lolos)
- [ ] MFA via SMS/Email
- [ ] WebAuthn
- [ ] OAuth 2.0 server
- [ ] SAML 2.0 SSO
- [x] JWT-ready token plumbing
- [ ] Session Redis (parsial via `SharedRateLimitStoreInterface`)
- [x] RBAC scope-based penuh
- [ ] ABAC penuh
- [x] Policy-based authorization (gates/policies)
- [ ] Resource-level permissions · Dynamic permissions · Permission inheritance/caching · RLS otomatis
- [x] SQL injection prevention (parameterized queries di layer data)
- [x] XSS/SSRF/Path traversal protection dasar
- [ ] WAF rules · File upload malware scan · Bot detection/CAPTCHA · Honeypot fields
- [x] AES-256-GCM service (v2.8.0: `AesGcmEncryptor`, port `EncryptionInterface`, format `zefenc1.*` versi-bawa-kunci)
- [x] Key rotation (v2.10.0: `RotatingKeyRing`, decrypt probe semua kunci)
- [ ] Encrypted columns/file storage
- [x] Secure hashing (Argon2id/bcrypt via PHP core)
- [x] CSPRNG (random_bytes)
- [ ] GDPR toolkit · Data retention · RTBF · Data portability · Cookie consent · Compliance reporting

---

## 🔄 3. CQRS & EVENT-DRIVEN ARCHITECTURE — `src/{Domain,Application}/CQRS` + `src/Domain/Event`

- [x] Command bus dengan middleware (validation, auth) → `CommandBus`
- [x] Query bus terpisah (read-only optimization) → `QueryBus`
- [x] Handler registration & resolution · Middleware chain (priority)
- [x] Idempotency store (in-memory) → `InMemoryIdempotencyStore`
- [x] Event publishing otomatis dari commands
- [x] Context correlation tracking (trace ID) → `CqrsContext`
- [x] Event dispatcher dengan priority (PSR-14) → `src/Application/Event/EventDispatcher`
- [x] Listeners & subscribers · Stop propagation · Event freezing

  #### Target Enterprise:
  - [x] Event Store (v2.19.0: port + InMemory/PDO adapter di atas Database Core — MySQL/SQLite/PostgreSQL-ready) · Event replay (`AggregateRoot::applyStored`) · Snapshot (`SnapshotPolicy` + store) · Projection/Read model sync (`Projector` + checkpoint store)
  - [ ] Postgres/NoSQL dedicated store optimizations
  - [ ] Event versioning & migration tools
  - [ ] Saga Orchestration/Choreography · Compensating actions · Timeout/circuit breaker
  - [ ] RabbitMQ · Amazon SQS · Apache Kafka adapters · Protobuf serialization — dasar siap: transport `MessageTransportInterface` + `InMemoryMessageTransport` (v2.30.0), `JsonMessageSerializer`, queue engine durabel multi-driver (v2.32.0: Redis Streams + PDO + CLI worker `queue:work/failed/retry/flush`); sisa pekerjaan = adapter broker eksternal (pola suggest + skip-guard)
  - [ ] DLQ (parsial: job worker ✔)
  - [ ] Retry policies (parsial: `RetryPolicy`, `RetryBackoffPolicy` ✔)
  - [ ] Message ordering guarantees · Exactly-once semantics
  - [x] v2.8.0: `DeduplicatingMiddleware` (at-least-once → efektif once per messageId via idempotency store)

---

## 📊 4. OBSERVABILITY & TELEMETRI — `src/{Domain,Application,Infrastructure}/Observability`

- [x] W3C Trace Context support → `TraceContextPropagator`
- [x] Distributed tracing · Span creation/attributes/events · Parent-child spans
- [x] OTLP HTTP/JSON exporter → `OtlpHttpJsonExporter` (Infrastructure)
- [x] In-memory exporter untuk testing → `InMemorySpanExporter`
- [x] Counter meters (monotonic) → `CounterMeter` · Histogram/metrics · Aggregation
- [x] Structured logging (JSON) → `TelemetryLogger` · Correlation ID · PII redaction → `TelemetrySanitizer`
- [x] Log levels (emergency → debug)

  #### Target Enterprise:
  - [ ] New Relic · Datadog · Elastic APM · Custom APM adapters
  - [ ] Performance profiling · Slow query/N+1 detection · Memory leak detection
  - [x] Prometheus export (v2.8.0: `PrometheusRenderer` + endpoint `/metrics` text format 0.0.4)
  - [ ] Grafana templates
  - [x] Health check endpoints (`/health/live`, `/health/ready`) → `modules/Health`
  - [x] Custom health indicators (v2.8.0: port `HealthIndicatorInterface`, `HealthAggregator`, endpoint agregat `/health` 503-saat-degraded)
  - [ ] Alert rule engine
  - [ ] Incident integration
  - [ ] ELK · Graylog · Splunk · CloudWatch · GCL · Log shipping/filtering/sampling
  - [ ] Jaeger · Zipkin · Service mesh · Trace sampling/baggage · Cross-service correlation
  - [ ] Business KPIs · Revenue tracking · Funnel · A/B metrics · Real-time dashboards

---

## 💾 5. DATA LAYER & PERSISTENCE — `src/{Domain,Infrastructure}/Cache` (+ roadmaps berikutnya)

- [x] In-memory cache store · TTL · Eviction policies (LRU/LFU/FIFO) → `InMemoryCacheStore`
- [x] Key normalization → `DefaultCacheKeyNormalizer` · Cache clock → `SystemCacheClock`
- [x] PSR-6-style pool (`CacheInterface`) & PSR-16-style simple cache
- [x] Cache tags (v2.8.0: `TaggableCache`, indeks tag + reverse index, `invalidateTag()`)
- [x] Multi-tier L1/L2 (v2.8.0: `TieredCache` write-through + promosi read)
- [x] Lock primitive (v2.8.0: port `LockStoreInterface` + `InMemoryLockStore` berbasis lease TTL — fondasi stampede prevention & distributed lock Redis)
- [ ] Redis cluster
- [ ] Memcached
- [ ] Stampede prevention penuh (fondasi lock ✔)
- [x] Distributed lock Redis (v2.24.0 issue #68: adapter `RedisLockStore` — SET NX PX + owner token di dalam satu Lua atomik, release/refresh compare-and-act Lua, TTL dipaksakan server Redis; `LeaderElector` leader election time-bounded; scheduler cluster-safe via lease sticky `zef:scheduler:*`; `LockingJobIdempotencyStore` claim exactly-once per window TTL)

### Database Management (Target — belum ada di monolith)
- [ ] Multi-connection
- [ ] Read/write splitting
- [ ] Pooling
- [ ] Sharding
- [x] Query builder (v2.18.0: `QueryBuilder` fluent, identifier-grammar ketat, subquery)
- [ ] ORM
- [ ] Seeding
- [ ] Schema builder
- [x] Migration (v2.18.0: `Migrator` versi 14-digit, lock TTL, rollback)
- [x] Transactions nested/savepoints (v2.18.0: `PdoConnection` SAVEPOINT + depth 16)
- [x] Repository pattern (v2.18.0: `Repository` base di atas QueryBuilder)
- [ ] Specification
- [x] Unit of Work (v2.22.0 UoW-lite: scope transaksi terkelola dengan hook after-commit, decorator command bus transaksional, antrean tulisan deferred yang flush atomik — lihat `docs/TRANSACTION-HOOKS.md`)
- [ ] Identity Map
- [ ] Batch ops

---

## ⚙️ 6. JOB QUEUE & BACKGROUND PROCESSING — `src/{Domain,Application}/Job`

- [x] In-process job worker → `InProcessJobWorker` · Envelope metadata → `JobEnvelope`
- [x] Job middleware · Retry policies (exponential backoff) → `RetryPolicy`
- [x] Dead letter queue · Idempotency store · Cancellation & timeout · Drain mode
- [x] Claim idempotent lintas node (v2.24.0: `LockingJobIdempotencyStore` — exactly-once per key dalam window TTL, kegagalan me-release lease agar retry sah)
- [x] Delayed jobs & priorities (konten queue: `availableAtUnixNano`, `priority`)
- [x] Scheduled/recurring (v2.8.0: `Scheduler` + `FixedIntervalSchedule` + `CronExpression` 5-field UTC) · [x] Cluster-safe scheduler (v2.24.0: lease `LockStoreInterface` opsional — follower skip tick alih-alih dobel-enqueue, leader lapse diambil alih otomatis setelah TTL)
- [x] Multi-driver (v2.32.0: `RedisStreamJobQueue` — XADD + claim Lua atomik (XRANGE→XDEL+SREM satu langkah), ordering paritas PdoJobQueue `priority DESC, available_at ASC, seq ASC`, duplicate-backstop SET id live, zero-pad 20-digit nano anti Lua-double; **CLI `queue:work`** (graceful SIGTERM/SIGINT antar-job + guard `--memory=<mb>` exit 2 untuk supervisor restart) · Database ✔ v2.30)
- [x] Worker operasional (v2.32.0: **`queue:failed`** (inspeksi DLQ read-only via `peek()`), **`queue:retry`** (re-delivery attempt-dipertahankan, skip duplikat + seen-set anti-siklus), **`queue:flush`** (drain port-agnostic) — failed storage = instansi `JobQueueInterface` kedua (stream `zef.queue.failed` / tabel PDO), driver-agnostic lewat port)
- [ ] Beanstalk · SQS
- [ ] Chaining/batching
- [ ] Job rate limiting · Monitoring dashboard

---

## ✉️ 7. MESSAGING & INTEGRATION — `src/{Domain,Application}/Message`

- [x] Message envelope/headers/context → `MessageEnvelope`, `MessageContext`, `MessageResult`
- [x] In-process message bus · Middleware · JSON serialization → `JsonMessageSerializer`
- [ ] Broker integration (RabbitMQ, Kafka, Redis Streams) · Pub/Sub · Request/Reply
- [ ] Transformation · Validation · Versioning · Compression · Encryption · Deduplication
- [ ] Consumer groups · Acknowledgment · Redelivery · Dead letter exchange

---

## 🌐 8. HTTP & API FEATURES — `src/Adapters/Http` + `src/Adapters/Kernel`

- [x] PSR-7 immutability penuh (`Request`, `Response`, `ServerRequest`, `Uri`, `Stream`, `UploadedFile`)
- [x] PSR-17 factories → `Psr17Factory` · PSR-15 middleware pipeline → `MiddlewarePipeline`
- [x] Global & route middleware · Priority · Header validation (RFC 9110) · HTTP/1.1–2–3 protocol strings
- [x] Request body limits · Emitter dengan CL reconciliation → `ResponseEmitter`

  #### Target Enterprise API:
  - [ ] HATEOAS · JSON:API · HAL · Content negotiation · Partial responses · Sparse fieldsets
  - [x] Pagination (offset/page: `PageRequest`+`PageSlice` dengan hard cap; cursor: `Cursor` opaque ber-checksum) (v2.8.0)
  - [x] Sorting/filtering (v2.10.0: `SortSpec`+`FilterSpec`, whitelist-wajib anti-injection)
  - [ ] Resource embedding
  - [x] ETag/Last-Modified conditional requests (v2.8.0: `ETagMiddleware`, opt-in)
  - [ ] Vary handling lanjutan
  - [x] RFC 9457 Problem Details (v2.8.0: `ProblemDetails` factory `application/problem+json`)
  - [ ] GraphQL (schema, resolvers, DataLoader, depth limiting, federation, playground)
  - [ ] WebSocket (channels, presence, auth, broadcasting) · SSE fallback
  - [x] OpenAPI generation (v2.20.0: generator spesifikasi 3.1 dari route table + PHP 8.4 Attributes, serializer JSON/YAML dependency-free, CLI `openapi:generate`, Swagger UI dev-only)
  - [x] Postman export (v2.20.0: export Postman v2.1 dari spesifikasi, flag `--postman=<path>`)
  - [ ] SDK generation

---

## 🔍 9. VALIDATION & INPUT HANDLING — `src/Domain/Validation` + `src/Adapters/Http`

- [x] Header validation → `HeaderValidator` · URI validation → `Identifier` · Route constraints → `RouteConstraintValidator`
- [x] HTTP status/method validators · Trusted host → `TrustedHostValidator` · Port range
- [x] Container/config dependency validation → `DependencyGraphValidator`
- [x] Rules engine field-based (v2.8.0: `Validator` + `FieldRules` + `ValidationResult` + `ValidationError`, ReDoS-guarded) · [x] Form request objects terintegrasi HTTP (v2.10.0: `FormRequest::fromServerRequest()`) · [x] Async rules (v2.27.0: `AsyncRuleEngine` konkuren di atas fiber scheduler — semaphore cap, deadline kooperatif per rule, fail-fast graceful; `RuleVerdict`/`RuleReport`/`RuleEngineOptions` — zona mutasi `app-rules` MSI 96.40%)
- [x] Localized error messages (v2.10.0: `MessageCatalog` + `ValidationTranslator`)
- [ ] HTML purifier
- [ ] Sanitization filters

---

## 🏢 10. MULTI-TENANCY (Target — fondasi tersedia)

- [ ] Database/schema-per-tenant · tenant_id shared · Tenant context/resolution/switching
- [ ] Provisioning, migration, seeding per tenant · White labeling · Custom domains
- Fondasi yang relevan: container request-scope, router subdomain-ready, config per-module.

---

## 🛠️ 11. DEVELOPER EXPERIENCE

- [x] Self-test runner CLI → `bin/zef --self-test` · Dev server → `bin/zef --serve` · Lint → `composer lint`
- [x] Route inspector → `bin/zef route:list` (v2.8.0) · Code generators → `bin/zef make:*` (v2.8.0: module/handler/middleware; **v2.16.0 ZEF Maker: plugin, config, command, query, entity, valueobject, service**; **v2.29.0: `make:app` — scaffold aplikasi standalone** — engine hexagonal `src/Infrastructure/Console`) · Command catalog → `bin/zef list [--json]` · Inspectors → `module:list`, `plugin:list`, `config:show` (v2.16.0) · **Queue ops → `bin/zef queue:work/failed/retry/flush` (v2.32.0: worker daemon + guard memori + harness DLQ)**
- [x] **v2.29.0 DX release**: standalone app scaffolding (`make:app`) · RoadRunner transparency (`rr:init` dari knob `ZEF_*`, collision-safe) · environment preflight + boot smoke (`doctor`, exit 1 hanya pada FAIL) · tutorial Zero-to-Hero (`TUTORIAL-CQRS-101.md`, diverifikasi end-to-end) · kontrak manifest + registry plugin (`PLUGINS.md`)
- [x] Tinker/REPL (v2.10.0: `TinkerSession` + `bin/zef tinker`)
- [ ] Debug toolbar
- [ ] Profiler
- [ ] Hot reload
- [ ] PhpStorm/VS Code plugin
- [ ] Beautiful error pages (parsial: `ErrorResponseFactory`)

---

## 🧪 12. TESTING FRAMEWORK — `tests/`

- [x] PSR contract compliance tests · Route/container/concurrency/security boundary tests
- [x] Request factory edge cases · Router semantic tests · Pipeline error handling
- [x] JSON scalar boundary · Zero critical bugs gate · 145 assertion suite via `CliRunner`
- [x] Mutation testing (v2.13.1: Infection + PCOV dieksekusi nyata, 8.907 mutan, gate no-regression 55/60)
- [x] Mutation deep-dive ke MSI 85/90 — **TERCAPAI di v2.15.0, gate dipertahankan sejak itu** (riwayat kampanye per rilis):
  - v2.14.0 ronde 1: MSI 59.6→61.8, gate 58/62
  - v2.14.1 ronde 2: **MSI ~67.0 / covered ~73.0, gate 64/68**, RequestFactory 181→35 escape, Router 136→66, Kernel 185→137, Telemetry 158→74
  - v2.14.2 ronde 3 kurikulum EDGE-CASE-MATRIX: fase 1 SecVal **64.4→84.0**
  - v2.14.3 fase 2b Container core: 3 kelas **67→92 / covered 94**, gate **68/73**
  - v2.14.4 fase 3 Runtime lifecycle: chunk adapters-runtime-sec **53→80 / covered 83**, +138 kill, dua akar fatal lingkungan uji diakari, gate **69/74**
  - v2.14.5 fase 4 HTTP/Router/Kernel: adapters-http **75→93 / covered 96** (+266 kill), Router **65→85**, Kernel 64→66 (sisa setara-CLI terinventarisasi), **+366 kill** total, gate **71/76**
  - v2.14.7 fase 5 observability: Telemetry **83→85**, Sanitizer/Logger/Clock **85→95**, Meter/Span/Tracer **94→98**, BSP/Health/Propagators **80→86** — escape chunk **94→69** mayoritas ekuivalen triaged, +63 test; lingkungan uji terbukti pulih penuh dari ZIP distribusi; gate **71.5/76**
  - v2.14.8 fase 6 Domain inti: rescfg **73→86**, sec **85→95**, rest **64→89**, core-a **72→89**, core-b **59→93** — escape **487→132** (+509 kill), 150 test / 993 asersi baru, gate **75/80**
  - v2.14.8 fase 7 app zones: job **90/92**, rest-a **92/96**, rest-b **91/93**, message **97/97**, container **88/92** (+~220 kill, 99 test)
  - v2.14.9 fase 8-9 infra+c3: cache **96/98**, config **94/96**, secinfra **85/89**, obsinfra **90/91**, c3a **83/89**, c3b **82/82**, c3c **96/96** — seluruh not-covered Redis/APCu jadi tercover via ekstensi nyata; gate **77/82**
  - **v2.14.x-v2.15.0 fase 10 tuntas: gate 85/90 TERCAPAI (full-run 9.032 mutan: MSI 90.4 / covered 93.1)**
  - **v2.16.0 gate tertahan di 85/90 pasca ZEF Maker: 9.419 mutan, MSI 90.77 / covered 93.38** — zone Console 387/387 (MSI 100%), validasi silang 16 zona 5.902 mutan MSI 92.3
- [x] Stub pre-scan korpus fixture (v2.34.0: matriks paritas 12 dimensi `docs/security/stub-prescan.md` — SAST/Bugs/Security INCLUDED untuk `tests/**`, CPD EXCLUDED by design (duplikasi adalah idiom fixture), ratchet jumlah fixture fail-closed dua arah `tests/fixtures.limit`; project SonarCloud terkarantina `zeflous_zef-framework-stubs` di-scan dengan scope ter-pin positif `sonar.inclusions=tests/**` + gate zero-tolerance bugs/vulnerabilities/hotspots (baseline terukur NOL); keluarga correctness Semgrep `php/lang/correctness` dari tarball ter-pin yang sama masuk ke 4 lane php-sast — 0 temuan/652 target produksi + 0/190 korpus saat enable)

---

## 🚀 13. DEPLOYMENT & DEVOPS

- [x] Worker lifecycle management · Signal handling (SIGTERM/SIGINT) · Memory limit enforcement
- [x] Request admission control → `InMemoryAdmissionController` · Graceful shutdown · Resource health
- [x] RoadRunner adapter → `bin/worker.php` · Docker-ready entrypoints (`bin/`, `public/`)
- [x] Docker/Compose templates → `deploy/Dockerfile`, `deploy/docker-compose.yml` + healthcheck · CI template → `.github/workflows/ci.yml` (v2.8.0)
- [x] K8s manifests (v2.10.0: `deploy/k8s/`)
- [ ] Helm
- [ ] Blue-green/canary
- [ ] IaC

---

## 🧩 14. EXTENSIBILITY

- [x] Config providers → `ConfigProviderInterface` · Module registration/lifecycle → `AbstractModule`, `ModuleRegistry`
- [x] Module dependencies · Isolation · Architecture policy enforcement
- [ ] Plugin marketplace · Webhook system · Sandboxing · Conflict resolution
