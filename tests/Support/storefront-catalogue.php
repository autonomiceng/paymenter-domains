<?php
// Catalogue scenarios adapted from Enom 9fc07a1/6119f1c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\{Product, Server};
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Data\{CatalogueEntry, CatalogueRate, Environment};
use Paymenter\Extensions\Servers\Domains\Models\{Price, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{CheckoutEligibility, Rdap, Storefront, TransferPrecheck};

/** Change the real active intent, retaining unrelated choices and the existing price rule. */
function storefrontFixtureIntent(object $owner,Tld $t,array $changes): void
{
    $ports=activeFixturePorts($owner);$b=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$t->server_id)->sole());
    $raw=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($t->fresh()->catalogue_intent);
    $v=array_replace($raw,$changes);$intent=new \Paymenter\Extensions\Servers\Domains\Catalogue\Intent(
        sell:$v['sell']??null,longest:$v['longest']??null,transfers:$v['transfers']??null,stops:$v['stops']??[]);
    $q=new \Paymenter\Extensions\Servers\Domains\Catalogue\Query($b,[$t->tld],'register');
    $ports->apply(\Paymenter\Extensions\Servers\Domains\Catalogue\Edit::override($b,$t->tld,$intent,$v['pricing_rule']??null),$ports->readOffers($q,\Paymenter\Extensions\Servers\Domains\Catalogue\Context::Staff)[0]->revision);
}
function storefrontFixtureRule(object $owner,Tld $t,string $register,string $renew,string $transfer): void
{
    storefrontFixtureIntent($owner,$t,['pricing_rule'=>['mode'=>'fixed','markup'=>'0','round_up'=>'none',
        'fixed'=>['register'=>$register,'renew'=>$renew,'transfer'=>$transfer]]]);
}
/** Deliberate persisted corruption at the active observation boundary, never a live provider reply. */
function storefrontFixtureCost(object $row,array $changes): void
{
    $raw=json_decode($row->catalogue_observation,true,32,JSON_THROW_ON_ERROR);
    DB::table('domains_prices')->where('id',$row->id)->update(['catalogue_observation'=>json_encode(array_replace($raw,$changes),JSON_THROW_ON_ERROR)]);
}

/** A separate synthetic account keeps catalogue mutations isolated from other storefront scenarios. */
function storefrontCatalogueFixture(array $fixture, array $endings, array $terms): array
{
    extract($fixture);
    $server = Server::create(['name' => 'Catalogue fixture', 'type' => 'server', 'extension' => 'Domains', 'enabled' => true]);
    $binding = $bindings->configure($server->id, 'enom', Environment::Test, ['token' => 'catalogue-fixture-'.$server->id]);
    $bindings->setProcessing($server->id, true, syntheticActivation());
    $rows = array_fill_keys($endings, ['register' => $terms, 'renew' => [1], 'transfer' => [1], 'registry' => true, 'renewal_safe' => true]);
    $catalogueTlds = [];
    foreach ($endings as $ending) {
        $tld = Tld::create(['server_id' => $server->id, 'registrar_key' => 'enom', 'account_fingerprint' => $binding->fingerprint,
            'tld' => $ending, 'enabled' => true,'catalogue_converted'=>true,'catalogue_intent'=>\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::json(new \Paymenter\Extensions\Servers\Domains\Catalogue\Intent(sell:true,longest:max($terms),transfers:true)), 'min_years' => min($terms), 'max_years' => max($terms),
            'use_default_rule' => false, 'pricing_mode' => 'fixed', 'fixed_register' => '20.00', 'fixed_renew' => '21.00', 'fixed_transfer' => '22.00']);
        $rates = [new CatalogueRate('renew', 1, '8.00', 'synthetic', $now, $now->modify('+1 day')),
            new CatalogueRate('transfer', 1, '7.00', 'synthetic', $now, $now->modify('+1 day'))];
        foreach ($terms as $term) { $rates[] = new CatalogueRate('register', $term, '6.00', 'synthetic', $now, $now->modify('+1 day')); }
        fixtureObserveOfferEntry(new CatalogueEntry($binding, $ending, $rates, $terms, true, true));
        fixtureObserveOfferFacts($binding,fixtureOfferFacts($binding)->endings+[$ending=>$rows[$ending]]);
        activeFixtureRepublish($native,$tld->id, $now); $catalogueTlds[$ending] = $tld->fresh();
        storefrontFixtureRule($native,$tld,'20.00','21.00','22.00');
        $ports=activeFixturePorts($native);$q=new \Paymenter\Extensions\Servers\Domains\Catalogue\Query($binding,[$ending],'register');
        $ports->apply(\Paymenter\Extensions\Servers\Domains\Catalogue\Edit::seller($binding,$ending,['register','transfer']),$ports->readOffers($q,\Paymenter\Extensions\Servers\Domains\Catalogue\Context::Staff)[0]->revision);
    }
    return compact('server', 'binding', 'catalogueTlds');
}

