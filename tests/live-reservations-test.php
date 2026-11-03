<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Real encrypted bindings and conditional SQL counters need the native SQLite fixture; no transport is installed.
require __DIR__.'/native-app.php';
require_once __DIR__.'/Support/FakeRegistrar.php';
use App\Models\Server;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock,LiveReservationPolicy};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment,Money};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances,EnomLiveCalls,EnomLiveCallSchema,Installation,LiveReservationLimit,LiveReservations};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
(new Installation)->install();
$clock=new FakeClock(new DateTimeImmutable('2026-11-01T23:59:59Z'));app()->instance(Clock::class,$clock);
$account=static function(string $provider,string $identity,string $environment='live'): AccountBinding {
    $s=Server::create(['name'=>'Synthetic reservation account','type'=>'server','extension'=>'Domains','enabled'=>true]);
    $a=Account::withVerifiedBindingWrite(fn()=>Account::create(['server_id'=>$s->id,'registrar_key'=>$provider,'environment'=>$environment,'stable_identity'=>$identity]));
    return CatalogueAcceptances::binding($a);
};
$enom=$account('enom','shared-local-account');$other=$account('namesilo','shared-local-account');
config(['app.url'=>'https://billing.example.com','domains.enom_identity.environment'=>'live','domains.enom_live.site'=>'https://billing.example.com',
    'domains.enom_live.accounts'=>['shared-local-account'=>['fingerprint'=>$enom->fingerprint,'daily_call_cap'=>4,'daily_spend_limit_usd'=>'1.00']]]);
Account::withVerifiedBindingWrite(fn()=>Account::where('server_id',$enom->serverId)->sole()->update(['live_site'=>'https://billing.example.com']));
// Existing Enom rows must be consumed in place, including micro-dollar precision.
DB::table(EnomLiveCallSchema::TABLE)->insert(['account'=>hash('sha256','shared-local-account'),'day'=>'2026-11-01','used'=>1,'spend_micros'=>500000]);
EnomLiveCalls::reserve($enom,new Money('0.000001','USD'));
same(500001,(int)DB::table(EnomLiveCallSchema::TABLE)->value('spend_micros'),'Enom preserves its deployed ledger and exact precision');
// A synthetic policy exercises the shared contract only; it grants no installed NameSilo authority.
$policy=new class implements LiveReservationPolicy {
    public array $settings=['daily_call_cap'=>4,'daily_spend_limit_usd'=>'1.00'];
    public bool $allowed=true;
    public function limits(AccountBinding $binding): array {
        if(!$this->allowed||$binding->registrarKey!=='namesilo'){throw new DomainException('Synthetic policy refuses.');}
        return $this->settings;
    }
};
$calls=new LiveReservations($policy);
$refuses=static function(callable $work):void {try{$work();throw new LogicException('Expected reservation refusal');}catch(LiveReservationLimit){}};
same('2026-11-01',$calls->reserve($other,new Money('0.75','USD')),'A second provider uses the shared UTC reservation path');
same(['used'=>1,'spend_micros'=>750000],$calls->usage($other),'Providers with equal local identities keep separate counters');
same(2,DB::table(EnomLiveCallSchema::TABLE)->count(),'Enom historical rows are retained alongside namespaced provider rows');
$sameAccount=CatalogueAcceptances::binding(Account::where('server_id',$other->serverId)->sole());$calls=new LiveReservations($policy);
$refuses(fn()=>$calls->reserve($sameAccount,new Money('0.250001','USD')));
same(['used'=>1,'spend_micros'=>750000],$calls->usage($sameAccount),'A reconstructed binding cannot reset account spend');
$calls->reserve($sameAccount,new Money('0.25','USD'));
same(['used'=>2,'spend_micros'=>1000000],$calls->usage($other),'Reservations survive binding and service reconstruction');
$refuses(fn()=>$calls->reserve($other,new Money('0.000001','USD')));
$calls->reserve($other);$calls->reserve($sameAccount);$refuses(fn()=>$calls->reserve($other));
same(['used'=>4,'spend_micros'=>1000000],$calls->usage($other),'Calls and spend share an atomic cap; refusals refund nothing');
$clock->instant=$clock->instant->modify('+1 second');
same('2026-11-02',$calls->reserve($other),'UTC midnight starts the next day');
same(['used'=>1,'spend_micros'=>0],$calls->usage($other),'The new UTC day has independent usage');
same(3,DB::table(EnomLiveCallSchema::TABLE)->count(),'Prior-day usage remains durable');
try {
    LiveReservations::withCheckoutHeadroom('namesilo','shared-local-account',static function()use($calls,$other):void {
        LiveReservations::withCheckoutHeadroom('namesilo','shared-local-account',fn()=>$calls->reserve($other));
        $calls->reserve($other);
    });
    throw new LogicException('Background work spent checkout headroom');
}catch(LiveReservationLimit){}
$calls->reserve($other);same(3,$calls->usage($other)['used'],'Nested headroom scopes restore after refusal');
$policy->allowed=false;$refuses(fn()=>$calls->reserve($other));$refuses(fn()=>$calls->usage($other));$policy->allowed=true;
foreach([['daily_call_cap'=>'4'],['daily_spend_limit_usd'=>'1e2'],['daily_spend_limit_usd'=>'-1.00']] as $bad){
    $saved=$policy->settings;$policy->settings=[...$saved,...$bad];$refuses(fn()=>$calls->reserve($other));$policy->settings=$saved;
}
$refuses(fn()=>$calls->reserve($other,new Money('0.01','EUR')));
$refuses(fn()=>$calls->reserve(new AccountBinding('namesilo',$other->serverId,Environment::Live,'wrong-local-account')));
$test=$account('namesilo','test-account','test');$refuses(fn()=>$calls->reserve($test));
DB::transaction(fn()=>$refuses(fn()=>$calls->reserve($other)));
same(['used'=>3,'spend_micros'=>0],$calls->usage($other),'Policy, currency, binding and transaction refusals consume no capacity');
// Historical account usage can predate the current server enrollment; no account rebinding is enabled here.
DB::table(EnomLiveCallSchema::TABLE)->insert(['account'=>hash('sha256',"namesilo\0live\0restored-local-account"),'day'=>'2026-11-02','used'=>3,'spend_micros'=>900000]);
$restored=$account('namesilo','restored-local-account');$restoredCalls=new LiveReservations($policy);
same(['used'=>3,'spend_micros'=>900000],$restoredCalls->usage($restored),'A new local binding retains prior usage by stable identity');
$restoredCalls->reserve($restored,new Money('0.10','USD'));$refuses(fn()=>$restoredCalls->reserve($restored));
// A day change after the conditional update must refuse while retaining that reservation.
DB::listen(static function($query)use($clock):void {
    if(str_starts_with($query->sql,'update')&&str_contains($query->sql,EnomLiveCallSchema::TABLE)){$clock->instant=$clock->instant->modify('+1 day');}
});
$refuses(fn()=>$calls->reserve($other));
same(4,(int)DB::table(EnomLiveCallSchema::TABLE)->where(['account'=>hash('sha256',"namesilo\0live\0shared-local-account"),'day'=>'2026-11-02'])->value('used'),'Rollover refusal retains the unsent reservation');
Schema::rename(EnomLiveCallSchema::TABLE,'domains_live_reservations_unreadable');
$refuses(fn()=>$calls->reserve($other));
Schema::rename('domains_live_reservations_unreadable',EnomLiveCallSchema::TABLE);
echo "Shared Live reservations preserve account lineage, provider isolation, UTC limits and checkout headroom.\n";
$nativeSuccessful=true;
