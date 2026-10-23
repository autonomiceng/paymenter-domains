<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
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
        if(!Schema::hasTable(BalanceSchema::TABLE)){return;}
        $claimedUntil=$this->claim($account,'daily_due_at',86400);if($claimedUntil===null){return;}
        if($this->read($account,'daily',RequestOrigin::Background)==='rate_limited'){
            // No provider request was made. Retry a later scheduler turn without burning a full day.
            DB::table(BalanceSchema::TABLE)->where('server_id',$account->server_id)->where('account_fingerprint',$account->account_fingerprint)
                ->where('daily_due_at',$claimedUntil)->update(['daily_due_at'=>$this->clock->now()->getTimestamp()+60]);
            return;
        }
        $facts=self::facts($account,$this->clock->now());
        if($facts['low']){$this->notify($account);}
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
        try{$reader=$this->bindings->reader((int)$account->server_id);$binding->requireMatch($reader->binding());}
        catch(\Throwable){BalanceReads::record($binding,null,$this->clock->now(),$source,'configuration_unavailable');return 'configuration_unavailable';}
        $c=new RequestContext($binding,$origin,$this->clock->now()->modify('+25 seconds'),new RequestBudget(1),$this->clock,$this->limiter);
        try{
            // Reserve the same per-login wire limiter used by the existing driver reads.
            $r=$c->withReservedWire(fn()=>BalanceReads::read($reader,$c,$source));
            return $r->evidence()->balance&&$r->disposition===\Paymenter\Extensions\Servers\Domains\Data\Disposition::Accepted?'refreshed':'read_failed';
        }catch(\Throwable $e){if($e instanceof ReadDeferred){return 'rate_limited';}BalanceReads::record($binding,null,$this->clock->now(),$source,'read_failed');return 'read_failed';}
    }
    public static function fundsUrl(string $registrar): string
    {
        // The public Enom refill instructions do not publish a stable deep link; use account home.
        return match($registrar){'enom'=>'https://cp.enom.com/','namesilo'=>\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\Environment::Live->origin().'/cart/add-funds',default=>throw new \DomainException('Unknown registrar.')};
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
        $status=$unknown?'Unknown':($low?'Low for upcoming renewals':'OK');
        if($forecast['unknown']>0){$status=($low||$unknown?$status.' · ':'').'Upcoming renewals unknown';}
        return ['status'=>$status,'low'=>$low,'balance'=>!$available||$row?->amount===null?'Unknown':self::money($row->amount,$row->currency),
            'as_of'=>$row?->read_at?gmdate('Y-m-d H:i:s',$row->read_at).' UTC':'Never read',
            'error'=>match($available?$row?->last_error_code:'configuration_unavailable'){null=>'None','rate_limited'=>'Read deferred','configuration_unavailable'=>'Configuration unavailable',default=>'Read failed'},
            'total'=>self::money($forecast['total'],$forecast['currency']),'unknown'=>$forecast['unknown']];
    }
    private static function money(string $amount,string $currency): string
    {$value=rtrim(rtrim($amount,'0'),'.');$dot=strpos($value,'.');return ($dot===false?$value.'.00':str_pad($value,$dot+3,'0')).' '.$currency;}
    private function notify(Account $account): void
    {
        // Use the native inbox only when it exists and is enabled; no email fallback.
        if(!method_exists(Notification::class,'sendToDatabase')||!Schema::hasTable('notifications')
            ||!Filament::getPanel('admin')->hasDatabaseNotifications()
            ||!((new User)->notifications()->getRelated() instanceof \Illuminate\Notifications\DatabaseNotification)
            ||!Schema::hasColumns('notifications',['id','type','notifiable_type','notifiable_id','data','read_at'])){return;}
        DB::transaction(function()use($account):void {
            $q=DB::table(BalanceSchema::TABLE)->where('server_id',$account->server_id)->where('account_fingerprint',$account->account_fingerprint);
            $r=(clone $q)->lockForUpdate()->firstOrFail();$day=$this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
            if($r->notified_on===$day){return;}
            foreach(User::whereHas('role')->with('role')->lazyById(100) as $staff){
                if(Staff::allows($staff)){Notification::make()->title('Low for upcoming renewals')->body('Registrar account #'.$account->server_id.' has a renewal shortfall.')->warning()->sendToDatabase($staff);}
            }
            $q->update(['notified_on'=>$day]);
        });
    }
}
