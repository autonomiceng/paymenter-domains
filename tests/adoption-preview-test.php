<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/adoption-preview.php';
use App\Models\{Invoice, Order, Service};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Data\{Environment};
use Paymenter\Extensions\Servers\Domains\Data\Adoption\{StaffContext, Target};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Adoption, Domain, Operation, Price};
extract(adoptionFixture());
$nativeBefore = [$service->fresh()->getAttributes(), $order->fresh()->getAttributes(), Invoice::count(), Domain::count(), Operation::count()];
$review = $reader->preview($target, $context);
same('ready', $review->code, 'Verified TEST mapping eligible for rehearsal');
same(true, $review->details['test_only'], 'TEST visibly flagged'); same(false, $review->jsonSerialize()['live_anchor_eligible'], 'TEST grants no live authority');
same(false, $review->jsonSerialize()['can_confirm'], 'No adoption commit enabled');
same('2027-10-13', $review->details['renewal_due'], 'Default seven day due cutoff');
$row = Adoption::findOrFail($review->previewId);
same('preview', $row->state, 'Only preview row persisted'); same('test', $row->environment, 'Evidence and row bind TEST');
same('123', $row->evidence['provider_domain_id'], 'Exact parsed identity saved encrypted');
same(0, $row->evidence['prior_generation'], 'No fabricated holding'); same(null, $row->authority_generation, 'No committed generation');
same($context->reason, $row->evidence['reason'], 'Full intent in digest evidence');
same([Staff::VIEW => true, Staff::ADOPT_PREVIEW => true], $row->evidence['permission_snapshot'], 'Exact required permission snapshot retained');
same(64, strlen($row->evidence['capture_digest']), 'Pre-read revision digest retained');
same(64, strlen($row->evidence['intent_digest']), 'Pre-read intent digest retained');
same(hash('sha256', json_encode($row->evidence, JSON_THROW_ON_ERROR)), $row->request_digest, 'Digest covers complete normalized intent');
$raw = DB::table('domains_adoptions')->where('id', $row->id)->value('evidence');
expect(!str_contains($raw, 'example.com') && !str_contains($raw, 'provider_domain_id'), 'Evidence encrypted at rest');
$safe = json_encode($review);
foreach (['account-enom', 'synthetic-secret', 'provider_domain_id', 'request_digest', 'account_fingerprint'] as $secret) { expect(!str_contains($safe, $secret), 'Safe DTO excludes '.$secret); }
same($nativeBefore, [$service->fresh()->getAttributes(), $order->fresh()->getAttributes(), Invoice::count(), Domain::count(), Operation::count()], 'Preview cannot mutate native or holding authority');
expect(isset(Staff::permissions()[Staff::ADOPT_PREVIEW]), 'Dedicated default-deny permission registered');
$limited = staffUser([Staff::VIEW]); $count = count($wire->calls);
same('permission_denied', $reader->preview($target, new StaffContext($limited->id, 'Reason'))->code, 'View permission alone insufficient');
same($count, count($wire->calls), 'Permission refusal zero wire');
foreach ([
    new Target('example.com', $service->id, $other->id, $tld->server_id, Environment::Test),
    new Target('other.com', $service->id, $owner->id, $tld->server_id, Environment::Test),
    new Target('example.com', $service->id, $owner->id, $tld->server_id, Environment::Live),
    new Target('example.com', $service->id, $owner->id, $tld->server_id + 100, Environment::Test),
] as $bad) { $before = count($wire->calls); expect($reader->preview($bad, $context)->code !== 'ready', 'Wrong target refused'); same($before, count($wire->calls), 'Wrong local identity zero wire'); }
$order->update(['user_id' => $other->id]);
same('mapping_unconfirmed', $reader->preview($target, $context)->code, 'Native order owner must agree'); $order->update(['user_id' => $owner->id]);
foreach (['true' => 'auto_renew_on', 'unknown' => 'auto_renew_unknown'] as $flag => $code) {
    $wire->payloads['GetRenew'] = '<auto-renew>'.$flag.'</auto-renew>';
    $refused = $reader->preview($target, $context); same($code, $refused->code, 'Fresh provider refusal persists safely');
    same('refused', Adoption::findOrFail($refused->previewId)->state, 'Refusal retained');
}
$wire->payloads['GetRenew'] = '<auto-renew>false</auto-renew>';
$wire->payloads['GetDomainExp'] = '<ExpirationDate>10/20/2027 11:14:00 PM</ExpirationDate>';
same('expiry_disagrees', $reader->preview($target, $context)->code, 'Expiry disagreement remains pending');
$wire->payloads['GetDomainExp'] = '<ExpirationDate>10/19/2027 11:14:00 PM</ExpirationDate>';
foreach (['owner', 'role', 'property', 'price', 'site'] as $change) {
    $armed = true;
    $originalPrice = Price::where('action', 'renew')->where('server_id', $tld->server_id)->sole()->getRawOriginal();
    $wire->during = function () use (&$armed, $change, $service, $other, $staff, $property, $originalPrice): void {
        expect(DB::transactionLevel() === 0, 'Network outside locks');
        if (!$armed) { return; } $armed = false;
        match ($change) {
            'owner' => DB::table('services')->where('id', $service->id)->update(['user_id' => $other->id]),
            'role' => DB::table('roles')->where('id', $staff->role_id)->update(['permissions' => json_encode([Staff::VIEW])]),
            'property' => DB::table('properties')->where('id', $property->id)->update(['value' => 'other.com']),
            'price' => DB::table('domains_prices')->where('id', $originalPrice['id'])->update(['revision' => $originalPrice['revision'] + 1]),
            'site' => config(['app.url' => 'http://changed.invalid']),
        };
    };
    $before = Adoption::count(); same('changed', $reader->preview($target, $context)->code, 'Mid-read '.$change.' change discards proof'); same($before, Adoption::count(), 'Changed proof never persisted as fresh preview');
    DB::table('services')->where('id', $service->id)->update(['user_id' => $owner->id]);
    DB::table('roles')->where('id', $staff->role_id)->update(['permissions' => json_encode([Staff::VIEW, Staff::ADOPT_PREVIEW])]);
    DB::table('properties')->where('id', $property->id)->update(['value' => 'example.com']);
    DB::table('domains_prices')->where('id', $originalPrice['id'])->update(['revision' => $originalPrice['revision']]);
    config(['app.url' => 'http://domains-tests.invalid']);
}
$wire->during = null;
$pending = adoptionPendingFixture($service, $binding, ['provider_domain_id' => '123']);
same('ready', $reader->preview($target, $context)->code, 'Reviewed pending row agrees with fresh identity and expiry');
DB::table('domains_domains')->where('id', $pending->id)->update(['reservation_name' => null]);
$unreserved = $reader->preview($target, $context); same('ready', $unreserved->code, 'Converted null reservation remains previewable');
same($pending->id, Adoption::findOrFail($unreserved->previewId)->domain_id, 'Preview retains original pending row identity');
same(null, $pending->fresh()->reservation_name, 'Preview never acquires a reservation');
$peerService = Service::create(['user_id' => $owner->id, 'order_id' => $order->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
    'quantity' => 1, 'price' => '10.00', 'currency_code' => 'USD', 'status' => 'pending']);
