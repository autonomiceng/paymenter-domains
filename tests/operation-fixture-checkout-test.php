<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
use App\Models\Invoice;
use Livewire\Livewire;

$quiet=$new('fixture-quiet-placeholder.com',paid:false);
putenv('PAYMENTER_TEST_RENDER_FIXTURE_CART=1');
try { $rendered=$new('fixture-rendered-placeholder.com',paid:false); }
finally { putenv('PAYMENTER_TEST_RENDER_FIXTURE_CART'); }
$facts=static function($op):array {
    $bill=Invoice::findOrFail($op->invoice_id);
    return [$op->action,$op->state,$op->amount,$op->currency,$bill->status,
        $bill->items()->sole()->price,$op->domain->service->price,$op->domain->service->status,
        $op->payload['contacts'],$op->funding];
};
same($facts($rendered),$facts($quiet),'Fixture checkout preserves native debt, contact, state and funding facts');
expect($quiet->invoice_id!==$rendered->invoice_id,'Each fixture retains its own invoice');
$cart=commerceCart($user);commerceAdd($cart,$product,'fixture-visible-placeholder.com');
Livewire::actingAs($user)->withCookies(['cart'=>$cart->ulid])->test(App\Livewire\Cart::class)
    ->assertSee('fixture-visible-placeholder.com');
$nativeSuccessful=true;
