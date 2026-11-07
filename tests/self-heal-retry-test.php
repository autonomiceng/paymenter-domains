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
$assertFamily=static function(string $label,?int $queued=null,?string $cause=null,string $kind='dns')use($familyViews):void {
 foreach($familyViews() as $surface=>$html){
  $dom=new DOMDocument;@$dom->loadHTML($html);$xp=new DOMXPath($dom);
  $nodes=$xp->query('//span[@data-record-family="'.$kind.'"]');expect($nodes->length>0,$surface.' renders the '.$kind.' family');
  foreach($nodes as $node){same($label,trim($node->textContent),$surface.' family label: expected '.$label.', got '.trim($node->textContent));
   same($label==='Missing'?1:0,$xp->query('.//svg',$node)->length,$surface.' warning icon only for Missing');
   $row=$node->parentNode;
   if($queued!==null){expect($xp->query('.//time[@datetime="'.gmdate(DATE_ATOM,$queued).'"]',$row)->length>0,$surface.' shows queued time');}
   if($cause!==null){expect(str_contains($row->textContent,$cause),$surface.' shows safe cause');}
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
// Native staff facts and the shared Blade partial reproduce the contradictory top rows
// and unbounded SVG; this cannot be covered by the retry policy alone.
require_once __DIR__.'/Support/staff-panel.php';
staffPanel();$staffActor=staffUser(array_keys(\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::permissions()));
\Illuminate\Support\Facades\Auth::setUser($staffActor);
$failedFacts=\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::for($d->fresh());
$familyHtml=view('domains::partials.record-families',['recordFamilies'=>\Paymenter\Extensions\Servers\Domains\Presentation\RecordFamilies::read($d->fresh(),$clock->now()),'staff'=>true])->render();
\Illuminate\Support\Facades\Auth::setUser($user);
$css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
writeRenderArtifact(sys_get_temp_dir().'/r14-records.html','<!doctype html><html class="dark"><head><meta charset="utf-8"><style>'.$css.'</style>'.view('domains::partials.staff-styles')->render().'</head><body><main class="domains-sec">'.$familyHtml.'</main></body></html>');
$x=new DOMDocument;@$x->loadHTML($familyHtml);$xp=new DOMXPath($x);$problems=[];
foreach($xp->query('//span[@data-record-family]//svg')as$icon){
 if((int)$icon->getAttribute('width')<1||(int)$icon->getAttribute('width')>20||(int)$icon->getAttribute('height')<1||(int)$icon->getAttribute('height')>20){$problems[]='Missing icon has no compact intrinsic size';break;}
}
foreach(['Last sync','DNS records']as$label){$f=$failedFacts[$label]??[];$text=json_encode($f);
 if(!str_contains($text,'The registrar read failed.')){$problems[]=$label.' does not explain the failed read';}
 if(str_contains($text,'Fills in by itself')||str_contains($text,'Use Sync now')){$problems[]=$label.' promises a day or asks for a manual sync';}
}
expect($problems===[],implode('; ',$problems));

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
expect(HoldingObservationWork::stopped($d->fresh()),'Five failures stop with their exact cause');$assertFamily('Missing',cause:'The registrar read failed.',kind:'srv');$assertFamily('Complete');$calls=count($state->calls);$clock->instant=$clock->now()->modify('+2 days');
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
$nativeSuccessful=true;echo "Bounded automatic read recovery passed\n";
