<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Role,Server,User};
use Illuminate\Support\Facades\{Crypt,DB};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Price,SalesPolicy,Tld};
/** Actor-bound, expiring, single-use review. Settings stay encrypted; audit contains safe state only. */
final class StaffSettingsReview
{
    public const KEY='domains_staff_settings_audit';
    public const KEYS='domains_staff_review_keys';
    public const HISTORY_LIMIT=50;
    public const ACTIONS=['lifecycle'=>'Review lifecycle facts','live_management'=>'Change live DNS, nameservers and contacts','live_renewal'=>'Change live renewals','test_management'=>'Change test DNS, nameservers and contacts','test_renewal'=>'Change test renewals','renewal_billing'=>'Edit renewal invoices','default_rule'=>'Edit default rule','override'=>'Save TLD override','remove_override'=>'Use default rule',
        'tld_settings'=>'Review TLD availability','seller'=>'Select seller','publish'=>'Publish prices','import'=>'Sync TLD prices','acceptance'=>'Edit supported TLDs','history'=>'Review history acceptance','enroll_sandbox'=>'Connect NameSilo test account','configure'=>'Edit API credentials','probe'=>'Test connection','processing'=>'Pause or resume orders','delivery_review'=>'Review notification outcome','alert_review'=>'Review notification check'];
    private array $verified=[];
    private array $before=[];
    private array $input=[];
    public function input(array $data): void
    { $this->input=array_intersect_key($data,array_flip(['ending','history_action','delivery_id','alert_id','mode','markup','round_up','fixed_register','fixed_renew','fixed_transfer','seller','register','transfer','renew','enabled','min_years','max_years','accept','terms','registry','verified','timezone','lag','retention','scheduled','manual','issue_days','due_days',...LifecycleFacts::FIELDS])); }
    public function __construct(public readonly int $actorId,public readonly int $serverId,public readonly ?int $tldId,
        public readonly string $action,private readonly string $ticket,public readonly string $reason) {}
    /** Saving these publishes prices, so they also need the publish permission. */
    public const PUBLISHES=['override','remove_override','default_rule'];
    public static function permission(string $action): string
    {if(!isset(self::ACTIONS[$action])){throw new \DomainException('Unknown settings action.');}return 'admin.domains.settings.'.$action;}
    public static function stamp(int $serverId,?int $tldId,bool $lock=false): string
    {
        $read=static fn($q)=>($lock?$q->lockForUpdate():$q)->get();
        $s=$read(Server::whereKey($serverId))->firstOrFail();
        $accounts=$read(Account::where('server_id',$serverId));$a=$accounts->first();
        $tlds=$read(Tld::where('server_id',$serverId)->when($tldId,fn($q)=>$q->whereKey($tldId))->orderBy('id'));
        $policies=$read(SalesPolicy::whereIn('tld',$tlds->pluck('tld'))->orderBy('id'));
        $prices=$read(Price::where('server_id',$serverId)->when($tldId,fn($q)=>$q->whereIn('tld',$tlds->pluck('tld')))->orderBy('id'));
        $settings=$read(DB::table('settings')->where('settingable_type',$s->getMorphClass())->where('settingable_id',$serverId)
            ->where('key','!=',self::KEY)->whereRaw("`key` NOT LIKE ? ESCAPE '!'",[str_replace('_','!_',self::KEY).'.%'])->whereRaw("`key` NOT LIKE ? ESCAPE '!'",['domains!_reminder!_audit.%'])->orderBy('id'))->map(fn($v)=>(array)$v)->all();
        $raw=static fn($rows)=>$rows->map(fn($v)=>$v->getRawOriginal())->all();
        return hash('sha256',json_encode([$s->getRawOriginal(),$a?->getRawOriginal(),$raw($accounts),$settings,$raw($tlds),$raw($prices),$raw($policies),$tldId],JSON_THROW_ON_ERROR));
    }

