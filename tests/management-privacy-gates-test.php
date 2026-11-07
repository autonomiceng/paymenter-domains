<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic privacy native coverage.
declare(strict_types=1);
require __DIR__.'/Support/management-privacy.php';
use App\Models\{Invoice,InvoiceItem,InvoiceTransaction,Role};
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Services\{ManagementEvidence,Refunds};
[$d,$driver]=$holding(placeholderComFixtureName('manage-privacy-gates-placeholder.com'));$state->privacy=false;$off=new \Paymenter\Extensions\Servers\Domains\Services\DomainManagement($bindings,$clock);$n=Invoice::count();commerceReject(fn()=>$off->request($user,$d->id,'privacy_purchase',[],$key(),$driver));commerceReject(fn()=>$management->request($staff,$d->id,'privacy_purchase',[],$key(),$driver));same([],$state->calls,'Disabled/staff paid requests no provider reads');same($n,Invoice::count(),'Disabled/staff requests no customer invoice');
foreach(['privacy_purchase','privacy_renew'] as $action) {
    [$d,$driver]=$holding(placeholderComFixtureName(str_replace('_','-',$action).'-balance-placeholder.com'));
    $state->privacy=$action==='privacy_renew';$state->enabled=true;$state->expiry='2027-10-18';$state->cost='8.00';
    $op=$submit($d,$driver,$action);$pay($op);$state->balance='999,999,999,999,999,999,999.00';
    foreach([1,2] as $pass) {
        $result=$management->run($op->id,$driver);
        same(1,count(array_filter($state->calls,fn($v)=>$v[0]==='GetBalance')),'The invalid balance reached preflight exactly once');
        same('held',$result->state,'Unrepresentable balance never permits paid privacy');
        same(0,count(array_filter($state->calls,fn($v)=>in_array($v[0],['PurchaseServices','RenewServices'],true))),'Invalid balance sends no privacy write');
    }
    $row=DB::table('domains_registrar_balances')->where('server_id',$op->server_id)->sole();
    same('read_failed',$row->last_error_code,'Recording and spending agree on unknown balance');
}
$state->balance='100.00';
[$d,$driver]=$holding(placeholderComFixtureName('manage-privacy-leap-placeholder.com'));$clock->instant=new \DateTimeImmutable('2028-02-29T12:00:00Z');$state->privacy=false;$op=$submit($d,$driver,'privacy_purchase');$pay($op);same('succeeded',$management->run($op->id,$driver)->state,'Leap-day purchase uses no-overflow year');same('2029-02-28',$op->fresh()->payload['privacy_terms']['expected'],'Leap-year target frozen at dispatch');same('WHOIS privacy: manage-privacy-leap-placeholder.com (1 year, Feb 29, 2028 to Feb 28, 2029)', InvoiceItem::findOrFail($op->invoice_item_id)->description, 'Privacy invoice leap day clamps without overflow');
$clock->instant=new \DateTimeImmutable('2026-10-18T12:00:00Z');

$nativeSuccessful=true;echo "management-privacy-gates-test.php passed\n";
