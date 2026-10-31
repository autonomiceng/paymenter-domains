<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,SalesPolicy,Tld};
use Paymenter\Extensions\Servers\Domains\Data\{CatalogueAcceptance,Environment,RequestBudget,RequestContext,RequestOrigin,SystemClock};
/** Local reviews and deliberately composed account reads; connection access defaults off. */
final class StaffSettingsWrites
{
    public function __construct(private readonly NativeCatalogue $native,private readonly SellerPolicies $sellers,
        private readonly AccountBindings $bindings,private readonly bool $allowInjectedProbes=false) {}
    public function connectionAvailable(?string $registrar=null): bool
    {return $this->allowInjectedProbes&&($registrar===null||$this->bindings->hasRegistrar($registrar));}
    /** Returns the publish summary for a default pricing change, otherwise null. */
    public function run(StaffSettingsReview $r,#[\SensitiveParameter] array $data,?\DateTimeImmutable $now=null): ?array
    {
        $clock=app()->bound(Clock::class)?app(Clock::class):new SystemClock;
        if($now!==null){
            $clock=new class($now) implements Clock {
                private readonly int $started;
                public function __construct(private readonly \DateTimeImmutable $instant){$this->started=hrtime(true);}
                public function now(): \DateTimeImmutable
                {return $this->instant->modify('+'.intdiv(hrtime(true)-$this->started,1000).' microseconds');}
            };
        }
        $now??=$clock->now();$r->input($data);
        // Live turns on through Activate only, whatever a caller supplies. It can always turn off.
        if(in_array($r->action,['live_renewal','live_management','live_transfer'],true)&&($data['accept']??false)===true){throw new \DomainException('Turns on when you activate live sales.');}
        if(in_array($r->action,['test_management','live_management'],true)){EnomTestManagement::review($r,$data);return null;}
        if(in_array($r->action,['test_renewal','live_renewal'],true)){EnomTestRenewal::review($r,$data);return null;}
        if(in_array($r->action,['test_transfer','live_transfer'],true)){EnomTestTransfer::review($r,$data);return null;}
        if($r->action==='renewal_billing'){RenewalBillingSettings::save($r,$data);return null;}
        if($r->action==='unpaid_hold'){UnpaidHoldSetting::save($r,$data);return null;}
        if($r->action==='enroll_sandbox'){
            if(($data['confirmed']??false)!==true){throw new \DomainException('Sandbox contract confirmation required.');}
            if(!is_string($data['label']??null)||array_diff(array_keys($data),['environment','label','api_key','confirmed','reason','review_key'])){throw new \DomainException('Invalid sandbox enrollment input.');}
            if(($data['environment']??null)!=='test'){throw new \DomainException('Sandbox enrollment unavailable.');}
            $this->bindings->enrollSandbox($r->serverId,Environment::Test,
                $data['label'],['api_key'=>$data['api_key']??''],$r);return null;
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
            });return null;
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
                if(!\Illuminate\Support\Facades\DB::table('domains_reminder_deliveries')->where('domain_id',$delivery->domain_id)->whereIn('state',['handoff','uncertain'])->exists()){
                    \Illuminate\Support\Facades\DB::table('domains_obligation_alerts')->where('domain_id',$delivery->domain_id)->where('code','delivery_uncertain')->whereNull('resolved_at')->update(['resolved_at'=>time()]);
                }
            });return null;
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
            });return null;
        }
        if($r->action==='import'){return TestCatalogue::import($r,$data,$this->bindings,$clock);}
        if($r->action==='publish'){$this->native->publish($r->tldId??0,$now,$r);return null;}
        if(in_array($r->action,['override','remove_override'],true)){
            // Saving prices publishes them in the same transaction; missing or stale costs refuse the whole change.
            $this->native->publish($r->tldId??0,$now,$r,function(Tld $t)use($r,$data,$now):void{
                $rule=$r->action==='override'?self::rule($data,$r->serverId):null;
                $t->update($rule===null?['use_default_rule'=>true]:['use_default_rule'=>false,'pricing_mode'=>$rule['mode'],'markup'=>$rule['markup'],'round_up'=>$rule['round_up'],
                    'fixed_register'=>$rule['fixed']['register'],'fixed_renew'=>$rule['fixed']['renew'],'fixed_transfer'=>$rule['fixed']['transfer']]);
                PriceCatalogue::requireCosts($t,$now);
            });return null;
        }
        if($r->action==='seller'){
            $t=Tld::findOrFail($r->tldId);$p=SalesPolicy::where('tld',$t->tld)->firstOrFail();
            $id=($data['seller']??'')===''?null:(int)$data['seller'];
            $this->sellers->change($t->tld,$p->revision,$id,(bool)($data['register']??false),(bool)($data['transfer']??false),$now,$r);return null;
        }
        if($r->action==='history'&&($data['accept']??false)===true){
            $a=Account::where('server_id',$r->serverId)->firstOrFail();$binding=CatalogueAcceptances::binding($a);
            if(($data['verified']??false)!==true){throw new \DomainException('Independent history verification required.');}
            $action=\Paymenter\Extensions\Servers\Domains\Data\Action::from((string)($data['history_action']??''));
            $vocabulary=$binding->registrarKey.match($action){\Paymenter\Extensions\Servers\Domains\Data\Action::Register=>'_registration_v1',\Paymenter\Extensions\Servers\Domains\Data\Action::Renew=>'_renewal_v1',\Paymenter\Extensions\Servers\Domains\Data\Action::Transfer=>'_transfer_v1',default=>throw new \DomainException('History action unsupported.')};
            $lag=filter_var($data['lag']??null,FILTER_VALIDATE_INT);$retention=filter_var($data['retention']??null,FILTER_VALIDATE_INT);
            if($lag===false||$retention===false){throw new \DomainException('Integer history limits required.');}
            HistoryAcceptances::save(new \Paymenter\Extensions\Servers\Domains\Data\HistoryAcceptance($binding,(string)($data['timezone']??''),$lag,$retention,$vocabulary),$r);return null;
        }
        if(in_array($r->action,['configure','probe'],true)){
            // Production boot explicitly opts into connection reads; processing remains independent.
            $registrar=$r->action==='configure'?(string)($data['registrar']??''):(string)Account::where('server_id',$r->serverId)->value('registrar_key');
            if(!$this->connectionAvailable($registrar)){throw new \DomainException('Connection checks are unavailable.');}
            if($r->action==='configure'){
                $credentials=$data['credentials']??($registrar==='enom'?['uid'=>$data['uid']??'','token'=>$data['token']??'']:['api_key'=>$data['api_key']??'']);
                $this->bindings->configure($r->serverId,$registrar,Environment::from((string)($data['environment']??'')),$credentials,$r);return null;
            }
            $r->local(fn()=>null,'connection_requested');$phase='connection_refused';
            try{
                $driver=$this->bindings->reader($r->serverId, true);$binding=$driver->binding();
                $result=BalanceReads::read($driver,new RequestContext($binding,RequestOrigin::Interactive,$now->modify('+10 seconds'),new RequestBudget(1),$clock,Runtime::limiter()),'manual');
                $binding->requireMatch($result->binding);
                \Illuminate\Support\Facades\DB::transaction(function()use($r,$result){$r->lockActor();$r->lockAccounts();$r->check(true);
                    $r->record($result->disposition===\Paymenter\Extensions\Servers\Domains\Data\Disposition::Accepted?'connection_verified':'connection_unverified');});
                if($result->disposition!==\Paymenter\Extensions\Servers\Domains\Data\Disposition::Accepted){throw new \DomainException('Connection not verified.');}$phase='connection_finished';
            }finally{$r->finish($phase);}return null;
        }
        $r->local(function()use($r,$data,$now):void{
            $s=Server::whereKey($r->serverId)->lockForUpdate()->firstOrFail();$a=Account::where('server_id',$s->id)->lockForUpdate()->firstOrFail();$t=$r->tldId?Tld::whereKey($r->tldId)->lockForUpdate()->firstOrFail():null;
            if($t&&($t->registrar_key!==$a->registrar_key||$t->account_fingerprint!==$a->account_fingerprint)){throw new \DomainException('TLD binding mismatch.');}
            if($r->action==='history'){
                HistoryAcceptances::revokeLocked(CatalogueAcceptances::binding($a),\Paymenter\Extensions\Servers\Domains\Data\Action::from((string)($data['history_action']??'')));return;
            }
            if($r->action==='card_fee'){
                $fee=CardFee::validate(['percent'=>$data['fee_percent']??'', 'fixed'=>$data['fee_fixed']??'']);
                // Validate the effective rules against the replacement fee; any refusal rolls it back.
                CardFee::saveLocked((int)$s->id,$fee);
                $rules=new PricingRules;$default=$rules->defaultRule((int)$s->id,true);
                if($default['mode']==='markup_fee_percent'){PricingRule::validate([...$default,'card_fee'=>$fee]);}
                foreach(Tld::where(['server_id'=>$s->id,'account_fingerprint'=>$a->account_fingerprint,'registrar_key'=>$a->registrar_key,'use_default_rule'=>false,'pricing_mode'=>'markup_fee_percent'])->orderBy('id')->lockForUpdate()->get() as $own){
                    PricingRule::validate([...$rules->rule($own,true),'card_fee'=>$fee]);
                }
                return;
            }
            if($r->action==='default_rule'){(new PricingRules)->saveDefaultRuleLocked((int)$s->id,self::rule($data,$r->serverId));return;}
            if($r->action==='tld_settings'){
                if(!$t){throw new \DomainException('TLD required.');}
                $min=filter_var($data['min_years']??null,FILTER_VALIDATE_INT);$max=filter_var($data['max_years']??null,FILTER_VALIDATE_INT);
                if(!$min||!$max||$min<1||$max>10||$min>$max){throw new \DomainException('Valid term bounds required.');}
                // The transaction rolls back, so a save with no change leaves no history and no used review.
                if($min===(int)$t->min_years&&$max===(int)$t->max_years&&(bool)($data['enabled']??false)===(bool)$t->enabled&&(bool)($data['renew']??false)===(bool)$t->renew_enabled
                    &&(bool)($data['register']??false)===(bool)$t->register_enabled&&(bool)($data['transfer']??false)===(bool)$t->transfer_enabled){throw new \DomainException('Terms unchanged.');}
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
                        $prices=new PriceCatalogue(new PricingRules);
                        $prices->current((int)$t->server_id,$t->account_fingerprint,$t->tld,'renew',1,'USD',$now,true,$t);
                        PublicationEligibility::firstEligibleTerm($t,$action,static function(int $term)use($t,$action,$prices,$now):void{
                            PublicationEligibility::require($t,$action,$term,true);
                            $prices->current((int)$t->server_id,$t->account_fingerprint,$t->tld,$action,$term,'USD',$now,true,$t);
                        });
                    }
                    $t->{$action.'_enabled'}=$enable;
                }
                $t->save();TestCatalogue::withdrawCarts(CatalogueAcceptances::binding($a),$t->tld,true);return;
            }
            if($r->action!=='acceptance'){throw new \DomainException('Unsupported settings action.');}
            $binding=CatalogueAcceptances::binding($a);
            try{$old=CatalogueAcceptances::load($binding,true);}catch(\DomainException){$old=null;}
            $changes=$data['changes']??null;
            if(is_array($data['sell']??null)){
                [$changes,$audit]=SellRule::applyLocked($a,$binding,$old,$data['sell']);$r->input($audit);
                if($changes===[]){return;}
            }
            if(!is_array($changes)||$changes===[]){throw new \DomainException('Nothing to save.');}
            $endings=$old?->endings??[];
            $registrationRoute=$old?->registrationRoute;$registrationInterval=$old?->registrationIntervalSeconds??0;
            if(!$old&&Domain::where('server_id',$s->id)->whereNotNull('reservation_name')->orderBy('id')->lockForUpdate()->get()->isNotEmpty()){throw new \DomainException('Repair must preserve all holder renewal acceptance.');}
            foreach($changes as $ending=>$change){
                $ending=(string)$ending;\Paymenter\Extensions\Servers\Domains\Data\CanonicalName::parse('example.'.$ending,[$ending]);
                if($change!==null){
                    if(EndingChoices::hidden($ending)){throw new \DomainException('This TLD is hidden.');}
                    $terms=array_map(static fn($v)=>is_string($v)&&preg_match('/^(?:[1-9]|10)$/D',$v)?(int)$v:$v,$change['terms']??[]);
                    if($terms===[]){throw new \DomainException('Choose at least one year.');}
                    // Annual NameSilo prices prove no multiyear terms; CatalogueAcceptance refuses them.
                    sort($terms);$endings[$ending]=['register'=>array_values(array_unique($terms)),'renew'=>[1],'transfer'=>($change['transfer']??false)?[1]:[],'registry'=>true,'renewal_safe'=>true];
                    continue;
                }
                $holders=Domain::where(['server_id'=>$s->id,'tld'=>$ending,'account_fingerprint'=>$a->account_fingerprint])->whereNotNull('reservation_name')->orderBy('id')->lockForUpdate()->get()->isNotEmpty();
                if($holders){
                    if(!$old||!($endings[$ending]['renewal_safe']??false)||!in_array(1,$endings[$ending]['renew']??[],true)){throw new \DomainException('Holder renewal acceptance cannot be inferred.');}
                    $endings[$ending]['register']=[];$endings[$ending]['transfer']=[];$endings[$ending]['registry']=false;
                }else{unset($endings[$ending]);}
            }
            // Selling needs a batch route and our request spacing. Both are fixed by the deployment, so record them rather than ask staff.
            $testRegistration=$binding->registrarKey==='enom'&&$binding->environment===\Paymenter\Extensions\Servers\Domains\Data\Environment::Test
                &&(EnomTestRegistration::deploymentEnabled()||EnomTestTransfer::deploymentEnabled());
            if(array_filter($changes)!==[]&&(EnomLive::enabled($binding,'registration')||EnomLive::enabled($binding,'transfer')||$testRegistration)){
                $protocol=\Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory::registrationAcceptance($binding);
                if(!$protocol['batch_route']||$protocol['interval_seconds']<25){throw new \DomainException(match(true){!$testRegistration=>'Live Enom Purchase route acceptance is unavailable.',EnomTestTransfer::deploymentEnabled()=>'Enom TEST acquisition route acceptance is unavailable.',default=>'Enom TEST Purchase route acceptance is unavailable.'});}
                $registrationRoute=$protocol['route'];$registrationInterval=$protocol['interval_seconds'];
            }
            $revision=$old?->revision??0;$prices=\Paymenter\Extensions\Servers\Domains\Models\Price::where('server_id',$s->id)->orderBy('id')->lockForUpdate()->get();
            foreach($prices as $price){$revision=max($revision,(int)($price->eligibility['catalogue_revision']??0));}
            $next=new CatalogueAcceptance($binding,$revision+1,$endings,$registrationRoute,$registrationInterval);
            CatalogueAcceptances::saveLocked($next);
            // A saved list only moves the revision. Terms kept in both lists keep their price evidence, so one change never holds other endings.
            foreach($prices as $price){$proof=$price->eligibility;
                if($old===null||($proof['catalogue_revision']??null)!==$old->revision||!in_array($price->term,$old->endings[$price->tld][$price->action]??[],true)||!in_array($price->term,$next->endings[$price->tld][$price->action]??[],true)){continue;}
                $price->fill(['eligibility'=>[...$proof,'catalogue_revision'=>$next->revision],'revision'=>$price->revision+1])->save();}
            // Narrow immediately; widening never imports prices, enables a TLD, or restores withdrawn actions.
            foreach(Tld::where('server_id',$s->id)->orderBy('id')->lockForUpdate()->get() as $row){$accepted=$next->endings[$row->tld]??null;
                $row->register_enabled=$row->register_enabled&&($accepted['registry']??false)&&!empty($accepted['register']);
                $row->transfer_enabled=$row->transfer_enabled&&($accepted['registry']??false)&&!empty($accepted['transfer']);
                if(array_key_exists($row->tld,$changes)&&!empty($accepted['register'])){$row->min_years=min($accepted['register']);$row->max_years=max($accepted['register']);}
                $row->save();}
            foreach(array_keys($changes) as $ending){TestCatalogue::withdrawCarts($binding,(string)$ending,true);}
        });
        if(!in_array($r->action,['default_rule','card_fee'],true)){return null;}
        // Publish affected TLDs independently; one failure does not stop the rest.
        // A TLD that switched to its own prices meanwhile is left alone.
        $summary=['published'=>0,'failed'=>[]];$a=Account::where('server_id',$r->serverId)->firstOrFail();
        $targets=Tld::where(['server_id'=>$r->serverId,'registrar_key'=>$a->registrar_key,'account_fingerprint'=>$a->account_fingerprint]);
        if($r->action==='default_rule'){$targets->where('use_default_rule',true);}
        else{
            try{$defaultFee=(new PricingRules)->defaultRule($r->serverId)['mode']==='markup_fee_percent';}
            catch(\Throwable){$defaultFee=true;}
            $targets->where(function($q)use($defaultFee):void{
                $q->where(fn($own)=>$own->where('use_default_rule',false)->where('pricing_mode','markup_fee_percent'));
                if($defaultFee){$q->orWhere('use_default_rule',true);}
            });
        }
        foreach($targets->orderBy('tld')->get() as $tld){
            $follows=true;
            try{
                $this->native->publish((int)$tld->id,$now,null,function(Tld $t)use($r,$now,&$follows):bool{
                    if($r->action==='default_rule'?!$t->use_default_rule:(new PricingRules)->rule($t,true)['mode']!=='markup_fee_percent'){return $follows=false;}
                    PriceCatalogue::requireCosts($t,$now);return true;});
                if($follows){$summary['published']++;}
            }
            catch(\Throwable $error){
                $summary['failed'][]='.'.$tld->tld;
                if(!$error instanceof \DomainException){\Illuminate\Support\Facades\Log::warning('Domains default pricing publish failed.',['tld'=>$tld->tld,'exception_class'=>$error::class]);}
            }
        }
        // Each publication has committed independently; losing this audit cannot undo it.
        try{$r->finish('publication_completed',$summary);}
        catch(\Throwable $error){
            $summary['audit_failed']=true;
            \Illuminate\Support\Facades\Log::warning('Domains pricing publication outcome audit failed.',['action'=>$r->action,'exception_class'=>$error::class]);
        }
        return $summary;
    }

    private static function rule(array $data,int $serverId): array
    {
        return PricingRule::validate(['mode'=>$data['mode']??null,'markup'=>$data['markup']??'0','round_up'=>$data['round_up']??'none',
            'fixed'=>['register'=>$data['fixed_register']??null,'renew'=>$data['fixed_renew']??null,'transfer'=>$data['fixed_transfer']??null],
            ...(($data['mode']??null)==='markup_fee_percent'?['card_fee'=>CardFee::read($serverId,true)]:[])]);
    }
}
