# ZEF — Edge-Case Matrix (Kurikulum Uji Adversarial)

> Prinsip: **mutan yang lolos = edge case yang belum di-assert.** Dokumen ini adalah
> kurikulum adversarial untuk ronde 3: setiap test yang lahir harus mewakili
> skenario nyata (bukan tautologis), dan chunk Infection adalah ujiannya.

> **STATUS: KAMPANYE SELESAI.** Tujuan multi-ronde **MSI 85/90 TERCAPAI di v2.15.0**
> (full-run 9.032 mutan: MSI 90.4 / Covered 93.1) dan dipertahankan setelah ZEF Maker
> masuk scope (v2.16.0: 9.419 mutan, MSI 90.77 / Covered 93.38; zona Console 100%).
> Gate `--min-msi=85 --min-covered-msi=90` kini dijalankan `mutation.yml` pada setiap
> rilis tag. Dokumen ini dipertahankan sebagai rekam jejak kurikulum + triage
> ekuivalen-mutant; seluruh angka pada section 1 adalah **keadaan awal ronde 3
> (v2.14.2)**, bukan keadaan saat ini.

## 1. Baseline terukur (awal ronde 3, komposisi segar)

| Zona chunk | Total | Killed | Escape | Not-Covered | MSI | Covered MSI |
|---|---:|---:|---:|---:|---:|---:|
| domain-core | 490 | 274 | 151 | 65 | 55.9 | 64.5 |
| domain-rescfg | 631 | 415 | 139 | 76 | 65.9 | 75.0 |
| domain-secval | 976 | 626 | 249 | 98 | 64.4 | 71.6 |
| domain-rest | 618 | 376 | 182 | 57 | 61.3 | 67.6 |
| app-container | 905 | 589 | 188 | 114 | 66.6 | 76.2 |
| app-observability | 661 | 429 | 190 | 31 | 64.9 | 68.2 |
| app-job | 242 | 129 | 67 | 39 | 56.2 | 67.0 |
| app-rest | 663 | 478 | 148 | 32 | 72.9 | 76.5 |
| infrastructure | 873 | 560 | 248 | 60 | 64.7 | 69.5 |
| adapters-http | 1453 | 1111 | 254 | 78 | 76.5 | 80.7 |
| adapters-router-kernel | 877 | 561 | 247 | 46 | 64.0 | 68.3 |
| adapters-runtime-sec | 518 | 270 | 204 | 38 | 53.3 | 57.5 |
| **GLOBAL** | **8907** | **5892** | **~2267** | **~734** | **~66.2** | **~72.3** |

Gate aktif **saat dokumen ditulis** (awal ronde 3): `--min-msi=64 --min-covered-msi=68`
(ronde 2). Target ronde 3: **MSI ≥ 74 / covered ≥ 78** (naik bertahap bersama tiap
tier; 85/90 tetap tujuan multi-ronde — sisa adalah ekuivalen-mutant & not-covered
struktural). Keadaan akhir kampanye: gate **85/90** (v2.15.0) — lihat banner status
di atas dan `docs/QUALITY.md`.

## 2. Peta mutator → dimensi adversarial

Bukti agregat dari 18 slice `build/escapes-*.txt` (~2.400 entri):

| Mutator (jumlah) | Dimensi edge-case yang hilang | Obat (jenis assertion) |
|---|---|---|
| Throw_ (313) | Jalur gagal tidak pernah diuji | `expectException` + pesan substring |
| Increment/DecrementInteger (597) | Boundary ±1 (limit, count, TTL, window) | Uji di limit-1 / limit / limit+1 |
| Concat + operand removal (310) | Komposisi pesan kesalahan | Assert substring pesan lengkap |
| LogicalOr/And (+negation) (317) | Semantik cabang majemuk | Kasus per-side (satu sisi benar, sisi lain salah) |
| MethodCallRemoval (194) | Efek samping tak teramati | Spy/collector + assert efek |
| ReturnRemoval (127) | Nilai kembalian tak dipakai | Assert nilai kembalian |
| Greater/LessThan (172) | Perbandingan ketat vs longgar | Pasangan sama-dengan-batas & diluar-batas |
| TrueValue/FalseValue (136) | Flag default salah | Assert perilaku kedua flag |
| PregMatchRemoveCaret/Dollar/Flags | Anchor & flag regex | Input ber-prefix/suffix `\n`, kasus huruf |
| ArrayItemRemoval/Foreach_/Continue_ | Iterasi parsial/duplikat | Koleksi ≥3 elemen + assert urutan/duplikat |

