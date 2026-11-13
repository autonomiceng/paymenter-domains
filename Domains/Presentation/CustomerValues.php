<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Value;
/** Customer page values as shared panel pieces: the domain's own state, a value we have not got, and a History step.
 * Words and tones come from State for the customer reader, so the list, the domain page and staff pages say the same word. */
final class CustomerValues
{
    /** The State for a domain state key. DomainStatus keys are State values; an unknown state reads Under review, as DomainStatus says. */
    public static function state(?string $state): State
    { return State::tryFrom(StatusPill::key($state)) ?? State::UnderReview; }

    /** The domain's own state with its reason line, or null when the state is not known (a monitored domain with no registry facts). */
    public static function status(?string $state, ?string $reason = null, ?string $record = null): ?Value
    {
        if ($state === null) { return null; }
        return Value::state(self::state($state), Reader::Customer, self::sentence(StatusPill::of($state, $reason)['reason']), $record);
    }

    /** The state an OwnedDomain carries as its DomainStatus label (the DTO keeps the label, not the key). */
    public static function labelled(string $label, ?string $reason = null, ?string $record = null): ?Value
    {
        foreach (DomainStatus::SET as $key => [$word]) {
            if ($word === $label) { return Value::state(State::from($key), Reader::Customer, self::sentence($reason), $record); }
        }
        return null;
    }

    /** A value we have not got yet, by the staff list's rule: Not read yet when it fills in by itself, Couldn't read otherwise. */
    public static function unread(?string $cause, bool $calm = false): Value
    { return Value::state($calm || Missing::needsNothing($cause) ? State::NotReadYet : State::CouldntRead, Reader::Customer, self::sentence($cause)); }

    /** A renewal invoice that is due to exist and doesn't: not a failed read, so it reads Not issued yet, with the expected date under it. */
    public static function notIssued(?string $cause): Value
    {
        $rest = $cause === null ? '' : trim(preg_replace('/^Not issued yet\.?/', '', $cause));
        return Value::state(State::NotIssuedYet, Reader::Customer, self::sentence($rest));
    }

    /** A History word as a State. In progress names a tone, not a state: a change waiting on the registrar reads Checking.
     * Done reads Completed and Unreadable reads Couldn't read (DESIGN.md Panels, merged words). Null for a word with no state. */
    public static function step(string $word): ?State
    {
        $merged = ['In progress' => State::Checking, 'Done' => State::Completed, 'Unreadable' => State::CouldntRead];
        if (isset($merged[$word])) { return $merged[$word]; }
        foreach (State::cases() as $state) { if ($state->label() === $word) { return $state; } }
        return null;
    }

    /** A reason line is a sentence; one that is empty or reads as a word is left out. */
    private static function sentence(?string $text): ?string
    { return $text === null || trim($text) === '' || Fact::isWord($text) ? null : $text; }
}
