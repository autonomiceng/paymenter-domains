<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic, read-only staff projections.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
require __DIR__.'/Support/domain-name-cell.php';
use App\Models\Server;
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ListDomains;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{PaidUncertainty,RequestFacts,Staff};
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
$customer=trim($viewer->first_name.' '.$viewer->last_name)?:$viewer->email;
$page->assertSee('Under review')->assertSee('Old registrar needs approval')->assertSee($customer)->assertSee('Offline registrar (synthetic)')->assertDontSee('Missing');
$instance=$page->instance();$columns=$instance->getTable()->getColumns();
$records=$instance->getTableRecords()->keyBy('id');
foreach([$held,$active,$uncertain] as $domain){assertDomainNameWraps($page->html(),$domain->domain,'Domains list '.$domain->domain);}
same('&lt;b&gt;.<wbr>x-<wbr>y',$columns['domain']->record($records[$active->id])->formatState('<b>.x-y')->toHtml(),'Domains list escapes the name before adding line breaks');
$pending=\Paymenter\Extensions\Servers\Domains\Admin\Resources\PendingAdoptionResource::table(\Filament\Tables\Table::make($instance))->getColumn('domain');
assertDomainNameWraps($pending->record($records[$uncertain->id])->toEmbeddedHtml(),$uncertain->domain,'Adoption pending list');
same('&lt;b&gt;.<wbr>x-<wbr>y',$pending->formatState('<b>.x-y')->toHtml(),'Adoption pending list escapes the name before adding line breaks');
foreach([[$held,'Old registrar needs approval'],[$uncertain,'Old registrar needs approval'],[$active,null]] as [$domain,$reason]){
    $record=$records[$domain->id];$column=$columns['status']->record($record);
    same('Under review',$column->getState(),'Held, uncertain and stored review share one status');
    same('warning',$column->getColor($column->getState()),'Under review tone');
    same($reason,$column->getDescriptionBelow(),'Reason sits under the badge');
    same(\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::status($domain->fresh()),\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::status($record,false,false),'Batched list status matches the page');
    same(null,$column->getTooltip(),'No tooltip: the reason is visible');
    same(OwnedDomains::acquisition($record->operations,$record)?->id,$record->latestAcquisition?->id,'Batch matches customer acquisition lookup');
    same($customer,$columns['domain']->record($record)->getDescriptionBelow(),'Customer name under the domain');
    same(null,$columns['domain']->getTooltip(),'No customer ID tooltip');
    same(null,$columns['provider_expires_at']->record($record)->getState(),'Expiry of a domain under review is blank, not Missing');
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
$column=$resolved->getTable()->getColumn('status')->record($record);
same(['Under review',null],[$column->getState(),$column->getDescriptionBelow()],'Resolved latest acquisition never revives an older hold');
// Fallback descriptions preserve retained rows when native records are missing.
DB::table('domains_domains')->where('id',$held->id)->update(['user_id'=>999999,'server_id'=>999999]);
$fallback=Livewire::actingAs($viewer)->test(ListDomains::class)->assertSeeText($held->domain)->assertSee('Missing')->assertDontSee('#999999');
$record=$fallback->instance()->getTableRecords()->keyBy('id')[$held->id];$columns=$fallback->instance()->getTable()->getColumns();
same([null,'Missing'],[$columns['domain']->record($record)->getDescriptionBelow(),$columns['server_id']->record($record)->getState()],'Missing customer and registrar account keep the row');
// Age grammar and exact staff copy; frozen timestamps avoid dependence on fixture business clocks.
$op=clone $base;$op->state='uncertain';$op->funding_basis='paid_invoice';$op->safe_code=null;
foreach([0=>' (under 1 hour so far)',1=>' (1 hour so far)',7=>' (7 hours so far)'] as $hours=>$age){
    $op->started_at=\Carbon\CarbonImmutable::createFromTimestampUTC(time()-$hours*3600-30);
    same('NameSilo has not confirmed this transfer.',PaidUncertainty::summary($op),'Paid uncertainty title snapshot');
    same('New orders on this NameSilo account are paused until the result is known'.$age.'.',PaidUncertainty::holdSummary($op),'Paused orders copy '.$hours);
}
$op->started_at=null;$op->created_at=null;
same('New orders on this NameSilo account are paused until the result is known.',PaidUncertainty::holdSummary($op),'Absent timestamps omit the age');
$op->attempt=[];
same('NameSilo has not confirmed this transfer.',RequestFacts::for($op)['outcome'],'Uncertain request copy snapshot');
same(['Start after payment','Paymenter payment events'],array_keys(NativePaidHooks::notices()),'Payment event labels snapshot');
same('Paused · the invoice-paid listener is missing. Scheduled checks still pick up paid orders.',NativePaidHooks::notices()['Start after payment'],'Missing listener copy snapshot');
echo "Staff list batch queries, labels, identity fallbacks and uncertainty copy passed.\n";$nativeSuccessful=true;
