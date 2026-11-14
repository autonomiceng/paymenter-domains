<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real native work leases, observer and fake wire; no live provider.
declare(strict_types=1);
require __DIR__.'/Support/owner-records.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,HoldingObservationWork,ObservationRecords,ObservationSchema,ReadDeferred};
ObservationSchema::install();[$d,$driver,$original]=$holding(placeholderComFixtureName('retry-records-placeholder.com'));
$d->server->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['type'=>'boolean','value'=>true]);
$worker=new HoldingObservationWork(new DomainObservation($readBindings,$clock,new AccountReadBudget($clock),$ports),$clock);
DB::table('domains_observation_queues')->insert(['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint]);
$queue=DB::table('domains_observation_queues')->sole();$guard=static fn()=>null;
// Real Blade + native customer controller are required to witness both visible family presentations.
storefrontViews();
$familyViews=static function()use(&$d,$clock,$page):array {
 $facts=\Paymenter\Extensions\Servers\Domains\Presentation\RecordFamilies::read($d->fresh(),$clock->now());
 return ['staff'=>view('domains::partials.record-families',['recordFamilies'=>$facts,'staff'=>true])->render(),'customer'=>$page()];
};
$assertFamily=static function(string $label,?int $queued=null,?string $cause=null,string $kind='dns',bool $stopped=false)use($familyViews):void {
 foreach($familyViews() as $surface=>$html){
  $dom=new DOMDocument;@$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);$xp=new DOMXPath($dom);
  $nodes=$xp->query('//span[@data-record-family="'.$kind.'"]');expect($nodes->length>0,$surface.' renders the '.$kind.' family');
  // Customers see no mark for a complete read; the row says how many records it found. An unread record is calm for
  // customers: Not read, with one note above the rows.
  $shown=$surface==='customer'&&$label==='Complete'?'':($surface==='customer'&&$label==='Missing'?\Paymenter\Extensions\Servers\Domains\Presentation\State::NotReadYet->label():$label);
  foreach($nodes as $node){same($shown,trim($node->textContent),$surface.' family label: expected '.$shown.', got '.trim($node->textContent));
   if($label==='Complete'&&$shown!==$label){same(1,$xp->query('./following-sibling::span[contains(@class,"domains-row__v") and normalize-space(.)="0 records"]',$node->parentNode)->length,'Customer complete family shows its record count');}
   same($label==='Missing'&&$surface==='staff'?1:0,$xp->query('.//svg',$node)->length,$surface.' warning icon only for staff Missing');
   $row=$node->parentNode;
   // ui-r15 R3: the queued read and the cause are said once above the rows, not on each row. Before the first read
   // customers see that we are checking with the registrar; staff see the queued time.
   if($queued!==null){expect(($surface==='customer'?substr_count($html,e(\Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::INITIAL_READ))===1:$xp->query('//time[@datetime="'.gmdate(DATE_ATOM,$queued).'"]')->length>0)&&$xp->query('.//time',$row)->length===0,$surface.' shows the queued read once, above the rows');}
   // Once the read stopped, the staff records head keeps the count, the cause in words and the time, said once.
   if($cause!==null&&$stopped&&$surface==='staff'){expect(substr_count($dom->textContent,'Stopped after 5 attempts. '.$cause)===1&&substr_count($dom->textContent,$cause)===1,'staff records head says the stopped count and cause once');}
   elseif($cause!==null&&$stopped&&$surface==='customer'){same(1,substr_count($dom->textContent,\Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::READ_HELD),'The customer stopped-read notice is said once');expect(!str_contains($dom->textContent,$cause),'Customer notice does not repeat the staff failure cause');}
   elseif($cause!==null&&$surface==='customer'){same(1,substr_count($dom->textContent,\Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::NOT_READ_NOTE),'Customers get one plain not-read note');expect(!str_contains($dom->textContent,$cause),'Customer note does not repeat the read cause');}
   elseif($cause!==null){expect(str_contains($dom->textContent,$cause)&&!str_contains($row->textContent,$cause),$surface.' shows safe cause once, above the rows');}
  }
 }
};
// Discover creates a due-now row. Yield at the existing catalogue seam before claiming any work.
$queued=$clock->now()->getTimestamp();
try {
 $worker->turn($queue,$guard,static function()use($d,$clock,$queued,$assertFamily):void {
  same($queued,(int)HoldingObservationWork::current($d->fresh())->sole()->due_at,'Discover schedules the first read now');
  $clock->instant=$clock->now()->modify('+5 seconds');$assertFamily('Checking',$queued);
  throw new ReadDeferred('Synthetic catalogue capacity yield before claim.');
 });
} catch(ReadDeferred) {}
$clock->instant=$clock->now()->setTimestamp($queued+20*60);$assertFamily('Checking',$queued); // A normal discovery batch queues up to 25 one-minute account turns.
$current=HoldingObservationWork::current($d->fresh());$current->update(['due_at'=>0,'claim'=>str_repeat('w',32),'lease_until'=>$clock->now()->getTimestamp()+300]);
$assertFamily('Checking',0); // An active worker lease is still an in-progress read, even after its due time.
$current->update(['due_at'=>$queued,'claim'=>null,'lease_until'=>0]);
$clock->instant=$clock->now()->setTimestamp($queued+31*60);$assertFamily('Missing',cause:'The scheduled registrar read is overdue.');
$observation->during=static fn()=>throw new DomainException('Registrar read failed.');
same('read_failed',$worker->turn($queue,$guard),'Transient primary failure is retained');
expect(!HoldingObservationWork::stopped($d->fresh()),'First transient failure schedules an automatic retry');
$assertFamily('Missing',cause:'The registrar read failed.');
$q=HoldingObservationWork::current($d->fresh());$job=(clone $q)->sole();same(60,$job->due_at-$clock->now()->getTimestamp(),'First retry uses bounded backoff');
same('idle',$worker->turn($queue,$guard),'No retry before due time');
$clock->instant=$clock->now()->modify('+60 seconds');same('refreshed',$worker->turn($queue,$guard),'Transient recovery needs no staff action');
expect(!ObservationRecords::held($d->fresh()),'Successful full-family retry clears stale failure hold');
// A family failure is also retried, even though the primary domain facts succeeded.
$q->update(['due_at'=>0]);$clock->instant=$clock->now()->modify('+61 seconds');$state->fault='GetDomainSRVHosts';
for($try=1;$try<=5;$try++){
 same('read_failed',$worker->turn($queue,$guard),'Family failure keeps its safe code');$job=(clone $q)->sole();same($try,(int)$job->tries,'Failure count is durable');
 if($try<5){expect(!HoldingObservationWork::stopped($d->fresh()),'Retry remains active before exhaustion');$clock->instant=$clock->now()->setTimestamp((int)$job->due_at);}
}
expect(HoldingObservationWork::stopped($d->fresh()),'Five failures stop with their exact cause');$assertFamily('Missing',cause:'The registrar read failed.',kind:'srv',stopped:true);same('The registrar read failed.',\Paymenter\Extensions\Servers\Domains\Services\HoldingReadRetry::status($d->fresh())['read_cause'],'The page notice names the exact cause');$assertFamily('Complete');$calls=count($state->calls);$clock->instant=$clock->now()->modify('+2 days');
same('idle',$worker->turn($queue,$guard),'Exhausted current work stays stopped');same($calls,count($state->calls),'Exhaustion does not loop');
// Legacy stopped work has no retry receipt. A repaired, verified account gets one recovery only.
$p=$d->fresh()->observation;unset($p['read_retry']);$d->fresh()->update(['observation'=>$p]);$q->update(['safe_code'=>'configuration_unverified','tries'=>5]);$state->fault='';
same('refreshed',$worker->turn($queue,$guard),'Legacy stop automatically resumes after configuration matches');
// Repair verification after the failed read transaction, before the worker saves its receipt.
// Native rollback events and encrypted settings reproduce the persistence race without wire I/O.
$verified=$d->server->settings()->where('key','domains_verified_credentials')->sole();$valid=$verified->value;
$verified->value='';$verified->save();$repaired=false;
\Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionRolledBack::class,static function()use($verified,$valid,&$repaired):void {
    if($repaired){return;}$repaired=true;$verified->value=$valid;$verified->save();
});
$q->update(['safe_code'=>null,'tries'=>0,'due_at'=>0]);$before=count($observation->calls);
same('configuration_unverified',$worker->turn($queue,$guard),'Pre-read verification failure is retained');
expect($repaired,'Verification was repaired between failure and persistence');same($before,count($observation->calls),'Unverified configuration sent no read');
$resumes=(int)(clone $q)->value('resumes');
same('refreshed',$worker->turn($queue,$guard),'Repair before failure persistence automatically resumes');
same($resumes+1,(int)(clone $q)->value('resumes'),'Repair uses one bounded automatic resume');
same('idle',$worker->turn($queue,$guard),'Successful recovery keeps the normal schedule');
// A refusal on unchanged verified credentials must not become an automatic retry loop.
$q->update(['safe_code'=>null,'tries'=>0,'due_at'=>0]);$clock->instant=$clock->now()->modify('+61 seconds');
$refusedWorker=new HoldingObservationWork(new DomainObservation($readBindings,$clock,new AccountReadBudget($clock),$ports,
    observationReader:static fn()=>throw new DomainException('Registrar configuration could not be verified.')),$clock);
