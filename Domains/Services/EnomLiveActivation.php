<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Currency,Server,User};
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment,HistoryAcceptance,PriceSnapshot};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Price,SalesPolicy,Tld};
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
/** Local activation only. Provider evidence comes from reviewed deployment input, never a staff checkbox or TEST defaults. */
final class EnomLiveActivation
{
    public const KEY='domains_live_activation';
    public const FEATURES=['registration','renewal','management','transfer'];
    private const PRICE_MISSING='Costs and prices Missing. Choose what you sell, Sync prices, then check the enabled TLDs and registrar on the TLD list.';
    public static function defaults(Account $a): array
    {
        $b=CatalogueAcceptances::binding($a);$out=[];
        foreach(self::FEATURES as $feature){$out[$feature]=$feature!=='transfer'&&EnomLive::enabled($b,$feature);}
        return $out;
    }
    /** Configuration outside server settings must also make a displayed review stale. No secrets enter the ticket or audit. */
    public static function stamp(Account $a,bool $balance=false): string
    {
        return hash('sha256',json_encode([config('domains.enom_live'),config('domains.enom_identity.environment'),config('app.url'),Missing::supportUrl(),
            config('cache.default'),config('cache.stores.'.config('cache.default').'.driver'),config('session.driver'),config('queue.default'),
            config('queue.connections.'.config('queue.default')),Currency::where('code','USD')->pluck('code')->all(),
            $balance?self::balance($a):null,$balance?self::workerReady():null],JSON_THROW_ON_ERROR));
    }
    private static function balance(Account $a): ?object
    {return Schema::hasTable(BalanceSchema::TABLE)?DB::table(BalanceSchema::TABLE)->where('server_id',$a->server_id)->where('account_fingerprint',$a->account_fingerprint)->select(['amount','currency','read_at','last_error_code'])->first():null;}
    private static function workerReady(): bool
    {
        if(!Schema::hasTable('domains_runtime_turns')){return false;}
        $last=DB::table('domains_runtime_turns')->max('finished_at');$now=app(Clock::class)->now()->getTimestamp();
        return $last!==null&&(int)$last<=$now&&(int)$last>=$now-900;
    }
    /** Evidence is account/site bound, dated and traceable to a separately retained redacted provider answer. */
    private static function evidence(AccountBinding $b): array
    {
        $e=EnomLive::account($b)['evidence']??null;
        if(!is_array($e)||($e['fingerprint']??null)!==$b->fingerprint||($e['site']??null)!==EnomLive::site()||!self::dated($e)
            ||($e['currency']??null)!=='USD'||($e['complete_history']??null)!==true){
            throw new \DomainException('Live evidence Missing. Ask Enom to confirm this account’s history timezone, delay and retention, then have your site admin install the dated answer. Saved TEST values cannot prove this.');
        }
        return $e;
    }
    private static function dated(array $e): bool
    {
        $date=$e['confirmed_on']??null;$d=is_string($date)?\DateTimeImmutable::createFromFormat('!Y-m-d',$date):false;
        return $d&&$d->format('Y-m-d')===$date&&$date<=app(Clock::class)->now()->format('Y-m-d')
            &&is_string($e['reference']??null)&&preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._:\/-]{2,159}$/D',$e['reference'])===1
            &&is_string($e['sha256']??null)&&preg_match('/^[a-f0-9]{64}$/D',$e['sha256'])===1;
    }
    private static function history(AccountBinding $b,array $e,string $feature): HistoryAcceptance
    {
        $h=$e['history'][$feature]??null;
        try{
            if(!is_array($h)||!is_string($h['timezone']??null)||!is_int($h['lag']??null)||!is_int($h['retention']??null)){throw new \DomainException;}
            return new HistoryAcceptance($b,$h['timezone'],$h['lag'],$h['retention'],'enom_'.$feature.'_v1');
        }catch(\Throwable){throw new \DomainException(ucfirst($feature).' history Missing. Ask Enom for the account’s timezone, delay and retention; your site admin must install the confirmed values.');}
    }
    /** TEST history values cannot overwrite a separately confirmed Live contract. */
    public static function requireHistory(HistoryAcceptance $h): void
    {
        if(!EnomLive::deployment($h->binding)){return;} // Standalone driver contracts do not grant managed Live authority.
        $feature=match($h->vocabulary){'enom_registration_v1'=>'registration','enom_renewal_v1'=>'renewal','enom_transfer_v1'=>'transfer',default=>throw new \DomainException('Live Enom history required.')};
        $expected=self::history($h->binding,self::evidence($h->binding),$feature);
        if([$h->timezone,$h->lagSeconds,$h->retentionSeconds]!==[$expected->timezone,$expected->lagSeconds,$expected->retentionSeconds]){throw new \DomainException('Live history changed. Ask your site admin to check the dated Enom answer before saving.');}
    }
    public static function current(AccountBinding $b): bool
    {
        if(!EnomLive::deployment($b)){return false;}
        try{
            $s=Server::findOrFail($b->serverId)->settings()->where('key',self::KEY)->first();
            $v=$s?->encrypted?json_decode($s->value,true,8,JSON_THROW_ON_ERROR):null;
            return ($v['fingerprint']??null)===$b->fingerprint&&($v['site']??null)===EnomLive::site()
                &&($v['evidence']??null)===hash('sha256',json_encode(self::evidence($b),JSON_THROW_ON_ERROR));
        }catch(\Throwable){return false;}
    }
    public static function registrationAccepted(AccountBinding $b): bool
    {
        if(!self::current($b)){return false;}
        try{$v=json_decode(Server::findOrFail($b->serverId)->settings()->where('key',self::KEY)->firstOrFail()->value,true,8,JSON_THROW_ON_ERROR);return ($v['registration']??null)===true;}
        catch(\Throwable){return false;}
    }
    /** Saved choices survive missing evidence, but never grant current sending authority. */
    private static function receipt(Account $a): array
    {
        try{
            $s=Server::findOrFail($a->server_id)->settings()->where('key',self::KEY)->first();
            $v=$s?->encrypted?json_decode($s->value,true,8,JSON_THROW_ON_ERROR):null;
            return is_array($v)&&($v['fingerprint']??null)===$a->account_fingerprint&&($v['site']??null)===$a->live_site?$v:[];
        }catch(\Throwable){return [];}
    }
    /** Stale receipts re-review selected On features, except when only withdrawing. */
    public static function enabling(Account $a,array $features): array
    {
        $b=CatalogueAcceptances::binding($a);
        $before=['registration'=>(self::receipt($a)['registration']??false)===true,'renewal'=>EnomTestRenewal::stored($b),
            'management'=>EnomTestManagement::stored($b),'transfer'=>EnomTestTransfer::stored($b)];
        $enabling=array_filter($features,static fn($on,$feature)=>$on&&!$before[$feature],ARRAY_FILTER_USE_BOTH);
        if(($enabling||$features===$before)&&!self::current($b)){return array_filter($features);}
        return $enabling;
    }
    /** Rechecked inside the review mutex. This also drives the page, so every refusal has a next step. */
    public static function problems(Account $a,array $features): array
    {return self::readiness($a,$features);}
    private static function readiness(Account $a,array $features,?array $projection=null): array
    {
        $missing=[];$check=static function(\Closure $work)use(&$missing):void {try{$work();}catch(\DomainException $e){$missing[]=$e->getMessage();}};
        if(Missing::supportUrl()===null){$missing[]='Support contact Missing. Turn tickets on or ask your site admin to set a support link; Paymenter has no other support contact setting.';}
        $b=CatalogueAcceptances::binding($a);$now=app(Clock::class)->now();
        $check(static function()use($b):void {EnomLive::account($b);});
        if(!app(AccountBindings::class)->configurationAvailable((int)$a->server_id)){$missing[]='API connection Missing. Use Connect account to verify the saved credentials and site.';}
        $check(static function()use($a,$b,$features):void {
            if(!EnomLive::enabled($b,'catalogue')){throw new \DomainException('Live setup Missing. Ask your site admin to enable Live catalogue reads for this account.');}
            foreach(self::FEATURES as $feature){if(($features[$feature]??false)&&!EnomLive::enabled($b,$feature)){throw new \DomainException(ucfirst($feature).' setup Missing. Ask your site admin to enable it for this account.');}}
            if(!app(RuntimePorts::class)->accepted($a)){throw new \DomainException('Site setup Missing. Ask your site admin to install the registrar runtime.');}
            Installation::legacy();
        });
        $cache=config('cache.stores.'.config('cache.default').'.driver');
        if(!in_array($cache,['redis','database','memcached'],true)||!in_array(config('session.driver'),['redis','database','memcached'],true)
            ||!in_array(config('queue.connections.'.config('queue.default').'.driver'),['redis','database'],true)||(int)config('queue.connections.'.config('queue.default').'.retry_after',0)<900||!self::workerReady()){
            $missing[]='Background worker Missing. Ask your site admin to start the scheduler and shared queue, cache and sessions; this page cannot start server processes.';
        }
        if(NativePaidHooks::serviceMissing()!==[]){$missing[]='Payment events Missing. Restart the queue worker or update Paymenter to restore its service listeners.';}
        if(!Currency::where('code','USD')->exists()){$missing[]='USD currency Missing. Add USD in Settings → Currencies.';}
        $balance=self::balance($a);
        if(!$balance||$balance->currency!=='USD'||$balance->amount===null||$balance->last_error_code!==null||!$balance->read_at||(int)$balance->read_at>$now->getTimestamp()||(int)$balance->read_at<$now->getTimestamp()-86400){$missing[]='USD balance Missing. Use Check balance; page loads do not call Enom.';}
        $check(static function()use($b):void {
            $c=EnomLive::account($b);$calls=$c['daily_call_cap']??null;$spend=$c['daily_spend_limit_usd']??null;
            if(!is_int($calls)||$calls<1||!is_string($spend)||!preg_match('/^(?:0|[1-9][0-9]{0,8})\.[0-9]{1,6}$/D',$spend)||(float)$spend<=0){throw new \DomainException('Our daily limits Missing. Ask your site admin to set a positive call cap and USD spending limit.');}
        });
        if(($features['renewal']??false)&&RenewalBillingSettings::read((int)$a->server_id)['issue_days']===null){$missing[]='Invoice days Missing. Use Edit schedule to fix the invoice days.';}
        $check(static function()use($b,$features):void {
            $e=self::evidence($b);
            foreach(['registration','renewal'] as $feature){self::history($b,$e,$feature);}
            if($features['transfer']??false){self::history($b,$e,'transfer');}
            if(($features['transfer']??false)&&(!is_array($e['transfer_proof']??null)||!self::dated($e['transfer_proof']))){throw new \DomainException('Transfer proof Missing. Finish the approved Live transfer pilot and have your site admin record its dated result. Transfers can stay Off.');}
        });
        if($projection===null){$check(static function()use($a,$b,$features,$now):void {self::prices($a,$b,$features,$now);});}
        elseif($projection['missing']!==null){$missing[]=$projection['missing'];}
        return array_values(array_unique($missing));
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
                $receipt=['fingerprint'=>$b->fingerprint,'site'=>EnomLive::site(),'evidence'=>hash('sha256',json_encode($e,JSON_THROW_ON_ERROR))];
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
