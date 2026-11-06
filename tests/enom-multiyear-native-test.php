<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Cost, FeedObserved, OfferState, Query, TermSupport};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CostUnit, Environment, Money};
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestSendLimitSchema, RenewalCycles};

// Native debt, funding, seal and completion need the existing financial fixture; a pure rule test cannot
// prove invoice totals, the final no-send boundary or preservation of an incurred charge discrepancy.
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class, $clock);
EnomTestSendLimitSchema::install();
$review = static function ($d) use ($ports, $clock): void {
    $t = Tld::where('server_id', $d->server_id)->where('tld', 'com')->sole();
    $facts = TermSupport::attach('com', OfferState::facts($t->catalogue_facts), TermSupport::REVIEWED_EVIDENCE['enom']['com'] ?? null);
    DB::table('domains_tlds')->where('id', $t->id)->update(['catalogue_facts' => OfferState::encodeFacts($facts)]);
    $b = \Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id', $d->server_id)->sole());
    $q = new Query($b, ['com'], 'renew');
    $ports->apply(new FeedObserved($b, 'com', [new Cost('renew', 2, true, '16.0000', CostUnit::TermTotal,
        'enom_renew_annual_total', $clock->now(), $clock->now()->modify('+30 days'))]), $ports->readOffers($q, Context::Staff)[0]->revision);
    $choices = $GLOBALS['billing']->renewalChoices($d);
    same([1, 2], $choices['terms'], 'Normal owner panel offers reviewed Enom TEST two-year renewal');
    same(\Paymenter\Extensions\Servers\Domains\Services\PricingRule::total($choices['next_annual'], 2), $choices['lines'][2]['total'], 'Panel charges twice annual sell price');
};
[$op, $d, $b, $invoice] = $makeRenewal(placeholderComFixtureName('multiyear-test-placeholder.com'), true, 2, beforeBill: $review);
same('16.0000', $op->policy['renewal']['cost_cap']['amount'], 'Saved two-year ceiling is twice annual cost');
same($op->amount, $invoice->items()->sole()->price, 'Native invoice matches frozen customer total');
config(['domains.enom_identity.environment' => 'test', 'domains.enom_catalogue.enabled' => true, 'domains.enom_writes.renewal' => true]);
[$engine, $writer, $completion] = $compose($b);
same('pending', $engine->run($op->id, $writer)->state, 'Enom TEST two-year debt admitted and sent');
same('16.0000', $op->fresh()->attempt['authorized_cost']['amount'], 'Pre-send quote is annual cost times two');
same(['Extend', ['SLD' => explode('.', $d->domain, 2)[0], 'TLD' => 'com', 'NumYears' => 2, 'UseCreditCard' => 'no']], $state->lastWrite, 'One ordinary Extend uses two years and account balance');
$state->expiry = '2029-10-18'; $state->charge = '16.00';
same('succeeded', $completion->run($op->id)->state, 'Matching two-year charge and expiry complete');

// An increased annual quote must be multiplied before comparison with the immutable saved limit.
[$over, $overDomain, $overBinding] = $makeRenewal(placeholderComFixtureName('multiyear-limit-placeholder.com'), true, 2, beforeBill: $review);
[$overEngine, $overWriter] = $compose($overBinding); $state->price = '8.01'; $writes = $state->writes;
same('held', $overEngine->run($over->id, $overWriter)->state, 'Two-year quote above saved ceiling is held');
same('renewal_cost_overrun', $over->fresh()->safe_code, 'Over-limit reason retained');
same($writes, $state->writes, 'No send above the saved limit');
same([], (array) $over->fresh()->attempt, 'Over-limit debt remains unsealed');

// A larger staff cap does not turn an actual charge above the pre-send quote into successful completion.
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
[$compEngine, $compWriter, $compCompletion] = $compose($compBinding);
same('pending', $compEngine->run($comp->id, $compWriter)->state, 'Staff two-year cap admits the same quote');
$state->expiry = '2029-10-18'; $state->charge = '16.01';
$held = $compCompletion->run($comp->id);
same('uncertain', $held->state, 'Actual charge above quote waits for staff');
same(['actual_charge_changed', true], [$held->policy['renewal_execution']['reason'], $held->policy['renewal_execution']['staff_alert']], 'Incurred discrepancy alerts staff even below staff cap');
same('2027-10-18', $compDomain->fresh()->provider_expires_at->format('Y-m-d'), 'Discrepancy does not advance the accepted anchor');
same(true, $compDomain->fresh()->renewal_policy['automatic_spend_paused'], 'Discrepancy pauses future spending');

$live = new AccountBinding('enom', $b->serverId, Environment::Live, $b->fingerprint);
same('live_proof_required', $caps->renewalTerms($live, Tld::where('server_id', $b->serverId)->where('tld', 'com')->sole(), [], new DateTimeImmutable('2027-10-18'))[2]['reason'], 'Live panel still requires NS18 proof');
same(false, TermSupport::admits('enom', 2, $op->policy['renewal'], environment: Environment::Live), 'Frozen TEST debt cannot admit Live two-year renewal');
echo "Enom TEST multiyear native: ok\n";
$nativeSuccessful = true;
