<?php
// Enom 444f31c/b561b9f domain-billing and billing-integration scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/reconciliation-fixture.php';
use App\Models\{Invoice, Service};
use Illuminate\Support\Facades\{Artisan, DB, Queue};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation, Price};
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, HolderPricing, NativeDomainGuard, StaffSettingsReview, StaffSettingsWrites};

$guard = new NativeDomainGuard(new HolderPricing($prices), $clock); $guard->boot(); $guard->boot();
$billing = new DomainBilling($bindings, $guard, $clock,catalogue: $GLOBALS['caps']??null); Queue::fake();
[$original]=$prepare(placeholderComFixtureName('billing-cancellation-placeholder.com'));
same('succeeded',$reconcile->run($original->id)->state,'Real completed cancellation anchor');
$d=$original->domain()->firstOrFail();$s=$d->service;
$server = $product->server; $setting = static fn ($key, $value) => $server->settings()->updateOrCreate(['key' => $key], ['value' => $value, 'type' => 'boolean']);
$setting('domains_billing_enabled',true);$setting('domains_manual_renewals_enabled',false);
require __DIR__.'/Support/cancelled-billing.php';
echo "Native cancellation billing passed\n";$nativeSuccessful=true;
