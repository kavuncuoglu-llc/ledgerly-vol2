<?php

namespace App\Ai\Parsing;

use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use Throwable;

final class DocxParser implements DocumentParser
{
    public function supports(string $extension): bool
    {
        return $extension === 'docx';
    }

    public function parse(string $path): string
    {
        try {
            $word = IOFactory::load($path);
        } catch (Throwable $e) {
            throw new ParsingFailed("Could not read {$path}: ".$e->getMessage(), previous: $e);
        }

        $blocks = [];

        foreach ($word->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $block = $this->block($element);

                if ($block !== '') {
                    $blocks[] = $block;
                }
            }
        }

        return implode("\n\n", $blocks)."\n";
    }

    private function block(AbstractElement $element): string
    {
        if ($element instanceof Title) {
            return str_repeat('#', max(1, min(6, (int) $element->getDepth()))).' '.$this->text($element->getText());
        }

        if ($element instanceof ListItem) {
            return '- '.$this->text($element->getTextObject());
        }

        if ($element instanceof ListItemRun) {
            return '- '.$this->text($element);
        }

        if ($element instanceof Table) {
            return $this->table($element);
        }

        if ($element instanceof TextBreak) {
            return '';
        }

        $text = trim($this->text($element));

        if ($text === '') {
            return '';
        }

        $level = $element instanceof TextRun
            ? $this->headingLevel($element)
            : 0;

        return $level > 0
            ? str_repeat('#', $level).' '.$text
            : $text;
    }

    private function headingLevel(TextRun $run): int
    {
        $style = $run->getParagraphStyle();

        $name = is_object($style) && method_exists($style, 'getStyleName')
            ? (string) $style->getStyleName()
            : (string) $style;

        if (preg_match('/^Heading\s*(\d)$/i', $name, $match) === 1) {
            return (int) $match[1];
        }

        return strcasecmp($name, 'Title') === 0
            ? 1
            : 0;
    }

    private function table(Table $table): string
    {
        $rows = [];

        foreach ($table->getRows() as $row) {
            $cells = [];

            foreach ($row->getCells() as $cell) {
                $cells[] = str_replace('|', '\\|', trim(implode(' ', array_map(fn ($element) => $this->text($element), $cell->getElements()))));
            }

            $rows[] = '| '.implode(' | ', $cells).' |';
        }

        if ($rows === []) {
            return '';
        }

        $columns = substr_count($rows[0], '|') - 1;

        array_splice($rows, 1, 0, ['|'.str_repeat('---|', $columns)]);

        return implode("\n", $rows);
    }

    private function text(mixed $element): string
    {
        if (is_string($element)) {
            return $element;
        }

        if ($element instanceof Text) {
            return (string) $element->getText();
        }

        if ($element instanceof TextRun || $element instanceof ListItemRun) {
            return implode('', array_map(fn ($child) => $this->text($child), $element->getElements()));
        }

        return method_exists($element, 'getText')
            ? (string) $element->getText()
            : '';
    }
}
