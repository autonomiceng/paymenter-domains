<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/installed.php';require __DIR__.'/Support/web-visit.php';
require_once app_path('Classes/helpers.php');

use Illuminate\Foundation\Vite;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
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
$actor=staffUser([Staff::VIEW,Staff::COSTS,Staff::POLICY,StaffSettingsReview::permission('acceptance')]);
$viewer=staffUser([Staff::VIEW]);
$ungranted=staffUser([]);
$matching=$namesilo->replicate();$matching->tld='com.au';$matching->save();

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
$historyUrl=TldResource::getUrl('history',['record'=>$namesilo]);
$index=TldResource::getUrl('index');

$all=$get($index,$actor);
same(200,$all->getStatusCode(),'Canonical catalogue renders, got HTTP '.$all->getStatusCode().' '.parse_url((string)$all->headers->get('Location'),PHP_URL_PATH));
expect($hasLink($all,$enomUrl)&&$hasLink($all,$nameSiloUrl),'All accounts keep both exact account record links, even for the same suffix');
$saleUrl=TldResource::getUrl('index',['filters'=>['for_sale'=>['isActive'=>1]]]);
$sale=$get($saleUrl,$actor);
same(200,$sale->getStatusCode(),'For sale resolves through the same registered route');
expect($hasLink($sale,$enomUrl)&&!$hasLink($sale,$nameSiloUrl),'For sale keeps the chosen seller and excludes the unavailable sibling');
same(200,$get($historyUrl,$actor)->getStatusCode(),'The numeric history route still resolves');
same(403,$get($index,$ungranted)->getStatusCode(),'Catalogue requires Domains View');
same(403,$get(WhatYouSell::getUrl(),$ungranted)->getStatusCode(),'Legacy page keeps the same View grant');

$old=WhatYouSell::getUrl(['account'=>$namesilo->server_id,'tld'=>'com','years'=>3,'search'=>'com',
    'filters'=>['for_sale'=>['isActive'=>0],'registrar_key'=>['value'=>'namesilo']]]);
$redirect=$get($old,$actor);
same(301,$redirect->getStatusCode(),'Legacy page redirects permanently');
$location=$redirect->headers->get('Location');
same('/admin/tlds',parse_url($location,PHP_URL_PATH),'Legacy sales link opens the canonical table');
parse_str((string)parse_url($location,PHP_URL_QUERY),$scope);
same([(string)$namesilo->server_id,'com','3','com','0','namesilo'],
    [$scope['account'],$scope['tld'],$scope['years'],$scope['search'],$scope['filters']['for_sale']['isActive'],$scope['filters']['registrar_key']['value']],
    'Redirect keeps an explicit account, exact focus, years, search and selected filters');
$focused=$get($location,$actor);
same(200,$focused->getStatusCode(),'Canonical route loads the focused table');
expect($hasLink($focused,$nameSiloUrl)&&!$hasLink($focused,$enomUrl)
    &&!$hasLink($focused,TldResource::getUrl('view',['record'=>$matching])),'Focused unavailable row still links only to the chosen account and suffix');

$legacyDefault=$get(url('/admin/tlds/price-list').'?search=com&filters[for_sale][isActive]=0',$actor);
same(301,$legacyDefault->getStatusCode(),'The older price list routes before the numeric detail');
$firstAccount=$get($legacyDefault->headers->get('Location'),$actor);
same(200,$firstAccount->getStatusCode(),'The older bookmark reaches the catalogue');
expect($hasLink($firstAccount,$enomUrl)&&!$hasLink($firstAccount,$nameSiloUrl),'A legacy bookmark without an account still selects the first account');

$page=Livewire::actingAs($actor)->withQueryParams($scope)->test(WhatYouSell::class,['allTlds'=>true])
    ->assertCountTableRecords(1)->assertSet('account',$namesilo->server_id)->assertSet('years',3);
expect($page->instance()->canEdit(),'A selected account retains its guarded terms action');
$page->set('account',WhatYouSell::ALL);
expect(!$page->instance()->canEdit(),'All accounts cannot use per-account terms edits');
$noCosts=Livewire::actingAs($viewer)->test(WhatYouSell::class,['allTlds'=>true])
    ->assertTableColumnHidden('register_cost')->assertTableColumnHidden('register_profit')->assertTableColumnVisible('renewal')
    ->assertActionHidden('csv');
$noCosts->call('downloadCsv')->assertForbidden();
same(0,$reads->calls,'Reading, filtering and redirecting never contact the registrar');
$nativeSuccessful=true;
