<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/adoption-confirm.php';
use Livewire\Livewire;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\AdoptDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Adoption, Domain};
use Paymenter\Extensions\Servers\Domains\Services\{AdoptionCommit, AdoptionReader};
extract(adoptionCommitFixture()); staffPanel();
app()->instance(AdoptionReader::class, $reader); app()->instance(AdoptionCommit::class, $commit); app()->instance(Clock::class, $clock);
$viewOnly = staffUser([Staff::VIEW]); Livewire::actingAs($viewOnly)->test(AdoptDomain::class)->assertForbidden();
config(['domains.adoption.enabled' => false]);
$page = Livewire::actingAs($staff)->test(AdoptDomain::class)->assertSee('Adoption is not enabled yet')->assertSee('Choose a dedicated domain service.');
same(0, count($wire->calls), 'Wizard GET never reads registrar');
$choose = ['customer' => $owner->id, 'service' => $service->id, 'account' => $binding->serverId];
$page->callAction('choose', data: $choose)->assertHasNoActionErrors()->assertSee('example.com')->assertSee('2. Fresh readback');
same(0, count($wire->calls), 'Choosing mapping performs no wire');
$page->callAction('readback', data: ['reason' => 'Verified native dedicated service for original customer', 'do_not_renew' => true])->assertHasNoActionErrors()
    ->assertSee('Verified preview')->assertSee('No renewal')->assertActionDisabled('confirm');
$html = $page->html(); $a = Adoption::sole();
foreach ([$a->request_key, $a->request_digest, 'synthetic-secret', 'account-enom', 'provider_domain_id', 'account_fingerprint'] as $secret) { expect(!str_contains($html, $secret), 'Browser excludes private authority '.$secret); }
$page->callAction('confirm', data: ['verified' => true]); same(0, Domain::count(), 'Default off UI cannot confirm');
config(['domains.adoption.enabled' => true]);
$clock->time = $clock->time->modify('+120 seconds');
$page->callAction('confirm', data: ['verified' => true])->assertSee('Confirmation refused')->assertSee('Read a fresh preview'); same(0, Domain::count(), 'Stale POST rolls back');
$page->callAction('readback', data: ['reason' => 'Rechecked after stale evidence', 'do_not_renew' => true])->assertHasNoActionErrors()->assertSee('Verified preview');
$calls = count($wire->calls); $money = scenarioMoney();
$page->callAction('confirm', data: ['verified' => true])->assertHasNoActionErrors()->assertSee('Adopted')->assertSee('Renewal off');
same(1, Domain::count(), 'Wizard uses real confirmer'); same($calls, count($wire->calls), 'Confirmation no provider call'); same($money, scenarioMoney(), 'Wizard no financial fabrication');
$page->assertActionHidden('confirm'); same(1, Domain::count(), 'Completed UI has no second confirm action');
DB::table('roles')->where('id', $staff->role_id)->update(['permissions' => json_encode([Staff::VIEW])]); $page->call('$refresh')->assertForbidden();
$nativeSuccessful = true; echo "Native adoption wizard, stale refusal, safe browser state and permission rechecks passed\n";
