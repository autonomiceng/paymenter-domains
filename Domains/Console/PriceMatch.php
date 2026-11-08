<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Console;

use Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting;
use Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers;

/** Local verification only: never publishes, repairs, or contacts a registrar. */
final class PriceMatch extends \Illuminate\Console\Command
{
    protected $signature = 'domains:price-match {--json : Emit all eligible terms and their mismatches as JSON}';
    protected $description = 'Check eligible acquisition terms against native plan prices without changing them';

    public function handle(): int
    {
        $offers = CallerRouting::resolve(AcquisitionOffers::class) ?? throw new \DomainException('Active catalogue required.');
        $rows = $offers->priceMatches();
        $failures = array_filter($rows, static fn ($row) => $row['mismatch'] !== null);
        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_THROW_ON_ERROR));
        } else {
            foreach ($failures as $row) {
                $this->line($row['tld'].' '.$row['action'].' '.$row['term'].'y: catalogue USD '.$row['catalogue']
                    .'; plan '.($row['plan'] ?? 'missing/invalid').': '.$row['mismatch']);
            }
            $this->line('Eligible acquisition terms: '.count($rows).'. Price mismatches: '.count($failures).'.');
        }
        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
