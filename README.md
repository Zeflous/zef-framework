# ZEF Framework — Hexagonal · PSR-15 · RoadRunner · PHP 8.4+

<div align="center">
  <img src="assets/readme-banner.svg" alt="ZEF Framework — Hexagonal · PSR-15 · RoadRunner · PHP 8.4+" width="100%">
</div>

## <br>

<div align="center">

  <img src="https://img.shields.io/badge/PHP-8.4%2B-777BB4?style=for-the-badge&amp;logo=php&amp;logoColor=white" alt="PHP 8.4+">
  <img src="https://img.shields.io/badge/RoadRunner-4.1-1f2937?style=for-the-badge" alt="RoadRunner 4.1">
  <img src="https://img.shields.io/badge/Architecture-Hexagonal-38bdf8?style=for-the-badge" alt="Hexagonal">
  <br>
  <img src="https://img.shields.io/badge/Kelas%20PSR--4-1146-818cf8?style=for-the-badge" alt="1146 kelas">
  <img src="https://img.shields.io/badge/Test%20PHPUnit-3721-c084fc?style=for-the-badge" alt="3721 test">
  <img src="https://img.shields.io/badge/Coverage%20gate-%E2%89%A5%2090%25-22c55e?style=for-the-badge" alt="Coverage gate 90%">
  <img src="https://img.shields.io/badge/Mutation%20gate-MSI%20%E2%89%A5%2085-f59e0b?style=for-the-badge" alt="Mutation gate MSI 85">
  <br>
  <img src="https://img.shields.io/badge/License-MIT-blue?style=for-the-badge" alt="MIT">
  <img src="https://img.shields.io/badge/Rilis%20terdokumentasi-v2.34.1-0ea5e9?style=for-the-badge" alt="v2.34.1">

</div>

## <br>

<div align="center">

  <a href="https://github.com/Zeflous/zef-framework/actions/workflows/ci.yml"><img src="https://github.com/Zeflous/zef-framework/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://github.com/Zeflous/zef-framework/actions/workflows/php-sast.yml"><img src="https://github.com/Zeflous/zef-framework/actions/workflows/php-sast.yml/badge.svg" alt="PHP SAST"></a>
  <a href="https://github.com/Zeflous/zef-framework/actions/workflows/github-code-scanning/codeql"><img src="https://github.com/Zeflous/zef-framework/actions/workflows/github-code-scanning/codeql/badge.svg" alt="CodeQL"></a>
  <a href="https://github.com/Zeflous/zef-framework/actions/workflows/secret-scan.yml"><img src="https://github.com/Zeflous/zef-framework/actions/workflows/secret-scan.yml/badge.svg" alt="Secret Scan"></a>
  <a href="https://github.com/Zeflous/zef-framework/actions/workflows/dependency-review.yml"><img src="https://github.com/Zeflous/zef-framework/actions/workflows/dependency-review.yml/badge.svg" alt="Dependency Review"></a>  <br>
  <a href="https://github.com/Zeflous/zef-framework/actions/workflows/docs-check.yml"><img src="https://github.com/Zeflous/zef-framework/actions/workflows/docs-check.yml/badge.svg" alt="API Documentation"></a>
  <a href="https://github.com/Zeflous/zef-framework/actions/workflows/phpbench.yml"><img src="https://github.com/Zeflous/zef-framework/actions/workflows/phpbench.yml/badge.svg" alt="PHPBench"></a>
  <!-- Mutation & Release dipicu tag rilis, bukan push ke main. Tanpa ?branch=, badge Mutation
       resolve ke run terakhir di main (2026-09-17, era trigger lama) yang gagal — tampak merah.
       Param ?branch= dipasang ke run rilis terakhir (v2.34.1); perbarui saat sinkronisasi README tiap rilis. -->
  <a href="https://github.com/Zeflous/zef-framework/actions/workflows/mutation.yml"><img src="https://github.com/Zeflous/zef-framework/actions/workflows/mutation.yml/badge.svg?branch=v2.34.1" alt="Mutation (v2.34.1)"></a>
  <a href="https://github.com/Zeflous/zef-framework/actions/workflows/sbom.yml"><img src="https://github.com/Zeflous/zef-framework/actions/workflows/sbom.yml/badge.svg" alt="SBOM"></a>
  <a href="https://github.com/Zeflous/zef-framework/actions/workflows/pages.yml"><img src="https://github.com/Zeflous/zef-framework/actions/workflows/pages.yml/badge.svg" alt="Docs &amp; Pages"></a>
  <a href="https://github.com/Zeflous/zef-framework/actions/workflows/release.yml"><img src="https://github.com/Zeflous/zef-framework/actions/workflows/release.yml/badge.svg?branch=v2.34.1" alt="Release (v2.34.1)"></a>
  <a href="https://github.com/Zeflous/zef-framework/actions/workflows/release-drafter.yml"><img src="https://github.com/Zeflous/zef-framework/actions/workflows/release-drafter.yml/badge.svg" alt="Release Drafter"></a>
  <a href="https://github.com/Zeflous/zef-framework/actions/workflows/auto-fix.yml"><img src="https://github.com/Zeflous/zef-framework/actions/workflows/auto-fix.yml/badge.svg" alt="Auto Fix"></a>
  <a href="https://github.com/Zeflous/zef-framework/actions/workflows/composer-lock.yml"><img src="https://github.com/Zeflous/zef-framework/actions/workflows/composer-lock.yml/badge.svg" alt="Composer Lock"></a>

</div>

<p align="center">
  <b>Framework PHP 8.4 berarsitektur Hexagonal dengan worker persisten RoadRunner.</b>
</p>

<br>

## <img src="https://img.shields.io/badge/-%20-0b1220" width="6" height="20" alt=""> Kenapa ZEF

