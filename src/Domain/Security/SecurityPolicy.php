<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security;

use Psr\Log\LoggerInterface;
use Zef\Framework\Foundation\Env;
use Zef\Framework\Foundation\EnvInterface;

final readonly class SecurityPolicy
{
    /**
     * @var list<string>
     */
    public array $allowedOrigins;

    /** @param list<string> $allowedOrigins */
    public function __construct(
        public bool $rateLimitEnabled = false,
        public int $rateLimitMaxRequests = 100,
        public int $rateLimitWindowSeconds = 60,
        public int $rateLimitMaxKeys = 10000,
        public bool $csrfEnabled = true,
        public string $csrfSecret = '',
        public string $csrfCookieName = 'ZEF-XSRF-TOKEN',
        public string $csrfHeaderName = 'X-CSRF-Token',
        public bool $csrfSecureCookie = true,
        public bool $csrfHttpOnlyCookie = true,
        public string $csrfSameSite = 'Strict',
        array $allowedOrigins = [],
        public bool $originEnabled = false,
        public int $csrfTokenBytes = 32,
        public int $csrfTokenTtlSeconds = 0,
        public bool $csrfSpaMode = false,
    ) {
        if ($this->csrfTokenBytes < 16) {
            throw new \InvalidArgumentException('csrfTokenBytes must be >= 16.');
        }
        $this->assertRateLimitBounds(
            $this->rateLimitMaxRequests,
            $this->rateLimitWindowSeconds,
            $this->rateLimitMaxKeys,
        );
        $this->assertCsrfSecretStrength($this->csrfEnabled, $this->csrfSecret);
        $this->assertCsrfTokenNames($this->csrfCookieName, $this->csrfHeaderName);
        $this->assertCsrfCookiePolicy($this->csrfSameSite, $this->csrfSecureCookie, $this->csrfTokenTtlSeconds);
        $this->assertCsrfSpaMode($this->csrfSpaMode, $this->csrfEnabled, $this->csrfHttpOnlyCookie);
        $this->allowedOrigins = $this->normalizeOrigins($allowedOrigins);
        $this->assertOriginPolicy($this->originEnabled, $this->allowedOrigins);
    }

    /**
     * Bug fix #17: error_log routed through LoggerInterface where reachable.
     *
     * Issue #55 step 3 (Domain module): every env read flows through the
     * EnvInterface port — the static facade is gone from this file. The
     * parameter is optional and defaults to the concrete Env, so existing
     * callers keep working unchanged.
     */
    public static function fromEnvironment(
        ?LoggerInterface $logger = null,
        ?EnvInterface $env = null,
    ): self {
        $env ??= new Env();
        $csrfDefault = true;
        $csrfRaw = $env->readString('ZEF_SECURITY_CSRF');
        $csrfExplicit = trim($csrfRaw) !== '';
        // v2.31.0 (audit C-11): boolean parsing is now STRICT and fail-closed.
        // The old filter_var(..., FILTER_VALIDATE_BOOL) silently turned values
        // like 'enabled'/'on'/'yes' into FALSE, switching CSRF off because of
        // one typo. Recognized words (incl. 'enabled'/'disabled') map plainly;
        // anything else refuses to boot.
        $csrfEnabled = $csrfExplicit
            ? self::envBoolStrict('ZEF_SECURITY_CSRF', $csrfRaw, $logger)
            : $csrfDefault;
        $csrfSecret = $env->readString('ZEF_SECURITY_CSRF_SECRET');
        if ($csrfEnabled && $csrfSecret === '' && $csrfExplicit) {
            throw new SecurityPolicyException(
                'ZEF_SECURITY_CSRF=1 requires ZEF_SECURITY_CSRF_SECRET (>= 32 bytes).'
            );
        }
        if ($csrfEnabled && $csrfSecret === '') {
            $csrfEnabled = false;
            $msg = sprintf(
                '[ZEF][security] ZEF_SECURITY_CSRF_SECRET is not set; CSRF protection disabled. %s',
                'Set a secret of at least 32 bytes in production.'
            );
            if ($logger instanceof LoggerInterface) {
                $logger->warning($msg);
            } else {
                error_log($msg);
            }
        }

        return new self(
            rateLimitEnabled: $env->readBool('ZEF_SECURITY_RATE_LIMIT'),
            rateLimitMaxRequests: self::envPositiveInt('ZEF_SECURITY_RATE_LIMIT_MAX', 100, $env),
            rateLimitWindowSeconds: self::envPositiveInt('ZEF_SECURITY_RATE_LIMIT_WINDOW', 60, $env),
            rateLimitMaxKeys: self::envPositiveInt('ZEF_SECURITY_RATE_LIMIT_MAX_KEYS', 10000, $env),
            csrfEnabled: $csrfEnabled,
            csrfSecret: $csrfSecret,
            csrfCookieName: trim($env->readString('ZEF_SECURITY_CSRF_COOKIE', 'ZEF-XSRF-TOKEN')),
            csrfHeaderName: trim($env->readString('ZEF_SECURITY_CSRF_HEADER', 'X-CSRF-Token')),
            csrfSecureCookie: $env->readBool('ZEF_SECURITY_CSRF_SECURE', true),
            csrfHttpOnlyCookie: $env->readBool('ZEF_SECURITY_CSRF_HTTP_ONLY', true),
            csrfSameSite: trim($env->readString('ZEF_SECURITY_CSRF_SAMESITE', 'Strict')),
            allowedOrigins: $env->readCsv('ZEF_SECURITY_ALLOWED_ORIGINS'),
            originEnabled: $env->readBool('ZEF_SECURITY_ORIGIN_POLICY'),
            csrfTokenBytes: max(16, self::envPositiveInt('ZEF_SECURITY_CSRF_TOKEN_BYTES', 32, $env)),
            csrfTokenTtlSeconds: self::envPositiveInt('ZEF_SECURITY_CSRF_TTL', 0, $env),
            csrfSpaMode: $env->readBool('ZEF_SECURITY_CSRF_SPA', false),
        );
    }

    private function assertRateLimitBounds(int $maxRequests, int $windowSeconds, int $maxKeys): void
    {
        if ($maxRequests < 1) {
            throw new \InvalidArgumentException('rateLimitMaxRequests must be >= 1.');
        }
        if ($windowSeconds < 1) {
            throw new \InvalidArgumentException('rateLimitWindowSeconds must be >= 1.');
        }
        if ($maxKeys < 1) {
            throw new \InvalidArgumentException('rateLimitMaxKeys must be >= 1.');
        }
    }

    private function assertCsrfSecretStrength(bool $csrfEnabled, string $csrfSecret): void
    {
        if ($csrfEnabled && $csrfSecret !== '' && strlen($csrfSecret) < 32) {
            throw new \InvalidArgumentException('CSRF secret must be at least 32 bytes when CSRF is enabled.');
        }
    }

    /**
     * Cookie and header names are RFC 7230 tokens: the ASCII-only class is
     * deliberate (HTTP field names cannot carry non-ASCII code points), so
     * the ranges stay explicit instead of going Unicode-aware.
     */
    private function assertCsrfTokenNames(string $cookieName, string $headerName): void
    {
        if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $cookieName) !== 1) {
            throw new \InvalidArgumentException('Invalid CSRF cookie name.');
        }
        if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $headerName) !== 1) {
            throw new \InvalidArgumentException('Invalid CSRF header name.');
        }
    }

    private function assertCsrfCookiePolicy(string $sameSite, bool $secureCookie, int $tokenTtlSeconds): void
    {
        if (!in_array($sameSite, ['Strict', 'Lax', 'None'], true)) {
            throw new \InvalidArgumentException('Invalid CSRF SameSite policy.');
        }
        if ($sameSite === 'None' && !$secureCookie) {
            throw new \InvalidArgumentException('SameSite=None requires Secure cookies.');
        }
        if ($tokenTtlSeconds < 0) {
            throw new \InvalidArgumentException('csrfTokenTtlSeconds must be >= 0 (0 = no expiry).');
        }
    }

    private function assertCsrfSpaMode(bool $spaMode, bool $csrfEnabled, bool $httpOnlyCookie): void
    {
        if (!$spaMode) {
            return;
        }
        if (!$csrfEnabled) {
            throw new \InvalidArgumentException('CSRF SPA mode requires CSRF to be enabled.');
        }
        if ($httpOnlyCookie) {
            // Audit I-3: an HttpOnly cookie makes the double-submit token
            // structurally unreachable for JavaScript — every unsafe SPA
            // request would fail 403. Fail the configuration loudly.
            throw new \InvalidArgumentException(sprintf(
                'CSRF SPA mode requires a JS-readable cookie — set csrfHttpOnlyCookie=false (%s).',
                'ZEF_SECURITY_CSRF_HTTP_ONLY=false'
            ));
        }
    }

    /**
     * @param list<string> $allowedOrigins
     *
     * @return list<string>
     */
    private function normalizeOrigins(array $allowedOrigins): array
    {
        $normalized = [];
        foreach ($allowedOrigins as $origin) {
            $normalized[] = OriginPolicy::normalizeOrigin($origin);
        }

        return array_values(array_unique($normalized));
    }

    /** @param list<string> $allowedOrigins */
    private function assertOriginPolicy(bool $originEnabled, array $allowedOrigins): void
    {
        if ($originEnabled && $allowedOrigins === []) {
            throw new \InvalidArgumentException('Origin policy enabled without allowed origins.');
        }
    }

    /**
     * v2.31.0 (audit C-11): strict, fail-closed boolean parsing for security
     * controls. Recognized words map plainly (note: 'enabled'/'on'/'yes' now
     * correctly evaluate to TRUE); any other non-empty value throws instead
     * of silently disabling the control.
     */
    private static function envBoolStrict(string $name, string $raw, ?LoggerInterface $logger): bool
    {
        $value = strtolower(trim($raw));
        if (in_array($value, ['1', 'true', 'yes', 'on', 'enabled'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'off', 'disabled'], true)) {
            return false;
        }
        $msg = sprintf(
            "[ZEF][security] %s='%s' is not a recognized boolean %s",
            $name,
            $raw,
            '(allowed: 1/0, true/false, yes/no, on/off, enabled/disabled).'
        );
        if ($logger instanceof LoggerInterface) {
            $logger->error($msg);
        } else {
            error_log($msg);
        }

        throw new SecurityPolicyException($msg);
    }

    /**
     * Non-numeric env values previously (int)-cast to 0, so a typo like
     * ZEF_SECURITY_RATE_LIMIT_MAX=1OO silently became 1 request/window
     * (not the documented default). Fall back to the default instead.
     */
    private static function envPositiveInt(string $name, int $default, EnvInterface $env): int
    {
        $raw = $env->readString($name);
        if (trim($raw) === '' || !ctype_digit(trim($raw))) {
            return $default;
        }

        return max(1, (int) $raw);
    }
}
