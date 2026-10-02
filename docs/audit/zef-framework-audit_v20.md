# Audit Fitur `router` — ZEF Framework (Zeflous/zef-framework)

**Versi laporan:** v20 (menggantikan v19)
**Tanggal:** 2026-10-03
**Fokus:** Audit ekspansi fitur Router System + remediasi seluruh temuan
**Baseline audit (v19):** `389ff64a3a8ef44d554d6817ef5eba4c5f404825`
**Baseline remediasi (v20):** `51f6a1aa95284cc3174848133eb4e792a492ab93` (`origin/main`, tag `v2.35.0`)
**PR remediasi:** [#354](https://github.com/Zeflous/zef-framework/pull/354) — `fix/router-audit-v19` → `main` (**OPEN, tidak di-merge**)
**Metode:** audit statis (v19, L0–L2) + implementasi & verifikasi runtime (v20, otorisasi owner L3+).

> **Boot Contract:** AGENTS.md read (BOOT-0..BOOT-6) OK | RSI active OK | Authority: L3+ (mutasi kode diotorisasi owner untuk tugas ini)
> **Active Workflow:** `arch.module-system` / `arch.public-surface-fitness` (Router = inbound adapter hexagonal)
> **Evidence labels:** VERIFIED (dibaca dari source / dijalankan), INFERENCE (penilaian desain), UNKNOWN (tidak diverifikasi runtime).

---

## Changelog v19 → v20 (remediasi)

Seluruh temuan audit v19 diselesaikan dalam **satu PR** (#354), ditambah temuan bug/issue/typo baru yang terverifikasi. **PR tidak di-merge** (untuk direview).

### Temuan audit v19 yang diperbaiki

| # | Temuan (v19) | Status | Perbaikan | Berkas |
|---|---|---|---|---|
| 1 | **P0 (VERIFIED, keamanan):** metadata `middleware` per-route tersimpan + tampil di `route:list`, tetapi **tidak pernah dieksekusi** — `Dispatcher` memanggil handler langsung | **FIXED** | `Dispatcher::routePipeline()` membungkus handler dengan `MiddlewarePipeline` dari daftar middleware rute yang cocok (inner pipeline di dalam stack global). Fail-closed bila service tidak terdaftar / bukan `MiddlewareInterface` | `src/Adapters/Kernel/Dispatcher.php` |
| 2 | `route:list` tak punya `--json` & tak menampilkan middleware | **FIXED** | `bin/zef` `zef_route_list()`: flag `--json` + kolom `MIDDLEWARE` dan `HOST` (TEXT + JSON) | `bin/zef` |
| 3 | Radix tree di-rebuild setiap `match()` saat unfrozen | **FIXED** | Compile revision-aware (`RouteCollection::revision()` + `RouteRadixIndex::compileIfStale()`) — tidak rebuild bila tak ada registrasi baru | `RouteCollection.php`, `RouteRadixIndex.php`, `RouteMatcher.php` |
| 4 | `nameIndex['']` dari group/route tanpa `name` | **FIXED** | Nama rute dinormalisasi (`trim`, kosong ⇒ `null`); entri `''` tak pernah masuk indeks; `hasRouteName('')` = false | `RouteCollection.php` |
| 5 | Roadmap: subdomain routing multi-tenant | **IMPLEMENTED** | Group `host` + wildcard `{tenant}`/`*`; signature host-scoped (rute host & host-less bisa hidup di method+path yang sama); `HostPatternMatches`; `UrlGenerator::generateHost()` | `HostPatternMatches.php`, `RouteGroupStack.php`, `RouteMatcher.php`, `UrlGenerator.php` |
| 6 | Roadmap: route model binding otomatis | **IMPLEMENTED** | Port `RouteModelBinderInterface` (Domain), group `bindings`, diselesaikan di `Dispatcher` menjadi atribut request | `RouteModelBinderInterface.php`, `Dispatcher.php` |
| 7 | Roadmap: localization routing `/{locale}/...` | **IMPLEMENTED** | `Router::localized()` (prefix divalidasi terhadap locale yang didukung) + `LocaleNegotiator` (parsing prefix locale well-formed) | `Router.php`, `LocaleNegotiator.php` |
| 8 | Roadmap: content negotiation routing | **IMPLEMENTED** | Group `accepts` + `ContentNegotiator`; `Dispatcher` membalas **406** bila Accept tak dapat diterima | `ContentNegotiator.php`, `Dispatcher.php` |

### Bug/issue/typo baru yang ditemukan & diperbaiki

1. **Host-mismatch salah balas 405 (bukan 404)** — path khusus subdomain, saat diminta dengan host yang salah, masuk daftar `allowed` dan memicu `MethodNotAllowedException`. Kini rute ber-host lain dilewati di failure path (`RouteMatcher::failureException()`) ⇒ **404** benar. Ditemukan oleh test (probe berulang), lalu diperbaiki.
2. **Signature rute tidak host-aware** — dua rute method+path sama di host berbeda ditolak sebagai "duplikat". Kini signature berisi prefix host (`host|METHOD|/segmen`) — host-less tetap identik dengan sebelumnya (hanya prefix `''`).
3. **Parse error docblock** — komentar `type/*` / `*/*` di `ContentNegotiator` membuat `php -l` gagal (`*` mengakhiri komentar). Diparafrase.
4. **Komentar/typo** — anotasi `@phpstan-type` internal & komentar stale disinkronkan dengan bentuk record baru.

### Perbaikan CI (putaran lanjutan, commit `f12f61b`)

Setelah push pertama, CI PR #354 masih merah pada dua job. Keduanya diperbaiki:

| Job CI | Penyebab | Perbaikan |
|---|---|---|
| **PHP lint, audit, static analysis and style** | `php-cs-fixer` (PER-CS2.0 + Symfony + PhpCsFixer + PHP84) menemukan 3 file perlu diformat: `UrlGenerator.php` (blank line sebelum `break`), `RouteMatcher.php` (brace `): array {`), `Router.php` (blank line sebelum `throw`) | Diformat dengan `php-cs-fixer`; dry-run kini **0 of 849** |
| **SonarCloud Scan / Code Analysis (Quality Gate)** | `new_maintainability_issue_severity` = 15 (> 4). 4 code smell MAJOR di new-code: `php:S1142` (method > 3 return) di `ContentNegotiator::parseRange` & `RouteGroupStack::mergeHost`; `php:S103` (baris > 120 char) di `Router::match` & `Router::matchOrFallback` | Return-count diturunkan 4→3 (gabung guard); baris panjang dipecah. **Tanpa perubahan perilaku** |

### Verifikasi (bukti)

```
# Suite router (unit + integrasi, probe negatif)
vendor/bin/phpunit tests/Unit/RouterFeatureExpansionTest.php \
  tests/Unit/RouterInternalsMutantKillTest.php tests/Unit/MutantKillRound2Test.php
OK (46 tests, 125 assertions)

# Suite penuh sebagai user non-root (TMPDIR bersih)
vendor/bin/phpunit
Tests: 3763, Assertions: 140777, Skipped: 58, Failures: 0, Errors: 0

# Static analysis & style (semua hijau)
php-cs-fixer check --diff --using-cache=no   # 0 of 849 files
phpstan analyse (level max + strict-rules)   # [OK] No errors
phpcs                                        # 8/8
rector process --dry-run                     # [OK] Rector is done
composer stan:ratchet                        # 455 suppressed (ceiling 458)
deptrac analyse --fail-on-uncovered          # 0 violations, 0 uncovered
composer mutation:zones                      # zone-coverage ratchet: PASSED
assert-release-cadence.php                   # OK (12 tags, newest v2.35.0)
assert-release-docs.php                      # OK (README/SECURITY = 2.35.0)
```

> Catatan: dijalankan **sebagai root** ada 4 failure artefak environment (`EcosystemPortsV30Test`, `ConfigV2HardeningTest`) yang mengandalkan directory tak-tulislah — root menembus permission. Sebagai user non-root: **0 failure**. Bukan regresi.

### Cakupan test baru
`tests/Unit/RouterFeatureExpansionTest.php` — 19 kasus: eksekusi middleware per-route (positif + bukti negatif bahwa sebelumnya di-bypass + fail-closed), `route:list --json`, indeks nama kosong, recompile-skip radix, subdomain routing (capture tenant, koeksistensi host/host-less, mismatch 404, konflik bersarang, grammar host), model binding, localization, content negotiation (406 + grammar).

### Ratchet dokumen
- README: badge `Test PHPUnit` **3744 → 3763** (badge + alt + tabel gate + quickstart), badge `Kelas PSR-4` **1147 → 1151**.
- `docs/ROADMAP.md`: 4 item router yang belum checklist kini `[x]` + catatan bahwa `Route middleware assignment` kini **dieksekusi** (v2.36.0).

### Catatan / keputusan owner
- **Tidak di-merge** sesuai instruksi — PR ini untuk direview.
- Perubahan menyentuh perilaku runtime (eksekusi middleware, 404/406) — mohon review pada `Dispatcher::routePipeline()` dan `RouteMatcher::failureException()`.

---

## a) Ringkasan item `router` di `docs/ROADMAP.md` yang BELUM checklist (kondisi v19)

Bagian **`### Router System — src/Adapters/Router`** (baris ~55–75). Item dasar (O(log n) radix, dynamic params + constraints, built-in/custom regex constraints + ReDoS guard, method-based, 405, priority, module-scoped) **semua `[x]`**.

**Target Enterprise — belum checklist (4 item, VERIFIED dari ROADMAP.md):**

| # | Item roadmap (belum `[x]`) | Status implementasi riil (v19) |
|---|---|---|
| R1 | `Subdomain routing untuk multi-tenancy` | ❌ **Belum ada** — tidak ada dukungan host/subdomain di `Router`/`RouteMatcher`. Fondasi baru "subdomain-ready" (komentar ROADMAP §10, bukan kode). |
| R2 | `Route model binding otomatis` | ❌ **Belum ada** — tidak ada resolver model/DI dari param rute. |
| R3 | `Localization routing (/{locale}/...)` | ❌ **Belum ada** — tidak ada prefix locale otomatis / locale resolution. |
| R4 | `Content negotiation routing` | ❌ **Belum ada** — tidak ada negosiasi `Accept` → varian handler per-route. |

**Sudah `[x]` (untuk konteks, VERIFIED):** Route groups + prefix (v2.10.0), API versioning URL/header/query (v2.10.0), Route caching & compilation (v2.10.0), Route naming & reverse routing (v2.8.0), Route middleware assignment (v2.10.0, metadata), Fallback routes & custom 404 (v2.10.0).

> **Status v20:** keempat item R1–R4 kini **`[x]`** di ROADMAP (diimplementasikan pada PR #354).

---

## b) Hasil audit implementasi Router aktual

### Peta modul (VERIFIED — `wc -l`)

| File | Baris | Peran |
|---|---|---|
| `src/Adapters/Router/Router.php` | 292 | **Fasad publik**: `add()`, `group()`, `fallback()`, `freeze()`, `match()`, `matchOrFallback()`, `exportRoutes()`, `fromCompiledArray()`, `patternFor()`, `routeNames()`, budget. |
| `src/Adapters/Router/RouteCollection.php` | 259 | Storage: budget, collision signature, name index, sort lazy, export/hydrate compiled. |
| `src/Adapters/Router/RouteGroupStack.php` | 136 | Stack atribut grup bersarang (prefix/name/middleware/priority) + merge. |
| `src/Adapters/Router/RoutePatternParser.php` | 183 | Codec pola: parse segmen, split path (collapse `//` N-9/#176), signature kanonik, `matchRoute()` (+ rawurldecode, ZEF-DEEP-04). |
| `src/Adapters/Router/RouteMatcher.php` | 192 | Matching: fast path constraint-aware → slow path 405/400/404 + `matchOrFallback()`. |
| `src/Adapters/Router/RouteRadixIndex.php` | 165 | Radix index immutable-after-freeze (`compile`, `candidates`, `advance`, `collectRouteCandidates`). |
| `src/Adapters/Router/RadixNode.php` | 24 | Node radix (static/dynamic edge + route terminasi). |
| `src/Adapters/Router/RouteCache.php` | 204 | Cache compile → file PHP atomik + envelope (version + SHA-256 fingerprint) + `loadIfFresh()` (v2.31.0, issue #175). |
| `src/Adapters/Router/UrlGenerator.php` | 114 | Reverse routing dari named route, rawurlencode + validasi constraint. |
| `src/Domain/Router/RouteDefinition.php` | 88 | Value-object spec rute (validasi method/path/handler/name). |
| `src/Domain/Validation/RouteConstraintValidator.php` | 167 | Constraint built-in (`int,uint,alpha,slug,uuid,hex`) + custom regex + ReDoS guard + diagnostics. |
| `src/Domain/Validation/RouteConstraintPatternException.php` | 16 | Exception diagnostik regex. |
| `src/Domain/Exception/Route{NotFound,Constraint,Cache}Exception.php` | 21/22/14 | Exception ingress 404/400 + cache. |
| `src/Adapters/Http/ApiVersionNegotiator.php` | 195 | Negosiasi versi API (path > header > query > default; whitelist + grammar numerik, #171). |
| `src/Adapters/Http/ApiVersion.php` | — | VO hasil negosiasi. |

### Fitur yang SUDAH ada (VERIFIED)

- **Matching & performa:** radix tree O(log n), fast path constraint-aware, sort prioritas (`priority > staticCount > constrainedCount > sequence`).
- **Parameter dinamis:** `{name}` / `{name:constraint}`, `assertUniqueParams`, decode percent-encoding sebelum constraint (round-trip dengan UrlGenerator).
- **Constraint:** 6 built-in + custom regex dengan guard ReDoS (tolak nested quantified groups) + `assertKnown` fail-fast.
- **Metode & semantik error:** 405 `MethodNotAllowedException` (+ `Allow` dari `array_keys`), HEAD otomatis melayani GET (`effectiveMethods`), 400 `RouteConstraintException`, 404 `RouteNotFoundException`.
- **Grouping:** prefix/name/middleware/priority bersarang (merge rekursif di `RouteGroupStack::push()`).
- **Naming/reverse routing:** `RouteDefinition.name`, `RouteCollection::patternFor()`, `UrlGenerator` (rawurlencode + validasi constraint + tolak nilai stringify-'' → issue #282).
- **Fallback:** `Router::fallback()` + `matchOrFallback()` (404 → fallback; 405/400 tetap dijaga).
- **Caching/kompilasi:** `exportRoutes()`/`fromCompiledArray()`/`RouteCache` (atomik, envelope version+fingerprint, `loadIfFresh()` soft-miss).
- **API versioning:** `ApiVersionNegotiator` (path `/v{n}` > header `X-Api-Version` > query `api_version` > default).
- **Module-scoped routes:** `module` disimpan per record.
- **CLI:** `bin/zef route:list` mencetak METHOD/PATH/NAME/HANDLER/MODULE/PRIO (formatter teks; **belum ada flag `--json`** — VERIFIED `grep json bin/zef` hanya menemukan `list --json` dan `openapi:generate`).
- **Integrasi kernel:** `KernelBootSequence` → `PipelineFactory::build()` (PSR-15, urut priority tertinggi-dulu, issue #174) → `MiddlewarePipeline` → **terminal `Dispatcher`** yang memanggil `Router::match()`.
- **Test:** ~5.853 baris test khusus router/route (`EdgeMatrixRouterKernelTest` 1079, `MutationDeepRouterTest` 499, `RouteCacheStalenessTest` 209, `RouteCollectionMutantKillTest` 108, `RouteConstraintValidatorMutantKillTest` 86, `RouterInternalsMutantKillTest` 153, `RouterParamDecodingTest` 128, dll).

### Temuan penting (gap struktural, VERIFIED — kondisi v19)

1. 🔴 **Metadata `middleware` per-route didaftarkan tapi TIDAK PERNAH diterapkan.** `Router::add()` menyimpan `'middleware' => $group['middleware']` di setiap record (Router.php:115); `route:list` menampilkannya; **tetapi** `MiddlewarePipeline`/`PipelineFactory` hanya membaca `middleware.stack` **global** dari config, dan `Dispatcher` memanggil handler **langsung** tanpa middleware per-route. Tidak ada satu pun konsumen yang membaca `$route['middleware']` untuk menyusun pipeline. → Fitur "Route middleware assignment" praktis **parsial** (metadata saja, bukan eksekusi). **→ FIXED di v20.**
2. 🔴 **Group `name` prefix default `''` membuat rute dalam grup tanpa `name:` menjadi ber-name kosong** (`$route['name'] ?? ''`), sehingga `route:list` menampilkan kolom NAME kosong (bukan `-`) untuk rute anonim di dalam grup. Minor, tapi menandakan `nameIndex` bisa punya key `''`. **→ FIXED di v20.**
3. 🟡 **`Route::middleware` tidak di-export ke bentuk yang dieksekusi** — `RouteSpecExtractor`/OpenAPI membaca `getRoutes()` untuk dokumentasi; konsumsi middleware masih tertunda (lihat #1). **→ FIXED di v20.**
4. 🟡 **Radix index rebuild saat unfrozen:** `RouteMatcher::match()` memanggil `$this->radix->compile($routes)` bila `!$frozen` — benar secara semantik, tetapi rebuild penuh tiap request bila router tidak dibekukan (jalur non-produksi). **→ FIXED di v20.**
5. 🟢 **`use Zef\Framework\Policy\ArchitecturePolicy`** di `Router.php` menandakan router sudah sadar policy (budget).

---

## c) Daftar fitur router yang KURANG + rekomendasi konkret (kondisi v19)

Legenda prioritas: **P0** = blocker/taruhan correctness-kompleteness, **P1** = nilai tinggi, jelas & terikat arsitektur, **P2** = nilai/effort lebih rendah atau menunggu prasyarat.
Estimasi kompleksitas: **S** (≤ ~1 hari), **M** (2–4 hari), **L** (≥ 1 minggu).

### P0 — Perbaiki jalur eksekusi middleware per-route
| Aspek | Detail |
|---|---|
| **Status** | Parsial (metadata ada, eksekusi tidak) — VERIFIED → **FIXED di v20** |
| **Dampak** | Fitur inti framework yang "terlihat ada" (terekspor di `getRoutes()`, ditampilkan `route:list`) **tidak berjalan**. Konsumen yang mengandalkan `$route['middleware']` untuk auth/rate-limit per-route akan diam-diam tidak terlindungi. Risiko kebenaran/keamanan. |
| **Desain** | Susun sub-pipeline per-rute dari `$route['middleware']` (daftar service ID) lalu gabung dengan pipeline global. Titik sambung paling bersih: `Dispatcher::handle()` — setelah `Router::match()` mengembalikan record, resolusi middleware dari container (via `RequestScope`) dan bangun `MiddlewarePipeline` ber-terminal handler. |
| **File disentuh** | `src/Adapters/Kernel/Dispatcher.php`, `src/Adapters/Kernel/PipelineFactory.php`, `src/Adapters/Kernel/KernelBootSequence.php`; test di `tests/Unit/EdgeMatrixRouterKernelTest.php`. |
| **Kompleksitas** | **M** |

### P0 — `route:list --json` + kolom middleware
| Aspek | Detail |
|---|---|
| **Status** | Belum ada (`--json` hanya untuk `list`/`openapi:generate`) — VERIFIED → **FIXED di v20** |
| **Dampak** | Otomasi/CI & inspeksi mesin (mis. gate "setiap rute wajib punya middleware auth") membutuhkan bentuk terstruktur. |
| **Desain** | Tambah flag `--json` pada `zef_route_list()` (bin/zef:401): emit `json_encode(getRoutes(), JSON_PRETTY_PRINT)` memuat `method, pattern, name, handler, module, priority, middleware`. Tetap pertahankan output tabel default. Tambahkan kolom/daftar MIDDLEWARE pada mode tabel. |
| **File disentuh** | `bin/zef` (fungsi `zef_route_list`) + test CLI. |
| **Kompleksitas** | **S** |

### P1 — Subdomain routing untuk multi-tenancy (R1)
| Aspek | Detail |
|---|---|
| **Status** | Belum ada — VERIFIED → **IMPLEMENTED di v20** |
| **Desain** | Tambah dimensi host pada registrasi & pencocokan: `Router::domain(string $pattern, callable $routes)` + field `host` pada `RouteRecord`. Matching membaca `Host` (sudah di-parse lewat `HostAuthorityParser`) lalu memfilter kandidat. Subdomain param `{tenant}` dapat di-decode sama seperti segmen dinamis. Prioritaskan pencocokan host-spesifik > host generik > tanpa-host. |
| **File disentuh** | `Router.php`, `RouteCollection.php` (field `host`), `RouteMatcher.php`, `RouteRadixIndex.php`, `RouteGroupStack.php`; reuse `src/Adapters/Http/HostAuthorityParser.php`. |
| **Kompleksitas** | **L** (perubahan bentuk `RouteRecord` → migrasi cache + bump fingerprint). |

### P1 — Localization routing `/{locale}/...` (R3)
| Aspek | Detail |
|---|---|
| **Status** | Belum ada — VERIFIED → **IMPLEMENTED di v20** |
| **Desain** | Helper grup `Router::localized(array $locales, callable $routes)` yang meng-expand `/{locale}` (constraint ke daftar locale) di kiri prefix; sediakan `LocaleNegotiator` kecil (path > Accept-Language > default) meniru pola `ApiVersionNegotiator`. Simpan `locale` sebagai atribut request. |
| **File disentuh** | `Router.php` (helper), `src/Adapters/Http/LocaleNegotiator.php` (baru), `RouteConstraintValidator.php` (constraint `locale` opsional); test baru. |
| **Kompleksitas** | **M** |

### P1 — Route model binding otomatis (R2)
| Aspek | Detail |
|---|---|
| **Status** | Belum ada — VERIFIED → **IMPLEMENTED di v20** |
| **Desain** | Tambah hook opsional `RouteModelBinderInterface` yang, pada `Dispatcher` (setelah match), menerima `(string $param, string $value, string $type)` → objek/404. Binding dideklarasikan lewat atribut handler atau metadata rute (`bindings`), default = no-op (backward compatible). |
| **File disentuh** | `src/Domain/Router/RouteModelBinderInterface.php` (baru), `Dispatcher.php` (`withAttribute` hasil binding), `Router.php`/`RouteDefinition.php` (metadata `bindings`), container wiring. |
| **Kompleksitas** | **M–L** |

### P2 — Content negotiation routing (R4)
| Aspek | Detail |
|---|---|
| **Status** | Belum ada — VERIFIED → **IMPLEMENTED di v20** |
| **Desain** | Negosiator `Accept` → pilih varian handler/format. Implementasi ringan: `ContentNegotiator` di `Dispatcher` yang memilih serializer/response encoder berdasar `Accept` + `produces` metadata. Dukung skema whitelist format per-rute. |
| **File disentuh** | `src/Adapters/Router/ContentNegotiator.php` (baru), `Dispatcher.php`, metadata rute. |
| **Kompleksitas** | **M** |

### P2 — Optimasi & operasional (hardening)
| Aspek | Detail |
|---|---|
| **Status** | Gap kecil namun nyata → **FIXED di v20** |
| **Item** | (a) **Hindari rebuild radix tiap request saat unfrozen** — compile revision-aware. (b) **`route:list --json`** sudah masuk P0. (c) **`nameIndex['']`** akibat prefix name kosong — dinormalisasi. |
| **File disentuh** | `Router.php`/`RouteCollection.php` (invalidation + name guard), `bin/zef`. |
| **Kompleksitas** | **S–M** |

---

## d) Prioritas ekspansi fitur (P0 / P1 / P2)

### P0 — Correctness & tooling
1. **Terapkan middleware per-route** (jalur eksekusi) — *M* — **DONE (v20)**.
2. **`route:list --json` + kolom middleware** — *S* — **DONE (v20)**.

### P1 — Fitur enterprise terikat arsitektur
3. **Subdomain routing** (R1) — *L* — **DONE (v20)**.
4. **Localization routing** (R3) — *M* — **DONE (v20)**.
5. **Route model binding** (R2) — *M–L* — **DONE (v20)**.

### P2 — Nilai lanjutan / hardening
6. **Content negotiation routing** (R4) — *M* — **DONE (v20)**.
7. **Optimasi radix-unfrozen + `route:list` filter + guard nama rute kosong** — *S–M* — **DONE (v20)**.

### Rekomendasi urutan rilis (INFERENCE)
- **v2.36.x:** P0 #1–#2 + P1/P2 (PR #354, menunggu review).
- **v2.37.x+:** item lanjutan (filter `route:list`, koordinasi HATEOAS/JSON:API).

> **Governance note:** Implementasi/mutasi kode, commit, PR, dan perubahan ROADMAP pada v20 dijalankan dengan **otorisasi owner (L3+)** untuk tugas ini. PR #354 **tidak di-merge**.

---

## Ringkasan bukti (evidence)

| Klaim | Sumber | Label |
|---|---|---|
| 4 item router belum `[x]` (v19) | `docs/ROADMAP.md` baris 67/69/71/72 | VERIFIED |
| Middleware per-route tersimpan tapi tak diterapkan (v19) | `Router.php:115`, `PipelineFactory.php:42`, `Dispatcher.php` | VERIFIED |
| Middleware per-route kini dieksekusi (v20) | `Dispatcher::routePipeline()` (v2.36.0) | VERIFIED |
| `route:list` tanpa `--json` (v19) → ada (v20) | `bin/zef:403-407` | VERIFIED |
| Radix rebuild saat unfrozen (v19) → revision-aware (v20) | `RouteMatcher.php`, `RouteRadixIndex::compileIfStale()` | VERIFIED |
| Subdomain/model-binding/locale/content-negotiation absen (v19) → ada (v20) | `grep -rn -i` + berkas baru | VERIFIED |
| Suite penuh hijau pasca-remediasi | `vendor/bin/phpunit` (3763 tests, 0 failures) | VERIFIED |
| CI style + SonarCloud QG diperbaiki | commit `f12f61b`; php-cs-fixer 0/849 | VERIFIED |
| Baseline `HEAD` == `origin/main` | `git rev-parse` sandbox | VERIFIED |
| Estimasi kompleksitas & urutan rilis | penilaian desain | INFERENCE |

*Catatan: v19 murni audit statis; v20 menambahkan implementasi + verifikasi runtime (test suite, static analysis, mutation zone ratchet).*
