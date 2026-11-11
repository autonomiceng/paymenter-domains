<?php
// Catalogue scenarios adapted from Enom 9fc07a1/6119f1c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\{Product, Server};
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Data\{CatalogueEntry, CatalogueRate, Environment};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,Edit,Facts,FactsObserved,Intent,OfferState,Query};
use Paymenter\Extensions\Servers\Domains\Services\PricingRule;
use Paymenter\Extensions\Servers\Domains\Models\{Price, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{CheckoutEligibility, Rdap, Storefront, TransferPrecheck};

/** Arrange the active typed command; retired TLD pricing columns confer no authority. */
function storefrontFixtureEdit(Tld $tld, ?array $intent = null, ?array $rule = null): void
{
    $ports=activeFixturePorts($GLOBALS['bindings']);
    $b=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$tld->server_id)->sole());
    $saved=OfferState::decode($tld->fresh()->catalogue_intent);$raw=array_intersect_key($saved,get_object_vars(new Intent));$rule??=$saved['pricing_rule']??null;
    $q=new Query($b,[$tld->tld],'register');
    $ports->apply(Edit::override($b,$tld->tld,new Intent(...array_replace($raw,$intent??[])),$rule),$ports->readOffers($q,Context::Staff)[0]->revision);
}
function storefrontFixtureRule(string $register,string $renew,string $transfer): array
{
    return PricingRule::validate(['mode'=>'fixed','markup'=>'0','round_up'=>'none','fixed'=>compact('register','renew','transfer')]);
}
/** Raw corruption targets the current normalized observation, leaving retained native retail in place. */
function storefrontCorruptCost(int $server,string $ending,array $actions,array $changes): void
{
    foreach(Price::where('server_id',$server)->where('tld',$ending)->whereIn('action',$actions)->get() as $price){
        $cost=OfferState::decode(costEvidence($price));
        DB::table('domains_prices')->where('id',$price->id)->update([...costColumns(OfferState::json(array_replace($cost,$changes)))]);
    }
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
            'tld' => $ending, 'enabled' => true,'catalogue_converted'=>true]);
        $rates = [new CatalogueRate('renew', 1, '8.00', 'synthetic', $now, $now->modify('+1 day')),
            new CatalogueRate('transfer', 1, '7.00', 'synthetic', $now, $now->modify('+1 day'))];
        foreach ($terms as $term) { $rates[] = new CatalogueRate('register', $term, '6.00', 'synthetic', $now, $now->modify('+1 day')); }
        fixtureObserveOfferEntry(new CatalogueEntry($binding, $ending, $rates, $terms, true, true));
        $ports=activeFixturePorts($sellers);$q=new Query($binding,[$ending],'register');
        $ports->apply(new FactsObserved($binding,$ending,new Facts(['register'=>$terms,'renew'=>[1],'transfer'=>[1]],[],true,false,false,'synthetic',$now,$now->modify('+1 day'))),$ports->readOffers($q,Context::Staff)[0]->revision);
        storefrontFixtureEdit($tld,['sell'=>true,'longest'=>max($terms),'transfers'=>true],storefrontFixtureRule('20.00','21.00','22.00'));
        $ports->apply(Edit::seller($binding,$ending,['register','transfer']),$ports->readOffers($q,Context::Staff)[0]->revision);
        $catalogueTlds[$ending]=$tld->fresh();
    }
    return compact('server', 'binding', 'catalogueTlds');
}

