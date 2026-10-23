<?php
// Synthetic NameSilo transfer evidence; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/transfer-completion-namesilo-fixture.php';
$unresolved(['retention','baseline','sealedprivacy','reversed','race','expiration','badexpiry','nullexpiry','pending','unknown','rejectowned','rejectcharge','needsowned','needscharge']);
foreach (['Missing Authorization Code','Incorrect Authorization Code','Domain is Locked','Wait Authorization Code','Domain is Private','Transfer Rejected'] as $i=>$status) {
    $op=$prepare('held-'.$i); $observe->status=$status; $observe->fault='reject'; $r=$reconcile->run($op->id);
    same($i===5?'rejected':'held',$r->state,'Needs-action is distinct from sent terminal rejection');
    same($i===5?'transfer_provider_rejected':'transfer_needs_action',$r->safe_code,'Safe staff outcome retained'); expect($r->policy['reconciliation']['staff_alert'],'Staff alert retained');
    same($i===5?'sent':null,$r->attempt['transfer_terminal']['send_state']??null,'Only terminal refusal retains sent terminal evidence');
    if ($i===5) { commerceReject(fn()=>$refunds->creditRejected($r->id)); } else { same(null,$refunds->creditRejected($r->id),'Held transfers are ineligible for credit'); }
    commerceReject(fn()=>Paymenter\Extensions\Servers\Domains\Services\DomainReservations::releaseTerminal($r->domain->domain));
    same(null,$r->fresh()->funding['refund']??null,'Sent outcomes never credit'); same($r->domain->domain,$r->domain->fresh()->reservation_name,'Reservation retained');
    $nativeBeforeReplay = nativeFinancialSnapshot($r);
    $calls=$state->calls; $engine->execute($r->id); same($calls,$state->calls,'Sent needs-action never replays');
    same($nativeBeforeReplay, nativeFinancialSnapshot($r), 'Replay preserves native/financial state');
    if ($i!==5) {
        $observe->status='unknown'; $r=$reconcile->run($r->id); same('transfer_needs_action',$r->policy['reconciliation']['reason'],'Uncovered read retains held reason');
        same(null,$r->completed_at,'Needs-action remains nonterminal'); $observe->fault=''; $observe->status='Transfer Completed';
        same('succeeded',$reconcile->run($r->id)->state,'Held transfer can complete through fresh read-only proof');
    }
}
echo "NameSilo transfer uncertainty, needs-action and sent rejection safeguards passed.\n"; $nativeSuccessful=true;
