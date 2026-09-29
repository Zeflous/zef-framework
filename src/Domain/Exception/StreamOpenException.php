<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (exception taxonomy)
 * Added in the sonar-zero campaign: a dedicated exception for PSR-17 stream
 * open failures instead of the generic one, so the cause is catchable by type.
 */

namespace Zef\Framework\Exception;

final class StreamOpenException extends \RuntimeException {}
