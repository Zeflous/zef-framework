# ZEF Framework — INTEGRATIONS

Panduan integrasi ekosistem: **port domain → adapter first-party**, kriteria
menulis adapter pihak ketiga, dan matriks kompatibilitas. Dokumen ini adalah
peta resmi "ekosistem ports" (v2.30.0); untuk kontrak plugin aplikasi lihat
[`PLUGINS.md`](PLUGINS.md), untuk arsitektur layer lihat
[`ARCHITECTURE.md`](ARCHITECTURE.md).

> **Prinsip kebijakan dependensi** (berlaku tanpa pengecualian): `composer.json`
> framework hanya memiliki **3 paket runtime** (`spiral/roadrunner-http`,
> `nyholm/psr7`, `php`). Adapter ekosistem TIDAK PERNAH menambah paket runtime
> — integrasi eksternal dimasukkan lewat `composer suggest`, ekstensi opsional,
> dan tes dengan *skip-guard* (pola `ext-redis`/`RedisStoreTest`).

---

## 1. Peta port × adapter (first-party)

| Port (Domain) | Adapter first-party | Sejak | Catatan |
|---|---|---|---|
| `Message\MessageTransportInterface` | `Infrastructure\Message\InMemoryMessageTransport` | v2.30.0 | FIFO in-process terbatas kapasitas; dev/test; `receive()`/`drain()` untuk konsumsi |
| `Message\MessageSerializerInterface` | `Application\Message\JsonMessageSerializer` | v2.8.0 | JSON envelope round-trip |
| `Job\JobQueueInterface` | `Application\Job\InMemoryJobQueue` | v2.8.0 | In-process, `SplPriorityQueue` |
| `Job\JobQueueInterface` | `Infrastructure\Job\PdoJobQueue` | v2.30.0 | **Durable**: SQLite/MySQL/PostgreSQL via `ConnectionInterface`; claim = SELECT+DELETE transaksional |
| `Job\JobIdempotencyStoreInterface` | `Application\Job\LockingJobIdempotencyStore` | v2.27.0 | In-process, berbasis lock |
| `Job\JobIdempotencyStoreInterface` | `Infrastructure\Job\PdoJobIdempotencyStore` | v2.30.0 | **Durable**: efek exactly-once dengan eksekusi at-least-once |
| `Storage\ObjectStorageInterface` | `Infrastructure\Storage\LocalStorage` | v2.30.0 | Filesystem lokal; tulis atomik (tmp+rename); bebas traversal |
| `Storage\ObjectStorageInterface` | `Infrastructure\Storage\S3CompatibleStorage` | v2.30.0 | **SigV4 in-house** tanpa SDK; AWS S3, MinIO, Ceph RGW, Cloudflare R2, GCS (XML API) |
| `Database\ConnectionInterface` | `Infrastructure\Database\PdoConnection` | v2.18.0 | Basis semua adapter PDO |
| `EventSourcing\EventStoreInterface` | `Infrastructure\EventSourcing\PdoEventStore` | v2.19.0 | — |
| `Cache`/`Security` lock & rate-limit | `RedisLockStore`, `RedisSharedRateLimitStore`, `ApcuRateLimiter`, `InMemory*` | v2.24.0+ | Pola suggest + skip-guard |
| `Runtime\Async` fiber scheduler | primitif konkurensi kooperatif | v2.26.0 | channel, semaphore, wait group, cancellation |
| `Rules\AsyncRuleEngineInterface` | `Application\Rules\AsyncRuleEngine` | v2.27.0 | Evaluasi rule konkuren + deadline |

## 2. Object storage — cara pakai

Port: `Zef\Framework\Storage\ObjectStorageInterface` — enam operasi primitif
(`put`/`get`/`delete`/`exists`/`stat`/`list`). Kunci divalidasi satu pintu
oleh `StorageKeys::assertValidKey()` (1..1024 byte, tanpa traversal,
tanpa backslash, tanpa slash di awal/akhir), sehingga path-safety dijamin
konsisten di SEMUA adapter.

### 2.1 LocalStorage (dev, single-node)

```php
use Zef\Framework\Storage\LocalStorage;

$storage = new LocalStorage('/var/lib/myapp/objects'); // root dibuat otomatis
$storage->put('invoices/2026/INV-001.pdf', $binary);
$contents = $storage->get('invoices/2026/INV-001.pdf');   // ObjectNotFoundException bila absen
foreach ($storage->list('invoices/2026/', 100) as $key) { /* terurut leksikografis */ }
```

