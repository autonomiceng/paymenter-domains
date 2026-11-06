<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Cost, FeedObserved, OfferState, Query, TermSupport};
use Paymenter\Extensions\Servers\Domains\Data\CostUnit;
use Paymenter\Extensions\Servers\Domains\Models\{Operation, Tld};
use Paymenter\Extensions\Servers\Domains\Services\RenewalAuthority;

// Two funded native cycles on one holding are needed to reproduce the R18b same-day pattern.
// A pure date-rule test would miss admission, immutable billing and the final single-send boundary.
[$first, $d, $b] = $makeRenewal(placeholderComFixtureName('renew-cooldown-placeholder.com'));
[$engine, $writer, $completion] = $compose($b);
same('pending', $engine->run($first->id, $writer)->state, 'First annual renewal sends');
$state->expiry = '2028-10-18'; $state->charge = '8.00';
same('succeeded', $completion->run($first->id)->state, 'First annual renewal is actually confirmed');
$confirmedAt = $clock->now();
$clock->instant = $confirmedAt->modify('+6 hours');
activeCommerceFacts($clock->now(), $clock->now()->modify('+30 days'), $ports);
$observeRenewalCost($b);
$t = Tld::where('server_id', $d->server_id)->where('tld', 'com')->sole();
$facts = TermSupport::attach('com', OfferState::facts($t->catalogue_facts), TermSupport::REVIEWED_EVIDENCE['enom']['com'] ?? null);
DB::table('domains_tlds')->where('id', $t->id)->update(['catalogue_facts' => OfferState::encodeFacts($facts)]);
$q = new Query($b, ['com'], 'renew');
$ports->apply(new FeedObserved($b, 'com', [new Cost('renew', 2, true, '16.0000', CostUnit::TermTotal,
    'enom_renew_annual_total', $clock->now(), $clock->now()->modify('+30 days'))]), $ports->readOffers($q, Context::Staff)[0]->revision);
$invoice = $billing->invoice($d->id, $user, 2);
$second = Operation::where('invoice_id', $invoice->id)->sole();
App\Helpers\ExtensionHelper::addPayment($invoice, null, $second->amount);
$state->expiry = '10/18/2028 5:00:00 AM'; $state->day = '10/18/2028';
$state->years = 2; $state->after = '2030-10-18'; $state->order = '9003'; $state->charge = '16.00';
$writes = $state->writes; $reads = count($state->calls); $money = nativeFinancialSnapshot($second);
Cache::flush();
same('queued', $engine->run($second->id, $writer)->state, 'Another paid Enom renewal waits for the provider repeat-renewal window');
same($writes, $state->writes, 'Same-day renewal sends no Extend');
same($reads, count($state->calls), 'Known local wait spends no provider reads');
same([], (array) $second->fresh()->attempt, 'Waiting obligation stays unsealed');
same($money, nativeFinancialSnapshot($second), 'Waiting preserves paid debt, customer money and expiry');
$clock->instant = $confirmedAt->modify('+24 hours -1 second'); Cache::flush();
same('queued', $engine->run($second->id, $writer)->state, 'No send just before the repeat-renewal window ends');
same($writes, $state->writes, 'Boundary retry still sends nothing');
$clock->instant = $confirmedAt->modify('+24 hours'); Cache::flush();
$state->billDate = '10/19/2027 4:59:01 AM';
same('pending', $engine->run($second->id, $writer)->state, 'Normal execution resumes automatically after the wait');
same($writes + 1, $state->writes, 'Exactly one two-year Extend after fresh admission');
same(2, $state->lastWrite[1]['NumYears'], 'The paid term remains two years');
expect(!array_key_exists('OverrideOrder', $state->lastWrite[1]), 'The product never overrides registrar replay protection');
$state->expiry = '2030-10-18';
same('succeeded', $completion->run($second->id)->state, 'Real matching charge and two-year expiry settle through normal reconciliation');
same(['amount' => '16.00', 'currency' => 'USD'], $second->fresh()->cost['actual'], 'Completion records the actual full-term charge');
expect(RenewalAuthority::completed($second->fresh(), $d->fresh(), $b), 'Completed two-year proof anchors the next cycle');
$engine->run($second->id, $writer); $completion->run($second->id);
same($writes + 1, $state->writes, 'Repeated execution and completion never resend');
echo "Same-day Enom renewal waits, then settles its paid two-year term with one send\n";
$nativeSuccessful = true;
