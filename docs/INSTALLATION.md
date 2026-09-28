# Instalasi & Konfigurasi — ZEF Framework

## 1. Persyaratan

| Komponen | Versi | Catatan |
|----------|-------|---------|
| PHP | **>= 8.4** | diuji pada 8.4.25 (CLI, NTS) |
| Ekstensi wajib | `json`, `mbstring`, `dom`, `xml`, `xmlwriter` | PHPUnit + framework |
| Ekstensi opsional | `redis` (phpredis), `apcu`, `pcntl`, `pcov`/`xdebug` | lihat tabel di §6 |
| Composer | 2.10.x | hanya untuk jalur "dengan Composer" |
| RoadRunner | v2025.1.15 | biner `rr` untuk mode produksi (persistent worker) |
| Redis | 7.x / 8.x | dibutuhkan hanya oleh uji rate-limiter terdistribusi |

> Framework **tidak memerlukan Composer untuk berjalan**: autoloader classmap statis
> (`autoload/zef_autoload.php`) sudah memuat seluruh kelas framework, dan shim PSR
> kondisional di `src/Compat/Psr/` aktif otomatis bila paket `psr/*` tidak tersedia.

## 2. Instalasi minimal (tanpa Composer)

```bash
git clone https://github.com/Zeflous/zef-framework.git
cd zef-framework

php bin/zef --self-test       # 501 assertion, 0 dependensi eksternal
php bin/zef --serve 0.0.0.0:8080
```

Ini adalah jalur tercepat untuk mengevaluasi framework — tidak ada unduhan paket,
tidak ada berkas cache, dan tidak ada I/O jaringan.

## 3. Instalasi dengan Composer (disarankan untuk pengembangan)

```bash
composer install --no-interaction --no-progress
composer validate --strict
composer check-platform-reqs
```

Paket dev yang terpasang (**jangan** dipindahkan ke `require` produksi):
`phpunit/phpunit` ^10, `infection/infection` ^0.30, `phpstan/phpstan` ^2.2 +
`phpstan/phpstan-strict-rules`, `squizlabs/php_codesniffer` + `slevomat/coding-standard`,
`friendsofphp/php-cs-fixer` ^3.95, `rector/rector` ^2.6, `deptrac/deptrac` ^4.7,
`phpbench/phpbench` ^1.4, `code-lts/doctum` ^5.6.

### 3.1 Redis untuk uji rate-limiter

`tests/Unit/RedisStoreTest.php` menghubungkan ke `127.0.0.1:6399` dengan kata sandi
`zef-test-secret`. Tanpa server hidup, suite Redis **self-skip** (dan cakupan
rate-limiter ikut hilang secara diam-diam), karena itu CI menyediakan server khusus:

```bash
docker run -d --name zef-redis \
  -p 127.0.0.1:6399:6399 \
  redis:7-alpine redis-server --port 6399 --requirepass zef-test-secret
```

### 3.2 setelan INI yang penting

```ini
apc.enable_cli=1          ; APCu wajib untuk uji rate-limiter APCu
error_log=/dev/null       ; lihat catatan di bawah
```

`phpunit.xml.dist` mengarahkan `error_log` ke `/dev/null` secara sengaja: Infection
menghentikan suite awal pada byte STDERR pertama, sehingga jalur peringatan
`error_log()` di dalam test tidak boleh mematikan run mutasi. Test yang memang
meng-assert isi `error_log` menimpanya secara lokal.

## 4. RoadRunner (mode produksi)

Bridge (`spiral/roadrunner-http` ^4.1 + `nyholm/psr7` ^1.8) sudah menjadi dependensi
resmi. Biner `rr` **v2025.1.15** disertakan pada distribusi arsip di `bin/rr`.
Konfigurasinya ada di `.rr.yaml` (versi protokol `2025.1`, relay `pipes`,
`num_workers: 4`, `max_worker_memory: 512`).

```bash
bin/rr serve -c .rr.yaml         # worker: bin/worker.php, listen 0.0.0.0:8080
```

Bila biner belum ada (instalasi dari git, bukan arsip rilis):

