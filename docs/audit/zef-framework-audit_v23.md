# Audit Drift Dokumentasi — ZEF Framework (v23)

**Tanggal:** 2026-10-03
**Baseline:** `main` = `origin/main` = `448b9ac978a47a1930ac8a0cfc36832061635d0f`
**Ruang lingkup:** drift dokumentasi (`README.md`, `docs/ARCHITECTURE.md`, `docs/CLI.md`) terhadap kondisi repo aktual + temuan kode yang di-flag ke owner.
**Authority:** L3+ (mutasi dokumentasi diotorisasi owner). **Tidak ada PR yang di-merge.**

---

## 1. Angka sumber kebenaran (diverifikasi dengan perintah nyata)

| Metrik | Nilai aktual | Perintah verifikasi |
|---|---|---|
| Berkas PHP `src/` | **640** | `find src -name '*.php' \| wc -l` |
| `src/Domain` | **290** | `find src/Domain -name '*.php' \| wc -l` |
| `src/Application` | **138** | `find src/Application -name '*.php' \| wc -l` |
| `src/Infrastructure` | **116** | `find src/Infrastructure -name '*.php' \| wc -l` |
| `src/Adapters` | **64** | `find src/Adapters -name '*.php' \| wc -l` |
| `src/Middleware` | **8** | `find src/Middleware -name '*.php' \| wc -l` |
| `src/Compat` | **23** | `find src/Compat -name '*.php' \| wc -l` |
| Berkas PHP `tests/` | **220** | `find tests -name '*.php' \| wc -l` |
| CHANGELOG di `docs/` | **42** | `find docs -name 'CHANGELOG*' \| wc -l` |
| Workflow `.github/workflows/` | **20** | `ls .github/workflows/*.y*ml \| wc -l` |
| Lint first-party | **893** | `php scripts/lint.php` → `Linted 893 PHP files — 0 failure(s).` |
| Entri classmap statis | **1154** | `grep -c "=>" autoload/zef_autoload.php` |
| Self-test | **501** | `php bin/zef --self-test` → `PASSED: 501  FAILED: 0` |
| Suite PHPUnit | **3800 / 140881 / 59** | `build/junit.xml` (evidence CI) |

---

## 2. Drift yang DIPERBAIKI

### 2.1 `README.md`

| # | Lokasi | Sebelum | Sesudah | Bukti |
|---|---|---|---|---|
| 1 | baris 136 (fitur HTTP & Router) | hanya radix-tree, group/prefix, route cache, ETag/304, Problem Details, versi API, middleware PSR-15 | + subdomain routing (multi-tenancy), route model binding, localization routing (`/{locale}/...`), content negotiation | fitur sudah ada di kode (v2.36.0, `docs/ROADMAP.md:66-71`) |
| 2 | baris 411 | `src/` = 360 berkas | **640** | `find src -name '*.php'` |
| 3 | baris 414 | Domain 181 | **290** | `find src/Domain` |
| 4 | baris 415 | Application 66 | **138** | `find src/Application` |
| 5 | baris 416 | Infrastructure 47 | **116** | `find src/Infrastructure` |
| 6 | baris 417 | Adapters 35 | **64** | `find src/Adapters` |
| 7 | baris 418 | Middleware 7 | **8** | `find src/Middleware` |
| 8 | baris 422 | `tests/` = 90 berkas | **220** | `find tests -name '*.php'` |
| 9 | baris 425 | `docs/` = 25 CHANGELOG | **42** | `find docs -name 'CHANGELOG*'` |
| 10 | baris 430 | `.github/workflows/` = 13 workflow | **20** | `ls .github/workflows/*.y*ml` |
| 11 | baris 500 (tabel gerbang) | `composer lint` = 879 berkas | **893** | `php scripts/lint.php` |
| 12 | baris 510 (quickstart) | `Linted 879 PHP files` | **893** | idem |
| 13 | baris 382 (CLI) | `route:list` = "Inspeksi rute beserta namanya" | + kolom MIDDLEWARE/HOST + dukungan `--json` | `php bin/zef route:list` / `--json` |

**Kontradiksi internal yang diperbaiki:** baris 425 (25 CHANGELOG) bertentangan dengan ringkasan rilis README sendiri yang menyebut "42 berkas CHANGELOG"; baris 430 (13 workflow) bertentangan dengan bagian CI/CD README yang menyebut "20 workflow". Keduanya kini konsisten.

> Catatan: entri historis `README.md:216` ("SonarCloud 879 → 0 pelanggaran") **tidak disentuh** — itu catatan rilis v2.31.0, bukan angka lint.

