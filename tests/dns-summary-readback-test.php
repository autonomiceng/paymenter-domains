<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/owner-records.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Services\{ObservationRecords,ObservationSchema};
ObservationSchema::install();
[$d,$driver]=$holding(placeholderComFixtureName('dns-summary-placeholder.com'));
storefrontViews();
$product->server->settings()->create(['key'=>'domains_dns_tools','value'=>true,'type'=>'boolean']);
// The encrypted native inventory, management engine and rendered owner table must agree after a save;
// a protocol-only fixture cannot catch the summary using an older observation than the editor.
$state->dns=[['name'=>'@','type'=>'A','address'=>'8.8.8.8','mx_pref'=>10]];
$families=['dns'=>['state'=>'complete','records'=>$state->dns],'srv'=>['state'=>'complete','records'=>[]],
    'ds'=>['state'=>'complete','records'=>[]],'glue'=>['state'=>'partial','records'=>[]]];
ObservationRecords::save($d->fresh(),$families,$clock->now(),$driver->observationCapabilities());
$baseline=$d->fresh()->observation['records'];
$long='alpha.bravo.charlie.delta.echo.foxtrot.golf.hotel.india.juliet.example.com.';
$wanted=[...$state->dns,['name'=>'saved-alias','type'=>'CNAME','address'=>$long,'mx_pref'=>10]];
$clock->instant=$clock->now()->modify('+2 minutes');
Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
$op=$submit($d,$driver,'dns',['records'=>$wanted,'preimage_digest'=>\Paymenter\Extensions\Servers\Domains\Services\ManagementPayload::digest($state->dns)]);
same('succeeded',$management->run($op->id,$driver)->state,'DNS save has confirmed management readback');
$saved=ObservationRecords::current($d->fresh(),$clock->now());
same($wanted,$saved['families']['dns']['records'],'Confirmed DNS save replaces the monitoring inventory');
same($clock->now()->format(DATE_ATOM),$saved['families']['dns']['at'],'DNS checked time is the actual confirming readback');
foreach(['srv','ds','glue'] as $kind){same($baseline['families'][$kind],$saved['families'][$kind],'DNS save does not refresh unrelated '.$kind);}
same($baseline['at'],$saved['at'],'A DNS readback does not retime the full registrar observation');
$io=count($state->calls);$html=$page();$dom=storefrontDom($html);
$table=$dom->query('//table[contains(concat(" ",normalize-space(@class)," ")," domains-dns-inventory ")]')->item(0);
expect($table&&str_contains($table->textContent,$long)&&$dom->query('.//tbody/tr',$table)->length===2,'Owner summary renders both records, including the long saved CNAME');
// walk-1107 (5): a full read shows no Complete mark; the row value gives the count and the family keeps its own checked time.
$value=trim(preg_replace('/\s+/',' ',(string)$dom->query('//li[@id="dns"]/span[contains(@class,"domains-row__v")]')->item(0)?->textContent));$checked=$dom->query('//*[@data-record-family="dns"]/following-sibling::*')->item(0);
expect(str_starts_with($value,'2 records')&&!str_contains($dom->query('//li[@id="dns"]')->item(0)?->textContent??'','Complete')&&$checked&&str_contains($checked->textContent,'Checked'),'Owner DNS summary shows the fresh count and its own checked time: '.$value);
expect(str_contains($html,$clock->now()->format(DATE_ATOM)),'Owner page includes the readback timestamp');
same($io,count($state->calls),'Reloading the summary performs no provider reads');
// An uncertain write retains the confirmed inventory; read-only reconciliation then publishes deletion.
Cache::flush();$clock->instant=$clock->now()->modify('+2 minutes');
Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
$op=$submit($d,$driver,'dns',['records'=>[$wanted[0]],'preimage_digest'=>\Paymenter\Extensions\Servers\Domains\Services\ManagementPayload::digest($wanted)]);
$state->afterWrite=static function()use($state){$state->fault='GetHosts';};
same('uncertain',$management->run($op->id,$driver)->state,'Missing readback cannot publish desired records');
same($saved,ObservationRecords::current($d->fresh(),$clock->now()),'Unconfirmed save cannot change inventory or checked time');
$state->fault='';same('succeeded',$management->reconcile($op->id,$driver)->state,'Read-only reconciliation confirms deletion');
same([$wanted[0]],ObservationRecords::current($d->fresh(),$clock->now())['families']['dns']['records'],'Reconciliation updates the same monitoring inventory');
$table=storefrontDom($page())->query('//table[contains(concat(" ",normalize-space(@class)," ")," domains-dns-inventory ")]')->item(0);
expect($table&&!str_contains($table->textContent,'saved-alias'),'Deleted CNAME disappears from the customer summary');
same(2,count(array_filter($state->calls,fn($call)=>$call[0]==='SetHosts')),'Reconciliation never repeats a DNS write');
// After the write lease expires, a scheduled read can save newer records before the write's readback finishes.
Cache::flush();$clock->instant=$clock->now()->modify('+2 minutes');
Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
$next=[$wanted[0],['name'=>'www','type'=>'A','address'=>'8.8.4.4','mx_pref'=>10]];
$op=$submit($d,$driver,'dns',['records'=>$next,'preimage_digest'=>\Paymenter\Extensions\Servers\Domains\Services\ManagementPayload::digest([$wanted[0]])]);
$newer=[['name'=>'@','type'=>'A','address'=>'1.1.1.1','mx_pref'=>10]];
$state->afterWrite=static function()use(&$op,$d,$driver,$clock,$newer):void {
    $op->fresh()->update(['lease_expires_at'=>$clock->now()->modify('-1 second')]);
    ObservationRecords::save($d->fresh(),['dns'=>['state'=>'complete','records'=>$newer],'srv'=>['state'=>'complete','records'=>[]],
        'ds'=>['state'=>'complete','records'=>[]],'glue'=>['state'=>'partial','records'=>[]]],$clock->now(),$driver->observationCapabilities());
};
same('succeeded',$management->run($op->id,$driver)->state,'The write still confirms from its own readback');
same($newer,ObservationRecords::current($d->fresh(),$clock->now())['families']['dns']['records'],'An older write readback cannot replace newer saved DNS records');
same($newer,\Paymenter\Extensions\Servers\Domains\Services\ObservationProjection::sections($d->fresh())['dns']['facts']['records']??null,'The DNS editor shows the newer saved records, not the older readback');
$reads=$op->fresh()->attempt['management_reads'];same($next,end($reads)['facts']['records']??null,'The older confirmed readback stays in the request history');

$nativeSuccessful=true;echo "Confirmed DNS readback refreshes the customer summary.\n";
