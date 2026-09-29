<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the sonar-zero campaign: dedicated runtime exception for cron
 * expression evaluation failures (replaces the generic \RuntimeException).
 */

namespace Zef\Framework\Job;

/**
 * A parsed cron expression cannot produce a run: it is statically
 * impossible (restricted day-of-month has no date in the restricted
 * months) or no minute matched within the four-year scan budget.
 */
final class CronExpressionException extends \RuntimeException {}