Banyak framework PHP tumbuh dari kenyamanan. ZEF tumbuh dari pembongkaran: satu berkas raksasa 12.639 baris dipindahkan **verbatim** ke struktur multi-berkas, lalu dijaga ketat oleh gerbang otomatis.

<table width="100%">
  <tr>
    <td width="33%" valign="top">
      <h3 align="center">Batasan yang Diuji</h3>
      <p align="center">Arah dependensi antar-layer ditegakkan <code>deptrac</code> dengan <code>--fail-on-uncovered</code> — 0 violation. Arsitektur yang hanya ada di diagram tidak dihitung.</p>
    </td>
    <td width="33%" valign="top">
      <h3 align="center">Mutan, Bukan Baris</h3>
      <p align="center">Ukuran kualitasnya adalah <b>mutan yang benar-benar terbunuh</b>, bukan baris yang dieksekusi. Menambah test yang hanya menyentuh baris tanpa asersi tidak menaikkan skor.</p>
    </td>
    <td width="33%" valign="top">
      <h3 align="center">Tanpa Composer pun Jalan</h3>
      <p align="center">Classmap statis <code>autoload/zef_autoload.php</code> memuat seluruh kelas first-party. PHPUnit, Composer, dan jaringan bukan syarat untuk menjalankan self-test.</p>
    </td>
  </tr>
</table>

<br>

## Fitur Utama

