<?php
// A longer registration costs the first year at the registration rate and later years at the renewal rate. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Cost, Evaluator, Facts, FeedObserved, Intent, Query, Retail, Revision, Snapshot};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CostUnit, Environment};

$now = new DateTimeImmutable('2026-11-10T20:00:00Z');
$seen = $now->modify('-1 hour'); $until = $now->modify('+1 day');
$revision = new Revision(7, 3, 2);
$binding = new AccountBinding('enom', 1, Environment::Test, 'synthetic-account');
$cost = static fn (string $action, int $term, string $amount, CostUnit $unit = CostUnit::TermTotal): Cost =>
    new Cost($action, $term, true, $amount, $unit, 'synthetic_feed', $seen, $until);
$facts = new Facts(['register' => [1, 2, 5], 'renew' => [1]], [], true, false, false, 'exact_terms', $seen, $until);
// Enom TEST quotes .net at 15.55 for one year and one yearly rate of 8.95 for two to nine years.
$feed = static fn (array $extra = []): FeedObserved => new FeedObserved($binding, 'net', [$cost('register', 1, '15.5500'), $cost('renew', 1, '8.9500'),
    $cost('register', 2, '8.9500', CostUnit::Annual), $cost('register', 5, '8.9500', CostUnit::Annual), ...$extra]);
$offer = static fn (array $retail, ?FeedObserved $f = null): Snapshot => new Snapshot($f ?? $feed(), $facts, new Intent(defaultLongest: 5), $revision, $revision,
    $retail, $binding, accountReady: true, retainedAnnualRetail: $retail['renew:1']);
$evaluator = new Evaluator;
$two = new Query($binding, ['net'], 'register', 2, expected: ['net' => $revision]);
$five = new Query($binding, ['net'], 'register', 5, expected: ['net' => $revision]);

// The registrar's two-year total is 17.90, but the sale costs 15.55 + 8.95 = 24.50, so 17.99 sells at a loss.
$retail = ['register:1' => '16.99', 'register:2' => '17.99', 'register:5' => '60.00', 'renew:1' => '9.99'];
foreach ([Context::Staff, Context::Public, Context::Locked] as $context) {
    $d = $evaluator->evaluate($offer($retail), $two, $context, $now);
    same(false, $d->available, 'A two-year price under the first year plus one renewal is not sold');
}
same('below_cost', $evaluator->evaluate($offer($retail), $two, Context::Staff, $now)->reason, 'Staff see the below-cost reason');
$covered = $offer(['register:1' => '16.99', 'register:2' => '26.98', 'register:5' => '56.95', 'renew:1' => '9.99']);
$d = $evaluator->evaluate($covered, $two, Context::Staff, $now);
same([true, '24.5000'], [$d->available, $d->staffCost], 'Staff cost for two years is the first year plus one renewal');
$d = $evaluator->evaluate($covered, $five, Context::Staff, $now);
same([true, '51.3500'], [$d->available, $d->staffCost], 'Staff cost for five years is the first year plus four renewals');
// A registrar quote above the registry rule stays the cost.
$bracket = new FeedObserved($binding, 'net', [$cost('register', 1, '8.9800'), $cost('renew', 1, '8.9800'), $cost('register', 2, '32.0000', CostUnit::Annual)]);
same('64.0000', Retail::cost($bracket->costs['register:2'], $bracket->costs), 'A higher registrar quote stays the cost');
same('8.9500', Retail::cost($cost('renew', 1, '8.9500')), 'One-year and renewal costs are unchanged');
echo "multi-year sale cost: ok\n";
