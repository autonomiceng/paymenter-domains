<?php
// Synthetic run policy and paired UTC/monotonic clock. No provider calls.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services {
    function hrtime(bool $number=false): mixed { return $GLOBALS['runBudgetMonotonic']??\hrtime($number); }
}
namespace {
    require_once __DIR__.'/FakeRegistrar.php';
    use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
    use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
    $runClock=new FakeClock(new DateTimeImmutable('2026-10-27T23:59:59Z'));
    app()->instance(Clock::class,$runClock);
    $GLOBALS['runBudgetMonotonic']=1000000000000;
    function runAdvance(string $interval): void {
        global $runClock;
        $before=$runClock->instant;
        $runClock->instant=$before->modify($interval);
        $GLOBALS['runBudgetMonotonic']+=($runClock->instant->getTimestamp()-$before->getTimestamp())*1000000000;
    }
    function runPolicy(string $run='run1',int $cap=12,int $rolling=20,?string $fingerprint=null): array {
        $policy=['version'=>2,'run_id'=>$run,'generation'=>'generation_'.$run,'cap'=>$cap,'rolling_cap'=>$rolling,
            'ledger_dev'=>1,'ledger_ino'=>2,'ledger_uid'=>1000,'ledger_gid'=>1000,
            'account_fingerprint'=>$fingerprint,'configuration_intent'=>'configure_synthetic'];
        ksort($policy);
        $policy['policy_hash']=hash('sha256',json_encode($policy,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        return $policy;
    }
    function runRefuses(Closure $work,string $why): void {
        try {$work();}catch(Paymenter\Extensions\Servers\Domains\Services\EnomTestCallLimit){return;}
        throw new LogicException('Expected run budget refusal: '.$why);
    }
}
