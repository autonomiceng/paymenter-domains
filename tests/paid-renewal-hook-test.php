<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\{Auth, DB, Queue};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Data\SendFailure;
use Paymenter\Extensions\Servers\Domains\Jobs\RunOperation;
use Paymenter\Extensions\Servers\Domains\Services\{EnomAnswers, NativePaidHooks, PricingRule, RegistrarCheck, Refunds, RenewalFundingLedger, RuntimeWork};
require __DIR__.'/Support/renewal-runtime.php';

// The real financial fixture is required: a lost paid reply must retain both the sealed attempt
// and native invoice while the normal completion entry point reports the existing staff action.
[$op, $d, $b, $invoice] = $makeRenewal(placeholderComFixtureName('renew-uncertain-placeholder.com'), false);
[$engine, $writer, $completion] = $compose($b);
$work = $renewalRuntime($b, $engine, $writer, $completion);
app()->instance(RuntimeWork::class, $work);
NativePaidHooks::boot();
Queue::fake();
DB::transaction(static function () use ($invoice, $op): void {
    \App\Helpers\ExtensionHelper::addPayment($invoice, null, '0.01');
    same('pending', $invoice->fresh()->status, 'One cent leaves the invoice open');
    \App\Helpers\ExtensionHelper::addPayment($invoice->fresh(), null, PricingRule::decimal(PricingRule::minor($op->amount) - 1));
    Queue::assertNotPushed(RunOperation::class);
});
$job = Queue::pushed(RunOperation::class)->where('operationId', $op->id)->sole();
$state->lost = new SendFailure('timeout', 24993);
$logged = [];
app('events')->listen(MessageLogged::class, static function ($event) use (&$logged): void {
    if ($event->message === 'Domains renewal reply not recorded.') { $logged[] = [$event->level, $event->context]; }
});
$job->handle($work);
$result = $op->fresh();
$payments = $invoice->transactions()->orderBy('id')->get()->mapWithKeys(static fn ($row) => [(int) $row->id => PricingRule::minor($row->amount)])->all();
same(2, count($payments), 'Both native receipts survive the lost reply');
same($payments, RenewalFundingLedger::entries($result)['payment'], 'The original reservation records each payment once');
same(['uncertain', 'renewal_confirmation_required', null, 1],
    [$result->state, $result->safe_code, $result->attempt['execution'], $state->writes], 'Lost paid renewal stays uncertain after one send');
same(['category' => 'timeout', 'timeout_ms' => 24993], $result->attempt['send_failure'], 'Attempt retains safe failure metadata');
same([['warning', ['operation_id' => $op->id, 'exception_class' => SendFailure::class, 'category' => 'timeout', 'timeout_ms' => 24993]]],
    $logged, 'Lost reply logs safe facts only');
require __DIR__.'/Support/staff-panel.php';
staffPanel();
Auth::setUser(staffUser(['admin.domains.view', 'admin.domains.manage', 'admin.domains.secrets-metadata']));
Livewire\Livewire::test(ViewOperation::class, ['record' => $result->id])->assertSee('timeout')->assertSee('24993');
$attempt = $op->fresh()->attempt;
$money = nativeFinancialSnapshot($op->fresh());
$reads = count($state->calls);
$writes = $state->writes;
// A pre-existing lost reply exposes the existing wait/support step before any worker creates status_check.
$beforeCheck=RegistrarCheck::forCustomer($op->fresh());
$deadline = (new DateTimeImmutable($attempt['dispatch_attempted_at']))->modify('+24 hours')->setTimezone(new DateTimeZone('America/Los_Angeles'));
expect(str_contains($beforeCheck['body'], $deadline->format('M j')) && empty($beforeCheck['asks']),
    'Before the first check the customer sees the original local deadline and has no action');
$result = $completion->run($op->id);
same('awaiting_answer', $result->policy['status_check']['outcome'], 'Unrecoverable renewal names the staff registrar-answer action');
same('Enom', $result->policy['status_check']['registrar'], 'Staff action names the bound registrar');
same((new DateTimeImmutable($attempt['dispatch_attempted_at']))->modify('+24 hours')->getTimestamp(),
    $result->policy['status_check']['answer_after'], 'Answer timing is tied to the original dispatch');
same('uncertain', $result->state, 'Missing reply is not a failure proof');
same($attempt, $result->attempt, 'Read checks preserve original attempt evidence');
same($money, nativeFinancialSnapshot($result), 'Read checks preserve customer money and issued debt');
// One read of the sealed attempt: the domain and its expiry, never history or a guessed order.
same([ReadCommand::Info, ReadCommand::Renewal, ReadCommand::Info, ReadCommand::Expiry], array_slice($state->calls, $reads), 'One sealed-attempt read with no unaccepted history or speculative order reads');
expect(RegistrarCheck::attempted($result), 'The read is recorded for this exact attempt');
same(null, app(Refunds::class)->creditRejected($op->id), 'Unknown renewal cannot return customer money');
$engine->run($op->id, $writer);
same($writes, $state->writes, 'Unknown original renewal never sends a second Extend');
// The real paid renewal reaches owner Blade with its own wait/support notice.
$cataloguePorts=$ports;require __DIR__.'/Support/owner-pages.php';
$owner=\App\Models\User::findOrFail($d->user_id);\Illuminate\Support\Facades\Auth::setUser($owner);
$request=\Illuminate\Http\Request::create('/domains/'.$d->id,'GET');$request->setUserResolver(fn()=>$owner);$request->setLaravelSession(app('request')->session());
$html=(new \Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController($owners))->show($request,$d->id)->render();
$deadline=(new DateTimeImmutable($attempt['dispatch_attempted_at']))->modify('+24 hours')->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('j'); // The deadline day, whatever the date format.
$notice=storefrontDom($html)->query('//*[@role="status" and contains(@class,"domains-notice")]')->item(0)?->textContent??'';
expect(str_contains($notice,'renewal')&&preg_match('/(?<!\d)'.$deadline.'(?!\d)/',$notice),'Actual uncertain paid renewal page shows the original dispatch deadline');
expect(!str_contains($html,'Ask Enom')&&!str_contains($html,'You can record'),'Owner has no staff-only answer instructions');
// At the existing staff boundary, only a real final answer can make this no-order attempt returnable.
$clock->instant = $clock->now()->modify('+25 hours');
$late=RegistrarCheck::forCustomer($result->fresh());
same(true,$late['asks']??false,'A late renewal check requires a customer support step');
$lateDomain=$owners->present($d->fresh());
same(true,\Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::notice($lateDomain,'/tickets/create')['asks']??false,'The renewal notice retains the customer step');
expect(EnomAnswers::eligible($op->fresh(), $clock->now()), 'Unchanged lost renewal reaches the existing audited answer path');
echo "Uncertain paid renewal retains money and exposes the existing registrar-answer action\n";
$nativeSuccessful = true;
