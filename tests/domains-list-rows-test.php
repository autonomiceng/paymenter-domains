<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Client Domains list: Renewal on every state and a fixed query count per page.
declare(strict_types=1);
require __DIR__.'/Support/owned.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController;
use Paymenter\Extensions\Servers\Domains\Services\{AdoptionSchema, HistoryLinkSchema, HolderPricing};

$op=$newTransfer(placeholderComFixtureName('rows-placeholder.com'),0,false,false); $d=$op->domain; storefrontViews();
AdoptionSchema::install(); HistoryLinkSchema::install();
$controller=new OwnedDomainController($owners); $session=app('request')->session();
$request=static function()use($session,$user){$r=Request::create('/domains','GET');$r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);return $r;};
$norm=static fn(?DOMNode $n)=>$n?preg_replace('/\s+/',' ',trim($n->textContent)):null;
$render=static function()use($controller,$request):array{
    DB::flushQueryLog();DB::enableQueryLog();$html=$controller->index($request())->render();$n=count(DB::getQueryLog());DB::disableQueryLog();DB::flushQueryLog();
    return [storefrontDom($html),$n];
};
$withoutQuote=static function()use($render):array{
    $holder=app(HolderPricing::class);
    app()->bind(HolderPricing::class,static fn()=>throw new DomainException('Synthetic missing renewal quote.'));
    try{return $render();}finally{app()->instance(HolderPricing::class,$holder);}
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
    same('On',$norm($list->query('td[@data-label="Auto-renew"]/*[contains(@class,"domains-cell-main")]',$row)->item(0)),'Renewal cell: '.$case);
}

