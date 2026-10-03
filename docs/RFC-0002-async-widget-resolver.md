# RFC-0002 — Async Widget Resolver: Komposisi HMVC Konkuren di atas Async Runtime Native

> **Status:** DRAFT — terbuka untuk umpan balik · **Pemilik keputusan:** @mbetixz · **Disusun:** 2026-10-03
> **Bergantung pada:** [RFC-0001](RFC-0001-module-layout.md) §D2 — widget sebagai primitive HMVC,
> `ModuleDispatcher`, aturan query-only & fail-soft (draf sudah live di `main`).
> **Mesin yang dipakai — sudah berjalan, TIDAK diimplement ulang:** Async Runtime v2.26.0
> (`FiberScheduler`, `Semaphore`, `CoroutineLocal`, `CancellationTokenSource`), Async Rules v2.27.0
> (`AsyncRuleEngine` sebagai preseden struktural), bus CQRS + `CqrsMiddlewareInterface`,
> `FailFastInitializationGuard`, `MeterInterface`.

---

## 1. Ringkasan

RFC ini mendefinisikan **Async Widget Resolver**: komponen kernel yang menyelesaikan **banyak widget
sub-request (RFC-0001 §D2) secara konkuren** di atas `FiberScheduler` native ZEF, tanpa mengubah jalur
komposisi sekuensial yang sudah didefinisikan RFC-0001. Satu panggilan resolver menerima manifest widget,
menjalankan setiap widget sebagai korutin tersendiri di bawah cap konkurensi dan deadline kooperatif per
widget, lalu mengembalikan **hasil total**: satu outcome per widget, **urutan manifest**, kegagalan dipetakan
menjadi placeholder fail-soft — bukan exception yang membatalkan seluruh halaman. Struktur internalnya
menyalin pola yang sudah terbukti di `AsyncRuleEngine` (body / inner / guard) sehingga perilakunya
deterministik dan dapat diuji tanpa real-time wait.

Sembilan keputusan inti:

| # | Keputusan | Inti |
|:--|:----------|:-----|
| **W1** | Kontrak dua-tingkat, cermin `AsyncRuleEngineInterface` | `resolve()` (wajib dalam korutin — `LogicException` di luar) + `run()` (driver blocking top-level); hasil selalu total: `WidgetComposition`, tidak pernah melempar untuk kegagalan level widget |
| **W2** | DTO bertipe kuat dua arah | `WidgetTask` (input; `fromArray()` menerima manifest array ala draf komunitas) → `ResolvedWidget` (output: status, HTML/placeholder, durasi ms, kelas kegagalan) |
| **W3** | Struktur body / inner / guard per widget | body total yang mengonversi semua outcome menjadi tepat satu verdict; guard timer membatalkan inner saat deadline menang — persis pemetaan `TaskCancelledException` milik `AsyncRuleEngine` |
| **W4** | Deadline kooperatif per widget | `timeoutMs` per task, default dari opsi; tanpa titik suspend tidak ada interupsi — keterbatasan ini didokumentasikan terbuka, bukan disembunyikan |
| **W5** | Cap konkurensi via `Semaphore` | default = satu permit per widget (pola rumah `AsyncRuleEngine`); widget kelebihan park di antrian permit — bukan spawn liar |
| **W6** | Guard query-only ditegakkan runtime | scope fiber-lokal (`CoroutineLocal`) dipasang resolver + middleware `CommandBus` melempar `QueryOnlyViolationException` — aturan D2 naik kelas dari konvensi review menjadi kontrak mesin |
| **W7** | Pre-resolve dependensi request-scoped | controller widget + dependensinya dimaterialisasi di fiber induk SEBELUM fan-out; pelanggaran lazy-init konkuren → `ConcurrentServiceInitializationException` → outcome Failed (fail-soft, halaman tetap hidup) |
| **W8** | Telemetri paritas | `zef.widgets.failed` + atribut `reason`, `zef.widgets.duration` — konkretisasi shorthand `widgets.failed{module}` dari D2 ke konvensi metric `zef.*` yang sudah berjalan |
| **W9** | Kejujuran wall-clock | overlap nyata hanya terjadi saat render men-suspend (await/timer/adapter async); I/O blocking murni = wall time sekuensial — resolver tetap memberi deadline, cap, ordering, fail-soft |

Dokumen ini sengaja berhenti di batas kontrak + matriks perilaku; core logic implementasi tetap milik PR yang
membawa kode (deal pembagian kerja: penulis core logic menyediakan mesin, RFC ini mengunci kontrak dan
paritas yang harus dipertahankan).

---

## 2. Motivasi

### 2.1 Latensi komposisi sekuensial

Halaman era v3 adalah komposisi widget: dashboard induk menaruh mini-cart dari modul `Toko`, daftar produk
unggulan dari `Catalog`, notifikasi dari `Notification`, saldo dari `Billing`. Pada jalur sekuensial D2,
wall time halaman = **jumlah** latensi seluruh widget: tiga widget yang masing-masing 50/70/90 ms menghasilkan
210 ms waktu render sebelum view induk bisa disusun. Widget adalah fragmen independen — tidak ada alasan
data-ke-data mereka saling menunggu. Konkurensi memotong biaya tersebut menjadi **maksimum** latensi
(widget tercepat menunggu terlama), ditambah biaya park antrian bila cap konkurensi lebih kecil dari jumlah
widget.

