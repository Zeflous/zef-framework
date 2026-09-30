# CHANGELOG v2.33.0 — OpenAPI Runtime Gate

**Tema**: menepati janji yang tercatat di CHANGELOG v2.20.0 — *runtime
validation middleware & security enforcement* kini hadir: PSR-15 middleware
`OpenApiGateMiddleware` yang **menegakkan kontrak dokumen OpenAPI 3.1 pada
setiap request masuk**. Sumber kebenaran tunggal adalah dokumen yang sama
yang di-serve `SpecHandler`: satu dokumen, dua penegak — validator
struktural saat boot, gate saat request. Kontrak perilaku dikunci lebih
dulu lewat **matriks paritas 12 batas** (`docs/OPENAPI-GATE-PARITY.md`,
dikommit sebelum implementasi — disiplin yang sama dengan matriks kontrak
antrean v2.32.0). **Nol paket runtime baru**; semuanya additive.

Rentang: setelah CHANGELOG-v2.32.0 (Unified Queue & Broker, 2026-09-30, PR
#265) sampai tag `v2.33.0` (2026-10-01) — membawa PR #267
(`feat/openapi-runtime-gate`).

## Fitur baru

### `OpenApiGateMiddleware` — enforcement kontrak per-request (PR #267)

- **12 batas runtime** (B1–B12, tiap baris di-pin test): pass-through
  path tak dikenal (404 tetap milik Router), 405 + header `Allow` dengan
  HEAD-implikasi-GET (paritas `MethodNotAllowedException`→Dispatcher),
  400 path parameter dengan paritas `RouteConstraintException`,
  parameter query/header/cookie (wajib + skema, coercion
  string-transport, array gaya form explode true/false), 415 media type
  tidak ditawarkan (+ ekstensi `supported`), 400 body wajib kosong, 400
  body JSON melanggar skema dengan JSON Pointer (tipe ketat tanpa
  coercion — string `'42'` bukan integer), 401/403 security requirement
  (identitas terverifikasi `zef.auth.identity` + bukti kehadiran
  kredensial; scope didelegasikan bila aplikasi tak mengekspos grant),
  respons opt-in fail-closed 500, dan **boot fail-closed** — dokumen
  invalid ditolak di konstruksi lewat reuse `OpenApiSpecValidator` /
  `OpenApiSecurityValidator` (B12).
- **Precedensi status deterministik** `405 → 401/403 → 415 → 400` —
  autentikasi dicek sebelum validasi supaya detail kontrak tidak bocor ke
  pemanggil anonim. Semua rejection berbentuk RFC 9457 problem+json via
  `ProblemDetails` (judul alasan tetap milik ProblemDetails — verdict
  tidak menduplikasi data mati).
- **Body lazy + stream-safe**: stream request hanya tersentuh bila
  operasi tercocok mendeklarasikan `requestBody` (provider closure);
  stream seekable dibaca dengan cast yang me-rewind, stream non-seekable
  dibuffer sekali lalu diganti salinan rewindable — handler hilir dan
  emitter tetap bisa membaca.
- **Indeks sekali-boot**: path-template di-parse dengan semantik persis
  `splitPath`/raw-compare/`rawurldecode` milik Router (termasuk collapse
  `//` dan `%2F` dalam satu segmen), template terurut deterministik,
  metode dijamin lowercase oleh validator boot.
- **`forRoutes()`** — factory dari route table (komposisi yang sama
  dengan `GenerateSpecCommand`), jadi dokumen yang ditegakkan dan yang
  di-serve identik by construction.
- **Nama skema non-string** pada requirement (kunci int — bentuk yang
  lolos validasi boot) diperlakukan fail-closed sebagai skema tak
  dikenal → 401, konsisten dengan nama string tak terdefinisi.
- **Pemecahan kelas dua tahap** demi anggaran SonarCloud
  (php:S2042/S1448): 16 berkas, semuanya ≤ 200 baris & ≤ 20 metode —
  perilaku, pesan, dan precedensi dibawa verbatim; seluruh test gate
  tetap hijau tanpa perubahan di tiap split.

### `OpenApiSchemaChecker` — subset JSON-Schema persis kontrak builder (PR #267)

- Kata kunci yang ditegakkan = persis keluaran `Schema::toArray()`
  (type/format/pattern/enum/bounds/items/uniqueItems/properties/required/
  additionalProperties/min-maxProperties/nullable/`$ref`/oneOf/anyOf/
  allOf); yang tak bisa di-emit generator diperlakukan longgar
  (anotasi, format unknown) atau fail-closed dengan isu deterministik.
- `$ref` + sibling keyword keduanya berlaku (2020-12); **nullable
  diperiksa sebelum `$ref`** — builder meng-emit `{$ref, nullable}` gaya
  OpenAPI 3.0 (union dengan null), bukan strict-2020.
- Pattern dokumen **tanpa delimiter** (bridge validator me-strip
  delimiter PHP) dibungkus ulang `~(...)~` dengan error-handler ter-scope
  — pattern invalid tetap fail-closed sebagai isu, tanpa `@` (php:S2002)
  dan tanpa warning bocor ke suite (failOnWarning tetap bersih); panjang
  pattern dibatasi 2048 (kebijakan ReDoS rumah).
- Rekursi **depth-bounded 64, cycle-safe** (skema self-referensial legal
  — terminasi pada kedalaman data); coercion string-transport untuk
  parameter (`'42'`→42, `'true'`→true) vs tipe JSON ketat untuk body.

## Perbaikan

- **`release.yml`: step create-release kini idempotent**
  (create-or-upload). Insiden nyata di tag v2.32.0: release yang sudah
  terbit manual (remediasi race attach SBOM) membuat workflow Release
  merah (*"a release with the same tag name already exists"*). Release
  yang sudah ada kini keadaan legal — artefak tarball/sha256 di-refresh
  (`--clobber` hanya menyentuh aset bernama sama; aset SBOM milik job
  sbom-release). Semangat sama dengan fix race PR #266.
- **Hardening SAST**: bentuk `\assert()` backslash-qualified di fixture
  test dihilangkan — rule lokal `ban-qualified-global-call` menutup
  blind-spot bentuk-qualified dari rule ter-pin (panggilan global tanpa
  backslash tetap terlihat oleh rule keamanan).

## Dokumentasi

- **`docs/OPENAPI-GATE-PARITY.md`** (baru): kontrak 12 batas + wire-up +
  non-goals + jaminan performa — dikommit SEBELUM implementasi sebagai
  komit pertama branch fitur.
- **`docs/ROADMAP.md`**: baris `OpenAPI runtime validation` → **[x]**
  v2.33.0 (baris *menyusul* di entri v2.20.0 ditutup).
- **`docs/README.md`**: indeks dokumen paritas.

## Kualitas

- **PHPUnit +188 test** (3312 → 3500): `OpenApiGateMatrixTest` (52 — 12
  batas ter-pin per baris, fixture via `SpecificationBuilder` + spec
  tulis-tangan untuk bentuk yang tak bisa diekspresikan builder: security
  global, apiKey name, requirement kosong), `OpenApiSchemaCheckerTest`
  (31 — subset + cabang defensif yang legitimately reachable),
  `OpenApiGateMiddlewareTest` (13 — bentuk problem+json eksak, `Allow`,
  atribut, stream safety dua arah, mode B11, B12 boot, `forRoutes`),
  `OpenApiGateMutationDebtTest` (92 test pembunuh mutasi: boundary eksak
  per famili rekursi 63/65 level, kebijakan pattern 2048, panjang
  multibyte, strictness enum, nilai coercion boolean feeding enum,
  jangkauan garbage-tolerance, mode strictQuery, komposisi pesan/pointer
  eksak).
- **Zona mutasi baru `openapi-gate`** — 16 file (engine, lima keluarga
  kontrak parameter/security/body/response, keluarga checker skalar/
  tipe/struktur/komposisi, index, + middleware PSR-15; pemecahan dua
  tahap mengikuti preseden carving `infra-job-redis` v2.32.0):
  **MSI 95.39 / covered 96.12, 1015/1064 killed** setelah delapan ronde
  killer-test (matrix, checker, middleware + lima ronde debt); 8
  not-covered = guard rewrap PSR-7 defensif terdokumentasi
  (pasangan instanceof-LogicException withBody/withHeader yang tak
  terjangkau dengan kelas request/response milik kerangka sendiri) +
  cabang boot-unreachable di belakang validasi B12; 41 escape ter-triage
  ekuivalen (fallback data-mati `?? 400` pada status yang selalu ter-set,
  peta `Allow` yang `array_keys`-agnostic terhadap nilai, trim `splitPath`
  subsumed filter segmen-kosong, arm garbage-tolerance tak terjangkau
  lewat dokumen tervalidasi B12, akuntansi ±1 depth sub-level pada
  `$ref`-sibling dan rekursi `additionalProperties`, cast kunci-int nama
  skema yang `implode`-nya identik, first-spread-of-empty-array no-op
  pada akumulasi isu). Ratchet `mutation:zones` PASSED — baris tabel +
  evidence ter-commit; baseline beku. Kampanye menemukan dan menghapus
  **data mati** (judul verdict terduplikasi, status internal bodyRejection
  yang tak pernah dibaca) dan guard tak-terjangkau di belakang validasi
  B12 — mutan mati karena kodenya jujur, bukan disembunyikan.
- PHPStan level max + strict-rules: 0 error; cs-fixer 0/819; PHPCS,
  rector, deptrac, lint (860 berkas), self-test 501/501 bersih; coverage
  gate **97.04%** ≥ 90%; ratchet PHPStan baseline tetap 0 drift;
  classmap **1145 kelas** (badge README disinkronkan 1118 → 1145; badge
  test-count 3312 → 3500 — ratchet issue #215 memin keduanya ke suite
  yang benar-benar dieksekusi CI).

## Catatan upgrade

- `composer.json` **tidak berubah** — nol dependensi baru.
- Semua API additive: 16 kelas baru (15 `src/Infrastructure/OpenApi/` +
  1 `src/Adapters/OpenApi/Http/`) + 1 dokumen kontrak; tidak ada API
  lama yang berubah atau dihapus. Gate opt-in — tidak ada perubahan
  perilaku sampai middleware di-wire.
- **Catatan kompatibilitas opt-in**: memasang gate mengubah bentuk body
  respons 405/400-konstrain dari `JsonResponse` (Dispatcher) menjadi
  problem+json untuk request yang ditolak gate — terdokumentasi di
  matriks paritas; respons router untuk request pass-through tidak
  berubah.
- `ZefVersion::VERSION` 2.32.0 → 2.33.0; README (badge, baris Ecosystem,
  tabel riwayat, tautan changelog), SECURITY.md, dan docs/README.md
  disinkronkan — ratchet docs memin keduanya ke versi kode.
