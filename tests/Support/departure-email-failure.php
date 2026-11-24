<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/departure-fixture.php';
$invoice=$issueDepartureRenewal();
require_once __DIR__.'/staff-panel.php';
require __DIR__.'/reminder-mail.php';
use Illuminate\Support\Facades\{DB,Mail};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Mail\DomainNotice;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingDeparture,HoldingLocks,MoneyOutcome,RenewalOwnership,StaffHoldingReview};

require_once __DIR__.'/fixture-checkpoint.php';
staffPanel();
$staff=staffUser([Staff::VIEW,'admin.domains.manage',HoldingDeparture::PERMISSION]);
$restore = nativeRegistrarCheckpoint($clock, $state, $observation);
$actor = Illuminate\Support\Facades\Auth::user();
$checkFailure = static function (Closure $fault, string $emailAlert, array $emailDeliveries) use ($restore, $actor, $staff, $d, $clock): void {
    $restore();
    Illuminate\Support\Facades\Auth::setUser($actor);
    Mail::fake();
    Illuminate\Support\Facades\Queue::fake();
    $emailRestore=$fault();
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
};

$checkFailure(static function():Closure{$zone=config('domains.time_zone');config(['domains.time_zone'=>'Not/AZone']);return static fn()=>config(['domains.time_zone'=>$zone]);}, 'template_failed', []);
// The departed page reads the saved departure and still shows the open email alert, so staff can act on it.
Illuminate\Support\Facades\Auth::setUser($staff);
$html=html_entity_decode(strip_tags(Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$d->id])->assertSuccessful()->html()),ENT_QUOTES);
expect(Paymenter\Extensions\Servers\Domains\Admin\Support\DepartedPage::record($d->fresh())!==null,'The page reads the saved departure');
expect(str_contains($html,Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::alertCause('template_failed',$d->fresh())),'The departed page keeps the open email alert');
