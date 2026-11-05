<?php
// NS17 terms and renewal families, paired Enom/NameSilo; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Catalogue\{Cost, ExpansionFacts, Facts, OfferState, TermSupport};
use Paymenter\Extensions\Servers\Domains\Data\CostUnit;
$at = static fn (string $v) => new DateTimeImmutable($v);
$throws = static function (callable $f, string $message): void { try { $f(); } catch (InvalidArgumentException|DomainException) { return; } expect(false, $message); };
$now = $at('2026-11-04T12:00:00+00:00');
$proof = ['source' => 'synthetic_registry', 'version' => 'rule-1', 'reviewed_at' => '2026-11-01T00:00:00+00:00', 'valid_until' => '2026-12-01T00:00:00+00:00'];
$term = static fn (int $y, string $unit = 'term_total') => $proof + ['terms' => [$y], 'price_unit' => $unit, 'rule' => 'exact-term-1'];
$family = $proof + ['terms' => [2, 3, 5], 'price_unit' => 'term_total', 'rule' => 'renew-family-1', 'states' => ['Active'],
    'expiry_precision' => 'date', 'horizon_years' => 10, 'safe_boundary' => 'utc-two-days'];
$evidence = static fn (array $f = [], array $t = []) => ['term_evidence' => ['format' => 1, 'entries' => ['renew' => $t + [2 => $term(2), 3 => $term(3), 5 => $term(5)]]],
    'renewal_families' => ['format' => 1, 'entries' => ['ordinary' => $f + $family]]];
$facts = static fn (array $expansion = [], string $until = '2026-12-01T00:00:00+00:00') => new Facts(['register' => [1, 2], 'renew' => [1], 'transfer' => [1]],
    [], true, false, false, 'synthetic', $at('2026-11-01T00:00:00+00:00'), $at($until), $expansion);
$cost = static fn (int $y, ?string $amount, string $until = '2026-11-04T13:00:00+00:00', CostUnit $unit = CostUnit::TermTotal)
    => new Cost('renew', $y, $amount !== null, $amount, $unit, 'synthetic_quote', $at('2026-11-04T11:00:00+00:00'), $at($until));
$costs = ['renew:1' => $cost(1, '14.0000'), 'renew:2' => $cost(2, '24.0000'), 'renew:3' => $cost(3, '36.0000')];
$expiry = $at('2027-11-20T00:00:00+00:00');
$reasons = static fn (array $terms) => array_map(static fn (array $t) => $t['reason'], $terms);

// Codec: facts without evidence keep the old stored shape; evidence rides as top-level siblings.
$old = ['terms' => ['register' => [1, 2], 'renew' => [1], 'transfer' => [1]], 'registryFields' => [], 'renewalSafe' => true,
    'requiresNameservers' => false, 'autoRenewOnly' => false, 'source' => 'synthetic', 'observedAt' => '2026-11-01T00:00:00+00:00', 'freshUntil' => '2026-12-01T00:00:00+00:00'];
same(json_encode($old), OfferState::encodeFacts($facts()), 'Old facts encode byte for byte');
$stored = OfferState::encodeFacts($facts($evidence()));
same(['term_evidence', 'renewal_families'], array_slice(array_keys(json_decode($stored, true)), -2), 'Evidence is stored as catalogue_facts siblings');
expect(OfferState::facts($stored) == $facts($evidence()), 'Evidence round-trips through the codec');
same([1], OfferState::facts($stored)->terms['renew'], 'Evidence never widens the evaluator renewal set');
same($term(2), OfferState::termEvidence($stored, 'renew', 2, $now), 'C0 reader sees produced evidence');
same([], OfferState::facts(json_encode($old + ['term_evidence' => 'bad']))->expansion, 'Unreadable sibling is dropped; ordinary facts survive');
$throws(fn () => $facts(['other' => []]), 'Unknown expansion key refused');
$throws(fn () => $facts(['term_evidence' => 'bad']), 'Non-array evidence refused');

