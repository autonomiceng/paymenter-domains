<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real runtime composition with the fixture's admitted renewal transport.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Services\{RuntimeComposition,RuntimeDispatch,RuntimePorts,RuntimeSchema,RuntimeWork,WriteAcceptance,WriteFeatures,RenewalAcceptance,TransferPrecheck,Rdap};
$renewalRuntime=static function($b,$engine,$writer,$completion)use($renewBindings,$clock):RuntimeWork {
    $offline=static fn()=>throw new LogicException('No unrelated provider exchange');
    $composition=RuntimeComposition::make($b,$renewBindings,$clock,new WriteFeatures(renewal:true),new WriteAcceptance($b,true,true,25,catalogue:$GLOBALS['caps']??null),new RenewalAcceptance($b,true,true,25,true),
        new TransferPrecheck(new Rdap($offline)),new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Registration($b,$offline),
        new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Transfer($b,$offline),$offline,new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Management($b,$offline));
    $composition['renewal']=$engine;$composition['renewal_writer']=$writer;$composition['renewal_completion']=$completion;
    $runtime=new RuntimePorts;$runtime->register($b->fingerprint,static fn()=>$composition);RuntimeSchema::install();app()->instance(RuntimeDispatch::class,new RuntimeDispatch($clock));
    return new RuntimeWork($clock,$runtime);
};
