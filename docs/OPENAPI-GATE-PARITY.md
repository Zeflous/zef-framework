# OpenAPI Runtime Gate — Kontrak Paritas 12 Batas (v2.33.0)

> Dokumen ini adalah **matriks paritas** yang dikommit **sebelum** implementasi
> (disiplin yang sama dengan matriks kontrak antrean v2.32.0). Setiap baris
> mendefinisikan satu batas runtime, jangkar semantiknya di dokumen OpenAPI,
> perilaku gate yang dijanjikan, dan jangkar paritasnya di kerangka kerja yang
> sudah ada. Test suite `OpenApiGateMatrixTest` meng-pin setiap baris; kalau
> perilaku dan tabel ini berbeda, yang salah adalah kode — bukan tabel.

## Posisi arsitektur

Runtime gate adalah **PSR-15 middleware** (`OpenApiGateMiddleware`) yang
menegakkan kontrak dokumen OpenAPI 3.1 pada setiap request masuk, sebelum
request mencapai handler. Sumber kebenaran tunggalnya adalah **dokumen
spesifikasi yang sama** dengan yang di-serve `SpecHandler` (ETag sha256):
satu dokumen, dua penegak — validator struktural saat boot
(`OpenApiSpecValidator`), gate saat request.

```
request ──▶ OpenApiGateMiddleware ──▶ Router ──▶ handler ──▶ response
              │  (spec = kontrak)         (pemilik 404/405/400
              │                            konstrain rute)
              ▼
        OpenApiRequestGate (engine, Infrastructure)
              ├── OpenApiGateIndex      (path-template → operasi)
              └── OpenApiSchemaChecker  (subset JSON-Schema)
```

Prinsip pembatas peran (boundary of responsibility):

- **Router** tetap pemilik tunggal 404 dan kecocokan rute — gate tidak pernah
  membayangi 404 (B1: path yang tidak dikenal dilewatkan).
- **Gate** pemilik kontrak dokumen: parameter, body, security requirement,
  dan (opt-in) respons — hal-hal yang router tidak tahu apa-apa tentangnya.
- **Autentikasi** tetap milik `AuthenticationMiddleware`; gate hanya membaca
  atribut identitas yang sudah terverifikasi + bukti kehadiran kredensial.
  Gate tidak pernah memverifikasi kredensial.

## Komponen

| Kelas | Layer | Peran |
|---|---|---|
| `OpenApiGateMiddleware` | `src/Adapters/OpenApi/Http` | PSR-15: ekstraksi PSR-7 → engine, render problem+json, rewind body |
| `OpenApiRequestGate` | `src/Infrastructure/OpenApi` | engine: seleksi template/metode, verdict admitted, orkestrasi precedensi |
| `OpenApiGateSecurity` | `src/Infrastructure/OpenApi` | B10: requirement OR/AND, bukti kehadiran, 401/403 |
| `OpenApiBodyContract` | `src/Infrastructure/OpenApi` | B7/B8/B9: lazy body, 415, JSON ketat + pointer |
| `OpenApiResponseContract` | `src/Infrastructure/OpenApi` | B11: status/media-type/body respons |
| `OpenApiParameterContract` | `src/Infrastructure/OpenApi` | B3–B6: parameter path/query/header/cookie + strictQuery |
| `OpenApiGateIndex` | `src/Infrastructure/OpenApi` | kompilasi spec → indeks path-template + metode + skema |
| `OpenApiSchemaChecker` | `src/Infrastructure/OpenApi` | inti rekursif checker (traversal, guard, `$ref`) |
| `OpenApiScalarConstraints` | `src/Infrastructure/OpenApi` | batasan skalar: enum, string, number, pattern |
| `OpenApiStructureConstraints` | `src/Infrastructure/OpenApi` | batasan struktur: array, object, komposisi oneOf/anyOf/allOf |
| `OpenApiSchemaTypes` | `src/Infrastructure/OpenApi` | sistem tipe: pencocokan, label, coercion string-transport |
| `OpenApiGateRequest` | `src/Infrastructure/OpenApi` | input VO engine (metode, path, query, header, cookie, CT, lazy body, atribut) |
| `OpenApiGateVerdict` | `src/Infrastructure/OpenApi` | hasil: admitted + konteks operasi, atau rejected + status/detail/isu/header |
| `OpenApiGateOptions` | `src/Infrastructure/OpenApi` | knob: `strictQuery` (default false), `validateResponses` (default false) |
| `OpenApiGateException` | `src/Infrastructure/OpenApi` | boot-time failure (extends `OpenApiException`) |

