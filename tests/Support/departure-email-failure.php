<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
// Shared departure email failure check. The caller sets $emailFault (breaks the email, may return a restore closure),
// $emailAlert (the staff alert code) and $emailDeliveries (delivery row states left behind), then requires this file.
require dirname(__DIR__).'/transfer-out-not-found-test.php';
require_once __DIR__.'/staff-panel.php';
require __DIR__.'/reminder-mail.php';
use Illuminate\Support\Facades\{DB,Mail};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Mail\DomainNotice;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingDeparture,HoldingLocks,MoneyOutcome,RenewalOwnership,StaffHoldingReview};

staffPanel();
$staff=staffUser([Staff::VIEW,'admin.domains.manage',HoldingDeparture::PERMISSION]);
$emailRestore=$emailFault($d);
$result=(new HoldingDeparture($clock))->confirm($staff,(int)$d->id,StaffHoldingReview::ticket((int)$staff->id,(int)$d->id,HoldingDeparture::ACTION,
    StaffHoldingReview::stamp(HoldingLocks::take($d->fresh(),advisory:true)),$clock),'other_registrar','Checked the registrar account.');
// An email failure never blocks or partly commits the decision: the holding departs once and its review closes.
same([MoneyOutcome::Applied,null,'departed'],[$result->outcome,$result->error?->getMessage(),$d->fresh()->status],'The email failure does not block the departure');
same(1,count(array_filter($d->fresh()->renewal_policy['staff_holdings'],fn($h)=>($h['action']??null)===HoldingDeparture::ACTION)),'The departure is recorded once');
expect(isset($d->fresh()->renewal_policy['departure']['confirmed_at']),'The frozen departure record is saved');
same(0,DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code',RenewalOwnership::REVIEW)->whereNull('resolved_at')->count(),'The departure review closes');
same(1,DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code',$emailAlert)->whereNull('resolved_at')->count(),'Staff get one email alert');
same(0,Mail::queued(DomainNotice::class)->count(),'No departure email is sent');
same($emailDeliveries,DB::table('domains_reminder_deliveries')->where('domain_id',$d->id)->orderBy('id')->pluck('state')->all(),'Only the expected delivery row is left');
if($emailRestore instanceof Closure){$emailRestore();}
