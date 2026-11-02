<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Cost, Evaluator, Facts, FeedObserved, Intent, Query, Revision, Snapshot};
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
    $query = new Query($binding, ['com'], 'register', expected: ['com' => $revision]);
    foreach ([Context::Staff, Context::Public, Context::Locked] as $context) {
        $decision = $evaluator->evaluate($offer, $query, $context, $now);
        same(true, $decision->available, $registrar.': verified offer is available in every sales context');
        same('13.00', $decision->retail, $registrar.': published retail is shared');
    }
}
echo "Neutral offer evaluator checks passed.\n";
