<?php
// Saved price matrix and authorized CSV; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\{ListTlds,PriceList as PriceListPage};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Price,Tld};
use Paymenter\Extensions\Servers\Domains\Data\CatalogueUnavailable;
use Paymenter\Extensions\Servers\Domains\Services\{CardFee,CatalogueAcceptances,PriceCatalogue,PriceList,PricingRules};
$f=commerceFixture();extract($f);tldPanel();Carbon\Carbon::setTestNow($now);
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
$u=staffUser([Staff::VIEW,Staff::COSTS]);Auth::setUser($u);[$t,$other]=$tlds;
App\Models\Server::whereKey($t->server_id)->update(['name'=>'<First & account>']);
$service=new PriceList;
// Extra fee-mode TLDs on the same accounts must not add settings reads to either staff list.
DB::beginTransaction();
try{
    foreach([$t,$other] as $index=>$ending){
        $server=App\Models\Server::findOrFail($ending->server_id);
        $server->settings()->updateOrCreate(['key'=>'domains_pricing_default_rule'],['type'=>'array','encrypted'=>false,'value'=>['mode'=>'markup_fee_percent','markup'=>'30','round_up'=>'none']]);
        $server->settings()->updateOrCreate(['key'=>CardFee::KEY],['type'=>'array','encrypted'=>false,'value'=>['percent'=>(string)($index+2),'fixed'=>'0.30']]);
    }
    DB::table('domains_tlds')->where('id',$t->id)->update(['use_default_rule'=>false,'pricing_mode'=>'markup_fee_percent','markup'=>'30','round_up'=>'none']);
    $grants=Staff::requireView();
    $measure=static function(callable $read):array{
        DB::flushQueryLog();DB::enableQueryLog();
        try{$result=$read();return [count(DB::getQueryLog()),$result];}
        finally{DB::disableQueryLog();DB::flushQueryLog();}
    };
    $small=Tld::orderBy('id')->get();
    [$pageSmall,$facts]=$measure(fn()=>TldFacts::page($small,$grants));
    foreach($small as $ending){same(json_encode(TldFacts::snapshot($ending,$grants)['prices']),json_encode($facts[$ending->id]['prices']),'Batched rules agree with the individual account fee');}
    [$labelsSmall]=$measure(fn()=>$small->map(fn($ending)=>TldFacts::pricing($ending)));
    [$listSmall,$matrixSmall]=$measure(fn()=>$service->read());
    same(2,count($matrixSmall['rows']),'Both fee-mode accounts are readable');same([],$matrixSmall['skipped'],'Valid fee-mode rules are not skipped');
    foreach($small as $ending){for($i=0;$i<4;$i++){
        $copy=$ending->replicate();$copy->tld='batch'.$i;$copy->use_default_rule=false;$copy->pricing_mode='markup_fee_percent';$copy->markup='30';$copy->save();
    }}
    $large=Tld::orderBy('id')->get();
    [$pageLarge]=$measure(fn()=>TldFacts::page($large,$grants));
    [$labelsLarge,$labels]=$measure(fn()=>$large->map(fn($ending)=>TldFacts::pricing($ending)));
    [$listLarge,$matrixLarge]=$measure(fn()=>$service->read());
    echo "Fee-mode query counts (2 to 10 rows): facts $pageSmall/$pageLarge, labels $labelsSmall/$labelsLarge, price list $listSmall/$listLarge.\n";
    same(10,count($matrixLarge['rows']),'Additional custom fee rows remain readable');same([],$matrixLarge['skipped'],'Additional fee-mode rules are not skipped');
    same(9,$labels->filter(fn($label)=>$label==='Custom markup')->count(),'Fee-mode rows retain their custom markup labels');
    same($pageSmall,$pageLarge,'Extra custom fee rows add no page queries');
    same($labelsSmall,$labelsLarge,'Extra custom fee rows add no pricing-label queries');
    same($listSmall,$listLarge,'Extra custom fee rows add no price-list queries');
}finally{DB::rollBack();}
$before=DB::table('domains_prices')->orderBy('id')->get()->toJson();
$matrix=$service->read(1,'all',$t->server_id);same(1,count($matrix['rows']),'Selected account has one TLD row');
$row=$matrix['rows'][0];same('.com',$row['tld'],'TLD label');same('Default',$row['pricing'],'Rule source');
same('Price = cost + 30%, per year, times years.',$row['formula'],'Formula includes account numbers');
foreach(['register','renew','transfer'] as $action){
    $saved=Price::where(['server_id'=>$t->server_id,'tld'=>'com','action'=>$action,'term'=>1])->sole();$cell=$row['prices'][$action];
    same(PriceCatalogue::retail($saved->retail),$cell['price'],'Retail equals the published revision');
    same(PriceCatalogue::cost($saved->wholesale),$cell['cost'],'Exact saved term cost');same($saved->revision,$cell['revision'],'Retained revision identity');
    same(CardFee::profit($cell['price'],$cell['cost'],CardFee::read($t->server_id)),$cell['profit'],'Profit uses current account fee');
}
$three=$service->read(3,'all',$t->server_id)['rows'][0]['prices'];same('23.40',$three['register']['price'],'Exact saved three-year total');
same(null,$three['renew']['price'],'No synthesized three-year renewal');same(null,$three['transfer']['price'],'No synthesized transfer');
$two=$service->read(2,'all',$t->server_id)['rows'][0]['prices'];same(null,$two['register']['price'],'Missing registration term is never annual times years');
// A rule edit without publication must not reprice the saved totals.
App\Models\Server::findOrFail($t->server_id)->settings()->updateOrCreate(['key'=>'domains_pricing_default_rule'],['type'=>'array','encrypted'=>false,'value'=>['mode'=>'markup_percent','markup'=>'80','round_up'=>'whole']]);
same('7.80',$service->read(1,'all',$t->server_id)['rows'][0]['prices']['register']['price'],'Current rule does not recompute published price');
// Distinct below-cost and fee-loss rows, plus Default and Custom pricing filters.
DB::table('domains_tlds')->where('id',$other->id)->update(['use_default_rule'=>false,'pricing_mode'=>'markup_amount','markup'=>'1.25','round_up'=>'none']);
DB::table('domains_prices')->where(['server_id'=>$t->server_id,'action'=>'register','term'=>1])->update(['retail'=>'5.00']);
DB::table('domains_prices')->where(['server_id'=>$other->server_id,'action'=>'renew','term'=>1])->update(['retail'=>'8.10']);
$before=DB::table('domains_prices')->orderBy('id')->get()->toJson();
same([$t->id],array_column($service->read(1,'below_cost')['rows'],'id'),'Below cost checks wholesale only');
same([$t->id,$other->id],array_column($service->read(1,'loses_money')['rows'],'id'),'Fee loss includes price above wholesale');
same([$t->id],array_column($service->read(1,'default')['rows'],'id'),'Default filter');same([$other->id],array_column($service->read(1,'own')['rows'],'id'),'Custom filter');
same('Price = cost + $1.25, per year, times years.',$service->read(1,'own')['rows'][0]['formula'],'Custom rule numbers shown');
// Page and CSV are the same selected visible matrix, not an unfiltered export.
$page=Livewire::actingAs($u)->test(PriceListPage::class)->assertSuccessful()->set('years',1)->set('filter','own')->assertSee('Custom')->assertSee('How prices are worked out');
same(['all'=>'All TLDs','below_cost'=>'Below cost','loses_money'=>'Loses money after card fee','default'=>'Default pricing','own'=>'Custom pricing'],PriceList::FILTERS,'Filter labels use Default and Custom');
$page->assertSee('Est. profit')->assertDontSee('Rough profit')->assertDontSee('Own rule')->assertDontSee('Account default');
$page->assertSee('International cards and refunds can cost more, so treat it as an estimate.')->assertSee('Issued invoices keep their prices.');
$page->call('downloadCsv')->assertFileDownloaded('domain-prices-1-year.csv');
$matrix=$service->read(1,'own');$csv=$service->csv(1,'own');$lines=preg_split('/\r?\n/',trim($csv));$records=array_map(static fn($line)=>str_getcsv($line,',','"',''),$lines);
same(2,count($records),'CSV contains only the visible filtered TLD');same($service->headings(),$records[0],'CSV headings match matrix headings');
$values=$records[1];same(['.com','Synthetic','1','Custom','USD'],$values===[]?[]:array_slice($values,0,5),'CSV selected identity');
same(['6.00','7.80','1.27','', '8.00','8.10','-0.44','Loses money after card fee','7.00','9.10','1.53',''],array_slice($values,5,12),'CSV exact visible action amounts and flags');
same($matrix['rows'][0]['formula'],$values[17],'CSV includes visible explanation');
same(['Register est. profit','Renew est. profit','Transfer est. profit'],[$records[0][7],$records[0][11],$records[0][15]],'CSV profit headers match the table');
$dom=new DOMDocument();@$dom->loadHTML($page->html());$xpath=new DOMXPath($dom);foreach($values as $i=>$value){
    if(in_array($i,[2,4,17,18],true)){continue;}
    same($value,$xpath->evaluate('normalize-space(//table[@data-price-list]/tbody/tr//*[@data-price-value="'.$i.'"])'),'CSV equals rendered table value '.$i);
}
same($values[2],$xpath->evaluate('normalize-space(//*[@data-price-years])'),'CSV years equal visible caption');
same($values[4],$xpath->evaluate('normalize-space(//*[@data-price-currency])'),'CSV currency equals visible caption');
$page->assertSee($values[17])->assertSee('Card fee: '.$values[18]);
// The explanation panel names each account and pricing type, with the formula in its own code line.
same($values[17],$xpath->evaluate('normalize-space(//*[@data-price-explanation]//code[@data-price-formula])'),'Formula shown as a code line');
same('Custom',$xpath->evaluate('normalize-space(//*[@data-price-explanation]//*[@data-price-pricing])'),'Panel pricing badge matches the table');
same(3,(int)$xpath->evaluate('count(//ul[@data-price-notes]/li)'),'Panel notes are a short list');
$list=Livewire::actingAs($u)->test(ListTlds::class)->assertSuccessful()->assertSee('Price list');expect(str_contains(TldResource::getUrl('price-list'),'/price-list'),'Price list has a named resource route');
$escaped=Livewire::actingAs($u)->test(PriceListPage::class)->assertSuccessful()->set('serverId',(string)$t->server_id);expect(str_contains($escaped->html(),'&lt;First &amp; account&gt;'),'Account names are escaped');
same($before,DB::table('domains_prices')->orderBy('id')->get()->toJson(),'Page, filters and CSV leave all revisions and prices unchanged');same(0,$reads->calls,'No provider calls');
// Fees are current read facts, even when a price was published under an older fee.
$feeServer=App\Models\Server::findOrFail($other->server_id);
$feeServer->settings()->updateOrCreate(['key'=>CardFee::KEY],['type'=>'array','encrypted'=>false,'value'=>['percent'=>'0','fixed'=>'0']]);
same('0.1000',$service->read(1,'own')['rows'][0]['prices']['renew']['profit'],'Changed fee updates profit immediately');
same([$t->id],array_column($service->read(1,'loses_money')['rows'],'id'),'Loss filter reads current fee');
same($before,DB::table('domains_prices')->orderBy('id')->get()->toJson(),'Fee estimates never rewrite published revisions');
$feeServer->settings()->where('key',CardFee::KEY)->delete();
// Stale binding and cost-only/unavailable rows never masquerade as published prices.
DB::beginTransaction();
DB::table('domains_prices')->where(['server_id'=>$t->server_id,'action'=>'register','term'=>1])->update(['account_fingerprint'=>'earlier-account']);
$cell=$service->read(1,'all',$t->server_id)['rows'][0]['prices']['register'];same(null,$cell['price'],'Foreign binding has no price');same(null,$cell['cost'],'Foreign binding has no cost');
DB::table('domains_prices')->where(['server_id'=>$t->server_id,'action'=>'transfer','term'=>1])->update(['eligibility'=>'{}']);
same(null,$service->read(1,'all',$t->server_id)['rows'][0]['prices']['transfer']['price'],'Unpublished legacy retail is missing');
same('Missing',$service->read(1,'all',$t->server_id)['rows'][0]['prices']['transfer']['status'],'With transfers on, no price is Missing');
// Transfers off is not Missing: Sync can never fill it. CSV and screen say the same.
DB::table('domains_tlds')->where('id',$t->id)->update(['transfer_enabled'=>false]);DB::table('domains_prices')->where(['server_id'=>$t->server_id,'action'=>'transfer','term'=>1])->update(['wholesale'=>null]);
$off=$service->read(1,'all',$t->server_id)['rows'][0];same('Transfers off',$off['prices']['transfer']['status'],'Off says so');same(['Off','Off','Off','Transfers off'],array_slice($service->values($off),13,4),'No Missing in the transfer cells');
expect(!str_contains(Livewire::actingAs($u)->test(PriceListPage::class)->set('serverId',(string)$t->server_id)->html(),'class="missing">Off'),'Off is not painted as Missing');
DB::rollBack();
// Account labels cannot execute spreadsheet formulas.
App\Models\Server::whereKey($t->server_id)->update(['name'=>'=1+1']);
expect(str_contains($service->csv(1,'all',$t->server_id),"'=1+1"),'CSV neutralizes account formulas');

