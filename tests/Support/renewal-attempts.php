<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{Money, SendState};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Renewal\Execution\RenewalAttempt;

// Renewal attempt fixtures. Tests seal and read a renewal only through these functions and the RenewalAttempt reader,
// so the bodies can move to the operation attempt rows without changing a call site. Today the attempt lives in the
// operation's JSON attempt record, in the shape the renewal seal writes.

if (!class_exists('RenewalReply', false)) {
    /** Test value: what one sealed renewal's wire call proved. A missing reply is unknown, never unsent. */
    final readonly class RenewalReply
    {
        private function __construct(public string $kind, public SendState $sendState, public string $outcome, public array $evidence) {}

        /** A registrar reply for the sealed request: its send state, disposition and the reason and discrepancies it gave. */
        public static function reply(SendState $state, string $disposition, array $evidence = []): self
        {
            if ($state === SendState::DefinitelyUnsent) { throw new InvalidArgumentException('A registrar reply cannot prove a local refusal.'); }
            return new self('reply', $state, $disposition, $evidence);
        }

        /** The final transport proved it stopped before the wire. */
        public static function localRefusal(string $reason = 'unsent'): self
        { return new self('local_refusal', SendState::DefinitelyUnsent, 'unsent', ['reason' => $reason]); }

        /** No reply, or one that cannot be tied to the request. The send stays possibly sent. */
        public static function unknown(array $evidence = []): self
        { return new self('unknown_reply', SendState::PossiblySent, 'unknown', $evidence); }

        /** The reply as the attempt record saves it; a lost reply is saved as null. */
        public function record(): ?array
        {
            if ($this->kind === 'unknown_reply') { return null; }
            return ['disposition' => $this->kind === 'local_refusal' ? 'rejected' : $this->outcome, 'send_state' => $this->sendState->value,
                'reason' => $this->evidence['reason'] ?? $this->outcome, 'discrepancies' => $this->evidence['discrepancies'] ?? []];
        }
    }
}

if (!function_exists('sealRenewal')) {
    /**
     * Fixture: a renewal sealed at $at for $amount USD in $mode, as the renewal seal records it. $attempt adds or replaces
     * attempt members and $columns sets other operation columns in the same save. A $reply is recorded on the sealed attempt.
     */
    function sealRenewal(Operation $op, array $attempt = [], array $columns = [], ?RenewalReply $reply = null,
        ?DateTimeImmutable $at = null, string $amount = '8.95', string $mode = 'ordinary'): Operation
    {
        expect($op->action === 'renew', 'Only a renewal has a renewal attempt');
        $at ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $sealed = ['claim_key' => substr(hash('sha256', 'fixture-claim|'.$op->operation_key), 0, 32), 'seal' => 'sealed',
            'dispatch_attempted_at' => $at->format(DATE_ATOM), 'send_state' => SendState::PossiblySent->value,
            'authorized_cost' => ['amount' => (new Money($amount, 'USD'))->amount, 'currency' => 'USD'], 'renewal_mode' => $mode];
        if ($reply !== null) { $sealed['execution'] = $reply->record(); }
        // One quiet write with the model's casts, as saveQuietly writes it, without reading the row first.
        $saved = Operation::whereKey($op->id)->update((new Operation)->forceFill([...$columns, 'attempt' => [...$sealed, ...$attempt]])->getAttributes());
        expect($saved === 1, 'The sealed renewal row exists');
        return $op->refresh();
    }

    /** Runs $check against a temporarily sealed renewal with an optional $reply, then puts the operation row back as it was. */
    function withSealedRenewal(Operation $op, Closure $check, array $attempt = [], ?DateTimeImmutable $at = null, ?RenewalReply $reply = null): mixed
    {
        $raw = (array) DB::table('domains_operations')->where('id', $op->id)->first();
        try { return $check(sealRenewal($op, $attempt, reply: $reply, at: $at)); }
        finally { DB::table('domains_operations')->where('id', $op->id)->update($raw); $op->refresh(); }
    }

    /** The renewal's sealed attempt as the production reader reads it now; null before the seal. */
    function renewalAttempt(Operation $op): ?RenewalAttempt
    { $facts = RenewalAttempt::of(Operation::findOrFail($op->id)); return $facts->sealed() ? $facts : null; }

    /** The sealed attempt's recorded wire outcome: a registrar disposition, unsent or unknown; null when no reply is recorded. */
    function renewalReply(Operation $op): ?array
    { $outcome = renewalAttempt($op)?->replyOutcome(); return $outcome === null ? null : ['outcome' => $outcome]; }

    /** When the renewal was sealed for dispatch, in UTC. */
    function renewalSentAt(Operation $op): DateTimeImmutable
    { $at = renewalAttempt($op)?->sentAt(); expect($at !== null, 'The renewal has a sealed attempt'); return $at; }
}