$collision = adoptionPendingFixture($peerService, $binding, ['domain' => 'other.com', 'sld' => 'other']);
$count = count($wire->calls); same('competing_holding', $reader->preview($target, $context)->code, 'Another retained reservation still blocks'); same($count, count($wire->calls), 'Reservation collision zero wire');
DB::table('domains_domains')->where('id', $collision->id)->delete();
foreach (['service_id' => $peerService->id, 'user_id' => $other->id, 'account_fingerprint' => str_repeat('a', 64)] as $key => $bad) {
    $old = $pending->getRawOriginal($key); DB::table('domains_domains')->where('id', $pending->id)->update([$key => $bad]);
    $count = count($wire->calls); same('mapping_unconfirmed', $reader->preview($target, $context)->code, 'Wrong pending '.$key.' refused'); same($count, count($wire->calls), 'Wrong pending binding zero wire');
    DB::table('domains_domains')->where('id', $pending->id)->update([$key => $old]);
}
DB::table('domains_domains')->where('id', $pending->id)->update(['provider_domain_id' => '456']);
same('identity_unconfirmed', $reader->preview($target, $context)->code, 'Stored pending provider ID must agree');
DB::table('domains_domains')->where('id', $pending->id)->update(['renewal_policy' => encrypt(json_encode(['conversion_source_expiry' => '2027-10-21']), false)]);
same('identity_unconfirmed', $reader->preview($target, $context)->code, 'Identity refusal takes precedence over source expiry mismatch');
DB::table('domains_domains')->where('id', $pending->id)->update(['provider_domain_id' => '123', 'renewal_policy' => encrypt(json_encode(['conversion_source_expiry' => '2027-10-21']), false)]);
same('expiry_disagrees', $reader->preview($target, $context)->code, 'Converted source expiry must agree even when provider expiry is null');
DB::table('domains_domains')->where('id', $pending->id)->update(['renewal_policy' => encrypt(json_encode(['conversion_source_expiry' => null]), false)]);
$count = count($wire->calls); same('expiry_unconfirmed', $reader->preview($target, $context)->code, 'Missing converted source date requires separate qualification'); same($count, count($wire->calls), 'Unknown source date zero wire');
DB::table('domains_domains')->where('id', $pending->id)->update(['renewal_policy' => encrypt(json_encode(['conversion_source_expiry' => '2027-10-20']), false)]);
// Terminal labels never replace negative proof. These uncertain rows exist only in the disposable fixture.
foreach (['failed', 'cancelled', 'succeeded'] as $state) {
    $op = Operation::create(['domain_id' => $pending->id, 'service_id' => $service->id, 'server_id' => $binding->serverId,
        'registrar_key' => 'enom', 'account_fingerprint' => $binding->fingerprint, 'authority_generation' => 1, 'action' => 'register',
        'operation_key' => 'synthetic-uncertain-'.$state, 'term' => 1, 'currency' => 'USD', 'amount' => '10.00', 'state' => $state,
        'attempt' => ['seal' => 'sealed', 'send_state' => 'possibly_sent'], 'policy' => [], 'payload' => []]);
    $count = count($wire->calls); same('unresolved_debt', $reader->preview($target, $context)->code, 'Unproved terminal label refuses: '.$state); same($count, count($wire->calls), 'Unresolved retained attempt zero wire');
    DB::table('domains_operations')->where('id', $op->id)->delete();
}
$unsent = Operation::create(['domain_id' => $pending->id, 'service_id' => $service->id, 'server_id' => $binding->serverId,
    'registrar_key' => 'enom', 'account_fingerprint' => $binding->fingerprint, 'authority_generation' => 1, 'action' => 'register',
    'operation_key' => 'synthetic-proved-unsent', 'term' => 1, 'currency' => 'USD', 'amount' => '10.00', 'state' => 'rejected',
    'attempt' => ['seal' => 'absent', 'send_state' => 'definitely_unsent', 'disposition' => 'rejected', 'rejection_basis' => 'definitely_unsent'], 'policy' => [], 'payload' => []]);
same('ready', $reader->preview($target, $context)->code, 'Existing bound definitive-unsent proof may be reviewed without authority');
$before = count($wire->calls);
DB::transaction(function () use ($reader, $target, $context): void { try { $reader->preview($target, $context); expect(false, 'Outer transaction forbidden'); } catch (LogicException) {} });
same($before, count($wire->calls), 'No provider read under caller locks');
$nativeSuccessful = true;
echo "Adoption preview mapping, separation and encrypted evidence scenarios passed\n";
