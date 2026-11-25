<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';require __DIR__.'/Support/WireLimit.php';
use Paymenter\Extensions\Servers\Domains\Services\Runtime;
[$op,$d,$b]=$makeRenewal(placeholderComFixtureName('capacity-renewal-placeholder.com'));[$engine,$writer]=$compose($b);$before=$state->writes;
app()->instance(Runtime::class,new Runtime);$limit=new WireLimit(12);app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class,$limit);
$result=$engine->run($op->id,$writer);same(13,$limit->calls,'Initial/final read wires admitted before refused renewal');same($before,$state->writes,'Capacity refusal has zero renewal exchanges');same('queued',$result->state,'Capacity refusal defers renewal');expect(empty($result->attempt),'Renewal refusal precedes immutable seal');
$limit->allowed=100;Illuminate\Support\Facades\Cache::flush();$result=$engine->run($op->id,$writer);same('pending',$result->state,'Renewal recovers after capacity returns');same($before+1,$state->writes,'Exactly one renewal exchange after recovery');
echo "Installed physical renewal capacity refusal and recovery passed.\n";$nativeSuccessful=true;
