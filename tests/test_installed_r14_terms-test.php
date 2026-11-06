<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB,Blade};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldTerms};
use Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ListTlds;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting,StaffCatalogue,Capabilities};
extract(commerceFixture());tldPanel();pinStaffClock($now);$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);$t=$tlds[0];
$probe=new ReflectionProperty(CallerRouting::class,'probe');$probe->setValue(null,[StaffCatalogue::class=>new StaffCatalogue($ports,$clock),Capabilities::class=>$caps]);
DB::table('domains_observation_queues')->updateOrInsert(['server_id'=>$t->server_id,'account_fingerprint'=>$t->account_fingerprint],['offer_feed_succeeded_at'=>$now->getTimestamp(),'offer_work'=>json_encode(['feed_due'=>$now->getTimestamp()+86400])]);
// A retained term total whose feed freshness expired must remain Missing.
$stale=new \Paymenter\Extensions\Servers\Domains\Catalogue\Cost('register',3,true,'18.0000',\Paymenter\Extensions\Servers\Domains\Data\CostUnit::TermTotal,'synthetic',$now->modify('-2 days'),$now->modify('-1 day'));
DB::table('domains_prices')->where('server_id',$t->server_id)->where('action','register')->where('term',3)->update(['catalogue_observation'=>\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::json(\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::encodeCost($stale))]);
$offer=(new StaffCatalogue($ports,$clock))->rows([\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$t->server_id)->sole())],$t->tld)->sole();
// Mounting the actual native bulk action is required: the old test rendered the price partial alone.
$page=Livewire::actingAs($actor)->test(WhatYouSell::class);$key=$page->instance()->getTableRecordKey($offer);
$page->mountTableBulkAction('edit',[$key])->call('$refresh');$modal=$page->effects['partials']['action-modals.0']??'';
expect(str_contains($modal,'Missing'),'Fixture includes unpriced registrar term totals');$errors=[];
foreach(['No saved registrar total for these terms.','Prices updated ','Next update ','Sync prices is an optional retry.']as$text){if(!str_contains($modal,$text)){$errors[]='Terms dialog lacks '.$text;}}
if(str_contains($modal,'Sync prices to read these term totals')){$errors[]='Terms still requires a sync';}
// Three selected endings with fresh term facts, and no ending needing attention.
$policy=\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld',$t->tld)->sole()->getAttributes();unset($policy['id']);
foreach(['net','org']as$ending){$copy=$t->replicate();$copy->tld=$ending;$copy->save();DB::table('domains_sales_policies')->insert([...$policy,'tld'=>$ending]);}
same(3,TldTerms::summary((int)$t->server_id)['total'],'Three selected endings remain');
$list=Livewire::actingAs($actor)->test(ListTlds::class)->filterTable('server_id',(string)$t->server_id)->filterTable('needs_attention')->assertCountTableRecords(0);
$empty=$list->html();if(!str_contains($empty,'No TLD terms need attention')){$errors[]='Attention-empty view still says no TLDs';}
if(str_contains($empty,'Choose what you sell on a registrar account.')){$errors[]='Filtered-empty view asks to select existing endings';}
$panel=\Filament\Facades\Filament::getCurrentPanel();$panel->viteTheme([])->theme(new \Illuminate\Support\HtmlString(''));
$request=\Illuminate\Http\Request::create('http://domains-tests.invalid/','GET');$request->setLaravelSession(app('session')->driver());$request->setRouteResolver(fn()=>app('router')->getRoutes()->getByName('home'));app()->instance('request',$request);\Illuminate\Support\Facades\Facade::clearResolvedInstance('request');
$html=Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',['page'=>$list->instance(),'body'=>$empty]);
$html=preg_replace('~<script\b[^>]*>.*?</script>|<link\b[^>]*>~si','',$html);
writeRenderArtifact((getenv('R14_RENDER_DIR')?:sys_get_temp_dir()).'/r14-terms-empty.html',str_replace('</head>','<style>'.file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css').'</style>'.view('domains::partials.staff-styles')->render().'</head>',$html));
writeRenderArtifact((getenv('R14_RENDER_DIR')?:sys_get_temp_dir()).'/r14-terms-modal.html',$modal);
expect($errors===[],implode('; ',$errors));$nativeSuccessful=true;echo "Native Terms guidance and selected attention-empty view passed.\n";
