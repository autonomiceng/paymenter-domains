<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Writes a staff list page as installed, in the stock theme or a host panel's skin; no public I/O.
declare(strict_types=1);
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
/**
 * Call before the list is built. A host panel renders with its own skin: DOMAINS_PANEL_SETUP names a PHP file that is
 * given $panel, configures it (table class, layout, its sheet after the theme) and returns its built theme stylesheet.
 * Without it, Filament's stock theme. The fixture has no theme build, so the theme is the first styles.after hook, and
 * a skin registered after it follows it.
 */
function panelTheme(string $fixture): void
{
    $panel=Filament::getCurrentPanel();$panel->viteTheme([])->theme(new HtmlString(''));$theme='';
    FilamentView::registerRenderHook(PanelsRenderHook::STYLES_AFTER,function()use(&$theme){return new HtmlString('<style>'.$theme.'</style>');});
    $setup=(string)getenv('DOMAINS_PANEL_SETUP');
    $theme=$setup!==''?(static fn(string $file,$panel):string=>require $file)($setup,$panel):file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
}
/** The list page in the panel layout, without scripts. As installed, the staff sheet follows the theme and any skin. */
function writePanelPage(string $path, mixed $list): void
{
    $html=Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',['page'=>$list->instance(),'body'=>$list->html()]);
    $html=preg_replace('~<script\b[^>]*>.*?</script>|<link\b[^>]*>~si','',$html);
    expect(substr_count($html,'</head>')===1,'One document head');
    writeRenderArtifact($path,str_replace('</head>',view('domains::partials.staff-styles')->render().'</head>',$html));
}
