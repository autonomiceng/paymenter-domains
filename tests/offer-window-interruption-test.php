<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/accounts.php';

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances, Installation, SellTableSchema};

(new Installation)->install(); extract(commerceFixture());
$binding = CatalogueAcceptances::binding(Account::where('server_id', $tlds[0]->server_id)->sole());
foreach (['second', 'third'] as $tld) {
    DB::table(SellTableSchema::ROWS)->insert(['server_id' => $binding->serverId, 'account_fingerprint' => $binding->fingerprint, 'tld' => $tld]);
}
$gate = new UpgradeGate; $gate->pause(); $evidence = $gate->capture();
$interrupted = false;
DB::listen(static function ($query) use (&$interrupted, $gate): void {
    if (!$interrupted && str_starts_with($query->sql, 'update "domains_tlds"') && str_contains($query->sql, 'catalogue_converted')) {
        $interrupted = true;
        DB::afterCommit(static fn () => $gate->resume());
    }
});
try { $gate->batch($binding, $evidence, 500); throw new LogicException('Interrupted conversion accepted'); }
catch (DomainException) {}
same(true, $interrupted, 'Resume interleaves after the first committed offer');
same(1, DB::table('domains_tlds')->where('server_id', $binding->serverId)->where('catalogue_converted', true)->count(),
    'Conversion stops writing offers immediately after its paused generation ends');
same(false, (bool) DB::table('domains_observation_queues')->where('server_id', $binding->serverId)->value('offer_conversion_complete'),
    'An interrupted batch cannot mark completion after resume');
$cursor = DB::table('domains_observation_queues')->where('server_id', $binding->serverId)->value('offer_conversion_cursor');
$gate->pause();
try { $gate->batch($binding, $evidence); throw new LogicException('Earlier paused generation accepted'); }
catch (DomainException) {}
same($cursor, DB::table('domains_observation_queues')->where('server_id', $binding->serverId)->value('offer_conversion_cursor'),
    'A new pause does not authorize an earlier batch cursor');
$nativeSuccessful = true;
echo "Interrupted offer conversion: ok\n";
