<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace OteContract;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AttemptEvidence, DispatchSeal, ExecutionRecord, Money, Query, RequestBudget, RequestContext, RequestOrigin, SendState};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client, Driver};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{Outcome, PurchaseMapping, RegistrarRequest, ResponseParser};

/** Sandbox observation only; never native completion, accepted history or retry authority. */
final class RenewalProbe
{
    public static function run(GuardedTransport $wire, Client $client, AccountBinding $binding, Clock $clock): void
    {
        if ($wire->remaining() < ($wire->continuationExpiry === null ? 25 : 8)) { $wire->check('renew_probe', 'skipped_budget'); return; }
        $context = new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+900 seconds'),
            new RequestBudget($wire->remaining()), $clock);
        $info = $client->read(RegistrarRequest::domainInfo($wire->domain), $context);
        $expiry = Shapes::strictDate($info->value('expires'));
        if ($info->outcome !== Outcome::Success || $info->value('status') !== 'Active' || $info->value('auto_renew') !== 'No'
            || ($wire->continuationExpiry !== null && $info->value('expires') !== $wire->continuationExpiry)
            || $expiry === null || $expiry->getTimestamp() <= $clock->now()->getTimestamp() + 172800) {
            $wire->check('renew_probe', 'blocked_without_active_owned_autorenew_off_readback'); return;
        }
        try {
            $quote = $client->read(RegistrarRequest::prices(), $context);
            if ($quote->outcome !== Outcome::Success) { throw new \RuntimeException('Renewal quote unavailable.'); }
            $cost = new Money($quote->catalogueRows()['com']['renew'] ?? '', 'USD');
        } catch (\Throwable) { $wire->check('renew_probe', 'blocked_without_renewal_quote'); return; }
        $wire->purchaseAuthorization(static fn () => $cost);
        $wire->renewalBefore($expiry->format('Y-m-d'));
        $origin = GuardedTransport::purchaseOrigin('renewDomain', 'https://ote.namesilo.com/apibatch');
        $outcome = Outcome::Unknown;
        try {
            $body = $wire->raw($origin, 'renewDomain', ['domain' => $wire->domain, 'years' => 1, 'auto_renew' => 0]);
            $outcome = (new ResponseParser)->parse($body, PurchaseMapping::renew($wire->domain, 1, false),
                (string) getenv('NAMESILO_SANDBOX_API_KEY'))->outcome;
        } catch (\Throwable) {
            if (!$wire->attempted('renewDomain')) { throw new \RuntimeException('Renewal not dispatched.'); }
        }
        $wire->check('renew_auto_renew_request_accepted', $outcome === Outcome::Success);
        if (!in_array($outcome, [Outcome::Success, Outcome::Rejected], true)) {
            $category = self::reconcile($wire, $client, $binding, $clock);
            // The native purchase stays Unknown. Only renewed permits the sandbox readback sequence.
            if ($category !== 'renewed') { throw new \RuntimeException('OTE renewal needs investigation.'); }
            $wire->receipt['checks']['purchase_unresolved'] = false; $wire->save();
        }
        $after = $client->read(RegistrarRequest::domainInfo($wire->domain), $context);
        $wire->check('renew_auto_renew_remained_off', $after->outcome === Outcome::Success && $after->value('auto_renew') === 'No');
        $wire->check('renew_added_one_year', $after->outcome === Outcome::Success
            && $after->value('expires') === $expiry->modify('+1 year')->format('Y-m-d'));
        $wire->check('renew_parameter_effect', 'request_acceptance_does_not_prove_parameter_honored');
    }
    public static function completed(GuardedTransport $wire): bool
    {
        $completed=$wire->attempted('renewDomain')&&!$wire->receipt['mismatches']&&!($wire->receipt['checks']['purchase_unresolved']??false);
        $wire->check('completed',$completed);return $completed;
    }
    public static function reconcile(GuardedTransport $wire, Client $client, AccountBinding $binding, Clock $clock): string
    {
        $wire->receipt['renewal_reconciliation'] = ['category' => 'ambiguous', 'adopted' => false];
        $wire->check('purchase_unresolved', true);
        $category = 'ambiguous';
        try {
            $data = $wire->purchaseAttempt()->original();
            if ($data['command'] !== 'renewDomain' || $data['name'] !== $wire->domain) { throw new \RuntimeException('Renewal identity refused.'); }
            $expiry = Shapes::strictDate($data['before_expiry'] ?? null);
            if ($expiry === null) { throw new \RuntimeException('Renewal baseline unavailable.'); }
            $start = new \DateTimeImmutable($data['dispatch_utc']);
            $end = $clock->now();
            $attempt = new AttemptEvidence($binding, new Query($data['name'], Action::Renew, $data['term']), 'ote-original-renewal', 1,
                ExecutionRecord::Recorded, SendState::PossiblySent, $start, $end,
                expectedCharge: new Money($data['authorized_amount'], 'USD'), seal: DispatchSeal::Sealed,
                expectedExpiry: new \Paymenter\Extensions\Servers\Domains\Data\DateEvidence($expiry->modify('+1 year')->format('Y-m-d'),
                    \Paymenter\Extensions\Servers\Domains\Data\ExpiryPrecision::Date, 'ote_pre_renewal_readback'));
            $offset = $wire->receipt['request_count'];
            $context = new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+600 seconds'),
                new RequestBudget($wire->remaining()), $clock);
            $facts = (new Driver($client, ['renew'], $clock))->reconcile($attempt, $context)->result()->evidence();
            $reads = array_slice($wire->receipt['requests'], $offset);
            $healthy = count($reads) >= 2 && count($reads) <= 22;
            foreach ($reads as $read) {
                $healthy = $healthy && ($read['http_status'] ?? null) === 200 && ($read['code'] ?? null) === 300
                    && ($read['read_outcome'] ?? null) === 'Success' && ($read['read_validated'] ?? false) === true
                    && (!in_array($read['command'], ['listOrders', 'orderDetails'], true) || ($read['history_complete'] ?? null) === true) && !($read['transport_failed'] ?? false);
            }
            // Require a fully traversed bounded list, including every detail. No swallowed truncation or failure.
            $orders = $wire->latest('listOrders')['order'] ?? null;
            if (is_array($orders) && !array_is_list($orders)) { $orders = [$orders]; }
            $ids = is_array($orders) ? array_column($orders, 'order_number') : [];
            $details = array_filter($reads, static fn ($r) => $r['command'] === 'orderDetails');
            $healthy = $healthy && is_array($orders) && count($ids) === count($orders) && count($ids) <= 20
                && count(array_unique($ids)) === count($ids) && count($details) === count($ids);
            $sameNameRows = 0;
            foreach ($orders ?? [] as $header) {
                $detail = $wire->orderDetail((string) ($header['order_number'] ?? ''));
                $healthy = $healthy && ($detail['order_date'] ?? null) === ($header['order_date'] ?? null)
                    && ($detail['order_number'] ?? null) === ($header['order_number'] ?? null);
                $items = $detail['order_details'] ?? [];
                $healthy = $healthy && is_array($items) && count($items) > 0 && count($items) <= 200;
                if (is_array($items) && !array_is_list($items)) { $items = [$items]; }
                foreach ($items as $item) {
                    $description = $item['description'] ?? '';
                    if (!preg_match('/^(.+) - ((?i:registration|renewal|transfer))$/D', $description, $parts)) { $healthy = false; continue; }
                    try { $normalized = \Paymenter\Extensions\Servers\Domains\Data\CanonicalName::normalize($parts[1]); }
                    catch (\InvalidArgumentException) { $healthy = false; continue; }
                    if ($normalized === $data['name'] && strtolower($parts[2]) === 'renewal') { $sameNameRows++; }
                }
            }
            $owned = $facts->domain?->owned === true && $facts->domain->active === true && $facts->domain->name === $data['name'];
            $matches = array_filter($facts->candidates, static function ($row) use ($attempt, $data, $start, $end, $wire): bool {
                $date = $wire->orderDetail((string) $row->providerReference)['order_date'] ?? null;
                $parsed = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date, new \DateTimeZone('UTC')) : false;
                return $row->query->matches($attempt->query) && $row->charge?->currency === 'USD'
                    && rtrim(rtrim($row->charge->amount, '0'), '.') === rtrim(rtrim($data['authorized_amount'], '0'), '.')
                    && $parsed !== false && $parsed->format('Y-m-d H:i:s') === $date
                    && $parsed >= $start->modify('-120 seconds') && $parsed <= $end->modify('+120 seconds');
            });
            if ($healthy && $owned && $facts->domain->expiry?->value === $expiry->modify('+1 year')->format('Y-m-d')
                && $sameNameRows === 1 && count($facts->candidates) === 1 && count($matches) === 1) { $category = 'renewed'; }
            elseif ($healthy && $owned && $facts->domain->expiry?->value === $data['before_expiry'] && $sameNameRows === 0 && !$facts->candidates) {
                $category = 'not_renewed';
            }
        } catch (\Throwable) { $category = 'ambiguous'; }
        $wire->receipt['renewal_reconciliation']['category'] = $category;
        $wire->purchaseAttempt()->renewalCategory($category);
        $wire->save(); return $category;
    }
}
