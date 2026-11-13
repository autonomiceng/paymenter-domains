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
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ListOperations;
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
// each notice status filter also reads encrypted automatic-transfer evidence.
same(7,count($opQueries),'Three domain-page batch queries and four queries for the account hold notice');
$userReads=array_filter($queries,fn($q)=>str_contains($q['query'],'from "users"')&&str_contains($q['query'],'"email"'));
same(1,count($userReads),'Customer descriptions use one eager query');
$customer=trim($viewer->first_name.' '.$viewer->last_name)?:$viewer->email;
$page->assertSee('Under review')->assertSee('Old registrar needs approval')->assertSee($customer)->assertSee('Offline registrar (synthetic)')->assertDontSee('Missing');
$instance=$page->instance();$columns=$instance->getTable()->getColumns();
$records=$instance->getTableRecords()->keyBy('id');
foreach([$held,$active,$uncertain] as $domain){assertDomainNameOneLine($page->html(),$domain->domain,'Domains list '.$domain->domain);}
$pending=\Paymenter\Extensions\Servers\Domains\Admin\Resources\PendingAdoptionResource::table(\Filament\Tables\Table::make($instance))->getColumn('domain');
assertDomainNameOneLine($pending->record($records[$uncertain->id])->toEmbeddedHtml(),$uncertain->domain,'Adoption pending list');
assertDomainNameWraps(Livewire::actingAs($viewer)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$uncertain->id])->instance()->getHeading()->toHtml(),$uncertain->domain,'Domain page title','title');
foreach([[$held,'Under review','Old registrar needs approval. Check request #'.$held->operations()->sole()->id.'.'],[$uncertain,'Under review','Old registrar needs approval. Check request #'.$uncertain->operations()->sole()->id.'.'],[$active,'Under review','Check the domain and its requests.']] as [$domain,$label,$reason]){
    $record=$records[$domain->id];$column=$columns['status']->record($record);
    same($label,$column->getState(),'A held or unconfirmed transfer reads the customer word');
    expect(str_contains((string)$column->formatState($column->getState()),'data-domains-tone="'.\Paymenter\Extensions\Servers\Domains\Presentation\Tone::Attention->value.'"'),'Staff have a job here');
    same($reason,$columns['note']->record($record)->getState(),'The next step sits in the note');
    same(\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::status($domain->fresh()),\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::status($record),'Batched list status matches the page');
    same(null,$column->getTooltip(),'No tooltip: the reason is visible');
    same(OwnedDomains::acquisition($record->operations,$record)?->id,$record->latestAcquisition?->id,'Batch matches customer acquisition lookup');
    same($customer,$columns['customer']->record($record)->getState(),'Customer name has its own column');
    // A phone keeps the status and next step: neither column waits for a wider screen.
    same([null,null,$reason],[$columns['status']->getVisibleFrom(),$columns['note']->getVisibleFrom(),$columns['note']->record($record)->getState()],'Phone keeps the status and next step from the row');
    same($label.$reason,$columns['domain']->getTooltip(),'The description tooltip keeps the phone summary without customer identifiers');
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
same(['Under review','Check the domain and its requests.'],[$column->getState(),$resolved->getTable()->getColumn('note')->record($record)->getState()],'Resolved latest acquisition never revives an older hold');
// Fallback descriptions preserve retained rows when native records are missing.
DB::table('domains_domains')->where('id',$held->id)->update(['user_id'=>999999,'server_id'=>999999]);
$fallback=Livewire::actingAs($viewer)->test(ListDomains::class)->assertSeeText($held->domain)->assertDontSee('#999999');
$record=$fallback->instance()->getTableRecords()->keyBy('id')[$held->id];$columns=$fallback->instance()->getTable()->getColumns();
same([null,'Missing'],[$columns['customer']->record($record)->getState(),$columns['server_id']->record($record)->getState()],'Missing customer and registrar account keep the row');
// Age grammar and exact staff copy; frozen timestamps avoid dependence on fixture business clocks.
$op=clone $base;$op->state='uncertain';$op->funding_basis='paid_invoice';$op->safe_code=null;
// The account hold names the one thing it holds and the time it began, never an age that grows per request.
$op->started_at=\Carbon\CarbonImmutable::createFromTimestampUTC(time()-7*3600-30);
same('NameSilo has not confirmed this transfer.',PaidUncertainty::summary($op),'Paid uncertainty title snapshot');
same(null,PaidUncertainty::holdSummary($op),'No hold line while WHOIS privacy is not sold');
$op->attempt=[];
same('NameSilo has not confirmed this transfer.',RequestFacts::for($op)['outcome'],'Uncertain request copy snapshot');
same(['Start after payment','Paymenter payment events'],array_keys(NativePaidHooks::notices()),'Payment event labels snapshot');
same('Paused. The invoice-paid listener is missing. Scheduled checks still pick up paid orders.',NativePaidHooks::notices()['Start after payment'],'Missing listener copy snapshot');
// An empty table says what fills it; a search that hid the rows says so instead.
Livewire::actingAs($viewer)->test(ListDomains::class)->set('tableSearch','no-such-name')->assertSee('No domains match')->assertDontSee('No domains yet')->assertDontSee('after a customer orders');
Livewire::actingAs($viewer)->test(ListOperations::class)->set('tableSearch','no-such-name')->assertSee('No requests match')->assertDontSee('Requests show here');
DB::beginTransaction();DB::table('domains_operations')->delete();DB::table('domains_domains')->delete();
Livewire::actingAs($viewer)->test(ListDomains::class)->assertSee('No domains yet')->assertSee('They show here after a customer orders one.');
Livewire::actingAs($viewer)->test(ListOperations::class)->assertSee('No registrar requests');
DB::rollBack();
echo "Staff list batch queries, labels, identity fallbacks and uncertainty copy passed.\n";$nativeSuccessful=true;
