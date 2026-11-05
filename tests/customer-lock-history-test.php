<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native management completion and customer Blade share durable operation evidence.
require __DIR__.'/Support/management.php';
use Paymenter\Extensions\Servers\Domains\Services\ManagementProgress;

[$d,$driver]=$holding(placeholderComFixtureName('customer-lock-history-placeholder.com'));
require __DIR__.'/Support/owner-pages.php';
app()->forgetInstance(Paymenter\Extensions\Servers\Domains\Services\Storefront::class);
foreach ([false=>'Off',true=>'On'] as $locked=>$word) {
    Illuminate\Support\Facades\Cache::flush();
    $op=$submit($d,$driver,'lock',['locked'=>(bool)$locked]);
    same('succeeded',$management->run($op->id,$driver)->state,'Lock result confirmed');
    $row=$owners->present($d->fresh())->progress[0];
    same('Transfer lock '.$word,$row['label'],'History names the requested lock direction');
    same('Completed',$row['state'],'Lock direction retains its completion state');
}
$nativeSuccessful=true; echo "Customer lock history passed.\n";