function storefrontCorruptionChecks(array $fixture, Storefront $store): void
{
    extract($fixture);
    extract(storefrontCatalogueFixture($fixture, ['net', 'org', 'test', 'old', 'belowcost', 'unknown'], [1, 3]));
    $broken = $native->product($catalogueTlds['org'], 'register');
    $marker = $broken->settings()->where('key', 'domains_action')->sole();$markerRaw=$marker->getRawOriginal('value');
    $brokenPrices = $broken->plans()->with('prices')->get()->flatMap(static fn ($plan) => $plan->prices)->pluck('id');
    // Valid registration survives an independently corrupt transfer price on the same ending.
    $onlyTransfer = $native->product($catalogueTlds['test'], 'transfer')->plans()->sole()->prices()->sole();
    $transferPrice = $onlyTransfer->getRawOriginal('price');
    DB::table('prices')->where('id', $onlyTransfer->id)->update(['price' => 'malformed']);
    foreach (['old'=>['observed_at'=>$now->modify('-1 day')->format(DATE_ATOM),'fresh_until'=>$now->format(DATE_ATOM)],'belowcost'=>['total'=>'999.0000'],'unknown'=>['enabled'=>false,'total'=>null]] as $ending=>$changes){
        storefrontCorruptCost($server->id,$ending,['register','transfer'],$changes);
    }
    $retained=activeFixturePorts($sellers)->readOffers(new Query($binding,['old'],'renew'),Context::Holder)[0];
    same('21.00',$retained->retail,'Stale acquisition evidence preserves the contractual annual holder retail');
    // Withdraw the other action so each corrupt registration must remove its ending entirely.
    DB::table('products')->where('id',$native->product($catalogueTlds['org'],'transfer')->id)->update(['stock'=>0]);
    foreach (['price', 'setup_fee', 'marker', 'duplicate_product', 'wrong_type_marker'] as $corruption) {
        if ($corruption === 'price') { DB::table('prices')->whereIn('id', $brokenPrices)->update(['price' => 'malformed']); }
        if ($corruption === 'setup_fee') { DB::table('prices')->whereIn('id', $brokenPrices)->update(['setup_fee' => null]); }
        if ($corruption === 'marker') { DB::table($marker->getTable())->where('id',$marker->id)->update(['value'=>'invalid']); }
        if (in_array($corruption,['duplicate_product','wrong_type_marker'],true)) {
            // Arrange retained corrupt rows only; current identity model writes deliberately refuse them.
            $duplicateProduct=Product::withoutEvents(static function()use($broken,$corruption,$catalogueTlds):Product {
                $copy=$broken->replicate();if($corruption==='wrong_type_marker'){$copy->slug='stray-domain-product';}$copy->save();
                if($corruption==='duplicate_product'){
                    foreach($broken->settings()->get() as $setting){$copy->settings()->create($setting->only(['key','value','type','encrypted']));}
                }else{$copy->settings()->create(['key'=>'domains_tld_id','value'=>(string)$catalogueTlds['org']->id,'type'=>'integer','encrypted'=>false]);}
                return $copy;
            });
        }
        Cache::flush(); $before = $reads->calls; $public = array_column($store->endings(), null, 'tld');
        same(['com', 'net', 'test'], array_keys($public), 'Corrupt '.$corruption.' isolates broken, stale, below-cost and unknown acquisition offers');
        same(['tld' => 'net', 'price' => '$20.00', 'register' => '$20.00', 'transfer' => '$22.00', 'transfersOn' => true, 'renew' => '$21.00', 'terms' => [1, 3], 'testEnvironment' => true],
            $public['net'], 'Corrupt '.$corruption.' preserves exact healthy row');
        expect(!isset($public['old']),'Stale cost cannot publish a new acquisition');
        same(null, $public['test']['transfer'], 'Corrupt transfer never leaks a price');
        same([1, 3], $public['test']['terms'], 'Registration-only ending retains exact terms');
        same($before, $reads->calls, 'Catalogue isolation performs no provider I/O');
        foreach (['net', 'test'] as $ending) {
            $result = $store->check('example.'.$ending);
            same('available', $result['status'], 'Corrupt '.$corruption.' preserves healthy check for '.$ending);
            // Three years are the first registration year plus two renewal years: $20 + 2 x $21.
            same([1 => '$20.00', 3 => '$62.00'], $result['terms'], 'Healthy check retains exact prices');
            same('$21.00', $result['renew'], 'Healthy check retains annual renewal');
        }
        $before = $reads->calls;
        foreach (['org', 'old', 'belowcost', 'unknown'] as $ending) {
            same('held', $store->check('example.'.$ending)['status'], 'Corrupt '.$corruption.' never revives '.$ending);
        }
        same($before, $reads->calls, 'Unorderable endings stop before availability');
        // Repair to the term total: the first registration year plus renewal years at 21.00.
        DB::table('prices')->whereIn('id', $brokenPrices)->update(['price' => DB::raw('20 + 21 * ((select billing_period from plans where plans.id = prices.plan_id) - 1)'), 'setup_fee' => '0.00']);
        DB::table($marker->getTable())->where('id',$marker->id)->update(['value'=>$markerRaw]);
        if (in_array($corruption, ['duplicate_product', 'wrong_type_marker'], true)) { Product::withoutEvents(static function()use($duplicateProduct):void {$duplicateProduct->settings()->delete();$duplicateProduct->delete();}); }
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