*Pemecahan kelas dua tahap mengikuti anggaran SonarCloud (php:S2042/S1448
— 15 berkas, semua ≤ 200 baris & ≤ 20 metode); perilaku, pesan dan
precedensi dibawa verbatim — 187 test gate hijau tanpa perubahan.*

## Matriks 12 batas

| # | Batas | Jangkar spec | Perilaku gate | Jangkar paritas |
|---|---|---|---|---|
| **B1** | Path tidak dikenal dokumen | `paths` tidak memuat template yang cocok | **Pass-through** — gate tidak menghasilkan verdict; request jatuh ke router | Router tetap pemilik 404 (`RouteNotFoundException` → Dispatcher 404). Gate bersifat additive: tidak pernah mengurangi rute yang bisa dilayani |
| **B2** | Path cocok, metode tidak terdokumentasi | `paths[path]` hanya memuat metode lain | **405** problem+json + header `Allow` = union metode terdeklarasi pada semua template yang cocok (+ `HEAD` bila ada `GET`), terurut abjad | Router 405: `MethodNotAllowedException->allowedMethods` → Dispatcher `Allow: implode(', ')`; HEAD diperlakukan sebagai GET (`RouteMatcher::$effectiveMethods`). Paritas **himpunan** metode identik; urutan penyajian terurut (semantik header Allow tidak bergantung urutan) |
| **B3** | Path parameter melanggar skema | `parameters[in=path].schema` (turunan constraint rute: `int`→integer, `uint`→integer+min 1, `alpha`/`slug`/`hex`→pattern, `uuid`→format) | **400** + isu `in=path`; nilai segmen di-`rawurldecode` dulu | Router constraint-miss pada rute bermotode-sama = `RouteConstraintException` → **400** (Dispatcher). Paritas status **400=400**; gate menambah bentuk problem+json + pointer. Template alternatif diuji semua (urut kunci path); bila satu lolos, template itu yang dipilih — padanan semantik kandidat berprioritas milik router |
| **B4** | Query parameter wajib hilang | `parameters[in=query].required=true` | **400** + isu `in=query` | Additive (router tidak punya kontrak query) |
| **B5** | Query parameter melanggar skema / gaya array | `parameters[in=query].schema` | **400**; nilai string di-coerce ke tipe skema (`'42'`→42); skema array menerima array PHP (kunci berulang, padanan `style=form, explode=true`) **atau** satu string terpisah koma (padanan `explode=false`) | Additive; parsing query mengikuti semantik PSR-7 `getQueryParams()` rumah (`parse_str`) |
| **B6** | Header terdokumentasi wajib hilang / salah skema | `parameters[in=header]` | **400** + isu `in=header`; lookup nama header case-insensitive | Additive; paritas pola `getHeaderLine()` PSR-7. Header yang tidak terdokumentasi **tidak pernah** ditolak (header = permukaan transport) |
| **B7** | Content-Type tidak ditawarkan requestBody | `requestBody.content` keys | **415** + isu `in=body, name=<media type>`, ekstensi `supported` berisi daftar media type | Additive (`RequestBodyPolicy` membatasi ukuran, bukan tipe). Media type dibandingkan setelah parameter (`; charset=…`) dibuang, lowercase |
| **B8** | Body wajib tidak hadir | `requestBody.required=true` | **400** (body kosong/whitespace) | Additive |
| **B9** | Body melanggar skema JSON | `requestBody.content[ct].schema` | **400** + isu `in=body` dengan JSON Pointer ke nilai yang salah (`/email`); tipe JSON diperiksa **ketat tanpa coercion** (string `'42'` ≠ integer) | Additive; subset skema = persis kontrak `Schema::toArray()` (type/format/pattern/enum/bounds/items/uniqueItems/properties/required/additionalProperties/min-max Properties/nullable/`$ref`/oneOf/anyOf/allOf) |
| **B10** | Security requirement tidak terpenuhi | `operation.security` (fallback `spec.security`), `components.securitySchemes` | **401** anonim / **403** scope-hilang; terpenuhi bila: atribut `zef.auth.identity` (kanonik v2.31.0) atau alias `zef.security.principal` berisi principal non-anonim, **atau** bukti kehadiran: header `Authorization` ber-prefix sesuai skema `http`, paramater apiKey pada lokasinya | `AuthenticationMiddleware`: set `zef.auth.identity` hanya untuk principal admited non-anonim; 401=belum autentik, 403=sudah autentik tapi ditolak. Invarian bentuk skema keamanan sudah dijaga `OpenApiSecurityValidator` saat boot (B12) — gate hanya menginterpretasi skema yang sudah lolos. oauth2/openIdConnect/mutualTLS tidak punya bukti murah → hanya atribut identitas yang dihitung (terdokumentasi). Nama skema non-string pada requirement (kunci int, tidak divalidasi boot) diperlakukan **fail-closed sebagai skema tak dikenal** — 401, konsisten dengan nama string yang tidak terdefinisi, tidak pernah di-skip diam-diam. Scope non-kosong dicek terhadap atribut `zef.security.scopes` (array string) bila aplikasi menyediakannya; tanpa atribut tersebut, keputusan scope didelegasikan ke lapisan otorisasi (pass-through, terdokumentasi) |
| **B11** | Respons melanggar kontrak (opt-in `validateResponses`) | `operation.responses[status].content[ct].schema` | **500** problem+json (fail-closed) + satu record error PSR-3 dengan konteks aman (operationId, status, isu pertama); body respons asli tidak pernah bocor ke respons error | Falsafah fail-closed rumah (preseden `RateLimitMiddleware` 503 fail-closed). Status dideklarasikan = exact (`'200'`) / `default` / rentang `'2XX'`. Media type hanya divalidasi bila respons mendeklarasikan `content`; body hanya di-skema-cek untuk media type JSON |
| **B12** | Dokumen spec invalid saat konstruksi | seluruh dokumen | **Boot fail-closed**: `OpenApiGateException` berisi daftar error `OpenApiSpecValidator::validate()` — tidak pernah per-request | Falsafah fail-fast boot rumah (preseden skema Config v2.21.0). **Ini reuse nyata**: invarian skema keamanan ikut dicek lewat `OpenApiSecurityValidator` yang dipanggil validator |

