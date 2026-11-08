<?php
// Native light/dark What you sell captures; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';require __DIR__.'/Support/storefront-catalogue.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Auth,Blade,DB};
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{PricingRule,StaffSettingsReview as Review};
$out=renderOutput($argv[1]??null);
$f=commerceFixture();extract($f);staffSalesFixture();tldPanel();Carbon\Carbon::setTestNow($now);
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
[$first,$second]=$tlds;
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.registration'=>true]);
App\Models\Server::whereKey($first->server_id)->update(['name'=>'Enom account']);
App\Models\Server::whereKey($second->server_id)->update(['name'=>'NameSilo account']);
App\Models\Setting::updateOrCreate(['key'=>'domains_pricing_default_rule','settingable_type'=>null,'settingable_id'=>null],['type'=>'array','encrypted'=>false,
    'value'=>['mode'=>'markup_fee_percent','markup'=>'5','round_up'=>'ending_99']]);
activeFixtureRepublish($prices,$first);
storefrontFixtureEdit($second,rule:storefrontFixtureRule('7.80','8.10','9.10'));
DB::table('domains_prices')->where(['server_id'=>$second->server_id,'action'=>'renew','term'=>1])->update(['retail'=>'8.10']);
// Rows the demo has none of: a saved price below cost, a term with no saved price, a TLD left out and one the registrar no longer lists. They show the red loss, Missing, the amber line and the blocked row.
DB::table('domains_prices')->where(['server_id'=>$first->server_id,'action'=>'register','term'=>1])->update(['retail'=>'5.00']);
DB::table('domains_prices')->where(['server_id'=>$first->server_id,'action'=>'register','term'=>3])->update(['retail'=>null]);
$left=$first->replicate();$left->tld='bad';$left->use_default_rule=false;$left->pricing_mode='unknown';
$left->catalogue_intent=json_encode([...\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($left->catalogue_intent),'pricing_rule'=>['mode'=>'unknown']]);$left->save();
$gone=$first->replicate();$gone->tld='gone';
$gone->catalogue_facts=json_encode([...\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($gone->catalogue_facts),'terms'=>['register'=>[],'renew'=>[],'transfer'=>[]]]);$gone->save();
$u=staffUser([Staff::VIEW,Staff::COSTS,Staff::POLICY,Review::permission('acceptance')]);Auth::setUser($u);
$panel=Filament::getCurrentPanel();$css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
$panel->viteTheme([])->theme(new HtmlString('<link rel="stylesheet" href="fixture.css">'))->brandName('Paymenter');
foreach([1,3] as $years){
    $test=Livewire::actingAs($u)->test(WhatYouSell::class,['tableFilters'=>['for_sale'=>['isActive'=>false]]])->assertSuccessful();
    // Resolve the sidebar's original request during the initial GET, before a test-only Livewire update.
    same('GET',\Filament\Support\original_request()->method(),'Static layout retains a GET request');
    $test->set('account',$first->server_id)->set('years',$years);
    $html=Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',['page'=>$test->instance(),'body'=>$test->html()]);
    $html=preg_replace('~<script\b[^>]*>.*?</script>~si','',$html);$html=preg_replace('~<link\b[^>]*>~si','',$html);
    $html=str_replace('x-cloak="-lg"','',$html);$html=str_replace('class="fi-main-ctn"','style="display:flex;opacity:1" class="fi-main-ctn"',$html);
    $html=preg_replace('~src="https?://[^\"]+"~','src="data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#64748b"/></svg>').'"',$html);
    $html=str_replace('</head>','<style>'.$css.'</style>'.\Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles().'</head>',$html);
    expect(str_contains($html,'How to read this table'),'Explanation in render');
    foreach(['light','dark'] as $mode){
        $page=preg_replace('~<html\b[^>]*>~','<html lang="en" class="'.$mode.'">',$html,1);
        writeRenderArtifact($out.'/what-you-sell-'.$years.'-year-'.$mode.'.html',$page);
    }
    $dom=new DOMDocument;@$dom->loadHTML($html);$xpath=new DOMXPath($dom);
    expect((int)$xpath->evaluate('count(//*[@data-price-omission]/following::*[contains(concat(" ",normalize-space(@class)," ")," fi-ta-header-toolbar ")])')>0,'Omission alert above the toolbar in render');expect(str_contains($html,'Not offered by this registrar for this term.'),'Blocked row reason in render');
    expect($years===3?str_contains($html,'To add one, open the TLD page and use Edit prices.'):str_contains($html,'This price is below registrar cost.'),'Loss and Missing in render');
}
echo "Native What you sell rendered in light/dark for 1 and 3 years.\n";$nativeSuccessful=true;
