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

/** A staff table keeps a domain name on one line in the shared cell (DomainName::cell): no break points, the full name for
 * hover and screen readers, and a long name cut in its head so the TLD stays. */
function assertDomainNameOneLine(string $html, string $name, string $where): void
{
    $dom = Dom\HTMLDocument::createFromString('<!doctype html><meta charset="utf-8">'.$html, LIBXML_NOERROR);
    $items = array_values(array_filter(iterator_to_array($dom->querySelectorAll('.fi-ta-text-item .domains-dn')), static fn(Dom\Element $node) => $node->getAttribute('title') === $name));
    same(1, count($items), $where.': one rendered domain name');
    same(0, $items[0]->querySelectorAll('wbr')->length, $where.': no line breaks in the name, got '.trim($items[0]->innerHTML));
    $part = static fn(string $class): string => (string) $items[0]->querySelector('.'.$class)?->textContent;
    same([$name, $name], [$part('domains-dn-sr'), $part('domains-dn-head').$part('domains-dn-tail')], $where.': screen readers and the visible parts read the full name');
    expect(str_contains((string) $items[0]->closest('.fi-ta-text')?->getAttribute('class'), 'domains-name-cell'), $where.': the cell is a one-line name');
    $sheet = file_get_contents(dirname(__DIR__,2).'/Domains/resources/views/partials/domain-name-styles.blade.php');
    expect(preg_match('/\.domains-dn\{[^}]*white-space:nowrap/', $sheet) === 1, $where.': the name stays on one line');
    expect(preg_match('/\.domains-dn-head\{[^}]*text-overflow:ellipsis/', $sheet) === 1 && preg_match('/\.domains-dn-tail\{flex:none\}/', $sheet) === 1, $where.': a cut name ends its head in "..." and keeps the TLD');
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
