<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
use App\Models\InvoiceTransaction;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\ManagementPreparation;
[$d,$driver]=$holding(placeholderComFixtureName('manage-dispatch-day-placeholder.com')); $state->privacy=false;
$product->server->settings()->create(['key'=>'domains_privacy_price','value'=>'5.00','type'=>'string']);
$op=$submit($d,$driver,'privacy_purchase'); $requestDay=$clock->now();
DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'paid']);
InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>$op->amount,'fee'=>'0.00','status'=>'succeeded']);
$clock->instant=$requestDay->modify('+1 day'); Carbon\Carbon::setTestNow($clock->now()); Carbon\CarbonImmutable::setTestNow($clock->now());
same('succeeded',$management->run($op->id,$driver)->state,'Paid purchase dispatch on next day uses dispatch target');
same(Carbon\CarbonImmutable::instance($clock->now())->addYearNoOverflow()->format('Y-m-d'),$op->fresh()->payload['privacy_terms']['expected'],'Target fixed at dispatch');
$check=static function(string $registrar,string $action,array $input,array $prepared): void {
    $op=new Operation(['registrar_key'=>$registrar,'action'=>$action,'policy'=>['management'=>['input'=>$input]]]);
    commerceReject(fn()=>ManagementPreparation::requireFrozen($op,$prepared));
};
$check('enom','lock',['locked'=>false],['locked'=>false,'old_ip'=>'192.0.2.1']);
$terms=['base'=>'2027-10-18','expected'=>'2028-10-18'];
$check('enom','privacy_renew',['privacy_terms'=>$terms],['privacy_terms'=>['base'=>'2027-10-19','expected'=>'2028-10-18']]);
$check('enom','privacy_renew',['privacy_terms'=>$terms],['privacy_terms'=>['base'=>'2027-10-18','expected'=>'2028-10-19']]);
$nativeSuccessful=true; echo "Management preparation obligation boundaries passed\n";
