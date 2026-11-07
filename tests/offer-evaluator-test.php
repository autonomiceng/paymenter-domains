<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Cost, Evaluator, Facts, FactsObserved, FeedObserved, Intent, Query, Revision, Snapshot};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CostUnit, Environment};

$now = new DateTimeImmutable('2026-11-02T20:00:00Z');
$seen = $now->modify('-1 hour'); $until = $now->modify('+1 day');
$revision = new Revision(7, 3, 2);
$evaluator = new Evaluator;
foreach (['enom', 'namesilo'] as $registrar) {
    $binding = new AccountBinding($registrar, 1, Environment::Test, 'synthetic-account');
    $cost = static fn (string $action, int $term, ?string $amount, CostUnit $unit = CostUnit::TermTotal): Cost =>
        new Cost($action, $term, $amount !== null, $amount, $unit, 'synthetic_feed', $seen, $until);
    $feed = new FeedObserved($binding, 'com', [$cost('register', 1, '10.0000'), $cost('renew', 1, '11.0000')]);
    $facts = new Facts(['register' => [1], 'renew' => [1]], [], true, false, false, 'synthetic_facts', $seen, $until);
    $offer = new Snapshot($feed, $facts, new Intent, $revision, $revision,
        ['register:1' => '13.00', 'renew:1' => '14.30'], $binding, accountReady: true, retainedAnnualRetail: '14.30');
    $make = static fn (array $changes): Snapshot => new Snapshot(...array_replace([
        'feed' => $feed, 'facts' => $facts, 'intent' => new Intent, 'revision' => $revision,
        'publishedRevision' => $revision, 'retail' => ['register:1' => '13.00', 'renew:1' => '14.30'],
        'seller' => $binding, 'accountReady' => true, 'retainedAnnualRetail' => '14.30',
    ], $changes));
    $query = new Query($binding, ['com'], 'register', expected: ['com' => $revision]);
    foreach ([Context::Staff, Context::Public, Context::Locked] as $context) {
        $decision = $evaluator->evaluate($offer, $query, $context, $now);
        same(true, $decision->available, $registrar.': verified offer is available in every sales context');
        same('13.00', $decision->retail, $registrar.': published retail is shared');
    }
    $staff = $evaluator->evaluate($offer, $query, Context::Staff, $now);
    same('10.0000', $staff->staffCost, $registrar.': authorized staff sees cost');
    $public = json_encode($evaluator->evaluate($offer, $query, Context::Public, $now), JSON_THROW_ON_ERROR);
    foreach (['cost', 'source', 'fingerprint', '10.0000'] as $private) {
        expect(!str_contains($public, $private), $registrar.': public output excludes '.$private);
    }
    // Every sales reader shares the same policy; only authorized staff gets the specific reason.
    $unavailable = static function (Snapshot $candidate, string $reason, ?Query $q = null) use ($evaluator, $query, $now, $registrar): void {
        foreach ([Context::Staff, Context::Public, Context::Locked] as $context) {
            $d = $evaluator->evaluate($candidate, $q ?? $query, $context, $now);
            same(false, $d->available, $registrar.': '.$reason.' closes every sales context');
            same(null, $d->retail, $registrar.': unavailable offers expose no sale price');
            same($context === Context::Staff ? $reason : 'unavailable', $d->reason, $registrar.': scoped reason');
        }
    };
    $unavailable($make(['intent' => new Intent(sell: false)]), 'staff_stopped');
    $unavailable($make(['intent' => new Intent(stops: ['register'])]), 'staff_stopped');
    $unavailable($make(['hidden' => true]), 'staff_stopped');
    $unavailable($make(['accountReady' => false]), 'account_unavailable');
    $unavailable($make(['intent' => new Intent(maxAnnualCost: '10.99', sell: true)]), 'cost_limit');
    $unavailable($make(['intent' => new Intent(maxAnnualCost: '10.00', stops: ['renew'])]), 'renewal_stopped');
    same(true, $evaluator->evaluate($make(['intent' => new Intent(defaultSell: false, sell: true)]), $query, Context::Public, $now)->available,
        $registrar.': explicit sell overrides the account default');
    $unavailable($make(['facts' => null]), 'unknown_facts');
    $unavailable($make(['facts' => new Facts($facts->terms, [], true, false, false, 'old_facts', $seen->modify('-2 days'), $now)]), 'stale_facts');
    $unavailable($make(['facts' => new Facts($facts->terms, [], true, false, false, 'future_facts', $until, $until->modify('+1 day'))]), 'stale_facts');
    foreach ([[null, true, false, false], [[], null, false, false], [[], false, false, false], [[], true, true, false], [[], true, false, true], [['required' => 'unsupported'], true, false, false]] as [$schema, $safe, $dns, $auto]) {
        $unavailable($make(['facts' => new Facts($facts->terms, $schema, $safe, $dns, $auto, 'unknown_facts', $seen, $until)]), 'unsupported_facts');
    }
    $unavailable($make(['publishedRevision' => null]), 'publication_pending');
    $unavailable($make(['publishedRevision' => new Revision(6, 3, 2)]), 'publication_pending');
    $unavailable($make(['publishedRevision' => new Revision(7, 2, 2)]), 'publication_pending');
    $unavailable($make(['publishedRevision' => new Revision(7, 3, 1)]), 'publication_pending');
    $other = new AccountBinding($registrar, 2, Environment::Test, 'synthetic-other-account');
    $unavailable($make(['seller' => $other]), 'seller_changed');
    $unavailable($make(['sellerActions' => []]), 'seller_changed');
    $foreign = $evaluator->evaluate($offer, new Query($other, ['com'], 'renew'), Context::Holder, $now);
    same(false, $foreign->available, $registrar.': holder cannot borrow another account retail');
    same(null, $foreign->staffCost, $registrar.': foreign binding reveals no cost');
    $foreignStaff = $evaluator->evaluate($offer, new Query($other, ['com'], 'register'), Context::Staff, $now);
    same([false, null], [$foreignStaff->available, $foreignStaff->staffCost], $registrar.': staff costs stay scoped to the queried account');
    $liveBinding = new AccountBinding($registrar, 1, Environment::Live, 'synthetic-account');
    same(false, $evaluator->evaluate($offer, new Query($liveBinding, ['com'], 'register'), Context::Public, $now)->available,
        $registrar.': test observations cannot authorize live sales');
    foreach ([new Revision(6, 3, 2), new Revision(7, 2, 2), new Revision(7, 3, 1), null] as $expected) {
        $stale = new Query($binding, ['com'], 'register', expected: $expected === null ? [] : ['com' => $expected]);
        same(false, $evaluator->evaluate($offer, $stale, Context::Locked, $now)->available, $registrar.': locked admission requires all revisions');
    }
    $disabled = new FeedObserved($binding, 'com', [$cost('register', 1, null), $cost('renew', 1, '11.0000')]);
    $unavailable($make(['feed' => $disabled]), 'provider_disabled');
    $omitted = new FeedObserved($binding, 'com', [$cost('renew', 1, '11.0000')]);
    $unavailable($make(['feed' => $omitted, 'retail' => ['renew:1' => '14.30']]), 'unknown_price');
    $unavailable($make(['feed' => new FeedObserved($binding, 'com', []), 'retail' => []]), 'unknown_price');
    $staleCost = new Cost('register', 1, true, '10.0000', CostUnit::TermTotal, 'old_cost', $seen->modify('-2 days'), $now);
    $unavailable($make(['feed' => new FeedObserved($binding, 'com', [$staleCost, $cost('renew', 1, '11.0000')])]), 'stale_price');
    $unavailable($make(['retail' => ['register:1' => '9.99', 'renew:1' => '14.30']]), 'below_cost');
    $unavailable($make(['retail' => ['register:1' => '0.00', 'renew:1' => '14.30']]), 'unpublished_price');
    $unavailable($make(['feed' => new FeedObserved($binding, 'com', [$cost('register', 1, '10.0000')]), 'retail' => ['register:1' => '13.00']]), 'unknown_price');
    // No interpolation: an annual listing never invents an unsupported second year.
    $two = new Query($binding, ['com'], 'register', 2, expected: ['com' => $revision]);
    $unavailable($make(['intent' => new Intent(defaultLongest: 2)]), 'unsupported_term', $two);
    $twoFacts = new Facts(['register' => [1, 2], 'renew' => [1]], [], true, false, false, 'exact_terms', $seen, $until);
    $unavailable($make(['facts' => $twoFacts, 'intent' => new Intent(defaultLongest: 2)]), 'unknown_price', $two);
    $exact = $cost('register', 2, '10.0000', CostUnit::Annual);
    same('20.0000', $exact->total, $registrar.': explicit annual rate normalizes to exact-term total');
    $twoOffer = $make(['feed' => new FeedObserved($binding, 'com', [$exact, $cost('register', 1, '10.0000'), $cost('renew', 1, '8.0000')]), 'facts' => $twoFacts,
        'intent' => new Intent(defaultLongest: 1, longest: 2, maxAnnualCost: '10.0000'), 'retail' => ['register:2' => '23.40', 'register:1' => '13.00', 'renew:1' => '10.40']]);
    same(true, $evaluator->evaluate($twoOffer, $two, Context::Locked, $now)->available, $registrar.': explicit longest override and inclusive cost limit');
    $raised = new Snapshot(new FeedObserved($binding, 'com', [$cost('register', 2, '20.0001'), $cost('register', 1, '10.0000'), $cost('renew', 1, '8.0000')]),
        $twoFacts, $twoOffer->intent, $revision, $revision, $twoOffer->retail, $binding, accountReady: true);
    $unavailable($raised, 'cost_limit', $two);
    same(true, $evaluator->evaluate($twoOffer, $two, Context::Public, $now)->available, $registrar.': cost fall re-enables without changing intent');
    $transferFeed = new FeedObserved($binding, 'com', [$cost('transfer', 1, '10.0000'), $cost('renew', 1, '11.0000')]);
    $transferFacts = new Facts(['transfer' => [1], 'renew' => [1]], [], true, false, false, 'transfer_facts', $seen, $until);
    $transfer = $make(['feed' => $transferFeed, 'facts' => $transferFacts, 'retail' => ['transfer:1' => '13.00', 'renew:1' => '14.30'], 'intent' => new Intent(transfers: true)]);
    $tq = new Query($binding, ['com'], 'transfer', expected: ['com' => $revision]);
    same(true, $evaluator->evaluate($transfer, $tq, Context::Public, $now)->available, $registrar.': explicit transfer override');
    $unavailable(new Snapshot($transferFeed, $transferFacts, new Intent(defaultTransfers: true, transfers: false), $revision, $revision, $transfer->retail, $binding, accountReady: true), 'staff_term_stopped', $tq);
    // Existing holders retain annual retail even on a deselected/stopped account with expired facts and no cost.
    $holderQuery = new Query($binding, ['com'], 'renew');
    $holder = $make(['feed' => new FeedObserved($binding, 'com', []), 'retail' => [], 'facts' => null,
        'intent' => new Intent(sell: false, stops: ['renew']), 'seller' => $other, 'publishedRevision' => null]);
    $held = $evaluator->evaluate($holder, $holderQuery, Context::Holder, $now);
    same([true, '14.30', 'retained_renewal'], [$held->available, $held->retail, $held->warning], $registrar.': frozen holder annual retail survives sales stops');
    same(false, $evaluator->evaluate($holder, $holderQuery, Context::Public, $now)->available, $registrar.': retained retail never authorizes public sales');
    same(false, $evaluator->evaluate($holder, $query, Context::Holder, $now)->available, $registrar.': holder context cannot acquire');
    same(false, $evaluator->evaluate($make(['retainedAnnualRetail' => null]), $holderQuery, Context::Holder, $now)->available,
        $registrar.': no current retail fallback without retained holder retail');
    $below = $make(['feed' => new FeedObserved($binding, 'com', [$cost('register', 1, '10.0000'), $cost('renew', 1, '20.0000')])]);
    $heldBelow = $evaluator->evaluate($below, $holderQuery, Context::Holder, $now);
    same([true, '14.30', 'below_cost'], [$heldBelow->available, $heldBelow->retail, $heldBelow->warning], $registrar.': below-cost renewal continues with warning');
    $factsCommand = new FactsObserved($binding, 'com', $facts);
    same($seen, $factsCommand->facts->observedAt, $registrar.': facts command preserves independent provenance');
    same($binding->fingerprint, $factsCommand->binding()->fingerprint, $registrar.': facts command remains account bound');
}
$reject = static function (Closure $call): void {
    try { $call(); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException('Invalid offer input escaped');
};
$reject(static fn () => new Cost('register', 1, true, null, CostUnit::Annual, 'synthetic_feed', $seen, $until));
$reject(static fn () => new Cost('register', 1, false, '10', CostUnit::Annual, 'synthetic_feed', $seen, $until));
$reject(static fn () => new Cost('register', 1, true, '10', CostUnit::Annual, 'synthetic_feed', $seen, $until, 'EUR'));
$reject(static fn () => new Cost('register', 10, true, '99999999.9999', CostUnit::Annual, 'synthetic_feed', $seen, $until));
$reject(static fn () => $cost('register', 1, '10.00001'));
$reject(static fn () => new FeedObserved($binding, 'com', [$cost('renew', 1, '10'), $cost('renew', 1, '11')]));
$reject(static fn () => new Query($binding, ['com'], 'renew', 2));
$reject(static fn () => new Revision(-1, 1, 1));
$reject(static fn () => new Facts(['register' => [1, 1]], [], true, false, false, 'synthetic_facts', $seen, $until));
echo "Neutral offer evaluator checks passed.\n";
