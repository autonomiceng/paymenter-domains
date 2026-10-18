<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;

use Paymenter\Extensions\Servers\Domains\Data\{Action, AttemptEvidence, DateEvidence, ExpiryPrecision, Money, OrderEvidence, RequestContext, Evidence, CanonicalName};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{Outcome, RegistrarRequest as Request};

/** Incomplete, synthetic-contract candidate search. Completeness requires explicit account acceptance. */
final class Correlation
{
    public function __construct(private readonly Client $client) {}
    public function candidates(#[\SensitiveParameter] AttemptEvidence $attempt, #[\SensitiveParameter] RequestContext $context): array
    {
        return $this->inspect($attempt, $context)->candidates;
    }
    public function inspect(AttemptEvidence $attempt, RequestContext $context): Evidence
    {
        $this->client->binding->requireMatch($context->binding); $this->client->binding->requireMatch($attempt->binding);
        if ($attempt->windowEnd->getTimestamp() - $attempt->windowStart->getTimestamp() > 604800) { return new Evidence; }
        $policy = $attempt->historyAcceptance; $complete = $policy?->covers($attempt, $context->now()) ?? false;
        $candidates = [];
        try {
            if ($attempt->providerReference !== null) { $ids = [$attempt->providerReference]; }
            else {
                // Broad discovery uses the accepted provider clock; date-filter semantics still require acceptance.
                $zone = new \DateTimeZone($policy?->timezone ?? 'UTC');
                $from = $attempt->windowStart->setTimezone($zone)->modify('-1 day')->format('Y-m-d');
                $to = $attempt->windowEnd->setTimezone($zone)->modify('+1 day')->format('Y-m-d');
                $reply = $this->client->read(Request::listOrders($from, $to), $context);
                if ($reply->outcome !== Outcome::Success) { return new Evidence; }
                $complete = $complete && $reply->value('history_complete') === true;
                $rows = self::rows($reply->value('order')); $ids = [];
                if (count($rows) > 20) { return new Evidence; }
                foreach ($rows as $row) {
                    $id = $row['order_number'] ?? null;
                    if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,19}$/D', $id) || in_array($id, $ids, true)) { return new Evidence; }
                    $ids[] = $id;
                }
            }
            foreach ($ids as $id) {
                $reply = $this->client->read(Request::orderDetails($id), $context);
                if ($reply->outcome !== Outcome::Success || $reply->value('order_number') !== $id) { $complete = false; continue; }
                $complete = $complete && $reply->value('history_complete') === true;
                $rows = self::rows($reply->value('order_details'));
                if (!$rows || count($rows) > 200) { $complete = false; continue; }
                foreach ($rows as $row) {
                    $label = match ($attempt->query->action) { Action::Register => 'registration', Action::Renew => 'renewal', Action::Transfer => 'transfer' };
                    if (($row['description'] ?? null) !== $attempt->query->name.' - '.$label
                        || ($row['years_qty'] ?? null) !== (string) $attempt->query->years) {
                        try {
                            if (!preg_match('/^(.+) - (registration|renewal|transfer)$/D', $row['description'] ?? '', $parts)
                                || CanonicalName::normalize($parts[1]) === $attempt->query->name) { $complete = false; }
                        } catch (\InvalidArgumentException) { $complete = false; }
                        continue;
                    }
                    $date = $policy?->date($reply->value('order_date'));
                    try { $date ??= new DateEvidence($reply->value('order_date'), ExpiryPrecision::Date, 'namesilo_order'); }
                    catch (\InvalidArgumentException|\TypeError) {} // No invented timezone for a naive timestamp.
                    if ($date?->precision === ExpiryPrecision::Instant
                        && (new \DateTimeImmutable($date->value) < $attempt->windowStart || new \DateTimeImmutable($date->value) > $attempt->windowEnd)) { $complete = false; continue; }
                    $charge = null;
                    if (($row['status'] ?? null) === 'Processed' && !array_key_exists('credited_amount', $row) && !array_key_exists('credited_date', $row)) {
                        try { $charge = new Money($row['subtotal'] ?? '', 'USD'); } catch (\InvalidArgumentException|\TypeError) {}
                    }
                    $candidates[] = new OrderEvidence($attempt->binding, $attempt->query, $charge, $id, $date);
                    if (count($candidates) === 200) { return new Evidence(candidates: $candidates); }
                }
            }
        } catch (\RuntimeException|\InvalidArgumentException) { $complete = false; } // Exhaustion/refusal never resolves the original attempt.
        return new Evidence(candidates: $candidates, historyComplete: $complete);
    }
    private static function rows(mixed $rows): array
    {
        return is_array($rows) ? (array_is_list($rows) ? $rows : [$rows]) : [];
    }
}
