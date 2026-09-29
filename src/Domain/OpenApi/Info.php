<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * OpenAPI Info Object — the metadata root of a specification document.
 */
final readonly class Info
{
    public function __construct(
        public string $title,
        public string $version,
        public string $description = '',
        public ?string $termsOfService = null,
        public ?Contact $contact = null,
        public ?License $license = null,
    ) {
        if (trim($title) === '') {
            throw new \InvalidArgumentException('Info title must not be empty.');
        }
        if (trim($version) === '') {
            throw new \InvalidArgumentException('Info version must not be empty.');
        }
        if ($termsOfService !== null && filter_var($termsOfService, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException("Info termsOfService '{$termsOfService}' is not a valid URL.");
        }
    }

    /**
     * @return array{
     *     title: string,
     *     version: string,
     *     description?: string,
     *     termsOfService?: string,
     *     contact?: array<string, string>,
     *     license?: array<string, string>
     * }
     */
    public function toArray(): array
    {
        $out = [
            'title' => $this->title,
            'version' => $this->version,
        ];
        if ($this->description !== '') {
            $out['description'] = $this->description;
        }
        if ($this->termsOfService !== null) {
            $out['termsOfService'] = $this->termsOfService;
        }
        if ($this->contact instanceof Contact) {
            $out['contact'] = $this->contact->toArray();
        }
        if ($this->license instanceof License) {
            $out['license'] = $this->license->toArray();
        }

        return $out;
    }
}
