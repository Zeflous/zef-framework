<?php

declare(strict_types=1);

/*
 * ZEF Framework — Infrastructure layer (outbound security adapter)
 * Added in the v2.10.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Security;

/**
 * Dedicated runtime failure of {@see RotatingKeyRing} decryption: the
 * payload did not decrypt with any key of the ring.
 *
 * Extends {@see \RuntimeException} so callers catching the generic SPL
 * exception keep working unchanged.
 */
class DecryptionException extends \RuntimeException {}
