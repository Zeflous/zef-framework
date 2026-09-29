<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Infrastructure layer (outbound adapters).
 * OTLP/HTTP exporter: transport-level failure (connection refused,
 * timeout, transient 5xx). Replaces the generic \RuntimeException
 * throws in OtlpHttpJsonExporter (Sonar php:S112) while staying a
 * RuntimeException subtype so existing catch sites keep matching.
 */

namespace Zef\Framework\Observability;

final class OtlpExporterException extends \RuntimeException {}
