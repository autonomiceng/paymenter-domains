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
$nativeSuccessful=true;echo "Shown renewal price passed.\n";
