<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace OteContract;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AttemptEvidence, DispatchSeal, ExecutionRecord, Money, Query, RequestBudget, RequestContext, RequestOrigin, SendState};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client, Driver};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{Outcome, RegistrarRequest};

/** Private sandbox attempt identity. Never serialize into public projections or native completion. */
final class PurchaseAttempt
{
    private function __construct(private readonly string $path, private readonly \SensitiveParameterValue $original) {}
    public static function persist(string $directory, string $name, Money $amount, int $term, string $route, string $utc): self
    {
        if (!preg_match('/^contract-ote-[0-9a-f]{12}\.com$/D', $name) || $amount->currency !== 'USD'
            || !preg_match('/^[0-9]+(?:\.[0-9]{1,6})?$/D', $amount->amount) || $term !== 1
            || !in_array($route, ['/api', '/apibatch'], true)
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D', $utc)
            || (new \DateTimeImmutable($utc))->format(DATE_ATOM) !== $utc) {
            throw new \RuntimeException('Private attempt scope refused.');
        }
        $data = ['name' => $name, 'authorized_amount' => $amount->amount, 'term' => $term, 'route' => $route, 'dispatch_utc' => $utc];
        $json = json_encode($data, JSON_THROW_ON_ERROR)."\n";
        $path = $directory.'/attempt-private.json';
        // A renewal may supersede the live file; retain the first purchase as private evidence too.
        if (file_exists($path)) {
            if (is_link($path) || !rename($path, $directory.'/registration-attempt-private.json')) {
                throw new \RuntimeException('Private attempt archive failed.');
            }
        }
        $old = umask(0077);
        try { $file = fopen($path, 'x'); }
        finally { umask($old); }
        if ($file === false) { throw new \RuntimeException('Private attempt creation failed.'); }
        try {
            if (!chmod($path, 0600) || fwrite($file, $json) !== strlen($json) || !fflush($file) || !fsync($file)) {
                throw new \RuntimeException('Private attempt persistence failed.');
            }
        } finally { fclose($file); }
        return new self($path, new \SensitiveParameterValue($data));
    }
    public function reconcile(GuardedTransport $wire, Client $client, AccountBinding $binding, Clock $clock): string
    {
        $wire->receipt['reconciliation'] = ['category' => 'ambiguous', 'adopted' => false];
        $wire->check('purchase_unresolved', true);
        try {
            if (is_link($this->path) || (fileperms($this->path) & 0777) !== 0600) { throw new \RuntimeException('Private evidence permissions changed.'); }
            $data = json_decode(file_get_contents($this->path), true, 8, JSON_THROW_ON_ERROR);
            if ($data !== $this->original->getValue() || $data['name'] !== $wire->domain) { throw new \RuntimeException('Private evidence changed.'); }
            $start = new \DateTimeImmutable($data['dispatch_utc']);
            $attempt = new AttemptEvidence($binding, new Query($data['name'], Action::Register, $data['term']), 'ote-original', 1,
                ExecutionRecord::Recorded, SendState::PossiblySent, $start, $clock->now(),
                expectedCharge: new Money($data['authorized_amount'], 'USD'), seal: DispatchSeal::Sealed);
            $offset = $wire->receipt['request_count'];
            $context = new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+240 seconds'),
                new RequestBudget($wire->remaining()), $clock);
            $facts = (new Driver($client, ['register'], $clock))->reconcile($attempt, $context)->result()->evidence();
            $reads = array_slice($wire->receipt['requests'], $offset);
            $healthy = count($reads) >= 2;
            foreach ($reads as $read) {
                $healthy = $healthy && ($read['http_status'] ?? null) === 200 && !($read['transport_failed'] ?? false)
                    && in_array($read['code'] ?? null, $read['command'] === 'getDomainInfo' ? [200, 300] : [300], true)
                    && ($read['read_outcome'] ?? null) === (($read['code'] ?? null) === 200 ? 'Rejected' : 'Success');
            }
            // This category authorizes only this sandbox sequence, never financial completion.
            $matches = array_filter($facts->candidates, static fn ($row) => $row->query->matches($attempt->query)
                && $row->charge?->currency === 'USD' && self::sameAmount($row->charge->amount, $data['authorized_amount']));
            if ($healthy && $facts->domain?->owned === true && $facts->domain->active === true && $facts->domain->name === $data['name']
                && count($facts->candidates) === 1 && count($matches) === 1) {
                $category = 'registered_in_account';
            } else {
                $info = $wire->latest('getDomainInfo'); $orders = $wire->latest('listOrders');
                $absent = in_array($info['code'] ?? null, [200, '200'], true) && is_string($info['detail'] ?? null)
                    && !array_intersect(['order_amount', 'order_number', 'order_details'], array_keys($info));
                // Strict empty history is sufficient here; nonempty/partial history stays ambiguous.
                $empty = ($orders['order'] ?? null) === [];
                $available = $client->read(RegistrarRequest::availability([$data['name']], false), $context);
                $row = $available->value('available', [])[0] ?? [];
                $category = $healthy && $absent && $empty && !$facts->candidates && $available->outcome === Outcome::Success
                    && ($row['domain'] ?? null) === $data['name'] && ($row['premium'] ?? null) === false
                    ? 'not_registered' : 'ambiguous';
            }
            $wire->receipt['reconciliation']['category'] = $category;
            $wire->save(); return $category;
        } catch (\Throwable) { $wire->save(); return 'ambiguous'; }
    }
    private static function sameAmount(string $a, string $b): bool
    {
        $normalize = static fn ($v) => rtrim(rtrim($v, '0'), '.');
        // Money canonicalizes integer and fractional inputs to a decimal form.
        return $normalize((new Money($a, 'USD'))->amount) === $normalize((new Money($b, 'USD'))->amount);
    }
    public function assertRedacted(string $json): void
    {
        $data = $this->original->getValue();
        foreach ([$data['name'], $data['authorized_amount']] as $value) {
            if (str_contains($json, $value)) { throw new \RuntimeException('Private attempt receipt leak refused.'); }
        }
    }
    public function __debugInfo(): array { return []; }
    public function __serialize(): never { throw new \LogicException('Private attempt cannot be serialized.'); }
}
