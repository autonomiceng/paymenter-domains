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
    public static function persist(string $directory, string $name, Money $amount, int $term, string $route, string $utc, string $command = 'registerDomain', ?string $beforeExpiry = null): self
    {
        if (!preg_match('/^contract-ote-[0-9a-f]{12}\.com$/D', $name) || $amount->currency !== 'USD'
            || !preg_match('/^[0-9]+(?:\.[0-9]{1,6})?$/D', $amount->amount) || $term !== 1
            || !in_array($route, ['/api', '/apibatch'], true)
            || !in_array($command, ['registerDomain', 'renewDomain'], true)
            || ($command === 'renewDomain' && Shapes::strictDate($beforeExpiry) === null)
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D', $utc)
            || (new \DateTimeImmutable($utc))->format(DATE_ATOM) !== $utc) {
            throw new \RuntimeException('Private attempt scope refused.');
        }
        $data = ['name' => $name, 'authorized_amount' => $amount->amount, 'term' => $term, 'route' => $route, 'dispatch_utc' => $utc, 'command' => $command];
        if ($command === 'renewDomain') { $data['before_expiry'] = $beforeExpiry; }
        $path = $directory.'/attempt-private.json';
        if (file_exists($path)) {
            $prior = self::readPrivate($path);
            if (isset($prior['name'])) {
                $archive = ($prior['command'] ?? 'registerDomain') === 'registerDomain'
                    ? 'registration-attempt-private.json' : 'renewal-background-attempt-private.json';
                if (file_exists($directory.'/'.$archive)) { $archive .= '.'.bin2hex(random_bytes(4)); }
                if (!rename($path, $directory.'/'.$archive)) {
                    throw new \RuntimeException('Private attempt archive failed.');
                }
            } else { $data['non_success_replies'] = $prior['non_success_replies'] ?? []; }
        }
        self::writePrivate($path, $data);
        unset($data['non_success_replies']);
        return new self($path, new \SensitiveParameterValue($data));
    }
    public static function readPrivate(string $path): array
    {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path) || (fileperms($path) & 0777) !== 0600) {
            throw new \RuntimeException('Private evidence permissions refused.');
        }
        return json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    }
    public static function writePrivate(string $path, array $data): void
    {
        if (is_link($path)) { throw new \RuntimeException('Private evidence link refused.'); }
        $json = json_encode($data, JSON_THROW_ON_ERROR)."\n";
        $temporary = $path.'.pending-'.bin2hex(random_bytes(8));
        $old = umask(0077);
        try { $file = fopen($temporary, 'x'); }
        finally { umask($old); }
        if ($file === false) { throw new \RuntimeException('Private attempt creation failed.'); }
        try {
            if (!chmod($temporary, 0600) || fwrite($file, $json) !== strlen($json) || !fflush($file) || !fsync($file)
                || is_link($path) || !rename($temporary, $path)) {
                throw new \RuntimeException('Private attempt persistence failed.');
            }
        } finally { fclose($file); if (file_exists($temporary)) { unlink($temporary); } }
        $parent=@fopen(dirname($path),'r');
        if ($parent===false) { throw new \RuntimeException('Private evidence directory open failed.'); }
        try { if (!fsync($parent)) { throw new \RuntimeException('Private evidence directory sync failed.'); } }
        finally { fclose($parent); }
    }

    public static function retainDetail(string $directory, string $command, ?int $code, ?string $detail): void
    {
        $path = $directory.'/attempt-private.json';
        $data = file_exists($path) ? self::readPrivate($path) : [];
        $data['non_success_replies'][] = ['command' => $command, 'code' => $code, 'detail' => $detail];
        self::writePrivate($path, $data);
    }
    public function original(): array
    {
        $data = self::readPrivate($this->path);
        $original = $this->original->getValue();
        if (array_intersect_key($data, $original) !== $original) { throw new \RuntimeException('Private evidence changed.'); }
        return $original;
    }
    public function renewalCategory(string $category): void
    {
        if (!in_array($category, ['renewed', 'not_renewed', 'ambiguous'], true)) { throw new \RuntimeException('Closed renewal category required.'); }
        $this->original(); $data = self::readPrivate($this->path); $data['renewal_category'] = $category;
        self::writePrivate($this->path, $data);
    }
    public function reconcile(GuardedTransport $wire, Client $client, AccountBinding $binding, Clock $clock): string
    {
        $wire->receipt['reconciliation'] = ['category' => 'ambiguous', 'adopted' => false];
        $wire->check('purchase_unresolved', true);
        try {
            $data = $this->original();
            if ($data['command'] !== 'registerDomain' || $data['name'] !== $wire->domain) { throw new \RuntimeException('Private evidence changed.'); }
            $start = new \DateTimeImmutable($data['dispatch_utc']);
            $attempt = new AttemptEvidence($binding, new Query($data['name'], Action::Register, $data['term']), 'ote-original', 1,
                ExecutionRecord::Recorded, SendState::PossiblySent, $start, $clock->now(),
                expectedCharge: new Money($data['authorized_amount'], 'USD'), seal: DispatchSeal::Sealed);
            $offset = $wire->receipt['request_count'];
            $context = new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+240 seconds'),
                new RequestBudget($wire->remaining()), $clock);
            $facts = (new Driver($client, ['register'], $clock))->reconcile($attempt, $context)->result()->evidence();
            $reads = array_slice($wire->receipt['requests'], $offset);
            $healthy = count($reads) >= 2; $info = null;
            foreach ($reads as $read) {
                if ($read['command'] === 'getDomainInfo') { $info = $read; }
                $healthy = $healthy && ($read['http_status'] ?? null) === 200 && !($read['transport_failed'] ?? false)
                    && in_array($read['code'] ?? null, $read['command'] === 'getDomainInfo' ? [200, 300] : [300], true)
                    && ($read['read_outcome'] ?? null) === (($read['code'] ?? null) === 200 ? 'Rejected' : 'Success')
                    && (($read['read_validated'] ?? false) === true
                        || ($read['command'] === 'getDomainInfo' && ($read['absence_validated'] ?? false) === true));
            }
            // This category authorizes only this sandbox sequence, never financial completion.
            $matches = array_filter($facts->candidates, static fn ($row) => $row->query->matches($attempt->query)
                && $row->charge?->currency === 'USD' && self::sameAmount($row->charge->amount, $data['authorized_amount']));
            if ($healthy && $facts->domain?->owned === true && $facts->domain->active === true && $facts->domain->name === $data['name']
                && count($facts->candidates) === 1 && count($matches) === 1) {
                $category = 'registered_in_account';
            } else {
                $orders = $wire->latest('listOrders');
                $absent = ($info['absence_validated'] ?? false) === true;
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
