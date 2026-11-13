<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Illuminate\Contracts\Support\Htmlable;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Row, SettingsRow, Trace, Value};
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact, Missing, Plain, Reader, State};
/** A registrar account's checklist rows (AccountChecklist, RegistrarFacts) as panel rows and System checks steps, for
 * the Detail page type. Words come from the closed list: an empty setting reads Not set, a value we could not read
 * Couldn't read, a feature this site does not offer yet Paused, and a failed check Failed. */
final class RegistrarRows
{
    public const SET_BY = 'Set by staff on this registrar account.';
    /** Settings staff choose here: empty means Not set, never Couldn't read. */
    private const SETTINGS = ['Registrar', 'API user', 'What you sell', 'New domains use', 'Customer pays', 'Card fee'];
    private const NOT_YET = 'Not available yet';

    /** A checklist row's value. @param array{label:string,value:?string,tone:?string,cause:mixed,help?:?string,mono?:bool,money?:bool} $r */
    public static function value(array $r): Value
    {
        [$sub] = self::line($r['cause'] ?? null);
        $value = $r['value'];
        if ($value === null) {
            if (($r['state'] ?? null) instanceof State) { return Value::state($r['state'], Reader::Staff, $sub); }
            $word = match (true) { $sub !== null && (Missing::needsNothing($sub) || str_starts_with($sub, 'Not checked yet')) => State::NotReadYet, in_array($r['label'], self::SETTINGS, true) => State::NotSet, default => State::CouldntRead };
            return Value::state($word, Reader::Staff, $sub);
        }
        if (($r['state'] ?? null) instanceof State) { return Value::state($r['state'], Reader::Staff, self::sentence(implode(' ', array_filter([$value.'.', $sub])))); }
        if ($value === self::NOT_YET) { return Value::state(State::Paused, Reader::Staff, $sub ?? self::sentence($r['help'] ?? null)); }
        if (($plain = Plain::tryFrom(strtolower((string) preg_replace('/[^A-Za-z]+/', '_', trim($value))))) !== null) { return Value::plain($plain, $sub); }
        if (str_starts_with($value, 'On, ')) { return Value::plain(Plain::On, self::sentence(implode(' ', array_filter([ucfirst(substr($value, 4)).'.', $sub])))); }
        if (($state = self::state($value)) !== null) { return Value::state($state, Reader::Staff, $sub); }
        if (in_array($r['tone'] ?? null, ['danger', 'warning'], true)) {
            return Value::state($r['tone'] === 'danger' ? State::Failed : State::NeedsReview, Reader::Staff, self::sentence(implode(' ', array_filter([$value.'.', $sub]))));
        }
        $fact = ($r['mono'] ?? false) ? (Fact::code($value) ?? Fact::names([$value])) : Fact::names([$value]);
        return $fact === null ? Value::state(State::CouldntRead, Reader::Staff, $sub) : Value::fact($fact, $sub);
    }
    /** A word from the closed list, when the value already is one ("OK", "Connection failed", "Queued"). */
    public static function state(?string $value): ?State
    { return $value === null ? null : State::tryFrom(strtolower((string) preg_replace('/[^A-Za-z]+/', '_', trim(str_replace("'", '', $value))))); }
    /** A line that may hold one link: its text as a sentence, and the link as a native action. @return array{0:?string,1:?Action} */
    public static function line(mixed $cause, string $name = 'line'): array
    {
        $html = $cause instanceof Htmlable ? $cause->toHtml() : null;
        $link = null;
        if ($html !== null && preg_match('~<a\b[^>]*href="([^"]+)"[^>]*>(.*?)</a>~si', $html, $m)) {
            $label = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES));
            $link = Sheet::link($name.'_'.substr(md5($label), 0, 6), $label, html_entity_decode($m[1], ENT_QUOTES));
            $html = str_replace($m[0], '', $html);
            // The link was the line's last step; what is left still reads as sentences, with one full stop.
            $html = (string) preg_replace(['/([.!?])\s*\.$/', '/\s+\.$/', '/\s*,\s*$/'], ['$1', '.', ''], trim($html));
        }
        return [self::sentence($html !== null ? new \Illuminate\Support\HtmlString($html) : $cause), $link];
    }
    /** One sheet row. A switch is a setting row with the page's own switch action; $actions are the row's other controls. */
    public static function row(array $r, array $actions = [], ?Action $switch = null): Row
    {
        if ($switch !== null) {
            [$sub] = self::line($r['cause'] ?? null);
            $row = SettingsRow::toggle($r['label'], $r['value'] === 'On', $switch, self::SET_BY, $sub);
        } else {
            $row = Row::make($r['label'], self::value($r));
            $actions = array_slice(array_values(array_filter($actions)), 0, Row::MAX_ACTIONS);
            if ($actions !== []) { $row->actions(...$actions); }
        }
        if (!empty($r['help'])) { $row->help((string) $r['help']); }
        return $row;
    }
    /** System checks as rail steps, failing first. A step's word comes from its check; a count says how many domains. */
    public static function checks(array $checks): Trace
    {
        $trace = Trace::make(Reader::Staff);
        foreach ($checks as $i => $c) {
            [$sub, $link] = self::line($c['cause'] ?? null, 'check_'.$i);
            $value = $c['value'];$tone = $c['tone'] ?? null;
            $state = $value === null ? State::Failed : (self::state($value) ?? match ($tone) { 'danger' => State::Failed, 'warning' => State::NeedsReview, default => State::Ok });
            $lead = match (true) {
                $value === null || self::state($value) !== null => null,
                ctype_digit($value) => $value.' '.((int) $value === 1 ? 'domain' : 'domains').'.',
                default => $value.'.',
            };
            $reason = self::sentence(implode(' ', array_filter([$lead, $sub])));
            $action = $c['buttons'][0] ?? $link;
            $trace->step($c['label'], $state, null, null, $reason !== null && !Fact::isWord($reason) ? $reason : null, null, $action);
        }
        return $trace;
    }
    public static function sentence(mixed $text): ?string { return RequestRows::sentence($text); }
}
