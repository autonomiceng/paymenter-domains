<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,SalesPolicy,Tld};
use Paymenter\Extensions\Servers\Domains\Data\{CatalogueAcceptance,Environment,RequestBudget,RequestContext,RequestOrigin};
/** Dormant local writers. Inject a test-only seam explicitly; default setup/probes cannot perform I/O. */
final class StaffSettingsWrites
{
    public function __construct(private readonly NativeCatalogue $native,private readonly SellerPolicies $sellers,
        private readonly AccountBindings $bindings,private readonly bool $allowInjectedProbes=false) {}
    public function connectionAvailable(): bool {return $this->allowInjectedProbes;}
    public function run(StaffSettingsReview $r,#[\SensitiveParameter] array $data,?\DateTimeImmutable $now=null): void
    {
        $now??=new \DateTimeImmutable;$r->input($data);
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
            // This slice has no production transport. Gate39 must deliberately supply its reviewed integration.
            if(!$this->allowInjectedProbes){$r->local(fn()=>throw new \DomainException('Connection checks are unavailable.'));return;}
            if($r->action==='configure'){
                $registrar=(string)($data['registrar']??'');
                $credentials=$data['credentials']??($registrar==='enom'?['uid'=>$data['uid']??'','token'=>$data['token']??'']:['api_key'=>$data['api_key']??'']);
                $this->bindings->configure($r->serverId,$registrar,Environment::from((string)($data['environment']??'')),$credentials,$r);return;
            }
            $r->local(fn()=>null,'connection_requested');$phase='connection_refused';
            try{
                $driver=$this->bindings->reader($r->serverId);$binding=$driver->binding();
                $result=$driver->balance(new RequestContext($binding,RequestOrigin::Interactive,$now->modify('+10 seconds'),new RequestBudget(1)));
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
                $accepted=CatalogueAcceptances::load(CatalogueAcceptances::binding($a),true)->endings[$t->tld]??null;
                if(($min<$t->min_years||$max>$t->max_years)&&(($data['verified']??false)!==true||!in_array($min,$accepted['register']??[],true)||!in_array($max,$accepted['register']??[],true))){throw new \DomainException('Explicit verified widening required.');}
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
                $t->save();return;
            }
            if($r->action!=='acceptance'){throw new \DomainException('Unsupported settings action.');}
            $binding=CatalogueAcceptances::binding($a);
            try{$old=CatalogueAcceptances::load($binding,true);}catch(\DomainException){$old=null;}
            $ending=(string)($data['ending']??'');\Paymenter\Extensions\Servers\Domains\Data\CanonicalName::parse('example.'.$ending,[$ending]);$endings=$old?->endings??[];
            if(!$old&&Domain::where('server_id',$s->id)->whereNotNull('reservation_name')->orderBy('id')->lockForUpdate()->get()->isNotEmpty()){throw new \DomainException('Repair must preserve all holder renewal acceptance.');}
            if(($data['accept']??false)===true){
                // Explicit independent exact-term evidence is required. Annual NameSilo prices prove no multiyear terms.
                if(($data['verified']??false)!==true){throw new \DomainException('Independent term verification required.');}
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
            $next=new CatalogueAcceptance($binding,$revision+1,$endings);
            CatalogueAcceptances::saveLocked($next);
            // Narrow immediately; widening never imports prices, enables a TLD, or restores withdrawn actions.
            foreach(Tld::where('server_id',$s->id)->orderBy('id')->lockForUpdate()->get() as $row){$accepted=$next->endings[$row->tld]??null;
                $row->register_enabled=$row->register_enabled&&($accepted['registry']??false)&&!empty($accepted['register']);
                $row->transfer_enabled=$row->transfer_enabled&&($accepted['registry']??false)&&!empty($accepted['transfer']);$row->save();}
        });
    }
}
