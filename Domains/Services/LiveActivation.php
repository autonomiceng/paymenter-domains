<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Currency,Server};
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock,LiveActivationEvidence};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,HistoryAcceptance};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
/** Local readiness and retained receipts only. No transport, feature writes or processing authority. */
final class LiveActivation
{
    public const KEY='domains_live_activation';
    public const FEATURES=['registration','renewal','management','transfer'];
    public function __construct(private readonly LiveActivationEvidence $provider) {}
    /** Configuration outside server settings must also make a displayed review stale. No secrets enter the ticket or audit. */
    public function stamp(Account $a,bool $balance=false): string
    {
        return hash('sha256',json_encode([...$this->provider->configuration($a),config('app.url'),Missing::supportUrl(),
            config('cache.default'),config('cache.stores.'.config('cache.default').'.driver'),config('session.driver'),config('queue.default'),
            config('queue.connections.'.config('queue.default')),Currency::where('code','USD')->pluck('code')->all(),
            $balance?$this->balance($a):null,$balance?$this->workerReady():null],JSON_THROW_ON_ERROR));
    }
    public function balance(Account $a): ?object
    {return Schema::hasTable(BalanceSchema::TABLE)?DB::table(BalanceSchema::TABLE)->where('server_id',$a->server_id)->where('account_fingerprint',$a->account_fingerprint)->select(['amount','currency','read_at','last_error_code'])->first():null;}
    private function workerReady(): bool
    {
        if(!Schema::hasTable('domains_runtime_turns')){return false;}
        $last=DB::table('domains_runtime_turns')->max('finished_at');$now=app(Clock::class)->now()->getTimestamp();
        return $last!==null&&(int)$last<=$now&&(int)$last>=$now-900;
    }
    /** Evidence is account/site bound, dated and traceable to a separately retained redacted provider answer. */
    public function evidence(AccountBinding $b): array
    {
        $e=$this->provider->account($b)['evidence']??null;
        if(!is_array($e)||($e['fingerprint']??null)!==$b->fingerprint||($e['site']??null)!==$this->provider->site()||!$this->dated($e)
            ||($e['currency']??null)!=='USD'||($e['complete_history']??null)!==true){
            throw new \DomainException('Live evidence Missing. Ask '.$this->provider->label().' to confirm this account’s history timezone, delay and retention, then have your site admin install the dated answer. Saved TEST values cannot prove this.');
        }
        $this->provider->requireControls($e);
        return $e;
    }
    private function dated(array $e): bool
    {
        $date=$e['confirmed_on']??null;$d=is_string($date)?\DateTimeImmutable::createFromFormat('!Y-m-d',$date):false;
        return $d&&$d->format('Y-m-d')===$date&&$date<=app(Clock::class)->now()->format('Y-m-d')
            &&is_string($e['reference']??null)&&preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._:\/-]{2,159}$/D',$e['reference'])===1
            &&is_string($e['sha256']??null)&&preg_match('/^[a-f0-9]{64}$/D',$e['sha256'])===1;
    }
    public function history(AccountBinding $b,array $e,string $feature): HistoryAcceptance
    {
        $h=$e['history'][$feature]??null;
        try{
            if(!is_array($h)||!is_string($h['timezone']??null)||!is_int($h['lag']??null)||!is_int($h['retention']??null)){throw new \DomainException;}
            return new HistoryAcceptance($b,$h['timezone'],$h['lag'],$h['retention'],$b->registrarKey.'_'.$feature.'_v1');
        }catch(\Throwable){throw new \DomainException(ucfirst($feature).' history Missing. Ask '.$this->provider->label().' for the account’s timezone, delay and retention; your site admin must install the confirmed values.');}
    }
    /** TEST history values cannot overwrite a separately confirmed Live contract. */
    public function requireHistory(HistoryAcceptance $h): void
    {
        if(!$this->provider->deployment($h->binding)){return;} // Standalone driver contracts do not grant managed Live authority.
        $feature=match($h->vocabulary){$h->binding->registrarKey.'_registration_v1'=>'registration',$h->binding->registrarKey.'_renewal_v1'=>'renewal',$h->binding->registrarKey.'_transfer_v1'=>'transfer',default=>throw new \DomainException('Live registrar history required.')};
        $expected=$this->history($h->binding,$this->evidence($h->binding),$feature);
        if([$h->timezone,$h->lagSeconds,$h->retentionSeconds]!==[$expected->timezone,$expected->lagSeconds,$expected->retentionSeconds]){throw new \DomainException('Live history changed. Ask your site admin to check the dated '.$this->provider->label().' answer before saving.');}
    }
    public function current(AccountBinding $b): bool
    {
        if(!$this->provider->deployment($b)){return false;}
        try{
            $s=Server::findOrFail($b->serverId)->settings()->where('key',self::KEY)->first();
            $v=$s?->encrypted?json_decode($s->value,true,8,JSON_THROW_ON_ERROR):null;
            return ($v['fingerprint']??null)===$b->fingerprint&&($v['site']??null)===$this->provider->site()
                &&($v['evidence']??null)===hash('sha256',json_encode($this->evidence($b),JSON_THROW_ON_ERROR))
                &&array_intersect_key($v,array_flip(array_keys($this->provider->receiptContext($b))))===$this->provider->receiptContext($b);
        }catch(\Throwable){return false;}
    }
    public function registrationAccepted(AccountBinding $b): bool
    {
        if(!$this->current($b)){return false;}
        try{$v=json_decode(Server::findOrFail($b->serverId)->settings()->where('key',self::KEY)->firstOrFail()->value,true,8,JSON_THROW_ON_ERROR);return ($v['registration']??null)===true;}
        catch(\Throwable){return false;}
    }
    /** Saved choices survive missing evidence, but never grant current sending authority. */
    public function receipt(Account $a): array
    {
        try{
            $s=Server::findOrFail($a->server_id)->settings()->where('key',self::KEY)->first();
            $v=$s?->encrypted?json_decode($s->value,true,8,JSON_THROW_ON_ERROR):null;
            return is_array($v)&&($v['fingerprint']??null)===$a->account_fingerprint&&($v['site']??null)===$a->live_site?$v:[];
        }catch(\Throwable){return [];}
    }
    /** Stale receipts re-review selected On features, except when only withdrawing. */
    public function enabling(Account $a,array $features,array $before): array
    {
        $enabling=array_filter($features,static fn($on,$feature)=>$on&&!$before[$feature],ARRAY_FILTER_USE_BOTH);
        if(($enabling||$features===$before)&&!$this->current(CatalogueAcceptances::binding($a))){return array_filter($features);}
        return $enabling;
    }
    /** Rechecked inside the review mutex. This also drives the page, so every refusal has a next step. */
    public function problems(Account $a,array $features,\Closure $priceReadiness): array
    {
        $missing=[];$check=static function(\Closure $work)use(&$missing):void {try{$work();}catch(\DomainException $e){$missing[]=$e->getMessage();}};
        if(Missing::supportUrl()===null){$missing[]='Support contact Missing. Turn tickets on or ask your site admin to set a support link; Paymenter has no other support contact setting.';}
        $b=CatalogueAcceptances::binding($a);$now=app(Clock::class)->now();
        $check(function()use($b):void {$this->provider->account($b);});
        if(!app(AccountBindings::class)->configurationAvailable((int)$a->server_id)){$missing[]='API connection Missing. Use Connect account to verify the saved credentials and site.';}
        $check(function()use($a,$b,$features):void {
            if(!$this->provider->enabled($b,'catalogue')){throw new \DomainException('Live setup Missing. Ask your site admin to enable Live catalogue reads for this account.');}
            foreach(self::FEATURES as $feature){if(($features[$feature]??false)&&!$this->provider->enabled($b,$feature)){throw new \DomainException(ucfirst($feature).' setup Missing. Ask your site admin to enable it for this account.');}}
            if(!app(RuntimePorts::class)->accepted($a)){throw new \DomainException('Site setup Missing. Ask your site admin to install the registrar runtime.');}
            Installation::legacy();
        });
        $cache=config('cache.stores.'.config('cache.default').'.driver');
        if(!in_array($cache,['redis','database','memcached'],true)||!in_array(config('session.driver'),['redis','database','memcached'],true)
            ||!in_array(config('queue.connections.'.config('queue.default').'.driver'),['redis','database'],true)||(int)config('queue.connections.'.config('queue.default').'.retry_after',0)<900||!$this->workerReady()){
            $missing[]='Background worker Missing. Ask your site admin to start the scheduler and shared queue, cache and sessions; this page cannot start server processes.';
        }
        if(NativePaidHooks::serviceMissing()!==[]){$missing[]='Payment events Missing. Restart the queue worker or update Paymenter to restore its service listeners.';}
        if(!Currency::where('code','USD')->exists()){$missing[]='USD currency Missing. Add USD in Settings → Currencies.';}
        $balance=$this->balance($a);
        if(!$balance||$balance->currency!=='USD'||$balance->amount===null||$balance->last_error_code!==null||!$balance->read_at||(int)$balance->read_at>$now->getTimestamp()||(int)$balance->read_at<$now->getTimestamp()-86400){$missing[]='USD balance Missing. Use Check balance; page loads do not call '.$this->provider->label().'.';}
        $check(function()use($b):void {
            $c=$this->provider->account($b);$calls=$c['daily_call_cap']??null;$spend=$c['daily_spend_limit_usd']??null;
            if(!is_int($calls)||$calls<1||!is_string($spend)||!preg_match('/^(?:0|[1-9][0-9]{0,8})\.[0-9]{1,6}$/D',$spend)||(float)$spend<=0){throw new \DomainException('Our daily limits Missing. Ask your site admin to set a positive call cap and USD spending limit.');}
        });
        if(($features['renewal']??false)&&RenewalBillingSettings::read((int)$a->server_id)['issue_days']===null){$missing[]='Invoice days Missing. Use Edit schedule to fix the invoice days.';}
        $check(function()use($b,$features):void {
            $e=$this->evidence($b);
            foreach(['registration','renewal'] as $feature){$this->history($b,$e,$feature);}
            if($features['transfer']??false){$this->history($b,$e,'transfer');}
            if(($features['transfer']??false)&&$this->provider->transferProofRequired()&&(!is_array($e['transfer_proof']??null)||!$this->dated($e['transfer_proof']))){throw new \DomainException('Transfer proof Missing. Finish the approved Live transfer pilot and have your site admin record its dated result. Transfers can stay Off.');}
        });
        $check(static function()use($priceReadiness,$b,$now):void {$priceReadiness($b,$now);});
        return array_values(array_unique($missing));
    }
    /** A receipt is assembled only after the caller's reviewed readiness check; this method writes nothing. */
    public function receiptFor(AccountBinding $b): array
    {
        return ['fingerprint'=>$b->fingerprint,'site'=>$this->provider->site(),
            'evidence'=>hash('sha256',json_encode($this->evidence($b),JSON_THROW_ON_ERROR)),...$this->provider->receiptContext($b)];
    }
}
