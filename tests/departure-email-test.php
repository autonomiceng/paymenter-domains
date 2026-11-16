<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/transfer-out-not-found-test.php';
require_once __DIR__.'/Support/staff-panel.php';
require __DIR__.'/Support/reminder-mail.php';
use Illuminate\Support\Facades\{DB,Mail};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DepartedHistory,DomainHistory,Staff};
use Paymenter\Extensions\Servers\Domains\Mail\DomainNotice;
use Paymenter\Extensions\Servers\Domains\Services\{DepartureBlocked,HoldingDeparture,HoldingLocks,MoneyOutcome,StaffHoldingReview};

$nativeSuccessful=false;
staffPanel();
$staff=staffUser([Staff::VIEW,'admin.domains.manage',HoldingDeparture::PERMISSION]);
$departure=new HoldingDeparture($clock);
$confirm=static fn()=>$departure->confirm($staff,(int)$d->id,StaffHoldingReview::ticket((int)$staff->id,(int)$d->id,HoldingDeparture::ACTION,
    StaffHoldingReview::stamp(HoldingLocks::take($d->fresh(),advisory:true)),$clock),'other_registrar','Checked the registrar account.');
$sent=static fn()=>Mail::queued(DomainNotice::class)->count();
$deliveries=static fn()=>DB::table('domains_reminder_deliveries')->where('domain_id',$d->id)->where('template','domains_domain_departed')->get();

// Before confirming, staff see the Email job row while the departure email will send, and none once it is off.
$template=\App\Models\NotificationTemplate::where('key','domains_domain_departed')->firstOrFail();
expect((Paymenter\Extensions\Servers\Domains\Admin\Support\DepartureConfirm::rows($d->fresh())['email']??null)!==null,'Staff see the Email row while the departure email will send');
$template->update(['enabled'=>false]);
same(null,Paymenter\Extensions\Servers\Domains\Admin\Support\DepartureConfirm::rows($d->fresh())['email']??null,'No Email row while the departure email is off');
$template->update(['enabled'=>true]);
// With mail turned off for the site, neither the row nor the confirm dialog promises an email.
$mailOff=config('settings.mail_disable');config(['settings.mail_disable'=>true]);
same([null,null],[Paymenter\Extensions\Servers\Domains\Admin\Support\DepartureConfirm::rows($d->fresh())['email']??null,Paymenter\Extensions\Servers\Domains\Admin\Support\DepartureConfirm::subject($d->fresh(),'other_registrar')],'No email promised while mail is disabled');
config(['settings.mail_disable'=>$mailOff]);

// A blocked confirmation rolls back, and its reserved email goes with it.
$at=$clock->instant;$clock->instant=$clock->now()->modify('+25 hours');
$blocked=$confirm();
same([MoneyOutcome::Failed,true],[$blocked->outcome,$blocked->error instanceof DepartureBlocked],'A stale read blocks confirmation');
same([0,0],[$sent(),$deliveries()->count()],'A rolled-back confirmation sends no email and keeps no delivery');
$clock->instant=$at;

// The confirmation commits, then the customer gets exactly one email.
$result=$confirm();
same(MoneyOutcome::Applied,$result->outcome,'Confirmation applies');
same(1,$sent(),'The departure email is sent once after the decision commits');
$mail=Mail::queued(DomainNotice::class)->first();$owner=$d->fresh()->user;
same([true,$d->domain.' transferred away'],[$mail->hasTo($owner->email),$mail->envelope()->subject],'The email goes to the owner with the reason in its subject');
same(['accepted',1],[$deliveries()->first()?->state,$deliveries()->count()],'Its delivery is recorded as accepted');
$entry=DepartedHistory::entry($d->fresh());
same([$d->domain.' transferred away',$owner->email],[$entry['email']['subject']??null,$entry['email']['to']??null],'The confirmation history keeps the Email sent fact');
$rows=array_values(array_filter(DomainHistory::entries($d->fresh()),fn($r)=>is_array($r)&&($r['event']??null)==='Email sent'));
same(1,count($rows),'History lists the sent email');

// A repeated confirmation is refused and sends nothing more.
same(MoneyOutcome::Failed,$departure->confirm($staff,(int)$d->id,'','other_registrar','Checked the registrar account.')->outcome,'A departed holding cannot be confirmed again');
same([1,1],[$sent(),$deliveries()->count()],'A replay sends no second email');
echo "The departure email is reserved inside the decision and sent once after it commits.\n";
$nativeSuccessful=true;
