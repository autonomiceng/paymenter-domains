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
// A pre-existing lost reply exposes the existing wait/support step before any worker creates status_check.
$beforeCheck=RegistrarCheck::forCustomer($op->fresh());
same('Renewal pending',$beforeCheck['title'],'Old unresolved renewal projects its original-dispatch deadline without a worker');
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
// The real paid renewal reaches owner Blade with its own wait/support notice.
$cataloguePorts=$ports;require __DIR__.'/Support/owner-pages.php';
$owner=\App\Models\User::findOrFail($d->user_id);\Illuminate\Support\Facades\Auth::setUser($owner);
$request=\Illuminate\Http\Request::create('/domains/'.$d->id,'GET');$request->setUserResolver(fn()=>$owner);$request->setLaravelSession(app('request')->session());
$html=(new \Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController($owners))->show($request,$d->id)->render();
$deadline=(new DateTimeImmutable($attempt['dispatch_attempted_at']))->modify('+24 hours')->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('M j, g:i A').' PT';
expect(str_contains($html,'We’re confirming this renewal with the registrar.')&&str_contains($html,$deadline),'Actual uncertain paid renewal page shows the original dispatch deadline');
expect(!str_contains($html,'Ask Enom')&&!str_contains($html,'You can record'),'Owner has no staff-only answer instructions');
// At the existing staff boundary, only a real final answer can make this no-order attempt returnable.
$clock->instant = $clock->now()->modify('+25 hours');
expect(EnomAnswers::eligible($op->fresh(), $clock->now()), 'Unchanged lost renewal reaches the existing audited answer path');
echo "Uncertain paid renewal retains money and exposes the existing registrar-answer action\n";
$nativeSuccessful = true;
