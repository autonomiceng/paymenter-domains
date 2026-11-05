<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real native work leases, observer and fake wire; no live provider.
declare(strict_types=1);
require __DIR__.'/Support/owner-records.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,HoldingObservationWork,ObservationRecords,ObservationSchema};
ObservationSchema::install();[$d,$driver,$original]=$holding(placeholderComFixtureName('retry-records-placeholder.com'));
$worker=new HoldingObservationWork(new DomainObservation($readBindings,$clock,new AccountReadBudget($clock),$ports),$clock);
DB::table('domains_observation_queues')->insert(['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint]);
$queue=DB::table('domains_observation_queues')->sole();$guard=static fn()=>null;
// Real Blade + native customer controller are required to witness both visible family presentations.
storefrontViews();
DB::table('domains_observation_work')->insert(['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint,
 'domain_id'=>$d->id,'generation'=>$d->authority_generation,'phase'=>HoldingObservationWork::phase($d),'due_at'=>$clock->now()->getTimestamp()+60]);
$familyViews=static function()use(&$d,$clock,$page):array {
 $facts=\Paymenter\Extensions\Servers\Domains\Presentation\RecordFamilies::read($d->fresh(),$clock->now());
 return ['staff'=>view('domains::partials.record-families',['recordFamilies'=>$facts,'staff'=>true])->render(),'customer'=>$page()];
};
$assertFamily=static function(string $label,?int $queued=null,?string $cause=null)use($familyViews):void {
 foreach($familyViews() as $surface=>$html){
  $dom=new DOMDocument;@$dom->loadHTML($html);$xp=new DOMXPath($dom);
  $nodes=$xp->query('//span[@data-record-family="dns"]');expect($nodes->length>0,$surface.' renders the DNS family');
  foreach($nodes as $node){same($label,trim($node->textContent),$surface.' family label');
   same($label==='Missing'?1:0,$xp->query('.//svg',$node)->length,$surface.' warning icon only for Missing');
   $row=$node->parentNode;
   if($queued!==null){expect($xp->query('.//time[@datetime="'.gmdate(DATE_ATOM,$queued).'"]',$row)->length>0,$surface.' shows queued time');}
   if($cause!==null){expect(str_contains($row->textContent,$cause),$surface.' shows safe cause');}
  }
 }
};
$assertFamily('Checking',$clock->now()->getTimestamp()+60);
$current=HoldingObservationWork::current($d->fresh());$current->update(['due_at'=>0,'lease_until'=>$clock->now()->getTimestamp()+300]);
$assertFamily('Checking',0); // An active worker lease is still an in-progress read, even after its due time.
$current->update(['due_at'=>$clock->now()->getTimestamp()+60,'lease_until'=>0]);
$clock->instant=$clock->now()->modify('+61 seconds');$assertFamily('Missing',cause:'The scheduled registrar read is overdue.');
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
expect(HoldingObservationWork::stopped($d->fresh()),'Five failures stop with their exact cause');$assertFamily('Missing',cause:'The registrar read failed.');$calls=count($state->calls);$clock->instant=$clock->now()->modify('+2 days');
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
