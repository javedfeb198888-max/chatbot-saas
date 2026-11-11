<?php

namespace App\Training;

use App\Training\Exceptions\TrainingException;
use SimpleXMLElement;

class SitemapParser
{
    /**
     * @return array{pages: array<int, string>, sitemaps: array<int, string>}
     */
    public function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = new SimpleXMLElement($xml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        } catch (\Exception) {
            throw new TrainingException('This sitemap could not be read.');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $locations = fn (string $tag) => array_values(array_filter(array_map(
            fn ($node) => trim((string) $node),
            $document->xpath("//*[local-name()='{$tag}']/*[local-name()='loc']") ?: [],
        )));

        return [
            'pages' => $locations('url'),
            'sitemaps' => $locations('sitemap'),
        ];
    }
}
