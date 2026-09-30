# CHANGELOG v2.31.0 — Zero-Debt & Fail-Closed Gates

**Tema**: garis rilis pasca-migrasi org (mbetixz → Zeflous) yang menuntaskan
kualitas menjadi kebijakan permanen: SonarCloud dari **879 pelanggaran → 0**,
scope analisis dikunci fail-closed ke kode milik ZEF, gerbang Snyk
ber-verdict ketat dengan bukti SARIF terkonsolidasi jadi satu check, postur
DX/DEEP ditutup, dan antrean PR berjalan zero-click. **Nol fitur runtime
baru, nol paket baru** — murni rilis kualitas, gerbang, dan otomasi.

Rentang: setelah CHANGELOG-v2.30.0 (Ecosystem Ports, 2026-09-25) sampai tag
`v2.31.0` (ef80bbc, 2026-09-30), mencakup era transfer org.

## Gerbang & integrasi

### SonarCloud — scope ketat + zero-debt (PR #251, #256, #257, #260)

- **Scope fail-closed (PR #251)**: analisis hanya menilai kode milik ZEF —
  kode pihak ketiga tidak lagi menyembunyikan atau menimbun temuan. 1308
  temuan yang tersurface oleh scope baru ditriase tuntas, dua isu terakhir
  ditutup, dan disposition duplikasi `app/**` dikoreksi (demo = surface
  `bin/zef`; hanya `Bootstrap` + middleware yang parallel-copy).
- **Zero-debt (PR #256, #257)**: kampanye bucket 0–7 menurunkan **879
  pelanggaran → 0** (new-code) dan **76 temuan overall-codebase → 0** —
  `main` hijau penuh di gerbang SonarCloud.
- **Stabilisasi analisis (PR #260)**: `sonar.python.version` dipin ke 3.12.
- Refaktor pelengkap zero-behaviour: ekstraksi `HostAuthorityParser`
  (S1448, paritas rector), konsolidasi return, rename shadowing, split
  template literal — semuanya internal tanpa perubahan API.

### Snyk — verdict fail-closed + konsolidasi bukti (PR #246, #262)

- **PR #246**: gerbang Snyk Code/SCA/IaC strict fail-closed — tiga scanner
  CLI berjalan `--severity-threshold=low` dalam satu job, verdict tunggal
  dari exit code (0 = bersih; 1 = temuan = GAGAL; >1 = error = GAGAL;
  hasil hilang = GAGAL), upload SARIF selalu sebelum verdict.
- **PR #262**: tiga upload SARIF digabung menjadi **satu** check
  `Code scanning results / Snyk` (`scripts/ci/merge_snyk_sarif.py`, pola
  upload multi-run CodeQL); bukti jadi advisory, verdict tetap dari scanner.
  Required contexts proteksi `main` dirapikan 12 → 10.
- Kebijakan `.snyk` (exclusion path + license ignore beralasan dan
  berkedaluwarsa) dipertahankan; tidak ada vulnerability yang di-ignore.

### Transparansi temuan & antrean PR

- **Cermin alert → issue** (PR #228, #239): alert code-scanning otomatis
  dibuatkan issue GitHub-nya (`scripts/ci/sync-code-scanning-issues.sh`) —
  temuan tidak pernah diam di tab alert saja.
- **Antrean PR zero-click**: `auto-update-prs.yaml` matang — FIFO
  oldest-first, arm-on-open (auto-merge aktif sejak detik pertama usia PR),
  rantai antrean hidup lewat event lifecycle (merge dieksekusi sebagai akun
  manusia via `ZEF_CI_PAT`), jaring pengaman cron 20 menit di menit
  7/27/47 (di luar menit puncak yang rutin dijatuhkan GitHub), approve
  otomatis untuk run bot, dan concurrency yang men-serial antrean.
- **Hardening konfigurasi (PR #255)**: 24 temuan audit konfigurasi
  (F-01..F-24) ditutup — termasuk kalibrasi ulang coverage gate agar
  floor-nya tercapai (F-03) dan pemulihan gate yang hilang saat migrasi.

## Perbaikan postur (DX & DEEP)

- **ZEF-DX-07..10 (PR #259, issues #247–#250)**: temuan postur keamanan
  ditutup (`fix(security)`).
- **ZEF-DX-01..06 (PR #216–#221)**: versi path-repo `make:app`, `tinker`
  tanpa boot container penuh, alias `composer audit`, guard skip Redis
  self-test, redaksi `config:show`, sinkronisasi statistik docs.
- **ZEF-DEEP-15..22 (PR #238–#245)**: sisa utang storage/http/event
  sourcing/platform dibersihkan + 20 nitpick roundup.
- **Stabilisasi suite**: order-independence lifecycle F10, teardown symlink,
  deflake jendela temporal `occurredAt`, dan belasan perbaikan kecil lain —
  rinciannya tercatat di PR masing-masing.
- **Referensi org**: seluruh referensi repo diarahkan ke org Zeflous
  setelah transfer (PR #240).

## Kualitas

- SonarCloud **0 pelanggaran** di `main` (new-code dan overall-codebase).
- PHPStan level max + strict rules: 0 error; Deptrac 0 pelanggaran;
  PHPCS/cs-fixer/rector bersih; lint penuh.
- Suite **3245 test**; classmap **1112 kelas** — badge README disinkronkan
  ke eksekusi nyata (ratchet angka, issue #215).
- Coverage gate ≥ 90% hijau; zona mutasi per-area tetap diratchet di CI
  (suite agregat pindah ke `mutation.yml` — lihat GOVERNANCE.md 2.5).

## Catatan upgrade

- `composer.json` tidak menambah dependensi (kebijakan 3-paket
  dipertahankan); hanya alias script `audit:security` dan referensi org.
- Tidak ada API publik baru, dihapus, atau diubah; seluruh refaktor
  zero-behaviour (seam `TemplateLoader` (#252) dievaluasi lalu di-revert
  (#253) karena nol perilaku berubah).
- `ZefVersion::VERSION` 2.30.0 → 2.31.0; README (badge, tabel riwayat,
  tautan changelog) dan SECURITY.md disinkronkan — ratchet docs
  (`assert-release-docs.php`) memin keduanya ke versi kode.
