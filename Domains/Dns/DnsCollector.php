<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Dns;
use Paymenter\Extensions\Servers\Domains\Contracts\DnsReader;
/** All hostnames share one deadline and ten-query ceiling. No retries or I/O in constructors. */
final class DnsCollector
{
    public function __construct(private DnsReader $reader) {}
    public function collect(WatchDnsTicket|ManagedDnsTicket $ticket, DnsContext $context): array
    {
        $answers = []; $profile = $ticket->profile; $hosts = $ticket->hostnames;
        if (!$hosts || count($hosts) > 101) { throw new \InvalidArgumentException('Bounded watch hostnames are required.'); }
        $read = function (string $host, string $type) use (&$answers, $context): DnsAnswer {
            $q = new DnsQuery($host, $type);
            if (isset($answers[$q->key()])) { return $answers[$q->key()]; }
            if (count($answers) >= DnsContext::MAX_QUERIES || $context->used() >= DnsContext::MAX_QUERIES || !$context->live()) { return DnsAnswer::unknown($q); }
            try { $answer = $this->reader->read($q, $context); } catch (\Throwable) { $answer = DnsAnswer::unknown($q); }
            return $answers[$q->key()] = $context->live() && $answer->query->key() === $q->key() ? $answer : DnsAnswer::unknown($q);
        };
        if ($ticket->kind === 'delegation') {
            $read($hosts[0], 'NS');
            return [DnsSignals::delegation($hosts[0], $profile, $answers), $ticket->cursor, $ticket->sourceCursor];
        }
        // Email gets an independent opportunity before any long website chain can exhaust the job.
        $mx = $read($hosts[0], 'MX');
        if ($profile->email['ips']) {
            $targets = $mx->values(); sort($targets, SORT_STRING); $mxOffset = $targets ? $ticket->cursor % count($targets) : 0;
            $targets = array_merge(array_slice($targets,$mxOffset),array_slice($targets,0,$mxOffset));
            foreach ($targets as $host) {
                if ($host === '.' || RoutingProfile::hostMatches($host, $profile->email)) { continue; }
                // Leave six queries for a full three-link chain and both address families even with a large MX set.
                if ($context->used() + 2 > DnsContext::MAX_QUERIES - 6) { break; }
                $read($host, 'A'); $read($host, 'AAAA');
            }
        }
        $offset = $ticket->cursor % count($hosts);
        $ordered = array_merge(array_slice($hosts, $offset), array_slice($hosts, 0, $offset));
        if ($ticket instanceof ManagedDnsTicket) {
            // The independent apex always gets its turn; only hosting primaries rotate behind it.
            $primaries=array_slice($hosts,1);$offset=$primaries?$ticket->cursor%count($primaries):0;
            $ordered=[$hosts[0],...array_slice($primaries,$offset),...array_slice($primaries,0,$offset)];
        }
        $finished = [];
        foreach ($ordered as $primary) {
            $host = $primary; $seen = []; $hops = 0; $covered = false;
            while ($context->used() < DnsContext::MAX_QUERIES && $context->live()) {
                if (isset($seen[$host])) { $covered = true; break; }
                $seen[$host] = true; $cname = $read($host, 'CNAME');
                if ($cname->state !== 'rrset') {
                    $read($host, 'A'); $read($host, 'AAAA');
                    $covered = isset($answers[$host.'|A'], $answers[$host.'|AAAA']); break;
                }
                if (++$hops > DnsSignals::CNAME_HOPS) { $covered = true; break; }
                $host = $cname->values()[0];
                if (RoutingProfile::hostMatches($host, $profile->website)) { $covered = true; break; }
            }
            $finished[$primary] = $covered;
        }
        $reading = DnsSignals::points($hosts, $profile, $answers);
        if ($ticket->sourceOverflow) { $reading = $reading->withOmittedHosts(); }
        $primary = $hosts[1] ?? $hosts[0];
        // Managed cohorts yield even when long chains cannot fit after the independent apex.
        $advance=$ticket instanceof ManagedDnsTicket || ($finished[$primary]??false);
        $sourceCursor = $ticket->sourceOverflow && $advance ? $ticket->nextSourceCursor : $ticket->sourceCursor;
        return [$reading, ($ticket->cursor + 1) % 2147483647, $sourceCursor];
    }
}