Tulisan bersifat atomik (berkas sementara unik + `rename(2)`) — pembaca tidak
pernah melihat objek setengah jadi. Ukuran maksimum objek dikontrol lewat
argumen konstruktor kedua (default 64 MiB).

Symlink pada setiap komponen key (direktori perantara maupun berkas akhir)
ditolak dengan `StorageException`, termasuk symlink yang targetnya masih di
dalam root atau sudah hilang. `list()` mengabaikan symlink dan tidak menelusuri
direktori symlink. Root yang dikonfigurasi tetap di-canonicalize saat konstruksi.
Root, direktori induknya, dan isinya harus dikelola pihak tepercaya: pemeriksaan
path ini tidak menjamin perlindungan terhadap perubahan filesystem konkuren
di antara validasi dan operasi I/O.

### 2.2 S3CompatibleStorage (produksi, kompatibel S3)

```php
use Zef\Framework\Storage\S3CompatibleStorage;
use Zef\Framework\Storage\CurlS3HttpTransport;

// AWS S3 (endpoint default mengikuti region)
$s3 = new S3CompatibleStorage('mybucket', 'us-east-1', $keyId, $secret, new CurlS3HttpTransport());

// MinIO / Ceph / R2 / GCS-interoperability: lewat endpoint eksplisit
$minio = new S3CompatibleStorage('mybucket', 'us-east-1', $keyId, $secret,
    new CurlS3HttpTransport(), 'http://127.0.0.1:9000');
$gcs   = new S3CompatibleStorage('mybucket', 'auto', $hmacKey, $hmacSecret,
    new CurlS3HttpTransport(), 'https://storage.googleapis.com');
```

- **Tanda tangan**: AWS SigV4 diimplementasikan in-house
  (`Infrastructure\Storage\SigV4`) — murni, deterministik, teruji terhadap
  vektor resmi AWS. **Tidak ada SDK, tidak ada paket baru.**
- **Kabel HTTP**: `CurlS3HttpTransport` (butuh `ext-curl`, dinyatakan lewat
  `composer suggest`). Untuk pengujian tanpa jaringan, injeksikan transport
  palsu yang mengimplementasikan `S3HttpTransport`.
- **Respon list**: XML ListObjectsV2 di-parsing dengan `ext-simplexml`
  (namespace-aware: AWS tanpa xmlns, MinIO/Ceph dengan xmlns).
- **GCS**: Google Cloud Storage menyediakan API XML yang kompatibel S3
  dengan kredensial HMAC (`gsutil hmac create`) — tanpa kode tambahan.

## 3. Message transport — cara pakai

Port: `Message\MessageTransportInterface::send(envelope, context): MessageResult`.

```php
use Zef\Framework\Message\InMemoryMessageTransport;
use Zef\Framework\Message\MessageEnvelope;
use Zef\Framework\Message\MessageContext;

$transport = new InMemoryMessageTransport(maxSize: 10_000);
$result = $transport->send(
    new MessageEnvelope('msg-00000001', 'order.placed', ['id' => 'A-1']),
    new MessageContext(correlationId: 'corr-00000001'),
);
$result->transportId; // "mem-000001" — diterima tanpa menunggu konsumen
```

Kelas yang sama melayani dua peran: **bus uji** (verifikasi apa yang
dipublikasikan lewat `receive()`/`drain()`) dan **dev transport** (smoke test,
plugin harness). Transport broker sungguhan mengimplementasikan port yang sama
dan dipasang di *composition root* tanpa mengubah kode domain.

**Membuat transport broker (panduan kontributor)** — ikuti pola Redis adapter:

1. Adapter di `src/Infrastructure/Message/`, `final readonly class`, mengimplement
   `MessageTransportInterface`; `#[\Override]` wajib.
2. Dependensi klien (paket/ekstensi) TIDAK masuk `require` — cukup `suggest`
   + pemeriksaan `extension_loaded()`/`class_exists()` dengan pesan jelas.
3. Semua kegagalan backend dipetakan ke hierarki exception domain — jangan
   bocorkan tipe driver.
4. Tes deterministik dengan fake transport/handler + skip-guard untuk jalur
   ekstensi nyata (pola `RedisLockStoreTest`).
5. Daftarkan kelas di classmap (`php scripts/dev/update_classmap.php --apply`
   lalu bersihkan entri palsu hasil pemindaian heredoc bila ada).
