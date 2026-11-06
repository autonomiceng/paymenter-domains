<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;

use Paymenter\Extensions\Servers\Domains\Catalogue\{Cost, Facts, FactsObserved, FeedObserved, PricedFeed, TermSupport};
use Paymenter\Extensions\Servers\Domains\Data\{CatalogueFreshness, CostUnit, RequestContext};
use Paymenter\Extensions\Servers\Domains\Services\PricingRule;

/** Five account reads. Bucket membership is evidence, never interpolation from an annual price. */
final class OfferFeed implements PricedFeed
{
    public const BUCKETS = [1, 2, 5, 10];
    private array $observations = [];
    public function __construct(private readonly Driver $reader,
        private readonly ?CatalogueFreshness $freshness = null,
        private readonly array $terms = [1 => [1], 2 => [2], 5 => [5], 10 => [10]],
        /** Reviewed NS17 term/family evidence keyed by suffix; first-pass suffixes only, default none. */
        private readonly array $expansion = [])
    {
        foreach ($terms as $bucket => $years) {
            if (!in_array($bucket, self::BUCKETS, true) || !is_array($years)) { throw new \InvalidArgumentException('Invalid bucket evidence.'); }
            foreach ($years as $term) {
                if (!is_int($term) || $term < $bucket || $term > 10
                    || max(array_filter(self::BUCKETS, static fn ($b) => $b <= $term)) !== $bucket) {
                    throw new \InvalidArgumentException('Invalid bucket evidence.');
                }
            }
            if (count($years) !== count(array_unique($years))) { throw new \InvalidArgumentException('Duplicate bucket term.'); }
        }
    }
    public function factsReads(): int { return 2; }
    public function priceReads(): int { return count(self::BUCKETS) + 1; }
    private function read(ReadResult $read, RequestContext $context): array
    {
        $this->reader->binding()->requireMatch($context->binding);
        $context->binding->requireMatch($read->binding);
        if ($read->status !== 'success') { throw new \DomainException('Offer feed read failed.'); }
        return $read->facts();
    }
    public function prices(RequestContext $context): iterable
    {
        $this->reader->binding()->requireMatch($context->binding);
        if ($context->budget->remaining() < $this->priceReads()) { throw new \DomainException('Offer feed capacity unavailable.'); }
        $at = $context->now(); $until = $at->modify('+'.($this->freshness ?? CatalogueFreshness::forAccount($context->binding->serverId))->maxAge().' seconds'); $rows = [];
        // Buffer the whole run: a failed later bucket/list must expose no earlier commands.
        foreach (self::BUCKETS as $bucket) {
            $read = $this->read($this->reader->bulkPricing(false, $bucket, $context), $context);
            foreach ($read['rows'] as $tld => $row) {
                try { Catalogue::tld((string) $tld); } catch (\InvalidArgumentException) { continue; }
                $rows[$tld] ??= [];
                $actions = ['register' => ['resellerpricereg', 'registerenabled', $this->terms[$bucket] ?? []]];
                if ($bucket === 1) {
                    $actions += ['renew' => ['resellerpricerenew', 'renewenabled', [1]], 'transfer' => ['resellerpricetran', 'transferenabled', [1]]];
                }
                foreach ($actions as $action => [$field, $flag, $terms]) {
                    $enabled = $row[$flag] ?? null;
                    $amount = PricingRule::wholesale($row[$field] ?? null);
                    // Unknown/malformed is omission. Only an explicit false withdraws a price.
                    if ($enabled === null || $enabled && ($amount === null || PricingRule::units($amount) < 1)) { continue; }
                    foreach ($terms as $term) {
                        try {
                            $rows[$tld][] = new Cost($action, $term, $enabled, $enabled ? $amount : null,
                                CostUnit::Annual, 'enom_bulk_bucket_'.$bucket, $at, $until);
                        } catch (\InvalidArgumentException) { /* Unrepresentable exact total retains old evidence. */ }
                    }
                }
            }
        }
        $list = $this->read($this->reader->catalogue($context), $context);
        foreach ($list['tlds'] as $tld) { $rows[$tld] ??= []; }
        $commands = [];
        foreach ($rows as $tld => $costs) { $commands[] = new FeedObserved($context->binding, $tld, $costs); }
        $this->observations = [];
        foreach ($commands as $command) { $this->observations[$command->tld()] = $command; }
        return $commands;
    }
    public function facts(string $tld, RequestContext $context): FactsObserved
    {
        $this->reader->binding()->requireMatch($context->binding); Catalogue::tld($tld);
        if ($context->budget->remaining() < 2) { throw new \DomainException('Offer facts capacity unavailable.'); }
        $at = $context->now();
        $details = $this->read($this->reader->tldCapabilities($tld, $context), $context);
        $schema = $this->read($this->reader->registrySchema($tld, $context), $context);
        $auto = $details['auto_renew_only'];
        $facts = new Facts(
            ['register' => $details['capabilities']->get('register')->terms, 'renew' => [], 'transfer' => []],
            $schema['supported'] === true && $details['registry_attributes'] !== null ? $schema['fields'] : null,
            $auto === null ? null : !$auto, $details['requires_nameservers'], $auto,
            'enom_tld_facts', $at, $at->modify('+2592000 seconds'));
        if (isset($this->observations[$tld])) { $facts = $this->observations[$tld]->facts($facts); }
        return new FactsObserved($context->binding, $tld, TermSupport::attach($tld, $facts, $this->expansion[$tld] ?? null));
    }
}
