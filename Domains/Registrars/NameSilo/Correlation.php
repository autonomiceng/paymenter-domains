<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;

use Paymenter\Extensions\Servers\Domains\Data\{Action, AttemptEvidence, DateEvidence, ExpiryPrecision, Money, OrderEvidence, RequestContext};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{Outcome, RegistrarRequest as Request};

/** Incomplete, synthetic-contract candidate search. Even one candidate is never actual-charge proof. */
final class Correlation
{
    public function __construct(private readonly Client $client) {}
    public function candidates(#[\SensitiveParameter] AttemptEvidence $attempt, #[\SensitiveParameter] RequestContext $context): array
    {
        $this->client->binding->requireMatch($context->binding); $this->client->binding->requireMatch($attempt->binding);
        if ($attempt->windowEnd->getTimestamp() - $attempt->windowStart->getTimestamp() > 604800) { return []; }
        $candidates = [];
        try {
            if ($attempt->providerReference !== null) { $ids = [$attempt->providerReference]; }
            else {
                // Date filters are broad discovery only: provider timezone/history completeness remain unproved.
                $zone = new \DateTimeZone('UTC');
                $from = $attempt->windowStart->setTimezone($zone)->modify('-1 day')->format('Y-m-d');
                $to = $attempt->windowEnd->setTimezone($zone)->modify('+1 day')->format('Y-m-d');
                $reply = $this->client->read(Request::listOrders($from, $to), $context);
                if ($reply->outcome !== Outcome::Success) { return []; }
                $rows = self::rows($reply->value('order')); $ids = [];
                if (count($rows) > 20) { return []; }
                foreach ($rows as $row) {
                    $id = $row['order_number'] ?? null;
                    if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,19}$/D', $id) || in_array($id, $ids, true)) { return []; }
                    $ids[] = $id;
                }
            }
            foreach ($ids as $id) {
                $reply = $this->client->read(Request::orderDetails($id), $context);
                if ($reply->outcome !== Outcome::Success) { continue; }
                $rows = self::rows($reply->value('order_details'));
                if (count($rows) > 200) { continue; }
                foreach ($rows as $row) {
                    $label = match ($attempt->query->action) { Action::Register => 'registration', Action::Renew => 'renewal', Action::Transfer => 'transfer' };
                    if (($row['description'] ?? null) !== $attempt->query->name.' - '.$label
                        || ($row['years_qty'] ?? null) !== (string) $attempt->query->years) { continue; }
                    $date = null;
                    try { $date = new DateEvidence($reply->value('order_date'), ExpiryPrecision::Date, 'namesilo_order'); }
                    catch (\InvalidArgumentException|\TypeError) {} // No invented timezone for a naive timestamp.
                    $charge = null;
                    if (($row['status'] ?? null) === 'Processed' && !array_key_exists('credited_amount', $row) && !array_key_exists('credited_date', $row)) {
                        try { $charge = new Money($row['subtotal'] ?? '', 'USD'); } catch (\InvalidArgumentException|\TypeError) {}
                    }
                    $candidates[] = new OrderEvidence($attempt->binding, $attempt->query, $charge, $id, $date);
                    if (count($candidates) === 200) { return $candidates; }
                }
            }
        } catch (\RuntimeException|\InvalidArgumentException) {} // Exhaustion/refusal never resolves the original attempt.
        return $candidates;
    }
    private static function rows(mixed $rows): array
    {
        return is_array($rows) ? (array_is_list($rows) ? $rows : [$rows]) : [];
    }
}
