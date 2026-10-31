<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/registration-fixture.php';
use App\Models\{Credit, Role};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Labels;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\{DomainOperations, OperationTerminalProof, PricingRule, RefundProof, Refunds, RegistrationPreflight, WriteAcceptance, WriteFeatures};

$staff = commerceUser(); $role = Role::create(['name' => 'Nameserver refunds', 'permissions' => ['admin.domains.manage', 'admin.invoices.update']]);
$staff->update(['role_id' => $role->id]); $refunds = new Refunds($clock);

foreach (['enom', 'namesilo'] as $index => $registrar) {
    if ($index) { $sellers->change('com', 2, $tlds[$index]->server_id, true, true, $clock->now()); }
    $binding = $bindingFor($index);
    $acceptance = new WriteAcceptance($binding, true, true, 25, static fn () => true);
    $fresh = new RegistrationPreflight($freshBindings, new WriteFeatures(registration: true), $acceptance);
    // The synthetic writer reaches preflight independently of the bridge's earlier owner-setup guard.
    $engine = new DomainOperations($claims, $fresh, $writer, $clock); $writer->engine = $engine;
    $cases = ['empty-host' => ['', 'ns2.example.net'],
        'too-many' => array_map(static fn ($i) => 'ns'.$i.'.example.net', range(1, 14)),
        'bad-ldh' => ['ns_1.example.net', 'ns2.example.net'],
        'valid' => ['ns1.example.net', 'ns2.example.net']];
    if ($registrar === 'enom') { $cases['privacy-first'] = $cases['bad-ldh']; }
    foreach ($cases as $case => $servers) {
        $name = placeholderComFixtureName($registrar.'-nameservers-'.$case.'-placeholder.com');
        $cart = commerceCart($user); commerceAdd($cart, $native->product($tlds[$index], 'register'), $name); commerceCheckout($user, $cart);
        $op = Domain::where('domain', $name)->sole()->operations()->sole();
        Illuminate\Support\Facades\DB::table('invoices')->where('id', $op->invoice_id)->update(['status' => 'paid']);
        App\Models\InvoiceTransaction::create(['invoice_id' => $op->invoice_id, 'amount' => $op->amount, 'fee' => '0.00', 'status' => 'succeeded']);
        $op->update(['payload' => array_replace($op->payload, ['nameservers' => $servers, 'privacy' => $case === 'privacy-first'])]);
        $registration->query = \Paymenter\Extensions\Servers\Domains\Services\OperationClaims::query($op, $op->domain);
        $before = [$writer->calls, $registration->reads]; $money = $nativeMoney();
        $result = $engine->execute($op->id);
        if ($case === 'valid') {
            same($before[0] + 1, $writer->calls, $registrar.' valid payload reaches the synthetic wire');
            same('sealed', $result->attempt['seal'], $registrar.' valid payload seals as before');
        } else {
            same('held', $result->state, $registrar.' '.$case.' holds');
            same($case === 'privacy-first' ? 'order_not_supported' : 'nameservers_required', $result->safe_code,
                $registrar.' '.$case.' retains the specific refusal instead of pre_send_interrupted');
            same('absent', $result->attempt['seal'], $registrar.' invalid setup is unsealed');
            expect(!array_key_exists('execution', $result->attempt), $registrar.' invalid setup has no execution');
            same($before, [$writer->calls, $registration->reads], $registrar.' invalid setup has zero wire calls or preflight reads');
        }
        same($money, $nativeMoney(), $registrar.' setup validation preserves native finance');
        if (in_array($result->safe_code, ['nameservers_required', 'order_not_supported'], true)) {
            // A specific refusal retains the old definitely-unsent recovery, never unknown execution.
            // Privileged corruption bypasses the normal write-once evidence guard.
            $raw = $result->getRawOriginal('attempt'); $result->attempt = [...$result->attempt, 'execution' => null];
            Illuminate\Support\Facades\DB::table('domains_operations')->where('id', $result->id)->update(['attempt' => $result->getAttributes()['attempt']]);
            commerceReject(fn () => OperationTerminalProof::encode($claims, $result->id));
            commerceReject(fn () => RefundProof::requireUnsent($result, $result->domain));
            Illuminate\Support\Facades\DB::table('domains_operations')->where('id', $result->id)->update(['attempt' => $raw]);
            $result->refresh(); $credit = PricingRule::minor((string) Credit::sum('amount'));
            if ($case === 'bad-ldh') { $refund = $refunds->refundHeld($staff, $result->id, 'customer_cancelled'); }
            else {
                $rejected = OperationTerminalProof::encode($claims, $result->id);
                expect(OperationTerminalProof::safe($rejected, $rejected->domain), $registrar.' terminal refusal is proven unsent');
                $refund = $refunds->creditRejected($result->id);
            }
            same(['amount' => $result->amount, 'currency' => 'USD'], $refund, $registrar.' specific hold remains refundable');
            same($credit + PricingRule::minor($result->amount), PricingRule::minor((string) Credit::sum('amount')), $registrar.' exact account credit');
            same(null, $refunds->creditRejected($result->id), $registrar.' refund remains once only');
            same($before, [$writer->calls, $registration->reads], $registrar.' recovery makes no provider calls');
        }
    }
}
echo "Registration nameserver holds, labels, privacy ordering and valid payload checks passed\n"; $nativeSuccessful = true;
