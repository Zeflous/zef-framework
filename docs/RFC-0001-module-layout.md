# RFC-0001 — Layout Target v3 & Sistem Modul Plug-n-Play

> **Status:** DRAFT — terbuka untuk umpan balik · **Pemilik keputusan:** @mbetixz · **Disusun:** 2026-10-03
> Umpan balik silakan lewat issue berlabel `rfc` atau komentar pada PR yang membawa dokumen ini.
> Dokumen ini mengambil alih draf ADR `docs/TARGET-ARCHITECTURE.md` pada PR #361 (lihat §7).

---

## 1. Ringkasan

v3 adalah langkah berikutnya setelah kernel dianggap matang: **sistem modul eksternal yang
plug-n-play di atas kernel hexagonal yang tidak lagi diutak-atik**. Kernel menangani
container, router, CQRS, keamanan, observability, dan gerbang mutasi — sisi aplikasi cukup
menjatuhkan satu folder modul untuk menginstal fitur. Prinsip zero-composer yang sudah
berjalan (`autoload/zef_autoload.php`) diperluas menjadi janji distribusi penuh:
**drop folder = install**.

Sembilan keputusan inti:

| # | Keputusan | Inti |
|:--|:----------|:-----|
| **D1** | Dua zona modul | `src/Module/` internal (release bareng kernel, governance penuh) vs `Modules/` eksternal (drop zone plug-n-play) |
| **D2** | Anatomi modul eksternal | MVC klasik ala Kohana: manifest + `Controllers/`, `Models/`, `Views/`, `Configs/`, `Languages/`, `Middleware/`, `Plugins/`, `Migrations/`, `public/`, `tests/`; blueprint DDD opsional (`Domain/` + `Application/` bawaan modul); komposisi antar-modul lewat widget sub-request |
| **D3** | Firewall governance | Kernel tetap hexagonal; `Modules/` berada di luar graf deptrac; mutu modul ditegakkan contract-test, bukan baseline kernel |
| **D4** | i18n cascade 5 tingkat | *nearest-wins*: locale modul → default modul → locale sistem → default sistem → kembalikan kunci + telemetri |
| **D5** | Kontrak `temp/` | Hanya artefak turunan runtime; apa yang tak bisa diregenerasi dilarang tinggal di `temp/`; aman di-wipe kapan pun |
| **D6** | `generator/` + sandbox | Mesin codegen di-shipped sebagai kode rilis; output `make:*` ditampung di `temp/generator/`, di-*promote* bila dipakai, di-GC bila tidak |
| **D7** | Views dua tingkat | View internal kernel (error/exception/maintenance) + view modul privat berdasarkan lokasi — file view tidak pernah web-readable |
| **D8** | `public/` docroot murni | Aset & upload publik + publish aset modul via symlink `public/modules/<x>/` |
| **D9** | AOT dua-perilaku | `EnvironmentMode` resolver tunggal, dikunci sekali saat boot worker; komposisi *Production Wins* (global × manifest); mismatch di production = `exit(1)` tanpa fallback diam-diam |

Semua keputusan dirancang **aditif**: tidak ada perilaku v2 yang berubah, dan setiap fase
implementasi harus tetap melewati gerbang CI yang sama (§6).

---

## 2. Motivasi

### 2.1 Tensi arsitektur yang belum terselesaikan

Kernel ZEF ditegakkan deptrac `--fail-on-uncovered` dengan 0 violation — arsitektur yang
hanya ada di diagram tidak dihitung. Ketatnya itu benar untuk kode framework, tetapi
menimbulkan pertanyaan yang belum terjawab untuk kode **aplikasi**: kalau pengguna menulis
modul blog dengan `Controllers/` + `Models/`, apakah mereka wajib menyusun hexagonal penuh
(Domain/Application/Infrastructure) hanya untuk CRUD sederhana? Jawaban desain selama ini
melayang antara "module hexagonal internal" dan HMVC — dua arah yang tarik-menarik sejak
arsitektur target pertama kali didiskusikan. RFC ini mengunci jawabannya: **kedua-duanya,
dipisahkan firewall** (D3). Kernel mempertahankan kemurnian hexagonal; modul eksternal
memakai struktur MVC klasik yang akrab bagi pengembang aplikasi. Tensi tersebut selesai
bukan dengan kompromi di dalam satu style, melainkan dengan pemisahan kepemilikan.

### 2.2 Wart audit yang harus ditutup lewat desain

Tiga temuan audit internal tidak bisa ditutup dengan patch tambal-sulam karena akarnya
struktural:

