<?php
// Enom 444f31c/b561b9f domain-billing and billing-integration scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/reconciliation-fixture.php';
use App\Models\{Invoice, Service};
use Illuminate\Support\Facades\{Artisan, DB, Queue};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation, Price};
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, HolderPricing, NativeDomainGuard, StaffSettingsReview, StaffSettingsWrites};

$guard = new NativeDomainGuard(new HolderPricing($prices), $clock); $guard->boot(); $guard->boot();
$billing = new DomainBilling($bindings, $guard, $clock); Queue::fake();
// Boot guards BEFORE a real checkout: initial same-product annual projection remains admitted.
[$original] = $prepare(placeholderComFixtureName('billing-held-placeholder.com'));
Queue::fake();
$d = $original->domain()->firstOrFail(); $s = $d->service;
$nativeInvoice = Invoice::findOrFail($original->invoice_id);
$ownedBefore=serialize([$original->fresh()->getRawOriginal(),$d->fresh()->getRawOriginal()]);
$nativeInvoice->update(['status' => 'pending']); $nativeInvoice->update(['status' => 'paid']);
same('active', $s->fresh()->status, 'Native Active means paid before registration confirmation');
same(null, $s->fresh()->expires_at, 'Native payment cannot annualize the purchased term');
same($ownedBefore,serialize([$original->fresh()->getRawOriginal(),$d->fresh()->getRawOriginal()]),'Native payment does not complete or rewrite owned registration');
Queue::assertPushed(App\Jobs\Server\CreateJob::class,1);
Queue::assertNotPushed(Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation::class); Queue::fake();
same('succeeded', $reconcile->run($original->id)->state, 'Provider evidence alone confirms owned registration');
$d->refresh(); same('active', $s->fresh()->status, 'Confirmed registration active');
same(['expiry' => '2027-10-18', 'issue' => '2027-09-27', 'due' => '2027-10-11'], DomainBilling::calendar($d), '21/7 calendar uses confirmed expiry');
commerceReject(fn () => DomainBilling::calendar($d, 7, 7));
$server = $product->server; $setting = static fn ($key, $value) => $server->settings()->updateOrCreate(['key' => $key], ['value' => $value, 'type' => 'boolean']);
$setting('domains_billing_enabled', true); $setting('domains_manual_renewals_enabled', false);
require __DIR__.'/Support/cancelled-billing.php';
commerceReject(fn () => $billing->invoice($d->id)); // too early for scheduled issue
commerceReject(fn () => $billing->invoice($d->id, $user)); // independent manual gate
$setting('domains_billing_enabled', false); $setting('domains_manual_renewals_enabled', true);
commerceReject(fn () => $billing->invoice($d->id, commerceUser()));
// Seller and sales flags do not change holder retail; emergency spending pauses block new debt.
$sellers->change('com', 2, $tlds[1]->server_id, true, true, $clock->now());
$tlds[0]->update(['enabled' => false, 'register_enabled' => false, 'renew_enabled' => false]);
$bindings->setProcessing($server->id, false);
$clock->instant = new DateTimeImmutable('2027-09-26T12:00:00Z');
commerceReject(fn () => $billing->invoice($d->id, $user, 3));
$bindings->setProcessing($server->id, true, syntheticActivation());
$d->update(['renewal_policy' => [...$d->renewal_policy, 'automatic_spend_paused' => true]]);
commerceReject(fn () => $billing->invoice($d->id, $user, 3));
$d->update(['renewal_policy' => [...$d->renewal_policy, 'automatic_spend_paused' => false]]);
$renewal = $billing->invoice($d->id, $user, 3); $op = Operation::where('invoice_id', $renewal->id)->sole();
// Staff calendar and gate reviews after issue apply to later invoices only.
require_once __DIR__.'/Support/staff-panel.php';
$staff = staffUser(['admin.domains.view', 'admin.domains.manage-policy', StaffSettingsReview::permission('renewal_billing')]);
$staffWrites = new StaffSettingsWrites($native, $sellers, $bindings);
$issuedDebt = static fn () => serialize([DB::table('invoices')->where('id', $renewal->id)->get(), DB::table('invoice_items')->where('invoice_id', $renewal->id)->get(), DB::table('invoice_transactions')->get(), $op->fresh()->getRawOriginal()]);
$debtBefore = $issuedDebt();
foreach ([[true, false, 60, 30], [false, true, 21, 7]] as [$scheduled, $manual, $issue, $due]) {
    $ticket = StaffSettingsReview::ticket($staff->id, $server->id, null, 'renewal_billing', StaffSettingsReview::stamp($server->id, null));
    $staffWrites->run(new StaffSettingsReview($staff->id, $server->id, null, 'renewal_billing', $ticket, 'Move the renewal calendar after issue'),
        ['scheduled' => $scheduled, 'manual' => $manual, 'issue_days' => $issue, 'due_days' => $due, 'confirmed' => true]);
    same(['issue' => $issue, 'due' => $due], Paymenter\Extensions\Servers\Domains\Services\BillingCalendar::timing($server->settings()->get()), 'Reviewed calendar '.$issue.'/'.$due.' saved');
    same($renewal->id, $billing->invoice($d->id, $user, 3)->id, 'Calendar '.$issue.'/'.$due.' returns the issued invoice');
    same($debtBefore, $issuedDebt(), 'Calendar '.$issue.'/'.$due.' leaves issued due date, term and amount unchanged');
}
// Cancellation skips scheduled work without erasing an already issued obligation.
$issuedBefore=serialize([DB::table('invoices')->get(),DB::table('invoice_items')->get(),DB::table('invoice_transactions')->get(),$op->fresh()->getRawOriginal()]);
$issuedCancel=App\Models\ServiceCancellation::create(['service_id'=>$s->id,'type'=>'end_of_period','reason'=>'Synthetic retained-debt cancellation']);
same(null,$billing->invoice($d->id),'Scheduled cancellation also skips an issued renewal quietly');
same($issuedBefore,serialize([DB::table('invoices')->get(),DB::table('invoice_items')->get(),DB::table('invoice_transactions')->get(),$op->fresh()->getRawOriginal()]),'Cancellation never rewrites retained debt');
DB::table('service_cancellations')->where('id',$issuedCancel->id)->delete();

