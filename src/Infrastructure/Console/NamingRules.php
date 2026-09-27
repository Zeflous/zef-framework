<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.16.0 — Infrastructure layer (outbound adapters).
 * ZEF Maker: pure naming rules. Validation + identifier conversions shared
 * by every generator. Kept side-effect free so it is trivially testable
 * and a dense mutation target.
 */

namespace Zef\Framework\Console;

final class NamingRules
{
    /**
     * PHP reserved words that cannot be used as class names. Stored
     * case-insensitively: `list` and `LIST` are equally fatal at runtime.
     */
    private const array RESERVED_CLASS_WORDS = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch',
        'class', 'clone', 'const', 'continue', 'declare', 'default', 'do', 'echo',
        'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif',
        'endswitch', 'endwhile', 'enum', 'extends', 'final', 'finally', 'fn',
        'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements',
        'include', 'include_once', 'instanceof', 'insteadof', 'interface',
        'isset', 'list', 'match', 'mixed', 'namespace', 'new', 'never', 'or',
        'parent', 'print', 'private', 'protected', 'public', 'readonly',
        'require', 'require_once', 'return', 'self', 'static', 'switch', 'throw',
        'trait', 'try', 'unset', 'use', 'var', 'while', 'xor', 'yield', 'true',
        'false', 'null',
    ];

    public static function className(?string $raw, string $label = 'class name'): string
    {
        if ($raw === null || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $raw) !== 1) {
            // The `?? ''` is a no-op for string rendering (null concat == '');
            // kept for explicitness. @infection-ignore-all
            throw new InvalidNameException(
                "Invalid {$label} '" . ($raw ?? '') . "'. Expected [A-Za-z_][A-Za-z0-9_]*.",
            );
        }
        if (in_array(strtolower($raw), self::RESERVED_CLASS_WORDS, true)) {
            throw new InvalidNameException(
                "{$label} '{$raw}' is a PHP reserved word. Choose a different name.",
            );
        }

        return $raw;
    }

    public static function moduleName(?string $raw): string
    {
        $name = strtolower(trim((string) $raw));

        if ($name === '' || preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $name) !== 1) {
            // The `?? ''` is a no-op for string rendering (null concat == '');
            // kept for explicitness. @infection-ignore-all
            throw new InvalidNameException(
                "Invalid module name '" . ($raw ?? '') . "'. Expected [a-z][a-z0-9_-]{0,31}.",
            );
        }

        return $name;
    }

    public static function pascal(string $snake): string
    {
        return str_replace('-', '', ucwords($snake, '_-'));
    }

    public static function snake(string $pascal): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $pascal));
    }

    public static function kebab(string $pascal): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $pascal));
    }
}