### 2.2 Paritas fail-soft D2 harus selamat di jalur konkuren

D2 menjanjikan tiga properti untuk komposisi widget: render query-only, kegagalan satu widget fail-soft
(placeholder + telemetry, halaman tetap ter-render), dan komposisi hanya lewat sub-request. Naik ke jalur
konkuren **tidak boleh melanggar satupun**: paritas perilaku sekuensial-konkuren dinyatakan eksplisit sebagai
matriks (§7) dan dijaga contract-test (fase C). Konkurensi yang membeli latensi dengan kehilangan fail-soft
adalah kemunduran arsitektur, bukan kemajuan.

### 2.3 Mesinnya sudah ada — ini RFC orkestrasi, bukan RFC runtime baru

`FiberScheduler` v2.26.0 sudah menyediakan spawn/await/awaitAll/timeout/run, `Semaphore` untuk pembatasan
fan-out, `CoroutineLocal` untuk state per-korutin, dan pemetaan cancellation yang jujur. `AsyncRuleEngine`
v2.27.0 sudah membuktikan pola "banyak item dievaluasi konkuren dengan cap + deadline kooperatif + hasil
urutan input" di produksi gerbang mutasi. RFC ini *tidak* menambah primitif runtime; ia menyusun ulang
pola yang sama untuk domain berbeda: render sub-request HMVC.

---

## 3. Non-sasaran

- **Bukan preemption.** PHP Fiber tidak mengizinkan interupsi korutin yang sedang berjalan; widget CPU-bound
  tanpa titik suspend tidak bisa dipotong (W4/W9). Ini properti model kooperatif ZEF, dipertahankan demi
  determinisme.
- **Bukan actor model / worker pool.** Resolver mengelola satu komposisi halaman dalam satu `run()` scheduler;
  tidak ada scheduler global lintas-request, tidak ada mailbox, tidak ada migrasi korutin antar worker
  RoadRunner.
- **Tidak mengubah jalur sekuensial D2.** `dispatchSubRequest()` satu-panggilan tetap API publik komposisi;
  jalur async adalah lapisan atas yang memakainya (§5.6).
- **Tidak membuka pintu Command dari widget.** Justru sebaliknya: aturan query-only D2 yang semula konvensi
  review dinaikkan menjadi penegakan runtime (W6).
- **Bukan jaminan speedup untuk I/O blocking.** Wall-clock benefit mensyaratkan render men-suspend (§8.2);
  menganggap PDO sinkron tumpang-tindih otomatis adalah salah kaprah yang didokumentasikan di sini agar tidak
  beredar sebagai mitos.

---

## 4. Basis mesin nyata

Setiap kebutuhan resolver dipetakan ke mesin yang **sudah live** — kolom kanan adalah FQCN + metode aktual
yang diverifikasi di `main`, bukan rencana:

| Kebutuhan | Mesin nyata (sudah berjalan) |
|:----------|:------------------------------|
| Menjalankan widget sebagai korutin | `Zef\Framework\Runtime\Async\FiberScheduler::spawn(callable, string $name): TaskInterface` — hanya mengantre; kode user tidak dieksekusi sinkron |
| Menunggu hasil per widget | `FiberScheduler::await(TaskInterface): mixed` — rethrow kegagalan/cancellation; `TaskInterface::result(): mixed` untuk hasil settled |
| Menunggu seluruh manifest, urutan input | `FiberScheduler::awaitAll(array): array` — hasil **in input order**; kegagalan pertama di-rethrow tanpa membatalkan sibling |
| Driver blocking top-level | `FiberScheduler::run(callable): int` — memompa sampai semua task settle, surface failures (`UnobservedTaskException`, `DeadlockException`) |
| Deadline per widget | `FiberScheduler::delay(float $seconds, callable): TaskInterface` sebagai guard timer + `TaskInterface::cancel(): bool` kooperatif; atau `FiberScheduler::timeout(float, callable): mixed` yang melempar `AsyncTimeoutException` |
| Cap konkurensi | `Zef\Framework\Runtime\Async\Semaphore::acquire(?CancellationTokenInterface)/release()` — over-release = `LogicException` (fail-fast bookkeeping) |
| State per-korutin (guard scope) | `Zef\Framework\Runtime\Async\CoroutineLocal::get/set` — WeakMap per fiber; `LogicException` di luar korutin |
| Pemetaan cancellation → verdict | preseden `Zef\Framework\Rules\AsyncRuleEngine`: `TaskCancelledException` tanpa permintaan cancel eval = deadline miss → verdict timeout |
| Penolakan mutasi dari widget | `Zef\Framework\CQRS\CommandBusInterface::use(CqrsMiddlewareInterface)` + `CqrsMiddlewareInterface::process(object, CqrsContext, Closure): mixed` |
| Query widget | `Zef\Framework\CQRS\QueryBusInterface::ask(object $query, ?CqrsContext): mixed` — tidak dipasangi guard |
| Backstop inisialisasi konkuren | `Zef\Framework\Container\FailFastInitializationGuard::synchronized()` → `Zef\Framework\Exception\ConcurrentServiceInitializationException` (sudah `ContainerExceptionInterface`) |
| Telemetri | `Zef\Framework\Observability\MeterInterface::increment(string, float\|int, array $attributes)` / `::observe(string, float, array)` |
| Uji deterministik tanpa real wait | port `MonotonicClockInterface` + `SleeperInterface` pada scheduler — waktu bisa dimajukan di test |

