<?php
// Synthetic NameSilo transfer evidence; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/transfer-completion-namesilo-fixture.php';
$unresolved(['retention','baseline','sealedprivacy','reversed','race','expiration','badexpiry','nullexpiry','pending','unknown','rejectowned','rejectcharge','needsowned','needscharge']);
foreach (['Missing Authorization Code','Incorrect Authorization Code','Domain is Locked','Wait Authorization Code','Domain is Private','Transfer Rejected'] as $i=>$status) {
    $op=$prepare('held-'.$i); Paymenter\Extensions\Servers\Domains\Services\HistoryAcceptances::save(new Paymenter\Extensions\Servers\Domains\Data\HistoryAcceptance($binding,'America/Los_Angeles',0,8*86400,'namesilo_transfer_v1')); $observe->status=$status; $observe->fault='reject'; $r=$reconcile->run($op->id);
    same($i===5?'uncertain':'held',$r->state,'Needs-action is distinct from unproved final rejection');
    same($i===5?'reconciliation_required':'transfer_needs_action',$r->safe_code,'Safe staff outcome retained'); expect($r->policy['reconciliation']['staff_alert'],'Staff alert retained');
    $notice=Paymenter\Extensions\Servers\Domains\Presentation\TransferNotice::for($r,$clock->now()->getTimestamp());
    if ($status==='Domain is Private') {
        expect(str_contains($notice['body']??'','Turn off WHOIS privacy there.'),'Privacy-blocked NameSilo transfer tells the owner to turn off privacy at the old registrar');
        expect(str_contains($notice['body']??'','at your old registrar.'),'Privacy recovery points to the old registrar');
        $clock->instant=$clock->now()->modify('+12 hours'); $observe->status='Domain is Locked'; $r=$reconcile->run($r->id);
        $notice=Paymenter\Extensions\Servers\Domains\Presentation\TransferNotice::for($r,$clock->now()->getTimestamp());
        expect(!str_contains($notice['body']??'','WHOIS privacy'),'A fresh lock status clears the old privacy instruction');
    } elseif ($i!==5) {
        expect(!str_contains($notice['body']??'','WHOIS privacy'),'Auth-code and lock blockers do not tell owners to disable privacy: '.$status);
        expect(str_contains($notice['body']??'','Check approval, the domain lock and auth code at your old registrar.'),'Other blockers retain the old registrar checklist');
    }
    same(null,$r->attempt['transfer_terminal']??null,'A status alone never proves transfer finality');
    same(null,$refunds->creditRejected($r->id),'Unproved finality never grants credit');
    commerceReject(fn()=>Paymenter\Extensions\Servers\Domains\Services\DomainReservations::releaseTerminal($r->domain->domain));
    same(null,$r->fresh()->funding['refund']??null,'Sent outcomes never credit'); same($r->domain->domain,$r->domain->fresh()->reservation_name,'Reservation retained');
    $nativeBeforeReplay = nativeFinancialSnapshot($r);
    $calls=$state->calls; $engine->execute($r->id); same($calls,$state->calls,'Sent needs-action never replays');
    same($nativeBeforeReplay, nativeFinancialSnapshot($r), 'Replay preserves native/financial state');
    if ($i!==5) {
        $clock->instant=$clock->now()->modify('+12 hours'); $observe->status='unknown'; $r=$reconcile->run($r->id); same('transfer_status_unavailable',$r->policy['reconciliation']['reason'],'Unknown read surfaces a check failure');
        same(null,$r->completed_at,'Needs-action remains nonterminal'); $observe->fault=''; $observe->status='Transfer Completed';
        $clock->instant=$clock->now()->modify('+12 hours'); same('succeeded',$reconcile->run($r->id)->state,'Held transfer can complete through fresh read-only proof');
    }
}
echo "NameSilo transfer uncertainty, needs-action and sent rejection safeguards passed.\n"; $nativeSuccessful=true;
