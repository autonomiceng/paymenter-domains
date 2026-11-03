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
}