---

## 5. Kontrak

Penempatan mengikuti pola rumah `Rules`: port + DTO di Domain, engine di Application, dispatcher HTTP di
`Adapters\Kernel` berdampingan `Dispatcher` yang sudah ada. Penempatan final tetap wewenang PR pembawa kode
(lihat §12 butir 3).

```
src/Domain/Widget/          AsyncWidgetResolverInterface, WidgetTask, WidgetStatus,
                            ResolvedWidget, WidgetComposition, WidgetResolverOptions,
                            QueryOnlyGuardInterface, QueryOnlyViolationException
src/Application/Widget/     AsyncWidgetResolver, QueryOnlyGuard
src/Adapters/Kernel/        ModuleDispatcher (+ ModuleDispatcherInterface) — fase 2 RFC-0001
```

### 5.1 `AsyncWidgetResolverInterface`

```php
namespace Zef\Framework\Widget;

interface AsyncWidgetResolverInterface
{
    /**
     * Menyelesaikan seluruh manifest widget secara konkuren di atas scheduler.
     * HARUS dipanggil dari dalam korutin scheduler (melempar LogicException di
     * luar korutin) — persis kontrak AsyncRuleEngineInterface::evaluate().
     *
     * @param array<string, WidgetTask> $tasks key = kunci widget manifest
     */
    public function resolve(array $tasks, ?WidgetResolverOptions $options = null): WidgetComposition;

    /**
     * Driver blocking top-level: membungkus resolve() dalam satu scheduler run
     * penuh, mengembalikan hasil setelah seluruh widget settle. Jalur ini yang
     * dipakai controller biasa — jalur request HTTP saat ini tidak berkorutin.
     *
     * @param array<string, WidgetTask> $tasks
     */
    public function run(array $tasks, ?WidgetResolverOptions $options = null): WidgetComposition;
}
```

Dua-metode ini bukan kemewahan: `run()` memegang flag `running` scheduler (nested run = `LogicException`),
sehingga komposisi widget bersarang harus berada dalam SATU run dan memanggil `resolve()` dari dalam korutin.
Kontrak ganda ini menyalin `AsyncRuleEngineInterface::evaluate()/run()` yang sudah teruji.

### 5.2 `WidgetTask` — DTO input

```php
namespace Zef\Framework\Widget;

final readonly class WidgetTask
{
    /**
     * @param array<string,mixed> $params
     * @param ?float $timeoutMs null = jatuh ke WidgetResolverOptions::perWidgetTimeoutMs();
     *                          nilai eksplisit menang atas default; nilai negatif ditolak
     */
    public function __construct(
        public readonly string $key,
        public readonly string $module,
        public readonly string $controller,
        public readonly string $action,
        public readonly array $params = [],
        public readonly ?float $timeoutMs = null,
    ) {}

    /** Materialisasi dari manifest array (kompatibilitas draf komunitas). */
    public static function fromArray(string $key, array $definition): self;
}
```

`fromArray()` menerima bentuk `['module' => …, 'controller' => …, 'action' => …, 'params' => …, 'timeout_ms' => …]`
dan memvalidasi: `key/module/controller/action` non-kosong, kunci manifest unik, `params` berbentuk
`array<string,mixed>`, `timeout_ms` non-negatif. Pelanggaran input = `InvalidArgumentException` **sebelum**
fan-out — kesalahan programmer harus deterministik dan keras, bukan fail-soft (fail-soft hanya untuk
kegagalan runtime widget, §8.1).

### 5.3 `WidgetStatus` + `ResolvedWidget` + `WidgetComposition` — DTO output

```php
namespace Zef\Framework\Widget;

enum WidgetStatus
{
    case Rendered;           // sub-request selesai, HTML valid
    case Failed;             // throwable dari render (termasuk violation query-only & init konkuren)
    case DeadlineExceeded;   // guard timer memenangkan perlombaan
}

final readonly class ResolvedWidget
{
    public function __construct(
        public readonly string $key,
        public readonly string $module,
        public readonly WidgetStatus $status,
        public readonly string $html,            // placeholder bila status != Rendered
        public readonly float $durationMs,       // durasi nyata hingga settle (bukan budget)
        public readonly ?string $failureClass,   // FQCN throwable untuk Failed; null selainnya
    ) {}

    public function isRendered(): bool;
}

final readonly class WidgetComposition
{
    /** @param array<string, ResolvedWidget> $widgets urutan = urutan manifest, bukan urutan selesai */
    public function __construct(public readonly array $widgets) {}

    public function get(string $key): ResolvedWidget;   // kunci tak dikenal = LogicException
    public function htmlMap(): array<string, string>;   // key => HTML/placeholder — drop-in ke view
    public function allRendered(): bool;
    public function failedCount(): int;
}
```

