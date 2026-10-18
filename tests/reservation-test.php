<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{CanonicalName, Query};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\DomainName;
use Paymenter\Extensions\Servers\Domains\Services\{DomainReservations, FoundationSchema};

FoundationSchema::install();
$seq = 1;
$make = static function (string $name = 'example.com', array $overrides = []) use (&$seq): Domain {
    return DomainReservations::reserve(array_replace(['service_id' => $seq++, 'user_id' => 1, 'server_id' => 1,
        'registrar_key' => 'enom', 'account_fingerprint' => str_repeat('a', 64), 'domain' => $name,
        'status' => 'pending'], $overrides), ['com', 'co.uk']);
};
$reject = static function (callable $call): void {
    try { $call(); expect(false, 'Reservation safety refusal escaped'); }
    catch (DomainException|InvalidArgumentException|Illuminate\Database\QueryException) { /* expected */ }
};
foreach ([' X.COM. ' => 'x.com', 'A.CO.UK' => 'a.co.uk', str_repeat('a', 63).'.com' => str_repeat('a', 63).'.com'] as $input => $name) {
    same($name, CanonicalName::normalize($input), 'Canonical normalization');
    same($name, DomainName::parse($input, ['com', 'co.uk'])['domain'], 'Provider shares canonical parser');
    Query::requireName($name);
}
foreach (['x.com..', "x.com\n", 'é.com', 'xn--bcher-kva.com', 'ab--cd.com', '-x.com', 'x-.com',
    str_repeat('a', 64).'.com', 'x', 'x.c', 'sub.x.com', 'x.net', 'x.com/path'] as $bad) {
    $reject(static fn () => CanonicalName::parse($bad, ['com']));
}
$maximum = implode('.', [str_repeat('a', 63), str_repeat('b', 63), str_repeat('c', 61), str_repeat('d', 63)]);
same(253, strlen(CanonicalName::normalize($maximum)), 'Maximum FQDN length');
$reject(static fn () => CanonicalName::normalize('a'.$maximum));
$original = $make(' X.COM. ');
same('x.com', $original->reservation_name, 'Single-character label reserved');
foreach (['register', 'transfer'] as $action) {
    foreach ([['server_id' => 2, 'registrar_key' => 'namesilo'], ['user_id' => 2], []] as $binding) {
        $reject(static function () use ($make, $action, $binding): void {
            DB::transaction(static function () use ($make, $action, $binding): void {
                $domain = $make('x.com', $binding);
                $domain->operations()->create(['operation_key' => 'duplicate:'.$action]);
            });
        });
    }
}
same(1, Domain::count(), 'Duplicates across action/account/owner create no row');
try { Domain::create(array_replace($original->getAttributes(), ['id' => 90, 'service_id' => 90])); expect(false, 'Direct global duplicate escaped'); }
catch (Illuminate\Database\QueryException $e) { expect(str_contains($e->getMessage(), 'reservation_name'), 'Global name unique arbitrates direct creation'); }
$original->status = 'cancelled'; $original->save();
try { DomainReservations::releaseTerminal('X.COM.'); expect(false, 'Unproved cancelled reservation released'); }
catch (DomainException $e) { same('This domain already has an order.', $e->getMessage(), 'Specific duplicate refusal'); }
$original->status = 'registration_rejected'; $original->save();
$op = Operation::create(['domain_id' => $original->id, 'service_id' => $original->service_id,
    'server_id' => 1, 'registrar_key' => 'enom', 'account_fingerprint' => str_repeat('a', 64),
    'authority_generation' => 1, 'operation_key' => 'terminal:register', 'action' => 'register', 'state' => 'rejected', 'currency' => 'USD']);
