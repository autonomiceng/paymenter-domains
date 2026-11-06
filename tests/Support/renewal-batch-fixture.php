<?php
// Shared native acquisition for independent renewal scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
$fixtureRegistrars=['enom'];
require_once __DIR__.'/operation-fixture.php';
// Every scenario retains real checkout, funded registration, reconciliation and its own renewal invoice.
// Batch only acquisition to avoid rebuilding Livewire for each otherwise independent holding.
$registrationNames=$renewalFixtureNames;
$registrationNames=array_map(static fn($name)=>placeholderComFixtureName($name.'-placeholder.com'),$registrationNames);
same(count($registrationNames),count(array_unique($registrationNames)),'Every renewal scenario has an independent acquisition');
$runtime=app()->bound(\Paymenter\Extensions\Servers\Domains\Services\Runtime::class)?app(\Paymenter\Extensions\Servers\Domains\Services\Runtime::class):null;
app()->offsetUnset(\Paymenter\Extensions\Servers\Domains\Services\Runtime::class);
try {
    Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
    $cart=commerceCart($user);
    foreach($registrationNames as $name){commerceAdd($cart,$product,$name);}
    commerceCheckout($user,$cart)->assertHasNoErrors();$registrations=[];
    foreach($registrationNames as $name){
        $registration=\Paymenter\Extensions\Servers\Domains\Models\Domain::where('domain',$name)->sole()->operations()->sole();
        Illuminate\Support\Facades\DB::table('invoices')->where('id',$registration->invoice_id)->update(['status'=>'paid']);
        App\Models\InvoiceTransaction::create(['invoice_id'=>$registration->invoice_id,'amount'=>$registration->amount,'fee'=>'0.00','status'=>'succeeded']);
        $registrations[$name]=$registration;
    }
    $invoice=App\Models\Invoice::findOrFail($registration->invoice_id);
    same(\Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor($invoice->total),\Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor((string)$invoice->transactions()->sum('amount')),'Batch acquisition is exactly funded');
} finally {if($runtime!==null){app()->instance(\Paymenter\Extensions\Servers\Domains\Services\Runtime::class,$runtime);}}
$new=static function(string $name,bool $paid=true,bool $setup=true,string $action='register',int $term=1)use(&$registrations){
    same([true,true,'register',1],[$paid,$setup,$action,$term],'Renewal acquisition keeps the original paid annual registration contract');
    expect(isset($registrations[$name]),'Each acquisition is consumed exactly once: '.$name);
    $op=$registrations[$name];unset($registrations[$name]);return $op->fresh();
};
require __DIR__.'/renewal-execution.php';