// Exact terms only: longer renewals are exact totals; annual units would be the guessed cap.
$throws(fn () => $cost(2, '12.0000', unit: CostUnit::Annual), 'Annual unit cannot price a longer renewal');
$throws(fn () => OfferState::cost(['action' => 'renew', 'term' => 2, 'enabled' => true, 'total' => '24.0000', 'currency' => 'USD',
    'source_unit' => 'annual', 'source' => 'x', 'observed_at' => '2026-11-04T11:00:00+00:00', 'fresh_until' => '2026-11-04T13:00:00+00:00']), 'Stored annual longer renewal refused');
$throws(fn () => new Cost('transfer', 2, true, '9.0000', CostUnit::TermTotal, 'x', $now, $now->modify('+1 hour')), 'Transfer stays annual');
same('24.0000', $cost(2, '24.0000')->total, 'Exact two-year renewal total is kept as observed');

foreach (['enom', 'namesilo'] as $registrar) {
    $exact = $registrar === 'enom';
    // Old rows: annual renewal stays; no longer term without reviewed evidence.
    $legacy = TermSupport::renewal($registrar, 'com', $facts(), $costs, $expiry, $now);
    same(range(1, 8), array_keys($legacy), $registrar.': years stop at ten years from today');
    same([true, null, '14.0000', null], [$legacy[1]['available'], $legacy[1]['reason'], $legacy[1]['cost'], $legacy[1]['rule']], $registrar.': annual renewal unchanged');
    same(array_fill(2, 7, $exact ? 'family_unreviewed' : 'no_exact_quote'), array_slice($reasons($legacy), 1, null, true), $registrar.': old rows hold longer terms');

    $terms = TermSupport::renewal($registrar, 'com', $facts($evidence()), $costs, $expiry, $now);
    $expected = $exact ? [1 => null, 2 => null, 3 => null, 4 => 'term_unreviewed', 5 => 'unknown_price', 6 => 'term_unreviewed', 7 => 'term_unreviewed', 8 => 'term_unreviewed']
        : [1 => null] + array_fill(2, 7, 'no_exact_quote');
    same($expected, $reasons($terms), $registrar.': exact-term evidence and exact costs decide each year');
    if ($exact) {
        $rule = $terms[2]['rule'];
        same([2, '2029-11-20', '24.0000'], [$rule['years'], $terms[2]['expiry'], $terms[2]['cost']], 'Exact cost and new expiry for two years');
        same(['ordinary', 'rule-1', 'renew-family-1', 'utc-two-days', 10], [$rule['family']['name'], $rule['family']['version'], $rule['family']['rule'],
            $rule['family']['safe_boundary'], $rule['family']['horizon_years']], 'Family version and boundary are frozen');
        same(['exact-term-1', 'term_total', '24.0000', '2029-11-20'], [$rule['term']['rule'], $rule['term']['price_unit'], $rule['cost']['amount'], $rule['boundary']['expiry']], 'Term rule and exact cost are frozen');
        same(['amount' => '14.0000', 'currency' => 'USD'], TermSupport::cap($terms, 1), 'Annual cap is the annual cost');
        same(['amount' => '24.0000', 'currency' => 'USD'], TermSupport::cap($terms, 2), 'Two-year cap is the exact term cost, not 2 x 14');
        $throws(fn () => TermSupport::cap($terms, 4), 'Held term has no cap');
        $throws(fn () => TermSupport::cap($terms, 9), 'Term past the horizon has no cap');
        $annual = TermSupport::renewal($registrar, 'com', $facts($evidence(t: [2 => $term(2, 'annual')])), $costs, $expiry, $now);
        same('annual_unit', $annual[2]['reason'], 'Annual-unit evidence is not an exact cap');
        same('annual_unit', TermSupport::renewal($registrar, 'com', $facts($evidence(['price_unit' => 'annual'])), $costs, $expiry, $now)[2]['reason'], 'Annual-unit family is not exact');
    } else {
        $throws(fn () => TermSupport::cap($terms, 2), 'NameSilo has no longer-term cap: getPrices has no years parameter');
    }

    // Stale facts and evidence: every expiry boundary closes the longer term, never year 1.
    $stale = [
        'stale_facts' => [$facts($evidence(), '2026-11-04T12:00:00+00:00'), $costs],
        'stale_price' => [$facts($evidence()), ['renew:2' => $cost(2, '24.0000', '2026-11-04T12:00:00+00:00')] + $costs],
        'family_unreviewed' => [$facts($evidence(['valid_until' => '2026-11-04T12:00:00+00:00'])), $costs],
        'family_unreviewed ' => [$facts($evidence(['reviewed_at' => '2026-11-04T12:00:01+00:00'])), $costs],
        'term_unreviewed' => [$facts($evidence(t: [2 => ['valid_until' => '2026-11-04T12:00:00+00:00'] + $term(2)])), $costs],
        'provider_disabled' => [$facts($evidence()), ['renew:2' => $cost(2, null)] + $costs],
        'unknown_facts' => [null, $costs],
    ];
    foreach ($stale as $reason => [$f, $c]) {
        $t = TermSupport::renewal($registrar, 'com', $f, $c, $expiry, $now);
        same([true, $exact ? trim($reason) : 'no_exact_quote'], [$t[1]['available'], $t[2]['reason']], $registrar.': '.$reason);
    }

    // Date boundaries: the ten-year registry horizon, the family horizon and Feb 29.
    $midnight = $at('2026-11-04T00:00:00+00:00');
    same(range(1, 9), array_keys(TermSupport::renewal($registrar, 'com', $facts($evidence()), $costs, $at('2027-11-04T00:00:00+00:00'), $midnight)), $registrar.': exactly ten years ahead is inside');
    same(range(1, 8), array_keys(TermSupport::renewal($registrar, 'com', $facts($evidence()), $costs, $at('2027-11-05T00:00:00+00:00'), $midnight)), $registrar.': one day past is outside');
    same('2029-02-28', TermSupport::renewal($registrar, 'com', $facts(), $costs, $at('2028-02-29T00:00:00+00:00'), $now)[1]['expiry'], $registrar.': Feb 29 does not overflow');
    $short = TermSupport::renewal($registrar, 'com', $facts($evidence(['horizon_years' => 3])), $costs, $at('2027-01-10T00:00:00+00:00'), $now);
    same($exact ? [null, 'horizon'] : ['no_exact_quote', 'no_exact_quote'], [$short[2]['reason'], $short[3]['reason']], $registrar.': family horizon bounds longer terms');

    // Empty inventories: nothing observed grants nothing beyond the annual path.
    $empty = TermSupport::renewal($registrar, 'com', $facts($evidence()), [], $expiry, $now);
    same([true, null, $exact ? 'unknown_price' : 'no_exact_quote'], [$empty[1]['available'], $empty[1]['cost'], $empty[2]['reason']], $registrar.': empty prices');
    same([], TermSupport::renewal($registrar, 'com', $facts($evidence()), $costs, $at('2037-01-01T00:00:00+00:00'), $now), $registrar.': expiry past the horizon offers nothing');
    same([], TermSupport::attach('dev', $facts(), $evidence())->expansion, $registrar.': unreviewed suffix carries no evidence');
    same(array_fill(2, 7, 'not_reviewed'), array_slice($reasons(TermSupport::renewal($registrar, 'dev', $facts($evidence()), $costs, $expiry, $now)), 1, null, true), $registrar.': .dev stays annual');
    same($evidence(), TermSupport::attach('org', $facts(), $evidence() + ['other' => 1])->expansion, $registrar.': first-pass suffix keeps reviewed keys only');

    // Off retention: withdrawn evidence closes new longer debt; issued debt keeps its frozen rule and cap.
    $withdrawn = TermSupport::renewal($registrar, 'com', TermSupport::attach('com', $facts($evidence()), null), $costs, $expiry, $now);
    same([true, $exact ? 'family_unreviewed' : 'no_exact_quote'], [$withdrawn[1]['available'], $withdrawn[2]['reason']], $registrar.': refresh without evidence holds new longer terms');
    $issued = ['term' => 2, 'cost_cap' => ['amount' => '24.0000', 'currency' => 'USD'], 'term_rule' => $terms[2]['rule'] ?? ['years' => 2, 'cost' => ['amount' => '24.0000']]];
    same([true, true, false, false, false, false], [TermSupport::admits(1, null), TermSupport::admits(2, $issued), TermSupport::admits(3, $issued),
        TermSupport::admits(2, array_diff_key($issued, ['term_rule' => 1])), TermSupport::admits(2, ['cost_cap' => ['amount' => '20.0000', 'currency' => 'USD']] + $issued),
        TermSupport::admits(2, ['term' => 3] + $issued)], $registrar.': admission follows only the frozen rule');
    same(true, TermSupport::admits(2, ['cost_cap' => ['amount' => '30.0000', 'currency' => 'USD']] + $issued), $registrar.': a higher staff cap still covers the exact cost');
}

