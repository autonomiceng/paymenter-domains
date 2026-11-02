<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\View\TablesRenderHook;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell;
use Paymenter\Extensions\Servers\Domains\Services\HookRegistration;
/** The account and years choices in the What you sell toolbar, and the note under it. Filament has no slot for either in a table's own config. */
final class SellToolbar
{
    public static function boot(): void
    {
        if(!HookRegistration::claim(FilamentView::getFacadeRoot(),self::class)){return;}
        FilamentView::registerRenderHook(TablesRenderHook::TOOLBAR_SEARCH_BEFORE,static fn():string=>view('domains::admin.sell-toolbar',['page'=>Livewire::current()])->render(),WhatYouSell::class);
        // This hook takes no scope, so the page is checked here.
        FilamentView::registerRenderHook(TablesRenderHook::TOOLBAR_AFTER,static function():string {
            $page=Livewire::current();
            if(!$page instanceof WhatYouSell||($note=$page->tableNote())===null){return '';}
            return '<p class="domains-sell-note">'.e($note).'</p>';
        });
    }
}
