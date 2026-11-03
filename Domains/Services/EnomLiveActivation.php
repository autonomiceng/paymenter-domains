<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Server,User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment,HistoryAcceptance,PriceSnapshot};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Price,SalesPolicy,Tld};
/** Local activation only. Provider evidence comes from reviewed deployment input, never a staff checkbox or TEST defaults. */
final class EnomLiveActivation
{
    public const KEY=LiveActivation::KEY;
    public const FEATURES=LiveActivation::FEATURES;
    private const PRICE_MISSING='Costs and prices Missing. Choose what you sell, Sync prices, then check the enabled TLDs and registrar on the TLD list.';
    public static function defaults(Account $a): array
    {
        $b=CatalogueAcceptances::binding($a);$out=[];
        foreach(self::FEATURES as $feature){$out[$feature]=$feature!=='transfer'&&EnomLive::enabled($b,$feature);}
        return $out;
    }
    private static function engine(): LiveActivation
    {return new LiveActivation(new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\ActivationEvidence);}
    public static function stamp(Account $a,bool $balance=false): string
    {
        $stamp=self::engine()->stamp($a,$balance);$offers=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferReadiness::routed();
        return $offers?hash('sha256',json_encode([$stamp,$offers->stamp($a)],JSON_THROW_ON_ERROR)):$stamp;
    }
    private static function balance(Account $a): ?object {return self::engine()->balance($a);}
    private static function evidence(AccountBinding $b): array {return self::engine()->evidence($b);}
    private static function history(AccountBinding $b,array $e,string $feature): HistoryAcceptance {return self::engine()->history($b,$e,$feature);}
    public static function requireHistory(HistoryAcceptance $h): void {self::engine()->requireHistory($h);}
    public static function current(AccountBinding $b): bool {return self::engine()->current($b);}
    public static function registrationAccepted(AccountBinding $b): bool {return self::engine()->registrationAccepted($b);}
    private static function receipt(Account $a): array {return self::engine()->receipt($a);}
    public static function enabling(Account $a,array $features): array
    {
        $b=CatalogueAcceptances::binding($a);
        return self::engine()->enabling($a,$features,['registration'=>(self::receipt($a)['registration']??false)===true,
            'renewal'=>EnomTestRenewal::stored($b),'management'=>EnomTestManagement::stored($b),'transfer'=>EnomTestTransfer::stored($b)]);
    }
    public static function problems(Account $a,array $features): array {return self::readiness($a,$features);}
    private static function readiness(Account $a,array $features,?array $projection=null): array
    {
        return self::engine()->problems($a,$features,static function(AccountBinding $b,\DateTimeImmutable $now)use($a,$features,$projection):void {
            if($projection===null){self::prices($a,$b,$features,$now);}
            elseif($projection['missing']!==null){throw new \DomainException($projection['missing']);}
        });
    }
    /** Display only: retain readable rows beside named failures. Never used by Activate's strict check. */
    private static function priceReview(Account $a,AccountBinding $b,array $features,\DateTimeImmutable $now): array
    {
        $costs=[];$failures=[];$missing=null;
        try{$costs=self::prices($a,$b,$features,$now,$failures);}catch(\DomainException $e){$missing=$e->getMessage();}
        if($failures!==[]){$missing=self::PRICE_MISSING;}
        return compact('costs','failures','missing');
    }
    /** Strict by default. Only the display supplies a failure list to continue after a bad action/term. No price writes. */
    private static function prices(Account $a,AccountBinding $b,array $features,\DateTimeImmutable $now,?array &$failures=null): array
    {
        if ($offers=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferReadiness::routed()) {
            $r=$offers->review($a,$features,DB::transactionLevel()>0);
            if($failures!==null){$failures=$r['failures'];} elseif($r['missing']!==null){throw new \DomainException($r['missing']);}
            return $r['costs'];
        }
        // Fence this legacy snapshot once; per-term evidence validation stays query-free.
        \Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate::legacy();
        try{
            $lock=DB::transactionLevel()>0;$accepted=CatalogueAcceptances::load($b,$lock);$rows=[];$counts=['registration'=>0,'transfer'=>0];$rules=new PricingRules;$prices=new PriceCatalogue($rules);
            // Display snapshot only. Activate still takes the original independent locking reads below.
            if(!$lock){
                $policies=SalesPolicy::where('server_id',$a->server_id)->where('account_fingerprint',$b->fingerprint)->get()->keyBy('tld');
                $saved=Price::where('server_id',$a->server_id)->where('account_fingerprint',$b->fingerprint)->where('currency','USD')
                    ->whereIn('tld',Tld::where('server_id',$a->server_id)->where('account_fingerprint',$b->fingerprint)->where('enabled',true)->select('tld'))->get()->groupBy('tld');
                $default=$fee=null;
            }
            $evidence=collect();$hash=null;
            if($accepted->registrationRoute!==EnomLive::route()||$accepted->registrationIntervalSeconds<25){throw new \DomainException;}
            foreach(Tld::where('server_id',$a->server_id)->where('account_fingerprint',$b->fingerprint)->where('enabled',true)->orderBy('tld')->get() as $t){
                $policy=$lock?SalesPolicy::where('tld',$t->tld)->where('server_id',$a->server_id)->where('account_fingerprint',$b->fingerprint)->first():$policies->get($t->tld);
                $needed=($features['renewal']??false)&&$t->renew_enabled;
                foreach(['registration'=>'register','transfer'=>'transfer'] as $feature=>$action){$needed=$needed||(($features[$feature]??false)&&$policy?->{$action.'_enabled'}&&$t->{$action.'_enabled'});}
                if(!$needed){continue;}
                $ruleError=null;
                if(!$lock){
                    try{
                        $rule=$t->use_default_rule?($default??=$rules->defaultRule())
                            :$rules->rule($t,fee:$t->pricing_mode==='markup_fee_percent'?($fee??=CardFee::read()):null);
                        $hash=hash('sha256',json_encode($rule,JSON_THROW_ON_ERROR));
                    }catch(\Throwable $e){if($failures===null){throw $e;}$ruleError=$e;}
                    $evidence=($saved->get($t->tld)??collect())->keyBy(static fn(Price $p)=>$p->action.':'.$p->term);
                }
                $attempt=static function(string $action,int $term,\Closure $validate)use(&$failures,$t,$ruleError):void {
                    try{if($ruleError!==null){throw $ruleError;}$validate();}
                    catch(\Throwable $e){
                        if($failures===null){throw $e;}
                        $failures[]=['tld'=>$t->tld,'action'=>$action,'term'=>$term,
                            'note'=>'Costs or prices Missing. Check the TLD price list and pricing rule; Sync prices if costs are missing.'];
                    }
                };
                $read=static fn(string $action,int $term)=>$lock
                    ?$prices->current((int)$a->server_id,$b->fingerprint,$t->tld,$action,$term,'USD',$now,true,$t)
                    :PriceCatalogue::currentEvidence($evidence->get($action.':'.$term),$hash,$now);
                foreach(['registration'=>'register','transfer'=>'transfer'] as $feature=>$action){
                    if(!($features[$feature]??false)||!$policy?->{$action.'_enabled'}||!$t->{$action.'_enabled'}){continue;}
                    $terms=$action==='register'?range($t->min_years,$t->max_years):[1];
                    foreach($terms as $term){
                        $attempt($action,$term,static function()use($lock,$t,$action,$term,$accepted,$evidence,$read,&$rows,&$counts,$feature):void {
                            if($lock){PublicationEligibility::require($t,$action,$term,true);}
                            else{PublicationEligibility::requireEvidence($t,$action,$term,$accepted,$evidence->get('renew:1'),$evidence->get($action.':'.$term));}
                            $p=$read($action,$term);
                            $renew=$read('renew',1);
                            $rows[]=self::costRow($t->tld,$action,$term,$p,$renew);$counts[$feature]++;
                        });
                    }
                }
                if(($features['renewal']??false)&&$t->renew_enabled){
                    $attempt('renew',1,static function()use($accepted,$t,$read,&$rows):void {
                        if(!($accepted->endings[$t->tld]['renewal_safe']??false)||!in_array(1,$accepted->endings[$t->tld]['renew']??[],true)){throw new \DomainException;}
                        $p=$read('renew',1);
                        $rows[]=self::costRow($t->tld,'renew',1,$p);
                    });
                }
            }
            foreach($counts as $feature=>$count){if($failures===null&&($features[$feature]??false)&&!$count){throw new \DomainException;}}
            return $rows;
        }catch(\Throwable){throw new \DomainException(self::PRICE_MISSING);}
    }
    private static function costRow(string $tld,string $action,int $term,PriceSnapshot $price,?PriceSnapshot $renew=null): array
    {
        $alerts=array_filter([$price->staffAlert,$renew?->staffAlert],static fn($alert)=>$alert!==null);
        $note=$alerts===[]?null:(in_array('below_cost',$alerts,true)?'Below cost: renewals are still billed.':'Price warning: check the TLD price list.');
        return ['tld'=>$tld,'action'=>$action,'term'=>$term,'cost'=>$price->wholesale,'price'=>$price->retail,'note'=>$note,
            'renewCost'=>$renew?->wholesale,'renewPrice'=>$renew?->retail];
    }
    public static function requireReady(Account $a,array $features): void
    {if($problems=self::problems($a,$features)){throw new \DomainException(implode("\n",$problems));}}
    /** Called by the existing feature writers too, so calling one directly cannot replace activation with verified=true. */
    public static function requireFeature(StaffSettingsReview $r,AccountBinding $b,string $feature): void
    {
        if($b->environment!==Environment::Live){return;}
        if($r->action!=='activate'&&!self::current($b)){throw new \DomainException('Activate live sales first. Open the registrar account to see what is missing.');}
        self::requireReady(Account::where('server_id',$b->serverId)->sole(),[$feature=>true]);
        if(in_array($feature,['renewal','transfer'],true)){HistoryAcceptances::saveLocked(self::history($b,self::evidence($b),$feature));}
    }
    public static function review(StaffSettingsReview $r,array $data): void
    {
        if($r->action!=='activate'||$r->tldId!==null||($data['confirmed']??null)!==true){throw new \DomainException('Confirm Activate on the registrar account.');}
        $features=[];foreach(self::FEATURES as $feature){if(!is_bool($data[$feature]??null)){throw new \DomainException('Choose the Live features to activate.');}$features[$feature]=$data[$feature];}
        $r->input($features);
        $r->local(static function()use($r,$features):void {
            $a=Account::where('server_id',$r->serverId)->sole();$b=CatalogueAcceptances::binding($a);
            if($b->registrarKey!=='enom'||$b->environment!==Environment::Live){throw new \DomainException('Live Enom account required.');}
            $enabling=self::enabling($a,$features);$receipt=self::receipt($a);
            if($enabling){
                self::requireReady($a,$enabling);$e=self::evidence($b);
                $receipt=self::engine()->receiptFor($b);
                if($enabling['registration']??false){HistoryAcceptances::saveLocked(self::history($b,$e,'registration'));}
            }
            foreach(['renewal'=>EnomTestRenewal::class,'management'=>EnomTestManagement::class,'transfer'=>EnomTestTransfer::class] as $feature=>$class){
                $on=$feature==='renewal'?EnomTestRenewal::on($b):$class::stored($b);
                if(!$features[$feature]&&!$on){continue;}
                if(!User::findOrFail($r->actorId)->hasPermission(StaffSettingsReview::permission('live_'.$feature))){throw new \DomainException('Current staff permissions required.');}
                if(!$features[$feature]||isset($enabling[$feature])){$class::review($r,['accept'=>$features[$feature],'verified'=>true]);}
            }
            $s=Server::findOrFail($r->serverId)->settings()->firstOrNew(['key'=>self::KEY]);
            $s->encrypted=true;$s->type='string';$s->value=json_encode([...$receipt,'registration'=>$features['registration']],JSON_THROW_ON_ERROR);$s->save();
            StorefrontCatalogueCache::invalidateAfterCommit();
            if($enabling){app(AccountBindings::class)->setProcessing($r->serverId,true,static function($server,$account):void {app(AccountBindings::class)->requireReadableLocked($server,$account);});}
        });
    }
    public static function summary(Account $a,?array $features=null): array
    {
        $features??=self::defaults($a);$b=CatalogueAcceptances::binding($a);$balance=self::balance($a);$details=[];
        $details[]=implode(', ',array_map(static fn($feature)=>ucfirst($feature).' '.($features[$feature]?'On':'Off'),self::FEATURES)).'.';
        $details[]='Account '.$b->fingerprint.', site '.($a->live_site??'Missing: verify the API connection').'.';
        $details[]='Balance '.($balance?->amount??'Missing: use Check balance').' '.($balance?->currency??'USD').'. '.EnomLiveCalls::facts($b);
        $billing=RenewalBillingSettings::read((int)$a->server_id);
        $details[]='Renewal invoices '.$billing['issue_days'].' days before expiry, due '.$billing['due_days'].' days before. Renewals also enable scheduled invoices and customer early renewals. Late renewal has a separate account switch.';
        $review=self::priceReview($a,$b,$features,app(Clock::class)->now());$costs=$review['costs'];$missing=$review['missing'];
        $counts=['register'=>0,'transfer'=>0,'renew'=>0];$min=$max=null;$exceptions=[];
        foreach($costs as $row){
            $counts[$row['action']]++;
            foreach(array_filter([$row['cost'],$row['renewCost']],static fn($cost)=>$cost!==null) as $cost){
                $units=PricingRule::units($cost);$min=$min===null?$units:min($min,$units);$max=$max===null?$units:max($max,$units);
            }
            if($row['note']!==null){$exceptions[$row['tld']]=['tld'=>$row['tld'],'note'=>$row['note']];}
        }
        foreach(['registration'=>'register','transfer'=>'transfer'] as $feature=>$action){if(($features[$feature]??false)&&!$counts[$action]){$missing=self::PRICE_MISSING;}}
        return ['text'=>'Activate the selected Live features. Enom takes domain costs from this account’s balance.',
            'help'=>implode("\n",$details),'costs'=>$costs,'counts'=>$counts,'tlds'=>count(array_unique(array_column($costs,'tld'))),
            'range'=>$min===null?null:[PricingRule::decimal($min,4),PricingRule::decimal($max,4)],'exceptions'=>array_values($exceptions),'failures'=>$review['failures'],
            'missing'=>self::readiness($a,$features,['missing'=>$missing])];
    }
}
