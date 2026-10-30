<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver;

/** What an account could sell. The full list is one read, kept for a day so opening the screen spends nothing.
 * Years are read per ending, only when staff turn it on. Terms and prices of other endings are never read.
 * The list needs no saved acceptance yet, so it uses the unrestricted reader; it reads and never sells. */
final class EndingChoices
{
    private const KEEP_SECONDS = 86400;

    public function __construct(private readonly AccountBindings $bindings, private readonly Clock $clock) {}

    /** Config domains.hidden_endings: endings we never offer. One already saved stays on the list for renewals only. */
    public static function hidden(string $ending): bool
    {
        return in_array($ending, array_map(static fn($name) => strtolower(ltrim(trim((string)$name), '.')), (array)config('domains.hidden_endings', [])), true);
    }

    /** Hidden endings read like ones staff turned off: no new sales, renewals unchanged. */
    public static function closeHidden(array $endings): array
    {
        foreach ($endings as $ending => $row) {
            if (self::hidden((string) $ending)) { $endings[$ending] = ['register' => [], 'transfer' => [], 'registry' => false] + $row; }
        }
        return $endings;
    }

    public static function readable(AccountBinding $binding): bool
    {
        return $binding->registrarKey === 'enom' && TestCatalogue::enabled($binding);
    }

    /** @return ?list<string> what the last read found, without a provider call */
    public function saved(AccountBinding $binding): ?array
    {
        return Cache::get(self::key($binding, 'list'));
    }

    /** @return list<string> @throws \Throwable when the read fails or our send limit stops it */
    public function refresh(AccountBinding $binding): array
    {
        $read = $this->driver($binding)->catalogue($this->context($binding));
        if ($read->status !== 'success') { throw new \DomainException('Ending list read failed.'); }
        $names = array_values($read->facts()['tlds']);
        Cache::put(self::key($binding, 'list'), $names, self::KEEP_SECONDS);
        return $names;
    }

    /** @return ?list<int> years offered, or null when they could not be read */
    public function years(AccountBinding $binding, string $ending): ?array
    {
        $key = self::key($binding, 'years.'.$ending);
        if (($years = Cache::get($key)) !== null) { return $years; }
        try {
            $read = $this->driver($binding)->tldCapabilities($ending, $this->context($binding));
            $years = $read->status === 'success' ? $read->facts()['capabilities']->get('register')->terms : [];
        } catch (\Throwable) { return null; }
        if ($years === []) { return null; }
        Cache::put($key, $years, self::KEEP_SECONDS);
        return $years;
    }

    private function driver(AccountBinding $binding): Driver
    {
        if (!self::readable($binding)) { throw new \DomainException('TEST catalogue reads are unavailable.'); }
        $driver = $this->bindings->reader($binding->serverId, true);
        return $driver instanceof Driver ? $driver : throw new \DomainException('TEST catalogue reads are unavailable.');
    }

    private function context(AccountBinding $binding): RequestContext
    {
        return new RequestContext($binding, RequestOrigin::Interactive, $this->clock->now()->modify('+20 seconds'), new RequestBudget(1), $this->clock, Runtime::limiter(), stopOnReadFailure: true);
    }

    private static function key(AccountBinding $binding, string $name): string
    {
        return 'domains.ending_choices.'.$binding->serverId.'.'.$binding->fingerprint.'.'.$name;
    }
}
