<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Service;
/** Registry dates belong to Domains; native paid and staff saves remain available. */
final class NativeExpiry
{
    public const NUMERIC_MESSAGE="Increment or decrement cannot set a managed domain's expiry. Save the service normally; Domains manages domain expiry.";
    public static function boot(): void
    {
        static $booted=false;if($booted){return;}$booted=true;
        $normalized=new \WeakMap;
        Service::saving(static function(Service $s)use($normalized):void {
            // Validate an active exact-object grant before querying through mutable model metadata.
            NativeDomainGuard::identityProjection($s);
            if(!NativeIdentity::routed($s)){return;}
            // Ordinary saves normalize here; updating repeats the invariant. Grants already plan null.
            foreach($s->getAttributes()as$key=>$value){if(self::expiry($key)){
                if($value!==null){$normalized[$s]=true;}$s->setAttribute($key,null);
            }}$s->expires_at=null;
        });
        Service::updating(static function(Service $s)use($normalized):void {
            NativeDomainGuard::identityProjection($s);
            if(!NativeIdentity::routed($s)){return;}
            $stored=Service::find($s->getRawOriginal('id')??$s->id)?->getRawOriginal('expires_at');
            // Instance increment/decrement skips saving. Eloquent passes its original extras straight
            // to the query builder AFTER updating, so changing this model cannot clear those extras.
            // No native Service numeric caller exists. Refuse only a non-null expiry write; ordinary
            // numeric updates without expiry extras (including Active) must continue.
            foreach($s->getAttributes()as$key=>$value){if(self::expiry($key)&&$value!==null
                &&($key!=='expires_at'||$value!==$stored||$s->isDirty('expires_at'))){
                logger()->warning('Managed domain numeric expiry refused.',['service_id'=>$s->getRawOriginal('id')??$s->id]);
                throw new \DomainException(self::NUMERIC_MESSAGE);
            }}
            if(isset($normalized[$s])||$s->isDirty('expires_at')){
                logger()->info('Managed domain native expiry cleared.',['service_id'=>$s->getRawOriginal('id')??$s->id]);
            }
            unset($normalized[$s]);$s->expires_at=null;
        });
    }
    private static function expiry(string $key): bool
    {$parts=explode('.',explode('->',$key,2)[0]);return strtolower(end($parts))==='expires_at';}
}
