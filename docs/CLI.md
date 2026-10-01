# Referensi CLI — `bin/zef`

`bin/zef` adalah *composition root* CLI framework: ia mem-boot aplikasi demo hanya
ketika sebuah perintah benar-benar membutuhkan container (inspector), dan tetap
berjalan tanpa Composer.

```bash
php bin/zef                       # banner + versi
php bin/zef list                  # katalog command (+ --json)
php bin/zef --self-test           # suite diagnostik internal
php bin/zef --serve [addr]        # server pengembangan
```

---

## 1. Diagnostik & server

### `bin/zef --self-test [filter]`

Menjalankan suite diagnostik tanpa PHPUnit (`Zef\Test\CliRunner`). Filter bersifat
*case-insensitive* dan dicocokkan sebagai substring terhadap key maupun label suite.

```bash
php bin/zef --self-test          # seluruh suite: 501 assertion
php bin/zef --self-test=v280     # suite fitur v2.8.0
php bin/zef --self-test=v290     # suite autowiring v2.9.0
php bin/zef --self-test=v210     # suite enterprise v2.10.0
php bin/zef --self-test=v211     # suite radix-tree v2.11.0
php bin/zef --self-test=router   # apa pun yang key/label-nya mengandung "router"
```

Filter yang tidak cocok apa pun keluar dengan status **1** dan mencetak daftar key
yang tersedia — ini disengaja agar salah ketik tidak pernah tampak seperti "hijau".

### `bin/zef --serve [host:port]`

Menjalankan server pengembangan bawaan (`php -S`) dengan `public/index.php` sebagai
entrypoint. Alamat divalidasi terhadap pola `host:port`; alamat tidak valid ditolak
dengan status 1.

```bash
php bin/zef --serve                # default 0.0.0.0:8080
php bin/zef --serve 127.0.0.1:9000
```

---

## 2. Inspector (mem-boot aplikasi)

Ketiga perintah berikut memanggil `Bootstrap::createApp()` + `boot()` sehingga yang
dilaporkan adalah **keadaan runtime nyata**, bukan hasil parsing statis.

| Perintah | Keluaran |
|----------|----------|
| `bin/zef route:list` | tabel rute: METHOD, PATH, NAME, HANDLER, MODULE, PRIO + jumlah |
| `bin/zef module:list` | modul terdaftar dari `ModuleRegistry` pasca-boot |
| `bin/zef plugin:list` | plugin yang ditemukan di `plugins/` (sumber kebenaran: disk) |
| `bin/zef config:show [key] [--reveal]` | dump config teragregasi; lookup *dotted key* opsional; nilai ber-key sensitif otomatis di-mask |

`config:show` bersifat **JSON-safe**: `Closure` → `"<closure>"`, objek →
`"<object NamaKelas>"`, resource → `"<resource>"`. Lookup dotted key memakai sentinel
yang membedakan *key tidak ada* (**exit 1**) dari *nilai `null` tersimpan*
(**exit 0**) — perbedaan ini penting untuk skrip.

**Redaksi rahasia:** nilai di bawah key ber-nama sensitif (`password`, `secret`,
`token`, `credential`, `api-key`, ...) dirender `"****(<panjang>)"` secara default —
rekaman terminal, log CI, atau paste ke issue tidak akan membocorkan kredensial.
Flag `--reveal` mencetak nilai asli untuk debugging sadar-risiko, namun **menolak
jalan saat `ZEF_ENV=production`** (exit 1).

```bash
php bin/zef route:list
php bin/zef config:show middleware.services
php bin/zef config:show tidak.ada.key    # exit 1
php bin/zef config:show db.password     # "****(18)" — panjang asli sebagai petunjuk
php bin/zef config:show db.password --reveal  # nilai asli (ditolak di production)
```

---

## 3. Generator — `bin/zef make:*`

11 generator menghasilkan artefak kerja framework. Seluruhnya melewati
`ZefMaker` → `NamingRules` → `ScaffoldWriter`, sehingga aturan berikut berlaku
seragam:

- **Nama divalidasi**, termasuk penolakan *reserved word* PHP secara
  case-insensitive (`make:entity List` ditolak, bukan menghasilkan kode rusak).