    public static function ticket(int $actor,int $server,?int $tld,string $action,string $stamp): string
    {return Crypt::encryptString(json_encode(['actor'=>$actor,'server'=>$server,'tld'=>$tld,'action'=>$action,'stamp'=>$stamp,'key'=>bin2hex(random_bytes(16)),'until'=>time()+900],JSON_THROW_ON_ERROR));}
    public function lockActor(): void
    {
        $u=User::whereKey($this->actorId)->lockForUpdate()->firstOrFail();
        $role=$u->role_id?Role::whereKey($u->role_id)->lockForUpdate()->first():null;$u->setRelation('role',$role);
        foreach(['admin.domains.view','admin.domains.manage-policy',self::permission($this->action),...($this->action==='lifecycle'?['admin.domains.costs']:[]),...(in_array($this->action,self::PUBLISHES,true)?[self::permission('publish')]:[])] as $p){
            if(!$u->hasPermission($p)){throw new \DomainException('Current staff permissions required.');}}
        StaffReason::require($this->reason);
    }
    /** Canonical shared mutex: all servers, then accounts, policies, TLDs, prices, settings. */
    public function lockAccounts(): void
    {
        $ids=Server::where('extension','Domains')->pluck('id')->push($this->serverId)->unique()->sort()->values();
        Server::whereIn('id',$ids)->orderBy('id')->lockForUpdate()->get();
        Account::whereIn('server_id',$ids)->orderBy('server_id')->lockForUpdate()->get();
    }
    public function check(bool $inFlight=false): void
    {
        if(DB::transactionLevel()!==1){throw new \LogicException('Outer staff transaction required.');}
        $server=Server::whereKey($this->serverId)->lockForUpdate()->firstOrFail();if($server->extension!=='Domains'||!$server->enabled){throw new \DomainException('Registrar unavailable.');}
        $endings=Tld::where('server_id',$this->serverId)->when($this->tldId,fn($q)=>$q->whereKey($this->tldId))->orderBy('id')->lockForUpdate()->pluck('tld');
        SalesPolicy::whereIn('tld',$endings)->orderBy('id')->lockForUpdate()->get();
        Tld::where('server_id',$this->serverId)->when($this->tldId,fn($q)=>$q->whereKey($this->tldId))->orderBy('id')->lockForUpdate()->get();
        Price::where('server_id',$this->serverId)->when($this->tldId,fn($q)=>$q->whereIn('tld',$endings))->orderBy('id')->lockForUpdate()->get();
        DB::table('settings')->where('settingable_type',$server->getMorphClass())->where('settingable_id',$this->serverId)
            ->where('key','!=',self::KEY)->whereRaw("`key` NOT LIKE ? ESCAPE '!'",[str_replace('_','!_',self::KEY).'.%'])->whereRaw("`key` NOT LIKE ? ESCAPE '!'",['domains!_reminder!_audit.%'])->orderBy('id')->lockForUpdate()->get();
        if($this->tldId&&!Tld::where(['id'=>$this->tldId,'server_id'=>$this->serverId])->lockForUpdate()->first()){throw new \DomainException('TLD account changed.');}
        try{$t=json_decode(Crypt::decryptString($this->ticket),true,8,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \DomainException('Invalid review key.');}
        if(($t['actor']??null)!==$this->actorId||($t['server']??null)!==$this->serverId||($t['tld']??null)!==$this->tldId||($t['action']??null)!==$this->action
            ||!is_int($t['until']??null)||$t['until']<=time()||!is_string($t['key']??null)||!preg_match('/^[a-f0-9]{32}$/D',$t['key'])||!is_string($t['stamp']??null)
            ||!hash_equals($t['stamp'],self::stamp($this->serverId,$this->tldId,true))){throw new \DomainException('Settings changed. Reload this page.');}
        // The account mutex serializes checks; the primary key also prevents duplicate claims.
        $keys=DB::table(self::KEYS)->where('server_id',$this->serverId)->where('review_key',$t['key']);
        $used=(clone $keys)->lockForUpdate()->exists();
        if($inFlight&&(!$used||$this->verified!==$t)){throw new \DomainException('Verified in-flight review required.');}
        if($used&&!$inFlight){throw new \DomainException('Review key used.');}
        if(!$used){DB::table(self::KEYS)->insert(['server_id'=>$this->serverId,'review_key'=>$t['key'],'expires_at'=>$t['until']]);}
        $this->verified=$t;$this->before=$this->state($this->serverId,$this->tldId);
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
        $s=Server::whereKey($this->serverId)->lockForUpdate()->firstOrFail();
        self::append($s,['key'=>$this->verified['key'],'actor_id'=>$this->actorId,'at'=>gmdate(DATE_ATOM),'action'=>self::ACTIONS[$this->action],
            'reason'=>$this->reason,'phase'=>$phase,...($outcome===null?[]:['outcome'=>$outcome]),'input'=>$this->input,'tld_id'=>$this->action==='default_rule'?null:$this->tldId,'before'=>$this->before,'after'=>$this->state($this->serverId,$this->tldId)]);
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
        $a=Account::where('server_id',$server)->lockForUpdate()->first();$t=$tld?Tld::whereKey($tld)->lockForUpdate()->first():null;
        $rules=new PricingRules;
        try{$rule=$this->action==='default_rule'||!$t?$rules->defaultRule($server,true):$rules->rule($t,true);}catch(\Throwable){$rule='unavailable';}
        try{$acceptance=$a?CatalogueAcceptances::load(CatalogueAcceptances::binding($a),true):null;$accepted=$acceptance?->endings;}catch(\Throwable){$acceptance=null;$accepted='unavailable';}
        return ['server_id'=>$server,'binding'=>$a?->account_fingerprint,'processing'=>$a?->processing_enabled,'tld_id'=>$tld,
            'rule'=>$rule,'tld'=>$t?array_intersect_key($t->getAttributes(),array_flip(['enabled','register_enabled','transfer_enabled','renew_enabled','use_default_rule','min_years','max_years'])):null,'acceptance_revision'=>$acceptance?->revision,'acceptance'=>is_array($accepted)?['count'=>count($accepted),'ending'=>$this->input['ending']??null,'terms'=>$accepted[$this->input['ending']??'']??null]:$accepted,
            'history'=>array_map(function($action)use($a){try{$v=$a?HistoryAcceptances::load(CatalogueAcceptances::binding($a),$action):null;return $v?['timezone'=>$v->timezone,'lag'=>$v->lagSeconds,'retention'=>$v->retentionSeconds,'vocabulary'=>$v->vocabulary]:null;}catch(\Throwable){return 'unavailable';}},[\Paymenter\Extensions\Servers\Domains\Data\Action::Register,\Paymenter\Extensions\Servers\Domains\Data\Action::Renew,\Paymenter\Extensions\Servers\Domains\Data\Action::Transfer]),
            'sellers'=>SalesPolicy::when($t,fn($q)=>$q->where('tld',$t->tld))->limit($t?1:0)->lockForUpdate()->get()->map(fn($p)=>['tld'=>$p->tld,'server'=>$p->server_id,'revision'=>$p->revision,'register'=>$p->register_enabled,'transfer'=>$p->transfer_enabled])->all(),
            'prices'=>Price::where('server_id',$server)->when($t,fn($q)=>$q->where('tld',$t->tld))->orderBy('id')->limit($t?12:0)->lockForUpdate()->get()->map(fn($p)=>['id'=>$p->id,'revision'=>$p->revision,'retail'=>$p->retail,'hold'=>$p->hold_reason])->all(),
            ...($this->action==='lifecycle'&&$t?['lifecycle'=>LifecyclePolicies::read(CatalogueAcceptances::binding($a),$t->tld)]:[]),
            ...($this->action==='renewal_billing'?['renewal_billing'=>RenewalBillingSettings::read($server,true)]:[]),
            'snapshot'=>self::stamp($server,$tld,true)];
    }
    /** Outcome audit grants no authority; retain it even if permissions or the saved state changed during I/O. */
    public function finish(string $phase,?array $outcome=null): void
    {DB::transaction(function()use($phase,$outcome){$this->lockAccounts();$this->record($phase,$outcome);});}
    public function local(\Closure $write,string $phase='completed'): mixed
    {
        if(DB::transactionLevel()!==0){throw new \LogicException('Staff review owns its transaction.');}
        return DB::transaction(function()use($write,$phase){$this->lockActor();$this->lockAccounts();$this->check();$v=$write();$this->record($phase);return $v;});
    }
}
