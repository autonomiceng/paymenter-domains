<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock,ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,RequestOrigin};
/** The price reader yields to checkout and transfer work under our shared day and minute guards. */
final readonly class PriceReadBudget implements ReadLimiter
{
    private AccountReadBudget $minute;
    private ?ReadLimiter $runtime;
    public function __construct(private Clock $clock)
    {
        $runtime=Runtime::limiter();
        $this->minute=$runtime instanceof AccountReadBudget?$runtime->restricted(25):new AccountReadBudget($clock,25);
        $this->runtime=$runtime instanceof AccountReadBudget?null:$runtime;
    }
    public function acquire(AccountBinding $binding,RequestOrigin $origin): string
    {
        if(PriceSync::backgroundLeft($binding,$this->clock)<1){throw new ReadDeferred('Our price read allowance is unavailable.');}
        $token=$this->minute->acquire($binding,$origin);
        try{return json_encode([$token,$this->runtime?->acquire($binding,$origin)],JSON_THROW_ON_ERROR);}
        catch(\Throwable $error){$this->minute->release($binding,$origin,$token);throw $error;}
    }
    public function release(AccountBinding $binding,RequestOrigin $origin,string $token): void
    {
        [$minute,$runtime]=json_decode($token,true,4,JSON_THROW_ON_ERROR);
        try{if($runtime!==null){$this->runtime->release($binding,$origin,$runtime);}}
        finally{$this->minute->release($binding,$origin,$minute);}
    }
}
