# CHANGELOG v2.35.0 — Kampanye Remediasi Audit Penuh + Keselamatan Container

**Tema**: rilis dengan **koreksi bug terbanyak dalam sejarah repo** — 47 issue
ditutup dalam satu rentang rilis: 5 temuan audit runtime dinamis (#278–#282),
**36 temuan deep logic audit** (#300–#335: 6 HIGH, 13 MEDIUM, 15 LOW + 2
penutup #319/#320), dan 5 temuan re-audit fitur container yang baru saja
dikirim (#343–#347). Ditambah satu **fitur keselamatan runtime container**
(lifetime-capture guard + service middleware), dokumentasi mutan ekuivalen
ter-formalisasi (#299), refresh menyeluruh dokumen stale, dan pengaktifan
efektif gerbang SonarCloud sebagai required check. Setelah merge PR terakhir
antrean, **open-issue queue repositori kosong sepenuhnya** — target eksplisit
*"fokus kurangi bugs yang segudang pada open issue"* tercapai sebelum fitur
ekspansi berikutnya dijalankan.

Rentang: setelah CHANGELOG-v2.34.1 (tag `v2.34.1`, 2026-10-01, merge PR #274)
sampai tag `v2.35.0` — membawa 14 PR: #275, #276, #277 (dokumentasi), #298
(audit #278–#282), #337 (audit & refresh docs), #336 (test pembunuh mutan
#299), #338 (HIGH #300–#305), #339 (MEDIUM #306–#318), #340 (LOW #321–#335),
#342 (re-baseline sonar/junit), #341 (fitur container), #348 (penutup #319/#320),
#349 (mutan ekuivalen terdokumentasi + S103), #350 (container round 2 #343–#347).

## Angka kunci

| Metrik | v2.34.1 | v2.35.0 |
|:-------|:--------|:--------|
| Test PHPUnit (CI junit) | 3539 | **3744** (+205) |
| Asersi PHPUnit (CI junit) | 140435 | **140907** |
| Kelas PSR-4 (classmap statis) | 1146 | **1147** (+`SingletonSubtreeTracker`) |
| Korpus fixture `tests/fixtures.limit` | 212 | **218** |
| Open issue | 42 | **0** |
| Lint berkas first-party | 861 | **880** |
| Mutan ekuivalen terdokumentasi | 0 | **36** (`@infection-ignore-all` ber-justifikasi) |

Seluruh angka klaim diverifikasi terhadap ground truth pada merge terakhir
antrean: junit CI 3744 test / 140907 asersi / 6 skipped (Redis aktif di runner),
classmap `autoload/zef_autoload.php` 1147 entri, korpus disk 218 berkas PHP
(ratchet fail-closed dua arah), lint 880 berkas 0 kegagalan, self-test 501/501.

## Kampanye deep logic audit — 36 bug (#300–#335)

Deep audit baris-per-baris + PoC runtime pada `7d3d294` menemukan 36 bug logika
yang **lolos 16 gerbang CI** (bukan style/mutu — semuanya bug perilaku yang
suite maupun PHPStan max tidak tangkap). Baseline saat audit: PHPUnit 3539 OK,
PHPStan max clean. Remediasi dieksekusi dalam tiga batch severity + satu
penutup, satu commit + satu test regresi fokus per issue.

### Batch HIGH — #300–#305 (PR #338)

| Issue | Area | Bug | Fix |
|:------|:-----|:----|:----|
| #300 (high, security) | Database / QueryBuilder | `SqlExpression` di posisi value WHERE **di-bind sebagai parameter** — PDO cast `__toString()` → membandingkan `updated_at >= 'NOW()'` sebagai string literal → baris salah | Ekspresi masuk komposisi SQL, bukan placeholder |
| #301 (high) | Event Sourcing / upcasting | Upcaster payload-only (legal per kontrak) tidak me-rename → `isset(byType[type])` tetap true → batch jalan 16x lalu melempar false-positive rename | Pengecekan tipe pasca-transform, bukan pra |
| #302 (high) | OpenAPI / schema union | Properti union (`string\|int`) menghasilkan `{"type":"object","oneOf":[...]}` — **schema unsatisfiable**; gate runtime menolak dokumen hasil generatornya sendiri | Union = `oneOf` langsung tanpa `type` pembungkus |
| #303 (high) | HTTP / emitter | `ETagMiddleware` hash body via `(string)` → cursor stream tertinggal di EOF → emitter SAPI echo **0 byte** untuk 200 GET fresh | Rewind eksplisit + hash dari isi, bukan sisa-baca |
| #304 (high, security) | Security / env parsing | `Env::readBool()` memetakan nilai tak dikenal ('enabled'/'disabled'!) ke **false** → satu typo config mematikan kontrol security diam-diam | `readBoolStrict()` fail-closed: kata dikenal atau boot error |
| #305 (high) | DI / autowiring | Parameter union/intersection optional non-final tidak menulis apa pun ke positional plan → **semua argumen setelahnya bergeser** | Rencana union eksplisit dengan offset posisi |

### Batch MEDIUM — #306–#318 (PR #339, 19 test / 45 asersi baru)

| Issue | Area | Fix |
|:------|:-----|:----|
| #306 | middleware | CORS berjalan tepat setelah error handler — short-circuit 429/403/500 kini membawa header CORS |
| #307 | middleware | Region nilai auth-scheme (`Bearer <token> …`) teredaksi penuh |
| #308 | config | Nilai ter-resolve secret dimasking `string('******')` di pesan error validasi/aksesor |
| #309 | config | `CompiledConfigSource::load()` idempoten (plain `require`) |
| #310 | config | Berkas temp ber-secret dibuat 0600 **sebelum** konten ditulis, mode terkonfigurasi sebelum rename |
| #311 | container | `triggerRequired()` re-scan sampai set referensi berhenti tumbuh — rantai deferred provider ter-trigger transitif |
| #312 | job | Scheduler commit cursor penerus setelah tiap enqueue sukses — kegagalan mid-tick tidak lagi menduplikasi fire |
| #313 | job | Tie-break leksikografis stream-id Redis dihapus (urutan scan sudah menjamin entry id ASC) |
| #314 | openapi | `applyPropertyMeta()` meneruskan `oneOf`/`anyOf`/`allOf` |
| #315 | openapi | `min`/`max` `int\|float` ujung-ke-ujung: generasi, serialisasi, penegakan `numericIssues()` |
| #316 | openapi | Field `required()->nullable()` tidak lagi terdaftar required |
| #317 | security | Binding CSRF per-prinsipal opsional: MAC mencakup konteks `CSRF_BINDING_ATTRIBUTE` (session id); atribut kosong = perilaku legacy |
| #318 | validation | Nilai kosong yang di-`skipEmpty` tidak lagi muncul sebagai data tervalidasi; `required()` tetap gagal keras |

### Batch LOW — #321–#335 (PR #340)

| Issue | Area | Bug → Fix ringkas |
|:------|:-----|:------------------|
| #321 | Redis rate-limit store | `increment()` menebak counter saat member script non-numerik → fail-loud `RateLimitStoreException` |
| #322 | Redis rate-limit store | Bucket key hanya hash `$key` — window tidak ikut identitas → window ikut kunci bucket |
| #323 | Async runtime | Callback cancellation yang melempar membatalkan loop → snapshot+clear sebelum firing, isolasi per callback |
| #324 | Job Scheduler | `register()` mereset cursor saat re-register → cursor dipertahankan (catch-up burst tidak menduplikasi) |
| #325 | Cron | Budget scan 4 tahun salah lintas abad non-kabisat (gap 2096→2104) → koreksi aritmetika jendela |
| #326 | TaggableCache | Reverse index ditulis tanpa TTL → TTL reverse = min(TTL value, lease sliding 24j) |
| #327 | Cache key normalizer | `trim()` sebelum validasi membuat alias kunci → whitespace ditolak, bukan di-strip |
| #328 | CORS | Semua OPTIONS ber-Origin di-intercept → preflight = OPTIONS **+** `Access-Control-Request-Method` |
| #329 | Request ingress | `Content-Length: ' 123'` (OWS RFC 9110) gagal digit-check → trim sebelum validasi |
| #330 | Origin policy | `isset(user, pass)` AND → user tanpa pass lolos → OR; userinfo mana pun = malformed |
| #331 | Trusted hosts | Allow-list kosong fail-open diam-diam → flag opt-in `failClosedOnEmptyList` |
| #332 | Health | Nol indikator = hijau → `degraded` + marker check `health.indicators` |
| #333 | YAML serializer | Explicit-key `"? foo"` tidak ter-quote → masuk kelas leading-char |
| #334 | S3 transport | PUT bertubuh tanpa Content-Type → default eksplisit `application/octet-stream` |
| #335 | Dead code | `app/Middleware/*` = 7 duplikat same-FQN yang divergen → `git rm` + pembersihan rule Sonar |

### Penutup kampanye — #319–#320 (PR #348)

Dua temuan LOW yang sengaja ditunda dari batch #340 (area `SecurityPolicy`/
`SecurityRateLimitWiring` yang baru dirapikan fix #304, dibundel sebagai
follow-up workstream yang sama agar tidak bertabrakan hunk):

- **#319** — `ZEF_SECURITY_RATE_LIMIT_ALGORITHM=fixed` diterima enum tapi
  ternary wiring memetakan semua non-token ke `SlidingWindowRateLimiter` —
  operator memilih `fixed` diam-diam mendapat semantik sliding (kontrak enum
  sendiri berjanji "never a silent fallback"). Fix: `match` ekshaustif tanpa
  default — `FixedWindow` kini me-wire `InMemoryRateLimiter` (fixed-window
  asli: counter per bucket, reset di boundary), dan algoritma keempat di masa
  depan fail-fast `UnhandledMatchError`. Test regresi mem-pin wiring per
  algoritma via reflection + kuota fixed-window end-to-end (2 lolos, ke-3 =
  429, `Retry-After` = window penuh).
- **#320** — `ZEF_SECURITY_CSRF_TTL=0` ("tanpa kedaluwarsa", kontrak yang
  didokumentasikan `assertCsrfCookiePolicy` dan `CsrfTokenManager`) di-clamp
  `max(1, ...)` oleh `envPositiveInt` menjadi **TTL 1 detik** — setiap request
  unsafe-method 403 satu detik setelah token terbit. Fix: parser baru
  `envNonNegativeInt` mempertahankan 0; matriks parsing (0 eksplisit, unset,
  7200, typo `72OO`, `-5`) + format legacy 2-bagian dipin test.

## Audit round 2 — container pasca-fitur (#343–#347, PR #350)

Re-audit atas fitur container yang dikirim PR #341 menemukan lima celah pada
mesin keselamatan yang baru itu sendiri — semuanya ditutup dalam satu PR:

| Issue | Temuan | Fix |
|:-------|:-------|:----|
| #344 (high) | `NamespaceFallbackResolver::resolve()` memanggil factory mentah — tanpa init guard/depth budget/cycle detection; self-pull = rekursi unbounded | `InitializationGuard` bersama + depth budget fiber-scoped |
| #343 (medium) | Lifetime-capture guard per-konteks — root-container pull dari body factory membuat konteks baru → transient tertangkap senyap ke singleton | `SingletonSubtreeTracker` per-fiber tingkat container + cek di `resolveRoot()` |
| #345 (medium) | TRANSIENT namespace fallback tidak pernah melewati `resolveInContext()` → seluruh mesin lifetime bolong untuk fallback | Cek capture di `assertConstructionSafety()`; fallback singleton ikut tracker |
| #346 (low) | Middleware tidak pernah melihat konstruksi fallback — kontrak "onion di sekeliling konstruksi" path-dependent | Konstruksi fallback melewati `ServiceMiddlewarePipeline` |
| #347 (low) | Middleware melihat id sintetis `@inner:*`; null short-circuit dilaporkan sebagai "factory returned null." | Id `@...` bypass onion; pesan null membedakan middleware vs factory |

Primitif inti: `SingletonSubtreeTracker` — stack singleton-in-flight per-fiber,
enter/exit simetris via `finally` dari `ServiceInstantiator::instantiate()` dan
`NamespaceFallbackResolver::construct()`; semantics `pop` meniru
`ResolutionContext::pop()` (id non-top diabaikan, unwind parsial aman).

## Fitur: keselamatan container runtime (PR #341)

Dua butir tinjauan Container System (PSR-11) di ROADMAP:

1. **Service middleware/interceptors** — `Container::addServiceMiddleware(
   callable $middleware, int $priority = 0)`: onion ber-priority di sekeliling
   **konstruksi** service (jalur cache-miss). Signature
   `fn(string $serviceId, Closure $next): mixed`; short-circuit tetap melewati
   resolved listeners + caching per-lifetime; invarian urutan tetap
   `resolving → [middleware onion → guarded factory] → resolved`; eksepsi
   middleware dibungkus `ServiceResolutionException` (aman PSR-11); pipeline
   tersegel saat `validateAndFreeze()`; fast path tanpa middleware = nol
   overhead.
2. **Runtime implicit lifetime-capture guard** — pass compile-time sudah
   menolak dependensi non-singleton yang dideklarasikan; lubang tersisa adalah
   **capture implisit**: body factory singleton memanggil `$ctx->get()` untuk
   service REQUEST/TRANSIENT saat runtime. Sebelum patch, pada worker
   RoadRunner long-running instance request-scoped **tertangkap di singleton
   store** — state request #1 diam-diam disajikan ke semua request berikutnya
   tanpa error. Sesudah: fail-fast `ServiceResolutionException` menyebut
   singleton pemilik, lifetime, dan service yang tertangkap. Guard di
   `ContainerResolver::resolveInContext()` (choke point seluruh resolusi),
   bekerja untuk container frozen maupun unfrozen; `warmSingletons()` ikut
   fail-fast.

## Mutasi & kualitas (PR #336, #349)

- **PR #336** — 13 berkas test pembunuh mutan untuk issue #299 (96 mutan
  escaped terkonsolidasi pada 15 berkas hotspot zona inti Job/Validation/
  Http/Router): pin bentuk pesan, kondisi boundary, jalur error, urutan, dan
  guard envelope baca/tulis.
- **PR #349** — 36 mutan ekuivalen terdokumentasi `@infection-ignore-all`
  ber-justifikasi per baris (mutan yang matematikanya tidak mengubah perilaku:
  nilai yang hanya dibaca `array_keys()`, guard duplikat, metadata diagnostik
  pada exception, dsb.) — utang mutasi yang tersisa kini **tercatat dan
  beralasan**, bukan senyap. Termasuk reflow 20 komentar anotasi ke ≤120 char
  (php:S103) setelah gerbang SonarCloud menjadi required check efektif —
  anotasi tetap berfungsi karena deteksi Infection berbasis komentar node
  (`str_contains`), bukan posisi baris tunggal.

## Audit runtime dinamis pra-kampanye (#278–#282, PR #298)

Lima temuan audit runtime (dynamic testing, 9 eksekusi konsisten per temuan):
validasi elemen allow-list `TrustedHostValidator` (non-string kini
`InvalidArgumentException` bersih), dua bug `UrlGenerator`, dan dua bug
`RedisStreamJobQueue` — detail lengkap di badan PR #298.

## Dokumentasi (PR #275, #276, #277, #337)

- **PR #337** — audit menyeluruh `docs/**`: 6 dokumen stale/kurang rapi
  direvisi (ROADMAP, ARCHITECTURE, EDGE-CASE-MATRIX, QUALITY, CLI, docs/README),
  12 lainnya current. Semua angka diverifikasi terhadap ground truth: 630
  berkas PHP (Domain 287 / Application 136 / Infrastructure 115 / Adapters 60 /
  Compat 23), 20 workflow, gate mutasi 85/90.
- **PR #275/#276/#277** — tabel gerbang kanonis (P0-2), sinkronisasi seksi
  CI/CD README ke kondisi terukur (P1-2), ROADMAP satu-item-per-baris + fix
  badge.

## Gerbang & infrastruktur

- **SonarCloud efektif sebagai required check**: kondisi new-code
  (reliability/security/maintainability rating, coverage-on-new-code dari
  clover lane CI, duplikasi, hotspot) dipaksakan per-PR; utang php:S103 dari
  era pra-gerbang terbayar (PR #342, #349).
- **Ratchet corpus fixture** `tests/fixtures.limit` fail-closed dua arah +
  assert ukuran `files` SonarCloud terhadap limit yang sama.
- **Ratchet dokumen rilis** kini juga memvalidasi badge jumlah test terhadap
  `build/junit.xml` CI (README tidak bisa lagi tertinggal dari suite aktual).
- Antrean PR zero-click (arm-on-open + rantai merge + cron 20 menit) membawa
  seluruh 14 PR rilis ini ke merge tanpa satu klik manual pun.

Seluruh 14 PR melewati 12 required context proteksi main (strict +
enforce_admins) secara organik: PHP lint/audit/static/style (lint 880/0,
self-test 501/501 + v290/v210/v211, PHPUnit, PHPStan max + baseline ratchet,
deptrac fail-on-uncovered, cs-fixer, phpcs, coverage ≥90%, ratchet zona mutasi,
ratchet kadensi & dokumen rilis), dependency-review, gitleaks, PHPBench, Build
API documentation, CodeQL, PHP SAST (Semgrep), Platform smoke (PHP 8.5 +
Windows), SonarCloud, snyk, Stub pre-scan.
