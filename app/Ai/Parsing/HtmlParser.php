<?php

namespace App\Ai\Parsing;

use DOMDocument;
use DOMElement;
use DOMXPath;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

final class HtmlParser implements DocumentParser
{
    private const NOISE = ['script', 'style', 'nav', 'header', 'footer', 'aside', 'form', 'noscript', 'iframe', 'svg'];

    public function supports(string $extension): bool
    {
        return in_array($extension, ['html', 'htm'], true);
    }

    public function parse(string $path): string
    {
        $html = (string) file_get_contents($path);

        return $this->toMarkdown($html);
    }

    public function toMarkdown(string $html): string
    {
        $document = new DOMDocument;

        libxml_use_internal_errors(true);

        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        libxml_clear_errors();

        $xpath = new DOMXPath($document);

        foreach (self::NOISE as $tag) {
            foreach (iterator_to_array($xpath->query("//{$tag}")) as $node) {
                $node->parentNode?->removeChild($node);                          // navigation, scripts and footers are not content
            }
        }

        $content = $this->contentElement($xpath, $document);

        $converter = new HtmlConverter(['strip_tags' => true, 'header_style' => 'atx', 'hard_break' => false, 'remove_nodes' => 'script style']);

        $converter->getEnvironment()->addConverter(new TableConverter);

        $markdown = $converter->convert($document->saveHTML($content));

        return trim(preg_replace("/\n{3,}/", "\n\n", $markdown))."\n";
    }

    private function contentElement(DOMXPath $xpath, DOMDocument $document): DOMElement
    {
        foreach (['//main', '//article', '//*[@role="main"]', '//body'] as $query) {
            $found = $xpath->query($query)->item(0);

            if ($found instanceof DOMElement) {
                return $found;
            }
        }

        return $document->documentElement;
    }
}
