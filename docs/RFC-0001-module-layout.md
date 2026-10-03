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

Delapan keputusan inti:

| # | Keputusan | Inti |
|:--|:----------|:-----|
| **D1** | Dua zona modul | `src/Module/` internal (release bareng kernel, governance penuh) vs `Modules/` eksternal (drop zone plug-n-play) |
| **D2** | Anatomi modul eksternal | MVC klasik ala Kohana: manifest + `Controllers/`, `Models/`, `Views/`, `Configs/`, `Languages/`, `Middleware/`, `Plugins/`, `Migrations/`, `public/`, `tests/` |
| **D3** | Firewall governance | Kernel tetap hexagonal; `Modules/` berada di luar graf deptrac; mutu modul ditegakkan contract-test, bukan baseline kernel |
| **D4** | i18n cascade 5 tingkat | *nearest-wins*: locale modul → default modul → locale sistem → default sistem → kembalikan kunci + telemetri |
| **D5** | Kontrak `temp/` | Hanya artefak turunan runtime; apa yang tak bisa diregenerasi dilarang tinggal di `temp/`; aman di-wipe kapan pun |
| **D6** | `generator/` + sandbox | Mesin codegen di-shipped sebagai kode rilis; output `make:*` ditampung di `temp/generator/`, di-*promote* bila dipakai, di-GC bila tidak |
| **D7** | Views dua tingkat | View internal kernel (error/exception/maintenance) + view modul privat berdasarkan lokasi — file view tidak pernah web-readable |
| **D8** | `public/` docroot murni | Aset & upload publik + publish aset modul via symlink `public/modules/<x>/` |

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
    ├── module.php            # manifest: id, versi, requires, provides, namespace, enabled
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

### D3 — Firewall governance: kernel hexagonal, modul MVC

deptrac kernel **tidak** meng-graph `Modules/` — folder itu berada di luar peta layer.
Sebagai gantinya, mutu modul ditegakkan tiga lapis: (1) validasi manifest saat discovery
(id unik, `requires`/`provides` terpenuhi setelah pengurutan topologis, tabrakan kapabilitas
ditolak); (2) contract-test yang dijalankan terhadap modul aktif — modul yang gagal kontrak
tidak dimuat di produksi; (3) modul hanya boleh bergantung pada **permukaan publik kernel**
(namespace kontrak), bukan detail internal. Sebuah modul boleh menyentuh `Domain\…Interface`,
tidak boleh menyentuh `Infrastructure\…` milik kernel langsung. Aturan ini kelak ditegakkan
statis oleh deptrac profil terpisah milik zona modul.

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
  `ConfigurationSnapshot` (mesin AOT sudah berjalan; `temp/` hanya merumahkan)
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

---

## 6. Fasing implementasi

Setiap fase **aditif**, nol perilaku lama berubah, dan tetap melewati seluruh gerbang CI.
Urutan disusun supaya wart ditutup paling awal dengan risiko terkecil:

| Fase | Isi | Nilai yang tercapai |
|:-----|:----|:--------------------|
| **1. Fondasi non-breaking** | Taksonomi 3 kelas + kontrak `temp/` (`ZEF_TEMP_PATH`, gitignore, chmod 0700, di luar docroot) + konsolidasi `generator/` (engine + classmap tool) | wart classmap yatim & instruksi dump-autoload tertutup; rumah siap |
| **2. Zona modul** | `Modules/` + manifest `module.php` + discovery topologis + enable/disable + i18n cascade D4 + harness contract-test | janji drop-folder-equals-install hidup; migrasi `plugins/Toko` sebagai modul contoh |
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
| `Plugins/` | kontrak manifest plugin (docs/PLUGINS.md) + scope registrasi parent-aktif |
| `public/` | symlink publish saat aktivasi (D8) + `ETagMiddleware` untuk aset |
| `module.php` | `ModuleDefinition` + `ModuleRegistrar` + `ModuleRegistry` (src/Domain/Config, src/Infrastructure/Config) |
| `tests/` | harness contract-test baru (D3) — PHPUnit native |
