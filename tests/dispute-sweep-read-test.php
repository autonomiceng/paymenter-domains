<?php
// Lost-dispute read on the system checklist; the unread case is tests/dispute-sweep-unread-test.php. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use App\Models\Gateway;
use Illuminate\Support\Facades\{DB,Log};
use Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist;
use Paymenter\Extensions\Servers\Domains\Services\{DisputeRecoveries,Installation};

/** $readable: the Stripe gateway class has lostDisputes. One file per case, since the class is fixed once loaded. */
function disputeSweepCase(bool $readable): void
{
    (new Installation)->install();
    $warnings=0;Log::listen(static function($event)use(&$warnings):void{if($event->level==='warning'){$warnings++;}});
    $failing=static fn()=>count(AccountChecklist::system()['failing']);
    $before=$failing();
    $gateway=Gateway::create(['extension'=>'AeStripe','name'=>'Synthetic Stripe','type'=>'gateway','enabled'=>false]);
    same($before,$failing(),'A disabled Stripe gateway adds no system check');
    $gateway->update(['enabled'=>true]);
    same($before+($readable?0:1),$failing(),$readable?'A Stripe gateway that lists lost disputes adds no system check':'A Stripe gateway without lostDisputes adds exactly one failing system check');
    $recovery=new DisputeRecoveries(new \Paymenter\Extensions\Servers\Domains\Data\SystemClock);
    $recovery->sweep();$recovery->sweep();
    same(0,DB::table('domains_obligation_alerts')->count(),'The sweep opens no hold or retry record');
    same($readable?0:1,$warnings,'Two sweeps write one throttled warning only when lost disputes cannot be read');
}
class ReadDisputeGateway extends \App\Classes\Extension\Gateway {
    public function pay(\App\Models\Invoice $invoice,$total){return '';}
    public function lostDisputes(int $afterAt,string $afterKey,int $limit):array{return [];}
}
class_alias(ReadDisputeGateway::class,'Paymenter\\Extensions\\Gateways\\AeStripe\\AeStripe');
disputeSweepCase(true);
$nativeSuccessful=true;echo "Read lost dispute checks passed.\n";
