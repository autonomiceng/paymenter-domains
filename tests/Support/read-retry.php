<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real native work leases, observer and fake wire; no live provider.
declare(strict_types=1);
require __DIR__.'/owner-records.php';
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
$assertFamily=static function(string $label,?int $queued=null,?string $cause=null,string $kind='dns',bool $stopped=false)use($familyViews,$d,$clock):void {
 foreach($familyViews() as $surface=>$html){
  $dom=new DOMDocument;@$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);$xp=new DOMXPath($dom);
  $initial=$surface==='customer'&&(\Paymenter\Extensions\Servers\Domains\Presentation\RecordFamilies::read($d->fresh(),$clock->now())['initial_read_pending']??false);
  $shown=$initial?'Checking':$label;
  if($initial){same(1,substr_count($dom->textContent,\Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::INITIAL_READ),'Initial customer read has one calm notice');}
  $nodes=$xp->query('//span[@data-record-family="'.$kind.'"]');expect($nodes->length>0,$surface.' renders the '.$kind.' family');
  foreach($nodes as $node){same($shown,trim($node->textContent),$surface.' family label: expected '.$shown.', got '.trim($node->textContent));
   $calm=$surface==='customer'&&str_contains($html,e(\Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::READ_HELD));
   same($shown==='Missing'&&!$calm?1:0,$xp->query('.//svg',$node)->length,$surface.' warning icon only for Missing without a held-read page notice');
   if($label==='Missing'&&$calm){expect($xp->query('.//span[contains(@class,"domains-missing--calm")]',$node)->length===1,'The held-read page notice leaves the customer family calm');}
   $row=$node->parentNode;
   // ui-r15 R3: the queued time and the cause are said once above the rows, not on each row.
   if($queued!==null&&!$initial){expect($xp->query('//time[@datetime="'.gmdate(DATE_ATOM,$queued).'"]')->length>0&&$xp->query('.//time',$row)->length===0,$surface.' shows queued time once, above the rows');}
   if($initial){expect(!str_contains($dom->textContent,'Next registrar read:')&&!str_contains($dom->textContent,'No registrar read is queued.'),'Initial customer read omits queue details');}
   // ui-r15 R4: once the read stopped, the staff page notice says the cause; the records head keeps the count and code.
   if($cause!==null&&$stopped&&$surface==='staff'){expect(!str_contains($dom->textContent,$cause)&&str_contains($dom->textContent,'Stopped after 5 attempts.'),'staff records head leaves the stopped cause to the page notice');}
   elseif($cause!==null&&$stopped&&$surface==='customer'){same(1,substr_count($dom->textContent,\Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::READ_HELD),'The customer stopped-read notice is said once');expect(!str_contains($dom->textContent,$cause),'The generic customer notice does not repeat the staff failure cause');}
   elseif($cause!==null&&!$initial){expect(str_contains($dom->textContent,$cause)&&!str_contains($row->textContent,$cause),$surface.' shows safe cause once, above the rows');}
  }
 }
};

$failFamilies=static function()use($d,$clock,$state,$worker,$queue,$guard){
$q=HoldingObservationWork::current($d->fresh());$q->update(['due_at'=>0]);$clock->instant=$clock->now()->modify('+61 seconds');$state->fault='GetDomainSRVHosts';
for($try=1;$try<=5;$try++){
 same('read_failed',$worker->turn($queue,$guard),'Family failure keeps its safe code');$job=(clone $q)->sole();same($try,(int)$job->tries,'Failure count is durable');
 if($try<5){expect(!HoldingObservationWork::stopped($d->fresh()),'Retry remains active before exhaustion');$clock->instant=$clock->now()->setTimestamp((int)$job->due_at);}
}
return $q;
};