1. **Scaffolder menulis dead-path.** Generator pernah menulis ke pohon direktori era lama
   (`app/Middleware/`) yang tidak lagi menjadi target registrasi — hasil generate terjamin
   tidak pernah dimuat. Perbaikan PR #357 saat itu berupa penyuntingan manual 9 entri
   classmap, yang seharusnya mustahil dilakukan tangan pada berkas turunan mesin.
2. **Classmap yatim.** `scripts/dev/update_classmap.php` ada dan berfungsi, tetapi tidak
   terikat pada alur kerja mana pun — tidak dipanggil CI, tidak dipanggil scaffolder,
   tidak disebut dokumentasi. Alat yang benar ada di tempat yang salah.
3. **Instruksi `composer dump-autoload` menyesatkan.** Bagi jalur zero-composer (yang
   merupakan identitas proyek), instruksi tersebut tidak berpengaruh apa pun terhadap
   `autoload/zef_autoload.php` — classmap hanya berubah bila di-regen oleh tooling milik
   ZEF sendiri.

Ketiganya menunjuk ke keputusan yang sama: codegen harus punya rumah engine yang di-shipped
(D6), dan classmap harus hanya pernah disentuh tooling pada momen promosi eksplisit.

### 2.3 Warisan: module system 2020

Desain ini bukan tren yang dikejar. Repo pendahulu (`mbetixz/zeflous`, CMS Kohana-style,
2020) sudah menjalankan inti pola yang diusulkan di sini: pemindaian otomatis
`modules/*/configs/routes.config.php` oleh router, fallback locale sistem, cache route di
`data/cache/`, aset modul yang diserve dari folder publik. v3 membawa pola itu kembali
**dengan tambahan yang 2020 tidak punya**: manifest deklaratif + validasi + pengurutan
topologis (2020 memindai buta tanpa validasi), gerbang governance (2020: nol test), kebijakan
keamanan fail-closed (2020: tidak ada policy), dan sandbox scaffold dengan promosi eksplisit
(2020: tidak ada scaffolder). Pemetaan satu-ke-satu dirinci di Lampiran A.

---

## 3. Keputusan desain

### D1 — Dua zona modul, dipisahkan kepemilikan

Sumbu pemisahnya adalah **ownership + distribusi**, bukan ukuran atau gaya kode:

| Aspek | `src/Module/` (internal) | `Modules/` (eksternal) |
|:------|:-------------------------|:-----------------------|
| Isi saat ini | `Core`, `Health` (naik dari `modules/` lama) | modul pihak ketiga / milik aplikasi |
| Dirilis | bareng kernel, satu versi dengan framework | independen, versi sendiri via manifest |
| Discovery | hard-wired composition root | manifest `module.php` + topological sort |
| Governance | penuh: deptrac, PHPStan max, mutasi, coverage | di luar graf deptrac; contract-test (D3) |
| Contoh audiens | pengembang framework | pengembang aplikasi / pembuat modul |

Zona eksternal menegakkan janji distribusi zero-composer: menaruh folder di `Modules/`
berarti modul terpasang — tanpa Composer, tanpa build step, tanpa registrasi manual.

### D2 — Anatomi modul eksternal: MVC klasik

Struktur kanonik `Modules/<Nama>/` mengikuti pola Kohana yang terbukti akrab bagi penulis
aplikasi (seluruh direktori **plural**, konsisten):

```
Modules/
└── Blog/
    ├── module.php            # manifest: id, versi, requires, provides, namespace,
    │                          # mode (dev|production), enabled
    ├── Configs/              # routes.php, view.php, database.php, languages.php, …
    ├── Controllers/          # target dispatch; titik HMVC ModuleDispatcher
    ├── Models/               # bebas membungkus query builder / repository kernel
    ├── Views/                # sumber presentasi — PRIVATE berdasarkan lokasi (D7)
    ├── Middleware/           # middleware milik modul (per-route, era per-route pipeline)
    ├── Plugins/              # sub-plugin ter-scope: hanya terdaftar saat parent aktif
    ├── Languages/            # en/ id/ cn/ … + default.php fallback
    ├── Migrations/           # skema milik modul
    ├── public/               # aset modul → di-publish ke public/modules/<blog>/ (D8)
    └── tests/                # contract-test modul (D3)
```

Model dan controller modul **bebas** memakai layanan kernel (query builder, bus CQRS,
cache) tanpa dipaksa upacara hexagonal. Konsekuensinya jelas dan disengaja: kode modul
tidak menerima perlindungan baseline kernel, melainkan perlindungan kontrak (D3).
Pemetaan setiap direktori ke mesin ZEF yang sudah berjalan ada di Lampiran B.

#### Komposisi antar-modul: widget sebagai primitive HMVC

