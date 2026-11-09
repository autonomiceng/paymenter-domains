<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native fixture renders real Filament callouts, toolbar hooks and linked row cells.
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB,View};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{CardFee,PriceList,PricingRule,PricingRules};
extract(commerceFixture());tldPanel();Carbon\Carbon::setTestNow($now);
View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
$u=staffUser([Staff::VIEW,Staff::COSTS]);Auth::setUser($u);[$t,$healthy]=$tlds;
$server=App\Models\Server::findOrFail($t->server_id);$server->update(['name'=>'= <Bad & account>']);
$second=$t->replicate();$second->tld='net';$second->save();
$custom=$t->replicate();$intent=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($custom->catalogue_intent);$intent['pricing_rule']=['mode'=>'markup_amount','markup'=>'1.25','round_up'=>'none'];$custom->catalogue_intent=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::json($intent);$custom->tld='org';$custom->use_default_rule=false;$custom->pricing_mode='markup_amount';$custom->markup='1.25';$custom->round_up='none';$custom->save();
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
    $page=Livewire::actingAs($u)->test(WhatYouSell::class)->assertSuccessful()->set('account',WhatYouSell::ALL)->filterTable('for_sale',false)->assertSee('Left out')->assertSee('so prices are left out for:')->assertDontSee('Some TLDs are left out')->assertSee('Fix it in Default pricing at the top of this page.');
    $html=$page->html();
    $dom=new DOMDocument;@$dom->loadHTML($html);$xpath=new DOMXPath($dom);
    $warningText=$xpath->evaluate('normalize-space(//*[@data-price-omission])');
    $labels=array_values(array_unique(array_column($matrix['skipped'],'tld')));
    same(($cause==='card_fee'?"The card fee can't be read":$reason).', so prices are left out for: '.implode(', ',$labels).'. Fix it in Default pricing at the top of this page.',$warningText,'Warning names the cause, every grouped TLD and repair location');
    same(count($labels),(int)$xpath->evaluate('count(//*[@data-price-omission]//*[@data-omitted-tld])'),'Page identifies every omitted TLD once');
    $page->assertCountTableRecords(count($matrix['rows'])+count($matrix['skipped']));
    expect((int)$xpath->evaluate('count(//*[@data-price-omission]/ancestor::*[contains(concat(" ",normalize-space(@class)," ")," fi-sc-callout ") and contains(@class,"fi-color-warning")])')===1,'Unreadable prices appear in an amber callout');
    expect((int)$xpath->evaluate('count(//*[@data-price-omission]/following::*[contains(concat(" ",normalize-space(@class)," ")," fi-ta-header-toolbar ")])')>0,'Omission alert is above the toolbar');
    expect(!str_contains($html,'Prices in USD for')&&!str_contains($html,'Saved prices in USD for'),'No repeated currency/years note between toolbar and rows');
    $page->set('account',$t->server_id)->filterTable('show','default')->assertSee('No prices to show. Some TLDs are left out.')
        ->assertSee(($cause==='card_fee'?"The card fee can't be read.":"Default pricing can't be read.").' Fix it in Default pricing at the top of this page.');
    // For sale is on when staff open the page. It drops the rows that can't be priced, but never their warning.
    $default=Livewire::actingAs($u)->test(WhatYouSell::class,['account'=>WhatYouSell::ALL])->assertSuccessful();
    expect($default->instance()->getTableFilterState('for_sale')['isActive']??false,'For sale starts on');
    same(1,substr_count(html_entity_decode($default->html(),ENT_QUOTES),'Fix it in Default pricing at the top of this page.'),'For sale on still gives the shared fix, once: '.$cause);
    if($cause==='default_rule'){$default->assertSee('Prices left out for 3 TLDs')->assertSee("They are not shown on this page. Default pricing can't be read.");}
    same($settingsBefore,$rawSettings(),'Service, page and CSV preserve raw settings bytes, including corrupt ciphertext');
    same($before,DB::table('domains_prices')->orderBy('id')->get()->toJson(),'Each corrupt case preserves saved prices and revisions');
    $watch=false;App\Models\Setting::whereNull('settingable_type')->where('key',$key)->delete();if($key===PricingRules::KEY){saveDefaultRule(PricingRule::DEFAULT);}
}
// Bad custom pricing names its own TLD editor, rather than the account default editor.
$intent=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($custom->fresh()->catalogue_intent);$intent['pricing_rule']['mode']='unknown';$custom->update(['catalogue_intent'=>\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::json($intent),'pricing_mode'=>'unknown']);$watch=true;
$matrix=$service->read();same([$custom->id],array_column($matrix['skipped'],'id'),'Only the malformed custom TLD is skipped');
// One left-out TLD among readable rows is not why an amount filter is empty.
Livewire::actingAs($u)->test(WhatYouSell::class)->assertSuccessful()->set('account',$custom->server_id)->filterTable('show','below_cost')->assertSee('No TLDs match')->assertDontSee('see above')->assertDontSee('use Sync prices');
same('custom_rule',$matrix['skipped'][0]['cause'],'Custom failure retains its own repair cause');
$page=Livewire::actingAs($u)->test(WhatYouSell::class)->assertSuccessful()->set('account',WhatYouSell::ALL)->filterTable('for_sale',false)->assertSee("custom pricing can't be read.",false)->assertSee('Open .org and use Edit prices.');
$dom=new DOMDocument;@$dom->loadHTML($page->html());$xpath=new DOMXPath($dom);
same('Open .org',$xpath->evaluate('normalize-space(//a[@href="'.\Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource::getUrl('view',['record'=>$custom->id]).'"][ancestor::*[contains(@class,"fi-sc-callout")]])'),'Single-TLD omission alert opens its saved TLD');
$record=collect($page->instance()->getTableRecords()->items())->firstWhere('tld','org');
same("Its pricing can't be read. See the alert above.",$page->instance()->getTable()->getColumn('register_price')->record($record)->getTooltip(),'Left-out cell points to the alert');
$records=$csvRows($service->csv());same("Left out: custom pricing can't be read",$records[4][8],'CSV gives the custom repair cause');
$watch=false;
// This account has one saved TLD; its unreadable global card fee has no account editor.
// As above, let the page prepare this account before watching read-only rendering.
Livewire::actingAs($u)->test(WhatYouSell::class,['account'=>$healthy->server_id]);
$setting(CardFee::KEY,[],false);$watch=true;
$feePage=Livewire::actingAs($u)->test(WhatYouSell::class,['account'=>$healthy->server_id])->filterTable('for_sale',false)->assertSuccessful();
$dom=new DOMDocument;@$dom->loadHTML($feePage->html());$xpath=new DOMXPath($dom);
$links=$xpath->query('//*[@data-price-omission]/ancestor::*[contains(concat(" ",normalize-space(@class)," ")," fi-sc-callout ")]//a');
same(['tlds'=>['.com'],'links'=>[]],
    ['tlds'=>array_map(static fn($node)=>$node->textContent,iterator_to_array($xpath->query('//*[@data-price-omission]//*[@data-omitted-tld]'))),
        'links'=>array_map(static fn($link)=>[$xpath->evaluate('normalize-space(.)',$link),$link->getAttribute('href')],iterator_to_array($links))],
    'Single-TLD card-fee warning uses global pricing without a registrar link');