same('31.20', $op->amount, 'Retained expired retail bills exact fixed three-year total without current cost');
same(true, $op->policy['price']['retained'], 'Stale costs retain published holder retail');
same('stale', $d->fresh()->observation['renewal_price_alert']['code'], 'Stale margin is a staff alert');
same(3, (int) $op->term, 'Obligation fixes requested term independently of annual plan');
same('2030-10-18', $op->expected_expires_at->format('Y-m-d'), 'Exact expected term expiry recorded');
same('Domain renewal: billing-held-placeholder.com (3 years, Oct 18, 2027 to Oct 18, 2030)', $renewal->items->sole()->description, 'Renewal dates use frozen registrar expiry and requested years');
DB::table('domains_domains')->where('id', $d->id)->update(['provider_expires_at' => '2031-01-01']);
same('Domain renewal: billing-held-placeholder.com (3 years, Oct 18, 2027 to Oct 18, 2030)', $renewal->fresh()->items->sole()->description, 'Renewal text stays frozen after expiry changes');
DB::table('domains_domains')->where('id', $d->id)->update(['provider_expires_at' => '2027-10-18']);
same('renew:'.$d->id.':g1:2027-10-18', $op->operation_key, 'One obligation key per expiry and generation');
same('2027-10-11', $renewal->due_at->format('Y-m-d'), 'Due calendar frozen');
same(null, $renewal->items->sole()->reference_type, 'Renewal has no native service routing');
same(null, $renewal->items->sole()->reference_id, 'Renewal has no native service identifier');
same(1, (int) $s->fresh()->plan->billing_period, 'Native service remains annual');
same(null, $s->fresh()->expires_at, 'Billing never changes native expiry');
// Current costs are fresh at this fixture clock; publish below-cost renewal retail deliberately.
$tld = $tlds[0]->fresh(); $tld->update(['use_default_rule' => false, 'pricing_mode' => 'fixed', 'fixed_renew' => '1.00', 'fixed_register' => '20.00', 'fixed_transfer' => '20.00']);
$prices->republish($tld);
$frozen = DB::table('invoice_items')->where('invoice_id', $renewal->id)->first();
$bindings->setProcessing($server->id, false);
$adjustment = ['reference_type' => null, 'reference_id' => null, 'quantity' => 1, 'price' => '1.00', 'description' => 'Adjustment'];
commerceReject(fn () => $renewal->items()->create($adjustment));
$otherInvoice = Invoice::create(['user_id' => $user->id, 'currency_code' => 'USD', 'status' => 'pending', 'due_at' => '2027-10-11']);
$otherItem = $otherInvoice->items()->create($adjustment);
commerceReject(fn () => $otherItem->update(['invoice_id' => $renewal->id]));
same(1, $renewal->items()->count(), 'Renewal invoice membership remains frozen');
$setting('domains_manual_renewals_enabled', false); $setting('domains_billing_enabled', false);
$clock->instant = new DateTimeImmutable('2027-10-17T12:00:00Z');
same($renewal->id, $billing->invoice($d->id, $user)->id, 'Existing invoice survives gate, due-date and price changes before expiry');
$clock->instant = new DateTimeImmutable('2027-10-18T00:00:00Z');
commerceReject(fn () => $billing->invoice($d->id, $user));
commerceReject(fn () => $billing->invoice($d->id, $user, 1));
same((array) $frozen, (array) DB::table('invoice_items')->where('invoice_id', $renewal->id)->first(), 'Issued item remains byte-for-byte unchanged');
commerceReject(fn () => $renewal->items->sole()->update(['price' => '99.00']));
commerceReject(fn () => $renewal->items->sole()->delete());
commerceReject(fn () => $renewal->update(['due_at' => '2027-10-12']));
commerceReject(fn () => $renewal->delete());
$invoicePage = new App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
$delete = (new ReflectionMethod($invoicePage, 'getHeaderActions'))->invoke($invoicePage)[0];
$delete->record($renewal);
commerceReject(fn () => $delete->evaluate($delete->getActionFunction()));
expect(Invoice::whereKey($renewal->id)->exists(), 'Actual native delete action retains issued invoice');
commerceReject(fn () => $op->update(['term' => 1]));
commerceReject(fn () => $op->fresh()->update(['base_expires_at' => '2028-10-18']));
commerceReject(fn () => $op->fresh()->update(['expected_expires_at' => '2031-10-18']));
commerceReject(fn () => $op->update(['policy' => [...$op->policy, 'calendar' => []]]));
$renewal = $renewal->fresh(); $op = $op->fresh();
$renewal->update(['status' => 'paid']);
same('awaiting_payment', $op->fresh()->state, 'Payment does not dispatch or grant renewal authority');
same('2027-10-18', $d->fresh()->provider_expires_at->format('Y-m-d'), 'Payment cannot advance confirmed provider expiry');
same(null, $s->fresh()->expires_at, 'Renewal payment leaves native expiry null');
// Native cron must ignore domain service dates and leave issued invoices untouched.
Carbon\Carbon::setTestNow('2027-10-20 12:00:00'); Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());
same(0, Artisan::call('app:cron-job'), 'Actual native cron completes');
same(null, $s->fresh()->expires_at, 'Actual native cron leaves native expiry null');
same((array) $frozen, (array) DB::table('invoice_items')->where('invoice_id', $renewal->id)->first(), 'Native cron preserves issued amount');
// A separate real completed domain exercises scheduled issue and bill+alert below wholesale.
$bindings->setProcessing($server->id, true, syntheticActivation());
$tld->update(['enabled' => true, 'register_enabled' => true, 'renew_enabled' => true]); $prices->republish($tld); $native->publish($tld->id, $now);
$sellers->change('com', 3, $server->id, true, true, $now);
Carbon\Carbon::setTestNow(); Carbon\CarbonImmutable::setTestNow();
[$next] = $prepare(placeholderComFixtureName('billing-below-placeholder.com')); same('succeeded', $reconcile->run($next->id)->state, 'Second real anchor completed');
$clock->instant = new DateTimeImmutable('2027-09-26T12:00:00Z'); $setting('domains_billing_enabled', true);
commerceReject(fn () => $billing->invoice($next->domain_id));
$lowPrice = Price::where(['server_id' => $server->id, 'action' => 'renew'])->sole();
$lowPrice->update(['revision' => $lowPrice->revision + 1, 'observed_at' => '2027-09-26 12:00:00', 'fresh_until' => '2027-09-28 12:00:00']);
$clock->instant = new DateTimeImmutable('2027-09-27T00:00:00Z'); $bill = $billing->invoice($next->domain_id);
$low = Operation::where('invoice_id', $bill->id)->sole();
same('Domain renewal: billing-below-placeholder.com (1 year, Oct 18, 2027 to Oct 18, 2028)', $bill->items->sole()->description, 'Annual renewal dates');
same('1.00', $low->amount, 'Below-cost renewal invoices without floor, subsidy or hold');
same('awaiting_payment', $low->state, 'Below-cost obligation remains billable');
same('below_cost', $next->domain->fresh()->observation['renewal_price_alert']['code'], 'Fresh below-cost margin alerts staff');
same($bill->id, $billing->invoice($next->domain_id)->id, 'Repeated scheduled run is idempotent');
foreach ([App\Jobs\Server\CreateJob::class, App\Jobs\Server\UnsuspendJob::class,
    Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation::class] as $job) { Queue::assertNotPushed($job); }
echo "Renewal calendar, holding invoices, native paid/cron routing and immutable obligations passed\n"; $nativeSuccessful = true;
