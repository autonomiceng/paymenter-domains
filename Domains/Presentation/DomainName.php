<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
use Illuminate\Support\HtmlString;
/** A domain name that wraps after dots and hyphens, not inside a label. */
final class DomainName
{
    /** Table cell style. break-word keeps the column as wide as the longest label, so a normal name stays on
     * one line; max-width still breaks a single label wider than the cell. */
    public const CELL = 'overflow-wrap:break-word;min-width:11rem;max-width:24rem';
    /** Escaped name with a line break opportunity after each dot and hyphen. */
    public static function html(string $name): HtmlString
    {
        return new HtmlString(str_replace(['.', '-'], ['.<wbr>', '-<wbr>'], e($name)));
    }
}