$watch=false;
same([],$writes,'Service, page and export change no saved data');
same($before,DB::table('domains_prices')->orderBy('id')->get()->toJson(),'All saved prices and revision identities remain unchanged');
same(0,$reads->calls,'No provider calls');
// Many TLDs with their own unreadable pricing: For sale on, the warning names ten with a way to each and counts the rest.
App\Models\Setting::whereNull('settingable_type')->where('key',CardFee::KEY)->delete();
for($i=0;$i<14;$i++){$copy=$t->fresh()->replicate();$copy->tld='broken'.chr(97+$i);$copy->catalogue_intent='{"sell":';$copy->save();}
$many=Livewire::actingAs($u)->test(WhatYouSell::class,['account'=>WhatYouSell::ALL])->assertSuccessful()->assertSee('Prices left out for 15 TLDs')->assertSee('and 5 more');
$dom=new DOMDocument;@$dom->loadHTML($many->html());$xpath=new DOMXPath($dom);
$callout='//*[contains(concat(" ",normalize-space(@class)," ")," fi-sc-callout ")][contains(normalize-space(.),"not shown on this page")]';
same(1,$xpath->query($callout)->length,'One warning covers the TLDs this page does not show');
same(10,$xpath->query($callout.'//a')->length,'The warning links at most ten TLDs');
// Permission checks remain in force even when settings cannot be read.
$u->role->update(['permissions'=>[Staff::VIEW]]);
foreach([fn()=>$service->read(),fn()=>$service->csv()] as $call){try{$call();expect(false,'Costs permission required');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(403,$e->getStatusCode(),'Unreadable settings do not bypass permissions');}}
Livewire::actingAs($u)->test(WhatYouSell::class)->assertSuccessful()->assertDontSee('Some TLDs are left out')->assertDontSee('Prices left out');
echo "Unreadable settings are reported with explicit page/export omissions and no writes.\n";$nativeSuccessful=true;
