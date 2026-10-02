<?php

declare(strict_types=1);

/*
 * ZEF Framework — Application layer (in-process orchestration)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Cache;

/**
 * In-process leased lock store implementing LockStoreInterface.
 *
 * Time is injectable (unix seconds) so expiry semantics are deterministically
 * testable; production callers use the default wall clock. Expiry is lazy:
 * expired entries are reclaimed on the next touch of that key.
 */
final class InMemoryLockStore implements LockStoreInterface
{
    /**
     * @var array<string,array{owner:string,expiresAt:int}>
     */
    private array $locks = [];

    /**
     * @var \Closure():int
     */
    private readonly \Closure $clock;

    /** @param null|callable():int $clock unix-second clock (defaults to time()) */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock === null ? time(...) : $clock(...);
    }

    #[\Override]
    public function acquire(string $key, string $owner, int $ttlSeconds): bool
    {
        $this->assertKey($key);
        $this->assertOwner($owner);
        $this->assertTtl($ttlSeconds);
        $now = ($this->clock)();
        $existing = $this->locks[$key] ?? null;
        if ($existing !== null) {
            if ($existing['expiresAt'] > $now) {
                if ($existing['owner'] !== $owner) {
                    return false;
                }
                // acquire() is the renewal operation used by Scheduler's
                // sticky-leader loop. Keep its semantics aligned with the
                // Redis implementation so a live owner does not leave a
                // takeover window between otherwise healthy ticks.
                $this->locks[$key]['expiresAt'] = $now + $ttlSeconds;

                return true;
            }
            unset($this->locks[$key]);
        }
        $this->locks[$key] = ['owner' => $owner, 'expiresAt' => $now + $ttlSeconds];

        return true;
    }

    #[\Override]
    public function release(string $key, string $owner): bool
    {
        $this->assertKey($key);
        $this->assertOwner($owner);
        $existing = $this->locks[$key] ?? null;
        if ($existing === null || $existing['owner'] !== $owner) {
            return false;
        }
        unset($this->locks[$key]);

        return true;
    }

    #[\Override]
    public function refresh(string $key, string $owner, int $ttlSeconds): bool
    {
        $this->assertKey($key);
        $this->assertOwner($owner);
        $this->assertTtl($ttlSeconds);
        $existing = $this->locks[$key] ?? null;
        $now = ($this->clock)();
        if ($existing === null || $existing['expiresAt'] <= $now || $existing['owner'] !== $owner) {
            return false;
        }
        $this->locks[$key]['expiresAt'] = $now + $ttlSeconds;

        return true;
    }

    #[\Override]
    public function holder(string $key): ?string
    {
        $this->assertKey($key);
        $existing = $this->locks[$key] ?? null;
        if ($existing === null || $existing['expiresAt'] <= ($this->clock)()) {
            unset($this->locks[$key]);

            return null;
        }

        return $existing['owner'];
    }

    private function assertKey(string $key): void
    {
        if ($key === '' || strlen($key) > 256) {
            throw new \InvalidArgumentException('Lock key must be 1..256 bytes.');
        }
    }

    private function assertOwner(string $owner): void
    {
        if ($owner === '' || strlen($owner) > 256) {
            throw new \InvalidArgumentException('Lock owner must be 1..256 bytes.');
        }
    }

    private function assertTtl(int $ttlSeconds): void
    {
        if ($ttlSeconds < 1 || $ttlSeconds > 86400) {
            throw new \InvalidArgumentException('Lock TTL must be 1..86400 seconds.');
        }
    }
}
