<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{Cache,Log};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
/** Expected holds share an atomic daily slot; actionable failures always retain a safe row locator. */
final readonly class RuntimeDiagnostics
{
    public function __construct(private Clock $clock) {}
    public function ineligible(string $producer,RuntimeReason $reason): void
    {
        // Unsupported/local caches cannot enforce a process-independent daily limit: keep those holds quiet.
        if(!in_array(config('cache.stores.'.config('cache.default').'.driver'),['database','redis','memcached'],true)){return;}
        $now=$this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $key='domains:runtime-held:'.$producer.':'.$reason->value.':'.$now->format('Y-m-d');
        try{$first=Cache::add($key,true,max(1,$now->modify('tomorrow')->getTimestamp()-$now->getTimestamp()));}
        catch(\Throwable){return;}
        if($first){Log::info('Domains runtime work ineligible.',['producer'=>$producer,'reason'=>$reason->value]);}
    }
    public function attempt(string $producer,\Closure $run,?int $row=null): void
    {
        try{$run();}
        catch(RuntimeIneligible $e){$this->ineligible($producer,$e->reason);}
        catch(\Throwable $e){Log::warning('Domains runtime work failed.',['producer'=>$producer,'reason'=>RuntimeReason::failure($e),'row_id'=>$row]);}
    }
}
