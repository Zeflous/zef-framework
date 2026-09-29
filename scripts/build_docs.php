<?php

/**
 * ZEF Framework — static documentation site builder (GitHub Pages).
 *
 *   php scripts/build_docs.php
 *
 * Renders the curated Markdown set under docs/ (plus the root README) into a
 * self-contained static site at build/docs. The Markdown sources are copied
 * alongside the HTML so every page stays downloadable in its original form.
 *
 * The API reference produced by Doctum (build/api) is NOT touched here; the
 * Pages workflow merges it in under build/docs/api so that both the official
 * documentation and the generated API surfaces are served from one root.
 *
 * Markdown -> HTML uses parsedown/parsedown in safe mode, which is already a
 * transitive dependency of code-lts/doctum (require-dev) and therefore needs
 * no change to composer.json / composer.lock.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/vendor/autoload.php';

if (!class_exists(Parsedown::class)) {
    fwrite(STDERR, "parsedown/parsedown is not installed. Run: composer install\n");

    exit(1);
}

/**
 * Curated navigation order for the published documentation.
 *
 * @var array<int, array{slug: string, title: string, source: string, group: string}> $pages
 */
$pages = [
    ['slug' => 'index', 'title' => 'Ikhtisar Dokumentasi', 'source' => 'docs/README.md', 'group' => 'Mulai'],
    ['slug' => 'readme', 'title' => 'README Proyek', 'source' => 'README.md', 'group' => 'Mulai'],
    ['slug' => 'installation', 'title' => 'Instalasi & Konfigurasi', 'source' => 'docs/INSTALLATION.md', 'group' => 'Panduan'],
    ['slug' => 'cli', 'title' => 'Referensi CLI (bin/zef)', 'source' => 'docs/CLI.md', 'group' => 'Panduan'],
    ['slug' => 'architecture', 'title' => 'Arsitektur', 'source' => 'docs/ARCHITECTURE.md', 'group' => 'Panduan'],
    ['slug' => 'quality', 'title' => 'Gerbang Kualitas & Mutation Testing', 'source' => 'docs/QUALITY.md', 'group' => 'Panduan'],
    ['slug' => 'deployment', 'title' => 'Deployment & Operasi', 'source' => 'docs/DEPLOYMENT.md', 'group' => 'Panduan'],
    ['slug' => 'roadmap', 'title' => 'Roadmap', 'source' => 'docs/ROADMAP.md', 'group' => 'Referensi'],
    ['slug' => 'edge-case-matrix', 'title' => 'Edge-Case Matrix', 'source' => 'docs/EDGE-CASE-MATRIX.md', 'group' => 'Referensi'],
    ['slug' => 'php-sast', 'title' => 'PHP SAST', 'source' => 'docs/security/php-sast.md', 'group' => 'Referensi'],
    ['slug' => 'sonarcloud', 'title' => 'SonarCloud', 'source' => 'docs/security/sonarcloud.md', 'group' => 'Referensi'],
];

$outDir = $root . '/build/docs';
$siteTitle = 'ZEF Framework — Dokumentasi Resmi';
$repoUrl = 'https://github.com/Zeflous/zef-framework';
$apiUrl = 'api/';

/** @var array<string, mixed> $meta */
$meta = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$version = 'unknown';

$versionFile = $root . '/src/Domain/Foundation/ZefVersion.php';
if (is_readable($versionFile)) {
    $versionSource = (string) file_get_contents($versionFile);
    if (preg_match('/VERSION\s*=\s*\'([^\']+)\'/', $versionSource, $m) === 1) {
        $version = $m[1];
    }
}

$parsedown = new Parsedown();
$parsedown->setSafeMode(true);
$parsedown->setBreaksEnabled(false);

/** Escape a value for HTML text/attribute context. */
$esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

/**
 * Rewrite relative Markdown links so they resolve inside the built site.
 */
$rewriteLinks = static function (string $html, array $page) use ($pages): string {
    $slugOf = [];
    foreach ($pages as $candidate) {
        $slugOf[$candidate['source']] = $candidate['slug'] . '.html';
    }

    return (string) preg_replace_callback(
        '/href="([^"#][^"]*\.md)(#[^"]*)?"/i',
        static function (array $match) use ($page, $slugOf): string {
            $target = $match[1];
            $fragment = $match[2] ?? '';

            $normalized = $target;
            if (str_starts_with($normalized, './')) {
                $normalized = substr($normalized, 2);
            }

            $baseDir = str_contains($page['source'], '/')
                ? substr($page['source'], 0, (int) strrpos($page['source'], '/'))
                : '';

            $candidates = [$normalized];
            if ($baseDir !== '') {
                $candidates[] = $baseDir . '/' . $normalized;
            }

            foreach ($candidates as $candidate) {
                if (isset($slugOf[$candidate])) {
                    return 'href="' . $slugOf[$candidate] . $fragment . '"';
                }
            }

            return 'href="' . $match[1] . $fragment . '"';
        },
        $html
    );
};

