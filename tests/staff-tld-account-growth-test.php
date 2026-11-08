<?php
// Native SQL counts, encrypted account identities and real HTML must share the same detail projection.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';require_once __DIR__.'/Support/accounts.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Models\Price;
extract(commerceFixture(nameSiloLocal:false,identities:['namesilo'=>'demo-namesilo']));tldPanel();[$enom,$silo]=$tlds;
$u=staffUser([Staff::VIEW,Staff::COSTS]);Auth::setUser($u);
// The detail table shares page facts for prices, sellers and holders as accounts grow.
$detailRead=static function($grants)use($enom):array{
    DB::flushQueryLog();DB::enableQueryLog();$html=TldFacts::accountsTable($enom,$grants)->toHtml();$queries=DB::getQueryLog();DB::disableQueryLog();
    $counts=[];foreach(['domains_prices','domains_domains','domains_sales_policies','domains_accounts'] as $table){$counts[$table]=count(array_filter($queries,fn($q)=>($table==='domains_accounts'?str_starts_with($q['query'],'select * from "domains_accounts" '):str_contains($q['query'],'from "'.$table.'"'))));}
    return [$html,$counts];
};
$grants=Staff::requireView();[, $twoAccounts]=$detailRead($grants);
foreach([1,2] as $i){
    $binding=fixtureAccount((int)App\Models\Server::max('id')+1,'enom','detail-table-'.$i);
    App\Models\Server::whereKey($binding['server_id'])->update(['name'=>'<Extra & '.$i.'>']);
    $saved=$enom->getRawOriginal();unset($saved['id']);DB::table('domains_tlds')->insert([...$saved,...$binding]);
    foreach(Price::where('server_id',$enom->server_id)->get() as $price){$saved=$price->getRawOriginal();unset($saved['id']);DB::table('domains_prices')->insert([...$saved,...$binding]);}
}
[$detail,$fourAccounts]=$detailRead($grants);
echo 'Native TLD account query counts: '.json_encode(['two'=>$twoAccounts,'four'=>$fourAccounts]).".\n";
// Current account batches and NameSilo credential fences stay fixed as rows grow.
same(5,$twoAccounts['domains_accounts'],'Two accounts use the current credential-fenced batch');
same(5,$fourAccounts['domains_accounts'],'Four accounts keep the same credential-fenced batch');
unset($twoAccounts['domains_accounts'],$fourAccounts['domains_accounts']);
same($twoAccounts,$fourAccounts,'Price, holder and seller reads stay fixed across detail accounts');
same(['domains_prices'=>1,'domains_domains'=>1,'domains_sales_policies'=>2],$fourAccounts,'Current snapshot policy batches remain bounded');
expect(str_contains($detail,'&lt;Extra &amp; 1&gt;'),'Account names remain escaped');
$dom=new DOMDocument();@$dom->loadHTML($detail);$xpath=new DOMXPath($dom);$rendered=$xpath->query('//tbody/tr');
$all=Paymenter\Extensions\Servers\Domains\Models\Tld::where('tld','com')->orderBy('server_id')->get();same($all->count(),$rendered->length,'One rendered row per bound account');
foreach($all as $i=>$row){
    $saved=TldFacts::prices($row,$grants);$first=static fn($action)=>collect($saved)->where('action',$action)->sortBy('term')->first();
    $expected=[$first('register')['retail'],TldFacts::renewal($saved)['retail'],$first('transfer')['retail'],TldFacts::renewal($saved)['cost'],(string)TldFacts::holders($row)];
    foreach($expected as $j=>$value){same($value,$xpath->evaluate('normalize-space(td['.($j+3).'])',$rendered->item($i)),'Detail values agree with saved reader');}
}
$viewerGrants=Paymenter\Extensions\Servers\Domains\Admin\Support\StaffGrants::current();
Auth::setUser(staffUser([Staff::VIEW]));$withoutCosts=Staff::requireView();Auth::setUser($u);
[$hidden]=$detailRead($withoutCosts);expect(!str_contains($hidden,'<th scope="col">Cost'),'Supplied grants hide cost even when current staff may read it');
[$shown]=$detailRead($viewerGrants);expect(str_contains($shown,'Registrations and transfers below cost are held.'),'Supplied cost grant keeps cost help');

echo "Staff TLD account growth keeps bounded reads and exact detail cells.\n";$nativeSuccessful=true;
