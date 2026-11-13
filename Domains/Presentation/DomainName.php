<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
use Illuminate\Support\HtmlString;
/** A domain name that wraps after dots and hyphens, not inside a label. Staff tables keep names on one line instead. */
final class DomainName
{
    /** Escaped name with a line break opportunity after each dot and hyphen. */
    public static function html(string $name): HtmlString
    {
        return new HtmlString(str_replace(['.', '-'], ['.<wbr>', '-<wbr>'], e($name)));
    }
    /** Characters kept before the TLD when a long name is cut in the middle. */
    private const TAIL = 5;
    /** A table cell name: one line, its content width up to the cap in partials/domain-name-styles, then cut in the middle
     * so the TLD always shows ("beco-test-fc9d…7b02d.com"). Hover (title) and screen readers get the full name; copy picks
     * up the full name from the two visible parts. A phone opens the row's detail page for it. Needs domain-name-styles. */
    public static function cell(string $name): HtmlString
    {
        $dot = strrpos($name, '.');
        $split = max(0, ($dot === false || $dot === 0 ? mb_strlen($name) : mb_strlen(substr($name, 0, $dot))) - self::TAIL);
        $head = mb_substr($name, 0, $split);$tail = mb_substr($name, $split);
        return new HtmlString('<span class="domains-dn" title="'.e($name).'"><span class="domains-dn-sr">'.e($name).'</span>'
            .'<span class="domains-dn-head" aria-hidden="true">'.e($head).'</span><span class="domains-dn-tail" aria-hidden="true">'.e($tail).'</span></span>');
    }
}
