<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

/** Explicit accepted all-pages USD history contract. No provider defaults or discovery imply acceptance. */
final readonly class HistoryAcceptance
{
    public function __construct(public AccountBinding $binding, public string $timezone,
        public int $lagSeconds, public int $retentionSeconds, public string $vocabulary)
    {
        if (($binding->registrarKey === 'enom' && $timezone !== 'America/Los_Angeles') || !in_array($timezone, \DateTimeZone::listIdentifiers(), true) || $lagSeconds < 0 || $lagSeconds > 86400
            || $retentionSeconds < 86400 || $retentionSeconds > 31536000
            || !in_array($vocabulary, [$binding->registrarKey.'_registration_v1', $binding->registrarKey.'_renewal_v1', $binding->registrarKey.'_transfer_v1'], true)) {
            throw new \InvalidArgumentException('History contract is unavailable.');
        }
    }
    public function covers(AttemptEvidence $attempt, \DateTimeImmutable $now): bool
    {
        $this->binding->requireMatch($attempt->binding);
        return in_array($attempt->query->action, [Action::Register, Action::Renew, Action::Transfer], true)
            && $this->vocabulary === $this->binding->registrarKey.match ($attempt->query->action) { Action::Renew => '_renewal_v1', Action::Transfer => '_transfer_v1', default => '_registration_v1' } && $attempt->windowEnd >= $attempt->windowStart
            && $attempt->windowEnd->getTimestamp() - $attempt->windowStart->getTimestamp() <= 300
            && $now->getTimestamp() >= $attempt->windowEnd->getTimestamp() + $this->lagSeconds
            && $now->getTimestamp() - $attempt->windowStart->getTimestamp() < $this->retentionSeconds;
    }
    public function date(mixed $input): ?DateEvidence
    { return self::localDate($input, $this->timezone, 'accepted_order_history'); }
    public static function localDate(mixed $input, string $timezone, string $source): ?DateEvidence
    {
        if (!is_string($input) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $input)) { return null; }
        $zone = new \DateTimeZone($timezone);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $input, $zone);
        if (!$date || $date->format('Y-m-d H:i:s') !== $input) { return null; }
        // Ambiguous fall-back local clocks cannot uniquely locate an attempt.
        foreach ($zone->getTransitions($date->getTimestamp() - 86400, $date->getTimestamp() + 86400) ?: [] as $transition) {
            $other = $date->setTimestamp($date->getTimestamp() + $date->getOffset() - $transition['offset']);
            if ($other->getTimestamp() !== $date->getTimestamp() && $other->format('Y-m-d H:i:s') === $input) { return null; }
        }
        return new DateEvidence($date->format(DATE_ATOM), ExpiryPrecision::Instant, $source, $timezone);
    }
}
