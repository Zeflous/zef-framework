# Gerbang Kualitas — ZEF Framework

Repositori ini dijaga oleh **tujuh gerbang independen**. Sebuah perubahan tidak
dianggap selesai sebelum semuanya hijau; "test lulus" bukan bukti kualitas, dan
"pipeline hijau" bukan bukti keamanan.

```bash
composer lint          # 1. syntax
composer test          # 2. suite PHPUnit native
composer coverage:gate # 3. coverage statement (ambang 90%)
composer mutation      # 4. mutation testing (ambang MSI)
composer stan          # 5. PHPStan level max + strict-rules
composer deptrac       # 6. konformansi arsitektur hexagonal
composer format:check && composer phpcs && composer rector:check   # 7. gaya & modernisasi
```

---

## 1. Syntax — `composer lint`

`php -l` atas **seluruh berkas first-party**. `vendor/`, `build/`, dan direktori cache
alat sengaja dilewati agar gerbang mencerminkan kode yang dimiliki proyek ini.

```
Linted 861 PHP files — 0 failure(s).
```

## 2. Suite PHPUnit — `composer test`

Suite native in-process (`tests/`), konfigurasi `phpunit.xml.dist` (`failOnRisky="true"`).
Cakupan sumber: `src/`, `modules/`, `plugins/` (kecuali `src/Compat`, yang merupakan
artefak byte-stabil hasil ekstraksi v2.7.0).

```
Tests: 3539, Assertions: 140435, Skipped: 6
```

Suite self-test internal (`bin/zef --self-test`) berdiri **terpisah** dan tidak
memerlukan PHPUnit sama sekali:

```
PASSED: 501  FAILED: 0
```

## 3. Coverage — `composer coverage:gate`

```bash
vendor/bin/phpunit --coverage-clover build/clover.xml --coverage-text
php scripts/ci/assert-coverage.php 90 build/clover.xml
```

Ambang **90% statement**. Alat ukur yang dipakai adalah **Xdebug**, bukan pcov:
ambang 90% dikalibrasi terhadap penghitungan statement Xdebug, sementara pcov
mengukur suite ini ~2% lebih rendah untuk profil eksekusi yang sama. Memakai
pcov akan memerahkan ambang yang sesungguhnya lulus.

## 4. Mutation testing — `composer mutation`

Gerbang paling menentukan dalam proyek ini: **bukan** seberapa banyak baris
dieksekusi test, melainkan seberapa banyak **mutan** yang benar-benar terbunuh.

```bash
composer mutation                          # gate skrip: --min-msi=85 --min-covered-msi=90
composer mutation:ci                       # versi CI (+ --logger-github) — dijalankan mutation.yml
```

Penempatan gate: suite agregat `mutation:ci` kini dijalankan oleh `mutation.yml`
(tag rilis + `workflow_dispatch`) dan **dituntut** oleh `release.yml` sebelum
rilis dipublikasikan; ratchet per-zona `mutation:zones` tetap berjalan di
`ci.yml` pada setiap push/PR. Lihat `docs/GOVERNANCE.md` 2.1 dan 2.5.

Konfigurasi kanonik: `infection.json5` (`source.directories` = `src/Domain`,
`src/Application`, `src/Infrastructure`, `src/Adapters`, `src/Middleware`;
`mutators: { "@default": true }`).

### 4.1 Metrik

| Metrik | Definisi |
|--------|----------|
| **MSI** | *Mutation Score Indicator* = mutan terdeteksi ÷ **seluruh** mutan yang dihasilkan |
| **Covered MSI** | mutan terdeteksi ÷ mutan yang **tercakup** test (mutan *not covered* dikeluarkan) |
| **Mutation Code Coverage** | mutan tercakup ÷ seluruh mutan — mengukur *reachability* test, bukan ketajaman asersi |

MSI sengaja dijadikan metrik utama: mutan yang tidak tercakup test tetap dihitung,
sehingga menambah test yang hanya mengeksekusi baris tanpa meng-assert perilaku
tidak bisa menaikkan skor.

### 4.2 Zona kampanye (dinormalisasi)

