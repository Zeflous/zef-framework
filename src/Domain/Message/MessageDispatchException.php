<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added for the Sonar bucket-5 hardening: dedicated message-bus dispatch
 * failure (php:S112 — generic \RuntimeException replaced at the throwing
 * site). Mirrors EventDispatchException/JobExecutionException semantics.
 */

namespace Zef\Framework\Message;

class MessageDispatchException extends \RuntimeException {}
