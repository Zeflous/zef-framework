<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Security policy construction and its failure vocabulary.
 */

namespace Zef\Framework\Security;

/**
 * A {@see SecurityPolicy} could not be constructed from its inputs or from
 * the environment: the offending combination is refused loudly instead of
 * silently degrading a security control.
 */
class SecurityPolicyException extends \RuntimeException {}
