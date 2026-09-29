<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (adapters layer).
 * Outbound response safety net extracted from RoadRunnerRuntime
 * (php:S1448/S2042): worker respond/error reporting, the synthetic
 * 503 admission-rejection and 500 handler-failure bodies, and the
 * ZEF-DEEP-05 Content-Length reconciliation. Behaviour-identical to
 * the code previously inlined in RoadRunnerRuntime.
 */

namespace Zef\Framework\Runtime;

use Psr\Http\Message\ResponseInterface;
use Zef\Framework\Http\Response;

final readonly class RuntimeResponder
{
    public function __construct(private WorkerInterface $worker) {}

    /** Forwards a successfully produced response to the worker, framing-reconciled. */
    public function respond(ResponseInterface $response): void
    {
        $this->worker->respond($this->reconcileContentLength($response));
    }

    /** Best-effort respond: a failed write is reported on the error channel instead of thrown. */
    public function safeRespond(ResponseInterface $response): void
    {
        try {
            $this->worker->respond($this->reconcileContentLength($response));
        } catch (\Throwable $e) {
            $this->reportWorkerFailure($e);
        }
    }

    /** Over-capacity answer: the worker stays alive and keeps draining. */
    public function respondServiceUnavailable(): void
    {
        $this->safeRespond(new Response(
            503,
            ['Content-Type' => 'application/json'],
            '{"error":"Service Unavailable","status":503}',
        ));
    }

    /** Unhandled-handler answer: delivered best-effort after reporting the failure. */
    public function respondInternalError(): void
    {
        $this->safeRespond(new Response(
            500,
            ['Content-Type' => 'application/json'],
            '{"error":"Internal Server Error","status":500}',
        ));
    }

    public function reportWorkerFailure(\Throwable $e): void
    {
        $message = $e::class . ': ' . $e->getMessage();

        try {
            $this->worker->error($message);
        } catch (\Throwable) {
            // The worker error channel is itself broken: the SAPI error log
            // is the last resort. A false return (unwritable target) has no
            // further channel to report through — dropping is the only option.
            error_log($message);
        }
    }

    /**
     * ZEF-DEEP-05 safety net: unlike the SAPI path (ResponseEmitter
     * reconciles lying framing headers), this runtime forwards headers to
     * the worker VERBATIM. A stale Content-Length — a 304/204/205 built from
     * a content-bearing 200, or any handler declaring more octets than its
     * stream holds — desyncs clients and keep-alive proxies (RFC 9110 §8.6
     * CL.CL smuggling), so the same reconciliation the emitter applies runs
     * here, centrally, for every outbound response.
     */
    private function reconcileContentLength(ResponseInterface $response): ResponseInterface
    {
        $status = $response->getStatusCode();
        if (in_array($status, [204, 205, 304], true)) {
            // Bodyless statuses never carry a payload: the would-be length
            // is a stale artefact of the response they were derived from.
            return $this->withoutContentLength($response);
        }
        if ($this->declaredLengthIsHonest($response)) {
            return $response;
        }

        return $this->withoutContentLength($response);
    }

    /** True when the declared Content-Length matches the actual body size (or is absent). */
    private function declaredLengthIsHonest(ResponseInterface $response): bool
    {
        $declared = $response->getHeaderLine('Content-Length');
        if ($declared === '') {
            return true;
        }
        $size = $response->getBody()->getSize();

        return ctype_digit($declared) && $size !== null && (int) $declared === $size;
    }

    private function withoutContentLength(ResponseInterface $response): ResponseInterface
    {
        $reconciled = $response->withoutHeader('Content-Length');
        if (!$reconciled instanceof ResponseInterface) {
            throw new \LogicException('withoutHeader must preserve the response type.');
        }

        return $reconciled;
    }
}