```bash
# opsi 1 — unggahan resmi RoadRunner (versi persis yang dipakai proyek ini)
curl -sSL -o /tmp/rr.tar.gz \
  https://github.com/roadrunner-server/roadrunner/releases/download/v2025.1.15/roadrunner-2025.1.15-linux-amd64.tar.gz
tar xzf /tmp/rr.tar.gz -C /tmp
cp /tmp/roadrunner-2025.1.15-linux-amd64/rr bin/rr && chmod +x bin/rr

# opsi 2 — plugin Composer
composer require --dev spiral/roadrunner-cli
vendor/bin/rr get-binary
```

Verifikasi:

```bash
bin/rr --version
# rr version 2025.1.15 (build time: …, OS: linux, arch: amd64)
```

> **Penting:** RoadRunner adalah *persistent worker*, bukan PHP-FPM. Setiap worker
> melayani banyak request dalam satu proses. Lihat [`DEPLOYMENT.md`](DEPLOYMENT.md)
> untuk implikasi state lintas-request, kepemilikan sinyal, dan graceful shutdown.

## 5. Docker & Kubernetes

```bash
cd deploy
docker compose up --build          # lihat deploy/docker-compose.yml
```

- `deploy/Dockerfile` — *multi-stage build* (dependensi dipasang di stage builder,
  artefak disalin ke stage runtime).
- `deploy/k8s/deployment.yaml` + `deploy/k8s/service.yaml` — pola non-root,
  probe `livenessProbe: /health/live`, `readinessProbe: /health/ready`.

## 6. Variabel lingkungan

Seluruh variabel dibaca melalui pembungkus `Env` dan dilaporkan hanya sebagai nilai
konfigurasi — **tidak ada rahasia yang boleh ditulis ke berkas, log, atau laporan**.

| Variabel | Default | Dampak |
|----------|---------|--------|
| `ZEF_DEBUG` | `0` | `1` mengaktifkan diagnostik error + pesan detail |
| `ZEF_MAX_BODY_BYTES` | bawaan framework | batas ukuran body request; melebihi → `413` |
| `ZEF_TRUSTED_HOSTS` | `localhost,127.0.0.1,::1,zef.test` | daftar host tepercaya (CSV) |
| `ZEF_SECURITY_CSRF_SECRET` | — | secret CSRF ≥ 32 byte; **diisi = CSRF aktif** |
| `ZEF_SECURITY_DISTRIBUTED_RATE_LIMIT` | — | pemilih store rate-limit terdistribusi |
| `ZEF_WORKER_MAX_JOBS` | `0` (tanpa batas) | jumlah job per worker RoadRunner |
| `ZEF_WORKER_MEMORY_LIMIT` | `0` (nonaktif) | batas memori worker (byte) |
| `ZEF_OTEL_ENABLED` | `0` | `1` mengaktifkan tracer OTLP riil (bukan NoopSpan) |

Pemeriksaan keberadaan variabel (untuk skrip operasional) harus *masked* — hanya
melaporkan `PRESENT`/`ABSENT`, tidak pernah mencetak nilainya.

## 7. Verifikasi pasca-instalasi

```bash
php scripts/lint.php                                   # php -l seluruh berkas first-party
php bin/zef --self-test                                # 501 assertion
php bin/zef --self-test=v290                           # hanya suite autowiring
composer test                                          # PHPUnit native
composer stan                                          # PHPStan level max
composer deptrac                                       # konformansi arsitektur
```

Jika seluruh perintah di atas hijau, instalasi Anda setara dengan lingkungan yang
dipakai CI untuk memvalidasi repositori ini.

## 8. Masalah umum

| Gejala | Sebab | Tindakan |
|--------|-------|----------|
| `php: command not found` | PHP belum terpasang / di luar `PATH` | pasang PHP ≥ 8.4 (di Debian/Ubuntu: repo `packages.sury.org/php`) |
| Suite Redis self-skip senyap | server Redis 6399 tidak hidup | jalankan kontainer Redis pada §3.1 |
| `Class "Psr\...` tidak ditemukan | shim PSR tidak termuat | pastikan `autoload/zef_autoload.php` terdaftar (`autoload.files`) |
| Mutasi berhenti di "initial test suite" | ada byte STDERR (mis. `error_log`) | pertahankan `error_log=/dev/null` pada konfigurasi PHPUnit |
| `composer check-platform-reqs` gagal | ekstensi `ext-*` kurang | pasang ekstensi yang dilaporkan, atau jalankan jalur zero-composer |
