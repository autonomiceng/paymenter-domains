<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Domains\Catalogue\OfferState;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Tld;
/** Selected sale intent, not current availability: missing facts must remain in the count. */
final class TldTerms
{
    public static function selected(Builder $query): Builder
    {
        return $query->whereExists(fn($q)=>$q->selectRaw('1')->from('domains_sales_policies as p')
            ->whereColumn('p.tld','domains_tlds.tld')->whereColumn('p.server_id','domains_tlds.server_id')
            ->whereColumn('p.registrar_key','domains_tlds.registrar_key')->whereColumn('p.account_fingerprint','domains_tlds.account_fingerprint')
            ->where(fn($q)=>$q->where('p.register_enabled',true)->orWhere('p.transfer_enabled',true)));
    }
    public static function matches(Tld $t,array $policy): bool
    { return (int)($policy['server_id']??0)===(int)$t->server_id&&($policy['registrar_key']??null)===$t->registrar_key&&($policy['account_fingerprint']??null)===$t->account_fingerprint; }
    /** Uses the same saved choice as What you sell; never infer intent from successful prices. */
    public static function chosen(Tld $t,array $policy,array $defaults): bool
    {
        $i=OfferState::decode($t->catalogue_intent);$stops=$i['stops']??[];
        return self::matches($t,$policy)&&!($i['salesPaused']??false)&&($i['sell']??$defaults['defaultSell']??true)&&!in_array('renew',$stops,true)
            &&(($policy['register_enabled']??false)&&!in_array('register',$stops,true)
                ||($policy['transfer_enabled']??false)&&($i['transfers']??$defaults['defaultTransfers']??false)&&!in_array('transfer',$stops,true));
    }
    public static function read(Tld $t): bool
    {
        try{$facts=OfferState::facts($t->catalogue_facts);return $facts!==null&&$facts->fresh(app(Clock::class)->now());}
        catch(\Throwable){return false;}
    }
    /** Full selected set, bounded reads. The filter and account summary share this exact predicate. */
    public static function summary(?int $server=null): array
    {
        Staff::requireView();$read=0;$attention=[];
        $accounts=\Paymenter\Extensions\Servers\Domains\Models\Account::query()->when($server!==null,fn($q)=>$q->where('server_id',$server))->get()->keyBy('server_id');
        self::selected(TldFacts::bound(Tld::query()))->when($server!==null,fn($q)=>$q->where('domains_tlds.server_id',$server))->chunkById(100,function($rows)use($accounts,&$read,&$attention):void{
            $policies=\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::whereIn('tld',$rows->pluck('tld'))->get()->keyBy('tld');
            foreach($rows as $t){
                if(!self::matches($t,($policies[$t->tld]??null)?->getAttributes()??[])){continue;}
                try{$defaults=OfferState::decode($accounts[$t->server_id]->catalogue_defaults);$chosen=self::chosen($t,$policies[$t->tld]->getAttributes(),$defaults);}
                catch(\Throwable){$chosen=true;}
                if(!$chosen){continue;}
                if(self::read($t)){$read++;}else{$attention[]=(int)$t->id;}
            }
        });
        return ['read'=>$read,'attention'=>$attention,'total'=>$read+count($attention)];
    }
}