## Precedensi status & urutan isu

Satu request bisa melanggar beberapa batas sekaligus. Urutan keputusan
deterministik (yang pertama menang):

```
B2 405 (struktural, non-sensitif)
 → B10 401/403 (autentikasi sebelum validasi — jangan bocorkan
    detail kontrak ke pemanggil anonim)
 → B7 415 (media type harus diketahui sebelum body bisa divalidasi)
 → B4/B5/B6/B8/B9 400 (parameter + body, isu terkumpul:
    path → query → header → cookie → body, urutan deklarasi)
```

Isu dalam satu verdict 400 diurut sesuai urutan deklarasi parameter pada
operasi, lalu isu body (urutan JSON Pointer leksikografis). Semua string
deterministik — tidak ada timestamp, tidak ada random.

## Kontrak bentuk respons (semua rejection)

RFC 9457 `application/problem+json` via `ProblemDetails`:

```json
{
  "type": "about:blank",
  "title": "<frasa alasan RFC 9110>",
  "status": 405,
  "detail": "Metode DELETE tidak terdokumentasi untuk path ini.",
  "instance": "/users/7",
  "errors": [
    { "in": "path", "name": "id", "pointer": "", "message": "…deterministik…" }
  ],
  "allowed": ["GET", "HEAD"]
}
```

