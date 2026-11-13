<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
use Carbon\CarbonImmutable;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Value;
use Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels;
/** The hosting domain page: its one notice, the Expires and Nameservers rows and History, from the summary and open notices alone. */
final class WatchPage
{
    /** The one notice at the top: text, tone and an optional link that ends the sentence, or null when nothing needs doing. */
    public static function notice(DomainSummary $s, array $notices, array $portal): ?array
    {
        $kinds = $portal; $keys = $s->stateKeys;
        $expiry = $s->expiry ? DomainPage::date($s->expiry) : null;
        $days = $s->daysLeft;
        return match (true) {
            // Stopped and Paused are the watch's own state: the head says them once, in gray (DESIGN.md P-STA-3, P-NTC-5).
            (bool) array_intersect(['watch.retired', 'watch.paused'], $keys) => null,
            in_array('watch.not_registered', $keys, true) => ['tone' => 'warn', 'text' => 'The registry has no record of '.$s->name.'. Check with your registrar.'],
            (bool) array_intersect(['watch.expired', 'watch.redemption'], $keys) || (bool) array_intersect(['expired', 'registry_expiry_reached'], $kinds)
                => ['tone' => 'bad', 'text' => $s->name.' expired'.($expiry ? ' on '.$expiry : '').'. Renew it at your registrar.'],
            in_array('watch.ns_changed', $keys, true) || in_array('ns_changed', $kinds, true) => ['tone' => 'warn', 'text' => $s->name.($s->dnsPreviouslyMatched?' stopped using ':' does not use ').DnsSignalLabels::nameservers().($s->dnsPreviouslyMatched?self::movedOn($s, $notices).'. Change them back at your registrar, or':'. To use them, update nameservers at your registrar, or'),
                'link' => ['label' => 'contact support', 'url' => $s->urls['support'] ?? null]],
            $expiry && (in_array('expiring', $kinds, true) || ($days !== null && $days > 0 && $days <= 30)) => ['tone' => 'warn', 'text' => 'Renew '.$s->name.' at your registrar before '.$expiry.'.'],
            (bool) array_intersect(['expiry_unavailable', 'dns_unavailable'], $kinds) => ['tone' => 'info', 'text' => 'We can’t read the public '.implode(' or ', array_values(array_filter([
                in_array('expiry_unavailable', $kinds, true) ? 'registry' : null, in_array('dns_unavailable', $kinds, true) ? 'DNS' : null]))).' for '.$s->name.' right now. We’ll keep checking.'],
            default => null,
        };
    }
    /** The head state: Stopped or Paused while checks are off (the watch's own state, gray, with what happens next), else the domain's state. */
    public static function state(DomainSummary $s): ?Value
    {
        $keys = $s->stateKeys;
        if (in_array('watch.retired', $keys, true)) { return Value::state(State::Stopped, Reader::Customer, self::STOPPED); }
        if (in_array('watch.paused', $keys, true)) { return Value::state(State::Paused, Reader::Customer, self::PAUSED); }
        return CustomerValues::status($s->statusState, $s->statusReason);
    }
    public const STOPPED = 'We no longer check this domain.';
    public const PAUSED = 'We check it again when monitoring resumes.';
    /** Expires: the date with its tone and one sub line, or Missing with its cause (when it shows, if the notice already says why). */
    public static function expires(DomainSummary $s, array $portal): array
    {
        if ($s->expiry === null) { return ['missing' => true, 'cause' => in_array('expiry_unavailable', $portal, true) ? Missing::fills('after the next check') : 'Check at your registrar']; }
        $row = DomainListRow::expires($s); $check = $s->freshness['registry'] ?? null;
        $sub = $row['note'] ?? (($check['checkedAt'] ?? null) ? (($check['stale'] ?? false) ? 'Last checked ' : 'Checked ').self::day((int) $check['checkedAt']) : null);
        return ['missing' => false, 'tone' => $row['tone'], 'sub' => $sub];
    }
    /** Nameservers: the servers (or which provider's) with "Checked {date}" or "Checking again", or Missing. */
    public static function nameservers(DomainSummary $s): array
    {
        $check = $s->freshness['delegation'] ?? null; $servers = $s->nameservers['nameservers'] ?? [];
        $label = $s->labels[array_values(array_intersect($s->dnsKeys, ['domain.dns.platform', 'domain.dns.external']))[0] ?? ''] ?? null;
        $values = $servers ?: (!($check['stale'] ?? true) && $label ? [$label] : []);
        if (!$values) {
            return ['missing' => true, 'cause' => Missing::fills(($check['checkedAt'] ?? null) ? 'after the next check' : 'after the first check')];
        }
        $at = isset($s->nameservers['at']) ? CarbonImmutable::parse($s->nameservers['at'])->utc()->getTimestamp() : ($check['checkedAt'] ?? null);
        return ['missing' => false, 'values' => $values, 'mono' => (bool) $servers,
            'sub' => in_array('watch.ns_pending', $s->stateKeys, true) ? 'Checking again' : ($at ? 'Checked '.self::day((int) $at) : null)];
    }
    /** History: nameserver changes from the open notices, newest last. */
    public static function history(array $notices): array
    {
        $labels = ['ns_changed' => 'Nameservers changed', 'ns_restored' => (DnsSignalLabels::company() !== '' ? 'Nameservers back to '.DnsSignalLabels::company() : 'Our nameservers restored')];
        return array_values(array_map(fn ($n) => ['label' => ($n['kind']==='ns_changed' && ($n['previously_matched']??false)!==true?'Not using '.DnsSignalLabels::nameservers():$labels[$n['kind']]), 'date' => self::parse($n['date'] ?? null)?->format('Y-m-d')],
            array_filter($notices, fn ($n) => isset($labels[$n['kind'] ?? '']))));
    }
    private static function movedOn(DomainSummary $s, array $notices): string
    {
        if (isset($s->noticeDates['dns'])) { return ' on '.self::day((int) $s->noticeDates['dns']); }
        $date = self::parse(array_values(array_filter($notices, fn ($n) => ($n['kind'] ?? null) === 'ns_changed'))[0]['date'] ?? null);
        return $date ? ' on '.$date->format('M j, Y') : '';
    }
    /** Notice dates arrive formatted ("January 15, 2027"). */
    private static function parse(?string $date): ?CarbonImmutable
    {
        try { return $date ? CarbonImmutable::parse($date, 'UTC') : null; } catch (\Throwable) { return null; }
    }
    private static function day(int $at): string { return CarbonImmutable::createFromTimestampUTC($at)->format('M j, Y'); }
}
