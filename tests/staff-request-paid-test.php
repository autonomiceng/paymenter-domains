<?php
// A request still marked awaiting payment after its invoice is paid tells staff it is paid, never that the customer has not paid.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
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
DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>false]);
same('Paid. Not sent. Orders are off for this account.',RequestFacts::status($op->fresh())['reason'],'A paid request waiting on Orders says so');
$nativeSuccessful=true;echo "Staff paid request status passed\n";
