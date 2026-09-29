<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Raised by RouteConstraintValidator's set_error_handler bridge while
 * test-compiling a user-supplied route constraint regex: a PHP diagnostic
 * (warning) is converted into this exception. Replaces the generic
 * \ErrorException throw (Sonar php:S112) while staying an ErrorException
 * subtype so the internal catch clause keeps matching.
 */

namespace Zef\Framework\Validation;

final class RouteConstraintPatternException extends \ErrorException {}
