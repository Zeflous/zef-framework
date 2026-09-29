<?php

/**
 * Menambahkan entri classmap baru ke autoload/zef_autoload.php secara
 * otomatis (scan src/, bandingkan dengan peta eksisting, sisipkan
 * terurut alfabetis). Dipakai saat menambah paket kelas baru.
 *
 * Usage: php scripts/dev/update_classmap.php [--apply]
 */
declare(strict_types=1);

$autoloadPath = __DIR__ . '/../../autoload/zef_autoload.php';
$source = (string) file_get_contents($autoloadPath);
$root = dirname(dirname($autoloadPath));

// 1. Scan semua class/interface di src/ → [fqcn => relative path]
$map = [];
$it = new CallbackFilterIterator(
    new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)),
    static fn (SplFileInfo $f): bool => $f->getExtension() === 'php',
);
$itTests = new CallbackFilterIterator(
    new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/tests', FilesystemIterator::SKIP_DOTS)),
    static fn (SplFileInfo $f): bool => $f->getExtension() === 'php',
);
foreach (array_merge(iterator_to_array($it), iterator_to_array($itTests)) as $file) {
    $src = (string) file_get_contents($file->getPathname());
    if (preg_match('/^namespace\s+([^;]+);/m', $src, $ns) !== 1) {
        continue;
    }
    $namespace = trim($ns[1]);
    $rel = ltrim(str_replace([$root . '/', $root . DIRECTORY_SEPARATOR], '', $file->getPathname()), '/');
    foreach ([ '/^\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', '/^\s*interface\s+(\w+)/m', '/^\s*enum\s+(\w+)/m' ] as $pattern) {
        if (preg_match_all($pattern, $src, $found) > 0) {
            foreach ($found[1] as $name) {
                $map[$namespace . '\\' . $name] = $rel;
            }
        }
    }
}

// 2. Parse entri eksisting dari file: normalized fqcn => nomor baris
$lines = explode("\n", $source);
$existing = []; // fqcn (normalized) => line index
foreach ($lines as $index => $line) {
    if (preg_match('/^\s*"([\w\\\\]+)"\s*=>\s*__DIR__/', $line, $m) === 1) {
        $fqcn = str_replace('\\\\', '\\', $m[1]);
        $existing[$fqcn] = $index;
    }
}

// 3. Tentukan entri baru
$new = [];
foreach ($map as $class => $rel) {
    if (!isset($existing[$class])) {
        $new[$class] = $rel;
    }
}

echo 'src classes: ', count($map), "\n";
echo 'existing map: ', count($existing), "\n";
echo 'new entries: ', count($new), "\n";
foreach ($new as $class => $rel) {
    echo "  + $class => $rel\n";
}

if (($argv[1] ?? '') !== '--apply' || $new === []) {
    exit(0);
}

// 4. Bangun baris baru dan sisipkan sebelum entri eksisting pertama yang
//    secara alfabetis lebih besar (case-insensitive) — forward pass.
uasort($new, fn ($a, $b) => 0); // keep order
$newSorted = $new;
uksort($newSorted, 'strcasecmp'); // case-insensitive alphabetical

$insertions = []; // line index => list of lines to insert before
foreach ($newSorted as $class => $rel) {
    $newLine = '                "' . str_replace('\\', '\\\\', $class) . '" => __DIR__ . \'/../' . $rel . '\',';
    $target = null;
    foreach ($existing as $fqcn => $index) {
        if (strcasecmp($fqcn, $class) > 0 && ($target === null || $index < $target)) {
            $target = $index;
        }
    }
    $insertions[$target ?? count($lines)][] = $newLine;
    $existing[$class] = $target ?? count($lines); // izinkan rantai sisipan
}

$out = [];
foreach ($lines as $index => $line) {
    foreach ($insertions[$index] ?? [] as $ins) {
        $out[] = $ins;
    }
    $out[] = $line;
}
// entri tanpa target (lebih besar dari semua eksisting) → sebelum baris peta penutup
$tail = $insertions[count($lines)] ?? [];
if ($tail !== []) {
    // sisipkan sebelum baris "Zef\\Test\\CliRunner" (entri test pertama)
    foreach ($out as $index => $line) {
        if (str_contains($line, '"Zef\\\\Test\\\\')) {
            array_splice($out, $index, 0, $tail);
            break;
        }
    }
}

file_put_contents($autoloadPath, implode("\n", $out));
echo "classmap diperbarui\n";
