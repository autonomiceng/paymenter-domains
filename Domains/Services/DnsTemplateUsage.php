<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\{ManagedPublicConfig,WatchName,WatchProductMap};
/** A replacement is staff intent; the system cannot choose it for assigned domains or products. */
final class DnsTemplateUsage
{
    public static function refusal(int $id): ?string
    {
        if(SiteDnsDefault::uses($id)){return 'Choose another template for new domains first.';}
        // Paused choices still apply after monitoring resumes. Stopped watches no longer use the template.
        if(WatchName::where('ns_profile_id',$id)->whereIn('state',['active','paused','linked'])->exists() || ManagedPublicConfig::where('profile_id',$id)->exists()){
            return 'This template is assigned to domains. Choose another template for them first.';
        }
        if(WatchProductMap::where('expected_ns_profile_id',$id)->exists()){
            return 'This template is assigned to hosting products. Choose another template for them first.';
        }
        return null;
    }
}
