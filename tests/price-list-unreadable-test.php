<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native fixture renders real Filament callouts, toolbar hooks and linked row cells.
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB,View};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarAccount,WhatYouSell};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{CardFee,PriceList,PricingRule,PricingRules};
extract(commerceFixture());tldPanel();Carbon\Carbon::setTestNow($now);
View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
$u=staffUser([Staff::VIEW,Staff::COSTS]);Auth::setUser($u);[$t,$healthy]=$tlds;
$server=App\Models\Server::findOrFail($t->server_id);$server->update(['name'=>'= <Bad & account>']);
$second=$t->replicate();$second->tld='net';$second->save();
$custom=$t->replicate();$custom->tld='org';$custom->use_default_rule=false;$custom->pricing_mode='markup_amount';$custom->markup='1.25';$custom->round_up='none';$custom->save();
$service=new PriceList;
$before=DB::table('domains_prices')->orderBy('id')->get()->toJson();
$watch=false;$writes=[];
// What you sell has always given each accepted TLD a sell row on opening; nothing else may be written.
DB::listen(static function($query)use(&$watch,&$writes):void{if($watch&&!preg_match('/^\s*(select|pragma)\b|^insert or ignore into "domains_sell_rows"/i',$query->sql)){$writes[]=$query->sql;}});
$setting=static function(string $key,mixed $value,bool $encrypted):void{
    $row=App\Models\Setting::updateOrCreate(['key'=>$key,'settingable_type'=>null,'settingable_id'=>null],['type'=>'array','encrypted'=>false,'value'=>$value]);
    // Bypass the model's saving encryption: these bytes must remain invalid ciphertext on disk.
    if($encrypted){DB::table('settings')->where('id',$row->id)->update(['encrypted'=>true,'value'=>'synthetic-invalid-ciphertext']);}
};
$rawSettings=static fn():string=>DB::table('settings')->orderBy('id')->get()->toJson();
$csvRows=static fn(string $csv):array=>array_map(static fn($line)=>str_getcsv($line,',','"',''),preg_split('/\r?\n/',trim($csv)));
// The page adds missing sales rows on its first read. After that, reads write nothing.
Livewire::actingAs($u)->test(WhatYouSell::class)->set('account',WhatYouSell::ALL);
$cases=[
    ['domains_pricing_default_rule',[...PricingRule::DEFAULT,'markup'=>[]],'default_rule',[$t->id,$healthy->id,$second->id],"Default pricing can't be read",null],
    [CardFee::KEY,['percent'=>[],'fixed'=>'0'],'card_fee',[$t->id,$healthy->id,$second->id,$custom->id],"Card fee can't be read",null],
    [CardFee::KEY,['percent'=>'0','fixed'=>[]],'card_fee',[$t->id,$healthy->id,$second->id,$custom->id],"Card fee can't be read",null],
    ['domains_pricing_default_rule',[...PricingRule::DEFAULT,'mode'=>'fixed','fixed'=>['register'=>[],'renew'=>'2','transfer'=>'3']],'default_rule',[$t->id,$healthy->id,$second->id],"Default pricing can't be read",null],
    [CardFee::KEY,null,'card_fee',[$t->id,$healthy->id,$second->id,$custom->id],"Card fee can't be read",null,true],
    ['domains_pricing_default_rule',null,'default_rule',[$t->id,$healthy->id,$second->id],"Default pricing can't be read",null,true],
    [CardFee::KEY,'unreadable','card_fee',[$t->id,$healthy->id,$second->id,$custom->id],"Card fee can't be read",null],
    [CardFee::KEY,[],'card_fee',[$t->id,$healthy->id,$second->id,$custom->id],"Card fee can't be read",null],
    [CardFee::KEY,['percent'=>'100','fixed'=>'0'],'card_fee',[$t->id,$healthy->id,$second->id,$custom->id],"Card fee can't be read",null],
    ['domains_pricing_default_rule','unreadable','default_rule',[$t->id,$healthy->id,$second->id],"Default pricing can't be read",null],
    ['domains_pricing_default_rule',['mode'=>'unknown'],'default_rule',[$t->id,$healthy->id,$second->id],"Default pricing can't be read",null],
];
foreach($cases as $case){
    [$key,$value,$cause,$ids,$reason]=$case;$encrypted=$case[6]??false;
    $setting($key,$value,$encrypted);$settingsBefore=$rawSettings();$watch=true;
    if($encrypted){
        $raw=DB::table('settings')->whereNull('settingable_type')->where('key',$key)->sole();
        same(1,(int)$raw->encrypted,'Corruption fixture is really encrypted on disk');
        same('synthetic-invalid-ciphertext',$raw->value,'Invalid ciphertext bypasses the model encryption setter');
        same(null,App\Models\Setting::whereNull('settingable_type')->where('key',$key)->sole()->value,'Real native retrieval cannot decrypt the persisted bytes');
    }
    $matrix=$service->read();
    same($ids,array_column($matrix['skipped'],'id'),'Every affected TLD is identified: '.$cause);
    same(array_fill(0,count($ids),$cause),array_column($matrix['skipped'],'cause'),'Each omission has its actual settings cause');
    same($cause==='default_rule'?[$custom->id]:[],array_column($matrix['rows'],'id'),'Custom pricing does not read an unused bad default');
    same([$healthy->id],array_column($service->read(1,'all',$healthy->server_id)['skipped'],'id'),'Every account shares one store, and account selection keeps its own TLDs');
    same($ids,array_column($service->read(1,'below_cost')['skipped'],'id'),'Below-cost filter still discloses unclassifiable rows');
    same($ids,array_column($service->read(1,'loses_money')['skipped'],'id'),'Loss filter still discloses unclassifiable rows');
    same([$t->id,$second->id],array_column($service->read(1,'default',$t->server_id)['skipped'],'id'),'Known default filter scopes omissions');
    same($cause==='card_fee'?[$custom->id]:[],array_column($service->read(1,'own',$t->server_id)['skipped'],'id'),'Known custom filter scopes omissions');
    same(1,count($matrix['warnings']),'One cause gives one warning');
    same(count($matrix['rows']),array_sum(array_map(static fn($r)=>count($r['tlds']),$matrix['explanations'])),'Omissions add no formula explanations');
    $records=$csvRows($service->csv());same($service->headings(),array_shift($records),'Export keeps existing headings');
    same(['.com','.com','.net','.org'],array_column($records,0),'Omissions stay in the usual TLD/account sort order');
    foreach($records as $record){
        same(count($service->headings()),count($record),'Every export row has the usual width');
        if($record[1]!=='Synthetic'){same("'= <Bad & account>",$record[1],'Omitted account labels are formula escaped');}
        if($cause==='default_rule'&&$record[0]==='.org'){expect($record[17]!=='Left out','Custom row keeps its formula');continue;}
        same(['1',$record[0]==='.org'?'Custom':'Default','USD'],array_slice($record,2,3),'Omission retains known identity columns');
        foreach([5,9,13] as $offset){same(['Left out','Left out','Left out','Left out: '.lcfirst($reason)],array_slice($record,$offset,4),'Omitted amounts never invent saved prices');}
        same(['Left out','Left out'],array_slice($record,17),'Unreadable formula and fee are explicit');
    }
    $page=Livewire::actingAs($u)->test(WhatYouSell::class)->assertSuccessful()->set('account',WhatYouSell::ALL)->assertSee('Left out')->assertSee('left out:')->assertDontSee('Some TLDs are left out')->assertSee('To fix, use Default pricing at the top of this page.');
    $html=$page->html();
    $dom=new DOMDocument;@$dom->loadHTML($html);$xpath=new DOMXPath($dom);
    $warningText=$xpath->evaluate('normalize-space(//*[@data-price-omission])');
    $labels=array_values(array_unique(array_column($matrix['skipped'],'tld')));
    same($reason.'. '.count($labels).' TLDs left out: '.implode(', ',$labels).'. To fix, use Default pricing at the top of this page.',$warningText,'Warning gives the exact grouped count, TLDs and repair location');
    same(count($labels),(int)$xpath->evaluate('count(//*[@data-price-omission]//*[@data-omitted-tld])'),'Page identifies every omitted TLD once');
    $page->assertCountTableRecords(count($matrix['rows'])+count($matrix['skipped']));
    expect((int)$xpath->evaluate('count(//*[@data-price-omission]/ancestor::*[contains(concat(" ",normalize-space(@class)," ")," fi-sc-callout ") and contains(@class,"fi-color-warning")])')===1,'Unreadable prices appear in an amber callout');
    expect((int)$xpath->evaluate('count(//*[@data-price-omission]/following::*[contains(concat(" ",normalize-space(@class)," ")," fi-ta-header-toolbar ")])')>0,'Omission alert is above the toolbar');
    expect(!str_contains($html,'Prices in USD for')&&!str_contains($html,'Saved prices in USD for'),'No repeated currency/years note between toolbar and rows');
    $page->set('account',$t->server_id)->filterTable('show','default')->assertSee('No prices to show. Some TLDs are left out; see above.');
    same($settingsBefore,$rawSettings(),'Service, page and CSV preserve raw settings bytes, including corrupt ciphertext');
    same($before,DB::table('domains_prices')->orderBy('id')->get()->toJson(),'Each corrupt case preserves saved prices and revisions');
    $watch=false;App\Models\Setting::whereNull('settingable_type')->where('key',$key)->delete();if($key===PricingRules::KEY){saveDefaultRule(PricingRule::DEFAULT);}
}
// Bad custom pricing names its own TLD editor, rather than the account default editor.
$custom->update(['pricing_mode'=>'unknown']);$watch=true;
$matrix=$service->read();same([$custom->id],array_column($matrix['skipped'],'id'),'Only the malformed custom TLD is skipped');
// One left-out TLD among readable rows is not why an amount filter is empty.
Livewire::actingAs($u)->test(WhatYouSell::class)->assertSuccessful()->set('account',$custom->server_id)->filterTable('show','below_cost')->assertSee('No TLDs match')->assertDontSee('see above')->assertDontSee('use Sync prices');
same('custom_rule',$matrix['skipped'][0]['cause'],'Custom failure retains its own repair cause');
$page=Livewire::actingAs($u)->test(WhatYouSell::class)->assertSuccessful()->set('account',WhatYouSell::ALL)->assertSee("custom pricing can't be read.",false)->assertSee('To fix, open the .org TLD page and use Edit prices.');
$dom=new DOMDocument;@$dom->loadHTML($page->html());$xpath=new DOMXPath($dom);
same('Open .org',$xpath->evaluate('normalize-space(//a[@href="'.\Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource::getUrl('view',['record'=>$custom->id]).'"][ancestor::*[contains(@class,"fi-sc-callout")]])'),'Single-TLD omission alert opens its saved TLD');
$record=collect($page->instance()->getTableRecords()->items())->firstWhere('tld','org');
same("Its pricing can't be read. See the alert above.",$page->instance()->getTable()->getColumn('register_price')->record($record)->getTooltip(),'Left-out cell points to the alert');
$records=$csvRows($service->csv());same("Left out: custom pricing can't be read",$records[4][8],'CSV gives the custom repair cause');
$watch=false;
// This account has one saved TLD: its unreadable card fee is repaired on the account, not that TLD.
$feeServer=App\Models\Server::findOrFail($healthy->server_id);
// As above, let the page prepare this account before watching read-only rendering.
Livewire::actingAs($u)->test(WhatYouSell::class,['account'=>$healthy->server_id]);
$feeServer->settings()->updateOrCreate(['key'=>CardFee::KEY],['type'=>'array','encrypted'=>false,'value'=>[]]);$watch=true;
$feePage=Livewire::actingAs($u)->test(WhatYouSell::class,['account'=>$healthy->server_id]);
$dom=new DOMDocument;@$dom->loadHTML($feePage->html());$xpath=new DOMXPath($dom);
$links=$xpath->query('//*[@data-price-omission]/ancestor::*[contains(concat(" ",normalize-space(@class)," ")," fi-sc-callout ")]//a');
same(['tlds'=>['.com'],'links'=>[['Open registrar account',RegistrarAccount::getUrl(['account'=>$healthy->server_id])]]],
    ['tlds'=>array_map(static fn($node)=>$node->textContent,iterator_to_array($xpath->query('//*[@data-price-omission]//*[@data-omitted-tld]'))),
        'links'=>array_map(static fn($link)=>[$xpath->evaluate('normalize-space(.)',$link),$link->getAttribute('href')],iterator_to_array($links))],
    'Single-TLD card-fee warning links only to its registrar account');
$watch=false;
same([],$writes,'Service, page and export change no saved data');
same($before,DB::table('domains_prices')->orderBy('id')->get()->toJson(),'All saved prices and revision identities remain unchanged');
same(0,$reads->calls,'No provider calls');
// Permission checks remain in force even when settings cannot be read.
$u->role->update(['permissions'=>[Staff::VIEW]]);
foreach([fn()=>$service->read(),fn()=>$service->csv()] as $call){try{$call();expect(false,'Costs permission required');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(403,$e->getStatusCode(),'Unreadable settings do not bypass permissions');}}
Livewire::actingAs($u)->test(WhatYouSell::class)->assertSuccessful()->assertDontSee('Some TLDs are left out');
echo "Unreadable settings are reported with explicit page/export omissions and no writes.\n";$nativeSuccessful=true;
