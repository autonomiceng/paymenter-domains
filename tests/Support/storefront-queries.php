<?php
// Cold reads adapted from Enom 6119f1c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Price, SalesPolicy, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{Storefront, StorefrontCatalogueRead};

function storefrontMeasure(Closure $read): array
{
    Cache::flush(); DB::enableQueryLog(); DB::flushQueryLog();
    try { $result = $read(); $queries = DB::getQueryLog(); }
    finally { DB::disableQueryLog(); }
    foreach ($queries as $query) { expect(str_starts_with(strtolower($query['query']), 'select'), 'Public catalogue only reads the database'); }
    return [$result, count($queries)];
}

/** Cold eligibility checks deliberately bypass the display TTL after privileged/native mutations. */
function storefrontFreshEndings(Storefront $store): array
{
    Cache::flush(); return $store->endings();
}

function storefrontQueryChecks(array $fixture, Storefront $store): void
{
    extract($fixture); $clock=activeFixtureClock($native);
    extract(storefrontCatalogueFixture($fixture, ['qnet', 'qorg', 'qxyz'], range(1, 10)));
    foreach (array_values($catalogueTlds) as $index => $tld) {
        storefrontFixtureRule($native,$tld,(string)(20+10*$index),(string)(21+$index),(string)(22+$index));
        activeFixtureRepublish($prices,$tld); activeFixtureRepublish($native,$tld->id, $now);
    }
    $before = $reads->calls;
    foreach($catalogueTlds as $tld){storefrontFixtureIntent($native,$tld,['longest'=>1]);}
    [$one, $oneQueries] = storefrontMeasure(static fn () => storefrontFreshEndings($store));
    [$oneCheck, $oneCheckQueries] = storefrontMeasure(static fn () => $store->check('example.qnet'));
    foreach($catalogueTlds as $tld){storefrontFixtureIntent($native,$tld,['longest'=>10]);}
    [$ten, $tenQueries] = storefrontMeasure(static fn () => storefrontFreshEndings($store));
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
    same([1 => '$20.00', 2 => '$41.00', 3 => '$62.00', 4 => '$83.00', 5 => '$104.00',
        6 => '$125.00', 7 => '$146.00', 8 => '$167.00', 9 => '$188.00', 10 => '$209.00'], $tenCheck['terms'], 'Ten-term exact price parity');
    echo 'Cold catalogue reads: one term '.$oneQueries.', ten terms '.$tenQueries.'; check one '.$oneCheckQueries.', check ten '.$tenCheckQueries.".\n";
    expect($oneQueries > 0 && $tenQueries <= $oneQueries + 3 && $tenQueries <= 240, 'Cold catalogue query growth is bounded: '.$oneQueries.' / '.$tenQueries);
    expect($oneCheckQueries > 0 && $tenCheckQueries <= $oneCheckQueries + 1 && $tenCheckQueries <= 85, 'Interactive catalogue query growth is bounded: '.$oneCheckQueries.' / '.$tenCheckQueries);
    SalesPolicy::whereIn('tld', ['qorg', 'qxyz'])->update(['register_enabled' => false, 'transfer_enabled' => false]);
    [$single, $singleQueries] = storefrontMeasure(static fn () => storefrontFreshEndings($store));
    same(['com', 'qnet'], array_column($single, 'tld'), 'Single additional ending fixture');
    SalesPolicy::whereIn('tld', ['qorg', 'qxyz'])->update(['register_enabled' => true, 'transfer_enabled' => true]);
    echo 'Cold catalogue ending reads: one additional '.$singleQueries.', three additional '.$tenQueries.".\n";
    expect($tenQueries <= $singleQueries + 120, 'Incremental ending reads stay bounded');
    // Retained native prices must not substitute for missing, future or unsupported current term evidence.
    $termPrice = DB::table('domains_prices')->where('server_id', $server->id)->where('tld', 'qnet')->where('action', 'register')->where('term', 1)->sole();
    $annualPrice=$native->product($catalogueTlds['qnet'],'register')->plans()->where('billing_period',1)->sole()->prices()->sole();
    $nativePrice=$annualPrice->getRawOriginal('price');$facts=$catalogueTlds['qnet']->fresh()->catalogue_facts;
    foreach(['unknown_cost','unknown_retail','held','unit','future','unsynced','term_proof'] as $reason){
        if($reason==='unknown_cost'){storefrontFixtureCost($termPrice,['enabled'=>false,'total'=>null]);}
        if($reason==='unknown_retail'){DB::table('prices')->where('id',$annualPrice->id)->update(['price'=>null]);}
        if($reason==='held'){storefrontFixtureCost($termPrice,['total'=>'30.0000']);}
        if($reason==='unit'){storefrontFixtureCost($termPrice,['source_unit'=>'unsupported']);}
        if($reason==='future'){storefrontFixtureCost($termPrice,['observed_at'=>$now->modify('+1 hour')->format(DATE_ATOM)]);}
        if($reason==='unsynced'){DB::table('domains_prices')->where('id',$termPrice->id)->update(['catalogue_observation'=>null]);}
        if($reason==='term_proof'){$v=json_decode($facts,true);$v['terms']['register']=range(2,10);DB::table('domains_tlds')->where('id',$catalogueTlds['qnet']->id)->update(['catalogue_facts'=>json_encode($v)]);}
        if(!in_array($reason,['unknown_retail','unit'],true)){activeFixtureRepublish($native,$catalogueTlds['qnet']);}
        $before=$reads->calls;$rows=array_column(storefrontFreshEndings($store),null,'tld');
        if($reason==='unit'){expect(!isset($rows['qnet']),'Malformed normalized units hold the whole ending');same('held',$store->check('example.qnet')['status'],'Malformed unit never reaches availability');same($before,$reads->calls,'Malformed normalized unit is refused locally');DB::table('domains_prices')->where('id',$termPrice->id)->update((array)$termPrice);continue;}
        same(range(2,10),$rows['qnet']['terms'],'Current '.$reason.' rejects only the invalid term');
        same(null,$rows['qnet']['register'],'Current '.$reason.' never leaks retained annual native retail');
        same($before,$reads->calls,'Invalid-term catalogue is local only');
        $result=$store->check('example.qnet');same(2,$result['term'],'Check rejects '.$reason.' and chooses a proven term');
        expect(!array_key_exists(1,$result['terms']),'Check has no invalid annual term');
        commerceReject(static fn()=>$store->offer('qnet','register',1));
        DB::table('domains_prices')->where('id',$termPrice->id)->update((array)$termPrice);
        DB::table('prices')->where('id',$annualPrice->id)->update(['price'=>$nativePrice]);
        DB::table('domains_tlds')->where('id',$catalogueTlds['qnet']->id)->update(['catalogue_facts'=>$facts]);activeFixtureRepublish($native,$catalogueTlds['qnet']);
    }
    // Impossible zero native retail is corruption, arranged below native model guards.
    DB::table('prices')->where('id',$annualPrice->id)->update(['price'=>'0.00']);
    same(range(2,10),array_column(storefrontFreshEndings($store),null,'tld')['qnet']['terms'],'Zero native retail never grants an acquisition');
    same(2,$store->check('example.qnet')['term'],'Check keeps only positive proven terms');
    commerceReject(static fn()=>$store->offer('qnet','register',1));
    DB::table('prices')->where('id',$annualPrice->id)->update(['price'=>$nativePrice]);
    $annual = $native->product($catalogueTlds['qnet'], 'register')->plans()->where('billing_period', 1)->sole();
    foreach (['unit' => ['billing_unit' => 'month'], 'type' => ['type' => 'one-time'], 'duplicate' => []] as $reason => $change) {
        if ($reason === 'duplicate') { $duplicate = $annual->replicate(); $duplicate->save(); }
        else { DB::table('plans')->where('id', $annual->id)->update($change); }
        $before = $reads->calls; $rows = array_column(storefrontFreshEndings($store), null, 'tld');
        same(range(2, 10), $rows['qnet']['terms'], 'Invalid native plan '.$reason.' isolates the affected term');
        same($before, $reads->calls, 'Native plan validation does not read providers');
        same(2, $store->check('example.qnet')['term'], 'Check rejects invalid native plan '.$reason);
        commerceReject(static fn () => $store->offer('qnet', 'register', 1));
        if ($reason === 'duplicate') { DB::table('plans')->where('id',$duplicate->id)->delete(); }
        else { DB::table('plans')->where('id', $annual->id)->update($annual->only(array_keys($change))); }
    }
    foreach (['enabled', 'renew_enabled', 'register_enabled', 'transfer_enabled'] as $flag) {
        storefrontFixtureIntent($native,$catalogueTlds['qnet'],['stops'=>$flag==='enabled'?['register','renew','transfer']:[str_replace('_enabled','',$flag)]]);
        $before = $reads->calls; $rows = array_column(storefrontFreshEndings($store), null, 'tld');
        if (in_array($flag, ['enabled', 'renew_enabled'], true)) { expect(!isset($rows['qnet']), 'Disabled '.$flag.' withdraws the ending'); }
        else {
            same($flag === 'register_enabled' ? [] : range(1, 10), $rows['qnet']['terms'], 'TLD action flags isolate registration');
            same($flag === 'transfer_enabled' ? null : '$22.00', $rows['qnet']['transfer'], 'TLD action flags isolate transfer');
        }
        same($before, $reads->calls, 'TLD flags are local catalogue inputs');
        same($flag === 'transfer_enabled' ? 'available' : 'held', $store->check('example.qnet')['status'], 'Check reads current TLD '.$flag);
        storefrontFixtureIntent($native,$catalogueTlds['qnet'],['stops'=>[]]);
    }
    // Native/raw mutations below exercise fresh render eligibility; warm display lag is covered separately.
    $register = $native->product($catalogueTlds['qnet'], 'register');
    DB::table('products')->where('id',$register->id)->update(['stock'=>0]); $rows = array_column(storefrontFreshEndings($store), null, 'tld');
    same([], $rows['qnet']['terms'], 'Native stock invalidates all registration terms');
    same('held', $store->check('example.qnet')['status'], 'Check cannot reuse prior in-stock registration');
    DB::table('products')->where('id',$register->id)->update(['stock'=>null]);
    same(range(1, 10), array_column(storefrontFreshEndings($store), null, 'tld')['qnet']['terms'], 'Restored stock is visible');
    DB::table('domains_tlds')->where('id',$catalogueTlds['qnet']->id)->update(['catalogue_intent'=>json_encode(array_replace(json_decode($catalogueTlds['qnet']->fresh()->catalogue_intent,true),['pricing_rule'=>['mode'=>'fixed','markup'=>'0','round_up'=>'none','fixed'=>['register'=>'25.00','renew'=>'21.00','transfer'=>'22.00']]])), 'catalogue_revision'=>DB::raw('catalogue_revision + 1')]); $before = $reads->calls;
    expect(!isset(array_column(storefrontFreshEndings($store), null, 'tld')['qnet']), 'Unpublished raw rule change invalidates fresh rows');
    same('held', $store->check('example.qnet')['status'], 'Check refuses old rule proof');
    same($before, $reads->calls, 'Rule hold never reads availability');
    storefrontFixtureRule($native,$catalogueTlds['qnet'],'20.00','21.00','22.00');
    $policy = SalesPolicy::where('tld', 'qnet')->firstOrFail();
    $old = $store->offer('qnet', 'register', 1)['selection'];
    activeFixtureSeller($sellers,'qnet', $policy->revision, $server->id, false, true, $now);
    same([], array_column($store->endings(), null, 'tld')['qnet']['terms'], 'Current seller revision and action flags are read');
    activeFixtureSeller($sellers,'qnet', $policy->revision + 1, $server->id, true, true, $now);
    expect($store->offer('qnet', 'register', 1)['selection']->policyRevision > $old->policyRevision, 'Switch-back never reuses old seller revision');
    $freshSelection = (new StorefrontCatalogueRead($sellers, $native, $clock, catalogue:activeFixtureOffers($native)))->offer('qnet', 'register', 1)['selection'];
    same($old->policyRevision + 2, $freshSelection->policyRevision, 'Display selection carries current seller revision');
    same($binding->fingerprint, $freshSelection->fingerprint, 'Display selection carries the selected account');
    foreach (['account_fingerprint' => str_repeat('b', 64), 'registrar_key' => 'namesilo'] as $field => $value) {
        DB::table('domains_sales_policies')->where('tld', 'qnet')->update([$field => $value]); $before = $reads->calls;
        expect(!isset(array_column(storefrontFreshEndings($store), null, 'tld')['qnet']), 'Mismatched seller '.$field.' is rejected');
        same('held', $store->check('example.qnet')['status'], 'Check rejects mismatched seller identity');
        same($before, $reads->calls, 'Mismatched seller never reaches provider');
        DB::table('domains_sales_policies')->where('tld', 'qnet')->update([$field => $field === 'registrar_key' ? 'enom' : $binding->fingerprint]);
    }
    $account = Account::where('server_id', $server->id)->sole(); $before = $reads->calls;
    DB::table('domains_accounts')->where('id', $account->id)->update(['account_fingerprint' => str_repeat('a', 64)]);
    expect(!isset(array_column(storefrontFreshEndings($store), null, 'tld')['qnet']), 'Changed account invalidates request reads');
    same('held', $store->check('example.qnet')['status'], 'Check refuses changed account');
    same($before, $reads->calls, 'Changed account never reaches provider');
    DB::table('domains_accounts')->where('id', $account->id)->update(['account_fingerprint' => $binding->fingerprint]);
    same('$20.00', array_column(storefrontFreshEndings($store), null, 'tld')['qnet']['register'], 'Fresh read recovers restored account');
    $before = $reads->calls;
    DB::table('domains_tlds')->where('server_id',$binding->serverId)->increment('catalogue_revision');
    expect(!isset(array_column(storefrontFreshEndings($store), null, 'tld')['qnet']), 'Changed active offer revision invalidates old publication proof');
    same('held', $store->check('example.qnet')['status'], 'Check independently requires current offer revision');
    same($before, $reads->calls, 'Revoked publication proof has no provider I/O');
    foreach (array_keys($catalogueTlds) as $ending) {
        $policy = SalesPolicy::where('tld', $ending)->firstOrFail();
        activeFixtureSeller($sellers,$ending, $policy->revision, null, false, false, $now);
    }
    Cache::flush();
}
