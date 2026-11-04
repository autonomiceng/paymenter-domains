<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/retained-offer.php';
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Domains;
$saved=retainedOfferDatabase();$before=retainedOfferObligations();
(new Domains)->upgraded('0.1.0');
foreach(['domains_sell_rows','domains_sell_fills'] as $table){expect(!Schema::hasTable($table),'Final package removes obsolete '.$table);}
foreach(['cost_register_terms','sale_holds','renew_price','cost_renew','cost_transfer','costs_synced_at'] as $column){expect(!Schema::hasColumn('domains_tlds',$column),'Final package removes obsolete mirror '.$column);}
foreach(['eligibility','hold_reason'] as $column){expect(!Schema::hasColumn('domains_prices',$column),'Final package removes old price proof '.$column);}
same($before,retainedOfferObligations(),'Cleanup preserves retained native identities, intent, annual retail and frozen obligations');
(new Domains)->installed();(new Domains)->upgraded('0.1.0');
same($before,retainedOfferObligations(),'Repeated cleanup and reinstall preserve retained rows');
$nativeSuccessful=true;
