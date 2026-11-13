<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Setting,User};
use Illuminate\Support\Facades\{Cache,DB,Schema};
use Paymenter\Extensions\Servers\Domains\Dns\RoutingProfile;
use Paymenter\Extensions\Servers\Domains\Models\{Account,WatchProfile,WatchName,WatchAudit};
/** One live site reference. Null domain/watch assignments inherit it without being rewritten. */
final class SiteDnsDefault
{
    public const KEY='domains_site_dns_template';
    public const MANAGE='admin.domains.settings.new_domain_dns';
    public const NONE='New domains have no nameservers to use. Choose a template in Domains > DNS templates.';
    public const MISSING='Default nameservers are Missing. '.self::NONE;
    public const REPLACE='Choose another template for new domains first.';
    public const RETAINED='This template is saved for new domains but cannot be used. Choose another template for new domains first.';
    public const ASK='Ask staff who can change the default nameservers.';
    private static function row(bool $lock=false): ?object
    { return BusinessSetting::row(self::KEY,$lock); }
    private static function usable(?object $row,bool $lock=false): ?WatchProfile
    {
        if($row===null || $row->type!=='string' || $row->encrypted){return null;}
        $id=$row->value;
        $p=is_string($id)&&ctype_digit($id)?WatchProfile::whereKey($id)->when($lock,fn($q)=>$q->lockForUpdate())->first():null;
        return ManagedDnsSetup::usable($p)?$p:null;
    }
    private static function saves(?object $row,int $id): bool
    { return $id>0 && $row?->type==='string' && !$row->encrypted && $row->value===(string)$id; }
    public static function profile(bool $lock=false): ?WatchProfile
    { return self::usable(self::row($lock),$lock); }
    public static function uses(int $id,bool $lock=false): bool
    { return self::saves(self::row($lock),$id); }
    /** Why a saved reference keeps this template, or null. Unusable references stay protected. */
    public static function usesRefusal(int $id,bool $lock=false): ?string
    {
        $row=self::row($lock);
        if(!self::saves($row,$id)){return null;}
        return self::usable($row,$lock)?->id===$id?self::REPLACE:self::RETAINED;
    }
    /** DNS template list markers from one settings read. @return array<int,string> */
    public static function marks(): array
    {
        $row=self::row();
        if($row?->type!=='string'||$row->encrypted||!is_string($row->value)||!ctype_digit($row->value)){return [];}
        $id=(int)$row->value;
        return [$id=>self::usable($row)?->id===$id?'Used for new domains':'Saved for new domains but cannot be used'];
    }
    public static function watchProfile(WatchName $watch,bool $lock=false): ?WatchProfile
    {
        // An explicit watch choice, including an unusable one, is never silently replaced.
        return $watch->ns_profile_id===null?self::profile($lock):WatchProfile::whereKey($watch->ns_profile_id)->when($lock,fn($q)=>$q->lockForUpdate())->first();
    }
    public static function watchExpected(WatchName $watch,?bool $configured=null): ?string
    { return $watch->expected_dns??($watch->ns_profile_id===null && ($configured??(self::profile()!==null))?'platform':null); }
    /** Called only after lifecycle initialization has proved and installed the retained schema. */
    public static function ensure(): void
    {
        $changed=Cache::lock('domains-site-dns-default',30)->block(5,static function():bool {
            return DB::transaction(static function():bool {
                Setting::where('settingable_type',(new \App\Models\Server)->getMorphClass())->where('key','domains_new_domain_dns_template')
                    ->whereIn('settingable_id',\App\Models\Server::where('extension','Domains')->select('id'))->delete();
                BusinessSetting::singleton(true);
                if(self::row(true)!==null){return false;} // Deployment changes never move a saved choice.
                $names=config('domains.site_dns_default.nameservers');
                if(!is_array($names) || !$names){return false;}
                try{$routing=new RoutingProfile($names,[],[]);}catch(\InvalidArgumentException|\TypeError){return false;}
                try{self::validateRegistrars($routing->nameservers);}catch(\DomainException){return false;}
                // System installation has no staff actor. Quiet maintenance preserves normal staff validation.
                $p=new WatchProfile(['name'=>'Site DNS','nameservers'=>$routing->nameservers,'website_endpoints'=>[],
                    'email_endpoints'=>[],'enabled'=>true,'edited_by'=>0,'reason'=>'Deployment DNS default']);
                $p->saveQuietly();
                self::write($p);
                WatchAudit::create(['subject_type'=>'dns_profile','subject_id'=>$p->id,'event'=>'deployment_dns_default',
                    'reason'=>'Deployment DNS default','after_revision'=>'1','data'=>['profile_id'=>$p->id],'created_at'=>now()]);
                return true;
            });
        });
        if($changed){self::reconcile();}
    }
    public static function authorize(User $actor): void
    {
        $staff=\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::class;
        abort_unless($staff::allows($actor,$staff::POLICY) && $staff::allows($actor,self::MANAGE),403);
    }
    public static function save(User $actor,int $id): void
    {
        self::authorize($actor);
        Cache::lock('domains-site-dns-default',30)->block(5,static function()use($actor,$id):void {
            DB::transaction(static function()use($actor,$id):void {
                self::authorize($actor);BusinessSetting::singleton(true);
                $p=WatchProfile::whereKey($id)->lockForUpdate()->first();
                if(!ManagedDnsSetup::usable($p)){throw new \DomainException('Choose an enabled DNS template.');}
                self::validateRegistrars($p->nameservers);
                $before=self::profile()?->id;self::write($p);
                WatchAudit::create(['subject_type'=>'site_dns','subject_id'=>0,'actor_id'=>$actor->id,'event'=>'site_dns_default_changed',
                    'reason'=>'Site DNS default changed','before_revision'=>(string)$before,'after_revision'=>(string)$id,
                    'data'=>['profile_id'=>$id],'created_at'=>now()]);
            });
        });
        self::reconcile();
    }
    /** Paused processing still represents a connected registrar and must support the saved default. */
    public static function validateRegistrars(array $nameservers): void
    {
        if($rule=self::rule($nameservers)){throw new \DomainException($rule.' Update this DNS template or choose another one.');}
    }
    /** Whether every connected registrar accepts these nameservers, so a save would not refuse them. */
    public static function fits(array $nameservers): bool{return self::rule($nameservers)===null;}
    private static function rule(array $nameservers): ?string
    {
        foreach(Account::orderBy('registrar_key')->distinct()->pluck('registrar_key') as $registrar){
            try{NewDomainDns::validate($registrar,$nameservers);}
            catch(\InvalidArgumentException){
                $max=$registrar==='enom'?12:13;$label=$registrar==='enom'?'Enom':'NameSilo';
                return count($nameservers)<2||count($nameservers)>$max?$label.' takes 2 to '.$max.' nameservers.':$label.' needs valid nameserver hostnames.';
            }
        }
        return null;
    }
    /** Deployment hosts, or why they cannot be used. Too many valid hosts is not a hostname problem. */
    private static function deployment(array $names): array|string
    {
        try{return (new RoutingProfile($names,[],[]))->nameservers;}catch(\InvalidArgumentException|\TypeError){}
        try{$hosts=array_unique(array_map(static fn($host)=>\Paymenter\Extensions\Servers\Domains\Dns\DnsQuery::hostname($host),array_values($names)));}
        catch(\InvalidArgumentException|\TypeError){return 'Deployment nameserver hostnames are invalid.';}
        return count($hosts)>13?'Deployment DNS default lists more than 13 nameservers.':'Deployment nameserver hostnames are invalid.';
    }
    /** Re-evaluate connected accounts on every read, including accounts added after installation.
     * Keep the saved reference and all domain/watch choices intact; only staff can replace the default.
     */
    public static function refusal(bool $canEdit=true): ?string
    {
        $row=self::row();
        $p=self::usable($row);
        if($p){$cause=self::rule($p->nameservers);$fix='Update this DNS template or choose another one.';}
        else{
            $names=config('domains.site_dns_default.nameservers');
            if(!is_array($names) || !$names){return null;}
            $names=self::deployment($names);
            $cause=is_string($names)?$names:self::rule($names);$fix='Create and turn on a compatible DNS template, then choose it for the default nameservers.';
        }
        // Staff without the settings permission keep the cause and are sent to someone who can fix it.
        return $cause===null?null:$cause.' '.($canEdit?$fix:self::ASK);
    }
    private static function write(WatchProfile $p): void
    {
        BusinessSetting::saveLocked(self::KEY,(string)$p->id);
    }
    /** Restart durable bounded cursors, then process one local page through the admitted workers. No DNS I/O. */
    public static function reconcile(?int $profileId=null,bool $inherited=false): void
    {
        if(!Schema::hasTable(PublicDnsQuota::TABLE) || !Schema::hasTable('domains_watch_schedule')){return;}
        $inherited=$inherited||$profileId===null;
        $watch=WatchName::where('state','active')->where(fn($q)=>$q->where('ns_profile_id',$profileId)->when($inherited,fn($q)=>$q->orWhereNull('ns_profile_id')))->exists();
        // A site-wide change also discovers eligible holdings without a config row; none means no sweep.
        $managed=Schema::hasTable('domains_managed_public_config') && ManagedDnsSetup::eligible(time())->when(!$inherited,fn($q)=>$q->whereExists(fn($c)=>$c->selectRaw('1')
            ->from('domains_managed_public_config as c')->whereColumn('c.domain_id','domains_domains.id')->where('c.profile_id',$profileId)))->exists();
        if(!$watch&&!$managed){return;}
        if($managed){ManagedPublicReconciliation::restart();}
        if($watch){
            // Settings, then templates, as the default save locks them.
            WatchDnsRefresh::request(static function()use($profileId,$inherited):array {
                BusinessSetting::singleton(true);
                $keys=[];$d=$inherited?self::profile(true):null;
                if($inherited){$keys[WatchDnsRefresh::DEFAULT]=$d?$d->id.':'.$d->revision:'none';}
                if($profileId!==null){$p=WatchProfile::whereKey($profileId)->lockForUpdate()->first();$keys[WatchDnsRefresh::scope($profileId)]=$p?$p->id.':'.$p->revision:'missing';}
                return $keys;
            });
        }
        DB::afterCommit(static fn()=>app(WatchWork::class)->reconcileDefaults($managed,$watch));
    }
}
