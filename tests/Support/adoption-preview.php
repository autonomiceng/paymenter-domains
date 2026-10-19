<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/commerce.php';
require_once __DIR__.'/native-commerce.php';
require_once __DIR__.'/staff-panel.php';
require_once __DIR__.'/adoption-wire.php';
use App\Models\{Order, Service};
use Illuminate\Support\Facades\Queue;
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment};
use Paymenter\Extensions\Servers\Domains\Data\Adoption\{StaffContext, Target};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\Client;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AdoptionMapping, AdoptionReader, AdoptionSchema, HoldingProvenanceSchema, PslReviewSchema, WatchSchema, RegistrarRegistry};
function adoptionFixture(): array
{
    Queue::fake(); extract(commerceFixture()); AdoptionSchema::install(); HoldingProvenanceSchema::install(); PslReviewSchema::install(); WatchSchema::install();
    $owner = commerceUser(); $other = commerceUser(); $staff = staffUser(['admin.domains.view', 'admin.domains.adopt.preview']);
    $tld = $tlds[0]; $product = $native->product($tld, 'register'); $plan = $native->annualPlan($product);
    $order = Order::create(['user_id' => $owner->id, 'currency_code' => 'USD']);
    $service = Service::create(['user_id' => $owner->id, 'order_id' => $order->id, 'product_id' => $product->id,
        'plan_id' => $plan->id, 'quantity' => 1, 'price' => '10.00', 'currency_code' => 'USD', 'status' => 'pending']);
    $property = $service->properties()->create(['key' => 'domain', 'value' => 'example.com']);
    $clock = new AdoptionClock; $wire = new AdoptionWire;
    $wire->payloads['GetDomainInfo'] = str_replace('synthetic-uid', 'account-enom', $wire->payloads['GetDomainInfo']);
    $registry = new RegistrarRegistry;
    $registry->register('enom', ['uid', 'token'], new class implements AccountVerifier {
        public function verify(Environment $environment, #[SensitiveParameter] array $credentials): string { return $credentials['uid']; }
    }, fn (AccountBinding $b, array $c) => new Driver(new Client($b, $c['uid'], $c['token'], $wire), ['com']));
    $bindings = new AccountBindings($registry);
    $binding = $bindings->configure($tld->server_id, 'enom', Environment::Test, ['uid' => 'account-enom', 'token' => 'synthetic-secret']);
    $mapping = new AdoptionMapping($native, $prices, $clock); $reader = new AdoptionReader($bindings, $mapping, $clock, $wire);
    $target = new Target('example.com', $service->id, $owner->id, $tld->server_id, Environment::Test);
    $context = new StaffContext($staff->id, 'Reviewed synthetic import mapping');
    return compact('owner', 'other', 'staff', 'order', 'service', 'property', 'tld', 'product', 'plan', 'clock', 'wire', 'bindings', 'binding', 'mapping', 'reader', 'target', 'context', 'prices', 'native');
}

/** Fixture-only reviewed pending row; production creation remains conversion-owned. */
function adoptionPendingFixture(App\Models\Service $service, AccountBinding $binding, array $overrides = []): \Paymenter\Extensions\Servers\Domains\Models\Domain
{
    $id = \Illuminate\Support\Facades\DB::table('domains_domains')->insertGetId(array_replace(['domain' => 'example.com', 'sld' => 'example', 'tld' => 'com',
        'reservation_name' => 'example.com', 'server_id' => $binding->serverId, 'registrar_key' => 'enom', 'account_fingerprint' => $binding->fingerprint,
        'user_id' => $service->user_id, 'service_id' => $service->id, 'status' => 'adoption_pending', 'provenance' => 'adoption_pending',
        'authority_generation' => 1, 'confirmed_revision' => 0, 'billing_managed' => false, 'price_mode' => 'standard',
        'renewal_policy' => encrypt(json_encode(['conversion_source_expiry' => '2027-10-20', 'conversion_hold_reason' => 'billing_not_managed']), false)], $overrides));
    return \Paymenter\Extensions\Servers\Domains\Models\Domain::findOrFail($id);
}
