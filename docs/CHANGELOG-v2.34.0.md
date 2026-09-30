# CHANGELOG v2.34.0 — Stub Pre-scan CI

**Tema**: gerbang baru untuk kode yang selama ini tak terlihat: **korpus fixture
`tests/`** — 190 berkas PHP yang berjalan di dalam lane CI dengan kredensial
repositori — kini di-scan oleh dimensi Bugs/Security SonarCloud lewat project
terkarantina, plus ratchet jumlah fixture fail-closed dua arah. Kontrak
disiplinnya dikunci lebih dulu lewat **matriks paritas 12 dimensi
INCLUDED/EXCLUDED** (`docs/security/stub-prescan.md`, dikommit sebelum
implementasi — disiplin yang sama dengan matriks antrean v2.32.0 dan matriks 12
batas OpenAPI v2.33.0). **Nol paket baru, nol kode produk berubah** — murni
gerbang, dan satu perbaikan CI rilis.

Rentang: setelah CHANGELOG-v2.33.0 (OpenAPI Runtime Gate, 2026-10-01, PR #268)
sampai tag `v2.34.0` (2026-10-01) — membawa PR #269 (fix release draft-publish)
dan PR #270 (`feat/stub-prescan`).

## Gerbang & integrasi

### Stub pre-scan — korpus fixture `tests/` (PR #270)

- **Celah yang ditutup**: keputusan scope v4 (`sonar-project.properties`)
  mengeluarkan `tests/**` dari analisis SonarCloud utama demi alasan terukur
  (coverage runtuh 33.6% vs 97.0%; 976 bug reliabilitas berkelas idiom fixture).
  Konsekuensi tak terdokumentasi: **aturan bug/vulnerability/hotspot SonarCloud
  tidak pernah melihat korpus** — padahal Semgrep php-sast memang memindai
  `tests/` secara blocking sejak 2026-09-24 atas alasan yang sama (kode uji
  berjalan dengan kredensial repositori). Pre-scan memberi dimensi itu rumah
  terkarantina tanpa menyentuh metrik project utama sedikit pun.
- **Matriks 12 dimensi** (`docs/security/stub-prescan.md`): SAST ERROR/WARNING,
  Bugs correctness (Semgrep), Bugs deep (PHPStan) — INCLUDED untuk korpus
  (baris 1–4, dua di antaranya baru); Bugs/Security SonarCloud utama tetap OUT
  untuk korpus (delegasi ke baris 7); **CPD EXCLUDED by design** (baris 8 —
  duplikasi adalah idiom fixture: sebuah double memang meniru bentuk kelas
  produksi; ceiling duplikasi akan melahirkan hierarki fixture terwarisi yang
  menukar repetisi terlihat dengan kopling tersembunyi — anti-pola untuk kode
  uji yang dibaca mutation ratchet); coverage & secrets direkam apa adanya.
- **Korpus struktural, bukan heuristik**: semua berkas PHP di bawah `tests/`
  (190). Klasifikasi nama (`*Stub*`/`*Fake*`) gagal-terbuka — double bernama
  `RecordingThing` lolos diam-diam; batas struktural gagal-tertutup — setiap
  berkas baru mengubah hitungan.
- **Ratchet jumlah fixture fail-closed dua arah** (`tests/fixtures.limit`):
  berkas baru tanpa bump = job merah (keputusan scope dilewati); berkas
  dihapus/dipindah tanpa bump = job merah. Setelah scan, ukuran `files`
  SonarCloud di-assert terhadap limit yang SAMA — typo inclusions yang
  menyempitkan scope diam-diam adalah job merah, bukan gerbang hijau senyap.
- **Project terkarantina `zeflous_zef-framework-stubs`** (publik, di-scan
  ber-branch per-run `prescan/pr-N` / `prescan/main`): scope di-pin POSITIF
  (`sonar.inclusions=tests/**` — pohon baru tak bisa masuk tanpa review
  matriks), CLI `-D` meng-override `sonar-project.properties` per kunci
  (daftar `sonar.exclusions` utama berisi `tests/**` dan WAJIB dioverride),
  CPD & coverage di-exclude by design.
- **Gate zero-tolerance tiga dimensi, kondisi overall** (tanpa dependensi
  semantik new-code period): `bugs = 0`, `vulnerabilities = 0`, seluruh
  hotspot tereview (`security_hotspots_reviewed = 100%`). **Baseline terukur
  NOL ketiganya** (190 berkas, 81.122 baris) — budget-nya nol karena memang
  tak ada yang ditoleransi; metrik INT `security_hotspots` sendiri tidak
  gate-eligible di SonarCloud (terukur), bentuk reviewed-ratio membawa semantik
  yang sama.
- **Provisioning idempotent fail-closed DI DALAM workflow** (components/show →
  create jika 404; qualitygates list/create + kondisi per-metrik + select;
  drift condition-set = job merah): sandbox yang bisa menjalankan workflow
  bisa membangun ulang seluruh karantina — tanpa langkah UI SonarCloud manual
  di keadaan tunak. Seluruh keanehan API yang di-encode terukur lewat run
  probe di branch (probe di-drop sebelum PR): key `qualitygates` LOWERCASE di
  `list`; `create_condition` menerima `op` (bukan `operator`) + wajib
  `organization`; `select` menjawab 204.
- **Semantika kegagalan**: token absen/withheld (PR fork) → skip diumumkan
  `::notice::` — SATU-SATUNYA tepi fail-open (mirror perilaku terdokumentasi
  workflow SonarCloud utama untuk kondisi sama; ratchet jumlah tetap jalan);
  token ada tapi provisioning ditolak → MERAH; berkas terindeks ≠ limit →
  MERAH; gate merah → MERAH via `sonar.qualitygate.wait=true`.
- **Required check ke-12**: "Stub pre-scan (tests fixtures)".
- **Indirection kredensial curl**: rule gitleaks `curl-auth-user`
  pattern-match bentuk literal `-u "nilai:"` dan tak bisa membedakan REFERENSI
  secret dari literal (terukur: satu temuan per panggilan langsung di revisi
  pertama). Kredensial dirakit sekali ke variabel dan dioper indirection-first
  — pola struktural rule tak pernah muncul di teks sumber, kredensial literal
  NYATA di tempat lain tetap menyalakan rule: nol rule dimatikan, nol
  fingerprint di-allowlist, nilai runtime tetap dari secret store.

### Semgrep correctness family — 4 lane php-sast (PR #270)

- Keluarga bug-detector `php/lang/correctness` dari tarball ter-pin yang SAMA
  (sha256 terverifikasi — permukaan pinning tidak bertambah satu byte) kini
  di-load di keempat lane: produksi ERROR/WARNING dan tests ERROR/WARNING.
  Ini baris-3 matriks: Bugs INCLUDED untuk pohon produksi dan korpus sekaligus,
  mencerminkan apa yang sudah dilakukan keluarga security.
- **Terukur sebelum enable** (run lokal ruleset ter-pin): **0 temuan / 652
  target produksi + 0 / 190 target korpus** — promosi ke blocking dikirim
  dalam perubahan yang sama dengan populasi kosong, karenanya ter-triage
  penuh tanpa sisa.

### Release workflow — draft blind-spot ditutup (PR #269)

- v2.32.0 DAN v2.33.0 dua-duanya berakhir dengan publish manual: cabang
  create-or-upload idempotent menemukan release yang sudah ada (draft Release
  Drafter yang namanya cocok persis dengan tag di v2.33.0; publish manual saat
  remediasi insiden di v2.32.0), mengunggah aset final ke DRAFT itu, lalu
  berhenti — release lengkap-tapi-tersembunyi sampai ditekan tombolnya dua
  kali berturut.
- Fix murni aditif: setelah upload `--clobber`, bila release yang baru
  di-refresh masih draft → `gh release edit --draft=false --latest`
  mem-publish-nya (contoh terdokumentasi `gh release edit --help` untuk
  operasi persis ini). Release non-draft tak tersentuh. Mulai v2.34.0,
  workflow Release self-contained penuh — tanpa publish manual.

## Dokumen

- **`docs/security/stub-prescan.md`** (BARU): matriks paritas 12 dimensi —
  dikommit duluan; diperkaya fakta terukur (budget nol; keanehan API; alasan
  CPD EXCLUDED dengan argumen yang bisa ditantang lewat PR yang mengubah
  §4-nya, bukan lewat edit properti).
- **`docs/security/php-sast.md`**: §3 mencatat keluarga correctness + angka
  terukur saat enable.
- **`docs/ROADMAP.md`** §12, **`docs/README.md`** (tree), **README.md**
  (tabel security): entri gerbang baru.

## Kualitas

- **Angka rilis tak berubah by design** (rilis murni gerbang — tak ada kode
  produk/tes yang ditambah): PHPUnit 3500 test / 66.329 asersi / 0 gagal;
  coverage gate 97.04%; PHPStan level max + strict-rules 0 error; cs-fixer
  0/819; lint 860 berkas; self-test 501/501; classmap 1145 kelas; 36 zona
  mutasi + ratchet zone PASSED (tidak ada zona baru — tak ada kode produk
  baru).
- **Angka baru**: korpus fixture **190 berkas / 81.122 baris**; gate stub
  pre-scan zero-tolerance (bugs 0 / vulnerabilities 0 / hotspots 0);
  required checks **11 → 12**; jumlah lane Semgrep per workflow php-sast tetap
  4 (config path bertambah, bukan lane).
- Gitleaks: nol rule dimatikan, nol fingerprint allowlisted (indirection
  sumber, bukan suppressi scanner) — diverifikasi lokal dengan binary
  gitleaks atas rentang commit PR sebelum push.
