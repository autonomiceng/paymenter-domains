<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Retained encrypted settings and native upgrade-command handling need the existing SQLite fixture.
require __DIR__.'/offer-activation-test.php';
$nativeSuccessful = false;

use App\Console\Commands\Extension\Upgrade;
use App\Models\{Role, Server, Setting};
use Illuminate\Support\Facades\{Artisan, DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{OfferUpgrade, RenewalBillingSettings as Billing, StaffSettingsReview as Review, StaffSettingsWrites};

$role = Role::create(['name' => 'Legacy safety reviewer', 'permissions' => array_keys(Staff::permissions())]);
$staff->update(['role_id' => $role->id]);
$staff->unsetRelation('role');
$server = Server::findOrFail($tlds[0]->server_id);
DB::transaction(static fn () => Billing::setSwitch($server->id, true));
$gate = Setting::whereNull('settingable_type')->whereNull('settingable_id')->where('key', UpgradeGate::KEY)->sole();
$gate->value = json_encode(['state' => 'legacy', 'generation' => 3], JSON_THROW_ON_ERROR);
$gate->save();

// The uploader copies replacement files before calling this native command. Its caught hook
// failure leaves the replacement package running against the retained legacy database.
try { OfferUpgrade::requireCompatible(); throw new LogicException('Legacy package admission unexpectedly passed.'); }
catch (DomainException $error) { same(OfferUpgrade::MESSAGE, $error->getMessage(), 'Pre-deploy admission requires the bridge'); }
same(0, Artisan::call(Upgrade::class, ['type' => 'server', 'name' => 'Domains', 'oldVersion' => '0.1.0']), 'Native upgrade command catches package admission failure');
expect(str_contains(Artisan::output(), OfferUpgrade::MESSAGE), 'Native upgrade reports the bridge refusal');
same(['state' => 'legacy', 'generation' => 3], UpgradeGate::routeState(), 'Failed hook leaves retained legacy state reachable');

$writer = new StaffSettingsWrites($native, $sellers, $bindings);
$review = static fn (string $action) => new Review($staff->id, $server->id, null, $action,
    Review::ticket($staff->id, $server->id, null, $action, Review::stamp($server->id, null)), 'Stop legacy financial work');
$off = ['scheduled' => false, 'manual' => false, 'issue_days' => 21, 'due_days' => 7, 'confirmed' => true];
$refusal = static function (callable $call, string $message): void {
    try { $call(); throw new RuntimeException('Expected legacy review refusal: '.$message); }
    catch (DomainException $error) { same($message, $error->getMessage(), 'Exact legacy review refusal'); }
};
$failures = [];
foreach (['processing' => ['enabled' => false, 'confirmed' => true], 'renewal_billing' => $off] as $action => $data) {
    try { $writer->run($review($action), $data); }
    catch (DomainException $error) { $failures[$action] = $error->getMessage(); }
}
same([], $failures, 'Legacy disable writes must succeed: '.json_encode($failures));
same(false, Account::where('server_id', $server->id)->sole()->processing_enabled, 'Legacy staff can pause Orders');
same([false, false], array_values(array_intersect_key(Billing::read($server->id), array_flip(['scheduled', 'manual']))), 'Legacy staff can stop both renewal billing paths');
same(2, count(array_filter(Review::historyRows($server->fresh()), static fn ($row) => $row['reason'] === 'Stop legacy financial work')), 'Both disable writes retain reviewed audit history');

// The exception grants only disable writes; enabling or unrelated reviews remain fenced.
foreach ([['scheduled' => true], ['manual' => true]] as $enable) {
    $refusal(static fn () => $writer->run($review('renewal_billing'), [...$off, ...$enable]), OfferUpgrade::MESSAGE);
}
$enable = $review('processing');
$enable->input(['enabled' => true]);
$refusal(static fn () => $enable->local(static fn () => throw new RuntimeException('Legacy enable review passed its gate.')), OfferUpgrade::MESSAGE);
$other = $review('unpaid_hold');
$other->input(['enabled' => false]);
$refusal(static fn () => $other->local(static fn () => throw new RuntimeException('Unrelated legacy review passed its gate.')), OfferUpgrade::MESSAGE);

// A paused window and a later resumed generation invalidate already issued disable tickets.
$stale = $review('renewal_billing');
$upgrade = new UpgradeGate;
$upgrade->pause();
$refusal(static fn () => $writer->run($review('renewal_billing'), $off), OfferUpgrade::MESSAGE);
$upgrade->resume();
$refusal(static fn () => $writer->run($stale, $off), 'Settings changed. Reload this page.');
same(0, $reads->calls, 'Legacy safety writes never resolve a provider');

$nativeSuccessful = true;
echo "Legacy staff disable reviews: ok\n";