HMVC tidak diimplementasikan sebagai panggilan method lintas modul, melainkan lewat
**sub-request ter-bound** yang diserve `ModuleDispatcher`: controller induk (mis.
`Dashboard`) mengomposisi **widget** — triad MVC kecil milik modul lain (mis.
`CatalogProductWidget`) — lalu menyisipkan fragmen hasil render-nya ke view induk.
Aturan mainnya:

- Sub-request adalah satu-satunya jalur komposisi — tidak ada import kelas controller
  modul lain secara langsung; dependensi antar-modul dideklarasikan di manifest
  (`requires`/`provides`) dan tervalidasi saat discovery (D3).
- Widget dirender lewat renderer kernel yang sama (escape-by-default, D7) — bukan
  `extract()` + `include()` ad-hoc, yang merupakan hazard injeksi variabel.
- Render bersifat **query-only**: widget boleh mengirim `Query` ke bus, tidak boleh
  mengirim `Command` (side effect) sebagai bagian dari rendering.
- Kegagalan satu widget **fail-soft**: halaman tetap ter-render dengan placeholder,
  kegagalan di-log + counter telemetry (`widgets.failed{module}`) — pola resilience
  halaman, bukan exception yang membatalkan seluruh halaman.

Jalur konkuren — fan-out seluruh widget satu komposisi di atas async runtime native,
dengan deadline per widget dan penegakan query-only di runtime — di-spec terpisah di
[RFC-0002](RFC-0002-async-widget-resolver.md); paritas perilaku jalur sekuensial dan
konkuren dikunci di matriks paritas dokumen tersebut.

#### Blueprint opsional: bounded context DDD bawaan modul

Modul dengan logika bisnis berat boleh membawa *bounded context*-nya sendiri di dalam
pohon modul — **bukan** di `src/`, yang beku bersama kernel:

```
Modules/Catalog/
├── module.php              # manifest juga mendaftarkan handler ke bus
├── Domain/                 # Entity, Aggregate, Value Object, Domain Event
├── Application/            # Commands/, Queries/, Handlers/ (orkestrasi)
├── Controllers/            # konsumen bus — kirim Command/Query, terima DTO
├── Views/                  # menerima DTO / hasil render, bukan entity
└── …
```

Aliran data: Controller → bus (`CommandBusInterface::dispatch()` /
`QueryBusInterface::ask()`) → Handler → Repository port → **DTO kembali ke
presentation** — entity tidak pernah bocor ke view. Mesinnya seluruhnya milik kernel
yang sudah berjalan: bus CQRS dengan `register()`/`freeze()`, base `Repository`,
Event Sourcing; modul hanya membawa aturan bisnisnya sendiri. Blueprint ini opsional
(`make:module --style=ddd`); **MVC klasik tetap default** — dua-duanya legal di balik
firewall karena yang dijaga adalah kontrak manifest + contract-test, bukan gaya
internal modul (D3). Modul lain yang butuh data `Catalog` memanggil kontrak publiknya
lewat dependensi manifest — bukan mengimpor `Modules\Catalog\Domain\…` langsung.

### D3 — Firewall governance: kernel hexagonal, modul MVC

deptrac kernel **tidak** meng-graph `Modules/` — folder itu berada di luar peta layer.
Sebagai gantinya, mutu modul ditegakkan tiga lapis: (1) validasi manifest saat discovery
(id unik, `requires`/`provides` terpenuhi setelah pengurutan topologis, tabrakan kapabilitas
ditolak); (2) contract-test yang dijalankan terhadap modul aktif — modul yang gagal kontrak
tidak dimuat di produksi; (3) modul hanya boleh bergantung pada **permukaan publik kernel**
(namespace kontrak), bukan detail internal. Sebuah modul boleh menyentuh `Domain\…Interface`,
tidak boleh menyentuh `Infrastructure\…` milik kernel langsung.

Profil deptrac zona modul hidup di **berkas terpisah** (mis. `deptrac.modules.yaml`) —
menggabungkannya ke `deptrac.yaml` kernel justru menjebol firewall: `--fail-on-uncovered`
akan menarik seluruh `Modules/` ke graf layer kernel. Sketsa ruleset-nya (nama layer
dan collector persisnya = detail implementasi PR yang membawa profil ini):

