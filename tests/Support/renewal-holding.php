<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

use App\Events\Invoice\Creating;
use App\Listeners\InvoiceNumberListener;
use App\Models\{Invoice, InvoiceItem, InvoiceTransaction, User};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\{Auth, Crypt, DB};
use Illuminate\Support\Once;
use Paymenter\Extensions\Servers\Domains\Catalogue\CallerPorts;
use Paymenter\Extensions\Servers\Domains\Data\{CanonicalName, PriceSnapshot};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionResultSchema, OperationAttempts, OperationSchema};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionOffers, HoldingObservationWork, InvoiceDescription, InvoiceFunding, OperationClaims, PriceCatalogue, RegistrarAnswers};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;

/** Each opted-in test starts with one real paid acquisition. Subsequent cases get independent copies of its retained history. */
function reusableRenewalHolding(
    Closure $acquire,
    FakeClock $clock,
    CallerPorts $ports,
    AcquisitionOffers $offers,
    PriceCatalogue $prices,
    User $user,
): Closure
{
    $seed = null;
    return static function (string $name) use (&$seed, $acquire, $clock, $ports, $offers, $prices, $user): array {
        if ($seed === null) {
            [$original, $binding] = $acquire($name);
            $domain = $original->domain;
            same(['enom', 'com', 'active', 'register', 1, 'paid_invoice', 1], [
                $domain->registrar_key, $domain->tld, $domain->status, $original->action, $original->term,
                $original->funding_basis, $domain->confirmed_revision,
            ], 'Reusable holding starts with an ordinary confirmed Enom registration');
            $service = $domain->service;
            $order = $service->order;
            $property = $service->properties()->sole();
            $invoice = Invoice::findOrFail($original->invoice_id);
            expect($invoice->number !== null && !config('settings.invoice_proforma'), 'Fixture requires ordinary native invoice numbering');
            $item = $invoice->items()->sole();
            $payment = $invoice->transactions()->sole();
            $seed = compact('original', 'binding', 'domain', 'service', 'order', 'property', 'invoice', 'item', 'payment');
            return [$original, $binding];
        }
        ['original' => $original, 'domain' => $domain, 'binding' => $binding] = $seed;
        $clock->instant = $original->completed_at->toDateTimeImmutable();
        activeCommerceFacts($clock->now(), $clock->now()->modify('+1 day'), $ports);
        Carbon\Carbon::setTestNow($clock->now());
        Carbon\CarbonImmutable::setTestNow($clock->now());
        Once::flush();
        Auth::login($user);
        $canonical = CanonicalName::parse($name, [$domain->tld]);
        same($name, $canonical['domain'], 'Renewal holding fixture name is canonical');

        // Preserve the same historical catalogue windows and authority as checkout.
        $selection = $offers->selection($domain->tld, 'register', 1);
        expect($selection->serverId === $binding->serverId && $selection->fingerprint === $binding->fingerprint,
            'Reusable holding belongs to the same Enom seller');
        $register = $prices->current($binding->serverId, $binding->fingerprint, $domain->tld, 'register', 1, 'USD', $clock->now());
        $renew = $prices->current($binding->serverId, $binding->fingerprint, $domain->tld, 'renew', 1, 'USD', $clock->now());
        $price = static fn (PriceSnapshot $p) => [...get_object_vars($p), 'observedAt' => $p->observedAt->format(DATE_ATOM), 'freshUntil' => $p->freshUntil->format(DATE_ATOM)];
        same([$original->cost['estimated'], $original->policy['price']['retail']], [$register->wholesale, $register->retail],
            'Reusable holding keeps the historical acquisition cost and retail');
        same($domain->renewal_policy['retail'], $renew->retail, 'Reusable holding keeps the historical annual retail');

        $reused = DB::transaction(static function () use ($seed, $original, $domain, $canonical, $name, $clock, $selection, $price, $register, $renew) {
            // Start with the database rows that real checkout and reconciliation persisted.
            $copy = static function (Model $row, array $fields = []): int {
                $attributes = array_replace($row->getAttributes(), $fields);
                unset($attributes['id']);
                return DB::table($row->getTable())->insertGetId($attributes);
            };
            $orderId = $copy($seed['order']);
            $serviceId = $copy($seed['service'], ['order_id' => $orderId, 'label' => $name]);
            $copy($seed['property'], ['model_id' => $serviceId, 'value' => $name]);

            // Keep native invoice numbering. This paid invoice is starting state for the new renewal.
            $invoice = new Invoice;
            (new InvoiceNumberListener)->handle(new Creating($invoice));
            $invoiceId = $copy($seed['invoice'], ['number' => $invoice->number]);
            $itemId = $copy($seed['item'], ['invoice_id' => $invoiceId, 'reference_id' => $serviceId,
                'description' => InvoiceDescription::acquisition($name, 'register', 1, $clock->now())]);
            $paymentId = $copy($seed['payment'], ['invoice_id' => $invoiceId]);
            $key = 'invoice-item:'.$itemId;
            $checkout = [...$original->policy['checkout'], 'name' => $name, 'order_id' => $orderId,
                'service_id' => $serviceId, 'invoice_id' => $invoiceId, 'invoice_item_id' => $itemId,
                'catalogue' => get_object_vars($selection)];
            $holding = (new Domain)->setRawAttributes($domain->getRawOriginal())->fill([
                'domain' => $name, 'sld' => $canonical['sld'], 'reservation_name' => $name, 'service_id' => $serviceId,
                'snapshot' => [...$domain->snapshot, 'checkout' => $checkout],
                'renewal_policy' => [...$domain->renewal_policy, ...$price($renew), 'billing_anchor' => [...$domain->renewal_policy['billing_anchor'], 'operation_key' => $key]],
                'observation' => [...$domain->observation, 'followup' => [...$domain->observation['followup'], 'operation_key' => $key]],
            ]);
            $holding = Domain::findOrFail($copy($holding));

            $attempt = $original->attempt;
            $attempt['claim_key'] = bin2hex(random_bytes(16));
            $attempt['evidence']['registration']['query']['name'] = $name;
            $attempt['registration']['query']['name'] = $name;
            $attempt['registration_admission']['name'] = $name;
            $attempt['reconciliation']['query']['name'] = $name;
            $attempt['reconciliation']['operation_key'] = $key;
            $op = (new Operation)->setRawAttributes(array_diff_key($original->getRawOriginal(), ['id' => true]))->fill([
                'domain_id' => $holding->id, 'service_id' => $serviceId, 'invoice_id' => $invoiceId,
                'invoice_item_id' => $itemId, 'operation_key' => $key, 'attempt' => $attempt, 'started_at' => $original->started_at,
            ]);
            // Recompute the production digest for the new operation, name and frozen evidence.
            $op->request_fingerprint = (new ReflectionMethod(OperationClaims::class, 'digest'))->invoke(null, $op, $holding);
            $policy = [...$original->policy, 'checkout' => $checkout, 'price' => $price($register), 'seller_revision' => $selection->policyRevision];
            $policy['status_check']['attempt_identity'] = RegistrarAnswers::identity($op);
            $op->policy = $policy;
            $invoice = Invoice::findOrFail($invoiceId);
            $service = $holding->service;
            $funding = InvoiceFunding::requirePaidFacts($op, [
                'domain' => $holding, 'service' => $service, 'product' => $service->product,
                'orders' => collect([$service->order]), 'invoices' => collect([$invoice]),
                'items' => collect([InvoiceItem::findOrFail($itemId)]),
                'transactions' => collect([InvoiceTransaction::findOrFail($paymentId)]),
            ]);
            $retained = Operation::findOrFail($copy($op, ['attempt' => null, 'funding' => null,
                'started_at' => null, 'request_fingerprint' => null]));
            copyRenewalHoldingLedger($original, $retained, $attempt, $funding, $op->request_fingerprint);
            same($attempt, $retained->attempt, 'Copied holding has its own sealed acquisition evidence');
            HoldingObservationWork::queueInitial($holding, $clock->now());
            return $retained;
        });
        return [$reused, $binding];
    };
}

