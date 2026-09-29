<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Infrastructure layer (outbound adapters).
 * APCu rate limiter: the APCu extension is not loaded. Replaces the
 * generic \RuntimeException throw in ApcuRateLimiter (Sonar php:S112)
 * while staying a RuntimeException subtype so existing catch sites and
 * tests keep matching.
 */

namespace Zef\Framework\Security;

final class ApcuUnavailableException extends \RuntimeException {}