6. Tambah baris ke tabel di atas + matriks kompatibilitas di bawah.

## 4. Job queue & idempotency — cara pakai

```php
use Zef\Framework\Job\PdoJobQueue;
use Zef\Framework\Job\PdoJobIdempotencyStore;
use Zef\Framework\Job\JobEnvelope;

$queue = new PdoJobQueue($connection, 'zef_job_queue');
$queue->createSchema();
$queue->enqueue(new JobEnvelope(
    'job-00000001', 'mail.send', ['to' => 'user@example.com'],
    availableAtUnixNano: $now, priority: 5,
));
$job = $queue->dequeue();          // null bila belum ada yang jatuh tempo
```

- **Urutan**: `priority DESC → available_at ASC → seq ASC`; `seq` dihitung
  `MAX(seq)+1` di dalam transaksi enqueue (portabel lintas SQLite/MySQL/PG),
  dengan `UNIQUE(job_id)` sebagai pengaman balapan.
- **Klaim**: SELECT kandidat + DELETE `job_id` dalam satu transaksi — bila
  pekerja lain menang balapan (affected rows 0), pemindaian lanjut ke
  kandidat berikutnya (dibatasi 8 iterasi). `SELECT ... FOR UPDATE` sengaja
  dihindari: SQLite menolaknya.
- **Payload**: dokumen JSON (skalar/list/peta); objek PHP harus diserialisasi
  pemanggil — kontrak yang sama dengan outbox.
- **Idempotency**: `PdoJobIdempotencyStore::remember($key, $producer, $ttl)`
  menjalankan producer sekali per key hidup; balapan pertama dimenangkan
  lewat `UNIQUE(idem_key)` dan kalah mengadopsi nilai pemenang — efek
  exactly-once dengan eksekusi at-least-once (producer gagal tidak pernah
  di-cache).

## 5. Cycle ORM & integrasi tingkat aplikasi

Roadmap menyebut **Cycle ORM**; kebijakan dependensi membuatnya menjadi
integrasi tingkat aplikasi, bukan dependensi framework:

1. `composer require cycle/orm cycle/database` **di aplikasi Anda** (bukan di
   framework).
2. Jembatan: bungkus koneksi Cycle di implementasi
   `Database\ConnectionInterface` (lima metode: `execute`, `fetchAll`,
   `fetchOne`, `lastInsertId`, transaksi dengan savepoint) — seluruh adapter
   PDO framework (event store, outbox, snapshot, job queue, idempotency)
   otomatis bekerja di atasnya.
3. Sebaliknya, `PdoConnection` dapat dipakai sebagai sumber koneksi Cycle
   custom driver bila aplikasi memakai framework sebagai basis.

Pola yang sama berlaku untuk Doctrine DBAL atau PDO wrapper lain: port
`ConnectionInterface` adalah satu-satunya titik sambung.

## 6. Matriks kompatibilitas adapter (rilis v2.30.0)

| Adapter | Backend teruji | Dependensi opsional | Versi framework minimum | Status |
|---|---|---|---|---|
| `LocalStorage` | POSIX filesystem (Linux CI, tmpdir) | — | 2.30.0 | first-party, didukung penuh |
| `S3CompatibleStorage` + `CurlS3HttpTransport` | AWS S3 (path-style), MinIO, Ceph RGW, Cloudflare R2, GCS XML-API | `ext-curl`, `ext-simplexml` | 2.30.0 | first-party; vektor SigV4 resmi AWS terpasang di test-suite |
| `InMemoryMessageTransport` | — | — | 2.30.0 | first-party |
| `PdoJobQueue` / `PdoJobIdempotencyStore` | SQLite (CI), MySQL/PostgreSQL (portabilitas DDL) | ekstensi PDO driver | 2.30.0 | first-party |
| `RedisLockStore`, `RedisSharedRateLimitStore` | Redis 7.4 | `ext-redis` | 2.24.0 | first-party |
| Transport broker (Redis Streams/NATS/SQS/Kafka) | — | klien eksternal di aplikasi | — | **terbuka** — panduan di §3 |

Baris matriks baru wajib: (1) nama adapter + backend yang benar-benar
diuji, (2) dependensi opsional yang dinyatakan lewat `suggest`, (3) versi
framework minimum, (4) status (first-party / komunitas). PR yang menambah
adapter tanpa memperbarui dokumen ini dan `PLUGINS.md` (bila berbentuk
plugin) tidak akan lolos review.
