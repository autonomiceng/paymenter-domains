<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\CatalogueReader;
use Paymenter\Extensions\Servers\Domains\Data\{CatalogueEntry, CatalogueFailure, CatalogueFreshness, CatalogueUnavailable, RequestContext};

final class CatalogueSync
{
    public function __construct(private readonly AccountBindings $bindings, private readonly PriceCatalogue $prices) {}
    public function refresh(int $serverId, RequestContext $context, CatalogueFreshness $freshness = new CatalogueFreshness, ?array $suffixes = null, ?StaffSettingsReview $review = null, bool $scheduled = false): array
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Catalogue reads must finish outside locks.'); }
        $generation = UpgradeGate::legacy();
        // Staff review, or the daily price sync, which checks our daily call cap for the account first.
        if (TestCatalogue::enabled($context->binding) && $review === null && !$scheduled) { throw new \DomainException('TEST catalogue import requires staff review.'); }
        $reader = $this->bindings->reader($serverId); // enabled, identity, credentials, site BEFORE any provider read
        if (!$reader instanceof CatalogueReader) { throw new \DomainException('Catalogue reader unavailable.'); }
        $reader->binding()->requireMatch($context->binding);
        $accepted = CatalogueAcceptances::load($reader->binding());
        $account = \Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id', $serverId)->sole();
        $automatic = $scheduled && $review === null && SellAutomation::enabled($account);
        $reading = $automatic ? SellAutomation::priceReads($account, $accepted, $context->now()) : $accepted;
        if ($suffixes !== null) {
            if (!array_is_list($suffixes) || array_diff($suffixes, array_keys($reading->endings))) { throw new \DomainException('Unaccepted refresh subset.'); }
            $reading = new \Paymenter\Extensions\Servers\Domains\Data\CatalogueAcceptance($reading->binding, $reading->revision,
                array_intersect_key($reading->endings, array_fill_keys($suffixes, true)));
        } elseif ($reading->binding->registrarKey === 'enom' && $reading->endings) {
            // Rotate the default starting ending each cadence; schedulers drain explicit bounded subsets.
            $keys = array_keys($reading->endings); $offset = intdiv($context->now()->getTimestamp(), $freshness->intervalSeconds) % count($keys);
            $keys = [...array_slice($keys, $offset), ...array_slice($keys, 0, $offset)];
            $reading = new \Paymenter\Extensions\Servers\Domains\Data\CatalogueAcceptance($reading->binding, $reading->revision,
                array_replace(array_fill_keys($keys, null), $reading->endings));
        }
        $summary = ['synced' => 0, 'failed' => []];
        if ($reading->endings === []) { return $summary; }
        try { $batch = $reader->catalogueBatch($reading, $freshness, $context); }
        catch (EnomTestCallLimit $error) { throw $error; }
        catch (ReadDeferred $error) { throw $error; }
        catch (\Throwable $error) {
            $failed = array_map(static fn (string $tld): string => '.'.$tld, array_keys($reading->endings));
            return ['synced' => 0, 'failed' => $failed, 'failure_codes' => array_fill_keys($failed, ReadFailure::code($error))];
        }
        foreach ($reading->endings as $tld => $_) {
            try {
                $entry = $batch[$tld] ?? null;
                if ($entry instanceof CatalogueFailure) {
                    $summary['failed'][] = '.'.$tld;
                    $summary['failure_codes']['.'.$tld] = $entry->code;
                    continue;
                }
                if ((!$entry instanceof CatalogueEntry && !$entry instanceof CatalogueUnavailable) || $entry->tld !== $tld) { throw new \DomainException('No catalogue evidence.'); }
                foreach ($entry instanceof CatalogueEntry ? $entry->rates : [] as $rate) {
                    if ($rate->observedAt > $context->now() || !$rate->freshUntil || $rate->freshUntil <= $context->now()
                        || $rate->freshUntil > $rate->observedAt->modify('+'.$freshness->maxAge().' seconds')
                        || !in_array($rate->term, $reading->endings[$tld][$rate->action], true)) {
                        throw new \DomainException('Unaccepted catalogue evidence.');
                    }
                }
                UpgradeGate::legacy($generation);
                if ($automatic && $entry instanceof CatalogueEntry) {
                    SellAutomation::accept($account, $context->now(), $entry, $accepted->revision, $generation);
                    $accepted = CatalogueAcceptances::load($reader->binding());
                }
                $recheck = function ($server, $account) use ($accepted, $generation): void {
                    UpgradeGate::legacy($generation);
                    $this->bindings->requireReadableLocked($server, $account);
                    if (CatalogueAcceptances::load($accepted->binding, true)->revision !== $accepted->revision) {
                        throw new \DomainException('Acceptance changed during refresh.');
                    }
                };
                if ($entry instanceof CatalogueUnavailable) {
                    $this->prices->unavailable($entry, $accepted, $recheck, $context->now());
                    $summary['quarantined'][] = '.'.$tld; continue;
                }
                $this->prices->import($entry, $accepted, $recheck, $context->now(), $review, $scheduled, $automatic);
                $summary['synced']++;
            } catch (ReadDeferred|EnomTestCallLimit $error) { throw $error; }
            catch (\Throwable $error) {
                $summary['failed'][] = '.'.$tld;
                $summary['failure_codes']['.'.$tld] = ReadFailure::code($error,'catalogue_import_failed');
            }
        }
        return $summary;
    }
}
