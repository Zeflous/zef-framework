# Deployment & Operasi — ZEF Framework

Dokumen ini membahas pola produksi. Prinsip utamanya satu: **RoadRunner adalah
*persistent worker*, bukan PHP-FPM.** Setiap worker melayani banyak request di dalam
satu proses PHP yang hidup lama, sehingga asumsi "proses mati di akhir request"
tidak berlaku.

---

## 1. Topologi

```
                 ┌───────────────────────────┐
   klien  ──────▶│  RoadRunner (rerun engine)│
                 │  0.0.0.0:8080             │
                 └───────────┬───────────────┘
                             │ relay: pipes
              ┌──────────────┴──────────────┐
              ▼              ▼              ▼
        worker #1      worker #2      worker #N     (php bin/worker.php)
              │              │              │
              └──────────────┴──────────────┘
                             │
                     state eksternal
              (Redis / APCu / OTLP collector)
```

Konfigurasi ada di `.rr.yaml`:

| Kunci | Nilai | Catatan |
|-------|-------|---------|
| `version` | `"2025.1"` | versi protokol, harus cocok dengan biner `rr` |
| `server.command` | `php bin/worker.php` | perintah worker |
| `server.relay` | `pipes` | jalur komunikasi |
| `http.address` | `0.0.0.0:8080` | alamat publik |
| `http.pool.num_workers` | `4` | kalibrasi sesuai CPU nyata |
| `http.pool.max_jobs` | `0` | `0` = tanpa batas; selaras `ZEF_WORKER_MAX_JOBS` |
| `http.pool.supervisor.max_worker_memory` | `512` (MB) | 0 = nonaktif |

## 2. Titik pembekuan (*freeze points*)

Aplikasi ZEF mem-boot sekali per worker lalu membekukan (`validateAndFreeze()`) graf
container. Setelah beku:

- Definisi service, alias, dan plan kompilasi tidak dapat diubah — percobaan
  registrasi melempar `LogicException`. Ini **disengaja**: menjamin tidak ada
  service yang berubah perilaku setelah request pertama.
- Konfigurasi, rute, dan tree namespace bersifat *sealed*.
- Warm singleton disiapkan tepat sekali; objek ber-*lifetime* `SINGLETON` hidup
  lintas request.

**Konsekuensi operasional:** apa pun yang perlu berbeda per request **tidak boleh**
disimpan sebagai singleton. Gunakan `RequestScope` (lifecycle terikat request) atau
state eksternal (Redis/APCu) untuk hal yang harus dibagi antar-worker.

## 3. State lintas-request (risiko utama)

| Pola | Aman? | Catatan |
|------|-------|---------|
| Singleton *stateless* (registry, compiler, policy) | ✅ | bentuk normal |
| Singleton menyimpan request terakhir | ❌ | bocor antar pengguna |
| Cache in-memory per worker | ⚠️ | hanya konsisten per-worker; butuh L2 bersama untuk konsistensi global |
| Rate limiter in-memory | ⚠️ | batas efektif × jumlah worker |
| `InMemoryLockStore` | ⚠️ | tidak memberi mutual exclusion lintas-worker |

Untuk pembatas laju, pilih store bersama: `RedisRateLimiter` /
`RedisSharedRateLimitStore` (skrip Lua, atomik) atau `ApcuRateLimiter` untuk
deployment satu instance. Data akun, sesi, dan variabel per-request harus
meninggalkan proses.

## 4. Kepemilikan sinyal & graceful shutdown

Worker tidak boleh memasang handler sinyal yang bertabrakan dengan supervisi
RoadRunner — proses induklah yang mengirim dan mengelola sinyal, lalu memberi worker
kesempatan menyelesaikan pekerjaan berjalan.

Aturan:

1. **Jangan** memasang handler `SIGTERM`/`SIGINT` sendiri pada worker.
2. Selesaikan request yang sedang berjalan sebelum keluar; jangan menutup koneksi
   ke store di tengah operasi tulis.
3. Untuk loop panjang (mis. pemrosesan job), periksa batas: `ZEF_WORKER_MAX_JOBS`
   dan `ZEF_WORKER_MEMORY_LIMIT` meminta worker mendaur ulang dirinya agar memori
   tidak tumbuh tanpa batas.
4. Hindari menutup `STDOUT`/`STDERR`; log dikirim melalui jalur tersebut.

## 5. Kesehatan & kesiapan

| Endpoint | Handler | Sifat |
|----------|---------|-------|
| `GET /health/live` | `LiveHandler` | *liveness* — proses hidup? jangan cek dependensi |
| `GET /health/ready` | `ReadyHandler` | *readiness* — siap menerima trafik? dependensi dicek |
| `GET /health` | `AggregateHealthHandler` | agregat indikator; **HTTP 503** saat *degraded* |
| `GET /metrics` | `MetricsHandler` | eksposisi Prometheus (`PrometheusRenderer`) |

Indikator kesehatan kustom diimplementasikan melalui `HealthIndicatorInterface`
(mis. `ContainerHealthIndicator`); agregasi dilakukan `HealthAggregator`.

**Kontrak probe K8s:** `livenessProbe` → `/health/live`, `readinessProbe` →
`/health/ready` (`deploy/k8s/deployment.yaml`). Jangan arahkan *liveness* ke
`/health` — ketergantungan eksternal yang sedang lambat akan memicu restart tanpa
guna, memperparah gangguan.

## 6. Observabilitas