// The list query count does not grow with rows on the page, whatever state the extra rows are in.
DB::table('domains_domains')->where('id',$d->id)->update(['status'=>'active','provider_expires_at'=>now()->addDays(200)->format('Y-m-d 00:00:00')]);
DB::table('domains_operations')->where('id',$op->id)->update(['action'=>'transfer','state'=>'succeeded','safe_code'=>null]);
DB::table('services')->where('id',$d->service_id)->update(['price'=>'41.23']);
[$list,$before]=$render();same(1,$list->query('//tbody/tr')->length,'One row');
$normalPrice=Paymenter\Extensions\Servers\Domains\Services\Storefront::money(HolderPricing::shown($d->fresh(),$d->service->fresh(),$clock->now()));
expect($normalPrice!=='$41.23','Fixture: successful current renewal quote differs from saved price');
expect(str_contains($norm($list->query('//tbody/tr')->item(0)),$normalPrice.'/yr'),'The normal row displays its current quote');
[$missing]=$withoutQuote();
expect(str_contains($norm($missing->query('//tbody/tr')->item(0)),'$41.23/yr'),'That same row falls back to its saved price');
[, $fallbackBefore]=$withoutQuote();
$clone=function(string $table,int $id,array $changes):int{$row=(array)DB::table($table)->where('id',$id)->first();unset($row['id']);return (int)DB::table($table)->insertGetId([...$row,...$changes]);};
$savedPrices=[$d->domain=>'$41.23'];
for ($i=0;$i<8;$i++) {
    $name=placeholderComFixtureName('rows'.$i.'-placeholder.com');$service=$clone('services',(int)$d->service_id,['price'=>20+$i]);$savedPrices[$name]='$'.number_format(20+$i,2,'.','');
    [$status,$action,$state,$code]=[['active','transfer','succeeded',null],...array_values($cases)][$i%7];
    $id=$clone('domains_domains',$d->id,['domain'=>$name,'reservation_name'=>$name,'sld'=>'rows'.$i.'-placeholder','service_id'=>$service,'status'=>$status]);
    $clone('domains_operations',$op->id,['domain_id'=>$id,'service_id'=>$service,'operation_key'=>'rows-'.$i,'invoice_id'=>null,'invoice_item_id'=>null,'action'=>$action,'state'=>$state,'safe_code'=>$code]);
}
[$list,$after]=$render();
same(9,$list->query('//tbody/tr')->length,'Nine rows on one page');
same($before,$after,'No queries per additional row');
// A missing quote keeps each service's own saved price without repeating the failed read for every row.
[$fallback,$fallbackAfter]=$withoutQuote();
same($fallbackBefore,$fallbackAfter,'No queries per additional row when the renewal quote is missing');
same(9,$fallback->query('//tbody/tr')->length,'Nine rows without a renewal quote');
foreach($fallback->query('//tbody/tr') as $row){$name=$norm($fallback->query('td[1]/a',$row)->item(0));same(true,str_contains($norm($row),$savedPrices[$name].'/yr'),'Missing quote keeps the saved price for '.$name);}
// Distinct endings still share this user's advisory tax selection within the summary batch.
$originalTld=DB::table('domains_tlds')->where('server_id',$d->server_id)->where('tld','com')->first();
foreach(['net','org'] as $i=>$ending){
    $clone('domains_tlds',(int)$originalTld->id,['tld'=>$ending,'catalogue_retained_annual_retail'=>'18.00']);
    foreach(DB::table('domains_prices')->where('server_id',$d->server_id)->where('tld','com')->get() as $price){$clone('domains_prices',(int)$price->id,['tld'=>$ending]);}
    DB::table('domains_domains')->where('sld','rows'.$i.'-placeholder')->update(['tld'=>$ending,'sld'=>'example','domain'=>'example.'.$ending,'reservation_name'=>'example.'.$ending]);
}
DB::flushQueryLog();DB::enableQueryLog();
$summaries=(new Paymenter\Extensions\Servers\Domains\Services\DomainSummaries)->listed($user,$clock->now()->getTimestamp());
$taxReads=array_filter(DB::getQueryLog(),static fn($q)=>str_contains($q['query'],'from "tax_rates"'));DB::disableQueryLog();DB::flushQueryLog();
foreach(['net','org'] as $i=>$ending){
    $id=DB::table('domains_domains')->where('domain','example.'.$ending)->value('id');
    same('$18.00',$summaries['managed:'.$id]->renewalPrice,'A successful quote is shown for distinct ending '.$ending);
}
same(1,count($taxReads),'A summary batch selects tax once per user across distinct TLD quotes');
// A failed tax selection is shared too, while every service keeps its own saved price.
$taxFailure=true;$taxAttempts=0;
DB::connection()->beforeExecuting(static function($query)use(&$taxFailure,&$taxAttempts):void {
    if($taxFailure&&str_contains($query,'from "tax_rates"')){++$taxAttempts;throw new RuntimeException('Synthetic advisory tax failure');}
});
$failedTax=(new Paymenter\Extensions\Servers\Domains\Services\DomainSummaries)->listed($user,$clock->now()->getTimestamp());
foreach(Paymenter\Extensions\Servers\Domains\Models\Domain::where('user_id',$user->id)->with('service')->get() as $domain){
    same('$'.number_format((float)$domain->service->price,2,'.',''),$failedTax['managed:'.$domain->id]->renewalPrice,'Failed tax keeps the saved price for '.$domain->tld);
}
same(1,$taxAttempts,'Several distinct TLD quotes share one failed tax attempt');
$taxFailure=false;
$recoveredTax=(new Paymenter\Extensions\Servers\Domains\Services\DomainSummaries)->listed($user,$clock->now()->getTimestamp());
foreach(['net','org'] as $i=>$ending){
    $id=DB::table('domains_domains')->where('domain','example.'.$ending)->value('id');
    same('$18.00',$recoveredTax['managed:'.$id]->renewalPrice,'A new batch recovers after failed tax selection');
}
// Cancelled unpaid orders check their payments in the page's one batch, never once per row. A processing payment still needs review.
$cancelledOrder=function(int $i)use($clone,$d,$op,$user):int{
    $name=placeholderComFixtureName('cancelled-order'.$i.'-placeholder.com');$service=$clone('services',(int)$d->service_id,[]);
    $invoice=App\Models\Invoice::withoutEvents(fn()=>App\Models\Invoice::create(['user_id'=>$user->id,'currency_code'=>'USD','status'=>'cancelled','due_at'=>now()]));
    $id=$clone('domains_domains',$d->id,['domain'=>$name,'reservation_name'=>$name,'sld'=>'cancelled-order'.$i.'-placeholder','service_id'=>$service,'status'=>'registration_pending','provider_expires_at'=>null]);
    $clone('domains_operations',$op->id,['domain_id'=>$id,'service_id'=>$service,'operation_key'=>'cancelled-order-'.$i,'invoice_id'=>$invoice->id,'invoice_item_id'=>null,'action'=>'register','state'=>'awaiting_payment','safe_code'=>null]);
    return (int)$invoice->id;
};
$cancelledOrder(0);[, $oneCancelled]=$render();
$processing=$cancelledOrder(1);$cancelledOrder(2);
App\Models\InvoiceTransaction::withoutEvents(fn()=>App\Models\InvoiceTransaction::create(['invoice_id'=>$processing,'amount'=>'1.00','fee'=>'0.00','status'=>'processing']));
[$orders,$threeCancelled]=$render();
same($oneCancelled,$threeCancelled,'No queries per additional cancelled unpaid order');
$statusOf=static function(string $name)use($orders,$norm):?string{foreach($orders->query('//tbody/tr') as $row){if($norm($orders->query('td[1]/a',$row)->item(0))===$name){return $norm($orders->query('td[@data-label="Status"]',$row)->item(0));}}return null;};
same([true,null],[str_contains((string)$statusOf(placeholderComFixtureName('cancelled-order1-placeholder.com')),'Under review'),$statusOf(placeholderComFixtureName('cancelled-order2-placeholder.com'))],
    'From the batch, a processing payment needs review and a never-paid cancelled order is left off the list');
echo "Client Domains list: Renewal on every state and a fixed query count passed.\n";$nativeSuccessful=true;
