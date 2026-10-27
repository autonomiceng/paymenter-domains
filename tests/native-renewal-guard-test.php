<?php
// Enom 444f31c native-recalc/billing integration scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/reconciliation-fixture.php';
use App\Models\{Coupon, Invoice, Service};
use Illuminate\Support\Facades\{DB, Queue};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Price};
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, HolderPricing, HoldingLocks, NativeDomainGuard, RetailLine};

$guard = new NativeDomainGuard(new HolderPricing($prices), $clock); $guard->boot(); Queue::fake();
[$original] = $prepare(placeholderComFixtureName('native-guard-placeholder.com')); same('succeeded', $reconcile->run($original->id)->state, 'Real completed holding');
$d = $original->domain; $service = $d->service; $before = DB::table('invoice_items')->where('id', $original->invoice_item_id)->first();
// Execute the actual native admin form's Recalculate Price action and EditRecord persistence method.
$page = new App\Admin\Resources\ServiceResource\Pages\EditService;
$schema = Filament\Schemas\Schema::make($page);
$form = App\Admin\Resources\ServiceResource::form($schema);
$field = collect($form->getFlatComponents(true))->first(fn ($component) => $component instanceof Filament\Forms\Components\TextInput && $component->getName() === 'price');
expect($field !== null, 'Native price field found');
$actions = $field->getHintActions(); $action = reset($actions);
$calculated = null;
$action->evaluate($action->getActionFunction(), ['component' => $field, 'service' => $service]);
$calculated = $field->getState();
same($service->calculatePrice(), number_format($calculated, 2, '.', ''), 'Actual native admin recalc follows acquisition plan');
expect(number_format($calculated, 2, '.', '') !== RetailLine::decimal($service->price), 'Acquisition recalc differs from holder annual retail');
$save = new ReflectionMethod($page, 'handleRecordUpdate');
// Take the real outer holding prefix before the actual native persistence method.
$refuseSave = static function (array $changes, string $message) use ($d, $save, $page): void {
    $before = $d->service->fresh()->getRawOriginal();
    try {
        DB::transaction(static function () use ($d, $save, $page, $changes): void {
            $locked = HoldingLocks::take($d);
            $save->invoke($page, $locked['service'], $changes);
        });
    } catch (DomainException $e) {
        same($message, $e->getMessage(), 'Exact locked native save refusal');
        same($before, $d->service->fresh()->getRawOriginal(), 'Rejected native save leaves the persisted service unchanged');
        return;
    }
    expect(false, 'Expected locked native save refusal');
};
$priceRefusal = 'Managed pricing authority requires a dedicated audited transition.';
$refuseSave(['price' => $calculated], $priceRefusal); // Dirty acquisition price differs from holder retail.
$refuseSave(['plan_id' => null], 'Managed service binding is permanent.');
// Even a price matching retail requires owned authority, with fresh or expired quotes.
DB::table('services')->where('id', $service->id)->update(['price' => '9.99']);
$freshPrice = (new HolderPricing($prices))->renewal($d, 'USD', $clock->now());
same('10.40', $freshPrice->retail, 'Native matching-price scenario uses current holder retail');
expect(!$freshPrice->retained, 'Matching-price scenario starts with a fresh quote');
$refuseSave(['price' => '10.40'], $priceRefusal);
$priorInstant = $clock->instant; $clock->instant = $clock->now()->modify('+2 days');
expect((new HolderPricing($prices))->renewal($d, 'USD', $clock->now())->retained, 'Quote is stale in the locked price-save scenario');
$refuseSave(['price' => '10.40'], $priceRefusal);
$clock->instant = $priorInstant;
DB::table('services')->where('id', $service->id)->update(['price' => '10.40']);
$delete = (new ReflectionMethod($page, 'getHeaderActions'))->invoke($page)[0]; $delete->record($service);
commerceReject(fn () => $delete->evaluate($delete->getActionFunction(), ['data' => ['deleteExtensionServer' => false], 'record' => $service]));
expect(Service::whereKey($service->id)->exists(), 'Actual native service delete action preserves holding');
foreach ([[new App\Admin\Resources\UserResource\Pages\EditUser, $user],
    [new App\Admin\Resources\OrderResource\Pages\EditOrder, $service->order]] as [$parentPage, $record]) {
    $parentDelete = (new ReflectionMethod($parentPage, 'getHeaderActions'))->invoke($parentPage)[0]; $parentDelete->record($record);
    commerceReject(fn () => $parentDelete->evaluate($parentDelete->getActionFunction()));
    expect($record->fresh() !== null, 'Native parent delete action cannot cascade away a holding');
}

