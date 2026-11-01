<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Client Domains list: Renewal on every state and a fixed query count per page.
declare(strict_types=1);
require __DIR__.'/Support/owned.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController;
use Paymenter\Extensions\Servers\Domains\Services\{AdoptionSchema, HistoryLinkSchema};

$op=$newTransfer(placeholderComFixtureName('rows-placeholder.com'),0,false,false); $d=$op->domain; storefrontViews();
AdoptionSchema::install(); HistoryLinkSchema::install();
$controller=new OwnedDomainController($owners); $session=app('request')->session();
$request=static function()use($session,$user){$r=Request::create('/domains','GET');$r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);return $r;};
$norm=static fn(?DOMNode $n)=>$n?preg_replace('/\s+/',' ',trim($n->textContent)):null;
$render=static function()use($controller,$request):array{
    DB::flushQueryLog();DB::enableQueryLog();$html=$controller->index($request())->render();$n=count(DB::getQueryLog());DB::disableQueryLog();DB::flushQueryLog();
    return [storefrontDom($html),$n];
};

// A page past the end doesn't claim only cancelled domains exist.
$far=Request::create('/domains','GET',['page'=>9]);$far->setUserResolver(fn()=>$user);$far->setLaravelSession($session);
expect(!str_contains($controller->index($far)->render(),'Only cancelled domains here.'),'Out-of-range page keeps the plain empty message');

// Auto-renew applies to every domain state (status-model.md 3, rules 1 and 4), so pending and under-review rows show it.
$cases=['registration pending'=>['registration_pending','register','queued',null],'transfer pending'=>['transfer_pending','transfer','queued',null],
    'awaiting payment'=>['registration_pending','register','awaiting_payment',null],'held request'=>['transfer_pending','transfer','held','transfer_needs_action'],
    'uncertain request'=>['transfer_pending','transfer','uncertain',null],'under review'=>['needs_review','transfer','held','transfer_needs_action']];
foreach ($cases as $case=>[$status,$action,$state,$code]) {
    DB::table('domains_domains')->where('id',$d->id)->update(['status'=>$status,'provider_expires_at'=>null,'renewal_policy'=>null]);
    DB::table('domains_operations')->where('id',$op->id)->update(['action'=>$action,'state'=>$state,'safe_code'=>$code]);
    [$list]=$render();$row=$list->query('//tbody/tr')->item(0);
    same('Auto-renew on',$norm($list->query('td[@data-label="Renewal"]/*[contains(@class,"domains-cell-main")]',$row)->item(0)),'Renewal cell: '.$case);
}

// The list query count does not grow with rows on the page, whatever state the extra rows are in.
DB::table('domains_domains')->where('id',$d->id)->update(['status'=>'active','provider_expires_at'=>now()->addDays(200)->format('Y-m-d 00:00:00')]);
DB::table('domains_operations')->where('id',$op->id)->update(['action'=>'transfer','state'=>'succeeded','safe_code'=>null]);
[$list,$before]=$render();same(1,$list->query('//tbody/tr')->length,'One row');
$clone=function(string $table,int $id,array $changes):int{$row=(array)DB::table($table)->where('id',$id)->first();unset($row['id']);return (int)DB::table($table)->insertGetId([...$row,...$changes]);};
for ($i=0;$i<8;$i++) {
    $name=placeholderComFixtureName('rows'.$i.'-placeholder.com');$service=$clone('services',(int)$d->service_id,[]);
    [$status,$action,$state,$code]=[['active','transfer','succeeded',null],...array_values($cases)][$i%7];
    $id=$clone('domains_domains',$d->id,['domain'=>$name,'reservation_name'=>$name,'sld'=>'rows'.$i.'-placeholder','service_id'=>$service,'status'=>$status]);
    $clone('domains_operations',$op->id,['domain_id'=>$id,'service_id'=>$service,'operation_key'=>'rows-'.$i,'invoice_id'=>null,'invoice_item_id'=>null,'action'=>$action,'state'=>$state,'safe_code'=>$code]);
}
[$list,$after]=$render();
same(9,$list->query('//tbody/tr')->length,'Nine rows on one page');
same($before,$after,'No queries per additional row');
echo "Client Domains list: Renewal on every state and a fixed query count passed.\n";$nativeSuccessful=true;
