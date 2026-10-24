<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,StaffAlert};
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock,ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Data\{RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Account;
final readonly class RegistrarBalances
{
    public function __construct(private AccountBindings $bindings,private Clock $clock,private ReadLimiter $limiter) {}
    public function refresh(int $serverId): string
    {
        Staff::requireView();OperationClaims::outside();
        $a=Account::where('server_id',$serverId)->sole();
        if(!$this->claim($a,'manual_due_at',60)){return 'rate_limited';}
        // Recheck current permission after the durable claim, immediately before any provider read.
        Staff::requireView();return $this->read($a,'manual',RequestOrigin::Interactive);
    }
    public function daily(Account $account): void
    {
        OperationClaims::outside();
        if (TestCatalogue::enabled(CatalogueAcceptances::binding($account))) { return; }
        if(!Schema::hasTable(BalanceSchema::TABLE)){return;}
        $claimedUntil=$this->claim($account,'daily_due_at',86400);
        if($claimedUntil!==null&&$this->read($account,'daily',RequestOrigin::Background)==='rate_limited'){
            // No provider request was made. Retry a later scheduler turn without burning a full day.
            $this->retryDaily($account,$claimedUntil);
            return;
        }
        $facts=self::facts($account,$this->clock->now());
        // Alert retries use the retained reading without changing the provider read schedule.
        if($facts['low']){$this->notify($account);}
    }
    private function retryDaily(Account $account,int $claimedUntil): void
    {
        DB::table(BalanceSchema::TABLE)->where('server_id',$account->server_id)->where('account_fingerprint',$account->account_fingerprint)
            ->where('daily_due_at',$claimedUntil)->update(['daily_due_at'=>$this->clock->now()->getTimestamp()+60]);
    }
    private function claim(Account $account,string $field,int $seconds): ?int
    {
        return DB::transaction(function()use($account,$field,$seconds):?int {
            $a=Account::where('server_id',$account->server_id)->lockForUpdate()->sole();
            CatalogueAcceptances::binding($account)->requireMatch(CatalogueAcceptances::binding($a));
            $q=DB::table(BalanceSchema::TABLE)->where('server_id',$a->server_id)->where('account_fingerprint',$a->account_fingerprint);
            $row=(clone $q)->lockForUpdate()->first();$now=$this->clock->now()->getTimestamp();
            if($row&&(int)$row->$field>$now){return null;}
            if($row){$q->update([$field=>$now+$seconds]);}else{DB::table(BalanceSchema::TABLE)->insert(['server_id'=>$a->server_id,'account_fingerprint'=>$a->account_fingerprint,$field=>$now+$seconds]);}
            return $now+$seconds;
        });
    }
    private function read(Account $account,string $source,RequestOrigin $origin): string
    {
        $binding=CatalogueAcceptances::binding($account);
        try{$balanceOnly=$account->registrar_key==='namesilo'&&NameSiloSandbox::enrolled($account);
            $reader=$this->bindings->reader((int)$account->server_id,$balanceOnly);$binding->requireMatch($reader->binding());
            if ($reader instanceof \Paymenter\Extensions\Servers\Domains\Registrars\Enom\CataloguePreview) { throw new \DomainException('Use the reviewed staff balance probe.'); }}
        catch(\Throwable){BalanceReads::record($binding,null,$this->clock->now(),$source,'configuration_unavailable');return 'configuration_unavailable';}
        $c=new RequestContext($binding,$origin,$this->clock->now()->modify('+25 seconds'),new RequestBudget(1),$this->clock,$this->limiter);
        try{
            // Reserve the same per-login wire limiter used by the existing driver reads.
            $r=$c->withReservedWire(fn()=>BalanceReads::read($reader,$c,$source));
            return $r->evidence()->balance&&$r->disposition===\Paymenter\Extensions\Servers\Domains\Data\Disposition::Accepted?'refreshed':'read_failed';
        }catch(\Throwable $e){if($e instanceof EnomTestCallLimit){return 'test_call_refused';}if($e instanceof ReadDeferred){return 'rate_limited';}BalanceReads::record($binding,null,$this->clock->now(),$source,'read_failed');return 'read_failed';}
    }
    public static function fundsUrl(string $registrar,string $environment): string
    {
        // The public Enom refill instructions do not publish a stable deep link; use account home.
        $namesilo=$environment==='live'?\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\Environment::Live:\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\Environment::Sandbox;
        return match($registrar){'enom'=>'https://cp.enom.com/','namesilo'=>$namesilo->origin().'/cart/add-funds',default=>throw new \DomainException('Unknown registrar.')};
    }
    public static function facts(Account $a,\DateTimeImmutable $now): array
    {
        $row=DB::table(BalanceSchema::TABLE)->where('server_id',$a->server_id)->where('account_fingerprint',$a->account_fingerprint)->first();
        // A native credential edit is not account verification. Retain the cache, but never
        // display its amount while the current server configuration cannot prove this binding.
        $available=app(AccountBindings::class)->configurationAvailable((int)$a->server_id);
        $forecast=BalanceForecast::for($a,$now);
        $unknown=!$available||!$row?->read_at||$row->last_error_code!==null||$row->amount===null||$row->currency!==$forecast['currency'];
        $low=!$unknown&&BalanceForecast::compare($row->amount,$forecast['total'])<0;
        $code=$available?$row?->last_error_code:'configuration_unavailable';
        // Missing always carries its cause; a failed check hides the retained amount.
        $cause=match(true){$code==='configuration_unavailable'=>"Couldn't load the account's settings",$code==='rate_limited'=>'Check delayed. Try again in a minute.',
            $code!==null=>'Last check failed',!$row?->read_at||$row->amount===null=>'Not checked yet',$unknown=>'Balance is not in USD',default=>null};
        return ['status'=>$unknown?\Paymenter\Extensions\Servers\Domains\Presentation\Missing::LABEL:($low?'Low balance':'OK'),'low'=>$low,
            'balance'=>$unknown?null:self::money($row->amount,$row->currency),'checked'=>$unknown?null:gmdate('M j, Y H:i \\U\\T\\C',(int)$row->read_at),
            'cause'=>$cause,'total'=>self::money($forecast['total'],$forecast['currency']),'unknown'=>$forecast['unknown']];
    }
    /** Staff money: "USD 49,642.19". */
    private static function money(string $amount,string $currency): string
    {
        [$whole,$fraction]=array_pad(explode('.',ltrim($amount,'+')),2,'');$sign=str_starts_with($whole,'-')?'-':'';$whole=ltrim($whole,'-');
        $fraction=rtrim($fraction,'0');$fraction=str_pad($fraction,max(2,strlen($fraction)),'0');
        return $currency.' '.$sign.preg_replace('/\B(?=(\d{3})+(?!\d))/',',',$whole).'.'.$fraction;
    }
    private function notify(Account $account): bool
    {
        return DB::transaction(function()use($account):bool {
            $q=DB::table(BalanceSchema::TABLE)->where('server_id',$account->server_id)->where('account_fingerprint',$account->account_fingerprint);
            $r=(clone $q)->lockForUpdate()->firstOrFail();$day=$this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
            if($r->notified_on===$day){return true;}
            $name=\App\Models\Server::whereKey($account->server_id)->value('name')?:'Registrar account';
            if (StaffAlert::send('Low balance',$name.' balance is below renewals due in 30 days.','filament.admin.pages.registrar-setup',Staff::VIEW,'balance:'.$account->server_id.':'.$account->account_fingerprint.':'.$day)) {
                $q->update(['notified_on'=>$day]);return true;
            }
            return false;
        });
    }
}
