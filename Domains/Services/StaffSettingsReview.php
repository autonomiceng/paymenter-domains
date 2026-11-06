<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate;
use App\Models\{Role,Server,User};
use Illuminate\Support\Facades\{Crypt,DB};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Price,SalesPolicy,Tld};
/** Actor-bound, expiring, single-use review. Settings stay encrypted; audit contains safe state only. */
final class StaffSettingsReview
{
    public const KEY='domains_staff_settings_audit';
    public const KEYS='domains_staff_review_keys';
    public const HISTORY_LIMIT=50;
    /** Stands in for a server id for business-wide pricing. */
    public const ALL_ACCOUNTS=0;
    public const ACTIONS=['price_freshness'=>'Edit price freshness','call_limits'=>'Edit call limits','new_domain_dns'=>'Change new domain nameservers','late_renewal'=>'Change late renewal','activate'=>'Activate live sales','enom_answer'=>'Enter Enom’s answer','live_transfer'=>'Change live transfers','live_management'=>'Change live DNS, nameservers and contacts','live_renewal'=>'Change live renewals','test_management'=>'Change test DNS, nameservers and contacts','test_renewal'=>'Change test renewals','test_transfer'=>'Change test transfers','renewal_billing'=>'Edit renewal schedule','unpaid_hold'=>'Edit unpaid hold','default_pricing'=>'Edit default pricing','card_fee'=>'Edit card fee','default_rule'=>'Edit default rule','override'=>'Save TLD override','remove_override'=>'Use default rule',

        'tld_settings'=>'Review TLD availability','seller'=>'Change registrar for new orders','publish'=>'Publish prices','import'=>'Sync TLD prices','acceptance'=>'Edit what you sell','history'=>'Check Enom order history','enroll_sandbox'=>'Connect NameSilo test account','configure'=>'Edit API credentials','probe'=>'Test connection','processing'=>'Pause or resume orders','delivery_review'=>'Review notification outcome','alert_review'=>'Review notification check'];
    private array $verified=[];
    private bool $activating=false;
    private array $before=[];
    private array $input=[];
    /** Snapshot after this review's last synced TLD; null until one is written. */
    private ?string $synced=null;
    private ?\Closure $catalogueStamp=null;
    public function input(array $data): void
    { $this->input=array_intersect_key($data,array_flip(['hours','dns_template','registration','renewal','management','operation_id','attempt_hash','outcome','case_reference','ending','endings','changes','rule','rule_changed','counts','rows','only','history_action','delivery_id','alert_id','fee_percent','fee_fixed','retained_annual_retail','mode','markup','round_up','fixed_register','fixed_renew','fixed_transfer','seller','register','transfer','renew','enabled','min_years','max_years','accept','terms','registry','verified','timezone','lag','retention','scheduled','manual','issue_days','due_days'])); }
    /** Actual accepted intent changes, captured under the writer locks. */
    public function salesRows(array $rows, bool $defaultsChanged = false): void
    { $this->input['rows']=$rows; $this->input['changes']=array_fill_keys(array_keys($rows),true); $this->input['rule_changed']=$defaultsChanged; }
    public function __construct(public readonly int $actorId,public readonly int $serverId,public readonly ?int $tldId,
        public readonly string $action,private readonly string $ticket,public readonly string $reason,private readonly ?\Closure $current=null) {}
    /** Syncing prices, saving what you sell, DNS template selection and testing the connection ask no reason; the audit still records who, when and what. */
    public const NO_REASON=['import','acceptance','new_domain_dns','probe'];
    /** Saving these publishes prices, so they also need the publish permission. */
    public const PUBLISHES=['override','remove_override','default_pricing'];
    /** Rights an action needs instead of its own. Staff who could edit the rule and the fee keep Default pricing, with no role changes. */
    public const NEEDS=['default_pricing'=>['default_rule','card_fee']];
    public static function permission(string $action): string
    {if(!isset(self::ACTIONS[$action])){throw new \DomainException('Unknown settings action.');}return in_array($action,['call_limits','price_freshness'],true)?'admin.domains.settings.configure':'admin.domains.settings.'.$action;}
    public static function rights(string $action): array
    {return array_map(self::permission(...),self::NEEDS[$action]??[$action]);}
    /** Every Domains server, on or off. */
    private static function domainServers()
    {return Server::where('extension','Domains')->orderBy('id')->pluck('id');}
    public static function stamp(int $serverId,?int $tldId,bool $lock=false): string
    {
        return \Paymenter\Extensions\Servers\Domains\Catalogue\StaffCommands::stamp($serverId,$tldId);
    }

