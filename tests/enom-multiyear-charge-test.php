<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native financial matrix; no provider calls.
declare(strict_types=1);
// This Enom incurred-charge proof never reads or sends through NameSilo.
$fixtureRegistrars = ['enom'];
require __DIR__.'/Support/enom-multiyear.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Cost, FeedObserved, OfferState, Query, TermSupport};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CostUnit, Environment, Money};
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestSendLimitSchema, RenewalCycles};
// A larger staff cap does not turn an actual charge above the pre-send quote into successful completion.
config(['domains.enom_identity.environment' => null]);
[$seed, $compDomain, $compBinding] = $makeRenewal(placeholderComFixtureName('multiyear-comp-placeholder.com'), false);
// The staff path creates its own complimentary obligation; cancel only the unpaid synthetic renewal first.
$cycles->retireUnpaid($seed->id);
$clock->instant = new DateTimeImmutable('2027-09-27T12:00:00Z'); $review($compDomain);
$staff = commerceUser();
$role = App\Models\Role::create(['name' => 'Synthetic funding', 'permissions' => ['admin.domains.manage', 'admin.domains.fund']]);
$staff->update(['role_id' => $role->id]);
$comp = $cycles->complimentary($compDomain->id, $staff, 2, new Money('20.00', 'USD'), 'staff_reviewed');
same('0.00', $comp->amount, 'Complimentary two-year customer amount remains zero');
$state->years = 2; $state->after = '2029-10-18'; $clock->instant = new DateTimeImmutable('2027-10-18T11:59:00Z'); Cache::flush();
config(['domains.enom_identity.environment' => 'test']);
[$compEngine, $compWriter, $compCompletion] = $compose($compBinding);
same('pending', $compEngine->run($comp->id, $compWriter)->state, 'Staff two-year cap admits the same quote');
same('16.0000', $comp->fresh()->attempt['authorized_cost']['amount'], 'Two-year authorized send freezes the full quote');
same(2, $state->lastWrite[1]['NumYears'], 'Ordinary Extend sends exactly two years');
$state->expiry = '2029-10-18'; $state->charge = '16.01';
$held = $compCompletion->run($comp->id);
same('uncertain', $held->state, 'Actual charge above quote waits for staff');
same(['actual_charge_changed', true], [$held->policy['renewal_execution']['reason'], $held->policy['renewal_execution']['staff_alert']], 'Incurred discrepancy alerts staff even below staff cap');
same('2027-10-18', $compDomain->fresh()->provider_expires_at->format('Y-m-d'), 'Discrepancy does not advance the accepted anchor');
same(true, $compDomain->fresh()->renewal_policy['automatic_spend_paused'], 'Discrepancy pauses future spending');

echo "Enom TEST multiyear incurred charge: ok\n";
$nativeSuccessful = true;