function storefrontCorruptionChecks(array $fixture, Storefront $store): void
{
    extract($fixture);
    extract(storefrontCatalogueFixture($fixture, ['net', 'org', 'test', 'old', 'belowcost', 'unknown'], [1, 3]));
    $broken = $native->product($catalogueTlds['org'], 'register');
    $marker = $broken->settings()->where('key', 'domains_action')->sole();
    $brokenPrices = $broken->plans()->with('prices')->get()->flatMap(static fn ($plan) => $plan->prices)->pluck('id');
    // Valid registration survives an independently corrupt transfer price on the same ending.
    $onlyTransfer = $native->product($catalogueTlds['test'], 'transfer')->plans()->sole()->prices()->sole();
    $transferPrice = $onlyTransfer->getRawOriginal('price');
    DB::table('prices')->where('id', $onlyTransfer->id)->update(['price' => 'malformed']);
    foreach (['old','belowcost','unknown'] as $ending) {
        foreach(DB::table('domains_prices')->where('server_id',$server->id)->where('tld',$ending)->whereIn('action',['register','transfer'])->get() as $row){
            if($ending==='old'){storefrontFixtureCost($row,['observed_at'=>$now->modify('-1 day')->format(DATE_ATOM),'fresh_until'=>$now->format(DATE_ATOM)]);}
            elseif($ending==='belowcost'){storefrontFixtureCost($row,['total'=>number_format(30*$row->term,4,'.','')]);}
            else{DB::table('domains_prices')->where('id',$row->id)->update(['catalogue_observation'=>null]);}
        }
    }
    // A staff seller choice withdraws transfer; native guards remain enabled.
    activeFixtureSeller($sellers,'org',(int)\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld','org')->value('revision'),$server->id,true,false,$now);
    foreach (['price', 'setup_fee', 'marker', 'duplicate_product', 'wrong_type_marker'] as $corruption) {
        if ($corruption === 'price') { DB::table('prices')->whereIn('id', $brokenPrices)->update(['price' => 'malformed']); }
        if ($corruption === 'setup_fee') { DB::table('prices')->whereIn('id', $brokenPrices)->update(['setup_fee' => null]); }
        if ($corruption === 'marker') { DB::table('settings')->where('id',$marker->id)->update(['value'=>'invalid']); }
        if ($corruption === 'duplicate_product') {
            $duplicateProduct = $broken->replicate(); $duplicateProduct->save();
            foreach ($broken->settings()->get() as $setting) { $duplicateProduct->settings()->create($setting->only(['key', 'value', 'type', 'encrypted'])); }
        }
        if ($corruption === 'wrong_type_marker') {
            $duplicateProduct = $broken->replicate(); $duplicateProduct->slug = 'stray-domain-product'; $duplicateProduct->save();
            $duplicateProduct->settings()->create(['key' => 'domains_tld_id', 'value' => (string) $catalogueTlds['org']->id, 'type' => 'integer', 'encrypted' => false]);
        }
        Cache::flush(); $before = $reads->calls; $public = array_column($store->endings(), null, 'tld');
        same(['com', 'net', 'test'], array_keys($public), 'Corrupt '.$corruption.' isolates broken, stale, below-cost and unknown offers: '.json_encode(array_keys($public)));
        same(['tld' => 'net', 'price' => '$20.00', 'register' => '$20.00', 'transfer' => '$22.00', 'transfersOn' => true, 'renew' => '$21.00', 'terms' => [1, 3], 'testEnvironment' => true],
            $public['net'], 'Corrupt '.$corruption.' preserves exact healthy row');
        expect(!isset($public['old']),'Stale normalized cost cannot retain a public offer');
        same(null, $public['test']['transfer'], 'Corrupt transfer never leaks a price');
        same([1, 3], $public['test']['terms'], 'Registration-only ending retains exact terms');
        same($before, $reads->calls, 'Catalogue isolation performs no provider I/O');
        foreach (['net', 'test'] as $ending) {
            $result = $store->check('example.'.$ending);
            same('available', $result['status'], 'Corrupt '.$corruption.' preserves healthy check for '.$ending);
            same([1 => '$20.00', 3 => '$60.00'], $result['terms'], 'Healthy check retains exact prices');
            same('$21.00', $result['renew'], 'Healthy check retains annual renewal');
        }
        $before = $reads->calls;
        foreach (['org', 'old', 'belowcost', 'unknown'] as $ending) {
            same('held', $store->check('example.'.$ending)['status'], 'Corrupt '.$corruption.' never revives '.$ending);
        }
        same($before, $reads->calls, 'Unorderable endings stop before availability');
        DB::table('prices')->whereIn('id', $brokenPrices)->update(['price' => DB::raw('20 * (select billing_period from plans where plans.id = prices.plan_id)'), 'setup_fee' => '0.00']);
        DB::table('settings')->where('id',$marker->id)->update(['value'=>'register']);
        if (in_array($corruption, ['duplicate_product', 'wrong_type_marker'], true)) { DB::table('settings')->where('settingable_type',$duplicateProduct->getMorphClass())->where('settingable_id',$duplicateProduct->id)->delete(); DB::table('products')->where('id',$duplicateProduct->id)->delete(); }
        Cache::flush(); $repaired = array_column($store->endings(), null, 'tld');
        same([1, 3], $repaired['org']['terms'], 'Repaired '.$corruption.' is visible in a fresh projection');
        same('available', $store->check('example.org')['status'], 'Repaired '.$corruption.' checks normally');
    }
    DB::table('prices')->where('id', $onlyTransfer->id)->update(['price' => $transferPrice]);
    Cache::flush(); $repaired = array_column($store->endings(), null, 'tld');
    same('$22.00', $repaired['test']['transfer'], 'Restored test transfer price is visible');
    foreach (array_keys($catalogueTlds) as $ending) { activeFixtureSeller($sellers,$ending,(int)\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld',$ending)->value('revision'), null, false, false, $now); }
    Cache::flush();
}
