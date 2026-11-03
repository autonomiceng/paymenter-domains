<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/offer-ports-test.php';
$nativeSuccessful = false;

use App\Models\{Role, User};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate;
use Paymenter\Extensions\Servers\Domains\Jobs\SyncPrices;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\{Driver};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\Client;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, PriceFill, RegistrarRegistry, StaffSettingsReview};
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\Environment;

$role = Role::create(['name' => 'Synthetic sync staff', 'permissions' => [Staff::VIEW, Staff::POLICY, StaffSettingsReview::permission('import')]]);
$staff = User::create(['first_name'=>'Synthetic', 'last_name'=>'Staff', 'email'=>'queued-sync@example.test', 'password'=>'synthetic-password', 'role_id'=>$role->id]);
expect(Staff::allows($staff, StaffSettingsReview::permission('import')), 'The old job fixture has actual current import authority');
$job = new SyncPrices($staff->id, $binding->serverId, $binding->fingerprint);
expect(property_exists($job, 'upgradeGeneration'), 'Queued work captures the upgrade generation');
same(0, $job->upgradeGeneration, 'Pre-maintenance queued work owns the legacy generation');
(new UpgradeGate)->pause(); (new UpgradeGate)->resume();
$registry = new RegistrarRegistry;
$registry->register('enom', ['uid','token'], new class implements AccountVerifier {
    public function verify(Environment $e, array $c): string { return $c['uid']; }
}, static fn ($b, $c) => new Driver(new Client($b, $c['uid'], $c['token'], $wire), ['com']));
app()->instance(PriceFill::class, new PriceFill(new AccountBindings($registry), $clock));
config(['domains.enom_identity.environment'=>'test', 'domains.enom_catalogue.enabled'=>true]);
$before = $wire->calls; $job->handle();
same($before, $wire->calls, 'An old queued job cannot read or fill after pause/resume');
same("Couldn't sync prices", App\Models\Notification::where('user_id', $staff->id)->latest('id')->value('title'), 'Stale queued work reports refusal');
same(UpgradeGate::generation(), (new SyncPrices($staff->id, $binding->serverId, $binding->fingerprint))->upgradeGeneration, 'Fresh jobs capture the new generation');
// Pause/resume after delivery validation, while resolving the actual admission service.
$gapJob = new SyncPrices($staff->id, $binding->serverId, $binding->fingerprint);
app()->bind(PriceFill::class, static function () use ($registry, $clock): PriceFill {
    (new UpgradeGate)->pause(); (new UpgradeGate)->resume();
    return new PriceFill(new AccountBindings($registry), $clock);
});
$before = $wire->calls; $gapJob->handle();
same($before, $wire->calls, 'A queued generation cannot be recaptured after delivery validation');
same(0, DB::table(Paymenter\Extensions\Servers\Domains\Services\SellTableSchema::FILLS)->count(), 'Stale delivery cannot admit a price-fill run');
same("Couldn't sync prices", App\Models\Notification::where('user_id', $staff->id)->latest('id')->value('title'), 'Admission gap reports refusal');
$nativeSuccessful = true;
echo "Queued catalogue trigger generation checks passed.\n";
