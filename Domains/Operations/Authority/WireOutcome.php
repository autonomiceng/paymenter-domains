<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

use Paymenter\Extensions\Servers\Domains\Data\SendState;

/** What one sealed attempt's wire call proved. A missing reply is recorded as unknown, never as unsent. */
final readonly class WireOutcome
{
    private function __construct(public string $kind, public SendState $sendState, public string $outcome, public array $evidence) {}

    /** A validated registrar reply for the sealed request. */
    public static function reply(SendState $state, string $disposition, array $evidence): self
    {
        if ($state === SendState::DefinitelyUnsent) { throw new \InvalidArgumentException('A registrar reply cannot prove a local refusal.'); }
        return new self('reply', $state, $disposition, $evidence);
    }

    /** The final transport proved it stopped before the wire. $evidence keeps the refusal's own facts beside its reason. */
    public static function localRefusal(string $reason, array $evidence = []): self
    { return new self('local_refusal', SendState::DefinitelyUnsent, 'unsent', [...$evidence, 'reason' => $reason]); }

    /** No reply, or one that cannot be tied to the request. The send stays possibly sent. */
    public static function unknown(array $evidence = []): self
    { return new self('unknown_reply', SendState::PossiblySent, 'unknown', $evidence); }
}
