<?php

/**
 * ZEF Framework — PHP-CS-Fixer configuration (composer format / format:check).
 *
 * v2.13.0 hardening: PER-CS 2.0 + Symfony + the full PhpCsFixer community
 * ruleset + PHP 8.4 migration rules, with risky rules enabled (strict_types
 * enforcement). src/Compat is excluded: those PSR shims are byte-stable
 * artifacts of the v2.7.0 extraction and must not be reformatted.
 */

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/modules',
        __DIR__ . '/plugins',
        __DIR__ . '/app',
        __DIR__ . '/tests',
    ])
    ->exclude('Compat')
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@Symfony' => true,
        '@PhpCsFixer' => true,
        '@PHP84Migration' => true,
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'no_useless_else' => true,
        'no_superfluous_elseif' => true,
        'php_unit_data_provider_static' => true,
        'php_unit_test_annotation' => ['style' => 'prefix'],
        // This fixer (part of @Symfony) adds @coversNothing class docblocks,
        // which silently disables per-test code coverage collection in
        // PHPUnit — the exact opposite of this repository's coverage goals.
        'php_unit_test_class_requires_covers' => false,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha', 'imports_order' => ['class', 'function', 'const']],
        'no_unused_imports' => true,
        'explicit_string_variable' => false,
        'concat_space' => ['spacing' => 'one'],
        'yoda_style' => false,
        'phpdoc_align' => false,
        'php_unit_method_casing' => ['case' => 'camel_case'],
    ])
    ->setFinder($finder);
