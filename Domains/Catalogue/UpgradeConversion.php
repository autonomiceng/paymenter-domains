<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use App\Models\Server;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CanonicalName};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances, SellRule, SellTableSchema};

/** Explicit, dormant entry point. B6 must pause/drain legacy writers before capture and conversion.
 * No caller switch, projection, audit rewrite or provider read is performed here. */
final class UpgradeConversion
{
    public function batch(AccountBinding $binding, int $limit = 100): array
    {
        if (DB::transactionLevel() !== 0 || $limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Conversion requires an outer-free bounded batch.');
        }
        [$accepted, $rule, $source] = $this->source($binding);
        $state = DB::transaction(function () use ($binding, $accepted, $rule, $source): object {
            $this->lock($binding);
            $queue = DB::table('domains_observation_queues')->where('server_id', $binding->serverId)->lockForUpdate()->first();
            if ($queue !== null && $queue->account_fingerprint !== $binding->fingerprint) { throw new \DomainException('Queue binding changed.'); }
            if ($queue === null) {
                DB::table('domains_observation_queues')->insert(['server_id' => $binding->serverId,
                    'account_fingerprint' => $binding->fingerprint, 'offer_queue_created' => true]);
                $queue = DB::table('domains_observation_queues')->where('server_id', $binding->serverId)->first();
            }
            if ($queue->offer_conversion_source !== null) {
                if ($queue->offer_conversion_source !== $source) { throw new \DomainException('Legacy conversion sources changed; comparison required.'); }
                return $queue;
            }
            $fill = $this->scoped(SellTableSchema::FILLS, $binding)->first();
            $defaults = new Intent($rule !== null ? $rule['mode'] !== 'only' : !$accepted,
                $rule['years'] ?? 1, $rule['transfers'] ?? false, $rule['max_cost'] ?? null);
            DB::table('domains_accounts')->where('server_id', $binding->serverId)->update(['catalogue_defaults' => self::json($defaults)]);
            DB::table('domains_observation_queues')->where('id', $queue->id)->update([
                'offer_conversion_source' => $source, 'offer_feed_run' => (int) ($fill?->run ?? 0) + 1,
                'offer_feed_tried_at' => $fill?->tried_at, 'offer_feed_succeeded_at' => $fill?->filled_at,
                'offer_feed_failed_at' => $fill?->failed_at, 'offer_feed_failure' => $fill?->failure]);
            return DB::table('domains_observation_queues')->where('id', $queue->id)->first();
        });
        if ($state->offer_conversion_complete) { return ['converted' => 0, 'complete' => true]; }
        $names = $this->names($binding, $accepted, $state->offer_conversion_cursor, $limit);
        $converted = 0;
        foreach ($names as $name) {
            $didConvert = DB::transaction(function () use ($binding, $accepted, $rule, $name, $source): bool {
                $this->lock($binding);
                DB::table('domains_sales_policies')->where('tld', $name)->orderBy('id')->lockForUpdate()->get();
                $tld = $this->scoped('domains_tlds', $binding)->where('tld', $name)->lockForUpdate()->first();
                if ($tld === null) {
                    // Never repoint an existing server/TLD to a different account.
                    if (DB::table('domains_tlds')->where(['server_id' => $binding->serverId, 'tld' => $name])->exists()) {
                        throw new \DomainException('Retained TLD binding conflicts with conversion.');
                    }
                    DB::table('domains_tlds')->insert([...self::key($binding), 'registrar_key' => $binding->registrarKey,
                        'tld' => $name, 'catalogue_created' => true]);
                    $tld = $this->scoped('domains_tlds', $binding)->where('tld', $name)->first();
                }
                $prices = $this->scoped('domains_prices', $binding)->where('tld', $name)->orderBy('id')->lockForUpdate()->get()->all();
                $queue = DB::table('domains_observation_queues')->where('id', $stateId = $this->queue($binding)->value('id'))->lockForUpdate()->first();
                if ($queue->offer_conversion_source !== $source) { throw new \DomainException('Conversion generation changed.'); }
                if (strcmp($queue->offer_conversion_cursor, $name) >= 0) { return false; }
                if (!$tld->catalogue_converted) {
                    $row = $this->scoped(SellTableSchema::ROWS, $binding)->where('tld', $name)->first();
                    $intent = UpgradeEvidence::intent($rule, $accepted, $row, $tld);
                    $evidence = UpgradeEvidence::prices($prices, $row, $tld);
                    foreach ($evidence['costs'] as $key => $cost) {
                        [$action, $term] = explode(':', $key);
                        $existing = $this->scoped('domains_prices', $binding)->where(['tld' => $name,
                            'action' => $action, 'term' => (int) $term, 'currency' => 'USD'])->first();
                        if ($existing === null) {
                            DB::table('domains_prices')->insert([...self::key($binding), 'registrar_key' => $binding->registrarKey,
                                'tld' => $name, 'action' => $action, 'term' => (int) $term, 'currency' => 'USD',
                                'unit' => 'term_total', 'source' => 'legacy_conversion', 'observed_at' => (new \DateTimeImmutable($cost['observed_at']))->format('Y-m-d H:i:s'),
                                'catalogue_created' => true, 'catalogue_observation' => self::json($cost)]);
                        } else {
                            DB::table('domains_prices')->where('id', $existing->id)->update(['catalogue_observation' => self::json($cost)]);
                        }
                    }
                    DB::table('domains_tlds')->where('id', $tld->id)->update([
                        'catalogue_intent' => self::json($intent), 'catalogue_revision' => 1,
                        'catalogue_retained_annual_retail' => $evidence['retained'],
                        'catalogue_issues' => self::json($evidence['issues']), 'catalogue_converted' => true]);
                }
                // Offer data and its resume cursor commit together; a crash rolls both back.
                DB::table('domains_observation_queues')->where('id', $stateId)->update(['offer_conversion_cursor' => $name]);
                return true;
            });
            $converted += (int) $didConvert;
        }
        $complete = count($names) < $limit;
        if ($complete) {
            DB::transaction(function () use ($binding, $source): void {
                $this->lock($binding);
                $queue = $this->queue($binding)->lockForUpdate()->first();
                if ($queue->offer_conversion_source !== $source) { throw new \DomainException('Conversion generation changed.'); }
                $this->queue($binding)->update(['offer_conversion_complete' => true]);
            });
        }
        return ['converted' => $converted, 'complete' => $complete];
    }

