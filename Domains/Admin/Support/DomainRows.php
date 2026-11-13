<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Illuminate\Contracts\Support\Htmlable;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Row, Value};
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact, Missing, Plain, Reader, StaffTime, State};
/** A domain's facts (DomainFacts) as panel rows, for the domain's Detail page. A value the registrar did not return
 * reads Couldn't read, one not read yet Not read yet; On, Off and the other plain words stay plain. An open alert keeps
 * the value and says the alert under it; a change on its way takes the action's place until it finishes. */
final class DomainRows
{
    /** One fact row: its value, the line under it, its "?", its alert or change, and at most two actions. */
    public static function row(string $label, array $fact, array $actions = [], ?string $shown = null): Row
    {
        [$sub, $link] = RegistrarRows::line(self::words($fact), 'row_'.substr(md5($label), 0, 6));
        $alert = $fact['value'] !== null && in_array($fact['tone'] ?? null, ['danger', 'warning'], true) && $sub !== null;
        $row = Row::make($shown ?? $label, self::value($fact, $alert ? null : $sub))->key('fact_'.md5($label));
        if ($alert) { $row->result(Value::state($fact['tone'] === 'danger' ? State::Failed : State::NeedsReview, Reader::Staff, $sub)); }
        $progress = $fact['progress'] ?? null;
        if (is_array($progress) && ($text = RegistrarRows::sentence($progress['text'] ?? null)) !== null) {
            if ($progress['waiting'] ?? false) { $row->running(Value::state(($progress['tone'] ?? null) === 'warning' ? State::NeedsReview : State::Checking, Reader::Staff, $text)); }
            elseif (!$alert) { $row->result(Value::state(match ($progress['tone'] ?? null) { 'danger' => State::Failed, 'warning' => State::NeedsReview, default => State::Completed }, Reader::Staff, $text)); }
        }
        $actions = array_values(array_filter([...$actions, $link]));
        $actions = array_slice($actions, 0, Row::MAX_ACTIONS);
        if ($actions !== []) { $row->actions(...$actions); }
        if (!empty($fact['help'])) { $row->help((string) $fact['help']); }
        return $row;
    }
    /** A fact's value. $sub is the sentence under it. */
    public static function value(array $fact, ?string $sub): Value
    {
        $v = $fact['value'];
        // A complete read that found nothing saved here is a setting to add, not a failed read.
        if ($v === null && ($fact['read'] ?? null) === 'complete') { return Value::state(State::NotSet, Reader::Staff, $sub); }
        if ($v === null) { return Value::state($sub !== null && Missing::needsNothing($sub) ? State::NotReadYet : State::CouldntRead, Reader::Staff, $sub); }
        if ($v === Missing::NOT_READ) { return Value::state(State::NotReadYet, Reader::Staff, $sub); }
        if (isset($fact['at']) && (is_int($fact['at']) || $fact['at'] instanceof \DateTimeInterface)) { $value = Value::fact(Fact::date($fact['at'], Reader::Staff), $sub); }
        elseif (is_array($v) && array_filter($v, static fn (mixed $i): bool => (bool) preg_match('/[.!?]$/', trim((string) $i))) !== []) {
            // A list of sentences (open alerts) is a job: every sentence stays on the page, under Needs review.
            $value = Value::state(State::NeedsReview, Reader::Staff, RegistrarRows::sentence(implode(' ', array_filter([...array_map(static fn (mixed $i): string => trim((string) $i), $v), $sub]))));
        }
        elseif (is_array($v)) { $value = ($f = Fact::lines(array_map('strval', $v))) === null ? Value::state(State::NotSet, Reader::Staff, $sub) : Value::fact($f, $sub); }
        elseif (($plain = Plain::tryFrom(strtolower((string) preg_replace('/[^A-Za-z]+/', '_', trim((string) $v))))) !== null) { $value = Value::plain($plain, $sub); }
        elseif (($state = RegistrarRows::state((string) $v)) !== null) { $value = Value::state($state, Reader::Staff, $sub); }
        else { $value = ($f = Fact::names([(string) $v])) === null ? Value::state(State::CouldntRead, Reader::Staff, $sub) : Value::fact($f, $sub); }
        return $value;
    }
    /** The next automatic retry, when a fact has one: its own row, so the time reads in the viewer's zone. @return list<Row> */
    public static function retry(array $fact): array
    {
        $at = $fact['retry'] ?? null;
        $at = is_int($at) ? $at : ($at instanceof \DateTimeInterface ? $at->getTimestamp() : (is_string($at) ? (strtotime($at) ?: null) : null));
        return $at === null ? [] : [Row::make('Next automatic retry', Value::fact(Fact::date($at, Reader::Staff)))->key('fact_'.md5('Next automatic retry'))];
    }
    /** The line under a value: when it was read and its words. The next automatic retry is its own row (retry()). */
    public static function words(array $fact): string|Htmlable|null
    {
        $sub = $fact['sub'] ?? null;
        $when = static fn (mixed $at): ?int => is_int($at) ? $at : ($at instanceof \DateTimeInterface ? $at->getTimestamp() : (is_string($at) ? (strtotime($at) ?: null) : null));
        $asOf = $when($fact['asOf'] ?? null);
        if ($asOf === null) { return $sub; }
        // A link in the words stays a link: it is taken out as the row's action, so only the sentences are joined.
        $html = $sub instanceof Htmlable ? $sub->toHtml() : e((string) $sub);
        $parts = array_filter([$asOf === null ? null : 'As of '.e(StaffTime::at($asOf)).'.', trim($html) === '' ? null : $html]);
        return new \Illuminate\Support\HtmlString(implode(' ', $parts));
    }
    /** A registrar record family's read state, for a row with no saved value of its own. */
    public static function family(array $family): Value
    {
        $sub = RegistrarRows::sentence($family['staff'] ?? null);
        return match ($family['state']) {
            'unknown' => Value::state(($family['read_checking'] ?? false) ? State::Checking : (($family['read_failed'] ?? false) ? State::CouldntRead : State::NotReadYet), Reader::Staff),
            'complete' => ($f = Fact::names([(string) $family['value']])) !== null && !Fact::isWord((string) $family['value']) ? Value::fact($f, $sub) : Value::state(State::Ok, Reader::Staff, $sub),
            'partial' => Value::state(State::NeedsReview, Reader::Staff, RegistrarRows::sentence(trim($family['value'].'. '.($sub ?? '')))),
            default => Value::state(State::OutOfDate, Reader::Staff, RegistrarRows::sentence(trim($family['value'].'. '.($sub ?? '')))),
        };
    }
    /** One notice for a section from its items, most urgent first: their sentences in order, with the first action.
     * @param list<array{0:State,1:mixed,2:?Action}> $items */
    public static function notice(string $key, array $items, string $record): ?Row
    {
        $items = array_values(array_filter($items, static fn (array $i): bool => RegistrarRows::sentence($i[1]) !== null));
        if ($items === []) { return null; }
        $action = array_values(array_filter(array_column($items, 2)))[0] ?? null;
        $line = implode(' ', array_map(static fn (array $i): string => (string) RegistrarRows::sentence($i[1]), $items));
        return Row::notice($key, Value::state($items[0][0], Reader::Staff, $line, $record), $action);
    }
}
