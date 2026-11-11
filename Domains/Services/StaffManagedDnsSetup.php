<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\User;
use Illuminate\Support\Facades\{Crypt,DB};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,ManagedPublicConfig,WatchProfile};
/** The system cannot infer intended DNS for these domains; staff choose a comparison template. */
final class StaffManagedDnsSetup
{
    public static function ticket(User $actor,Domain $d): string
    {
        return Crypt::encryptString(json_encode(['actor'=>(int)$actor->id,'id'=>(int)$d->id,'owner'=>(int)$d->user_id,
            'generation'=>(int)$d->authority_generation,'account'=>$d->account_fingerprint,
            'revision'=>(int)(ManagedPublicConfig::find($d->id)?->revision??0),'until'=>time()+600,'key'=>bin2hex(random_bytes(16))],JSON_THROW_ON_ERROR));
    }
    public function assign(User $actor,int $id,string $ticket,int $profileId,?string $expected): void
    {
        StaffWatchWrites::authorize($actor);
        try{$t=json_decode(Crypt::decryptString($ticket),true,16,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \DomainException('Reload and try again.');}
        if(($t['actor']??null)!==(int)$actor->id || ($t['id']??null)!==$id || ($t['until']??0)<=time()
            || !in_array($expected,[null,'platform','external'],true)){throw new \DomainException('Reload and try again.');}
        $owner=DB::transaction(function()use($actor,$id,$ticket,$profileId,$expected,$t):int {
            $seed=Domain::findOrFail($id);$l=HoldingLocks::take($seed,staffId:(int)$actor->id);HoldingAnchor::resolve($l);$d=$l['domain'];
            StaffWatchWrites::authorize($actor);
            if(!ManagedPublicTarget::valid($l,time()) || (int)$d->user_id!==$t['owner'] || (int)$d->authority_generation!==$t['generation']
                || $d->account_fingerprint!==$t['account']){throw new \DomainException('This domain changed. Reload and try again.');}
            BusinessSetting::singleton(true);
            $config=ManagedPublicConfig::whereKey($id)->lockForUpdate()->first();
            if((int)($config?->revision??0)!==$t['revision'] || ($config && (int)$config->user_id!==(int)$d->user_id)){throw new \DomainException('This domain changed. Reload and try again.');}
            $profile=WatchProfile::whereKey($profileId)->lockForUpdate()->first();
            if(!ManagedDnsSetup::usable($profile)){throw new \DomainException('Choose an enabled DNS template.');}
            if(!DB::table('domains_watch_staff_claims')->insertOrIgnore(['claim'=>hash('sha256',$ticket),'subject_type'=>'managed_dns','subject_id'=>$id,'action'=>'profile','created_at'=>time()])){throw new \DomainException('Reload and try again.');}
            $config??=ManagedPublicConfig::create(['domain_id'=>$id,'user_id'=>$d->user_id,'notice_preferences'=>[],'reason'=>'DNS monitoring setup']);
            ManagedDnsSetup::assign($config,$profile,$expected,(int)$actor->id,'staff',time());
            return (int)$d->user_id;
        });
        // Local durable enrollment only; the ordinary dispatcher reads DNS outside the staff request.
        app(WatchWork::class)->enrollManaged($id,$owner);
    }
}
