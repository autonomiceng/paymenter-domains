<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts,Context,Edit,Query};
use Paymenter\Extensions\Servers\Domains\Models\{Account,SalesPolicy,Tld};
use Paymenter\Extensions\Servers\Domains\Services\AccountBindings;
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;

/** Share the explicit offline composition with existing native/financial fixture arrangers. */
function activeFixturePorts(object $owner,?CallerPorts $register=null,?FakeClock $clock=null): CallerPorts
{
    static $ports;$ports??=new WeakMap;
    if($register!==null){$ports[$owner]=$register;if($clock!==null){activeFixtureClock($owner,$clock);}}
    return $ports[$owner]??throw new LogicException('Explicit active fixture composition required');
}
function activeFixtureClock(object $owner,?FakeClock $register=null): FakeClock
{
    static $clocks;$clocks??=new WeakMap;
    if($register!==null){$clocks[$owner]=$register;}
    return $clocks[$owner]??throw new LogicException('Explicit active fixture clock required');
}
function activeFixtureSeller(object $owner,string $tld,int $selection,?int $server,bool $register,bool $transfer,DateTimeImmutable $now): void
{
    $ports=activeFixturePorts($owner);$policy=SalesPolicy::where('tld',$tld)->firstOrFail();
    if((int)$policy->revision!==$selection){throw new DomainException('Synthetic seller revision changed');}
    $target=$server??$policy->server_id??Tld::where('tld',$tld)->value('server_id');
    $b=AccountBindings::binding(Account::where('server_id',$target)->sole());
    $q=new Query($b,[$tld],'register');$revision=$ports->readOffers($q,Context::Staff)[0]->revision;
    $actions=[];if($register){$actions[]='register';}if($transfer){$actions[]='transfer';}
    $ports->apply(Edit::seller($b,$tld,$server===null?[]:$actions),$revision);
}
/** Pure fixture publication uses the real writer. Staff flows keep their real review/command path. */
function activeFixtureRepublish(object $owner,Tld|int $ending,?DateTimeImmutable $now=null,mixed ...$legacy): void
{
    if(array_filter($legacy,static fn($value)=>$value!==null)){throw new DomainException('Typed catalogue command required');}
    if($now!==null){activeFixtureClock($owner)->instant=$now;}
    $ports=activeFixturePorts($owner);$t=$ending instanceof Tld?$ending:Tld::findOrFail($ending);
    $b=AccountBindings::binding(Account::where('server_id',$t->server_id)->sole());
    $q=new Query($b,[$t->tld],'register');
    $ports->apply(Edit::republish($b,$t->tld),$ports->readOffers($q,Context::Staff)[0]->revision);
}

function activeFixtureOffers(object $owner,?\Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers $register=null): \Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers
{
    static $offers;$offers??=new WeakMap;if($register!==null){$offers[$owner]=$register;}
    return $offers[$owner]??throw new LogicException('Explicit active fixture offers required');
}
function activeFixtureEligibility($bindings,$clock,$transfers=null): \Paymenter\Extensions\Servers\Domains\Services\CheckoutEligibility
{
    $port=new class($bindings,$clock,$transfers) extends \Paymenter\Extensions\Servers\Domains\Services\CheckoutEligibility {
        public \Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers $fixtureOffers;
        protected function catalogue(): ?\Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers {return $this->fixtureOffers;}
    };$port->fixtureOffers=activeFixtureOffers($bindings);return $port;
}
function activeFixtureAdmission($native,$sellers,$eligibility,$clock): \Paymenter\Extensions\Servers\Domains\Services\CartAdmission
{
    $port=new class($native,$sellers,$eligibility,$clock) extends \Paymenter\Extensions\Servers\Domains\Services\CartAdmission {
        public \Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers $fixtureOffers;
        protected function catalogue(): ?\Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers {return $this->fixtureOffers;}
    };$port->fixtureOffers=activeFixtureOffers($sellers);return $port;
}
function activeFixtureStops(object $owner,Tld $t,array $stops): void
{
    $ports=activeFixturePorts($owner);$b=AccountBindings::binding(Account::where('server_id',$t->server_id)->sole());
    $raw=array_intersect_key(\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($t->fresh()->catalogue_intent),get_object_vars(new \Paymenter\Extensions\Servers\Domains\Catalogue\Intent));$raw['stops']=$stops;
    $q=new Query($b,[$t->tld],'register');
    $ports->apply(Edit::override($b,$t->tld,new \Paymenter\Extensions\Servers\Domains\Catalogue\Intent(...$raw)),$ports->readOffers($q,Context::Staff)[0]->revision);
}

/** Independently declared synthetic OTE facts mirror the current production configuration contract.
 * Price replies never supply these terms. No activation/enrollment receipt is manufactured. */
function fixtureNameSiloFacts($binding,string $ending,\Paymenter\Extensions\Servers\Domains\Catalogue\Facts $facts): \Paymenter\Extensions\Servers\Domains\Catalogue\Facts
{
    if($binding->registrarKey!=='namesilo'||$binding->environment!==\Paymenter\Extensions\Servers\Domains\Data\Environment::Test){return $facts;}
    $account=Account::where('server_id',$binding->serverId)->sole();
    $copy=new \Paymenter\Extensions\Servers\Domains\Catalogue\Facts($facts->terms,$facts->registryFields,$facts->renewalSafe,
        $facts->requiresNameservers,$facts->autoRenewOnly,'namesilo_test_protocol',$facts->observedAt,$facts->freshUntil);
    $rows=config('domains.namesilo_test.accounts',[]);$row=$rows[$account->stable_identity]??[];
    $row['fingerprint']=$binding->fingerprint;$row['credential_revision']=app(AccountBindings::class)->credentialRevision($binding);
    $row['facts'][$ending]=[
        'terms'=>$copy->terms,'registry_fields'=>$copy->registryFields,'renewal_safe'=>$copy->renewalSafe,
        'requires_nameservers'=>$copy->requiresNameservers,'auto_renew_only'=>$copy->autoRenewOnly,
        'observed_at'=>$copy->observedAt->format(DATE_ATOM),'fresh_until'=>$copy->freshUntil->format(DATE_ATOM)];
    $rows[$account->stable_identity]=$row;
    config(['domains.namesilo_account.environment'=>'test','domains.namesilo_test.accounts'=>$rows]);
    return $copy;
}
