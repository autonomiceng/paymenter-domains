<?php
// The domain page, the Domains list and Renew now show one renewal price. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/reminders.php';
require __DIR__.'/Support/owner-pages.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{DomainSummaries, Storefront};

[$d]=$make(placeholderComFixtureName('shown-renewal-placeholder.com'),false);
$user=$d->service->user;
$renewNow=Storefront::money($ownerBilling->renewalChoices($d->fresh())['next_annual']);
// The service keeps the price from when the domain was last billed; prices changed since.
DB::table('services')->where('id',$d->service_id)->update(['price'=>'10.99']);
expect($renewNow!=='$10.99','Fixture: the current renewal price differs from the saved one');
same($renewNow,$owners->present($d->fresh())->renewalPrice,'The domain page shows the Renew now price');
same($renewNow,(new DomainSummaries)->one($user,'managed',(int)$d->id,$clock->now()->getTimestamp())->renewalPrice,'The domain summary shows the Renew now price');
same($renewNow,(new DomainSummaries)->listed($user,$clock->now()->getTimestamp())['managed:'.$d->id]->renewalPrice,'The Domains list shows the Renew now price');
staffPanel();Illuminate\Support\Facades\Auth::setUser($staff);
$staffPrice=\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::for($d->fresh())['Renewal price'];
same($renewNow.' / year',$staffPrice['value'],'Staff quote the same next renewal price as the customer');
same('10.99',$d->service->fresh()->price,'Reading renewal prices leaves the purchase price unchanged');
$holder=app(\Paymenter\Extensions\Servers\Domains\Services\HolderPricing::class);
app()->bind(\Paymenter\Extensions\Servers\Domains\Services\HolderPricing::class,static fn()=>throw new DomainException('Synthetic missing quote'));
try{
    same('$10.99',$owners->present($d->fresh())->renewalPrice,'Missing page quote keeps the saved price');
    same('$10.99',(new DomainSummaries)->one($user,'managed',(int)$d->id,$clock->now()->getTimestamp())->renewalPrice,'Missing summary quote keeps the saved price');
    same('$10.99 / year',\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::for($d->fresh())['Renewal price']['value'],'Missing staff quote uses the same saved fallback');
}finally{app()->instance(\Paymenter\Extensions\Servers\Domains\Services\HolderPricing::class,$holder);}
foreach(['custom','complimentary'] as $mode){
    DB::table('domains_domains')->where('id',$d->id)->update(['price_mode'=>$mode]);
    $expected=$mode==='complimentary'?\Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccounts::LABEL:'$10.99';
    same($expected,$owners->present($d->fresh())->renewalPrice,$mode.' page contract');
    same($expected,(new DomainSummaries)->one($user,'managed',(int)$d->id,$clock->now()->getTimestamp())->renewalPrice,$mode.' summary contract');
}
DB::table('domains_domains')->where('id',$d->id)->update(['price_mode'=>'standard']);
$bill=$ownerBilling->invoice((int)$d->id,$user);
same($renewNow,Storefront::money(number_format((float)$bill->total,2,'.','')),'The actual next annual invoice matches both panels');
App\Models\Currency::create(['code'=>'EUR','name'=>'Euro','prefix'=>'€','suffix'=>'','format'=>'1,234.56']);
DB::table('services')->where('id',$d->service_id)->update(['currency_code'=>'EUR']);
same(null,$owners->present($d->fresh())->renewalPrice,'Non-USD page omits the renewal display');
same(null,(new DomainSummaries)->one($user,'managed',(int)$d->id,$clock->now()->getTimestamp())->renewalPrice,'Non-USD summary omits the renewal display');
$nativeSuccessful=true;echo "Shown renewal price passed.\n";