`htmlMap()` adalah jembatan paritas untuk konsumsi tampilan: pemanggil mendapat `array<string,string>`
persis seperti hasil `dispatchSubRequest()` sekuensial yang dikumpulkan manual — migrasi dari loop sekuensial
ke resolver tidak mengubah bentuk data yang masuk view.

### 5.4 `WidgetResolverOptions`

```php
namespace Zef\Framework\Widget;

final readonly class WidgetResolverOptions
{
    /** null = satu permit per widget (pola AsyncRuleEngine: pool tak pernah kelaparan). */
    public function concurrency(): ?int;

    /** Budget default per widget dalam milidetik; null = tanpa deadline. */
    public function perWidgetTimeoutMs(): ?float;
}
```

Konstruksi mengikuti pola aksesor `RuleEngineOptions` (nilai default: `concurrency = null`,
`perWidgetTimeoutMs = 250.0` — angka final dibahas di §12 butir 1-2). Konversi ms → detik terjadi tepat satu
kali di batas engine (`timeoutMs / 1000.0`) karena seluruh API scheduler memakai detik `float`.

### 5.5 Guard query-only: `QueryOnlyGuardInterface` + middleware

```php
namespace Zef\Framework\Widget;

interface QueryOnlyGuardInterface
{
    /** Memasang scope widget pada fiber saat ini; kembalikan token disposable. */
    public function enter(string $widgetKey): QueryOnlyScope;

    /** DIPANGGIL MIDDLEWARE CommandBus — melempar bila fiber aktif berada dalam scope widget. */
    public function assertMutationAllowed(): void;
}

interface QueryOnlyScope
{
    public function exit(): void;   // idempotent; dipanggil di finally
}
```

Penegakan terpasang di **composition root**, sekali per aplikasi:

```php
$commandBus->use(new QueryOnlyGuardMiddleware($guard));   // QueryBus TIDAK dipasangi apa pun
```

```php
namespace Zef\Framework\Widget;

final class QueryOnlyGuardMiddleware implements \Zef\Framework\CQRS\CqrsMiddlewareInterface
{
    public function process(object $message, \Zef\Framework\CQRS\CqrsContext $context,
                            \Closure $next): mixed
    {
        $this->guard->assertMutationAllowed();   // QueryOnlyViolationException di scope widget
        return $next($message, $context);
    }
}
```

Mekanismenya fiber-lokal: resolver memasang scope lewat `CoroutineLocal` di dalam korutin widget
(`enter()` sebelum render, `exit()` di `finally`), sehingga `CommandBusInterface::dispatch()` yang dipanggil
dari render widget melempar `QueryOnlyViolationException` — sementara command yang sah dari controller induk
di fiber lain tidak terpengaruh sama sekali. **Batasan yang dinyatakan terbuka:** scope bersifat per-fiber
("never shared" menurut kontrak `CoroutineLocal`); widget yang sengaja men-spawn korutin tambahan lalu
melempar Command dari sana lolos dari guard fiber-lokal — kasus ini ditangkap contract-test modul (fase C),
bukan oleh runtime, dan dicatat sebagai wart yang disengaja diterima.

### 5.6 `ModuleDispatcher` dua-fase: kontrak D2 yang dikonkretkan

Jalur sekuensial D2 tetap satu panggilan; jalur async membutuhkan pemecahan eksplisit agar aturan pre-resolve
(W7) punya titik jangkar:

```php
namespace Zef\Framework\Adapters\Kernel;

interface ModuleDispatcherInterface
{
    /** Jalur D2 sekuensial: satu panggilan = prepare() + render(). */
    public function dispatchSubRequest(string $module, string $controller,
                                       string $action, array $params): string;

    /** Materialisasi controller + dependensinya — sinkron, dipanggil di fiber induk SEBELUM fan-out (W7). */
    public function prepare(\Zef\Framework\Widget\WidgetTask $task): object;

    /** Render sub-request oleh korutin widget; query-only, mengembalikan string HTML. */
    public function render(object $controller, string $action, array $params): string;
}
```

`prepare()` melakukan seluruh resolusi container (controller widget + dependensi request-scoped-nya) secara
sekuensial di fiber induk. `render()` menerima controller yang sudah jadi — di dalam korutin widget ia hanya
mengeksekusi action + renderer kernel (D7, escape-by-default). Dengan pemisahan ini, `dispatchSubRequest()`
adalah gula sintaktis atas dua fase yang sama, sehingga paritas sekuensial-konkuren dijaga oleh **satu**
implementasi, bukan dua kode yang harus dijaga tetap sama.

---

## 6. Semantik eksekusi

### 6.1 Struktur tugas: body / inner / guard per widget

Persis anatomi `AsyncRuleEngine`, diadaptasi ke widget. Untuk setiap entri manifest:

