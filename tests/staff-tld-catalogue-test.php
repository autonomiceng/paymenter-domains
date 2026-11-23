<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/installed.php';require __DIR__.'/Support/web-visit.php';
require_once app_path('Classes/helpers.php');

use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\OfferState;
use Paymenter\Extensions\Servers\Domains\Domains;
use Paymenter\Extensions\Servers\Domains\Services\{RuntimePorts,StaffSettingsReview};

extract(commerceFixture(true));staffSalesFixture();pinStaffClock($now);
app()->offsetUnset(RuntimePorts::class);
(new Domains)->boot();
installedPanel();
$hot=storage_path('framework/domains-assets-hot');
file_put_contents($hot,'http://assets.example.test');
app(Vite::class)->useHotFile($hot);
[$enom,$namesilo]=$tlds;
\App\Models\Server::whereKey($enom->server_id)->update(['name'=>'Synthetic Enom']);
\App\Models\Server::whereKey($namesilo->server_id)->update(['name'=>'Synthetic NameSilo']);
$actor=staffUser([Staff::VIEW,Staff::COSTS,Staff::POLICY,StaffSettingsReview::permission('acceptance')]);
$viewer=staffUser([Staff::VIEW]);
$ungranted=staffUser([]);
$matching=$namesilo->replicate();$matching->tld='com.au';$matching->save();
$fallback=$enom->replicate();$fallback->tld='com.au';$fallback->save();

$get=static function(string $url,\App\Models\User $user): \Symfony\Component\HttpFoundation\Response {
    $original=app('request');
    $path=parse_url($url,PHP_URL_PATH);
    $query=parse_url($url,PHP_URL_QUERY);
    try{
        $response=webVisit($user,$path.($query===null?'':'?'.$query));
        if ($response->getStatusCode()>=500 && ($response->exception??null) instanceof Throwable) {throw $response->exception;}
        return $response;
    }
    finally{app()->instance('request',$original);app('url')->setRequest($original);}
};
$hasLink=static fn($response,string $url):bool=>str_contains($response->getContent(),'href="'.e($url).'"');
$enomUrl=TldResource::getUrl('view',['record'=>$enom]);
$nameSiloUrl=TldResource::getUrl('view',['record'=>$namesilo]);
$fallbackUrl=TldResource::getUrl('view',['record'=>$fallback]);
$matchingUrl=TldResource::getUrl('view',['record'=>$matching]);
$historyUrl=TldResource::getUrl('history',['record'=>$namesilo]);
$index=TldResource::getUrl('index');

$all=$get($index,$actor);
same(200,$all->getStatusCode(),'Canonical catalogue renders, got HTTP '.$all->getStatusCode().' '.parse_url((string)$all->headers->get('Location'),PHP_URL_PATH));
expect($hasLink($all,$enomUrl)&&!$hasLink($all,$nameSiloUrl),'All accounts shows only the .com seller and links to that record');
expect($hasLink($all,$fallbackUrl)&&!$hasLink($all,$matchingUrl),'A suffix with no seller uses the first account with a matching offer');
$registrar=$get(TldResource::getUrl('index',['years'=>3,'search'=>'com','filters'=>['registrar_key'=>['value'=>'namesilo']]]),$actor);
same(200,$registrar->getStatusCode(),'Registrar scope renders through the canonical route');
expect($hasLink($registrar,$nameSiloUrl)&&$hasLink($registrar,$matchingUrl)
    &&!$hasLink($registrar,$enomUrl)&&!$hasLink($registrar,$fallbackUrl),'Search and registrar scope choose from their matching account records');
$saleUrl=TldResource::getUrl('index',['filters'=>['for_sale'=>['isActive'=>1]]]);
$sale=$get($saleUrl,$actor);
same(200,$sale->getStatusCode(),'For sale resolves through the same registered route');
expect($hasLink($sale,$enomUrl)&&!$hasLink($sale,$nameSiloUrl),'For sale keeps the chosen seller and excludes the unavailable sibling');
same(200,$get($historyUrl,$actor)->getStatusCode(),'The numeric history route still resolves');
same(403,$get($index,$ungranted)->getStatusCode(),'Catalogue requires Domains View');
$scope=['account'=>$namesilo->server_id,'tld'=>'com','years'=>3,'search'=>'com',
    'filters'=>['for_sale'=>['isActive'=>0],'registrar_key'=>['value'=>'namesilo']]];
