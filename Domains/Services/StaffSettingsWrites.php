<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Tld};
use Paymenter\Extensions\Servers\Domains\Data\{Environment,RequestBudget,RequestContext,RequestOrigin,SystemClock};
/** Local reviews and deliberately composed account reads; connection access defaults off. */
class StaffSettingsWrites
{
    public function __construct(private readonly NativeCatalogue $native,private readonly SellerPolicies $sellers,
        private readonly AccountBindings $bindings,private readonly bool $allowInjectedProbes=false) {}
    protected function catalogue(): ?\Paymenter\Extensions\Servers\Domains\Catalogue\StaffCommands
    {return \Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::resolve(\Paymenter\Extensions\Servers\Domains\Catalogue\StaffCommands::class);}
    public function connectionAvailable(?string $registrar=null): bool
    {return $this->allowInjectedProbes&&($registrar===null||$this->bindings->hasRegistrar($registrar));}
    /** Returns the publish summary for a default pricing change, otherwise null. */
    public function run(StaffSettingsReview $r,#[\SensitiveParameter] array $data,?\DateTimeImmutable $now=null): ?array
    {
        if(in_array($r->action,\Paymenter\Extensions\Servers\Domains\Catalogue\StaffCommands::ACTIONS,true)){return ($this->catalogue() ?? throw new \DomainException(OfferUpgrade::MESSAGE))->run($r,$data);}
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
        if($r->action==='enom_answer'){EnomAnswers::record($r,$data,$now);return null;}
        if($r->action==='late_renewal'){EnomLateRenewal::review($r,$data);return null;}
        if($r->action==='activate'){EnomLiveActivation::review($r,$data);return null;}
        if(in_array($r->action,['test_management','live_management'],true)){EnomTestManagement::review($r,$data);return null;}
        if(in_array($r->action,['test_renewal','live_renewal'],true)){EnomTestRenewal::review($r,$data);return null;}
        if(in_array($r->action,['test_transfer','live_transfer'],true)){EnomTestTransfer::review($r,$data);return null;}
        if($r->action==='renewal_billing'){RenewalBillingSettings::save($r,$data);return null;}
        if($r->action==='call_limits'){ApiCallLimits::save($r,$data);return null;}
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
                $live=Account::where('server_id',$r->serverId)->sole();
                if($enabled&&$live->environment==='live'){
                    $b=AccountBindings::binding($live);
                    if(!EnomLiveActivation::current($b)){throw new \DomainException('Activate live sales first. Open the registrar account to see what is missing.');}
                    EnomLiveActivation::requireReady($live,['registration'=>EnomLiveActivation::registrationAccepted($b),'renewal'=>EnomTestRenewal::accepted($b),'management'=>EnomTestManagement::accepted($b),'transfer'=>EnomTestTransfer::accepted($b)]);
                }
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
        if($r->action==='history'&&($data['accept']??false)===true){
            $a=Account::where('server_id',$r->serverId)->firstOrFail();$binding=AccountBindings::binding($a);
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
            if($r->action==='new_domain_dns'){NewDomainDns::saveLocked($a,$data['dns_template']??null);return;}
            if($r->action==='history'){
                HistoryAcceptances::revokeLocked(AccountBindings::binding($a),\Paymenter\Extensions\Servers\Domains\Data\Action::from((string)($data['history_action']??'')));return;
            }
            throw new \DomainException('Unsupported settings action.');
        });

        return null;
    }

    /** Rule and fee save together. Then each account publishes on its own, and saving again retries what failed. */



}
