# CHANGELOG v2.34.1 — Signed Available-at Parity

**Tema**: bug paling dalam yang pernah lolos dari matriks v2.32.0: deadline
negatif di `RedisStreamJobQueue`. `padNano()` mem-format nilai non-negatif ke
20 digit ber-pad nol, tetapi mengembalikan nilai negatif **apa adanya** —
sehingga pembandingan leksikografis Lua atas field `available_at`
(8) membalik **kedua** arah sekaligus: urutan antrean (job bertanda minus
terbesar tampak paling kecil) dan verdict ketersediaan (job yang belum jatuh
tempo dianggap sudah due). Kontrak domain sebenarnya adalah signed 64-bit
penuh — `InMemoryJobQueue` dan `PdoJobQueue` sudah benar; Redis-lah yang
menyimpang, dan hanya pada sisi negatifnya. Reproduksi live tiga adapter
(issue #272) membuktikan divergensi dua arah sebelum fix, dan **paritas
penuh tiga adapter** sesudahnya.

Rentang: setelah CHANGELOG-v2.34.0 (Stub Pre-scan CI, 2026-10-01, PR #271)
sampai tag `v2.34.1` (2026-10-01) — membawa PR #273
(`fix/redis-nano-signed-parity`), menutup issue #272.

## Perbaikan

### padNano() order-preserving — 9's complement untuk sisi negatif

- **Matriks dikommit sebelum fix** (`docs/JOB-QUEUE-PARITY.md`, 103 baris,
  12 batas: encoding shape, byte-compat non-negatif, round-trip eksak
  `PHP_INT_MIN`..`PHP_INT_MAX`, ordering leksikografis == numerik,
  availability verdict, corrupt-entry doctrine, fuzz invarian I1/I2/I3) —
  disiplin yang sama dengan matriks antrean v2.32.0, matriks 12 batas
  OpenAPI v2.33.0, dan matriks 12 dimensi stub pre-scan v2.34.0.
- **Encoding**: `v >= 0` tetap `sprintf('%020d', $v)` — **byte-identik**
  dengan v2.32.0–v2.34.0, jadi antrean realistis (deadline non-negatif)
  tidak tersentuh satu byte pun: nol migrasi, nol deploy step. `v < 0`
  menjadi `'-'` + komplemen-9 dari magnitude 19 digit ber-pad nol
  (`-20` → `-9999999999999999979`) — transformasi yang memelihara urutan:
  kian besar magnitudenya, kian *kecil* string komplemennya, persis
  kebalikan urutan numeriknya. `PHP_INT_MIN` round-trip **eksak** tanpa
  pernah menyentuh float.
- **Doktrin corrupt-entry (liveness-over-preservation)**: `decodeNano()`
  kini validasi shape dua bentuk sah (20 digit; `'-'`+19 digit). Field
  berbentuk lain — termasuk legacy negative unpadded tulisan rilis
  pra-v2.34.1 — dianggap korup dan melempar `RedisJobQueueException`
  **tepat sekali**: klaim Lua sudah menghapus entrinya, antrean tetap
  mengalir, kegagalan hidrasi tersurat. Antrean realistis tidak terdampak
  (bytes-nya tidak berubah).
- **Rector**: `padNano()`/`decodeNano()` (dan kembarannya di test) menjadi
  instance method (`LocallyCalledStaticMethodToNonStatic`) — harness
  refleksi kini melalui shell instance tanpa konstruktor; codec murni
  yang tak pernah menyentuh `$this`.

## Pengujian

- **`tests/Unit/QueueNegativeTimestampParityTest.php` (BARU)**: 32 test /
  **74.090 asersi** — paritas tiga adapter (Redis live 6399 / InMemory /
  PDO SQLite) untuk ordering **dan** availability pada sisi negatif;
  round-trip signed penuh termasuk `PHP_INT_MIN`/`PHP_INT_MAX`;
  byte-compat legacy; doktrin corrupt-entry live; fuzz **250 acak + 13
  batas** dengan invarian `strcmp(encoded) == (<=> numerik)` untuk semua
  pasangan.
- **Ratchet**: `tests/fixtures.limit` 190 → 191 (korpus stub pre-scan
  fail-closed dua arah); README suite-count 3500 → **3532**.
- **SonarCloud php:S1192** (literal digit 4×) ditutup dengan konstanta
  `DIGITS`/`DIGITS_COMPLEMENT` — byte-identik, nol duplikasi.

## Kualitas

- **Angka rilis**: PHPUnit **3532 test / 140.419 asersi / 6 skipped /
  0 gagal** (+32 test / +74.090 asersi dari v2.34.0); coverage gate
  **97.04%** (15.525/15.998 statement, syarat 90%); PHPStan level max +
  strict-rules 0 error; cs-fixer 0/820; self-test 501/501; rector
  dry-run 0 file; stub pre-scan hijau atas korpus 191 berkas; 17/17
  check PR #273 hijau sebelum merge (job ID 110357771945).
- **Nol paket baru**; nol perubahan API publik; nol perubahan skema —
  murni perbaikan codec internal satu kelas infrastruktur.