```yaml
# deptrac.modules.yaml — profil ZONA MODULE (terpisah dari deptrac.yaml kernel)
deptrac:
  paths: [Modules]
  layers:
    - name: Module_Presentation   # Controllers/, Views/, Middleware/
      collectors: [{ type: className, regex: '^Modules\\[^\\]+\\(Controllers|Views|Middleware)\\' }]
    - name: Module_Application    # Application/ (blueprint DDD) + Models/ (MVC default, tier data-access)
      collectors: [{ type: className, regex: '^Modules\\[^\\]+\\(Application|Models)\\' }]
    - name: Module_Domain         # Domain/ (blueprint DDD)
      collectors: [{ type: className, regex: '^Modules\\[^\\]+\\Domain\\' }]
    - name: Kernel_Public         # permukaan publik kernel — allowlist eksplisit
      collectors:
        - { type: className, regex: '^Zef\\Framework\\(Domain|Application)\\' }
        - { type: className, regex: '^Zef\\Framework\\Infrastructure\\Database\\' }  # query builder + base repository
  ruleset:
    Module_Presentation: [Module_Application, Kernel_Public]  # TIDAK Module_Domain — entity tidak bocor ke view
    Module_Application:    [Module_Domain, Kernel_Public]
    Module_Domain:         [Kernel_Public]                    # murni: kontrak & VO kernel saja
```

`Kernel_Public` adalah **allowlist eksplisit**: kontrak `Domain/**` + `Application/**`
(interface bus, port cache/job/message, value object) ditambah elemen Infrastructure
yang memang dirancang untuk konsumsi aplikasi (query builder, base `Repository`).
Daftar ini hanya diperluas lewat keputusan tertulis — bukan diam-diam — dan adapter
internal kernel (crypto, Redis, OTLP, Prometheus) tetap di luar jangkauan modul.
Arah layering di dalam modul mengikuti proposal klasik DDD: presentation →
application → domain, domain tidak bergantung pada apa pun di atasnya.

### D4 — i18n: cascade 5 tingkat, nearest-wins

Rantai pencarian kunci terjemahan bersifat eksplisit dan tidak pernah melempar exception:

1. `Modules/<X>/Languages/{locale}/…` — locale modul
2. `Modules/<X>/Languages/default.php` — fallback modul
3. `src/Languages/{locale}/…` — locale **sistem** (baru: kernel punya `Languages/`)
4. `src/Languages/default.php` — fallback sistem (baseline terjemahan kernel)
5. kembalikan kunci apa adanya + telemetry miss

`src/Languages/` adalah direktori **resource** kernel (bukan kelas PHP — dibaca translator,
konsisten dengan pola view internal di D7). Fondasinya sudah ada: `MessageCatalog` +
`ValidationTranslator` (pesan validasi internal) diangkat menjadi lapisan terjemahan sistem
dengan notasi lookup ter-namespace-modul: `trans('blog::post.title')`. Semantik merge:
`array_merge(system, module)` — modul menang untuk kunci duplikat, jadi "helo" milik modul
mengalahkan "helo" milik sistem, dan modul yang tidak punya kunci jatuh langsung ke
terjemahan sistem tanpa biaya tambahan.

Pengamatan kinerja & governance:

- Katalog gabungan per `{module,locale}` di-compile ke `temp/cache/i18n/<module>.<locale>.php`
  (array return murni, opcache-friendly); invalidasi = wipe `temp/` saat deploy — konsisten
  invariant D5.
- Kunci hilang tidak pernah gagal anggun diam-diam: counter `i18n.miss{module,locale}` lewat
  `MeterInterface` + log debug — observability-first.
- Setiap kunci sistem wajib hadir minimal di `src/Languages/default.php`; paritas ini
  dijaga self-test.

### D5 — Kontrak `temp/`: tiga kelas berkas

Taksonomi penempatan seluruh berkas proyek:

| Kelas | Sifat | Contoh | Git | Boleh di-wipe? |
|:------|:------|:-------|:----|:---------------|
| **Source** | tulisan tangan | `src/**`, `Modules/*/…`, `bin/zef`, `generator/**`, `config/` | committed | tidak |
| **Shipped-derived** | di-generate tapi wajib ada di tarball | `autoload/zef_autoload.php` (classmap), docs api | committed, regen oleh tooling rilis | tidak |
| **Runtime-derived** | di-generate saat runtime, regeneratable | `temp/**` | ignored | **ya, kapan pun** |

Hukum `temp/`: **berkas yang tidak bisa diregenerasi dilarang tinggal di `temp/`**.
Wipe kapan pun harus aman; cold start membangun ulang semuanya. Isi yang direncanakan,
semuanya memetakan ke mesin yang sudah ada:

- `temp/cache/` — rumah `FileCacheStore` (adapter baru dari `CacheStoreInterface` yang ada)
- `temp/aot/` — `CompiledContainerPlan` + `ContainerCompiler` + `RouteCache` +
  `ConfigurationSnapshot` (mesin AOT sudah berjalan; `temp/` hanya merumahkan) +
  artefak AOT per-modul produksi (D9)
- `temp/view/` — cache view ter-compile (pasca D7)
- `temp/cache/i18n/` — katalog cascade D4
- `temp/sessions/` — session berbasis berkas (*semi-derived*: wipe = force logout semua —
  diterima dengan catatan, lihat open question di §5)
