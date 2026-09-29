<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.30.0 — Infrastructure layer (outbound adapters)
 * Ecosystem Ports: MessageTransport adapter for dev/test topologies.
 */

namespace Zef\Framework\Message;

/**
 * In-process {@see MessageTransportInterface} — the zero-dependency
 * dev/test transport. send() appends the envelope into a bounded FIFO and
 * immediately returns an accepted result, mirroring broker semantics
 * (send succeeds independent of any consumer); receive()/drain() let the
 * same process (tests, dev workers, the plugin harness) consume what was
 * published.
 *
 * Single-process only — there is no persistence, no cross-worker delivery
 * and no retry semantics. Broker-grade transports (Redis Streams, NATS,
 * SQS, Kafka) implement the SAME port and plug in at the composition root;
 * see docs/INTEGRATIONS.md for the exchange contract.
 */
final class InMemoryMessageTransport implements MessageTransportInterface
{
    /** @var null|\SplQueue<array{MessageEnvelope, MessageContext, string}> */
    private ?\SplQueue $queue = null;
    private int $sequence = 0;

    public function __construct(private readonly int $maxSize = 10_000)
    {
        if ($maxSize < 1) {
            throw new \InvalidArgumentException('Message transport capacity must be positive.');
        }
    }

    #[\Override]
    public function send(MessageEnvelope $message, MessageContext $context): MessageResult
    {
        if ($this->queue()->count() >= $this->maxSize) {
            throw new \OverflowException('Message transport capacity exceeded.');
        }
        ++$this->sequence;
        $transportId = sprintf('mem-%06d', $this->sequence);
        $this->queue()->enqueue([$message, $context, $transportId]);

        return new MessageResult($message->messageId, true, $transportId);
    }

    /** Number of messages waiting for consumption. */
    public function size(): int
    {
        return $this->queue()->count();
    }

    /** Dequeue the oldest published message, or null when the queue is empty. */
    public function receive(): ?ReceivedMessage
    {
        if ($this->queue()->isEmpty()) {
            return null;
        }

        /** @var array{MessageEnvelope, MessageContext, string} $item */
        $item = $this->queue()->dequeue();

        return new ReceivedMessage($item[0], $item[1], $item[2]);
    }

    /** @return list<ReceivedMessage> everything published so far, FIFO order */
    public function drain(): array
    {
        $messages = [];
        while (($received = $this->receive()) instanceof ReceivedMessage) {
            $messages[] = $received;
        }

        return $messages;
    }

    /**
     * The FIFO is pure internal scaffolding: created on first use so the
     * constructor stays work-free (validation only).
     *
     * @return \SplQueue<array{MessageEnvelope, MessageContext, string}>
     */
    private function queue(): \SplQueue
    {
        $this->queue ??= new \SplQueue();

        return $this->queue;
    }
}
