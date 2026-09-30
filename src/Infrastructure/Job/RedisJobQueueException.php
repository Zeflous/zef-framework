<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.32.0 — Infrastructure layer (outbound adapters).
 * Redis job-queue protocol failures: an unexpected eval/xLen/xRange
 * result shape, or a duplicate live-id claim rejected by the Lua enqueue
 * backstop. Replaces the generic \RuntimeException throws in
 * RedisStreamJobQueue (Sonar php:S112) while staying a RuntimeException
 * subtype so existing catch sites keep matching — the same contract
 * OtlpExporterException established for the OTLP exporter.
 */

namespace Zef\Framework\Job;

final class RedisJobQueueException extends \RuntimeException {}