- `temp/generator/` — sandbox output scaffold (D6)

Keamanan `temp/` tidak boleh ditawar: **di luar docroot** (config ter-compile bisa berisi
`%secret%` yang sudah ter-resolve — vektor kebocoran), path configurable via `ZEF_TEMP_PATH`
(default `<root>/temp`, jangan pernah diarahkan ke `/tmp` bersama sistem), dan `chmod 0700`.

### D6 — `generator/`: engine di-shipped + sandbox promote/GC

Pemisahan yang sering terbalik: **engine** codegen adalah kode rilis — kena mutation gate,
PHPStan, deptrac — dan di-shipped di `generator/` (konsolidasi maker + scaffold + stubs +
classmap tool; `scripts/dev/update_classmap.php` yang yatim diadopsi ke sini, menutup wart
§2.2 butir 2). **Output** codegen adalah artefak sementara yang ditampung di
`temp/generator/`:

```
bin/zef make:handler Produk
        │
        ▼
temp/generator/handler/Produk/        # sandbox: preview, inert, tidak di-boot
        │
   dipakai?                            tidak ──► GC age-based saat boot
        │ yes                                    (default 7 hari, scope temp/generator saja)
        ▼
PROMOTE  = move ke path asli + regen classmap + collision guard + hint registrasi
        │
        ▼
Modules/Katalog/Controllers/…          # repo hanya berisi hasil promosi eksplisit
```

- **Promosi adalah command framework**, bukan `mv` manual: memindahkan berkas,
  me-regen `autoload/zef_autoload.php` (satu-sunya cara classmap berubah), menjalankan
  `ScaffoldCollisionException` sebagai guard tabrakan, dan mencetak hint registrasi.
  Setelah ini, penyuntingan classmap manual (wart §2.2 butir 1) menjadi mustahil secara
  desain.
- **GC tidak pernah wipe-on-boot** (membunuh draf di tengah sesi): age-based scan saat
  boot, default 7 hari, hanya scope `temp/generator/` — cache/AOT/session tidak
  tersentuh — plus `temp:clear` manual.
- **Escape hatch `--here`** untuk yang yakin: menulis langsung ke path asli.
- **`make:app` tetap langsung** — aplikasi standalone adalah sandbox alami yang
  runnable penuh, tidak perlu promosi.
- Sandbox bersifat **preview-only** (rekomendasi; lihat §5): classmap statis menjaga
  filosofi deterministik — yang ingin mencoba jalan sungguhan memakai `make:app`.

### D7 — Views: dua tingkat, privat berdasarkan lokasi

File view adalah **source presentasi**, bukan artefak siap-saji. Kalau web-readable, itu
berarti pengungkapan sumber (logika, variabel, terkadang kredensial template) sekaligus
permukaan template-injection. Laravel, Symfony, dan Rails semuanya menaruh sumber view di
luar docroot; ZEF mengikuti preseden yang sama. Yang diserve adalah **output render**
lewat response pipeline.

1. **View internal kernel** — error/exception/maintenance pages di
   `src/Adapters/Http/views/` (resource, bukan kelas — dibaca renderer). Renderer
   zero-dependency, escape-by-default, substitusi token: cukup untuk halaman error,
   tanpa mesin template. Tie-in content negotiation: satu sumber menghasilkan HTML untuk
   browser dan problem-details JSON (RFC 9457) untuk API. Bonus langsung: ini menutup
   temuan audit lama — `public/index.php` pada `ZEF_DEBUG=1` pernah meng-echo exception
   mentah (class + message); view exception proper berarti escaping + redaction.
2. **View modul** — `Modules/<X>/Views/`, privat berdasarkan lokasi (di luar `public/`),
   output render yang diserve. Bukan janji inti RFC ini — API-first tetap arah utama
   untuk modul — tetapi struktur direktorinya sudah direservasi di D2 supaya tidak
   perlu breaking change kelak.

Upload: default `public/uploads/` (publik langsung); mode privat (stream via controller)
ditangguhkan sampai ada use case nyata — tidak dikremium sekarang.

### D8 — `public/`: docroot murni + publishing aset modul

Docroot hanya berisi domain publik: `index.php`, `assets/`, `uploads/`, dan
`modules/<x>/` — symlink ke `Modules/<X>/public/` yang dibuat saat modul diaktifkan
(pola package-publish). Skenario deploy yang menyajikan docroot ter-isolasi tetap aman:
symlink dibuat ulang oleh proses aktivasi, bukan dikerjakan manual oleh operator.

### D9 — AOT dua-perilaku: resolusi mode sekali di boot, *Production Wins*

