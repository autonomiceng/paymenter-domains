<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once dirname(__DIR__).'/native-app.php';
require_once __DIR__.'/adoption-preview.php';
require_once __DIR__.'/lifecycle-scenarios.php';
function adoptionCommitFixture(\Paymenter\Extensions\Servers\Domains\Data\Environment $environment = \Paymenter\Extensions\Servers\Domains\Data\Environment::Live): array
{
    config(['app.url' => 'https://domains-tests.invalid', 'domains.adoption.enabled' => true]);
    $f = adoptionFixture($environment); extract($f);
    foreach (['HoldingAdoptionSchema', 'HistoryLinkSchema', 'NativeIntentSchema', 'ObservationSchema', 'ReminderSchema', 'RuntimeSchema'] as $schema) {
        ('Paymenter\\Extensions\\Servers\\Domains\\Services\\'.$schema)::install();
    }
    $permissions = ['admin.domains.view', 'admin.domains.adopt.preview', 'admin.domains.adopt', 'admin.domains.manage', 'admin.domains.fund', 'admin.services.update', 'admin.invoices.view'];
    Illuminate\Support\Facades\DB::table('roles')->where('id', $staff->role_id)->update(['permissions' => json_encode($permissions)]);
    $guard = new \Paymenter\Extensions\Servers\Domains\Services\NativeDomainGuard(new \Paymenter\Extensions\Servers\Domains\Services\HolderPricing($prices), $clock);
    $guard->boot(); \Paymenter\Extensions\Servers\Domains\Services\NativeIdentity::boot(); \Paymenter\Extensions\Servers\Domains\Services\NativeCancellation::boot();
    $commit = new \Paymenter\Extensions\Servers\Domains\Services\AdoptionCommit($mapping, $bindings, $clock);
    return [...$f, ...compact('commit', 'guard', 'permissions')];
}
