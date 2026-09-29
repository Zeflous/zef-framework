<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Message;

use Zef\Framework\Validation\Identifier;

final class InProcessMessageBus implements MessageBusInterface
{
    private const int DISPATCH_DEPTH_LIMIT = 64;

    /**
     * @var array<string,MessageHandlerInterface>
     */
    private array $handlers = [];

    /**
     * @var list<MessageMiddlewareInterface>
     */
    private array $middleware = [];
    private int $dispatchDepth = 0;

    /**
     * @param array<string,MessageHandlerInterface> $handlers
     * @param array<int,MessageMiddlewareInterface> $middleware
     */
    public function __construct(array $handlers = [], array $middleware = [])
    {
        foreach ($handlers as $type => $handler) {
            $this->registerHandler($type, $handler);
        }
        foreach ($middleware as $item) {
            $this->addMiddleware($item);
        }
    }

    public function registerHandler(string $messageType, MessageHandlerInterface $handler): void
    {
        Identifier::assertMessageType($messageType);
        if (isset($this->handlers[$messageType])) {
            throw new \LogicException('Message handler already registered.');
        }
        $this->handlers[$messageType] = $handler;
    }

    public function addMiddleware(MessageMiddlewareInterface $middleware): void
    {
        if (count($this->middleware) >= 32) {
            throw new \LogicException('Message middleware limit exceeded.');
        }
        $this->middleware[] = $middleware;
    }

    #[\Override]
    public function dispatch(MessageEnvelope $message, ?MessageContext $context = null): MessageResult
    {
        // Re-entrant dispatch guard: a handler re-dispatching the same
        // envelope recursed unbounded (OOM fatal).
        if ($this->dispatchDepth >= self::DISPATCH_DEPTH_LIMIT) {
            throw new \LogicException('Message bus dispatch depth exceeded (recursive dispatch?).');
        }
        ++$this->dispatchDepth;

        try {
            return $this->doDispatch($message, $context);
        } finally {
            --$this->dispatchDepth;
        }
    }

    private function doDispatch(MessageEnvelope $message, ?MessageContext $context): MessageResult
    {
        $context ??= new MessageContext();
        // Resolve the handler once from the ORIGINAL envelope type: the
        // terminal step must not re-resolve from middleware-rewritten
        // envelopes, or a rewritten type would null-deref here.
        $handler = $this->handlers[$message->messageType] ?? null;
        if ($handler === null) {
            throw new MessageDispatchException('No message handler registered.');
        }
        // Immutable folded chain (same pattern as CqrsBusTrait::buildChain
        // and InProcessJobWorker::execute): each $next link is fixed, so
        // middleware that invokes $next more than once (retry/fallback)
        // still walks the FULL chain instead of skipping to the terminal.
        $terminal = function (MessageEnvelope $current, MessageContext $ctx) use ($handler): MessageResult {
            ($handler)($current, $ctx);

            return new MessageResult($current->messageId, true);
        };
        for ($i = count($this->middleware) - 1; $i >= 0; --$i) {
            $layer = $this->middleware[$i];
            $next = $terminal;
            $terminal = fn (MessageEnvelope $current, MessageContext $ctx): MessageResult => $layer->process(
                $current,
                $ctx,
                $next,
            );
        }

        return $terminal($message, $context);
    }
}