// Families: ordinary/grace/redemption stay distinct; grace stays held without live proof.
same(['annual_only', 'family_unreviewed', [1]], array_values(TermSupport::families($facts(), $now)['ordinary']), 'Old rows are annual only');
$grace = $evidence(); $grace['renewal_families']['entries']['grace'] = $family + ['restoration_disabled' => true];
$f = TermSupport::families($facts($grace), $now);
same([['reviewed', [1, 2, 3, 5], 10, 'rule-1'], ['held', 'live_proof_required'], ['separate', 'restoration_request']],
    [[$f['ordinary']['state'], $f['ordinary']['terms'], $f['ordinary']['horizon_years'], $f['ordinary']['version']], array_values($f['grace']), array_values($f['redemption'])], 'Family readiness');
same('family_unreviewed', TermSupport::families($facts($evidence()), $now)['grace']['reason'], 'Grace without evidence is unreviewed');
$ready = TermSupport::readiness('enom', 'com', $facts($evidence()), $costs, $now);
same([range(1, 10), null, 'unknown_price', 'reviewed'], [array_keys($ready['renew']), $ready['renew'][2]['reason'], $ready['renew'][5]['reason'], $ready['families']['ordinary']['state']], 'Staff readiness from today');

// Price lines and cart rechecks, using the approved mock amounts.
same(['years' => 3, 'first_year' => '12.00', 'renewal' => '14.00', 'renewal_years' => 2, 'total' => '40.00'], TermSupport::lines('12.00', '14.00', 3), 'First year plus renewal x (years - 1)');
same(['26.00', '138.00', '28.00'], [TermSupport::lines('12', '14.00', 2)['total'], TermSupport::lines('12.00', '14.00', 10)['total'], TermSupport::lines('14.00', '14.00', 2)['total']], 'Mock totals');
$throws(fn () => TermSupport::lines('12.00', '14.00', 11), 'Lines stop at ten years');
$cart = [2 => TermSupport::lines('12.00', '14.00', 2)];
same(['same', 'changed', 'removed', 'missing'], [TermSupport::recheck(2, '26.00', $cart)['state'], TermSupport::recheck(2, '26.00', [2 => TermSupport::lines('12.00', '16.00', 2)])['state'],
    TermSupport::recheck(2, '26.00', [1 => TermSupport::lines('12.00', '14.00', 1)])['state'], TermSupport::recheck(2, '26.00', [2 => ['total' => null]])['state']], 'Cart cases');
same(['state' => 'changed', 'was' => '26.00', 'now' => '28.00'], TermSupport::recheck(2, '26.00', [2 => TermSupport::lines('12.00', '16.00', 2)]), 'Was/now for a changed total');
echo "demo-c terms: ok\n";