same('configuration_unverified',$refusedWorker->turn($queue,$guard),'Reader verification refusal is retained');
$before=count($observation->calls);same('idle',$worker->turn($queue,$guard),'Unchanged failed configuration stays stopped');
same($before,count($observation->calls),'Stopped verification refusal sends no repeated read');
same($resumes+1,(int)(clone $q)->value('resumes'),'Unchanged failure consumes no extra resume');
$q->update(['safe_code'=>'binding_mismatch','tries'=>1,'due_at'=>0]);$calls=count($state->calls);
same('idle',$worker->turn($queue,$guard),'Permanent binding refusal stays stopped');same($calls,count($state->calls),'Permanent refusal sends no wire');
require_once __DIR__.'/Support/staff-panel.php';
$actor=staffUser([\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW,'admin.domains.manage']);
(new \Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords($actor->id,$d->id,$d->authority_generation,$d->account_fingerprint))->handle(new DomainObservation($readBindings,$clock,new AccountReadBudget($clock),$ports));
expect(!HoldingObservationWork::stopped($d->fresh()),'Successful optional staff retry restores daily read work');
same('refreshed',(clone $q)->value('safe_code'),'Manual read success does not leave a stale stopped heading');
foreach(['pending'=>300,'observed'=>86400]as$followState=>$delay){
 $current=$d->fresh();$p=$current->observation;$p['followup']=[...($p['followup']??[]),'state'=>$followState];$current->update(['observation'=>$p]);
 \Paymenter\Extensions\Servers\Domains\Services\HoldingReadRetry::completedManually($current);
 same($clock->now()->getTimestamp()+$delay,(int)HoldingObservationWork::current($current)->sole()->due_at,$followState.' manual read keeps automatic follow-up cadence');}
$nativeSuccessful=true;echo "Bounded automatic read recovery passed\n";
