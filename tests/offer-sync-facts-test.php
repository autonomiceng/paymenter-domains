<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/retained-offer.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\RegistrarFacts;
use Paymenter\Extensions\Servers\Domains\Domains;
use Paymenter\Extensions\Servers\Domains\Models\Account;
retainedOfferDatabase();(new Domains)->upgraded('0.1.0');
$account=Account::orderBy('server_id')->firstOrFail();
DB::table('domains_prices')->where('server_id',$account->server_id)->update(['observed_at'=>'2026-11-01 00:00:00']);
DB::table('domains_observation_queues')->where('server_id',$account->server_id)->update(['offer_feed_succeeded_at'=>1793577600]);
same(1793577600,RegistrarFacts::lastSync($account->server_id),'Completed active feed replaces retired fill timestamp');
DB::table('domains_observation_queues')->where('server_id',$account->server_id)->update(['account_fingerprint'=>'other','offer_feed_succeeded_at'=>1893456000]);
same(1793491200,RegistrarFacts::lastSync($account->server_id),'Another account feed cannot age current account prices');
$nativeSuccessful=true;
