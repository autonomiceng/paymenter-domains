<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/retained-offer.php';
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Database\Schema\Blueprint;
use Paymenter\Extensions\Servers\Domains\Services\OfferRetirement;
use Paymenter\Extensions\Servers\Domains\Domains;
$saved=retainedOfferDatabase();
if(isset($cleanupPrefix)){
    foreach(Schema::getTableListing(null,false) as $table){if(!str_starts_with($table,'sqlite_')){Schema::rename($table,$cleanupPrefix.$table);}}
    DB::connection()->setTablePrefix($cleanupPrefix);
}
$before=retainedOfferObligations();
$archives=serialize(DB::table('domains_audit_archives')->orderBy('id')->get()->all());
$interrupt=true;$physical=DB::connection()->getTablePrefix().'domains_sell_rows';
DB::listen(static function($event)use(&$interrupt,$physical):void {
    if($interrupt && str_starts_with(strtolower($event->sql),'drop table') && str_contains($event->sql,$physical)){
        $interrupt=false;throw new RuntimeException('Synthetic interruption after committed DROP');
    }
});
try{(new Domains)->upgraded('0.1.0');throw new LogicException('Cleanup did not reach interruption');}
catch(RuntimeException $error){same('Synthetic interruption after committed DROP',$error->getMessage(),'Fault occurs after the actual DDL statement');}
expect(OfferRetirement::started()&&!OfferRetirement::finished(),'Durable begin record survives interruption before finish');
expect(!Schema::hasTable('domains_sell_rows'),'Interrupted DROP remains committed');
same($before,retainedOfferObligations(),'Interruption preserves frozen obligations');
(new Domains)->upgraded('0.1.0');
foreach(['domains_sell_rows','domains_sell_fills'] as $table){expect(!Schema::hasTable($table),'Final package removes obsolete '.$table);}
foreach(['cost_register_terms','sale_holds','renew_price','cost_renew','cost_transfer','costs_synced_at'] as $column){expect(!Schema::hasColumn('domains_tlds',$column),'Final package removes obsolete mirror '.$column);}
foreach(['eligibility','hold_reason'] as $column){expect(!Schema::hasColumn('domains_prices',$column),'Final package removes old price proof '.$column);}
same($before,retainedOfferObligations(),'Cleanup preserves retained native identities, intent, annual retail and frozen obligations');
(new Domains)->installed();(new Domains)->upgraded('0.1.0');
same($before,retainedOfferObligations(),'Repeated cleanup and reinstall preserve retained rows');
same($archives,serialize(DB::table('domains_audit_archives')->orderBy('id')->get()->all()),'Immutable staff audit archives survive cleanup');
// Completed schema verification must refuse reintroduced obsolete columns, never adopt them.
Schema::table('domains_tlds',static fn(Blueprint $t)=>$t->json('sale_holds')->nullable());
try{(new Domains)->installed();throw new LogicException('Reintroduced mirror was silently accepted');}
catch(RuntimeException $error){expect(str_contains($error->getMessage(),'schema mismatch'),'Completed verifier detects reintroduced mirror');}
same($before,retainedOfferObligations(),'Schema refusal leaves financial rows untouched');
$nativeSuccessful=true;
