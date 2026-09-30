# CHANGELOG v2.32.0 — Unified Queue & Broker

**Tema**: driver antrean job tahan-lama ketiga — `RedisStreamJobQueue` di
atas Redis Streams — plus permukaan CLI antrean terpadu
(`queue:work`/`queue:failed`/`queue:retry`/`queue:flush`) yang
driver-agnostic lewat port. Deployment shared-nothing (Redis tanpa
database) kini punya padanan penuh untuk `PdoJobQueue`. **Nol paket runtime
baru** (kebijakan 3-paket dipertahankan); `ext-redis` sudah dinyatakan di
`require-dev` + `suggest` sejak remediasi audit v5 — rilis ini hanya
memanfaatkannya.

Rentang: setelah CHANGELOG-v2.31.0 (Zero-Debt & Fail-Closed Gates,
2026-09-30, PR #263) sampai tag `v2.32.0` (2026-09-30) — membawa PR #264
(`feat/unified-queue-redis-streams`) dan ekor deflake PR #263.

## Fitur baru

### `RedisStreamJobQueue` — driver antrean Redis Streams (PR #264)

- **Klaim atomik satu-script Lua**: `XRANGE` memilih kandidat terbaik →
  `XDEL` + `SREM` → field datar kembali ke PHP — semuanya dalam satu
  eksekusi Redis. Tidak ada `XREADGROUP`, tidak ada lifecycle pending-entry
  (`XACK`/`XPENDING`/`XAUTOCLAIM`): klaim = *atomically claim and remove*,
  paritas semantik dengan `SELECT → DELETE → execute` milik `PdoJobQueue`.
  Crash worker tidak meninggalkan entri yang harus direklamasi — antrean
  self-healing tanpa mesin reclaim tambahan (job in-flight yang gagal
  bersama worker tetap mengikuti semantik claim-destruktif PDO).
- **Paritas ordering** `priority DESC, available_at ASC, seq ASC`: entry-id
  stream (`<ms>-<seq>`, monoton server-side) berperan sebagai tie-breaker
  seq PDO — dua job ber-priority dan ketersediaan sama tetap keluar dalam
  urutan enqueue. JobEnvelope tidak memiliki field `seq` (seq adalah detail
  internal storage), jadi entry-id stream adalah satu-satunya sumber
  kebenaran urutan — atomik by construction, tidak bisa drift.
- **Backstop duplikat** via SET live-id — padanan moral `UNIQUE(job_id)`
  PDO: enqueue ulang job_id yang masih hidup ditolak keras
  (`RedisJobQueueException`); re-enqueue setelah klaim tetap legal (jalur
  retry). Dedup antrean tetap terpisah total dari idempotensi eksekusi
  (`JobIdempotencyStoreInterface`).
- **Presisi Lua 2^53**: `available_at` disimpan sebagai string 20-digit
  zero-padded — angka Lua adalah double IEEE (exact hanya sampai 2^53) dan
  satu timestamp nano (~1.7e18) sudah melampauinya; dua deadline berbeda
  bisa collapse menjadi double yang sama dan diam-diam bertukar urutan.
  Perbandingan leksikografis string = perbandingan numerik untuk seluruh
  rentang nano non-negatif.
- **Liveness-over-preservation**: entri ber-payload korup dimusnahkan
  oleh klaimnya sendiri dan kegagalannya ter-surfaces tepat sekali —
  perbedaan terdokumentasi dari `PdoJobQueue`, tempat baris korup bertahan
  dalam transaksi yang di-rollback dan meracuni setiap dequeue berikutnya
  (antrean mengunci pada baris yang sama selamanya).
- **Guard kapasitas** (`XLEN` → `OverflowException`), **`peek()` read-only**
  untuk `queue:failed` (menghidrasi tanpa pernah mengklaim), **grammar nama
  antrean** di batas storage (allow-list `strspn` eksplisit, Sonar S5867 —
  byte mentah tidak pernah sampai ke keyspace), validasi ulang `job_id` di
  batas storage (paritas Regresi P-4, issue #170).
- **Trade-off terdokumentasi**: dequeue memindai seluruh stream demi
  correctness ordering penuh — O(N) per klaim (PDO: O(log N) via indeks
  ordering); `maxSize` adalah bound operasionalnya. Ini dipilih sadar di
  atas bounded-scan (`XRANGE COUNT n`): window terbatas dari kepala stream
  bisa menyembunyikan job eligible di belakang dinding job delayed —
  pelanggaran diam-diam atas kontrak priority yang menyamar jadi guard
  latensi.

### CLI antrean terpadu (PR #264)

- **`bin/zef queue:work`** — worker daemon/batch di atas
  `InProcessJobWorker` yang di-wire aplikasi (factory closure dari
  composition root — bukan engine eksekusi kedua): handler, middleware,
  retry policy, idempotency, dan DLQ tetap urusan worker; CLI hanya
  lifecycle. `SIGTERM`/`SIGINT` dihormati **antar-job** (kontrak dequeue
  destruktif — job in-flight dibiarkan selesai, jalur retry/DLQ tetap
  berlaku), `--memory=<mb>` berbagi checkpoint antar-job yang sama dan
  exit **2** agar supervisor (systemd `Restart=on-failure`, RoadRunner,
  K8s) mengganti proses alih-alih menampung kebocoran; `--once` (drain lalu
  exit saat kosong), `--max=<n>` (berhenti setelah n job).
- **`bin/zef queue:failed`** — tabel DLQ read-only (`peek()`, tidak pernah
  mengklaim): job-id, type, attempt, available-at (ISO-8601 UTC),
  correlation.
- **`bin/zef queue:retry`** — re-delivery dengan **attempt dipertahankan**
  (retry = keputusan pengiriman ulang, bukan reset attempt — attempt yang
  sudah mengehabiskan RetryPolicy akan dead-letter lagi pada kegagalan
  berikutnya); job_id yang masih hidup di antrean utama di-skip (backstop
  duplikat sedang bekerja, bukan crash worker); seen-set memutus siklus
  requeue; `--max=<n>` (default 50) / `--all`.
- **`bin/zef queue:flush`** — purge bounded/unbounded, port-agnostic.
- **Penyimpanan job gagal = instance kedua `JobQueueInterface`** yang
  di-wire sebagai `deadLetterQueue` `InProcessJobWorker` (stream Redis
  khusus `zef.queue.failed`, tabel PDO, atau InMemory di test) — *port
  composes*, tanpa kelas repository baru; seluruh sub-perintah
  queue:* driver-agnostic lewat port.

## Perbaikan

- **Deflake `X-Response-Time`** (PR #263): platform smoke PHP 8.5 gagal —
  `'0ms'` tidak cocok `/^\d+\.\d+ms$/` (run 109715900195). Akar masalah:
  konkatenasi float ter-round; PHP merender `0.0` sebagai `"0"`, sehingga
  handler sub-0.005ms menghasilkan header tanpa desimal yang kontrak (dan
  test) wajibkan. `sprintf('%.2fms')` menjaga bentuk dua-desimal
  deterministik di semua platform dan kecepatan; guard sama diterapkan
  pada salinan paralel `app/`.
- **Hardening SonarCloud/SAST** (PR #264): exception domain khusus
  `RedisJobQueueException` (driver type tidak pernah bocor ke caller);
  alfabet nama antrean eksplisit `strspn` (S5867) alih-alih regex
  character-class.
- **Rector zero-behaviour** (PR #264): helper privat jadi instance method
  (`LocallyCalledStaticMethodToNonStatic`).

## Dokumentasi

- **`docs/CLI.md`**: seksi baru "6. Antrean kerja — `bin/zef queue:*`" —
  contoh pemakaian, opsi, dan semantik graceful-shutdown keempat command.
- **`docs/ROADMAP.md`**: baris "Multi-driver (Redis, Database, Beanstalk,
  SQS)" → **[x]** untuk Redis + worker operasional; sisa Beanstalk/SQS dan
  chaining/batching tetap terbuka.

## Kualitas

- **PHPUnit +67 test** (3245 → 3312): suite live-Redis (paritas ordering
  3-kunci, backstop duplikat, kapasitas, liveness payload korup, peek
  non-claiming), suite hermetic fake-Redis (pola F8: klasifikasi hasil
  `eval`/`xLen`/`xRange`, tipe argumen Lua, kontrak clock padded), harness
  CLI (pasangan InMemory + `HermeticConsoleIo`), dan
  `JobQueueIndexUpgradeTest` (klasifikasi error upgrade indeks
  `SeqBackstop` + narrow cast `JobRowCodec` yang ter-surface saat
  pengukuran ulang zona).
- **Zona mutasi baru** `infra-job-redis` — di-carve dari `infra-job-pdo`
  (registry menjadi file-list eksplisit): **MSI 98.35**, 119/121 killed,
  0 not-covered, 2 escape ter-triage sebagai ekuivalen (jitter literal
  clock default 1000000000 ±1 — sub-nanodetik per detik, tak teramati di
  test non-racy). `infra-job-pdo` diukur ulang saat split: MSI 96.37,
  coveredMSI 100.00 — baseline ter-committed mengikuti pengukuran.
- PHPStan level max + strict-rules: 0 error; cs-fixer 0/799; PHPCS, rector,
  deptrac, lint (840 berkas) bersih; self-test 501/501; classmap **1118
  kelas** (badge README disinkronkan 1112 → 1118); coverage gate ≥ 90%
  hijau; ratchet PHPStan baseline tetap 0 drift.

## Catatan upgrade

- `composer.json` **tidak berubah** — `ext-redis` tetap `require-dev`
  (profil CI) + `suggest` (produksi); aktifkan `ext-redis` di image worker
  yang memakai driver Redis.
- Semua API additive: implementasi `JobQueueInterface` baru + 4 command
  CLI; tidak ada API lama yang berubah atau dihapus.
- `ZefVersion::VERSION` 2.31.0 → 2.32.0; README (badge, baris Ecosystem,
  tabel CLI, tabel riwayat, tautan changelog), SECURITY.md, dan
  docs/README.md disinkronkan — ratchet docs memin keduanya ke versi kode.
