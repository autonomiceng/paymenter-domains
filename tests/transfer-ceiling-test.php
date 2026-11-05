<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native paid dispatch is needed to prove the hold, absent seal and unchanged invoice, not just an exception.
require __DIR__.'/Support/transfer-fixture.php';
\Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::install();
use Paymenter\Extensions\Servers\Domains\Data\{RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\WriteHeld;

foreach (['enom', 'namesilo'] as $i => $registrar) {
    foreach (['first' => ['99.00', 1], 'second' => ['99.00', 2], 'fraction' => ['7.000001', 1],
        'equal' => ['7.0000', null], 'lower' => ['6.99', null]] as $case => [$quote, $refuseAt]) {
        $op = $newTransfer(placeholderComFixtureName('ceiling-'.$registrar.'-'.$case.'-placeholder.com'), $i);
        $makeTransfer($i); $state->calls = []; $state->reads = 0; $state->quote = '7.00';
        $state->during = static function (int $read) use ($state, $quote, $refuseAt): void {
            $state->quote = $refuseAt === null || $read >= $refuseAt ? $quote : '7.00';
        };
        same(['7.0000', '9.10'], [$op->cost['estimated'], $op->amount], 'Paid transfer has frozen cost and issued total');
        $frozen = [$op->amount, $op->currency, $op->cost, $op->policy];
        $finance = nativeFinancialSnapshot($op); unset($finance['domain']);
        $r = $engine->execute($op->id);
        $writes = array_values(array_intersect($state->calls, ['TP_CreateOrder', 'transferDomain']));
        if ($refuseAt !== null) {
            same([], $writes, 'Above-ceiling transfer never calls transferIn: '.$registrar.' '.$case);
            same(['held', 'registrar_price_increased', 'absent'], [$r->state, $r->safe_code, $r->attempt['seal']], 'Same unsent price hold as registration');
            same($refuseAt, $state->reads, 'Each fresh observation enforces the ceiling');
        } else {
            same([$i === 0 ? 'TP_CreateOrder' : 'transferDomain'], $writes, 'In-ceiling transfer sends once');
            same('uncertain', $r->state, 'Acknowledgement keeps the existing unresolved disposition');
            same(2, $state->reads, 'In-ceiling transfer passes both observations');
        }
        same($frozen, [$r->amount, $r->currency, $r->cost, $r->policy], 'Dispatch preserves frozen operation evidence');
        $after = nativeFinancialSnapshot($r); unset($after['domain']);
        same($finance, $after, 'Dispatch preserves invoice, item, payment, service and credit rows');
        $calls = $state->calls; $engine->execute($op->id); same($calls, $state->calls, 'Held or sent transfer is never replayed');
    }
    $state->during = null; $state->quote = '7.00';
    // As in registration, the issued total is an independent limit and malformed frozen evidence fails closed.
    $op = $newTransfer(placeholderComFixtureName('ceiling-'.$registrar.'-evidence-placeholder.com'), $i); $makeTransfer($i);
    $original = clone $op;
    foreach (['total', 'missing', 'currency', 'unit', 'invalid'] as $fault) {
        $op = clone $original;
        if ($fault === 'total') { $op->amount = '6.99'; }
        else { $saved = $op->cost; match ($fault) {
            'missing' => $saved['estimated'] = null, 'currency' => $saved['currency'] = 'EUR',
            'unit' => $saved['unit'] = 'year', 'invalid' => $saved['estimated'] = 'invalid',
        }; $op->cost = $saved; }
        $ctx = new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+60 seconds'), new RequestBudget(12), $clock);
        try { $fresh->inspect($op, $op->domain, $ctx); throw new LogicException('Invalid frozen transfer authority accepted: '.$fault); }
        catch (WriteHeld $held) { same($fault === 'total' ? 'registrar_price_increased' : 'registrar_price_missing', $held->reason, 'Registration refusal reused: '.$fault); }
    }
}
echo "Transfer fresh quotes respect both frozen spending limits before dispatch.\n"; $nativeSuccessful = true;
