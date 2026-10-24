<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,SalesPolicy,Tld};
use Paymenter\Extensions\Servers\Domains\Data\{CatalogueAcceptance,Environment,RequestBudget,RequestContext,RequestOrigin};
/** Local reviews and deliberately composed account reads; connection access defaults off. */
final class StaffSettingsWrites
{
    public function __construct(private readonly NativeCatalogue $native,private readonly SellerPolicies $sellers,
        private readonly AccountBindings $bindings,private readonly bool $allowInjectedProbes=false) {}
    public function connectionAvailable(?string $registrar=null): bool
    {return $this->allowInjectedProbes&&($registrar===null||$this->bindings->hasRegistrar($registrar));}
    public function run(StaffSettingsReview $r,#[\SensitiveParameter] array $data,?\DateTimeImmutable $now=null): void
    {
        $clock=app()->bound(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)?app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class):new \Paymenter\Extensions\Servers\Domains\Data\SystemClock;$now??=$clock->now();$r->input($data);
        if($r->action==='test_management'){EnomTestManagement::review($r,$data);return;}
        if($r->action==='test_renewal'){EnomTestRenewal::review($r,$data);return;}
        if($r->action==='renewal_billing'){RenewalBillingSettings::save($r,$data);return;}
        if($r->action==='enroll_sandbox'){
            if(($data['confirmed']??false)!==true){throw new \DomainException('Sandbox contract confirmation required.');}
            if(!is_string($data['label']??null)||array_diff(array_keys($data),['environment','label','api_key','confirmed','reason','review_key'])){throw new \DomainException('Invalid sandbox enrollment input.');}
            if(($data['environment']??null)!=='test'){throw new \DomainException('Sandbox enrollment unavailable.');}
            $this->bindings->enrollSandbox($r->serverId,Environment::Test,
                $data['label'],['api_key'=>$data['api_key']??''],$r);return;
        }
        if($r->action==='alert_review'){
            if(($data['confirmed']??false)!==true){throw new \DomainException('Explicit notification check confirmation required.');}
            $r->local(function()use($r,$data,$now):void {
                $alert=\Illuminate\Support\Facades\DB::table('domains_obligation_alerts')->where('id',(int)($data['alert_id']??0))->lockForUpdate()->first();
                if(!$alert||!in_array($alert->code,['template_failed','reminder_failed'],true)||$alert->resolved_at!==null
                    ||!Domain::whereKey($alert->domain_id)->where('server_id',$r->serverId)->where('authority_generation',$alert->generation)->where('account_fingerprint',Account::where('server_id',$r->serverId)->value('account_fingerprint'))->exists()){
                    throw new \DomainException('Current notification check alert required.');
                }
                \Illuminate\Support\Facades\DB::table('domains_obligation_alerts')->where('id',$alert->id)->update(['resolved_at'=>$now->getTimestamp()]);
            });return;
        }
        if($r->action==='delivery_review'){
            if(($data['confirmed']??false)!==true){throw new \DomainException('Explicit notification confirmation required.');}
            $r->local(function()use($r,$data):void {
                $delivery=\Illuminate\Support\Facades\DB::table('domains_reminder_deliveries')->where('id',(int)($data['delivery_id']??0))->lockForUpdate()->first();
                if(!$delivery||!in_array($delivery->state,['handoff','uncertain'],true)
                    ||!Domain::whereKey($delivery->domain_id)->where('server_id',$r->serverId)->where('account_fingerprint',Account::where('server_id',$r->serverId)->value('account_fingerprint'))->exists()){
                    throw new \DomainException('Current notification claim required.');
                }
                \Illuminate\Support\Facades\DB::table('domains_reminder_deliveries')->where('id',$delivery->id)->update(['state'=>'reviewed']);
            });return;
        }
        if($r->action==='processing'){
            if(($data['confirmed']??false)!==true){throw new \DomainException('Explicit processing confirmation required.');}
            $enabled=($data['enabled']??false)===true;
            if($enabled){app(Installation::class)->verify();} // Schema verification creates reference tables; keep DDL outside financial locks.
            $r->local(function()use($r,$enabled):void {
                if((bool)Account::where('server_id',$r->serverId)->lockForUpdate()->firstOrFail()->processing_enabled===$enabled){throw new \DomainException('Processing state unchanged.');}
                $this->bindings->setProcessing($r->serverId,$enabled,function($server,$account):void {
                    Installation::legacy();
                    $cache=config('cache.stores.'.config('cache.default').'.driver');
                    if(!app(RuntimePorts::class)->accepted($account)||!in_array($cache,['redis','database','memcached'],true)
                        ||!in_array(config('session.driver'),['redis','database','memcached'],true)
                        ||!in_array(config('queue.connections.'.config('queue.default').'.driver'),['redis','database'],true)
                        ||(int)config('queue.connections.'.config('queue.default').'.retry_after',0)<900){
                        throw new \DomainException('Runtime acceptance and shared workers are required.');
                    }
                    $this->bindings->requireReadableLocked($server,$account);
                });
            });return;
        }
        if($r->action==='import'){TestCatalogue::import($r,$data,$this->bindings,$clock);return;}
        if($r->action==='publish'){$this->native->publish($r->tldId??0,$now,$r);return;}
        if($r->action==='seller'){
            $t=Tld::findOrFail($r->tldId);$p=SalesPolicy::where('tld',$t->tld)->firstOrFail();
            $id=($data['seller']??'')===''?null:(int)$data['seller'];
            $this->sellers->change($t->tld,$p->revision,$id,(bool)($data['register']??false),(bool)($data['transfer']??false),$now,$r);return;
        }
        if($r->action==='history'&&($data['accept']??false)===true){
            $a=Account::where('server_id',$r->serverId)->firstOrFail();$binding=CatalogueAcceptances::binding($a);
            if(($data['verified']??false)!==true){throw new \DomainException('Independent history verification required.');}
            $action=\Paymenter\Extensions\Servers\Domains\Data\Action::from((string)($data['history_action']??''));
            $vocabulary=$binding->registrarKey.match($action){\Paymenter\Extensions\Servers\Domains\Data\Action::Register=>'_registration_v1',\Paymenter\Extensions\Servers\Domains\Data\Action::Renew=>'_renewal_v1',\Paymenter\Extensions\Servers\Domains\Data\Action::Transfer=>'_transfer_v1',default=>throw new \DomainException('History action unsupported.')};
            $lag=filter_var($data['lag']??null,FILTER_VALIDATE_INT);$retention=filter_var($data['retention']??null,FILTER_VALIDATE_INT);
            if($lag===false||$retention===false){throw new \DomainException('Integer history limits required.');}
            HistoryAcceptances::save(new \Paymenter\Extensions\Servers\Domains\Data\HistoryAcceptance($binding,(string)($data['timezone']??''),$lag,$retention,$vocabulary),$r);return;
        }
        if(in_array($r->action,['configure','probe'],true)){
            // Production boot explicitly opts into connection reads; processing remains independent.
            $registrar=$r->action==='configure'?(string)($data['registrar']??''):(string)Account::where('server_id',$r->serverId)->value('registrar_key');
            if(!$this->connectionAvailable($registrar)){throw new \DomainException('Connection checks are unavailable.');}
            if($r->action==='configure'){
                $credentials=$data['credentials']??($registrar==='enom'?['uid'=>$data['uid']??'','token'=>$data['token']??'']:['api_key'=>$data['api_key']??'']);
                $this->bindings->configure($r->serverId,$registrar,Environment::from((string)($data['environment']??'')),$credentials,$r);return;
            }
            $r->local(fn()=>null,'connection_requested');$phase='connection_refused';
            try{
                $driver=$this->bindings->reader($r->serverId, true);$binding=$driver->binding();
                $result=BalanceReads::read($driver,new RequestContext($binding,RequestOrigin::Interactive,$now->modify('+10 seconds'),new RequestBudget(1),$clock,Runtime::limiter()),'manual');
                $binding->requireMatch($result->binding);
                \Illuminate\Support\Facades\DB::transaction(function()use($r,$result){$r->lockActor();$r->lockAccounts();$r->check(true);
                    $r->record($result->disposition===\Paymenter\Extensions\Servers\Domains\Data\Disposition::Accepted?'connection_verified':'connection_unverified');});
                if($result->disposition!==\Paymenter\Extensions\Servers\Domains\Data\Disposition::Accepted){throw new \DomainException('Connection not verified.');}$phase='connection_finished';
            }finally{$r->finish($phase);}return;
        }
        $r->local(function()use($r,$data):void{
            $s=Server::whereKey($r->serverId)->lockForUpdate()->firstOrFail();$a=Account::where('server_id',$s->id)->lockForUpdate()->firstOrFail();$t=$r->tldId?Tld::whereKey($r->tldId)->lockForUpdate()->firstOrFail():null;
            if($t&&($t->registrar_key!==$a->registrar_key||$t->account_fingerprint!==$a->account_fingerprint)){throw new \DomainException('TLD binding mismatch.');}
            if($r->action==='history'){
                HistoryAcceptances::revokeLocked(CatalogueAcceptances::binding($a),\Paymenter\Extensions\Servers\Domains\Data\Action::from((string)($data['history_action']??'')));return;
            }
            if(in_array($r->action,['default_rule','override','remove_override'],true)){
                if($r->action==='remove_override'){if(!$t){throw new \DomainException('TLD required.');}$t->update(['use_default_rule'=>true]);return;}
                $rule=PricingRule::validate(['mode'=>$data['mode']??null,'markup'=>$data['markup']??'0','round_up'=>$data['round_up']??'none',
                    'fixed'=>['register'=>$data['fixed_register']??null,'renew'=>$data['fixed_renew']??null,'transfer'=>$data['fixed_transfer']??null]]);
                if($r->action==='default_rule'){(new PricingRules)->saveDefaultRuleLocked((int)$s->id,$rule);}
                else{if(!$t){throw new \DomainException('TLD required.');}$t->update(['use_default_rule'=>false,'pricing_mode'=>$rule['mode'],'markup'=>$rule['markup'],'round_up'=>$rule['round_up'],
                    'fixed_register'=>$rule['fixed']['register'],'fixed_renew'=>$rule['fixed']['renew'],'fixed_transfer'=>$rule['fixed']['transfer']]);}return;
            }
            if($r->action==='tld_settings'){
                if(!$t){throw new \DomainException('TLD required.');}
                $min=filter_var($data['min_years']??null,FILTER_VALIDATE_INT);$max=filter_var($data['max_years']??null,FILTER_VALIDATE_INT);
                if(!$min||!$max||$min<1||$max>10||$min>$max){throw new \DomainException('Valid term bounds required.');}
                if($min<$t->min_years||$max>$t->max_years){
                    $accepted=CatalogueAcceptances::load(CatalogueAcceptances::binding($a),true)->endings[$t->tld]??null;
                    if(($data['verified']??false)!==true||!in_array($min,$accepted['register']??[],true)||!in_array($max,$accepted['register']??[],true)){throw new \DomainException('Explicit verified widening required.');}
                }
                $renew=(bool)($data['renew']??false);
                if(!$renew&&Domain::where(['server_id'=>$s->id,'tld'=>$t->tld,'account_fingerprint'=>$a->account_fingerprint])->whereNotNull('reservation_name')->orderBy('id')->lockForUpdate()->get()->isNotEmpty()){throw new \DomainException('Retained holders require annual renewal support.');}
                $t->fill(['min_years'=>$min,'max_years'=>$max,'enabled'=>(bool)($data['enabled']??false),'renew_enabled'=>$renew]);
                foreach(['register','transfer'] as $action){
                    $enable=(bool)($data[$action]??false);
                    if($enable&&(!$t->{$action.'_enabled'}||(!$t->getOriginal('enabled')&&$t->enabled)||(!$t->getOriginal('renew_enabled')&&$renew))){
                        // Restoring withdrawn sales requires explicit confirmation and current imported acceptance proof.
                        if(($data['verified']??false)!==true){throw new \DomainException('Explicit restoration required.');}
                        PublicationEligibility::require($t,$action,$action==='register'?$min:1,true);
                    }
                    $t->{$action.'_enabled'}=$enable;
                }
                $t->save();TestCatalogue::withdrawCarts(CatalogueAcceptances::binding($a),$t->tld,true);return;
            }
            if($r->action!=='acceptance'){throw new \DomainException('Unsupported settings action.');}
            $binding=CatalogueAcceptances::binding($a);
            try{$old=CatalogueAcceptances::load($binding,true);}catch(\DomainException){$old=null;}
            $ending=(string)($data['ending']??'');\Paymenter\Extensions\Servers\Domains\Data\CanonicalName::parse('example.'.$ending,[$ending]);$endings=$old?->endings??[];
            $registrationRoute=$old?->registrationRoute;$registrationInterval=$old?->registrationIntervalSeconds??0;
            $testRegistration=$binding->registrarKey==='enom'&&$binding->environment===\Paymenter\Extensions\Servers\Domains\Data\Environment::Test
                &&(EnomTestRegistration::deploymentEnabled()||EnomTestTransfer::deploymentEnabled());
            if(!$old&&Domain::where('server_id',$s->id)->whereNotNull('reservation_name')->orderBy('id')->lockForUpdate()->get()->isNotEmpty()){throw new \DomainException('Repair must preserve all holder renewal acceptance.');}
            if(($data['accept']??false)===true){
                // Explicit independent exact-term evidence is required. Annual NameSilo prices prove no multiyear terms.
                if(($data['verified']??false)!==true){throw new \DomainException('Independent term verification required.');}
                if($testRegistration){
                    if(($data['test_registration_accepted']??false)!==true){throw new \DomainException(EnomTestTransfer::deploymentEnabled()?'Review the Enom TEST Purchase / TP_CreateOrder route and request interval before accepting acquisition terms.':'Review the Enom TEST Purchase route and request interval before accepting registration terms.');}
                    $protocol=\Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory::registrationAcceptance($binding);
                    if(!$protocol['batch_route']||$protocol['interval_seconds']<25){throw new \DomainException(EnomTestTransfer::deploymentEnabled()?'Enom TEST acquisition route acceptance is unavailable.':'Enom TEST Purchase route acceptance is unavailable.');}
                    $registrationRoute=$protocol['route'];$registrationInterval=$protocol['interval_seconds'];
                }
                $terms=array_map(static fn($v)=>is_string($v)&&preg_match('/^(?:[1-9]|10)$/D',$v)?(int)$v:$v,$data['terms']??[]);
                $endings[$ending]=['register'=>$terms,'renew'=>[1],'transfer'=>($data['transfer']??false)?[1]:[],
                    'registry'=>(bool)($data['registry']??false),'renewal_safe'=>true];
            }else{
                $holders=Domain::where(['server_id'=>$s->id,'tld'=>$ending,'account_fingerprint'=>$a->account_fingerprint])->whereNotNull('reservation_name')->orderBy('id')->lockForUpdate()->get()->isNotEmpty();
                if($holders){
                    if(!$old||!($endings[$ending]['renewal_safe']??false)||!in_array(1,$endings[$ending]['renew']??[],true)){throw new \DomainException('Holder renewal acceptance cannot be inferred.');}
                    $endings[$ending]['register']=[];$endings[$ending]['transfer']=[];$endings[$ending]['registry']=false;
                }else{unset($endings[$ending]);}
            }
            $revision=$old?->revision??0;
            foreach(\Paymenter\Extensions\Servers\Domains\Models\Price::where('server_id',$s->id)->orderBy('id')->lockForUpdate()->get() as $price){$revision=max($revision,(int)($price->eligibility['catalogue_revision']??0));}
            $next=new CatalogueAcceptance($binding,$revision+1,$endings,$registrationRoute,$registrationInterval);
            CatalogueAcceptances::saveLocked($next);
            // Narrow immediately; widening never imports prices, enables a TLD, or restores withdrawn actions.
            foreach(Tld::where('server_id',$s->id)->orderBy('id')->lockForUpdate()->get() as $row){$accepted=$next->endings[$row->tld]??null;
                $row->register_enabled=$row->register_enabled&&($accepted['registry']??false)&&!empty($accepted['register']);
                $row->transfer_enabled=$row->transfer_enabled&&($accepted['registry']??false)&&!empty($accepted['transfer']);$row->save();}
            TestCatalogue::withdrawCarts($binding);
        });
    }
}
