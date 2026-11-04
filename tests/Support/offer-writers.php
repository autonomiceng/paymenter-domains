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
    $raw=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($t->fresh()->catalogue_intent);$raw['stops']=$stops;
    $q=new Query($b,[$t->tld],'register');
    $ports->apply(Edit::override($b,$t->tld,new \Paymenter\Extensions\Servers\Domains\Catalogue\Intent(...$raw)),$ports->readOffers($q,Context::Staff)[0]->revision);
}