- **Tracing OTLP** — aktif bila `ZEF_OTEL_ENABLED=1`; tanpa itu tracer bersifat
  *no-op* (`NoopSpan`), jadi tidak ada biaya saat nonaktif. Ekspor melalui
  `OtlpHttpJsonExporter` (JSON over HTTP) dan dibatch oleh `BatchSpanProcessor`.
- **Korelasi** — `CorrelationPropagator` + `TraceContextPropagator` menyebarkan
  konteks W3C (`traceparent`/`tracestate`) sehingga satu request dapat dilacak lintas
  worker.
- **Redaksi** — `TelemetrySanitizer` menyaring kunci sensitif (`authorization`,
  `api_key`, `token`, `cookie`, `secret`, …) sebelum data keluar. Ini **bukan**
  pengganti disiplin: jangan pernah menaruh rahasia di atribut span.
- **Log** — JSON terstruktur; `trace_id` ikut tercatat agar log dan trace dapat
  dikorelasikan.

## 7. Keamanan runtime

| Kontrol | Kelas | Cara mengaktifkan |
|---------|-------|-------------------|
| Header keamanan | `SecurityHeadersMiddleware` | middleware stack default |
| CORS | `CorsMiddleware` | whitelist origin eksplisit |
| CSRF | `CsrfManager` | set `ZEF_SECURITY_CSRF_SECRET` (≥ 32 byte) — kosong berarti nonaktif |
| Kebijakan asal | `OriginPolicy` | whitelist origin |
| Pembatas laju | `RateLimiter` + store | in-memory / APCu / Redis sesuai topologi |
| Perlindungan replay | `ReplayProtector` | jalur autentikasi |
| Batas body | `RequestBodyPolicy` | `ZEF_MAX_BODY_BYTES` → `413` |
| Host tepercaya | `TrustedHostValidator` | `ZEF_TRUSTED_HOSTS` (CSV) |
| Enkripsi | `AesGcmEncryptor` + `RotatingKeyRing` | rotasi kunci tanpa downtime |

Catatan produksi:

- Validasi ingress berlaku sebelum middleware/handler pada SAPI native maupun
  RoadRunner melalui `Application::handle()`. Jika allowlist host disetel, host
  kosong atau di luar daftar ditolak dengan `400`; body di atas batas ditolak
  dengan `413`, termasuk tanpa `Content-Length` atau dengan nilai yang terlalu
  kecil. Body dibaca secara terbatas sebelum dispatch; posisi stream seekable
  dipulihkan, sedangkan stream non-seekable diganti buffer agar tetap dapat
  dibaca aplikasi. Pertimbangkan biaya buffering saat menaikkan batas body.
- **CSRF nonaktif secara default.** Mengaktifkannya adalah keputusan eksplisit:
  set secret ≥ 32 byte, dan pastikan tidak ada komponen lain yang menulis nilai itu.
- **`ZEF_TRUSTED_HOSTS` wajib disetel** untuk deployment nyata; nilai default hanya
  mencakup localhost.
- **Rate limiter in-memory tidak konsisten lintas-worker** — lihat §3.
- Rahasia hanya masuk lewat lingkungan (`EnvironmentSecretProvider`), tidak pernah
  ke berkas konfigurasi yang dikomit.

## 8. Docker

`deploy/Dockerfile` memakai *multi-stage build*: dependensi dipasang pada stage
*builder*, lalu hanya artefak yang dibutuhkan disalin ke stage *runtime* — image
akhir tidak membawa Composer maupun cache paket.

```bash
cd deploy
docker compose up --build
```

Perhatian saat menyusun image produksi:

- Jalankan sebagai pengguna **non-root**; k8s manifest sudah memakai pola ini.
- Salin `.rr.yaml` dan biner `rr` ke image; pastikan `bin/worker.php` dapat dieksekusi.
- Jangan sertakan `vendor/bin/*` yang bersifat dev (phpunit, infection, phpstan)
  pada image runtime bila tidak dipakai.
- Pasang hanya ekstensi yang dibutuhkan: `mbstring`, `dom`, `xml`, `xmlwriter`,
  plus `redis`/`apcu` bila fitur terkait dipakai.

## 9. Kubernetes

`deploy/k8s/deployment.yaml` + `deploy/k8s/service.yaml` menyediakan pola dasar:

- container non-root, resource request/limit eksplisit;
- probe memakai endpoint kesehatan ZEF (§5);
- jumlah replika ditentukan horizontal — **perhatikan**: menambah replika menambah
  jumlah worker, sehingga rate limiter in-memory kehilangan makna. Pindahkan batas
  ke store bersama sebelum melakukan *scale-out*.

## 10. Runbook singkat

| Kejadian | Tindakan |
|----------|----------|
| `/health` 503 | baca indikator yang gagal pada respons agregat; cek dependensi (Redis/collector) sebelum me-restart |
| worker memori tumbuh | turunkan `ZEF_WORKER_MAX_JOBS`/`ZEF_WORKER_MEMORY_LIMIT` sehingga worker mendaur ulang lebih cepat |
| rate limit tembus | pastikan store bersama, bukan in-memory; cek jumlah worker × jumlah replika |
| trace hilang | verifikasi `ZEF_OTEL_ENABLED=1` dan keterjangkauan collector dari dalam pod |
| 413 beruntun | naikkan `ZEF_MAX_BODY_BYTES` **atau** perbaiki klien — jangan naikkan tanpa batas |
| deploy tidak zero-downtime | periksa urutan: readiness harus gagal lebih dulu sebelum proses lama dihentikan |
