<?php
// Cold reads adapted from Enom 6119f1c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Price, SalesPolicy, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances, Storefront, StorefrontCatalogueRead};

function storefrontMeasure(Closure $read): array
{
    Cache::flush(); DB::enableQueryLog(); DB::flushQueryLog();
    try { $result = $read(); $queries = DB::getQueryLog(); }
    finally { DB::disableQueryLog(); }
    foreach ($queries as $query) { expect(str_starts_with(strtolower($query['query']), 'select'), 'Public catalogue only reads the database'); }
    return [$result, count($queries)];
}

function storefrontQueryChecks(array $fixture, Storefront $store): void
{
    extract($fixture);
    extract(storefrontCatalogueFixture($fixture, ['qnet', 'qorg', 'qxyz'], range(1, 10)));
    foreach (array_values($catalogueTlds) as $index => $tld) {
        $tld->update(['fixed_register' => (string) (20 + 10 * $index), 'fixed_renew' => (string) (21 + $index), 'fixed_transfer' => (string) (22 + $index)]);
        $prices->republish($tld); $native->publish($tld->id, $now);
    }
    $before = $reads->calls;
    Tld::where('server_id', $server->id)->update(['max_years' => 1]);
    [$one, $oneQueries] = storefrontMeasure(static fn () => $store->endings());
    [$oneCheck, $oneCheckQueries] = storefrontMeasure(static fn () => $store->check('example.qnet'));
    Tld::where('server_id', $server->id)->update(['max_years' => 10]);
    [$ten, $tenQueries] = storefrontMeasure(static fn () => $store->endings());
    [$tenCheck, $tenCheckQueries] = storefrontMeasure(static fn () => $store->check('example.qnet'));
    same($before + 2, $reads->calls, 'Cold catalogue has no provider I/O; each explicit check has one availability read');
    $oneRows = array_column($one, null, 'tld'); $tenRows = array_column($ten, null, 'tld');
    foreach (array_keys($catalogueTlds) as $index => $ending) {
        $checked = $ending === 'qnet' ? $tenCheck : $store->check('example.'.$ending);
        same([1], $oneRows[$ending]['terms'], 'One-term public fixture');
        same(range(1, 10), $tenRows[$ending]['terms'], 'Ten-term public fixture');
        same('$'.(20 + 10 * $index).'.00', $tenRows[$ending]['register'], 'Cold acquisition price parity');
        same('$'.(21 + $index).'.00', $tenRows[$ending]['renew'], 'Cold annual renewal parity');
        same('$'.(22 + $index).'.00', $tenRows[$ending]['transfer'], 'Cold transfer parity');
        foreach (range(1, 10) as $term) {
            $strict = $store->offer($ending, 'register', $term);
            same($strict['price'], $checked['terms'][$term], 'Every term matches strict current-price resolution');
            same($strict['renew'], $tenRows[$ending]['renew'], 'Chosen offer reuses strict renewal price');
        }
        same($store->offer($ending, 'transfer', 1)['price'], $tenRows[$ending]['transfer'], 'Transfer matches strict current-price resolution');
    }
    same([1 => '$20.00'], $oneCheck['terms'], 'One-term check price parity');
    same([1 => '$20.00', 2 => '$40.00', 3 => '$60.00', 4 => '$80.00', 5 => '$100.00',
        6 => '$120.00', 7 => '$140.00', 8 => '$160.00', 9 => '$180.00', 10 => '$200.00'], $tenCheck['terms'], 'Ten-term exact price parity');
    echo 'Cold catalogue reads: one term '.$oneQueries.', ten terms '.$tenQueries.'; check one '.$oneCheckQueries.', check ten '.$tenCheckQueries.".\n";
    expect($oneQueries > 0 && $tenQueries <= $oneQueries + 3 && $tenQueries <= 240, 'Cold catalogue query growth is bounded: '.$oneQueries.' / '.$tenQueries);
    expect($oneCheckQueries > 0 && $tenCheckQueries <= $oneCheckQueries + 1 && $tenCheckQueries <= 85, 'Interactive catalogue query growth is bounded: '.$oneCheckQueries.' / '.$tenCheckQueries);
    SalesPolicy::whereIn('tld', ['qorg', 'qxyz'])->update(['register_enabled' => false, 'transfer_enabled' => false]);
    [$single, $singleQueries] = storefrontMeasure(static fn () => $store->endings());
    same(['com', 'qnet'], array_column($single, 'tld'), 'Single additional ending fixture');
    SalesPolicy::whereIn('tld', ['qorg', 'qxyz'])->update(['register_enabled' => true, 'transfer_enabled' => true]);
    echo 'Cold catalogue ending reads: one additional '.$singleQueries.', three additional '.$tenQueries.".\n";
    expect($tenQueries <= $singleQueries + 120, 'Incremental ending reads stay bounded');
    // Retained native prices must not substitute for missing, stale or unsupported current term evidence.
    $termPrice = DB::table('domains_prices')->where('server_id', $server->id)->where('tld', 'qnet')->where('action', 'register')->where('term', 1)->sole();
    $proof = json_decode($termPrice->eligibility, true); $badProof = $proof; $badProof['accepted_terms'] = [2, 3];
    foreach (['unknown_cost' => ['wholesale' => null], 'unknown_retail' => ['retail' => null], 'held' => ['hold_reason' => 'below_cost'],
        'unit' => ['unit' => 'annual'], 'future' => ['observed_at' => $now->modify('+1 second')], 'expiry' => ['fresh_until' => $now],
        'term_proof' => ['eligibility' => json_encode($badProof)]] as $reason => $change) {
        DB::table('domains_prices')->where('id', $termPrice->id)->update($change); $before = $reads->calls;
        $rows = array_column($store->endings(), null, 'tld');
        same(range(2, 10), $rows['qnet']['terms'], 'Current '.$reason.' rejects only the invalid term');
        same(null, $rows['qnet']['register'], 'Current '.$reason.' never leaks retained native annual price');
        same($before, $reads->calls, 'Invalid-term catalogue is local only');
        $result = $store->check('example.qnet');
        same(2, $result['term'], 'Check rejects '.$reason.' and chooses a proven term');
        expect(!array_key_exists(1, $result['terms']), 'Check has no invalid annual term');
        commerceReject(static fn () => $store->offer('qnet', 'register', 1));
        DB::table('domains_prices')->where('id', $termPrice->id)->update((array) $termPrice);
    }
    $annualPrice = $native->product($catalogueTlds['qnet'], 'register')->plans()->where('billing_period', 1)->sole()->prices()->sole();
    DB::table('domains_prices')->where('id', $termPrice->id)->update(['retail' => '0.00']);
    DB::table('prices')->where('id', $annualPrice->id)->update(['price' => '0.00']);
    same(range(2, 10), array_column($store->endings(), null, 'tld')['qnet']['terms'], 'Matching zero retail is never an acquisition offer');
    same(2, $store->check('example.qnet')['term'], 'Check refuses zero retail and keeps valid longer terms');
    commerceReject(static fn () => $store->offer('qnet', 'register', 1));
    DB::table('domains_prices')->where('id', $termPrice->id)->update((array) $termPrice);
    DB::table('prices')->where('id', $annualPrice->id)->update(['price' => '20.00']);
    $annual = $native->product($catalogueTlds['qnet'], 'register')->plans()->where('billing_period', 1)->sole();
    foreach (['unit' => ['billing_unit' => 'month'], 'type' => ['type' => 'one-time'], 'duplicate' => []] as $reason => $change) {
        if ($reason === 'duplicate') { $duplicate = $annual->replicate(); $duplicate->save(); }
        else { DB::table('plans')->where('id', $annual->id)->update($change); }
        $before = $reads->calls; $rows = array_column($store->endings(), null, 'tld');
        same(range(2, 10), $rows['qnet']['terms'], 'Invalid native plan '.$reason.' isolates the affected term');
        same($before, $reads->calls, 'Native plan validation does not read providers');
        same(2, $store->check('example.qnet')['term'], 'Check rejects invalid native plan '.$reason);
        commerceReject(static fn () => $store->offer('qnet', 'register', 1));
        if ($reason === 'duplicate') { $duplicate->delete(); }
        else { DB::table('plans')->where('id', $annual->id)->update($annual->only(array_keys($change))); }
    }
    foreach (['enabled', 'renew_enabled', 'register_enabled', 'transfer_enabled'] as $flag) {
        DB::table('domains_tlds')->where('id', $catalogueTlds['qnet']->id)->update([$flag => false]);
        $before = $reads->calls; $rows = array_column($store->endings(), null, 'tld');
        if (in_array($flag, ['enabled', 'renew_enabled'], true)) { expect(!isset($rows['qnet']), 'Disabled '.$flag.' withdraws the ending'); }
        else {
            same($flag === 'register_enabled' ? [] : range(1, 10), $rows['qnet']['terms'], 'TLD action flags isolate registration');
            same($flag === 'transfer_enabled' ? null : '$22.00', $rows['qnet']['transfer'], 'TLD action flags isolate transfer');
        }
        same($before, $reads->calls, 'TLD flags are local catalogue inputs');
        same($flag === 'transfer_enabled' ? 'available' : 'held', $store->check('example.qnet')['status'], 'Check reads current TLD '.$flag);
        DB::table('domains_tlds')->where('id', $catalogueTlds['qnet']->id)->update([$flag => true]);
    }
    // A reused Storefront instance models a long-lived container; every public invocation must read fresh facts.
    $register = $native->product($catalogueTlds['qnet'], 'register');
    $register->update(['stock' => 0]); $rows = array_column($store->endings(), null, 'tld');
    same([], $rows['qnet']['terms'], 'Native stock invalidates all registration terms');
    same('held', $store->check('example.qnet')['status'], 'Check cannot reuse prior in-stock registration');
    $register->update(['stock' => null]);
    same(range(1, 10), array_column($store->endings(), null, 'tld')['qnet']['terms'], 'Restored stock is visible');
    $catalogueTlds['qnet']->update(['fixed_register' => '25.00']); $before = $reads->calls;
    expect(!isset(array_column($store->endings(), null, 'tld')['qnet']), 'Unpublished rule change invalidates cold or warm rows');
    same('held', $store->check('example.qnet')['status'], 'Check refuses old rule proof');
    same($before, $reads->calls, 'Rule hold never reads availability');
    $catalogueTlds['qnet']->update(['fixed_register' => '20.00']);
    $policy = SalesPolicy::where('tld', 'qnet')->firstOrFail();
    $old = $store->offer('qnet', 'register', 1)['selection'];
    $sellers->change('qnet', $policy->revision, $server->id, false, true, $now);
    same([], array_column($store->endings(), null, 'tld')['qnet']['terms'], 'Current seller revision and action flags are read');
    $sellers->change('qnet', $policy->revision + 1, $server->id, true, true, $now);
    expect($store->offer('qnet', 'register', 1)['selection']->policyRevision > $old->policyRevision, 'Switch-back never reuses old seller revision');
    $freshSelection = (new StorefrontCatalogueRead($sellers, $native, new Paymenter\Extensions\Servers\Domains\Tests\FakeClock($now)))->offer('qnet', 'register', 1)['selection'];
    same($old->policyRevision + 2, $freshSelection->policyRevision, 'Display selection carries current seller revision');
    same($binding->fingerprint, $freshSelection->fingerprint, 'Display selection carries the selected account');
    foreach (['account_fingerprint' => str_repeat('b', 64), 'registrar_key' => 'namesilo'] as $field => $value) {
        DB::table('domains_sales_policies')->where('tld', 'qnet')->update([$field => $value]); $before = $reads->calls;
        expect(!isset(array_column($store->endings(), null, 'tld')['qnet']), 'Mismatched seller '.$field.' is rejected');
        same('held', $store->check('example.qnet')['status'], 'Check rejects mismatched seller identity');
        same($before, $reads->calls, 'Mismatched seller never reaches provider');
        DB::table('domains_sales_policies')->where('tld', 'qnet')->update([$field => $field === 'registrar_key' ? 'enom' : $binding->fingerprint]);
    }
    $account = Account::where('server_id', $server->id)->sole(); $before = $reads->calls;
    DB::table('domains_accounts')->where('id', $account->id)->update(['account_fingerprint' => str_repeat('a', 64)]);
    expect(!isset(array_column($store->endings(), null, 'tld')['qnet']), 'Changed account invalidates request reads');
    same('held', $store->check('example.qnet')['status'], 'Check refuses changed account');
    same($before, $reads->calls, 'Changed account never reaches provider');
    DB::table('domains_accounts')->where('id', $account->id)->update(['account_fingerprint' => $binding->fingerprint]);
    same('$20.00', array_column($store->endings(), null, 'tld')['qnet']['register'], 'Fresh read recovers restored account');
    $before = $reads->calls;
    $accepted = CatalogueAcceptances::load($binding); CatalogueAcceptances::save($binding, $accepted->revision, $accepted->endings);
    expect(!isset(array_column($store->endings(), null, 'tld')['qnet']), 'Changed acceptance revision invalidates old publication proof');
    same('held', $store->check('example.qnet')['status'], 'Check independently requires current acceptance revision');
    same($before, $reads->calls, 'Revoked publication proof has no provider I/O');
    foreach (array_keys($catalogueTlds) as $ending) {
        $policy = SalesPolicy::where('tld', $ending)->firstOrFail();
        $sellers->change($ending, $policy->revision, null, false, false, $now);
    }
    Cache::flush();
}
