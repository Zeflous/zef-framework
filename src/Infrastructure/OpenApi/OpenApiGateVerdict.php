<?php

declare(strict_types=1);

// ZEF Framework v2.33.0 — Infrastructure layer (OpenAPI runtime gate)

namespace Zef\Framework\OpenApi;

/**
 * The outcome of one gate evaluation.
 *
 * Admitted verdicts carry the matched-operation context (operationId, path
 * template, effective method, captured path parameters and the declared
 * responses map — the last is what response validation needs); rejected
 * verdicts carry everything the RFC 9457 document needs: status, detail,
 * the deterministic issue list, extra response headers (Allow on 405) and
 * extra problem extensions (allowed/supported).
 *
 * The reason-phrase title is deliberately NOT duplicated here: the
 * middleware renders rejections through ProblemDetails, which derives the
 * RFC 9110 title from the status itself — a second copy would be dead
 * data that can drift.
 *
 * A pass-through (boundary B1: no template matched) is an admitted verdict
 * with a null operation — the router stays the owner of that request.
 *
 * @phpstan-type GateIssue array{in: string, name: string, pointer: string, message: string}
 * @phpstan-type GateOperationContext array{
 *     operationId: string,
 *     path: string,
 *     method: string,
 *     pathParams: array<string, string>,
 *     responses: array<mixed, mixed>,
 * }
 */
final readonly class OpenApiGateVerdict
{
    /**
     * @param list<GateIssue>           $issues
     * @param array<string, string>     $headers
     * @param array<string, mixed>      $extensions
     * @param null|array<string, mixed> $operation
     */
    public function __construct(
        public bool $admitted,
        public ?array $operation = null,
        public ?int $status = null,
        public string $detail = '',
        public array $issues = [],
        public array $headers = [],
        public array $extensions = [],
    ) {}

    /**
     * @param null|array<string, mixed> $operation
     */
    public static function admitted(?array $operation): self
    {
        return new self(true, $operation);
    }

    /**
     * @param list<GateIssue>          $issues
     * @param array<string, string>    $headers
     * @param array<string, mixed>     $extensions
     */
    public static function rejected(
        int $status,
        string $detail,
        array $issues = [],
        array $headers = [],
        array $extensions = [],
    ): self {
        return new self(
            admitted: false,
            status: $status,
            detail: $detail,
            issues: $issues,
            headers: $headers,
            extensions: $extensions,
        );
    }
}
