<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Renders the staff Domains list with a long name, customer and note; no public I/O.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-panel.php';
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\{Auth, Blade};
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ListDomains;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
$out=renderOutput($argv[1]??null);
staffPanel();
// A host panel renders with its own skin: DOMAINS_PANEL_SETUP names a PHP file that is given $panel, configures it
// (table class, layout, its sheet after the theme) and returns its built theme stylesheet. Without it, Filament's stock theme.
// The fixture has no theme build, so the theme is the first styles.after hook, and a skin registered after it follows it.
$panel=\Filament\Facades\Filament::getCurrentPanel();$panel->viteTheme([])->theme(new HtmlString(''));$theme='';
FilamentView::registerRenderHook(PanelsRenderHook::STYLES_AFTER,function()use(&$theme){return new HtmlString('<style>'.$theme.'</style>');});
$setup=(string)getenv('DOMAINS_PANEL_SETUP');
$theme=$setup!==''?(static fn(string $file,$panel):string=>require $file)($setup,$panel):file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
[$held,$active,$long]=staffData();
// The long name is a held transfer, so its row also has a Note. A long customer name fills the 120px column.
// The binding is immutable, so the customer takes the row's synthetic user id.
(new App\Models\User)->forceFill(['id'=>$long->user_id,'first_name'=>'Maximiliana-Alexandrina','last_name'=>'Featherstonehaugh-Worthington',
    'email'=>'long-customer@example.test','password'=>'synthetic-password'])->save();
Auth::setUser(staffUser([Staff::VIEW]));
$list=Livewire::test(ListDomains::class);
$html=Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',['page'=>$list->instance(),'body'=>$list->html()]);
$html=preg_replace('~<script\b[^>]*>.*?</script>|<link\b[^>]*>~si','',$html);
// As installed: the staff sheet follows the theme and any skin (STYLES_AFTER). The browser check also tries the reverse order.
expect(substr_count($html,'</head>')===1,'One document head');
writeRenderArtifact($out.'/staff-domains-list.html',str_replace('</head>',view('domains::partials.staff-styles')->render().'</head>',$html));
$nativeSuccessful=true;echo "Staff Domains list rendered.\n";