    private function lock(AccountBinding $binding): void
    {
        Server::whereKey($binding->serverId)->lockForUpdate()->firstOrFail();
        $account = Account::where('server_id', $binding->serverId)->lockForUpdate()->sole();
        $binding->requireMatch(CatalogueAcceptances::binding($account));
    }

    private static function key(AccountBinding $binding): array
    { return ['server_id' => $binding->serverId, 'account_fingerprint' => $binding->fingerprint]; }
    private function scoped(string $table, AccountBinding $binding): Builder
    { return UpgradeComparison::query($table)->where(self::key($binding)); }
    private function queue(AccountBinding $binding): Builder
    { return DB::table('domains_observation_queues')->where(self::key($binding)); }
    private static function json(mixed $value): string
    { return json_encode($value, JSON_THROW_ON_ERROR); }

    private function source(AccountBinding $binding): array
    {
        $account = Account::where('server_id', $binding->serverId)->sole();
        $binding->requireMatch(CatalogueAcceptances::binding($account));
        $server = Server::findOrFail($binding->serverId);
        if ($server->extension !== 'Domains') { throw new \DomainException('Not a Domains server.'); }
        $exists = $server->settings()->where('key', CatalogueAcceptances::KEY)->exists();
        $accepted = $exists ? CatalogueAcceptances::load($binding)->endings : null;
        $rule = SellRule::saved($binding); $digests = [];
        foreach (['domains_tlds', 'domains_prices', SellTableSchema::ROWS, SellTableSchema::FILLS] as $table) {
            // Include wrong-binding rows too: they must block, never be silently adopted.
            $query = UpgradeComparison::retained($table)->where('server_id', $binding->serverId);
            if ((clone $query)->where('account_fingerprint', '!=', $binding->fingerprint)->exists()) {
                throw new \DomainException('Retained catalogue binding conflicts with conversion.');
            }
            $digests[$table] = UpgradeComparison::digest($table, $query);
        }
        return [$accepted, $rule, hash('sha256', self::json([$binding->fingerprint, $accepted, $rule, $digests]))];
    }

