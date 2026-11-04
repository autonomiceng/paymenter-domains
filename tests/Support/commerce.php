<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/FakeRegistrar.php';
require_once __DIR__.'/offer-generation.php';
require_once __DIR__.'/offer-writers.php';
use App\Models\{Category, Currency, Server};
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Capabilities, CatalogueEntry, CatalogueRate, Disposition, DriverResult, Environment, Evidence, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, CatalogueAcceptances, FoundationSchema, StaffAuditSchema, NativeCatalogue, PriceCatalogue, PricingRules, RegistrarRegistry, SalesPolicySchema, SellerPolicies};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;

function commerceFixture(bool $eligible = false, ?Closure $activation = null, Environment $environment = Environment::Test, string $ending = 'com', bool $active = true): array
{
    if(!app()->bound(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)){app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new \Paymenter\Extensions\Servers\Domains\Tests\FakeClock(new DateTimeImmutable('2026-10-18T12:00:00Z')));}
    if ($active) { (new \Paymenter\Extensions\Servers\Domains\Services\Installation)->install();app()->offsetUnset(\Paymenter\Extensions\Servers\Domains\Services\Runtime::class);app()->offsetUnset(\Paymenter\Extensions\Servers\Domains\Services\RuntimePorts::class); }
    // Active fixtures already ran every schema and verification through the native installer.
    if (!$active) {
        FoundationSchema::install(); SalesPolicySchema::install();
        if (class_exists(\Paymenter\Extensions\Servers\Domains\Services\UserDeletionSchema::class)) { \Paymenter\Extensions\Servers\Domains\Services\UserDeletionSchema::install(); }
        // Historical installed ZIPs predate this schema; current partial fixtures need it explicitly.
        if (class_exists(StaffAuditSchema::class)) { StaffAuditSchema::install(); }
    }
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'format' => '1,000.00', 'prefix' => '$', 'suffix' => '']);
    Category::create(['name' => 'Domains', 'slug' => 'domains', 'description' => 'Domains']);
    $registry = new RegistrarRegistry;
    $reads = (object) ['calls' => 0, 'premium' => false, 'available' => true];
    $verifier = new class implements AccountVerifier {
        public function verify(Environment $env, #[SensitiveParameter] array $credentials): string { return $credentials['token']; }
    };
    foreach (['enom', 'namesilo'] as $key) {
        $registry->register($key, ['token'], $verifier, static function (AccountBinding $b, array $c) use ($eligible, $reads): FakeRegistrar {
            expect(Illuminate\Support\Facades\DB::transactionLevel() === 0, 'No provider reads under locks'); $reads->calls++;
            return new FakeRegistrar($b, new Capabilities($b, []), $eligible
                ? new DriverResult($b, Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged, new Evidence(premium: $reads->premium, available: $reads->available))
                : new DriverResult($b, Disposition::Unknown, SendState::PossiblySent, ResultReason::Unverified));
        },authenticator:$key==='namesilo'?new class implements \Paymenter\Extensions\Servers\Domains\Contracts\CredentialAuthenticator {
            public function authenticate(Environment $environment,array $credentials):void { /* Synthetic connectivity only. */ }
        }:null);
    }
    $bindings = new AccountBindings($registry); $prices = new PriceCatalogue(new PricingRules);
    $sellers = new SellerPolicies($prices, $bindings); $native = new NativeCatalogue($prices);
    $now = new DateTimeImmutable('2026-10-18T12:00:00Z'); $tlds = [];
    if($active){
        $clock=new \Paymenter\Extensions\Servers\Domains\Tests\FakeClock($now);
        $ports=new \Paymenter\Extensions\Servers\Domains\Catalogue\CallerPorts(new \Paymenter\Extensions\Servers\Domains\Catalogue\Projection($native),$clock,$bindings,static fn()=>null,static fn()=>true);
        $caps=new \Paymenter\Extensions\Servers\Domains\Catalogue\Capabilities($ports,$clock,static fn()=>null);
        $prices=new PriceCatalogue(new PricingRules,$caps,$ports);$native=new NativeCatalogue($prices);
        $offers=new \Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers($ports,$bindings,$native);
        $sellers=new SellerPolicies($prices,$bindings,$offers);
    }
    foreach (['enom', 'namesilo'] as $key) {
        $server = Server::create(['name' => 'Synthetic', 'type' => 'server', 'extension' => 'Domains', 'enabled' => true]);
        $review=null;
        if($key==='namesilo'){
            // Use the real local enrollment/review with a synthetic authenticator; no provider routing is installed.
            $role=App\Models\Role::create(['name'=>'Synthetic account reviewer','permissions'=>array_keys(\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::permissions())]);
            $actor=App\Models\User::create(['first_name'=>'Synthetic','last_name'=>'Reviewer','email'=>'fixture-enrollment@example.test','password'=>'synthetic-password','role_id'=>$role->id]);
            $class=\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::class;
            $review=new $class($actor->id,$server->id,null,'configure',$class::ticket($actor->id,$server->id,null,'configure',$class::stamp($server->id,null)),'Synthetic fixture account enrollment');
        }
        $binding = $bindings->configure($server->id, $key, $environment, ['token' => 'account-'.$key],$review);
        // This shared fixture keeps its explicit historical arithmetic; new-account defaults have an upgrade regression.
        App\Models\Setting::updateOrCreate(['key'=>'domains_pricing_default_rule','settingable_type'=>null,'settingable_id'=>null],
            ['type' => 'array', 'encrypted' => false, 'value' => \Paymenter\Extensions\Servers\Domains\Services\PricingRule::DEFAULT]);
        if ($environment === Environment::Live) { $bindings->confirmLiveSite($server->id, rtrim(config('app.url'), '/')); }
        $bindings->setProcessing($server->id, true, $activation ?? syntheticActivation());
        $tld = Tld::create(['server_id' => $server->id, 'registrar_key' => $key, 'account_fingerprint' => $binding->fingerprint,
            'tld' => $ending, 'enabled' => true, 'min_years' => 1, 'max_years' => 3, ...($active?['catalogue_converted'=>true,'registry_fields'=>[]]:[])]);
        $terms = $key === 'enom' ? [1, 3] : [1];
        if($active){
            $q=new \Paymenter\Extensions\Servers\Domains\Catalogue\Query($binding,[$ending],'register');
            $rev=fn()=>$ports->readOffers($q,\Paymenter\Extensions\Servers\Domains\Catalogue\Context::Staff)[0]->revision;
            $costs=[];
            foreach([['renew',1,'8.0000'],['transfer',1,'7.0000'],...array_map(static fn($n)=>['register',$n,'6.0000'],$terms)] as [$a,$n,$cost]){
                $costs[]=new \Paymenter\Extensions\Servers\Domains\Catalogue\Cost($a,$n,true,\Paymenter\Extensions\Servers\Domains\Services\PricingRule::decimal(\Paymenter\Extensions\Servers\Domains\Services\PricingRule::units($cost)*$n,4),\Paymenter\Extensions\Servers\Domains\Data\CostUnit::TermTotal,'synthetic',$now,$now->modify('+1 day'));
            }
            $ports->apply(new \Paymenter\Extensions\Servers\Domains\Catalogue\FeedObserved($binding,$ending,$costs),$rev());
            $ports->apply(new \Paymenter\Extensions\Servers\Domains\Catalogue\FactsObserved($binding,$ending,new \Paymenter\Extensions\Servers\Domains\Catalogue\Facts(['register'=>$terms,'renew'=>[1],'transfer'=>[1]],[],true,false,false,'synthetic',$now,$now->modify('+1 day'))),$rev());
            $ports->apply(\Paymenter\Extensions\Servers\Domains\Catalogue\Edit::override($binding,$ending,new \Paymenter\Extensions\Servers\Domains\Catalogue\Intent(sell:true,longest:max($terms),transfers:true)),$rev());
            $ports->apply(\Paymenter\Extensions\Servers\Domains\Catalogue\Edit::seller($binding,$ending,['register','transfer']),$rev());
            $tlds[]=$tld->fresh();continue;
        }
        throw new LogicException('Final package fixtures require active commerce.');
    }
    if($active){
        $first=AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$tlds[0]->server_id)->sole());
        $q=new \Paymenter\Extensions\Servers\Domains\Catalogue\Query($first,[$ending],'register');
        $ports->apply(\Paymenter\Extensions\Servers\Domains\Catalogue\Edit::seller($first,$ending,['register','transfer']),$ports->readOffers($q,\Paymenter\Extensions\Servers\Domains\Catalogue\Context::Staff)[0]->revision);
        foreach([$prices,$sellers,$native,$bindings] as $owner){activeFixturePorts($owner,$ports,$clock);activeFixtureOffers($owner,$offers);}
        return compact('bindings','prices','sellers','native','now','tlds','reads','clock','ports','caps','offers');
    }
    return compact('bindings', 'prices', 'sellers', 'native', 'now', 'tlds', 'reads');
}
function commerceReject(callable $call): void
{
    try { $call(); expect(false, 'Expected commerce refusal'); }
    catch (DomainException|InvalidArgumentException|LogicException|Illuminate\Database\Eloquent\ModelNotFoundException|Illuminate\Database\MultipleRecordsFoundException $e) { }
}

/** Independent synthetic provider support, with an explicit age for financial fixture preparation. */
function activeCommerceFacts(DateTimeImmutable $now,DateTimeImmutable $until,?\Paymenter\Extensions\Servers\Domains\Catalogue\CallerPorts $ports=null): void
{
    foreach(Tld::where('catalogue_converted',true)->get() as $t){
        $terms=$t->registrar_key==='enom'?[1,3]:[1];
        Illuminate\Support\Facades\DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_facts'=>
            \Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::encodeFacts(new \Paymenter\Extensions\Servers\Domains\Catalogue\Facts(
                ['register'=>$terms,'renew'=>[1],'transfer'=>[1]],[],true,false,false,'synthetic',$now,$until))]);
        if($ports!==null){
            $b=AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$t->server_id)->sole());
            $q=new \Paymenter\Extensions\Servers\Domains\Catalogue\Query($b,[$t->tld],'register');
            $ports->apply(\Paymenter\Extensions\Servers\Domains\Catalogue\Edit::republish($b,$t->tld),$ports->readOffers($q,\Paymenter\Extensions\Servers\Domains\Catalogue\Context::Staff)[0]->revision);
        }
    }
}
