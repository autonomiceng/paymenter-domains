<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Durable account binding and SQL lease sharing require the native SQLite fixture, with no transport installed.
require __DIR__.'/native-app.php';
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\FeedReadBudget;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget, Installation, ReadDeferred};
(new Installation)->install();
$clock = new class implements Clock {
    public DateTimeImmutable $at;
    public function __construct() { $this->at = new DateTimeImmutable('2026-11-02T20:00:00Z'); }
    public function now(): DateTimeImmutable { return $this->at; }
};
app()->instance(Clock::class, $clock);
$binding = static function (string $provider): AccountBinding {
    $s = Server::create(['name' => 'Synthetic feed account', 'type' => 'server', 'extension' => 'Domains', 'enabled' => true]);
    $a = Account::withVerifiedBindingWrite(fn () => Account::create(['server_id' => $s->id, 'registrar_key' => $provider, 'environment' => 'test', 'stable_identity' => 'synthetic-'.$provider.'-'.$s->id]));
    return \Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($a);
};
$refuses = static function (callable $work): void {
    try { $work(); } catch (ReadDeferred|DomainException|InvalidArgumentException $e) { return; }
    throw new LogicException('Expected budget refusal.');
};
foreach (['enom' => 5, 'namesilo' => 1] as $provider => $calls) {
    $b = $binding($provider); $minute = new AccountReadBudget($clock, 5);
    $group = new FeedReadBudget($minute, $b, RequestOrigin::Background, $calls);
    $q = fn () => DB::table('domains_read_budgets')->where('server_id', $b->serverId)->first();
    same($calls, (int) $q()->used, 'Whole feed reserves all slots atomically');
    if ($provider === 'enom') {
        $refuses(fn () => new FeedReadBudget($minute, $b, RequestOrigin::Background, $calls));
        same($calls, (int) $q()->used, 'Overlapping feed cannot partially reserve');
    }
    for ($i = 0; $i < $calls; $i++) {
        $token = $group->acquire($b, RequestOrigin::Background);
        $refuses(fn () => $group->acquire($b, RequestOrigin::Background));
        $group->release($b, RequestOrigin::Background, $token);
    }
    same($calls, (int) $q()->used, 'Per-wire lease use does not double-count group reservation');
    $refuses(fn () => $group->acquire($b, RequestOrigin::Background));
    $group->finish(); $group->finish();
    same([], json_decode($q()->leases, true), 'Completion releases only the group lease, once');
    same($calls, (int) $q()->used, 'Completion retains charged attempts, including unsent capacity');
    $refuses(fn () => $group->acquire($b, RequestOrigin::Background));
    $refuses(fn () => $minute->reserve(new AccountBinding($provider, $b->serverId, $b->environment, 'wrong-account'), RequestOrigin::Background, 1));
}
$clock->at = $clock->at->modify('+61 seconds');
$b = $binding('enom'); $minute = new AccountReadBudget($clock, 5);
$group = new FeedReadBudget($minute, $b, RequestOrigin::Background, 5);
$clock->at = $clock->at->modify('+31 seconds');
$refuses(fn () => $minute->acquire($b, RequestOrigin::Interactive));
$clock->at = $clock->at->modify('+30 seconds');
$refuses(fn () => $minute->acquire($b, RequestOrigin::Interactive));
$group->finish();
$token = $minute->acquire($b, RequestOrigin::Interactive); $minute->release($b, RequestOrigin::Interactive, $token);
// Headroom failure is all-or-nothing, with no lease left behind.
$clock->at = $clock->at->modify('+61 seconds');
$token = $minute->acquire($b, RequestOrigin::Background); $minute->release($b, RequestOrigin::Background, $token);
$refuses(fn () => $minute->reserve($b, RequestOrigin::Background, 5));
same(1, (int) DB::table('domains_read_budgets')->where('server_id', $b->serverId)->value('used'), 'Insufficient group capacity retains only prior usage');
echo "Whole-feed budget checks passed.\n";
$nativeSuccessful = true;
