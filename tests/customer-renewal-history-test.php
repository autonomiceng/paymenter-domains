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
// Ended and stopped renewals read the request word staff read, through the owner page.
$cases=[['rejected',null,'Failed'],['held',null,'Under review'],['uncertain',null,'Under review'],['rejected','renewal_cancelled_unpaid','Cancelled']];
foreach ($cases as [$state,$code,$word]) {
    Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$renewal->id)->update(['state'=>$state,'safe_code'=>$code]);
    same([[$word,null]],array_map(static fn($row)=>[$row['state'],$row['note']],$rows()),'History reads a '.$state.($code?' '.$code:'').' renewal as '.$word);
}
// Money that reached an early renewal after it closed reads as account credit, from the request's retained credit.
// Retained evidence is frozen against model writes, so the fixture writes the stored columns as billing left them.
$early=Paymenter\Extensions\Servers\Domains\Models\Operation::findOrFail($renewal->id);$seal=static fn(array $value)=>Illuminate\Support\Facades\Crypt::encryptString(json_encode($value));
Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$renewal->id)->update(['state'=>'rejected','safe_code'=>'renewal_cancelled_unpaid',
    'policy'=>$seal([...($early->policy??[]),'unpaid_retirement'=>['reason'=>'Early renewal not paid']]),
    'funding'=>$seal(['late_credits'=>[['payments'=>[['id'=>9101,'amount_minor'=>1250]],'amount'=>'12.50','currency'=>'USD','to'=>'account_credit','credit_id'=>null,'at'=>'2026-11-10T08:00:00+00:00']]])]);
same([['Cancelled','Paid after this invoice closed. We added $12.50 to account credit. You can apply it on an invoice.']],
    array_map(static fn($row)=>[$row['state'],$row['note']],$rows()),'A closed early renewal with late money names the account credit');
$nativeSuccessful=true; echo "Customer renewal history passed.\n";
