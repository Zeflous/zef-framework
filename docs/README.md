# ZEF Framework — Official Documentation

Dokumentasi resmi **ZEF Framework** (`Zeflous/zef-framework`) — framework PHP 8.4
berarsitektur *Hexagonal (Ports & Adapters)* dengan worker RoadRunner.

> **API reference (generated):** <https://zeflous.github.io/zef-framework/>
> — dibangun otomatis oleh Doctum pada setiap push ke `main`.

---

## Daftar Isi

| Dokumen | Isi | Untuk siapa |
|---------|-----|-------------|
| [`INSTALLATION.md`](INSTALLATION.md) | Persyaratan, instalasi (Composer & zero-composer), RoadRunner, Docker, Kubernetes, variabel lingkungan | Operator, developer baru |
| [`CLI.md`](CLI.md) | Referensi lengkap `bin/zef` — self-test, serve, inspector, 11 generator `make:*`, `openapi:generate`, `rr:init`/`doctor`, tinker, `queue:*`, `outbox:work` | Developer sehari-hari |
| [`TUTORIAL-CQRS-101.md`](TUTORIAL-CQRS-101.md) | **v2.29.0** — Zero-to-Hero: `make:app` → modul → command/query → wiring bus → HTTP/RoadRunner, plus troubleshooting | Developer baru |
| [`PLUGINS.md`](PLUGINS.md) | **v2.29.0** — kontrak manifest plugin, registrasi composition root, kriteria registry index, distribusi | Author plugin, maintainer |
| [`INTEGRATIONS.md`](INTEGRATIONS.md) | **v2.30.0** — peta port × adapter ekosistem (object storage S3/Local, transport pesan, job queue PDO), resep kontributor adapter broker, jembatan Cycle ORM, matriks kompatibilitas | Integrator, kontributor adapter |
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | Pemecahan monolith → layer hexagonal, aturan arah dependensi, peta namespace → layer, autoloading ganda, evolusi v2.12.0–v2.34.1 | Arsitek, reviewer |
| [`QUALITY.md`](QUALITY.md) | Seluruh gerbang kualitas: PHPUnit, coverage, **mutation testing (MSI per area)**, PHPStan, PHPCS, cs-fixer, Rector, Deptrac | Kontributor, release manager |
| [`GOVERNANCE.md`](GOVERNANCE.md) | **v2.31.0+** — tabel kanonik 13 konteks gate, jumlah required checks, protokol rilis & sinkronisasi dokumen | Maintainer, release manager |
| [`DEPLOYMENT.md`](DEPLOYMENT.md) | Pola produksi: RoadRunner (persistent worker), graceful shutdown, observabilitas, health probe, keamanan | SRE, DevOps |
| [`ROADMAP.md`](ROADMAP.md) | Rencana kerja dan status fitur | Product, kontributor |
| [`RFC-0001-module-layout.md`](RFC-0001-module-layout.md) | **DRAFT — fokus berjalan** — layout target v3 & sistem modul plug-n-play: dua zona modul, MVC klasik, firewall governance, i18n cascade 5 tingkat, kontrak `temp/`, `generator/` + sandbox promote/GC, views dua tingkat, AOT dua-perilaku dev/production | Arsitek, kontributor, penulis modul |
| [`RFC-0002-async-widget-resolver.md`](RFC-0002-async-widget-resolver.md) | **DRAFT** — komposisi widget HMVC konkuren di atas async runtime native: kontrak resolver + DTO, guard query-only runtime, deadline kooperatif per widget, matriks paritas sekuensial-konkuren & batas kegagalan | Arsitek, penulis modul |
| [`EDGE-CASE-MATRIX.md`](EDGE-CASE-MATRIX.md) | Kurikulum uji edge-case per fase kampanye mutasi | QA, kontributor |
| [`OPENAPI-GATE-PARITY.md`](OPENAPI-GATE-PARITY.md) | **v2.33.0** — kontrak paritas 12 batas runtime gate OpenAPI (B1–B12) + wire-up + non-goals | Kontributor API, QA |
| [`TRANSACTION-HOOKS.md`](TRANSACTION-HOOKS.md) | **v2.22.0** — orkestrasi transaksi & UoW-lite: scope terkelola, hook after-commit, command bus transaksional | Developer aplikasi |
| [`JOB-QUEUE-PARITY.md`](JOB-QUEUE-PARITY.md) | **v2.34.1** — matriks paritas antrean job Redis Streams × PDO + reproduksi bug padNano | Kontributor job queue |
| [`WIKI-INDEX.md`](WIKI-INDEX.md) | Cara kerja mirror indeks API ke GitHub Wiki (manual, opt-in, double-gated) | Maintainer |
| `CHANGELOG-v*.md` | Catatan rilis per versi (append-only) | Semua |

## Rujukan cepat

```bash
# tanpa Composer (zero-composer fallback)
php bin/zef --self-test            # 501 assertion self-test
php bin/zef --serve 0.0.0.0:8080   # server HTTP pengembangan

# dengan Composer
composer install
composer test                      # suite PHPUnit native
composer mutation                  # kampanye mutasi Infection (gate MSI)
composer stan                      # PHPStan level max + strict-rules
composer deptrac                   # konformansi arsitektur hexagonal
composer docs                      # bangun API reference -> build/api
```

## Struktur dokumentasi ini

```
docs/
├── README.md            ← Anda di sini (indeks)
├── INSTALLATION.md
├── CLI.md
├── TUTORIAL-CQRS-101.md
├── PLUGINS.md
├── INTEGRATIONS.md
├── ARCHITECTURE.md
├── QUALITY.md
├── GOVERNANCE.md
├── DEPLOYMENT.md
├── ROADMAP.md
├── RFC-0001-module-layout.md   ← DRAFT: layout target v3 (fokus berjalan)
├── RFC-0002-async-widget-resolver.md   ← DRAFT: komposisi widget konkuren (HMVC + async runtime)
├── EDGE-CASE-MATRIX.md
├── OPENAPI-GATE-PARITY.md
├── TRANSACTION-HOOKS.md
├── JOB-QUEUE-PARITY.md
├── WIKI-INDEX.md
├── security/
│   ├── php-sast.md
│   ├── sonarcloud.md
│   ├── stub-prescan.md
│   ├── snyk-security.md
│   └── code-scanning-issues.md
├── CHANGELOG-v2.7.0.md … CHANGELOG-v2.34.1.md
└── CHANGELOG-v2.35.0.md   (rilis terbaru)
```

## Konvensi dokumen

- **Bahasa:** dokumen kanal `docs/` memakai Bahasa Indonesia (bahasa kerja proyek),
  dengan istilah teknis dipertahankan dalam bahasa Inggris.
- **Sumber kebenaran:** kode dan gate CI adalah sumber kebenaran; dokumen ini
  merangkum, tidak menggantikan. Setiap angka pada dokumen quality berasal dari
  eksekusi nyata dan dicatat beserta artefak buktinya.
- **Append-only:** `docs/CHANGELOG-v*.md` tidak pernah ditulis ulang; koreksi
  ditambahkan sebagai berkas versi baru.
