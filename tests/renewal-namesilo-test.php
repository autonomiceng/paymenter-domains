<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/reconciliation-namesilo-test.php';
$nativeSuccessful = false;
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Data\{Action, Disposition, Query, RenewalCode, RenewalRequest, RenewalStatus, RequestBudget, RequestContext, RequestOrigin, ResultReason};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, RenewalAcceptance, RenewalAdmission, RenewalAuthority};
// Ordinary admission uses the completed nonpremium purchase, never a fabricated provider domain ID.
$renewBindings = new AccountBindings($registry);
$admission = new RenewalAdmission(
    new RenewalAuthority($renewBindings, $clock), $renewBindings,
    new RenewalAcceptance($binding, true, true, 25, true), $clock);
$holding = $result->domain->fresh();
$request = static fn (?string $id = null) => new RenewalRequest($holding->id,
    $holding->authority_generation, $holding->confirmed_revision, '2027-10-18', $id);
$check = static function () use ($wire, $admission, $request) {
    Cache::flush(); $wire->commands = []; return $admission->check($request());
};
$decision = $check();
same(RenewalCode::Admitted, $decision->code, 'Completed nonpremium NameSilo holder admits ordinary renewal');
same(RenewalStatus::Ordinary, $decision->status, 'Only ordinary NameSilo state admitted');
same('8.95', $decision->cost?->amount, 'Exact annual account renewal cost retained');
same(['getDomainInfo', 'getPrices', 'getDomainInfo', 'getAccountBalance'], $wire->commands, 'Four bounded named reads, no writes');
same(null, $holding->provider_domain_id, 'Absent provider-domain ID is never fabricated');
$wire->price = '99.00'; $decision = $check();
same(RenewalCode::Admitted, $decision->code, 'Below-cost NameSilo renewal remains admitted');
same('below_cost', $decision->staffAlert, 'Below-cost NameSilo renewal only alerts staff'); $wire->price = '8.95';
$raw = $result->fresh();
same(false, $raw->attempt['registration_admission']['premium'], 'Sealed registration retains explicit nonpremium admission');
commerceReject(fn () => $raw->update(['attempt' => array_diff_key($raw->attempt, ['registration_admission' => true])]));
$before = serialize(DB::table('invoices')->get());
$ops = serialize(DB::table('domains_operations')->get());
$check();
same($before, serialize(DB::table('invoices')->get()), 'No NameSilo invoice mutation');
same($ops, serialize(DB::table('domains_operations')->get()), 'No NameSilo operation mutation');
same(null, $holding->service->fresh()->expires_at, 'Native expiry remains null');
$clock->instant = new DateTimeImmutable('2027-10-16T12:00:00Z');
same(RenewalCode::Admitted, $check()->code, 'Exactly two UTC calendar days admits');
foreach (['2027-10-17', '2027-10-18', '2027-10-19'] as $day) {
    $clock->instant = new DateTimeImmutable($day.'T12:00:00Z');
    same(RenewalCode::State, $check()->code, 'One day/today/past stays unknown');
    same(['getDomainInfo'], $wire->commands, 'Unsafe date stops before quotes');
}
$clock->instant = new DateTimeImmutable('2027-10-17T00:30:00+14:00');
same(RenewalCode::Admitted, $check()->code, 'UTC date controls margin across positive offset');
$clock->instant = new DateTimeImmutable('2027-10-16T23:30:00-02:00');
same(RenewalCode::State, $check()->code, 'UTC date controls margin across negative offset');
$clock->instant = new DateTimeImmutable('2027-10-16T23:59:59Z');
$decision = $check();
same('2027-10-17T00:00:00+00:00', $decision->validUntil?->format(DATE_ATOM), 'Validity stops when UTC margin ceases');
$wire->during = static function ($command) use ($clock) { $clock->instant = new DateTimeImmutable('2027-10-17T00:00:01Z'); };
same(RenewalCode::State, $check()->code, 'Date boundary during initial read refuses');
$clock->instant = new DateTimeImmutable('2027-10-16T23:59:59Z');
$wire->during = static function () use ($wire, $clock) {
    $wire->during = static fn () => $clock->instant = new DateTimeImmutable('2027-10-17T00:00:01Z');
};
same(RenewalCode::State, $check()->code, 'Date boundary during quote remains unknown');
$clock->instant = new DateTimeImmutable('2027-10-16T23:59:59Z');
$wire->during = static function () use ($wire, $clock) {
    $wire->during = static function () use ($wire, $clock) {
        $wire->during = static fn () => $clock->instant = new DateTimeImmutable('2027-10-17T00:00:01Z');
    };
};
same(RenewalCode::Changed, $check()->code, 'Date boundary during final reads refuses expired margin');
$clock->instant = new DateTimeImmutable('2026-10-18T12:15:01Z');
foreach (['Unknown', 'active', 'Active ', 'Expired', 'Redemption', ''] as $status) {
    $wire->status = $status;
    same(RenewalCode::State, $check()->code, 'Unaccepted status stays unknown');
}
$wire->status = 'Active';
foreach (['expires', 'status'] as $field) {
    $wire->omit = [$field];
    same(RenewalCode::State, $check()->code, 'Missing expiry/status stays unknown');
}
$wire->omit = [];
foreach (['2027-02-30', '', '2027-10-18T00:00:00Z'] as $expiry) {
    $wire->expiry = $expiry;
    same(RenewalCode::State, $check()->code, 'Malformed/non-date expiry refuses');
}
$wire->expiry = '2027-10-18'; $wire->quote = false;
same(RenewalCode::State, $check()->code, 'Missing exact suffix quote stays unknown');
$wire->quote = true;
$wire->auto = 'Yes';
same(RenewalCode::AutoRenew, $check()->code, 'NameSilo auto-renew on refuses');
$wire->auto = 'No'; $wire->balance = '8.94';
same(RenewalCode::Balance, $check()->code, 'Insufficient NameSilo balance refuses');
$wire->balance = '8.95';
same(RenewalCode::Admitted, $check()->code, 'Exact NameSilo balance admits');
// Privileged fixture edits exercise absence and contradictions; ordinary evidence edits are refused above.
$originalAttempt = $result->fresh()->attempt;
foreach ([null, ['premium' => true], ['premium' => null], ['name' => 'another.com'], ['years' => 2], ['cost' => ['amount' => '1.00', 'currency' => 'USD']]] as $change) {
    $attempt = $result->fresh()->attempt;
    if ($change === null) { unset($attempt['registration_admission']); }
    else { $attempt['registration_admission'] = array_replace($attempt['registration_admission'], $change); }
    $saved = $result->fresh()->getRawOriginal('attempt');
    $result->fresh()->forceFill(['attempt' => $attempt])->saveQuietly();
    if ($change === null) { commerceReject(fn () => $result->fresh()->update(['attempt' => $originalAttempt])); }
    same(RenewalCode::Cost, $check()->code, 'Missing/contradictory local nonpremium proof refuses');
    DB::table('domains_operations')->where('id', $result->id)->update(['attempt' => $saved]);
}
$saved = ['attempt' => $result->fresh()->getRawOriginal('attempt'), 'cost' => $result->fresh()->getRawOriginal('cost')];
foreach (['500.00', '8.94', '8.950000'] as $amount) {
    $attempt = $result->fresh()->attempt; $cost = $result->fresh()->cost;
    $cost['actual']['amount'] = $amount; $attempt['reconciliation']['charge'] = $cost['actual'];
    $result->fresh()->forceFill(['attempt' => $attempt, 'cost' => $cost])->saveQuietly();
    same($amount === '8.950000' ? RenewalCode::Admitted : RenewalCode::Cost, $check()->code, 'Actual charge must equal nonpremium authorization after decimal normalization');
    DB::table('domains_operations')->where('id', $result->id)->update($saved);
}
Cache::flush();
same(RenewalCode::Anchor, $admission->check($request('fabricated'))->code, 'No-ID request cannot fabricate a domain ID');
$reader = $renewBindings->reader($binding->serverId);
$ctx = static fn (int $budget) => new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+30 seconds'), new RequestBudget($budget), $clock);
$query = new Query($holding->domain, Action::Renew, 1);
foreach ([0, 1] as $budget) {
    $assessment = $reader->renewalAssessment($query, $ctx($budget));
    same(Disposition::Unknown, $assessment->disposition, 'Exhausted read budget remains unknown');
    same(ResultReason::Unverified, $assessment->reason, 'Exhausted budget remains unverified');
}
$assessment = $reader->renewalAssessment($query, $ctx(2));
same(null, $assessment->evidence()->premium, 'Provider never fabricates nonpremium evidence');
$wire->during = static fn () => DB::table('domains_domains')->where('id', $holding->id)->update(['confirmed_revision' => 2]);
same(RenewalCode::Anchor, $check()->code, 'Changed NameSilo authority during reads refuses');
echo "Native NameSilo no-ID completion and ordinary renewal admission passed\n"; $nativeSuccessful = true;
