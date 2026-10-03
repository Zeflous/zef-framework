# Arsitektur — ZEF Framework (Edisi Hexagonal)

Dokumen ini menjelaskan bagaimana monolith `zef_framework_v2.7.0.php` dipecah menjadi
struktur hexagonal (Ports & Adapters), aturan arah dependensi antar-layer, dan peta
pemindahan setiap subsistem.

> **Snapshot struktur: v2.36.0.** Section 1–7 menggambarkan prinsip dan keadaan
> terkini pohon `src/` (640 file PHP: Domain 290 · Application 138 · Infrastructure 116 ·
> Adapters 64 · Compat 23). Section 8–11 adalah catatan historis per rilis
> (v2.8.0–v2.11.0) yang dipertahankan sebagai rekam jejak refactor; evolusi lanjutan
> v2.12.0–v2.34.1 diringkas di section 12. Rincian per rilis: `docs/CHANGELOG-v*.md`.

---

## 1. Prinsip Pemecahan

Tiga keputusan desain yang menentukan seluruh hasil refactor:

1. **Move-only, bukan rewrite.** Setiap deklarasi (kelas, interface, trait, enum) dipindahkan
   byte-exact ke file-nya sendiri. Ekstraksi diverifikasi *round-trip* (dedent → re-indent →
   bandingkan) sehingga tidak ada satu byte pun dari badan kelas yang berubah — termasuk
   string literal multi-baris seperti script Lua pada `RedisSharedRateLimitStore`. Akibatnya,
   seluruh 145 assertion self-test dan 11 rute HTTP demo berperilaku identik dengan monolith.
2. **Namespace dipertahankan, direktori diubah.** Nama FQCN seperti
   `Zef\Framework\CQRS\CommandBus` tetap sama persis, sehingga semua referensi antar-kelas,
   string ID layanan di container, dan assertion berbasis nama kelas tetap valid. Yang
   diorganisasi ulang adalah **pohon direktori fisik**: setiap file ditempatkan pada layer
   hexagonal yang sesuai. PSR-4 Composer memetakan prefix `Zef\Framework\` ke *empat basis
   direktori* (`src/Domain/`, `src/Application/`, `src/Infrastructure/`, `src/Adapters/`) —
   Composer mencarinya berurutan, dan karena satu kelas hanya ada di satu tempat, resolusinya
   deterministik.
3. **Satu kelas, satu file, nama file = nama kelas.** Memudahkan navigasi, `grep`, review
   diff, dan memenuhi konvensi autoloading PSR-4 secara ketat.

## 2. Layer dan Aturan Arah Dependensi

```
                    ┌─────────────────────────────────────────┐
                    │              ADAPTERS (inbound)          │
                    │  HTTP · Router · Kernel · Runtime · MW   │
                    │  Security MW · OpenApi gate + CLI        │
                    └───────────────────────┬─────────────────┘
                                            │ memakai
                    ┌───────────────────────▼─────────────────┐
                    │        APPLICATION (orkestrasi)          │
                    │  Container · CQRS · Message · Job ·      │
                    │  Event · Observability · Security core   │
                    │  Database · EventSourcing · OpenApi ·    │
                    │  Resource · Rules · Runtime async        │
                    └──────────┬────────────────┬─────────────┘
                               │ memakai port   │ delegasi teknologi
                    ┌──────────▼──────────┐  ┌──▼───────────────────┐
                    │  DOMAIN (port, VO)  │  │ INFRASTRUCTURE       │
                    │  kontrak murni,     │  │ (adapter outbound)   │
                    │  tanpa I/O          │  │ cache · redis · pdo  │
                    └─────────────────────┘  │ otlp · s3 · console  │
                                             │ config · env · apcu  │
                                             └──────────────────────┘
                    ┌─────────────────────────────────────────┐
                    │  COMPAT — shim PSR kondisional (23)      │
                    │  dipakai semua layer, tidak bergantung   │
                    │  pada layer manapun (shared kernel)      │
                    └─────────────────────────────────────────┘
