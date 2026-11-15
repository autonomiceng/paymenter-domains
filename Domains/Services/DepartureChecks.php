<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock,DnsReader};
use Paymenter\Extensions\Servers\Domains\Dns\{QuotaDnsReader,RegistryContext};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
/** Read-only departure evidence. The existing holding queue owns the one automatic recheck. */
final class DepartureChecks
{
    public const PAUSED='Registrar changes paused while we check this domain.';
    public const REGISTRAR=['lock','auth_code_email','contacts','contact_profile','nameservers'];
    public function __construct(private DnsReader $dns,private Clock $clock,private ?\Closure $factory=null) {}
    public static function suspected(Domain $d): bool
    { return !$d->adoptionPending()&&in_array($d->status,['active','grace','expired'],true)&&HoldingReadRetry::absent($d); }
    /** Called under holding locks after observation writers have saved the final result. */
    public static function capture(Domain $d,int $now): void
    {
        RenewalOwnership::review($d,$now);$p=$d->observation??[];
        if(!self::suspected($d)){if(isset($p['departure_check'])){unset($p['departure_check']);$d->update(['observation'=>$p]);}return;}
        if(($p['departure_check']['identity']??null)!==ObservationRecords::identity($d)){
            $p['departure_check']=['identity'=>ObservationRecords::identity($d),'at'=>$now,'rechecked'=>false];$d->update(['observation'=>$p]);
        }
        HoldingObservationWork::queueInitial($d,(new \DateTimeImmutable())->setTimestamp($now));
        HoldingObservationWork::idle(HoldingObservationWork::current($d),$now)->update(self::schedule($d,$now));
    }
    public static function schedule(Domain $d,int $now): array
    {
        $check=$d->observation['departure_check']??[];
        return !isset($check['registry'])?['safe_code'=>'departure_registry','due_at'=>$now]:
            ['safe_code'=>empty($check['rechecked'])?'departure_recheck':'domain_not_in_account','due_at'=>($check['at']??$now)+86400];
    }
    public static function attention(Domain $d,int $now): array
    { return ['state'=>$now>=($d->observation['departure_check']['at']??$now)+172800?'delayed':'needs_review','expiry'=>$d->provider_expires_at?->format('Y-m-d')]; }
    public static function dnsNote(Domain $d): ?string
    {
        if(!self::suspected($d)){return null;}
        if(RegistrationDns::registrarDns($d)){return "This domain uses our registrar's DNS. Its records stop when the transfer completes, so set up DNS at your new host first.";}
        $actual=ObservationProjection::nameservers($d)?->nameservers??[];$ours=SiteDnsDefault::profile()?->nameservers??[];sort($actual);sort($ours);
        return $ours&&$actual===$ours?'DNS records on your hosting plan keep working while the plan is active.':null;
    }
    public function registry(Domain $seed): bool
    {
        OperationClaims::outside();$identity=ObservationRecords::identity($seed);$clock=fn()=>$this->clock->now()->getTimestamp();
        $pool=new PublicDnsQuota();$token=$pool->acquire($clock());if($token===null){return false;}
        try{
            $quota=new RdapQuota();$dns=new QuotaDnsReader($this->dns,$pool,$token,$clock);
            $saved=DB::table('domains_watch_schedule')->where('scope','bootstrap')->first();
            $snapshot=$saved?->data===null?null:json_decode($saved->data,true,32,JSON_THROW_ON_ERROR);
            $reader=$this->factory?($this->factory)($dns,$snapshot):new PublicRegistryReader($dns,$snapshot);
            $context=new RegistryContext($clock,fn($url)=>$quota->reserve($url,$clock()),fn($url,$r)=>$quota->complete($url,$r,$clock()));
            $result=$reader->read($seed->domain,$context);if($dns->saturated||($result['reason']??null)==='deferred'){return false;}
            return DB::transaction(function()use($seed,$identity,$result,$clock):bool{
                $d=HoldingLocks::take($seed)['domain'];if(ObservationRecords::identity($d)!==$identity){return false;}
                $p=$d->observation??[];$p['departure_check']??=['identity'=>$identity,'at'=>$clock(),'rechecked'=>false];
                $p['departure_check']['registry']=[...$result,'at'=>$clock()];$d->update(['observation'=>$p]);return true;
            });
        }finally{$pool->release($token);}
    }
}