/** Copy the completed fixture's single attempt, rewriting holding-bound evidence for the new native rows. */
function copyRenewalHoldingLedger(Operation $original, Operation $retained, array $attempt, array $funding, string $digest): void
{
    $source = $original->acquisitionAttemptFacts()->token;
    $copy = static function (string $table, object $row, array $fields): int {
        $attributes = array_replace((array) $row, $fields);
        unset($attributes['id']);
        return DB::table($table)->insertGetId($attributes);
    };
    $step = DB::table(OperationSchema::STEPS)->where('id', $source->stepId)->sole();
    $stepId = $copy(OperationSchema::STEPS, $step, ['operation_id' => $retained->id, 'current_attempt_id' => null]);
    $originalAttempt = DB::table(OperationSchema::ATTEMPTS)->where('step_id', $source->stepId)->sole();
    $attemptId = $copy(OperationSchema::ATTEMPTS, $originalAttempt,
        ['step_id' => $stepId, 'claim_key' => $attempt['claim_key'], 'request_digest' => $digest]);
    DB::table(OperationSchema::STEPS)->where('id', $stepId)->update(['current_attempt_id' => $attemptId]);

    $authorities = [];
    $rows = DB::table(OperationSchema::EVIDENCE)->where('attempt_id', $source->attemptId)->orderBy('id')->get();
    foreach ($rows as $row) {
        $facts = OperationAttempts::decodeEvidence($row->evidence);
        $facts = match ($row->kind) {
            'claim' => ['identity' => $attempt['claim_key'], 'digest' => $digest, 'funding' => $funding],
            'admission' => [...$facts, 'funding' => $funding, 'registration_admission' => $attempt['registration_admission'],
                'window' => [...$facts['window'], 'authority_id' => $authorities[$facts['window']['authority_id']]]],
            'reply' => [...$facts, 'evidence' => $attempt['evidence']],
            'decision' => [...$facts, 'registration' => $attempt['registration']],
            'settlement' => [...$facts, 'reconciliation' => $attempt['reconciliation']],
            default => $facts,
        };
        $authorities[$row->id] = $copy(OperationSchema::EVIDENCE, $row, ['operation_id' => $retained->id,
            'attempt_id' => $attemptId, 'authority_key' => hash('sha256', $retained->id.':'.$row->authority_key),
            'evidence' => Crypt::encryptString(json_encode($facts, JSON_THROW_ON_ERROR))]);
    }
    $result = (array) DB::table(AcquisitionResultSchema::TABLE)->where('operation_id', $original->id)->sole();
    DB::table(AcquisitionResultSchema::TABLE)->insert([...$result, 'operation_id' => $retained->id,
        'window_authority_id' => $authorities[$result['window_authority_id']]]);
}