- **body** `widget-<key>` — korutin total: `Semaphore::acquire()` → spawn inner → `await(inner)` →
  mengonversi outcome menjadi tepat satu `ResolvedWidget` → `release()` di `finally`. Body **tidak pernah
  melempar** untuk masalah level widget; semua jalur berakhir pada verdict.
- **inner** `render-<key>` — korutin murni: `QueryOnlyGuard::enter()` → `ModuleDispatcher::render()` →
  `exit()` di `finally`. Nilai kembalian = string HTML.
- **guard** `render-<key>-deadline` — timer `FiberScheduler::delay($timeoutMs / 1000.0, fn () => $inner->cancel())`;
  dibatalkan di `finally` body bila inner selesai lebih dulu (cancel pada task settled adalah no-op).

Pemetaan outcome di body (satu-satunya tempat verdict diputuskan):

| Kejadian di `await(inner)` | Verdict | Catatan |
|:---------------------------|:--------|:--------|
| nilai kembalian string | `Rendered` | HTML masuk apa adanya |
| `TaskCancelledException` tanpa permintaan cancel komposisi | `DeadlineExceeded` | satu-satunya canceller inner adalah guard deadline (pola pemetaan `AsyncRuleEngine::cancelledVerdict()`) |
| `Throwable` lain | `Failed` | `failureClass` = FQCN; termasuk `QueryOnlyViolationException` dan `ConcurrentServiceInitializationException` |

Setelah seluruh body settle, hasil diurutkan **kembali ke urutan manifest** (mirror `ksort` +
`array_values` pada `AsyncRuleEngine::evaluate()`) lalu dibungkus `WidgetComposition`.

### 6.2 Aturan pre-resolve dependensi request-scoped

Seluruh `prepare()` dieksekusi **sekuensial di fiber induk sebelum satu pun body di-spawn**. Ini menutup
kelas bug konkurensi pada saat lahirnya: dua widget yang kebetulan memicu inisialisasi service singleton /
request-scoped yang sama pada saat bersamaan. Backstop-nya sudah ada di container —
`FailFastInitializationGuard::synchronized()` melempar `ConcurrentServiceInitializationException` bila
sebuah service diinisialisasi re-entrant. Resolver memetakan pengecualian itu menjadi outcome `Failed`
(reason `init`) untuk widget yang bersangkutan: halaman tetap fail-soft, log tetap keras (kesalahan
dependensi adalah bug wiring, bukan kondisi lingkungan). Konsekuensi kontraktual untuk penulis modul:
**controller widget menerima dependensinya lewat konstruktor** (diinjeksi `prepare()`), dan tidak boleh
me-resolve dari container secara lazy di dalam `render()` — aturan yang juga berlalu sebagai contract-test
fase C.

### 6.3 Determinisme output

Hasil tidak pernah mengikuti urutan penyelesaian. `WidgetComposition::$widgets` terurut persis seperti
manifest; `htmlMap()` stabil untuk diff snapshot view; telemetry per widget tidak memengaruhi ordering.
Kesalahan input (kunci ganda, field kosong, `timeout_ms` negatif) gagal **sebelum** korutin pertama lahir —
tidak ada kondisi "setengah komposisi lalu melempar".

### 6.4 Propagasi konteks CQRS

Setiap `ask()` yang dilakukan widget membawa `CqrsContext` turunan: `correlationId` baru per sub-request,
`traceParent` menaikkan trace induk, `attributes` membawa `widget.key` + `widget.module` sehingga query
sebuah widget bisa ditelusuri kembali ke komposisinya di telemetry. `idempotencyKey` **tidak** diturunkan —
query tidak ber-idempotensi.

---

## 7. Matriks paritas: sekuensial D2 vs konkuren RFC-0002

| Dimensi | Sekuensial (`dispatchSubRequest()` loop) | Konkuren (`AsyncWidgetResolver::run()`) | Paritas |
|:--------|:------------------------------------------|:------------------------------------------|:--------|
| Urutan hasil | urutan pemanggilan | urutan manifest (bukan urutan selesai) | ✅ identik untuk konsumen view |
| Kegagalan 1 widget | placeholder + `widgets.failed{module}` | placeholder + `zef.widgets.failed{module,widget,reason}` | ✅ superset atribut |
| Query-only | konvensi review (D2) | ditegakkan runtime: middleware CommandBus + scope fiber | ⬆️ lebih ketat |
| Deadline per widget | tidak ada (mengikuti budget HTTP global) | guard kooperatif per task, default opsi | ➕ baru |
| Wall time | Σ durasi widget | maksimum durasi widget yang overlap + park antrian semaphore | tergantung profil I/O (§8.2) |
| Dependensi request-scoped | lazy bebas | wajib pre-resolve; pelanggaran → verdict Failed | ⬆️ lebih ketat |
| Preemption | tidak ada | tidak ada (kooperatif — korutin tanpa suspend menyelesaikan diri) | ✅ model sama |
| Konteks CQRS | satu context induk | context turunan per widget (trace + `widget.*`) | ⬆️ lebih kaya |
| Jalur HTTP | langsung dari controller | `run()` membawa scheduler sendiri; `resolve()` bila caller sudah berkorutin | ✅ keduanya tersedia |
| renderer & escape | renderer kernel D7 | renderer kernel D7 (objek yang sama via `render()`) | ✅ satu implementasi |

