<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\Support\Htmlable;
/**
 * Staff rows are one line. Long text ends in "..." (staff-styles) and the tooltip has all of it, when it may be cut.
 * A description sits after the value on the same line, so a Missing keeps its next step beside it.
 */
final class OneLine
{
    /** A domain name: 326px of mono, padding included (the approved staff table). */
    public const NAME=['domains-name-cell',30];
    /** A person or account: 120px, padding included. Glyph widths decide where so short a line is cut, so any nonempty text
     * keeps its full text in the tooltip. */
    public const SHORT=['domains-cut',0];
    /** A note or a longer name: 24rem. */
    public const LINE=['domains-line',44];
    /** A list Note: its cell takes the rest of the row, and 160px when the table scrolls. Glyph widths and the table decide
     * where it is cut, so every nonempty note keeps its full text in the tooltip. */
    public const NOTE=['domains-line domains-list-note',0,'domains-note-cell'];
    /** @param array{0:string,1:int,2?:string} $kind The item class, the length that always fits, and an optional cell class. */
    public static function apply(TextColumn $column, array $kind=self::LINE): TextColumn
    {
        if (isset($kind[2])) { $column->extraCellAttributes(['class'=>$kind[2]],merge:true); }
        $tip=fn($state,TextColumn $column)=>self::tip(trim(self::text($state).' '.$column->getDescriptionBelow()),$kind[1]);
        // Keyboard focus shows a cut text in place (staff-styles). A row that opens nothing has no link or button to
        // focus, so the cut text takes focus itself.
        return $column->tooltip($tip)->extraAttributes(['class'=>$kind[0]],merge:true)
            ->extraAttributes(fn($state,TextColumn $column,$record)=>$tip($state,$column)!==null&&!self::opens($column,$record)?['tabindex'=>'0']:[],merge:true);
    }
    /** Whether Filament wraps this cell in a link or button (tables index.blade.php). */
    private static function opens(TextColumn $column, mixed $record): bool
    {
        if ($column->isClickDisabled()||$record===null) { return false; }
        $table=$column->getTable();
        return filled($column->getUrl())||filled($column->getAction())||filled($table->getRecordUrl($record))||filled($table->getRecordAction($record));
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
