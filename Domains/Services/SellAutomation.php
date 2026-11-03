<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate;
use App\Models\Server;
use Illuminate\Support\Facades\{DB,Log};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,CatalogueAcceptance,CatalogueEntry,Environment};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Price,SalesPolicy,SellRow,Tld};
/** Applies only a saved rule. Local preparation never grants processing or Live activation. */
final class SellAutomation
{
    public static function enabled(Account $a): bool
    {
        $b=CatalogueAcceptances::binding($a);
        // Activate requires prepared prices and a seller. This grants local preparation, never spending.
        return ($a->processing_enabled||$b->environment===Environment::Live)&&SellRule::saved($b)!==null
            &&TestCatalogue::enabled($b)&&EnomTestRegistration::enabled($b);
    }
    /** Fresh, complete costs are necessary even for an explicit Sell choice. No unpriced discovery widens acceptance. */
    public static function row(AccountBinding $b,string $tld,\DateTimeImmutable $now): ?SellRow
    {
        $rule=SellRule::saved($b);if($rule===null){return null;}
        $row=SellRule::rows($b)->where('tld',$tld)->first();
        return $row&&self::priced($row,$now)&&SellRule::decide($row,$rule)==='sell'?$row:null;
    }
    private static function fresh(SellRow $row,\DateTimeImmutable $now): bool
    { return $row->prices!==null&&$row->read_at<=$now->getTimestamp()&&$row->read_at>$now->getTimestamp()-PriceCatalogue::MAX_COST_AGE_SECONDS; }
    private static function priced(SellRow $row,\DateTimeImmutable $now): bool
    { return self::fresh($row,$now)&&$row->register_cost!==null&&$row->renew_cost!==null&&$row->high_cost!==null; }
    /** Keep reading rule-selected terms after a cost withdrawal. This read list grants no sale or publication proof. */
    public static function priceReads(Account $a,CatalogueAcceptance $accepted,\DateTimeImmutable $now): CatalogueAcceptance
    {
        if(!self::enabled($a)){return $accepted;}
        $b=CatalogueAcceptances::binding($a);$accepted->binding->requireMatch($b);$endings=$accepted->endings;
        foreach(SellRule::changes(SellRule::rows($b)->get()->filter(static fn(SellRow $row)=>self::priced($row,$now)),SellRule::saved($b),[]) as $tld=>$change){
            $endings[$tld]=['register'=>$change['terms'],'renew'=>[1],'transfer'=>$change['transfer']?[1]:[],'registry'=>true,'renewal_safe'=>true];
        }
        return new CatalogueAcceptance($b,$accepted->revision,$endings,$accepted->registrationRoute,$accepted->registrationIntervalSeconds);
    }
    /** Independent reads and committed saves survive a refused rule refresh. Acceptance itself still rolls back. */
    public static function refresh(Account|int $seed,\DateTimeImmutable $now): void
    {
        try{self::accept(is_int($seed)?Account::where('server_id',$seed)->sole():$seed,$now);}
        catch(\Throwable $error){Log::warning('Domains selling rule refresh failed.',['server_id'=>$seed instanceof Account?(int)$seed->server_id:$seed,'exception_class'=>$error::class]);}
    }
    /** Runs again each tick so a pause or failed publication does not lose a successful fill. Idempotent under the account mutex. */
    public static function accept(Account $seed,\DateTimeImmutable $now,?CatalogueEntry $read=null,?int $expectedRevision=null): void
    {
        DB::transaction(static function()use($seed,$now,$read,$expectedRevision):void {
            $s=Server::whereKey($seed->server_id)->lockForUpdate()->firstOrFail();
            $a=Account::where('server_id',$seed->server_id)->lockForUpdate()->sole();
            UpgradeGate::legacy();
            $b=CatalogueAcceptances::binding($a);if($b->fingerprint!==$seed->account_fingerprint){throw new \DomainException('Catalogue binding mismatch.');}
            $active=self::enabled($a);if(SellRule::saved($b)===null){return;}
            app(AccountBindings::class)->requireReadableLocked($s,$a);
            // Missing is a new catalogue; a corrupt saved acceptance needs repair, never replacement by inference.
            $old=$s->settings()->where('key',CatalogueAcceptances::KEY)->exists()?CatalogueAcceptances::load($b,true):null;
            if($expectedRevision!==null&&$old?->revision!==$expectedRevision){throw new \DomainException('Acceptance changed during refresh.');}
            $read?->binding->requireMatch($b);$rule=SellRule::saved($b);
            $rows=SellRule::rows($b)->when($read,static fn($q)=>$q->where('tld',$read->tld))->get();
            // Start from the whole requested term list: a limit-only save can leave the acceptance revision unchanged.
            $changes=SellRule::changes($rows->filter(static fn(SellRow $row)=>self::priced($row,$now)),$rule,[]);
            $costs=[];
            if($rule['max_cost']!==null){
                // The account mutex excludes price writers; no price locks precede the policy/TLD prefix.
                foreach(Price::where(['server_id'=>$b->serverId,'account_fingerprint'=>$b->fingerprint,'currency'=>'USD'])->whereIn('tld',$rows->pluck('tld'))->get() as $price){
                    $costs[$price->tld][$price->action][$price->term]=$price->wholesale===null?null:PricingRule::units(PriceCatalogue::cost($price->wholesale));
                }
                foreach($read?->rates??[] as $rate){$costs[$read->tld][$rate->action][$rate->term]=$rate->perYear===null?null:PricingRule::units($rate->perYear)*$rate->term;}
            }
            foreach($rows as $row){
                $prior=$old?->endings[$row->tld]??[];$change=$changes[$row->tld]??null;
                // Stale bulk rows cannot widen acceptance, but a new limit must still narrow their existing terms.
                if(!self::fresh($row,$now)&&!empty($prior['register'])){$change=['terms'=>$prior['register'],'transfer'=>!empty($prior['transfer'])];}
                // Paused/disabled automation may narrow saved sales, never accept a new term or action.
                if(!$active&&$change!==null){
                    $change['terms']=array_values(array_intersect($change['terms'],$prior['register']??[]));
                    $change['transfer']=$change['transfer']&&!empty($prior['transfer']);if($change['terms']===[]){$change=null;}
                }
                if($change!==null&&$rule['max_cost']!==null){
                    $cost=static function(string $action,int $term)use($costs,$row):?int {
                        if(array_key_exists($term,$costs[$row->tld][$action]??[])){return $costs[$row->tld][$action][$term];}
                        $value=$row->prices[PriceFill::bucket($term)][$action]??null;return $value===null?null:PricingRule::units($value)*$term;
                    };
                    $cap=PricingRule::units($rule['max_cost']);$renew=$cost('renew',1);
                    $change['terms']=$renew===null||$renew>$cap?[]:array_values(array_filter($change['terms'],static fn(int $term)=>($value=$cost('register',$term))!==null&&$value<=$cap*$term));
                    if($change['terms']===[]){$change=null;}
                }
                if($change===null?empty($prior['register'])&&empty($prior['transfer']):($prior['register']??[])===$change['terms']&&(!empty($prior['transfer']))===$change['transfer']){
                    unset($changes[$row->tld]);
                }else{$changes[$row->tld]=$change;}
            }
            if($changes===[]){return;}
            $next=CatalogueAcceptances::changeLocked($a,$old,$changes);
            self::audit($s,$now,'acceptance',null,['revision'=>$old?->revision??0],['revision'=>$next->revision],
                ['rule'=>$rule,'added'=>count(array_filter($changes)),'removed'=>count($changes)-count(array_filter($changes))]);
        });
    }
    /** Called in the native publication transaction after its server/account/policy/TLD prefix. */
    public static function publishLocked(Server $s,Account $a,Tld $t,SalesPolicy $policy,PriceCatalogue $prices,\DateTimeImmutable $now,bool $hasProducts,bool $behind): bool
    {
        $b=CatalogueAcceptances::binding($a);
        if(!self::enabled($a)||($row=self::row($b,$t->tld,$now))===null){return false;}
        app(AccountBindings::class)->requireReadableLocked($s,$a);
        $before=['register'=>$t->register_enabled,'transfer'=>$t->transfer_enabled,'seller'=>$policy->server_id,'seller_revision'=>$policy->revision];
        $rule=SellRule::saved($b);$actions=['register'=>false,'transfer'=>false];
        // The separate staff master switch remains authoritative. Cost and rule withdrawals use action flags.
        foreach($actions as $action=>$_){
            if(!$t->enabled||!$t->renew_enabled||($action==='transfer'&&(!($row->transfer??$rule['transfers'])||!EnomTestTransfer::accepted($b)))){continue;}
            try{
                $renew=$prices->current($b->serverId,$b->fingerprint,$t->tld,'renew',1,'USD',$now,true,$t);
                PublicationEligibility::firstEligibleTerm($t,$action,static function(int $term)use($t,$action,$prices,$b,$now,$rule,$renew):void {
                    PublicationEligibility::require($t,$action,$term,true);
                    $price=$prices->current($b->serverId,$b->fingerprint,$t->tld,$action,$term,'USD',$now,true,$t);
                    if($rule['max_cost']!==null&&(PricingRule::units($renew->wholesale)>PricingRule::units($rule['max_cost'])
                        ||PricingRule::units($price->wholesale)>PricingRule::units($rule['max_cost'])*$term)){throw new \DomainException('Cost is over your limit.');}
                });
                $actions[$action]=true;
            }catch(\DomainException){/* Existing Status and price diagnostics give the cause and its fix. */}
        }
        $t->fill(['register_enabled'=>$actions['register'],'transfer_enabled'=>$actions['transfer']]);
        $changed=$t->isDirty();if($changed){$t->save();}
        // Only an untouched policy gets first-wins selection. A later empty policy is a staff decision.
        if($policy->server_id===null&&$policy->revision===1&&array_filter($actions)!==[]){
            $policy->fill(['server_id'=>$b->serverId,'registrar_key'=>$b->registrarKey,'account_fingerprint'=>$b->fingerprint,
                'register_enabled'=>$actions['register'],'transfer_enabled'=>$actions['transfer'],'revision'=>$policy->revision+1]);
            SalesPolicy::withPolicyWrite(static fn()=>$policy->save());
        }
        $after=['register'=>$t->register_enabled,'transfer'=>$t->transfer_enabled,'seller'=>$policy->server_id,'seller_revision'=>$policy->revision];
        $publish=$changed||$behind||!$hasProducts;
        if($before!==$after||$behind||(!$hasProducts&&array_filter($actions)!==[])){
            self::audit($s,$now,'publish',(int)$t->id,$before,$after,['rule'=>$rule,'prices_refreshed'=>$behind]);
        }
        return $publish&&($hasProducts||array_filter($actions)!==[]);
    }
    public static function audit(Server $s,\DateTimeImmutable $now,string $action,?int $tld,array $before,array $after,array $input=[]): void
    {
        StaffSettingsReview::append($s,['key'=>bin2hex(random_bytes(16)),'actor_id'=>null,'at'=>$now->format(DATE_ATOM),
            'action'=>StaffSettingsReview::ACTIONS[$action],'reason'=>'Applied the saved selling rule.','phase'=>'completed',
            'input'=>$input,'tld_id'=>$tld,'before'=>$before,'after'=>$after]);
    }
}
