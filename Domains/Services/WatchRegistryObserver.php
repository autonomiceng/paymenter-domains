<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\DnsReader;
use Paymenter\Extensions\Servers\Domains\Dns\{QuotaDnsReader,RegistryContext};
/** All public I/O is outside transactions, under both the existing DNS pool and separate HTTP family. */
final class WatchRegistryObserver
{
    private \Closure $clock; private \Closure $factory;
    public function __construct(private WatchRegistryChecks $checks,private DnsReader $reader,?callable $clock=null,?callable $factory=null)
    {
        $this->clock=$clock===null?static fn():int=>time():\Closure::fromCallable($clock);
        $this->factory=$factory===null?static fn($dns,$snapshot)=>new PublicRegistryReader($dns,$snapshot):\Closure::fromCallable($factory);
    }
    public function observe(int $id,int $owner): bool
    {
        if (DB::transactionLevel()!==0) { return false; }
        $t=$this->checks->begin($id,$owner,($this->clock)()); if (!$t) { return false; }
        $pool=new PublicDnsQuota(); $token=$pool->acquire(($this->clock)()); $quota=new RdapQuota();
        if ($token===null) { return $this->checks->finish($t,['reason'=>'deferred'],'',($this->clock)()); }
        try {
            $dns=new QuotaDnsReader($this->reader,$pool,$token,$this->clock);
            $saved=DB::table('domains_watch_schedule')->where('scope','bootstrap')->first();
            $snapshot=$saved?->data===null?null:json_decode($saved->data,true,32,JSON_THROW_ON_ERROR);
            $reader=($this->factory)($dns,$snapshot);
            $context=new RegistryContext($this->clock,fn($url)=>$quota->reserve($url,($this->clock)()),fn($url,$r)=>$quota->complete($url,$r,($this->clock)()));
            $refreshed=$snapshot!==null && (int)$saved->due_at<=($this->clock)() && $reader->refresh($context);
            $result=$reader->read($t->name,$context);
            if ($dns->saturated) { $result=['reason'=>'deferred']; }
            if ($reader->snapshot()!==null && ($snapshot===null || $refreshed)) {
                $changed=$saved?->data===null || $reader->revision()!==hash('sha256',json_encode(json_decode($saved->data,true,32,JSON_THROW_ON_ERROR),JSON_THROW_ON_ERROR));
                DB::table('domains_watch_schedule')->updateOrInsert(['scope'=>'bootstrap'],['data'=>json_encode($reader->snapshot(),JSON_THROW_ON_ERROR),'due_at'=>($this->clock)()+604800]);
                // Only a changed accepted snapshot reopens coverage gaps; refreshes renew the cache deadline.
                if ($changed) { DB::table('domains_watch_work')->where('kind','expiry')->where('safe_code','no_coverage')->whereNull('claim')->update(['due_at'=>($this->clock)()]); }
            }
            return $this->checks->finish($t,$result,$reader->revision(),($this->clock)(),$quota->blockedUntil);
        } finally { $pool->release($token); }
    }
}
