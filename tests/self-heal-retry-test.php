<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real native work leases, observer and fake wire; no live provider.
declare(strict_types=1);
require __DIR__.'/Support/owner-records.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,HoldingObservationWork,ObservationRecords,ObservationSchema,ReadDeferred};
ObservationSchema::install();[$d,$driver,$original]=$holding(placeholderComFixtureName('retry-records-placeholder.com'));
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
  $dom=new DOMDocument;@$dom->loadHTML($html);$xp=new DOMXPath($dom);
  $nodes=$xp->query('//span[@data-record-family="'.$kind.'"]');expect($nodes->length>0,$surface.' renders the '.$kind.' family');
  foreach($nodes as $node){same($label,trim($node->textContent),$surface.' family label: expected '.$label.', got '.trim($node->textContent));
   same($label==='Missing'?1:0,$xp->query('.//svg',$node)->length,$surface.' warning icon only for Missing');
   $row=$node->parentNode;
   // ui-r15 R3: the queued time and the cause are said once above the rows, not on each row.
   if($queued!==null){expect($xp->query('//time[@datetime="'.gmdate(DATE_ATOM,$queued).'"]')->length>0&&$xp->query('.//time',$row)->length===0,$surface.' shows queued time once, above the rows');}
   // ui-r15 R4: once the read stopped, the staff page notice says the cause; the records head keeps the count and code.
   if($cause!==null&&$stopped&&$surface==='staff'){expect(!str_contains($dom->textContent,$cause)&&str_contains($dom->textContent,'Stopped after 5 attempts.'),'staff records head leaves the stopped cause to the page notice');}
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
$current=HoldingObservationWork::current($d->fresh());$current->update(['due_at'=>0,'lease_until'=>$clock->now()->getTimestamp()+300]);
$assertFamily('Checking',0); // An active worker lease is still an in-progress read, even after its due time.
$current->update(['due_at'=>$queued,'lease_until'=>0]);
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
$q->update(['safe_code'=>'binding_mismatch','tries'=>1,'due_at'=>0]);$calls=count($state->calls);
same('idle',$worker->turn($queue,$guard),'Permanent binding refusal stays stopped');same($calls,count($state->calls),'Permanent refusal sends no wire');
require_once __DIR__.'/Support/staff-panel.php';
$actor=staffUser([\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW,'admin.domains.manage']);
(new \Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords($actor->id,$d->id,$d->authority_generation,$d->account_fingerprint))->handle(new DomainObservation($readBindings,$clock,new AccountReadBudget($clock),$ports));
expect(!HoldingObservationWork::stopped($d->fresh()),'Successful optional staff retry restores daily read work');
same('refreshed',(clone $q)->value('safe_code'),'Manual read success does not leave a stale stopped heading');
$nativeSuccessful=true;echo "Bounded automatic read recovery passed\n";
