<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Illuminate\Support\HtmlString;
/** The one on/off look in Domains Admin. With an action it is a click; without one it only shows the state. */
final class OnOffSwitch
{
    /** $word is false where the row's value already says On or Off. */
    public static function apply(Action $action,bool $on,string $label,bool $word=true): Action
    { return $action->view('domains::admin.switch',['on'=>$on,'label'=>$label,'word'=>$word]); }
    public static function html(bool $on,string $label='',bool $word=true): HtmlString
    { return new HtmlString(view('domains::admin.switch',['on'=>$on,'label'=>$label,'word'=>$word])->render()); }
}
