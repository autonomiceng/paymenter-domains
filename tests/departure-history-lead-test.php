<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/departure-confirm-test.php';
use Paymenter\Extensions\Servers\Domains\Admin\Support\DomainHistory;

$nativeSuccessful=false;
// Staff confirmed the departure above, which cancelled the unpaid renewal and closed its request unsent.
$held=$d->fresh();$closed=(int)$renewal->id;
same([['operation_id'=>$closed,'invoice_id'=>(int)$invoice->id,'outcome'=>'cancelled_unpaid']],$held->renewal_policy['departure']['invoices'],'The departure saved the closed renewal');
// Staff History and Activity lead with the departure; the closed request has no separate ended row.
$entries=DomainHistory::entries($held);
same('departed-0',is_array($entries[0])?($entries[0]['__key']??null):null,'History leads with the departure confirmation');
same([],array_values(array_filter($entries,fn($e)=>is_array($e)&&($e['__key']??null)==='ended-'.$closed)),'The closed renewal adds no separate ended row');
same(1,count(array_filter($entries,fn($e)=>$e instanceof Paymenter\Extensions\Servers\Domains\Models\Operation&&(int)$e->id===$closed)),'The renewal request itself stays in History');
// Customer History: the departure's own rows tell what happened to the renewal, so the request adds no row.
$progress=$owners->present($held)->progress;
same([],array_values(array_filter($progress,fn($row)=>($row['label']??null)==='Renewal')),'The closed renewal adds no customer History row');
echo "A confirmed departure leads History with its own rows and adds no generic row for the request it closed.\n";
$nativeSuccessful=true;
