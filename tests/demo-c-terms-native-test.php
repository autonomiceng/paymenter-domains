<?php
// NS17 renewal terms over stored catalogue rows, paired Enom/NameSilo; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require_once __DIR__.'/Support/FakeRegistrar.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Capabilities, CallerPorts, Cost, Facts, OfferState, Pricing, Retail, Writer};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CostUnit, Environment};
use Paymenter\Extensions\Servers\Domains\Models\{Price, Tld};
use Paymenter\Extensions\Servers\Domains\Services\Installation;
(new Installation)->install();
$now = new DateTimeImmutable('2026-11-04T12:00:00+00:00');
$clock = new \Paymenter\Extensions\Servers\Domains\Tests\FakeClock($now);
$proof = ['source' => 'synthetic_registry', 'version' => 'rule-1', 'reviewed_at' => '2026-11-01T00:00:00+00:00', 'valid_until' => '2026-12-01T00:00:00+00:00'];
$evidence = ['term_evidence' => ['format' => 1, 'entries' => ['renew' => [2 => $proof + ['terms' => [2], 'price_unit' => 'term_total', 'rule' => 'exact-term-1']]]],
    'renewal_families' => ['format' => 1, 'entries' => ['ordinary' => $proof + ['terms' => [2], 'price_unit' => 'term_total', 'rule' => 'renew-family-1',
        'states' => ['Active'], 'expiry_precision' => 'date', 'horizon_years' => 10, 'safe_boundary' => 'utc-two-days']]]];
$facts = new Facts(['register' => [1], 'renew' => [1], 'transfer' => [1]], [], true, false, false, 'synthetic',
    new DateTimeImmutable('2026-11-01T00:00:00+00:00'), new DateTimeImmutable('2026-12-01T00:00:00+00:00'), $evidence);
$observe = static fn (string $action, int $term, string $total) => OfferState::json(OfferState::encodeCost(new Cost($action, $term, true, $total,
    CostUnit::TermTotal, 'synthetic_quote', new DateTimeImmutable('2026-11-04T11:00:00+00:00'), new DateTimeImmutable('2026-11-04T13:00:00+00:00'))));
