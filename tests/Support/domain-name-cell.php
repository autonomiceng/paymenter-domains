<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

/** Escaped name with a line break opportunity after each dot and hyphen. */
function domainNameMarkup(string $name): string
{
    return str_replace(['.', '-'], ['.<wbr>', '-<wbr>'], e($name));
}

/** @return array<string,string> */
function domainNameStyle(Dom\Element $node): array
{
    $style = [];
    foreach (explode(';', (string) $node->getAttribute('style')) as $rule) {
        [$property, $value] = array_map('trim', explode(':', $rule, 2) + [1 => '']);
        if ($property !== '') { $style[strtolower($property)] = strtolower($value); }
    }
    return $style;
}

/**
 * A domain name wraps only at dots and hyphens. In a table cell, break-word keeps the column at its longest
 * label, so a normal name stays on one line, and max-width still breaks one label wider than the cell.
 * A link or page title sits in a flex or grid item, where break-word would push a long label past the column,
 * so it keeps anywhere: the <wbr> breaks come first. $element is 'table', 'link' or 'title'.
 */
function assertDomainNameWraps(string $html, string $name, string $where, string $element = 'table'): void
{
    // The HTML5 parser treats <wbr> as a void element, as browsers do.
    $dom = Dom\HTMLDocument::createFromString('<!doctype html><meta charset="utf-8">'.$html, LIBXML_NOERROR);
    $items = array_values(array_filter(iterator_to_array($dom->querySelectorAll(['table'=>'.fi-ta-text-item', 'link'=>'a', 'title'=>'span.font-mono'][$element])),
        static fn(Dom\Element $node) => trim($node->textContent) === $name));
    same(1, count($items), $where.': one rendered domain name');
    $item = $items[0];
    $markup = trim($item->innerHTML);
    same(domainNameMarkup($name), $markup, $where.': line breaks only after dots and hyphens, got '.$markup);
    $cell = $element === 'table' ? $item->closest('.fi-ta-text') : $item;
    expect($cell instanceof Dom\Element, $where.': domain cell found');
    $style = domainNameStyle($cell);
    same($element === 'table' ? 'break-word' : 'anywhere', $style['overflow-wrap'] ?? null, $where.': labels break only when wider than the cell');
    expect(!isset($style['word-break']), $where.': no word-break override');
    if ($element === 'table') { same('11rem', $style['min-width'] ?? null, $where.': column keeps a minimum width'); }
}
