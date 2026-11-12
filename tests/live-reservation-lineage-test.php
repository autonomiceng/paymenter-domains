<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Real reviewed rotation, encrypted bindings and SQL counters must agree. Authentication is synthetic.
require __DIR__.'/Support/binding-local.php';

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{Environment,Money};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Registrars\{Enom\EnomLiveReservationPolicy,NameSilo\NameSiloLiveReservationPolicy};
use Paymenter\Extensions\Servers\Domains\Services\{LiveReservationLimit,LiveReservations,LiveReservationSchema};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;

$clock=new FakeClock(new DateTimeImmutable('2026-11-11T12:00:00Z'));app()->instance(Clock::class,$clock);
config(['domains.enom_identity.environment'=>'live','domains.enom_live.site'=>'https://live.example.com',
    'domains.namesilo_live.site'=>'https://live.example.com']);
$refuses=static function(callable $work):void {
    try{$work();throw new LogicException('Expected exhausted reservation refusal.');}catch(LiveReservationLimit){}
};
foreach(['enom','namesilo'] as $provider){
    $server=Server::create(['name'=>'Synthetic rotation account','type'=>'server','extension'=>'Domains','enabled'=>true]);
    $service=$provider==='enom'?$bindings:$local;
    $configure=static function(string $key)use($provider,$service,$server,$review) {
        $credentials=$provider==='enom'?['uid'=>'account-a','token'=>$key]:['api_key'=>$key];
        return $service->configure($server->id,$provider,Environment::Live,$credentials,$provider==='namesilo'?$review($server->id):null);
    };
    $binding=$configure($provider==='enom'?'A1':'placeholder-lineage-key-one');
    $service->confirmLiveSite($server->id,'https://live.example.com');
    $account=Account::where('server_id',$server->id)->sole();$identity=$account->stable_identity;
    config(['domains.'.$provider.'_live.accounts'=>[$identity=>['fingerprint'=>$binding->fingerprint,
        'daily_call_cap'=>3,'daily_spend_limit_usd'=>'1.00','currency'=>'USD','balance_funding'=>true]]]);
    $policy=$provider==='enom'?new EnomLiveReservationPolicy:new NameSiloLiveReservationPolicy;
    $ledger=new LiveReservations($policy);
    $ledger->reserve($binding,new Money('0.75','USD'));
    $revision=$provider==='namesilo'?$service->credentialRevision($binding):null;
    $rotated=$configure($provider==='enom'?'A2':'placeholder-lineage-key-two');
    same($binding->fingerprint,$rotated->fingerprint,$provider.' rotation retains the immutable binding');
    same($identity,$account->fresh()->stable_identity,$provider.' rotation retains local account identity');
    if($provider==='namesilo'){
        expect($revision!==$service->credentialRevision($rotated),'NameSilo rotation changes the credential revision');
    }
    $ledger=new LiveReservations($policy);
    same(['used'=>1,'spend_micros'=>750000],$ledger->usage($rotated),$provider.' reconstructed ledger retains pre-rotation usage');
    $refuses(fn()=>$ledger->reserve($rotated,new Money('0.250001','USD')));
    $ledger->reserve($rotated,new Money('0.25','USD'));
    $refuses(fn()=>$ledger->reserve($rotated,new Money('0.000001','USD')));
    $ledger->reserve($rotated);
    $replaced=$configure($provider==='enom'?'A2':'placeholder-lineage-key-two');
    $ledger=new LiveReservations($policy);
    $refuses(fn()=>$ledger->reserve($replaced));
    same(['used'=>3,'spend_micros'=>1000000],$ledger->usage($replaced),$provider.' same-key replacement restores neither call nor spend capacity');
}
same(2,DB::table(LiveReservationSchema::TABLE)->count(),'Both providers keep one daily row through repeated rotation');
echo "Live reservations retain call and spend usage through Enom and NameSilo credential rotation.\n";
$nativeSuccessful=true;