```

Aturan arah dependensi (diberlakukan secara dokumentatif; bisa diperiksa dengan `grep`):

| Layer            | Boleh bergantung pada                          | Dilarang bergantung pada |
|------------------|------------------------------------------------|--------------------------|
| `Domain`         | `Compat`                                       | semua layer lain         |
| `Application`    | `Domain`, `Compat`                             | `Adapters`               |
| `Infrastructure` | `Domain`, `Compat`                             | `Adapters`               |
| `Adapters`       | `Domain`, `Application`, `Infrastructure`, `Compat` | — (ujung rantai)    |
| `app/modules/plugins` | semua layer `src/` (composition root)     | —                        |

> **Catatan pragmatis:** dalam monolith asli, sebagian kelas *Application* memegang referensi
> ke implementasi teknologi (mis. `Telemetry` memakai exporter OTLP melalui interface).
> Port-nya selalu berada di `Domain`; implementasi teknologinya di `Infrastructure`. Pemisahan
> ini memenuhi *dependency inversion*: detail teknologi bergantung pada abstraksi, bukan
> sebaliknya.

## 3. Definisi Layer

### `src/Domain` — Port, Kontrak, Value Object (290 file)
Lapisan terdalam. Berisi **semua interface port** (Cache, CQRS, Message, Job, Security,
Observability, Resource, Runtime, Database, EventSourcing, OpenApi, Storage, Rules),
**value object murni** (`JobEnvelope`, `MessageEnvelope`, `SpanContext`, `CqrsContext`,
`RateLimitDecision`, `SecurityRequest`, …), **enum** hasil
keputusan (`SecurityVerdict`, `ReplayDecision`, `AuthenticationStatus`, `ServiceLifetime`),
**validator murni** (`HeaderValidator`, `RouteConstraintValidator`, `DependencyGraphValidator`,
…), **exception** kontrak kegagalan seluruh framework, serta konstanta
(`HttpReasonPhrases`) dan kebijakan arsitektur (`ArchitecturePolicy`).
**Ciri layer:** tidak ada I/O, tidak ada global state, tidak tahu HTTP/Redis/PDO/dll.

### `src/Application` — Mesin Orkestrasi In-Process (138 file)
Implementasi alur kerja framework yang berjalan dalam satu proses:
`Container` (auto-wiring, compiler, registry, scope, radix-tree namespace), bus `CQRS`
(Command/Query + idempotency), `InProcessMessageBus` + serializer, `InProcessJobWorker` +
queue in-memory, `EventDispatcher` (PSR-14), inti `Observability` (Span, Tracer, Telemetry
facade, BatchSpanProcessor, CorrelationPropagator), mesin keamanan in-process (boundary,
CSRF manager, origin policy, rate limiter in-memory, replay protector), orkestrasi
`Database` (QueryBuilder, Migrator, Repository), `EventSourcing` (AggregateRoot, Projector),
`OpenApi` (spec builder + validation engine), `Resource`, `Rules` (sync + async engine),
dan `Runtime` async fiber-native (v2.26.0).
**Ciri layer:** mengorkestrasi port dari Domain; tidak menyentuh jaringan/disk secara langsung.

### `src/Infrastructure` — Adapter Outbound (116 file)
Semua titik sentuh teknologi eksternal: `ConfigAggregator` + modul registry (I/O konfigurasi),
`EnvironmentSecretProvider`, `Env` (getenv), cache store in-memory + clock + normalizer,
`RedisRateLimiter` & `RedisSharedRateLimitStore` (script Lua), `ApcuRateLimiter`,
`OtlpHttpJsonExporter` (exporter telemetri via HTTP), `Database` (PDO connection, job queue
tabel, outbox), `EventSourcing` (EventStore + outbox PDO), `Job` (`RedisStreamJobQueue`,
failed-storage), `Message` (transport in-memory), `OpenApi` (serializer JSON/YAML + cache),
`Security` (AES-GCM, key ring, lock Redis), `Storage` (S3-compatible SigV4 in-house + Local),
dan `Console` (mesin generator ZEF Maker, 22 kelas, v2.16.0).
**Ciri layer:** satu-satunya tempat yang tahu Redis/APCu/OTLP/PDO/S3/getenv.

### `src/Adapters` — Adapter Inbound (64 file)
Semua cara dunia luar **masuk** ke framework:
- `Adapters/Http` — implementasi PSR-7/17 (`Request`, `Response`, `Stream`, `Uri`,
  `UploadedFile`, `RequestFactory`, `Psr17Factory`, `RequestBodyPolicy`, …).
- `Adapters/Router` — radix tree router O(log n) + `RouteDefinition` + `RouteCache`.
- `Adapters/Kernel` — `Application`, `Dispatcher`, `MiddlewarePipeline`, `PipelineFactory`,
  `ResponseEmitter`, `ModuleBootstrapper` (composition root HTTP).
- `Adapters/Runtime` — `RoadRunnerRuntime`, `RoadRunnerWorkerAdapter`, `InMemoryWorker`,
  `BlockingSleeper`, `TinkerSession`.
- `Adapters/Security` — middleware PSR-15 (`AuthenticationMiddleware`,
  `SecurityRuntimeMiddleware`, `RateLimitMiddleware` v2.25.0).
- `Adapters/OpenApi` — `OpenApiGateMiddleware` (runtime validation + security enforcement,
  v2.33.0) + `GenerateSpecCommand` (CLI `openapi:generate`, v2.20.0).

### `src/Compat` — Shared Kernel PSR (23 file)
Shim kondisional PSR-3/7/11/14/15/17 (`if (!interface_exists(...))`) — mempertahankan
kemampuan *zero-composer* monolith. Dimuat **eager** oleh autoloader persis seperti
perilaku monolith; guard membuatnya no-op ketika paket `psr/*` resmi terpasang.

### Di luar `src/` — composition root aplikasi
- `app/` — `Bootstrap` + middleware aplikasi (`Zef\Middleware`): CORS, error handling,
  security headers, timing.
- `modules/` — `Zef\Module\Core` (halaman demo) & `Zef\Module\Health` (probe live/ready).
- `plugins/` — `Zef\Plugin\Toko` (contoh plugin + service + routing).
- `tests/` — `Zef\Test\CliRunner` (145 assertion) + fixture suite v2.7.0.

## 4. Peta Pemindahan (namespace → layer)

| Namespace monolith                     | Layer tujuan                          |
|----------------------------------------|----------------------------------------|
| `Psr\*` (5 blok shim)                  | `src/Compat/Psr/**`                   |
| `Zef\Framework\Foundation`             | `ZefVersion` → Domain · `Env` → Infra  |
| `Zef\Framework\Exception`              | `src/Domain/Exception` (13)           |
| `Zef\Framework\Validation`             | `src/Domain/Validation` (8)           |
| `Zef\Framework\Constant`               | `src/Domain/Constant`                 |
| `Zef\Framework\Policy`                 | `src/Domain/Policy`                   |
| `Zef\Framework\Container`              | kontrak → Domain · mesin → Application|
| `Zef\Framework\Http`                   | `src/Adapters/Http` (14)              |
| `Zef\Framework\Router`                 | `src/Adapters/Router`                 |
| `Zef\Framework\Config`                 | kontrak/VO → Domain · impl → Infra    |
| `Zef\Framework\Event`                  | kontrak → Domain · dispatcher → App   |
| `Zef\Framework\CQRS`                   | kontrak/VO → Domain · bus → App       |
| `Zef\Framework\Message`                | kontrak/VO → Domain · bus → App       |
| `Zef\Framework\Job`                    | kontrak/VO → Domain · worker → App    |
| `Zef\Framework\Cache`                  | port → Domain · store → Infra         |
| `Zef\Framework\Resource`               | port/VO → Domain · impl → App         |
| `Zef\Framework\Observability`          | port/VO → Domain · core → App · OTLP → Infra |
| `Zef\Framework\Security`               | port/VO → Domain · mesin → App · Redis/APCu → Infra · PSR-15 MW → Adapters |
| `Zef\Framework\Security\Distributed`   | port/VO → Domain · impl → App         |
| `Zef\Framework` (kernel)               | `src/Adapters/Kernel` (7)             |
| `Zef\Framework\Runtime`                | port → Domain · runtime → Adapters    |
| `Zef\Middleware`                       | `app/Middleware`                      |
| `Zef\Module\Core` · `Zef\Module\Health`| `modules/Core` · `modules/Health`     |
| `Zef\Plugin\Toko`                      | `plugins/Toko`                        |
| `Zef\App`                              | `app/Bootstrap.php`                   |
| `Zef\Test`                             | `tests/`                              |
| Blok global (SECTION 29 — entrypoint)  | `bin/zef` (CLI) · `public/index.php` (web) |

## 5. Strategi Autoloading Ganda

1. **Composer PSR-4** — prefix `Zef\Framework\` dipetakan ke 4 basis direktori (array),
   `Zef\Middleware\` → `app/Middleware/`, dst. Jalankan `composer dump-autoload` bila
   menambah kelas baru.
2. **Classmap statis zero-composer** — `autoload/zef_autoload.php` berisi peta eksplisit
   1154 FQCN → path, terdaftar via `spl_autoload_register`. Shim PSR dimuat eager (23 file)
   meniru perilaku monolith. File ini juga didaftarkan di `autoload.files` Composer, jadi
   kedua mekanisme hidup berdampingan tanpa konflik.

## 6. Verifikasi Perilaku (hasil nyata)

Angka pada tabel di bawah adalah hasil verifikasi **saat pemecahan monolith** (v2.7.0).
Keadaan v2.36.0: 640 file PHP di `src/` (Domain 290 · Application 138 · Infrastructure 116 ·
Adapters 64 · Compat 23), lint first-party **893 file / 0 gagal**, self-test **501/501**,
suite PHPUnit **3.800 test / 140.881 asersi / 59 skipped** (evidence junit CI), gate mutasi
agregat **85/90** tercapai sejak v2.15.0 (lihat `docs/QUALITY.md`).

| Pengujian                                  | Monolith | Hasil refactor |
|--------------------------------------------|----------|----------------|
| `php -l` seluruh file                      | —        | **319 file, 0 gagal** |
| Self-test suite (`bin/zef --self-test`)    | 145/145  | **501/501** (145 baseline + 103 v2.8.0 + 59 autowiring v2.9.0 + 107 enterprise v2.10.0 + 87 radix-tree v2.11.0) |
| Rute HTTP (status + body byte-identik)     | 11/11    | **11/11**      |
| Header respons (security headers, dll.)    | —        | **identik** (kecuali X-Request-ID acak & waktu, secara desain) |
| Round-trip byte verifikasi ekstraksi       | —        | **250/250 kelas** |

## 7. Konvensi untuk Pengembangan Lanjutan

- **Kelas baru masuk layer mana?** Tanyakan: apakah murni kontrak/data (→ Domain),
  orkestrasi in-process (→ Application), menyentuh teknologi eksternal (→ Infrastructure),
  atau titik masuk dunia luar (→ Adapters).
- Menambah kelas: buat file di layer yang tepat, lalu jalankan `composer dump-autoload`
  (atau tambahkan entri ke classmap statis bila tidak memakai Composer).
- Jalankan `composer test` + `composer lint` sebelum commit — keduanya cepat (< 5 detik)
  dan menjadi pagar minimum untuk menjaga isolasi layer.

## 8. Rilis v2.8.0 — Tambahan Aditif

Feature drop pertama pasca-refactor. Prinsip yang sama dipertahankan: **aditif saja** —
tidak ada kelas lama yang ditulis ulang; kelas yang disentuh hanya mendapat ekstensi
kompatibel-mundur (parameter opsional/metode baru). `ZefVersion::VERSION` sengaja tetap
`2.7.0` supaya seluruh permukaan HTTP lama tetap byte-identik dengan baseline ter-audit.

Peta kelas baru per layer:

| Layer | Kelas baru |
|-------|-----------|
| `Domain/Cache` | `LockStoreInterface` |
| `Domain/Job` | `ScheduleInterface`, `FixedIntervalSchedule`, `CronExpression` |
| `Domain/Observability` | `HealthIndicatorInterface`, `HealthCheckResult` |
| `Domain/Resource` | `PageRequest`, `PageSlice`, `Cursor` |
| `Domain/Security` | `EncryptionInterface`, `Base32`, `Totp` |
| `Domain/Validation` | `Validator`, `FieldRules`, `ValidationResult`, `ValidationError` |
| `Application/Cache` | `InMemoryLockStore` |
| `Application/Container` | `TaggedServiceLocator` |
| `Application/Job` | `Scheduler` |
| `Application/Message` | `DeduplicatingMiddleware` |
| `Application/Observability` | `HealthAggregator` |
| `Infrastructure/Cache` | `TaggableCache`, `TieredCache` |
| `Infrastructure/Observability` | `PrometheusRenderer` |
| `Infrastructure/Security` | `AesGcmEncryptor` |
| `Adapters/Http` | `ETagMiddleware`, `ProblemDetails` |
| `Adapters/Router` | `UrlGenerator` (+ `RouteDefinition::$name`, `Router::patternFor()`) |
| `modules/Health` | `MetricsHandler` (`GET /metrics`), `AggregateHealthHandler` (`GET /health`), `ContainerHealthIndicator`, `CanaryService` |

Titik integrasi pada kelas lama (semuanya no-op bila fitur tak dipakai):

1. `Application::boot()` — mendaftarkan singleton `TaggedServiceLocator`.
2. `Router::add()` — parameter opsional ke-6 `$name`; indeks nama + `patternFor()`.
3. `RouteDefinition` — parameter opsional `$name` (config rute: kunci `'name'`).
4. `ModuleBootstrapper::registerModule()` — meneruskan `$route->name` ke router.
5. `tests/CliRunner` + `tests/V280FeatureSuite` + `tests/V290AutowireSuite` — 17 suite
   self-test; suite khusus v2.8.0 (103 assertion) dan v2.9.0 (59 assertion) berdiri di
   berkas sendiri dan dapat dijalankan terisolasi via `bin/zef --self-test=v280` /
   `--self-test=v290`.

Deployment artifacts baru (di luar kode): `deploy/Dockerfile`,
`deploy/docker-compose.yml`, `.github/workflows/ci.yml`, `scripts/update_classmap.py`
(pembaruan classmap statis tanpa Composer).

## 9. Rilis v2.9.0 — Advanced Autowiring Engine (aditif)

Engine autowiring lengkap sebagai ekstensi Container, **tanpa mengubah satu baris pun**
dari `Container`, `ServiceDefinition`, `DependencyGraphValidator`, `ContainerCompiler`,
`ArchitecturePolicy`, maupun semantik `frozen`.

### Titik integrasi (satu arah, sebelum freeze)

```
AutowireCompilerPass::process($container, [Kelas::class])
        │  ReflectionMetadataExtractor (satu-satunya titik refleksi, compile-time)
        ▼
ServiceDefinition deps lengkap ──▶ validateAndFreeze() yang asli
        │                            (siklus, cross-module, singleton-closure)
        ▼
factory = closure hasil generasi (tanpa Reflection saat runtime)
AutowireAotCompiler::export()  ──▶ berkas PHP murni untuk cold-start
```

### Kelas baru per layer

| Layer | Berkas | Isi |
|---|---|---|
| Domain | `src/Domain/Autowiring/Inject.php` `Value.php` `Target.php` | PHP 8 attributes (data murni) |
| Domain | `src/Domain/Autowiring/AutowireParameterSpec.php` `AutowireClassSpec.php` | hasil ekstraksi refleksi (POJO/VO) |
| Domain | `src/Domain/Autowiring/AutowireMetadata.php` `AutowireResult.php` | rencana argument/dependensi + hasil pass |
| Application | `src/Application/Container/Autowiring/ReflectionMetadataExtractor.php` | Reflection → spec (hanya compile) |
| Application | `src/Application/Container/Autowiring/AutowireCompilerPass.php` | resolusi binding → registrasi definisi |
| Application | `src/Application/Container/Autowiring/AutowireAotCompiler.php` | codegen, eval compile-time, export/load AOT |

### Invariant yang dijaga (terverifikasi test v290)

- `process()` menolak container frozen (`LogicException`) — disiplin sama dengan `register()`.
- Setiap definisi hasil autowiring membawa `$dependencies` lengkap, sehingga
  `validateAndFreeze()` tetap gerbang tunggal: siklus kelas terdeteksi lebih awal di pass
  (dengan rantai kelas), siklus service tetap tertangkap validator, cross-module
  (`ModuleDependencyViolationException`) dan singleton-over-request
  (`InvalidConfigurationException`) berjalan tanpa perubahan.
- Factory hasil generasi adalah `static fn (ResolutionContext $ctx, ...$d) => new ...`
  murni; kode hasil & berkas AOT bebas `Reflection` (divalidasi assertion).
- `ServiceDefinition` tetap `final readonly` — mutasi tetap fatal (`Error`).
- Cold-start dari berkas AOT tidak menyentuh `ReflectionMetadataExtractor` sama sekali.

## 10. Rilis v2.10.0 — Enterprise Feature Pack (aditif)

Rilis lanjutan yang melengkapi 15 butir enterprise `ROADMAP.md` dalam empat area,
mengikuti disiplin yang sama: **nol perubahan perilaku lama, semua fitur baru pre-freeze,
semua jaminan graph tetap satu gerbang di `validateAndFreeze()`**.

### Titik integrasi (semuanya additive)

```
Container (composition-time, pre-freeze)
  when(C)->needs(dep)->give(target) ──▶ REWRITE registry:
      deps C: dep → @contextual:C|dep  (alias → target)
      ⇒ graph validator melihat edge nyata ⇒ siklus, cross-module,
        singleton-closure & missing-target tetap tervalidasi tanpa ubah resolver.
  decorate(id, decorator) ──▶ applyDecorations() di validateAndFreeze():
      definisi asli → @inner:id:base; chain decorator jadi definisi wrapper
      (first-registered = outermost); lifetime/module/lazy dipertahankan.
  registerProvider(): eager → register() langsung; Deferrable → pending,
      trigger saat get() pre-freeze ATAU otomatis di validateAndFreeze()
      bila provides()-nya direferensikan graph (deps/alias target).
  onResolving()/onResolved(): listener di registry, dipicu resolver
      hanya saat instansiasi nyata (cache hit tidak memicu); return
      non-null onResolved menggantikan instance sebelum caching.

Router
  group(attributes, fn): stack prefix/name/middleware/priority bersarang;
      add() menggabungkan prefix SEBELUM parse/signature ⇒ collision
      detection tetap O(1) atas pattern final. Rute kini membawa
      metadata 'middleware' (list service-ID).
  fallback(id) + matchOrFallback(): 404 → fallback; 405/400 tetap.
  exportRoutes()/fromCompiledArray()/RouteCache: snapshot data murni →
      file PHP atomik → include kembali → router frozen + radix
      tanpa validasi ulang per-rute.
```

### Kelas baru per layer

| Layer | Berkas | Isi |
|---|---|---|
| Domain/Container | `ServiceProviderInterface.php` | port provider + `DeferrableProviderInterface` + `BootableProviderInterface` |
| Domain/Resource | `SortSpec.php` `SortKey.php` `FilterSpec.php` `FilterCondition.php` | sort/filter spec whitelist-wajib (anti-injection boundary) |
| Domain/Validation | `MessageCatalog.php` `ValidationTranslator.php` | katalog locale (fallback `id_ID→id→*`) + interpolasi `{{label}}` |
| Domain/Exception | `ApiVersionUnsupportedException.php` | exception versi API tidak didukung |
| Application/Container | `ContextualBindingBuilder.php` | builder fluent contextual binding |
| Application/Container | `Container.php` (+metode) | when/decorate/registerProvider/bootProviders/onResolving/onResolved |
| Application/Container | `ServiceRegistry.php` (+listener), `ContainerResolver.php` (+hook event) | storage listener + pemicu resolving/resolved |
| Adapters/Router | `Router.php` (+group/fallback/export), `RouteCache.php` | groups, fallback, kompilasi cache |
| Adapters/Http | `ApiVersion.php` `ApiVersionNegotiator.php` `FormRequest.php` | versioning + form request |
| Adapters/Runtime | `TinkerSession.php` | mesin REPL stateful (tanpa I/O) |
| Infrastructure/Security | `RotatingKeyRing.php` | rotasi kunci GCM (decrypt probe semua kunci) |
| deploy/k8s | `deployment.yaml` `service.yaml` | manifests non-root + probes `/health/*` |

### Invariant yang dijaga (terverifikasi test v210)

- Semua fitur container menolak container frozen (`LogicException`) — disiplin sama.
- Contextual binding & decoration adalah **rewritten definitions**, bukan cabang
  resolver baru: `DependencyGraphValidator`, compiled plan, `warmSingletons()`,
  alias canonicalization, dan cross-module budget bekerja identik pada definisi hasil
  rewrite (siklus via `@contextual:`/`@inner:` terbukti tertangkap di test).
- Deferred provider yang tidak pernah diminta tidak pernah di-register; yang
  direferensikan graph di-register sebelum compile (tidak ada definisi hantu).
- `Router::match()` lama tidak berubah — `matchOrFallback()` hanya lapisan di atasnya;
  405 (`MethodNotAllowedException`) dan 400 (`RouteConstraintException`) tidak tertutup fallback.
- Rute cache adalah data murni (`var_export`); file hasil di-include kembali tanpa
  mengeksekusi kode aplikasi apa pun.

### Verifikasi v2.10.0

- 315 file PHP lolos `php -l` (0 gagal).
- `--self-test` penuh: **414/414** (145 baseline + 103 v280 + 59 v290 + 107 v210).
- HTTP compare monolith vs refactor: **11/11 rute byte-identik**.
- CI 5 langkah lokal: lint · self-test · v290 · v210 · composer.json — semua hijau.

## 11. Rilis v2.11.0 — RadixTree Namespace Container (aditif)

### Motivasi: hybrid container yang mencerminkan dual autoloading

Sama seperti strategi autoloading ganda (flat classmap O(1) + fallback PSR-4), container
kini punya dua struktur yang berdampingan:

```
              ┌────────────────────────────────────────┐
              │        Container::get($fqcn)           │
              └──────────────────┬─────────────────────┘
                                 │
              ┌──────────────────▼─────────────────────┐
              │  CompiledContainerPlan (flat, O(1))    │  ← jalur cepat, TIDAK berubah
              │  canonicalIds / dependencies hashmap   │
              └──────────────────┬─────────────────────┘
                                 │ hanya bila: prefix query /
                                 │ fallback / policy audit
              ┌──────────────────▼─────────────────────┐
              │  NamespaceRadixTree (segment trie, O(K))│ ← pre-built saat freeze, sealed
              │  scope annotation · subtree traversal  │
              └────────────────────────────────────────┘
```

Tree dibangun dari **service terdaftar** (definitions + alias, bukan seluruh classmap
autoloader) pada `validateAndFreeze()` SETELAH `DependencyGraphValidator` lulus —
sehingga semua ID kanonik dan terbukti ada. ID sintetis mesin internal (`@inner:*`,
`@contextual:*`, `@value:*`) dikecualikan.

### Kelas baru per layer

| Layer | Kelas | Peran |
|---|---|---|
| Domain/Container | `NamespaceRadixTree` | Trie segmen `\` dengan path compression (label majemuk `A\\B`), `idsUnderPrefix` (semantik batas segmen, terurut), `annotate`/`scopeOf` (nearest-ancestor), `seal()` immutable, `exportArray`/`fromArray` AOT |
| Domain/Policy | `NamespaceScopePolicy` | VO immutable: prefix → `public`/`internal`/`module` + `maxCrossScopeRefs` |
| Application/Container | `RadixTreeCompilerPass` | Build tree dari `CompiledContainerPlan`, anotasi scope, enforcement policy (internal = hard deny; module = budget per pasangan), lempar `ModuleDependencyViolationException` |
| tests | `V2110RadixTreeSuite` | 8 sub-suite, 87 assertion |

### API Container baru (additive)

- `configureNamespacePolicy(NamespaceScopePolicy)` — pre-freeze.
- `getByPrefix(prefix)` / `getIdsByPrefix(prefix)` — batch fetch subtree, ID-sorted,
  wajib post-freeze; alias ikut terindeks dan resolusinya melewati jalur normal.
- `registerNamespaceFallback(prefix, factory, lifetime)` — resolusi fallback untuk ID
  belum terdaftar (longest prefix menang); SINGLETON di-cache per-ID, TRANSIENT baru
  tiap panggilan; tidak pernah membayangi service terdaftar dan tidak pernah dipakai
  untuk edge graph (dep tetap wajib eksplisit).
- `namespaceTree()` / `namespaceStats()` — inspeksi + bukti kompresi.

### Invariant yang dijaga (terverifikasi test v211)

- Fast path O(1) untuk FQCN terdaftar **identik** — nol traversal pada resolusi normal
  (HTTP 11/11 byte-identik membuktikan boot penuh tak tersentuh).
- `ServiceDefinition`, `DependencyGraphValidator`, `ContainerCompiler`,
  `ContainerResolver`, `ArchitecturePolicy`: nol perubahan perilaku; hook v2.11.0
  berjalan setelah compile dan berbiaya nol saat fitur tak dipakai (guard array kosong).
- Tree sealed → `insert()`/`annotate()` melempar `LogicException`; policy tidak bisa
  dipasang setelah freeze.
- Enforccement scope berjalan pada edge graph kanonik — alias yang menunjuk target
  internal tetap tertangkap; consumer sintetis dikecualikan.
- AOT: `exportArray()` payload array murni (bebas `Closure`/`Reflection`),
  `fromArray()` mengembalikan tree sealed dengan stats identik.

### Verifikasi v2.11.0

- 319 file PHP lolos `php -l` (0 gagal).
- `--self-test` penuh: **501/501** (145 baseline + 103 v280 + 59 v290 + 107 v210 + 87 v211).
- HTTP compare monolith vs refactor: **11/11 rute byte-identik**.
- CI 6 langkah lokal: lint · self-test · v290 · v210 · v211 · composer.json — semua hijau.
- Bukti dunia nyata: boot aplikasi demo penuh → tree sealed otomatis, 18 service,
  71 segmen mentah → 23 edge (**rasio kompresi 3.09**), batch fetch
  `getByPrefix('Zef\Framework\Container')` live.

## 12. Evolusi v2.12.0 – v2.34.1 (ringkas)

Rilis pasca-v2.11.0 tetap memegang disiplin yang sama (aditif, satu gerbang
`validateAndFreeze()`, deptrac fail-on-uncovered). Peta singkat tambahan struktur per
rilis — rincian lengkap di `docs/CHANGELOG-v<versi>.md`:

| Rilis | Tema | Tambahan struktur utama |
|-------|------|--------------------------|
| v2.12.0 | Composer packaging & QA toolchain | — (tooling) |
| v2.13.0–v2.13.1 | Hardening + baseline mutasi (Infection, 8.907 mutan) | — |
| v2.14.0–v2.14.9 | Mutation deep-dive (kurikulum `EDGE-CASE-MATRIX.md`) | test `EdgeMatrix*` |
| v2.15.0 | Fase 10 — **gate mutasi 85/90 TERCAPAI** (9.032 mutan, MSI 90.4/93.1) | `src/Middleware` masuk scope |
| v2.16.0 | ZEF Maker (scaffold CLI) | `Infrastructure/Console` (22 kelas) |
| v2.17.0 | Dokumentasi resmi + toolchain | — |
| v2.18.0 | Database Core | `Domain/Database`, `Infrastructure/Database` (PDO, QueryBuilder, Migrator, Repository) |
| v2.19.0 | Event Sourcing + Transactional Outbox | `Domain/EventSourcing`, `Infrastructure/EventSourcing` (EventStore PDO/InMemory, relay `outbox:work`) |
| v2.20.0 | OpenAPI 3.1 Documentation Module | `Domain/OpenApi`, `Infrastructure/OpenApi`, `Adapters/OpenApi` (CLI `openapi:generate`) |
| v2.21.0–v2.21.1 | Configuration System v2 (+ hardening) | overlay env `ZEF_*__KEY`, secrets provider, skema fail-fast |
| v2.22.0 | Transaction orchestration & UoW-lite | hook after-commit, command bus transaksional (`TRANSACTION-HOOKS.md`) |
| v2.23.0 | Config observability + cache index radix | `RadixTreeCache`, `MeterConfigMetrics`, `ConfigMigrator` |
| v2.24.0 | Redis Distributed Lock (issue #68) | `RedisLockStore`, `LeaderElector`, scheduler cluster-safe |
| v2.25.0 | Rate limiting lanjutan | sliding window + token bucket, `TieredRateLimiter`, `RateLimitMiddleware` |
| v2.26.0 | Async Runtime fiber-native | `FiberScheduler`, `FiberChannel`, `Semaphore`, `WaitGroup` |
| v2.27.0 | Async Rules | `AsyncRuleEngine` konkuren di atas fiber scheduler |
| v2.28.0 | Env static facade deprecation | jalur migrasi `EnvInterface` (issue #55) |
| v2.29.0 | DX release | `make:app`, `rr:init`, `doctor`, `TUTORIAL-CQRS-101.md`, `PLUGINS.md` |
| v2.30.0 | Ecosystem Ports | `Domain/Storage`, `Infrastructure/Storage` (S3 SigV4 in-house, Local), `MessageTransportInterface`, `PdoJobQueue` |
| v2.31.0 | Zero-Debt & Fail-Closed Gates | relay outbox lease-based `outbox:work` |
| v2.32.0 | Unified Queue & Broker | `RedisStreamJobQueue`, CLI `queue:work/failed/retry/flush` |
| v2.33.0 | OpenAPI Runtime Gate | `OpenApiGateMiddleware` (`OPENAPI-GATE-PARITY.md`) |
| v2.34.0 | Stub Pre-scan CI | korpus fixture `tests/**` masuk gate (`docs/security/stub-prescan.md`) |
| v2.34.1 | Signed Available-at Parity | perbaikan padNano/`JobRowCodec` (`JOB-QUEUE-PARITY.md`) |
