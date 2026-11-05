<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native encrypted bindings, admission SQL and the real feed scope cannot be covered by a unit stub. No wire sends.
require __DIR__.'/native-app.php';
require_once __DIR__.'/Support/FakeRegistrar.php';
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CredentialFeed,OfferPorts,Projection,Writer};
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier,Clock,CredentialAuthenticator};
use Paymenter\Extensions\Servers\Domains\Data\{Environment,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Registrars\{Enom\EnomLiveReservationPolicy,NameSilo\NameSiloLiveReservationPolicy};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,AccountReadBudget,Installation,LiveReservationLimit,LiveReservations,LiveReservationSchema,LocalAccountEnrollment,NativeCatalogue,PriceCatalogue,PricingRules,RegistrarRegistry};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
(new Installation)->install();
$clock=new FakeClock(new DateTimeImmutable('2026-11-04T12:00:00Z'));app()->instance(Clock::class,$clock);
$registry=new RegistrarRegistry;
$registry->register('enom',['uid','token'],new class implements AccountVerifier {
    public function verify(Environment $environment,array $credentials):string {return $credentials['uid'];}
},static fn()=>throw new LogicException('No provider reader.'));
$registry->register('namesilo',['api_key'],new class implements AccountVerifier {
    public function verify(Environment $environment,array $credentials):string {return 'synthetic-live-namesilo';}
},static fn()=>throw new LogicException('No provider reader.'),authenticator:new class implements CredentialAuthenticator {
    public function authenticate(Environment $environment,array $credentials):void {throw new LogicException('No provider authentication.');}
});
$bindings=new AccountBindings($registry);$ports=new OfferPorts($bindings,$clock);
config(['app.url'=>'https://billing.example.com','domains.enom_identity.environment'=>'live',
    'domains.enom_live.site'=>'https://billing.example.com','domains.enom_live.catalogue'=>true,
    'domains.namesilo_live.site'=>'https://billing.example.com','domains.namesilo_live.catalogue'=>true]);
