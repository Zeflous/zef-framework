<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Message;

final class JsonMessageSerializer implements MessageSerializerInterface
{
    /**
     * A serializer runs on an ingress boundary. Keep this deliberately below
     * the 1 MiB wire limit so validation itself cannot become a memory DoS.
     */
    private const int MAX_PAYLOAD_NODES = 100_000;

    private const int MAX_PAYLOAD_DEPTH = 1_024;

    #[\Override]
    public function serialize(MessageEnvelope $message): string
    {
        $this->assertJsonSafe($message->payload);

        return json_encode(
            [
                'id' => $message->messageId,
                'type' => $message->messageType,
                'payload' => $message->payload,
                'headers' => $message->headers,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }

    #[\Override]
    public function deserialize(string $payload): MessageEnvelope
    {
        if (strlen($payload) > 1_048_576) {
            throw new \InvalidArgumentException('Message payload exceeds the 1 MiB limit.');
        }
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (
            !is_array($data)
            || !isset($data['id'], $data['type'])
            || !is_string($data['id'])
            || !is_string($data['type'])
        ) {
            throw new \InvalidArgumentException('Invalid serialized message envelope.');
        }
        $headers = $data['headers'] ?? [];
        if (!is_array($headers)) {
            throw new \InvalidArgumentException('Invalid serialized headers.');
        }
        foreach ($headers as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new \InvalidArgumentException('Serialized headers must be strings.');
            }
        }

        return new MessageEnvelope($data['id'], $data['type'], $data['payload'] ?? null, $headers);
    }

    private function assertJsonSafe(mixed $value): void
    {
        // Do not recurse here. Message payloads are caller-controlled and a
        // deeply nested (but otherwise valid) array must not consume the PHP
        // call stack before json_encode() can report its documented depth
        // error. The explicit stack also bounds cyclic references and very
        // broad payloads before they can keep the worker busy indefinitely.
        $stack = [[$value, 0]];
        $visited = 0;

        while ($stack !== []) {
            /** @var array{mixed, int} $entry */
            $entry = array_pop($stack);
            [$current, $depth] = $entry;

            if (is_resource($current) || is_object($current)) {
                throw new \InvalidArgumentException('Message payload must be JSON-safe data.');
            }
            if (!is_array($current)) {
                continue;
            }
            // Count this node before the limit check: the increment is a
            // dedicated statement so the guard reads as a pure comparison.
            ++$visited;
            if ($depth >= self::MAX_PAYLOAD_DEPTH || $visited > self::MAX_PAYLOAD_NODES) {
                throw new \InvalidArgumentException('Message payload structure exceeds the safety limit.');
            }

            // Check before growing the work queue. Counting only popped nodes
            // would still permit one wide array to allocate an unbounded stack.
            if (count($current) > self::MAX_PAYLOAD_NODES - count($stack)) {
                throw new \InvalidArgumentException('Message payload structure exceeds the safety limit.');
            }

            foreach ($current as $item) {
                $stack[] = [$item, $depth + 1];
            }
        }
    }
}
