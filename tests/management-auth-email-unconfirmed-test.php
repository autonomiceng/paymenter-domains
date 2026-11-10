<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// An auth code email whose send reply was lost stays unconfirmed: never resent and never marked sent. It no longer holds
// nameserver changes, the customer gets a step instead of an endless wait, and Check status says what the read showed.
$fixtureTransferRegistrars=['namesilo'];
require __DIR__.'/Support/management-namesilo.php';
require __DIR__.'/Support/staff-panel.php';
use Paymenter\Extensions\Servers\Domains\Admin\Support\{ManagementCheck,StaffReviewHistory};
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement,ManagementProgress,OwnerPorts,StaffOperationReview,StaffOperations};
[$d,$driver]=$holding('email-unconfirmed'); $ms->fault='lost'; $op=$submit($d,$driver,'auth_code_email');
same('uncertain',$management->run($op->id,$driver)->state,'Fixture: the email acknowledgement is lost');
$ms->fault=''; $ms->route='apibatch'; same('uncertain',$management->reconcile($op->id,$driver)->state,'Fixture: a read cannot confirm the email');
// Nameservers do not wait for it. The lock, contacts and another email still do.
$refused=null;
same(['nameservers'],$management->available($user,$d->fresh(),$driver,['nameservers','lock','contacts','auth_code_email'],$refused),'Only a change the email cannot affect opens');
same((int)$op->id,$refused,'The rest still say they wait for the email');
same((int)$op->id,(int)$submit($d->fresh(),$driver,'auth_code_email')->id,'Asking again returns the same unconfirmed email, not a new one');
try{$submit($d->fresh(),$driver,'lock',['locked'=>false]);throw new LogicException('lock admitted');}
catch(DomainException $e){same('Prior operation unresolved.',$e->getMessage(),'The lock still waits for the email');}
$ms->route='api'; $ns=$submit($d->fresh(),$driver,'nameservers',['nameservers'=>['ns3.example.net','ns4.example.net']]);
Illuminate\Support\Facades\Cache::forget('domains-write-slot:'.$ns->account_fingerprint);
same('succeeded',$management->run($ns->id,$driver)->state,'A nameserver change goes ahead');
same(['retrieveAuthCode','changeNameServers'],array_column($writes(),0),'The email was sent once and never again');
same(['uncertain','management_unconfirmed'],[$op->fresh()->state,$op->fresh()->safe_code],'The email stays unconfirmed');
// The customer is told what we know and what to do, not that we will keep trying.
Carbon\Carbon::setTestNow(now()->addMinutes(10));Carbon\CarbonImmutable::setTestNow(now());
$row=ManagementProgress::for($d->fresh())['auth_code_email'];
same(["We couldn't confirm the registrar sent the auth code email to the admin contact email. If it hasn't arrived, contact support.",'warning',true],
    [$row['text'],$row['tone']??null,$row['pending']],'The customer gets a step for the unconfirmed email');
// Staff Check status reads again, and the toast, notice and History say what it showed.
$staff=staffUser(['admin.domains.view','admin.domains.operations.recheck']);Illuminate\Support\Facades\Auth::setUser($staff);
app()->instance(DomainManagement::class,$management);app()->instance(OwnerPorts::class,new OwnerPorts(static fn()=>$driver));
$clock->instant=$clock->now()->modify('+20 minutes');$ms->route='apibatch';
(new StaffOperations($clock))->run($staff,$op->domain_id,$op->id,'recheck',StaffOperationReview::ticket($staff->id,$op->domain_id,$op->id,'recheck',StaffOperationReview::stamp($op->fresh(),$op->domain->fresh()),$clock),'Check status');
same('uncertain',$op->fresh()->state,'Check status never marks the email sent');
same(['retrieveAuthCode','changeNameServers'],array_column($writes(),0),'Check status never resends the email');
$words=ManagementCheck::words($op->fresh());
same([ManagementCheck::UNCONFIRMED,false,ManagementCheck::EMAIL],[$words['title'],$words['confirmed'],$words['detail']],'The check result names why it stays unconfirmed');
expect(str_starts_with($words['body'],'Checked '),'The result says when it was checked: '.$words['body']);
same('Status check: still unconfirmed',collect(StaffReviewHistory::rows($op->fresh()))->last()['event'],'History keeps the check result');
$nativeSuccessful=true;echo "Unconfirmed auth code email passed.\n";
