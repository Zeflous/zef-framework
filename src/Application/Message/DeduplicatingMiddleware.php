<?php

declare(strict_types=1);

/*
 * ZEF Framework — Application layer (in-process orchestration)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Message;

use Zef\Framework\CQRS\IdempotencyStoreInterface;

/**
 * Message middleware suppressing duplicate deliveries by message ID.
 *
 * The first delivery of an envelope flows through `$next` and its result is
 * remembered in the idempotency store (MessageResult objects are immutable,
 * so replaying the same instance is safe). Redeliveries of the same
 * messageId — a normal at-least-once transport behaviour — return the cached
 * result without touching the handler chain again.
 *
 * Insert BEFORE transport-boundary middlewares in the bus configuration.
 */
final readonly class DeduplicatingMiddleware implements MessageMiddlewareInterface
{
    public function __construct(
        private IdempotencyStoreInterface $idempotency,
        private int $ttlSeconds = 3600,
    ) {
        if ($this->ttlSeconds < 1 || $this->ttlSeconds > 86400 * 7) {
            throw new \InvalidArgumentException('Dedup TTL must be 1..604800 seconds.');
        }
    }

    #[\Override]
    public function process(MessageEnvelope $message, MessageContext $context, \Closure $next): MessageResult
    {
        // Bounded, opaque-safe key: "dedup-" + sha256 hex (70 bytes, matches
        // the idempotency store's [A-Za-z0-9._:-]{8,128} key policy) — avoids
        // overflow when message IDs approach the 128-byte limit themselves.
        $key = 'dedup-' . hash('sha256', $message->messageId, false);

        /** @var MessageResult $result */
        $result = $this->idempotency->remember(
            $key,
            static fn (): MessageResult => $next($message, $context),
            $this->ttlSeconds,
        );
        if (!$result instanceof MessageResult) {
            throw new DeduplicationException('Dedup store returned a non-MessageResult entry.');
        }

        return $result;
    }
}