$reject(static fn () => DomainReservations::releaseTerminal('x.com')); // missing proof
$unsent = ['disposition' => 'rejected', 'rejection_basis' => 'definitely_unsent', 'send_state' => 'definitely_unsent', 'seal' => 'absent'];
$op->attempt = $unsent; $op->save(); $op->refresh();
foreach ([['state' => 'uncertain'], ['state' => 'pending'], ['state' => 'succeeded'], ['authority_generation' => 0], ['authority_generation' => null], ['account_fingerprint' => null], ['provider_order_id' => '123'],
    ['cost' => ['actual' => '12.34']], ['attempt' => $unsent + ['charge' => '12.34']],
    ['attempt' => $unsent + ['order_candidates' => ['order']]], ['cost' => ['execution' => null]], ['policy' => ['dispatch_attempted_at' => null]],
    ['attempt' => $unsent + ['dispatch_attempted_at' => null]], ['attempt' => $unsent + ['execution' => null]], ['attempt' => $unsent + ['provider_reference' => null]],
    ['attempt' => array_replace($unsent, ['seal' => 'unknown'])], ['server_id' => 2], ['authority_generation' => 2]] as $changes) {
    $baseline = $op->getAttributes(); Operation::withoutEvents(static fn () => $op->fill($changes)->save());
    $reject(static fn () => DomainReservations::releaseTerminal('x.com'));
    Operation::withoutEvents(static fn () => $op->setRawAttributes($baseline)->save());
}
foreach (['provider_order_id' => '123', 'provider_expires_at' => '2027-10-18', 'provider_domain_id' => 'owned'] as $field => $value) {
    $original->$field = $value; $original->save();
    $reject(static fn () => DomainReservations::releaseTerminal('x.com'));
    $original->$field = null; $original->save();
}
$reject(static fn () => $original->delete());
same('x.com', $original->fresh()->reservation_name, 'Ordinary deletion retains reservation');
$reject(static fn () => $original->update(['reservation_name' => null]));
$original->refresh();
$reject(static fn () => $original->update(['domain' => 'other.com']));
$original->refresh();
// A checkout failure restores both terminal reservation and native writes.
try {
    DB::transaction(static function () use ($make): void {
        App\Models\User::create(['email' => 'rollback@example.invalid', 'password' => bcrypt('synthetic')]);
        $make('x.com', ['server_id' => 2, 'registrar_key' => 'namesilo']);
        throw new RuntimeException('checkout rollback');
    });
} catch (RuntimeException $e) { same('checkout rollback', $e->getMessage(), 'Checkout abort'); }
same(0, App\Models\User::count(), 'Outer native checkout rolled back');
same('x.com', $original->fresh()->reservation_name, 'Release rolled back with checkout');
same(1, Domain::count(), 'New ledger row rolled back');
$replacement = $make('x.com', ['server_id' => 2, 'registrar_key' => 'namesilo', 'user_id' => 2]);
same(null, $original->fresh()->reservation_name, 'Safe terminal reservation released');
same('x.com', $replacement->reservation_name, 'Replacement holds same global name');
same('terminal:register', $op->fresh()->operation_key, 'Financial deduplication retained');
// A provider-proven rejection can release an acquisition, but not a cancelled holding.
$replacement->update(['status' => 'transfer_rejected']);
$proof = $op->replicate(); $proof->fill(['domain_id' => $replacement->id, 'service_id' => $replacement->service_id,
    'registrar_key' => 'namesilo', 'server_id' => 2, 'operation_key' => 'provider:transfer', 'action' => 'transfer',
    'attempt' => ['disposition' => 'rejected', 'rejection_basis' => 'provider_proof', 'send_state' => 'sent', 'charge_absent' => true]]); $proof->save();
$replacement->update(['status' => 'cancelled']);
$reject(static fn () => DomainReservations::releaseTerminal('x.com'));
$replacement->update(['status' => 'transfer_rejected']);
$providerProof = $proof->attempt;
foreach ([['charge_absent' => false], ['charge_absent' => null], ['send_state' => 'possibly_sent']] as $change) {
    $proof->attempt = array_replace($providerProof, $change); $proof->save();
    $reject(static fn () => DomainReservations::releaseTerminal('x.com'));
}
$proof->attempt = $providerProof; $proof->save();
DomainReservations::releaseTerminal('x.com');
same(null, $replacement->fresh()->reservation_name, 'Proven rejection released');
// All operations, including ancillary work, must be terminal and resolved.
$third = $make('history.com'); $third->update(['status' => 'registration_rejected']);
$initial = $op->replicate(); $initial->fill(['domain_id' => $third->id, 'service_id' => $third->service_id, 'operation_key' => 'history:initial']); $initial->save();
$extra = $initial->replicate(); $extra->fill(['operation_key' => 'history:other', 'action' => 'nameservers', 'state' => 'pending']); $extra->save();
$reject(static fn () => $extra->delete());
same(2, $third->operations()->count(), 'Ordinary deletion retains unresolved work');
$reject(static fn () => DomainReservations::releaseTerminal('history.com'));
foreach (['id' => 999, 'domain_id' => 999, 'service_id' => 999, 'server_id' => 2, 'registrar_key' => 'namesilo',
    'account_fingerprint' => str_repeat('f', 64), 'authority_generation' => 2, 'action' => 'transfer'] as $field => $value) {
    $reject(static fn () => $initial->update([$field => $value])); $initial->refresh();
}
$reject(static fn () => $third->update(['id' => 999]));
$third->refresh();
same(2, $third->operations()->count(), 'Primary key edit retains operation relationships');
$cancelled = $make('cancelled.com'); $cancelled->update(['status' => 'cancelled']);
$cancelOp = $op->replicate(); $cancelOp->fill(['domain_id' => $cancelled->id, 'service_id' => $cancelled->service_id, 'operation_key' => 'cancelled:initial']); $cancelOp->save();
DomainReservations::releaseTerminal('cancelled.com');
same(null, $cancelled->fresh()->reservation_name, 'Proven unsent cancelled holding releases');
echo "Reservations: shared names, global duplicates, safe terminal release and native rollback passed.\n";
$nativeSuccessful = true;