Baris "wall time" satu-satunya yang tidak paritas penuh — dan memang itu tujuannya: §8.2 menjelaskan kapan
penurunan itu terjadi dan seberapa jujur batasnya.

---

## 8. Matriks batas kegagalan

### 8.1 Kondisi → status → perilaku

| Kondisi | Status | HTML | Telemetri `reason` | Log | Halaman |
|:--------|:-------|:-----|:--------------------|:----|:--------|
| Render sukses | `Rendered` | hasil render | — (`zef.widgets.duration` observe) | — | lanjut |
| `Throwable` dari action/render | `Failed` | placeholder | `exception` | `error` + exception + task | lanjut |
| Guard deadline menang | `DeadlineExceeded` | placeholder | `deadline` | `warning` (kondisi yang diharapkan bisa terjadi) | lanjut |
| `QueryOnlyViolationException` (widget melempar Command) | `Failed` | placeholder | `violation` | `error` — kesalahan programmer, keras | lanjut |
| `ConcurrentServiceInitializationException` (lazy-init saat fan-out) | `Failed` | placeholder | `init` | `error` — bug wiring | lanjut |
| Manifest tak valid (kunci ganda / field kosong / timeout negatif) | — | — | — | — | `InvalidArgumentException` **sebelum** fan-out |
| Kunci tak dikenal pada `get()`/`htmlMap()` konsumsi | — | — | — | — | `LogicException` (programmer error konsumen) |

Placeholder dihasilkan **oleh renderer kernel D7** (escape-by-default) — atribut seperti `data-widget`
di-escape dari nilai kunci; template placeholder tunggal milik kernel di fase B, override per modul dibahas
di §12 butir 5.

### 8.2 Kejujuran wall-clock — kapan overlap sungguhan terjadi

| Profil render widget | Wall time komposisi | Penjelasan |
|:----------------------|:--------------------|:------------|
| Murni CPU tanpa suspend | ≈ Σ (tidak ada overlap) | model kooperatif: tanpa titik suspend tidak ada pergantian korutin; deadline juga tidak bisa memotong |
| Menunggu timer (`FiberScheduler::sleep()` / `delay()`) | overlap penuh via timer queue monotonic | sudah berjalan hari ini |
| Menunggu `await()` task/adapter yang park di `SuspensionHandle` | overlap penuh | jalur inilah target adapter I/O async masa depan |
| I/O blocking murni (PDO sinkron, file get) | ≈ Σ — **tidak ada overlap** | blocking call membekukan satu-satunya thread scheduler; resolver tetap memberi deadline + cap + ordering + fail-soft |
| Jumlah widget > permit semaphore | ≈ Σ berkelompok per gelombang antrian permit | trade-off sadar: melindungi pool koneksi > latency |

Dampak praktisnya jujur dan penting: **angka "50 ms + 70 ms → ~70 ms" hanya benar bila kedua render
men-suspend pada I/O yang diparkirkan.** Dengan driver blocking saat ini, naik ke resolver memberi hari ini:
deadline per widget, cap konkurensi, hasil deterministik, fail-soft yang diperkaya reason — dan *struktur*
yang siap menangkap overlap penuh begitu adapter I/O async park di `SuspensionHandle`. Menjanjikan speedup
tanpa syarat itu adalah cara melahirkan wart dokumentasi baru.

---

## 9. Kalibrasi draf inti (core logic komunitas)

Draf `AsyncWidgetResolver` komunitas di-review penuh; struktur intinya — spawn per widget, fail-soft dengan
placeholder, telemetry + logger, render via sub-request — **diadopsi**. Yang dikalibrasi agar berdiri di atas
API mesin nyata:

| Draf | Kalibrasi | Alasan mesin |
|:-----|:----------|:--------------|
| `use Zef\Framework\Application\Async\FiberScheduler` | `Zef\Framework\Runtime\Async\FiberScheduler` (`src/Application/Runtime/Async`) | namespace aktual; `Application\Async` tidak ada |
| `$this->scheduler->spawn(fn)` — benar | return `TaskInterface`, bukan `Fiber`; spawn hanya mengantre | `spawn(): TaskInterface` — "spawn never executes user code synchronously" |
| `$this->scheduler->join()` | `await($task)` per task / `awaitAll($tasks)` | `join()` tidak ada di API scheduler |
| `$fiber->getResult()` | `$task->result(): mixed` — rethrow untuk Failed/Cancelled | `TaskInterface::result()` bukan getter polos |
| try/catch di dalam closure widget | dipertahankan sebagai **pola body-total**, tetapi konversi outcome pindah ke body resolver; inner tetap murni | menyalin disiplin `AsyncRuleEngine` (satu verdict per widget, body tak pernah melempar) |
| `$meter->increment("widgets.failed", ["module" => …])` | `$meter->increment('zef.widgets.failed', 1, ['module' => …, 'widget' => …, 'reason' => …])` | `MeterInterface::increment(string, float\|int, array)` — atribut argumen ketiga; konvensi prefix `zef.*` |
| `assertQueryOnlyContext($task)` (stub) | `QueryOnlyGuardInterface` + scope fiber-lokal + `QueryOnlyGuardMiddleware` di CommandBus | kontrak `CoroutineLocal` + `CqrsMiddlewareInterface` (§5.5) |
| `"<div … data-widget='{$key}'>…"` interpolasi mentah | placeholder dari renderer kernel D7, escape-by-default | interpolasi atribut mentah = hazard injeksi |
| `QueryBusInterface` di `Application\CQRS` | `Zef\Framework\CQRS` (`src/Domain/CQRS`); mutasi lewat `CommandBusInterface::dispatch()` | namespace aktual; nama metode dispatch |
| `$dispatcher->dispatchSubRequest(...)` langsung di korutin | jalur async = `prepare()` di fiber induk + `render()` di korutin widget | aturan pre-resolve W7; `dispatchSubRequest()` tetap wajah sekuensial (§5.6) |
| komentar "non-blocking I/O via Swoole/RoadRunner driver" | dijernihkan jadi tabel §8.2 | kernel hari ini park di timer/suspension; adapter I/O async = prasyarat overlap |
| `return array<string,string>` | `WidgetComposition` + `htmlMap()` untuk bentuk `array<string,string>` | paritas + metadata (status, durasi, failureClass) tanpa memutus konsumen view |

