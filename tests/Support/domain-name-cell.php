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

/** A staff table exposes the full domain name exactly once. */
function assertDomainNameOneLine(string $html, string $name, string $where): void
{
    $dom = Dom\HTMLDocument::createFromString('<!doctype html><meta charset="utf-8">'.$html, LIBXML_NOERROR);
    $items = array_values(array_filter(iterator_to_array($dom->querySelectorAll('.fi-ta-text-item')), static fn(Dom\Element $node) => trim($node->textContent) === $name));
    same(1, count($items), $where.': one rendered domain name');
}

/**
 * A domain name in a link or page title wraps only at dots and hyphens. It sits in a flex or grid item, where
 * break-word would push a long label past the column, so it keeps anywhere: the <wbr> breaks come first.
 * $element is 'link' or 'title'.
 */
function assertDomainNameWraps(string $html, string $name, string $where, string $element = 'link'): void
{
    // The HTML5 parser treats <wbr> as a void element, as browsers do.
    $dom = Dom\HTMLDocument::createFromString('<!doctype html><meta charset="utf-8">'.$html, LIBXML_NOERROR);
    $items = array_values(array_filter(iterator_to_array($dom->querySelectorAll(['link'=>'a', 'title'=>'span.domains-wrap, span.domains-panel-head-title'][$element])),
        static fn(Dom\Element $node) => trim($node->textContent) === $name));
    same(1, count($items), $where.': one rendered domain name');
    $item = $items[0];
    $markup = trim($item->innerHTML);
    same(domainNameMarkup($name), $markup, $where.': line breaks only after dots and hyphens, got '.$markup);
    // A title with domains-wrap, or a panel head title, wraps from the Domains staff stylesheet, so a plain install wraps it too.
    $wrap = array_values(array_intersect(['domains-wrap', 'domains-panel-head-title'], explode(' ', (string) $item->getAttribute('class'))))[0] ?? null;
    if ($wrap !== null) { expect(str_contains(file_get_contents(dirname(__DIR__,2).'/Domains/resources/views/partials/staff-styles.blade.php'), '.'.$wrap.','), $where.': the staff stylesheet wraps the name'); }
    else { same('anywhere', domainNameStyle($item)['overflow-wrap'] ?? null, $where.': labels break only when wider than the line'); }
    expect(!isset(domainNameStyle($item)['word-break']), $where.': no word-break override');
}