## 3. Tata kelola (aturan menulis test)

1. **Bukan tautologis** — test tidak boleh sekadar meniru implementasi; setiap test
   harus menyebut skenario adversarial nyata (nama test menjelaskan ancamannya).
2. **Negatif penuh** — exception di-assert kelasnya + substring pesan kunci
   (membunuh Concat/Throw_ sekaligus mencegah regresi pesan).
3. **Boundary tiga titik** — limit-1, limit, limit+1 untuk setiap batas numerik.
4. **Not-covered dulu** — branch yang belum dieksekusi harus sampai dieksekusi
   (test eksplorasi) sebelum assertion negatifnya ditulis.
5. **Ekuivalen-mutant jujur** — mutan yang tidak mengubah hasil observable
   (defensive code, CastInt setelah ctype_digit, dsb.) diinventarisasi di changelog
   dengan justifikasi; **tidak dipalsukan** kill-nya.
6. **Wajib lolos rantai penuh** — PHPStan max+strict, Slevomat, cs-fixer PER-CS2 +
   @PHP84Migration, Rector agresif. Test yang memicu warning harus mengisolasi
   handler sendiri (pola `set_error_handler` probe).

## 4. Tier plan (kurikulum per fase)

Escape di bawah = hitungan dari slice segar (pasca-ronde 2). Status diisi saat eksekusi.

### Tier 1 — Security & Validation (fase 1) — 334 escape

