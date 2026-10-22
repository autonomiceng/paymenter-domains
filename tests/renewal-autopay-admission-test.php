<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic registrar and gateway only.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use App\Helpers\ExtensionHelper;
use App\Models\{BillingAgreement, Gateway, Invoice};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
class RenewalAdmissionGateway
{
    public function __construct(array $settings) {}
    public function charge(Invoice $invoice, $amount, BillingAgreement $agreement): bool
    {
        same(0, Illuminate\Support\Facades\DB::transactionLevel(), 'Auto Pay outside admission locks');
        ExtensionHelper::addPayment($invoice, null, $amount); return true;
    }
}
class_alias(RenewalAdmissionGateway::class, 'Paymenter\\Extensions\\Gateways\\RenewalAdmissionDouble\\RenewalAdmissionDouble');
$g = Gateway::create(['name'=>'Synthetic', 'extension'=>'RenewalAdmissionDouble', 'type'=>'gateway', 'enabled'=>true]);
$a = BillingAgreement::create(['user_id'=>$user->id, 'gateway_id'=>$g->id, 'name'=>'Synthetic', 'external_reference'=>'synthetic']);
$results = [];
foreach ([false, true] as $auto) {
    [$original, $binding] = $prepare($auto?'funded-auto-placeholder.com':'funded-manual-placeholder.com');
    same('succeeded', $reconcile->run($original->id)->state, 'Real holding anchor');
    $d = $original->domain()->firstOrFail(); $d->service->update(['billing_agreement_id'=>$a->id]);
    $product->server->settings()->updateOrCreate(['key'=>'domains_billing_enabled'], ['value'=>true,'type'=>'boolean']);
    $product->server->settings()->updateOrCreate(['key'=>'domains_manual_renewals_enabled'], ['value'=>true,'type'=>'boolean']);
    $clock->instant = new DateTimeImmutable('2027-09-27T12:00:00Z');
    $invoice = $billing->invoice($d->id, $auto ? null : $user); $op = Operation::where('invoice_id',$invoice->id)->sole();
    if (!$auto) { ExtensionHelper::addPayment($invoice, null, $op->amount); }
    same('paid', $invoice->fresh()->status, 'Either collection route funds native invoice');
    $state->name = $d->domain; $state->price = '8.00';
    $clock->instant = new DateTimeImmutable('2027-10-18T11:59:00Z');
    Illuminate\Support\Facades\Cache::flush();
    $result = $runRenewal($op, $binding); $results[] = [$result->state, $result->funding_basis, $result->funding['seal']['basis']??null];
    same('pending', $result->state, 'Same ordinary renewal admission and synthetic registrar acknowledgment');
    same($auto?'submitted':'skipped', ($result->funding['auto_pay_result']??$result->funding['auto_pay'])['status'], 'Collection audit survives registrar funding seal');
    [$engine, $writer, $completion] = $compose($binding);
    $state->charge = '8.00';
    same('succeeded', $completion->run($op->id)->state, 'Paid renewal completes through existing reconciliation');
    $clock->instant = new DateTimeImmutable('2028-09-27T12:00:00Z');
    $next = $billing->invoice($d->id);
    expect($next->id !== $invoice->id, 'Later expiry creates its own renewal debt');
    same('paid', $next->fresh()->status, 'Earlier collection marker does not block the next cycle');
    $nextOp = Operation::where('invoice_id', $next->id)->sole();
    same('submitted', $nextOp->funding['auto_pay_result']['status'], 'Next cycle has its own collection result');
    same($next->id, $billing->invoice($d->id)->id, 'Later cycle also reuses issued debt');
}
same($results[0], $results[1], 'Automatic and interactive payment share funding and admission states');
echo "Automatic and interactive renewal payment share native funding and registrar admission.\n";
$nativeSuccessful = true;
