<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Http;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Zef\Framework\Exception\UploadedFileException;

final class UploadedFile implements UploadedFileInterface
{
    private bool $moved = false;

    public function __construct(
        private readonly StreamInterface $stream,
        private readonly ?int $size = null,
        private readonly int $error = UPLOAD_ERR_OK,
        private readonly ?string $clientFilename = null,
        private readonly ?string $clientMediaType = null,
    ) {
        // Mirror Psr17Factory::createUploadedFile(): a negative size is
        // meaningless (PSR-7 §6.5) and must not be stored verbatim.
        if ($size !== null && $size < 0) {
            throw new \InvalidArgumentException('Uploaded file size must not be negative.');
        }
    }

    #[\Override]
    public function getStream(): StreamInterface
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new UploadedFileException("Uploaded file is not available (error {$this->error}).");
        }
        $this->assertNotMoved();

        return $this->stream;
    }

    /**
     * Bug fix #11: replaced @fopen/@rename with scoped error handlers;
     * removed pointless function_exists('fflush').
     */
    #[\Override]
    public function moveTo(string $targetPath): void
    {
        $this->assertMoveable($targetPath);
        $tmpTarget = $targetPath . '.zef-tmp-' . bin2hex(random_bytes(8));
        $dest = $this->openTarget($tmpTarget);
        $success = false;
        $originalPosition = null;

        try {
            if ($this->stream->isSeekable()) {
                $originalPosition = $this->stream->tell();
                $this->stream->rewind();
            }
            $this->copyStreamTo($dest);
            fflush($dest);
            fclose($dest);
            $dest = null;
            $this->finalizeTarget($tmpTarget, $targetPath);
            $success = true;
        } finally {
            if (is_resource($dest)) {
                fclose($dest);
            }
            $this->cleanupAfterMove($tmpTarget, $success, $originalPosition);
        }
        $this->moved = true;
        $this->stream->close();
    }

    #[\Override]
    public function getSize(): ?int
    {
        return $this->size ?? $this->stream->getSize();
    }

    #[\Override]
    public function getError(): int
    {
        return $this->error;
    }

    #[\Override]
    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    #[\Override]
    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }

    private function assertNotMoved(): void
    {
        if ($this->moved) {
            throw new UploadedFileException('Uploaded file has already been moved.');
        }
    }

    private function assertMoveable(string $targetPath): void
    {
        if ($targetPath === '') {
            throw new \InvalidArgumentException('Target path must not be empty.');
        }
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new UploadedFileException("Cannot move uploaded file with error code {$this->error}.");
        }
        $this->assertNotMoved();
        $directory = dirname($targetPath);
        if (!is_dir($directory)) {
            throw new UploadedFileException("Destination directory does not exist: '{$directory}'.");
        }
    }

    /**
     * Opens the temporary target with a scoped error handler so the fopen
     * warning is captured into the thrown message instead of leaking.
     *
     * @return resource
     */
    private function openTarget(string $tmpTarget)
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            if (($severity & (E_WARNING | E_NOTICE)) !== 0) {
                $warning = $message;
            }

            return true;
        });

        try {
            $dest = fopen($tmpTarget, 'x+b');
        } finally {
            restore_error_handler();
        }
        if ($dest === false) {
            throw new UploadedFileException(
                'Unable to create temporary upload target' . ($warning !== null ? ": {$warning}" : '.'),
            );
        }

        return $dest;
    }

    /**
     * @param resource $dest
     */
    private function copyStreamTo($dest): void
    {
        while (!$this->stream->eof()) {
            $chunk = $this->stream->read(8192);
            if ($chunk === '') {
                break;
            }
            $offset = 0;
            $length = strlen($chunk);
            while ($offset < $length) {
                $written = fwrite($dest, substr($chunk, $offset));
                if ($written === false || $written === 0) {
                    throw new UploadedFileException('Unable to write uploaded file.');
                }
                $offset += $written;
            }
        }
    }

    private function finalizeTarget(string $tmpTarget, string $targetPath): void
    {
        $renameWarning = null;
        set_error_handler(static function (int $severity, string $message) use (&$renameWarning): bool {
            if (($severity & (E_WARNING | E_NOTICE)) !== 0) {
                $renameWarning = $message;
            }

            return true;
        });

        try {
            $renamed = rename($tmpTarget, $targetPath);
        } finally {
            restore_error_handler();
        }
        if (!$renamed) {
            throw new UploadedFileException(
                "Unable to finalize uploaded file to '{$targetPath}'"
                . ($renameWarning !== null ? ": {$renameWarning}" : '.'),
            );
        }
    }

    private function cleanupAfterMove(string $tmpTarget, bool $success, ?int $originalPosition): void
    {
        if (!$success) {
            // Cleanup of $tmpTarget, a name this class generated itself
            // ($targetPath . '.zef-tmp-' . bin2hex(random_bytes(8))). No request
            // input reaches the argument, and this runs only on the failure path.
            // Registered as an accepted suppression: docs/security/php-sast.md §7.
            // php.lang.security.unlink-use matches every non-literal argument by
            // construction, so no rewrite of this call can clear it (measured, §7.1).
            if (is_file($tmpTarget)) {
                unlink($tmpTarget); // nosemgrep: php.lang.security.unlink-use
            }
        }
        if (!$success && $originalPosition !== null) {
            try {
                $this->stream->seek($originalPosition);
            } catch (\Throwable) {
                // Intentionally empty: restoring the read position is
                // best-effort cleanup on the failure path — the original
                // failure must propagate, not be replaced by this one.
            }
        }
    }
}