$ports = (new ReflectionClass(CallerPorts::class))->newInstanceWithoutConstructor();
$caps = new Capabilities($ports, $clock, static fn () => null);
foreach ([Environment::Test, Environment::Live] as $environment) {
foreach (['enom', 'namesilo'] as $registrar) {
    $server = App\Models\Server::create(['name' => 'Synthetic '.$registrar, 'type' => 'server', 'extension' => 'Domains', 'enabled' => true]);
    $binding = new AccountBinding($registrar, $server->id, $environment, str_repeat($registrar === 'enom' ? 'e' : 'n', 64));
    $row = ['server_id' => $server->id, 'registrar_key' => $registrar, 'account_fingerprint' => $binding->fingerprint];
    DB::table('domains_tlds')->insert($row + ['tld' => 'com', 'catalogue_converted' => true, 'catalogue_revision' => 1,
        'catalogue_intent' => OfferState::json(['sell' => false]), 'catalogue_facts' => OfferState::encodeFacts($facts), 'created_at' => $now, 'updated_at' => $now]);
    foreach ([['renew', 1, '14.0000'], ['renew', 2, '24.0000'], ['register', 2, '21.0000']] as [$action, $term, $total]) {
        DB::table('domains_prices')->insert($row + ['tld' => 'com', 'action' => $action, 'term' => $term, 'currency' => 'USD', 'unit' => 'term_total',
            'source' => 'synthetic_quote', 'observed_at' => '2026-11-04 11:00:00', 'catalogue_observation' => $observe($action, $term, $total), 'created_at' => $now, 'updated_at' => $now]);
    }
    $tld = Tld::where('server_id', $server->id)->where('tld', 'com')->sole();
    $prices = Price::where(['server_id' => $server->id, 'tld' => 'com', 'action' => 'renew'])->orderBy('id')->get();
    expect(OfferState::facts($tld->catalogue_facts) == $facts, $registrar.': stored evidence round-trips through domains_tlds');
    $terms = $caps->renewalTerms($binding, $tld, $prices, new DateTimeImmutable('2027-11-20T00:00:00+00:00'));
    same(range(1, 8), array_keys($terms), $registrar.': stored holding years stay inside the horizon');
    // Reviewed TEST evidence cannot open Live; NameSilo keeps its existing quote refusal.
    $available = $registrar === 'enom' && $environment === Environment::Test;
    $held = $registrar === 'enom' ? ($available ? null : 'live_proof_required') : 'no_exact_quote';
    same([$available, $available ? '24.0000' : null, $held], [$terms[2]['available'], $terms[2]['cost'], $terms[2]['reason']], $registrar.'/'.$environment->value.': stored two-year renewal follows reviewed TEST authority');
    same([true, '14.0000'], [$terms[1]['available'], $terms[1]['cost']], $registrar.': annual path unchanged');
    // Staff Off (sell false) is sales intent; holder renewal terms and the retained annual path do not depend on it.
    same($terms, $caps->renewalTerms($binding, $tld, $prices, new DateTimeImmutable('2027-11-20T00:00:00+00:00')), $registrar.': reads are repeatable');
    $ready = $caps->termReadiness($binding, 'com');
    same([range(1, 10), $held, 'reviewed'],
        [array_keys($ready['renew']), $ready['renew'][2]['reason'], $ready['families']['ordinary']['state']], $registrar.': staff readiness');
    // A row from another account or a changed TLD binding refuses instead of mixing evidence.
    $foreign = clone $prices->first(); $foreign->account_fingerprint = str_repeat('f', 64);
    try { $caps->renewalTerms($binding, $tld, [$foreign], new DateTimeImmutable('2027-11-20T00:00:00+00:00')); expect(false, $registrar.': foreign price refused'); }
    catch (DomainException) {}
    $other = new AccountBinding($registrar, $server->id, $environment, str_repeat('0', 64));
    try { $caps->renewalTerms($other, $tld, $prices, new DateTimeImmutable('2027-11-20T00:00:00+00:00')); expect(false, $registrar.': changed binding refused'); }
    catch (DomainException) {}
    // Account policy can shorten the age of an already stored bulk observation. Native settings/rows
    // are required to exercise the same owner-cap and staff readers used after a settings edit.
    $annualPrice = $prices->firstWhere('term', 1); $original = $annualPrice->catalogue_observation;
    $bulk = new Cost('renew', 1, true, '14.0000', CostUnit::TermTotal,
        $registrar === 'enom' ? 'enom_bulk_bucket_1' : 'namesilo_account_annual', $now->modify('-25 hours'), $now->modify('+23 hours'));
    DB::table('domains_prices')->where('id', $annualPrice->id)->update(['catalogue_observation' => OfferState::json(OfferState::encodeCost($bulk))]);
    $server->settings()->updateOrCreate(['key' => 'domains_price_freshness_hours'], ['value' => 24, 'type' => 'integer']);
    $policyPrices = Price::where(['server_id' => $server->id, 'tld' => 'com', 'action' => 'renew'])->get();
    $policyTerms = $caps->renewalTerms($binding, $tld, $policyPrices, new DateTimeImmutable('2027-11-20T00:00:00+00:00'));
    $policyReady = $caps->termReadiness($binding, 'com');
    same([null, null], [$policyTerms[1]['cost'], $policyReady['renew'][1]['cost']], $registrar.': shortened account age removes stale annual caps and readiness costs');
    // A derived two-year total shares its annual source's account age policy after a settings change.
    if ($registrar === 'enom' && $environment === Environment::Test) {
        $twoYear = $prices->firstWhere('term', 2); $savedTwoYear = $twoYear->catalogue_observation;
        $derived = new Cost('renew', 2, true, '24.0000', CostUnit::TermTotal,
            'enom_renew_annual_total', $now->modify('-25 hours'), $now->modify('+23 hours'));
        DB::table('domains_prices')->where('id', $twoYear->id)->update(['catalogue_observation' => OfferState::json(OfferState::encodeCost($derived))]);
        $checkDerived = static fn () => $caps->renewalTerms($binding, $tld, Price::where(['server_id' => $server->id, 'tld' => 'com', 'action' => 'renew'])->get(), new DateTimeImmutable('2027-11-20T00:00:00+00:00'));
        same('stale_price', $checkDerived()[2]['reason'], 'Shortened account age removes a derived two-year quote');
        $server->settings()->updateOrCreate(['key' => 'domains_price_freshness_hours'], ['value' => 48, 'type' => 'integer']);
        same('24.0000', $checkDerived()[2]['cost'], 'Current account age restores the same derived observation without redating it');
        DB::table('domains_prices')->where('id', $twoYear->id)->update(['catalogue_observation' => $savedTwoYear]);
    }
    $server->settings()->updateOrCreate(['key' => 'domains_price_freshness_hours'], ['value' => 48, 'type' => 'integer']);
    $policyTerms = $caps->renewalTerms($binding, $tld, $policyPrices, new DateTimeImmutable('2027-11-20T00:00:00+00:00'));
    same('14.0000', $policyTerms[1]['cost'], $registrar.': current account age restores the same observation without redating it');
    DB::table('domains_prices')->where('id', $annualPrice->id)->update(['catalogue_observation' => $original]);
    // Empty inventory: no stored prices leaves only the annual path, with no known cost.
    $empty = $caps->renewalTerms($binding, $tld, [], new DateTimeImmutable('2027-11-20T00:00:00+00:00'));
    same([true, null, $available ? 'unknown_price' : $held], [$empty[1]['available'], $empty[1]['cost'], $empty[2]['reason']], $registrar.': empty inventory');
    // Publishing a renew:2 observation after renew:1 never replaces the stored annual retail.
    $publish = new ReflectionMethod(Writer::class, 'publishPrices');
    $publish->invoke((new ReflectionClass(Writer::class))->newInstanceWithoutConstructor(), $tld, $now);
    $annual = Retail::quote(OfferState::cost(OfferState::decode($observe('renew', 1, '14.0000'))), Pricing::rule($tld));
    $longer = Retail::quote(OfferState::cost(OfferState::decode($observe('renew', 2, '24.0000'))), Pricing::rule($tld));
    expect($annual !== $longer, $registrar.': fixture separates annual and two-year retail');
    same($annual, $tld->catalogue_retained_annual_retail, $registrar.': retained annual retail comes from renew:1 only');
    // Native writer mutations reconstruct and store facts on both metadata and bulk-price refreshes.
    // Real SQLite rows are needed to exercise the second path's independent stored-price read.
    $mutate = new ReflectionMethod(Writer::class, 'mutate');
    $writer = (new ReflectionClass(Writer::class))->newInstanceWithoutConstructor();
    $target = clone $tld; $target->catalogue_facts = null;
    $command = new \Paymenter\Extensions\Servers\Domains\Catalogue\FactsObserved($binding, 'com', $facts);
    same(true, $mutate->invoke($writer, $command, $target, $prices, $now), $registrar.': metadata refresh changes facts');
    DB::table('domains_tlds')->where('id', $target->id)->update(['catalogue_facts' => $target->catalogue_facts]);
    same($evidence, OfferState::facts($target->fresh()->catalogue_facts)->expansion,
        $registrar.': metadata writer preserves reviewed term and family evidence');
    $bulk = new \Paymenter\Extensions\Servers\Domains\Catalogue\FeedObserved($binding, 'com', [
        new Cost('renew', 1, true, '15.0000', CostUnit::TermTotal,
            $registrar === 'enom' ? 'enom_bulk_bucket_1' : 'namesilo_account_annual', $now, $now->modify('+48 hours'))]);
    same(true, $mutate->invoke($writer, $bulk, $target, $prices, $now), $registrar.': bulk refresh changes cost');
    DB::table('domains_tlds')->where('id', $target->id)->update(['catalogue_facts' => $target->catalogue_facts]);
    $saved = OfferState::facts($target->fresh()->catalogue_facts);
    same([[1], $evidence, $facts->observedAt->format(DATE_ATOM)], [$saved->terms['renew'], $saved->expansion, $saved->observedAt->format(DATE_ATOM)],
        $registrar.': bulk writer preserves reviewed evidence and metadata age while deriving annual support');
}
}
$nativeSuccessful = true;
echo "demo-c terms native: ok\n";
