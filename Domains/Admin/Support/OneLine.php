<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\Support\Htmlable;
/** Content-sized staff columns. Only a linked name or note can shorten; structured values stay complete. */
final class OneLine
{
    /** A linked domain name may shorten when structured columns use the available room. */
    public const NAME=['domains-name-cell',30];
    /** A person or account wraps and keeps its complete value. */
    public const SHORT=['domains-cut',0];
    /** Ordinary text stays complete. */
    public const LINE=['domains-line',44];
    /** A linked note shares the remaining room with the name; unlinked notes wrap. */
    public const NOTE=['domains-line domains-list-note',0,'domains-note-cell'];
    /** @param array{0:string,1:int,2?:string} $kind The item class, the length that always fits, and an optional cell class. */
    public static function apply(TextColumn $column, array $kind=self::LINE): TextColumn
    {
        if (isset($kind[2])) { $column->extraCellAttributes(['class'=>$kind[2]],merge:true); }
        return $column->extraAttributes(['class'=>$kind[0]],merge:true);
    }
    /** The full text when it is longer than $fits characters. With $fits 0, any nonempty text. */
    public static function tip(mixed $text, int $fits): ?string
    {
        $text=self::text($text);
        return mb_strlen($text)>$fits?$text:null;
    }
    private static function text(mixed $text): string
    { return trim(html_entity_decode(strip_tags($text instanceof Htmlable?$text->toHtml():(string)$text))); }
}
