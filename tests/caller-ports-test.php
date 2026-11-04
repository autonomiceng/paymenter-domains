<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/OfferFixture.php';
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/accounts.php';

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts, Context, Cost, Edit, Facts, FactsObserved, FeedObserved, Intent, OfferState, Projection, Query, Writer};
use Paymenter\Extensions\Servers\Domains\Data\CostUnit;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances, Installation};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;

expect(is_file(dirname(__DIR__).'/Domains/Catalogue/CallerPorts.php'), 'Caller ports must compose local account gates and shared reader/writer');
// A native fixture is needed for encrypted credential/processing gates and real price row IDs.
// All transport factories are synthetic tripwires; no installed/Live acceptance is claimed.
(new Installation)->install(); extract(commerceFixture(active:true));
$clock = new FakeClock($now); $permission = true; $feature = true;
$authorize = static function ($input, $context) use (&$permission): void {
    if (!$permission) { throw new DomainException('Permission revoked.'); }
};
$ports = new CallerPorts(new Projection($native), $clock, $bindings, $authorize,
    static function ($b, $action) use (&$feature): bool { return $feature; });
$b = CatalogueAcceptances::binding(Account::where('server_id', $tlds[0]->server_id)->sole());
$n = CatalogueAcceptances::binding(Account::where('server_id', $tlds[1]->server_id)->sole());
$converter = new \Paymenter\Extensions\Servers\Domains\Tests\OfferFixture; $converter->batch($b); $converter->batch($n);
$query = new Query($b, ['com'], 'register', 3); $holder = new Query($b, ['com'], 'renew');
$rev = static fn () => $ports->readOffers($query, Context::Staff)[0]->revision;
$ports->apply(new FactsObserved($b, 'com', new Facts(['register' => [1, 3], 'renew' => [1], 'transfer' => [1]],
    [], true, false, false, 'synthetic_facts', $now, $now->modify('+1 day'))), $rev());
$ports->apply(Edit::override($b, 'com', new Intent(sell: true, longest: 3, transfers: true)), $rev());
$ports->apply(Edit::seller($b, 'com', ['register', 'transfer']), $rev());
// The persisted normalized total retains the original annual source unit and original age.
$clock->instant = $now->modify('+1 minute');
$ports->apply(new FeedObserved($b, 'com', [new Cost('register', 3, true, '7.0000', CostUnit::Annual,
    'synthetic_annual', $clock->instant, $clock->instant->modify('+1 day'))]), $rev());
$loaded = $ports->loadBatch([$query], Context::Public)[0][0]; $price = $loaded->price();
$row = DB::table('domains_prices')->where('server_id', $b->serverId)->where('tld', 'com')->where('action', 'register')->where('term', 3)->sole();
same([(int) $row->id, (int) $row->revision, '21.0000', '27.30', 'synthetic_annual'],
    [$price->id, $price->revision, $price->wholesale, $price->retail, $price->source], 'Price DTO uses real row identity and exact normalized term total');
