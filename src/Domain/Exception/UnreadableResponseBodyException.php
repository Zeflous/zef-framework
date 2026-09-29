<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the sonar-zero hardening pass: dedicated exception for the
 * response emitter's unreadable-body-stream failure (was generic
 * \RuntimeException; message byte-identical, BC via subclassing).
 */

namespace Zef\Framework\Exception;

final class UnreadableResponseBodyException extends \RuntimeException {}
