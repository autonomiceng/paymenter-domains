<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock,ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Account;
/** A full pass fits 25 reads, below our background/60-total minute guards; half the shared day stays for other work. */
final class TransferReadBudget implements ReadLimiter
{
    private readonly AccountReadBudget $minute;
    private readonly ?ReadLimiter $runtime;
    private int $admissions=0;
    public function admissions(): int { return $this->admissions; }
    public function __construct(Clock $clock,?ReadLimiter $runtime=null)
    {
        $this->minute=$runtime instanceof AccountReadBudget?$runtime->restricted(25):new AccountReadBudget($clock,25);
        // The standard runtime limiter uses the same ledger. Other deployment guards must still run.
        $this->runtime=$runtime instanceof AccountReadBudget?null:$runtime;
    }
    public function acquire(AccountBinding $binding,RequestOrigin $origin): string
    {
        if($binding->registrarKey==='enom'&&$binding->environment===Environment::Test){
            $uid=Account::where('server_id',$binding->serverId)->sole()->stable_identity;$u=EnomTestSendLimit::usage($uid);
            if($u['day']===null||$u['day_limit']===null||$u['day']>=intdiv($u['day_limit'],2)){throw new ReadDeferred('Our daily transfer read allowance is unavailable.');}
        }
        if($binding->registrarKey==='enom'&&$binding->environment===Environment::Live&&EnomLive::requested()){
            $config=EnomLive::account($binding);$cap=$config['daily_call_cap']??0;
            $uid=Account::where('server_id',$binding->serverId)->sole()->stable_identity;
            $used=(int)(DB::table(EnomLiveCallSchema::TABLE)->where(['account'=>hash('sha256',$uid),'day'=>EnomTestCalls::day()])->value('used')??0);
            if($used>=intdiv($cap,2)){throw new ReadDeferred('Our daily transfer read allowance is unavailable.');}
        }
        $token=$this->minute->acquire($binding,$origin);
        try{$reserved=json_encode([$token,$this->runtime?->acquire($binding,$origin)],JSON_THROW_ON_ERROR);$this->admissions++;return $reserved;}
        catch(\Throwable $e){$this->minute->release($binding,$origin,$token);throw $e;}
    }
    /** The precheck avoids wasting minute admission; the physical guard repeats it atomically against concurrent sends. */
    public function run(AccountBinding $binding,\Closure $read): mixed
    {
        if($binding->registrarKey!=='enom'||$binding->environment!==Environment::Test){return $read();}
        return EnomTestSendLimit::withCheckoutHeadroom(Account::where('server_id',$binding->serverId)->sole()->stable_identity,$read);
    }
    public function release(AccountBinding $binding,RequestOrigin $origin,string $token): void
    {
        [$minute,$runtime]=json_decode($token,true,4,JSON_THROW_ON_ERROR);
        try{if($runtime!==null){$this->runtime->release($binding,$origin,$runtime);}}
        finally{$this->minute->release($binding,$origin,$minute);}
    }
}
