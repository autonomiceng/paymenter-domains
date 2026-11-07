<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$ownerFixtureNames=['owner-calendar-20270926'];
require __DIR__.'/Support/owner-auto-renew.php';
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController;
use Paymenter\Extensions\Servers\Domains\Presentation\DomainPage;
use Paymenter\Extensions\Servers\Domains\Services\{DomainSummaries,DomainBilling};
$controller=new OwnedDomainController($owners);
[$op]=$prepare(placeholderComFixtureName('owner-calendar-20270926-placeholder.com'));
$d=$op->domain()->firstOrFail();
$status=fn($actor=null)=>$controller->orderStatus($req($d,[],$actor),$d->id);
$pending=$status()->getData(true);
same(true,$pending['pending'],'Registration status polls while pending');
expect(str_contains($page($d),'data-domain-order-poll'),'Pending domain page starts its status reader');
try{$status($other);expect(false,'Another customer cannot read order status');}catch(\Illuminate\Database\Eloquent\ModelNotFoundException $e){same(\Paymenter\Extensions\Servers\Domains\Models\Domain::class,$e->getModel(),'Order status is owner scoped');}
same('succeeded',$reconcile->run($op->id)->state,'Local registration fixture completes');
$complete=$status()->getData(true);
same(false,$complete['pending'],'Polling stops when registration completes');
expect($complete['state']!==$pending['state'],'Completion changes the state fingerprint');
expect(!str_contains($page($d),'data-domain-order-poll'),'Completed page no longer polls');
same('no-store, private',$status()->headers->get('Cache-Control'),'Status must not be cached');
// A custom registrar calendar is the same on the service DTO, domain page and billing writer.
$server=$d->service->product->server;
foreach(['domains_issue_days'=>30,'domains_due_days'=>7] as $key=>$days){$server->settings()->updateOrCreate(['key'=>$key],['value'=>$days,'type'=>'integer']);}
$clock->instant=new DateTimeImmutable('2027-09-01T12:00:00Z');
$dto=$owners->present($d->fresh());
same(['expiry'=>'2027-10-18','issue'=>'2027-09-18','due'=>'2027-10-11'],$dto->renewalSchedule,'Service DTO uses registrar schedule');
$summary=(new DomainSummaries())->one($user,'managed',$d->id,$clock->now()->getTimestamp());
same($dto->renewalSchedule['issue'],$summary->renewalIssueAt,'Domain summary shares issue date');
same('Issues Sep 18, 2027. Due Oct 11, 2027.',DomainPage::nextInvoice($dto,$summary,null)['value'],'Issue and due dates are explicitly labelled');
$clock->instant=new DateTimeImmutable('2027-09-18T12:00:00Z');
$invoice=$billing->invoice($d->id);
same($dto->renewalSchedule['due'],$invoice->due_at->format('Y-m-d'),'Invoice uses the displayed due date');
$issued=$owners->present($d->fresh());
same([],$issued->renewalSchedule,'Issued invoice replaces the projected schedule');
same($invoice->due_at->format('Y-m-d'),$issued->renewalDue,'Retained invoice owns its due date');
$nativeSuccessful=true;echo "Order status and renewal dates passed.\n";
