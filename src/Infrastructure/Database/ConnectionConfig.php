<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Infrastructure layer: outbound adapters)
 * Added in v2.18.0 (Database Core: query builder, PDO adapter, migrations).
 */

namespace Zef\Framework\Database;

/**
 * Strictly validated connection configuration value object.
 *
 * Every field is checked in {@see self::fromArray()} so misconfiguration
 * surfaces at construction time with an exact message instead of deep
 * inside a driver connect call. Supported drivers: mysql, pgsql, sqlite.
 */
final readonly class ConnectionConfig
{
    public const array DRIVERS = ['mysql', 'pgsql', 'sqlite'];

    private const int DEFAULT_MYSQL_PORT = 3306;
    private const int DEFAULT_PGSQL_PORT = 5432;

    /**
     * @param array<string, mixed> $options
     */
    private function __construct(
        public string $driver,
        public ?string $host,
        public ?int $port,
        public string $dbname,
        public ?string $user,
        public ?string $password,
        public ?string $charset,
        public array $options,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $driver = self::assertDriver($config);
        $dbname = self::assertDbname($config, $driver);
        $host = $driver === 'sqlite' ? null : self::assertHost($config, $driver);
        $port = $driver === 'sqlite' ? null : self::normalizePort($config['port'] ?? null, $driver);
        $user = self::assertOptionalString($config, 'user', 'User');
        $password = self::assertOptionalString($config, 'password', 'Password');
        $charset = self::assertCharset($config, $driver);
        $options = self::assertOptions($config);

        return new self($driver, $host, $port, $dbname, $user, $password, $charset, $options);
    }

    public function dsn(): string
    {
        return match ($this->driver) {
            'mysql' => 'mysql:host=' . $this->host . ';port=' . $this->port
                . ';dbname=' . $this->dbname . ($this->charset !== null ? ';charset=' . $this->charset : ''),
            'pgsql' => 'pgsql:host=' . $this->host . ';port=' . $this->port
                . ';dbname=' . $this->dbname
                . ($this->charset !== null ? ';options=\'--client_encoding=' . $this->charset . '\'' : ''),
            'sqlite' => 'sqlite:' . $this->dbname,
            default => throw new ConnectionException("Unsupported database driver '{$this->driver}'."),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function assertDriver(array $config): string
    {
        $driver = $config['driver'] ?? null;
        if (!is_string($driver) || !in_array($driver, self::DRIVERS, true)) {
            throw new ConnectionException(
                "Unknown database driver '" . (is_scalar($driver) ? (string) $driver : get_debug_type($driver))
                . "' (allowed: " . implode(', ', self::DRIVERS) . ').',
            );
        }

        return $driver;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function assertDbname(array $config, string $driver): string
    {
        $dbname = $config['dbname'] ?? null;
        if (!is_string($dbname) || $dbname === '') {
            throw new ConnectionException("Database name (dbname) must be a non-empty string for driver '{$driver}'.");
        }
        if ($driver === 'sqlite' && $dbname !== ':memory:' && preg_match('/^[\x20-\x7E]{1,4096}$/', $dbname) !== 1) {
            throw new ConnectionException(
                "SQLite database path must be ':memory:' or printable ASCII (got non-printable input).",
            );
        }

        return $dbname;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function assertHost(array $config, string $driver): string
    {
        $host = $config['host'] ?? null;
        if (!is_string($host) || $host === '' || strlen($host) > 255 || preg_match('/^\S+$/u', $host) !== 1) {
            throw new ConnectionException(
                "Host must be a non-empty string without whitespace for driver '{$driver}'.",
            );
        }

        return $host;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function assertOptionalString(array $config, string $key, string $label): ?string
    {
        $value = $config[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new ConnectionException("{$label} must be a string or null.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function assertCharset(array $config, string $driver): ?string
    {
        $charset = $config['charset'] ?? null;
        if ($charset !== null && !is_string($charset)) {
            throw new ConnectionException('Charset must be a string or null.');
        }
        if ($charset === null && $driver === 'mysql') {
            return 'utf8mb4';
        }

        return $charset;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function assertOptions(array $config): array
    {
        $options = $config['options'] ?? [];
        if (!is_array($options)) {
            throw new ConnectionException('Options must be an array.');
        }

        return self::normalizeOptions($options);
    }

    private static function normalizePort(mixed $port, string $driver): int
    {
        if ($port === null) {
            return $driver === 'mysql' ? self::DEFAULT_MYSQL_PORT : self::DEFAULT_PGSQL_PORT;
        }
        if (is_int($port)) {
            $value = $port;
        } elseif (is_string($port) && preg_match('/^\d{1,5}$/', $port) === 1) {
            $value = (int) $port;
        } else {
            throw new ConnectionException('Port must be an int or a numeric string (1-65535).');
        }
        if ($value < 1 || $value > 65535) {
            throw new ConnectionException("Port {$value} is out of range (1-65535).");
        }

        return $value;
    }

    /**
     * @param array<mixed, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function normalizeOptions(array $options): array
    {
        $known = ['persistent', 'timeout'];
        $normalized = [];
        foreach ($options as $key => $value) {
            if (!is_string($key) || !in_array($key, $known, true)) {
                throw new ConnectionException(
                    "Unknown connection option '" . (is_string($key) ? $key : get_debug_type($key))
                    . "' (allowed: " . implode(', ', $known) . ').',
                );
            }
            $normalized[$key] = self::normalizeOptionValue($key, $value);
        }

        return $normalized;
    }

    private static function normalizeOptionValue(string $key, mixed $value): mixed
    {
        if ($key === 'persistent') {
            return self::normalizePersistentOption($value);
        }

        return self::normalizeTimeoutOption($value);
    }

    private static function normalizePersistentOption(mixed $value): mixed
    {
        if (!is_bool($value)) {
            throw new ConnectionException('Option persistent must be a bool.');
        }

        return $value;
    }

    private static function normalizeTimeoutOption(mixed $value): mixed
    {
        if (!is_int($value) && !is_float($value)) {
            throw new ConnectionException('Option timeout must be an int or float.');
        }
        if ((is_float($value) ? $value : (float) $value) <= 0.0) {
            throw new ConnectionException('Option timeout must be greater than zero.');
        }

        return $value;
    }
}