// A real publication distinguishes the correct account fee from two readers agreeing on stale rows.
foreach([$t,$other] as $index=>$ending){
    $server=App\Models\Server::findOrFail($ending->server_id);
    $server->settings()->updateOrCreate(['key'=>'domains_pricing_default_rule'],['type'=>'array','encrypted'=>false,'value'=>['mode'=>'markup_fee_percent','markup'=>'30','round_up'=>'none']]);
    $server->settings()->updateOrCreate(['key'=>CardFee::KEY],['type'=>'array','encrypted'=>false,'value'=>['percent'=>(string)($index+2),'fixed'=>'0.30']]);
    DB::table('domains_tlds')->where('id',$ending->id)->update(['use_default_rule'=>$index===1,'pricing_mode'=>'markup_fee_percent','markup'=>'30','round_up'=>'none']);
    $native->publish($ending->id,$now,null,static fn(Tld $locked)=>null);
}
$published=Tld::whereKey([$t->id,$other->id])->get();$grants=Staff::requireView();
$publicationStatus=static function(bool $changed)use($published,$grants,$t):void{
    $page=TldFacts::page($published,$grants);
    foreach($published as $ending){
        foreach([$page[$ending->id],TldFacts::snapshot($ending,$grants)] as $facts){
            expect(count($facts['prices'])>0,'Published fee-mode account has saved prices');
            foreach($facts['prices'] as $price){same($changed&&$ending->id===$t->id,in_array('unpublished',$price['flags'],true),'Fee changes invalidate only that account in both readers');}
        }
    }
};
$publicationStatus(false);
App\Models\Server::findOrFail($t->server_id)->settings()->where('key',CardFee::KEY)->sole()->update(['value'=>['percent'=>'4','fixed'=>'0.30']]);
$publicationStatus(true);

