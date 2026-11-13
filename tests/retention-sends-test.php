<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-dns.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{HistoryRetention,EnomTestSendLimitSchema};
extract(dnsFixture());$now=1800000000;$account=str_repeat('a',64);
DB::table(EnomTestSendLimitSchema::ACCOUNTS)->insert(['account'=>$account]);
foreach([$now-30*86400-1,$now-30*86400,$now-86400,$now-1] as $at){DB::table(EnomTestSendLimitSchema::SENDS)->insert(['account'=>$account,'command'=>'GetDomains','sent_at'=>$at]);}
if(is_file(dirname(__DIR__).'/Domains/Services/HistoryRetention.php')){HistoryRetention::tick($now);}
same(3,DB::table(EnomTestSendLimitSchema::SENDS)->count(),'TEST sends older than thirty days are deleted');
// The count window remains intact; a second turn at the same time is harmless.
HistoryRetention::tick($now);same(3,DB::table(EnomTestSendLimitSchema::SENDS)->count(),'Repeated sweep does not delete boundary or live sends');
same(2,DB::table(EnomTestSendLimitSchema::SENDS)->where('sent_at','>=',$now-86400)->count(),'Entire live rolling window survives');
echo "TEST send retention preserves thirty-day boundary and rolling count window.\n";

$nativeSuccessful=true;
