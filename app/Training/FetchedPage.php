<?php

namespace App\Training;

class FetchedPage
{
    public function __construct(
        public readonly string $url,
        public readonly string $body,
        public readonly string $contentType,
    ) {
    }

    public function isHtml(): bool
    {
        return str_contains($this->contentType, 'html');
    }

    public function isXml(): bool
    {
        return str_contains($this->contentType, 'xml') || str_starts_with(ltrim($this->body), '<?xml');
    }
}
