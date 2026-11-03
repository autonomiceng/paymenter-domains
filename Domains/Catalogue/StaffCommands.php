<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,CardFee,CatalogueAcceptances,PricingRule,StaffSettingsReview as Review};

/** Dormant translator. Writer owns the reviewed transaction; wake only enqueues bounded local work. */
final class StaffCommands
{
    public const ACTIONS=['acceptance','override','remove_override','default_rule','card_fee','tld_settings','seller','publish','import'];
    public function __construct(private readonly Writer $writer,private readonly AccountBindings $bindings,
        private readonly \Closure $wake,private readonly ?\Closure $sync=null) {}

    /** Raw state hash includes unavailable credentials and every offer/publication revision. Never decrypts credentials. */
    public static function stamp(int $server,?int $tld=null): string
    {
        $ending=$tld?Tld::whereKey($tld)->where('server_id',$server)->value('tld'):null;
        $raw=static fn($q)=>(DB::transactionLevel()>0?$q->lockForUpdate():$q)->get()->map(static fn($r)=>(array)$r)->all();
        $names=Tld::where('server_id',$server)->when($tld,fn($q)=>$q->whereKey($tld))->pluck('tld');
        $ids=Server::where('extension','Domains')->orderBy('id')->pluck('id');
        return 'offer:'.hash('sha256',OfferState::json([UpgradeGate::generation(),
            $raw(DB::table((new Server)->getTable())->whereIn('id',$ids)->orderBy('id')),
            $raw(DB::table('domains_accounts')->whereIn('server_id',$ids)->orderBy('server_id')),
            $raw(DB::table('settings')->where('settingable_type',(new Server)->getMorphClass())->whereIn('settingable_id',$ids)
                ->whereRaw("`key` NOT LIKE ? ESCAPE '!'",[str_replace('_','!_',Review::KEY).'%'])->orderBy('id')),
            $raw(DB::table('domains_tlds')->when($ending,fn($q)=>$q->where('tld',$ending),fn($q)=>$q->where('server_id',$server))->orderBy('id')),
            $raw(DB::table('domains_prices')->when($ending,fn($q)=>$q->where('tld',$ending),fn($q)=>$q->where('server_id',$server))->orderBy('id')),
            $raw(DB::table('domains_sales_policies')->whereIn('tld',$names)->orderBy('tld')),$server,$tld]));
    }
    public function publish(int $id,?Review $review=null): void
    {
        $t=Tld::findOrFail($id);
        if($review!==null){$this->run($review,[]);return;}
        $b=CatalogueAcceptances::binding(Account::where('server_id',$t->server_id)->sole());
        $d=OfferState::decode(Account::where('server_id',$t->server_id)->sole()->catalogue_defaults);
        $this->writer->apply(Edit::republish($b,$t->tld),$this->revision($t,$d));
    }
    public function run(Review $review,array $data): array
    {
        if(!in_array($review->action,self::ACTIONS,true)){throw new \DomainException('Unsupported catalogue action.');}
        if(DB::transactionLevel()!==0){throw new \LogicException('Staff commands require the outer writer.');}
        $account=Account::where('server_id',$review->serverId)->sole();$binding=CatalogueAcceptances::binding($account);
        $query=Tld::where('server_id',$review->serverId)->where('catalogue_converted',true);
        if($review->tldId!==null){$query->whereKey($review->tldId);}
        $sell=$data['sell']??[];$names=array_unique(array_keys(($sell['choices']??[])+($sell['terms']??[])));
        if(count($names)>25){throw new \DomainException('Review at most 25 TLDs at once.');}
        $defaults=in_array($review->action,['default_rule','card_fee'],true)||($review->action==='acceptance'&&isset($sell['rule']));
        $rows=(clone $query)->when($names!==[],fn($q)=>$q->whereIn('tld',$names))->orderBy('id')->limit($names===[]?1:25)->get();
        if($rows->isEmpty()){throw new \DomainException('Converted TLD required.');}
        $d=OfferState::decode($account->catalogue_defaults);$entries=[];$failed=[];
        foreach($names as $name){if(!$rows->contains('tld',$name)){$failed[$name]='Converted TLD required.';}}
        if($defaults){
            $fee=$d['card_fee']??CardFee::DEFAULT;$intent=$this->intent($d);$rule=$d['pricing_rule']??PricingRule::DEFAULT;
            if(isset($sell['rule'])){
                $v=\Paymenter\Extensions\Servers\Domains\Services\SellRule::validate($sell['rule']);
                $intent=new Intent($v['mode']!=='only',$v['years'],$v['transfers'],$v['max_cost']);
            }elseif($review->action==='default_rule'){$rule=$this->rule($data,$d,$review->serverId);}
            else{$fee=CardFee::validate(['percent'=>$data['fee_percent']??'', 'fixed'=>$data['fee_fixed']??'']);if($rule['mode']==='markup_fee_percent'){$rule['card_fee']=$fee;}}
            // Keep fee evidence in the defaults revision even when the current rule doesn't use it.
            $command=Edit::defaults($binding,$rows->first()->tld,$intent,$rule,$fee);
            $entries[]=[$command,$this->revision($rows->first(),$d)];
        }
        if(!$defaults||$names!==[]){foreach($rows as $t){
            if($defaults&&!in_array($t->tld,$names,true)){continue;}
            try{
                $raw=OfferState::decode($t->catalogue_intent);$intent=$this->intent($raw);$rule=$raw['pricing_rule']??null;
                $command=match($review->action){
                    'publish'=>Edit::republish($binding,$t->tld),
                    'import'=>Edit::requestSync($binding,$t->tld),
                    'override','remove_override'=>Edit::override($binding,$t->tld,$intent,$review->action==='override'?$this->rule($data,$d,$review->serverId):null),
                    'seller'=>$this->seller($t,$data),
                    'acceptance'=>$this->row($binding,$t,$raw,$sell,$rule),
                    'tld_settings'=>isset($data['retained_annual_retail'])?Edit::retainRenewal($binding,$t->tld,$data['retained_annual_retail']):$this->settings($binding,$t,$raw,$data,$rule),
                    default=>throw new \DomainException('Unsupported catalogue action.')};
                $target=$command->binding()->serverId===$t->server_id?$t:Tld::where('server_id',$command->binding()->serverId)->where('tld',$t->tld)->sole();
                $targetDefaults=OfferState::decode(Account::where('server_id',$target->server_id)->sole()->catalogue_defaults);
                $entries[]=[$command,$this->revision($target,$targetDefaults)];
            }catch(\DomainException|\InvalidArgumentException $e){$failed[$t->tld]=$e->getMessage();}
        }}
        if(count($entries)>25||$entries===[]){throw new \DomainException('Review 1 to 25 valid commands.');}
        if($review->action==='import'&&$this->sync===null){throw new \LogicException('Offer sync composition required.');}
        $review->input([...$data,...($sell===[]?[]:['changes'=>array_fill_keys($names,null),'rule'=>$sell['rule']??null,'rows'=>array_map(static fn($name)=>['new'=>['choice'=>$sell['choices'][$name]??null,'longest'=>$sell['terms'][$name]['longest']??null,'transfer'=>$sell['terms'][$name]['transfer']??null]],array_combine($names,$names))])]);
        $result=$this->writer->reviewed($review,$entries,static fn()=>self::stamp($review->serverId,$review->tldId));
        if($defaults){($this->wake)($binding);}
        if($review->action==='import'){if($this->sync===null){throw new \LogicException('Offer sync composition required.');}($this->sync)($binding);}
        return ['pending'=>$defaults,'published'=>$defaults?0:count($result),'failed'=>array_map(static fn($name)=>'.'.$name,array_keys($failed)),'rows'=>$result,'failures'=>$failed];
    }
    private function revision(Tld $t,array $d): Revision
    {return OfferState::revision($t,$d,DB::table('domains_sales_policies')->where('tld',$t->tld)->first());}
    private function intent(array $raw): Intent
    {return new Intent(...array_intersect_key($raw,get_object_vars(new Intent)));}
    private function rule(array $data,array $defaults,int $server): array
    {return PricingRule::validate(['mode'=>$data['mode']??null,'markup'=>$data['markup']??'0','round_up'=>$data['round_up']??'none',
        'fixed'=>['register'=>$data['fixed_register']??null,'renew'=>$data['fixed_renew']??null,'transfer'=>$data['fixed_transfer']??null],
        ...(($data['mode']??null)==='markup_fee_percent'?['card_fee'=>$defaults['card_fee']??CardFee::DEFAULT]:[])]);}
    private function row($binding,Tld $t,array $raw,array $sell,?array $rule): Edit
    {
        $name=$t->tld;$choice=$sell['choices'][$name]??null;
        if(array_key_exists($name,$sell['choices']??[])){
            if(!in_array($choice,['sell','skip',null],true)){throw new \DomainException('Invalid sell choice.');}
            $raw['sell']=$choice===null?null:$choice==='sell';
        }
        $terms=$sell['terms'][$name]??[];
        foreach(['longest'=>'longest','transfer'=>'transfers'] as $key=>$field){if(array_key_exists($key,$terms)){$raw[$field]=$terms[$key];}}
        return Edit::override($binding,$name,$this->intent($raw),$rule);
    }
    private function settings($binding,Tld $t,array $raw,array $data,?array $rule): Edit
    {
        if(($data['min_years']??1)!=1){throw new \DomainException('Offer terms start at one year.');}
        if(($data['enabled']??false)&&(($data['register']??false)||($data['transfer']??false))&&($raw['sell']??null)!==true){
            $defaults=OfferState::decode(Account::where('server_id',$binding->serverId)->sole()->catalogue_defaults);
            $raw['sell']=($defaults['defaultSell']??true)?null:true;
        }
        $raw['longest']=(int)($data['max_years']??1);$raw['transfers']=(bool)($data['transfer']??false);$raw['stops']=[];
        foreach(['register','renew','transfer'] as $action){if(!($data['enabled']??false)||!($data[$action]??false)){$raw['stops'][]=$action;}}
        if(in_array('renew',$raw['stops'],true)&&Domain::where(['server_id'=>$binding->serverId,'tld'=>$t->tld,'account_fingerprint'=>$binding->fingerprint])->whereNotNull('reservation_name')->exists()){
            throw new \DomainException('Retained holders require annual renewal support.');}
        // A verified checkbox is administrative intent only; this command never creates or ages Facts.
        return Edit::override($binding,$t->tld,$this->intent($raw),$rule);
    }
    private function seller(Tld $t,array $data): Edit
    {
        $id=($data['seller']??'')===''?(int)$t->server_id:(int)$data['seller'];
        $b=CatalogueAcceptances::binding(Account::where('server_id',$id)->sole());
        return Edit::seller($b,$t->tld,($data['seller']??'')===''?[]:array_keys(array_filter(['register'=>(bool)($data['register']??false),'transfer'=>(bool)($data['transfer']??false)])));
    }
}