/**
 * Add an id to every h2/h3 so the outline links can target them.
 *
 * @param array<int, array{level: int, text: string, id: string}> $outline
 */
$anchorHeadings = static function (string $html, array &$outline): string {
    $used = [];

    return (string) preg_replace_callback(
        '/<h([23])>(.*?)<\/h\1>/s',
        static function (array $match) use (&$outline, &$used): string {
            $level = (int) $match[1];
            $text = trim(strip_tags($match[2]));
            $base = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $text));
            $base = trim($base, '-');
            if ($base === '') {
                $base = 'section';
            }
            $id = $base;
            $i = 2;
            while (isset($used[$id])) {
                $id = $base . '-' . $i;
                ++$i;
            }
            $used[$id] = true;
            $outline[] = ['level' => $level, 'text' => $text, 'id' => $id];

            return '<h' . $level . ' id="' . $id . '">' . $match[2] . '</h' . $level . '>';
        },
        $html
    );
};

$css = <<<'CSS'
    :root{--bg:#0d1117;--panel:#161b22;--panel2:#1c232c;--border:#30363d;--text:#e6edf3;--muted:#9198a1;--accent:#4493f8;--accent2:#3fb950;--warn:#d29922;--code:#0b0f14;--radius:10px}
    @media (prefers-color-scheme: light){:root{--bg:#ffffff;--panel:#f6f8fa;--panel2:#eef1f4;--border:#d0d7de;--text:#1f2328;--muted:#59636e;--accent:#0969da;--accent2:#1a7f37;--warn:#9a6700;--code:#f6f8fa}}
    *{box-sizing:border-box}
    html{scroll-behavior:smooth}
    body{margin:0;background:var(--bg);color:var(--text);font:16px/1.65 -apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans",Helvetica,Arial,sans-serif}
    a{color:var(--accent);text-decoration:none}
    a:hover{text-decoration:underline}
    .topbar{position:sticky;top:0;z-index:20;display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;padding:12px 22px;background:var(--panel);border-bottom:1px solid var(--border)}
    .brand{display:flex;align-items:baseline;gap:10px;font-weight:700;font-size:17px}
    .brand .tag{font-weight:500;font-size:12px;color:var(--muted);border:1px solid var(--border);border-radius:999px;padding:2px 9px}
    .topbar nav{display:flex;gap:16px;font-size:14px}
    #filter{background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:var(--radius);padding:7px 11px;font-size:14px;min-width:210px}
    .layout{display:grid;grid-template-columns:290px minmax(0,1fr);gap:34px;max-width:1320px;margin:0 auto;padding:26px 22px 60px}
    .sidebar{position:sticky;top:74px;align-self:start;max-height:calc(100vh - 96px);overflow:auto;padding-right:6px;font-size:14.5px}
    .sidebar h3{margin:18px 0 7px;font-size:11px;letter-spacing:.09em;text-transform:uppercase;color:var(--muted)}
    .sidebar h3:first-child{margin-top:0}
    .sidebar ul{list-style:none;margin:0;padding:0}
    .sidebar li a{display:block;padding:6px 10px;border-radius:7px;color:var(--text);border-left:3px solid transparent}
    .sidebar li a:hover{background:var(--panel);text-decoration:none}
    .sidebar li a.active{background:var(--panel2);border-left-color:var(--accent);font-weight:600;color:var(--accent)}
    .outline{margin:26px 0 0;border-top:1px solid var(--border);padding-top:14px}
    .outline a{color:var(--muted);font-size:13px;padding:3px 10px;display:block;border-radius:6px}
    .outline a.lvl3{padding-left:24px}
    .outline a:hover{color:var(--accent);text-decoration:none}
    .content{min-width:0;background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:34px 40px}
    .docmeta{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin:0 0 22px;padding-bottom:16px;border-bottom:1px solid var(--border);font-size:13px;color:var(--muted)}
    .content h1{font-size:31px;line-height:1.25;margin:.2em 0 .6em}
    .content h2{font-size:23px;margin:2em 0 .6em;padding-bottom:.3em;border-bottom:1px solid var(--border)}
    .content h3{font-size:18px;margin:1.6em 0 .5em}
    .content h4{font-size:16px;margin:1.3em 0 .4em}
    .content p{margin:0 0 1em}
    .content ul,.content ol{margin:0 0 1em;padding-left:1.5em}
    .content li{margin:.3em 0}
    .content img{max-width:100%;border-radius:var(--radius)}
    .content blockquote{margin:1em 0;padding:.6em 1.1em;border-left:4px solid var(--accent);background:var(--panel2);border-radius:0 var(--radius) var(--radius) 0;color:var(--text)}
    .content blockquote p:last-child{margin-bottom:0}
    .content table{width:100%;border-collapse:collapse;margin:1.1em 0;font-size:14.5px;display:block;overflow-x:auto}
    .content th,.content td{border:1px solid var(--border);padding:8px 12px;text-align:left;vertical-align:top}
    .content th{background:var(--panel2);font-weight:600;white-space:nowrap}
    .content tr:nth-child(even) td{background:color-mix(in srgb,var(--panel2) 45%,transparent)}
    .content code{background:var(--code);border:1px solid var(--border);border-radius:6px;padding:.14em .42em;font:13.5px/1.5 ui-monospace,SFMono-Regular,"SF Mono",Menlo,Consolas,monospace}
    .content pre{background:var(--code);border:1px solid var(--border);border-radius:var(--radius);padding:15px 17px;overflow-x:auto;margin:1.1em 0}
    .content pre code{background:none;border:0;padding:0;font-size:13.5px}
    .content hr{border:0;border-top:1px solid var(--border);margin:2em 0}
    .content a.anchor-h{color:inherit}
    footer{max-width:1320px;margin:0 auto;padding:0 22px 44px;color:var(--muted);font-size:13px;display:flex;flex-wrap:wrap;gap:8px;justify-content:space-between}
    .pill{display:inline-block;border:1px solid var(--border);border-radius:999px;padding:3px 10px;font-size:12.5px;color:var(--muted)}
    .pill.ok{color:var(--accent2);border-color:color-mix(in srgb,var(--accent2) 45%,var(--border))}
    @media(max-width:900px){.layout{grid-template-columns:1fr;gap:20px}.sidebar{position:static;max-height:none}.content{padding:22px 18px}.topbar nav{display:none}}
    CSS;

$js = <<<'JS'
    (function () {
      var filter = document.getElementById('filter');
      var sidebar = document.querySelector('.sidebar');
      if (filter && sidebar) {
        filter.addEventListener('input', function () {
          var q = filter.value.toLowerCase().trim();
          sidebar.querySelectorAll('li').forEach(function (li) {
            var text = li.textContent.toLowerCase();
            li.style.display = (!q || text.indexOf(q) !== -1) ? '' : 'none';
          });
          sidebar.querySelectorAll('h3').forEach(function (h) {
            var list = h.nextElementSibling;
            if (!list) { return; }
            var any = Array.prototype.some.call(list.querySelectorAll('li'), function (li) {
              return li.style.display !== 'none';
            });
            h.style.display = any ? '' : 'none';
          });
        });
      }

      var headings = Array.prototype.slice.call(document.querySelectorAll('.content h2, .content h3'));
      if (!headings.length) { return; }

      var links = Array.prototype.map.call(document.querySelectorAll('.outline a'), function (a) {
        return { a: a, el: document.getElementById(a.getAttribute('href').slice(1)) };
      }).filter(function (x) { return x.el; });

      if (!('IntersectionObserver' in window)) { return; }

      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) { return; }
          links.forEach(function (x) {
            var on = x.el === entry.target;
            x.a.style.color = on ? 'var(--accent)' : '';
            x.a.style.fontWeight = on ? '600' : '';
          });
        });
      }, { rootMargin: '-70px 0px -75% 0px', threshold: 0 });

      headings.forEach(function (h) { observer.observe(h); });
    })();
    JS;

