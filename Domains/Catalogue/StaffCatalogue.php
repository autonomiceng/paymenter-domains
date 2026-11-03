<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,StaffGrants,TldFacts};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,SellRow,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{CardFee,CatalogueAcceptances,PriceList,PricingRule};

/** Bounded Staff snapshots over converted identities; no shadow rows, legacy costs or render writes. */
final class StaffCatalogue
{
    public static function routed(): ?self { return CallerRouting::legacy(static fn()=>null); }
    public function __construct(private readonly CallerPorts $ports,private readonly Clock $clock) {}
    public function rows(array $bindings,?string $focus): \Illuminate\Database\Eloquent\Builder
    {
        $costs=Staff::requireView()->costs;
        $q=TldFacts::bound(DB::table('domains_tlds'))->where('catalogue_converted',true)->where(function($q)use($bindings):void {
            foreach($bindings as $b){$q->orWhere(fn($q)=>$q->where('domains_tlds.server_id',$b->serverId)->where('domains_tlds.account_fingerprint',$b->fingerprint));}
            if(!$bindings){$q->whereRaw('1=0');}
        });
        $cost=DB::table('domains_prices')->where('action','register')->where('term',1)->where('currency','USD');
        $q->leftJoinSub($cost,'p',fn($j)=>$j->on('p.server_id','=','domains_tlds.server_id')->on('p.tld','=','domains_tlds.tld')->on('p.account_fingerprint','=','domains_tlds.account_fingerprint'));
        $extract="json_extract(p.catalogue_observation, '$.total')";
        if(DB::getDriverName()!=='sqlite'){$extract='json_unquote('.$extract.')';}
        $q->select('domains_tlds.id','domains_tlds.server_id','domains_tlds.account_fingerprint','domains_tlds.tld')
            ->selectRaw('null as choice,null as longest,null as transfer,null as renew_cost,null as transfer_cost,null as high_cost,null as prices,null as read_at,1 as listed')
            ->selectRaw(($costs?'cast('.$extract.' as decimal(18,4))':'null').' as register_cost');
        return SellRow::query()->fromSub($q,'domains_sell_rows')->when($focus!==null,fn($q)=>$q->where('tld',$focus));
    }
    public function rule(int $server): array
    {
        $grants=Staff::requireView();$d=OfferState::decode(Account::where('server_id',$server)->sole()->catalogue_defaults);
        $i=new Intent(...array_intersect_key($d,get_object_vars(new Intent)));
        return ['mode'=>$i->defaultSell?'except':'only','max_cost'=>$grants->costs?$i->maxAnnualCost:null,'years'=>$i->defaultLongest,'transfers'=>$i->defaultTransfers];
    }
    public function pricing(int $server,?Tld $t=null): array
    {
        Staff::requireView();$d=OfferState::decode(Account::where('server_id',$server)->sole()->catalogue_defaults);
        $own=$t?OfferState::decode($t->catalogue_intent):[];
        $rule=$own['pricing_rule']??$d['pricing_rule']??PricingRule::DEFAULT;
        if($rule['mode']==='markup_fee_percent'){$rule['card_fee']=$d['card_fee']??CardFee::DEFAULT;}
        return PricingRule::validate($rule);
    }
    public function fee(int $server): array
    {Staff::requireView();$d=OfferState::decode(Account::where('server_id',$server)->sole()->catalogue_defaults);return CardFee::validate($d['card_fee']??CardFee::DEFAULT);}
    /** Each call is at most one table page. Same evaluator and observation evidence as the public adapter. */
    public function page(Collection $tlds): array
    {
        $grants=Staff::requireView();if($tlds->count()>100){throw new \DomainException('Staff page exceeds 100 rows.');}
        if($tlds->isEmpty()){return [];}
        $accounts=Account::whereIn('server_id',$tlds->pluck('server_id'))->get()->keyBy('server_id');$queries=[];
        foreach($tlds->groupBy('server_id') as $server=>$rows){$queries[]=new Query(CatalogueAcceptances::binding($accounts[$server]),$rows->pluck('tld')->all(),'register');}
        $loaded=$this->ports->staffBatch($queries);$offers=[];
        foreach($queries as $i=>$q){foreach($loaded[$i] as $name=>$offer){$offers[$q->binding->serverId.':'.$name]=$offer;}}
        $sellers=DB::table('domains_sales_policies')->whereIn('tld',$tlds->pluck('tld'))->pluck('server_id','tld');
        $holders=Domain::whereIn('server_id',$tlds->pluck('server_id'))->whereIn('tld',$tlds->pluck('tld'))->whereNotNull('reservation_name')
            ->selectRaw('server_id,tld,account_fingerprint,count(*) as holders')->groupBy('server_id','tld','account_fingerprint')->get()->keyBy(fn($r)=>$r->server_id.':'.$r->tld.':'.$r->account_fingerprint);
        $out=[];
        foreach($tlds as $t){
            $d=OfferState::decode($accounts[$t->server_id]->catalogue_defaults);$raw=OfferState::decode($t->catalogue_intent);$all=$offers[$t->server_id.':'.$t->tld]??[];$prices=[];
            foreach($all as $offer){$decision=$offer->decision;$cost=$offer->observation();$flags=[];
                if($decision->reason!==null){$flags[]=$decision->reason;}
                if(!$cost||$cost->total===null){$flags[]='cost_missing';}
                if(!$cost?->fresh($this->clock->now())){$flags[]='cost_old';}
                $retail=$offer->staffRetail();
                if($decision->action==='renew'&&$decision->term===1){$retail=UpgradeEvidence::money($t->catalogue_retained_annual_retail,2);}
                $prices[]=['action'=>$decision->action,'term'=>$decision->term,'retail'=>$retail===null?null:TldFacts::money($retail),
                    'flags'=>$flags,'synced'=>$cost!==null,'observed'=>$cost?->observedAt,
                    ...($grants->costs?['cost'=>$cost?->total===null?null:TldFacts::money($cost->total,4)]:[])];
            }
            $chosen=collect($all)->first(fn($o)=>$o->decision->action==='register'&&$o->decision->available);
            $reason=$all['register:1']->decision->reason??'unknown_facts';
            $label=match($reason){'staff_stopped','staff_term_stopped'=>'Stopped by staff','seller_changed'=>'No registrar selected','publication_pending'=>'Prices not published','stale_price'=>'Cost out of date','below_cost'=>'Below cost','unknown_price'=>'Cost missing',default=>TldFacts::NEEDS_SYNC};
            try{$facts=OfferState::facts($t->catalogue_facts);}catch(\Throwable){$facts=null;}
            $out[$t->id]=['prices'=>$prices,'sales'=>['label'=>$chosen?'For sale':'Not for sale','tone'=>$chosen?'success':'gray','reason'=>$chosen?null:$label],
                'seller'=>isset($sellers[$t->tld])?(int)$sellers[$t->tld]:null,'holders'=>(int)($holders[$t->server_id.':'.$t->tld.':'.$t->account_fingerprint]->holders??0),
                'demo'=>false,'intent'=>array_intersect_key($raw,array_flip(['sell','longest','transfers','stops'])),'defaults'=>$this->publicDefaults($d,$grants->costs),
                'own'=>isset($raw['pricing_rule']),'offers'=>$grants->costs?$all:[],'facts'=>$facts];
        }
        return $out;
    }
    private function publicDefaults(array $d,bool $costs): array
    {if(!$costs){unset($d['maxAnnualCost'],$d['card_fee'],$d['pricing_rule']);}return $d;}
    /** The matrix/CSV contract uses the exact same page snapshots, chunked without an unbounded SQL IN. */
    public function matrix(Collection $tlds,int $years,string $filter='all'): array
    {
        abort_unless(Staff::requireView()->costs,403);$rows=[];$accounts=[];
        foreach($tlds->chunk(100) as $chunk){$page=$this->page($chunk);$settings=[];
            foreach($chunk as $t){$s=$page[$t->id];$id=(int)$t->server_id;$own=$s['own'];
                if($filter==='default'&&$own||$filter==='own'&&!$own){continue;}
                if(!isset($settings[$id])){$a=Account::where('server_id',$id)->sole();$d=OfferState::decode($a->catalogue_defaults);$settings[$id]=[$d,Server::whereKey($id)->value('name')];}
                [$d,$name]=$settings[$id];$accounts[$id]=$name;$raw=OfferState::decode($t->catalogue_intent);
                $rule=$raw['pricing_rule']??$d['pricing_rule']??PricingRule::DEFAULT;$fee=$d['card_fee']??CardFee::DEFAULT;
                $row=['id'=>(int)$t->id,'tld'=>'.'.$t->tld,'server_id'=>$id,'account'=>$name,'years'=>$years,'pricing'=>$own?'Custom':'Default','currency'=>'USD','prices'=>[],
                    'formula'=>'Price = '.PricingRule::describe($rule).($rule['mode']==='fixed'?', times years.':', per year, times years.'),'fee'=>$fee,'fee_in_price'=>$rule['mode']==='markup_fee_percent'];$below=$loss=false;
                foreach(PriceList::ACTIONS as $action=>$unused){$offer=$s['offers'][$action.':'.$years]??null;$cost=$offer?->observation()?->total;$price=$offer?->staffRetail();
                    if($action==='renew'&&$years===1){$price=UpgradeEvidence::money($t->catalogue_retained_annual_retail,2);}
                    $profit=$price!==null&&$cost!==null?CardFee::profit($price,$cost,$fee):null;$under=$price!==null&&$cost!==null&&!PricingRule::covers($price,$cost);$loses=$profit!==null&&str_starts_with($profit,'-');$below|=$under;$loss|=$loses;
                    $row['prices'][$action]=['price'=>$price,'cost'=>$cost,'profit'=>$profit,'revision'=>$offer?->decision->revision->offer,'below_cost'=>$under,'loses_money'=>$loses,
                        'status'=>$price===null?($action==='transfer'&&!($s['intent']['transfers']??$s['defaults']['defaultTransfers']??false)?PriceList::TRANSFERS_OFF:'Missing'):($cost===null?'Cost missing':($under?'Below cost':($loses?'Loses money after card fee':'')))];}
                if($filter==='below_cost'&&!$below||$filter==='loses_money'&&!$loss){continue;}$rows[]=$row;
            }
        }
        return ['rows'=>$rows,'skipped'=>[],'warnings'=>[],'accounts'=>$accounts,'years'=>$years,'explanations'=>PriceList::explain($rows)];
    }
}
