<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// A renewal is a change to the domain, so the owner's History lists it once paid.
require __DIR__.'/Support/management.php';
[$d,$driver]=$holding(placeholderComFixtureName('customer-renewal-history-placeholder.com'));
require __DIR__.'/Support/owner-pages.php';
$registration=$d->operations()->where('action','register')->firstOrFail();
$renewal=$registration->replicate();$renewal->fill(['action'=>'renew','operation_key'=>'renew:history:customer-renewal','invoice_id'=>null,'invoice_item_id'=>null,'state'=>'awaiting_payment','safe_code'=>null]);$renewal->save();
$rows=static fn()=>array_values(array_filter($owners->present($d->fresh())->progress,static fn($row)=>$row['label']==='Renewal'));
same([],$rows(),'An unpaid renewal invoice is not a change yet');
foreach (['queued'=>'In progress','succeeded'=>'Completed'] as $state=>$word) {
    Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$renewal->id)->update(['state'=>$state]);
    same([$word],array_column($rows(),'state'),'History lists a '.$state.' renewal');
}
$nativeSuccessful=true; echo "Customer renewal history passed.\n";
