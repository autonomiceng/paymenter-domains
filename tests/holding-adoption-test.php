<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/adoption-holding.php';
use Illuminate\Support\Facades\{DB, Schema};
use Paymenter\Extensions\Servers\Domains\Services\{HoldingAdoptionSchema, HoldingIdentityLock};
$f = adoptionHoldingFixture(); $d = $f['domain']; $a = $f['adoption'];
$guard = new \Paymenter\Extensions\Servers\Domains\Services\NativeDomainGuard(new \Paymenter\Extensions\Servers\Domains\Services\HolderPricing($f['prices']), $f['clock']); $guard->boot();
foreach (['ADOPTION_ID' => $a->id, 'SNAPSHOT' => encrypt(json_encode(['holding' => ['version' => 1]]), false)] as $key => $value) {
    holdingRefused(fn () => \Paymenter\Extensions\Servers\Domains\Models\Domain::create(['domain' => 'uppercase-placeholder.com', 'sld' => 'uppercase-placeholder', 'tld' => 'com',
        'server_id' => $d->server_id, 'registrar_key' => $d->registrar_key, 'account_fingerprint' => $d->account_fingerprint,
        'user_id' => $d->user_id, 'service_id' => $d->service_id, $key => $value]), 'Alternate-case proof attribute refused: '.$key);
}
$newService = App\Models\Service::create(['user_id' => $f['owner']->id, 'order_id' => $f['order']->id, 'product_id' => $f['product']->id,
    'plan_id' => $f['plan']->id, 'quantity' => 1, 'price' => '10.00', 'currency_code' => 'USD', 'status' => 'pending']);
$lateCreate = true;
\Paymenter\Extensions\Servers\Domains\Models\Domain::creating(static function ($row) use (&$lateCreate, $a): void {
    if ($lateCreate) { $lateCreate = false; $row->adoption_id = $a->id; $row->snapshot = [...($row->snapshot ?? []), 'holding' => ['version' => 1]]; }
});
holdingRefused(fn () => \Paymenter\Extensions\Servers\Domains\Models\Domain::create(['domain' => 'late-create-placeholder.com', 'sld' => 'late-create-placeholder', 'tld' => 'com',
    'server_id' => $d->server_id, 'registrar_key' => $d->registrar_key, 'account_fingerprint' => $d->account_fingerprint,
    'user_id' => $d->user_id, 'service_id' => $newService->id, 'status' => 'registration_pending', 'snapshot' => ['checkout' => ['name' => 'late-create-placeholder.com']]]), 'Late creation callback cannot insert adoption proof');
same(0, \Paymenter\Extensions\Servers\Domains\Models\Domain::where('service_id', $newService->id)->count(), 'Caught insert refusal rolls back the entire row');
$lock = DB::transaction(fn () => HoldingIdentityLock::lockForAdoption($f['order']->id, $f['service']->id));
holdingRefused(fn () => DB::transaction(fn () => $d->attachAdoption($lock, $a->id)), 'Expired identity lock refuses attachment');
foreach (['domain_id' => $d->id + 1, 'state' => 'preview', 'state-terminal' => 'refused'] as $key => $value) {
    $column = $key === 'state-terminal' ? 'state' : $key; $original = $a->getRawOriginal($column);
    DB::table('domains_adoptions')->where('id', $a->id)->update([$column => $value]);
    holdingRefused(fn () => attachHolding($f), 'Attachment refuses '.$key);
    DB::table('domains_adoptions')->where('id', $a->id)->update([$column => $original]);
}
$marker = $f['product']->settings()->where('key', 'domains_tld_id')->sole();
$marker->update(['value' => '999999']);
holdingRefused(fn () => attachHolding($f), 'Wrong TLD marker refuses attachment');
$marker->update(['value' => (string) $f['tld']->id]);
DB::table('domains_domains')->where('id', $d->id)->update(['confirmed_revision' => 1]);
holdingRefused(fn () => attachHolding($f), 'Stale prior revision refuses attachment');
DB::table('domains_domains')->where('id', $d->id)->update(['confirmed_revision' => 0,
    'snapshot' => encrypt(json_encode(['checkout' => ['name' => $d->domain]]), false)]);