AOT tidak lagi satu-perilaku: modul `dev` dimuat dinamis demi kecepatan iterasi, modul
`production` dibekukan demi performa worker persisten. Seluruh keputusan mode diturunkan
dari resolusi yang tunggal, deterministik, dan dikunci saat boot.

**Sumber & normalisasi — satu resolver.** Pembacaan `ZEF_ENV` wajib lewat kontrak
`EnvInterface::readString('ZEF_ENV', 'development')` (jalur pasca-deprecasi facade statis
v2.28.0), bukan `getenv()`/`$_ENV` yang tersebar. Normalisasi alias (`production`, `prod`)
didefinisikan **tepat satu kali** di value object `EnvironmentMode` (kernel), lalu seluruh
pengecek env menjadi konsumennya. Saat ini dua guard membaca env dengan sumber berbeda —
`bin/zef` (tinker) lewat `getenv()`, `ConfigShower` lewat `EnvInterface` — keduanya inline
`strcasecmp`. Tanpa konsolidasi, `ZEF_ENV=prod` akan berarti "production" bagi AOT tetapi
bukan bagi penolakan tinker: inkonsistensi dua-perilaku dari kelas yang sama dengan drift
dokumen yang ditegakkan ratchet release-docs.

**Invariant boot worker persisten.** Mode dibaca **sekali** saat worker boot
(`Bootstrap::createApp()`), dikunci immutable — properti `Application` atau argumen
konstruktor engine AOT — dan tidak pernah dibaca ulang di tengah lifecycle request:
proses PHP hidup lama, membaca ulang env per request berarti overhead sekaligus
non-determinisme state lintas-request (aturan emas runtime persisten ZEF).

**Matriks komposisi — *Production Wins* (yang paling ketat menang):**

| `ZEF_ENV` global ↓ · manifest `mode` → | `dev` | `production` |
|:---|:---|:---|
| `development` | dinamis: scoped autoloader + cache volatil; checkpoint invalidasi = **worker boot** — di `php bin/zef --serve` yang boot per-request, hot-reload gratis | AOT beku per-modul; mismatch fingerprint (hotfix atas modul beku saat develop) → recompile + telemetry warn |
| `production` | **dipaksa perlakuan production** — materialisasi via tooling deploy (warm-up `doctor`: regen classmap + kompilasi + seal); boot memvalidasi fingerprint | AOT immutable; mismatch fingerprint / artefak korup → worker mati `exit(1)` + `AotMismatchException` ke log — **tanpa fallback diam-diam ke dinamis** |

