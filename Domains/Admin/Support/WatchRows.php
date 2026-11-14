<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Illuminate\Contracts\Support\Htmlable;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Row, Trace, Value};
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact, Plain, Reader, State};
/** A monitored domain's facts and history (WatchFacts) as panel rows and a trace, for the Detail page type.
 * A value that isn't saved yet takes Not read yet with its cause; a fact WatchFacts marks as a problem keeps its value
 * and gets the problem as its result line (Failed for a failed check, Needs review otherwise). */
final class WatchRows
{
    /** The watch status word, as the head shows it. */
    public static function state(string $label): State
    {
        return match ($label) { 'Paused' => State::Paused, 'Stopped' => State::Stopped, 'Problem found' => State::NeedsReview, 'Check failed' => State::Failed, default => State::Monitored };
    }
    /** @param array{value:mixed,sub:mixed,url:?string,tone:?string,cause:?string,check:bool,at?:?int} $fact
     *  @param ?\Closure():Action $check the Check now action, for a check time that isn't saved yet */
    public static function row(string $label, array $fact, ?\Closure $check): Row
    {
        [$shown, $problem, $word, $sub] = self::shown($fact);$staff = Reader::Staff;$value = $fact['value'];
        $row = Row::make($label, $shown);
        if ($problem) { $row->result(Value::state($word ?? (($fact['tone'] ?? null) === 'danger' ? State::Failed : State::NeedsReview), $staff, $sub)); }
        $actions = [];
        if (($fact['url'] ?? null) !== null) { $actions[] = Sheet::link('open_'.substr(md5($label), 0, 8), 'Open', $fact['url'])->extraAttributes(['aria-label' => 'Open '.$label]); }
        // Check now sits beside a value that isn't saved yet, as a quiet row button.
        if ($value === null && ($fact['check'] ?? false) && $check !== null) { $actions[] = Sheet::button($check()); }
        return $actions === [] ? $row : $row->actions(...$actions);
    }
    /** The fact's value alone, as a list cell shows it: a value not saved yet reads Not read yet with its cause. */
    public static function value(array $fact): Value { return self::shown($fact)[0]; }
    /** @return array{0:Value,1:bool,2:?State,3:?string} the value, whether the fact is a problem, its one-word state and its line */
    private static function shown(array $fact): array
    {
        $staff = Reader::Staff;$sub = self::sentence($fact['sub'] ?? null);$value = $fact['value'];
        $problem = in_array($fact['tone'] ?? null, ['warning', 'danger'], true) && $value !== null && $sub !== null;
        // A one-word line ("Expired") is a state word, never a sentence: it becomes the result's state.
        $word = $sub !== null && Fact::isWord($sub) ? (State::tryFrom(strtolower(str_replace(' ', '_', $sub))) ?? State::NeedsReview) : null;
        if ($word !== null) { $sub = null; }
        $shown = match (true) {
            $value === null => self::notYet($fact['cause'] ?? null),
            ($fact['at'] ?? null) !== null && is_string($value) => Value::fact(Fact::date((int) $fact['at'], $staff), $problem ? null : $sub),
            is_array($value) => self::fact($value, $problem ? null : $sub),
            $value === 'On' => Value::plain(Plain::On, $sub),
            $value === 'Off' => Value::plain(Plain::Off, $sub),
            $value === 'Unknown' => self::timed(State::NotReadYet, $fact['sub'] ?? null, $sub),
            $value === 'Checking now' => self::timed(State::Checking, $fact['sub'] ?? null, $sub),
            default => self::fact([(string) $value], $problem ? null : $sub),
        };
        return [$shown, $problem, $word, $sub];
    }
    /** A state whose line ends with a check time ("Checked <time>") keeps that time as its staff time, in the viewer's zone,
     * and the rest of the line as its sentence. */
    private static function timed(State $state, mixed $raw, ?string $sub): Value
    {
        if (!$raw instanceof Htmlable || !preg_match('~<time\b[^>]*\bdatetime="([^"]+)"~', $html = $raw->toHtml(), $m) || ($at = strtotime(html_entity_decode($m[1]))) === false) {
            return Value::state($state, Reader::Staff, $sub);
        }
        $rest = self::sentence(new \Illuminate\Support\HtmlString((string) preg_replace('~(Checked\s+)?(<span\b[^>]*>\s*)?<time\b.*?</time>(\s*</span>)?~s', '', $html)));
        return Value::state($state, Reader::Staff, $rest !== null && Fact::isWord($rest) ? null : $rest)->when($at);
    }
    /** The status reason as the sheet's first row. A one-word reason ("Expired") is the notice's own state word. */
    public static function notice(string $label, mixed $reason): Row
    {
        $reason = self::sentence($reason);
        if ($reason !== null && Fact::isWord($reason)) { return Row::notice('status', Value::state(State::tryFrom(strtolower(str_replace(' ', '_', $reason))) ?? self::state($label), Reader::Staff)); }
        return Row::notice('status', Value::state(self::state($label), Reader::Staff, $reason));
    }
    /** History rows, newest first, as rail steps. A monitoring change takes the state it left and a finished read
     * reads Completed; a note, a request or a template change has no state of its own, so it draws no mark. */
    public static function trace(array $rows): Trace
    {
        $trace = Trace::make(Reader::Staff);
        foreach ($rows as $r) {
            $state = match ($r['event']) { 'Monitoring paused' => State::Paused, 'Monitoring stopped' => State::Stopped, WatchFacts::RESUME_SAVED => State::Queued, 'Monitoring resumed' => State::Monitored, 'Registrar read complete' => State::Completed, default => null };
            $reason = self::sentence($r['note'] ?? null);
            $at = is_string($r['time'] ?? null) && ($t = strtotime($r['time'])) !== false ? $t : null;
            $trace->step((string) $r['event'], $state, $at, $r['by'] ?? null, $reason !== null && !Fact::isWord($reason) ? $reason : null);
        }
        return $trace;
    }
    private static function notYet(?string $cause): Value
    {
        $cause = self::sentence($cause);
        if ($cause !== null && Fact::isWord($cause)) { return Value::state(State::from(strtolower(str_replace(' ', '_', $cause))), Reader::Staff); }
        return Value::state(State::NotReadYet, Reader::Staff, $cause);
    }
    /** @param list<string> $names */
    private static function fact(array $names, ?string $sub): Value
    {
        $fact = Fact::lines(array_map('strval', $names));
        return $fact === null ? Value::state(State::NotReadYet, Reader::Staff, $sub) : Value::fact($fact, $sub);
    }
    /** Plain text for the line under a value: times keep their staff zone wording. */
    private static function sentence(mixed $text): ?string
    {
        if ($text instanceof Htmlable) { $text = html_entity_decode(strip_tags($text->toHtml()), ENT_QUOTES); }
        $text = is_string($text) ? trim((string) preg_replace('/\s+/u', ' ', $text)) : '';
        return $text === '' ? null : $text;
    }
}
