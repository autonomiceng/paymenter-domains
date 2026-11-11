<?php
// A request whose invoice is paid tells staff it is paid on the request list and its page, never that the customer has not paid.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\{ListOperations,ViewOperation};
use Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts;
$op=$held('staff-request-paid-placeholder.com');\Illuminate\Support\Facades\Auth::setUser($staff);
DB::table('domains_operations')->where('id',$op->id)->update(['state'=>'awaiting_payment','safe_code'=>null]);
DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'pending']);
$unpaid=RequestFacts::status($op->fresh());
same(['Awaiting payment','Customer has not paid the invoice. Not sent yet.'],[$unpaid['label'],$unpaid['reason']],'An unpaid request waits on the customer');
DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'paid']);
$account=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$op->server_id)->sole();
DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>true]);
$paid=RequestFacts::status($op->fresh());
same(['In progress','Paid. It goes to the registrar automatically. Nothing to do yet.'],[$paid['label'],$paid['reason']],'A paid request not yet picked up reads paid');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertSee('Paid. It goes to the registrar automatically.')->assertDontSee('Customer has not paid the invoice');
// The request list's Note reads what its Status column does: paid, never that the customer has not paid.
$note=static function()use($staff,$op):?string{\Illuminate\Support\Facades\Auth::setUser($staff);
    return Livewire::actingAs($staff)->test(ListOperations::class)->instance()->getTable()->getColumn('note')->record($op->fresh())->getState();};
same('Paid. It goes to the registrar automatically. Nothing to do yet.',$note(),'The list note reads paid while the request waits to be picked up');
// Picked up and sent, the paid request keeps saying where it is until the registrar answers, on the list and the request page.
same('paid_invoice',$op->fresh()->funding_basis,'Fixture: the order was paid by invoice');
foreach(['queued'=>'Paid. It goes to the registrar automatically. Nothing to do yet.','running'=>'Paid. Sending to the registrar now. Nothing to do yet.',
    'pending'=>'Paid. Sent to the registrar. Waiting for it to confirm. Nothing to do yet.'] as $state=>$words){
    DB::table('domains_operations')->where('id',$op->id)->update(['state'=>$state,'safe_code'=>null]);
    same([$words,$words],[$note(),RequestFacts::status($op->fresh())['reason']],'A paid '.$state.' request says where it is');
}
// A paid transfer still waiting on its auth code is not on its way, so it never says it goes on its own.
$saved=DB::table('domains_operations')->where('id',$op->id)->first(['action','payload']);
DB::table('domains_operations')->where('id',$op->id)->update(['state'=>'queued','safe_code'=>null,'action'=>'transfer']);
$transfer=$op->fresh();$transfer->payload=[...($transfer->payload??[]),'epp'=>''];
same('code',Paymenter\Extensions\Servers\Domains\Admin\Support\OperationActions::customerOwes($transfer),'Fixture: the transfer has no auth code');
expect(!str_contains((string)RequestFacts::reason($transfer,false),'goes to the registrar automatically'),'A transfer without its auth code does not read as on its way');
DB::table('domains_operations')->where('id',$op->id)->update(['action'=>$saved->action,'payload'=>$saved->payload]);
DB::table('domains_operations')->where('id',$op->id)->update(['state'=>'awaiting_payment','safe_code'=>null]);
DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>false]);
same('Paid. Not sent. Orders are off for this account.',RequestFacts::status($op->fresh())['reason'],'A paid request waiting on Orders says so');
// List projections use one prefetched invoice batch for both status and reason.
$page=Livewire::actingAs($staff)->test(ListOperations::class)->instance();
$records=$page->getTableRecords();
$off=Paymenter\Extensions\Servers\Domains\Admin\Support\OperationQueries::ordersOffAccounts();
DB::flushQueryLog();DB::enableQueryLog();
foreach($records as $record){RequestFacts::status($record,accounts:$off);RequestFacts::reason($record,accounts:$off);}
$invoiceReads=array_filter(DB::getQueryLog(),fn($q)=>str_contains($q['query'],'from "invoices"'));
DB::disableQueryLog();
same([],$invoiceReads,'Already-loaded list rows do not read invoices per status, reason or column');
$nativeSuccessful=true;echo "Staff paid request status passed\n";