**Autoloader ter-scope untuk zona dev.** Kelas baru yang dijatuhkan di modul `dev`
tidak terdaftar di classmap statis — dan memang seharusnya tidak: classmap hanya
berubah lewat tooling (D6). Selama sebuah modul ber-mode `dev` aktif, kernel memasang
autoloader dinamis ter-scope untuk prefix `Modules\<X>\` saja — memanfaatkan preseden
dua-jalur autoloading (Composer PSR-4 berdampingan classmap statis) yang sudah berjalan
hari ini. Determinisme zero-composer tetap utuh untuk kernel dan seluruh modul
production: keduanya tetap 100% classmap statis.

Sel `production` global + modul `dev` dibaca hati-hati: "dipaksa" berarti kebijakan
kompilasi, bukan sihir — kelas modul `dev` tidak terdaftar di classmap statis, jadi
materialisasinya adalah tanggung jawab tooling deploy (regen classmap + kompilasi +
seal fingerprint). Deploy yang melewatkan tooling akan gagal boot — fail-closed, bukan
degradasi diam-diam. Crash deterministik itu memang permukaan yang diinginkan; `doctor`
ada justru untuk menangkapnya sebelum naik server.

**Komposisi ketat.** Manifest `requires:` yang menunjuk modul ber-mode `dev`, dalam
konteks `ZEF_ENV=production`, melempar `AotCompositionException` saat boot (keluarga
semantik `ModuleDependencyViolationException` yang sudah ada): dependensi eksplisit pada
kode yang penulisnya sendiri menandai belum-stabil adalah kesalahan komposisi, bukan
sekadar masalah artefak deploy — materialisasi bisa saja, tetapi tidak dipercayakan
diam-diam.

**Warm-up deploy.** `bin/zef doctor` / `bin/zef rr:init` memicu kompilasi AOT seluruh
modul enabled + boot smoke: manifest korup, toposort gagal, atau fingerprint tak cocok →
`exit 1` sebelum kode naik server (perluasan langsung perilaku doctor hari ini: "exit 1
hanya bila FAIL").

**Output opcache-friendly.** Artefak `temp/aot/` berbentuk array-return murni / PHP
prosedural ter-wire — pola rumah `RouteCache`, `RadixTreeCache`, `CompiledConfigSource` —
sehingga OPcache PHP 8.4+ menguncinya di memori bersama worker RoadRunner: tanpa I/O
scanning manifest atau parsing atribut per lifecycle aplikasi.

Catatan penamaan: field manifest memakai `mode` (bukan `status`) agar tidak bentrok
semantik dengan `enabled`.

---

## 4. Pohon direktori target

```
zef/
├── bin/zef                  # thin entry
├── src/                     # KERNEL hexagonal + governance beku
│   ├── {Domain,Application,Infrastructure,Adapters,Compat}/
│   ├── Module/              # internal: Core, Health (gaya kernel, D1)
│   ├── Languages/           # resource i18n sistem — cascade level 3-4 (D4)
│   └── Adapters/Http/views/ # view internal kernel — error/exception (D7)
├── Modules/                 # ZONA EKSTERNAL drop zone (D1-D2)
│   └── Blog/{module.php, Configs/, Controllers/, Models/, Views/,
│             Middleware/, Plugins/, Languages/, Migrations/, public/, tests/}
├── generator/               # ENGINE codegen — di-shipped, kena gerbang (D6)
├── temp/                    # RUNTIME-DERIVED — ignored, wipeable (D5)
│   └── {cache/, cache/i18n/, aot/, view/, sessions/, generator/}
├── public/                  # DOCROOT murni (D8)
│   ├── index.php
│   ├── assets/{css,js,img}
│   ├── uploads/
│   └── modules/<name>/      # symlink → Modules/<Name>/public
├── config/                  # konfigurasi aplikasi + overlay modules.php
└── autoload/                # SHIPPED-DERIVED classmap (committed; regen tooling rilis)
```

Status quo `modules/` (Core, Health) dan `plugins/` (Toko) dimigrasikan bertahap sesuai
fase §6: `modules/` → `src/Module/`, `plugins/` → kandidat pertama zona `Modules/`
sebagai modul contoh.

---

## 5. Pertanyaan terbuka

| # | Pertanyaan | Rekomendasi saat ini |
|:--|:-----------|:---------------------|
| 1 | Apakah kernel di-reorg ke `src/Kernel/` (memisahkan composition root dari kode framework)? | ditangguhkan — kosmetik terhadap RFC ini, biar tidak mencampur dua perubahan besar |
| 2 | Tenancy: apakah `Modules/` perlu kesadaran tenant (modul per-tenant)? | defer sampai ada konsumen nyata; jangan dikremium |
| 3 | Modul internal `src/Module/{Core,Health}` ikut struktur MVC atau tetap hexagonal kecil? | hexagonal kecil — asimetri OK karena audiensnya berbeda (D1) |
| 4 | Sandbox scaffold: preview-only atau bootable via dynamic loader debug? | preview-only — classmap statis = determinisme; uji sungguhan lewat `make:app` |
| 5 | `temp/sessions/` semi-derived (wipe = force logout semua) — diterima atau dipindah kelas? | diterima dengan caveat terdokumentasi; catat di runbook deploy |
| 6 | `ZEF_ENV` kosong: default `development` (mengikuti perilaku guard hari ini yang memperlakukan unset = non-production), atau deployment ketit mewajibkan env eksplisit? | default `development` + knob fail-closed `ZEF_REQUIRE_EXPLICIT_ENV` untuk deployment ketat |

---

## 6. Fasing implementasi

Setiap fase **aditif**, nol perilaku lama berubah, dan tetap melewati seluruh gerbang CI.
Urutan disusun supaya wart ditutup paling awal dengan risiko terkecil:

| Fase | Isi | Nilai yang tercapai |
|:-----|:----|:--------------------|
| **1. Fondasi non-breaking** | Taksonomi 3 kelas + kontrak `temp/` (`ZEF_TEMP_PATH`, gitignore, chmod 0700, di luar docroot) + konsolidasi `generator/` (engine + classmap tool) | wart classmap yatim & instruksi dump-autoload tertutup; rumah siap |
| **2. Zona modul** | `Modules/` + manifest `module.php` + discovery topologis + enable/disable + i18n cascade D4 + harness contract-test + profil `deptrac.modules.yaml` + resolusi mode D9 (`EnvironmentMode`, warm-up `doctor`) | janji drop-folder-equals-install hidup; migrasi `plugins/Toko` sebagai modul contoh |
| **3. Sandbox scaffold** | `temp/generator/` + command promote (move + regen classmap + collision guard + hint) + GC age-based | wart dead-path & classmap manual tertutup penuh |
| **4. Permukaan presentasi** | view internal kernel (error/exception/maintenance, escape-by-default) + publish aset modul (symlink) + i18n halaman error | audit exception-mentah tertutup; docroot murni tercapai |

Fase 2 dan 3 bisa berjalan paralel oleh kontributor berbeda; fase 4 bergantung pada
sebagian fase 2 (aktivasi modul) tetapi view internal kernel bisa maju independen.

---

## 7. Hubungan dengan PR terbuka

- **PR #360** (CSRF fail-closed + serializer bounded + deferred gate buffering) — tetap
  berdiri sendiri sebagai perbaikan keamanan; tidak tumpang-tindih dengan RFC ini.
  Empat blokir CI-nya tetap harus diselesaikan sebelum merge (env harness tinker/smoke,
  `tests/fixtures.limit`, badge).
- **PR #361** — superset #360 + draf ADR `docs/TARGET-ARCHITECTURE.md`. Rekomendasi:
  **ditutup** karena hunk kode identik dengan #360 (merge keduanya mustahil), dan draf
  ADR-nya diambil alih dokumen ini. Dari lima titik divergensi ADR tersebut, empat telah
  terresolusi di sini (penempatan modul D1, codegen D6, taksonomi temp/ D5, pivot
  full-stack discope-down menjadi API-first + view internal D7); satu (reorg kernel)
  menjadi pertanyaan terbuka §5 butir 1.

---

## Lampiran A — Warisan zeflous 2020 → v3

| zeflous 2020 | ZEF v3 |
|:-------------|:-------|
| `modules/{auth,forum,homepage,user}` | `Modules/` drop zone (D1) |
| `modules/*/classes/{controllers,models,plugins}` | `Controllers/`, `Models/`, `Plugins/` (D2) |
| `modules/*/components/templates/views/*.tpl` (Smarty) | `Views/` + renderer zero-dep (D7) |
| `modules/*/components/locales/{en,id}/*.lng.php` | `Languages/{en,id}/` + `default.php` (D2) |
| `modules/*/configs/routes.config.php` | `Configs/routes.php` → `ConfigAggregator` |
| `modules/*/assets/{css,js,images}` | `public/` modul → symlink `public/modules/<x>/` (D8) |
| `system/` | `src/` kernel (termasuk `Module/` internal) |
| `system/components/locales/id/default.lng.php` | `src/Languages/` — cascade level 3-4 (D4) |
| `system/components/templates/views/system/ERROR-PAGE.tpl` | `src/Adapters/Http/views/` (D7) |
| `data/cache/` (route cache) | `temp/` (D5) |
| `languages.config.php` (`fallback`, `show missing`) | cascade 5 tingkat + telemetri miss (D4) |
| Router: `LoadConfigFromDir('routes')` scan buta | auto-discovery + manifest + topological sort (D3) |
| `composer` wajib di bootstrap | zero-composer classmap; drop folder = install |

Dependensi 2020 (guzzle/psr7, defuse, pixie, smarty) telah ditulis-ulang in-house oleh
kernel 2026; v3 mengembalikan sistem modul 2020 di atas kernel yang matang — *full circle*.

## Lampiran B — Pemetaan direktori modul → mesin yang sudah ada

| Direktori modul | Mesin ZEF yang sudah berjalan |
|:----------------|:------------------------------|
| `Configs/routes.php` | `ConfigProviderInterface` + `ConfigAggregator` (src/Infrastructure/Config) |
| `Configs/*.php` lain | Config System v2 multi-source + schema fail-fast |
| `Controllers/` | `Dispatcher` + per-route middleware pipeline (target HMVC `ModuleDispatcher`) |
| `Middleware/` | assignmen metadata per-route + eksekusi sub-pipeline |
| `Languages/` | `LocaleNegotiator` (src/Adapters/Router) + port translation store baru (D4) |
| `Models/` | Query Builder + base Repository + PDO adapter |
| `Controllers/` (komposisi widget) | sub-request `ModuleDispatcher` (HMVC) — widget antar-modul, bukan import langsung |
| `Domain/` + `Application/` (blueprint DDD) | `CommandBusInterface`/`QueryBusInterface` (`register()`/`freeze()`) + base `Repository` + Event Sourcing |
| `Plugins/` | kontrak manifest plugin (docs/PLUGINS.md) + scope registrasi parent-aktif |
| `public/` | symlink publish saat aktivasi (D8) + `ETagMiddleware` untuk aset |
| `module.php` | `ModuleDefinition` + `ModuleRegistrar` + `ModuleRegistry` (src/Domain/Config, src/Infrastructure/Config) |
| manifest `mode` + `ZEF_ENV` | `EnvironmentMode` (baru — konsolidasi guard `bin/zef` tinker & `ConfigShower`) + mesin fingerprint existing: `RadixTreeCache` / `RouteCache` / `SpecificationCache` / `CompiledConfigSource` |
| `tests/` | harness contract-test baru (D3) — PHPUnit native |
