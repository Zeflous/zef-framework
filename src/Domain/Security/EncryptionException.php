<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added for the Sonar bucket-5 hardening: dedicated encryption failure
 * (php:S112 — generic \RuntimeException replaced at the throwing sites).
 */

namespace Zef\Framework\Security;

class EncryptionException extends \RuntimeException {}
