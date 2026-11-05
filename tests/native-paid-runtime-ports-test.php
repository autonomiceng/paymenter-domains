<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$compositionCalls=0;
$runtimeCompositionWitness=static function()use(&$compositionCalls):void {$compositionCalls++;};
require __DIR__.'/Support/native-paid-fixture.php';
// A genuine retained unpaid order reaches worker composition but cannot enter the exchange.
$probe=$new(placeholderComFixtureName('paid-worker-ports-placeholder.com'),paid:false);
$compositionCalls=0;$before=$writer->calls;
$work->operation((int)$probe->id);
same(1,$compositionCalls,'Worker resolves the registered offline composition');
same($before,$writer->calls,'An unpaid worker wake makes no acquisition send');
same('awaiting_payment',$probe->fresh()->state,'Unpaid worker wake preserves the retained order');
$nativeSuccessful=true;
echo "Native paid runtime ports: ok\n";
