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
    public const RENEWAL_WAITS='Renewal waits while we check this domain.';
    public const REGISTRAR=['lock','auth_code_email','contacts','contact_profile','nameservers','ds_add','ds_delete'];
    public function __construct(private DnsReader $dns,private Clock $clock,private ?\Closure $factory=null) {}
    public static function suspected(Domain $d): bool
    { return !$d->adoptionPending()&&in_array($d->status,['active','grace','expired'],true)&&HoldingReadRetry::absent($d); }
    /** Kept departure reads for staff Activity, newest last. */
    public const READS=20;
    /** Called under holding locks after observation writers have saved the final result. $absent: this read itself found
     * the domain missing from our registrar account, so staff Activity lists it. */
    public static function capture(Domain $d,int $now,bool $absent=false): void
    {
        RenewalOwnership::review($d,$now);$p=$d->observation??[];
        if(!self::suspected($d)){if(isset($p['departure_check'])){unset($p['departure_check']);$d->update(['observation'=>$p]);}return;}
        $opened=($p['departure_check']['identity']??null)!==ObservationRecords::identity($d);
        if($opened){$p['departure_check']=['identity'=>ObservationRecords::identity($d),'at'=>$now,'rechecked'=>false];}
        // The read that opens the review is the registrar read; later absent reads are its rechecks.
        if($absent){$reads=is_array($p['departure_reads']??null)?$p['departure_reads']:[];$p['departure_reads']=array_slice([...$reads,['at'=>$now,'recheck'=>!$opened]],-self::READS);}
        if($opened||$absent){$d->update(['observation'=>$p]);}
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
        return match(self::dnsCase($d)){
            'registrar'=>"This domain uses our registrar's DNS. Its records stop when the transfer completes, so set up DNS at your new host first.",
            'hosting'=>'DNS records on your hosting plan keep working while the plan is active.',
            default=>null};
    }
    /** Where this suspected holding's DNS is served, from the nameservers in the last registrar read: our registrar's DNS,
     * our nameservers on the owner's hosting plan, or other nameservers. Our nameservers without a hosting service of the
     * owner's for this domain read as other nameservers: nothing says a plan keeps them working. Null when not suspected
     * or no nameservers were read. */
    public static function dnsCase(Domain $d): ?string
    {
        if(!self::suspected($d)||ObservationProjection::nameservers($d)?->state!=='observed'){return null;}
        if(RegistrationDns::registrarDns($d)){return 'registrar';}
        $actual=ObservationProjection::nameservers($d)?->nameservers??[];$ours=SiteDnsDefault::profile()?->nameservers??[];sort($actual);sort($ours);
        return $ours&&$actual===$ours&&self::hosted($d)?'hosting':'other';
    }
    /** A departure record's saved DNS case as it reads now: the hosting case only while the owner still has a hosting
     * service for this domain. */
    public static function savedDnsCase(Domain $d, mixed $saved): ?string
    { return $saved==='hosting'&&!self::hosted($d)?'other':(is_string($saved)?$saved:null); }
    /** The owner has an active hosting service whose domain is this one, the same link the customer domain page lists. */
    public static function hosted(Domain $d): bool
    {
        $c=new DomainSummaryContext((int)$d->user_id,[(int)$d->id],[]);
        foreach($c->related('managed',(int)$d->id) as $s){if($c->validSource($s)){return true;}}
        return false;
    }
    /** The staff words for dnsCase(): a short title for the Nameservers row and a sentence for the DNS records row. @return array{0:string,1:string}|null */
    public static function staffDnsCase(Domain $d): ?array
    {
        $company=\Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels::company();$ours=($company!==''?$company:'Our').' nameservers';
        $product=match($d->registrar_key){'enom'=>'Enom DNS','namesilo'=>'NameSilo DNSOwl',default=>null};
        return match(self::dnsCase($d)){
            'registrar'=>["Our registrar's DNS",($product?'On '.$product.', our':'On our')." registrar's DNS."],
            'hosting'=>[$ours.', hosting plan','On '.$ours.', on the hosting plan.'],
            'other'=>['Other nameservers','On other nameservers.'],
            default=>null};
    }
    /** The public registry's last answer lists a registrar other than this holding's: the transfer completed, so our
     * registrar no longer serves its DNS. No answer, no registrar listed or a registrar we can't name reads as false. */
    public static function registryListsOther(Domain $d): bool
    {
        $registry=$d->observation['departure_check']['registry']??null;
        $ours=match($d->registrar_key){'enom'=>'enom','namesilo'=>'namesilo',default=>null};
        if(!is_array($registry)||($registry['result']??null)!=='facts'||!is_string($registry['registrar']??null)||$ours===null){return false;}
        return !str_contains((string)preg_replace('/[^a-z0-9]/','',strtolower($registry['registrar'])),$ours);
    }
    /** Why DNS records can't be edited: the domain used our registrar's DNS and the registry lists another registrar, so
     * nothing serves those records (mock Decision 1). Null while they may still be served. */
    public static function dnsStopped(Domain $d): ?string
    {
        if(self::dnsCase($d)!=='registrar'||!self::registryListsOther($d)){return null;}
        return ManagementNameservers::registrar($d)." stopped serving them when the transfer completed, so they can't be edited.";
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
                if(!self::suspected($d)){return true;}
                $p=$d->observation??[];$p['departure_check']??=['identity'=>$identity,'at'=>$clock(),'rechecked'=>false];
                $p['departure_check']['registry']=[...$result,'at'=>$clock()];$d->update(['observation'=>$p]);return true;
            });
        }finally{$pool->release($token);}
    }
}