    private function names(AccountBinding $binding, ?array $accepted, string $cursor, int $limit): array
    {
        $names = [];
        foreach (['domains_tlds', SellTableSchema::ROWS, 'domains_prices'] as $table) {
            $column = DB::connection()->getQueryGrammar()->wrap('tld');
            $binary = DB::connection()->getDriverName() === 'sqlite' ? $column.' COLLATE BINARY' : 'CAST('.$column.' AS BINARY)';
            foreach (DB::table($table)->where(self::key($binding))->select('tld')->whereRaw($binary.' > ?', [$cursor])
                ->orderByRaw($binary)->distinct()->limit($limit)->pluck('tld') as $name) { $names[$name] = true; }
        }
        foreach (array_keys($accepted ?? []) as $name) { if (strcmp((string) $name, $cursor) > 0) { $names[$name] = true; } }
        $out = array_keys($names); sort($out, SORT_STRING); $out = array_slice($out, 0, $limit);
        foreach ($out as $name) { CanonicalName::parse('example.'.$name, [$name]); }
        return $out;
    }

    /** Original-row digests plus a bounded comparison of the actual staged intent/costs/retail. */
    public function report(AccountBinding $binding, array $before): array
    {
        [$accepted, $rule, $source] = $this->source($binding);
        $queue = $this->queue($binding)->first(); $issues = []; $mismatches = [];
        $this->scoped('domains_tlds', $binding)->where('catalogue_converted', true)->orderBy('id')->chunkById(250,
            function ($rows) use ($binding, $accepted, $rule, &$issues, &$mismatches): void {
                $names = $rows->pluck('tld');
                $legacyRows = $this->scoped(SellTableSchema::ROWS, $binding)->whereIn('tld', $names)->get()->keyBy('tld');
                $allPrices = $this->scoped('domains_prices', $binding)->whereIn('tld', $names)->orderBy('id')->get()->groupBy('tld');
                foreach ($rows as $tld) {
                    $prices = $allPrices[$tld->tld] ?? collect();
                    $evidence = UpgradeEvidence::prices($prices->where('catalogue_created', false)->all(), $legacyRows[$tld->tld] ?? null, $tld);
                    $intent = UpgradeEvidence::intent($rule, $accepted, $legacyRows[$tld->tld] ?? null, $tld);
                    $actual = [];
                    foreach ($prices as $price) {
                        if ($price->catalogue_observation !== null) {
                            $key = $price->action.':'.$price->term.($price->currency === 'USD' ? '' : ':'.$price->currency);
                            $actual[$key] = UpgradeEvidence::json($price->catalogue_observation);
                        }
                    }
                    ksort($actual);
                    $checks = ['intent' => UpgradeEvidence::json($tld->catalogue_intent) === json_decode(self::json($intent), true),
                        'costs' => $actual === $evidence['costs'],
                        'retained_retail' => UpgradeEvidence::money($tld->catalogue_retained_annual_retail, 2) === $evidence['retained']];
                    foreach ($checks as $check => $matches) {
                        if (!$matches) { $mismatches[$check] = ($mismatches[$check] ?? 0) + 1; }
                    }
                    foreach ($evidence['issues'] as $code) { $issues[$code] = ($issues[$code] ?? 0) + 1; }
                }
            });
        $defaults = new Intent($rule !== null ? $rule['mode'] !== 'only' : !$accepted,
            $rule['years'] ?? 1, $rule['transfers'] ?? false, $rule['max_cost'] ?? null);
        $storedDefaults = DB::table('domains_accounts')->where('server_id', $binding->serverId)->value('catalogue_defaults');
        if (UpgradeEvidence::json($storedDefaults) !== json_decode(self::json($defaults), true)) { $mismatches['defaults'] = 1; }
        $comparison = UpgradeComparison::compare($before);
        return [...$comparison, 'compatible' => $comparison['compatible'] && $mismatches === [],
            'staging_mismatches' => $mismatches, 'complete' => (bool) ($queue?->offer_conversion_complete ?? false),
            'source_unchanged' => $queue?->offer_conversion_source === $source, 'issues' => $issues,
            'converted' => $this->scoped('domains_tlds', $binding)->where('catalogue_converted', true)->count(),
            'activated' => false];
    }
}