Contoh pemakaian terkalibrasi ada di Lampiran A.

---

## 10. Observability, konfigurasi, doctor

**Metric** (nama final mengikuti konvensi `zef.*` yang sudah berjalan):

| Metric | Jenis | Atribut | Kapan |
|:-------|:------|:--------|:------|
| `zef.widgets.failed` | increment | `module`, `widget`, `reason` (`exception`/`deadline`/`violation`/`init`) | setiap outcome `Failed`/`DeadlineExceeded` |
| `zef.widgets.duration` | observe (ms) | `module`, `widget`, `status` | setiap widget settle — termasuk yang sukses |

`zef.widgets.failed` adalah konkretisasi shorthand `widgets.failed{module}` pada D2: nama penuh + atribut
terstruktur, dibaca langsung oleh alarm SRE tanpa parsing string.

**Konfigurasi** (`Config System v2`, schema fail-fast):

| Kunci | Default | Makna |
|:------|:---------|:------|
| `widgets.timeout_ms` | `250` | budget per widget bila task tak menetapkan sendiri |
| `widgets.max_concurrency` | `null` (satu permit per widget) | batas fan-out global komposisi |

**Doctor.** `bin/zef doctor` menambah satu cek wiring: middleware `QueryOnlyGuard` terpasang pada
`CommandBus` aktif (exit 1 bila FAIL — konsisten dengan semangat warm-up D9: salah wiring harus mati saat
doctor, bukan saat halaman pertama dirender di produksi).

---

## 11. Fasing implementasi

Setiap fase aditif, nol perubahan perilaku lama, melewati seluruh gerbang CI:

| Fase | Isi | Prasyarat |
|:-----|:----|:----------|
| **A. ModuleDispatcher sekuensial** (milik fase 2 RFC-0001) | `dispatchSubRequest()` + `prepare()`/`render()` internal + renderer D7 | D2 |
| **B. Resolver + guard + telemetry** | kontrak `Zef\Framework\Widget` (Domain) + `AsyncWidgetResolver` (Application) + `QueryOnlyGuardMiddleware` wiring + metric `zef.widgets.*` + opsi | A |
| **C. Contract-test + doctor + docs parity** | harness modul D3: widget query-only, placeholder parity sekuensial-konkuren, deadline deterministik; cek doctor; matriks paritas §7 jadi assertion | B |

---

## 12. Pertanyaan terbuka

| # | Pertanyaan | Rekomendasi saat ini |
|:--|:-----------|:---------------------|
| 1 | Default `widgets.timeout_ms` — 250 atau 500? | 250: widget adalah fragmen; budget harus di bawah ambang persepsi latensi halaman induk, kalau tidak deadline tidak pernah menang |
| 2 | Default konkurensi — `null` (satu permit per widget) atau angka tetap? | `null` — identik observabel dengan tanpa semaphore (pola `AsyncRuleEngine`); angka tetap jadi keputusan deploy per-beban |
| 3 | Penempatan final: `Zef\Framework\Widget` vs `Adapters\Http\HMVC` ala draf komunitas? | `Widget` Domain+Application (mirror `Rules`); dispatcher tetap di `Adapters\Kernel` |
| 4 | Budget induk (overall timeout seluruh komposisi) di v1? | tidak — cukup `FiberScheduler::timeout()` oleh caller; komposisi eksplisit lebih jujur daripada knob kedua |
| 5 | Placeholder per modul (view fallback milik modul)? | kernel dulu (satu template, escape-by-default); override belakangan lewat konfigurasi modul |
| 6 | `zef.widgets.duration` dalam ms atau detik? | ms — sisi konsumen alarm berpikir dalam ms; konversi ke detik hanya di batas engine scheduler |

---

## Lampiran A — Contoh pemakaian terkalibrasi

