<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
expect(is_file(__DIR__.'/../Domains/Services/ComplimentaryAccounts.php'),'Account complimentary renewal must be implemented');
// Native checkout, invoice observers and retained financial locks require the real Paymenter fixture.
require __DIR__.'/Support/renewal-execution.php';
use App\Models\{Invoice,Role};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccounts,ComplimentaryCosts,ComplimentaryInvoices,HoldingLocks,RenewalFunding};
$staff=commerceUser();$role=Role::create(['name'=>'Complimentary funding','permissions'=>['admin.domains.manage','admin.domains.fund','admin.domains.costs']]);$staff->update(['role_id'=>$role->id]);
$enable=fn()=>ComplimentaryAccounts::set($user->id,$staff,true,'Staff account gift',$clock);
$disable=fn()=>ComplimentaryAccounts::set($user->id,$staff,false,'Gift ended',$clock);
[$op,$d,$b,$invoice]=$makeRenewal(placeholderComFixtureName('account-renew-placeholder.com'),false,1,false,$enable);
same(['complimentary','queued','0.00','paid',0.0,0],[$op->funding_basis,$op->state,$op->amount,$invoice->status,(float)$invoice->total,$invoice->transactions()->count()],'Renewal is complimentary with a paid zero receipt');
$runRenewal($op,$b);[$engine,$writer,$completion]=$compose($b);$state->expiry='2028-10-18';$state->after='2028-10-18';$state->charge='8.00';
same('succeeded',$completion->run($op->id)->state,'Real provider proof completes the zero receipt');
require __DIR__.'/Support/owner-pages.php';
$presenter=new \Paymenter\Extensions\Servers\Domains\Services\NativePresenter(new \Paymenter\Extensions\Servers\Domains\Services\OwnedDomains($clock,new \Paymenter\Extensions\Servers\Domains\Services\OwnerPorts),$native,new \Paymenter\Extensions\Servers\Domains\Services\HolderPricing($prices));
$read=fn()=>$presenter->invoice($user,$invoice->id);
expect(isset($read()[$op->invoice_item_id]),'Completed complimentary receipt remains a domain invoice line');
$offset=$invoice->items()->whereKeyNot($op->invoice_item_id)->sole();
foreach([['invoice_items',$offset->id,'price','-7.99'],['invoice_items',$offset->id,'reference_id',$op->service_id],['invoice_items',$op->invoice_item_id,'quantity',2],['invoices',$invoice->id,'user_id',commerceUser()->id],['domains_operations',$op->id,'authority_generation',99],['domains_operations',$op->id,'account_fingerprint',str_repeat('a',64)],['domains_operations',$op->id,'server_id',99999]]as[$table,$id,$key,$value]){
    $old=DB::table($table)->where('id',$id)->value($key);DB::table($table)->where('id',$id)->update([$key=>$value]);
    same([],$read(),'Foreign or unbalanced receipt refuses: '.$table.'.'.$key);
    DB::table($table)->where('id',$id)->update([$key=>$old]);
}
expect(isset($read()[$op->invoice_item_id]),'Restored exact receipt still resolves');
echo "Completed complimentary receipt membership and foreign bindings passed\n";$nativeSuccessful=true;