$focused=$get(WhatYouSell::salesUrl($scope),$actor);
same(200,$focused->getStatusCode(),'Canonical route loads the focused table');
expect($hasLink($focused,$nameSiloUrl)&&!$hasLink($focused,$enomUrl)
    &&!$hasLink($focused,TldResource::getUrl('view',['record'=>$matching])),'Focused unavailable row still links only to the chosen account and suffix');

$page=Livewire::actingAs($actor)->withQueryParams($scope)->test(WhatYouSell::class,['allTlds'=>true])
    ->assertCountTableRecords(1)->assertSet('account',$namesilo->server_id)->assertSet('years',3);
expect($page->instance()->canEdit(),'A selected account retains its guarded terms action');
$page->set('account',WhatYouSell::ALL);
expect(!$page->instance()->canEdit(),'All accounts cannot use per-account terms edits');
$page->assertCountTableRecords(1);
$scopedPrice=$page->instance()->savedPrices()['rows'][0];
same((int)$namesilo->id,$scopedPrice['id'],'Scoped prices use the same record as the row link');
same(null,$scopedPrice['prices']['register']['cost'],'An unpriced three-year term does not borrow the Enom cost');

$allOffers=Livewire::actingAs($actor)->withQueryParams(['tld'=>'com'])->test(WhatYouSell::class,['allTlds'=>true])
    ->assertCountTableRecords(1)->assertCanSeeTableRecords([$enom]);
ob_start();
$allOffers->instance()->downloadCsv()->sendContent();
$records=array_slice(explode("\n",trim(ob_get_clean())),1);
same([['.com','Synthetic Enom'],['.com','Synthetic NameSilo']],
    array_map(static fn($line)=>array_slice(str_getcsv($line,',','"',''),0,2),$records),
    'The exact-focus CSV keeps both account offers and excludes a longer suffix');

$intent=OfferState::decode($namesilo->catalogue_intent);
$intent['pricing_rule']=['mode'=>'fixed','markup'=>'0','round_up'=>'none','fixed'=>['register'=>'13.00','renew'=>'14.00','transfer'=>'15.00']];
$namesilo->update(['catalogue_intent'=>OfferState::json($intent)]);
$priceFilter=$get(TldResource::getUrl('index',['tld'=>'com','filters'=>['show'=>['value'=>'own']]]),$actor);
expect($hasLink($priceFilter,$nameSiloUrl)&&!$hasLink($priceFilter,$enomUrl),'A price filter can select a matching sibling offer');
$accountFilter=$get(TldResource::getUrl('index',['tld'=>'com','filters'=>['server_id'=>['value'=>$namesilo->server_id]]]),$actor);
expect($hasLink($accountFilter,$nameSiloUrl)&&!$hasLink($accountFilter,$enomUrl),'The registrar account filter chooses inside its scope');

DB::table('domains_sales_policies')->where('tld','com')->update([
    'server_id'=>$namesilo->server_id,'registrar_key'=>$namesilo->registrar_key,'account_fingerprint'=>$namesilo->account_fingerprint,
]);
$changedSeller=$get(TldResource::getUrl('index',['tld'=>'com']),$actor);
expect($hasLink($changedSeller,$nameSiloUrl)&&!$hasLink($changedSeller,$enomUrl),'The seller wins even when its account sorts after the fallback');
$namesilo->update(['catalogue_facts'=>null]);
$needsTerms=$get(TldResource::getUrl('index',['filters'=>['needs_attention'=>['isActive'=>1]]]),$actor);
expect($hasLink($needsTerms,$nameSiloUrl)&&!$hasLink($needsTerms,$enomUrl),'Terms need attention keeps the selected account record with missing terms');
$noCosts=Livewire::actingAs($viewer)->test(WhatYouSell::class,['allTlds'=>true])
    ->assertTableColumnHidden('register_cost')->assertTableColumnHidden('register_profit')->assertTableColumnVisible('renewal')
    ->assertActionHidden('csv');
$noCosts->call('downloadCsv')->assertForbidden();
same(0,$reads->calls,'Reading, filtering and redirecting never contact the registrar');
$nativeSuccessful=true;
