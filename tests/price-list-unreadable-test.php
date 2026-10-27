<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB,View};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\PriceList as PriceListPage;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{CardFee,PriceList,PricingRule};
extract(commerceFixture());tldPanel();Carbon\Carbon::setTestNow($now);
View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
$u=staffUser([Staff::VIEW,Staff::COSTS]);Auth::setUser($u);[$t,$healthy]=$tlds;
$server=App\Models\Server::findOrFail($t->server_id);$server->update(['name'=>'= <Bad & account>']);
$second=$t->replicate();$second->tld='net';$second->save();
$custom=$t->replicate();$custom->tld='org';$custom->use_default_rule=false;$custom->pricing_mode='markup_amount';$custom->markup='1.25';$custom->round_up='none';$custom->save();
$service=new PriceList;$healthyRow=$service->read(1,'all',$healthy->server_id)['rows'][0];
$before=DB::table('domains_prices')->orderBy('id')->get()->toJson();
$watch=false;$writes=[];
DB::listen(static function($query)use(&$watch,&$writes):void{if($watch&&!preg_match('/^\s*(select|pragma)\b/i',$query->sql)){$writes[]=$query->sql;}});
$setting=static function(string $key,mixed $value,bool $encrypted)use($server):void{
    $row=$server->settings()->updateOrCreate(['key'=>$key],['type'=>'array','encrypted'=>false,'value'=>$value]);
    // Bypass the model's saving encryption: these bytes must remain invalid ciphertext on disk.
    if($encrypted){DB::table('settings')->where('id',$row->id)->update(['encrypted'=>true,'value'=>'synthetic-invalid-ciphertext']);}
};
$rawSettings=static fn():string=>DB::table('settings')->orderBy('id')->get()->toJson();
$csvRows=static fn(string $csv):array=>array_map(static fn($line)=>str_getcsv($line,',','"',''),preg_split('/\r?\n/',trim($csv)));
$cases=[
    ['domains_pricing_default_rule',[...PricingRule::DEFAULT,'markup'=>[]],'default_rule',[$t->id,$second->id],"default pricing can't be read",'Edit default pricing'],
    [CardFee::KEY,['percent'=>[],'fixed'=>'0'],'card_fee',[$t->id,$second->id,$custom->id],"card fee can't be read",'Edit card fee'],
    [CardFee::KEY,['percent'=>'0','fixed'=>[]],'card_fee',[$t->id,$second->id,$custom->id],"card fee can't be read",'Edit card fee'],
    ['domains_pricing_default_rule',[...PricingRule::DEFAULT,'mode'=>'fixed','fixed'=>['register'=>[],'renew'=>'2','transfer'=>'3']],'default_rule',[$t->id,$second->id],"default pricing can't be read",'Edit default pricing'],
    [CardFee::KEY,null,'card_fee',[$t->id,$second->id,$custom->id],"card fee can't be read",'Edit card fee',true],
    ['domains_pricing_default_rule',null,'default_rule',[$t->id,$second->id],"default pricing can't be read",'Edit default pricing',true],
    [CardFee::KEY,'unreadable','card_fee',[$t->id,$second->id,$custom->id],"card fee can't be read",'Edit card fee'],
    [CardFee::KEY,[],'card_fee',[$t->id,$second->id,$custom->id],"card fee can't be read",'Edit card fee'],
    [CardFee::KEY,['percent'=>'100','fixed'=>'0'],'card_fee',[$t->id,$second->id,$custom->id],"card fee can't be read",'Edit card fee'],
    ['domains_pricing_default_rule','unreadable','default_rule',[$t->id,$second->id],"default pricing can't be read",'Edit default pricing'],
    ['domains_pricing_default_rule',['mode'=>'unknown'],'default_rule',[$t->id,$second->id],"default pricing can't be read",'Edit default pricing'],
];
foreach($cases as $case){
    [$key,$value,$cause,$ids,$reason,$repair]=$case;$encrypted=$case[6]??false;
    $setting($key,$value,$encrypted);$settingsBefore=$rawSettings();$watch=true;
    if($encrypted){
        $raw=DB::table('settings')->where('settingable_id',$server->id)->where('settingable_type',$server->getMorphClass())->where('key',$key)->sole();
        same(1,(int)$raw->encrypted,'Corruption fixture is really encrypted on disk');
        same('synthetic-invalid-ciphertext',$raw->value,'Invalid ciphertext bypasses the model encryption setter');
        same(null,$server->settings()->where('key',$key)->sole()->value,'Real native retrieval cannot decrypt the persisted bytes');
    }
    $matrix=$service->read();
    same($ids,array_column($matrix['skipped'],'id'),'Every affected TLD is identified: '.$cause);
    same(array_fill(0,count($ids),$cause),array_column($matrix['skipped'],'cause'),'Each omission has its actual settings cause');
    foreach($matrix['skipped'] as $row){same($t->server_id,$row['server_id'],'Omission retains account identifier');}
    same($healthyRow,collect($matrix['rows'])->firstWhere('id',$healthy->id),'Healthy account retains exactly the same saved values');
    same($cause==='default_rule'?[$healthy->id,$custom->id]:[$healthy->id],array_column($matrix['rows'],'id'),'Custom pricing does not read an unused bad default');
    same([],$service->read(1,'all',$healthy->server_id)['skipped'],'Account selection excludes unrelated bad settings');
    same($ids,array_column($service->read(1,'below_cost')['skipped'],'id'),'Below-cost filter still discloses unclassifiable rows');
    same($ids,array_column($service->read(1,'loses_money')['skipped'],'id'),'Loss filter still discloses unclassifiable rows');
    same([$t->id,$second->id],array_column($service->read(1,'default',$t->server_id)['skipped'],'id'),'Known default filter scopes omissions');
    same($cause==='card_fee'?[$custom->id]:[],array_column($service->read(1,'own',$t->server_id)['skipped'],'id'),'Known custom filter scopes omissions');
    same(1,count($matrix['warnings']),'Account and cause share one warning');
    same(count($matrix['rows']),array_sum(array_map(static fn($r)=>count($r['tlds']),$matrix['explanations'])),'Omissions add no formula explanations');
    $records=$csvRows($service->csv());same($service->headings(),array_shift($records),'Export keeps existing headings');
    same(['.com','.com','.net','.org'],array_column($records,0),'Omissions stay in the usual TLD/account sort order');
    foreach($records as $record){
        same(count($service->headings()),count($record),'Every export row has the usual width');
        if($record[1]==='Synthetic'){same($service->values($healthyRow),$record,'Healthy export values retain service parity');continue;}
        same("'= <Bad & account>",$record[1],'Omitted account labels are formula escaped');
        if($cause==='default_rule'&&$record[0]==='.org'){expect($record[17]!=='Left out','Custom row keeps its formula');continue;}
        same(['1',$record[0]==='.org'?'Custom':'Default','USD'],array_slice($record,2,3),'Omission retains known identity columns');
        foreach([5,9,13] as $offset){same(['Left out','Left out','Left out','Left out: '.$reason],array_slice($record,$offset,4),'Omitted amounts never invent saved prices');}
        same(['Left out','Left out'],array_slice($record,17),'Unreadable formula and fee are explicit');
    }
    $page=Livewire::actingAs($u)->test(PriceListPage::class)->assertSuccessful()->assertSee('Some TLDs are left out')->assertSee("Their saved pricing can't be read, so no prices are shown for them. The rows below are not affected.",false)->assertSee($repair);
    $html=$page->html();expect(str_contains($html,'= &lt;Bad &amp; account&gt;'),'Warning account names use HTML escaping');
    $dom=new DOMDocument;@$dom->loadHTML($html);$xpath=new DOMXPath($dom);
    $warningText=$xpath->evaluate('normalize-space(//*[@data-price-omission])');
    $labels=implode(', ',array_column($matrix['skipped'],'tld'));
    same("= <Bad & account>: ".$reason.'. '.count($ids).' TLDs left out: '.$labels.'. To fix, open = <Bad & account> under Registrars and use '.$repair.'.',$warningText,'Warning gives the exact grouped count, TLDs and repair location');
    same(count($ids),(int)$xpath->evaluate('count(//*[@data-price-omission]//*[@data-omitted-tld])'),'Page identifies every omitted TLD once');
    same(count($matrix['rows']),(int)$xpath->evaluate('count(//tr[@data-price-row])'),'Page and service retain the same healthy rows');
    expect(strpos($html,'data-price-omission')<strpos($html,'<table data-price-list'),'Warning is above the table');
    $page->set('serverId',(string)$t->server_id)->set('filter','default')->assertSee('No prices to show. Some TLDs are left out; see above.');
    same($settingsBefore,$rawSettings(),'Service, page and CSV preserve raw settings bytes, including corrupt ciphertext');
    same($before,DB::table('domains_prices')->orderBy('id')->get()->toJson(),'Each corrupt case preserves saved prices and revisions');
    $watch=false;$server->settings()->where('key',$key)->delete();
}
// Bad custom pricing names its own TLD editor, rather than the account default editor.
$custom->update(['pricing_mode'=>'unknown']);$watch=true;
$matrix=$service->read();same([$custom->id],array_column($matrix['skipped'],'id'),'Only the malformed custom TLD is skipped');
same('custom_rule',$matrix['skipped'][0]['cause'],'Custom failure retains its own repair cause');
Livewire::actingAs($u)->test(PriceListPage::class)->assertSuccessful()->assertSee("custom pricing can't be read.",false)->assertSee('To fix, open the .org TLD page and use Edit prices.');
$records=$csvRows($service->csv());same("Left out: custom pricing can't be read",$records[4][8],'CSV gives the custom repair cause');
$watch=false;
same([],$writes,'Service, page and export perform no database writes');
same($before,DB::table('domains_prices')->orderBy('id')->get()->toJson(),'All saved prices and revision identities remain unchanged');
same(0,$reads->calls,'No provider calls');
// Permission checks remain in force even when settings cannot be read.
$u->role->update(['permissions'=>[Staff::VIEW]]);
foreach([fn()=>$service->read(),fn()=>$service->csv()] as $call){try{$call();expect(false,'Costs permission required');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(403,$e->getStatusCode(),'Unreadable settings do not bypass permissions');}}
Livewire::actingAs($u)->test(PriceListPage::class)->assertForbidden();
echo "Unreadable settings are isolated with explicit page/export omissions and no writes.\n";$nativeSuccessful=true;
