<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

use App\Events\Invoice\Creating;
use App\Listeners\InvoiceNumberListener;
use App\Models\{Invoice, InvoiceItem, InvoiceTransaction, User};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\{Auth, DB};
use Illuminate\Support\Once;
use Paymenter\Extensions\Servers\Domains\Catalogue\CallerPorts;
use Paymenter\Extensions\Servers\Domains\Data\{CanonicalName, PriceSnapshot};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
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
            $op = (new Operation)->setRawAttributes($original->getRawOriginal())->fill([
                'domain_id' => $holding->id, 'service_id' => $serviceId, 'invoice_id' => $invoiceId,
                'invoice_item_id' => $itemId, 'operation_key' => $key, 'attempt' => $attempt,
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
            $op->funding = ['claim' => $funding, 'seal' => $funding];
            $opId = $copy($op);
            HoldingObservationWork::queueInitial($holding, $clock->now());
            return Operation::findOrFail($opId);
        });
        return [$reused, $binding];
    };
}
