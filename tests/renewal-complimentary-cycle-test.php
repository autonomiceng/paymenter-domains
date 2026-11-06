<?php
// Enom d1392a8 complimentary/cancelled-unpaid cycles; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
$cycleFixtureGroup='complimentary';
require __DIR__.'/Support/renewal-cycle-fixture.php';
use App\Models\{InvoiceTransaction, Role};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks, PricingRule, RenewalFunding};
$staff = commerceUser(); $role = Role::create(['name'=>'Renewal funding','permissions'=>['admin.domains.manage','admin.domains.fund']]); $staff->update(['role_id'=>$role->id]);
foreach (['complete', 'revoked', 'unknown', 'over', 'exact', 'term', 'unauthorized'] as $case) {
    [$unpaid, $d, $b] = $makeRenewal(placeholderComFixtureName('complimentary-'.$case.'-placeholder.com'), false);
    $cycles->retireUnpaid($unpaid->id); $years = 1;
    commerceReject(fn () => $cycles->complimentary($d->id, $user, $years, new Money('8.00','USD'), 'staff_reviewed'));
    if ($case === 'term') {
        same([1],array_keys($cycles->complimentaryOptions($d->id,$staff)['terms']),'No exact quote offers annual complimentary funding only');
        $beforeLonger=serialize([$d->fresh()->getRawOriginal(),$d->service->fresh()->getRawOriginal(),Operation::where('domain_id',$d->id)->get()->map->getRawOriginal()->all()]);
        commerceReject(fn () => $cycles->complimentary($d->id,$staff,3,new Money('24.00','USD'),'staff_reviewed'));
        same($beforeLonger,serialize([$d->fresh()->getRawOriginal(),$d->service->fresh()->getRawOriginal(),Operation::where('domain_id',$d->id)->get()->map->getRawOriginal()->all()]),'Refused longer gift changes no authority or debt');
    }
    $nativePrice = $d->service->fresh()->price; $nativeMode = $d->fresh()->price_mode;
    $comp = $cycles->complimentary($d->id, $staff, $years, new Money('8.00','USD'), 'staff_reviewed');
    same('complimentary', $comp->funding_basis, 'Independent audited authority'); same($years, (int)$comp->term, 'Explicit fixed complimentary term');
    same(null, $comp->invoice_id, 'No complimentary invoice'); same($nativePrice, $d->service->fresh()->price, 'One-cycle gift preserves paid native retail');
    same($nativeMode,$d->fresh()->price_mode,'One-cycle gift preserves future paid mode');
    same($comp->id, $cycles->complimentary($d->id, $staff, $years, new Money('8.00','USD'), 'staff_reviewed')->id, 'Cycle idempotent');
    commerceReject(fn () => $cycles->complimentary($d->id, $staff, 2, new Money('16.00','USD'), 'staff_reviewed'));
    commerceReject(fn () => $comp->update(['authorized_by'=>$user->id])); commerceReject(fn () => $comp->fresh()->update(['funding_reason'=>'other']));
    [$engine,$writer,$completion] = $compose($b); $writes = $state->writes;
    if ($case === 'revoked') { $d->refresh()->update(['renewal_policy'=>array_diff_key($d->renewal_policy,['complimentary'=>true])]); }
    if ($case === 'unknown') { $state->price='?'; }
    if ($case === 'over') { $state->price='8.01'; }
    if ($case === 'unauthorized') { $role->update(['permissions'=>[]]); }
    // Role authorization is audited at issuance; revocation of the exact cycle is separate.
    $state->years = $years;
    $sent = $engine->run($comp->id,$writer);
    $refused = in_array($case,['revoked','unknown','over'],true);
    same($refused ? 'held':'pending', $sent->state, 'Complimentary '.$case.' disposition');
    same($writes+($refused?0:1),$state->writes,'Complimentary bounded send');
    if (in_array($case, ['complete', 'exact', 'term'], true)) {
        $d->refresh()->update(['renewal_policy'=>array_diff_key($d->renewal_policy,['complimentary'=>true])]);
        $state->expiry=(2027+$years).'-10-18'; $state->after=$state->expiry; $state->charge='8.00';
        same('succeeded',$completion->run($comp->id)->state,'Revocation after send retains incurred completion');
        $clock->instant=new DateTimeImmutable((2027+$years).'-09-27T12:00:00Z');
        same('pending',$billing->invoice($d->id,$user)->status,'Next cycle bills normally after one-cycle gift');
    }
    if ($case === 'unauthorized') { $role->update(['permissions'=>['admin.domains.manage','admin.domains.fund']]); }
}
same([],array_keys($registrations),'All independent acquisition scenarios ran');
echo "Native renewal cycle matrix passed\n"; $nativeSuccessful=true;
