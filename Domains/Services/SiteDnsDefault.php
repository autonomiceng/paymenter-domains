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
    public const MISSING='Site DNS default is Missing. New domains have no nameservers to use. Choose a set with Change site DNS default.';
    private static function query(): \Illuminate\Database\Eloquent\Builder
    { return Setting::whereNull('settingable_type')->whereNull('settingable_id')->where('key',self::KEY); }
    public static function profile(bool $lock=false): ?WatchProfile
    {
        $rows=self::query()->when($lock,fn($q)=>$q->lockForUpdate())->get();
        if($rows->count()!==1 || $rows[0]->type!=='string' || $rows[0]->encrypted){return null;}
        $id=$rows[0]->value;
        $p=is_string($id)&&ctype_digit($id)?WatchProfile::whereKey($id)->when($lock,fn($q)=>$q->lockForUpdate())->first():null;
        return ManagedDnsSetup::usable($p)?$p:null;
    }
    public static function uses(int $id): bool
    {
        if($id<1){return false;}$rows=self::query()->get();
        return $rows->contains(fn($row)=>$row->type==='string' && !$row->encrypted && $row->value===(string)$id);
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
                Setting::orderBy('id')->select('id')->lockForUpdate()->get();
                Setting::where('settingable_type',(new \App\Models\Server)->getMorphClass())->where('key','domains_new_domain_dns_template')
                    ->whereIn('settingable_id',\App\Models\Server::where('extension','Domains')->select('id'))->delete();
                if(self::query()->exists()){return false;} // Deployment changes never move a saved choice.
                $names=config('domains.site_dns_default.nameservers');
                if(!is_array($names) || !$names){return false;}
                try{$routing=new RoutingProfile($names,[],[]);}catch(\InvalidArgumentException|\TypeError){return false;}
                try{self::validateRegistrars($routing->nameservers);}catch(\DomainException){return false;}
                // System installation has no staff actor. Quiet maintenance preserves normal staff validation.
                $p=new WatchProfile(['name'=>'Beco DNS','nameservers'=>$routing->nameservers,'website_endpoints'=>[],
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
    private static function authorize(User $actor): void
    {
        $staff=\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::class;
        abort_unless($staff::allows($actor,$staff::POLICY) && $staff::allows($actor,self::MANAGE),403);
    }
    public static function save(User $actor,int $id): void
    {
        self::authorize($actor);
        Cache::lock('domains-site-dns-default',30)->block(5,static function()use($actor,$id):void {
            DB::transaction(static function()use($actor,$id):void {
                self::authorize($actor);Setting::orderBy('id')->select('id')->lockForUpdate()->get();
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
        foreach(Account::orderBy('registrar_key')->distinct()->pluck('registrar_key') as $registrar){
            try{NewDomainDns::validate($registrar,$nameservers);}
            catch(\InvalidArgumentException $e){
                $max=$registrar==='enom'?12:13;$label=$registrar==='enom'?'Enom':'NameSilo';
                $rule=count($nameservers)<2||count($nameservers)>$max?$label.' takes 2 to '.$max.' nameservers.':$label.' needs valid nameserver hostnames.';
                throw new \DomainException($rule.' Update this DNS template or choose another one.',0,$e);
            }
        }
    }
    /** Re-evaluate connected accounts on every read, including accounts added after installation.
     * Keep the saved reference and all domain/watch choices intact; only staff can replace the default.
     */
    public static function refusal(): ?string
    {
        $p=self::profile();
        if($p){$names=$p->nameservers;}
        else{
            $names=config('domains.site_dns_default.nameservers');
            if(!is_array($names) || !$names){return null;}
            try{$names=(new RoutingProfile($names,[],[]))->nameservers;}catch(\InvalidArgumentException|\TypeError){return 'Deployment nameserver hostnames are invalid. Create and turn on a compatible DNS template, then choose it as the site DNS default.';}
        }
        try{self::validateRegistrars($names);}catch(\DomainException $e){return $e->getMessage().($p?'':' Create and turn on a compatible DNS template, then choose it as the site DNS default.');}
        return null;
    }
    private static function write(WatchProfile $p): void
    {
        $rows=self::query()->lockForUpdate()->get();
        if($rows->count()>1){throw new \DomainException('Duplicate site DNS defaults need administrator review.');}
        $s=$rows->first()??new Setting(['key'=>self::KEY,'settingable_type'=>null,'settingable_id'=>null]);
        $s->fill(['type'=>'string','encrypted'=>false,'value'=>(string)$p->id])->save();
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
            $keys=[];$p=$profileId===null?null:WatchProfile::find($profileId);$d=$inherited?self::profile():null;
            if($profileId!==null){$keys[WatchDnsRefresh::scope($profileId)]=$p?$p->id.':'.$p->revision:'missing';}
            if($inherited){$keys[WatchDnsRefresh::DEFAULT]=$d?$d->id.':'.$d->revision:'none';}
            WatchDnsRefresh::request($keys);
        }
        DB::afterCommit(static fn()=>app(WatchWork::class)->reconcileDefaults($managed,$watch));
    }
}