if (!is_dir($outDir) && !mkdir($outDir, 0o755, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create {$outDir}\n");

    exit(1);
}
$assetsDir = $outDir . '/assets';
if (!is_dir($assetsDir) && !mkdir($assetsDir, 0o755, true) && !is_dir($assetsDir)) {
    fwrite(STDERR, "Cannot create {$outDir}/assets\n");

    exit(1);
}

file_put_contents($outDir . '/assets/style.css', $css);
file_put_contents($outDir . '/assets/docs.js', $js);

$buildStamp = gmdate('Y-m-d');
$rendered = 0;
$skipped = [];

/**
 * Render the sidebar navigation for one page.
 *
 * @param array<int, array{slug: string, title: string, source: string, group: string}> $pages
 * @param array<int, array{level: int, text: string, id: string}> $outline
 */
function navHtml(array $pages, string $currentSlug, string $apiUrl, array $outline, callable $esc): string
{
    $groups = [];
    foreach ($pages as $page) {
        $groups[$page['group']][] = $page;
    }

    $html = '';
    foreach ($groups as $group => $groupPages) {
        $html .= '<h3>' . $esc((string) $group) . '</h3><ul>';
        foreach ($groupPages as $page) {
            $class = $page['slug'] === $currentSlug ? ' class="active"' : '';
            $html .= '<li><a' . $class . ' href="' . $esc($page['slug']) . '.html">'
                . $esc($page['title']) . '</a></li>';
        }
        $html .= '</ul>';
    }

    $html .= '<h3>API</h3><ul>';
    $html .= '<li><a href="' . $esc($apiUrl) . '">API Reference (Doctum)</a></li>';
    $html .= '</ul>';

    if ($outline !== []) {
        $html .= '<div class="outline"><h3>Di halaman ini</h3>';
        foreach ($outline as $item) {
            $cls = $item['level'] === 3 ? ' class="lvl3"' : '';
            $html .= '<a' . $cls . ' href="#' . $esc($item['id']) . '">' . $esc($item['text']) . '</a>';
        }
        $html .= '</div>';
    }

    return $html;
}

foreach ($pages as $page) {
    $sourcePath = $root . '/' . $page['source'];
    if (!is_readable($sourcePath)) {
        $skipped[] = $page['source'];

        continue;
    }

    $markdown = (string) file_get_contents($sourcePath);
    $body = $parsedown->text($markdown);
    $body = $rewriteLinks($body, $page);

    /** @var array<int, array{level: int, text: string, id: string}> $outline */
    $outline = [];
    $body = $anchorHeadings($body, $outline);

    $sidebar = navHtml($pages, $page['slug'], $apiUrl, $outline, $esc);
    $title = $page['title'];

    $html = <<<HTML
        <!DOCTYPE html>
        <html lang="id">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$esc($title)} — {$esc($siteTitle)}</title>
        <meta name="description" content="Dokumentasi resmi ZEF Framework (Zeflous/zef-framework) versi {$esc($version)}.">
        <link rel="stylesheet" href="assets/style.css">
        </head>
        <body>
        <header class="topbar">
          <div class="brand">ZEF Framework <span class="tag">v{$esc($version)}</span> <span class="tag">Dokumentasi</span></div>
          <nav>
            <a href="index.html">Ikhtisar</a>
            <a href="installation.html">Instalasi</a>
            <a href="cli.html">CLI</a>
            <a href="quality.html">Kualitas</a>
            <a href="{$esc($apiUrl)}">API</a>
            <a href="{$esc($repoUrl)}">GitHub</a>
          </nav>
          <input id="filter" type="search" placeholder="Cari halaman…" aria-label="Cari halaman dokumentasi">
        </header>
        <div class="layout">
          <aside class="sidebar">{$sidebar}</aside>
          <main class="content">
            <div class="docmeta">
              <span>Sumber: <code>{$esc($page['source'])}</code></span>
              <span>Diperbarui: {$esc($buildStamp)} · Build otomatis (GitHub Actions)</span>
            </div>
        {$body}
          </main>
        </div>
        <footer>
          <span>ZEF Framework v{$esc($version)} — dokumentasi resmi. Dibangun otomatis dari <code>docs/</code>.</span>
          <span><span class="pill">PHP &gt;= 8.4</span> <span class="pill ok">RoadRunner v2025.1.15</span></span>
        </footer>
        <script src="assets/docs.js"></script>
        </body>
        </html>
        HTML;

    file_put_contents($outDir . '/' . $page['slug'] . '.html', $html);

    // Keep the original Markdown downloadable from the published site.
    $rawDir = $outDir . '/raw';
    if (!is_dir($rawDir)) {
        mkdir($rawDir, 0o755, true);
    }
    file_put_contents($rawDir . '/' . $page['slug'] . '.md', $markdown);

    ++$rendered;
    echo "  + {$page['slug']}.html  <- {$page['source']}\n";
}

$indexSource = (string) file_get_contents($root . '/docs/README.md');
echo "\nRendered {$rendered} page(s) into build/docs.\n";
if ($skipped !== []) {
    echo 'Skipped (missing): ' . implode(', ', $skipped) . "\n";
}
if ($indexSource === '') {
    fwrite(STDERR, "docs/README.md is empty\n");

    exit(1);
}
