<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/management.php';
require __DIR__.'/Support/staff-panel.php';
use App\Models\InvoiceTransaction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{Refunds,StaffOperationReview};
staffPanel();app()->instance(Clock::class,$clock);app()->instance(Refunds::class,new Refunds($clock));
$permissions=[Staff::VIEW,'admin.domains.manage','admin.invoices.update',StaffOperationReview::permission('hold'),StaffOperationReview::permission('refund')];
$staff=staffUser($permissions);
foreach(['privacy_purchase','privacy_renew']as$action){
    [$d,$driver]=$holding(placeholderComFixtureName('staff-'.str_replace('_','-',$action).'-placeholder.com'));$state->privacy=$action==='privacy_renew';$state->enabled=$state->privacy;$state->expiry='2027-10-18';$state->cost='unknown';
    $product->server->settings()->updateOrCreate(['key'=>'domains_privacy_price'],['value'=>'5.00','type'=>'string']);
    $op=$submit($d,$driver,$action);DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'paid']);
    InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>$op->amount,'fee'=>'0.00','status'=>'succeeded']);
    $calls=count($state->calls);$original=$op->attempt;
    Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->callAction('hold')->assertNotified('Request on hold');
    same('held',$op->fresh()->state,'Explicit virgin privacy hold');same($original,$op->fresh()->attempt,'Privacy hold retains unsent evidence');
    Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->callAction('refund',['reason'=>'Close the unsent privacy debt'])->assertNotified('Cancelled, credit added');
    same('account_credit',$op->fresh()->funding['refund']['to'],'Privacy exact engine credit');same('active',$d->service->fresh()->status,'Privacy credit keeps domain service');
    same($calls,count($state->calls),'Staff local privacy workflow has no registrar calls');
}
echo "Native virgin paid privacy hold/credit after revoked authority passed.\n";$nativeSuccessful=true;
