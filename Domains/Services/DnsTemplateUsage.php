<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\{ManagedPublicConfig,WatchName,WatchProductMap};
/** A replacement is staff intent; the system cannot choose it for assigned domains or products. */
final class DnsTemplateUsage
{
    /**
     * Live assignments are explained before retained history. With $lock, the caller already holds the site settings
     * and template row locks, as every assignment writer takes the template lock before saving its choice; locking
     * reads then see choices committed while this transaction waited, which an older read view would miss.
     */
    public static function refusal(int $id,bool $lock=false): ?string
    {
        if($reason=SiteDnsDefault::usesRefusal($id,$lock)){return $reason;}
        $read=fn($q)=>$q->when($lock,fn($q)=>$q->lockForUpdate());
        // Paused choices still apply after monitoring resumes. Stopped watches no longer use the template.
        $watches=$read(WatchName::where('ns_profile_id',$id)->whereIn('state',['active','paused','linked']))->pluck('state')->all();
        if(array_intersect($watches,['active','paused'])){return 'This template is assigned to domains. Choose another template for them first.';}
        $managed=$read(ManagedPublicConfig::where('profile_id',$id))->pluck('domain_id')->all();
        // A registered domain still eligible for monitoring compares against its chosen template today. Staff can
        // assign a template only to domains without a usable one, so the guidance cannot offer a replacement here.
        if($managed && ManagedDnsSetup::eligible(app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->getTimestamp())->whereIn('domains_domains.id',$managed)->exists()){
            return 'This template is assigned to monitored registered domains. Keep it while they are monitored.';
        }
        if($read(WatchProductMap::where('expected_ns_profile_id',$id))->exists()){
            return 'This template is assigned to hosting products. Choose another template for them first.';
        }
        if($watches || $managed){return 'This template is assigned to retained domain records. Keep it for their history.';}
        return null;
    }
}