    public static function ticket(int $actor,int $server,?int $tld,string $action,string $stamp): string
    {return Crypt::encryptString(json_encode(['actor'=>$actor,'server'=>$server,'tld'=>$tld,'action'=>$action,'stamp'=>$stamp,'ready'=>$action==='activate'?EnomLiveActivation::stamp(Account::where('server_id',$server)->sole(),true):null,'key'=>bin2hex(random_bytes(16)),'until'=>time()+900],JSON_THROW_ON_ERROR));}
    public function lockActor(): void
    {
        $u=User::whereKey($this->actorId)->lockForUpdate()->firstOrFail();
        $role=$u->role_id?Role::whereKey($u->role_id)->lockForUpdate()->first():null;$u->setRelation('role',$role);
        foreach(['admin.domains.view','admin.domains.manage-policy',...self::rights($this->action),...($this->action==='activate'?['admin.domains.costs']:[]),...(in_array($this->action,self::PUBLISHES,true)?[self::permission('publish')]:[])] as $p){
            if(!$u->hasPermission($p)){throw new \DomainException('Current staff permissions required.');}}
        if(!in_array($this->action,self::NO_REASON,true)){StaffReason::require($this->reason);}
    }
    /** Canonical shared mutex: all servers, then accounts, policies, TLDs, prices, settings. */
    public function lockAccounts(): void
    {
        $ids=self::domainServers()->when($this->serverId!==self::ALL_ACCOUNTS,fn($all)=>$all->push($this->serverId))->unique()->sort()->values();
        Server::whereIn('id',$ids)->orderBy('id')->lockForUpdate()->get();
        Account::whereIn('server_id',$ids)->orderBy('server_id')->lockForUpdate()->get();
    }
    public function check(bool $inFlight=false,?\Closure $catalogueStamp=null): void
    {
        if(DB::transactionLevel()!==1){throw new \LogicException('Outer staff transaction required.');}
        if($catalogueStamp===null){if($this->current!==null){($this->current)();}else{
            $state=UpgradeGate::routeState();
            // A replaced package can still encounter legacy state after a failed native upgrade hook.
            $disable=match($this->action){
                'processing'=>($this->input['enabled']??null)===false,
                'renewal_billing'=>($this->input['scheduled']??null)===false&&($this->input['manual']??null)===false,
                default=>false,
            };
            if($state['state']==='legacy'&&$disable){UpgradeGate::legacy($state['generation']);}
            else{
                if($state['state']!=='active'){throw new \DomainException(OfferUpgrade::MESSAGE);}
                UpgradeGate::active($state['generation']);
            }
        }}
        $this->catalogueStamp=$catalogueStamp;
        if($this->serverId===self::ALL_ACCOUNTS){if(self::domainServers()->isEmpty()){throw new \DomainException('Registrar unavailable.');}}
        elseif($catalogueStamp===null){$this->lockOne();}
        else{$server=Server::whereKey($this->serverId)->lockForUpdate()->firstOrFail();if($server->extension!=='Domains'||!$server->enabled){throw new \DomainException('Registrar unavailable.');}}
        if($catalogueStamp===null){
        if($this->tldId&&!Tld::where(['id'=>$this->tldId,'server_id'=>$this->serverId])->lockForUpdate()->first()){throw new \DomainException('TLD account changed.');}
        }
        try{$t=json_decode(Crypt::decryptString($this->ticket),true,8,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \DomainException('Invalid review key.');}
        if(($t['actor']??null)!==$this->actorId||($t['server']??null)!==$this->serverId||($t['tld']??null)!==$this->tldId||($t['action']??null)!==$this->action
            ||!is_int($t['until']??null)||$t['until']<=time()||!is_string($t['key']??null)||!preg_match('/^[a-f0-9]{32}$/D',$t['key'])||!is_string($t['stamp']??null)
            ||($this->action==='activate'&&EnomLiveActivation::enabling(Account::where('server_id',$this->serverId)->sole(),$this->input)&&($t['ready']??null)!==EnomLiveActivation::stamp(Account::where('server_id',$this->serverId)->sole(),true))
            ||!hash_equals($this->synced??$t['stamp'],($catalogueStamp!==null?$catalogueStamp():self::stamp($this->serverId,$this->tldId,true)))){throw new \DomainException('Settings changed. Reload this page.');}
        // The account mutex serializes checks; the primary key also prevents duplicate claims.
        $keys=DB::table(self::KEYS)->where('server_id',$this->serverId)->where('review_key',$t['key']);
        $used=(clone $keys)->lockForUpdate()->exists();
        if($inFlight&&(!$used||$this->verified!==$t)){throw new \DomainException('Verified in-flight review required.');}
        if($used&&!$inFlight){throw new \DomainException('Review key used.');}
        if(!$used){DB::table(self::KEYS)->insert(['server_id'=>$this->serverId,'review_key'=>$t['key'],'expires_at'=>$t['until']]);}
        $this->verified=$t;$this->before=$catalogueStamp!==null?['snapshot'=>$catalogueStamp()]:$this->state($this->serverId,$this->tldId);
    }
    /** One account's rows, in mutex order. */
    private function lockOne(): void
    {
        $server=Server::whereKey($this->serverId)->lockForUpdate()->firstOrFail();if($server->extension!=='Domains'||!$server->enabled){throw new \DomainException('Registrar unavailable.');}
        $endings=Tld::where('server_id',$this->serverId)->when($this->tldId,fn($q)=>$q->whereKey($this->tldId))->orderBy('id')->lockForUpdate()->pluck('tld');
        SalesPolicy::whereIn('tld',$endings)->orderBy('id')->lockForUpdate()->get();
        Tld::where('server_id',$this->serverId)->when($this->tldId,fn($q)=>$q->whereKey($this->tldId))->orderBy('id')->lockForUpdate()->get();
        Price::where('server_id',$this->serverId)->when($this->tldId,fn($q)=>$q->whereIn('tld',$endings))->orderBy('id')->lockForUpdate()->get();
        DB::table('settings')->where('settingable_type',$server->getMorphClass())->where('settingable_id',$this->serverId)
            ->where('key','!=',self::KEY)->whereRaw("`key` NOT LIKE ? ESCAPE '!'",[str_replace('_','!_',self::KEY).'.%'])->whereRaw("`key` NOT LIKE ? ESCAPE '!'",['domains!_reminder!_audit.%'])->orderBy('id')->lockForUpdate()->get();
    }
    /** Recent display only: current batch and one archive, at most 50 entries. Never replay authority. */
    public static function historyRows(Server $s,bool $lock=false): array
    {
        $query=$s->settings()->where('key',self::KEY);if($lock){$query->lockForUpdate();}
        $current=$query->first();
        $archive=AuditArchives::latest((int)$s->id,'staff_settings',$lock);
        return array_slice([...($archive!==null?self::decode($archive):[]),...($current?self::batch($current):[])],-self::HISTORY_LIMIT);
    }
    private static function batch($setting): array
    {
        if(!$setting->encrypted){throw new \DomainException('Audit unavailable. Changes held.');}
        return self::decode($setting->value);
    }
    private static function decode(string $json): array
    {
        try{$rows=json_decode($json,true,16,JSON_THROW_ON_ERROR);
            if(!is_array($rows)||!array_is_list($rows)){throw new \DomainException;}
            foreach($rows as $r){if(!is_array($r)||!is_string($r['key']??null)){throw new \DomainException;}}
            return $rows;
        }catch(\Throwable){throw new \DomainException('Audit unavailable. Changes held.');}
    }
    public function record(string $phase='completed',?array $outcome=null): void
    {
        if(!$this->verified||DB::transactionLevel()!==1){throw new \LogicException('Locked review required.');}
        $entry=['key'=>$this->verified['key'],'actor_id'=>$this->actorId,'at'=>gmdate(DATE_ATOM),'action'=>self::ACTIONS[$this->action],
            'reason'=>$this->reason,'phase'=>$phase,...($outcome===null?[]:['outcome'=>$outcome]),'input'=>$this->input,'tld_id'=>$this->tldId,'before'=>$this->before,'after'=>$this->catalogueStamp!==null?['snapshot'=>($this->catalogueStamp)()]:$this->state($this->serverId,$this->tldId)];
        // History is read per account, so a business-wide change is written to each one, disabled accounts too.
        foreach($this->serverId===self::ALL_ACCOUNTS?self::domainServers():[$this->serverId] as $id){self::append(Server::whereKey($id)->lockForUpdate()->firstOrFail(),$entry);}
        // One sync writes TLD after TLD under one review: the next TLD checks against this run's own last write.
        if($phase==='catalogue_imported'){$this->synced=self::stamp($this->serverId,$this->tldId,true);}
    }
    /** Caller holds the server/account mutex; audit and the local change commit together. */
    public static function append(Server $s,array $entry): void
    {
        if(DB::transactionLevel()!==1){throw new \LogicException('Outer audit transaction required.');}
        $setting=$s->settings()->where('key',self::KEY)->lockForUpdate()->first()??$s->settings()->make(['key'=>self::KEY]);
        $rows=$setting->exists?self::batch($setting):[];
        $rows[]=$entry;
        $json=json_encode($rows,JSON_THROW_ON_ERROR);
        if(strlen(Crypt::encryptString($json))>64000){
            $last=array_pop($rows);if(!$rows){throw new \DomainException('Audit entry too large. Changes held.');}
            AuditArchives::append((int)$s->id,'staff_settings',json_encode($rows,JSON_THROW_ON_ERROR));
            $json=json_encode([$last],JSON_THROW_ON_ERROR);if(strlen(Crypt::encryptString($json))>64000){throw new \DomainException('Audit entry too large. Changes held.');}
        }
        $setting->encrypted=true;$setting->type='string';$setting->value=$json;$setting->save();
    }
    private function state(int $server,?int $tld): array
    {
        if($server===self::ALL_ACCOUNTS){
            try{$rule=(new PricingRules)->defaultRule(true);}catch(\Throwable){$rule='unavailable';}
            try{$fee=CardFee::read(true);}catch(\Throwable){$fee='unavailable';}
            return ['scope'=>'all_accounts','rule'=>$rule,'card_fee'=>$fee,'snapshot'=>self::stamp($server,null,true)];
        }
        $a=Account::where('server_id',$server)->lockForUpdate()->first();$t=$tld?Tld::whereKey($tld)->lockForUpdate()->first():null;
        $rules=new PricingRules;
        try{$rule=$t?$rules->rule($t,true):$rules->defaultRule(true);}catch(\Throwable){$rule='unavailable';}
        $live=null;
        if($a?->environment==='live'){
            try{$b=AccountBindings::binding($a);$live=['current'=>EnomLiveActivation::current($b),'registration'=>EnomLiveActivation::registrationAccepted($b),'renewal'=>EnomTestRenewal::accepted($b),'management'=>EnomTestManagement::accepted($b),'transfer'=>EnomTestTransfer::accepted($b)];}
            catch(\Throwable){$live='unavailable';}
        }
        return [...($this->action==='new_domain_dns'?['dns_template'=>NewDomainDns::profile($server)?->id]:[]),'server_id'=>$server,'binding'=>$a?->account_fingerprint,'processing'=>$a?->processing_enabled,'tld_id'=>$tld,
            'rule'=>$rule,'tld'=>$t?array_intersect_key($t->getAttributes(),array_flip(['enabled','register_enabled','transfer_enabled','renew_enabled','use_default_rule','min_years','max_years'])):null,'offer'=>$t?['revision'=>$t->catalogue_revision,'intent'=>$t->catalogue_intent]:null,
            'history'=>array_map(function($action)use($a){try{$v=$a?HistoryAcceptances::load(AccountBindings::binding($a),$action):null;return $v?['timezone'=>$v->timezone,'lag'=>$v->lagSeconds,'retention'=>$v->retentionSeconds,'vocabulary'=>$v->vocabulary]:null;}catch(\Throwable){return 'unavailable';}},[\Paymenter\Extensions\Servers\Domains\Data\Action::Register,\Paymenter\Extensions\Servers\Domains\Data\Action::Renew,\Paymenter\Extensions\Servers\Domains\Data\Action::Transfer]),
            'sellers'=>SalesPolicy::when($t,fn($q)=>$q->where('tld',$t->tld))->limit($t?1:0)->lockForUpdate()->get()->map(fn($p)=>['tld'=>$p->tld,'server'=>$p->server_id,'revision'=>$p->revision,'register'=>$p->register_enabled,'transfer'=>$p->transfer_enabled])->all(),
            'prices'=>Price::where('server_id',$server)->when($t,fn($q)=>$q->where('tld',$t->tld))->orderBy('id')->limit($t?12:0)->lockForUpdate()->get()->map(fn($p)=>['id'=>$p->id,'revision'=>$p->revision,'retail'=>$p->retail,'hold'=>$p->hold_reason])->all(),
            ...(in_array($this->action,['activate','renewal_billing','test_renewal','live_renewal'],true)?['renewal_billing'=>RenewalBillingSettings::read($server,true)]:[]),
            ...($this->action==='price_freshness'?['price_freshness'=>\Paymenter\Extensions\Servers\Domains\Data\CatalogueFreshness::forAccount($server)->hours]:[]),
            ...($this->action==='call_limits'?['call_limits'=>$a?ApiCallLimits::forAccount($a):null]:[]),
            ...($this->action==='unpaid_hold'?['unpaid_hold'=>UnpaidHoldSetting::minutes($server)]:[]),
            'live_activation'=>$live,
            ...($this->action==='late_renewal'?['late_renewal'=>$a?EnomLateRenewal::stored(AccountBindings::binding($a)):false]:[]),
            'snapshot'=>self::stamp($server,$tld,true)];
    }
    /** Outcome audit grants no authority; retain it even if permissions or the saved state changed during I/O. */
    public function finish(string $phase,?array $outcome=null): void
    {DB::transaction(function()use($phase,$outcome){$this->lockAccounts();$this->record($phase,$outcome);});}
    public function local(\Closure $write,string $phase='completed'): mixed
    {
        // Activate composes the existing feature writers in this same reviewed transaction and audit.
        if($this->activating&&DB::transactionLevel()===1){return $write();}
        if(DB::transactionLevel()!==0){throw new \LogicException('Staff review owns its transaction.');}
        return DB::transaction(function()use($write,$phase){$this->lockActor();
            // Template edits lock the profile before its account settings; keep the same order before stamp().
            if($this->action==='new_domain_dns'){\Paymenter\Extensions\Servers\Domains\Models\WatchProfile::whereKey($this->input['dns_template']??0)->lockForUpdate()->first();}
            $this->lockAccounts();$this->check();
            $this->activating=$this->action==='activate';
            try{$v=$write();$this->record($phase);return $v;}finally{$this->activating=false;}
        });
    }
}