| Kelas | Esc | Dimensi adversarial prioritas | Status |
|---|---:|---|---|
| Validation/RouteConstraintValidator | 68 | Boundary regex 2048/2049; ReDoS guard `(a+)+`; error-handler bocor (finally); ValueError null-byte; semua arm built-in: anchor `^$`, flag `D/i`, guard `''`; uint tolak nol-depan; kustom re-kompilasi setelah addCustom | ✅ fase 1 |
| Validation/MessageCatalog | 45 | Locale chain exact→base→wildcard; batas rule 64/65 byte; template 512/513 byte; trim+lowercase di with(); non-string key; pesan exception berisi locale/rule | ✅ fase 1 |
| Validation/DependencyGraphValidator | 40 | Siklus alias (CircularAliasException rantai); alias kosong/non-string; not-found di 2 loop; singleton-klosure menolak transient; cross-module limit 0/1/2 (dedup edge); siklus DFS posisi stack | ✅ fase 1 |
| Security/SecurityPolicy | 32 | ±1 semua guard (token 15/16, maxReq 0/1, window, maxKeys, secret 31/32); SameSite None vs Secure; nama cookie/header charset; origin kosong saat enabled; envPositiveInt ('', 'abc', '00', '-5'); fromEnvironment matrix CSRF | ✅ fase 1 |
| Validation/FieldRules | 33 | minLength -1/0; maxLength 0/1; typeInt ±1 digit 18; min/max float boundary; in() tolak tipe lain; pattern 2048/2049 + subjek 4096/4097; nullable() retroaktif; skipEmpty | ✅ fase 1 |
| Security/Totp | 25 | Period 0/1/86400/86401; digits 5/6/8/9; secret <8; vektor RFC 4226 (10) + RFC 6238 3-algoritma; window ±1 boundary; kode 5/6/7 digit + suffix newline | ✅ fase 1 |
| Security/Base32 | 18 | Vektor RFC 4648 penuh; padding non-kanonik ditolak; `=`/spasi di-strip; lowercase diterima; char ilegal `1`, `8`, `0`; empty | ✅ fase 1 |
| Validation/Validator+Translator+Result+Error | 15 | Kombinasi rule engine; interpolasi `{{field}}/{{label}}/{{rule}}`; fallback catalog | ✅ fase 1 |
| Validation/HttpStatus+HttpMethod+PortRange+Identifier | 9 | Boundary kode status 99/100/599/600; metode token; port 0/65535/65536; format identifier | ✅ fase 1 |
| Security/Distributed/* (VO) | 32 | Enum guard, bounded trait (0, max, max+1), pesan exception | ✅ fase 1 |

### Tier 2 — Container & Autowire (fase 2 parsial + fase 2b) — 339 escape

| Kelas | Esc | Dimensi adversarial prioritas | Status |
|---|---:|---|---|
| Autowiring/AutowireCompilerPass | 75 → 10 ✅ | Siklus rantai (trim ke siklus); ledger reuse ±1; union/intersection ditolak; enum default tidak exportable; #[Inject] sukses+ghost; #[Target] abstrak; variadic-class vs #[Value] precedence; variadic scalar list/non-array; @value null/missing/share-dedup; dedup dep transient antar-param; literal matrix (escape/bool false/'0'/nested/null-elem/non-exportable); required scalar; mixed→null; frozen+empty list | ✅ fase 2b |
| Container/Container | 68 → 10 ✅ | debug flag; guard kustom; cross-module budget 0=nonaktif/1/±1-clamp; warm matrix (eager/lazy/transient/unshared); reset default-vs-true + fallback cache; getRegisteredIds list; contextual dup+via-id exact; dekorasi chain + synthetic id + budget 128/129 + budget registrasi; provider 64/65, eager boot, deferred lazy/dup/frozen-message/alias-target; namespace fallback REQUEST/eternal/fail-wrap/null/prefix-lifetime/catch-all root; namespaceStats null | ✅ fase 2b |
| Container/NamespaceRadixTree | 56 → 32 ✅ | Prefix boundary (1 char, exact, deeper); split node; delete; collision | ✅ fase 2 (parsial) |
| Container/ContainerResolver | 40 → 7 ✅ | depth clamp 0/-7/255/257/256; unbound resolveRoot; scope helper round-trip; scope state 2 layanan; transient+unshared tak ter-cache; scope cache wajib request-lifetime; listener resolving/resolved fail exact; SRE rethrow-as-is; generic wrap; runtime cycle (stack push); transient-in-scope tanpa cache write; alias ghost dengan module | ✅ fase 2b |

### Tier 3 — Runtime lifecycle (fase 3) — 204 escape

| Kelas | Esc | Dimensi adversarial prioritas | Status |
|---|---:|---|---|
| RoadRunnerRuntime + WorkerAdapter | ~120 → 45 ✅ | Payload rusak; state antar-iterasi; wait/psr7 stream; graceful shutdown | ✅ fase 3 |
| AuthenticationMiddleware | ~50 → 4 ✅ | Kredensial 128/129/512 byte; skema tak dikenal; replay | ✅ fase 3 |
| TinkerSession + BlockingSleeper + InMemoryWorker | ~34 → 9 ✅ | Siklus wait; error mid-session; recovery | ✅ fase 3 |

### Tier 4 — HTTP / Router / Kernel (fase 4) — 537 escape

| Kelas | Esc | Dimensi adversarial prioritas | Status |
|---|---:|---|---|
| Http/Uri | 58 → 9 ✅ | Kanonisasi persen; sub-delim path; userinfo encoding; IPv6 bracket; port 0/65535/65536; immutability | ✅ fase 4 |
| Http/UploadedFile | 46 → 7 ✅ | Guard error/move; destinasi hilang; rename ke dir; cleanup tmp; posisi stream dipulihkan; multi-chunk | ✅ fase 4 |
| Http/TrustedProxyMatcher | 41 → 4 ✅ | CIDR /0 /32 /128 /33 /129 /-1; keluarga campuran; trim; lanjut-scan entri sampah | ✅ fase 4 |
| Router/Router + RouteDefinition + RouteCache | ~126 → 57 ✅ | Grammar placeholder; signature duplikat; HEAD→GET saat POST sort dulu; 405 allow-list; budget persis; fallback hanya 404; restore sanitasi | ✅ fase 4 |
| Http/ETag + ApiVersionNegotiator + Stream + LimitedInput + Psr17 + ServerRequest | ~160 → 31 ✅ | If-None-Match list/W/*; IMS ≤ persis; tanggal rusak; token 16/17 byte; header caps; mode stream; body limit N/N+1; immutability | ✅ fase 4 |
| Kernel/Application + Dispatcher + ResponseEmitter | ~180 → 119 ⚠️ | Span/meter OTEL (teramati saat enabled), rekonsiliasi Content-Length + loop header() **tidak teramati di CLI** (headers_list no-op) — setara-lingkungan; middleware order ✅ | ⚠️ sisa setara-CLI |

### Tier 5 — Observability (fase 5) — ~94 escape baseline riil

| Kelas | Escape | Kurikulum adversarial | Status |
|---|---|---|---|
| Telemetry | 32 → 30 ⚠️ | Env strict bounds (min/max dibunuh via throw; default ±1 ekuivalen); endpoint grammar+credentials; guard recordLog 3-cabang; drain pipeline continue/return dengan spy urutan; shutdown eksak meter events; dual/different exporter | ✅ sisa ekuivalen (hook registry, dead logger, timing deadline) |
| TelemetrySanitizer + Logger + Clock | 15 → 5 ✅ | Limit 0/1/3/4/2048 persis; multibyte; scrub kontrol-char; invalid UTF-8; 17+6 grammar key; redaksi separator/case; NAN/INF/slice-32/multi-sensitive; severity mapping; presisi unixNano < 1.2 detik | ✅ sisa ekuivalen (needle generik men-subsume separator; dua strategi scrub) |
| CounterMeter + Span + Tracer + NoopSpan + InMemory | 7 → 2 ✅ | Delta negatif/NaN/INF; default 1; float cast; key ksort+unescaped JSON; overflow PHP_INT_MAX; cardinality per-name overflow+eviction; guard konjungtif; span lifecycle full; tracer singleton/inherit | ✅ sisa cast redundant |
| BatchSpanProcessor + Health + Propagators | 42 → 32 ⚠️ | Ctor defaults refleksi; flush post-shutdown zombie-queue; retry policy tak terjamah saat queue kosong; splice order [s1,s2,s3]; anchor regex caret/dollar; uppercase hex normalisasi; traceState 512 grammar-valid; Health truncation class-name 128 (fixture 145 char); toJson raw unescaped | ⚠️ sisa timing deadline/for-bound/anchor-redundant/ctor-revalidate (lihat changelog) |

### Tier 6 — Domain inti (fase 6) — 487 escape baseline riil (2.151 mutan)

| Kelas (teratas) | Escape → sisa | Kurikulum kunci | Status |
|---|---|---|---|
| Observability/CorrelationContext | 48 → 19 | W3C anchored + flags mask + tracestate 512 + token printable + agregat 4096/4097 programatik + biaya null/bool/int/float crossing + redaksi mixed-case | ✅ fase 6 |
| Resource/FilterSpec+SortSpec | 64 → 56 | cap 32/8, truncation embeda, operator grammar, eq kanonik bool/float, GT numerik-vs-leksikal, raw 512/513, defaultDesc | ✅ fase 6 |
| Job/CronExpression | 36 → 24 | lima-field grammar penuh, OR-semantika dom-dow, boundary nanodetik strictly-greater | ✅ fase 6 |
| Observability/RetryBackoffPolicy + Job/RetryPolicy | 47 → 7 | eksponen + cap, round half-up, env clamp ±1, cap 0 | ✅ fase 6 |
| Security/SecurityPolicy+Base32+Totp | 37 → 17 | default exact, env trim, error_log tmpfile, RFC 4648/4226/6238 + counter ≥2^32 | ✅ fase 6 |
| Container/NamespaceRadixTree residual | 29 → 10 | mid-edge traversal, stats exact 2.2 & 12/7, ksort, fromArray cast | ✅ fase 6 |
| Config/ModuleDefinition | 13 → 4 | trim-asli, dedup non-kontigu, koalesensi requires, continue-vs-break | ✅ fase 6 |

Sisa 132 escape terinventarisasi jujur: self-consistent codec cursor, wire-size
tak terjangkau struktural (568 < 8192), konstanta sama-dinilai (128=128),
jitter random statistik, For_/negasi loop bermuara status sama, not-covered
guard dedup.

## 5. Riwayat verifikasi (diisi per fase)

| Fase | Chunk rerun | Escape sebelum→sesudah | MSI zona sebelum→sesudah | Bukti |
|---|---|---|---|---|
| fase 1 (SecVal) | domain-secval | 249→120 | 64.4→**84.0** (covered 71.6→87.0) | 88 test / 406 asersi baru; +193 kill; not-covered 98→33; 3m27s threads=2 |
| fase 2 (Container, parsial) | src/Domain/Container + src/Application/Container | RadixTree 56→32; app-container 188 (belum tersentuh) | Domain/Container **77.1** (covered 80.2); app-container 66.6 (tetap) | EdgeMatrixRadixTreeTest 10 test/66 asersi; Container/AutowireCompilerPass/ContainerResolver → fase 2b |
| fase 2b (Container core) | Container+AutowireCompilerPass+ContainerResolver (filter 3 kelas) | 110→27 escape; 64→22 not-covered | 3 kelas **67→92** (covered 76→94); mutation coverage 88→97 | EdgeMatrixContainerTest **62 test / 155 asersi**; +103 kill; 538 mutan; 1m11s threads=2; gate 66/71→**68/73** |
| fase 3 (Runtime) | adapters-runtime-sec | 204→81 escape; 38→22 not-covered | zona **53→80** (covered 57→83) | EdgeMatrixRuntimeTest 31 test/117 asersi + EdgeMatrixSecAdapterTest 18 test/69 asersi; +138 kill; 518 mutan; 4m43s threads=2; 2 akar fatal lingkungan uji diakari (signal self-kill 143, error_log routing); gate 68/73→**69/74**; v2.14.4 |
| fase 4 (Http/Router/Kernel) | adapters-http; adapters-router-kernel (dipecah Router + Kernel) | HTTP 277→**51** escape (78→40 nc); Router 113→**57**; Kernel 147→**119** | HTTP **75→93** (covered 79→96); Router **65→85** (covered 68→87); Kernel 64→66 | EdgeMatrixHttpTest **82 test/274 asersi** + EdgeMatrixRouterKernelTest **33 test/131 asersi**; **+366 kill**; gate 69/74→**71/76**; v2.14.5 |
| fase 5 (Observability) | src/Application/Observability (4 sub-run) | Telemetry 32→**30**; San+Log+Clock 15→**5**; Meter/Span/Tracer 7→**2**; BSP/Health/Propag 42→**32** | **85 / 95 / 98 / 86** | EdgeMatrixObservabilityTest **69 test / 349 asersi**; lingkungan dipulihkan dari ZIP v2.14.6 (bukti distribusi penuh); gate 71/76→**71.5/76**; v2.14.7 |
| fase 5 (gate) | gate 66/71→**68/73**→**69/74**→**71/76**; 13 tool hijau; coverage 92.05→92.3% | — | — | CHANGELOG-v2.14.3 … v2.14.5 |
| fase 6 (Domain inti) | 5 chunk: rescfg 73→**86**, sec 85→**95**, rest 64→**89**, core-a 72→**89**, core-b 59→**93** | escape **487→132** (+509 kill / 2.151 mutan) | EdgeMatrixF6Core/Rest/Mixed **124 test / 818 asersi** + Round2 **26 test / 87 asersi**; lingkungan dipulihkan dari 7z v2.14.7 (8 detik); gate 71.5/76→**75/80**; v2.14.8 |
| fase 7 (app zones) | job/rest-a/rest-b/message/container (5 sub-chunk) | job 50, rest-a 49, rest-b 39, message 26, container 29 mutan dibunuh | job **90/92**, rest-a **92/96**, rest-b **91/93**, message **97/97**, container **88/92** | EdgeMatrixF7* 6 file **99 test / 434 asersi**; gate tetap 75/80; arsip kembali ke ZIP normal (permintaan user) |
| fase 8–9 (Infra + c3) | f8-cache **96/98**, f8-config **94/96**, f8-secinfra **85/89**, f8-obsinfra **90/91**, f9-c3a **83/89**, f9-c3b **82/82**, f9-c3c **96/96** | secinfra 11→28 tertriase (not-covered 121→12 via ekstensi nyata); obsinfra 35→22; c3a 34→32; c3b 23 (semua timing-equivalent) | zone baru seluruhnya ≥ 82, mayoritas ≥ 90 | EdgeMatrixF8* (4 file) + EdgeMatrixF9C3Test +18 test; APCu/phpredis/redis-server 8.0.2 lokal (6 test berhenti skip); inventaris ekuivalen di CHANGELOG-v2.14.9; gate 75/80→**77/82**; v2.14.9 |
| fase 10 (Container sisa + Kernel/Application + Middleware) | zona sisa Container + `Application.php` + `src/Middleware` masuk scope | Container sisa 67 → zona 96/97/99; Application.php 63 → 92 | full-run 9.032 mutan — **MSI 90.4 / Covered 93.1** | ~35 anotasi `@infection-ignore-all` terjustifikasi; **gate 85/90 TERCAPAI**; v2.15.0 |
| fase 16 (ZEF Maker) | `src/Infrastructure/Console` (22 kelas) masuk `source.directories` | Console 387/387 mutan mati | **MSI 100% / Covered 100%** (zona) | EdgeMatrixMakerTest + EdgeMatrixMakerGeneratorsTest 66 test / 503 asersi; full-run 9.419 mutan MSI **90.77/93.38**; gate tertahan 85/90; v2.16.0 |

## 6. Inventaris ekuivalen-mutant (jujur, tidak dipalsukan)

Diisi saat eksekusi per kelas, contoh pola yang sudah diketahui dari ronde 1–2:
`CastInt` setelah `ctype_digit` (hasil identik), `Throw_` pada jalur defensif yang
tidak dapat dicapai tanpa memutasi PCRE itu sendiri, `MethodCallRemoval` pada
`restore_error_handler` ganda. Justifikasi lengkap ditempel di changelog rilis.

**Fase 2b (v2.14.3) — pola terkonfirmasi:**
- Guard redundan: `(class_exists || interface_exists)` sebelum `is_a`;
  `isset(lifetimeOf) && hasFactory` (selalu bernilai sama).
- Pasangan write/read guard pada flag `shared` (resolver L114/L178) — mutasi
  tunggal tak terobservasi.
- `TrueValue` pada sentinel yang dibaca `isset()`; `TrueValue` pada map yang
  dibaca `array_keys()` (nilai diabaikan).
- Defensive dedup `array_unique`/`array_values` pada koleksi tak-berduplikat.
- Masking antar-guard: `max(-1,…)` di belakang gate `> 0`; `>=`→`>` pada
  base-add dekorasi dimasking check wrapper; ledger duplikat contextual
  dimasking guard deps-ter-rewrite.
- Catch `Closure::fromCallable` (factory selalu callable via typehint).

**Fase 3 (v2.14.4) — pola terkonfirmasi (sisa 81 escape di-triage penuh di
CHANGELOG-v2.14.4.md):**
- Branch penolakan admission struktural mati (inFlight selalu 0 di loop sinkron).
- Counter privat yang hanya ditulis, tidak pernah dibaca.
- Control-plane self-check dengan perintah hardcoded yang selalu valid.
- Mutan ±1 threshold env yang butuh presisi memori <1% vs derau arena ±2.8%.
- Catch `var_export` yang tak terjangkau (sirkular → warning, bukan exception).
- Guard `function_exists` platform-invariant; `ini_restore` vs `ini_set` pada
  routing error_log Infection (akar fatal lingkungan uji — diperbaiki).

## 7. Penutup kampanye (v2.15.0 & v2.16.0)

Dua fase penutup yang menuntaskan target multi-ronde 85/90 — detail lengkap di
`docs/CHANGELOG-v2.15.0.md` dan `docs/CHANGELOG-v2.16.0.md`.

### Fase 10 — Container sisa + Kernel/Application + Middleware masuk scope (v2.15.0)

- Zona Container sisa 67 escape → 96/97/99; Application.php 63 → 92 (OTLP sink E2E:
  span attr/status/durasi, event exception, log lifecycle, flush per-request);
  `src/Middleware` masuk `source.directories` (sebelumnya tak pernah dimutasi).
- Kurikulum: AOT reindex+shared-default, koleksi variadic (interface id / invokable /
  array-callable / union-return), nullable-default satu-argumen, budget dekorasi tepat
  count==budget, singleton shared=false resolusi ulang, guard depth tepat, RequestScope
  guards, tag grammar full-match, trustedHosts kanonisasi (jebakan kunci '1' auto-index),
  config merged modul `framework` → policy, bus freeze ×3, warmSingletons, lifecycle
  modul, emitter chunk 8192 utuh + 204/304 tanpa body, TTL CommandBus 3600.
- ~35 anotasi `@infection-ignore-all` terjustifikasi (isset-guard, budget redundan,
  edgeKey tak-terobservasi, jalur lempar identik, dsb.).
- Full-run akhir: 9.032 mutan — MSI 90.4 / Covered MSI 93.1 → gate 85/90 TERCAPAI.

### Fase 16 — ZEF Maker: seluruh permukaan CLI masuk gate (v2.16.0)

- Logika generator dikeluarkan dari `bin/zef` (procedural, di luar gate) →
  `src/Infrastructure/Console` (22 class, masuk `source.directories`). Kurikulum:
  `EdgeMatrixMakerTest` + `EdgeMatrixMakerGeneratorsTest` — 66 test / 503 asersi.
- Pola pembunuh: (1) **pesan eksak `assertSame`** — mematikan seluruh Concat /
  ConcatOperandRemoval / Coalesce pada pesan error (pesan dibangun dari label +
  raw + petunjuk regex); (2) **byte-stream IO** (`fwrite($m . "\n")` di-assert
  per byte dari stream memori) — mematikan Concat + FunctionCallRemoval ConsoleIO;
  (3) **struktur `outLog` eksak** (header/baris/baris-kosong/footer `assertCount`)
  — mematikan MethodCallRemoval baris kosong di ModuleLister, PluginLister,
  ZefMaker; (4) **data provider 10 command via `ZefMaker::run()`** — mematikan 10
  MatchArmRemoval sekaligus di `resolve()`; (5) **`0stray.txt` sortir sebelum dir
  pertama** — membedakan `continue` vs `break` pada guard non-dir PluginLister;
  (6) **umask(0) + assert fileperms 0o777** — mematikan DecrementInteger mode
  mkdir; (7) **kas kosong `assertCount(1, outLog)`** — mematikan ReturnRemoval
  yang jatuh ke cetak tabel; (8) **branch resource** di `jsonSafe` (`<resource>`).
- `sort()` redundan dihapus (scandir sudah SCANDIR_SORT_ASCENDING — mutan
  ekuivalen diselesaikan dengan menghapus kode mati, bukan anotasi).
- 3 `@infection-ignore-all` berjustifikasi: coalesce null-render pesan NamingRules
  (null concat == ''), `(string)` kunci array `jsonSafe` (normalisasi kunci PHP),
  LogicalAnd guard mkdir race-safe (varian `||` identik di semua cabang terjangkau).
- Hasil chunk: 387/387 mutan mati — **MSI 100% / Covered MSI 100%** (gate chunk
  dinaikkan ke 90, lulus +10).
