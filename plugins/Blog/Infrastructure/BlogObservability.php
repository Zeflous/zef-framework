<?php

declare(strict_types=1);

namespace Zef\Plugin\Blog\Infrastructure;

use Psr\Log\LoggerInterface;

/** Structured audit/event log and in-process counters; replaceable through DI. */
final class BlogObservability
{
    /** @var array<string,int> */
    private array $counters = [];
    /** @var list<array<string,mixed>> */
    private array $audit = [];

    public function __construct(private readonly ?LoggerInterface $logger = null) {}
    public function count(string $name): void { $this->counters[$name] = ($this->counters[$name] ?? 0) + 1; }
    /** @param array<string,mixed> $entry */
    public function audit(array $entry): void { $this->audit[] = $entry; $this->logger?->info('blog.audit', $entry); }
    /** @return array<string,int> */ public function counters(): array { return $this->counters; }
    /** @return list<array<string,mixed>> */ public function auditEntries(): array { return $this->audit; }
}
