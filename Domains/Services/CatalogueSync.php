<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\CatalogueReader;
use Paymenter\Extensions\Servers\Domains\Data\{CatalogueEntry, CatalogueFreshness, CatalogueUnavailable, RequestContext};

final class CatalogueSync
{
    public function __construct(private readonly AccountBindings $bindings, private readonly PriceCatalogue $prices) {}
    public function refresh(int $serverId, RequestContext $context, CatalogueFreshness $freshness = new CatalogueFreshness, ?array $suffixes = null): array
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Catalogue reads must finish outside locks.'); }
        $reader = $this->bindings->reader($serverId); // enabled, identity, credentials, site BEFORE any provider read
        if (!$reader instanceof CatalogueReader) { throw new \DomainException('Catalogue reader unavailable.'); }
        $reader->binding()->requireMatch($context->binding);
        $accepted = CatalogueAcceptances::load($reader->binding());
        if ($suffixes !== null) {
            if (!array_is_list($suffixes) || array_diff($suffixes, array_keys($accepted->endings))) { throw new \DomainException('Unaccepted refresh subset.'); }
            $accepted = new \Paymenter\Extensions\Servers\Domains\Data\CatalogueAcceptance($accepted->binding, $accepted->revision,
                array_intersect_key($accepted->endings, array_fill_keys($suffixes, true)));
        } elseif ($accepted->binding->registrarKey === 'enom' && $accepted->endings) {
            // Rotate the default starting ending each cadence; schedulers drain explicit bounded subsets.
            $keys = array_keys($accepted->endings); $offset = intdiv($context->now()->getTimestamp(), $freshness->intervalSeconds) % count($keys);
            $keys = [...array_slice($keys, $offset), ...array_slice($keys, 0, $offset)];
            $accepted = new \Paymenter\Extensions\Servers\Domains\Data\CatalogueAcceptance($accepted->binding, $accepted->revision,
                array_replace(array_fill_keys($keys, null), $accepted->endings));
        }
        $summary = ['synced' => 0, 'failed' => []];
        if ($accepted->endings === []) { return $summary; }
        try { $batch = $reader->catalogueBatch($accepted, $freshness, $context); }
        catch (ReadDeferred $error) { throw $error; }
        catch (\Throwable) { return ['synced' => 0, 'failed' => array_map(static fn (string $tld): string => '.'.$tld, array_keys($accepted->endings))]; }
        foreach ($accepted->endings as $tld => $_) {
            try {
                $entry = $batch[$tld] ?? null;
                $recheck = function ($server, $account) use ($accepted): void {
                    $this->bindings->requireReadableLocked($server, $account);
                    if (CatalogueAcceptances::load($accepted->binding, true)->revision !== $accepted->revision) {
                        throw new \DomainException('Acceptance changed during refresh.');
                    }
                };
                if ($entry instanceof CatalogueUnavailable && $entry->tld === $tld) {
                    $this->prices->unavailable($entry, $accepted, $recheck, $context->now());
                    $summary['quarantined'][] = '.'.$tld; continue;
                }
                if (!$entry instanceof CatalogueEntry || $entry->tld !== $tld) { throw new \DomainException('No catalogue evidence.'); }
                foreach ($entry->rates as $rate) {
                    if ($rate->observedAt > $context->now() || !$rate->freshUntil || $rate->freshUntil <= $context->now()
                        || $rate->freshUntil > $rate->observedAt->modify('+'.$freshness->maxAge().' seconds')
                        || !in_array($rate->term, $accepted->endings[$tld][$rate->action], true)) {
                        throw new \DomainException('Unaccepted catalogue evidence.');
                    }
                }
                $this->prices->import($entry, $accepted, $recheck, $context->now());
                $summary['synced']++;
            } catch (\Throwable) { $summary['failed'][] = '.'.$tld; }
        }
        return $summary;
    }
}