- **Penulisan transaksional**: seluruh target dicek tabrakan terlebih dahulu, baru
  ditulis; satu tabrakan membatalkan seluruh batch tanpa berkas parsial.
- **Exit 1 + pesan stderr** untuk nama invalid, tabrakan, atau command tak dikenal.

| Command | Menghasilkan |
|---------|--------------|
| `make:app <path> [--name=<project>] [--address=host:port]` | **v2.29.0** — scaffold aplikasi standalone di `<path>` (di luar root framework): composer.json (path-repo) + `app/Bootstrap.php` + modul pertama + `.rr.yaml` + entrypoint web/worker + wrapper `bin/zef`. Panduan lengkap: [`TUTORIAL-CQRS-101.md`](TUTORIAL-CQRS-101.md) |
| `make:module <name>` | `modules/<Pascal>/` — `ConfigProvider` + `HomeHandler` |
| `make:plugin <Name>` | `plugins/<Name>/` — `ConfigProvider` + `Service` + `Handler` |
| `make:handler <Name> [--module=] [--path=/uri]` | handler PSR-15 di dalam modul |
| `make:middleware <Name>` | middleware PSR-15 di `src/Middleware/` (target PSR-4 `Zef\Middleware\`) |
| `make:config <Name> [--module=]` | `ConfigProvider` modul — mekanisme config ZEF (bukan berkas lepas) |
| `make:command <Name> [--module=]` | pasangan CQRS `Command` + `CommandHandlerInterface` |
| `make:query <Name> [--module=]` | pasangan CQRS `Query` + `QueryHandlerInterface` |
| `make:entity <Name> [--module=]` | entitas Domain dengan identitas + `equals()` |
| `make:valueobject <Name> [--module=]` | `final readonly class` + validasi constructor |
| `make:service <Name> [--module=]` | service aplikasi + snippet wiring |

Contoh alur kerja:

```bash
php bin/zef make:module katalog
php bin/zef make:command PlaceOrder --module=katalog
php bin/zef make:valueobject Uang --module=katalog
composer dump-autoload            # atau perbarui classmap statis tanpa Composer
php bin/zef --self-test
```

> Setelah menambah kelas, autoloader harus diperbarui. Pada jalur zero-composer
> classmap statis `autoload/zef_autoload.php` perlu entri baru; dengan Composer
> cukup `composer dump-autoload`.

---

## 4. RoadRunner & kesehatan lingkungan — v2.29.0

### `bin/zef rr:init`

Menghasilkan `.rr.yaml` (RoadRunner v2025.1) dari precedensi
**flag CLI > env knob > default** — developer tidak menyunting konfigurasi
pool secara manual:

```bash
php bin/zef rr:init                                   # default 0.0.0.0:8080, 4 worker
php bin/zef rr:init --address=127.0.0.1:9000 --workers=8
ZEF_HTTP_ADDRESS=0.0.0.0:8080 php bin/zef rr:init     # env juga didukung
php bin/zef rr:init --force                           # regenerasi (timpa eksplisit)
```

| Knob | Flag | Env | Default |
|------|------|-----|---------|
| Address | `--address=` (IPv4/IPv6 `host:port`) | `ZEF_HTTP_ADDRESS` | `0.0.0.0:8080` |
| Workers | `--workers=` (1–1024) | `ZEF_RR_NUM_WORKERS` | `4` |
| Max jobs | `--max-jobs=` (0 = unbounded) | `ZEF_WORKER_MAX_JOBS` | `0` |
| Memori/worker | `--memory=` MB (0 = off) | `ZEF_WORKER_MEMORY_LIMIT` | `512` |

**Collision-safe**: tanpa `--force`, `.rr.yaml` eksisting tidak pernah ditimpa
(exit 1). Validasi gagal (address busuk, workers di luar rentang) → exit 1
tanpa menulis berkas apa pun.

### `bin/zef doctor`

Preflight lingkungan read-only — PHP/ekstensi, autoloader, entrypoint,
bridge & binary RoadRunner, validitas `.rr.yaml`, dan **boot smoke**
(aplikasi benar-benar di-boot lewat probe yang di-inject composition root):

```text
$ php bin/zef doctor
ZEF doctor — environment preflight
  [OK] PHP                      8.4.24
  [OK] ext-mbstring             loaded
  ...
  [OK] app boot                 Application booted (ZEF v2.29.0)
11 ok, 1 warn, 0 fail
```

**Kontrak exit code**: `0` tanpa FAIL, `1` bila minimal satu FAIL — WARN
(`pcov`/`redis` absen, binary `rr` tak ditemukan, dst.) tidak mengubah exit
code sehingga aman dipakai di awal script CI.

---

## 5. REPL — `bin/zef tinker`

REPL stateful dengan `$app` dan `$container` siap pakai. Mendukung eksekusi sekali
jalan, melewati boot, dan *override* produksi.

```bash
php bin/zef tinker                       # sesi interaktif
php bin/zef tinker -e '$app->getRouter()'
php bin/zef tinker -e='$container->get("cache")' --no-boot
php bin/zef tinker --force               # izinkan saat ZEF_ENV=production
```

| Opsi | Efek |
|------|------|
| `-e <expr>` / `-e=<expr>` | eksekusi satu ekspresi lalu keluar |
| `--no-boot` | jangan boot aplikasi (hanya container berdiri sendiri) |
| `--force` | mengizinkan REPL saat `ZEF_ENV=production` |

**Pengaman produksi:** saat `ZEF_ENV=production`, tinker **menolak** berjalan karena
REPL mengeksekusi kode arbitrer; `--force` adalah *override* eksplisit.

---

## 6. Dokumentasi API — `bin/zef openapi:generate` (v2.20.0)

Merakit dokumen **OpenAPI 3.1** dari route table + atribut PHP 8.4 — tanpa dependensi
eksternal (serializer JSON/YAML in-house) — lalu menulisnya ke `openapi.json` /
`openapi.yaml`. Spesifikasi yang sama disajikan `SpecHandler` dengan ETag/304 dan
Swagger UI dev-only di mode pengembangan.

```bash
php bin/zef openapi:generate                        # openapi.json (default)
php bin/zef openapi:generate --format=yaml          # openapi.yaml
php bin/zef openapi:generate --output=api/spec.json --pretty
php bin/zef openapi:generate --postman=postman.json # export Postman v2.1
php bin/zef openapi:generate --base-url=https://api.example.test
```

| Opsi | Efek |
|------|------|
| `--format=json` / `--format=yaml` | format keluaran (default `json`) |
| `--output=<path>` | path berkas hasil (default `openapi.<format>`) |
| `--pretty` / `--no-pretty` | pretty-print JSON/YAML |
| `--postman=<path>` | export tambahan Postman v2.1 dari spesifikasi |
| `--base-url=<url>` | tambahkan entri `server` ke dokumen |

Validasi **runtime** berjalan terpisah lewat `OpenApiGateMiddleware` (v2.33.0) —
matriks paritas 12 batas: [`OPENAPI-GATE-PARITY.md`](OPENAPI-GATE-PARITY.md).

---

## 7. Antrean kerja — `bin/zef queue:*` (v2.32.0)

Empat perintah operasional di atas driver antrean yang di-wire di container
(`InMemoryJobQueue`, `PdoJobQueue`, atau `RedisStreamJobQueue` — semuanya
mengimplementasikan port `JobQueueInterface`, jadi CLI-nya driver-agnostic).

### `bin/zef queue:work`

Worker daemon: memproses job dari antrean lewat `InProcessJobWorker` yang
terdaftar di container. Loop memakai stop-signal antar-job, sehingga sinyal
atau ambang memori **tidak pernah memotong job yang sedang berjalan** (port
`dequeue()` bersifat destruktif — job in-flight dibiarkan selesai, jalur
retry/DLQ tetap berlaku saat gagal).

```bash
php bin/zef queue:work                    # daemon: jalan sampai dihentikan
php bin/zef queue:work --once             # batch: drain lalu keluar saat kosong
php bin/zef queue:work --max=100          # berhenti setelah 100 job
php bin/zef queue:work --memory=128       # guard kebocoran memori (MiB)
```

| Opsi | Efek |
|------|------|
| `--once` | mode batch — keluar saat antrean kosong |
| `--max=<n>` | batas jumlah job yang diproses |
| `--memory=<mb>` | berhenti bila `memory_get_usage(true)` melewati anggaran MiB |

**Guard memori keluar dengan kode `2`** — supervisor (systemd
`Restart=on-failure`, RoadRunner, K8s) mengganti proses yang bocor alih-alih
menampungnya. Flag telanjang (`--memory` tanpa nilai) berarti tanpa guard
(semantika bare-flag `outbox:work`, bukan cast `true` → 1 MiB).

### `bin/zef queue:failed`

Inspeksi read-only DLQ: mendaftar job yang dead-letter ke antrean gagal yang
di-wire (biasanya stream Redis khusus `zef.queue.failed`).

```bash
php bin/zef queue:failed                  # 50 entri teratas
php bin/zef queue:failed --max=200
```

### `bin/zef queue:retry`

Memindahkan job dead-letter kembali ke antrean utama — **envelope apa adanya
(attempt dipertahankan)**: retry adalah keputusan re-delivery, bukan reset
 percobaan. Job dengan attempt yang sudah menguras `RetryPolicy` akan
dead-letter lagi bila tetap gagal. Id yang sudah hidup di antrean utama
**di-skip** (bukan crash) — backstop duplikat PDO `UNIQUE(job_id)` / SET id
live Redis yang bekerja, bukan bug.

```bash
php bin/zef queue:retry                   # hingga 50 job
php bin/zef queue:retry --all             # semua entri
php bin/zef queue:retry --max=5
```

### `bin/zef queue:flush`

Menguras job gagal dari DLQ — padanan port-agnostic dari `TRUNCATE`:
drain `dequeue()` berulang, jadi bekerja untuk stream Redis, tabel PDO, dan
`InMemoryJobQueue` dengan satu jalur kode.

```bash
php bin/zef queue:flush                   # kuras semua
php bin/zef queue:flush --max=100         # batch terbatas
```

**Wiring yang diharapkan** (dari ConfigProvider aplikasi):

```php
$services[JobQueueInterface::class]     = fn () => new RedisStreamJobQueue($c->get(\Redis::class), 'default');
$services['zef.queue.failed']           = fn () => new RedisStreamJobQueue($c->get(\Redis::class), 'failed');
$services[InProcessJobWorker::class]    = fn () => new InProcessJobWorker(
    $c->get(JobQueueInterface::class),
    new RetryPolicy(3, 100, 30_000),
    null,
    $c->get('zef.queue.failed'),
);
```

---

## 8. Relay outbox — `bin/zef outbox:work` (v2.31.0)

Worker relay untuk **transactional outbox** (pola dikirim v2.19.0 bersama Event
Sourcing): memindahkan entri outbox ke event bus via **lease claiming** — aman
dijalankan N worker sekaligus karena setiap batch diklaim eksklusif dalam window
lease. Membutuhkan `OutboxRelay` yang di-wire di container.

```bash
php bin/zef outbox:work                   # daemon: jalan sampai dihentikan
php bin/zef outbox:work --once            # satu batch lalu keluar
php bin/zef outbox:work --batch=100 --lease=30
php bin/zef outbox:work --interval=500 --max=10
```

| Opsi | Efek |
|------|------|
| `--once` | mode batch — drain satu batch lalu keluar |
| `--batch=<n>` | ukuran klaim per batch (default `100`) |
| `--lease=<sec>` | window lease detik (default `30`) — worker lain tak boleh mengambil entri yang sama |
| `--interval=<ms>` | jeda polling saat idle (default `500`) |
| `--max=<n>` | berhenti setelah `n` batch |

---

## 9. Keluar-kode

| Kode | Arti |
|------|------|
| `0` | sukses |
| `1` | command tak dikenal, nama/filter invalid, tabrakan scaffold, key config tidak ada, atau suite self-test tidak menemukan kecocokan |
| `2` | `queue:work --memory` terpicu — restart proses disarankan |

Kontrak ini membuat `bin/zef` aman dipakai di pipeline CI: kegagalan tidak pernah
dilaporkan sebagai sukses.
