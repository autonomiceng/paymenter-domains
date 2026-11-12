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
    extract($fixture);
    extract(storefrontCatalogueFixture($fixture, ['qnet', 'qorg', 'qxyz'], range(1, 10)));
    foreach (array_values($catalogueTlds) as $index => $tld) {
        storefrontFixtureEdit($tld,rule:storefrontFixtureRule((string)(20+10*$index),(string)(21+$index),(string)(22+$index)));
    }
    $before = $reads->calls;
    foreach($catalogueTlds as $tld){storefrontFixtureEdit($tld,['longest'=>1]);}
    [$one, $oneQueries] = storefrontMeasure(static fn () => storefrontFreshEndings($store));
    [$oneCheck, $oneCheckQueries] = storefrontMeasure(static fn () => $store->check('example.qnet'));
    foreach($catalogueTlds as $tld){storefrontFixtureEdit($tld,['longest'=>10]);}
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
    // Each term total is the first registration year plus renewal years at $21.00.
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
    $termPrice = \Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::query('domains_prices')->where('server_id', $server->id)->where('tld', 'qnet')->where('action', 'register')->where('term', 1)->sole();
    $cost=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($termPrice->catalogue_observation);
    $tldFacts=$catalogueTlds['qnet']->fresh()->catalogue_facts;
    $facts=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($tldFacts);$facts['terms']['register']=range(2,10);
    foreach (['unknown_cost'=>['enabled'=>false,'total'=>null],'unknown_retail'=>null,'held'=>['total'=>'999.0000'],
        'unit'=>['source_unit'=>'unsupported'],'future'=>['observed_at'=>activeFixtureClock($bindings)->now()->modify('+1 second')->format(DATE_ATOM)],
        'unsynced'=>null,'term_proof'=>null] as $reason=>$change) {
        $change=match($reason){
            'unknown_retail'=>['retail'=>null], 'unsynced'=>['catalogue_observation'=>null],
            'term_proof'=>[],default=>['catalogue_observation'=>json_encode(array_replace($cost,$change))],
        };
        DB::table('domains_prices')->where('id',$termPrice->id)->update($change?:['catalogue_observation'=>$termPrice->catalogue_observation]);
        if($reason==='term_proof'){DB::table('domains_tlds')->where('id',$catalogueTlds['qnet']->id)->update(['catalogue_facts'=>json_encode($facts)]);}
        $before=$reads->calls;$rows=array_column(storefrontFreshEndings($store),null,'tld');
        if($reason==='unit'){
            expect(!isset($rows['qnet']),'Malformed normalized unit closes the ending instead of interpreting retained costs');
            same('held',$store->check('example.qnet')['status'],'Check rejects malformed normalized unit');
        }elseif(in_array($reason,['unknown_cost','unknown_retail','held','future','unsynced'],true)){
            // Longer registrations use the first-year cost too; missing evidence or a loss closes every term.
            same([],$rows['qnet']['terms'],'Current '.$reason.' closes every registration term priced from the first year');
            same(null,$rows['qnet']['register'],'Current '.$reason.' never leaks retained native annual price');
            same($before,$reads->calls,'Invalid-term catalogue is local only');
            $result=$store->check('example.qnet');expect(($result['terms']??[])===[]&&!isset($result['term']),'Check offers no registration term without first-year evidence');
        }else{
            same(range(2,10),$rows['qnet']['terms'],'Current '.$reason.' rejects only the invalid term');
            same(null,$rows['qnet']['register'],'Current '.$reason.' never leaks retained native annual price');
            same($before,$reads->calls,'Invalid-term catalogue is local only');
            $result=$store->check('example.qnet');same(2,$result['term'],'Check rejects '.$reason.' and chooses a proven term');
            expect(!array_key_exists(1,$result['terms']),'Check has no invalid annual term');
        }
        if($reason==='unit'){
            try{$store->offer('qnet','register',1);expect(false,'Malformed source unit cannot authorize an acquisition');}
            catch(ValueError $e){expect(str_contains($e->getMessage(),'CostUnit'),'Typed decoder refuses the unsupported cost unit');}
        }else{commerceReject(static fn()=>$store->offer('qnet','register',1));}
        DB::table('domains_prices')->where('id',$termPrice->id)->update((array)$termPrice);
        DB::table('domains_tlds')->where('id',$catalogueTlds['qnet']->id)->update(['catalogue_facts'=>$tldFacts]);
    }
    $annualPrice = $native->product($catalogueTlds['qnet'], 'register')->plans()->where('billing_period', 1)->sole()->prices()->sole();
    DB::table('domains_prices')->where('id', $termPrice->id)->update(['retail' => '0.00']);
    DB::table('prices')->where('id', $annualPrice->id)->update(['price' => '0.00']);
    // Zero first-year retail is never an offer, and the longer terms priced from that year close with it.
    same([], array_column(storefrontFreshEndings($store), null, 'tld')['qnet']['terms'], 'Matching zero retail is never an acquisition offer');
    $zeroCheck = $store->check('example.qnet'); expect(($zeroCheck['terms'] ?? []) === [] && !isset($zeroCheck['term']), 'Check refuses zero retail and the terms priced from it');
    commerceReject(static fn () => $store->offer('qnet', 'register', 1));
    DB::table('domains_prices')->where('id', $termPrice->id)->update((array) $termPrice);
    DB::table('prices')->where('id', $annualPrice->id)->update(['price' => '20.00']);
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
        activeFixtureStops($sellers,$catalogueTlds['qnet'],$flag==='enabled'?['register','renew','transfer']: [str_replace('_enabled','',$flag)]);
        $before = $reads->calls; $rows = array_column(storefrontFreshEndings($store), null, 'tld');
        if (in_array($flag, ['enabled', 'renew_enabled'], true)) { expect(!isset($rows['qnet']), 'Disabled '.$flag.' withdraws the ending'); }
        else {
            same($flag === 'register_enabled' ? [] : range(1, 10), $rows['qnet']['terms'], 'TLD action flags isolate registration');
            same($flag === 'transfer_enabled' ? null : '$22.00', $rows['qnet']['transfer'], 'TLD action flags isolate transfer');
        }
        same($before, $reads->calls, 'TLD flags are local catalogue inputs');
        $checked=$store->check('example.qnet');
        if($flag==='register_enabled'){
            expect(!array_key_exists('terms',$checked),'Check offers no disabled registration term, even when transfer remains selected');
            commerceReject(static fn()=>$store->offer('qnet','register',1));
        }else{same($flag === 'transfer_enabled' ? 'available' : 'held',$checked['status'],'Check reads current TLD '.$flag);}
        activeFixtureStops($sellers,$catalogueTlds['qnet'],[]);
    }
    // Native/raw mutations below exercise fresh render eligibility; warm display lag is covered separately.
    $register = $native->product($catalogueTlds['qnet'], 'register');
    DB::table('products')->where('id',$register->id)->update(['stock'=>0]); $rows = array_column(storefrontFreshEndings($store), null, 'tld');
    same([], $rows['qnet']['terms'], 'Native stock invalidates all registration terms');
    expect(!array_key_exists('terms',$store->check('example.qnet')),'Check cannot reuse prior in-stock registration terms');
    commerceReject(static fn()=>$store->offer('qnet','register',1));
    DB::table('products')->where('id',$register->id)->update(['stock'=>null]);
    same(range(1, 10), array_column(storefrontFreshEndings($store), null, 'tld')['qnet']['terms'], 'Restored stock is visible');
    $rawIntent=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($catalogueTlds['qnet']->fresh()->catalogue_intent);
    $changedIntent=[...$rawIntent,'pricing_rule'=>storefrontFixtureRule('25.00','21.00','22.00')];
    $catalogueTlds['qnet']->fresh()->update(['catalogue_intent'=>json_encode($changedIntent),'catalogue_revision'=>(int)$catalogueTlds['qnet']->fresh()->catalogue_revision+1]);$before=$reads->calls;
    expect(!isset(array_column($store->endings(), null, 'tld')['qnet']), 'Unpublished rule change invalidates cold or warm rows');
    same('held', $store->check('example.qnet')['status'], 'Check refuses old rule proof');
    same($before, $reads->calls, 'Rule hold never reads availability');
    DB::table('domains_tlds')->where('id',$catalogueTlds['qnet']->id)->update(['catalogue_intent'=>json_encode($rawIntent)]);
    activeFixtureRepublish($native,$catalogueTlds['qnet']);
    $policy = SalesPolicy::where('tld', 'qnet')->firstOrFail();
    $old = $store->offer('qnet', 'register', 1)['selection'];
    activeFixtureSeller($sellers,'qnet', $policy->revision, $server->id, false, true, $now);
    same([], array_column($store->endings(), null, 'tld')['qnet']['terms'], 'Current seller revision and action flags are read');
    activeFixtureSeller($sellers,'qnet', $policy->revision + 1, $server->id, true, true, $now);
    expect($store->offer('qnet', 'register', 1)['selection']->policyRevision > $old->policyRevision, 'Switch-back never reuses old seller revision');
    $freshSelection = (new StorefrontCatalogueRead($sellers, $native, new Paymenter\Extensions\Servers\Domains\Tests\FakeClock($now),catalogue:activeFixtureOffers($sellers)))->offer('qnet', 'register', 1)['selection'];
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
    expect(!isset(array_column(storefrontFreshEndings($store), null, 'tld')['qnet']), 'Changed active offer revision invalidates cold publication proof');
    same('held', $store->check('example.qnet')['status'], 'Check independently requires current offer revision');
    same($before, $reads->calls, 'Revoked publication proof has no provider I/O');
    foreach (array_keys($catalogueTlds) as $ending) {
        $policy = SalesPolicy::where('tld', $ending)->firstOrFail();
        activeFixtureSeller($sellers,$ending, $policy->revision, null, false, false, $now);
    }
    Cache::flush();
}