Controller induk (jalur HTTP biasa — tidak berkorutin — memakai `run()`):

```php
namespace Zef\Modules\Dashboard\Controllers;

use Zef\Framework\Widget\AsyncWidgetResolverInterface;
use Zef\Framework\Widget\WidgetTask;

final class DashboardController
{
    public function __construct(private AsyncWidgetResolverInterface $widgets) {}

    public function index(): string
    {
        $composition = $this->widgets->run([
            'cart_summary' => WidgetTask::fromArray('cart_summary', [
                'module'     => 'Toko',
                'controller' => 'CartWidgetController',
                'action'     => 'renderMiniCart',
                'params'     => ['userId' => 42],
            ]),
            'top_products' => WidgetTask::fromArray('top_products', [
                'module'     => 'Catalog',
                'controller' => 'ProductWidgetController',
                'action'     => 'renderTopList',
                'params'     => ['limit' => 5],
                'timeout_ms' => 120.0,   // override per task — default 250 tidak dipakai
            ]),
        ]);

        return $this->renderView('dashboard.view.php', [
            'cartWidgetHtml'     => $composition->htmlMap()['cart_summary'],
            'productsWidgetHtml' => $composition->htmlMap()['top_products'],
        ]);
    }
}
```

Sketsa internal resolver (struktur, bukan kode final — mengikuti disiplin `AsyncRuleEngine`):

```php
// di dalam WidgetComposition-returning engine — resolve() dipanggil dalam korutin:
foreach ($tasks as $key => $task) {
    $controller = $this->dispatcher->prepare($task);        // W7: pre-resolve di fiber induk
    $bodies[$key] = $this->scheduler->spawn(
        $this->widgetBody($key, $task, $controller),        // total: acquire → inner → verdict → release
        sprintf('widget-%s', $key),
    );
}

foreach ($bodies as $key => $body) {
    $results[$key] = $this->scheduler->await($body);        // body total → tidak melempar utk level widget
}

return new WidgetComposition($results);                     // urutan manifest terjaga (map keyed)
```

```php
// widgetBody — anatomi per widget (semua jalur berakhir pada satu verdict):
$semaphore->acquire();
try {
    $inner = $this->scheduler->spawn(function () use ($controller, $task): string {
        $scope = $this->guard->enter($task->key);           // W6: scope fiber-lokal
        try {
            return $this->dispatcher->render($controller, $task->action, $task->params);
        } finally {
            $scope->exit();
        }
    }, sprintf('render-%s', $task->key));

    $guard = $task->timeoutMs !== null || $timeoutMsDefault !== null
        ? $this->scheduler->delay(($task->timeoutMs ?? $timeoutMsDefault) / 1000.0,
            static fn (): bool => $inner->cancel(),
            sprintf('render-%s-deadline', $task->key))
        : null;

    try {
        return ResolvedWidget::rendered($task, $this->scheduler->await($inner), $startedAtMs);
    } catch (TaskCancelledException) {
        return ResolvedWidget::deadlineExceeded($task, $startedAtMs);   // satu-satunya canceller = guard
    } catch (\Throwable $e) {
        return ResolvedWidget::failed($task, $e, $startedAtMs);         // violation/init/exception
    } finally {
        $guard?->cancel();
        $inner->cancel();                                     // no-op bila sudah settle
    }
} finally {
    $semaphore->release();
}
```

## Lampiran B — Matriks uji

Deterministik tanpa real wait: scheduler di-inject `MonotonicClockInterface` + `SleeperInterface` test double
yang memajukan waktu — properti yang sudah dipakai suite async runtime.

| Kasus | Assertion kunci |
|:------|:----------------|
| Dua widget beda "latensi" timer | wall time ≈ max(bukan Σ); hasil urutan manifest |
| Widget melempar `Throwable` | placeholder + `Failed` + metric reason `exception`; halaman tetap utuh |
| Guard deadline menang | `DeadlineExceeded` + reason `deadline`; inner sudah settle = cancel no-op |
| Widget tanpa suspend (CPU loop) | selesai sendiri → `Rendered`; deadline tidak memotong (dokumentasi W4 hidup sebagai test) |
| Widget melempar Command dari render | `QueryOnlyViolationException` → `Failed` reason `violation` |
| Controller induk melempar Command saat komposisi | **tidak** terblok — guard fiber-lokal |
| Widget lazy-init service saat fan-out | `ConcurrentServiceInitializationException` → `Failed` reason `init` |
| Konkurensi cap = 1 | eksekusi widget terserialize (permit antrian) — wall ≈ Σ |
| Kunci manifest ganda / field kosong | `InvalidArgumentException` sebelum spawn pertama |
| `resolve()` di luar korutin | `LogicException` (mirror `AsyncRuleEngine::evaluate()`) |
| Paritas sekuensial-konkuren | `htmlMap()` identik untuk manifest sama pada jalur `dispatchSubRequest()` vs `run()` |
| Coroutines bersarang (widget spawn korutin lalu Command) | **lolos guard** — wart diterima; contract-test fase C yang menangkap |
| Telemetry | `zef.widgets.failed` + `zef.widgets.duration` terpancar dengan atribut penuh |