foreach(['enom','namesilo'] as $provider){
    $identity=$provider==='namesilo'?LocalAccountEnrollment::PREFIX.'synthetic-live-namesilo':'synthetic-live-enom';
    $server=Server::create(['name'=>'Synthetic Live catalogue','extension'=>'Domains','type'=>'server','enabled'=>true]);
    if($provider==='enom'){
        $binding=$bindings->configure($server->id,$provider,Environment::Live,['uid'=>$identity,'token'=>'synthetic-token']);
    }else{
        // Production NameSilo offers require encrypted local enrollment and its persisted revision, not a verifier-only account.
        $account=Account::withVerifiedBindingWrite(fn()=>Account::create(['server_id'=>$server->id,'registrar_key'=>'namesilo','environment'=>'live','stable_identity'=>$identity]));
        $binding=AccountBindings::binding($account);$credentials=['api_key'=>'synthetic-key'];
        foreach(['domains_credentials'=>json_encode($credentials,JSON_THROW_ON_ERROR),
            'domains_verified_credentials'=>hash_hmac('sha256',$binding->fingerprint.json_encode($credentials,JSON_THROW_ON_ERROR),(string)config('app.key')),
            LocalAccountEnrollment::KEY=>json_encode(LocalAccountEnrollment::facts($binding,$identity,$credentials),JSON_THROW_ON_ERROR)] as $name=>$value){
            $setting=$server->settings()->firstOrNew(['key'=>$name]);$setting->encrypted=true;$setting->type='string';$setting->value=$value;$setting->save();
        }
    }
    Account::withVerifiedBindingWrite(fn()=>Account::where('server_id',$server->id)->sole()->update(['live_site'=>'https://billing.example.com']));
    $setting='domains.'.$provider.'_live.accounts.'.$identity;
    config([$setting=>['fingerprint'=>$binding->fingerprint,'daily_call_cap'=>1000,'daily_spend_limit_usd'=>'1.00','currency'=>'USD','balance_funding'=>true]]);
    $key=hash('sha256',$provider==='enom'?$identity:$provider."\0live\0".$identity);
    $ledger=new LiveReservations($provider==='enom'?new EnomLiveReservationPolicy:new NameSiloLiveReservationPolicy);
    // Old rows do not carry a purpose. All their usage must remain other work, even after upgrade.
    DB::table(LiveReservationSchema::TABLE)->insert(['account'=>$key,'day'=>LiveReservations::day(),'used'=>909,'spend_micros'=>123]);
    same(25,$ports->allowance($binding,RequestOrigin::Background),$provider.' price reads still go after other work used most of the day');
    $scope=(new ReflectionProperty(CredentialFeed::class,'scope'))->getValue($ports->feed($binding));
    $send=static function(int $count,bool $catalogue)use($scope,$binding,$ledger):int {
        for($sent=0;$sent<$count;$sent++){
            try{$catalogue?$scope(fn()=>$ledger->reserve($binding)):$ledger->reserve($binding);}catch(LiveReservationLimit){return $sent;}
            same(0,DB::transactionLevel(),'A physical send is outside database locks');
        }
        return $sent;
    };
    same(1,$send(1,true),$provider.' price reads go at 909/1000');
    same(['used'=>910,'spend_micros'=>123],$ledger->usage($binding),'Every call counts once and retained spend is unchanged');
    same(25,$ports->allowance($binding,RequestOrigin::Interactive),'Manual sync shares the same price-read allowance');
    $reset=static function(int $used,int $catalogue=0)use($setting,$key):void {
        config([$setting.'.daily_call_cap'=>100]);
        DB::table(LiveReservationSchema::TABLE)->where('account',$key)->update(['used'=>$used,'catalogue_used'=>$catalogue]);
    };
    // Seed durable prior usage and exercise the boundary calls, rather than replaying a day's redundant admissions.
    $reset(79,29);
    same(1,$ports->allowance($binding,RequestOrigin::Interactive),'Only the thirtieth price read remains available');
    same(1,$send(2,true),$provider.' price reads stop at thirty price reads out of the hundred-call cap');
    same(0,$ports->allowance($binding,RequestOrigin::Interactive),'Exhausted price-read share closes advisory allowance');
    same(1,$send(1,false),'Customer work continues after the price-read share is exhausted');
    $reset(99,30);
    same(1,$send(2,false),'Customer work can use the last call under the full daily cap');
    same(100,$ledger->usage($binding)['used'],'Refused calls consume no reservation');
    $reset(95);
    same(5,$ports->allowance($binding,RequestOrigin::Background),'Price-read allowance also stops at the full daily cap');
    same(5,$send(6,true),'Price reads stop at the daily cap below their own share');
    same([0,0],[$send(1,false),$ports->allowance($binding,RequestOrigin::Interactive)],'Every call stops at the daily cap');
    $reset(0);
    $scope(static function()use($scope,$ledger,$binding):void {
        $scope(fn()=>$ledger->reserve($binding));$ledger->reserve($binding);
    });
    same(2,(int)DB::table(LiveReservationSchema::TABLE)->where('account',$key)->value('catalogue_used'),'Nested catalogue scopes preserve their marker');
    try{$scope(static fn()=>throw new RuntimeException('Synthetic scope exit'));}catch(RuntimeException){}
    $ledger->reserve($binding);
    same(2,(int)DB::table(LiveReservationSchema::TABLE)->where('account',$key)->value('catalogue_used'),'Scope exit restores ordinary customer work');
    // Production worker retains its 25-read minute ceiling for either origin; no provider attempt is allowed.
    $minute=new AccountReadBudget($clock);$lease=$minute->reserve($binding,RequestOrigin::Interactive,25);$minute->release($binding,RequestOrigin::Interactive,$lease);
    $attempts=0;config(['domains.provider_send_guard'=>static function()use(&$attempts):never{$attempts++;throw new RuntimeException('No send.');}]);
    $writer=new Writer(new Projection(new NativeCatalogue(new PriceCatalogue(new PricingRules))),$clock,static function():void{},static fn()=>false);
    foreach([RequestOrigin::Background,RequestOrigin::Interactive] as $origin){
        $worker=$ports->worker($writer);$worker->sync($binding,$origin);$worker->tick(1);
        same(null,$worker->status($binding)['tried_at'],'Minute exhaustion defers '.$provider.' catalogue '.$origin->value.' before a feed attempt');
    }
    same(0,$attempts,'Minute exhaustion never reaches a physical send');config(['domains.provider_send_guard'=>null]);
    same(25,(int)DB::table('domains_read_budgets')->where('server_id',$server->id)->value('used'),'Minute reservations stay at the existing catalogue ceiling');
}
echo "Live catalogue share: price-only share, total cap, old rows, nested scopes and minute guard checked.\n";
$nativeSuccessful=true;
