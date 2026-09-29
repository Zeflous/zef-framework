<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.30.0 — Infrastructure layer (outbound adapters)
 * Ecosystem Ports: ListObjectsV2 response parser, collaborator of
 * S3CompatibleStorage (php:S2042 split, 2026-09-29): the adapter keeps the
 * signed HTTP wire, the parser owns the XML contract.
 */

namespace Zef\Framework\Storage;

/**
 * Extracts the object keys and the continuation marker from
 * ListObjectsV2 documents (namespace-aware: MinIO/Ceph emit the
 * 2006-03-01 default xmlns, AWS omits it). The token is non-null exactly
 * when the page reports IsTruncated=true.
 */
final class S3ListingParser
{
    /**
     * @return array{keys: list<string>, token: null|string}
     */
    public static function parse(S3HttpResponse $response): array
    {
        if (!\function_exists('simplexml_load_string')) {
            throw new StorageException('The SimpleXML extension is required to parse S3 listing responses.');
        }
        $xml = self::parseListingXml($response->body);
        if ($xml === false) {
            throw new StorageException('S3 listing response is not valid XML.');
        }
        $namespaces = $xml->getDocNamespaces();
        $root = isset($namespaces['']) && (string) $namespaces[''] !== ''
            ? $xml->children((string) $namespaces[''])
            : $xml;
        $keys = [];
        foreach ($root->Contents as $entry) {
            $keys[] = (string) $entry->Key;
        }
        if (strtolower(trim((string) $root->IsTruncated)) !== 'true') {
            return ['keys' => $keys, 'token' => null];
        }
        $token = trim((string) $root->NextContinuationToken);
        if ($token === '') {
            // Truncated without a token is a malformed V2 document: fail
            // loudly rather than silently reporting a partial page (P-9).
            throw new StorageException('S3 listing response is truncated without a continuation token.');
        }

        return ['keys' => $keys, 'token' => $token];
    }

    /**
     * Parses the listing XML with libxml diagnostics routed into libxml's
     * own error buffer instead of the engine warning channel (php:S2002 —
     * no '@' suppression); the previous internal-errors state is restored.
     */
    private static function parseListingXml(string $body): false|\SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($body);
        } finally {
            libxml_use_internal_errors($previous);
        }

        return $xml;
    }
}
