<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Dns;
use Paymenter\Extensions\Servers\Domains\Contracts\DnsReader;
/** Valid DNS error responses are evidence, never a reason to bypass the validating resolver. */
final readonly class DohDnsReader implements DnsReader
{
    private const TYPES = [1 => 'A', 2 => 'NS', 5 => 'CNAME', 15 => 'MX', 28 => 'AAAA'];
    public function __construct(private DohEndpoint $endpoint, private DohTransport $transport) {}
    public function read(DnsQuery $query, DnsContext $context): DnsAnswer
    {
        if (!$context->reserve()) { return DnsAnswer::unknown($query, $context->live() ? 'budget_exhausted' : 'deadline')->withSource('doh'); }
        $remaining = $context->remaining();
        if ($remaining < 0.1) { return DnsAnswer::unknown($query, 'deadline')->withSource('doh'); }
        try {
            $r = $this->transport->fetch($this->endpoint, $query, $remaining);
            if (!$context->live()) { return DnsAnswer::unknown($query, 'deadline')->withSource('doh'); }
            if (($r['status'] ?? null) !== 200 || !is_string($r['body'] ?? null) || strlen($r['body']) > 65536) { throw new \UnexpectedValueException; }
            $answer = $this->parse($query, $r['body']);
        } catch (DohFailure $e) {
            $answer = DnsAnswer::unknown($query, !$context->live() || ($e->kind === 'timeout' && $remaining < 2.0) ? 'deadline' : 'transport_'.$e->kind);
        } catch (\Throwable) { $answer = DnsAnswer::unknown($query, $context->live() ? 'transport_malformed' : 'deadline'); }
        return $answer->withSource('doh');
    }
    private function parse(DnsQuery $q, string $body): DnsAnswer
    {
        $json = json_decode($body, false, 16, JSON_THROW_ON_ERROR);
        if (!$json instanceof \stdClass || !isset($json->Question) || !is_array($json->Question) ||
            (property_exists($json, 'Answer') && !is_array($json->Answer))) { throw new \UnexpectedValueException; }
        foreach (array_merge($json->Question, $json->Answer ?? []) as $row) {
            if (!$row instanceof \stdClass) { throw new \UnexpectedValueException; }
        }
        $r = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($r) || !is_int($r['Status'] ?? null) || $r['Status'] < 0 || $r['Status'] > 4095 ||
            (array_key_exists('TC', $r) && $r['TC'] !== false)) { throw new \UnexpectedValueException; }
        if (!array_key_exists('Question', $r)) { throw new \UnexpectedValueException; }
        {
            $questions = $r['Question'];
            if (!is_array($questions) || !array_is_list($questions) || count($questions) !== 1 || !is_array($questions[0]) ||
                !is_string($questions[0]['name'] ?? null) || DnsQuery::hostname($questions[0]['name']) !== $q->name ||
                ($questions[0]['type'] ?? null) !== array_search($q->type, self::TYPES, true)) { throw new \UnexpectedValueException; }
        }
        $rows = array_key_exists('Answer', $r) ? $r['Answer'] : [];
        if (!is_array($rows) || !array_is_list($rows)) { throw new \UnexpectedValueException; }
        if (count($rows) > 100) { return DnsAnswer::unknown($q, 'invalid_answer'); }
        $answers = []; $aliases = []; $owners = []; $invalid = false;
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['name'] ?? null) || !is_int($row['type'] ?? null) ||
                ($row['type'] < 1 || $row['type'] > 65535) || !is_string($row['data'] ?? null) || !is_int($row['TTL'] ?? null)) { throw new \UnexpectedValueException; }
            try { $name = DnsQuery::hostname($row['name']); } catch (\InvalidArgumentException) { $invalid = true; continue; }
            $type = self::TYPES[$row['type']] ?? null;
            if ($type === null) { $invalid = true; continue; }
            if ($type !== $q->type && $type !== 'CNAME') { $invalid = true; continue; }
            $native = ['host' => $name, 'class' => 'IN', 'type' => $type, 'ttl' => $row['TTL']];
            if ($type === 'MX') {
                if (!preg_match('/\A([0-9]{1,5}) ([^\s]+)\z/D', $row['data'], $m)) { throw new \UnexpectedValueException; }
                $native += ['pri' => (int) $m[1], 'target' => $m[2]];
            } else { $native[match ($type) { 'A' => 'ip', 'AAAA' => 'ipv6', default => 'target' }] = $row['data']; }
            $a = DnsAnswer::rrset(new DnsQuery($name, $type), [$native]);
            if (!$a->known()) { $invalid = true; continue; }
            $owners[$name] = true;
            if ($type === 'CNAME') {
                if (isset($aliases[$name])) { $invalid = true; continue; }
                $aliases[$name] = $a->values()[0];
            }
            if ($name === $q->name && $type === $q->type) { $answers[] = $native; }
        }
        $seen = []; $name = $q->name;
        while (isset($aliases[$name])) {
            if (isset($seen[$name])) { return DnsAnswer::unknown($q, 'invalid_answer'); }
            $seen[$name] = true; $name = $aliases[$name];
        }
        $seen[$name] = true;
        if ($invalid || array_diff_key($owners, $seen)) { return DnsAnswer::unknown($q, 'invalid_answer'); }
        if (!in_array($r['Status'], [0, 3], true)) { return DnsAnswer::unknown($q, 'dns_rcode_'.$r['Status']); }
        if ($q->type !== 'CNAME' && isset($aliases[$q->name])) {
            // Never attach a target's addresses to the alias owner. Collector walks CNAME separately.
            if ($answers) { return DnsAnswer::unknown($q, 'invalid_answer'); }
            return DnsAnswer::unknown($q, 'cname_requires_walk');
        }
        if ($r['Status'] === 3) { return $answers ? DnsAnswer::unknown($q, 'invalid_answer') : DnsAnswer::negative($q, reason: 'nxdomain'); }
        $a = $answers ? DnsAnswer::rrset($q, $answers) : DnsAnswer::negative($q, reason: 'nodata');
        return $a;
    }
}