// Real quarantine retains billed renewal retail while costs/profit and acquisition prices become unknown.
$priorRenewal=$prices->renewal($other->server_id,$other->account_fingerprint,'com','USD',$now);
$binding=CatalogueAcceptances::binding(Account::where('server_id',$other->server_id)->sole());
$prices->unavailable(new CatalogueUnavailable($binding,'com',$now),CatalogueAcceptances::load($binding),static fn()=>null,$now);
$retained=$prices->renewal($other->server_id,$other->account_fingerprint,'com','USD',$now);
expect($retained->retained,'Quarantined renewal still bills the retained price');same($priorRenewal->retail,$retained->retail,'Quarantine preserves billed retail');
$quarantineBefore=DB::table('domains_prices')->orderBy('id')->get()->toJson();
$quarantined=$service->read(1,'all',$other->server_id)['rows'][0];$renew=$quarantined['prices']['renew'];
same($retained->retail,$renew['price'],'Matrix shows exactly the retained billed renewal');
same($retained->revision,$renew['revision'],'Matrix preserves the retained renewal revision');
same(null,$renew['cost'],'Quarantined renewal cost is unknown');same(null,$renew['profit'],'Quarantined profit is unknown');
same('Cost missing',$renew['status'],'Retained price explains the missing cost');
foreach(['register','transfer'] as $action){same(null,$quarantined['prices'][$action]['price'],'Quarantined acquisition remains Missing');}
$quarantineCsv=array_map(static fn($line)=>str_getcsv($line,',','"',''),preg_split('/\r?\n/',trim($service->csv(1,'all',$other->server_id))));
same(['Missing',$retained->retail,'Missing','Cost missing'],array_slice($quarantineCsv[1],9,4),'CSV preserves retained renewal and unknown costs/profit');
$quarantinePage=Livewire::actingAs($u)->test(PriceListPage::class)->assertSuccessful()->set('serverId',(string)$other->server_id)->assertSee('Cost missing');
$dom=new DOMDocument();@$dom->loadHTML($quarantinePage->html());$xpath=new DOMXPath($dom);
foreach([9=>'Missing',10=>$retained->retail,11=>'Missing',12=>'Cost missing'] as $index=>$expected){same($expected,$xpath->evaluate('normalize-space(//table[@data-price-list]/tbody/tr//*[@data-price-value="'.$index.'"])'),'Quarantined table agrees with CSV');}
same($quarantineBefore,DB::table('domains_prices')->orderBy('id')->get()->toJson(),'Quarantined matrix/export reads leave revisions unchanged');
// Fresh costs permission is required for service, page and forged export calls after revocation.
$u->role->update(['permissions'=>[Staff::VIEW]]);
foreach([fn()=>$service->read(),fn()=>$service->csv()] as $call){try{$call();expect(false,'Cost permission required');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(403,$e->getStatusCode(),'Forbidden costs');}}
$page->call('downloadCsv')->assertForbidden();Livewire::actingAs($u)->test(PriceListPage::class)->assertForbidden();
Livewire::actingAs($u)->test(ListTlds::class)->assertDontSee('Price list');
Auth::setUser(staffUser([Staff::COSTS]));try{$service->read();expect(false,'View permission required too');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(403,$e->getStatusCode(),'Forbidden without view');}
Auth::setUser(staffUser([Staff::VIEW,Staff::COSTS]));
foreach([[0,'all'],[11,'all'],[1,'bad']] as [$term,$filter]){try{$service->read($term,$filter);expect(false,'Invalid selection refused');}catch(InvalidArgumentException){}}
echo "Saved term price list, current fee profit, filtered CSV parity, permissions and unchanged revisions passed.\n";$nativeSuccessful=true;
