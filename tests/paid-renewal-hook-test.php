<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Paymenter\Extensions\Servers\Domains\Money\Allocations\{PostingSource, RenewalFundingLedger};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\{Auth, DB, Event, Queue};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Data\{ExecutionRecord, SendFailure};
use Paymenter\Extensions\Servers\Domains\Jobs\RunOperation;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\OperationSchema;
use Paymenter\Extensions\Servers\Domains\Services\{EnomAnswers, NativePaidHooks, PricingRule, RegistrarCheck, Refunds, RuntimeWork};
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
// Watch the owner lock that opens the holding prefix in the transactions that seal or open a read.
$transaction = 0;
$lockTrace = [];
Event::listen(TransactionBeginning::class, static function () use (&$transaction): void { $transaction++; });
DB::listen(static function ($query) use (&$transaction, &$lockTrace): void {
    if ($query->connection->transactionLevel() !== 1) { return; }
    if (str_starts_with($query->sql, 'select * from "users" where "users"."id" in (')) {
        $lockTrace[$transaction][] = 'owner lock';
    } elseif (str_starts_with($query->sql, 'update "'.OperationSchema::ATTEMPTS.'" ')) {
        if (str_contains($query->sql, '"sealed_at" = ?')) { $lockTrace[$transaction][] = 'seal'; }
        if (str_contains($query->sql, '"read_token" = ?') && $query->bindings[0] !== null) {
            $lockTrace[$transaction][] = 'open read';
        }
    }
});
// The fixed clock is the seal time; the deadline checks below use this capture, not the reader under test.
$dispatchedAt = $clock->now();
$job->handle($work);
$result = $op->fresh();
$payments = $invoice->transactions()->orderBy('id')->get()->mapWithKeys(static fn ($row) => [(int) $row->id => PricingRule::minor($row->amount)])->all();
same(2, count($payments), 'Both native receipts survive the lost reply');
same($payments, RenewalFundingLedger::reservation($result)?->postings(PostingSource::Payment), 'The original reservation records each payment once');
same(['uncertain', 'renewal_confirmation_required', 'unknown', 1],
    [$result->state, $result->safe_code, renewalReply($result)['outcome'], $state->writes], 'Lost paid renewal stays uncertain after one send');
same(ExecutionRecord::RecordedNull, renewalAttempt($result)?->replyRecord(), 'The lost reply is saved as an explicit null reply');
same($dispatchedAt->getTimestamp(), renewalSentAt($result)->getTimestamp(), 'The seal records the dispatch time');
same(['category' => 'timeout', 'timeout_ms' => 24993], renewalAttempt($result)?->sendFailure(), 'Attempt retains safe failure metadata');
same([['warning', ['operation_id' => $op->id, 'exception_class' => SendFailure::class, 'category' => 'timeout', 'timeout_ms' => 24993]]],
    $logged, 'Lost reply logs safe facts only');
require __DIR__.'/Support/staff-panel.php';
staffPanel();
Auth::setUser(staffUser(['admin.domains.view', 'admin.domains.manage', 'admin.domains.secrets-metadata']));
Livewire\Livewire::test(ViewOperation::class, ['record' => $result->id])->assertSee('timeout')->assertSee('24993');
$attempt = renewalRecord($op);
$money = nativeFinancialSnapshot($op->fresh());
$reads = count($state->calls);
$writes = $state->writes;
// A pre-existing lost reply exposes the existing wait/support step before any worker creates status_check.
$beforeCheck=RegistrarCheck::forCustomer($op->fresh());
$deadline = $dispatchedAt->modify('+24 hours')->setTimezone(new DateTimeZone('America/Los_Angeles'));
expect(preg_match('/(?<!\d)'.$deadline->format('j').'(?!\d)/', $beforeCheck['body']) && empty($beforeCheck['asks']),
    'Before the first check the customer sees the original local deadline and has no action');
$result = $completion->run($op->id);
$transitionLocks = array_values(array_filter($lockTrace, static fn (array $trace) =>
    in_array('seal', $trace, true) || in_array('open read', $trace, true)));
same([['owner lock', 'seal'], ['owner lock', 'open read']], $transitionLocks,
    'Sealing and opening a reconciliation read each take the holding prefix once: '.json_encode($transitionLocks));
same('awaiting_answer', $result->policy['status_check']['outcome'], 'Unrecoverable renewal names the staff registrar-answer action');
same('Enom', $result->policy['status_check']['registrar'], 'Staff action names the bound registrar');
same($dispatchedAt->modify('+24 hours')->getTimestamp(),
    $result->policy['status_check']['answer_after'], 'Answer timing is tied to the original dispatch');
same('uncertain', $result->state, 'Missing reply is not a failure proof');
same($attempt, renewalRecord($result), 'Read checks preserve original attempt evidence');
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
$deadline=$dispatchedAt->modify('+24 hours')->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('j'); // The deadline day, whatever the date format.
$ownerPage = storefrontDom($html);
$notices = array_map(static fn ($node) => $node->textContent, iterator_to_array($ownerPage->query('//*[@role="status"]')));
expect((bool) preg_match('/(?<!\d)'.$deadline.'(?!\d)/', implode(' ', $notices)), 'Owner status shows the original dispatch deadline');
expect(!str_contains($html, '24993') && $ownerPage->query('//*[@name="case_reference" or @name="order_reference"]')->length === 0,
    'The owner cannot read the staff transport detail or submit a registrar answer');
// At the existing staff boundary, only a real final answer can make this no-order attempt returnable.
$clock->instant = $clock->now()->modify('+25 hours');
$late=RegistrarCheck::forCustomer($result->fresh());
same(true,$late['asks']??false,'A late renewal check requires a customer support step');
$lateDomain=$owners->present($d->fresh());
same(true,\Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::notice($lateDomain,'/tickets/create')['asks']??false,'The renewal notice retains the customer step');
expect(EnomAnswers::eligible($op->fresh(), $clock->now()), 'Unchanged lost renewal reaches the existing audited answer path');
echo "Uncertain paid renewal retains money and exposes the existing registrar-answer action\n";
$nativeSuccessful = true;
