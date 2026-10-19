<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Dns;
/** Normalized independent axes, never registration, expiry or ownership authority. */
final readonly class DnsReading
{
    public array $queries;
    private array $answers;
    public function __construct(public string $kind, public ?string $dns = null, public ?bool $website = null,
        public ?bool $email = null, public bool $mixed = false, public bool $partial = false, array $queries = [], public bool $budgetLimited = false)
    {
        if (!in_array($kind, ['delegation', 'points'], true) || !in_array($dns, [null, 'platform', 'external'], true) ||
            ($kind === 'delegation' && ($website !== null || $email !== null || $mixed)) ||
            ($kind === 'points' && $dns !== null) || ($mixed && $website !== true && $email !== true) || count($queries) > 10) {
            throw new \InvalidArgumentException('Invalid DNS axes.');
        }
        $out = [];
        foreach ($queries as $answer) {
            if (!$answer instanceof DnsAnswer) { throw new \InvalidArgumentException('Normalized DNS evidence is required.'); }
            $out[] = $answer->facts();
        }
        $this->queries = $out; $this->answers = array_values($queries);
    }
    public function result(): string
    {
        $axes = $this->kind === 'delegation' ? [$this->dns] : [$this->website, $this->email];
        if (count(array_filter($axes, static fn($v) => $v !== null)) === 0) { return 'unknown'; }
        return $this->partial || $this->budgetLimited || in_array(null, $axes, true) ? 'partial' : 'complete';
    }
    public function withOmittedHosts(): self
    {
        if ($this->kind !== 'points') { return $this; }
        return new self('points', website: $this->website === true ? true : null, email: $this->email,
            mixed: $this->mixed, partial: true, queries: $this->answers, budgetLimited: true);
    }
    public function safeCode(): ?string
    {
        if ($this->result() === 'complete') { return null; }
        foreach (['deadline','resolver_failed','invalid_answer','ambiguous_empty','read_unavailable'] as $code) {
            foreach ($this->queries as $q) { if ($q['state'] === 'unknown' && $q['code'] === $code) { return $code; } }
        }
        $budget = $this->budgetLimited || count($this->queries) === 10 || in_array('budget_exhausted', array_column($this->queries, 'code'), true);
        $known = $this->dns !== null || $this->website !== null || $this->email !== null ||
            count(array_filter($this->queries, static fn($q) => $q['state'] !== 'unknown')) > 0;
        return $budget ? ($known ? 'rotation' : 'budget_exhausted') : 'read_unavailable';
    }
    /** Stable routing fingerprint, distinct from diagnostic TTLs and resolver row order. */
    public function digest(): string
    {
        $queries = [];
        foreach ($this->queries as $q) {
            $records = array_map(static fn($r) => [$r['value'], $r['priority'] ?? null], $q['records']);
            usort($records, static fn($a,$b) => $a <=> $b);
            $queries[$q['name'].'|'.$q['type']] = [$q['state'], $records];
        }
        ksort($queries, SORT_STRING);
        return hash('sha256', json_encode([$this->kind,$this->dns,$this->website,$this->email,$this->mixed,$this->result(),$queries], JSON_THROW_ON_ERROR));
    }
    public function facts(): array
    {
        return ['version' => 1, 'dns' => $this->dns, 'website' => $this->website, 'email' => $this->email,
            'mixed' => $this->mixed, 'budget_limited' => $this->budgetLimited, 'partial' => $this->result() !== 'complete', 'queries' => $this->queries];
    }
}
