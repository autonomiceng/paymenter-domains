<?php
// Saved price matrix and authorized CSV; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\{ListTlds,PriceList as PriceListPage};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Price,Tld};
use Paymenter\Extensions\Servers\Domains\Data\CatalogueUnavailable;
use Paymenter\Extensions\Servers\Domains\Services\{CardFee,CatalogueAcceptances,PriceCatalogue,PriceList,PricingRules};
$f=commerceFixture();extract($f);tldPanel();Carbon\Carbon::setTestNow($now);
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
$u=staffUser([Staff::VIEW,Staff::COSTS]);Auth::setUser($u);[$t,$other]=$tlds;
App\Models\Server::whereKey($t->server_id)->update(['name'=>'<First & account>']);
$service=new PriceList;
$before=DB::table('domains_prices')->orderBy('id')->get()->toJson();
$matrix=$service->read(1,'all',$t->server_id);same(1,count($matrix['rows']),'Selected account has one TLD row');
$row=$matrix['rows'][0];same('.com',$row['tld'],'TLD label');same('Account default',$row['pricing'],'Rule source');
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
// Distinct below-cost and fee-loss rows, plus account default / own rule filters.
DB::table('domains_tlds')->where('id',$other->id)->update(['use_default_rule'=>false,'pricing_mode'=>'markup_amount','markup'=>'1.25','round_up'=>'none']);
DB::table('domains_prices')->where(['server_id'=>$t->server_id,'action'=>'register','term'=>1])->update(['retail'=>'5.00']);
DB::table('domains_prices')->where(['server_id'=>$other->server_id,'action'=>'renew','term'=>1])->update(['retail'=>'8.10']);
$before=DB::table('domains_prices')->orderBy('id')->get()->toJson();
same([$t->id],array_column($service->read(1,'below_cost')['rows'],'id'),'Below cost checks wholesale only');
same([$t->id,$other->id],array_column($service->read(1,'loses_money')['rows'],'id'),'Fee loss includes price above wholesale');
same([$t->id],array_column($service->read(1,'default')['rows'],'id'),'Default filter');same([$other->id],array_column($service->read(1,'own')['rows'],'id'),'Own rule filter');
same('Price = cost + $1.25, per year, times years.',$service->read(1,'own')['rows'][0]['formula'],'Own rule numbers shown');
// Page and CSV are the same selected visible matrix, not an unfiltered export.
$page=Livewire::actingAs($u)->test(PriceListPage::class)->assertSuccessful()->set('years',1)->set('filter','own')->assertSee('Own rule')->assertSee('How prices are worked out');
$page->assertSee('International cards and refunds can cost more, so treat it as an estimate.')->assertSee('Issued invoices keep their prices.');
$page->call('downloadCsv')->assertFileDownloaded('domain-prices-1-year.csv');
$matrix=$service->read(1,'own');$csv=$service->csv(1,'own');$lines=preg_split('/\r?\n/',trim($csv));$records=array_map(static fn($line)=>str_getcsv($line,',','"',''),$lines);
same(2,count($records),'CSV contains only the visible filtered TLD');same($service->headings(),$records[0],'CSV headings match matrix headings');
$values=$records[1];same(['.com','Synthetic','1','Own rule','USD'],$values===[]?[]:array_slice($values,0,5),'CSV selected identity');
same(['6.00','7.80','1.27','', '8.00','8.10','-0.44','Loses money after card fee','7.00','9.10','1.53',''],array_slice($values,5,12),'CSV exact visible action amounts and flags');
same($matrix['rows'][0]['formula'],$values[17],'CSV includes visible explanation');
$dom=new DOMDocument();@$dom->loadHTML($page->html());$xpath=new DOMXPath($dom);foreach($values as $i=>$value){
    if(in_array($i,[2,4,17,18],true)){continue;}
    same($value,$xpath->evaluate('normalize-space(//table[@data-price-list]/tbody/tr//*[@data-price-value="'.$i.'"])'),'CSV equals rendered table value '.$i);
}
same($values[2],$xpath->evaluate('normalize-space(//*[@data-price-years])'),'CSV years equal visible caption');
same($values[4],$xpath->evaluate('normalize-space(//*[@data-price-currency])'),'CSV currency equals visible caption');
$page->assertSee($values[17])->assertSee('Card fee: '.$values[18]);
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
DB::rollBack();
// Account labels cannot execute spreadsheet formulas.
App\Models\Server::whereKey($t->server_id)->update(['name'=>'=1+1']);
expect(str_contains($service->csv(1,'all',$t->server_id),"'=1+1"),'CSV neutralizes account formulas');

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
