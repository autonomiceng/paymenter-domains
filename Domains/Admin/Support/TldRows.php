<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Row, Trace, Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Section;
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact, Missing, Plain, Reader, State, StaffTime};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Models\Tld;
/** What the TLD page's sections say about prices: one section per registrar account that offers the TLD, and the
 * Activity trace. Facts come from TldFacts; this only places them. */
final class TldRows
{
    private const COST_HELP = 'Each price shows its registrar cost under it. Registrations and transfers below cost are held. Renewals are billed.';
    /** Every account's Registration row carries the same cost help, so each section reads alone. @param list<array> $accounts TldFacts::accounts() @return list<Section> */
    public static function accounts(array $accounts, bool $costs): array
    {
        $staff = Reader::Staff;
        return array_map(function (array $a) use ($staff, $costs): Section {
            $fix = $a['fix'] === null ? null : Sheet::link('fix_'.md5($a['name']), $a['fix']['label'], $a['fix']['url']);
            $pricing = Row::make('Pricing', Value::fact(Fact::entered($a['pricing']) ?? Fact::count(0), $a['note'] === '' ? null : $a['note']));
            $registration = Row::make('Registration', self::price($a['register'], $staff));
            $rows = [$fix === null ? $pricing : $pricing->actions($fix), $costs ? $registration->help(self::COST_HELP) : $registration, Row::make('Renewal', self::price($a['renew'], $staff)),
                Row::make('Transfer', $a['transfer'] === null ? Value::plain(Plain::Off) : self::price($a['transfer'], $staff)), Row::make('Domains', Value::fact(Fact::count($a['holders'])))];
            $section = Section::make($a['name'].($a['demo'] ? ' (demo data)' : ''), $rows, $a['seller'] ? 'New orders go to this account.' : null, 'account-'.md5($a['name']));
            return $a['url'] === null ? $section : $section->link(Sheet::link('open_'.md5($a['name']), 'Open account', $a['url']));
        }, $accounts);
    }
    /** A money text with its cost under it, or the state that says why there is no price. A price under its cost reads
     * Below cost, amber, with the price and cost in its sentence. @param array{value:string,cost:?string,below?:bool} $p */
    private static function price(array $p, Reader $staff): Value
    {
        $value = $p['value'];
        if ($value === Missing::LABEL) { return Value::state(State::NotSet, $staff); }
        if ($value === 'Not for sale') { return Value::state(State::NotForSale, $staff); }
        $money = Fact::money(str_replace(',', '', ltrim($value, '$')));
        if ($money === null || !str_starts_with($value, '$')) { return Value::state(State::NotSupported, $staff, rtrim($value, '.').'.'); }
        $costMoney = $p['cost'] !== null && str_starts_with($p['cost'], '$') ? Fact::money(str_replace(',', '', ltrim($p['cost'], '$'))) : null;
        $cost = $p['cost'] === null ? null : ($costMoney !== null ? 'Cost '.$costMoney->text.'.' : 'Cost missing.');
        return ($p['below'] ?? false) ? Value::state(State::BelowCost, $staff, trim($money->text.'. '.($cost ?? ''))) : Value::fact($money, $cost);
    }
    /** The rail: the newest changes, with the History tab holding the rest. */
    public static function activity(Tld $t): Section
    {
        $rows = TldFacts::history((int) $t->server_id, (int) $t->id);
        if ($rows === null) { return Section::make('Activity', [Row::make('Changes', Value::state(State::CouldntRead, Reader::Staff, DomainFacts::UNREADABLE))]); }
        if ($rows === []) { return Section::make('Activity', [Row::make('Changes', Value::plain(Plain::None))]); }
        $trace = Trace::make(Reader::Staff);
        foreach (array_slice($rows, 0, Sheet::RAIL_STEPS) as $r) {
            $at = is_string($r['time'] ?? null) && ($time = strtotime($r['time'])) !== false ? $time : null;
            $reason = trim((string) ($r['reason'] ?? ''));
            $trace->step((string) $r['change'], null, $at, (string) $r['by'], $reason === '' || Fact::isWord($reason) ? null : $reason);
        }
        return Section::make('Activity', [$trace])->link(Sheet::link('view_history', 'View all '.count($rows), TldResource::getUrl('history', ['record' => $t])));
    }
}
