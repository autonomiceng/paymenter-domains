<?php
// Installed Enom purchase expiry and read publication with synthetic Paymenter checkout and cURL.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$liveTransferRuntime = true;
require __DIR__.'/enom-live-transfer-test.php';
require_once __DIR__.'/Support/staff-panel.php';

use Illuminate\Support\Facades\{Auth, DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\OperationActions;
use Paymenter\Extensions\Servers\Domains\Data\{HistoryAcceptance, Money};
use Paymenter\Extensions\Servers\Domains\Jobs\RunOperation;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{OperationAttempts, OperationSchema, OperationWriter, SealTerms, Transition};
use Paymenter\Extensions\Servers\Domains\Services\{HistoryAcceptances, OperationClaims, PricingRule, Refunds, RuntimeWork, StaffOperationReview, StaffOperations, StaffReviewRefused, TransferInputs};

$nativeSuccessful = false;
$move = static function (int $seconds) use ($clock): void {
    $clock->at = $clock->now()->modify('+'.$seconds.' seconds');
    Carbon\Carbon::setTestNow($clock->now());
    Carbon\CarbonImmutable::setTestNow($clock->now());
};
$refunds = new Refunds($clock);
$credit = static fn (): int => PricingRule::minor((string) (App\Models\Credit::where('user_id', $user->id)
    ->where('currency_code', 'USD')->value('amount') ?? '0.00'));

$move(61);
$reset();
$ledger->update(['used' => 0]); // Start the next synthetic scenario with unused daily read capacity.
Auth::setUser($staff);
installedOfferEvidence($binding, $clock, true);
$cart = commerceCart($user);
$name = placeholderComFixtureName('enom-delayed-transfer-placeholder.com');
commerceAdd($cart, $transferProduct, $name);
commerceCheckout($user, $cart)->assertHasNoErrors();
$sent = Domain::where('domain', $name)->sole()->operations()->sole();
App\Helpers\ExtensionHelper::addPayment($sent->invoice_id, null, $sent->amount);
$request = Illuminate\Http\Request::create('/code', 'POST', ['transfer_code' => 'SyntheticOnly']);
$request->setUserResolver(fn () => $user);
app(TransferInputs::class)->submitCode($request, $sent->id);
$transferState->pending = true;
(new RunOperation($sent->id))->handle(app(RuntimeWork::class));
$sent->refresh();
expect($sent->acquisitionAttemptFacts()?->grantConsumedAt !== null, 'The submitted transfer spent its original purchase grant');
HistoryAcceptances::save(new HistoryAcceptance($binding, 'America/Los_Angeles', 0, 86400, 'enom_transfer_v1'));
$move(61);
$reset();
$GLOBALS['enomWire']['reply'] = static function (array $form) use ($setupReply): string {
    $reply = $setupReply($form);
    return $form['Command'] === 'TP_GetOrder'
        ? str_replace(['<StatusID>5</StatusID>', '<StatusID>100</StatusID>'], ['<StatusID>2</StatusID>', '<StatusID>7</StatusID>'], $reply)
        : $reply;
};
$paused = false;
DB::listen(static function ($query) use ($sent, $clock, $move, &$paused): void {
    if ($paused || DB::transactionLevel() === 0 || !str_contains($query->sql, 'from "users"')
        || !in_array('TP_GetOrder', array_column($GLOBALS['enomWire']['forms'], 'Command'), true)) { return; }
    $paused = true;
    $read = $sent->fresh()->acquisitionAttemptFacts();
    $move($read->readUntil->getTimestamp() - $clock->now()->getTimestamp());
});
$sentState = $sent->state;
$polls = $sent->policy['reconciliation']['polls'] ?? 0;
$status = $sent->policy['status_check'] ?? null;
app(\Paymenter\Extensions\Servers\Domains\Services\OperationReconciliation::class)->run($sent->id);
expect($paused, 'The finishing lock waits until the read expires after an Enom transfer reply '.json_encode([
    $sent->fresh()->state, $sent->fresh()->safe_code, $sent->fresh()->policy['reconciliation'] ?? null, array_column($GLOBALS['enomWire']['forms'], 'Command'),
]));
$sent->refresh();
$delayed = [$sent->state, $sent->policy['reconciliation']['polls'] ?? 0,
    $sent->policy['reconciliation']['transfer_status'] ?? null, $sent->policy['status_check'] ?? null];

$expired = [];
foreach (['transfer' => $transferProduct, 'orphan-read' => $transferProduct] as $case => $nativeProduct) {
    $stage = 'checkout';
    try {
        $move(61);
        $reset();
        Auth::setUser($staff);
        installedOfferEvidence($binding, $clock, true);
        $cart = commerceCart($user);
        $name = placeholderComFixtureName('enom-expired-'.$case.'-placeholder.com');
        commerceAdd($cart, $nativeProduct, $name);
        commerceCheckout($user, $cart)->assertHasNoErrors();
        $order = Domain::where('domain', $name)->sole()->operations()->sole();
        App\Helpers\ExtensionHelper::addPayment($order->invoice_id, null, $order->amount);
        $request = Illuminate\Http\Request::create('/code', 'POST', ['transfer_code' => 'SyntheticOnly']);
        $request->setUserResolver(fn () => $user);
        app(TransferInputs::class)->submitCode($request, $order->id);
        $stage = 'claim';
        $claim = app(OperationClaims::class)->claim($order->id);
        expect($claim !== null, 'Funded Enom order owns its installed claim');
        [$order] = $claim;
        $facts = $order->acquisitionAttemptFacts();
        $at = $facts->claimUntil;
        DB::transaction(static function () use ($order, $facts, $clock): void {
            Operation::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $terms = new SealTerms($order->transition_revision, $order->request_fingerprint, new Money('10.00', 'USD'),
                'transfer', $clock->now()->modify('+5 seconds'));
            same(Transition::Applied, (new OperationWriter($clock))->seal($facts->token, $terms,
                ['funding' => $order->funding['claim'], 'transfer_admission' => []]), 'The stopped worker sealed without spending');
        });
        $at = max($at, TransferInputs::deadline($order, $clock->now()));
        $move($at->getTimestamp() - $clock->now()->getTimestamp() + 1);
        $reset();
        $order->refresh();
        $actor = staffUser(['admin.domains.view', 'admin.domains.manage', 'admin.invoices.update',
            StaffOperationReview::permission('refund'), StaffOperationReview::permission('recheck')]);
        Auth::setUser($actor);
        $staffOperations = new StaffOperations($clock);
        if ($case === 'orphan-read') {
            // The old Recheck could leave this read open on an unspent seal and put the order in reconciliation.
            DB::table(OperationSchema::ATTEMPTS)->where('id', $facts->token->attemptId)->update([
                'read_token' => str_repeat('a', 32), 'read_until' => OperationAttempts::at($clock->now()->modify('-1 second')),
                'read_unresolved' => true,
            ]);
            $order->update(['state' => 'uncertain', 'safe_code' => 'reconciliation_required']);
        }
        $stage = 'Recheck';
        $ticket = StaffOperationReview::ticket($actor->id, $order->domain_id, $order->id, 'recheck',
            StaffOperationReview::stamp($order, $order->domain), $clock);
        try { $staffOperations->run($actor, $order->domain_id, $order->id, 'recheck', $ticket, 'Check the expired transfer'); }
        catch (StaffReviewRefused) {} // Settlement can require a fresh review of the changed order.
        $order->refresh();
        same([], $GLOBALS['enomWire']['forms'], 'Recheck makes no registrar read for an expired unused seal');
        $retired = $order->acquisitionAttemptFacts();
        $local = OperationAttempts::evidence($order->id, $facts->token->attemptId, 'reply');
        same(['held', true, false, null, null, false, 'local_refusal', 'unsent'], [
            $order->state, $retired?->released, $retired?->current, $retired?->grantConsumedAt,
            $retired?->readToken, $retired?->readUnresolved, $local['source'] ?? null, $local['outcome'] ?? null,
        ], 'Recheck retires the original seal and fences any orphaned read');
        $order->refresh();
        expect(OperationActions::relevant('refund', $order), 'The expired transfer exposes audited refund');
        $ticket = StaffOperationReview::ticket($actor->id, $order->domain_id, $order->id, 'refund',
            StaffOperationReview::stamp($order, $order->domain), $clock);
        $before = $credit();
        $stage = 'refund';
        $staffOperations->run($actor, $order->domain_id, $order->id, 'refund', $ticket, 'Return the expired purchase payment');
        same(['rejected', $before + PricingRule::minor($order->amount), []],
            [$order->fresh()->state, $credit(), array_values(array_intersect(['Purchase', 'TP_CreateOrder'], array_column($GLOBALS['enomWire']['forms'], 'Command')))],
            'Expired Enom orders return exactly one payment and make no purchase');
        $order->refresh();
        same($actor->id, $order->funding['refund_authorization']['actor_id'] ?? null, 'The return has one staff authorization');
        same(0, $refunds->retryPending($order->id - 1, 1), 'A later credit sweep leaves the audited return settled');
    } catch (Throwable $error) {
        $expired[$case] = [$stage, $error::class, $error->getMessage()];
    }
}
same([[], [$sentState, $polls, null, $status]], [$expired, $delayed],
    'Recheck retires unused purchases and expired transfer reads cannot publish: '.json_encode([$expired, $delayed]));
$nativeSuccessful = true;