holdingRefused(fn () => attachHolding($f), 'Checkout proof cannot become an adoption');
DB::table('domains_domains')->where('id', $d->id)->update(['snapshot' => null]);
$latePrice = true;
\Paymenter\Extensions\Servers\Domains\Models\Domain::updating(static function ($row) use (&$latePrice): void {
    if ($latePrice) { $latePrice = false; $row->price_mode = 'complimentary'; }
});
holdingRefused(fn () => attachHolding($f), 'Late callback cannot change attachment pricing authority');
same(null, $d->fresh()->adoption_id, 'Caught pricing refusal rolls back attachment');
same('standard', $d->fresh()->price_mode, 'Attachment retains original pricing mode');
$stale = $d->fresh();
attachHolding($f); $d->refresh(); same($a->id, $d->adoption_id, 'Domain points to committed adoption');
same($d->id, (int) $a->domain_id, 'Adoption points back to domain');
same('adopted', $d->provenance, 'Pending provenance promoted');
same(2, $d->authority_generation, 'Promotion advances exactly the committed generation');
$holding = $d->snapshot['holding'];
$late = false;
\Paymenter\Extensions\Servers\Domains\Models\Domain::updating(static function ($row) use (&$late): void { if ($late) { $late = false; $row->adoption_id += 1; } });
$late = true;
holdingRefused(fn () => $d->update(['observation' => ['ordinary' => true]]), 'Late callback cannot replace original pointer'); $d->refresh();
same($a->id, $d->adoption_id, 'Caught callback refusal rolls back pointer');
holdingRefused(fn () => $stale->update(['snapshot' => ['ordinary_metadata' => true]]), 'Stale save cannot erase newly attached proof');
same($holding, $d->fresh()->snapshot['holding'], 'Stale refusal retains original proof');
foreach ([null, $a->id + 1] as $pointer) {
    holdingRefused(fn () => $d->update(['adoption_id' => $pointer]), 'Original pointer is frozen'); $d->refresh();
}
foreach ($holding as $key => $value) {
    holdingRefused(fn () => $d->update(['snapshot' => ['holding' => [...$holding, $key => null]]]), 'Holding identity is frozen: '.$key); $d->refresh();
}
holdingRefused(fn () => $d->update(['snapshot' => []]), 'Cannot remove original holding'); $d->refresh();
holdingRefused(fn () => $d->update(['snapshot' => ['holding' => $holding, 'checkout' => ['name' => $d->domain]]]), 'Cannot add checkout to adoption'); $d->refresh();
holdingRefused(fn () => $d->increment('adoption_id'), 'Numeric update cannot bypass pointer freeze');
holdingRefused(fn () => attachHolding($f), 'Cannot reattach original adoption');
$d->update(['confirmed_revision' => 3, 'provider_expires_at' => '2028-10-20', 'observation' => ['healthy' => true],
    'renewal_policy' => [...$d->renewal_policy, 'billing_anchor' => ['operation_key' => 'renew:synthetic', 'expiry' => '2028-10-20']]]);
same($holding, $d->fresh()->snapshot['holding'], 'Evolution retains original holding identity');
same($a->id, $d->fresh()->adoption_id, 'Evolution retains original pointer');
HoldingAdoptionSchema::install(); HoldingAdoptionSchema::verify();
$m = require dirname(__DIR__).'/Domains/database/migrations/2026_10_19_000014_add_holding_adoption.php'; $m->down();
same($holding, $d->fresh()->snapshot['holding'], 'Retained migration preserves original proof');
Schema::table('domains_domains', fn ($t) => $t->dropColumn('adoption_id'));
$failed = false; try { HoldingAdoptionSchema::verify(); } catch (RuntimeException) { $failed = true; }
expect($failed, 'Recorded missing pointer refuses verification');
echo "Holding adoption attachment, two-way linkage, pointer/identity freeze and ordinary evolution passed.\n";
$nativeSuccessful = true;