### 2.2 `docs/ARCHITECTURE.md`

| # | Lokasi | Sebelum | Sesudah |
|---|---|---|---|
| 1 | baris 7–9 (snapshot) | v2.34.1 · 630 file · Domain 287 · App 136 · Infra 115 · Adapters 60 | **v2.36.0 · 640 · 290 · 138 · 116 · 64** |
| 2 | baris 84 | Domain (287 file) | **290** |
| 3 | baris 95 | Application (136 file) | **138** |
| 4 | baris 107 | Infrastructure (115 file) | **116** |
| 5 | baris 118 | Adapters (60 file) | **64** |
| 6 | baris 182 | classmap 250 FQCN | **1154** |
| 7 | baris 189–191 | v2.34.1 · 630 file · lint 861 · suite 3.539/140.435/6 | **v2.36.0 · 640 · lint 893 · suite 3.800/140.881/59** |

### 2.3 `docs/CLI.md`

| # | Lokasi | Sebelum | Sesudah |
|---|---|---|---|
| 1 | baris 55 | `route:list` = "METHOD, PATH, NAME, HANDLER, MODULE, PRIO + jumlah" | + **MIDDLEWARE, HOST** + `--json` |

---

## 3. Temuan kode — DI-FLAG KE OWNER (tidak diperbaiki di PR ini)

### 3.1 🔴 `route:list` fatal — `RouteLister` hilang dari classmap statis

**Severity:** High (perintah CLI rusak total)
**File:** `autoload/zef_autoload.php` (tidak memuat entri) · `bin/zef:417` (pemanggil) · `src/Infrastructure/Console/Inspector/RouteLister.php` (kelas ada)

**Bukti probe (runtime nyata):**
```
$ php bin/zef route:list
PHP Fatal error:  Uncaught Error: Class "Zef\Framework\Console\Inspector\RouteLister"
not found in /workspace/zef-framework/bin/zef:417
```
Gagal **baik** pada jalur zero-composer **maupun** saat `vendor/` ada (perintah ini tidak memuat `vendor/autoload.php`; hanya `doctor` yang memuatnya).

**Akar masalah:** `RouteLister.php` ditambahkan pada commit `6d5bb64` (v2.36.0 router expansion) tetapi entri classmap statisnya tidak pernah ditambahkan. Probe menyeluruh atas seluruh `src/` menemukan **tepat 1 kelas** yang hilang dari classmap: `Zef\Framework\Console\Inspector\RouteLister`.

**Mengapa CI hijau:** semua job CI menjalankan `composer install` lebih dulu, sehingga jalur zero-composer (yang menjadi klaim utama README "tanpa Composer") tidak pernah dieksekusi. Tidak ada test yang menjaga kelengkapan classmap.

**Status:** **Tidak diperbaiki di PR ini** — perbaikan menyentuh kode (`autoload/zef_autoload.php`) dan/atau test baru, di luar lingkup PR dokumentasi. **Butuh keputusan owner** (lihat §5).

---

## 4. Verifikasi

| Gate | Hasil |
|---|---|
| `php scripts/ci/assert-release-docs.php` | `RELEASE_DOCS_RATCHET_OK` (exit 0) |
| `php scripts/ci/assert-release-cadence.php` | `RELEASE_CADENCE_RATCHET_OK` (exit 0) |
| `php scripts/lint.php` | `Linted 893 PHP files — 0 failure(s).` |
| `php bin/zef --self-test` | `PASSED: 501  FAILED: 0` |

---

## 5. Rekomendasi / keputusan yang diminta ke owner

1. **Bug `RouteLister`/classmap (§3.1):** apakah diizinkan membuka PR perbaikan kode terpisah yang (a) menambahkan entri classmap `RouteLister`, dan (b) menambahkan guard test anti-drift (assert setiap kelas `src/` ada di classmap statis) + job CI zero-composer yang menjalankan `bin/zef route:list` tanpa `vendor/`? Ini menutup kelas bug yang sama untuk seterusnya.
2. **Angka struktur proyek di README/ARCHITECTURE:** saat ini manual dan mudah drift. Pertimbangkan gate otomatis (mirip `assert-release-docs.php`) yang menghitung `src/`/`tests/`/workflow dan membandingkan dengan README.
3. **`docs/ARCHITECTURE.md` section 8–11** masih memuat angka historis per rilis (v2.8.0–v2.11.0) — sengaja dipertahankan sebagai rekam jejak; tidak diubah.
