<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Illuminate\Contracts\Support\Htmlable;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Row, Trace, Value};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact, Missing, Reader, State};
/** A registrar request's status, facts and history (RequestFacts, StaffReviewHistory) as panel rows and a trace, for
 * the Detail page type. A missing value takes Not read yet when its cause says nothing is needed, else Couldn't read. */
final class RequestRows
{
    /** The request's own word from the closed list. In progress waits to start (Queued) or on the registrar (Checking).
     * A hold staff placed reads Under review (DESIGN.md Status). @param array{label:string} $status RequestFacts::status() */
    public static function state(array $status, Operation $op): State
    {
        return State::tryFrom(strtolower(str_replace(' ', '_', $status['label'])))
            ?? match ($status['label']) { 'In progress' => in_array($op->state, ['queued', 'awaiting_payment'], true) ? State::Queued : State::Checking, default => State::UnderReview };
    }
    /** The sheet's first row: the notice's cause and next step, with its one action. A cause staff must act on reads the
     * request's word when that word already asks staff to act, else Needs review; a page that can't be read reads Couldn't read.
     * @param array{title:string,body?:?string,tone:string} $notice */
    public static function notice(array $notice, State $state, ?Action $action): Row
    {
        $word = match ($notice['tone']) {
            'danger' => $notice['title'] === DomainFacts::UNREADABLE ? State::CouldntRead : State::Failed,
            'warning' => $state->tone(Reader::Staff) === \Paymenter\Extensions\Servers\Domains\Presentation\Tone::Attention ? $state : State::NeedsReview,
            default => $state,
        };
        // Each part is made a sentence on its own, so a typed body never reaches the page as markup; a timed retry stays a time.
        $try = $notice['next_try'] ?? null;
        $line = self::sentence(implode(' ', array_filter([self::sentence($notice['title']), self::sentence($notice['body'] ?? null), $try === null ? null : 'Next try'])));
        return Row::notice('request', Value::state($word, Reader::Staff, $line, null, $try === null ? null : Fact::date((int) $try, Reader::Staff)), $action);
    }
    /** The request's reason, when no notice gives one: the head shows the word, so the reason opens the sheet. */
    public static function reason(State $state, string $reason, string $record): Row
    { return Row::notice('request', Value::state($state, Reader::Staff, self::sentence($reason), $record)); }
    /** A text fact with its line, or the state its cause gives when it has no value. */
    public static function text(?string $value, ?string $sub = null, ?string $cause = null): Value
    {
        $fact = $value === null ? null : (Fact::host($value) ?? Fact::names([$value]));
        return $fact === null ? self::missing($cause ?? $sub) : Value::fact($fact, self::sentence($sub));
    }
    /** A moment, or the state its cause gives when none was saved. */
    public static function time(?\DateTimeInterface $at, string $cause): Value
    { return $at === null ? self::missing($cause) : Value::fact(Fact::date($at, Reader::Staff)); }
    /** The invoice: its state with the invoice name and Auto Pay under it. A linked invoice with no readable state keeps its name. */
    public static function invoice(?string $name, ?string $state, ?string $sub): Value
    {
        $word = match ($state) { 'Paid' => State::Paid, 'Not paid' => State::AwaitingPayment, 'Cancelled' => State::Cancelled, default => null };
        if ($word === null || $name === null) { return self::text($name, $sub); }
        return Value::state($word, Reader::Staff, self::sentence(implode('. ', array_filter([$name, $sub]))));
    }
    /** History rows, newest first, as rail steps. The newest step carries the request's own word; earlier ones have no state of their own.
     * @param list<array{time:?string,event:string,sub:?string,by:string}> $rows */
    public static function trace(array $rows, State $state): Trace
    {
        $trace = Trace::make(Reader::Staff);
        foreach ($rows as $i => $r) {
            $at = is_string($r['time'] ?? null) && ($t = strtotime($r['time'])) !== false ? $t : null;
            $reason = self::sentence($r['sub'] ?? null);
            $trace->step((string) $r['event'], $i === 0 ? $state : null, $at, $r['by'] ?? null, $reason !== null && !Fact::isWord($reason) ? $reason : null);
        }
        return $trace;
    }
    private static function missing(?string $cause): Value
    {
        $cause = self::sentence($cause);
        return Value::state($cause !== null && Missing::needsNothing($cause) ? State::NotReadYet : State::CouldntRead, Reader::Staff, $cause);
    }
    /** Plain text for the line under a value. A lone state or plain word gets its full stop, so it reads as a sentence. */
    public static function sentence(mixed $text): ?string
    {
        if ($text instanceof Htmlable) { $text = html_entity_decode(strip_tags($text->toHtml()), ENT_QUOTES); }
        $text = is_string($text) ? trim((string) preg_replace('/\s+/u', ' ', $text)) : '';
        if ($text === '') { return null; }
        return Fact::isWord($text) ? $text.'.' : $text;
    }
}
