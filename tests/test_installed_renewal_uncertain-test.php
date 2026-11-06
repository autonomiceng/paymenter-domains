<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Paymenter\Extensions\Servers\Domains\Services\{EnomAnswers, RegistrarCheck, Refunds, RenewalWriter, RenewalAcceptance, WriteFeatures, RenewalAdmission, RenewalExecution};

require __DIR__.'/Support/staff-panel.php';
staffPanel();
$staff = staffUser(['admin.domains.view','admin.domains.manage-policy','admin.domains.settings.enom_answer']);

// The real financial fixture is required: a lost paid reply must retain both the sealed attempt
// and native invoice while the normal completion entry point reports the existing staff action.
foreach (['unknown', 'lost'] as $reply) {
    [$op, $d, $b, $invoice] = $makeRenewal(placeholderComFixtureName('renew-uncertain-'.$reply.'-placeholder.com'));
    [$engine, $writer, $completion] = $compose($b);
    $state->lost = true;
    if ($reply === 'unknown') {
        // Like 207, the provider response is recorded unknown/possibly_sent without an order.
        $features = new WriteFeatures(renewal:true); $accepted = new RenewalAcceptance($b,true,true,25,true);
        $engine = new RenewalExecution($renewBindings,$authority,new RenewalAdmission($authority,$renewBindings,$accepted,$clock),$accepted,$features,$clock);
        $writer = new RenewalWriter($b, $features, $accepted, fn()=>$engine,
            static function () use ($state): string { $state->writes++; return '<interface-response><Command>Extend</Command><ErrCount>1</ErrCount><Done>true</Done></interface-response>'; });
    }
    same('uncertain', $engine->run($op->id, $writer)->state, 'Lost paid renewal reply stays uncertain');
    $attempt = $op->fresh()->attempt;
    $money = nativeFinancialSnapshot($op->fresh());
    $reads = count($state->calls);
    $writes = $state->writes;
    // An older request can retain an unavailable check indefinitely; opening it must not need
    // another reconciliation turn, registrar call or write to recover the staff instructions.
    $policy = $op->fresh()->policy;
    $policy['status_check'] = ['outcome'=>'unavailable', 'at'=>$clock->now()->getTimestamp(), 'next_at'=>null];
    $op->fresh()->update(['policy'=>$policy]);
    $before = $op->fresh()->getAttributes();
    $legacyWords = RegistrarCheck::for($op->fresh());
    expect(str_contains($legacyWords['body'], 'Ask Enom to check this attempt.'), 'Older unavailable renewal shows the registrar-answer step on read');
    $after = (new DateTimeImmutable($attempt['dispatch_attempted_at']))->modify('+24 hours')->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('M j, g:i A').' PT';
    expect(str_contains($legacyWords['body'], $after), 'Older unavailable renewal shows its original dispatch plus 24 hours');
    same($before, $op->fresh()->getAttributes(), 'Reading the staff step writes no request state');
    same($reads, count($state->calls), 'Reading the staff step makes no registrar calls');
    Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation::class, ['record'=>$op->id])
        ->assertSee('Ask Enom to check this attempt.')->assertSee($after)->assertDontSee('Cannot check registrar');
    $noCheck = $op->fresh()->policy; unset($noCheck['status_check']); $op->fresh()->update(['policy'=>$noCheck]);
    Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation::class, ['record'=>$op->id])
        ->assertSee('Ask Enom to check this attempt.')->assertSee($after);
    expect(!str_contains(RegistrarCheck::forCustomer($op->fresh())['body'], 'record Enom'), 'Derived customer notice keeps staff-only instructions out');

    $result = $completion->run($op->id);
    same('awaiting_answer', $result->policy['status_check']['outcome'], 'Unrecoverable renewal names the staff registrar-answer action');
    same('Enom', $result->policy['status_check']['registrar'], 'Staff action names the bound registrar');
    same((new DateTimeImmutable($attempt['dispatch_attempted_at']))->modify('+24 hours')->getTimestamp(),
        $result->policy['status_check']['answer_after'], 'Answer timing is tied to the original dispatch');
    $words = RegistrarCheck::for($result);
    expect(str_contains($words['body'], 'Ask Enom to check this attempt.'), 'Staff sees the existing registrar-answer instructions');
    same('uncertain', $result->state, 'Missing reply is not a failure proof');
    same($attempt, $result->attempt, 'Read checks preserve original attempt evidence');
    same($money, nativeFinancialSnapshot($result), 'Read checks preserve customer money and issued debt');
    same($reads, count($state->calls), 'No unaccepted history or speculative order reads');
    same(null, app(Refunds::class)->creditRejected($op->id), 'Unknown renewal cannot return customer money');
    $engine->run($op->id, $writer);
    same($writes, $state->writes, 'Unknown original renewal never sends a second Extend');
    // At the existing staff boundary, only a real final answer can make this no-order attempt returnable.
    $clock->instant = $clock->now()->modify('+25 hours');
    expect(EnomAnswers::eligible($op->fresh(), $clock->now()), 'Unchanged lost renewal reaches the existing audited answer path');
    echo "Uncertain paid renewal retains money and exposes the existing registrar-answer action\n";
}
$nativeSuccessful = true;
