<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/transfer-out-not-found-test.php';
require_once __DIR__.'/Support/staff-panel.php';
require __DIR__.'/Support/reminder-mail.php';
use Illuminate\Support\Facades\{DB,Mail};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Mail\DomainNotice;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingDeparture,HoldingLocks,MoneyOutcome,StaffHoldingReview};

$nativeSuccessful=false;
staffPanel();
$staff=staffUser([Staff::VIEW,'admin.domains.manage',HoldingDeparture::PERMISSION]);
// A bad time zone setting breaks the email data, never the departure: the decision commits once and staff get the alert.
config(['domains.time_zone'=>'Not/AZone']);
$result=(new HoldingDeparture($clock))->confirm($staff,(int)$d->id,StaffHoldingReview::ticket((int)$staff->id,(int)$d->id,HoldingDeparture::ACTION,
    StaffHoldingReview::stamp(HoldingLocks::take($d->fresh(),advisory:true)),$clock),'other_registrar','Checked the registrar account.');
same([MoneyOutcome::Applied,'departed'],[$result->outcome,$d->fresh()->status],'Email data that fails to build does not block the departure');
same(1,DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','template_failed')->whereNull('resolved_at')->count(),'Staff get one email alert');
same([0,0],[Mail::queued(DomainNotice::class)->count(),DB::table('domains_reminder_deliveries')->where('domain_id',$d->id)->count()],'No email and no delivery row');
same(1,count(array_filter($d->fresh()->renewal_policy['staff_holdings'],fn($h)=>($h['action']??null)===HoldingDeparture::ACTION)),'The departure is recorded once');
echo "Email data that fails to build leaves the staff alert and keeps the departure.\n";
$nativeSuccessful=true;
