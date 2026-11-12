<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\View\ComponentAttributeBag;
/** Status marks for staff pages. A skin may draw them from data-domains-tone. Without one the attribute does nothing and the badge and words stay plain Filament.
 * data-domains-record names the record a status belongs to, so a skin can tell a real status change from a table reusing the row for another record. */
final class StatusMark
{
    /** Status colours => data-domains-tone. Unknown colours get no mark and keep stock styling. */
    public const TONES=['success'=>'ok','gray'=>'off','info'=>'prog','warning'=>'warn','danger'=>'err'];
    public static function tone(?string $color): ?string { return self::TONES[$color]??null; }
    /** Attributes for a cell or entry that holds a status badge. Every caller names the record, or passes null for a row that never changes. */
    public static function attributes(?string $color,?string $record): array
    { return ['data-domains-tone'=>self::tone($color),'data-domains-record'=>$record]; }
    /** Attributes for words that say what staff must do. They get the amber mark and keep their own colour. */
    public static function note(bool $act): array
    { return ['class'=>'domains-note','data-domains-tone'=>$act?'warn':null]; }
    /** The status badge in a page head. */
    public static function head(string $color,string $label,string $record): HtmlString
    {
        $badge=view('filament::components.badge',['color'=>$color,'size'=>'lg','slot'=>new HtmlString(e($label))])->render();
        return new HtmlString('<span class="domains-head-state" '.new ComponentAttributeBag(self::attributes($color,$record)).'>'.$badge.'</span>');
    }
    /** The one status line under a page title: mark, state, then the step or link if there is one. */
    public static function line(string $color,string $label,string $record,string|Htmlable|null $step=null): HtmlString
    {
        $step=$step instanceof Htmlable?$step->toHtml():e((string)$step);
        return new HtmlString('<span class="domains-head-line">'.self::head($color,$label,$record)->toHtml().($step!==''?' <span class="domains-head-step">'.$step.'</span>':'').'</span>');
    }
    /** A line of $html that names a job for staff. */
    public static function act(HtmlString $html): HtmlString
    { return new HtmlString('<span class="domains-note" data-domains-tone="warn">'.$html->toHtml().'</span>'); }
}