- `errors` selalu list (kosong pada 405/415 murni); setiap isu:
  `{in, name, pointer, message}` — `pointer` hanya terisi untuk isu body
  (JSON Pointer ke nilai yang salah, mis. `/email`).
- `allowed` hanya hadir pada 405. Header `Allow` ikut diset (paritas Dispatcher).
- `instance` = path request.
- Extension tambahan per-batas: B7 `supported` (list media type);
  B11 `operationId`.

**Catatan kompatibilitas opt-in**: memasang gate mengubah bentuk body respons
405/400-konstrain dari `JsonResponse` (Dispatcher) menjadi problem+json untuk
request yang ditolak gate — opt-in, terdokumentasi di sini, dan merupakan
peningkatan kontrak (pointer presisi). Respons router untuk request yang
di-pass-through (B1) tidak berubah sama sekali.

## Wire-up

```php
use Zef\Framework\OpenApi\Http\OpenApiGateMiddleware;

// A. dari route table (rekomendasi — dokumen selalu sebangun dgn rute):
$middleware = OpenApiGateMiddleware::forRoutes(
    $router->getRoutes(),                 // list<array> bentuk Router::getRoutes()
    classResolver: $containerClassLookup, // ?\Closure(string): ?class-string
    options: new OpenApiGateOptions(strictQuery: false, validateResponses: false),
    logger: $logger,                      // ?LoggerInterface — hanya dipakai B11
);

// B. dari dokumen yang sudah dibangun / di-cache:
$middleware = new OpenApiGateMiddleware($spec, $options, $logger);
$app->pipe($middleware);                  // setelah AuthenticationMiddleware
                                         // agar B10 membaca identitas terverifikasi
```

Urutan pipeline yang direkomendasikan: `AuthenticationMiddleware` → gate →
router. Gate yang dipasang sebelum autentikasi tetap benar — jalur bukti
(B10) yang bekerja, hanya saja 401-nya datang dari gate alih-alih middleware
autentikasi.

## Non-goals (hal yang gate SENGAJA tidak lakukan)

1. **Verifikasi kredensial** — bukan urusan gate (B10 hanya kehadiran/identitas).
2. **Penolakan header/cookie tak terdokumentasi** — permukaan transport,
   bukan kontrak API (hanya query yang punya mode `strictQuery`).
3. **Validasi media type non-JSON pada body** — hanya keluarga JSON
   (`application/json`, `application/*+json`) yang di-skema-cek; media type
   lain yang terdeklarasi lolos tanpa pemeriksaan (terdokumentasi).
4. **Enforcement `readOnly`/`writeOnly`/`deprecated`** — anotasi, bukan
   batas runtime.
5. **Wildcard media type** (`*/*`, `application/*`) — builder rumah tidak
   pernah meng-emit-nya; pencocokan exact-only (terdokumentasi).
6. **Skema query menyusul** — generator menurunkan parameter path dari
   constraint rute; parameter query/header hanya hadir lewat atribut
   `#[Parameter]`. Dokumen tanpa atribut = gate hanya menegakkan
   B1/B2/B3/B7/B8/B9/B10/B11/B12 — konsisten dan jujur.

## Jaminan performa

Indeks dikompilasi **sekali di konstruksi** (bukan per-request): template →
segmen, metode → operasi, skema komponen. Pencocokan path O(segmen) per
template dengan short-circuit; body hanya dibaca (closure lazy) bila operasi
tercocok mendeklarasikan `requestBody`; stream di-rewind setelah dibaca
(`__toString` rewind-seekable), dan stream non-seekable diganti salinan
`Stream::fromString()` sehingga handler hilir tetap bisa membaca body.
