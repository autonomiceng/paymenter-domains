<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/offer-work-test.php';
$nativeSuccessful = false;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{OfferWork, Projection, UpgradeGate, Writer};
use Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;

// A paused generation is an explicit offline composition fixture, never an active parser shortcut.
$generation = UpgradeGate::generation();
$guard = static function () use ($generation): void {
    if (DB::transactionLevel() > 0) { UpgradeGate::active($generation); }
    elseif (UpgradeGate::generation() !== $generation) { throw new DomainException('Synthetic generation changed.'); }
};
$writer = new Writer(new Projection($native), $clock, static function (): void {}, static fn () => false, current: $guard);
$worker = new OfferWork($writer, $clock, new AccountReadBudget($clock, 25, 25), $factory,
    static fn () => 25, $credentialPort, $generation, $guard);
$worker->sync($b);
commerceReject(static fn () => $worker->tick(), 'A composed worker cannot rotate a second account consumer');
$clock->time = $clock->time->modify('+301 seconds'); $wire->extra = 60;
$before = count($wire->calls);
same('worked', $worker->accountTurn($b->serverId), 'Shared scheduler executes one account turn');
same(5, count($wire->calls) - $before, 'New generation discards the former feed buffer');
same(38, $worker->status($b)['remaining'], 'Shared account turn retains the 25-row bound');
$restarted = new OfferWork($writer, $clock, new AccountReadBudget($clock, 25, 25), $factory,
    static fn () => 25, $credentialPort, $generation, $guard);
same('worked', $restarted->accountTurn($b->serverId), 'A reconstructed shared worker resumes its buffer');
same(13, $restarted->status($b)['remaining'], 'Restart drains one bounded batch');
same(5, count($wire->calls) - $before, 'Restart does not repeat physical feed reads');
$restarted->accountTurn($b->serverId);
$clock->time = $clock->time->modify('+61 seconds'); $worker->sync($b);
$lastGood = $worker->status($b)['succeeded_at'];
$physical = count($wire->calls);
$wire->during = static function ($command): void { if ($command === ReadCommand::RetailPricing) { advanceOfferGeneration(); } };
commerceReject(static fn () => $worker->accountTurn($b->serverId)); $wire->during = null;
same($physical + 1, count($wire->calls), 'Generation change stops the remaining physical feed group reads');
$row = DB::table('domains_observation_queues')->where('server_id', $b->serverId)->first();
same($lastGood, $row->offer_feed_succeeded_at, 'Superseded feed cannot acknowledge success');
same(null, $row->offer_claim, 'Stale completion releases only its own claim');
commerceReject(static fn () => $worker->sync($b), 'An obsolete generation cannot enqueue');
commerceReject(static fn () => $worker->status($b), 'Stale status cannot claim ready');
$nativeSuccessful = true;
echo "Shared offer account generation checks passed.\n";
