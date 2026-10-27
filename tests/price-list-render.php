<?php
// Native light/dark price-list captures; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Auth,Blade,DB};
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\PriceList;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\PricingRule;
$out=$argv[1]??'';expect(is_dir($out)&&is_writable($out),'Output directory required');
$f=commerceFixture();extract($f);tldPanel();Carbon\Carbon::setTestNow($now);
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
[$first,$second]=$tlds;
App\Models\Server::whereKey($first->server_id)->update(['name'=>'Enom account']);
App\Models\Server::whereKey($second->server_id)->update(['name'=>'NameSilo account']);
App\Models\Server::findOrFail($first->server_id)->settings()->updateOrCreate(['key'=>'domains_pricing_default_rule'],['type'=>'array','encrypted'=>false,
    'value'=>['mode'=>'markup_fee_percent','markup'=>'5','round_up'=>'ending_99']]);
$prices->republish($first);
DB::table('domains_tlds')->where('id',$second->id)->update(['use_default_rule'=>false,'pricing_mode'=>'fixed','round_up'=>'none',
    'fixed_register'=>'7.80','fixed_renew'=>'8.10','fixed_transfer'=>'9.10']);
DB::table('domains_prices')->where(['server_id'=>$second->server_id,'action'=>'renew','term'=>1])->update(['retail'=>'8.10']);
$u=staffUser([Staff::VIEW,Staff::COSTS]);Auth::setUser($u);
$panel=Filament::getCurrentPanel();$css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
$panel->viteTheme([])->theme(new HtmlString('<link rel="stylesheet" href="fixture.css">'))->brandName('Paymenter');
foreach([1,3] as $years){
    $test=Livewire::actingAs($u)->test(PriceList::class)->assertSuccessful();
    // Resolve the sidebar's original request during the initial GET, before a test-only Livewire update.
    same('GET',\Filament\Support\original_request()->method(),'Static layout retains a GET request');
    $test->set('years',$years);
    $html=Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',['page'=>$test->instance(),'body'=>$test->html()]);
    $html=preg_replace('~<script\b[^>]*>.*?</script>~si','',$html);$html=preg_replace('~<link\b[^>]*>~si','',$html);
    $html=str_replace('x-cloak="-lg"','',$html);$html=str_replace('class="fi-main-ctn"','style="display:flex;opacity:1" class="fi-main-ctn"',$html);
    $html=preg_replace('~src="https?://[^\"]+"~','src="data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#64748b"/></svg>').'"',$html);
    $html=str_replace('</head>','<style>'.$css.'</style>'.\Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles().'</head>',$html);
    expect(str_contains($html,'How prices are worked out'),'Explanation in render');
    foreach(['light','dark'] as $mode){
        $page=preg_replace('~<html\b[^>]*>~','<html lang="en" class="'.$mode.'">',$html,1);
        writeRenderArtifact($out.'/price-list-'.$years.'-year-'.$mode.'.html',$page);
    }
}
echo "Native price list rendered in light/dark for 1 and 3 years.\n";$nativeSuccessful=true;
