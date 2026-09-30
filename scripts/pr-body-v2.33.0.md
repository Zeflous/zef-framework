# feat: OpenAPI Runtime Gate — v2.33.0 core

## Tema

Menepati janji yang tercatat di CHANGELOG v2.20.0 ("runtime validation
middleware & security enforcement direncanakan pada paket berikutnya"):
PSR-15 middleware yang **menegakkan kontrak dokumen OpenAPI 3.1 pada setiap
request masuk**, dengan dokumen yang sama yang di-serve `SpecHandler` —
satu dokumen, dua penegak: validator struktural saat boot, gate saat
request.

## Matriks paritas 12 batas — dikommit SEBELUM implementasi

`docs/OPENAPI-GATE-PARITY.md` (commit pertama branch ini) mengunci kontrak
sebelum satu baris kode — disiplin yang sama dengan matriks kontrak antrean
v2.32.0:

| # | Batas | Perilaku | Jangkar paritas |
|---|---|---|---|
| B1 | Path tak dikenal | pass-through, `operation: null` | Router pemilik tunggal 404 |
| B2 | Metode tak terdokumentasi | 405 + `Allow` (+HEAD bila ada GET) | `MethodNotAllowedException` + Dispatcher |
| B3 | Path param melanggar skema | 400 + isu `in:path` | `RouteConstraintException` → 400 |
| B4/B5 | Query wajib hilang / skema + array style | 400 + coercion string-transport | additive; `parse_str` PSR-7 |
| B6 | Header terdokumentasi | 400 (case-insensitive) | additive |
| B7 | Content-Type tak ditawarkan | 415 + `supported` | additive |
| B8 | Body wajib tak hadir | 400 | additive |
| B9 | Body melanggar skema JSON | 400 + JSON Pointer, tipe ketat | kontrak `Schema::toArray()` |
| B10 | Security tak terpenuhi | 401 anonim / 403 scope | `zef.auth.identity` (v2.31.0) + bukti kehadiran |
| B11 | Respons melanggar kontrak (opt-in) | 500 fail-closed + 1 record PSR-3 | falsafah fail-closed rumah |
| B12 | Dokumen invalid saat konstruksi | boot fail-closed | **reuse `OpenApiSpecValidator`** (termasuk `OpenApiSecurityValidator`) |

Precedensi status deterministik: **405 → 401/403 → 415 → 400** — autentikasi
dicek sebelum validasi supaya detail kontrak tidak bocor ke pemanggil anonim.

## Komponen (8 kelas baru, additive)

- `src/Infrastructure/OpenApi/` — `OpenApiRequestGate` (engine), `OpenApiGateIndex`
  (path-template mirror semantik `splitPath`/raw-compare/`rawurldecode` Router),
  `OpenApiSchemaChecker` (subset JSON-Schema persis `Schema::toArray()`; `$ref`
  + sibling; nullable-before-ref ala OpenAPI 3.0; pattern tanpa delimiter
  dibungkus ulang dengan error-handler ter-scope — tanpa `@`, php:S2002;
  depth-bound 64, cycle-safe), `OpenApiGateRequest`/`OpenApiGateVerdict`/
  `OpenApiGateOptions`/`OpenApiGateException`
- `src/Adapters/OpenApi/Http/OpenApiGateMiddleware` — PSR-15; rejection RFC 9457
  problem+json (+ header `Allow`); atribut request `zef.openapi.gate`; body
  **lazy** (stream tak tersentuh kecuali operasi mendeklarasikan requestBody),
  rewind-safe (cast rewind; non-seekable dibuffer + diganti salinan); factory
  `forRoutes()` berbagi komposisi `GenerateSpecCommand`

## Bonus perbaikan CI

`release.yml`: step create-release kini **idempotent** (create-or-upload) —
insiden nyata di tag v2.32.0: release yang sudah terbit manual (remediasi
race SBOM) membuat workflow Release merah ("a release with the same tag
name already exists"). Semangat yang sama dengan fix PR #266.

## Zona mutasi

Zona kanonik baru `openapi-gate` (8 file) di-carve mengikuti preseden
`infra-job-redis` v2.32.0 — campaign terukur, evidence ter-commit,
tabel `zones.tsv`/`baseline.tsv` + ratchet `mutation:zones` diperbarui.
(Angka final menyusul campaign — baris tabel di-commit di commit terakhir
branch ini sebelum merge.)

## Kualitas

- **+95 test** (3 file): `OpenApiGateMatrixTest` (12 batas ter-pin per baris,
  spec fixture via `SpecificationBuilder` + spec tulis-tangan untuk kasus yang
  builder tak bisa ekspresikan: global security, apiKey name, empty requirement),
  `OpenApiSchemaCheckerTest` (subset + defensive branches yang legit reachable),
  `OpenApiGateMiddlewareTest` (problem+json shape eksak, Allow, atribut, stream
  safety, B11 mode, B12 boot, `forRoutes`)
- PHPStan level max + strict-rules: 0 error; cs-fixer 0/810; PHPCS 0 pelanggaran
  (810 file); rector bersih; deptrac 0 pelanggaran/0 uncovered; lint 851/0;
  self-test 501/501; coverage gate **96.48%** ≥ 90%
- ROADMAP.md baris `OpenAPI runtime validation` → **[x]**; docs/README.md
  mengindeks matriks paritas
