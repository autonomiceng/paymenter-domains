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
        // Each read is one indexed row or page for this template, so its cost and locks never grow with other
        // templates' retained assignments. Paused choices still apply after monitoring resumes.
        if($read(WatchName::where('ns_profile_id',$id)->whereIn('state',['active','paused']))->value('id')!==null){
            return 'This template is assigned to domains. Choose another template for them first.';
        }
        // Stopped watches no longer use the template; a linked one keeps it for history.
        $linked=$read(WatchName::where('ns_profile_id',$id)->where('state','linked'))->value('id')!==null;
        // A registered domain still eligible for monitoring compares against its chosen template today. Staff can
        // assign a template only to domains without a usable one, so the guidance cannot offer a replacement here.
        $managed=false;$after=-1;$now=app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->getTimestamp();
        do{
            $page=$read(ManagedPublicConfig::where('profile_id',$id)->where('domain_id','>',$after)->orderBy('domain_id')->limit(100))->pluck('domain_id')->all();
            if(!$page){break;}$managed=true;$after=(int)end($page);
            if(ManagedDnsSetup::eligible($now)->whereIn('domains_domains.id',$page)->exists()){
                return 'This template is assigned to monitored registered domains. Keep it while they are monitored.';
            }
        }while(count($page)===100);
        if($read(WatchProductMap::where('expected_ns_profile_id',$id))->value('id')!==null){
            return 'This template is assigned to hosting products. Choose another template for them first.';
        }
        if($linked || $managed){return 'This template is assigned to retained domain records. Keep it for their history.';}
        return null;
    }
}
