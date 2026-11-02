<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\Support\Htmlable;
/** Staff rows are one line. Long text ends in "..." (staff-styles) and the tooltip has all of it, only when it may be cut. */
final class OneLine
{
    /** A domain name: 24rem of mono. */
    public const NAME=['domains-name-cell',38];
    /** A person or account: 11rem. */
    public const SHORT=['domains-cut',18];
    /** A note or a longer name: 24rem. */
    public const LINE=['domains-line',44];
    /** @param array{0:string,1:int} $kind */
    public static function apply(TextColumn $column, array $kind=self::LINE): TextColumn
    { return $column->tooltip(fn($state)=>self::tip($state,$kind[1]))->extraAttributes(['class'=>$kind[0]],merge:true); }
    /** The full text when it is longer than $fits characters. */
    public static function tip(mixed $text, int $fits): ?string
    {
        $text=trim(html_entity_decode(strip_tags($text instanceof Htmlable?$text->toHtml():(string)$text)));
        return mb_strlen($text)>$fits?$text:null;
    }
}
