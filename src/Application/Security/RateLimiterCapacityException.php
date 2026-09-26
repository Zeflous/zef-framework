<?php

declare(strict_types=1);

/*
 * ZEF Framework — Security (Application layer: in-process orchestration)
 * Added in v2.31.0 (ZEF-DEEP-02: capacity exhaustion is a distinct, benign
 * operating condition of bounded in-process limiter stores, not a storage
 * failure).
 */

namespace Zef\Framework\Security;

/**
 * Thrown by bounded in-process limiter stores (InMemory / SlidingWindow /
 * TokenBucket) when a NEW key arrives while the store already holds
 * maxKeys live buckets.
 *
 * Why a dedicated type (ZEF-DEEP-02, issue #156): previously this condition
 * surfaced as a bare RuntimeException, indistinguishable from a storage
 * failure — so middleware fail-closed policy turned a full key store into a
 * global 503 for every new identity. Callers can now separate the two:
 *
 *  - storage failure       -> fail-open/fail-closed per configured policy;
 *  - capacity exhaustion   -> the store is merely full; already-tracked keys
 *    keep working (the guard only fires for keys WITHOUT a bucket), so the
 *    resilient behaviour is to serve the request untracked instead of
 *    refusing service to everyone.
 *
 * Note the exhaustion lever itself was closed by the same issue: identities
 * no longer derive from client-controlled headers by default, so filling the
 * store requires as many distinct client IPs.
 */
final class RateLimiterCapacityException extends \RuntimeException {}
