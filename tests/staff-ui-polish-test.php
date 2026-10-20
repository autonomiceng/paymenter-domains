<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic, read-only staff projections.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use App\Models\Server;
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ListDomains;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Labels,PaidUncertainty,RequestFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{NativePaidHooks,OwnedDomains};
staffPanel();[$held,$active,$uncertain]=staffData();
$viewer=staffUser([Staff::VIEW]);Auth::setUser($viewer);
Server::whereKey($held->server_id)->update(['name'=>'Offline registrar (synthetic)']);
DB::table('domains_domains')->update(['user_id'=>$viewer->id,'status'=>'needs_review','provider_expires_at'=>null]);
DB::table('domains_operations')->where('domain_id',$uncertain->id)->update(['state'=>'uncertain']);
// Exercise the real table render and its eager-load queries, rather than a standalone helper.
DB::enableQueryLog();DB::flushQueryLog();
$page=Livewire::actingAs($viewer)->test(ListDomains::class)->assertSuccessful();
$queries=DB::getQueryLog();DB::disableQueryLog();
$opQueries=array_filter($queries,fn($q)=>str_contains($q['query'],'from "domains_operations"'));
same(1,count($opQueries),'Exactly one acquisition query for the rendered table page');
$userReads=array_filter($queries,fn($q)=>str_contains($q['query'],'from "users"')&&str_contains($q['query'],'"email"'));
same(1,count($userReads),'Customer descriptions use one eager query');
$page->assertSee('On hold')->assertSee('Unconfirmed')->assertSee('Needs review')->assertSee($viewer->email)->assertSee('Offline registrar (synthetic)');
$instance=$page->instance();$columns=$instance->getTable()->getColumns();
$records=$instance->getTableRecords()->keyBy('id');
foreach([[$held,'held','warning'],[$uncertain,'uncertain','danger'],[$active,'needs_review','danger']] as [$domain,$state,$color]){
    $record=$records[$domain->id];$column=$columns['status']->record($record);
    same(in_array($state,['held','uncertain'],true)?Labels::state($state):Labels::status($state),$column->formatState($record->status),'Badge reuses existing staff vocabulary');
    same($color,$column->getColor($record->status),'Badge tone matches underlying state');
    same('See Registrar requests',$column->getTooltip(),'Badge points staff to requests');
    same(OwnedDomains::acquisition($record->operations,$record)?->id,$record->latestAcquisition?->id,'Batch matches customer acquisition lookup');
    same($viewer->email,$columns['domain']->record($record)->getDescriptionBelow(),'Customer email is first');
    same('Customer #'.$viewer->id,$columns['domain']->getTooltip(),'Customer ID remains secondary');
    same('Unconfirmed',$columns['provider_expires_at']->record($record)->getState(),'Unknown expiry has normal text state');
}
// A newer unrelated action, stale generation or foreign binding must not displace the acquisition.
$base=$held->operations()->sole();
foreach([['action'=>'renew'],['authority_generation'=>2],['account_fingerprint'=>str_repeat('f',64)]] as $i=>$changes){
    $copy=$base->replicate();$copy->operation_key='synthetic-lookalike-'.$i;$copy->save();
    DB::table('domains_operations')->where('id',$copy->id)->update($changes);
}
same($base->id,$held->fresh()->latestAcquisition->id,'Foreign lookalikes do not displace latest acquisition');
$copy=$base->replicate();$copy->operation_key='synthetic-resolved';$copy->state='succeeded';$copy->save();
$resolved=Livewire::actingAs($viewer)->test(ListDomains::class)->instance();
$record=$resolved->getTableRecords()->keyBy('id')[$held->id];
same('Needs review',$resolved->getTable()->getColumn('status')->record($record)->formatState($record->status),'Resolved latest acquisition never revives an older hold');
// Fallback descriptions preserve retained rows when native records are missing.
DB::table('domains_domains')->where('id',$held->id)->update(['user_id'=>999999,'server_id'=>999999]);
$fallback=Livewire::actingAs($viewer)->test(ListDomains::class)->assertSee('Customer #999999')->assertSee('Server #999999');
// Age grammar and exact staff copy; frozen timestamps avoid dependence on fixture business clocks.
$op=clone $base;$op->state='uncertain';$op->funding_basis='paid_invoice';$op->safe_code=null;
foreach([0=>'less than 1 hour',1=>'1 hour',7=>'7 hours'] as $hours=>$age){
    $op->started_at=\Carbon\CarbonImmutable::createFromTimestampUTC(time()-$hours*3600-30);
    $summary=PaidUncertainty::summary($op);
    expect(str_contains($summary,' · '.$age.' · since '.$op->started_at->format('M j, Y H:i').' UTC'),'Age copy '.$age);
    expect(str_starts_with($summary,'Paid, outcome unconfirmed · '),'Paid uncertainty heading snapshot');
    expect(str_starts_with(PaidUncertainty::holdSummary($op),'Spending on hold for this account · '),'Spending hold heading snapshot');
    expect(str_ends_with(PaidUncertainty::holdSummary($op),'. Check the original request with the registrar. Staff cannot release this hold.'),'Hold instructions snapshot');
}
$op->started_at=null;$op->created_at=null;
expect(str_contains(PaidUncertainty::summary($op),'Age unknown'),'Absent timestamps remain unknown');
$op->attempt=[];
same('Unconfirmed. Do not resend or refund until the registrar result is known.',RequestFacts::for($op)['outcome'],'Uncertain request copy snapshot');
same(['Start after payment','Paymenter payment events'],array_keys(NativePaidHooks::notices()),'Payment event labels snapshot');
same('Paused · the invoice-paid listener is missing. Scheduled checks still pick up paid orders.',NativePaidHooks::notices()['Start after payment'],'Missing listener copy snapshot');
echo "Staff list batch queries, labels, identity fallbacks and uncertainty copy passed.\n";$nativeSuccessful=true;