Zona = daftar berkas/direktori pada `scripts/f16_zones.tsv` (27 zona kanonik),
dijalankan satu per satu dengan `--filter` sehingga setiap area punya angka MSI-nya
sendiri. Zona kecil yang digabung kecuali berkasnya diuji terpisah:

`d-validation`, `d-resource`, `d-obs-job`, `d-container-config`, `d-misc`,
`app-container-core`, `app-container-2`, `app-job`, `app-cqrs`, `app-cache-res`,
`app-msg-evt-sec`, `infra-a`, `ad-http-a`, `ad-http-b`, `ad-http-c2`,
`ad-kernel-app`, `ad-kernel-dispatch`, `ad-kernel-mid`, `ad-router`,
`ad-runtime-sec`, `infra-obs`, `infra-sec-fnd`, `middleware`,
`app-obs-a`, `app-obs-b`, `app-obs-c`.

### 4.3 Cara menjalankan satu zona

Infection menyelesaikan **path relatif terhadap direktori berkas konfigurasi**.
Berkas konfigurasi karena itu harus berada di **root repositori**, agar
`phpunit.xml.dist` dapat ditemukan:

```bash
# tulis konfigurasi zona di root repo (source.directories = direktori induk unik)
python3 - <<'PY'
import json
paths = ["src/Domain/Validation"]
parents = sorted({p.rsplit("/", 1)[0] for p in paths})
json.dump({
    "source": {"directories": parents},
    "timeout": 90,
    "logs": {"text": "build/infection-zona.log",
             "summary": "build/infection-summary-zona.json"},
    "mutators": {"@default": True},
}, open("infection-zona.json5", "w"), indent=2)
PY

php vendor/bin/infection \
  --configuration=infection-zona.json5 \
  --threads=4 --no-progress --min-msi=0 \
  --filter=src/Domain/Validation < /dev/null
```

**Wajib `< /dev/null`** — tanpa itu Infection dapat menunggu input dan menggantung.
Berkas ringkasan `build/infection-summary-<zona>.json` inilah sumber angka MSI; log
per-mutan ada di `build/infection-<zona>.log` dan dapat ditambang otomatis oleh
`scripts/mine_escapes.py`.

### 4.4 Anggaran waktu

Mutasi adalah pekerjaan CPU-berat. Suite awal (~1 menit) berjalan sekali per zona,
lalu setiap mutan menjalankan ulang test yang relevan. `--threads` harus dipilih
menurut kuota CPU yang **nyata** dimiliki lingkungan — lihat
`/sys/fs/cgroup/cpu.max` (nilai `100000 100000` berarti kuota setara **1 CPU**,
bukan jumlah `nproc`). Menyetel `--threads` jauh di atas kuota tidak mempercepat
apa pun dan hanya menambah overhead.

### 4.5 Triage mutan

Tidak semua mutan yang bertahan adalah celah test. Triage yang jujur membedakan:

1. **Mutan terkill-belum** — test belum ada atau asersinya lemah → tulis test.
2. **Mutan ekuivalen** — perilaku runtime identik dengan aslinya (contoh nyata di
   proyek ini: `base64_decode(..., strict: true)` mengabaikan padding; `break`
   setara `continue` pada kondisi monotonik). Bila terbukti ekuivalen, tandai
   `@infection-ignore-all` **dengan justifikasi**, jangan diekstrak ke konfigurasi
   tanpa alasan.
3. **Mutan not covered** — baris tidak pernah dieksekusi → tambah jalur uji, atau
   akui sebagai *unreachable* dengan penjelasan.

Anotasi `@infection-ignore-all` selalu disertai alasan pada kode sumber; ini bagian
dari kontrak review, bukan trik untuk menaikkan angka.

## 5. Analisis statis — `composer stan`

PHPStan **level max** + `phpstan/phpstan-strict-rules`, dengan baseline beku
(`phpstan-baseline.neon`) dan `reportUnmatchedIgnoredErrors` aktif: baseline hanya
diregenerasi dengan sengaja, dan CI gagal pada setiap error di luar baseline.

## 6. Arsitektur — `composer deptrac`

```bash
composer deptrac   # deptrac analyse --config-file=deptrac.yaml --fail-on-uncovered
```

