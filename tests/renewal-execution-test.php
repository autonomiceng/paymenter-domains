<?php
// Enom d1392a8 renewal execute/late-paid parity; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use App\Models\{InvoiceTransaction, ServiceCancellation};
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Models\{Operation, Price};
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks, RenewalFunding};
[$op, $d, $b, $invoice] = $makeRenewal('renew-execute.com'); [$engine, $writer, $completion] = $compose($b);
// A paid status without succeeded native funds never permits dispatch.
$tx = $invoice->transactions()->sole(); commerceReject(fn () => $tx->update(['amount' => '999.00'])); commerceReject(fn () => $tx->delete());
$oldId = $tx->id; $tx->id += 999; commerceReject(fn () => $tx->delete()); $tx->id = $oldId;
$before = serialize(DB::table('invoices')->get()); $items = serialize(DB::table('invoice_items')->get());
$settings = $product->server->settings(); $settings->updateOrCreate(['key' => 'domains_manual_renewals_enabled'], ['value' => false, 'type' => 'boolean']);
$sellers->change('com', 2, $tlds[1]->server_id, true, true, $now);
$tlds[0]->update(['enabled' => false, 'renew_enabled' => false]);
same('pending', $engine->run($op->id, $writer)->state, 'Late paid obligation survives preferences, seller, sales flags and stale retail');
same(1, $state->writes, 'One renewal write'); same(16, count($state->calls), 'Initial and final full read sequences, single reservation');
same('2027-10-18', $d->fresh()->provider_expires_at->format('Y-m-d'), 'Acknowledgement cannot advance expiry');
same(null, $d->service->fresh()->expires_at, 'Native expiry stays null');
$nativeBeforeReplay = nativeFinancialSnapshot($op);
$engine->run($op->id, $writer); same(1, $state->writes, 'No replay after acknowledgement');
same($nativeBeforeReplay, nativeFinancialSnapshot($op), 'Replay preserves native/financial state');
$state->expiry = '2028-10-18'; $state->charge = '8.00';
same('succeeded', $completion->run($op->id)->state, 'Unique original charge plus full term completes');
same('2028-10-18', $d->fresh()->provider_expires_at->format('Y-m-d'), 'Confirmed provider expiry stored');
same($op->operation_key, $d->fresh()->renewal_policy['billing_anchor']['operation_key'], 'Latest completed renewal anchor advances');
same(null, $d->service->fresh()->expires_at, 'Completion preserves null native expiry');
same($before, serialize(DB::table('invoices')->get()), 'Renewal execution never rewrites debt'); same($items, serialize(DB::table('invoice_items')->get()), 'Issued line unchanged');
$nativeBeforeReplay = nativeFinancialSnapshot($op);
$completion->run($op->id); same(1, $state->writes, 'Completion idempotent');
same($nativeBeforeReplay, nativeFinancialSnapshot($op), 'Replay preserves native/financial state');
// Subsequent cycle accepts original registration plus completed renewal history.
$clock->instant = new DateTimeImmutable('2028-09-27T12:00:00Z');
$settings->updateOrCreate(['key' => 'domains_manual_renewals_enabled'], ['value' => true, 'type' => 'boolean']);
$bill2 = $billing->invoice($d->id, $user); $op2 = Operation::where('invoice_id', $bill2->id)->sole();
same('2028-10-18', $op2->base_expires_at->format('Y-m-d'), 'Next debt uses renewed anchor');
App\Helpers\ExtensionHelper::addProcessingPayment($bill2, null, $op2->amount, '0.00', 'renewal-callback');
$pending = $bill2->transactions()->sole(); $pending->update(['fee'=>'0.10']); commerceReject(fn () => $pending->update(['amount'=>'999.00','status'=>'succeeded']));
App\Helpers\ExtensionHelper::addPayment($bill2, null, $op2->amount, '0.00', 'renewal-callback');
App\Helpers\ExtensionHelper::addPayment($bill2, null, $op2->amount, '0.20', 'renewal-callback');
same(1,$bill2->transactions()->count(),'Repeated gateway callback retains one signed credit');
same('paid', $bill2->fresh()->status, 'Native processing callback settles the full invoice');
$state->expiry = '10/18/2028 5:00:00 AM'; $state->day = '10/18/2028'; $state->after = '2029-10-18'; $state->order = '9003'; $state->orderYear = '2028';
$clock->instant = new DateTimeImmutable('2028-10-18T11:59:00Z'); Cache::flush();
same('pending', $engine->run($op2->id, $writer)->state, 'Second renewal executes from latest completed anchor');
$state->expiry = '2029-10-18'; same('succeeded', $completion->run($op2->id)->state, 'Second renewal completes');
same(2, $d->fresh()->confirmed_revision - 1, 'Each renewal advances one revision');
echo "Paid renewal, late preferences, financial retention and repeated cycles passed\n"; $nativeSuccessful = true;
