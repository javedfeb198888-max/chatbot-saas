<?php

namespace App\Training;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Turns a web page into plain readable text: drops menus, footers, scripts
 * and forms, and keeps headings, paragraphs, lists and table rows.
 */
class HtmlTextExtractor
{
    private const REMOVE = [
        'script', 'style', 'noscript', 'template', 'svg', 'iframe', 'canvas',
        'nav', 'header', 'footer', 'aside', 'form', 'button', 'select',
    ];

    private const BLOCKS = [
        'p', 'div', 'section', 'article', 'main', 'li', 'tr', 'blockquote', 'pre',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'dt', 'dd', 'figcaption', 'address', 'br',
    ];

    private const SPACED = ['td', 'th', 'label', 'option'];

    public function extract(string $html): ExtractedPage
    {
        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);
        // The XML prefix makes DOMDocument read the page as UTF-8.
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);

        $title = $this->clean($xpath->evaluate('string(//title)')) ?: null;

        foreach (self::REMOVE as $tag) {
            foreach (iterator_to_array($xpath->query('//'.$tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        // Prefer the main content area when the page marks one.
        $root = $xpath->query('//main')->item(0)
            ?? $xpath->query('//article')->item(0)
            ?? $xpath->query('//body')->item(0);

        if ($root === null) {
            return new ExtractedPage($title, '');
        }

        $lines = [];
        $this->collect($root, $lines);

        $paragraphs = [];

        foreach ($lines as $line) {
            $line = $this->clean($line);

            if ($line !== '' && end($paragraphs) !== $line) {
                $paragraphs[] = $line;
            }
        }

        return new ExtractedPage($title, implode("\n\n", $paragraphs));
    }

    /** @param  array<int, string>  $lines */
    private function collect(DOMNode $node, array &$lines): void
    {
        $buffer = '';

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $buffer .= $child->textContent;

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            if (in_array(strtolower($child->tagName), self::BLOCKS, true)) {
                $lines[] = $buffer;
                $buffer = '';
                $this->collect($child, $lines);
            } elseif ($this->containsBlock($child)) {
                $lines[] = $buffer;
                $buffer = '';
                $this->collect($child, $lines);
            } elseif (in_array(strtolower($child->tagName), self::SPACED, true)) {
                // Table cells sit side by side with no space between them in the HTML.
                $buffer .= ' '.$child->textContent.' ';
            } else {
                // Bold, links and other inline tags: keep the sentence exactly as written.
                $buffer .= $child->textContent;
            }
        }

        $lines[] = $buffer;
    }

    private function containsBlock(DOMElement $element): bool
    {
        foreach (self::BLOCKS as $tag) {
            if ($element->getElementsByTagName($tag)->length > 0) {
                return true;
            }
        }

        return false;
    }

    private function clean(string $text): string
    {
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
