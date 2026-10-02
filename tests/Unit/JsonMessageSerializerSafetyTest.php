<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Message\JsonMessageSerializer;
use Zef\Framework\Message\MessageEnvelope;

final class JsonMessageSerializerSafetyTest extends TestCase
{
    public function testDeepPayloadIsRejectedByJsonDepthInsteadOfExhaustingPhpCallStack(): void
    {
        $payload = ['leaf' => true];
        for ($depth = 0; $depth < 600; ++$depth) {
            $payload = ['next' => $payload];
        }

        $this->expectException(\JsonException::class);

        (new JsonMessageSerializer())->serialize(new MessageEnvelope('deep-message', 'audit.deep', $payload));
    }

    public function testCyclicPayloadIsBoundedBeforeItCanLoopIndefinitely(): void
    {
        $payload = [];
        $payload['self'] = &$payload;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('safety limit');

        (new JsonMessageSerializer())->serialize(new MessageEnvelope('cyclic-message', 'audit.cycle', $payload));
    }
}