<table width="100%">
  <tr>
    <th align="left">Area</th>
    <th align="left">Yang tersedia</th>
  </tr>
  <tr>
    <td><b>Container &amp; DI</b></td>
    <td>Autowiring rekursif (<code>#[Inject]</code>, <code>#[Value]</code>, <code>#[Target]</code>) · contextual binding · decoration chain · deferred provider · AOT compile ke berkas PHP murni</td>
  </tr>
  <tr>
    <td><b>HTTP &amp; Router</b></td>
    <td>Radix-tree router · route group/prefix bersarang · route cache terkompilasi · conditional GET (ETag/304) · RFC 9457 Problem Details · negosiasi versi API · middleware PSR-15</td>
  </tr>
  <tr>
    <td><b>CQRS</b></td>
    <td>Command bus &amp; query bus · handler terpisah dari transport · integrasi container</td>
  </tr>
  <tr>
    <td><b>OpenAPI Docs</b></td>
    <td>Spesifikasi <b>OpenAPI 3.1</b> otomatis dari route table + PHP 8.4 Attributes · endpoint <code>/openapi.json</code> (ETag/304) · Swagger UI dev-only · CLI <code>bin/zef openapi:generate</code> · export Postman v2.1 · serializer JSON/YAML dependency-free · validator struktural · <b>runtime gate PSR-15</b> <code>OpenApiGateMiddleware</code> — enforcement kontrak per-request: 12 batas (404 pass-through, 405+<code>Allow</code>, 400 parameter/body + JSON Pointer, 415, 401/403 security, respons opt-in fail-closed, boot fail-closed), rejection problem+json RFC 9457, precedensi deterministik 405→401/403→415→400 (v2.33.0)</td>
  </tr>
  <tr>
    <td><b>Configuration</b></td>
    <td><b>Config System v2</b>: multi-source (<code>config/*.php</code> + overlay env <code>ZEF_DATABASE__HOST</code> + secrets provider) · skema tervalidasi <i>fail-fast</i> yang melaporkan semua pelanggaran sekaligus · accessor bertipe <code>string()/int()/enum()</code> · export config terkompilasi untuk boot produksi</td>
  </tr>
  <tr>
    <td><b>Event Sourcing</b></td>
    <td>Port <code>EventStore</code> + adapter <code>InMemory</code>/<code>PDO</code> (persist atomik dalam transaction ambien) · <code>AggregateRoot</code> + kebijakan snapshot · <code>Projector</code> catch-up ber-checkpoint · transactional outbox + relay (retry eksponensial, dead letter)</td>
  </tr>
  <tr>
    <td><b>Database</b></td>
    <td>Query Builder + adapter PDO (savepoint transaction bersarang) · <code>Migrator</code> berversi dengan lock TTL · base <code>Repository</code></td>
  </tr>
  <tr>
    <td><b>Cache</b></td>
    <td>Cache bertag · tier L1/L2 · lock lease · store Redis &amp; APCu untuk deployment terdistribusi</td>
  </tr>
  <tr>
    <td><b>Jobs</b></td>
    <td>Scheduler cron &amp; fixed-interval · <code>CronExpression</code> · middleware dedup at-least-once</td>
  </tr>
  <tr>
    <td><b>Keamanan</b></td>
    <td>AES-256-GCM + rotasi key ring · TOTP (RFC 6238) &amp; Base32 · CSRF · origin policy · rate limiter (in-memory/Redis/APCu) · rate limit tiered (sliding window + token bucket, v2.25.0) · SecurityPolicy fail-closed</td>
  </tr>
  <tr>
    <td><b>Observability</b></td>
    <td>OpenTelemetry (tracer, span, propagator, meter) · health aggregator · endpoint Prometheus <code>/metrics</code></td>
  </tr>
  <tr>
    <td><b>Runtime</b></td>
    <td>Worker persisten RoadRunner v4.1 · async runtime fiber-native (<code>FiberScheduler</code>, channel, semaphore, wait group, cancellation + timeout, v2.26.0) · async rules engine konkuren (<code>AsyncRuleEngine</code>: batas konkurensi, deadline kooperatif per rule, fail-fast graceful, v2.27.0) · <code>.rr.yaml</code> siap pakai + <code>rr:init</code>/<code>doctor</code> preflight (v2.29.0) · scaffold aplikasi standalone <code>make:app</code> (v2.29.0) · CLI <code>bin/zef</code> dengan 11 generator · REPL <code>tinker</code></td>
  </tr>
  <tr>
    <td><b>Ecosystem</b></td>
    <td>Object storage port + adapter <code>LocalStorage</code> (tulis atomik) &amp; <code>S3CompatibleStorage</code> (SigV4 in-house tanpa SDK — AWS/MinIO/Ceph/R2/GCS XML-API, v2.30.0) · transport pesan <code>InMemoryMessageTransport</code> (FIFO terbatas, id deterministik, v2.30.0) · antrean job tahan lama <code>PdoJobQueue</code> (klaim transaksional portabel) + <code>PdoJobIdempotencyStore</code> (efek exactly-once, v2.30.0) · antrean durable Redis Streams <code>RedisStreamJobQueue</code> (klaim atomik single-Lua XRANGE→XDEL+SREM, v2.32.0) · CLI antrean <code>queue:work/failed/retry/flush</code> (worker + DLQ ops, v2.32.0) · peta integrasi <a href="docs/INTEGRATIONS.md">INTEGRATIONS.md</a></td>
  </tr>
</table>

<details>
<summary><b>Riwayat rilis selengkapnya (v2.8.0 → v2.34.1)</b> — 41 berkas CHANGELOG · 28 entri ringkas</summary>

<br>

Setiap rilis bersifat **aditif**: perilaku lama tidak diubah.

| Rilis | Fokus |
|:------|:------|
| **v2.8.0** | Tagged services · URL generator · ETag/304 · ProblemDetails · pagination · Prometheus · health aggregator · cache bertag · scheduler · AES-GCM · TOTP · validator |
| **v2.9.0** | Advanced Autowiring Engine: atribut, interface binding berlapis, variadic koleksi, graf dependensi, AOT compiler |
| **v2.10.0** | Enterprise Feature Pack: contextual binding, decoration, route cache, API versioning, sort/filter whitelist, key ring, i18n, form request, tinker, manifest K8s |
| **v2.11.0** | RadixTree Namespace Container |
| **v2.12.0–v2.13.1** | Penyelarasan toolchain Composer, hardening (PHPStan level *max* + strict-rules, PHPCS + Slevomat, Rector, Deptrac fail-on-uncovered), migrasi suite ke PHPUnit native |
| **v2.14.0–v2.14.9** | Kampanye mutation *deep-dive* per fase: Security, Container, Runtime lifecycle, HTTP/Router/Kernel, Observability, Domain inti, Infrastructure |
| **v2.15.0–v2.16.0** | Lanjutan ratchet mutasi & penutupan escape per zona |
| **v2.17.0** | Situs dokumentasi resmi diterbitkan ke GitHub Pages |
| **v2.18.0** | **Database Core**: Query Builder + PDO, Migrator, Repository base |
| **v2.19.0** | **Event Sourcing**: EventStore + adapter, AggregateRoot, Projector, transactional outbox |
| **v2.20.0** | **OpenAPI 3.1 Docs**: spesifikasi otomatis dari route table + Attributes, validator struktural, Swagger UI dev-only, CLI + export Postman |
| **v2.21.0** | **Configuration System v2**: multi-source + env overlay + secrets provider, skema fail-fast collect-all, accessor bertipe + native enum, config terkompilasi |
| **v2.21.1** | **Configuration hardening**: kueri pola radix (`query()/subtree()/longestMatch()`), decorator secrets tangguh (retry + backoff + stale fallback), hint penolakan string kosong, compiled config `chmod 0600` + header keamanan |
| **v2.22.0** | **Transaction orchestration & UoW-lite**: port `TransactionManagerInterface` + hook after-commit, `TransactionalCommandBus` (commit/rollback per command, flush UnitOfWork sebelum commit), `CommandBus` fan-out event pasca-commit |
| **v2.22.1** | **Hardening pass**: dokumentasi semantik error hook, retry opt-in untuk transient failure UoW, guard slow-hook kooperatif (tiga item Low tinjauan v2.22.0, issue #65) |
| **v2.23.0** | **Event Sourcing Hardening**: guard stream-continuity (versi wajib +1), snapshot no-regress (CAS), dead-letter `requeue()` + `requeueDeadLetters()`, backstop schema `UNIQUE(event_id)`/`UNIQUE(global_sequence)`, upcasting `UpcasterInterface` + `EventUpcaster` (rename chain + identity guard) |
| **v2.24.0** | **Redis Distributed Lock** (issue #68): adapter `RedisLockStore` (Lua atomic acquire/release/refresh), leader election, scheduler cluster-safe, claim idempotent lintas node |
| **v2.25.0** | **Rate Limiting**: sliding window counter + token bucket (refill kontinu, cost-aware), tiering `RateLimitRule` + `TieredRateLimiter` (most restrictive wins), header `RateLimit-*` draft IETF + legacy `X-RateLimit-*`, rantai identitas identity > API key > IP, middleware PSR-15 + wiring env fail-fast |
| **v2.26.0** | **Async Runtime**: scheduler fiber-native (`FiberScheduler::run()/spawn()/await()/awaitAll()/suspend()/sleep()/timeout()`), `FiberChannel` bounded FIFO, `Semaphore` pembatas konkurensi, `WaitGroup`, `CoroutineLocal`, `CancellationToken(Source)`, deteksi deadlock, surfacing unobserved failure, timer monotonic + clock/sleeper port (deterministik di test) |
| **v2.27.0** | **Async Rules**: engine evaluasi rule konkuren di atas fiber scheduler (`AsyncRuleEngine` + port `AsyncRuleEngineInterface`), verdict total per rule (`RuleVerdict` pass/fail/skip + throwable), `RuleReport` urutan input, `RuleEngineOptions` (batas konkurensi, deadline kooperatif per rule, fail-fast graceful via pembatalan kooperatif), zona mutasi `app-rules` MSI 96.40% |
| **v2.28.0** | **Env Facade Deprecation**: penutup issue #55 — `Env::int/bool/string/csv` kini `@deprecated` + `E_USER_DEPRECATED` (ter-suppress di runtime), pengganti `EnvInterface::readInt/readBool/readString/readCsv` dengan tanda tangan identik; sensus call statis `src/` 0 | 
| **v2.29.0** | **DX Release**: `make:app` scaffold aplikasi standalone (path-repo leluhur bersama + wrapper maker), `rr:init` konfigurasi RoadRunner dari knob `ZEF_*` (collision-safe), `doctor` preflight lingkungan + boot smoke, tutorial Zero-to-Hero `TUTORIAL-CQRS-101.md`, kontrak plugin `PLUGINS.md` |
| **v2.30.0** | **Ecosystem Ports**: port `ObjectStorageInterface` + adapter `LocalStorage` (atomik, bebas traversal) & `S3CompatibleStorage` (SigV4 in-house, vektor resmi AWS, tanpa SDK), transport `InMemoryMessageTransport` + `ReceivedMessage`, antrean durable `PdoJobQueue` (klaim SELECT+DELETE portabel, seq transaksional, UNIQUE job_id) + `PdoJobIdempotencyStore` (exactly-once effect), `INTEGRATIONS.md` — nol paket runtime baru |
| **v2.31.0** | **Zero-Debt & Fail-Closed Gates**: SonarCloud 879 → 0 pelanggaran + scope fail-closed milik-ZEF (PR #251/#256/#257), Snyk strict fail-closed + bukti SARIF terkonsolidasi jadi satu check (PR #246/#262), cermin alert code-scanning → issue, antrean PR zero-click (arm-on-open + rantai merge + cron 20m), hardening konfigurasi F-01..F-24 (PR #255), ZEF-DX-01..10 dan ZEF-DEEP-15..22 ditutup — nol fitur runtime baru |
| **v2.32.0** | **Unified Queue & Broker**: driver antrean durable `RedisStreamJobQueue` di atas Redis Streams (klaim atomik single-Lua XRANGE→XDEL+SREM tanpa XREADGROUP/pending-entry, ordering paritas PDO `priority DESC/available_at ASC/seq ASC`, dedupe SET padanan `UNIQUE(job_id)`, nano zero-padded 20-digit anti Lua-double), CLI terpadu `queue:work`/`queue:failed`/`queue:retry`/`queue:flush` (SIGTERM drain antar-job, memory guard exit 2), zona mutasi `infra-job-redis` MSI 98.35 — nol paket runtime baru |
| **v2.33.0** | **OpenAPI Runtime Gate**: PSR-15 `OpenApiGateMiddleware` menegakkan kontrak dokumen OpenAPI 3.1 per-request (matriks paritas 12 batas dikommit sebelum implementasi; precedensi 405→401/403→415→400; rejection RFC 9457 problem+json + `Allow`; security dari identitas terverifikasi `zef.auth.identity` + bukti kehadiran; boot fail-closed reuse `OpenApiSpecValidator`/`OpenApiSecurityValidator`; `forRoutes()` — dokumen yang ditegakkan = yang di-serve), `OpenApiSchemaChecker` subset JSON-Schema persis `Schema::toArray()`, fix `release.yml` create-release idempotent, zona mutasi `openapi-gate` MSI 95.39/1064 — nol paket runtime baru |
| **v2.34.0** | **Stub Pre-scan CI**: pre-scan korpus fixture `tests/` (matriks paritas 12 dimensi INCLUDED/EXCLUDED dikommit sebelum implementasi) — project SonarCloud terkarantina `zeflous_zef-framework-stubs` di-scan scope ter-pin positif dengan gate zero-tolerance bugs/vulnerabilities/hotspots (baseline terukur nol; kondisi overall tanpa dependensi new-code period; provisioning idempotent fail-closed di dalam workflow), ratchet jumlah fixture `tests/fixtures.limit` fail-closed dua arah + assert ukuran `files` SonarCloud, keluarga correctness Semgrep masuk 4 lane php-sast dari tarball ter-pin yang sama (0/652 + 0/190 terukur), fix `release.yml` publish draft blind-spot (v2.32.0+v2.33.0), required checks 11→12, indirection kredensial curl anti-FP gitleaks — nol paket baru, nol kode produk berubah |
| **v2.34.1** | **Paritas Available-at Bertanda**: fix `RedisStreamJobQueue::padNano()` — deadline negatif tak ber-pad membalik urutan antrean & verdict ketersediaan di pembandingan leksikografis Lua (issue #272; matriks paritas 12 batas dikommit sebelum fix) — encoding order-preserving 9's complement: byte non-negatif identik dengan v2.32–v2.34 (**nol migrasi**), negatif = `'-'` + 19 digit komplemen, `PHP_INT_MIN` round-trip eksak; doktrin corrupt-entry liveness-over-preservation; +32 test / +74.090 aseri paritas 3 adapter |

Rincian per rilis: [`docs/CHANGELOG-v2.34.1.md`](docs/CHANGELOG-v2.34.1.md), [`v2.34.0.md`](docs/CHANGELOG-v2.34.0.md), [`v2.33.0.md`](docs/CHANGELOG-v2.33.0.md), [`v2.32.0.md`](docs/CHANGELOG-v2.32.0.md), [`v2.31.0.md`](docs/CHANGELOG-v2.31.0.md), [`v2.30.0.md`](docs/CHANGELOG-v2.30.0.md), [`v2.29.0.md`](docs/CHANGELOG-v2.29.0.md), [`v2.28.0.md`](docs/CHANGELOG-v2.28.0.md), [`v2.27.0.md`](docs/CHANGELOG-v2.27.0.md), [`v2.26.0.md`](docs/CHANGELOG-v2.26.0.md), [`v2.25.0.md`](docs/CHANGELOG-v2.25.0.md), [`v2.23.0.md`](docs/CHANGELOG-v2.23.0.md), [`v2.22.0.md`](docs/CHANGELOG-v2.22.0.md), [`v2.21.1.md`](docs/CHANGELOG-v2.21.1.md), [`v2.21.0.md`](docs/CHANGELOG-v2.21.0.md), [`v2.19.0`](docs/CHANGELOG-v2.19.0.md), [`v2.18.0`](docs/CHANGELOG-v2.18.0.md), [`v2.17.0`](docs/CHANGELOG-v2.17.0.md), [`v2.16.0`](docs/CHANGELOG-v2.16.0.md), [`v2.15.0`](docs/CHANGELOG-v2.15.0.md), [`v2.14.0`](docs/CHANGELOG-v2.14.0.md) — atau seluruh berkas lainnya di [`docs/`](docs/README.md).

</details>

<br>

## Persyaratan

| Komponen | Versi | Catatan |
|:---------|:------|:--------|
| **PHP** | `^8.4` | Diuji pada PHP 8.4.25 (NTS) |
| **RoadRunner** | `^4.1` | `spiral/roadrunner-http` + `nyholm/psr7`; binary <kbd>bin/rr</kbd> sudah disertakan |
| **Redis** | opsional | Rate limiter & cache terdistribusi (`ext-redis`) |
| **APCu** | opsional | Rate limiter instance tunggal (`ext-apcu`) |
| **Composer** | opsional | Framework berjalan tanpa Composer lewat classmap statis |

<br>

## Instalasi

### Opsi A — Tanpa Composer

Classmap statis memuat seluruh kelas first-party; self-test berjalan tanpa PHPUnit dan tanpa jaringan.

```bash
git clone https://github.com/Zeflous/zef-framework.git
cd zef-framework

php bin/zef --self-test          # 501 assertion, 19 suite
php bin/zef --self-test=v280     # hanya suite fitur v2.8.0
php bin/zef --self-test=router   # filter substring case-insensitive
```

### Opsi B — Dengan Composer <kbd>disarankan untuk pengembangan</kbd>

```bash
composer install

composer test            # suite PHPUnit native
composer coverage:gate   # coverage + ambang 90%
composer stan            # PHPStan level max + strict-rules
composer deptrac         # konformansi arsitektur hexagonal
composer lint            # php -l seluruh berkas first-party
composer mutation        # gerbang mutasi (MSI)
composer docs            # API reference Doctum → build/api
```

> Autoloader statis tetap terdaftar melalui `autoload.files` ketika Composer dipakai, sehingga keduanya aman berdampingan. Interface PSR resmi (`psr/*`) digunakan otomatis bila tersedia; jika tidak, shim kondisional di `src/Compat/Psr/` yang aktif.

<br>

## Quick Start

<div align="center">
  <img src="docs/assets/quickstart.svg" alt="Sesi terminal ZEF: dev server, /health, rute /, dan self-test" width="100%">
  <br>
  <sub><b>Cuplikan quick start</b> — animasi terminal SVG di <code>docs/assets/quickstart.svg</code> (bukan rekaman asciinema; <code>asciinema</code> tidak tersedia di lingkungan build). Setiap baris keluaran disalin dari sesi nyata di repositori ini dengan <b>PHP 8.4.25</b>.</sub>
</div>

### 1. Menjalankan server

```bash
php bin/zef --serve 0.0.0.0:8080
# atau
php -S 0.0.0.0:8080 public/index.php
```

### 2. Worker persisten (produksi)

Bridge RoadRunner dan binary <kbd>bin/rr</kbd> sudah termasuk dalam paket — konfigurasi siap di `.rr.yaml`:

```bash
vendor/bin/rr serve      # worker: bin/worker.php
```

### 3. Memakai framework dari kode

```php
<?php

declare(strict_types=1);

require 'autoload/zef_autoload.php';

use Zef\App\Bootstrap;

$app = Bootstrap::createApp(debug: true);   // host tepercaya, provider Core/Toko/Health/Middleware
// jalankan melalui public/index.php atau worker RoadRunner
```

```php
<?php

declare(strict_types=1);

use Zef\Framework\Cache\InMemoryCache;
use Zef\Framework\Cache\InMemoryCacheStore;
use Zef\Framework\Cache\SystemCacheClock;
use Zef\Framework\Container\Autowiring\AutowireCompilerPass;

$cache = new InMemoryCache(new InMemoryCacheStore(), new SystemCacheClock());

$pass   = new AutowireCompilerPass(configValues: ['db.host' => 'localhost'], module: 'billing');
$result = $pass->process($container, [PaymentService::class]);
$container->validateAndFreeze();            // graf dependensi lengkap
```

<details>
<summary><b>Contoh lanjutan</b> — contextual binding, route group, decoration</summary>

<br>

```php
<?php

declare(strict_types=1);

$container->when(BillingService::class)->needs(PaymentGateway::class)->give('gateway.stripe');

$container->decorate(
    'mailer',
    static fn ($ctx, $inner) => new LoggingMailer($inner, $ctx->get('logger')),
);

$router->group(['prefix' => '/api/v2', 'name' => 'api.v2.'], static function ($router): void {
    $router->add('GET', '/users', 'user.index', name: 'users');
});
```

</details>

<br>

## Tabel Rute Bawaan

Aplikasi demo mengekspos rute berikut dari `modules/` dan `plugins/`:

| Rute | Handler | Sumber | Catatan |
|:-----|:--------|:-------|:--------|
| `GET /` | `HomeHandler` | `modules/Core` | — |
| `GET /about` | `AboutHandler` | `modules/Core` | — |
| `GET /health` | `AggregateHealthHandler` | `modules/Health` | agregat; 503 saat degraded |
| `GET /health/live` | `LiveHandler` | `modules/Health` | liveness |
| `GET /health/ready` | `ReadyHandler` | `modules/Health` | readiness |
| `GET /metrics` | `MetricsHandler` | `modules/Health` | format Prometheus |
| `GET /toko` | `TokoHandler` | `plugins/Toko` | plugin contoh |
| `GET /toko/produk/{id:int}` | `ProdukDetailHandler` | `plugins/Toko` | constraint `{id:int}` |

Respon `404`, `400` (pelanggaran constraint), dan `405` juga aktif.

<br>

## CLI — `bin/zef`

<table width="100%">
  <tr><th align="left">Perintah</th><th align="left">Kegunaan</th></tr>
  <tr><td><kbd>bin/zef list</kbd></td><td>Katalog seluruh command (mendukung <code>--json</code>)</td></tr>
  <tr><td><kbd>bin/zef --self-test</kbd></td><td>Menjalankan 501 assertion self-test</td></tr>
  <tr><td><kbd>bin/zef --serve 0.0.0.0:8080</kbd></td><td>Server HTTP pengembangan</td></tr>
  <tr><td><kbd>bin/zef route:list</kbd></td><td>Inspeksi rute beserta namanya</td></tr>
  <tr><td><kbd>bin/zef module:list</kbd></td><td>Modul terdaftar (hasil boot nyata)</td></tr>
  <tr><td><kbd>bin/zef plugin:list</kbd></td><td>Plugin yang ditemukan di disk</td></tr>
  <tr><td><kbd>bin/zef config:show &lt;key&gt;</kbd></td><td>Dump konfigurasi teragregasi (JSON-safe)</td></tr>
  <tr><td><kbd>bin/zef tinker</kbd></td><td>REPL dengan <code>$app</code> dan <code>$container</code> siap pakai</td></tr>
  <tr><td><kbd>bin/zef make:*</kbd></td><td>11 generator: <code>app</code> (aplikasi standalone, v2.29.0), <code>module</code>, <code>plugin</code>, <code>handler</code>, <code>middleware</code>, <code>config</code>, <code>command</code>, <code>query</code>, <code>entity</code>, <code>valueobject</code>, <code>service</code></td></tr>
  <tr><td><kbd>bin/zef rr:init</kbd></td><td><strong>v2.29.0</strong> — generate <code>.rr.yaml</code> dari knob <code>ZEF_*</code> + flag (<code>--force</code> untuk regenerasi)</td></tr>
  <tr><td><kbd>bin/zef doctor</kbd></td><td><strong>v2.29.0</strong> — preflight lingkungan: PHP, ekstensi, autoloader, RR bridge/binary, boot smoke (exit 1 hanya bila FAIL)</td></tr>
</table>

```bash
bin/zef make:module katalog
bin/zef make:handler Produk --module=katalog --path=/katalog/produk
bin/zef make:command PlaceOrder --module=katalog
```

<br>

## Struktur Proyek

```text
zef-framework/
├── assets/readme-banner.svg     # aset README
├── autoload/zef_autoload.php    # classmap statis (fallback tanpa Composer)
├── bin/
│   ├── zef                      # CLI: list, --self-test, --serve, route:list, make:* , tinker
│   ├── worker.php               # worker RoadRunner
│   └── rr                       # binary RoadRunner
├── public/index.php             # entrypoint HTTP (web SAPI)
├── src/                         # 360 berkas PHP — inti framework
│   ├── Bootstrap.php
│   ├── Compat/Psr/              # 23 shim PSR kondisional
│   ├── Domain/                  # 181 — port, kontrak, value object, validator
│   ├── Application/             #  66 — mesin orkestrasi in-process (CQRS, jobs, observability)
│   ├── Infrastructure/          #  47 — adapter outbound: cache, redis, otlp, crypto, prometheus
│   ├── Adapters/                #  35 — adapter inbound: http, router, kernel, runtime
│   └── Middleware/              #   7 — middleware PSR-15
├── app/Bootstrap.php            # aplikasi demo (createApp + provider)
├── modules/                     # modul: Core, Health
├── plugins/                     # plugin contoh: Toko
├── tests/                       # 90 berkas PHP — suite PHPUnit native + self-test
├── benchmarks/ContainerBench.php
├── deploy/                      # Dockerfile · docker-compose.yml · k8s/
├── docs/                        # 25 CHANGELOG + panduan (lihat bagian Dokumentasi)
├── scripts/
│   ├── lint.php                 # lint seluruh berkas PHP
│   ├── build_docs.php           # Markdown → situs HTML statis
│   └── ci/                      # gate: coverage, phpstan-baseline, zone-coverage, abandoned-policy
└── .github/workflows/           # 13 workflow
```

<br>

## Keamanan Runtime

Runtime worker persisten berbeda mendasar dari PHP-FPM: proses hidup lama, sehingga **state lintas-request adalah risiko pertama**, bukan pengecualian.

<table width="100%">
  <tr><th align="left">Kontrol</th><th align="left">Perilaku</th></tr>
  <tr>
    <td><b>SecurityPolicy</b></td>
    <td>Bootstrap fail-closed: konfigurasi keamanan yang tidak lengkap menghentikan start, bukan menurunkan perlindungan secara diam-diam.</td>
  </tr>
  <tr>
    <td><b>Host tepercaya</b></td>
    <td><code>ZEF_TRUSTED_HOSTS</code> default <code>localhost,127.0.0.1,::1,zef.test</code>.</td>
  </tr>
  <tr>
    <td><b>CSRF</b></td>
    <td>Aktif bila <code>ZEF_SECURITY_CSRF_SECRET</code> (≥ 32 byte) diisi; nama cookie/header dan flag Secure/HttpOnly/SameSite dapat dikonfigurasi.</td>
  </tr>
  <tr>
    <td><b>Batas body</b></td>
    <td><code>ZEF_MAX_BODY_BYTES</code> → <code>413</code> saat terlampaui.</td>
  </tr>
  <tr>
    <td><b>Rate limit</b></td>
    <td>In-memory, Redis, atau APCu; mode terdistribusi dipilih lewat switch non-rahasia.</td>
  </tr>
  <tr>
    <td><b>SAST &amp; rahasia</b></td>
    <td>Semgrep (<code>php-sast.yml</code>), CodeQL, dan pemindaian rahasia (<code>gitleaks</code>) berjalan sebagai check wajib pada setiap PR — kini termasuk <b>pre-scan korpus fixture <code>tests/</code></b>: Bugs/Security SonarCloud lewat project terkarantina dengan gate zero-tolerance + ratchet jumlah fixture fail-closed dua arah (v2.34.0).</td>
  </tr>
</table>

**Kebijakan pelaporan kerentanan** ada di [`SECURITY.md`](SECURITY.md).

<br>

## Variabel Lingkungan

| Variabel | Default | Keterangan |
|:---------|:--------|:-----------|
| `ZEF_ENV` | — | Penanda lingkungan aplikasi |
| `ZEF_DEBUG` | `0` | Mode debug (diagnostik verbose) |
| `ZEF_MAX_BODY_BYTES` | bawaan framework | Batas ukuran request body (`413` bila lebih) |
| `ZEF_TRUSTED_HOSTS` | `localhost,127.0.0.1,::1,zef.test` | Daftar host tepercaya (CSV) |
| `ZEF_SECURITY_CSRF_SECRET` | — | Secret CSRF ≥ 32 byte; mengaktifkan CSRF |
| `ZEF_SECURITY_CSRF_TOKEN_BYTES` · `_COOKIE` · `_HEADER` · `_SAMESITE` · `_SECURE` · `_HTTP_ONLY` | — | Penyetelan CSRF |
| `ZEF_SECURITY_ORIGIN_POLICY` · `ZEF_SECURITY_ALLOWED_ORIGINS` | — | Kontrol origin |
| `ZEF_SECURITY_RATE_LIMIT` · `_MAX` · `_WINDOW` · `_MAX_KEYS` · `_DISTRIBUTED_RATE_LIMIT` | — | Rate limiting global (SecurityRuntimeMiddleware) |
| `ZEF_SECURITY_RATE_LIMIT_TIERS` | — (nonaktif) | Daftar JSON tier `RateLimitMiddleware` (lihat CHANGELOG v2.25.0) |
| `ZEF_SECURITY_RATE_LIMIT_ALGORITHM` | `sliding` | Algoritma tier: `sliding` atau `token` |
| `ZEF_SECURITY_RATE_LIMIT_FAIL_OPEN` | `false` | Lanjut tanpa kuota saat penyimpanan gagal (fail-open) |
| `ZEF_SECURITY_HSTS` · `ZEF_SECURITY_CSP` | — | Header keamanan respons |
| `ZEF_WORKER_MAX_JOBS` | `0` (tanpa batas) | Kapasitas job per worker sebelum daur ulang |
| `ZEF_WORKER_MEMORY_LIMIT` | `0` (nonaktif) | Batas memori worker (byte) |

> Seluruh switch `ZEF_SECURITY_*` adalah **selector non-rahasia**: nilainya dibaca dari lingkungan dan tidak pernah di-hardcode, dan secret (mis. `ZEF_SECURITY_CSRF_SECRET`) tidak boleh ditulis ke repositori, log, atau berkas konfigurasi yang dapat dibaca publik.

<br>

## Gerbang Kualitas

Sebuah perubahan tidak dianggap selesai sebelum **gerbang independen** hijau. "Test lulus" bukan bukti kualitas, dan "pipeline hijau" bukan bukti keamanan.

<table width="100%">
  <tr><th align="left">#</th><th align="left">Gerbang</th><th align="left">Perintah</th><th align="left">Ambang / bukti</th></tr>
  <tr><td>1</td><td>Syntax</td><td><kbd>composer lint</kbd></td><td><code>879</code> berkas first-party, 0 kegagalan</td></tr>
  <tr><td>2</td><td>Suite PHPUnit native</td><td><kbd>composer test</kbd></td><td><code>3721</code> test · <code>140860</code> assertion · 6 skipped</td></tr>
  <tr><td>3</td><td>Coverage</td><td><kbd>composer coverage:gate</kbd></td><td>ambang statement <b>90%</b> (diukur Xdebug)</td></tr>
  <tr><td>4</td><td>Mutation testing</td><td><kbd>composer mutation</kbd></td><td><code>--min-msi=85 --min-covered-msi=90</code></td></tr>
  <tr><td>5</td><td>Analisis statis</td><td><kbd>composer stan</kbd></td><td>PHPStan level <b>max</b> + strict-rules, baseline ter-ratchet</td></tr>
  <tr><td>6</td><td>Konformansi arsitektur</td><td><kbd>composer deptrac</kbd></td><td><code>--fail-on-uncovered</code>, 0 violation</td></tr>
  <tr><td>7</td><td>Gaya &amp; modernisasi</td><td><kbd>composer format:check &amp;&amp; composer phpcs &amp;&amp; composer rector:check</kbd></td><td>php-cs-fixer (PER-CS2.0 + Symfony) · PHPCS + Slevomat · Rector 8.4</td></tr>
</table>

```bash
composer lint            # Linted 879 PHP files — 0 failure(s).
composer test            # Tests: 3721, Assertions: 140860, Skipped: 6
php bin/zef --self-test  # PASSED: 501  FAILED: 0
```

<details>
<summary><b>Mengapa MSI, bukan sekadar coverage</b></summary>

<br>

**MSI** (*Mutation Score Indicator*) = mutan terdeteksi ÷ **seluruh** mutan yang dihasilkan. Mutan yang tidak tercakup test tetap dihitung, sehingga test yang hanya mengeksekusi baris tanpa meng-*assert* perilaku tidak bisa menaikkan skor.

| Metrik | Definisi |
|:-------|:---------|
| **MSI** | mutan terdeteksi ÷ seluruh mutan |
| **Covered MSI** | mutan terdeteksi ÷ mutan yang tercakup test |
| **Mutation Code Coverage** | mutan tercakup ÷ seluruh mutan — *reachability* test, bukan ketajaman asersi |

Kampanye mutasi dijalankan per **zona kanonik** (`scripts/f16_zones.tsv`, 27 zona) dengan `--filter`, sehingga setiap area punya angka MSI-nya sendiri. Ratchet per-zona berjalan di `ci.yml` pada setiap push/PR, sedangkan suite agregat dijalankan `mutation.yml` dan **dituntut** `release.yml` sebelum rilis dipublikasikan.

</details>

<br>

## CI/CD

20 workflow pada `.github/workflows/`. `main` dilindungi: 13 check wajib efektif — union proteksi classic BP (12 konteks) + ruleset `main` (6 konteks, 5 tumpang tindih; tabel kanonik di [`docs/GOVERNANCE.md`](docs/GOVERNANCE.md) §1.1) — PR wajib, dan aturan berlaku juga untuk admin.

| Workflow | Peran |
|:---------|:------|
| `ci.yml` | Lint, self-test, PHPStan, PHPCS, Deptrac, ratchet mutasi per-zona |
| `php-sast.yml` | Semgrep — termasuk ERROR floor atas `tests/`, `scripts/`, `tools/` + keluarga correctness |
| `stub-prescan.yml` | Pre-scan korpus fixture `tests/` — project SonarCloud terkarantina + ratchet jumlah fixture fail-closed |
| `mutation.yml` | Suite mutasi agregat (tag rilis & dispatch manual) |
| `secret-scan.yml` · `dependency-review.yml` | Pemindaian rahasia (gitleaks) & review dependensi |
| `docs-check.yml` · `pages.yml` | Pemeriksaan API docs & publikasi situs dokumentasi |
| `sbom.yml` · `release.yml` · `release-drafter.yml` | SBOM, penerbitan rilis, draf catatan rilis |
| `phpbench.yml` | Benchmark performa container |
| `auto-fix.yml` · `composer-lock.yml` | Perbaikan gaya otomatis & bootstrap lockfile (manual) |
| `sonarcloud.yml` · `snyk-security.yml` | Analisis mutu SonarCloud (bugs/quality) & keamanan Snyk |
| `code-scanning-issues.yml` | Sinkronisasi alert code-scanning ke isu pelacak (terjadwal) |
| `auto-update-prs.yaml` | Antrean PR zero-click — update & merge FIFO saat gerbang hijau |
| `wiki.yml` · `sbom-generate.yml` | Indeks API wiki (manual) & generator SBOM reusable |

<br>

## Dokumentasi

Situs dokumentasi diterbitkan otomatis ke GitHub Pages pada setiap push ke `main`, dan setiap berkas juga dapat dibaca langsung dari repositori.

| Dokumen | Isi |
|:--------|:----|
| [`docs/README.md`](docs/README.md) | Indeks dokumentasi |
| [`docs/INSTALLATION.md`](docs/INSTALLATION.md) | Persyaratan, Composer, RoadRunner, Docker/K8s, variabel lingkungan |
| [`docs/CLI.md`](docs/CLI.md) | Referensi lengkap `bin/zef` |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Pembongkaran monolith, layer hexagonal, aturan arah dependensi |
| [`docs/QUALITY.md`](docs/QUALITY.md) | Gerbang kualitas, mutation testing per area, triage mutan ekuivalen |
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | Worker persisten RoadRunner, state lintas-request, observabilitas |
| [`docs/GOVERNANCE.md`](docs/GOVERNANCE.md) | Kebijakan gerbang rilis &amp; ratchet |
| [`docs/ROADMAP.md`](docs/ROADMAP.md) | Rencana &amp; status fitur |
| [`docs/EDGE-CASE-MATRIX.md`](docs/EDGE-CASE-MATRIX.md) | Kurikulum uji edge-case per fase kampanye mutasi |
| [`docs/security/php-sast.md`](docs/security/php-sast.md) | Panduan SAST PHP |
| [`docs/security/stub-prescan.md`](docs/security/stub-prescan.md) | Matriks paritas pre-scan korpus fixture `tests/` (12 dimensi INCLUDED/EXCLUDED) |
| [`docs/security/snyk-security.md`](docs/security/snyk-security.md) | Gate keamanan Snyk (SAST/SCA/IaC) & strategi status check |

```bash
composer docs                  # API reference Doctum → build/api
php scripts/build_docs.php     # situs dokumentasi → build/docs
```

<br>

## Kontribusi

1. **Branch dari `main`** dan gunakan nama deskriptif dengan prefix peran, mis. `fix/rate-limiter-window` atau `docs/installation-clarity`.
2. **Jalankan gerbang secara lokal sebelum membuka PR** — CI akan menuntut hal yang sama:
   ```bash
   composer lint && composer test && composer stan && composer deptrac && composer format:check
   ```
3. **Satu perubahan, satu tujuan.** Perubahan aditif lebih mudah ditinjau daripada yang mengubah perilaku lama.
4. **Sertakan bukti, bukan klaim.** Untuk perubahan gate atau keamanan, cantumkan keluaran perintah yang mendukung.
5. **Jangan pernah menuliskan rahasia** ke kode, konfigurasi, log, atau catatan PR. Gunakan variabel lingkungan dengan nama yang jelas.
6. **Untuk kerentanan keamanan**, jangan buka issue publik — ikuti [`SECURITY.md`](SECURITY.md).

<br>

## Lisensi

**MIT** — deklarasi pada [`composer.json`](composer.json) dan teks penuh pada [`LICENSE`](LICENSE).

<div align="center">
  <sub>Hak cipta © 2026 <b>mbetixz</b> · <a href="LICENSE">MIT License</a></sub>
</div>

<br>

<div align="center">
  <sub>
    Dibangun dengan <b>PHP 8.4</b> · <b>Hexagonal</b> · <b>RoadRunner</b><br>
    Dokumentasi terbit otomatis di setiap push ke <code>main</code>.
  </sub>
</div>