$otherOwner = commerceUser(); $order = $service->order;
commerceReject(fn () => $order->update(['user_id' => $otherOwner->id]));
same($user->id, (int) $order->fresh()->user_id, 'Holding order cannot be reparented');
// An already-drifted historical parent must also fail closed before a native cascade.
DB::table('orders')->where('id', $order->id)->update(['user_id' => $otherOwner->id]);
commerceReject(fn () => $otherOwner->delete());
DB::table('orders')->where('id', $order->id)->update(['user_id' => $user->id]);
$otherOwner->delete(); expect(Service::whereKey($service->id)->exists(), 'Unrelated owner deletion leaves holding intact');
foreach ([$user, $order->fresh(), $service, $product->fresh(), $service->plan, Invoice::findOrFail($original->invoice_id)] as $retained) {
    $spoofed = $retained->fresh()->forceFill(['id' => 999999]);
    commerceReject(fn () => $spoofed->delete());
    expect($retained->fresh() !== null, 'Original-key deletion guard preserves retained native evidence');
}
commerceReject(fn () => $service->fresh()->forceFill(['id' => 999999])->save());
commerceReject(fn () => App\Models\InvoiceItem::findOrFail($original->invoice_item_id)->forceFill(['id' => 999999])->save());
same('10.40', RetailLine::decimal($service->fresh()->price), 'Native admin cannot persist acquisition retail as renewal');
same((array) $before, (array) DB::table('invoice_items')->where('id', $original->invoice_item_id)->first(), 'Admin recalc never reprices issued invoice');
foreach (['plan_id' => null, 'product_id' => $native->product($tlds[1], 'register')->id, 'quantity' => 2,
    'currency_code' => 'EUR', 'user_id' => commerceUser()->id, 'coupon_id' => 99, 'price' => '0.00'] as $key => $value) {
    commerceReject(fn () => DB::transaction(fn () => $service->fresh()->update([$key => $value])));
}
commerceReject(fn () => $d->update(['price_mode' => 'complimentary']));
commerceReject(fn () => $d->update(['billing_managed' => false]));
commerceReject(fn () => $product->update(['server_id' => $tlds[1]->server_id]));
commerceReject(fn () => $service->plan->update(['billing_period' => 3]));
commerceReject(fn () => $service->plan->delete()); commerceReject(fn () => $product->delete());
commerceReject(fn () => $service->configs()->create(['config_option_id' => 1, 'config_value_id' => 1]));
commerceReject(fn () => Invoice::findOrFail($original->invoice_id)->items()->create(['reference_type' => Service::class,
    'reference_id' => $service->id, 'quantity' => 1, 'price' => '10.40', 'description' => 'Unexpected renewal']));
// Exclusive tax must use the native gross service-price basis, once, without coupon discounts.
config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive']);
App\Models\TaxRate::create(['name' => 'Synthetic', 'country' => 'US', 'state' => null, 'rate' => 10]);
$server = $product->fresh()->server; $server->settings()->create(['key' => 'domains_manual_renewals_enabled', 'value' => true, 'type' => 'boolean']);
$billing = new DomainBilling($bindings, $guard, $clock); $bill = $billing->invoice($d->id, $user);
same('11.44', RetailLine::decimal($service->fresh()->price), 'Holder projection adds exclusive tax once');
same('11.44', $bill->items->sole()->price, 'Invoice shares native gross tax basis');
DB::table('services')->where('id', $service->id)->update(['price' => '9.99']);
$refuseSave(['price' => '11.44'], $priceRefusal);
same('9.99', RetailLine::decimal($service->fresh()->price), 'Native price edits require an owned projection even at current retail');
// Unknown-cost retained retail also grants no manual edit authority under Option E.
$price = Price::where(['server_id' => $server->id, 'action' => 'renew'])->sole(); $price->update(['wholesale' => null, 'revision' => $price->revision + 1]);
DB::table('services')->where('id', $service->id)->update(['price' => '9.99']);
$refuseSave(['price' => '11.44'], $priceRefusal);
$service->fresh()->update(['status' => 'suspended', 'expires_at' => '2030-01-01']);
same('suspended', $service->fresh()->status, 'Lifecycle-only save proceeds during unknown price');
same(null, $service->fresh()->expires_at, 'Native date normalization survives lifecycle save');
same('9.99', RetailLine::decimal($service->fresh()->price), 'Lifecycle save keeps the unchanged old price');
// A retained complimentary mode permits lifecycle saves, without granting price-edit authority.
DB::table('domains_domains')->where('id', $d->id)->update(['price_mode' => 'complimentary']);
DB::transaction(static function () use ($d): void {
    $locked = HoldingLocks::take($d); $locked['service']->update(['status' => 'active']);
});
same('active', $service->fresh()->status, 'Complimentary lifecycle save proceeds');
same('9.99', RetailLine::decimal($service->fresh()->price), 'Complimentary lifecycle save preserves retail');
$refuseSave(['price' => '11.44'], $priceRefusal);
DB::table('domains_domains')->where('id', $d->id)->update(['price_mode' => 'standard']);
// Retained coupon association is usage accounting only; zero or missing acquisition-plan prices grant nothing.
$coupon = Coupon::create(['code' => 'RETAIN', 'type' => 'percentage', 'value' => 100, 'recurring' => 0]);
DB::table('services')->where('id', $service->id)->update(['coupon_id' => $coupon->id, 'status' => 'active']);
$service->plan->prices()->delete();
$refuseSave(['price' => $service->fresh()->calculatePrice()], $priceRefusal);
same('9.99', RetailLine::decimal($service->fresh()->price), 'Coupon/plan-null zero cannot create complimentary renewal');
same($coupon->id, (int) $service->fresh()->coupon_id, 'Coupon usage association retained');
foreach ([App\Jobs\Server\CreateJob::class, App\Jobs\Server\UnsuspendJob::class,
    Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation::class] as $job) { Queue::assertNotPushed($job); }
echo "Native admin recalc/save, tax, config, annual mapping, coupon and lifecycle guards passed\n"; $nativeSuccessful = true;
