<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

/** Saved progress of one order history scan, kept in the encrypted operation policy. The provider reader owns and validates
 * its state: listed order IDs, per-order outcomes, counts and refusal categories. Never replies, contacts or credentials. */
final readonly class HistoryScan
{
    public const VERSION = 'history_scan_v1';
    /** $progressed: this read added order outcomes and more remain, so it is not a failed check. */
    public function __construct(public string $key, public array $state, public bool $progressed = false)
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $key) || strlen(json_encode($state, JSON_THROW_ON_ERROR)) > 32768) {
            throw new \InvalidArgumentException('Invalid history scan.');
        }
    }
    /** Contract revision: a different accepted contract or scanner version starts a new scan and allows one resumption. */
    public static function revision(HistoryAcceptance $acceptance): string
    { return hash('sha256', self::VERSION.json_encode($acceptance, JSON_THROW_ON_ERROR)); }
    /** Binds saved progress to this exact attempt, account, window, action and term, and contract revision. */
    public static function key(AttemptEvidence $attempt): ?string
    {
        if (!$attempt->historyAcceptance) { return null; }
        return hash('sha256', json_encode([self::revision($attempt->historyAcceptance), $attempt->operationKey, $attempt->authorityGeneration,
            $attempt->binding->fingerprint, $attempt->windowStart->getTimestamp(), $attempt->windowEnd->getTimestamp(),
            $attempt->query->name, $attempt->query->action->value, $attempt->query->years], JSON_THROW_ON_ERROR));
    }
    public static function load(mixed $saved): ?self
    {
        try { return is_array($saved) && is_string($saved['key'] ?? null) && is_array($saved['state'] ?? null) ? new self($saved['key'], $saved['state']) : null; }
        catch (\Throwable) { return null; }
    }
    public function saved(): array { return ['key' => $this->key, 'state' => $this->state]; }
}