same($clock->instant->format(DATE_ATOM), $price->observedAt->format(DATE_ATOM), 'Mapping does not freshen observation age');
same($clock->instant->modify('+1 day')->format(DATE_ATOM), $price->freshUntil->format(DATE_ATOM), 'Mapping preserves original expiry');
same(CostUnit::Annual, $loaded->observation()->sourceUnit, 'Original unit survives normalized persistence');
$public = json_encode($loaded, JSON_THROW_ON_ERROR);
expect(!str_contains($public, '21.0000') && !str_contains($public, $b->fingerprint) && !str_contains($public, 'synthetic_annual'), 'Public JSON carries neither cost nor private evidence');
commerceReject(fn () => serialize($loaded));
$clock->instant = $now->modify('+3 days');
same(false, $ports->readOffers($query, Context::Public)[0]->available, 'Stale observations close public acquisition');
same('10.40', $ports->loadBatch([$holder], Context::Holder)[0][0]->renewal()->retail, 'Stale observations retain holder retail');
$clock->instant = $now->modify('+1 minute');
$feature = false;
same(false, $ports->readOffers($query, Context::Public)[0]->available, 'Local feature revocation closes acquisition without a price-summary recursion');
$feature = true;
Account::where('server_id', $b->serverId)->update(['processing_enabled' => false]);
same(false, $ports->readOffers($query, Context::Public)[0]->available, 'Local processing revocation closes acquisition');
Account::where('server_id', $b->serverId)->update(['processing_enabled' => true]);
$expected = $rev();
$permission = false;
commerceReject(fn () => $ports->readOffers($query, Context::Staff));
commerceReject(fn () => DB::transaction(fn () => $ports->loadBatch([$holder], Context::Holder, true)));
commerceReject(fn () => $ports->apply(Edit::republish($b, 'com'), $expected));
$permission = true;
$admission = new Query($b, ['com'], 'register', 3, expected: ['com' => $expected]);
same('27.30', DB::transaction(fn () => $ports->loadBatch([$admission], Context::Locked)[0][0]->price())->retail,
    'Locked caller ports use current local account gates without a provider callback');
$proof = Server::findOrFail($b->serverId)->settings()->where('key', 'domains_verified_credentials')->sole();
$savedProof = $proof->value; $proof->update(['value' => 'synthetic revoked proof']);
same(false, DB::transaction(fn () => $ports->readOffers($admission, Context::Locked)[0]->available),
    'Locked admission reloads revoked local credential proof even when offer revisions match');
$proof->update(['value' => $savedProof]);
// Holder retail belongs to this frozen account despite EVERY public-sale predicate failing.
DB::table('domains_sales_policies')->where('tld', 'com')->update(['server_id' => $n->serverId,
    'registrar_key' => $n->registrarKey, 'account_fingerprint' => $n->fingerprint, 'register_enabled' => false, 'transfer_enabled' => false]);
DB::table('domains_tlds')->where('id', $tlds[0]->id)->update(['catalogue_intent' => OfferState::json(new Intent(sell: false)),
    'catalogue_facts' => null, 'catalogue_published_revision' => null]);
DB::table('domains_prices')->where('server_id', $b->serverId)->update(['catalogue_observation' => null]);
Server::whereKey($b->serverId)->update(['enabled' => false]); $feature = false;
DB::transaction(function () use ($ports, $holder): void {
    $loaded = $ports->loadBatch([$holder], Context::Holder, true)[0][0]; $retained = $loaded->renewal();
    same(['10.40', true, 'retained_renewal'], [$retained->retail, $retained->retained, $retained->staffAlert], 'Locked Holder remains independent of locked public admission');
    expect($retained->id !== null && $retained->revision !== null, 'Retained DTO preserves an existing price identity');
    commerceReject(fn () => $loaded->price());
});
DB::table('domains_prices')->where('server_id', $b->serverId)->where('action', 'renew')->delete();
$retained = DB::transaction(fn () => $ports->loadBatch([$holder], Context::Holder, true)[0][0]->renewal());
same([null, null, '10.40'], [$retained->id, $retained->revision, $retained->retail], 'Missing cost/price row does not invent IDs or discard retained retail');
$foreign = new Query($n, ['com'], 'renew');
expect($ports->loadBatch([$foreign], Context::Holder)[0][0]->renewal()->retail === '10.40', 'Other account retains only its own retail');
DB::table('domains_tlds')->where('id', $tlds[1]->id)->update(['catalogue_retained_annual_retail' => null]);
same(false, $ports->readOffers($foreign, Context::Holder)[0]->available, 'Holder cannot borrow retained retail from another account');
same(0, $reads->calls, 'Shared ports never enter a provider factory, including under holder locks');
$nativeSuccessful = true;
echo "Caller ports: ok\n";