`depfrac.yaml` menetapkan lima layer hexagonal (`Domain`, `Application`,
`Infrastructure`, `Adapters`, `Compat`) plus layer aplikasi (`App`, `Module`,
`Plugin`) dan sejumlah *exception layer* terdokumentasi untuk deviasi yang disengaja.
`--fail-on-uncovered` menjadikan berkas yang tak ternaungi layer sebagai **kegagalan**,
sehingga kelas baru tidak dapat menyelinap ke luar aturan tanpa disadari.

Detail aturan arah dependensi: [`ARCHITECTURE.md`](ARCHITECTURE.md).

## 7. Gaya & modernisasi

| Perintah | Alat | Cakupan |
|----------|------|---------|
| `composer format:check` | php-cs-fixer (PER-CS2.0 + Symfony + PhpCsFixer + PHP84, risky diizinkan) | `src`, `modules`, `plugins`, `tests` |
| `composer phpcs` | phpcs + Slevomat (phpDoc/type-hint ketat) + guard struktural PSR-1 | idem |
| `composer rector:check` | Rector (dry-run) | idem |

Pembagian tugas disengaja: **php-cs-fixer memiliki byte** (spasi, brace, urutan),
**phpcs memiliki semantik phpDoc/tipe**. Tidak ada sniff whitespace di phpcs,
sehingga kedua alat tidak pernah berebut berkas yang sama.

## 8. Benchmark — `composer bench`

PHPBench (`phpbench.json`), contoh laporan agregat: resolusi `get()` singleton dan
transient pada container. Benchmark berfungsi sebagai **pagar regresi** performa,
bukan target optimasi.

## 9. CI

`.github/workflows/ci.yml` menjalankan seluruh gerbang di atas pada `ubuntu-latest`
dengan PHP 8.4 (Xdebug untuk coverage, ekstensi `mbstring, dom, xml, xmlwriter,
apcu, redis`, `apc.enable_cli=1`) dan server Redis pada `127.0.0.1:6399`.
Workflow pendamping: `docs-check.yml` (bangun dokumentasi read-only untuk PR),
`pages.yml` (terbitkan API docs ke GitHub Pages), `php-sast.yml`,
`sonarcloud.yml` (gate kualitas cloud-side SonarCloud),
`secret-scan.yml`, `dependency-review.yml`, `composer-lock.yml`, `sbom.yml` +
`sbom-generate.yml`, `phpbench.yml`, `auto-fix.yml`, `auto-update-prs.yaml`,
`release.yml`, `release-drafter.yml`, `mutation.yml` (gate agregat 85/90 saat
rilis tag), `stub-prescan.yml` (pre-scan korpus fixture, v2.34.0),
`code-scanning-issues.yml`, `snyk-security.yml`, dan `wiki.yml` (manual, opt-in —
lihat `docs/WIKI-INDEX.md`).

Nama job `PHP lint, audit, static analysis and style` bersifat **load-bearing** —
nama itu harus persis sama dengan *required status check context* pada branch
protection `main`, jika tidak check akan menggantung selamanya sebagai *pending*.
Karena itu job test sengaja **tidak** memakai matriks. Hal yang sama berlaku untuk
job `Build API documentation`.

## 10. Checklist sebelum PR

- [ ] `composer lint` bersih
- [ ] `composer test` hijau (tanpa penurunan jumlah test)
- [ ] `composer coverage:gate` lulus (≥ 90% statement)
- [ ] `composer mutation` lulus **secara lokal** (suite agregat kini dijalankan saat rilis oleh `mutation.yml`) dan **setiap zona yang disentuh** punya MSI baru ≥ 95%
- [ ] `composer stan` 0 error di luar baseline
- [ ] `composer deptrac` 0 pelanggaran, 0 uncovered
- [ ] `composer format:check`, `composer phpcs`, `composer rector:check` bersih
- [ ] Mutan ekuivalen baru diberi anotasi **berjustifikasi** di kode sumber
- [ ] Dokumentasi terkait diperbarui (`docs/`, `README.md`)
- [ ] `docs/CHANGELOG-<versi>.md` ditambahkan untuk perubahan yang memengaruhi pengguna
