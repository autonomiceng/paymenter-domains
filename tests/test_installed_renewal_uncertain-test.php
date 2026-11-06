<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Paymenter\Extensions\Servers\Domains\Services\{EnomAnswers, RegistrarCheck, Refunds};

// The real financial fixture is required: a lost paid reply must retain both the sealed attempt
// and native invoice while the normal completion entry point reports the existing staff action.
[$op, $d, $b, $invoice] = $makeRenewal(placeholderComFixtureName('renew-uncertain-placeholder.com'));
[$engine, $writer, $completion] = $compose($b);
$state->lost = true;
same('uncertain', $engine->run($op->id, $writer)->state, 'Lost paid renewal reply stays uncertain');
$attempt = $op->fresh()->attempt;
$money = nativeFinancialSnapshot($op->fresh());
$reads = count($state->calls);
$writes = $state->writes;
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
$nativeSuccessful = true;
